<?php
declare(strict_types=1);

/**
 * archivo_verificar_cli.php — Integridad real del PDF que el índice del
 * Archivo dice tener (T2.28.17a).
 *
 * SOLO LECTURA. No borra, no mueve, no reindexa: por cada fila de
 * `ot_archivo` con `en_servidor = 1` comprueba que:
 *   1. el archivo existe en `ordenes_pdf/`;
 *   2. pesa más de 1 KB;
 *   3. los primeros bytes son `%PDF-`;
 *   4. los últimos 1.024 bytes contienen `%%EOF`.
 * Si el índice trae `sha256`, además recalcula la huella del archivo y la
 * compara contra la que quedó guardada al indexar.
 *
 * POR QUÉ ESTAS CUATRO COSAS Y NO SOLO "EXISTE": `en_servidor = 1` es lo que
 * hace que el Archivo ofrezca "Ver" en vez de "Pedir copia" (Emision::existePdf()
 * / T2.28.17b). Un archivo de 0 bytes, un HTML de error guardado con extensión
 * .pdf, o un PDF cortado a la mitad por una subida que se cortó, pasarían el
 * "existe" y romperían igual `pdf.php` — o peor, servirían un documento
 * incompleto sin que nadie lo note hasta que alguien lo abre.
 *
 * DÓNDE VIVE. Como el resto de `pruebas/servidor/*.php` (LEEME.md:29-44): se
 * sube por scp a `~/respaldos/` (fuera del docroot, nadie lo sirve por web) y
 * se corre con el directorio de trabajo puesto en `ot/` — por eso usa
 * `getcwd()` y no `__DIR__` para encontrar `nucleo/Db.php` y para resolver la
 * `ruta` del índice (que es relativa al docroot, no a donde vive este archivo).
 *
 * USO (en el servidor, DESDE la carpeta de la app):
 *     cd domains/.../public_html/ot
 *     php ~/respaldos/archivo_verificar_cli.php                  # todo en_servidor=1
 *     php ~/respaldos/archivo_verificar_cli.php --limite=200     # una muestra, para probar rápido
 *     php ~/respaldos/archivo_verificar_cli.php --sin-huella     # se salta el sha256 (más rápido)
 *
 * Código de salida: 0 si TODO lo comprobado está íntegro, 1 si hay al menos
 * un problema (la lista completa sale por stdout, una línea por fila).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once getcwd() . '/nucleo/Db.php';

$args = array_slice($argv, 1);
$opt = ['limite' => 0, 'sin_huella' => false];
foreach ($args as $a) {
    if (preg_match('/^--limite=(\d+)$/', $a, $m)) { $opt['limite'] = (int) $m[1]; }
    elseif ($a === '--sin-huella') { $opt['sin_huella'] = true; }
    else { fwrite(STDERR, "opción no reconocida: {$a}\n"); exit(1); }
}

try {
    Db::todos('SELECT 1 FROM ot_archivo LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "falta la tabla ot_archivo: aplica la 009 primero\n");
    exit(3);
}

// getcwd(), NO __DIR__: el script vive en ~/respaldos/ (fuera del docroot,
// ver cabecera) pero `ruta` en el índice es relativa a la carpeta de la app
// (ot/), que es desde donde se invoca este CLI. Con __DIR__ aquí, TODAS las
// filas daban RUTA_FUERA_DE_LA_APP -- se detectó al correrlo de verdad contra
// el servidor (T2.28.17a, 2026-09-24): 0/7640 íntegros en vez de 7640/7640.
$DIR_APP = getcwd();
$sql = 'SELECT id_industec, ruta, bytes AS bytes_indice, sha256 FROM ot_archivo WHERE en_servidor = 1 ORDER BY id_industec';
if ($opt['limite'] > 0) { $sql .= ' LIMIT ' . $opt['limite']; }
$filas = Db::todos($sql);

$total = count($filas);
$ok = 0;
$problemas = [];   // [id_industec, motivo]

foreach ($filas as $r) {
    $ot = (string) $r['id_industec'];
    $ruta = (string) ($r['ruta'] ?? '');
    if ($ruta === '') { $problemas[] = [$ot, 'SIN_RUTA_EN_INDICE']; continue; }

    // `ruta` es relativa a la carpeta de la app (p.ej. "ordenes_pdf/OT-....pdf"),
    // tal como la deja archivo_indexar_cli.php. Nunca se sale de ahí: basename
    // aparte no haría falta, pero ningún "../" debe poder escapar del docroot.
    $ruta_fs = $DIR_APP . '/' . ltrim(str_replace('\\', '/', $ruta), '/');
    if (strpos(realpath($ruta_fs) ?: '', realpath($DIR_APP) ?: "\0") !== 0) {
        $problemas[] = [$ot, 'RUTA_FUERA_DE_LA_APP:' . $ruta];
        continue;
    }
    if (!is_file($ruta_fs)) { $problemas[] = [$ot, 'NO_EXISTE_EN_DISCO:' . $ruta]; continue; }

    $bytes = @filesize($ruta_fs);
    if ($bytes === false) { $problemas[] = [$ot, 'NO_SE_PUDO_LEER_TAMANO']; continue; }
    if ($bytes <= 1024) { $problemas[] = [$ot, "PESA_1KB_O_MENOS:{$bytes}b"]; continue; }

    $fh = @fopen($ruta_fs, 'rb');
    if ($fh === false) { $problemas[] = [$ot, 'NO_SE_PUDO_ABRIR']; continue; }
    $inicio = fread($fh, 5);
    if ($inicio !== '%PDF-') {
        fclose($fh);
        $problemas[] = [$ot, 'NO_EMPIEZA_POR_%PDF-'];
        continue;
    }
    fseek($fh, max(0, $bytes - 1024));
    $cola = fread($fh, 1024);
    fclose($fh);
    if ($cola === false || strpos($cola, '%%EOF') === false) {
        $problemas[] = [$ot, 'SIN_%%EOF_EN_LOS_ULTIMOS_1024_BYTES'];
        continue;
    }

    $sha_indice = strtolower(trim((string) ($r['sha256'] ?? '')));
    if (!$opt['sin_huella'] && $sha_indice !== '') {
        $sha_real = hash_file('sha256', $ruta_fs);
        if ($sha_real === false || $sha_real !== $sha_indice) {
            $problemas[] = [$ot, 'HUELLA_NO_COINCIDE: indice=' . $sha_indice . ' real=' . ($sha_real ?: '?')];
            continue;
        }
    }

    $ok++;
}

printf("archivo_verificar_cli.php · en_servidor=1 comprobados: %d\n", $total);
printf("íntegros: %d/%d\n", $ok, $total);
if ($problemas) {
    printf("con problema: %d\n", count($problemas));
    foreach ($problemas as [$ot, $motivo]) {
        echo "  {$ot}\t{$motivo}\n";
    }
} else {
    echo "sin problemas.\n";
}

try {
    Db::ejecutar(
        "INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia, detalle, datos, exito)
         VALUES (NULL, 'automatico', 'VERIFICAR_ARCHIVO', 'archivo_ot', 'servidor', ?, ?, ?)",
        [sprintf('%d/%d integros', $ok, $total),
         json_encode(['total' => $total, 'ok' => $ok, 'problemas' => count($problemas)], JSON_UNESCAPED_UNICODE),
         $problemas ? 0 : 1]
    );
} catch (Throwable $e) { /* la bitácora nunca frena la verificación */ }

exit($problemas ? 1 : 0);
