<?php
declare(strict_types=1);
require_once __DIR__ . '/Auth.php';

/**
 * Casos — de dónde salen los casos, y quién puede ver cuáles.
 *
 * POR QUE ESTA CLASE EXISTE
 * Cuatro pantallas necesitan lo mismo: el buzón, la asignación, las órdenes
 * emitidas y el visor de PDF. Si cada una arma su propia consulta, el día que
 * cambie el alcance por zona hay que acordarse de cambiarlo en cuatro sitios, y
 * el que se olvide filtra datos de otra zona. Aquí está una sola vez.
 *
 * TRES FUENTES, CADA UNA CON SU DUEÑO
 *   catalogos/casos_sap.json    lo que pidio KFC. La estacion lo REESCRIBE
 *                               entero en cada barrido: nada se guarda ahi.
 *   catalogos/atenciones.json   que se atendio ya, leido de los informes de OT.
 *                               Tambien lo reescribe la estacion. Temporal.
 *   tabla casos_gestion         lo que NOSOTROS decidimos. Esto si persiste.
 *
 * EL ALCANCE SE APLICA AQUI, NO AL DIBUJAR
 * `enAlcance()` se llama antes de contar y antes de mostrar. Un jefe de zona
 * nunca recibe una fila de otra zona, ni siquiera para descartarla despues.
 */
final class Casos
{
    private static ?array $catalogo = null;
    private static ?array $atenciones = null;

    /** Lo que sigue siendo trabajo del técnico: su bandeja y su formulario (T2.13.2). */
    public const ABIERTOS_TECNICO = ['ASIGNADO', 'ESPERA_REPUESTO'];
    /** Lo que ya cerró, o se resolvió por otra vía: su historial (T2.13.3). */
    public const CERRADOS_TECNICO = ['ATENDIDO', 'RESUELTO', 'NO_COMPETE'];

    private static function leerJson(string $nombre, string $clave): array
    {
        $candidatos = [
            __DIR__ . '/../catalogos/' . $nombre,
            __DIR__ . '/../../../../../SALIDAS IA/OTS/catalogos/' . $nombre,
        ];
        foreach ($candidatos as $c) {
            if (is_file($c)) {
                $j = json_decode((string) file_get_contents($c), true);
                if (is_array($j) && isset($j[$clave])) { return $j; }
            }
        }
        return [];
    }

    /** El catálogo completo, tal como lo dejó el último barrido. */
    public static function catalogo(): array
    {
        return self::$catalogo ??= self::leerJson('casos_sap.json', 'datos');
    }

    /** Qué se atendió ya, por aviso. Vacío si todavía no se ha cruzado. */
    public static function atenciones(): array
    {
        self::$atenciones ??= self::leerJson('atenciones.json', 'atenciones');
        return self::$atenciones['atenciones'] ?? [];
    }

    /**
     * Recorta a lo que esta persona puede ver.
     *
     * El técnico ve LO SUYO: los casos que tiene asignados, no los de su zona.
     * Se descubrió probando que veía los 300 de la zona, con el nombre del
     * usuario de KFC que abrió cada uno. `casos.ver` le sirve para consultar sus
     * órdenes, no el buzón entero.
     */
    public static function enAlcance(array $casos, array $gestion): array
    {
        $u = Auth::actual();
        if ($u === null) { return []; }

        /* La zona que vale es la de la gestión: un caso derivado ya no es de la
           zona del catálogo. Se reescribe aquí, una vez, para que el filtro, los
           contadores, los pendientes y la propia derivación usen la misma. Antes
           solo el filtro la respetaba: derivar de vuelta un caso se rechazaba
           para siempre con «ya está en esa zona». */
        $casos = array_map(static function (array $c) use ($gestion): array {
            $zg = $gestion[$c['aviso'] ?? '']['zona'] ?? null;
            if ($zg !== null && $zg !== '' && $zg !== ($c['zona'] ?? null)) {
                $c['zona_catalogo'] = $c['zona'] ?? null;
                $c['zona'] = $zg;
            }
            return $c;
        }, $casos);

        if ($u['rol'] === 'TECNICO') {
            $mios = array_keys(array_filter(
                $gestion,
                fn($g) => (int) ($g['asignado_a'] ?? 0) === (int) $u['usuario_id']
            ));
            $mios = array_flip($mios);
            return array_values(array_filter($casos, fn($c) => isset($mios[$c['aviso'] ?? ''])));
        }

        $zona = Auth::zonaAlcance();
        if ($zona === null) { return $casos; }        // administración: las tres

        // Un caso derivado sale de la zona vieja y entra a la nueva (arriba ya
        // lleva la zona de la gestión): el jefe que lo derivó deja de verlo.
        return array_values(array_filter($casos, fn($c) => ($c['zona'] ?? null) === $zona));
    }

