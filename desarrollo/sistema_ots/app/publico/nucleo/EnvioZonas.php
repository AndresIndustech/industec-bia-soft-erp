<?php
declare(strict_types=1);
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';

/**
 * EnvioZonas.php — El interruptor del envío real de las OT INDUSTEC, zona por
 * zona (T2.29, pedido de Andrés del 2026-09-29; migración 023).
 *
 * POR QUÉ EXISTE. Hasta el 29-sep el modo era uno solo para todo el sitio y
 * vivía en config.php (`emision_modo`), el único archivo que no se toca en el
 * servidor. Sin esa clave todo era PRUEBA: serie 9000 y correo RETENIDO. Los
 * técnicos veían «del piloto: NO llega a Grupo KFC» y emitían cada OT dos veces,
 * una aquí y otra en el formulario viejo. Ahora el modo es por zona, vive en
 * `emision_zonas` y lo cambia un superadministrador desde Correos, con el
 * contador sembrado en el mismo paso.
 *
 * LA NUMERACIÓN SIGUE LA DEL FORMULARIO VIEJO (decisión de Andrés, D-2). Al
 * activar una zona se lee su contador —`counter_{ZONA}.txt` de cada módulo del
 * sistema viejo, SOLO LECTURA (regla 9)— y la serie real queda en ese número +
 * MARGEN. Mientras el formulario viejo siga existiendo, cada reserva vuelve a
 * mirarlo: si alguien lo usó, la app numera por encima (Emision::reservar()) y
 * Correos lo dice. Lo que la app no puede impedir es lo contrario —que el viejo
 * repita un número que ya dio la app—, porque para eso habría que escribir en
 * el sistema viejo. Por eso el aviso.
 *
 * OTRA SE QUEDA EN PILOTO hasta que Andrés decida: su contador del viejo
 * (`ot_normal_otros`) es uno solo para correctivo y preventivo, y sembrar las
 * dos series desde el mismo número las haría repetirse.
 *
 * Las funciones puras (`siguienteSembrado`, `vigilar`, `resolverModo`) no tocan
 * la base: las prueba `pruebas/prueba_envio_real.php` sin MySQL.
 */
require_once __DIR__ . '/Emision.php';   // SERIE_PRUEBA, seriePrueba() y mayorNumeroReal(); el ciclo con Emision.php es seguro (require_once)

