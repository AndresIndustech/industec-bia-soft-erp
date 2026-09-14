<?php
declare(strict_types=1);

/**
 * archivo_indexar_cli.php — Llena el índice del archivo general de OT (`ot_archivo`, 009).
 *
 * SOLO POR LÍNEA DE ÓRDENES. Por web responde 404 (el .htaccess corta los
 * `*_cli.php`) y, por si acaso, aquí se comprueba el SAPI.
 *
 * CUATRO FUENTES, TODAS IDEMPOTENTES (la clave es el identificador de la OT;
 * volver a correrlo no duplica ni borra nada; lo que ya se sabía no se pisa
 * con un vacío):
 *
 *   (a) Los PDF que están en `ordenes_pdf/`: cada archivo con nombre canónico
 *       entra con `en_servidor = 1`, bytes y huella. Si la OT la emitió la app
 *       (`ot_capturadas`) el origen es APP; si no, CORREO.
 *   (b) Lo que emitió la app (`ot_capturadas` con número): local, zona, aviso,
 *       módulo, fecha y técnico salen de la captura, aunque el PDF falte.
 *   (c) Lo que llegó por el buzón de correo (`atenciones.json`, la ventana de
 *       90 días que sube la estación): fecha, técnico y local del catálogo.
 *   (d) `--catalogo <archivo.json>`: el catálogo histórico de la estación
 *       (7.069 órdenes), exportado por `t2_15_exportar_archivo.py`. Entra como
 *       HISTORICO con la ruta donde vive en la estación (`fuente_ruta`), y con
 *       `en_servidor` según el PDF esté o no en `ordenes_pdf/`.
 *
 * USO (en el servidor, desde la carpeta de la app):
 *     php archivo_indexar_cli.php                       # (a) + (b) + (c)
 *     php archivo_indexar_cli.php --catalogo ~/respaldos/archivo_ot.json
 *     php archivo_indexar_cli.php --solo-pdf            # solo (a)
 *     php archivo_indexar_cli.php --sin-huella          # no recalcula sha256 de lo ya indexado
 *
 * Pensado para el cron de hPanel (una vez por hora basta) y para correrlo a
 * mano después de subir los históricos (D2). Código de salida 0 si terminó,
 * 2 si otra instancia estaba corriendo, 3 si falta la tabla.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/nucleo/Db.php';
require_once __DIR__ . '/nucleo/Emision.php';
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Catalogo.php';

$args = array_slice($argv, 1);
$opt = ['catalogo' => null, 'solo_pdf' => false, 'sin_huella' => false];
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--catalogo' && isset($args[$i + 1])) { $opt['catalogo'] = $args[++$i]; }
    elseif ($args[$i] === '--solo-pdf')   { $opt['solo_pdf'] = true; }
    elseif ($args[$i] === '--sin-huella') { $opt['sin_huella'] = true; }
    else { fwrite(STDERR, "opción no reconocida: {$args[$i]}\n"); exit(1); }
}

// Una instancia a la vez: el cron y una corrida a mano no deben pisarse.
$candado = fopen(sys_get_temp_dir() . '/industec_archivo_indexar.lock', 'c');
if ($candado === false || !flock($candado, LOCK_EX | LOCK_NB)) {
    echo "otra instancia está corriendo; nada que hacer\n";
    exit(2);
}

try {
    Db::todos('SELECT 1 FROM ot_archivo LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "falta la tabla ot_archivo: aplica la 009 primero\n");
    exit(3);
}

$DIR_PDF = __DIR__ . '/ordenes_pdf';
$ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];
$cuenta = ['pdf' => 0, 'app' => 0, 'correo' => 0, 'cierre' => 0, 'historico' => 0, 'saltados' => 0];

/** Lo que ya se sabe de cada OT indexada: para no recalcular huellas ni pisar datos. */
$previo = [];
foreach (Db::todos('SELECT id_industec, bytes, sha256, en_servidor, origen FROM ot_archivo') as $f) {
    $previo[(string) $f['id_industec']] = $f;
}

/** El maestro de locales, para el nombre y la cadena. */
$locales = [];
foreach ((Catalogo::cargar()['locales'] ?? []) as $l) {
    $locales[strtoupper((string) ($l['codigo'] ?? ''))] = $l;
}

/** Lo que trae el nombre canónico de la OT: OT-NNNN-LOCAL-AVISO[-Dn]-ZONA. */
function partesDelNombre(string $ot): array
{
    $p = explode('-', $ot);
    $zona = end($p);
    $dia = null;
    if (count($p) >= 2 && preg_match('/^D(\d{1,2})$/', $p[count($p) - 2], $m)) { $dia = (int) $m[1]; }
    $local = $p[2] ?? null;
    $aviso = null;
    foreach (array_slice($p, 3) as $x) {
        if (preg_match('/^\d{6,10}$/', $x)) { $aviso = $x; break; }
    }
    return ['zona' => in_array($zona, ['UIO', 'LARB', 'CNLJ', 'OTRA'], true) ? $zona : 'OTRA',
            'local' => $local, 'aviso' => $aviso, 'dia' => $dia];
}