    /** La gestión de todos los casos, indexada por aviso. */
    public static function gestion(): array
    {
        $filas = Db::todos(
            'SELECT g.*, t.nombre AS tecnico_nombre, t.usuario AS tecnico_usuario,
                    a.nombre AS asignador_nombre, v.nombre AS veredicto_nombre,
                    r.nombre AS revision_nombre, o.nombre AS otro_trabajo_nombre
               FROM casos_gestion g
               LEFT JOIN usuarios t ON t.usuario_id = g.asignado_a
               LEFT JOIN usuarios a ON a.usuario_id = g.asignado_por
               LEFT JOIN usuarios v ON v.usuario_id = g.veredicto_por
               LEFT JOIN usuarios r ON r.usuario_id = g.revision_por
               LEFT JOIN usuarios o ON o.usuario_id = g.otro_trabajo_por'
        );
        $out = [];
        foreach ($filas as $f) { $out[$f['aviso']] = $f; }
        return $out;
    }

    /**
     * ¿El caso parece fuera del área de INDUSTEC? Lo dicen las alertas de
     * `config/alcance_trabajos.json`: CON_ALERTA, y POR_CONFIRMAR para los
     * tipos que todavía no tienen criterio. No decide nada: señala dónde hace
     * falta la decisión de la administradora (011, «otros trabajos»).
     */
    public static function fueraDeArea(array $c): bool
    {
        return in_array((string) ($c['estado_alerta'] ?? ''), ['CON_ALERTA', 'POR_CONFIRMAR'], true);
    }

    /**
     * Fuera del área y sin decidir: la ÚNICA definición, para el panel, el
     * buzón y los reportes. Deja de estarlo cuando la administradora lo
     * autoriza —o no— como «otro trabajo» (hubo acuerdo con KFC), o cuando lo
     * cierra como «no nos compete» y le pide a KFC que lo derive. Un caso ya
     * RESUELTO también entra: el 10351229 (Mant. Constructivo, CNLJ) se atendió
     * y se cerró sin que nadie decidiera si era un extra.
     */
    public static function otroTrabajoPorDecidir(array $c, ?array $g): bool
    {
        return self::fueraDeArea($c)
            && empty($g['otro_trabajo'])
            && ($g['estado'] ?? 'NUEVO') !== 'NO_COMPETE';
    }

    /**
     * Los casos de un técnico en esos estados, desde la base y no desde el catálogo.
     *
     * El catálogo del buzón es una ventana que la estación reescribe entera: el
     * 2026-09-10 había 4 casos ASIGNADO fuera de él y sus 3 técnicos no los veían
     * ni en la bandeja ni en el formulario (T2.13.3). La lista la manda
     * `casos_gestion`, que es lo que decidimos y persiste; el catálogo solo la
     * completa. Lo que no está en él va con `sin_catalogo` y sin inventar nada (I-7).
     *
     * @param string[] $estados
     * @return array<int,array<string,mixed>> filas con la forma del catálogo
     */
    public static function delTecnico(int $usuarioId, array $estados, array $gestion): array
    {
        $cat = [];
        foreach (self::catalogo()['datos'] ?? [] as $c) { $cat[(string) ($c['aviso'] ?? '')] = $c; }
        $out = [];
        foreach ($gestion as $aviso => $g) {
            $aviso = (string) $aviso;   // un aviso numérico llega como clave int
            if ((int) ($g['asignado_a'] ?? 0) !== $usuarioId || !in_array($g['estado'], $estados, true)) {
                continue;
            }
            $c = $cat[$aviso] ?? ['aviso' => $aviso, 'sin_catalogo' => true];
            if (($g['zona'] ?? '') !== '') { $c['zona'] = $g['zona']; }   // la de la gestión manda
            $out[] = $c;
        }
        return $out;
    }

