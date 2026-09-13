<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Ui.php';

/**
 * documento.php — Entrega un archivo del aprendizaje, con sesión y bitácora.
 *
 * Los archivos de `documentos/` no se sirven como archivos sueltos (el
 * .htaccess niega todo): un manual de fabricante o un comunicado interno no
 * tienen por qué estar en una URL adivinable. Salen solo por aquí:
 *
 *   ?v=<version_id>        esa versión, si está aprobada — o, sin aprobar, solo
 *                          para quien aprueba o para quien la subió
 *   ?d=<doc_id>            la última versión aprobada del documento
 *   &dl=1                  para guardar (attachment) en vez de abrir
 *
 * Cada apertura y cada descarga quedan en la bitácora (ABRIR_DOCUMENTO /
 * DESCARGAR_DOCUMENTO), igual que las órdenes: el archivo es de aprendizaje,
 * la trazabilidad es la misma.
 */

$u = Auth::exigir();
if (!Ui::puedeModulo('documentos.ver', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO'], $u)) {
    Auth::bitacora('DENEGADO', 'documento', '', 'sin permiso', null, null, [], false);
    http_response_code(403);
    exit('No tienes acceso a los documentos.');
}

$vid = (int) ($_GET['v'] ?? 0);
$did = (int) ($_GET['d'] ?? 0);
$descarga = ($_GET['dl'] ?? '') === '1';

try {
    if ($vid > 0) {
        $v = Db::uno('SELECT v.*, d.titulo, d.tipo FROM documento_versiones v JOIN documentos d ON d.doc_id = v.doc_id WHERE v.version_id = ?', [$vid]);
    } elseif ($did > 0) {
        $v = Db::uno('SELECT v.*, d.titulo, d.tipo FROM documento_versiones v JOIN documentos d ON d.doc_id = v.doc_id
                       WHERE v.doc_id = ? AND v.estado = "APROBADA" ORDER BY v.version DESC LIMIT 1', [$did]);
    } else {
        $v = null;
    }
} catch (Throwable $ex) {
    $v = null;
}
if (!$v) {
    Auth::bitacora('ABRIR_DOCUMENTO', 'documento', (string) ($vid ?: $did), 'no existe', null, null, [], false);
    http_response_code(404);
    exit('Ese documento no existe.');
}

$puedeAprobar = Ui::puedeModulo('documentos.aprobar', ['SUPERADMIN', 'ADMIN'], $u);
if ($v['estado'] !== 'APROBADA' && !$puedeAprobar && (int) $v['subida_por'] !== (int) $u['usuario_id']) {
    // Lo que no está aprobado lo ve quien aprueba y quien lo subió; nadie más.
    Auth::bitacora('DENEGADO', 'documento', (string) $v['doc_id'], 'versión ' . (int) $v['version'] . ' sin aprobar',
                   null, null, ['version_id' => (int) $v['version_id'], 'estado' => $v['estado']], false);
    http_response_code(403);
    exit('Ese documento todavía no está aprobado.');
}

$ruta = __DIR__ . '/documentos/' . basename((string) $v['ruta']);
if (!is_file($ruta)) {
    Auth::bitacora('ABRIR_DOCUMENTO', 'documento', (string) $v['doc_id'], 'el archivo no está en el servidor',
                   null, null, ['version_id' => (int) $v['version_id']], false);
    http_response_code(404);
    exit('El archivo de ese documento no está en el servidor.');
}

Auth::bitacora($descarga ? 'DESCARGAR_DOCUMENTO' : 'ABRIR_DOCUMENTO', 'documento', (string) $v['doc_id'],
               $v['titulo'] . ' v' . (int) $v['version'], null, null,
               ['version_id' => (int) $v['version_id'], 'estado' => $v['estado'], 'dl' => $descarga]);

$mime = (string) $v['mime'];
$inline = !$descarga && in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'video/mp4'], true);
$nombre = preg_replace('/[^\w .\-()]+/u', '_', (string) $v['nombre_archivo']) ?: 'documento';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($ruta);