/** Upsert: lo que llega vacío no pisa lo que ya estaba (COALESCE). */
function guardar(array $r): void
{
    Db::ejecutar(
        'INSERT INTO ot_archivo (id_industec, zona, local_codigo, local_nombre, cadena, aviso, modulo, dia,
                                 fecha_atencion, tecnico, origen, en_servidor, ruta, bytes, sha256, fuente_ruta)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            zona           = VALUES(zona),
            local_codigo   = COALESCE(VALUES(local_codigo), local_codigo),
            local_nombre   = COALESCE(VALUES(local_nombre), local_nombre),
            cadena         = COALESCE(VALUES(cadena), cadena),
            aviso          = COALESCE(VALUES(aviso), aviso),
            modulo         = COALESCE(VALUES(modulo), modulo),
            dia            = COALESCE(VALUES(dia), dia),
            fecha_atencion = COALESCE(VALUES(fecha_atencion), fecha_atencion),
            tecnico        = COALESCE(VALUES(tecnico), tecnico),
            -- APP manda sobre CORREO y sobre HISTORICO: es la fuente más rica.
            origen         = IF(origen = "APP", origen, VALUES(origen)),
            en_servidor    = GREATEST(en_servidor, VALUES(en_servidor)),
            ruta           = COALESCE(VALUES(ruta), ruta),
            bytes          = COALESCE(VALUES(bytes), bytes),
            sha256         = COALESCE(VALUES(sha256), sha256),
            fuente_ruta    = COALESCE(VALUES(fuente_ruta), fuente_ruta)',
        [$r['id_industec'], $r['zona'], $r['local_codigo'] ?? null, $r['local_nombre'] ?? null,
         $r['cadena'] ?? null, $r['aviso'] ?? null, $r['modulo'] ?? null, $r['dia'] ?? null,
         $r['fecha_atencion'] ?? null, $r['tecnico'] ?? null, $r['origen'], (int) ($r['en_servidor'] ?? 0),
         $r['ruta'] ?? null, $r['bytes'] ?? null, $r['sha256'] ?? null, $r['fuente_ruta'] ?? null]
    );
}

// --- (b) lo que emitió la app: se lee primero para saber qué PDF son APP ----
$deApp = [];
try {
    foreach (Db::todos(
        "SELECT c.id_industec, c.zona, c.local_codigo, c.cadena, c.aviso, c.modulo, c.emitida_en,
                u.nombre AS tecnico,
                JSON_UNQUOTE(JSON_EXTRACT(c.carga, '$.fecha_atencion')) AS fecha_atencion,
                JSON_UNQUOTE(JSON_EXTRACT(c.carga, '$.dia')) AS dia
           FROM ot_capturadas c JOIN usuarios u ON u.usuario_id = c.usuario_id
          WHERE c.id_industec IS NOT NULL AND c.estado IN ('EMITIDA','ENVIADA','NUMERADA','FALLIDA','PROCESADA')"
    ) as $c) {
        $ot = strtoupper((string) $c['id_industec']);
        if (!preg_match(Emision::PATRON_OT, $ot)) { $cuenta['saltados']++; continue; }
        $n = partesDelNombre($ot);
        $loc = strtoupper((string) ($c['local_codigo'] ?? $n['local'] ?? ''));
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $c['fecha_atencion'], $m) ? $m[0]
               : (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $c['emitida_en'], $m2) ? $m2[0] : null);
        $deApp[$ot] = [
            'id_industec' => $ot, 'zona' => $c['zona'] ?: $n['zona'], 'local_codigo' => $loc ?: null,
            'local_nombre' => $locales[$loc]['nombre'] ?? null,
            'cadena' => $c['cadena'] ?: ($locales[$loc]['cadena'] ?? null),
            'aviso' => $c['aviso'] ?: $n['aviso'], 'modulo' => $c['modulo'] ?: null,
            'dia' => is_numeric((string) $c['dia']) ? (int) $c['dia'] : $n['dia'],
            'fecha_atencion' => $fecha, 'tecnico' => $c['tecnico'], 'origen' => 'APP',
        ];
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ot_capturadas: " . $e->getMessage() . "\n");
}