    /**
     * Los técnicos a los que este usuario puede asignar.
     *
     * Un jefe de zona solo reparte entre los suyos. La administración ve las
     * tres. Se listan solo los activos: asignarle un caso a alguien que ya no
     * trabaja aquí es una orden que nadie va a atender.
     */
    public static function tecnicosAsignables(): array
    {
        $zona = Auth::zonaAlcance();
        $sql = "SELECT usuario_id, usuario, nombre, zona FROM usuarios
                 WHERE rol IN ('TECNICO','JEFE_ZONA') AND activo = 1";
        $par = [];
        if ($zona !== null) { $sql .= ' AND zona = ?'; $par[] = $zona; }
        $sql .= ' ORDER BY zona, nombre';
        return Db::todos($sql, $par);
    }

    /**
     * Asegura que exista la fila de gestión de un caso, y devuelve su estado.
     *
     * Se crea al primer toque, no al aparecer el caso: 917 filas vacías no
     * dicen nada y habría que mantenerlas sincronizadas con un catálogo que se
     * reescribe entero cada vez.
     */
    public static function asegurar(string $aviso, ?string $zona): void
    {
        Db::ejecutar(
            'INSERT INTO casos_gestion (aviso, zona) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE zona = COALESCE(zona, VALUES(zona))',
            [$aviso, $zona]
        );
    }

    /**
     * La orden emitida desde la app mueve el caso en el acto (T2.14.3).
     *
     * Hasta el 2026-09-13 solo la reconciliación nocturna —que lee los informes
     * del sistema viejo— ponía `ot_cierre` y pasaba el caso a ATENDIDO. Una
     * orden concluida desde el celular dejaba el caso ASIGNADO hasta la noche
     * siguiente, y la administradora no lo veía en «a registrar en SAP» en
     * tiempo real, que es lo que pidió. Aquí se aplica la misma regla que
     * `Reconciliar::atenciones()`, para un solo caso y en el momento:
     *
     *   - concluida: `ot_cierre` y `atendido_en` si no los tenía, y ATENDIDO,
     *     salvo que una persona ya lo haya resuelto (RESUELTO/NO_COMPETE),
     *     esté en revisión, o siga esperando un repuesto (ESPERA_REPUESTO: lo
     *     libera `Pendientes` cuando el último pendiente se cierra, y como el
     *     caso ya tiene `ot_cierre`, cae en ATENDIDO).
     *   - sin concluir: el caso queda ASIGNADO al técnico que firmó, si nadie
     *     lo había asignado ni derivado; un ATENDIDO no regresa (ASG-13).
     *
     * Lo que decidió una persona (asignado_por, derivado_en) no se pisa.
     */
    public static function atenderPorOrden(string $aviso, ?string $zona, string $idIndustec,
                                           bool $concluida, int $tecnicoId): void
    {
        $aviso = trim($aviso);
        if ($aviso === '') { return; }
        self::asegurar($aviso, $zona);
        $antes = Db::uno('SELECT estado FROM casos_gestion WHERE aviso = ?', [$aviso]);
        $estadoAntes = (string) ($antes['estado'] ?? 'NUEVO');

        if ($concluida) {
            Db::ejecutar(
                "UPDATE casos_gestion
                    SET ot_cierre    = COALESCE(ot_cierre, ?),
                        atendido_en  = COALESCE(atendido_en, NOW()),
                        asignado_a   = IF(asignado_por IS NULL AND derivado_en IS NULL AND asignado_a IS NULL,
                                          ?, asignado_a),
                        tecnico_auto = IF(asignado_por IS NULL AND derivado_en IS NULL AND asignado_a IS NOT NULL
                                          AND asignado_a = ?, 1, tecnico_auto),
                        estado       = CASE
                            WHEN estado IN ('RESUELTO','NO_COMPETE','EN_REVISION','ESPERA_REPUESTO') THEN estado
                            ELSE 'ATENDIDO' END
                  WHERE aviso = ?",
                [$idIndustec, $tecnicoId, $tecnicoId, $aviso]
            );
            $nuevo = 'ATENDIDO';
        } else {
            Db::ejecutar(
                "UPDATE casos_gestion
                    SET asignado_a   = IF(asignado_por IS NULL AND derivado_en IS NULL AND asignado_a IS NULL,
                                          ?, asignado_a),
                        tecnico_auto = IF(asignado_por IS NULL AND derivado_en IS NULL AND asignado_a IS NOT NULL
                                          AND asignado_a = ?, 1, tecnico_auto),
                        estado       = CASE
                            WHEN estado IN ('RESUELTO','NO_COMPETE','EN_REVISION','ESPERA_REPUESTO','ATENDIDO') THEN estado
                            WHEN asignado_a IS NULL THEN estado
                            ELSE 'ASIGNADO' END
                  WHERE aviso = ?",
                [$tecnicoId, $tecnicoId, $aviso]
            );
            $nuevo = 'ASIGNADO';
        }

        $desp = Db::uno('SELECT estado FROM casos_gestion WHERE aviso = ?', [$aviso]);
        $estadoDesp = (string) ($desp['estado'] ?? $estadoAntes);
        if ($estadoDesp !== $estadoAntes) {
            // Mismo nombre de acción que la reconciliación, para que la bitácora
            // y minar.php cuenten las dos fuentes juntas; la fuente lo distingue.
            Auth::bitacora($concluida ? 'ATENDIDO_AUTO' : 'ASIGNADO_AUTO', 'caso', $aviso,
                           'orden ' . $idIndustec . ($concluida ? ' concluida' : ' sin concluir'),
                           $estadoAntes, $estadoDesp,
                           ['ot' => $idIndustec, 'fuente' => 'orden emitida por la app', 'tecnico' => $tecnicoId]);
        } elseif ($concluida && $estadoAntes === 'ESPERA_REPUESTO') {
            Auth::bitacora('OT_CIERRE_CON_PENDIENTE', 'caso', $aviso,
                           'orden ' . $idIndustec . ' concluida con el caso esperando repuesto',
                           $estadoAntes, $estadoAntes, ['ot' => $idIndustec]);
        }
        unset($nuevo);
    }

