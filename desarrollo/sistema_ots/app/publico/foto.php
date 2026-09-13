<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';
require_once __DIR__ . '/nucleo/Emision.php';

/**
 * foto.php — Recibe UNA foto de una orden, antes que la orden (T2.13, la 008).
 *
 * POR QUE DE UNA EN UNA
 * En producción las fotos viajan dentro del envío, en base64. Una preventiva con
 * 7 equipos y 35 fotos pesa ~48 MB contra un límite de 64 MB, y si se pasa, PHP
 * descarta el envío entero en silencio y sale un PDF en blanco. Aquí cada foto
 * sube sola, en cuanto hay señal, y la orden solo lleva sus identificadores.
 *
 * IDEMPOTENTE POR `foto_uuid`, que genera el celular: sin señal el reintento es
 * la norma, y la segunda subida de la misma foto no crea otra.
 *
 * SE RECODIFICA, SIEMPRE. La foto del teléfono trae EXIF con la ubicación GPS del
 * local y el modelo del teléfono, y va dentro de un PDF que recibe Grupo KFC.
 * Volver a codificarla lo quita, la deja a 1.200 px por el lado mayor —como la
 * dejaba producción— y de paso comprueba que de verdad sea una imagen.
 *
 * Las mismas respuestas que envio.php: 200 guardada (o ya estaba), 409 de otro
 * usuario, 4xx no sirve y no se reintenta, 5xx se reintenta.
 */

const MAX_BYTES = 15 * 1024 * 1024;
const LADO_MAX  = 1200;
const CALIDAD   = 70;
const RE_UUID   = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
// SEG-19/H-24: un PNG/JPEG "bomba" (pocos bytes, muchísimos píxeles) agota la
// memoria al decodificarlo con GD y el fatal de PHP responde 500 -- que
// cola.js SÍ reintenta, cada dos minutos, para siempre. 24 Mpx es más que
// cualquier foto de celular real (12-16 Mpx) y deja margen.
const MAX_PIXELES = 24_000_000;
// E-23: tope de fotos por orden. app.js ya para en 8 en el picker; esto es lo
// mismo del lado del servidor, que es el que de verdad decide.
const MAX_FOTOS_POR_ENVIO = 8;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $cuerpo): never
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['ok' => false, 'motivo' => 'solo POST']);
}
$u = Auth::exigir('ots.crear', true);
Auth::exigirCsrf();
$uid = (int) $u['usuario_id'];

$envio = strtolower((string) ($_POST['envio_uuid'] ?? ''));
$foto  = strtolower((string) ($_POST['foto_uuid'] ?? ''));
if (!preg_match(RE_UUID, $envio) || !preg_match(RE_UUID, $foto)) {
    responder(400, ['ok' => false, 'motivo' => 'faltan los identificadores de la foto']);
}
$n = max(0, min(99, (int) ($_POST['n'] ?? 0)));

try {
    // Ya estaba: el reintento no la duplica. La de otro no se toca.
    $previa = Db::uno('SELECT usuario_id FROM ot_fotos WHERE foto_uuid = ?', [$foto]);
    if ($previa !== null) {
        if ((int) $previa['usuario_id'] !== $uid) {
            responder(409, ['ok' => false, 'ajena' => true, 'motivo' => 'esa foto la subió otro usuario']);
        }
        responder(200, ['ok' => true, 'foto_uuid' => $foto, 'ya_estaba' => true]);
    }
    // Si la orden ya llegó, tiene que ser suya.
    $orden = Db::uno('SELECT usuario_id FROM ot_capturadas WHERE envio_uuid = ?', [$envio]);
    if ($orden !== null && (int) $orden['usuario_id'] !== $uid) {
        responder(409, ['ok' => false, 'ajena' => true, 'motivo' => 'esa orden la llenó otro usuario']);
    }
    // E-23: tope de fotos por orden. Se cuenta ANTES de aceptar el archivo:
    // no tiene sentido decodificar una novena foto para rechazarla después.
    $yaSubidas = (int) (Db::uno('SELECT COUNT(*) AS n FROM ot_fotos WHERE envio_uuid = ?', [$envio])['n'] ?? 0);
    if ($yaSubidas >= MAX_FOTOS_POR_ENVIO) {
        responder(400, ['ok' => false,
                        'motivo' => 'esta orden ya tiene ' . MAX_FOTOS_POR_ENVIO . ' fotos, el máximo por orden']);
    }
} catch (Throwable $ex) {
    error_log('foto.php: ' . $ex->getMessage());
    responder(503, ['ok' => false, 'motivo' => 'el sistema todavía no recibe fotos; quedan en el celular y suben solas']);
}

