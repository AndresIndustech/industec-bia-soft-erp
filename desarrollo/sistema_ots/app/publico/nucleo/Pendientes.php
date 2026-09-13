<?php
declare(strict_types=1);

require_once __DIR__ . '/Casos.php';
require_once __DIR__ . '/Ui.php';

/**
 * Pendientes.php — El equipo que quedó sin concluir, y su reloj de 48 horas.
 *
 * ============================================================================
 * LA REGLA DE NEGOCIO QUE ESTA CLASE HACE CUMPLIR
 *
 * INDUSTEC trabaja con una premisa: **una intervención concluye el trabajo**.
 * El técnico va, diagnostica y resuelve en esa visita. El único motivo válido
 * para no concluir es que el equipo dependa de una pieza o de un tercero.
 *
 * Y encima hay un compromiso duro: **un equipo no puede estar deshabilitado
 * más de 48 horas**. Un local de Grupo KFC con la freidora muerta deja de
 * vender; el reloj no es una meta interna, es el negocio del cliente parado.
 *
 * Dentro de esas 48 horas hay que dar un VEREDICTO, y son exactamente cuatro:
 *
 *   REPUESTO    se compra la pieza y se instala
 *   REPARACION  el equipo sale a un taller
 *   GARANTIA    se reclama al fabricante o al proveedor
 *   BAJA        no tiene arreglo razonable y se propone darlo de baja
 *
 * EL PLAZO MIDE EL VEREDICTO, NO LA REPARACION COMPLETA. Una garantía puede
 * tardar semanas y eso no es incumplimiento; lo que no puede pasar es que a las
 * 72 horas nadie haya decidido por cuál de las cuatro vías va. Confundir las
 * dos cosas haría que el tablero marque en rojo trabajos que van bien, y a un
 * tablero que se pone rojo sin razón se le deja de hacer caso.
 *
 * ============================================================================
 * POR QUE ESTO NO ES UNA LISTA DE REPUESTOS
 *
 * Empezó siéndolo. El pedido de repuesto es la vía más frecuente, pero es UNA
 * de las cuatro, y modelarlo como «lista de repuestos» dejaba fuera al equipo
 * que se manda a garantía —que también está parado, también corre el mismo
 * reloj y también hay que responderle a KFC—. Lo que se registra es el hecho:
 * un equipo quedó sin concluir. La vía es la decisión que viene después.
 *
 * ============================================================================
 * EL ALCANCE SE FILTRA EN EL SERVIDOR, igual que en el buzón:
 *   TECNICO    -> los que él abrió y los de los casos que tiene asignados
 *   JEFE_ZONA  -> los de su zona
 *   ADMIN/SUPER-> los tres
 * Y en la cláusula WHERE, no escondiendo filas al dibujar: las que no
 * corresponden nunca salen de la consulta.
 *
 * SI LA MIGRACION 007 NO ESTA APLICADA, esta clase lo dice y devuelve listas
 * vacías en vez de reventar con «Table doesn't exist» encima de la pantalla.
 * Es la regla I-7 llevada a la interfaz: si no hay dato, se dice.
 */
final class Pendientes
{
    /** Las cuatro vías del veredicto: etiqueta, ayuda y qué estado abre. */
    public const VIAS = [
        'REPUESTO'   => ['Esperar repuesto',   'Se compra la pieza y se instala. Es la vía más frecuente.', 'COTIZANDO'],
        'REPARACION' => ['Enviar a reparación', 'El equipo sale del local a un taller.',                     'EN_TALLER'],
        'GARANTIA'   => ['Reclamar garantía',   'Le toca al fabricante o al proveedor, sin costo para el cliente.', 'GARANTIA_RECLAMADA'],
        'BAJA'       => ['Dar de baja',         'No tiene arreglo razonable. Se propone a Grupo KFC con el informe técnico.', 'BAJA_PROPUESTA'],
    ];

    /** Qué pasos tiene cada vía, en orden. Es lo que la pantalla ofrece. */
    public const PASOS = [
        'REPUESTO'   => ['COTIZANDO', 'COMPRADO', 'EN_BODEGA', 'ENTREGADO', 'RESUELTO'],
        'REPARACION' => ['EN_TALLER', 'DEVUELTO_TALLER', 'RESUELTO'],
        'GARANTIA'   => ['GARANTIA_RECLAMADA', 'GARANTIA_APROBADA', 'GARANTIA_NEGADA', 'RESUELTO'],
        'BAJA'       => ['BAJA_PROPUESTA', 'BAJA_APROBADA', 'RESUELTO'],
    ];

    public const ESTADOS = [
        'SIN_VEREDICTO'      => ['sin veredicto',      'Corre el plazo de 48 h: nadie ha decidido todavía la vía'],
        'COTIZANDO'          => ['cotizando',          'La administración está pidiendo precios de la pieza'],
        'COMPRADO'           => ['comprado',           'La orden de compra salió; se espera al proveedor'],
        'EN_BODEGA'          => ['en bodega',          'Llegó a INDUSTEC; falta llevarlo al local'],
        'ENTREGADO'          => ['entregado',          'Está en el local, en manos del técnico'],
        'EN_TALLER'          => ['en el taller',       'El equipo salió del local a reparación'],
        'DEVUELTO_TALLER'    => ['vuelto del taller',  'Regresó reparado; falta montarlo'],
        'GARANTIA_RECLAMADA' => ['garantía reclamada', 'Se presentó el reclamo al fabricante o proveedor'],
        'GARANTIA_APROBADA'  => ['garantía aceptada',  'La cubren; se espera el reemplazo'],
        'GARANTIA_NEGADA'    => ['garantía negada',    'No la cubren. Hay que volver a decidir la vía'],
        'BAJA_PROPUESTA'     => ['baja propuesta',     'Se propuso a Grupo KFC dar de baja el equipo'],
        'BAJA_APROBADA'      => ['baja aprobada',      'Grupo KFC aceptó la baja'],
        'RESUELTO'           => ['resuelto',           'El equipo volvió a operar, o su baja quedó ejecutada'],
        'CANCELADO'          => ['cancelado',          'No procedía; se resolvió de otra forma'],
    ];