    /** ¿Existe ese aviso —en el catálogo o, para el técnico, asignado a él— y lo alcanza este usuario? */
    public static function alcanzaAviso(string $aviso, array $gestion): ?array
    {
        foreach (self::catalogo()['datos'] ?? [] as $c) {
            if (($c['aviso'] ?? '') === $aviso) {
                $vis = self::enAlcance([$c], $gestion);
                return $vis === [] ? null : $vis[0];
            }
        }
        /* Fuera del catálogo, al técnico le alcanza lo que tiene asignado en la
           base: si no, el caso que ve en su bandeja (delTecnico) no se podía
           reportar trabado y su orden salía marcada «fuera de alcance». Sin zona
           en la gestión vale la del técnico, que es la del jefe que decide. */
        $u = Auth::actual();
        $g = $gestion[$aviso] ?? null;
        if ($u !== null && $u['rol'] === 'TECNICO' && $g !== null
            && (int) ($g['asignado_a'] ?? 0) === (int) $u['usuario_id']) {
            return ['aviso' => $aviso, 'sin_catalogo' => true,
                    'zona' => ($g['zona'] ?? '') !== '' ? $g['zona'] : ($u['zona'] ?? null)];
        }
        /* A la administración y al jefe de zona les alcanza también lo que tiene
           fila de gestión y ya salió de la ventana del catálogo: un caso ATENDIDO
           hace cuatro meses que falta confirmar en SAP, o un aviso que KFC borró
           del correo. Hasta el 2026-09-13 esto respondía «fuera de su alcance»
           hasta a un superadmin (AUDITORIA_2026-09-10 §6, aviso 10353555). El
           corte por zona se mantiene: el jefe solo alcanza los de su zona. */
        if ($u !== null && $u['rol'] !== 'TECNICO' && $g !== null) {
            $za = Auth::zonaAlcance();
            $zg = (string) ($g['zona'] ?? '');
            if ($za === null || ($zg !== '' && $zg === $za)) {
                return ['aviso' => $aviso, 'sin_catalogo' => true,
                        'zona' => $zg !== '' ? $zg : null, 'local' => '', 'local_nombre' => '',
                        'caso' => 'sin dato en el catálogo', 'estado_gestion' => $g['estado'] ?? 'NUEVO'];
            }
        }
        return null;
    }

