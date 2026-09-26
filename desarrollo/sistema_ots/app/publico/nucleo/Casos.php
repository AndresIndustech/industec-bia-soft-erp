<?php
declare(strict_types=1);
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Vocabulario.php';   // los textos que devuelve (bitácora, enlaces de continuidad)

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
                           // Con las palabras del diccionario: «concluida» era la OT INDUSTEC
                           // de cierre y «sin concluir» la de evaluación (OT_CIERRE, OT_EVALUACION).
                           ($concluida ? Vocabulario::t('OT_CIERRE') : Vocabulario::t('OT_EVALUACION')) . ' ' . $idIndustec,
                           $estadoAntes, $estadoDesp,
                           ['ot' => $idIndustec, 'fuente' => 'OT INDUSTEC emitida desde la app', 'tecnico' => $tecnicoId]);
        } elseif ($concluida && $estadoAntes === 'ESPERA_REPUESTO') {
            Auth::bitacora('OT_CIERRE_CON_PENDIENTE', 'caso', $aviso,
                           Vocabulario::t('OT_CIERRE') . ' ' . $idIndustec . ' con la orden '
                           . Vocabulario::t('ESPERA_REPUESTO'),
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

    /* =====================================================================
       LA TARJETA POR ZONA (VOCABULARIO.md §8; decisiones D-A y D-B de
       Andrés, 24-sep-2026)

       Isabel trabaja con SAP/KFC en cuatro cifras por zona: ÓRDENES ABIERTAS,
       ÓRDENES A ESPERA DE INFORME TÉCNICO, EQUIPOS DESHABILITADOS y el TOTAL
       DE ÓRDENES ABIERTAS. Aquí vive el ÚNICO cálculo de esas cifras: lo usan
       el panel (tarjetas, total general, «Cómo va el buzón», «Lo que te toca
       ahora») y el buzón (`casos.php?grupo=`), para que la cifra y las filas
       del enlace salgan de la misma función. Es la lección de `sinAsignar()`:
       una cifra con dos cálculos terminó diciendo dos números.

       Todo lo de este bloque es determinista: `indiceInformes()`, `grupoOrden()`,
       `clasificar()` y `tarjetasPorZona()` no leen la base ni el disco, reciben
       lo leído. La lectura vive sola en `informesPorAviso()`. Así se prueba sin
       levantar MySQL (`pruebas/prueba_panel_zona.php`) y con datos sintéticos.
       ===================================================================== */

    /** Abierta para INDUSTEC: sigue en el TOTAL DE ÓRDENES ABIERTAS. ATENDIDO no
     *  (va al pie: «atendidas, por cerrar en SAP»); RESUELTO, NO_COMPETE y
     *  CERRADO_SIN_ATENCION tampoco. */
    public const ABIERTAS_INDUSTEC = ['NUEVO', 'ASIGNADO', 'EN_REVISION', 'ESPERA_REPUESTO'];

    /** Capturas que se numeraron pero no salieron (ni PDF ni correo): no son
     *  una OT INDUSTEC emitida, y la orden sigue a espera de informe técnico. */
    private const CAPTURA_NO_EMITIDA = ['NUMERADA', 'FALLIDA'];

    /** Días que lleva asignada una orden para contar en «asignadas hace 3+ días». */
    public const DIAS_ASIGNADA = 3;

    /** El aviso llega con y sin ceros a la izquierda según la fuente
     *  (`000010352936` en SAP, `10352936` en el nombre de la orden). */
    private static function claveAviso($a): string
    {
        return ltrim(trim((string) $a), '0');
    }

    /** Estado del equipo tal como lo escribe cada fuente → OPERATIVO,
     *  DESHABILITADO o null. Cualquier otro texto, o vacío, es «sin dato»:
     *  nunca se lee como Operativo (I-7). */
    private static function estadoEquipoDe($v): ?string
    {
        $v = strtoupper(trim((string) $v));
        return in_array($v, ['OPERATIVO', 'DESHABILITADO'], true) ? $v : null;
    }

    /**
     * Qué avisos tienen OT INDUSTEC emitida, qué dice cada fuente del equipo y
     * QUÉ FUENTES RESPONDIERON. Lee atenciones.json y la base, y le pasa lo
     * leído a `indiceInformes()`. Una fuente que no responde llega como null,
     * no como lista vacía: «no sé» y «no hay» se pintan distinto (I-7).
     *
     * Es la variante de `documentos()` sin `Emision::existePdf()`: la tarjeta
     * clasifica todas las órdenes del buzón en cada carga y no puede leer el
     * disco por cada una.
     */
    public static function informesPorAviso(array $gestion): array
    {
        require_once __DIR__ . '/Pendientes.php';

        $aten = self::atenciones();
        // `leerJson()` devuelve [] si el archivo no está o no se entiende: solo
        // si trae su clave 'atenciones' se sabe que la fuente respondió.
        $atenOk   = isset(self::$atenciones['atenciones']);
        $generado = $atenOk ? (string) (self::$atenciones['generado'] ?? '') : '';

        $capturadas = null;
        try {
            // Solo el arreglo de equipos de la carga: la carga entera trae el
            // formulario completo y aquí no hace falta.
            $capturadas = Db::todos(
                "SELECT aviso, estado, emitida_en, JSON_EXTRACT(carga, '$.equipos') AS equipos
                   FROM ot_capturadas
                  WHERE aviso IS NOT NULL AND aviso <> ''
                    AND estado IN ('EMITIDA','ENVIADA','NUMERADA','FALLIDA','PROCESADA')");
        } catch (Throwable $e) { $capturadas = null; }      // sin la 008 no responde

        $archivo = null;
        try {
            $archivo = Db::todos("SELECT aviso, fecha_atencion FROM ot_archivo
                                   WHERE aviso IS NOT NULL AND aviso <> ''");
        } catch (Throwable $e) { $archivo = null; }         // sin la 009 no responde

        $pendientes = null;
        if (Pendientes::disponible()) {
            try {
                /* El «vencido» lo calcula MySQL con la MISMA condición que
                   `Pendientes::cumplimiento48()` y el grupo `vencidos` de
                   `Pendientes::lista()`, con su propio NOW(): así la sublínea de
                   la tarjeta y la lista del enlace no pueden discrepar. La fecha
                   de la evidencia es la del reloj (`plazo_desde` si se reinició):
                   es la última vez que alguien declaró el equipo deshabilitado. */
                $pendientes = Db::todos(
                    "SELECT p.aviso, COALESCE(p.plazo_desde, p.abierto_en) AS desde,
                            (p.via = 'SIN_VEREDICTO'
                             AND COALESCE(p.plazo_desde, p.abierto_en) < DATE_SUB(NOW(), INTERVAL 48 HOUR)) AS vencido
                       FROM pendientes p
                      WHERE p.deshabilitado = 1
                        AND p.estado IN ('" . implode("','", Pendientes::ABIERTOS) . "')");
            } catch (Throwable $e) { $pendientes = null; }
        }

        return self::indiceInformes($gestion, $atenOk ? $aten : null, $capturadas, $archivo,
                                    $pendientes, $generado !== '' ? $generado : null);
    }

    /**
     * El índice por aviso, a partir de lo que ya se leyó. Determinista.
     *
     * @param array|null $aten       atenciones.json → 'atenciones'; null = no respondió
     * @param array|null $capturadas filas {aviso, estado, emitida_en, equipos(JSON)}; null = sin la 008
     * @param array|null $archivo    filas {aviso, fecha_atencion}; null = sin la 009
     * @param array|null $pendientes filas {aviso, desde, vencido} de solicitudes en trámite
     *                               con el equipo deshabilitado; null = sin la 007
     * @return array{avisos:array, cadenas:array, fuentes:array, ot_disponible:bool,
     *               equipo_disponible:bool, generado:?string}
     */
    public static function indiceInformes(array $gestion, ?array $aten, ?array $capturadas,
                                          ?array $archivo, ?array $pendientes, ?string $generado = null): array
    {
        $idx = [];
        $nodo = static function (string $k) use (&$idx): void {
            $idx[$k] ??= ['emitida' => false, 'no_emitida' => false, 'equipo' => [], 'vencida' => false];
        };

        // La OT de cierre que dejó la app o la reconciliación: es de la propia
        // gestión, que siempre responde.
        foreach ($gestion as $aviso => $g) {
            if (trim((string) ($g['ot_cierre'] ?? '')) !== '') {
                $k = self::claveAviso($aviso);
                if ($k === '') { continue; }
                $nodo($k);
                $idx[$k]['emitida'] = true;
            }
        }
        // Lo que llegó por correo: cualquier OT, con cualquier estado_ot.
        foreach ($aten ?? [] as $aviso => $a) {
            $k = self::claveAviso($aviso);
            if ($k === '' || empty($a['ots'])) { continue; }
            $nodo($k);
            $idx[$k]['emitida'] = true;
            foreach ($a['ots'] as $o) {
                $est = self::estadoEquipoDe($o['estado_equipo'] ?? null);
                if ($est !== null) { $idx[$k]['equipo'][] = [$est, (string) ($o['fecha'] ?? '')]; }
            }
        }
        // Lo que emitió la app. NUMERADA o FALLIDA no salió: no cuenta como
        // emitida, pero deja la marca «con OT INDUSTEC no emitida».
        foreach ($capturadas ?? [] as $r) {
            $k = self::claveAviso($r['aviso'] ?? '');
            if ($k === '') { continue; }
            $nodo($k);
            $est = strtoupper((string) ($r['estado'] ?? ''));
            if (in_array($est, self::CAPTURA_NO_EMITIDA, true)) {
                $idx[$k]['no_emitida'] = true;
                continue;
            }
            if (($r['emitida_en'] ?? null) === null || $r['emitida_en'] === '') { continue; }
            $idx[$k]['emitida'] = true;
            // Deshabilitado si algún equipo lo está; Operativo solo si TODOS lo
            // están; si alguno viene sin estado y ninguno deshabilitado, la OT
            // no dice nada del equipo (un null del formulario es «sin dato»).
            $eqs = is_array($r['equipos'] ?? null) ? $r['equipos'] : json_decode((string) ($r['equipos'] ?? ''), true);
            if (is_array($eqs) && $eqs !== []) {
                $vals = array_map(static fn($e) => self::estadoEquipoDe(is_array($e) ? ($e['estado'] ?? null) : null), $eqs);
                if (in_array('DESHABILITADO', $vals, true)) {
                    $idx[$k]['equipo'][] = ['DESHABILITADO', (string) $r['emitida_en']];
                } elseif (!in_array(null, $vals, true)) {
                    $idx[$k]['equipo'][] = ['OPERATIVO', (string) $r['emitida_en']];
                }
            }
        }
        foreach ($archivo ?? [] as $r) {
            $k = self::claveAviso($r['aviso'] ?? '');
            if ($k === '') { continue; }
            $nodo($k);
            $idx[$k]['emitida'] = true;
        }
        // La solicitud en trámite con el equipo deshabilitado. Una con
        // deshabilitado = 0 no llega aquí: no es evidencia de Operativo.
        foreach ($pendientes ?? [] as $r) {
            $k = self::claveAviso($r['aviso'] ?? '');
            if ($k === '') { continue; }
            $nodo($k);
            $idx[$k]['equipo'][] = ['DESHABILITADO', (string) ($r['desde'] ?? '')];
            if (!empty($r['vencido'])) { $idx[$k]['vencida'] = true; }
        }

        /* Las cadenas de continuidad (la 012): un trabajo con varios avisos se
           cuenta UNA vez, y para saber si «tiene OT» o qué dice del equipo se
           miran todos sus avisos. Se arman aquí una sola vez; `cadena()` por
           orden recorrería la gestión entera 900 veces en cada carga. */
        $grupos = [];
        foreach ($gestion as $aviso => $g) {
            if (trim((string) ($g['continua_de'] ?? '')) === '') { continue; }
            $raiz = self::raiz((string) $aviso, $gestion);
            $grupos[$raiz][self::claveAviso($aviso)] = true;
            $grupos[$raiz][self::claveAviso($raiz)] = true;
        }
        $cadenas = [];
        foreach ($grupos as $raiz => $miembros) {
            $lista = array_map('strval', array_keys($miembros));
            foreach ($lista as $m) { $cadenas[$m] = ['raiz' => self::claveAviso($raiz), 'avisos' => $lista]; }
        }

        $fuentes = ['atenciones' => $aten !== null, 'capturadas' => $capturadas !== null,
                    'archivo' => $archivo !== null, 'pendientes' => $pendientes !== null];
        return [
            'avisos'  => $idx,
            'cadenas' => $cadenas,
            'fuentes' => $fuentes,
            // Filas 1 y 2: sin cualquiera de las tres fuentes de OT, una orden
            // con su OT emitida se contaría «a espera de informe técnico».
            'ot_disponible' => $fuentes['atenciones'] && $fuentes['capturadas'] && $fuentes['archivo'],
            // Fila 3: el estado del equipo sale de las OT (correo y app) y de la
            // solicitud en trámite; sin una de ellas, «la evidencia más
            // reciente» puede ser otra.
            'equipo_disponible' => $fuentes['atenciones'] && $fuentes['capturadas'] && $fuentes['pendientes'],
            'generado' => $generado,
        ];
    }

    /** Los avisos (normalizados) de la cadena de este aviso; él solo si no tiene cadena. */
    private static function avisosDeCadena(string $aviso, array $informes): array
    {
        $k = self::claveAviso($aviso);
        return $informes['cadenas'][$k]['avisos'] ?? [$k];
    }

    /** ¿La cadena de esta orden tiene al menos una OT INDUSTEC emitida? */
    public static function tieneOT(string $aviso, array $informes): bool
    {
        foreach (self::avisosDeCadena($aviso, $informes) as $k) {
            if (!empty($informes['avisos'][$k]['emitida'])) { return true; }
        }
        return false;
    }

    /**
     * El grupo de la orden en la tarjeta. Precedencia exacta (VOCABULARIO.md §8.2):
     *
     *   estado fuera de ABIERTAS_INDUSTEC  -> null (fuera del total)
     *   ESPERA_REPUESTO                    -> 'ABIERTA'  (D-A: siempre; hubo visita
     *                                          o diagnóstico aunque no haya OT,
     *                                          p. ej. «el equipo no quedó operativo»
     *                                          desde la bandeja del técnico)
     *   con ≥1 OT INDUSTEC emitida         -> 'ABIERTA'
     *   sin ninguna                        -> 'ESPERA_INFORME'
     *
     * Una orden con OT de cierre cuyo estado aún no pasó a ATENDIDO se cuenta
     * por su estado: sigue en el total, como abierta, hasta que la reconciliación
     * la mueva. Quien llama mira `$informes['ot_disponible']` antes de pintar
     * las filas 1 y 2 (I-7).
     */
    public static function grupoOrden(array $caso, ?array $g, array $informes): ?string
    {
        $estado = strtoupper(trim((string) ($g['estado'] ?? 'NUEVO'))) ?: 'NUEVO';
        if (!in_array($estado, self::ABIERTAS_INDUSTEC, true)) { return null; }
        if ($estado === 'ESPERA_REPUESTO') { return 'ABIERTA'; }
        return self::tieneOT((string) ($caso['aviso'] ?? ''), $informes) ? 'ABIERTA' : 'ESPERA_INFORME';
    }

    /**
     * Estado del equipo de la orden por la evidencia MÁS RECIENTE de su cadena:
     * 'DESHABILITADO', 'OPERATIVO' o null (sin dato). En empate gana
     * Deshabilitado, para no esconder la alarma. Un informe posterior que dice
     * Operativo saca la orden de EQUIPOS DESHABILITADOS aunque la solicitud
     * siga abierta (su `deshabilitado` no baja nunca: GREATEST).
     */
    public static function estadoEquipo(string $aviso, array $informes): ?string
    {
        $mejor = null;
        foreach (self::avisosDeCadena($aviso, $informes) as $k) {
            foreach ($informes['avisos'][$k]['equipo'] ?? [] as [$est, $fecha]) {
                if ($mejor === null) { $mejor = [$est, $fecha]; continue; }
                $c = self::compararFecha($fecha, $mejor[1]);
                if ($c > 0 || ($c === 0 && $est === 'DESHABILITADO')) { $mejor = [$est, $fecha]; }
            }
        }
        return $mejor[0] ?? null;
    }

    /** Compara dos fechas de fuentes distintas. El correo trae solo el día y la
     *  app la hora: si una de las dos es solo día, se comparan por día (y el
     *  empate lo resuelve quien llama), en vez de dar por anterior al día sin hora. */
    private static function compararFecha(string $a, string $b): int
    {
        $a = trim($a); $b = trim($b);
        if (strlen($a) <= 10 || strlen($b) <= 10) {
            return substr($a, 0, 10) <=> substr($b, 0, 10);
        }
        return substr($a, 0, 19) <=> substr($b, 0, 19);
    }

    /** ¿Alguna solicitud de la cadena está vencida (más de 48 h sin validar)? */
    private static function vencida48(string $aviso, array $informes): bool
    {
        foreach (self::avisosDeCadena($aviso, $informes) as $k) {
            if (!empty($informes['avisos'][$k]['vencida'])) { return true; }
        }
        return false;
    }

    /** ¿Alguna captura de la cadena quedó NUMERADA o FALLIDA? */
    private static function conOtNoEmitida(string $aviso, array $informes): bool
    {
        foreach (self::avisosDeCadena($aviso, $informes) as $k) {
            if (!empty($informes['avisos'][$k]['no_emitida'])) { return true; }
        }
        return false;
    }

    /**
     * Clasifica cada orden: su grupo y si entra en el total. Cada cadena de
     * continuidad cuenta UNA vez: la representa su orden más reciente que siga
     * abierta; las demás de la cadena quedan fuera del total (grupo null).
     *
     * @return array<string,array{grupo:?string,en_total:bool}> por aviso tal como viene
     */
    public static function clasificar(array $casos, array $gestion, array $informes): array
    {
        $out = [];
        $rep = [];      // raíz → [aviso, fecha] de la orden que representa la cadena
        foreach ($casos as $c) {
            $aviso = (string) ($c['aviso'] ?? '');
            $grupo = self::grupoOrden($c, $gestion[$aviso] ?? null, $informes);
            $out[$aviso] = ['grupo' => $grupo, 'en_total' => $grupo !== null];
            if ($grupo === null) { continue; }
            $raiz = $informes['cadenas'][self::claveAviso($aviso)]['raiz'] ?? null;
            if ($raiz === null) { continue; }
            $clave = [(string) ($c['fecha_creacion'] ?? ''), self::claveAviso($aviso)];
            if (!isset($rep[$raiz]) || $clave > $rep[$raiz][1]) { $rep[$raiz] = [$aviso, $clave]; }
        }
        foreach ($casos as $c) {
            $aviso = (string) ($c['aviso'] ?? '');
            if (!$out[$aviso]['en_total']) { continue; }
            $raiz = $informes['cadenas'][self::claveAviso($aviso)]['raiz'] ?? null;
            if ($raiz !== null && $rep[$raiz][0] !== $aviso) {
                $out[$aviso] = ['grupo' => null, 'en_total' => false];
            }
        }
        return $out;
    }

    /**
     * ¿La orden cae en el filtro `?grupo=` del buzón? Una sola regla para el
     * enlace de cada cifra de la tarjeta: abiertas | espera_informe | total |
     * deshabilitados. `null` = no se puede saber (falta una fuente): el buzón
     * no lista nada y lo dice (I-7).
     */
    public static function enGrupo(string $grupo, array $caso, array $clasif, array $informes): ?bool
    {
        $k = $clasif[(string) ($caso['aviso'] ?? '')] ?? ['grupo' => null, 'en_total' => false];
        switch ($grupo) {
            case 'total':
                return $k['en_total'];
            case 'abiertas':
                return $informes['ot_disponible'] ? $k['grupo'] === 'ABIERTA' : null;
            case 'espera_informe':
                return $informes['ot_disponible'] ? $k['grupo'] === 'ESPERA_INFORME' : null;
            case 'deshabilitados':
                return $informes['equipo_disponible']
                    ? $k['en_total'] && self::estadoEquipo((string) ($caso['aviso'] ?? ''), $informes) === 'DESHABILITADO'
                    : null;
        }
        return null;
    }

    /**
     * Qué tarjetas se dibujan. La administración ve las tres zonas siempre, y
     * OTRA ZONA (o cualquier otro código) solo si tiene algo (ASG-21); «sin
     * zona» no lleva tarjeta sino su línea aparte. El jefe de zona ve solo la
     * suya: `enAlcance()` ya le recortó las órdenes, así que su tarjeta cuenta
     * exactamente lo que ve en el buzón.
     *
     * @return string[]
     */
    public static function zonasVisibles(array $tz, ?string $zonaAlc): array
    {
        if ($zonaAlc !== null) { return [$zonaAlc]; }
        $out = ['UIO', 'LARB', 'CNLJ'];
        foreach ($tz['zonas'] as $zk => $t) {
            $zk = (string) $zk;
            if ($zk === '' || in_array($zk, $out, true)) { continue; }
            if ($t['total'] + $t['atendidas'] + $t['sin_regularizar'] + $t['en_revision'] > 0) { $out[] = $zk; }
        }
        return $out;
    }

    /** Días enteros desde una fecha hasta hoy, como `Ui::dias()`, con el «hoy» explícito para poder probarlo. */
    private static function diasDesde(?string $fecha, string $hoy): ?int
    {
        $f = substr(trim((string) $fecha), 0, 10);
        if ($f === '' || strtotime($f) === false) { return null; }
        return (int) floor((strtotime($hoy) - strtotime($f)) / 86400);
    }

    /**
     * Las cifras de la tarjeta por zona, y los totales que dependen de ellas.
     *
     * El universo son los `$casos` que ya pasaron por `enAlcance()` (el
     * catálogo de 90 días del buzón): el mismo que lista `casos.php`, para que
     * cada cifra sea igual a las filas de su enlace. No se suma
     * `fueraDeCatalogo()`: no distingue una orden que salió de la ventana de
     * una que KFC anuló, y el enlace no la mostraría.
     *
     * Una cifra que no se puede calcular es null, nunca 0 (I-7): filas 1 y 2 sin
     * `ot_disponible`; fila 3 y sus sublíneas sin `equipo_disponible`.
     *
     * @param string[] $zonasFijas zonas que llevan tarjeta aunque no tengan nada (la del jefe de zona)
     * @return array{zonas:array<string,array>, tres_zonas:array, total:int, ot_disponible:bool,
     *               equipo_disponible:bool, sin_tecnico:int, asignadas_3d:?int,
     *               asignadas_3d_por_estado:int, espera_repuesto:int, en_revision:int,
     *               atendidas:int, sin_regularizar:int}
     */
    public static function tarjetasPorZona(array $casos, array $gestion, array $informes,
                                           ?string $hoy = null, array $zonasFijas = []): array
    {
        $hoy ??= date('Y-m-d');
        $otOk = (bool) $informes['ot_disponible'];
        $eqOk = (bool) $informes['equipo_disponible'];
        $vacia = static fn(): array => [
            'total' => 0, 'abiertas' => 0, 'abiertas_espera_repuesto' => 0, 'abiertas_sin_asignar' => 0,
            'espera_informe' => 0, 'sin_asignar' => 0, 'asignadas_3d' => 0,
            'otro_por_decidir' => 0, 'ot_no_emitida' => 0,
            'deshabilitados' => 0, 'vencidas' => 0, 'operativos' => 0, 'sin_dato' => 0,
            'en_revision' => 0, 'atendidas' => 0, 'sin_regularizar' => 0,
            'sin_tecnico' => 0, 'asignadas_3d_por_estado' => 0, 'espera_repuesto' => 0,
        ];
        // Las tres zonas tienen tarjeta aunque estén en cero (y la del jefe de
        // zona, que llega en `$zonasFijas`): una zona sin órdenes se ve en 0,
        // no desaparece.
        $zonas = [];
        foreach (array_merge(['UIO', 'LARB', 'CNLJ'], $zonasFijas) as $zf) { $zonas[(string) $zf] ??= $vacia(); }
        $clasif = self::clasificar($casos, $gestion, $informes);

        foreach ($casos as $c) {
            $aviso  = (string) ($c['aviso'] ?? '');
            $g      = $gestion[$aviso] ?? null;
            $estado = strtoupper(trim((string) ($g['estado'] ?? 'NUEVO'))) ?: 'NUEVO';
            $z      = (string) ($c['zona'] ?? '');
            $zonas[$z] ??= $vacia();
            $t = &$zonas[$z];

            // El pie: la tarea diaria de Isabel. Se cuenta por estado, orden por
            // orden, igual que la lista de su enlace (`casos.php?est=`).
            if ($estado === 'ATENDIDO')    { $t['atendidas']++; }
            if ($estado === 'EN_REVISION') { $t['en_revision']++; }
            if ($estado === 'CERRADO_SIN_ATENCION' && empty($g['regularizado_en'])) { $t['sin_regularizar']++; }

            if (!$clasif[$aviso]['en_total']) { unset($t); continue; }
            $grupo = $clasif[$aviso]['grupo'];
            $t['total']++;
            if ($estado === 'ESPERA_REPUESTO') { $t['espera_repuesto']++; }

            // Sin técnico: no depende de ninguna fuente de OT, por eso la tarea
            // «N órdenes sin asignar» se puede dar aunque falte atenciones.json.
            $sinTecnico = in_array($estado, ['NUEVO', 'EN_REVISION'], true) && empty($g['asignado_a']);
            if ($sinTecnico) { $t['sin_tecnico']++; }
            $dias = $estado === 'ASIGNADO' ? self::diasDesde($g['asignado_en'] ?? null, $hoy) : null;
            $viejaAsig = $dias !== null && $dias >= self::DIAS_ASIGNADA;
            if ($viejaAsig) { $t['asignadas_3d_por_estado']++; }

            // Filas 1 y 2 y sus sublíneas, siempre SOBRE el grupo: una sublínea
            // no puede pasar de su fila.
            if ($grupo === 'ABIERTA') {
                $t['abiertas']++;
                if ($estado === 'ESPERA_REPUESTO') { $t['abiertas_espera_repuesto']++; }
                // Sin técnico pero con OT: la firma de la OT no cruzó con nadie del padrón.
                if ($sinTecnico) { $t['abiertas_sin_asignar']++; }
            } else {
                $t['espera_informe']++;
                if ($sinTecnico) { $t['sin_asignar']++; }
                if ($viejaAsig)  { $t['asignadas_3d']++; }
                if (self::otroTrabajoPorDecidir($c, $g))          { $t['otro_por_decidir']++; }
                if (self::conOtNoEmitida($aviso, $informes))      { $t['ot_no_emitida']++; }
            }

            // Fila 3: una por orden, por la evidencia más reciente de su equipo.
            $eq = self::estadoEquipo($aviso, $informes);
            if ($eq === 'DESHABILITADO') {
                $t['deshabilitados']++;
                if (self::vencida48($aviso, $informes)) { $t['vencidas']++; }
            } elseif ($eq === 'OPERATIVO') {
                $t['operativos']++;
            } else {
                $t['sin_dato']++;
            }
            unset($t);
        }

        foreach ($zonas as &$t) {
            if (!$otOk) {
                foreach (['abiertas', 'abiertas_espera_repuesto', 'abiertas_sin_asignar', 'espera_informe',
                          'sin_asignar', 'asignadas_3d', 'otro_por_decidir', 'ot_no_emitida'] as $k) { $t[$k] = null; }
            }
            if (!$eqOk) {
                foreach (['deshabilitados', 'vencidas', 'operativos', 'sin_dato'] as $k) { $t[$k] = null; }
            }
        }
        unset($t);

        $sumar = static function (array $zs, string $k): ?int {
            $s = 0;
            foreach ($zs as $t) { if ($t[$k] === null) { return null; } $s += $t[$k]; }
            return $s;
        };
        $tres = array_intersect_key($zonas, ['UIO' => 1, 'LARB' => 1, 'CNLJ' => 1]);
        return [
            'zonas' => $zonas,
            // La fila 5 del RESUMEN de Isabel: solo las tres zonas. OTRA y
            // «sin zona» van aparte y no entran.
            'tres_zonas' => ['total' => $sumar($tres, 'total'),
                             'deshabilitados' => $sumar($tres, 'deshabilitados'),
                             'operativos' => $sumar($tres, 'operativos')],
            // El cuadro de «Cómo va el buzón»: todas, incluidas OTRA y sin zona.
            'total' => $sumar($zonas, 'total'),
            'ot_disponible' => $otOk,
            'equipo_disponible' => $eqOk,
            'sin_tecnico' => $sumar($zonas, 'sin_tecnico'),
            'asignadas_3d' => $sumar($zonas, 'asignadas_3d'),
            'asignadas_3d_por_estado' => $sumar($zonas, 'asignadas_3d_por_estado'),
            'espera_repuesto' => $sumar($zonas, 'espera_repuesto'),
            'en_revision' => $sumar($zonas, 'en_revision'),
            'atendidas' => $sumar($zonas, 'atendidas'),
            'sin_regularizar' => $sumar($zonas, 'sin_regularizar'),
        ];
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
            return 'Falta la orden o el trabajo anterior.';
        }
        if ($aviso === $origen) {
            return 'Una orden no puede continuarse a sí misma.';
        }
        if (trim((string) ($gestion[$aviso]['continua_de'] ?? '')) !== '') {
            return 'Esta orden ya continúa el trabajo del aviso ' . $gestion[$aviso]['continua_de'] . '.';
        }
        if (self::raiz($origen, $gestion) === $aviso) {
            // El origen ya cuelga de este caso: enlazarlos al revés cerraría el
            // círculo y los dos quedarían esperándose. Se corta aquí y se dice
            // cuál es el orden bueno, que es lo único que puede arreglarlo.
            return 'Esa orden ya figura como continuación de esta. Enlázalas al revés: '
                 . 'el enlace va de la orden nueva al trabajo que empezó primero.';
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
                    /* Con una OT INDUSTEC que ya cubre el trabajo, la orden
                       queda ATENDIDO y con ella como cierre. Sin OT, el estado
                       no se toca: la orden sigue en el total de las que faltan. */
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
            return [false, 'Alguien enlazó esta orden mientras tanto. Recarga la pantalla.'];
        }
        $despues = (string) ($desp['estado'] ?? $antes);

        Auth::bitacora('CASO_CONTINUA', 'caso', $aviso,
                       'continúa el trabajo del aviso ' . $raiz
                       . ($origen !== $raiz ? ' (elegido: ' . $origen . ')' : '')
                       . ($ot !== '' ? ', cubierto por la OT INDUSTEC ' . $ot : ', que todavía no tiene OT INDUSTEC'),
                       $antes, $despues,
                       ['continua_de' => $raiz, 'elegido' => $origen, 'ot' => $ot !== '' ? $ot : null,
                        'nota' => $nota]);

        if ($ot !== '') {
            // Queda ATENDIDO, no cerrada: falta que la administración la cierre
            // en SAP («queda cerrado» era el error que corrigió el diccionario).
            return [true, 'Listo: esta orden queda ' . Vocabulario::t('ATENDIDA') . ', con la OT INDUSTEC ' . $ot
                        . ', la del trabajo que empezaste en el aviso ' . $raiz . '. No hace falta emitir otra.'];
        }
        return [true, 'Esta orden ' . Vocabulario::t('CONTINUIDAD') . ' del aviso ' . $raiz
                    . '. Ese trabajo todavía no tiene OT INDUSTEC emitida: '
                    . 'la ' . Vocabulario::t('OT_CIERRE') . ' que emitas ahora atiende las dos órdenes.'];
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
            return [false, 'Esa orden no continúa ningún trabajo anterior.'];
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
        return [true, 'Enlace deshecho: la orden vuelve a contar en el '
                    . Vocabulario::t('TOTAL_ABIERTAS') . '.'];
    }
}