    /** Los que todavía cuentan como pendiente de alguien. */
    public const ABIERTOS = ['SIN_VEREDICTO', 'COTIZANDO', 'COMPRADO', 'EN_BODEGA', 'ENTREGADO',
                             'EN_TALLER', 'DEVUELTO_TALLER', 'GARANTIA_RECLAMADA',
                             'GARANTIA_APROBADA', 'GARANTIA_NEGADA', 'BAJA_PROPUESTA', 'BAJA_APROBADA'];

    /* Las horas del reloj las calcula MySQL, con sus propias fechas: restar
       strtotime() contra time() solo cuadra si la base y el PHP de la web
       corren en la misma zona. Desde T2.13.7 las dos van en hora de Ecuador
       porque las fija Db.php; antes las fijaba el hosting (UTC en Hostinger),
       y calcularlo aquí sigue sin depender de eso. */
    private const MINUTOS =
        'TIMESTAMPDIFF(MINUTE, COALESCE(p.plazo_desde, p.abierto_en), NOW()) AS min_plazo,
         TIMESTAMPDIFF(MINUTE, COALESCE(p.plazo_desde, p.abierto_en), p.veredicto_en) AS min_veredicto';

    private static function lista_(array $x): string { return "'" . implode("','", $x) . "'"; }

    /**
     * ¿Está aplicada la migración 007?
     *
     * Se comprueba una vez por petición. Sin esto, cada pantalla que toque
     * pendientes revienta con un error de MySQL en crudo delante del usuario
     * mientras el SQL espera aprobación para aplicarse.
     */
    public static function disponible(): bool
    {
        static $hay = null;
        if ($hay !== null) { return $hay; }
        try {
            Db::todos('SELECT 1 FROM pendientes LIMIT 1');
            return $hay = true;
        } catch (Throwable $e) {
            return $hay = false;
        }
    }

    public static function etiquetaEstado(?string $e): string
    {
        return self::ESTADOS[strtoupper((string) $e)][0] ?? strtolower(str_replace('_', ' ', (string) $e));
    }
    public static function ayudaEstado(?string $e): string
    {
        return self::ESTADOS[strtoupper((string) $e)][1] ?? '';
    }
    public static function etiquetaVia(?string $v): string
    {
        return self::VIAS[strtoupper((string) $v)][0] ?? 'sin veredicto';
    }

    /**
     * El trozo de WHERE que impone el alcance, con sus parámetros.
     *
     * Vive aquí y no copiado en cada consulta porque son cinco pantallas las
     * que lo necesitan, y la copia que se olvide de actualizar es la que enseña
     * los pendientes de otra zona.
     *
     * @return array{0:string,1:array}
     */
    private static function alcance(): array
    {
        $u = Auth::actual();
        if (!$u) { return ['1=0', []]; }
        if ($u['rol'] === 'TECNICO') {
            // El técnico ve lo que él abrió y lo de sus casos asignados: si le
            // reasignan un caso, hereda el equipo trabado (antes no lo veía, se
            // le ofrecía «No pude concluir» y pisaba el diagnóstico de otro).
            // No los de su zona: el inventario de sus compañeros no le ayuda.
            return ['(p.abierto_por = ? OR p.aviso IN (SELECT aviso FROM casos_gestion WHERE asignado_a = ?))',
                    [(int) $u['usuario_id'], (int) $u['usuario_id']]];
        }
        // La zona que vale es la del caso, no la que tenía al abrirse: derivar un
        // caso cambia `casos_gestion.zona`, y el pendiente tiene que irse con él.
        // Antes el jefe de la zona vieja lo seguía viendo y podía dar el
        // veredicto, y el de la nueva no se enteraba. Igual que Casos::enAlcance.
        $zona = Auth::zonaAlcance();
        if ($zona !== null) {
            return ['COALESCE((SELECT g.zona FROM casos_gestion g WHERE g.aviso = p.aviso), p.zona) = ?', [$zona]];
        }
        return ['1=1', []];
    }