    /* --- Las transiciones legales del caso ---------------------------------
       Hasta la 009 cada rama de casos.php decidía por su cuenta desde qué
       estado se podía hacer qué, y se quedaron huecos: «veredicto → RESUELTO»
       cerraba un caso ESPERA_REPUESTO con el equipo parado (ASG-01), «revisión»
       y «derivar» reabrían un caso RESUELTO con un POST fabricado (ASG-18).
       Una sola tabla, que usan las ramas y los botones. Las claves son la
       acción del formulario; para `veredicto` se mira además cuál. */
    public const TRANSICIONES = [
        // asignar reabre lo cerrado sin atención: la administradora que reparte
        // un caso ya decidió regularizarlo (D6). Sobre ASIGNADO es reasignar.
        // ESPERA_REPUESTO también se puede reasignar (ASG-02 MATIZADO): cambia
        // quién tiene detrás el repuesto pendiente sin tocar el estado, y eso
        // se decide en casos.php mirando `$antes`, no aquí.
        'asignar'              => ['NUEVO', 'EN_REVISION', 'CERRADO_SIN_ATENCION', 'ASIGNADO', 'ESPERA_REPUESTO'],
        'derivar'              => ['NUEVO', 'ASIGNADO', 'EN_REVISION'],
        'revision'             => ['NUEVO', 'ASIGNADO'],
        'veredicto:NO_COMPETE' => ['NUEVO', 'ASIGNADO', 'EN_REVISION'],
        // Solo desde ATENDIDO: el cierre es de dos manos, y la segunda no puede
        // darse antes de que exista la primera.
        'veredicto:RESUELTO'   => ['ATENDIDO'],
        'cerrado_sap'          => ['ATENDIDO'],
        'regularizar'          => ['CERRADO_SIN_ATENCION'],
        'seguimiento'          => ['ASIGNADO', 'ESPERA_REPUESTO', 'ATENDIDO'],
    ];

    /** ¿Se puede hacer esta acción sobre un caso en ese estado? */
    public static function puedeTransitar(string $accion, ?string $estadoAntes, ?string $veredicto = null): bool
    {
        $clave = $accion === 'veredicto' ? 'veredicto:' . strtoupper((string) $veredicto) : $accion;
        $desde = self::TRANSICIONES[$clave] ?? null;
        if ($desde === null) {
            return false;
        }
        return in_array(strtoupper((string) ($estadoAntes ?: 'NUEVO')), $desde, true);
    }