// --- (a) los PDF del servidor -------------------------------------------------
if (is_dir($DIR_PDF)) {
    foreach (scandir($DIR_PDF) ?: [] as $nombre) {
        if (!preg_match('/^(.+)\.pdf$/i', $nombre, $m)) { continue; }
        $ot = strtoupper($m[1]);
        if (!preg_match(Emision::PATRON_OT, $ot)) { $cuenta['saltados']++; continue; }
        $ruta = $DIR_PDF . '/' . $nombre;
        $bytes = (int) filesize($ruta);
        $hash = null;
        $ant = $previo[$ot] ?? null;
        if ($ant !== null && (int) $ant['bytes'] === $bytes && !empty($ant['sha256']) && $opt['sin_huella']) {
            $hash = (string) $ant['sha256'];
        } elseif ($ant === null || (int) $ant['bytes'] !== $bytes || empty($ant['sha256']) || !$opt['sin_huella']) {
            $hash = hash_file('sha256', $ruta) ?: null;
        }
        $n = partesDelNombre($ot);
        $loc = strtoupper((string) ($n['local'] ?? ''));
        $base = $deApp[$ot] ?? [
            'id_industec' => $ot, 'zona' => $n['zona'], 'local_codigo' => $loc ?: null,
            'local_nombre' => $locales[$loc]['nombre'] ?? null, 'cadena' => $locales[$loc]['cadena'] ?? null,
            'aviso' => $n['aviso'], 'dia' => $n['dia'], 'origen' => 'CORREO',
        ];
        guardar($base + ['en_servidor' => 1, 'ruta' => 'ordenes_pdf/' . $nombre, 'bytes' => $bytes, 'sha256' => $hash]);
        $cuenta['pdf']++;
        unset($deApp[$ot]);
    }
}