    /**
     * Los pendientes que este usuario alcanza.
     *
     * @param array $f  'grupo' => 'abiertos'|'cerrados'|'sin_veredicto'|'vencidos',
     *                  'via' => <una>, 'parado' => bool, 'q' => texto
     */
    public static function lista(array $f = []): array
    {
        if (!self::disponible()) { return []; }
        [$donde, $par] = self::alcance();

        $g = (string) ($f['grupo'] ?? 'abiertos');
        if ($g === 'abiertos') {
            $donde .= ' AND p.estado IN (' . self::lista_(self::ABIERTOS) . ')';
        } elseif ($g === 'cerrados') {
            $donde .= " AND p.estado IN ('RESUELTO','CANCELADO')";
        } elseif ($g === 'sin_veredicto') {
            $donde .= " AND p.via = 'SIN_VEREDICTO' AND p.estado NOT IN ('RESUELTO','CANCELADO')";
        } elseif ($g === 'vencidos') {
            // Vencido = deshabilitado, sin veredicto y con más de 48 h encima.
            // Se calcula en SQL para que la cifra del globo de navegación y la
            // de la pantalla salgan del mismo sitio y no puedan discrepar.
            $donde .= " AND p.via = 'SIN_VEREDICTO' AND p.deshabilitado = 1
                        AND p.estado NOT IN ('RESUELTO','CANCELADO')
                        AND COALESCE(p.plazo_desde, p.abierto_en) < DATE_SUB(NOW(), INTERVAL 48 HOUR)";
        }

        if (!empty($f['via']) && isset(self::VIAS[$f['via']])) {
            $donde .= ' AND p.via = ?';
            $par[] = $f['via'];
        }
        if (!empty($f['parado'])) { $donde .= ' AND p.deshabilitado = 1'; }
        if (!empty($f['q'])) {
            $donde .= ' AND (p.parte LIKE ? OR p.aviso LIKE ? OR p.local_codigo LIKE ?
                             OR p.activo_fijo LIKE ? OR p.equipo_desc LIKE ? OR p.diagnostico LIKE ?)';
            $like = '%' . $f['q'] . '%';
            for ($i = 0; $i < 6; $i++) { $par[] = $like; }
        }

        $filas = Db::todos(
            "SELECT p.*, a.nombre AS abrio, a.usuario AS abrio_usuario,
                    v.nombre AS decidio, g.nombre AS gestor, " . self::MINUTOS . "
               FROM pendientes p
               JOIN usuarios a ON a.usuario_id = p.abierto_por
          LEFT JOIN usuarios v ON v.usuario_id = p.veredicto_por
          LEFT JOIN usuarios g ON g.usuario_id = p.gestionado_por
              WHERE $donde
              /* El orden ES la prioridad de trabajo, no una preferencia:
                 primero lo que está parado sin veredicto (el reloj corriendo),
                 después el resto de lo parado, y dentro de cada grupo lo más
                 viejo arriba, que es lo que lleva más tiempo esperando. */
              ORDER BY (p.deshabilitado = 1 AND p.via = 'SIN_VEREDICTO') DESC,
                       p.deshabilitado DESC, p.abierto_en ASC",
            $par
        );

