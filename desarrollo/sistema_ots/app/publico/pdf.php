<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Casos.php';

/**
 * pdf.php — Entrega el PDF de una orden, con nombre y apellido de quién lo abrió.
 *
 * POR QUE NO SE SIRVEN COMO ARCHIVOS SUELTOS
 * Cada orden lleva el nombre, el correo y la **firma manuscrita** de un empleado
 * de Grupo KFC. Dejarlos colgando de una URL adivinable es exactamente la
 * conducta por la que sancionaron a LigaPro, y es la misma exposición que se
 * cerró en producción el 2026-09-08. Por eso viven fuera del alcance web y solo
 * salen por aquí.
 *
 * DOS FORMAS DE ENTRAR, Y LAS DOS DEJAN RASTRO
 *
 *   1. Con sesión. Se comprueba el permiso `ots.pdf` y el alcance: un técnico
 *      solo abre las suyas, un jefe de zona las de su zona.
 *
 *   2. Con un enlace firmado y con caducidad, para mandárselo al administrador
 *      del local por correo o WhatsApp. El destinatario no tiene usuario en el
 *      sistema y no va a tenerlo. El enlace lleva HMAC sobre (orden, caducidad)
 *      con el secreto de la aplicación: no se puede fabricar, no se puede
 *      adivinar probando números, y deja de servir solo.
 *
 * LA CADUCIDAD NO ES UN ADORNO. Un enlace sin fecha de muerte reenviado por
 * WhatsApp sigue abriendo el documento dentro de dos años, en un teléfono que ya
 * no es de quien lo recibió.
 *
 * NO ADIVINA EL NOMBRE DEL ARCHIVO A PARTIR DE LA URL. El identificador se
 * valida contra el patrón canónico de OT y luego se busca en el directorio: sin
 * eso, un `../` en el parámetro sirve cualquier archivo del servidor.
 */

const DIR_PDF = __DIR__ . '/ordenes_pdf';
// Cuánto vive un enlace compartido. Un día alcanza para que lo abran y es
// suficientemente corto para que no sobreviva al hilo de WhatsApp.
const HORAS_ENLACE = 24;

/** El patrón canónico de nombre de OT, en sus cuatro formas (ver invariantes). */
function otValida(string $ot): bool
{
    return (bool) preg_match(
        '/^OT-\d{3,5}-[A-Z]{1,2}\d{2,4}(EC)?(-\d{6,10})?(-D\d{1,2})?-(UIO|LARB|CNLJ)$/i',
        $ot
    );
}

function firmaEnlace(string $ot, int $exp, string $secreto): string
{
    return hash_hmac('sha256', $ot . '.' . $exp, $secreto);
}

/** Arma el enlace que se manda por fuera. Lo usa ordenes.php. */
function enlaceCompartido(string $ot, string $secreto, int $horas = HORAS_ENLACE): string
{
    $exp = time() + $horas * 3600;
    return 'pdf.php?ot=' . rawurlencode($ot) . '&exp=' . $exp
         . '&f=' . firmaEnlace($ot, $exp, $secreto);
}

// A partir de aquí solo se ejecuta si alguien pidió la página.
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== 'pdf.php') {
    return;                                  // lo incluyó otra pantalla: solo funciones
}

$ot = trim((string) ($_GET['ot'] ?? ''));
if ($ot === '' || !otValida($ot)) {
    http_response_code(400);
    exit('Identificador de orden no válido.');
}

$cfg = Db::config();
$secreto = (string) ($cfg['sync_secreto'] ?? '');
$exp = (int) ($_GET['exp'] ?? 0);
$firma = (string) ($_GET['f'] ?? '');
$porEnlace = false;

if ($firma !== '' && $exp > 0) {
    if ($secreto === '' || !hash_equals(firmaEnlace($ot, $exp, $secreto), $firma)) {
        http_response_code(403);
        exit('Enlace no válido.');
    }
    if ($exp < time()) {
        http_response_code(410);
        exit('Este enlace caducó. Pídele uno nuevo a INDUSTEC.');
    }
    $porEnlace = true;
} else {
    $u = Auth::exigir('ots.pdf');
    if ($u['debe_cambiar_clave']) { header('Location: clave.php'); exit; }

    // El alcance: un técnico solo abre lo suyo. Se resuelve por la gestión del
    // caso, no por el nombre del archivo -- el nombre lo escribe quien pide.
    $gestion = Casos::gestion();
    $za = Auth::zonaAlcance();
    // La zona que lleva el propio identificador (OT-…-UIO), que otValida ya
    // exige: vale cuando la fila de gestión no tiene zona, como las que creó
    // la reconciliación.
    $zonaOt = preg_match('/-(UIO|LARB|CNLJ)$/', $ot, $mz) ? $mz[1] : null;
    $encontrada = false;
    $mio = false;
    foreach ($gestion as $g) {
        if (($g['ot_cierre'] ?? null) === $ot) {
            $encontrada = true;
            $mio = $u['rol'] === 'TECNICO'
                 ? ((int) $g['asignado_a'] === (int) $u['usuario_id'])
                 : ($za === null || (($g['zona'] ?? null) ?: $zonaOt) === $za);
            break;
        }
    }
    /* Una orden que todavía no está en `casos_gestion` la abre la
       administración, y un jefe de zona solo si el identificador es de su
       zona. Al técnico se le niega: no hay forma de comprobar que sea suya.
       Hasta el 2026-09-10 el corte alcanzaba solo al técnico, y un jefe abría
       los PDF de cualquier zona, con la firma del administrador del local. */
    if (!$encontrada) {
        $mio = $u['rol'] !== 'TECNICO' && ($za === null || $zonaOt === $za);
    }
    if (!$mio) {
        Auth::bitacora('DENEGADO', 'ot', $ot, 'PDF fuera de su alcance',
                       null, null, ['ot' => $ot], false);
        http_response_code(403);
        exit($u['rol'] === 'TECNICO' ? 'Esa orden no es tuya.' : 'Esa orden no es de tu zona.');
    }
}

$ruta = DIR_PDF . '/' . $ot . '.pdf';
if (!is_file($ruta)) {
    http_response_code(404);
    exit('El PDF de esa orden no está en el servidor.');
}

if ($porEnlace) {
    // No hay usuario, pero el acceso se registra igual: es lo que permite
    // responder «quién vio esta firma» si alguien lo pregunta.
    Db::ejecutar(
        "INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia,
                               detalle, datos, ip, equipo)
         VALUES (NULL, 'enlace', 'ABRIR_PDF', 'ot', ?, 'por enlace compartido', ?, ?, ?)",
        [$ot, json_encode(['exp' => date('c', $exp)], JSON_UNESCAPED_UNICODE),
         substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
         substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]
    );
} else {
    Auth::bitacora('ABRIR_PDF', 'ot', $ot, null, null, null, ['ot' => $ot]);
}

header('Content-Type: application/pdf');
// `inline` para que se abra en la pestaña, que es lo que se pidió. El nombre va
// igual, para que si lo guardan quede con el identificador de la orden.
header('Content-Disposition: inline; filename="' . $ot . '.pdf"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($ruta);
