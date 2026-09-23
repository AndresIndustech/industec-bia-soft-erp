<?php
declare(strict_types=1);

require_once __DIR__ . '/Auth.php';

/**
 * Automatizacion.php — Las reglas de los reportes programados (T2.27.7, migración 020).
 *
 * QUÉ GUARDA
 * Cada tarea es un reporte de T2.27 que la estación genera sola en su horario:
 * el STATUS de los martes, la respuesta del miércoles a KFC, el tablero de
 * gerencia, la propuesta de kits y la presentación mensual. Aquí vive QUÉ corre,
 * CUÁNDO, EN QUÉ MODO y A QUIÉN va; el trabajo lo hace `t2_27_programador.py`
 * en la estación, que lee esto con `automatizacion_cli.php`.
 *
 * LA REGLA QUE IMPORTA
 * Andrés pidió (2026-09-23) que todo nazca inactivo y se active con la
 * aprobación de la administradora. Por eso:
 *   - activar exige la nota de quién aprobó y cómo (se guarda con quién pulsó);
 *   - activar en modo AVISAR exige un revisor activo, y en ENVIAR un destinatario PARA;
 *   - el modo no se cambia con la tarea activa: pasar de AVISAR a ENVIAR es
 *     empezar a mandarle archivos a KFC, y eso se decide desactivando y
 *     volviendo a aprobar, no con un desplegable;
 *   - nada se borra: tareas y destinatarios se desactivan, y cada cambio queda en
 *     `automatizacion_cambios` y en la bitácora.
 */
