<?php
declare(strict_types=1);

require_once __DIR__ . '/Casos.php';
require_once __DIR__ . '/Ui.php';
require_once __DIR__ . '/Catalogo.php';

/**
 * Pendientes.php — El equipo que quedó sin concluir, y su reloj de 48 horas.
 *
 * ============================================================================
 * LA REGLA DE NEGOCIO QUE ESTA CLASE HACE CUMPLIR (desde la 009, 2026-09-12)
 *
 * INDUSTEC trabaja con una premisa: **una intervención concluye el trabajo**.
 * El técnico va, diagnostica y resuelve en esa visita. El único motivo válido
 * para no concluir es que el equipo dependa de una pieza, de un taller o de un
 * tercero — y esos tres dependen, a su vez, de Grupo KFC: los equipos son de
 * KFC, y es KFC quien decide y quien paga.
 *
 * El recorrido real, con sus cuatro actores, es una cadena:
 *
 *   1. EL TÉCNICO solicita, con su diagnóstico.           estado SOLICITADO
 *   2. EL JEFE DE ZONA valida diagnóstico y repuesto,
 *      y fija por cuál de las cuatro vías va.              estado VALIDADO_JEFE
 *   3. LA ADMINISTRACIÓN registra el requerimiento en
 *      SAP, con su número.                                 estado REGISTRADO_SAP
 *   4. GRUPO KFC decide: manda el repuesto, lo repara en
 *      talleres de INDUSTEC, lo manda a otro proveedor,     REPUESTO_ENVIADO /
 *      o lo da de baja.                                     TALLER_INDUSTEC /
 *                                                            OTRO_PROVEEDOR / BAJA
 *
 * El caso queda abierto hasta que el repuesto llegue, el equipo vuelva del
 * taller, o KFC dé su veredicto de baja — eso es lo que cierra el pendiente.
 *
 * Y encima hay un compromiso duro: **un equipo no puede estar deshabilitado
 * más de 48 horas sin que alguien decida qué se hace**. Un local de Grupo KFC
 * con la freidora muerta deja de vender; el reloj no es una meta interna, es
 * el negocio del cliente parado. EL PLAZO MIDE LA VALIDACIÓN DEL JEFE, NO EL
 * CIERRE COMPLETO: registrar en SAP y esperar a KFC puede tardar semanas y eso
 * no es incumplimiento; lo que no puede pasar es que a las 72 horas nadie haya
 * mirado el diagnóstico y decidido por cuál de las cuatro vías va. Confundir
 * las dos cosas haría que el tablero marque en rojo trabajos que van bien, y a
 * un tablero que se pone rojo sin razón se le deja de hacer caso.
 *
 * Las cuatro vías, fijadas por el jefe al validar (no son pasos, son la
 * categoría de la solicitud que se le hace a KFC):
 *
 *   REPUESTO    se pide la pieza por SAP
 *   REPARACION  se pide que el equipo salga a un taller
 *   GARANTIA    se reclama al fabricante o al proveedor
 *   BAJA        no tiene arreglo razonable y se propone a Grupo KFC
 *
 * ============================================================================
 * LO ANTERIOR A LA 009 NO SE BORRA
 *
 * Antes de esto, el sistema modelaba una compra propia de INDUSTEC
 * (COTIZANDO → COMPRADO → EN_BODEGA → ENTREGADO) y un solo veredicto. Las filas
 * que ya estaban en esos pasos (COTIZANDO, COMPRADO, EN_BODEGA, EN_TALLER,
 * GARANTIA_*, BAJA_PROPUESTA) los conservan y se les sigue mostrando bien y
 * dejando avanzar con `mover()` — pero a un pendiente nuevo ya no se le
 * ofrecen: nace `SOLICITADO` y sigue la cadena de arriba.
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
    /** Las cuatro vías, fijadas por el jefe al validar: etiqueta, ayuda y
     *  (legacy) primer paso de cuando esto era una compra propia. */
    public const VIAS = [
        'REPUESTO'   => ['Repuesto',            'Se pide la pieza a Grupo KFC por requerimiento SAP. Es la vía más frecuente.', 'COTIZANDO'],
        'REPARACION' => ['Reparación en taller', 'Se pide que el equipo salga a reparación.',                                   'EN_TALLER'],
        'GARANTIA'   => ['Garantía',             'Le toca al fabricante o al proveedor, sin costo para el cliente.',           'GARANTIA_RECLAMADA'],
        'BAJA'       => ['Baja',                 'No tiene arreglo razonable. Se propone a Grupo KFC con el informe técnico.', 'BAJA_PROPUESTA'],
    ];

    /** Los pasos de la compra propia de ANTES de la 009. Se conservan tal
     *  cual para las filas que ya estaban en ellos (mostrarlos y dejarlos
     *  avanzar); a un pendiente nuevo no se le ofrecen — nace SOLICITADO y
     *  sigue la cadena validar -> SAP -> KFC de la clase. */
    public const PASOS = [
        'REPUESTO'   => ['COTIZANDO', 'COMPRADO', 'EN_BODEGA', 'ENTREGADO', 'RESUELTO'],
        'REPARACION' => ['EN_TALLER', 'DEVUELTO_TALLER', 'RESUELTO'],
        'GARANTIA'   => ['GARANTIA_RECLAMADA', 'GARANTIA_APROBADA', 'GARANTIA_NEGADA', 'RESUELTO'],
        'BAJA'       => ['BAJA_PROPUESTA', 'BAJA_APROBADA', 'RESUELTO'],
    ];

    /** Los pasos DESPUES de que Grupo KFC decide (009). Un solo camino por
     *  decisión: aquí no hace falta «saltar» pasos. OTRO_PROVEEDOR no tiene
     *  camino: `decidioKfc()` lo resuelve directo, porque el trabajo de
     *  INDUSTEC termina al entregar el equipo al tercero. */
    public const PASOS_KFC = [
        'REPUESTO_ENVIADO' => ['REPUESTO_ENVIADO', 'ENTREGADO', 'RESUELTO'],
        'TALLER_INDUSTEC'  => ['TALLER_INDUSTEC', 'DEVUELTO_TALLER', 'RESUELTO'],
        'BAJA'             => ['BAJA_APROBADA', 'RESUELTO'],
    ];

    /** Lo que Grupo KFC puede decidir una vez registrado el requerimiento. */
    private const VEREDICTOS_KFC = [
        'PENDIENTE'        => 'sin decisión de Grupo KFC todavía',
        'REPUESTO_ENVIADO' => 'envía el repuesto',
        'TALLER_INDUSTEC'  => 'a taller de INDUSTEC',
        'OTRO_PROVEEDOR'   => 'a otro proveedor',
        'BAJA'             => 'da de baja el equipo',
    ];

    public const ESTADOS = [
        // -- Anteriores a la 009: solo para las filas que ya estaban aquí. --
        'SIN_VEREDICTO'      => ['sin validar',        '(anterior a la 009) Corre el plazo de 48 h: el jefe de zona no ha validado todavía la vía'],
        'COTIZANDO'          => ['cotizando',          '(anterior a la 009) La administración está pidiendo precios de la pieza'],
        'COMPRADO'           => ['comprado',           '(anterior a la 009) La orden de compra salió; se espera al proveedor'],
        'EN_BODEGA'          => ['en bodega',          '(anterior a la 009) Llegó a INDUSTEC; falta llevarlo al local'],
        'ENTREGADO'          => ['entregado',          'Está en el local, en manos del técnico'],
        'EN_TALLER'          => ['en el taller',       '(anterior a la 009) El equipo salió del local a reparación'],
        'DEVUELTO_TALLER'    => ['vuelto del taller',  'Regresó reparado; falta montarlo'],
        'GARANTIA_RECLAMADA' => ['garantía reclamada', '(anterior a la 009) Se presentó el reclamo al fabricante o proveedor'],
        'GARANTIA_APROBADA'  => ['garantía aceptada',  'La cubren; se espera el reemplazo'],
        'GARANTIA_NEGADA'    => ['garantía negada',    '(anterior a la 009) No la cubren. Hay que volver a decidir la vía'],
        'BAJA_PROPUESTA'     => ['baja propuesta',     '(anterior a la 009) Se propuso a Grupo KFC dar de baja el equipo'],
        'BAJA_APROBADA'      => ['baja aprobada',      'Grupo KFC aceptó la baja'],
        'RESUELTO'           => ['resuelto',           'El equipo volvió a operar, o su baja quedó ejecutada'],
        'CANCELADO'          => ['cancelado',          'No procedía; se resolvió de otra forma'],
        // -- Desde la 009: la cadena real (técnico -> jefe -> SAP -> KFC). --
        'SOLICITADO'         => ['solicitado',                       'El técnico pidió el repuesto con su diagnóstico; corre el plazo de 48 h para que el jefe lo valide'],
        'VALIDADO_JEFE'      => ['validado por el jefe',             'El jefe de zona confirmó el diagnóstico y el repuesto; falta que la administración lo registre en SAP'],
        'REGISTRADO_SAP'     => ['registrado en SAP, esperando a KFC', 'La administración ya lo registró en SAP: se espera la respuesta de Grupo KFC'],
        'ESPERA_KFC'         => ['esperando a KFC',                  '(reservado) Hoy «registrado en SAP» ya significa esto'],
        'REPUESTO_ENVIADO'   => ['KFC envía el repuesto',            'Grupo KFC decidió mandar la pieza; falta que llegue al local'],
        'TALLER_INDUSTEC'    => ['a taller de INDUSTEC',             'Grupo KFC decidió que el equipo se repare en los talleres de INDUSTEC'],
        'OTRO_PROVEEDOR'     => ['a otro proveedor',                 'Grupo KFC lo mandó a otro proveedor; el seguimiento queda fuera de INDUSTEC'],
    ];

    /** Los que todavía cuentan como pendiente de alguien: todo el ENUM menos
     *  RESUELTO y CANCELADO (prueba_48h lo comprueba). Incluye lo anterior a
     *  la 009 (para las filas que siguen ahí) y toda la cadena nueva.
     *  OTRO_PROVEEDOR figura por completitud, pero ninguna fila se queda en
     *  él: `decidioKfc()` lo resuelve en el acto. */
    public const ABIERTOS = [
        'SIN_VEREDICTO', 'COTIZANDO', 'COMPRADO', 'EN_BODEGA', 'ENTREGADO',
        'EN_TALLER', 'DEVUELTO_TALLER', 'GARANTIA_RECLAMADA',
        'GARANTIA_APROBADA', 'GARANTIA_NEGADA', 'BAJA_PROPUESTA', 'BAJA_APROBADA',
        'SOLICITADO', 'VALIDADO_JEFE', 'REGISTRADO_SAP', 'ESPERA_KFC',
        'REPUESTO_ENVIADO', 'TALLER_INDUSTEC', 'OTRO_PROVEEDOR',
    ];

    /* Las horas del reloj las calcula MySQL, con sus propias fechas: restar
       strtotime() contra time() solo cuadra si la base y el PHP de la web
       corren en la misma zona. Desde T2.13.7 las dos van en hora de Ecuador
       porque las fija Db.php; antes las fijaba el hosting (UTC en Hostinger),
       y calcularlo aquí sigue sin depender de eso. */
    private const MINUTOS =
        'TIMESTAMPDIFF(MINUTE, COALESCE(p.plazo_desde, p.abierto_en), NOW()) AS min_plazo,
         TIMESTAMPDIFF(MINUTE, COALESCE(p.plazo_desde, p.abierto_en), p.veredicto_en) AS min_veredicto';

    /** Lo que se necesita del hilo de SAP/KFC para dibujar la fila entera. */
    private const EXTRA =
        "DATEDIFF(CURDATE(), p.registrado_sap_en) AS dias_esperando_kfc,
         CASE WHEN p.prometido_para IS NOT NULL AND p.prometido_para < CURDATE()
              THEN DATEDIFF(CURDATE(), p.prometido_para) END AS dias_vencido_prom";

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
        return self::VIAS[strtoupper((string) $v)][0] ?? 'sin validar';
    }
    /** El «veredicto», reservado para lo que decide Grupo KFC (P-20): no se
     *  confunde con lo que valida el jefe de zona. */
    public static function etiquetaVeredictoKfc(?string $v): string
    {
        return self::VEREDICTOS_KFC[strtoupper((string) $v)] ?? self::VEREDICTOS_KFC['PENDIENTE'];
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
     * @param array $f  'grupo' => 'abiertos'|'cerrados'|'sin_veredicto'|'vencidos'
     *                            |'por_validar'|'por_registrar'|'esperando_kfc'|'prometidos_vencidos',
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
        } elseif ($g === 'sin_veredicto' || $g === 'por_validar') {
            // «sin_veredicto» es el nombre de siempre (via sin decidir); desde
            // la 009 equivale exactamente a «por validar» del jefe (P-05).
            $donde .= " AND p.via = 'SIN_VEREDICTO' AND p.estado NOT IN ('RESUELTO','CANCELADO')";
        } elseif ($g === 'vencidos') {
            // Vencido = deshabilitado, sin validar y con más de 48 h encima.
            // Se calcula en SQL para que la cifra del globo de navegación y la
            // de la pantalla salgan del mismo sitio y no puedan discrepar.
            $donde .= " AND p.via = 'SIN_VEREDICTO' AND p.deshabilitado = 1
                        AND p.estado NOT IN ('RESUELTO','CANCELADO')
                        AND COALESCE(p.plazo_desde, p.abierto_en) < DATE_SUB(NOW(), INTERVAL 48 HOUR)";
        } elseif ($g === 'por_registrar') {
            $donde .= " AND p.estado = 'VALIDADO_JEFE'";
        } elseif ($g === 'esperando_kfc') {
            $donde .= " AND p.estado IN ('REGISTRADO_SAP','ESPERA_KFC')";
        } elseif ($g === 'prometidos_vencidos') {
            $donde .= ' AND p.estado IN (' . self::lista_(self::ABIERTOS) . ')
                        AND p.prometido_para IS NOT NULL AND p.prometido_para < CURDATE()';
        }

        if (!empty($f['via']) && isset(self::VIAS[$f['via']])) {
            $donde .= ' AND p.via = ?';
            $par[] = $f['via'];
        }
        if (!empty($f['parado'])) { $donde .= ' AND p.deshabilitado = 1'; }
        if (!empty($f['q'])) {
            $donde .= ' AND (p.parte LIKE ? OR p.aviso LIKE ? OR p.local_codigo LIKE ?
                             OR p.activo_fijo LIKE ? OR p.equipo_desc LIKE ? OR p.diagnostico LIKE ?
                             OR p.requerimiento_sap LIKE ?)';
            $like = '%' . $f['q'] . '%';
            for ($i = 0; $i < 7; $i++) { $par[] = $like; }
        }

        $filas = Db::todos(
            "SELECT p.*, a.nombre AS abrio, a.usuario AS abrio_usuario,
                    v.nombre AS decidio, rs.nombre AS registro_sap, vk.nombre AS decidio_kfc,
                    g.nombre AS gestor, " . self::MINUTOS . ', ' . self::EXTRA . "
               FROM pendientes p
               JOIN usuarios a ON a.usuario_id = p.abierto_por
          LEFT JOIN usuarios v  ON v.usuario_id = p.veredicto_por
          LEFT JOIN usuarios rs ON rs.usuario_id = p.registrado_sap_por
          LEFT JOIN usuarios vk ON vk.usuario_id = p.veredicto_kfc_por
          LEFT JOIN usuarios g  ON g.usuario_id = p.gestionado_por
              WHERE $donde
              /* El orden ES la prioridad de trabajo, no una preferencia:
                 primero lo que está parado sin validar (el reloj corriendo),
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
     * se validó la vía, o el pendiente está cerrado. Medir lo que no aplica es
     * lo que llena un tablero de rojos que nadie mira.
     */
    public static function reloj(array $p): ?array
    {
        if (empty($p['deshabilitado'])) { return null; }
        if (!in_array($p['estado'], self::ABIERTOS, true)) { return null; }
        if (($p['via'] ?? 'SIN_VEREDICTO') !== 'SIN_VEREDICTO') {
            // Ya lo validó el jefe: el plazo se cumplió, y se guarda cómo.
            $h = ($p['min_veredicto'] ?? null) !== null ? (int) $p['min_veredicto'] / 60 : null;
            return $h === null ? null : [
                'clase'   => $h <= 48 ? 'edad edad-hoy' : 'edad edad-viejo',
                'texto'   => $h <= 48 ? 'validado en ' . round($h) . ' h'
                                      : 'se validó a las ' . round($h) . ' h',
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
            "SELECT p.*, a.nombre AS abrio, v.nombre AS decidio,
                    rs.nombre AS registro_sap, vk.nombre AS decidio_kfc, " . self::MINUTOS . ', ' . self::EXTRA . "
               FROM pendientes p
               JOIN usuarios a ON a.usuario_id = p.abierto_por
          LEFT JOIN usuarios v  ON v.usuario_id = p.veredicto_por
          LEFT JOIN usuarios rs ON rs.usuario_id = p.registrado_sap_por
          LEFT JOIN usuarios vk ON vk.usuario_id = p.veredicto_kfc_por
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

    /** Limpia y acota la lista estructurada de partes que viaja desde el
     *  formulario (H-11/D9): [{descripcion, cantidad, numero_parte, codigo}]. */
    private static function limpiarPartes($partes): ?string
    {
        if (!is_array($partes) || !$partes) { return null; }
        $out = [];
        foreach ($partes as $pt) {
            if (!is_array($pt)) { continue; }
            $desc = trim((string) ($pt['descripcion'] ?? ''));
            if ($desc === '') { continue; }
            $out[] = [
                'descripcion'  => mb_substr($desc, 0, 160),
                'cantidad'     => max(1, (int) ($pt['cantidad'] ?? 1)),
                'numero_parte' => trim((string) ($pt['numero_parte'] ?? '')) !== ''
                                  ? mb_substr(trim((string) $pt['numero_parte']), 0, 60) : null,
                'codigo'       => trim((string) ($pt['codigo'] ?? '')) !== ''
                                  ? mb_substr(trim((string) $pt['codigo']), 0, 20) : null,
            ];
        }
        return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
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
     * Nace SOLICITADO (009): corre el plazo de 48 h para que el jefe de zona
     * lo valide. Acepta además `diagnostico_codigo` (el diagnóstico
     * pre-redactado que se eligió) y `partes` (la lista estructurada).
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
            // por qué, y ese texto es lo que después sostiene la validación y
            // la respuesta a Grupo KFC.
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
        $diagCodigo = trim((string) ($d['diagnostico_codigo'] ?? '')) !== ''
                    ? mb_substr(trim((string) $d['diagnostico_codigo']), 0, 20) : null;
        $partesJson = self::limpiarPartes($d['partes'] ?? null);

        $previo = Db::uno('SELECT pendiente_id, estado, diagnostico, deshabilitado
                              FROM pendientes WHERE aviso = ? AND activo_fijo = ?', [$aviso, $activo]);
        $reabre = false;
        $tardio = false;
        if ($previo === null) {
            Db::ejecutar(
                'INSERT INTO pendientes
                    (aviso, zona, local_codigo, cadena, activo_fijo, equipo_desc,
                     deshabilitado, via, estado, diagnostico, diagnostico_codigo, partes,
                     parte, cantidad, abierto_por, abierto_en)
                 VALUES (?,?,?,?,?,?,?, "SIN_VEREDICTO", "SOLICITADO", ?,?,?,?,?,?, COALESCE(FROM_UNIXTIME(?), NOW()))
                 ON DUPLICATE KEY UPDATE deshabilitado = GREATEST(deshabilitado, VALUES(deshabilitado))',
                [$aviso, $caso['zona'] ?? null, $caso['local'] ?? null, $caso['cadena'] ?? null,
                 $activo, $desc, $parado, mb_substr($diag, 0, 600), $diagCodigo, $partesJson,
                 $parte, $cantidad, (int) $u['usuario_id'], $ts]
            );
        } elseif (in_array($previo['estado'], self::ABIERTOS, true)) {
            // El mismo episodio: agrava si ahora está parado, y el diagnóstico
            // nuevo va al hilo en vez de pisar el que sostiene la validación.
            // P-09: si el equipo ESTABA operando y ahora aparece deshabilitado,
            // el reloj de 48 h arranca de este reporte, no del episodio viejo
            // (si no, nace vencido en el instante de reportarlo).
            $reactivaReloj = (int) $previo['deshabilitado'] === 0 && $parado === 1;
            Db::ejecutar(
                'UPDATE pendientes SET deshabilitado = GREATEST(deshabilitado, ?),
                        parte = COALESCE(?, parte), cantidad = GREATEST(cantidad, ?),
                        diagnostico_codigo = COALESCE(?, diagnostico_codigo),
                        partes = COALESCE(?, partes),
                        plazo_desde = ' . ($reactivaReloj ? 'NOW()' : 'plazo_desde') . '
                  WHERE pendiente_id = ?',
                [$parado, $parte, $cantidad, $diagCodigo, $partesJson, (int) $previo['pendiente_id']]
            );
            if ($reactivaReloj) {
                self::anotar((int) $previo['pendiente_id'], 'DIAGNOSTICO',
                             'El equipo pasó a deshabilitado en este reporte: el plazo de 48 horas '
                           . 'arranca desde aquí.', true);
            }
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
                        SET via = 'SIN_VEREDICTO', estado = 'SOLICITADO', deshabilitado = ?,
                            diagnostico = ?, diagnostico_codigo = ?, partes = ?, parte = ?, cantidad = ?,
                            equipo_desc = COALESCE(NULLIF(?, ''), equipo_desc),
                            abierto_por = ?, abierto_en = COALESCE(FROM_UNIXTIME(?), NOW()),
                            plazo_desde = NULL, veredicto_por = NULL, veredicto_en = NULL,
                            veredicto_nota = NULL, prometido_para = NULL,
                            validado_por = NULL, validado_en = NULL,
                            requerimiento_sap = NULL, registrado_sap_por = NULL, registrado_sap_en = NULL,
                            veredicto_kfc = 'PENDIENTE', veredicto_kfc_ref = NULL,
                            veredicto_kfc_en = NULL, veredicto_kfc_por = NULL,
                            gestionado_por = NULL, gestionado_en = NULL,
                            cerrado_en = NULL, nota_cierre = NULL, insistencias = 0
                      WHERE pendiente_id = ?",
                    [$parado, mb_substr($diag, 0, 600), $diagCodigo, $partesJson, $parte, $cantidad, $desc,
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

        // P-21: la nota libre de `abrir()` se eliminó -- contaba como
        // RECORDATORIO (insistencia) sin que el técnico hubiera insistido en
        // nada, solo por abrir el pendiente por primera vez.

        Auth::bitacora('PENDIENTE_ABRE', 'pendiente', (string) $id,
                       mb_substr($diag, 0, 120) . ($parado ? ' · EQUIPO PARADO' : ''),
                       null, 'SOLICITADO',
                       ['aviso' => $aviso, 'equipo' => $activo, 'parado' => $parado,
                        'zona' => $caso['zona'] ?? null]);

        $inicio = $reabre ? 'Reabierto: el equipo volvió a quedar sin concluir. ' : 'Registrado. ';
        return [true, $inicio . ($parado
            ? 'El equipo consta como deshabilitado: hay 48 horas para que el jefe de zona lo valide.'
            : 'El equipo sigue operando, así que no corre el plazo de 48 horas.'), $id];
    }

    /**
     * Validar la solicitud: el jefe de zona (o la administración, por
     * excepción) confirma que el diagnóstico y el repuesto pedidos por el
     * técnico son correctos, y fija por cuál de las cuatro vías va.
     *
     * Es lo que hasta la 009 se llamaba «dar el veredicto». Ese nombre se
     * reserva ahora para lo que decide Grupo KFC (P-20).
     */
    public static function validar(int $id, string $via, string $nota): array
    {
        if (!Auth::puede('repuestos.validar')) {
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, 'validar sin permiso',
                           null, $via, [], false);
            return [false, 'No tienes permiso para validar solicitudes de repuesto.'];
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
        // SIN_VEREDICTO es el estado con que nacían antes de la 009: esas filas
        // siguen vivas en el sitio y se validan por aquí igual que SOLICITADO.
        if (!$p['abierto'] || !in_array($p['estado'], ['SOLICITADO', 'SIN_VEREDICTO'], true)) {
            return [false, 'Ese pendiente ya fue validado, o está cerrado.'];
        }
        // Dar de baja un activo del cliente es una decisión que se le explica a
        // Grupo KFC. Sin motivo escrito no se sostiene.
        if ($via === 'BAJA' && trim($nota) === '') {
            return [false, 'Para proponer la baja del equipo hace falta el sustento técnico.'];
        }

        $u = Auth::actual();
        $horas = (int) ($p['min_plazo'] ?? 0) / 60;

        // El reloj se detiene con esta validación -- se guardan las columnas
        // viejas (veredicto_por/en, que es lo que reloj()/cumplimiento48() ya
        // saben leer) y las nuevas de la 009, con el mismo valor.
        $filas = Db::ejecutar(
            'UPDATE pendientes
                SET via = ?, estado = "VALIDADO_JEFE",
                    veredicto_por = ?, veredicto_en = NOW(), veredicto_nota = NULLIF(?, ""),
                    validado_por = ?, validado_en = NOW()
              WHERE pendiente_id = ? AND estado IN ("SOLICITADO", "SIN_VEREDICTO")',
            [$via, (int) $u['usuario_id'], mb_substr(trim($nota), 0, 600), (int) $u['usuario_id'], $id]
        );
        if ($filas === 0) {
            // Carrera: alguien más lo validó entre el `uno()` de arriba y este
            // UPDATE. No se anota nada -- el UPDATE que afecta 0 filas no deja
            // rastro de una acción que no ocurrió («omitidos», auditoría T2.14).
            return [false, 'Ese pendiente ya fue validado por otra persona.'];
        }

        // P-08: el jefe de zona es quien debería validar; si lo hace la
        // administración (por su ausencia, o por urgencia), queda marcado
        // aparte en la bitácora para que se pueda revisar el patrón.
        $accion = $u['rol'] === 'JEFE_ZONA' ? 'PENDIENTE_VALIDA' : 'VALIDADO_SIN_JEFE';

        self::anotar($id, 'VALIDACION',
                     self::etiquetaVia($via) . ($nota !== '' ? ' — ' . $nota : ''),
                     false, $p['estado'], 'VALIDADO_JEFE');

        Auth::bitacora($accion, 'pendiente', (string) $id,
                       self::etiquetaVia($via) . ' a las ' . round($horas) . ' h',
                       $p['estado'], 'VALIDADO_JEFE',
                       ['via' => $via, 'horas_hasta_validacion' => round($horas, 1),
                        'dentro_de_48' => $horas <= 48, 'aviso' => $p['aviso'], 'rol' => $u['rol']]);

        return [true, 'Solicitud validada: ' . self::etiquetaVia($via) . '.'
                    . ($p['deshabilitado'] && $horas > 48
                        ? ' Se validó a las ' . round($horas) . ' h, fuera del plazo de 48.'
                        : '') . ' Falta que la administración la registre en SAP.'];
    }

    /**
     * Registrar en SAP: la administración anota el número del requerimiento
     * que registró con Grupo KFC. Desde aquí el pendiente queda esperando la
     * respuesta del cliente (P-02).
     */
    public static function registrarSap(int $id, string $requerimiento, string $nota): array
    {
        if (!Auth::puede('repuestos.registrar_sap')) {
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, 'registrar sap sin permiso',
                           null, null, [], false);
            return [false, 'No tienes permiso para registrar requerimientos en SAP.'];
        }
        $p = self::uno($id);
        if ($p === null) {
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, __FUNCTION__ . ' fuera de alcance o inexistente',
                           null, null, [], false);
            return [false, 'Ese pendiente no existe o no está en tu alcance.'];
        }
        $req = trim($requerimiento);
        if ($req === '') {
            // Obligatorio a propósito: es el paso que hoy no deja rastro (P-02).
            // Sin el número, «se registró en SAP» es una afirmación que nadie
            // puede sostener ante Grupo KFC tres semanas después.
            return [false, 'El número del requerimiento en SAP es obligatorio.'];
        }
        if (!$p['abierto'] || $p['estado'] !== 'VALIDADO_JEFE') {
            return [false, 'Ese pendiente todavía no está validado por el jefe, o ya se registró en SAP.'];
        }

        $u = Auth::actual();
        $req = mb_substr($req, 0, 30);
        $filas = Db::ejecutar(
            'UPDATE pendientes
                SET estado = "REGISTRADO_SAP", requerimiento_sap = ?,
                    registrado_sap_por = ?, registrado_sap_en = NOW()
              WHERE pendiente_id = ? AND estado = "VALIDADO_JEFE"',
            [$req, (int) $u['usuario_id'], $id]
        );
        if ($filas === 0) {
            return [false, 'Ese pendiente cambió de estado mientras tanto.'];
        }

        self::anotar($id, 'REGISTRO_SAP',
                     'Requerimiento SAP ' . $req . ($nota !== '' ? ' — ' . trim($nota) : ''),
                     false, 'VALIDADO_JEFE', 'REGISTRADO_SAP');
        Auth::bitacora('PENDIENTE_REGISTRA_SAP', 'pendiente', (string) $id, 'requerimiento ' . $req,
                       'VALIDADO_JEFE', 'REGISTRADO_SAP',
                       ['requerimiento_sap' => $req, 'aviso' => $p['aviso']]);

        return [true, 'Registrado en SAP con el requerimiento ' . $req . '. Queda esperando la '
                    . 'respuesta de Grupo KFC.'];
    }

    /**
     * KFC decidió: Grupo KFC respondió al requerimiento registrado en SAP.
     * Puede mandar el repuesto, pedir que se repare en talleres de INDUSTEC,
     * mandarlo a otro proveedor, o dar de baja el equipo (P-03: antes esto no
     * se podía registrar si no coincidía con la vía que pidió el jefe).
     */
    public static function decidioKfc(int $id, string $veredicto, ?string $referencia,
                                      ?string $tercero, string $nota): array
    {
        if (!Auth::puede('repuestos.gestionar')) {
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, 'kfc decidio sin permiso',
                           null, $veredicto, [], false);
            return [false, 'No tienes permiso para registrar la decisión de Grupo KFC.'];
        }
        $veredicto = strtoupper($veredicto);
        if (!in_array($veredicto, ['REPUESTO_ENVIADO', 'TALLER_INDUSTEC', 'OTRO_PROVEEDOR', 'BAJA'], true)) {
            return [false, 'Esa no es una de las decisiones de Grupo KFC.'];
        }
        $p = self::uno($id);
        if ($p === null) {
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, __FUNCTION__ . ' fuera de alcance o inexistente',
                           null, null, [], false);
            return [false, 'Ese pendiente no existe o no está en tu alcance.'];
        }
        if (!$p['abierto'] || !in_array($p['estado'], ['REGISTRADO_SAP', 'ESPERA_KFC'], true)) {
            return [false, 'Ese pendiente todavía no está registrado en SAP, o ya tiene decisión de Grupo KFC.'];
        }

        $tercero = trim((string) $tercero);
        if ($veredicto === 'OTRO_PROVEEDOR' && $tercero === '') {
            return [false, 'Di a qué proveedor lo mandó Grupo KFC (P-04: para no mezclarlo con taller propio).'];
        }
        if ($veredicto === 'TALLER_INDUSTEC' && $tercero === '') {
            // Taller propio: se anota tal cual para poder reportar cuántos
            // equipos repara INDUSTEC (P-04) sin exigirle el dato al usuario.
            $tercero = 'INDUSTEC';
        }

        $u = Auth::actual();
        // OTRO_PROVEEDOR se resuelve en el acto (spec D3): el trabajo de
        // INDUSTEC termina al entregar el equipo al tercero, no hay paso
        // intermedio que seguir. Los otros tres dejan un camino en PASOS_KFC.
        $directo = $veredicto === 'OTRO_PROVEEDOR';
        $nuevoEstado = $directo ? 'RESUELTO' : ($veredicto === 'BAJA' ? 'BAJA_APROBADA' : $veredicto);
        $notaCierre = mb_substr('Grupo KFC: ' . self::etiquetaVeredictoKfc($veredicto)
                               . ($tercero !== '' ? ' — ' . $tercero : ''), 0, 400);

        $filas = Db::ejecutar(
            'UPDATE pendientes
                SET veredicto_kfc = ?, veredicto_kfc_ref = NULLIF(?, ""), veredicto_kfc_en = NOW(),
                    veredicto_kfc_por = ?, estado = ?,
                    tercero = COALESCE(NULLIF(?, ""), tercero),
                    gestionado_por = ?, gestionado_en = NOW(),
                    cerrado_en  = ' . ($directo ? 'NOW()' : 'cerrado_en') . ',
                    nota_cierre = ' . ($directo ? '?' : 'nota_cierre') . '
              WHERE pendiente_id = ? AND estado IN ("REGISTRADO_SAP","ESPERA_KFC")',
            $directo
                ? [$veredicto, $referencia, (int) $u['usuario_id'], $nuevoEstado, $tercero,
                   (int) $u['usuario_id'], $notaCierre, $id]
                : [$veredicto, $referencia, (int) $u['usuario_id'], $nuevoEstado, $tercero,
                   (int) $u['usuario_id'], $id]
        );
        if ($filas === 0) {
            return [false, 'Ese pendiente cambió de estado mientras tanto.'];
        }

        self::anotar($id, 'VEREDICTO_KFC',
                     'Grupo KFC: ' . self::etiquetaVeredictoKfc($veredicto)
                     . ($tercero !== '' ? ' — ' . $tercero : '') . ($nota !== '' ? ' — ' . trim($nota) : ''),
                     false, $p['estado'], $nuevoEstado);
        Auth::bitacora('PENDIENTE_VEREDICTO_KFC', 'pendiente', (string) $id,
                       self::etiquetaVeredictoKfc($veredicto), $p['estado'], $nuevoEstado,
                       ['veredicto_kfc' => $veredicto, 'referencia' => $referencia, 'tercero' => $tercero,
                        'aviso' => $p['aviso'],
                        'dias_esperando_kfc' => $p['dias_esperando_kfc'] ?? null]);

        if ($directo) { self::liberarCaso((string) $p['aviso']); }

        return [true, 'Grupo KFC decidió: ' . self::etiquetaVeredictoKfc($veredicto) . '.'];
    }

    /**
     * Avanzar dentro del camino ya decidido -- el de la compra propia de antes
     * de la 009 (para las filas que siguen ahí) o el que dejó la decisión de
     * Grupo KFC (REPUESTO_ENVIADO/TALLER_INDUSTEC/BAJA_APROBADA). Un pendiente
     * SOLICITADO, VALIDADO_JEFE o REGISTRADO_SAP no se mueve por aquí: tiene su
     * propia acción (`validar`, `registrarSap`, `decidioKfc`).
     *
     * P-17: retroceder o saltar más de un paso exige nota -- un toque
     * equivocado en el celular no puede llevar un repuesto ya entregado a
     * «cotizando» sin que quede dicho por qué.
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
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, __FUNCTION__ . ' fuera de alcance o inexistente',
                           null, null, [], false);
            return [false, 'Ese pendiente no existe o no está en tu alcance.'];
        }
        if (!isset(self::ESTADOS[$nuevo])) { return [false, 'Estado no válido.']; }
        if ($nuevo === $p['estado']) { return [false, 'El pendiente ya está en ese estado.']; }
        if (!$p['abierto']) { return [false, 'El pendiente ya está cerrado.']; }

        if ($nuevo === 'CANCELADO') {
            if (trim($nota) === '') { return [false, 'Para cancelar hace falta el motivo.']; }
        } else {
            $via = (string) $p['via'];
            $kfc = (string) ($p['veredicto_kfc'] ?? 'PENDIENTE');
            if ($kfc !== 'PENDIENTE' && isset(self::PASOS_KFC[$kfc])
                && in_array($p['estado'], self::PASOS_KFC[$kfc], true)) {
                $camino = self::PASOS_KFC[$kfc];
            } elseif (isset(self::PASOS[$via]) && in_array($p['estado'], self::PASOS[$via], true)) {
                // Fila anterior a la 009: sigue su camino de siempre.
                $camino = self::PASOS[$via];
            } else {
                return [false, 'Este pendiente se gestiona con «Validar», «Registrar en SAP» o '
                             . '«KFC decidió», no con Avanzar.'];
            }
            if (!in_array($nuevo, $camino, true)) {
                return [false, 'Ese paso no pertenece al camino de este pendiente.'];
            }
            $iAct = array_search($p['estado'], $camino, true);
            $iNue = array_search($nuevo, $camino, true);
            if ($iAct !== false && $iNue !== false && ($iNue < $iAct || $iNue > $iAct + 1)
                && trim($nota) === '') {
                return [false, 'Para retroceder o saltar un paso hace falta la nota.'];
            }
        }

        $fecha = null;
        if ($prometido !== null && trim($prometido) !== '') {
            $t = strtotime($prometido);
            if ($t === false) { return [false, 'La fecha comprometida no es una fecha.']; }
            $fecha = date('Y-m-d', $t);
        }

        $u = Auth::actual();
        $cierra = in_array($nuevo, ['RESUELTO', 'CANCELADO'], true);
        $filas = Db::ejecutar(
            'UPDATE pendientes
                SET estado = ?, gestionado_por = ?, gestionado_en = NOW(),
                    prometido_para = COALESCE(?, prometido_para),
                    cerrado_en  = ' . ($cierra ? 'NOW()' : 'cerrado_en') . ',
                    nota_cierre = ' . ($cierra ? 'NULLIF(?, "")' : 'nota_cierre') . '
              WHERE pendiente_id = ? AND estado = ?',
            $cierra
                ? [$nuevo, (int) $u['usuario_id'], $fecha, mb_substr(trim($nota), 0, 400), $id, $p['estado']]
                : [$nuevo, (int) $u['usuario_id'], $fecha, $id, $p['estado']]
        );
        if ($filas === 0) {
            // Otra persona ya lo movió entre el `uno()` y este UPDATE: no se
            // anota nada de una transición que no ocurrió.
            return [false, 'Ese pendiente cambió de estado mientras tanto: recarga la pantalla.'];
        }

        self::anotar($id, 'CAMBIO_ESTADO',
                     $nota !== '' ? $nota : 'Pasó a: ' . self::etiquetaEstado($nuevo),
                     false, $p['estado'], $nuevo);

        // La garantía negada (solo filas anteriores a la 009) devuelve el
        // pendiente a la casilla de salida: hay que volver a decidir la vía, y
        // el reloj vuelve a tener sentido porque el equipo sigue parado.
        if ($nuevo === 'GARANTIA_NEGADA') {
            Db::ejecutar("UPDATE pendientes SET via = 'SIN_VEREDICTO', plazo_desde = NOW(),
                                 veredicto_por = NULL, veredicto_en = NULL
                           WHERE pendiente_id = ?", [$id]);
        }

        if ($cierra) { self::liberarCaso((string) $p['aviso']); }

        Auth::bitacora('PENDIENTE_MUEVE', 'pendiente', (string) $id,
                       self::etiquetaEstado($p['estado']) . ' -> ' . self::etiquetaEstado($nuevo),
                       $p['estado'], $nuevo,
                       ['aviso' => $p['aviso'], 'via' => $p['via'], 'prometido' => $fecha]);

        return [true, 'Pendiente: ' . self::etiquetaEstado($nuevo) . '.'];
    }

    /** El caso vuelve a la corriente cuando ya no queda nada esperando en él.
     *  Reutilizado por `mover()`, `decidioKfc()` (OTRO_PROVEEDOR) y
     *  `resolverPorOrden()` (P-06): los tres cierran pendientes y los tres
     *  necesitan la misma regla de si el caso puede dejar de esperar. */
    private static function liberarCaso(string $aviso): void
    {
        $otros = Db::uno(
            'SELECT COUNT(*) c FROM pendientes WHERE aviso = ? AND estado IN (' . self::lista_(self::ABIERTOS) . ')',
            [$aviso]
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
                [$aviso]
            );
        }
    }

    /**
     * Interfaz para S1 (envio.php): la orden concluida resuelve sola los
     * pendientes que ya estaban en manos del técnico (P-06). Sin permiso: lo
     * dispara el hecho de que la orden llegó concluida, no una persona.
     *
     * No finge (I-7): un pendiente que sigue esperando a Grupo KFC no se cierra
     * solo porque llegó una orden -- queda anotado, y la administración lo ve.
     *
     * @return int Cuántos pendientes se resolvieron de verdad.
     */
    public static function resolverPorOrden(string $aviso, ?string $activoFijo, string $idIndustec,
                                            int $usuarioId): int
    {
        if (!self::disponible()) { return 0; }
        $aviso = trim($aviso);
        if ($aviso === '') { return 0; }
        $activoFijo = $activoFijo !== null ? trim($activoFijo) : null;

        // Los pasos en que la pieza o el equipo ya están en manos del técnico:
        // instalarlo y emitir la orden es justo lo que los cierra.
        $cerrables = ['ENTREGADO', 'DEVUELTO_TALLER', 'GARANTIA_APROBADA', 'BAJA_APROBADA'];
        $donde = 'aviso = ? AND estado IN (' . self::lista_($cerrables) . ')';
        $par = [$aviso];
        if ($activoFijo !== null && $activoFijo !== '') {
            $donde .= ' AND activo_fijo = ?';
            $par[] = $activoFijo;
        }
        $nota = 'Orden concluida ' . $idIndustec;
        $n = 0;
        foreach (Db::todos("SELECT pendiente_id, estado FROM pendientes WHERE $donde", $par) as $f) {
            $pid = (int) $f['pendiente_id'];
            $afectadas = Db::ejecutar(
                "UPDATE pendientes SET estado = 'RESUELTO', gestionado_por = ?, gestionado_en = NOW(),
                        cerrado_en = NOW(), nota_cierre = ?
                  WHERE pendiente_id = ? AND estado = ?",
                [$usuarioId, $nota, $pid, $f['estado']]
            );
            if ($afectadas === 0) { continue; }   // otra cosa lo cerró primero
            self::anotar($pid, 'CAMBIO_ESTADO', $nota, false, $f['estado'], 'RESUELTO', $usuarioId);
            Auth::bitacora('PENDIENTE_RESUELTO_POR_ORDEN', 'pendiente', (string) $pid, $nota,
                           $f['estado'], 'RESUELTO', ['aviso' => $aviso, 'id_industec' => $idIndustec]);
            $n++;
        }

        // Los abiertos del mismo aviso que NO estaban en un paso cerrable (p.
        // ej. uno todavía esperando a Grupo KFC): no se fingen resueltos, se
        // anota que la orden llegó con el pendiente aún vivo (I-7).
        $otrosDonde = 'aviso = ? AND estado IN (' . self::lista_(self::ABIERTOS) . ')
                       AND estado NOT IN (' . self::lista_($cerrables) . ')';
        $otrosPar = [$aviso];
        if ($activoFijo !== null && $activoFijo !== '') {
            $otrosDonde .= ' AND activo_fijo = ?';
            $otrosPar[] = $activoFijo;
        }
        foreach (Db::todos("SELECT pendiente_id, estado FROM pendientes WHERE $otrosDonde", $otrosPar) as $o) {
            $pid = (int) $o['pendiente_id'];
            $txt = $nota . ' recibida con este pendiente aún en ' . self::etiquetaEstado($o['estado']) . '.';
            self::anotar($pid, 'AVISO_INTERNO', $txt, false, null, null, $usuarioId);
            Auth::bitacora('PENDIENTE_ORDEN_CONCLUIDA_SIN_CERRAR', 'pendiente', (string) $pid, $txt,
                           $o['estado'], $o['estado'], ['aviso' => $aviso, 'id_industec' => $idIndustec]);
        }

        if ($n > 0) { self::liberarCaso($aviso); }
        return $n;
    }

    /**
     * P-07: un equipo trabado reportado en una orden SIN aviso de SAP no
     * arranca reloj ni entra a ninguna bandeja -- queda solo en el JSON de la
     * orden. Esta es la cola de la administración (y del jefe de su zona) para
     * asignarle el aviso y recién ahí abrir el pendiente de verdad.
     */
    public static function sinAviso(): array
    {
        if (!self::disponible()) { return []; }
        $u = Auth::actual();
        if (!$u || !in_array($u['rol'], ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA'], true)) { return []; }
        $donde = '1=1';
        $par = [];
        $za = Auth::zonaAlcance();
        if ($za !== null) {
            if ($za === '') { return []; }
            $donde = 'c.zona = ?';
            $par[] = $za;
        }
        return Db::todos(
            "SELECT c.captura_id, c.envio_uuid, c.local_codigo, c.zona, c.capturada_en, u.nombre AS reporto,
                    JSON_UNQUOTE(JSON_EXTRACT(c.carga, '$.pendiente.diagnostico'))   AS diagnostico,
                    JSON_UNQUOTE(JSON_EXTRACT(c.carga, '$.pendiente.equipo_desc'))   AS equipo_desc,
                    JSON_UNQUOTE(JSON_EXTRACT(c.carga, '$.pendiente.activo_fijo'))   AS activo_fijo,
                    JSON_EXTRACT(c.carga, '$.pendiente.deshabilitado')              AS parado
               FROM ot_capturadas c
               JOIN usuarios u ON u.usuario_id = c.usuario_id
              WHERE $donde AND c.aviso IS NULL
                AND JSON_EXTRACT(c.carga, '$.pendiente.diagnostico') IS NOT NULL
              ORDER BY c.capturada_en ASC",
            $par
        );
    }

    /** Asignarle el aviso a una orden que llegó sin él, y recién entonces
     *  abrir su pendiente de verdad (P-07, opción A). */
    public static function regularizar(string $envioUuid, string $aviso): array
    {
        if (!Auth::puede('repuestos.gestionar')) {
            Auth::bitacora('DENEGADO', 'ot', $envioUuid, 'regularizar sin permiso', null, null, [], false);
            return [false, 'No tienes permiso para regularizar equipos trabados sin aviso.', null];
        }
        $aviso = trim($aviso);
        if ($aviso === '') { return [false, 'Hace falta el aviso al que corresponde.', null]; }

        $cap = Db::uno('SELECT * FROM ot_capturadas WHERE envio_uuid = ? AND aviso IS NULL', [$envioUuid]);
        if ($cap === null) {
            return [false, 'Esa orden no existe o ya tiene aviso asignado.', null];
        }
        $carga = json_decode((string) $cap['carga'], true) ?: [];
        $pen = $carga['pendiente'] ?? null;
        if (!is_array($pen) || trim((string) ($pen['diagnostico'] ?? '')) === '') {
            return [false, 'Esa orden no tiene un equipo trabado que regularizar.', null];
        }

        $filas = Db::ejecutar('UPDATE ot_capturadas SET aviso = ? WHERE envio_uuid = ? AND aviso IS NULL',
                              [mb_substr($aviso, 0, 20), $envioUuid]);
        if ($filas === 0) {
            return [false, 'Ya se regularizó desde otra pantalla.', null];
        }

        [$ok, $msg, $id] = self::abrir([
            'aviso'              => $aviso,
            'activo_fijo'        => $pen['activo_fijo'] ?? '',
            'equipo_desc'        => $pen['equipo_desc'] ?? '',
            'diagnostico'        => $pen['diagnostico'],
            'parte'              => $pen['parte'] ?? '',
            'deshabilitado'      => !empty($pen['deshabilitado']),
            'abierto_ts'         => strtotime((string) $cap['capturada_en']) ?: null,
            'diagnostico_codigo' => $pen['diagnostico_codigo'] ?? null,
            'partes'             => is_array($pen['partes'] ?? null) ? $pen['partes'] : [],
        ]);
        Auth::bitacora('PENDIENTE_REGULARIZADO', 'ot', $envioUuid, 'aviso asignado: ' . $aviso,
                       null, null, ['aviso' => $aviso, 'captura_id' => $cap['captura_id']]);
        return [$ok, $msg, $id];
    }

    /**
     * Insistir. Es la acción que hoy es un mensaje de WhatsApp.
     *
     * P-13: quien insiste de verdad es quien espera -- el técnico. Cuando
     * quien escribe es la oficina (jefe o administración «recordando» algo) es
     * un aviso propio, no una insistencia del técnico, y no debe sumarse al
     * contador que se le enseña a Grupo KFC: va como `AVISO_INTERNO`, tipo que
     * el disparador de `pendiente_notas` (007) no cuenta.
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
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, __FUNCTION__ . ' fuera de alcance o inexistente',
                           null, null, [], false);
            return [false, 'Ese pendiente no existe o no está en tu alcance.'];
        }
        if (!$p['abierto']) { return [false, 'Ese pendiente ya está cerrado.']; }

        $texto = trim($texto);
        if ($texto === '') { return [false, 'Escribe qué quieres decirle a la administración.']; }

        $u = Auth::actual();
        $tipo = $u['rol'] === 'TECNICO' ? 'RECORDATORIO' : 'AVISO_INTERNO';

        $rep = Db::uno(
            'SELECT nota_id FROM pendiente_notas
              WHERE pendiente_id = ? AND usuario_id = ? AND tipo = ? AND texto = ?
                AND creado_en > DATE_SUB(NOW(), INTERVAL 90 SECOND)',
            [$id, (int) $u['usuario_id'], $tipo, mb_substr($texto, 0, 600)]
        );
        if ($rep) { return [true, 'Ese recordatorio ya se envió hace un momento.']; }

        self::anotar($id, $tipo, $texto, $urgente);
        Auth::bitacora($tipo === 'RECORDATORIO' ? 'PENDIENTE_INSISTE' : 'PENDIENTE_AVISO_INTERNO',
                       'pendiente', (string) $id, ($urgente ? 'URGENTE: ' : '') . mb_substr($texto, 0, 120),
                       $p['estado'], $p['estado'],
                       ['urgente' => $urgente, 'aviso' => $p['aviso'],
                        'van' => (int) $p['insistencias'] + ($tipo === 'RECORDATORIO' ? 1 : 0)]);

        return [true, $tipo === 'RECORDATORIO'
            ? 'Recordatorio enviado. Queda registrado con la fecha.'
            : 'Aviso interno enviado. No cuenta como una insistencia del técnico.'];
    }

    /** Responder en el hilo sin mover el estado. P-12: permiso propio, para
     *  que el jefe de zona pueda pedir un dato antes de validar sin tener que
     *  «recordar» (que cuenta distinto) o validar a ciegas. */
    public static function responder(int $id, string $texto): array
    {
        if (!Auth::puede('repuestos.responder')) {
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, 'responder sin permiso',
                           null, null, [], false);
            return [false, 'No tienes permiso para responder aquí.'];
        }
        $p = self::uno($id);
        if ($p === null) {
            Auth::bitacora('DENEGADO', 'pendiente', (string) $id, __FUNCTION__ . ' fuera de alcance o inexistente',
                           null, null, [], false);
            return [false, 'Ese pendiente no existe o no está en tu alcance.'];
        }
        if (!$p['abierto']) { return [false, 'Ese pendiente ya está cerrado.']; }
        if (trim($texto) === '') { return [false, 'Escribe la respuesta.']; }

        self::anotar($id, 'RESPUESTA', $texto, false);
        Auth::bitacora('PENDIENTE_RESPONDE', 'pendiente', (string) $id,
                       mb_substr($texto, 0, 120), $p['estado'], $p['estado']);
        return [true, 'Respuesta enviada. El técnico la ve en su bandeja.'];
    }

    private static function anotar(int $id, string $tipo, string $texto, bool $urgente,
                                   ?string $antes = null, ?string $desp = null, ?int $actorId = null): void
    {
        $u = Auth::actual();
        $usuarioId = $actorId ?? (int) ($u['usuario_id'] ?? 0);
        Db::ejecutar(
            'INSERT INTO pendiente_notas
                (pendiente_id, usuario_id, tipo, texto, urgente, estado_antes, estado_desp)
             VALUES (?,?,?,?,?,?,?)',
            [$id, $usuarioId, $tipo, mb_substr(trim($texto), 0, 600),
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
                 'insistidos' => 0, 'cerrados_semana' => 0,
                 'por_validar' => 0, 'por_registrar' => 0, 'esperando_kfc' => 0,
                 'prometidos_vencidos' => 0];
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
                    AND p.cerrado_en > DATE_SUB(NOW(), INTERVAL 7 DAY))       AS cerrados_semana,
                SUM(p.estado = 'SOLICITADO')                                  AS por_validar,
                SUM(p.estado = 'VALIDADO_JEFE')                               AS por_registrar,
                SUM(p.estado IN ('REGISTRADO_SAP','ESPERA_KFC'))              AS esperando_kfc,
                SUM(p.estado IN ($ab) AND p.prometido_para IS NOT NULL
                    AND p.prometido_para < CURDATE())                        AS prometidos_vencidos
               FROM pendientes p WHERE $donde", $par
        );
        foreach ($cero as $k => $_) { $cero[$k] = (int) ($f[$k] ?? 0); }
        return $cero;
    }

    /**
     * El cumplimiento de las 48 horas, para el reporte.
     *
     * Es EL indicador del compromiso: de los equipos que quedaron parados,
     * cuántos tuvieron validación dentro del plazo. Los que siguen sin validar
     * y ya se pasaron cuentan como incumplidos ahora mismo, no cuando alguien
     * se acuerde de decidir — si no, el número mejora solo con no decidir.
     *
     * Acepta zona: si no se da, usa el alcance de la sesión (como siempre);
     * si se da, calcula esa zona sin importar el rol de quien pregunta (para
     * que el panel dibuje las tres columnas sin tres sesiones distintas).
     */
    public static function cumplimiento48(?string $zona = null): array
    {
        if (!self::disponible()) { return ['a_tiempo' => 0, 'tarde' => 0, 'corriendo' => 0, 'vencidos' => 0]; }
        if ($zona !== null) {
            // Misma resolución de zona que `alcance()`: la del caso manda sobre
            // la del pendiente, porque un caso derivado se lleva su pendiente.
            $donde = "COALESCE((SELECT g.zona FROM casos_gestion g WHERE g.aviso = p.aviso), p.zona) = ?";
            $par = [$zona];
        } else {
            [$donde, $par] = self::alcance();
        }
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