$f = $_FILES['foto'] ?? null;
if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $grande = in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    responder($grande ? 413 : 400, ['ok' => false, 'motivo' => $grande ? 'la foto pesa demasiado' : 'no llegó la foto']);
}
if ((int) $f['size'] > MAX_BYTES) {
    responder(413, ['ok' => false, 'motivo' => 'la foto pesa demasiado']);
}

$bin = (string) file_get_contents($f['tmp_name']);
// SEG-19/H-24: se miran las DIMENSIONES antes de decodificar. Un archivo de
// pocos MB puede describir una imagen de miles de millones de píxeles, y
// `imagecreatefromstring` la decodifica entera en memoria: eso es lo que
// tumbaba el proceso con un fatal 500 (que SÍ se reintenta) en vez de un 413
// (que no). `getimagesizefromstring` solo lee la cabecera.
$info = @getimagesizefromstring($bin);
if ($info === false) {
    responder(400, ['ok' => false, 'motivo' => 'el archivo no es una imagen']);
}
if ($info[0] * $info[1] > MAX_PIXELES) {
    responder(413, ['ok' => false, 'motivo' => 'la foto es demasiado grande en píxeles; tómala de nuevo con menor resolución']);
}

/* Recodificar, siempre (quita EXIF con la ubicación del local). Con Imagick,
   si está instalado, se corrige la orientación EXIF antes de nada (E-17):
   sin esto, una foto que el navegador no pudo reducir —createImageBitmap
   falló y se subió el original con su etiqueta de orientación— sale girada
   en el PDF que recibe Grupo KFC. GD no lee EXIF; por eso es Imagick o nada. */
if (class_exists('Imagick')) {
    try {
        $im = new Imagick();
        $im->readImageBlob($bin);
        $im->autoOrient();
        $im->stripImage();
        $bin = $im->getImageBlob();
        $im->clear();
    } catch (Throwable $ex) {
        // Si Imagick no pudo leerla, se sigue con el binario original: GD
        // decide después si de verdad es una imagen.
    }
}

$img = @imagecreatefromstring($bin);
if ($img === false) {
    responder(400, ['ok' => false, 'motivo' => 'el archivo no es una imagen']);
}

$w = imagesx($img);
$h = imagesy($img);
$escala = min(1, LADO_MAX / max($w, $h));
if ($escala < 1) {
    $nw = max(1, (int) round($w * $escala));
    $nh = max(1, (int) round($h * $escala));
    $chica = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($chica, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);
    [$img, $w, $h] = [$chica, $nw, $nh];
}
ob_start();
imagejpeg($img, null, CALIDAD);
$jpg = (string) ob_get_clean();
imagedestroy($img);

$carpeta = Emision::dirFotos() . '/' . $envio;
$ruta = $envio . '/' . $foto . '.jpg';
if (!is_dir($carpeta) && !mkdir($carpeta, 0755, true) && !is_dir($carpeta)) {
    responder(503, ['ok' => false, 'motivo' => 'no se pudo guardar la foto; queda en el celular y se reintenta']);
}
$destino = Emision::dirFotos() . '/' . $ruta;
if (file_put_contents($destino . '.tmp', $jpg) !== strlen($jpg) || !rename($destino . '.tmp', $destino)) {
    responder(503, ['ok' => false, 'motivo' => 'no se pudo guardar la foto; queda en el celular y se reintenta']);
}

try {
    Db::ejecutar('INSERT INTO ot_fotos (foto_uuid, envio_uuid, usuario_id, orden_n, ruta, bytes, ancho, alto, sha256)
                  VALUES (?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE foto_id = foto_id',
                 [$foto, $envio, $uid, $n, $ruta, strlen($jpg), $w, $h, hash('sha256', $jpg)]);
} catch (Throwable $ex) {
    error_log('foto.php: ' . $ex->getMessage());
    responder(503, ['ok' => false, 'motivo' => 'no se pudo registrar la foto; queda en el celular y se reintenta']);
}

responder(200, ['ok' => true, 'foto_uuid' => $foto, 'bytes' => strlen($jpg), 'ancho' => $w, 'alto' => $h]);