final class Automatizacion
{
    public const MODOS = [
        'GENERAR' => ['solo genera', 'Deja los archivos en SALIDAS IA de la estación. No manda nada.'],
        'AVISAR'  => ['genera y avisa a los revisores', 'Manda los archivos a los revisores internos para que los revisen y los envíen ellos.'],
        'ENVIAR'  => ['genera y envía al cliente', 'Manda los archivos directo a los destinatarios PARA y COPIA, sin revisión humana.'],
    ];
    public const TIPOS = ['PARA' => 'Para', 'COPIA' => 'Copia', 'REVISION' => 'Revisión interna'];
    public const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    public static function disponible(): bool
    {
        try {
            Db::uno('SELECT 1 FROM automatizacion_tareas LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Las tareas con sus destinatarios, sus últimas corridas y quién aprobó. */
    public static function tareas(): array
    {
        $tareas = Db::todos(
            'SELECT t.*, a.nombre AS aprobada_nombre, x.nombre AS actualizado_nombre
               FROM automatizacion_tareas t
               LEFT JOIN usuarios a ON a.usuario_id = t.aprobada_por
               LEFT JOIN usuarios x ON x.usuario_id = t.actualizado_por
              ORDER BY t.tarea_id'
        );
        $dest = [];
        foreach (Db::todos('SELECT * FROM automatizacion_destinatarios ORDER BY FIELD(tipo, "REVISION", "PARA", "COPIA"), correo') as $d) {
            $dest[(int) $d['tarea_id']][] = $d;
        }
        $corr = [];
        foreach (Db::todos('SELECT * FROM automatizacion_corridas ORDER BY programada_para DESC LIMIT 200') as $c) {
            if (count($corr[(int) $c['tarea_id']] ?? []) < 5) {
                $corr[(int) $c['tarea_id']][] = $c;
            }
        }
        foreach ($tareas as &$t) {
            $t['destinatarios'] = $dest[(int) $t['tarea_id']] ?? [];
            $t['corridas'] = $corr[(int) $t['tarea_id']] ?? [];
        }
        return $tareas;
    }

    public static function tarea(int $id): ?array
    {
        return Db::uno('SELECT * FROM automatizacion_tareas WHERE tarea_id = ?', [$id]) ?: null;
    }

    /** «martes a las 07:00», «el día 1 de cada mes a las 07:00». */
    public static function horarioTexto(array $t): string
    {
        $hora = substr((string) $t['hora'], 0, 5);
        if (!empty($t['dia_mes'])) {
            return 'el día ' . (int) $t['dia_mes'] . ' de cada mes a las ' . $hora;
        }
        $dias = array_map(fn($d) => self::DIAS[(int) $d] ?? '?', array_filter(explode(',', (string) $t['dias'])));
        if (!$dias) {
            return 'sin horario';
        }
        $ultimo = array_pop($dias);
        return ($dias ? implode(', ', $dias) . ' y ' : '') . $ultimo . ' a las ' . $hora;
    }

    /** La próxima vez que le toca, desde `$desde` (hora de Ecuador). */
    public static function proxima(array $t, ?DateTimeImmutable $desde = null): ?DateTimeImmutable
    {
        $desde = $desde ?? new DateTimeImmutable('now');
        [$h, $m] = array_map('intval', explode(':', substr((string) $t['hora'], 0, 5)));
        for ($i = 0; $i <= 62; $i++) {
            $d = $desde->setTime(0, 0)->modify("+$i day")->setTime($h, $m);
            if ($d <= $desde) {
                continue;
            }
            if (!empty($t['dia_mes'])) {
                if ((int) $d->format('j') === (int) $t['dia_mes']) {
                    return $d;
                }
            } elseif (in_array((string) $d->format('N'), explode(',', (string) $t['dias']), true)) {
                return $d;
            }
        }
        return null;
    }

    /** ¿Se puede activar ya? Devuelve el motivo si no. */
    public static function faltaParaActivar(array $t, array $destinatarios): ?string
    {
        $activos = fn(string $tipo) => count(array_filter($destinatarios, fn($d) => $d['tipo'] === $tipo && (int) $d['activo'] === 1));
        if ($t['modo'] === 'AVISAR' && $activos('REVISION') === 0) {
            return 'En modo «' . self::MODOS['AVISAR'][0] . '» hace falta al menos un revisor interno activo.';
        }
        if ($t['modo'] === 'ENVIAR' && $activos('PARA') === 0) {
            return 'En modo «' . self::MODOS['ENVIAR'][0] . '» hace falta al menos un destinatario PARA activo.';
        }
        if (trim((string) $t['dias']) === '' && empty($t['dia_mes'])) {
            return 'La tarea no tiene horario.';
        }
        return null;
    }

    // ------------------------------------------------------------------ cambios

    private static function anotar(int $tareaId, string $accion, $antes, $despues, ?string $nota, int $por): void
    {
        Db::ejecutar(
            'INSERT INTO automatizacion_cambios (tarea_id, accion, antes, despues, nota, por) VALUES (?, ?, ?, ?, ?, ?)',
            [$tareaId, $accion, $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
             $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE), $nota, $por]
        );
        Auth::bitacora('AUTOMATIZACION_' . $accion, 'automatizacion_tarea', (string) $tareaId, $nota,
                       is_scalar($antes) ? (string) $antes : null, is_scalar($despues) ? (string) $despues : null,
                       ['antes' => $antes, 'despues' => $despues]);
    }

    /** @return string|null el error, o null si se hizo */
    public static function activar(int $id, string $nota, array $u): ?string
    {
        $t = self::tarea($id);
        if (!$t) { return 'Esa tarea no existe.'; }
        if ((int) $t['activa'] === 1) { return 'La tarea ya está activa.'; }
        $nota = trim($nota);
        if (mb_strlen($nota) < 10) {
            return 'Escribe quién aprobó la activación y cómo (por ejemplo, «Aprobado por Isabel Rodríguez por correo del 25/09»). Queda registrado.';
        }
        $dest = Db::todos('SELECT * FROM automatizacion_destinatarios WHERE tarea_id = ?', [$id]);
        if ($falta = self::faltaParaActivar($t, $dest)) { return $falta; }
        // `AND activa = 0`: si dos personas activan a la vez, solo una queda registrada.
        $n = Db::ejecutar(
            'UPDATE automatizacion_tareas SET activa = 1, aprobada_por = ?, aprobada_en = NOW(), aprobacion_nota = ?,
                    actualizado_por = ?, actualizado_en = NOW() WHERE tarea_id = ? AND activa = 0',
            [(int) $u['usuario_id'], mb_substr($nota, 0, 300), (int) $u['usuario_id'], $id]
        );
        if ($n === 0) { return 'Alguien la activó hace un instante. Recarga la página.'; }
        self::anotar($id, 'ACTIVAR', ['activa' => 0], ['activa' => 1, 'modo' => $t['modo']], mb_substr($nota, 0, 300), (int) $u['usuario_id']);
        return null;
    }

    public static function desactivar(int $id, string $nota, array $u): ?string
    {
        $t = self::tarea($id);
        if (!$t) { return 'Esa tarea no existe.'; }
        if ((int) $t['activa'] === 0) { return 'La tarea ya está inactiva.'; }
        Db::ejecutar('UPDATE automatizacion_tareas SET activa = 0, actualizado_por = ?, actualizado_en = NOW() WHERE tarea_id = ?',
                     [(int) $u['usuario_id'], $id]);
        self::anotar($id, 'DESACTIVAR', ['activa' => 1], ['activa' => 0], trim($nota) !== '' ? mb_substr(trim($nota), 0, 300) : null, (int) $u['usuario_id']);
        return null;
    }

    public static function cambiarHorario(int $id, array $dias, ?int $diaMes, string $hora, array $u): ?string
    {
        $t = self::tarea($id);
        if (!$t) { return 'Esa tarea no existe.'; }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) { return 'La hora no es válida (HH:MM).'; }
        $dias = array_values(array_unique(array_filter(array_map('intval', $dias), fn($d) => $d >= 1 && $d <= 7)));
        sort($dias);
        if ($diaMes !== null && ($diaMes < 1 || $diaMes > 28)) {
            return 'El día del mes va de 1 a 28 (así existe en todos los meses).';
        }
        if (!$dias && $diaMes === null) { return 'Elige al menos un día de la semana, o un día del mes.'; }
        $antes = ['dias' => $t['dias'], 'dia_mes' => $t['dia_mes'], 'hora' => substr((string) $t['hora'], 0, 5)];
        $despues = ['dias' => $diaMes !== null ? '' : implode(',', $dias), 'dia_mes' => $diaMes, 'hora' => $hora];
        if ($antes == $despues) { return 'El horario no cambió.'; }
        Db::ejecutar('UPDATE automatizacion_tareas SET dias = ?, dia_mes = ?, hora = ?, actualizado_por = ?, actualizado_en = NOW() WHERE tarea_id = ?',
                     [$despues['dias'], $diaMes, $hora . ':00', (int) $u['usuario_id'], $id]);
        self::anotar($id, 'HORARIO', $antes, $despues, null, (int) $u['usuario_id']);
        return null;
    }

    public static function cambiarModo(int $id, string $modo, array $u): ?string
    {
        $t = self::tarea($id);
        if (!$t) { return 'Esa tarea no existe.'; }
        if (!isset(self::MODOS[$modo])) { return 'Modo desconocido.'; }
        if ((int) $t['activa'] === 1) {
            return 'Para cambiar el modo, primero desactiva la tarea: pasar a enviar al cliente se vuelve a aprobar.';
        }
        if ($t['modo'] === $modo) { return 'Ese ya es el modo.'; }
        Db::ejecutar('UPDATE automatizacion_tareas SET modo = ?, actualizado_por = ?, actualizado_en = NOW() WHERE tarea_id = ?',
                     [$modo, (int) $u['usuario_id'], $id]);
        self::anotar($id, 'MODO', $t['modo'], $modo, null, (int) $u['usuario_id']);
        return null;
    }

    public static function agregarDestinatario(int $id, string $tipo, string $correo, string $nombre, array $u): ?string
    {
        if (!self::tarea($id)) { return 'Esa tarea no existe.'; }
        if (!isset(self::TIPOS[$tipo])) { return 'Tipo de destinatario desconocido.'; }
        $correo = mb_strtolower(trim($correo));
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { return 'El correo no es válido.'; }
        if ($tipo === 'REVISION' && !str_ends_with($correo, '@industec.me')) {
            return 'La revisión es interna: el correo tiene que ser @industec.me.';
        }
        $ya = Db::uno('SELECT destinatario_id, activo, tipo FROM automatizacion_destinatarios WHERE tarea_id = ? AND correo = ?', [$id, $correo]);
        if ($ya) {
            return 'Ese correo ya está en esta tarea (' . self::TIPOS[$ya['tipo']] . ((int) $ya['activo'] ? '' : ', desactivado: actívalo en la lista') . ').';
        }
        Db::ejecutar('INSERT INTO automatizacion_destinatarios (tarea_id, tipo, correo, nombre, origen, creado_por) VALUES (?, ?, ?, ?, "MANUAL", ?)',
                     [$id, $tipo, $correo, trim($nombre) !== '' ? mb_substr(trim($nombre), 0, 120) : null, (int) $u['usuario_id']]);
        self::anotar($id, 'DEST_ALTA', null, ['tipo' => $tipo, 'correo' => $correo], null, (int) $u['usuario_id']);
        return null;
    }

    public static function destinatarioActivo(int $destId, bool $activo, array $u): ?string
    {
        $d = Db::uno('SELECT * FROM automatizacion_destinatarios WHERE destinatario_id = ?', [$destId]);
        if (!$d) { return 'Ese destinatario no existe.'; }
        if ((int) $d['activo'] === (int) $activo) { return 'No hay nada que cambiar.'; }
        $t = self::tarea((int) $d['tarea_id']);
        if (!$activo && $t && (int) $t['activa'] === 1) {
            $resto = Db::todos('SELECT * FROM automatizacion_destinatarios WHERE tarea_id = ? AND destinatario_id <> ?', [(int) $d['tarea_id'], $destId]);
            $d2 = $d;
            $d2['activo'] = 0;
            if ($falta = self::faltaParaActivar($t, array_merge($resto, [$d2]))) {
                return 'No se puede desactivar con la tarea activa: ' . $falta;
            }
        }
        Db::ejecutar('UPDATE automatizacion_destinatarios SET activo = ? WHERE destinatario_id = ?', [(int) $activo, $destId]);
        self::anotar((int) $d['tarea_id'], $activo ? 'DEST_ACTIVAR' : 'DEST_DESACTIVAR', null, ['correo' => $d['correo'], 'tipo' => $d['tipo']], null, (int) $u['usuario_id']);
        return null;
    }

    public static function cambios(int $limite = 25): array
    {
        return Db::todos(
            'SELECT c.*, t.nombre AS tarea_nombre, u.nombre AS por_nombre
               FROM automatizacion_cambios c
               JOIN automatizacion_tareas t ON t.tarea_id = c.tarea_id
               LEFT JOIN usuarios u ON u.usuario_id = c.por
              ORDER BY c.en DESC, c.cambio_id DESC LIMIT ' . max(1, min(200, $limite))
        );
    }
}
