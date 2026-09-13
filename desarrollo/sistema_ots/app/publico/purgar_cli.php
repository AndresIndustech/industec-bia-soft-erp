<?php
declare(strict_types=1);

/**
 * purgar_cli.php — Lo que sobra en el disco del servidor, con cuidado (T2.14.6, E-11).
 *
 * SOLO POR LÍNEA DE ÓRDENES, y SOLO INFORMA salvo con --ejecutar. Nunca toca
 * un PDF de orden ni una foto que alguna orden emitida liste.
 *
 *   (a) Fotos huérfanas (`ot_fotos` + `ordenes_fotos/`): las que subió el
 *       celular y ninguna orden lista —el formulario las descartó después de
 *       subirlas (E-20)— o cuya captura no existe, con más de N días
 *       (30 por defecto). Se borra el archivo y la fila.
 *   (b) Archivos en `ordenes_fotos/` sin fila en `ot_fotos`, con más de N días.
 *   (c) Los `.tmp` de `ordenes_pdf/`: una emisión que se cortó a medias deja
 *       el temporal; a las 24 horas ya no lo va a recoger nadie.
 *
 * USO (en el servidor, desde la carpeta de la app):
 *     php purgar_cli.php                 # informa, no borra
 *     php purgar_cli.php --ejecutar      # borra
 *     php purgar_cli.php --dias=45       # otro umbral para las fotos
 *
 * Pensado para el cron semanal de hPanel. Código de salida 0.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/nucleo/Db.php';

$ejecutar = false; $dias = 30;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--ejecutar') { $ejecutar = true; }
    elseif (preg_match('/^--dias=(\d{1,3})$/', $a, $m)) { $dias = max(1, (int) $m[1]); }
    else { fwrite(STDERR, "opción no reconocida: $a\n"); exit(1); }
}
$DIR_FOTOS = __DIR__ . '/ordenes_fotos';
$DIR_PDF   = __DIR__ . '/ordenes_pdf';
$n = ['fotos_huerfanas' => 0, 'archivos_sueltos' => 0, 'tmp' => 0, 'bytes' => 0];
$modo = $ejecutar ? 'BORRADO' : 'ENSAYO';

/* --- (a) fotos huérfanas ---------------------------------------------------- */
$listadas = [];    // envio_uuid => [foto_uuid...] según la carga de la orden
try {
    foreach (Db::todos('SELECT envio_uuid, carga FROM ot_capturadas') as $c) {
        $carga = json_decode((string) $c['carga'], true);
        $uuids = [];
        foreach ((array) ($carga['fotos'] ?? []) as $f) {
            $u = is_array($f) ? (string) ($f['uuid'] ?? $f['foto_uuid'] ?? '') : (string) $f;
            if ($u !== '') { $uuids[$u] = true; }
        }
        $listadas[(string) $c['envio_uuid']] = $uuids;
    }
    $viejas = Db::todos('SELECT foto_id, foto_uuid, envio_uuid, ruta, bytes FROM ot_fotos
                          WHERE subida_en < DATE_SUB(NOW(), INTERVAL ? DAY)', [$dias]);
} catch (Throwable $e) {
    fwrite(STDERR, "sin las tablas de la 008: " . $e->getMessage() . "\n");
    $viejas = [];
}
$conFila = [];
foreach (Db::todos('SELECT ruta FROM ot_fotos') as $r) { $conFila[basename((string) $r['ruta'])] = true; }

foreach ($viejas as $f) {
    $envio = (string) $f['envio_uuid'];
    $huerfana = !isset($listadas[$envio]) || !isset($listadas[$envio][(string) $f['foto_uuid']]);
    if (!$huerfana) { continue; }
    $ruta = $DIR_FOTOS . '/' . basename((string) $f['ruta']);
    $n['fotos_huerfanas']++;
    $n['bytes'] += (int) $f['bytes'];
    printf("  foto huérfana  %s  (%s, %d KB)%s\n", $f['foto_uuid'], isset($listadas[$envio]) ? 'no la lista la orden' : 'sin captura', (int) round((int) $f['bytes'] / 1024), $ejecutar ? '  -> borrada' : '');
    if ($ejecutar) {
        if (is_file($ruta)) { @unlink($ruta); }
        Db::ejecutar('DELETE FROM ot_fotos WHERE foto_id = ?', [(int) $f['foto_id']]);
        unset($conFila[basename((string) $f['ruta'])]);
    }
}

/* --- (b) archivos sueltos en ordenes_fotos ---------------------------------- */
if (is_dir($DIR_FOTOS)) {
    foreach (scandir($DIR_FOTOS) ?: [] as $nombre) {
        if ($nombre[0] === '.' || !preg_match('/\.(jpe?g|png|webp)$/i', $nombre)) { continue; }
        $ruta = $DIR_FOTOS . '/' . $nombre;
        if (isset($conFila[$nombre]) || filemtime($ruta) > time() - $dias * 86400) { continue; }
        $n['archivos_sueltos']++;
        $n['bytes'] += (int) filesize($ruta);
        printf("  archivo suelto %s  (%d KB)%s\n", $nombre, (int) round((int) filesize($ruta) / 1024), $ejecutar ? '  -> borrado' : '');
        if ($ejecutar) { @unlink($ruta); }
    }
}

/* --- (c) temporales de la emisión ------------------------------------------- */
if (is_dir($DIR_PDF)) {
    foreach (scandir($DIR_PDF) ?: [] as $nombre) {
        if (!preg_match('/\.tmp$/i', $nombre) && !preg_match('/\.tmp\.[a-z0-9]+$/i', $nombre)) { continue; }
        $ruta = $DIR_PDF . '/' . $nombre;
        if (filemtime($ruta) > time() - 24 * 3600) { continue; }
        $n['tmp']++;
        $n['bytes'] += (int) filesize($ruta);
        printf("  temporal       %s%s\n", $nombre, $ejecutar ? '  -> borrado' : '');
        if ($ejecutar) { @unlink($ruta); }
    }
}

printf("%s: %d fotos huérfanas, %d archivos sueltos, %d temporales · %d KB%s\n",
       $modo, $n['fotos_huerfanas'], $n['archivos_sueltos'], $n['tmp'], (int) round($n['bytes'] / 1024),
       $ejecutar ? '' : ' (nada se borró: usa --ejecutar)');
if ($ejecutar && array_sum([$n['fotos_huerfanas'], $n['archivos_sueltos'], $n['tmp']]) > 0) {
    try {
        Db::ejecutar("INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia, detalle, datos, exito)
                      VALUES (NULL, 'automatico', 'PURGA', 'servidor', 'purgar_cli', ?, ?, 1)",
                     [sprintf('%d fotos, %d sueltos, %d temporales, %d KB', $n['fotos_huerfanas'], $n['archivos_sueltos'], $n['tmp'], (int) round($n['bytes'] / 1024)),
                      json_encode($n + ['dias' => $dias])]);
    } catch (Throwable $e) { /* la bitácora nunca frena la purga */ }
}
exit(0);