    /**
     * Lo que falta repartir: la ÚNICA definición.
     *
     * Hasta la 009 el panel contaba solo NUEVO, la asignación contaba NUEVO y
     * EN_REVISION, y el buzón otra cosa: tres cifras distintas de «sin
     * repartir» en tres pantallas (ASG-12). Y los casos NUEVO con un informe
     * abierto firmado por alguien que no está en el padrón quedaban en un
     * limbo: con informe no eran «sin repartir», sin técnico reconocido nadie
     * los tenía, y la reconciliación tampoco los cerraba. Aquí entran, marcados,
     * para que alguien los reparta a mano.
     *
     * @return array<int,array{c:array,estado:string,revision:?string,informe_sin_usuario:bool}>
     */
    public static function sinAsignar(array $casos, array $gestion, array $aten): array
    {
        $out = [];
        foreach ($casos as $c) {
            $aviso  = (string) ($c['aviso'] ?? '');
            $g      = $gestion[$aviso] ?? null;
            $estado = (string) ($g['estado'] ?? 'NUEVO');
            if (!in_array($estado, ['NUEVO', 'EN_REVISION'], true)) {
                continue;
            }
            $conInforme = isset($aten[$aviso]);
            if ($conInforme) {
                // Con informe y con técnico reconocido, la reconciliación ya lo
                // puso ASIGNADO o ATENDIDO. Si sigue NUEVO es que la firma no
                // cruzó con nadie del padrón: se ofrece a mano.
                $usuarios = $aten[$aviso]['usuarios'] ?? [];
                if (!empty($usuarios) || $estado !== 'NUEVO') {
                    continue;
                }
            }
            $out[] = ['c' => $c, 'estado' => $estado,
                      'revision' => $g['revision_motivo'] ?? null,
                      'informe_sin_usuario' => $conInforme];
        }
        usort($out, static function (array $a, array $b): int {
            $pa = ['ALTA' => 0, 'MEDIA' => 1, 'BAJA' => 2][$a['c']['prioridad'] ?? ''] ?? 3;
            $pb = ['ALTA' => 0, 'MEDIA' => 1, 'BAJA' => 2][$b['c']['prioridad'] ?? ''] ?? 3;
            if ($pa !== $pb) { return $pa <=> $pb; }
            return strcmp((string) ($b['c']['fecha_creacion'] ?? ''), (string) ($a['c']['fecha_creacion'] ?? ''));
        });
        return $out;
    }

    /**
     * Los casos con gestión viva que ya no están en el catálogo del buzón.
     *
     * El catálogo es una ventana de 90 días que la estación reescribe entera.
     * Un caso ATENDIDO hace cuatro meses que la administración todavía no
     * confirmó en SAP sale de la ventana y desaparece de «a registrar en SAP»
     * (ASG-04). Aquí se recuperan desde `casos_gestion`, con la forma mínima
     * que las pantallas leen y marcados, sin inventar lo que el catálogo ya no
     * dice (I-7).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fueraDeCatalogo(array $gestion): array
    {
        $cat = [];
        foreach (self::catalogo()['datos'] ?? [] as $c) { $cat[(string) ($c['aviso'] ?? '')] = true; }
        $vivos = ['ASIGNADO', 'ESPERA_REPUESTO', 'ATENDIDO', 'EN_REVISION', 'CERRADO_SIN_ATENCION'];
        $out = [];
        foreach ($gestion as $aviso => $g) {
            $aviso = (string) $aviso;
            if (isset($cat[$aviso]) || !in_array($g['estado'] ?? '', $vivos, true)) {
                continue;
            }
            $out[] = [
                'aviso' => $aviso, 'sin_catalogo' => true,
                'zona' => (string) ($g['zona'] ?? ''), 'local' => '', 'local_nombre' => '',
                'caso' => 'sin dato en el catálogo', 'prioridad' => '',
                'fecha_creacion' => substr((string) ($g['creado_en'] ?? ''), 0, 10),
                'fecha_estimada' => '', 'estado_alerta' => '', 'estado_gestion' => $g['estado'],
            ];
        }
        return $out;
    }

    /**
     * Todos los documentos de cada caso, relacionados por AVISO.
     *
     * Hasta el 2026-09-14 el buzón pintaba solo lo que traía `atenciones.json`,
     * y la orden de cierre que dejan la reconciliación y la app en
     * `casos_gestion.ot_cierre` no salía: 38 de los 67 casos con orden de
     * cierre se veían «sin atender» en la misma fila que decía «atendido ·
     * técnico (del informe)» (avisos 10354415 y 10354383, LARB). Y un mismo
     * informe llega con dos nombres —`OT-2488-K061-10351229-CNLJ` por el correo
     * y `OT-2488-K061EC-10351229-CNLJ` en el árbol canónico—, así que la
     * relación se hace por el aviso y no por el nombre exacto de la orden.
     *
     * Cuatro fuentes, sin repetir una orden: el índice del Archivo
     * (`ot_archivo`), la orden de cierre, lo que llegó por correo y lo que
     * emitió la app (`ot_capturadas`, que trae también lo que sigue en curso).
     * Si el PDF está en el servidor lo dice `Emision::existePdf()` en el
     * momento, no `ot_archivo.en_servidor`, que solo se refresca al indexar.
     *
     * @param string[] $avisos los avisos que se van a pintar
     * @return array<string,array<int,array{ot:string,fecha:?string,cierre:bool,pdf:bool}>>
     */
    public static function documentos(array $avisos, array $gestion, array $aten): array
    {
        require_once __DIR__ . '/Emision.php';
        // El aviso llega con y sin ceros a la izquierda según la fuente
        // (`000010352936` en SAP, `10352936` en el nombre de la orden).
        $clave = static fn($a): string => ltrim(trim((string) $a), '0');
        $quiero = [];
        foreach ($avisos as $a) {
            if ($clave($a) !== '') { $quiero[$clave($a)] = (string) $a; }
        }
        $docs = [];
        $poner = static function (string $k, string $ot, ?string $fecha, bool $cierre) use (&$docs, $quiero): void {
            $ot = strtoupper(trim($ot));
            if ($ot === '' || !isset($quiero[$k])) { return; }
            $aviso = $quiero[$k];
            $f = $docs[$aviso][$ot] ?? ['ot' => $ot, 'fecha' => null, 'cierre' => false];
            if ($f['fecha'] === null && $fecha !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $fecha, $m)) {
                $f['fecha'] = $m[0];
            }
            $f['cierre'] = $f['cierre'] || $cierre;
            $docs[$aviso][$ot] = $f;
        };

