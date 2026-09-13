<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Emision.php';

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
 * QUIEN PUEDE ABRIR QUE — la regla del 2026-09-12 (D1)
 * Andrés decidió ese día que el archivo de órdenes de TODAS las zonas se consulta,
 * se comparte y se descarga por todo el personal, como hoy en Google Drive, en
 * solo lectura. Hasta entonces este archivo cortaba por técnico y por zona, y
 * con eso el técnico no podía abrir las órdenes previas de sus propios casos:
 * 76 de los 87 casos abiertos tenían una orden que su técnico recibía con 403
 * «Esa orden no es tuya» (medido el 2026-09-12). El corte por zona sigue rigiendo
 * las ACCIONES (asignar, veredicto, cerrar); la LECTURA es de quien tenga el
 * permiso `ots.archivo`, que la 009 da a los cuatro roles. El control
 * compensatorio es la bitácora: cada apertura y cada descarga quedan con quién,
 * cuándo, desde dónde y por qué vía.
 *
 * DOS FORMAS DE ENTRAR, Y LAS DOS DEJAN RASTRO
 *
 *   1. Con sesión. Se comprueba el permiso `ots.pdf`; con `ots.archivo` se abre
 *      cualquier orden válida; sin él rige el alcance viejo (técnico: lo suyo;
 *      jefe: su zona). `?dl=1` la entrega para guardar y se registra distinto.
 *
 *   2. Con un enlace firmado y con caducidad, para mandárselo al administrador
 *      del local por correo o WhatsApp. El destinatario no tiene usuario en el
 *      sistema y no va a tenerlo. El enlace lleva HMAC sobre (orden, caducidad)
 *      con el secreto de la aplicación: no se puede fabricar, no se puede
 *      adivinar probando números, y deja de servir solo. Un enlace inválido o
 *      caducado también deja fila: un reenvío masivo de enlaces viejos tiene que
 *      verse en `minar.php`.
 *
 * LA CADUCIDAD NO ES UN ADORNO. Un enlace sin fecha de muerte reenviado por
 * WhatsApp sigue abriendo el documento dentro de dos años, en un teléfono que ya
 * no es de quien lo recibió. Y el servidor no acepta caducidades de más de siete
 * días aunque vengan firmadas.
 *
 * NO ADIVINA EL NOMBRE DEL ARCHIVO A PARTIR DE LA URL. El identificador se
 * valida contra el patrón canónico de OT, se lleva a mayúsculas (el disco de
 * Linux distingue `ot-…` de `OT-…`) y luego se busca en el directorio: sin eso,
 * un `../` en el parámetro sirve cualquier archivo del servidor.
 */

const DIR_PDF = __DIR__ . '/ordenes_pdf';
// Cuánto vive un enlace compartido. Un día alcanza para que lo abran y es
// suficientemente corto para que no sobreviva al hilo de WhatsApp.
const HORAS_ENLACE = 24;
// Tope absoluto: aunque el enlace venga bien firmado, no se aceptan caducidades
// más lejanas. Es lo que acota el daño de un secreto filtrado.
const HORAS_ENLACE_MAX = 24 * 7;
// Los enlaces firmados antes del 2026-09-13 llevaban la firma vieja (sin quién
// los compartió). Se aceptan hasta que el último de ellos caduque; después, no.
const ENLACES_VIEJOS_HASTA = '2026-09-15 00:00:00';

/** El patrón canónico de nombre de OT, en sus cuatro formas más la zona OTRA. */
function otValida(string $ot): bool
{
    return (bool) preg_match(Emision::PATRON_OT, strtoupper(trim($ot)));
}

/**
 * La firma del enlace (SEG-02, SEG-13): con prefijo de dominio («pdf|»), para
 * que el mismo secreto no firme otra cosa, y con quién lo compartió, para que
 * la bitácora de la apertura diga de qué mano salió el enlace.
 */
function firmaEnlace(string $ot, int $exp, string $secreto, int $uid = 0): string
{
    return hash_hmac('sha256', 'pdf|' . $ot . '|' . $exp . '|' . $uid, $secreto);
}

/** La firma anterior al 2026-09-13, solo para los enlaces que ya circulaban. */
function firmaEnlaceVieja(string $ot, int $exp, string $secreto): string
{
    return hash_hmac('sha256', $ot . '.' . $exp, $secreto);
}

/** Arma el enlace que se manda por fuera. Lo usa ordenes.php al pulsar «Compartir». */
function enlaceCompartido(string $ot, string $secreto, int $uid = 0, int $horas = HORAS_ENLACE): string
{
    $exp = time() + $horas * 3600;
    return 'pdf.php?ot=' . rawurlencode($ot) . '&exp=' . $exp
         . ($uid > 0 ? '&u=' . $uid : '')
         . '&f=' . firmaEnlace($ot, $exp, $secreto, $uid);
}

/** Bitácora de una entrada por enlace, con o sin éxito. No hay sesión: el usuario es «enlace». */
function bitacoraEnlace(string $accion, string $ot, string $detalle, array $datos, bool $exito): void
{
    try {
        Db::ejecutar(
            "INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia,
                                   detalle, datos, exito, ip, equipo)
             VALUES (NULL, 'enlace', ?, 'ot', ?, ?, ?, ?, ?, ?)",
            [$accion, $ot, $detalle, json_encode($datos, JSON_UNESCAPED_UNICODE), $exito ? 1 : 0,
             substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
             substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]
        );
    } catch (Throwable $e) {
        error_log('bitacora enlace: ' . $e->getMessage());
    }
}