final class EnvioZonas
{
    public const ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];
    /** Las tres zonas del contrato: «el sitio en producción» es que estas tres lo estén. */
    public const ZONAS_CONTRATO = ['UIO', 'LARB', 'CNLJ'];
    /** Números que se dejan libres entre el formulario viejo y el primero de la app (D-2). */
    public const MARGEN = 5;
    /**
     * Cuánto más arriba del contador viejo (+ MARGEN) se acepta sembrar sin que
     * lo mire una persona: una zona que se reactiva lleva encima los números
     * que la app ya dio, pero un salto mayor que este es otra cosa (así se
     * habría visto el 8001 de la revisión del 29-sep).
     */
    public const SALTO_MAXIMO = 500;
    /** Las zonas que se pueden activar hoy. OTRA espera la decisión de Andrés (ver arriba). */
    public const ACTIVABLES = ['UIO', 'LARB', 'CNLJ'];

    /**
     * Dónde lleva cada serie su contador el formulario viejo, relativo a
     * `ot/produccion/` del sitio viejo. El mismo mapa que
     * `agentes/scripts/t2_14_sembrar_correlativos.py`: si cambia uno, cambia el
     * otro. Verificado por SSH el 2026-09-29 (UIO 1945 · LARB 2320 · CNLJ 2645;
     * preventivo 225 · 353 · 241).
     */
    public const CONTADORES_VIEJO = [
        'CORRECTIVO:UIO'  => 'ot_normal_v3/uio/contadores/counter_UIO.txt',
        'CORRECTIVO:LARB' => 'ot_normal_v3/larb/contadores/counter_LARB.txt',
        'CORRECTIVO:CNLJ' => 'ot_normal_v3/cnlj/contadores/counter_CNLJ.txt',
        'PREVENTIVO:UIO'  => 'ot_mantenimiento/contadores/counter_UIO.txt',
        'PREVENTIVO:LARB' => 'ot_mantenimiento/contadores/counter_LARB.txt',
        'PREVENTIVO:CNLJ' => 'ot_mantenimiento/contadores/counter_CNLJ.txt',
    ];

    /** @var array<string,array{modo:string,desde:?string}>|null */
    private static ?array $cache = null;

    /** Para las pruebas y para leer el estado recién cambiado en la misma petición. */
    public static function olvidar(): void
    {
        self::$cache = null;
    }

    /**
     * El modo de cada zona tal como está en la base. Sin la 023, las cuatro en
     * PRUEBA: que falte la tabla nunca puede encender el envío.
     *
     * @return array<string,array{modo:string,desde:?string}>
     */
    public static function estado(): array
    {
        if (self::$cache !== null) { return self::$cache; }
        $out = [];
        foreach (self::ZONAS as $z) { $out[$z] = ['modo' => 'PRUEBA', 'desde' => null]; }
        try {
            foreach (Db::todos('SELECT zona, modo, desde FROM emision_zonas') as $f) {
                if (isset($out[$f['zona']])) {
                    $out[$f['zona']] = ['modo' => (string) $f['modo'], 'desde' => $f['desde'] !== null ? (string) $f['desde'] : null];
                }
            }
        } catch (Throwable $e) {
            // sin la 023: todo PRUEBA, como antes del 29-sep
        }
        return self::$cache = $out;
    }

    /**
     * El modo que rige, con la misma regla para todos: config.php manda si trae
     * `emision_modo` (PRUEBA es el freno de emergencia de todo el sitio;
     * PRODUCCION, el sitio definitivo tras el corte); si no, la zona; sin zona,
     * PRODUCCION solo si las tres zonas del contrato lo están. Pura.
     *
     * @param array<string,array{modo:string,desde:?string}> $estado
     */
    public static function resolverModo(?string $modoConfig, array $estado, ?string $zona): string
    {
        if ($modoConfig === 'PRUEBA' || $modoConfig === 'PRODUCCION') { return $modoConfig; }
        if ($zona !== null && $zona !== '') {
            return ($estado[$zona]['modo'] ?? 'PRUEBA') === 'PRODUCCION' ? 'PRODUCCION' : 'PRUEBA';
        }
        foreach (self::ZONAS_CONTRATO as $z) {
            if (($estado[$z]['modo'] ?? 'PRUEBA') !== 'PRODUCCION') { return 'PRUEBA'; }
        }
        return 'PRODUCCION';
    }

    /**
     * ¿Con qué modo se emite ESTA OT INDUSTEC? Pura. Tres respuestas:
     *
     *   ENSAYO      una cuenta de prueba (su usuario lleva «_prueba»: las cinco
     *               del arnés). Se comporta como producción —atiende la orden,
     *               resuelve el repuesto, deja la OT de cierre— para que las
     *               baterías prueben el camino real, pero numera en su propia
     *               serie (8000) y su correo queda RETENIDO: nunca le escribe a
     *               Grupo KFC (D-6). Hasta el 29-sep emitían como piloto y por
     *               eso verificar_ciclo.py y verificar_continuidad.py fallaban
     *               10 comprobaciones (error nº 53): el piloto no cierra nada.
     *   PRUEBA      el piloto: la zona no tiene el envío real activo, o el
     *               técnico LLENÓ la OT viendo la franja del piloto (se quedó en
     *               el celular sin señal y llega después de activar): cuando la
     *               llenó, la app le decía que no llegaría a KFC y que la
     *               emitiera también por el formulario de siempre. Mandarla ahora
     *               le daría a KFC el trabajo dos veces.
     *   PRODUCCION  el resto: número real, y el correo sale.
     *
     * Qué vio el técnico lo dice la propia OT (`modo_visto`, app.js desde el
     * 29-sep). Una app anterior no lo manda: entonces se usa la hora en que se
     * llenó contra la de activación, con el reloj del teléfono. `modo_visto`
     * solo puede bajar a piloto: nunca vuelve real una OT de una zona en piloto.
     */
    public static function modoDeCaptura(string $modoZona, ?string $desde, ?string $capturadaEn, ?string $usuario,
                                         ?string $modoVisto = null): string
    {
        if ($usuario !== null && str_contains($usuario, '_prueba')) { return 'ENSAYO'; }
        if ($modoZona !== 'PRODUCCION') { return 'PRUEBA'; }
        if ($modoVisto === 'PRUEBA') { return 'PRUEBA'; }
        if ($modoVisto === 'PRODUCCION') { return 'PRODUCCION'; }
        if ($desde !== null && $capturadaEn !== null && strcmp($capturadaEn, $desde) < 0) { return 'PRUEBA'; }
        return 'PRODUCCION';
    }

    // ------------------------------------------------------------------
    //  El formulario viejo: SOLO LECTURA.
    // ------------------------------------------------------------------

    /**
     * La carpeta `ot/produccion/` del sistema viejo. En Hostinger, los dos
     * sitios cuelgan del mismo home: nucleo → ot → public_html → dominio →
     * domains → ~. `sistema_viejo_raiz` en config.php la cambia (o la apaga,
     * con ''). En la estación no existe y todo esto devuelve null.
     */
    public static function raizViejo(): ?string
    {
        $cfg = Db::config();
        if (array_key_exists('sistema_viejo_raiz', $cfg)) {
            $r = (string) $cfg['sistema_viejo_raiz'];
            return $r !== '' && is_dir($r) ? rtrim($r, '/') : null;
        }
        $r = dirname(__DIR__, 5) . '/domains/yellow-elephant-166233.hostingersite.com/public_html/ot/produccion';
        return is_dir($r) ? $r : null;
    }

    /** El contador del formulario viejo para esa serie, o null si no se puede leer. Solo lee. */
    public static function contadorViejo(string $serie): ?int
    {
        $rel = self::CONTADORES_VIEJO[$serie] ?? null;
        $raiz = self::raizViejo();
        if ($rel === null || $raiz === null) { return null; }
        $txt = @file_get_contents($raiz . '/' . $rel);
        if ($txt === false) { return null; }
        $txt = trim($txt);
        return preg_match('/^\d{1,7}$/', $txt) ? (int) $txt : null;
    }

    /**
     * Desde qué número sigue la serie real al activar la zona. Pura. Nunca
     * baja: si la serie real ya iba más arriba (una zona que se desactivó y se
     * vuelve a activar) o la app ya emitió números más altos, se queda ahí.
     */
    public static function siguienteSembrado(int $viejo, int $actualReal, int $maxEmitido, int $margen = self::MARGEN): int
    {
        return max($viejo + $margen, $actualReal, $maxEmitido);
    }

    /**
     * ¿Se sigue usando el formulario viejo en una zona activada? Pura.
     *
     *   usos:     cuántas OT emitió el viejo desde que se activó la zona.
     *   repetidos: los números que el viejo dio después de activar y que la
     *             app TAMBIÉN emitió: dos OT distintas con el mismo número. Hay
     *             que revisarlas a mano. La app no puede evitarlo (tendría que
     *             escribir en el sistema viejo), solo verlo.
     *
     * Hasta la prueba de integración del 29-sep el «choque» comparaba el
     * contador viejo con el número ACTUAL de la serie, que sube con cada OT de
     * la app: nunca avisaba. Ahora mira los números que la app de verdad dio.
     *
     * @param int[] $numerosApp los números reales que emitió la app en esa serie
     * @return array{usos:int, choque:bool, repetidos:int[]}
     */
    public static function vigilar(?int $viejoAlActivar, ?int $viejoAhora, array $numerosApp = []): array
    {
        if ($viejoAlActivar === null || $viejoAhora === null) { return ['usos' => 0, 'choque' => false, 'repetidos' => []]; }
        $repetidos = array_values(array_filter(array_map('intval', $numerosApp),
                                               static fn(int $n): bool => $n > $viejoAlActivar && $n <= $viejoAhora));
        sort($repetidos);
        return ['usos' => max(0, $viejoAhora - $viejoAlActivar), 'choque' => $repetidos !== [], 'repetidos' => $repetidos];
    }

    /**
     * Las series de una zona con su estado, para la pantalla: número real
     * actual, contador del viejo ahora y al activar, y la vigilancia.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function series(string $zona): array
    {
        $out = [];
        foreach (['CORRECTIVO', 'PREVENTIVO'] as $mod) {
            $serie = $mod . ':' . $zona;
            $f = null;
            try {
                $f = Db::uno('SELECT ultimo, viejo_al_activar, sembrado_en FROM correlativos WHERE serie = ?', [$serie]);
            } catch (Throwable $e) {
                $f = Db::uno('SELECT ultimo FROM correlativos WHERE serie = ?', [$serie]);   // sin la 023
            }
            $ultimo = $f !== null ? (int) $f['ultimo'] : null;
            $real = $ultimo !== null && $ultimo < Emision::SERIE_PRUEBA ? $ultimo : null;
            $viejo = self::contadorViejo($serie);
            $alActivar = isset($f['viejo_al_activar']) ? (int) $f['viejo_al_activar'] : null;
            // Solo si el viejo se movió después de activar: los números de la
            // app en ese tramo son los que el viejo pudo repetir.
            $enTramo = [];
            if ($alActivar !== null && $viejo !== null && $viejo > $alActivar) {
                $enTramo = array_map('intval', array_column(Db::todos(
                    "SELECT CAST(SUBSTRING_INDEX(SUBSTRING(id_industec, 4), '-', 1) AS UNSIGNED) n
                       FROM ot_capturadas
                      WHERE zona = ? AND modulo = ? AND id_industec REGEXP '^OT-[0-9]+-'
                        AND CAST(SUBSTRING_INDEX(SUBSTRING(id_industec, 4), '-', 1) AS UNSIGNED) BETWEEN ? AND ?",
                    [$zona, $mod, $alActivar + 1, $viejo]), 'n'));
            }
            $out[$serie] = [
                'modulo'           => $mod,
                'ultimo'           => $ultimo,
                'real'             => $real,
                'viejo'            => $viejo,
                'viejo_al_activar' => $alActivar,
                'sembrado_en'      => $f['sembrado_en'] ?? null,
                'vigilancia'       => self::vigilar($alActivar, $viejo, $enTramo),
            ];
        }
        return $out;
    }

    /**
     * Lo que tiene que estar en orden antes de activar una zona. Cada fila:
     * ['ok' => bool, 'que' => texto]. Se activa solo si todas dan ok.
     *
     * @return array<int,array{ok:bool,que:string}>
     */
    public static function comprobaciones(string $zona): array
    {
        require_once __DIR__ . '/Correo.php';
        require_once __DIR__ . '/Destinatarios.php';
        $c = [];
        $c[] = ['ok' => in_array($zona, self::ACTIVABLES, true),
                'que' => in_array($zona, self::ACTIVABLES, true)
                    ? 'Zona del contrato.'
                    : 'Esta zona todavía no se puede activar: su contador del formulario viejo es uno solo para correctivo y preventivo (decide Andrés).'];
        $cfg = Emision::modoConfig();
        $c[] = ['ok' => $cfg === null,
                'que' => $cfg === null ? 'config.php no fija el modo: manda este interruptor.'
                                       : 'config.php fija el modo en ' . $cfg . ' para todo el sitio: este interruptor no tiene efecto hasta quitar esa clave.'];
        $cuenta = Correo::cuentaActiva();
        $okCuenta = $cuenta !== null && ($cuenta['clave'] ?? null) !== null && ($cuenta['cuenta_id'] ?? null) !== null;
        $c[] = ['ok' => $okCuenta,
                'que' => $okCuenta ? 'Cuenta de envío: ' . $cuenta['remitente'] . '.'
                                   : 'Falta una cuenta de envío activa con su clave (pestaña «Cuenta de envío»).'];
        // Un envío real, no solo conectar: es lo único que dice que el servidor
        // deja mandar como ese remitente y con un PDF adjunto.
        $probada = $okCuenta ? Correo::envioProbado((int) $cuenta['cuenta_id']) : null;
        $c[] = ['ok' => $probada !== null,
                'que' => $probada !== null ? 'La cuenta mandó un correo de prueba con éxito el ' . substr($probada, 0, 16) . '.'
                                           : 'Manda un correo de prueba con la cuenta de envío (y que salga bien) antes de activar.'];
        $phpmailer = Correo::cargarPhpMailer();
        $c[] = ['ok' => $phpmailer, 'que' => $phpmailer
                ? 'PHPMailer instalado en el servidor.' : 'Falta PHPMailer en el servidor (app/lib/LEEME.md).'];
        foreach (['CORRECTIVO', 'PREVENTIVO'] as $mod) {
            $v = self::contadorViejo($mod . ':' . $zona);
            $c[] = ['ok' => $v !== null,
                    'que' => $v !== null ? "Contador del formulario viejo, $mod: $v."
                                         : "No se pudo leer el contador del formulario viejo para $mod: sin él no se sabe desde qué número seguir."];
            if ($v !== null) {
                // La semilla no puede irse lejos del contador viejo: si algo la
                // empuja (una OT de la app con un número raro), se para aquí y
                // lo decide una persona, en vez de mandarle a KFC un salto.
                $fila = Db::uno('SELECT ultimo FROM correlativos WHERE serie = ?', [$mod . ':' . $zona]);
                $actual = $fila !== null && (int) $fila['ultimo'] < Emision::SERIE_PRUEBA ? (int) $fila['ultimo'] : 0;
                $semilla = self::siguienteSembrado($v, $actual, Emision::mayorNumeroReal($mod, $zona));
                $lejos = $semilla > $v + self::MARGEN + self::SALTO_MAXIMO;
                $c[] = ['ok' => !$lejos,
                        'que' => $lejos
                            ? "La serie $mod quedaría en $semilla, muy lejos del formulario viejo ($v): revisa correlativos y las OT de la app antes de activar."
                            : "La serie $mod quedaría en $semilla: la primera OT de la app sería la " . ($semilla + 1) . '.'];
            }
        }
        // Un local cualquiera de la zona: que la OT tenga al menos a quién ir.
        $dest = Destinatarios::resolver('ORDEN', $zona, null, null, null);
        $n = count($dest['para']) + count($dest['cc']);
        $c[] = ['ok' => $n > 0,
                'que' => $n > 0 ? "Destinatarios configurados para la zona: $n (además del correo de cada local)."
                                : 'La zona no tiene ningún destinatario configurado en Correos.'];
        return $c;
    }

    /**
     * Activa el envío real en una zona: siembra sus dos series desde el
     * formulario viejo y cambia el modo, en una sola transacción.
     *
     * @return array{ok:bool, errores:string[], detalle:array<string,mixed>}
     */
    public static function activar(string $zona, int $usuarioId, string $nota = ''): array
    {
        $fallan = array_values(array_filter(self::comprobaciones($zona), static fn($c) => !$c['ok']));
        if ($fallan !== []) {
            return ['ok' => false, 'errores' => array_column($fallan, 'que'), 'detalle' => []];
        }
        $pdo = Db::conn();
        $detalle = [];
        $pdo->beginTransaction();
        try {
            $antes = Db::uno('SELECT modo FROM emision_zonas WHERE zona = ? FOR UPDATE', [$zona]);
            if ($antes === null) { throw new RuntimeException("la zona $zona no está en emision_zonas (¿falta la 023?)"); }
            if ($antes['modo'] === 'PRODUCCION') { throw new RuntimeException("la zona $zona ya tiene el envío real activo"); }
            foreach (['CORRECTIVO', 'PREVENTIVO'] as $mod) {
                $serie = $mod . ':' . $zona;
                $viejo = self::contadorViejo($serie);
                if ($viejo === null) { throw new RuntimeException("no se pudo leer el contador del formulario viejo de $serie"); }
                $fila = Db::uno('SELECT ultimo FROM correlativos WHERE serie = ? FOR UPDATE', [$serie]);
                $actual = $fila !== null ? (int) $fila['ultimo'] : null;
                // La numeración del piloto se aparta antes de sembrar la real
                // (la 023 ya lo hizo con lo que había; esto cubre lo de después).
                if ($actual !== null && $actual >= Emision::SERIE_PRUEBA) {
                    Db::ejecutar("INSERT INTO correlativos (serie, ultimo, nota) VALUES (?, ?, ?)
                                  ON DUPLICATE KEY UPDATE ultimo = GREATEST(ultimo, VALUES(ultimo))",
                                 [Emision::seriePrueba($serie), $actual, 'serie del piloto, apartada al activar ' . $zona]);
                }
                $actualReal = $actual !== null && $actual < Emision::SERIE_PRUEBA ? $actual : 0;
                $maxEmitido = Emision::mayorNumeroReal($mod, $zona);
                $nuevo = self::siguienteSembrado($viejo, $actualReal, $maxEmitido);
                Db::ejecutar("INSERT INTO correlativos (serie, ultimo, nota, viejo_al_activar, sembrado_en, sembrado_por)
                              VALUES (?, ?, ?, ?, NOW(), ?)
                              ON DUPLICATE KEY UPDATE ultimo = VALUES(ultimo), nota = VALUES(nota),
                                                      viejo_al_activar = VALUES(viejo_al_activar),
                                                      sembrado_en = NOW(), sembrado_por = VALUES(sembrado_por)",
                             [$serie, $nuevo, "sembrado al activar $zona: formulario viejo en $viejo, margen " . self::MARGEN,
                              $viejo, $usuarioId]);
                $detalle[$serie] = ['viejo' => $viejo, 'antes' => $actual, 'sembrado' => $nuevo, 'primera' => $nuevo + 1];
            }
            Db::ejecutar("UPDATE emision_zonas SET modo = 'PRODUCCION', desde = NOW(), por = ?, nota = ? WHERE zona = ?",
                         [$usuarioId, $nota !== '' ? mb_substr($nota, 0, 300) : null, $zona]);
            Db::ejecutar("INSERT INTO emision_zonas_cambios (zona, antes, despues, detalle, por) VALUES (?, 'PRUEBA', 'PRODUCCION', ?, ?)",
                         [$zona, json_encode($detalle, JSON_UNESCAPED_UNICODE), $usuarioId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            return ['ok' => false, 'errores' => [$e->getMessage()], 'detalle' => []];
        }
        self::olvidar();
        Auth::bitacora('EMISION_ZONA_ACTIVA', 'emision_zona', $zona, 'envío real activado en ' . $zona,
                       'PRUEBA', 'PRODUCCION', $detalle);
        return ['ok' => true, 'errores' => [], 'detalle' => $detalle];
    }

    /**
     * Devuelve la zona al piloto. Las OT ya emitidas con número real siguen su
     * curso (su correo sale igual: son reales y el técnico ya las dio por
     * enviadas); desde este momento, las nuevas de la zona son del piloto. La
     * serie real se queda donde está y no se pierde: al reactivar se siembra
     * desde el mayor de los dos.
     *
     * @return array{ok:bool, errores:string[]}
     */
    public static function desactivar(string $zona, int $usuarioId, string $motivo): array
    {
        if (!in_array($zona, self::ZONAS, true)) { return ['ok' => false, 'errores' => ['zona desconocida']]; }
        if (mb_strlen(trim($motivo)) < 5) { return ['ok' => false, 'errores' => ['Escribe por qué (al menos cinco caracteres): queda en la bitácora.']]; }
        $n = Db::ejecutar("UPDATE emision_zonas SET modo = 'PRUEBA', desde = NOW(), por = ?, nota = ? WHERE zona = ? AND modo = 'PRODUCCION'",
                          [$usuarioId, mb_substr(trim($motivo), 0, 300), $zona]);
        if ($n === 0) { return ['ok' => false, 'errores' => ["la zona $zona ya estaba en el piloto"]]; }
        Db::ejecutar("INSERT INTO emision_zonas_cambios (zona, antes, despues, detalle, por) VALUES (?, 'PRODUCCION', 'PRUEBA', ?, ?)",
                     [$zona, json_encode(['motivo' => trim($motivo)], JSON_UNESCAPED_UNICODE), $usuarioId]);
        self::olvidar();
        Auth::bitacora('EMISION_ZONA_PILOTO', 'emision_zona', $zona, 'envío real desactivado en ' . $zona . ': ' . trim($motivo),
                       'PRODUCCION', 'PRUEBA');
        return ['ok' => true, 'errores' => []];
    }
}