        foreach ($filas as &$p) {
            $p['abierto'] = in_array($p['estado'], self::ABIERTOS, true);
            $p['reloj'] = self::reloj($p);
        }
        return $filas;
    }

    /**
     * El reloj de un pendiente.
     *
     * Devuelve null cuando no corresponde medirlo: el equipo sigue operando, ya
     * se dio el veredicto, o el pendiente está cerrado. Medir lo que no aplica
     * es lo que llena un tablero de rojos que nadie mira.
     */
    public static function reloj(array $p): ?array
    {
        if (empty($p['deshabilitado'])) { return null; }
        if (!in_array($p['estado'], self::ABIERTOS, true)) { return null; }
        if (($p['via'] ?? 'SIN_VEREDICTO') !== 'SIN_VEREDICTO') {
            // Ya hay veredicto: el plazo se cumplió, y se guarda cómo se cumplió.
            $h = ($p['min_veredicto'] ?? null) !== null ? (int) $p['min_veredicto'] / 60 : null;
            return $h === null ? null : [
                'clase'   => $h <= 48 ? 'edad edad-hoy' : 'edad edad-viejo',
                'texto'   => $h <= 48 ? 'decidido en ' . round($h) . ' h'
                                      : 'se decidió a las ' . round($h) . ' h',
                'vencido' => $h > 48, 'horas' => $h, 'cerrado' => true,
            ];
        }
        // Ui::reloj48 resta contra time(): se le da un instante del reloj de PHP
        // que ya lleva dentro los minutos que midió MySQL.
        $desde = ($p['min_plazo'] ?? null) !== null
               ? date('Y-m-d H:i:s', time() - (int) $p['min_plazo'] * 60)
               : (string) $p['abierto_en'];
        $r = Ui::reloj48($desde);
        $r['cerrado'] = false;
        return $r;
    }

    /** Un pendiente, solo si está en el alcance de quien pregunta. */
    public static function uno(int $id): ?array
    {
        if (!self::disponible()) { return null; }
        [$donde, $par] = self::alcance();
        array_unshift($par, $id);
        $p = Db::uno(
            "SELECT p.*, a.nombre AS abrio, v.nombre AS decidio, " . self::MINUTOS . "
               FROM pendientes p
               JOIN usuarios a ON a.usuario_id = p.abierto_por
          LEFT JOIN usuarios v ON v.usuario_id = p.veredicto_por
              WHERE p.pendiente_id = ? AND ($donde)", $par
        );
        if (!$p) { return null; }
        $p['abierto'] = in_array($p['estado'], self::ABIERTOS, true);
        $p['reloj'] = self::reloj($p);
        return $p;
    }

    /** El hilo, del más viejo al más nuevo: se lee como una historia. */
    public static function notas(int $id): array
    {
        if (!self::disponible()) { return []; }
        return Db::todos(
            'SELECT n.*, u.nombre, u.rol FROM pendiente_notas n
               JOIN usuarios u ON u.usuario_id = n.usuario_id
              WHERE n.pendiente_id = ? ORDER BY n.creado_en ASC', [$id]
        );
    }

    /** Las notas de varios pendientes de una vez, para no consultar en el bucle. */
    public static function notasDe(array $ids): array
    {
        if (!self::disponible() || !$ids) { return []; }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $filas = Db::todos(
            "SELECT n.*, u.nombre, u.rol FROM pendiente_notas n
               JOIN usuarios u ON u.usuario_id = n.usuario_id
              WHERE n.pendiente_id IN ($marcas) ORDER BY n.creado_en ASC",
            array_map('intval', $ids)
        );
        $out = [];
        foreach ($filas as $n) { $out[(int) $n['pendiente_id']][] = $n; }
        return $out;
    }

    /**
     * Abrir un pendiente: declarar que un equipo quedó sin concluir.
     *
     * Es idempotente por la clave de negocio (aviso, activo_fijo): el técnico
     * que pulsa dos veces porque la pantalla no respondió no abre dos relojes.
     * Lo que sí hace el segundo intento es AGRAVAR —si ahora dice que el equipo
     * quedó parado, la bandera sube—, porque la situación pudo empeorar entre
     * un intento y otro.
     *
     * @return array{0:bool,1:string,2:?int}
     */
    public static function abrir(array $d): array
    {
        if (!self::disponible()) {
            return [false, 'El módulo de pendientes todavía no está instalado en la base.', null];
        }
        // El permiso se comprueba aquí y no solo escondiendo el botón: mis.php y
        // envio.php llegaban hasta este punto sin mirarlo.
        if (!Auth::puede('repuestos.pedir')) {
            Auth::bitacora('DENEGADO', 'pendiente', (string) ($d['aviso'] ?? ''), 'abrir sin permiso',
                           null, null, [], false);
            return [false, 'No tienes permiso para registrar equipos sin concluir.', null];
        }
        $u = Auth::actual();
        $aviso = trim((string) ($d['aviso'] ?? ''));
        $diag  = trim((string) ($d['diagnostico'] ?? ''));

        if ($aviso === '') { return [false, 'El pendiente tiene que ir contra un caso.', null]; }
        if ($diag === '') {
            // Es obligatorio a propósito. La premisa del servicio es que una
            // intervención concluye; dejar un equipo sin concluir exige decir
            // por qué, y ese texto es lo que después sostiene el veredicto y la
            // respuesta a Grupo KFC.
            return [false, 'Escribe qué encontraste y por qué no se pudo concluir.', null];
        }

        $caso = Casos::alcanzaAviso($aviso, Casos::gestion());
        if ($caso === null) {
            Auth::bitacora('DENEGADO', 'pendiente', $aviso, 'abrir fuera de alcance',
                           null, null, [], false);
            return [false, 'Ese caso no existe o no está en tu alcance.', null];
        }

        $parado = !empty($d['deshabilitado']) ? 1 : 0;
        // Vacío no es «sin dato»: si la orden no trae el equipo, vale el del
        // caso. Con `??` el '' que manda la app nunca caía al del caso, y el
        // mismo equipo reportado por la app y por la bandeja abría dos relojes.
        $activo = trim((string) ($d['activo_fijo'] ?? ''));
        if ($activo === '') { $activo = trim((string) ($caso['activo_fijo'] ?? '')); }
        $activo = mb_substr($activo, 0, 60);
        // El reloj arranca cuando el técnico lo reportó en el celular, acotado a
        // las últimas 72 h: a un reloj de teléfono no se le cree sin límite.
        $ts = isset($d['abierto_ts']) ? (int) $d['abierto_ts'] : null;
        if ($ts !== null && $ts > time()) { $ts = null; }
        // Para compararlo con un cierre vale la hora del reporte aunque sea
        // vieja (envio.php ya la acota a 7 días); para arrancar el reloj, no.
        $tsReporte = $ts;
        if ($ts !== null && $ts < time() - 72 * 3600) { $ts = null; }
        $parte    = ($d['parte'] ?? '') !== '' ? mb_substr(trim((string) $d['parte']), 0, 160) : null;
        $desc     = mb_substr(trim((string) ($d['equipo_desc'] ?? '')), 0, 160);
        $cantidad = max(1, (int) ($d['cantidad'] ?? 1));

        $previo = Db::uno('SELECT pendiente_id, estado, diagnostico FROM pendientes
                            WHERE aviso = ? AND activo_fijo = ?', [$aviso, $activo]);
        $reabre = false;
        $tardio = false;
        if ($previo === null) {
            Db::ejecutar(
                'INSERT INTO pendientes
                    (aviso, zona, local_codigo, cadena, activo_fijo, equipo_desc,
                     deshabilitado, diagnostico, parte, cantidad, abierto_por, abierto_en)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?, COALESCE(FROM_UNIXTIME(?), NOW()))
                 ON DUPLICATE KEY UPDATE deshabilitado = GREATEST(deshabilitado, VALUES(deshabilitado))',
                [$aviso, $caso['zona'] ?? null, $caso['local'] ?? null, $caso['cadena'] ?? null,
                 $activo, $desc, $parado, mb_substr($diag, 0, 600), $parte, $cantidad,
                 (int) $u['usuario_id'], $ts]
            );
        } elseif (in_array($previo['estado'], self::ABIERTOS, true)) {
            // El mismo episodio: agrava si ahora está parado, y el diagnóstico
            // nuevo va al hilo en vez de pisar el que sostiene el veredicto.
            Db::ejecutar(
                'UPDATE pendientes SET deshabilitado = GREATEST(deshabilitado, ?),
                        parte = COALESCE(?, parte), cantidad = GREATEST(cantidad, ?)
                  WHERE pendiente_id = ?',
                [$parado, $parte, $cantidad, (int) $previo['pendiente_id']]
            );
        } else {
            // Cerrado. Si el reporte es posterior al cierre, el equipo volvió a
            // fallar: episodio nuevo, con su propio reloj, y el diagnóstico
            // anterior queda en el hilo (antes el upsert lo dejaba RESUELTO y
            // parado sin reloj). Si es ANTERIOR —una orden que esperó sin señal
            // mientras alguien lo resolvía—, no se reabre nada: nacería vencido.
            // La comparación la hace MySQL, en su propio reloj.
            $posterior = Db::uno('SELECT (cerrado_en IS NULL
                                          OR cerrado_en < COALESCE(FROM_UNIXTIME(?), NOW())) AS p
                                    FROM pendientes WHERE pendiente_id = ?',
                                 [$tsReporte, (int) $previo['pendiente_id']]);
            if (!(int) ($posterior['p'] ?? 1)) {
                $tardio = true;
            } else {
                $reabre = true;
                self::anotar((int) $previo['pendiente_id'], 'DIAGNOSTICO',
                             'Episodio anterior (' . self::etiquetaEstado($previo['estado']) . '): '
                             . $previo['diagnostico'], false);
                Db::ejecutar(
                    "UPDATE pendientes
                        SET via = 'SIN_VEREDICTO', estado = 'SIN_VEREDICTO', deshabilitado = ?,
                            diagnostico = ?, parte = ?, cantidad = ?,
                            equipo_desc = COALESCE(NULLIF(?, ''), equipo_desc),
                            abierto_por = ?, abierto_en = COALESCE(FROM_UNIXTIME(?), NOW()),
                            plazo_desde = NULL, veredicto_por = NULL, veredicto_en = NULL,
                            veredicto_nota = NULL, prometido_para = NULL,
                            gestionado_por = NULL, gestionado_en = NULL,
                            cerrado_en = NULL, nota_cierre = NULL, insistencias = 0
                      WHERE pendiente_id = ?",
                    [$parado, mb_substr($diag, 0, 600), $parte, $cantidad, $desc,
                     (int) $u['usuario_id'], $ts, (int) $previo['pendiente_id']]
                );
            }
        }

        $fila = Db::uno('SELECT pendiente_id FROM pendientes WHERE aviso = ? AND activo_fijo = ?',
                        [$aviso, $activo]);
        $id = (int) ($fila['pendiente_id'] ?? 0);
        if ($previo !== null && !$reabre) {
            self::anotar($id, 'DIAGNOSTICO',
                         ($tardio ? 'Llegó después del cierre (se capturó antes): ' : '') . $diag, false);
        }
        if ($tardio) {
            Auth::bitacora('PENDIENTE_REPORTE_TARDIO', 'pendiente', (string) $id,
                           mb_substr($diag, 0, 120), $previo['estado'], $previo['estado'],
                           ['aviso' => $aviso, 'equipo' => $activo]);
            return [true, 'Ese equipo ya se había resuelto después de este reporte: quedó anotado '
                        . 'en su historial, sin reabrirlo.', $id];
        }

        // El caso pasa a ESPERA_REPUESTO. No es un limbo: dice que el técnico
        // fue, diagnosticó, y el equipo depende de algo. `Reconciliar` trata
        // ese estado como intocable, así que no lo cierra por falta de atención
        // ni lo marca ATENDIDO antes de que el equipo vuelva a operar.
        // También si ya estaba ATENDIDO o cerrado por falta de atención: un
        // equipo parado manda sobre una orden emitida. Si no, la pantalla le
        // pedía a la administradora cerrarlo en SAP con el equipo parado.
        Casos::asegurar($aviso, $caso['zona'] ?? null);
        Db::ejecutar(
            "UPDATE casos_gestion SET estado = 'ESPERA_REPUESTO'
              WHERE aviso = ? AND estado IN ('NUEVO','ASIGNADO','EN_REVISION','ATENDIDO','CERRADO_SIN_ATENCION')",
            [$aviso]
        );

        if (trim((string) ($d['nota'] ?? '')) !== '') {
            self::anotar($id, 'RECORDATORIO', (string) $d['nota'], $parado === 1);
        }

        Auth::bitacora('PENDIENTE_ABRE', 'pendiente', (string) $id,
                       mb_substr($diag, 0, 120) . ($parado ? ' · EQUIPO PARADO' : ''),
                       null, 'SIN_VEREDICTO',
                       ['aviso' => $aviso, 'equipo' => $activo, 'parado' => $parado,
                        'zona' => $caso['zona'] ?? null]);

        $inicio = $reabre ? 'Reabierto: el equipo volvió a quedar sin concluir. ' : 'Registrado. ';
        return [true, $inicio . ($parado
            ? 'El equipo consta como deshabilitado: hay 48 horas para el veredicto.'
            : 'El equipo sigue operando, así que no corre el plazo de 48 horas.'), $id];
    }

    /**
     * El veredicto de las 48 horas: por cuál de las cuatro vías va.
     *
     * Lo da el jefe de zona o la administración. El técnico no: él aporta el
     * diagnóstico, que es la evidencia. Es la misma separación de todo el
     * sistema — quien ve el hecho lo reporta, quien responde ante el cliente
     * decide.
     */
    public static function veredicto(int $id, string $via, string $nota, ?string $prometido): array
    {
        if (!Auth::puede('repuestos.veredicto')) {
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, 'veredicto sin permiso',
                           null, $via, [], false);
            return [false, 'No tienes permiso para dar el veredicto.'];
        }
        $p = self::uno($id);
        if ($p === null) {
            // El rechazo por alcance deja rastro igual que el de permiso: un POST
            // fabricado contra otra zona tiene que quedar en la bitácora (T2.12.5).
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, __FUNCTION__ . ' fuera de alcance o inexistente',
                           null, null, [], false);
            return [false, 'Ese pendiente no existe o no está en tu alcance.'];
        }
        if (!isset(self::VIAS[$via])) { return [false, 'Esa no es una de las cuatro vías.']; }
        if (!$p['abierto'] || $p['via'] !== 'SIN_VEREDICTO') {
            // Un POST repetido no reescribe un veredicto ya dado ni el indicador.
            return [false, 'Ese pendiente ya tiene veredicto o está cerrado.'];
        }

        // Dar de baja un activo del cliente y negar una garantía son decisiones
        // que se le explican a Grupo KFC. Sin motivo escrito no se sostienen.
        if ($via === 'BAJA' && trim($nota) === '') {
            return [false, 'Para proponer la baja del equipo hace falta el sustento técnico.'];
        }

        $fecha = null;
        if ($prometido !== null && trim($prometido) !== '') {
            $t = strtotime($prometido);
            if ($t === false) { return [false, 'La fecha comprometida no es una fecha.']; }
            $fecha = date('Y-m-d', $t);
        }

        $u = Auth::actual();
        $nuevo = self::VIAS[$via][2];
        $horas = (int) ($p['min_plazo'] ?? 0) / 60;

        Db::ejecutar(
            'UPDATE pendientes
                SET via = ?, estado = ?, veredicto_por = ?, veredicto_en = NOW(),
                    veredicto_nota = NULLIF(?, ""),
                    prometido_para = COALESCE(?, prometido_para),
                    tercero = COALESCE(NULLIF(?, ""), tercero)
              WHERE pendiente_id = ? AND via = "SIN_VEREDICTO"',
            [$via, $nuevo, (int) $u['usuario_id'], mb_substr(trim($nota), 0, 600),
             $fecha, '', $id]
        );

        self::anotar($id, 'VEREDICTO',
                     self::etiquetaVia($via) . ($nota !== '' ? ' — ' . $nota : ''),
                     false, $p['estado'], $nuevo);

        Auth::bitacora('PENDIENTE_VEREDICTO', 'pendiente', (string) $id,
                       self::etiquetaVia($via) . ' a las ' . round($horas) . ' h',
                       $p['estado'], $nuevo,
                       ['via' => $via, 'horas_hasta_veredicto' => round($horas, 1),
                        'dentro_de_48' => $horas <= 48, 'aviso' => $p['aviso'],
                        'prometido' => $fecha]);

        return [true, 'Veredicto: ' . self::etiquetaVia($via) . '.'
                    . ($p['deshabilitado'] && $horas > 48
                        ? ' Se dio a las ' . round($horas) . ' h, fuera del plazo de 48.'
                        : '')];
    }

    /**
     * Avanzar dentro de la vía ya decidida.
     *
     * Solo se ofrecen los pasos de ESA vía: un pendiente que va por garantía no
     * puede pasar a «en bodega». El ENUM de la base no puede expresar esa
     * regla —depende de otra columna— así que se impone aquí, y se impone en el
     * servidor porque un POST se fabrica a mano.
     */
    public static function mover(int $id, string $nuevo, string $nota, ?string $prometido): array
    {
        if (!Auth::puede('repuestos.gestionar')) {
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, 'mover sin permiso',
                           null, $nuevo, [], false);
            return [false, 'No tienes permiso para gestionar pendientes.'];
        }
        $p = self::uno($id);
        if ($p === null) {
            // El rechazo por alcance deja rastro igual que el de permiso: un POST
            // fabricado contra otra zona tiene que quedar en la bitácora (T2.12.5).
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, __FUNCTION__ . ' fuera de alcance o inexistente',
                           null, null, [], false);
            return [false, 'Ese pendiente no existe o no está en tu alcance.'];
        }
        if (!isset(self::ESTADOS[$nuevo])) { return [false, 'Estado no válido.']; }
        if ($nuevo === $p['estado']) { return [false, 'El pendiente ya está en ese estado.']; }
        if (!$p['abierto']) { return [false, 'El pendiente ya está cerrado.']; }

        $via = (string) $p['via'];
        if ($via === 'SIN_VEREDICTO' && $nuevo !== 'CANCELADO') {
            return [false, 'Primero hay que dar el veredicto: repuesto, reparación, garantía o baja.'];
        }
        $permitidos = array_merge(self::PASOS[$via] ?? [], ['CANCELADO']);
        if (!in_array($nuevo, $permitidos, true)) {
            return [false, 'Ese paso no pertenece a la vía «' . self::etiquetaVia($via) . '».'];
        }
        if ($nuevo === 'CANCELADO' && trim($nota) === '') {
            return [false, 'Para cancelar hace falta el motivo.'];
        }

        $fecha = null;
        if ($prometido !== null && trim($prometido) !== '') {
            $t = strtotime($prometido);
            if ($t === false) { return [false, 'La fecha comprometida no es una fecha.']; }
            $fecha = date('Y-m-d', $t);
        }

        $u = Auth::actual();
        $cierra = in_array($nuevo, ['RESUELTO', 'CANCELADO'], true);
        Db::ejecutar(
            'UPDATE pendientes
                SET estado = ?, gestionado_por = ?, gestionado_en = NOW(),
                    prometido_para = COALESCE(?, prometido_para),
                    cerrado_en  = ' . ($cierra ? 'NOW()' : 'cerrado_en') . ',
                    nota_cierre = ' . ($cierra ? 'NULLIF(?, "")' : 'nota_cierre') . '
              WHERE pendiente_id = ?',
            $cierra
                ? [$nuevo, (int) $u['usuario_id'], $fecha, mb_substr(trim($nota), 0, 400), $id]
                : [$nuevo, (int) $u['usuario_id'], $fecha, $id]
        );

        self::anotar($id, 'CAMBIO_ESTADO',
                     $nota !== '' ? $nota : 'Pasó a: ' . self::etiquetaEstado($nuevo),
                     false, $p['estado'], $nuevo);

        // La garantía negada devuelve el pendiente a la casilla de salida: hay
        // que volver a decidir entre comprar, reparar o dar de baja, y el reloj
        // vuelve a tener sentido porque el equipo sigue parado.
        if ($nuevo === 'GARANTIA_NEGADA') {
            // El plazo se reinicia desde la negativa, y el veredicto anterior sale
            // de la cuenta de a tiempo/tarde: el mismo equipo contaba a la vez
            // como a tiempo y como vencido. Sus horas quedan en la bitácora.
            Db::ejecutar("UPDATE pendientes SET via = 'SIN_VEREDICTO', plazo_desde = NOW(),
                                 veredicto_por = NULL, veredicto_en = NULL
                           WHERE pendiente_id = ?", [$id]);
        }

        // El caso vuelve a la corriente cuando ya no queda nada esperando.
        if ($cierra) {
            $otros = Db::uno(
                'SELECT COUNT(*) c FROM pendientes
                  WHERE aviso = ? AND estado IN (' . self::lista_(self::ABIERTOS) . ')',
                [$p['aviso']]
            );
            if ((int) ($otros['c'] ?? 0) === 0) {
                // Si el caso ya tenía orden de cierre vuelve a ATENDIDO, no a la
                // bandeja como trabajo abierto: `abrir` lo pasa a ESPERA_REPUESTO
                // también desde ATENDIDO.
                Db::ejecutar(
                    "UPDATE casos_gestion
                        SET estado = CASE WHEN ot_cierre IS NOT NULL THEN 'ATENDIDO'
                                          WHEN asignado_a IS NULL THEN 'NUEVO'
                                          ELSE 'ASIGNADO' END
                      WHERE aviso = ? AND estado = 'ESPERA_REPUESTO'",
                    [$p['aviso']]
                );
            }
        }

        Auth::bitacora('PENDIENTE_MUEVE', 'pendiente', (string) $id,
                       self::etiquetaEstado($p['estado']) . ' -> ' . self::etiquetaEstado($nuevo),
                       $p['estado'], $nuevo,
                       ['aviso' => $p['aviso'], 'via' => $via, 'prometido' => $fecha]);

        return [true, 'Pendiente: ' . self::etiquetaEstado($nuevo) . '.'];
    }

    /**
     * Insistir. Es la acción que hoy es un mensaje de WhatsApp.
     *
     * El corte contra el doble envío se hace aquí y no con una restricción del
     * esquema: insistir dos veces es legítimo —es justo el dato que hay que
     * conservar—, lo que no es legítimo es que un toque doble en una pantalla
     * lenta cuente como dos insistencias. Noventa segundos separan las dos
     * cosas sin dejar fuera ninguna insistencia de verdad.
     */
    public static function insistir(int $id, string $texto, bool $urgente): array
    {
        if (!Auth::puede('repuestos.pedir')) {
            return [false, 'No tienes permiso para escribir aquí.'];
        }
        $p = self::uno($id);
        if ($p === null) {
            // El rechazo por alcance deja rastro igual que el de permiso: un POST
            // fabricado contra otra zona tiene que quedar en la bitácora (T2.12.5).
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, __FUNCTION__ . ' fuera de alcance o inexistente',
                           null, null, [], false);
            return [false, 'Ese pendiente no existe o no está en tu alcance.'];
        }

        $texto = trim($texto);
        if ($texto === '') { return [false, 'Escribe qué quieres decirle a la administración.']; }

        $u = Auth::actual();
        $rep = Db::uno(
            'SELECT nota_id FROM pendiente_notas
              WHERE pendiente_id = ? AND usuario_id = ? AND texto = ?
                AND creado_en > DATE_SUB(NOW(), INTERVAL 90 SECOND)',
            [$id, (int) $u['usuario_id'], mb_substr($texto, 0, 600)]
        );
        if ($rep) { return [true, 'Ese recordatorio ya se envió hace un momento.']; }

        self::anotar($id, 'RECORDATORIO', $texto, $urgente);
        Auth::bitacora('PENDIENTE_INSISTE', 'pendiente', (string) $id,
                       ($urgente ? 'URGENTE: ' : '') . mb_substr($texto, 0, 120),
                       $p['estado'], $p['estado'],
                       ['urgente' => $urgente, 'aviso' => $p['aviso'],
                        'van' => (int) $p['insistencias'] + 1]);

        return [true, 'Recordatorio enviado. Queda registrado con la fecha.'];
    }

    /** Responder en el hilo sin mover el estado. */
    public static function responder(int $id, string $texto): array
    {
        if (!Auth::puede('repuestos.gestionar')) {
            return [false, 'No tienes permiso para responder aquí.'];
        }
        $p = self::uno($id);
        if ($p === null) {
            // El rechazo por alcance deja rastro igual que el de permiso: un POST
            // fabricado contra otra zona tiene que quedar en la bitácora (T2.12.5).
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, __FUNCTION__ . ' fuera de alcance o inexistente',
                           null, null, [], false);
            return [false, 'Ese pendiente no existe o no está en tu alcance.'];
        }
        if (trim($texto) === '') { return [false, 'Escribe la respuesta.']; }

        self::anotar($id, 'RESPUESTA', $texto, false);
        Auth::bitacora('PENDIENTE_RESPONDE', 'pendiente', (string) $id,
                       mb_substr($texto, 0, 120), $p['estado'], $p['estado']);
        return [true, 'Respuesta enviada. El técnico la ve en su bandeja.'];
    }

    private static function anotar(int $id, string $tipo, string $texto, bool $urgente,
                                   ?string $antes = null, ?string $desp = null): void
    {
        $u = Auth::actual();
        Db::ejecutar(
            'INSERT INTO pendiente_notas
                (pendiente_id, usuario_id, tipo, texto, urgente, estado_antes, estado_desp)
             VALUES (?,?,?,?,?,?,?)',
            [$id, (int) $u['usuario_id'], $tipo, mb_substr(trim($texto), 0, 600),
             $urgente ? 1 : 0, $antes, $desp]
        );
    }

    /**
     * Los números del panel y de los globos de navegación.
     *
     * Una sola consulta agrupada. Con una por cifra eran seis viajes a la base
     * en cada carga de cada pantalla, y esto se dibuja en todas.
     */
    public static function contadores(): array
    {
        $cero = ['abiertos' => 0, 'parados' => 0, 'sin_veredicto' => 0, 'vencidos' => 0,
                 'insistidos' => 0, 'cerrados_semana' => 0];
        if (!self::disponible()) { return $cero; }
        [$donde, $par] = self::alcance();
        $ab = self::lista_(self::ABIERTOS);

        $f = Db::uno(
            "SELECT
                SUM(p.estado IN ($ab))                                        AS abiertos,
                SUM(p.estado IN ($ab) AND p.deshabilitado = 1)                AS parados,
                SUM(p.estado IN ($ab) AND p.via = 'SIN_VEREDICTO')            AS sin_veredicto,
                SUM(p.estado IN ($ab) AND p.via = 'SIN_VEREDICTO' AND p.deshabilitado = 1
                    AND COALESCE(p.plazo_desde, p.abierto_en) < DATE_SUB(NOW(), INTERVAL 48 HOUR)) AS vencidos,
                SUM(p.estado IN ($ab) AND p.insistencias >= 2)                AS insistidos,
                SUM(p.estado IN ('RESUELTO','CANCELADO')
                    AND p.cerrado_en > DATE_SUB(NOW(), INTERVAL 7 DAY))       AS cerrados_semana
               FROM pendientes p WHERE $donde", $par
        );
        foreach ($cero as $k => $_) { $cero[$k] = (int) ($f[$k] ?? 0); }
        return $cero;
    }

    /**
     * El cumplimiento de las 48 horas, para el reporte.
     *
     * Es EL indicador del compromiso: de los equipos que quedaron parados,
     * cuántos tuvieron veredicto dentro del plazo. Los que siguen sin veredicto
     * y ya se pasaron cuentan como incumplidos ahora mismo, no cuando alguien
     * se acuerde de decidir — si no, el número mejora solo con no decidir.
     */
    public static function cumplimiento48(): array
    {
        if (!self::disponible()) { return ['a_tiempo' => 0, 'tarde' => 0, 'corriendo' => 0, 'vencidos' => 0]; }
        [$donde, $par] = self::alcance();
        $f = Db::uno(
            "SELECT
               SUM(p.veredicto_en IS NOT NULL
                   AND TIMESTAMPDIFF(MINUTE, COALESCE(p.plazo_desde, p.abierto_en), p.veredicto_en) <= 48 * 60) AS a_tiempo,
               SUM(p.veredicto_en IS NOT NULL
                   AND TIMESTAMPDIFF(MINUTE, COALESCE(p.plazo_desde, p.abierto_en), p.veredicto_en) >  48 * 60) AS tarde,
               SUM(p.via = 'SIN_VEREDICTO' AND p.deshabilitado = 1
                   AND p.estado NOT IN ('RESUELTO','CANCELADO')
                   AND COALESCE(p.plazo_desde, p.abierto_en) >= DATE_SUB(NOW(), INTERVAL 48 HOUR)) AS corriendo,
               SUM(p.via = 'SIN_VEREDICTO' AND p.deshabilitado = 1
                   AND p.estado NOT IN ('RESUELTO','CANCELADO')
                   AND COALESCE(p.plazo_desde, p.abierto_en) <  DATE_SUB(NOW(), INTERVAL 48 HOUR)) AS vencidos
              FROM pendientes p
             WHERE $donde AND p.deshabilitado = 1", $par
        );
        return ['a_tiempo' => (int) ($f['a_tiempo'] ?? 0), 'tarde' => (int) ($f['tarde'] ?? 0),
                'corriendo' => (int) ($f['corriendo'] ?? 0), 'vencidos' => (int) ($f['vencidos'] ?? 0)];
    }

    /** La barra de las 48 h: cuánto se lleva consumido y con qué color. */
    public static function barra48(array $p): string
    {
        $r = $p['reloj'] ?? self::reloj($p);
        if ($r === null || !empty($r['cerrado'])) { return ''; }
        $h = (float) $r['horas'];
        $pct = min(100, max(2, $h / 48 * 100));
        $cl = $h >= 48 ? 'mal' : ($h >= 36 ? 'ojo' : 'bien');
        return '<div class="barra48" role="img" aria-label="'
             . ($h >= 48 ? 'Plazo de 48 horas vencido' : 'Van ' . round($h) . ' de 48 horas')
             . '"><i class="' . $cl . '" style="width:' . round($pct) . '%"></i></div>';
    }
}
