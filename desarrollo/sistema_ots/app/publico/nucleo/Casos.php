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

    /**
     * El catálogo completo, tal como lo dejó el último barrido, más el
     * catálogo de prueba del arnés cuando corresponde (T2.28.1).
     *
     * Hasta el 2026-09-23 `preparar_prueba.php` no tenía más remedio que
     * asignarle al técnico de prueba dos casos REALES abiertos, porque los
     * avisos sintéticos (9999xxxx) no traían `local` y `envio.php` rechazaba
     * cualquier orden contra ellos. Así quedaron secuestrados los avisos
     * 10355931 y 10356012: un jefe de zona de verdad dejó de verlos en su
     * buzón. Ahora el arnés escribe sus propios avisos sintéticos CON local en
     * `catalogos/casos_prueba.json`, y esta función los fusiona aquí — nunca
     * los toma prestados de lo que pidió KFC.
     */
    public static function catalogo(): array
    {
        if (self::$catalogo !== null) {
            return self::$catalogo;
        }
        $cat = self::leerJson('casos_sap.json', 'datos');
        if (self::pruebaAplica()) {
            $existentes = [];
            foreach ($cat['datos'] ?? [] as $c) { $existentes[(string) ($c['aviso'] ?? '')] = true; }
            $prueba = self::leerJson('casos_prueba.json', 'datos');
            foreach ($prueba['datos'] ?? [] as $c) {
                $aviso = (string) ($c['aviso'] ?? '');
                // Solo avisos con la forma de los sintéticos del arnés, y jamás
                // pisando uno que ya esté en el catálogo real: un dato de
                // prueba nunca sustituye uno real (I-7).
                if (preg_match('/^9999\d{4}$/', $aviso) !== 1 || isset($existentes[$aviso])) { continue; }
                $cat['datos'][] = $c;
                $existentes[$aviso] = true;
            }
        }
        return self::$catalogo = $cat;
    }

    /**
     * ¿Se fusiona el catálogo de prueba? Solo en el sitio de pruebas
     * (`Emision::modo() === 'PRUEBA'`) y solo para quien está probando:
     *
     *   - CON sesión: decide la sesión, aunque se consulte por CLI —
     *     `alcance_cli.php <usuario>` simula la de cualquiera—. Una cuenta
     *     real, aunque sea la de la administradora entrando a probar el
     *     sitio, nunca trae los casos de prueba a su buzón: el login tiene
     *     que llevar «_prueba» (las cinco cuentas que crea
     *     `preparar_prueba.php`).
     *   - SIN sesión: basta con CLI. Es el caso de los propios scripts de
     *     mantenimiento del arnés (`preparar_prueba.php`, `limpiar_pruebas.php`,
     *     `archivo_verificar_cli.php`…), que no abren sesión web ninguna.
     */
    private static function pruebaAplica(): bool
    {
        require_once __DIR__ . '/Emision.php';
        if (Emision::modo() !== 'PRUEBA') {
            return false;
        }
        $u = Auth::actual();
        if ($u !== null) {
            return str_contains((string) ($u['usuario'] ?? ''), '_prueba');
        }
        return PHP_SAPI === 'cli';
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
        $sql = 'SELECT g.*, t.nombre AS tecnico_nombre, t.usuario AS tecnico_usuario,
                       a.nombre AS asignador_nombre, v.nombre AS veredicto_nombre,
                       r.nombre AS revision_nombre, o.nombre AS otro_trabajo_nombre%s
                  FROM casos_gestion g
                  LEFT JOIN usuarios t ON t.usuario_id = g.asignado_a
                  LEFT JOIN usuarios a ON a.usuario_id = g.asignado_por
                  LEFT JOIN usuarios v ON v.usuario_id = g.veredicto_por
                  LEFT JOIN usuarios r ON r.usuario_id = g.revision_por
                  LEFT JOIN usuarios o ON o.usuario_id = g.otro_trabajo_por%s';
        /* Quién declaró la continuidad (la 012). El JOIN se agrega solo si la
           columna existe: nombrarla sin la migración aplicada haría fallar la
           consulta entera y dejaría SIN BANDEJA a todos los técnicos, no solo
           sin este dato. `SELECT g.*` ya degrada bien por su cuenta.
           El resultado del intento se recuerda por petición: sin eso, en un
           servidor sin la 012 cada llamada pagaría una consulta que falla. */
        static $hay012 = null;
        if ($hay012 !== false) {
            try {
                $filas = Db::todos(sprintf($sql, ', c.nombre AS continua_nombre',
                                           ' LEFT JOIN usuarios c ON c.usuario_id = g.continua_por'));
                $hay012 = true;
            } catch (Throwable $ex) {
                $hay012 = false;
            }
        }
        if ($hay012 === false) {
            $filas = Db::todos(sprintf($sql, '', ''));
        }
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

    /**
     * La zona real de un aviso, exista o no fila de gestión, esté o no asignado.
     *
     * Para enlazar un caso con «el trabajo anterior que ya empecé» (T2.25.2) no
     * importa a quién está asignado ese trabajo anterior: las urgencias hacen
     * que un caso lo termine un técnico distinto del que lo arrancó, y exigir
     * que sea el mismo técnico dejaba sin enlazar el caso más común. Lo que sí
     * tiene que seguir cortando es la zona — por eso esta función, separada de
     * `alcanzaAviso()`, que sigue siendo «es mío» para emitir una orden o abrir
     * un pendiente (`envio.php`, `Pendientes.php`): ese candado no se toca.
     */
    public static function zonaDeAviso(string $aviso, array $gestion): ?string
    {
        $zg = trim((string) ($gestion[$aviso]['zona'] ?? ''));
        if ($zg !== '') { return $zg; }
        foreach (self::catalogo()['datos'] ?? [] as $c) {
            if (($c['aviso'] ?? '') === $aviso) { return $c['zona'] ?? null; }
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

    /* =====================================================================
       CONTINUIDAD: UN TRABAJO, VARIOS AVISOS (T2.25, la 012)

       SAP cierra solo el aviso que nadie atendió en 48 horas. Cuando el
       técnico fue, diagnosticó y el repuesto tarda, ese aviso muere y KFC
       abre otro por el mismo equipo. El horno HORNO-S/M-2023-118 de G006EC
       llegó a tener CUATRO avisos por el mismo problema —10342524, 10342924,
       10343636 y 10349666— y para el segundo KFC ya estaba pidiendo «el
       repuesto del horno», o sea que el técnico ya había ido.

       Hasta aquí el sistema no tenía cómo decirlo, así que el técnico o
       emitía una orden duplicada o dejaba el caso pendiente para siempre.

       LO QUE ESTE CODIGO NO HACE, Y ES LO IMPORTANTE: no enlaza nada por su
       cuenta. `continuidadPosible()` propone y una persona confirma. Los
       mismos datos que justifican la función traen los contraejemplos: en
       J022EC el par del mismo equipo es «informe técnico para dar de baja» ->
       «instalando el nuevo equipo», y en K124EC «no emite sonido» -> «escape
       de aceite». Son trabajos distintos sobre el mismo equipo, y cerrarlos
       por parecido sería declarar ante Grupo KFC que se atendió un caso que
       nadie atendió (I-7).
       ===================================================================== */

    /** Cuántos días atrás se busca un trabajo anterior del mismo equipo. */
    public const CONTINUIDAD_DIAS = 30;
    /** Cuántos candidatos se le ofrecen. Más de tres en un celular es una lista, no una decisión. */
    public const CONTINUIDAD_TOPE = 3;

    /**
     * Normaliza un activo fijo para compararlo.
     *
     * KFC escribe el mismo equipo de maneras que solo difieren en espacios y
     * mayúsculas («FREIDORA-SR 142GP-2205MA0180» frente a «freidora-sr 142gp…»).
     * No se toca nada más: quitar los guiones juntaría equipos distintos,
     * porque el guion es lo que separa denominación, modelo y serie.
     */
    public static function equipoNormalizado(?string $activoFijo): string
    {
        $s = mb_strtoupper(trim((string) $activoFijo));
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        // «CAMARA DE REFRIGERACION--» es el equipo sin modelo ni serie: los
        // guiones de relleno no aportan y se caen, pero el texto sí queda.
        return trim($s, ' -');
    }

    /**
     * La raíz de la cadena de un caso: el primer aviso del mismo trabajo.
     *
     * La cadena se guarda PLANA —todos los casos apuntan al primero, no al
     * inmediatamente anterior—, así que esto normalmente resuelve en un salto.
     * El bucle con tope existe para que una fila escrita a mano en la base no
     * pueda colgar la pantalla: a los 10 saltos se devuelve lo último visto.
     */
    public static function raiz(string $aviso, ?array $gestion = null): string
    {
        $aviso = trim($aviso);
        $gestion ??= self::gestion();
        $vistos = [$aviso => true];
        for ($i = 0; $i < 10; $i++) {
            $de = trim((string) ($gestion[$aviso]['continua_de'] ?? ''));
            if ($de === '' || isset($vistos[$de])) { break; }
            $vistos[$de] = true;
            $aviso = $de;
        }
        return $aviso;
    }

    /**
     * Quién consta que atendió cada uno de esos avisos, en UNA consulta.
     *
     * Es lo que deja ver al jefe de zona que un trabajo cruzó de técnico
     * (T2.25.5). El dato sale de la FIRMA de la orden archivada, no de
     * `asignado_a`: el primer caso real de continuidad lo demostró —el aviso
     * 10342524 estaba CERRADO_SIN_ATENCION y con `asignado_a` en NULL, porque
     * SAP lo cerró solo a las 48 h, y sin embargo la orden OT-1561 la firmó
     * Marco Taipe—. Comparar contra `asignado_a` habría dicho «sin dato»
     * justo en el caso que hay que ver.
     *
     * `asignado_a` queda de respaldo para el aviso que todavía no tiene
     * orden. Si no hay ninguna de las dos cosas, el aviso no aparece en el
     * resultado: quien llama dice «no consta», no inventa un nombre (I-7).
     *
     * OJO con el nombre: `ot_archivo.tecnico` es la firma cruda del PDF
     * («Anthony Jumbo»), no el nombre del padrón («Anthony Medardo Jumbo
     * Rojano»). Sirve para MOSTRARLO, que es para lo que se usa. Para contar
     * o filtrar por técnico hace falta antes resolver esa identidad, que es
     * el mismo pendiente que tiene «Las mías» del Archivo.
     *
     * @param string[] $avisos
     * @return array<string,array{nombre:string,fuente:string}>
     */
    public static function quienAtendio(array $avisos): array
    {
        $avisos = array_values(array_unique(array_filter(array_map(
            static fn($a): string => trim((string) $a), $avisos
        ))));
        if ($avisos === []) { return []; }
        $marcas = implode(',', array_fill(0, count($avisos), '?'));

        $out = [];
        try {
            // La más reciente de cada aviso: es la que refleja quién quedó al
            // frente del trabajo, no quién pasó primero.
            foreach (Db::todos(
                "SELECT aviso, tecnico, fecha_atencion FROM ot_archivo
                  WHERE aviso IN ($marcas) AND tecnico IS NOT NULL AND tecnico <> ''
                  ORDER BY fecha_atencion", $avisos) as $r) {
                $out[(string) $r['aviso']] = ['nombre' => (string) $r['tecnico'], 'fuente' => 'la orden'];
            }
        } catch (Throwable $e) { /* sin la 009 no hay índice: queda el respaldo */ }

        foreach (Db::todos(
            "SELECT g.aviso, u.nombre FROM casos_gestion g
               JOIN usuarios u ON u.usuario_id = g.asignado_a
              WHERE g.aviso IN ($marcas)", $avisos) as $r) {
            $out[(string) $r['aviso']] ??= ['nombre' => (string) $r['nombre'], 'fuente' => 'asignado'];
        }
        return $out;
    }

    /**
     * ¿Estos dos nombres son, con poco margen de duda, la misma persona?
     *
     * Solo decide el RESALTE de la pantalla, nunca el texto: los dos nombres
     * se muestran siempre tal como constan, y quien mira saca su conclusión.
     * Por eso puede equivocarse sin afirmar nada falso (I-7).
     *
     * Dos palabras en común bastan —«Anthony Jumbo» y «Anthony Medardo Jumbo
     * Rojano» son el mismo—, porque la firma del PDF recorta el nombre del
     * padrón de formas que no se pueden prever. Con una sola palabra en común
     * se devuelve `true` a propósito: ante la duda NO se marca el caso como
     * cruce, que es el error que le haría perder el tiempo a un jefe de zona.
     */
    public static function mismaPersona(?string $a, ?string $b): bool
    {
        $pal = static function (?string $s): array {
            $s = mb_strtoupper(trim((string) $s), 'UTF-8');
            $s = strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
            $s = preg_replace('/[^A-Z ]+/', ' ', $s) ?? '';
            return array_values(array_filter(explode(' ', $s), static fn($p) => mb_strlen($p) > 2));
        };
        $pa = $pal($a);
        $pb = $pal($b);
        if ($pa === [] || $pb === []) { return true; }   // sin nombre no se afirma un cruce
        return array_intersect($pa, $pb) !== [];
    }

    /**
     * Toda la cadena de un caso: él, su raíz y los demás que cuelgan de ella.
     *
     * Es lo que cierra una orden concluida (T2.25.3). Solo entran casos que
     * alguien enlazó a mano: nadie llega aquí por parecerse a otro.
     *
     * @return string[] avisos, sin repetir, empezando por el que se pidió
     */
    public static function cadena(string $aviso, ?array $gestion = null): array
    {
        $aviso = trim($aviso);
        if ($aviso === '') { return []; }
        $gestion ??= self::gestion();
        $raiz = self::raiz($aviso, $gestion);
        $out = [$aviso => true, $raiz => true];
        foreach ($gestion as $a => $g) {
            if (trim((string) ($g['continua_de'] ?? '')) === $raiz) { $out[(string) $a] = true; }
        }
        /* El `(string)` de la vuelta NO sobra: un aviso es todo dígitos, y PHP
           convierte a int las claves numéricas de un array, así que
           `array_keys()` devolvía ints. Con `declare(strict_types=1)`,
           `atenderPorOrden(int)` revienta con TypeError, la excepción se
           tragaba en el catch de `envio.php` y la orden se emitía SIN cerrar
           ningún caso de la cadena -- en silencio, con el recibo diciendo que
           sí. Lo cazó `verificar_continuidad.py` contra el servidor; en local
           no se veía porque la prueba comparaba con `implode()`, que convierte.
           Es el mismo tropiezo que ya documenta `delTecnico()` más arriba. */
        return array_map('strval', array_keys($out));
    }

    /**
     * Qué trabajo anterior puede estar continuando este caso.
     *
     * Busca en el catálogo del buzón —que es lo que el técnico reconoce— los
     * casos del MISMO local y el MISMO equipo creados antes y dentro de la
     * ventana, y los devuelve ordenados por cercanía. De cada uno trae lo que
     * hace falta para decidir sin salir de la pantalla: el texto que escribió
     * KFC, la orden que salió de ese caso, y si dejó un equipo trabado.
     *
     * No mira el parecido de los textos a propósito. Dos pedidos del mismo
     * equipo se parecen siempre («su ayuda con un técnico»), y dos que no son
     * el mismo trabajo también: el parecido no distingue nada, y quien sí
     * distingue es el técnico que estuvo ahí.
     *
     * @return array<int,array<string,mixed>> candidatos, el más cercano primero
     */
    public static function continuidadPosible(array $caso, array $gestion, ?array $aten = null): array
    {
        $aviso = trim((string) ($caso['aviso'] ?? ''));
        $local = trim((string) ($caso['local'] ?? ''));
        $equipo = self::equipoNormalizado($caso['activo_fijo'] ?? '');
        $desde  = (string) ($caso['fecha_creacion'] ?? '');
        if ($aviso === '' || $local === '' || $equipo === '' || $desde === '') {
            return [];                  // sin local, sin equipo o sin fecha no hay con qué comparar (I-7)
        }
        $aten ??= self::atenciones();
        $raizPropia = self::raiz($aviso, $gestion);

        $ts = strtotime($desde);
        if ($ts === false) { return []; }
        $out = [];
        foreach (self::catalogo()['datos'] ?? [] as $c) {
            $otro = trim((string) ($c['aviso'] ?? ''));
            if ($otro === '' || $otro === $aviso) { continue; }
            if (trim((string) ($c['local'] ?? '')) !== $local) { continue; }
            if (self::equipoNormalizado($c['activo_fijo'] ?? '') !== $equipo) { continue; }

            $tsOtro = strtotime((string) ($c['fecha_creacion'] ?? ''));
            if ($tsOtro === false || $tsOtro > $ts) { continue; }   // solo hacia atrás
            $dias = (int) round(($ts - $tsOtro) / 86400);
            if ($dias > self::CONTINUIDAD_DIAS) { continue; }

            // Ya enlazado a la misma cadena: no se ofrece enlazar lo que ya está.
            $g = $gestion[$otro] ?? [];
            if (self::raiz($otro, $gestion) === $raizPropia
                && trim((string) ($g['continua_de'] ?? '')) !== '') { continue; }

            // La orden que salió de ese caso, mirando las dos fuentes: lo que
            // decidimos nosotros (`ot_cierre`) y lo que dicen los informes ya
            // leídos del correo (`atenciones.json`). Si no hay, se dice que no
            // hay: un caso sin orden también puede ser el origen del trabajo.
            $ot = trim((string) ($g['ot_cierre'] ?? ''));
            if ($ot === '' && !empty($aten[$otro]['ots'])) {
                $ot = trim((string) ($aten[$otro]['ots'][0]['ot'] ?? ''));
            }

            $out[] = [
                'aviso'       => $otro,
                'fecha'       => (string) ($c['fecha_creacion'] ?? ''),
                'dias'        => $dias,
                'pedido'      => (string) ($c['descripcion_trabajo'] ?? ''),
                'activo_fijo' => (string) ($c['activo_fijo'] ?? ''),
                'estado'      => (string) ($g['estado'] ?? 'NUEVO'),
                'ot'          => $ot !== '' ? $ot : null,
                'raiz'        => self::raiz($otro, $gestion),
            ];
        }
        usort($out, fn($a, $b) => $a['dias'] <=> $b['dias'] ?: strcmp($b['aviso'], $a['aviso']));
        return array_slice($out, 0, self::CONTINUIDAD_TOPE);
    }

    /**
     * Por qué NO se puede enlazar este caso con ese trabajo, o null si sí.
     *
     * Está separado de `enlazar()` —y recibe la gestión en vez de leerla— para
     * que la regla que evita los ciclos se pueda probar sin levantar MySQL.
     * Es la única restricción de esta tarea que el esquema no puede expresar,
     * así que es la que más falta hace poder comprobar.
     */
    public static function motivoRechazoEnlace(string $aviso, string $origen, array $gestion): ?string
    {
        $aviso  = trim($aviso);
        $origen = trim($origen);
        if ($aviso === '' || $origen === '') {
            return 'Falta el caso o el trabajo anterior.';
        }
        if ($aviso === $origen) {
            return 'Un caso no puede continuarse a sí mismo.';
        }
        if (trim((string) ($gestion[$aviso]['continua_de'] ?? '')) !== '') {
            return 'Este caso ya está enlazado al trabajo ' . $gestion[$aviso]['continua_de'] . '.';
        }
        if (self::raiz($origen, $gestion) === $aviso) {
            // El origen ya cuelga de este caso: enlazarlos al revés cerraría el
            // círculo y los dos quedarían esperándose. Se corta aquí y se dice
            // cuál es el orden bueno, que es lo único que puede arreglarlo.
            return 'Ese caso ya figura como continuación de este. Enlázalos al revés: '
                 . 'el enlace va del caso nuevo al trabajo que empezó primero.';
        }
        return null;
    }

    /**
     * Declara que un caso continúa un trabajo anterior.
     *
     * Escribe contra la RAIZ de la cadena, no contra el caso que se eligió:
     * así 10342924, 10343636 y 10349666 apuntan los tres a 10342524, un ciclo
     * es imposible por construcción y cerrar la cadena es un WHERE de un solo
     * nivel en cada emisión de orden.
     *
     * Si la cadena ya tiene una orden, el caso queda ATENDIDO con esa orden
     * como cierre: es el punto entero de la función —no se emite un PDF
     * duplicado por un aviso que SAP abrió dos veces—. Si no la tiene, el
     * enlace queda hecho y el caso sigue abierto: lo concluirá la orden que se
     * emita ahora (T2.25.3). Lo segundo NO es un fallo y se dice así en el
     * mensaje, porque «no hay orden todavía» es un dato, no un error (I-7).
     *
     * `avisos_sap.estatus_general` no se toca ni se consulta para decidir: el
     * estado real de un correctivo lo manda SAP (regla 5 de ESTADO.md §6).
     *
     * @return array{0:bool,1:string} ok y el mensaje que ve quien lo hizo
     */
    public static function enlazar(string $aviso, string $origen, ?string $zona,
                                   int $usuarioId, string $nota = ''): array
    {
        $aviso  = trim($aviso);
        $origen = trim($origen);
        $gestion = self::gestion();
        $motivo = self::motivoRechazoEnlace($aviso, $origen, $gestion);
        if ($motivo !== null) { return [false, $motivo]; }
        $raiz = self::raiz($origen, $gestion);

        self::asegurar($aviso, $zona);
        self::asegurar($raiz, $zona);

        // La orden que ya cubre el trabajo: la de la raíz, o la del caso que se
        // eligió si la raíz no alcanzó a tener una.
        $aten = self::atenciones();
        $ot = '';
        foreach ([$raiz, $origen] as $cual) {
            $ot = trim((string) ($gestion[$cual]['ot_cierre'] ?? ''));
            if ($ot === '' && !empty($aten[$cual]['ots'])) {
                $ot = trim((string) ($aten[$cual]['ots'][0]['ot'] ?? ''));
            }
            if ($ot !== '') { break; }
        }

        $antes = (string) ($gestion[$aviso]['estado'] ?? 'NUEVO');
        Db::ejecutar(
            "UPDATE casos_gestion
                SET continua_de   = ?,
                    continua_ot   = ?,
                    continua_por  = ?,
                    continua_en   = NOW(),
                    continua_nota = ?,
                    /* Con una orden que ya cubre el trabajo, el caso queda
                       ATENDIDO y con ella como cierre. Sin orden, el estado no
                       se toca: el caso sigue siendo trabajo abierto. */
                    ot_cierre     = IF(? <> '', COALESCE(ot_cierre, ?), ot_cierre),
                    atendido_en   = IF(? <> '', COALESCE(atendido_en, NOW()), atendido_en),
                    estado        = CASE
                        WHEN ? = '' THEN estado
                        WHEN estado IN ('RESUELTO','NO_COMPETE','EN_REVISION','ESPERA_REPUESTO') THEN estado
                        ELSE 'ATENDIDO' END
              WHERE aviso = ? AND continua_de IS NULL",
            [$raiz, $ot !== '' ? $ot : null, $usuarioId, mb_substr(trim($nota), 0, 300),
             $ot, $ot, $ot, $ot, $aviso]
        );

        $desp = Db::uno('SELECT estado, continua_de FROM casos_gestion WHERE aviso = ?', [$aviso]);
        if (trim((string) ($desp['continua_de'] ?? '')) !== $raiz) {
            // Otro lo enlazó entre medio: no se pisa lo que ya decidió alguien.
            return [false, 'Alguien enlazó este caso mientras tanto. Recarga la pantalla.'];
        }
        $despues = (string) ($desp['estado'] ?? $antes);

        Auth::bitacora('CASO_CONTINUA', 'caso', $aviso,
                       'continúa el trabajo del aviso ' . $raiz
                       . ($origen !== $raiz ? ' (elegido: ' . $origen . ')' : '')
                       . ($ot !== '' ? ', cubierto por la orden ' . $ot : ', que todavía no tiene orden'),
                       $antes, $despues,
                       ['continua_de' => $raiz, 'elegido' => $origen, 'ot' => $ot !== '' ? $ot : null,
                        'nota' => $nota]);

        if ($ot !== '') {
            return [true, 'Listo: este caso queda cerrado con la orden ' . $ot
                        . ', la del trabajo que empezaste en el aviso ' . $raiz . '. No hace falta emitir otra.'];
        }
        return [true, 'Enlazado con el aviso ' . $raiz . '. Ese trabajo todavía no tiene orden emitida: '
                    . 'la que emitas ahora cierra los dos casos.'];
    }

    /**
     * Deshace un enlace. Existe porque la alternativa es peor: sin esto, un
     * enlace equivocado solo se corrige entrando a la base a mano, y el caso
     * queda figurando atendido por una orden que no lo atendió.
     *
     * No devuelve `ot_cierre` a NULL si la orden la puso otra cosa: solo quita
     * la que entró POR el enlace (`continua_ot`), y únicamente si sigue siendo
     * la misma. Lo que escribió una emisión de verdad no se borra nunca.
     */
    public static function desenlazar(string $aviso, int $usuarioId, string $motivo = ''): array
    {
        $aviso = trim($aviso);
        $g = Db::uno('SELECT estado, continua_de, continua_ot, ot_cierre FROM casos_gestion WHERE aviso = ?', [$aviso]);
        if ($g === null || trim((string) ($g['continua_de'] ?? '')) === '') {
            return [false, 'Ese caso no está enlazado a ningún trabajo anterior.'];
        }
        $heredada = trim((string) ($g['continua_ot'] ?? ''));
        $antes = (string) ($g['estado'] ?? '');
        Db::ejecutar(
            "UPDATE casos_gestion
                SET continua_de = NULL, continua_ot = NULL, continua_por = NULL,
                    continua_en = NULL, continua_nota = NULL,
                    ot_cierre   = IF(? <> '' AND ot_cierre = ?, NULL, ot_cierre),
                    estado      = CASE WHEN ? <> '' AND ot_cierre = ? AND estado = 'ATENDIDO'
                                       THEN IF(asignado_a IS NULL, 'NUEVO', 'ASIGNADO')
                                       ELSE estado END
              WHERE aviso = ?",
            [$heredada, $heredada, $heredada, $heredada, $aviso]
        );
        $desp = Db::uno('SELECT estado FROM casos_gestion WHERE aviso = ?', [$aviso]);
        Auth::bitacora('CASO_DESCONTINUA', 'caso', $aviso,
                       'se deshizo el enlace con ' . $g['continua_de']
                       . ($motivo !== '' ? ': ' . $motivo : ''),
                       $antes, (string) ($desp['estado'] ?? $antes),
                       ['continua_de' => $g['continua_de'], 'ot' => $heredada !== '' ? $heredada : null]);
        return [true, 'Enlace deshecho: el caso vuelve a estar abierto.'];
    }
}
