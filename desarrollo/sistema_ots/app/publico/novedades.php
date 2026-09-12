<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';

/**
 * novedades.php — "¿Cambió algo en el buzón?" Nada más que eso.
 *
 * La pantalla del buzón lo consulta cada 30 segundos. Por eso tiene que ser
 * barato: lee un resumen de ~200 bytes que dejó `sync_casos.php`, no el
 * catálogo de 1 MB. Con cuatro personas mirando la pantalla serían decenas de
 * lecturas completas por minuto para contestar casi siempre "no cambió nada".
 *
 * SI NO HAY RESUMEN, no se parsea el catálogo entero como consuelo: se usa la
 * fecha de modificación del archivo, que alcanza para saber que cambió. Es el
 * caso de cuando alguien sube el JSON a mano en vez de que lo empuje la
 * estación.
 *
 * PIDE SESION Y PERMISO igual que el buzón. Un endpoint que dice cuántos casos
 * hay por zona es poco, pero es información del cliente y no tiene por qué
 * estar abierta. Y **respeta el alcance**: al jefe de zona le contesta con el
 * número de su zona, no con el de la empresa.
 *
 * NO deja traza en la bitácora. Se llama cada 30 segundos por persona: anotarlo
 * llenaría la tabla de ruido y enterraría las consultas que sí importan.
 */

$u = Auth::exigir('casos.ver', true);          // true -> responde JSON si no hay sesión

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const CATALOGO = __DIR__ . '/catalogos/casos_sap.json';
const RESUMEN  = __DIR__ . '/catalogos/casos_resumen.json';

$zona = Auth::zonaAlcance();
$rol  = (string) $u['rol'];

/* Al técnico no se le cuenta el buzón —ese volumen es de la empresa y no se le
   informa por una vía lateral— sino LO SUYO (T2.13.5): cuántos avisos tiene
   desde la última vez que abrió su bandeja. La barra le ofrece ir a verlos. */
if ($rol === 'TECNICO') {
    require_once __DIR__ . '/nucleo/Avisos.php';
    $uid   = (int) $u['usuario_id'];
    $visto = Avisos::visto($uid) ?? date('Y-m-d H:i:s', time() - 7 * 86400);
    $ev    = Avisos::delTecnico($uid, $visto);
    echo json_encode([
        'hay'     => true,
        // Cambia cuando le llega un aviso nuevo y cuando abre la bandeja.
        'version' => (int) strtotime($ev ? $ev[0]['cuando'] : $visto),
        'avisos'  => count($ev),
        'ir'      => 'mis.php?t=avisos',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$mtime = is_file(CATALOGO) ? (int) filemtime(CATALOGO) : 0;
if ($mtime === 0) {
    echo json_encode(['hay' => false, 'motivo' => 'sin catálogo']);
    exit;
}

$r = is_file(RESUMEN) ? json_decode((string) file_get_contents(RESUMEN), true) : null;
$total = null;
if (is_array($r)) {
    if ($zona === null) {
        $total = (int) ($r['total'] ?? 0);
    } else {
        $total = (int) ($r['por_zona'][$zona] ?? 0);
    }
}

echo json_encode([
    'hay'      => true,
    // `version` es lo único que la pantalla compara. Cambia cuando cambia el
    // archivo, venga de la estación o de una subida a mano.
    'version'  => $mtime,
    'generado' => is_array($r) ? ($r['generado'] ?? null) : null,
    'recibido' => is_array($r) ? ($r['recibido'] ?? null) : null,
    'total'    => $total,
], JSON_UNESCAPED_UNICODE);