        try {
            foreach (Db::todos("SELECT id_industec, aviso, fecha_atencion FROM ot_archivo
                                 WHERE aviso IS NOT NULL AND aviso <> ''") as $r) {
                $poner($clave($r['aviso']), (string) $r['id_industec'], $r['fecha_atencion'], false);
            }
        } catch (Throwable $e) { /* sin la 009 no hay índice: quedan las otras tres fuentes */ }

        foreach ($gestion as $aviso => $g) {
            if (!empty($g['ot_cierre'])) {
                $poner($clave($aviso), (string) $g['ot_cierre'], $g['atendido_en'] ?? null, true);
            }
        }
        foreach ($aten as $aviso => $a) {
            foreach ($a['ots'] ?? [] as $o) {
                $poner($clave($aviso), (string) ($o['ot'] ?? ''), $o['fecha'] ?? null,
                       ($o['estado_ot'] ?? '') === 'Cerrada');
            }
        }
        try {
            foreach (Db::todos("SELECT id_industec, aviso, emitida_en FROM ot_capturadas
                                 WHERE id_industec IS NOT NULL AND aviso IS NOT NULL AND aviso <> ''
                                   AND estado IN ('EMITIDA','ENVIADA','NUMERADA','FALLIDA','PROCESADA')") as $r) {
                $poner($clave($r['aviso']), (string) $r['id_industec'], $r['emitida_en'], false);
            }
        } catch (Throwable $e) { /* ot_capturadas llega con la 008 */ }

        $out = [];
        foreach ($docs as $aviso => $porOt) {
            $lista = [];
            foreach ($porOt as $f) { $lista[] = $f + ['pdf' => Emision::existePdf($f['ot'])]; }
            usort($lista, static fn($x, $y) => [(string) $x['fecha'], $x['ot']] <=> [(string) $y['fecha'], $y['ot']]);
            $out[$aviso] = $lista;
        }
        return $out;
    }

    /**
     * Etiqueta legible del estado.
     *
     * La lista completa vive en `Ui::ESTADOS` y esto delega ahí a propósito.
     * Cuando estaba duplicada aquí se quedó corta DOS veces: le faltaron
     * ATENDIDO y CERRADO_SIN_ATENCION al agregarlos al ENUM, y esos casos se
     * dibujaban en crudo —«CERRADO_SIN_ATENCION», en mayúsculas y con guion
     * bajo— en la pantalla que mira la administradora. Con una sola lista, el
     * estado nuevo que se olvide sale mal en un sitio y no en cinco.
     */
    public static function etiquetaEstado(?string $estado): string
    {
        require_once __DIR__ . '/Ui.php';
        return Ui::etiquetaEstado($estado);
    }
}
