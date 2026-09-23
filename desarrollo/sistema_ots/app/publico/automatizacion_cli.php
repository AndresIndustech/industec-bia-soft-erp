<?php
declare(strict_types=1);

/**
 * automatizacion_cli.php — La puerta de la estación al panel de automatización (T2.27.7).
 *
 * `t2_27_programador.py` corre en la estación y no tiene sesión web: por SSH
 * lee la configuración y deja registrada cada corrida. Esto es lo único que
 * puede hacer, a propósito:
 *
 *   php automatizacion_cli.php tareas                 → JSON: tareas y destinatarios
 *   php automatizacion_cli.php corrida CLAVE FECHA     → JSON: la corrida de ese horario, o null
 *   php automatizacion_cli.php registrar [--simular]   → lee un JSON por la entrada y guarda la corrida
 *
 * No activa, no cambia horarios ni destinatarios: eso se hace en el panel, con
 * sesión, permiso y aprobación registrada. Por web devuelve 404, como
 * aplicar_sql.php: es mantenimiento, no un endpoint.
 *
 * Una corrida por horario (UNIQUE tarea_id + programada_para). Si la anterior de
 * ese horario terminó en ERROR, un reintento la reemplaza; una OK no se pisa.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/nucleo/Db.php';

$args = array_slice($argv, 1);
$orden = $args[0] ?? '';
$salir = static function (int $codigo, $datos): void {
    echo json_encode($datos, JSON_UNESCAPED_UNICODE), "\n";
    exit($codigo);
};

try {
    Db::uno('SELECT 1 FROM automatizacion_tareas LIMIT 1');
} catch (Throwable $e) {
    $salir(3, ['error' => 'falta la migración 020 (automatizacion_tareas)']);
}

if ($orden === 'tareas') {
    $tareas = Db::todos('SELECT tarea_id, clave, nombre, comando, dias, dia_mes, hora, modo, asunto, hilo, activa,
                                aprobada_en, aprobacion_nota FROM automatizacion_tareas ORDER BY tarea_id');
    $dest = Db::todos('SELECT tarea_id, tipo, correo, nombre FROM automatizacion_destinatarios WHERE activo = 1 ORDER BY destinatario_id');
    foreach ($tareas as &$t) {
        $t['destinatarios'] = array_values(array_filter($dest, fn($d) => (int) $d['tarea_id'] === (int) $t['tarea_id']));
    }
    $salir(0, ['tareas' => $tareas, 'ahora' => date('Y-m-d H:i:s')]);
}

if ($orden === 'corrida') {
    $f = Db::uno('SELECT c.* FROM automatizacion_corridas c JOIN automatizacion_tareas t ON t.tarea_id = c.tarea_id
                   WHERE t.clave = ? AND c.programada_para = ?', [(string) ($args[1] ?? ''), (string) ($args[2] ?? '')]);
    $salir(0, ['corrida' => $f ?: null]);
}

if ($orden === 'registrar') {
    $simular = in_array('--simular', $args, true);
    $in = json_decode((string) stream_get_contents(STDIN), true);
    if (!is_array($in)) {
        $salir(2, ['error' => 'la entrada no es un JSON']);
    }
    $t = Db::uno('SELECT tarea_id, activa FROM automatizacion_tareas WHERE clave = ?', [(string) ($in['clave'] ?? '')]);
    if (!$t) {
        $salir(2, ['error' => 'no existe la tarea ' . ($in['clave'] ?? '')]);
    }
    $fecha = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/';
    foreach (['programada_para', 'inicio'] as $k) {
        if (!preg_match($fecha, (string) ($in[$k] ?? ''))) {
            $salir(2, ['error' => "fecha inválida en $k"]);
        }
    }
    if (!in_array($in['estado'] ?? '', ['OK', 'ERROR', 'OMITIDA'], true) || !in_array($in['enviado'] ?? 'NO', ['NO', 'REVISORES', 'CLIENTE'], true)) {
        $salir(2, ['error' => 'estado o enviado inválido']);
    }
    // Una tarea inactiva solo puede registrar corridas marcadas a mano: el programador
    // no la corre en su horario, y una corrida «de horario» de una inactiva sería un error.
    if ((int) $t['activa'] === 0 && empty($in['manual'])) {
        $salir(2, ['error' => 'la tarea está inactiva: solo se registran corridas manuales']);
    }
    $pdo = Db::conn();
    $pdo->beginTransaction();
    $n = Db::ejecutar(
        'INSERT INTO automatizacion_corridas (tarea_id, programada_para, inicio, fin, estado, enviado, archivos, mensaje, equipo, manual)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           inicio = IF(estado = "ERROR", VALUES(inicio), inicio), fin = IF(estado = "ERROR", VALUES(fin), fin),
           enviado = IF(estado = "ERROR", VALUES(enviado), enviado), archivos = IF(estado = "ERROR", VALUES(archivos), archivos),
           mensaje = IF(estado = "ERROR", VALUES(mensaje), mensaje), equipo = IF(estado = "ERROR", VALUES(equipo), equipo),
           estado = IF(estado = "ERROR", VALUES(estado), estado)',
        [(int) $t['tarea_id'], $in['programada_para'], $in['inicio'], $in['fin'] ?? null, $in['estado'], $in['enviado'] ?? 'NO',
         json_encode($in['archivos'] ?? [], JSON_UNESCAPED_UNICODE), mb_substr((string) ($in['mensaje'] ?? ''), 0, 1000),
         mb_substr((string) ($in['equipo'] ?? ''), 0, 60), empty($in['manual']) ? 0 : 1]
    );
    if ($simular) {
        $pdo->rollBack();
        $salir(0, ['simulado' => true, 'filas' => $n]);
    }
    $pdo->commit();
    $salir(0, ['registrada' => true, 'filas' => $n]);
}

fwrite(STDERR, "Uso: php automatizacion_cli.php tareas | corrida CLAVE 'AAAA-MM-DD HH:MM:SS' | registrar [--simular] < corrida.json\n");
exit(1);