if (!$opt['solo_pdf']) {
    // --- (b) lo emitido por la app cuyo PDF no está ---------------------------
    foreach ($deApp as $ot => $r) {
        guardar($r + ['en_servidor' => 0]);
        $cuenta['app']++;
    }

    // --- (c) lo que llegó por el buzón de correo ------------------------------
    $porAviso = [];
    foreach ((Casos::catalogo()['datos'] ?? []) as $c) { $porAviso[(string) ($c['aviso'] ?? '')] = $c; }
    foreach (Casos::atenciones() as $aviso => $a) {
        $c = $porAviso[(string) $aviso] ?? null;
        foreach ($a['ots'] ?? [] as $o) {
            $ot = strtoupper((string) ($o['ot'] ?? ''));
            if (!preg_match(Emision::PATRON_OT, $ot)) { $cuenta['saltados']++; continue; }
            if (isset($previo[$ot]) && $previo[$ot]['origen'] === 'APP') { continue; }
            $n = partesDelNombre($ot);
            $loc = strtoupper((string) ($c['local'] ?? $n['local'] ?? ''));
            $personas = array_values(array_filter(array_map(
                static fn($p) => is_array($p) ? (string) ($p['nombre'] ?? '') : (string) $p, $o['personas'] ?? [])));
            guardar([
                'id_industec' => $ot, 'zona' => $c['zona'] ?? $n['zona'], 'local_codigo' => $loc ?: null,
                'local_nombre' => $c['local_nombre'] ?? ($locales[$loc]['nombre'] ?? null),
                'cadena' => $locales[$loc]['cadena'] ?? null, 'aviso' => (string) $aviso,
                'dia' => $n['dia'], 'fecha_atencion' => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($o['fecha'] ?? ''), $m) ? $m[0] : null,
                'tecnico' => $personas ? mb_substr(implode(', ', $personas), 0, 160) : null,
                'origen' => 'CORREO', 'en_servidor' => Emision::existePdf($ot) ? 1 : 0,
            ]);
            $cuenta['correo']++;
        }
    }

    // --- (e) la orden de cierre que guarda la gestión del caso -----------------
    /* La reconciliación y la app dejan la orden de cierre en `casos_gestion`
       aunque el informe ya no esté en `atenciones.json`, que es una ventana.
       El 2026-09-14 eran 19 de las 67 órdenes de cierre las que no estaban en
       el índice: el Archivo no las encontraba ni buscando el aviso (10354415,
       10354383). El técnico solo se toma si salió del informe (`tecnico_auto`):
       el que asignó una persona no es necesariamente quien firmó la orden. */
    foreach (Db::todos(
        "SELECT g.aviso, g.zona, g.ot_cierre, g.atendido_en, g.tecnico_auto, u.nombre AS tecnico
           FROM casos_gestion g LEFT JOIN usuarios u ON u.usuario_id = g.asignado_a
          WHERE g.ot_cierre IS NOT NULL AND g.ot_cierre <> ''"
    ) as $g) {
        $ot = strtoupper(trim((string) $g['ot_cierre']));
        if (!preg_match(Emision::PATRON_OT, $ot)) { $cuenta['saltados']++; continue; }
        if (isset($previo[$ot]) && $previo[$ot]['origen'] === 'APP') { continue; }
        $n = partesDelNombre($ot);
        $loc = strtoupper((string) ($n['local'] ?? ''));
        // El correo nombra el local sin «EC» (K061) y el maestro con él (K061EC).
        $l = $locales[$loc] ?? $locales[$loc . 'EC'] ?? null;
        guardar([
            'id_industec' => $ot,
            'zona' => in_array((string) $g['zona'], $ZONAS, true) ? (string) $g['zona'] : $n['zona'],
            'local_codigo' => $loc ?: null,
            'local_nombre' => $l['nombre'] ?? null, 'cadena' => $l['cadena'] ?? null,
            'aviso' => (string) $g['aviso'], 'dia' => $n['dia'],
            'fecha_atencion' => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $g['atendido_en'], $m) ? $m[0] : null,
            'tecnico' => (int) $g['tecnico_auto'] === 1 && $g['tecnico'] ? mb_substr((string) $g['tecnico'], 0, 160) : null,
            'origen' => 'CORREO', 'en_servidor' => Emision::existePdf($ot) ? 1 : 0,
        ]);
        $cuenta['cierre']++;
    }

    // --- (d) el catálogo histórico de la estación -----------------------------
    if ($opt['catalogo'] !== null) {
        $json = json_decode((string) @file_get_contents($opt['catalogo']), true);
        $filas = $json['filas'] ?? (is_array($json) ? $json : null);
        if (!is_array($filas)) {
            fwrite(STDERR, "no se pudo leer el catálogo {$opt['catalogo']}\n");
            exit(1);
        }
        foreach ($filas as $h) {
            $ot = strtoupper(trim((string) ($h['id_industec'] ?? '')));
            if (!preg_match(Emision::PATRON_OT, $ot)) { $cuenta['saltados']++; continue; }
            $n = partesDelNombre($ot);
            $loc = strtoupper((string) ($h['local'] ?? $n['local'] ?? ''));
            $mod = strtoupper((string) ($h['modulo'] ?? ''));
            guardar([
                'id_industec' => $ot,
                'zona' => in_array(strtoupper((string) ($h['zona'] ?? '')), $ZONAS, true) ? strtoupper((string) $h['zona']) : $n['zona'],
                'local_codigo' => $loc ?: null,
                'local_nombre' => $h['local_nombre'] ?? ($locales[$loc]['nombre'] ?? null),
                'cadena' => $h['cadena'] ?? ($locales[$loc]['cadena'] ?? null),
                'aviso' => ($h['aviso'] ?? '') !== '' ? (string) $h['aviso'] : $n['aviso'],
                'modulo' => in_array($mod, ['CORRECTIVO', 'PREVENTIVO', 'OTROS'], true) ? $mod : null,
                'dia' => is_numeric((string) ($h['dia'] ?? '')) ? (int) $h['dia'] : $n['dia'],
                'fecha_atencion' => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($h['fecha_atencion'] ?? ''), $m) ? $m[0] : null,
                'tecnico' => ($h['tecnico'] ?? '') !== '' ? mb_substr((string) $h['tecnico'], 0, 160) : null,
                'origen' => 'HISTORICO', 'en_servidor' => Emision::existePdf($ot) ? 1 : 0,
                'fuente_ruta' => ($h['ruta'] ?? '') !== '' ? mb_substr((string) $h['ruta'], 0, 400) : null,
            ]);
            $cuenta['historico']++;
        }
    }
}

$tot = Db::uno('SELECT COUNT(*) n, SUM(en_servidor) s FROM ot_archivo');
printf("PDF en el servidor: %d · emitidas por la app sin PDF: %d · del correo: %d · de la gestión: %d · del histórico: %d · saltados: %d\n",
       $cuenta['pdf'], $cuenta['app'], $cuenta['correo'], $cuenta['cierre'], $cuenta['historico'], $cuenta['saltados']);
printf("índice: %d órdenes, %d con el PDF aquí\n", (int) ($tot['n'] ?? 0), (int) ($tot['s'] ?? 0));
try {
    Db::ejecutar(
        "INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia, detalle, datos, exito)
         VALUES (NULL, 'automatico', 'INDEXAR_ARCHIVO', 'archivo_ot', ?, ?, ?, 1)",
        [$opt['catalogo'] !== null ? basename((string) $opt['catalogo']) : 'servidor',
         sprintf('%d en el índice, %d con PDF', (int) ($tot['n'] ?? 0), (int) ($tot['s'] ?? 0)),
         json_encode($cuenta, JSON_UNESCAPED_UNICODE)]
    );
} catch (Throwable $e) { /* la bitácora nunca frena el índice */ }
exit(0);