// A partir de aquí solo se ejecuta si alguien pidió la página.
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== 'pdf.php') {
    return;                                  // lo incluyó otra pantalla: solo funciones
}

$ot = strtoupper(trim((string) ($_GET['ot'] ?? '')));
if ($ot === '' || !otValida($ot)) {
    http_response_code(400);
    exit('Identificador de orden no válido.');
}

$cfg = Db::config();
// El secreto de los enlaces, separado del de la sincronización cuando existe
// (SEG-13); mientras no se configure, el de siempre, para no romper lo que ya
// se compartió.
$secreto  = (string) ($cfg['enlace_secreto'] ?? $cfg['sync_secreto'] ?? '');
$exp      = (int) ($_GET['exp'] ?? 0);
$firma    = (string) ($_GET['f'] ?? '');
$uidEnlace = (int) ($_GET['u'] ?? 0);
$descarga = ($_GET['dl'] ?? '') === '1';
$porEnlace = false;
$u = null;

if ($firma !== '' && $exp > 0) {
    $valida = $secreto !== '' && hash_equals(firmaEnlace($ot, $exp, $secreto, $uidEnlace), $firma);
    if (!$valida && $secreto !== '' && $uidEnlace === 0 && $exp < (int) strtotime(ENLACES_VIEJOS_HASTA)) {
        // Un enlace de antes del cambio de firma, todavía dentro de su vida.
        $valida = hash_equals(firmaEnlaceVieja($ot, $exp, $secreto), $firma);
    }
    if (!$valida) {
        bitacoraEnlace('DENEGADO', $ot, 'enlace con firma inválida', ['exp' => $exp, 'u' => $uidEnlace], false);
        http_response_code(403);
        exit('Enlace no válido.');
    }
    if ($exp < time()) {
        bitacoraEnlace('DENEGADO', $ot, 'enlace caducado', ['exp' => date('c', $exp)], false);
        http_response_code(410);
        exit('Este enlace caducó. Pídele uno nuevo a INDUSTEC.');
    }
    if ($exp - time() > HORAS_ENLACE_MAX * 3600) {
        bitacoraEnlace('DENEGADO', $ot, 'enlace con caducidad fuera de tope', ['exp' => date('c', $exp)], false);
        http_response_code(403);
        exit('Enlace no válido.');
    }
    $porEnlace = true;
} else {
    $u = Auth::exigir('ots.pdf');
    if ($u['debe_cambiar_clave']) { header('Location: clave.php'); exit; }

    if (Auth::puede('ots.archivo')) {
        // D1: el archivo es de lectura para todos. La bitácora de abajo es el control.
        $mio = true;
    } else {
        // Sin el permiso del archivo rige el alcance viejo: se resuelve por la
        // gestión del caso, no por el nombre del archivo, que lo escribe quien pide.
        $gestion = Casos::gestion();
        $za = Auth::zonaAlcance();
        $zonaOt = preg_match('/-(UIO|LARB|CNLJ|OTRA)$/', $ot, $mz) ? $mz[1] : null;
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
        if (!$encontrada) {
            try {
                $cap = Db::uno('SELECT usuario_id, zona FROM ot_capturadas WHERE id_industec = ?', [$ot]);
            } catch (Throwable $ex) {
                $cap = null;
            }
            if ($cap !== null) {
                $encontrada = true;
                $mio = $u['rol'] === 'TECNICO'
                     ? ((int) $cap['usuario_id'] === (int) $u['usuario_id'])
                     : ($za === null || (($cap['zona'] ?? null) ?: $zonaOt) === $za);
            }
        }
        if (!$encontrada) {
            $mio = $u['rol'] !== 'TECNICO' && ($za === null || $zonaOt === $za);
        }
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
    if ($porEnlace) {
        bitacoraEnlace('ABRIR_PDF', $ot, 'el PDF no está en el servidor',
                       ['exp' => date('c', $exp), 'compartido_por' => $uidEnlace ?: null], false);
    } else {
        Auth::bitacora('ABRIR_PDF', 'ot', $ot, 'el PDF no está en el servidor', null, null, ['ot' => $ot], false);
    }
    http_response_code(404);
    exit('El PDF de esa orden no está en el servidor.');
}

if ($porEnlace) {
    // No hay usuario, pero el acceso se registra igual: es lo que permite
    // responder «quién vio esta firma» si alguien lo pregunta -- y de qué
    // mano salió el enlace (`compartido_por`, SEG-02).
    bitacoraEnlace($descarga ? 'DESCARGAR_PDF' : 'ABRIR_PDF', $ot, 'por enlace compartido',
                   ['exp' => date('c', $exp), 'compartido_por' => $uidEnlace ?: null, 'dl' => $descarga], true);
} else {
    // Ver y descargar se distinguen (SEG-03): una descarga es una copia que sale
    // del sistema, y hay que poder contarlas por persona.
    Auth::bitacora($descarga ? 'DESCARGAR_PDF' : 'ABRIR_PDF', 'ot', $ot,
                   Auth::puede('ots.archivo') ? 'archivo general' : 'alcance propio',
                   null, null, ['ot' => $ot, 'dl' => $descarga]);
}

header('Content-Type: application/pdf');
// `inline` para que se abra en la pestaña, que es lo que se pidió; `attachment`
// cuando piden guardarla. El nombre va igual, para que quede con el
// identificador de la orden.
header('Content-Disposition: ' . ($descarga ? 'attachment' : 'inline') . '; filename="' . $ot . '.pdf"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($ruta);
