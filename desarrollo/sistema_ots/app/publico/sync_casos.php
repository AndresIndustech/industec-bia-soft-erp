<?php
declare(strict_types=1);

/**
 * sync_casos.php — Recibe el catálogo de casos que empuja la estación.
 *
 * ES EL UNICO ENDPOINT DEL SISTEMA SIN SESION, y por eso es el que más cuidado
 * lleva. No puede tener sesión: quien llama es un proceso de la estación, no
 * una persona con un navegador.
 *
 * QUE LO PROTEGE
 *
 * 1. FIRMA HMAC-SHA256 sobre `timestamp.cuerpo`, con un secreto compartido que
 *    vive en `nucleo/config.php` (fuera de git, fuera del alcance web). Sin el
 *    secreto no se puede fabricar un envío válido.
 *
 * 2. LA MARCA DE TIEMPO VA DENTRO DE LO FIRMADO y se acepta solo ±5 minutos.
 *    Sin eso, quien grabe un POST legítimo podría repetirlo mañana y revertir
 *    el buzón a un estado viejo — la administradora vería casos ya resueltos
 *    como pendientes y dejaría de ver los nuevos. No hace falta romper nada:
 *    basta con repetir algo que ya era válido.
 *
 * 3. `hash_equals` para comparar. Un `===` sobre cadenas se corta en el primer
 *    byte distinto, y ese tiempo de más deja adivinar la firma byte a byte.
 *
 * 4. ESCRIBE EN UNA RUTA FIJA. No toma el nombre del archivo de la petición.
 *    Esto no es una subida de archivos: es un solo destino, decidido aquí.
 *
 * 5. VALIDA QUE SEA EL CATALOGO ESPERADO antes de reemplazar nada. Un JSON bien
 *    firmado pero con la forma equivocada dejaría el buzón vacío.
 *
 * 6. ESCRITURA ATOMICA (temporal + rename). Si se corta a la mitad, el archivo
 *    viejo sigue entero. Escribir directo sobre el destino puede dejar medio
 *    JSON, y ahí el buzón no muestra nada.
 *
 * NO REGISTRA EN BITACORA por usuario porque no hay usuario. Deja su propio
 * registro en `catalogos/sync.log`, que es lo que se mira cuando alguien
 * pregunta por qué el buzón está desactualizado.
 */

const MAX_BYTES   = 12 * 1024 * 1024;   // el catálogo pesa ~1 MB; 12 sobra
const TOLERANCIA  = 300;                // ±5 min de diferencia de reloj
const DESTINO     = __DIR__ . '/catalogos/casos_sap.json';
const REGISTRO    = __DIR__ . '/catalogos/sync.log';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

/** Contesta y corta. El motivo se guarda entero; al cliente se le dice poco. */
function fin(int $codigo, string $publico, string $interno = ''): never
{
    @file_put_contents(
        REGISTRO,
        sprintf("%s  %s  %d  %s%s\n", date('Y-m-d H:i:s'),
                $_SERVER['REMOTE_ADDR'] ?? '?', $codigo, $publico,
                $interno !== '' ? " ($interno)" : ''),
        FILE_APPEND | LOCK_EX
    );
    http_response_code($codigo);
    exit($publico . "\n");
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fin(405, 'solo POST');
}

$cfg = require __DIR__ . '/nucleo/config.php';
$secreto = (string) ($cfg['sync_secreto'] ?? '');
if (strlen($secreto) < 32) {
    // Preferimos no funcionar antes que funcionar sin protección de verdad.
    fin(500, 'endpoint sin configurar', 'falta sync_secreto o es muy corto');
}

$ts    = (string) ($_SERVER['HTTP_X_INDUSTEC_TS'] ?? '');
$firma = (string) ($_SERVER['HTTP_X_INDUSTEC_FIRMA'] ?? '');
if ($ts === '' || $firma === '') {
    fin(401, 'falta la firma');
}
if (!ctype_digit($ts) || abs(time() - (int) $ts) > TOLERANCIA) {
    // También salta si el reloj de la estación se desfasó: es un aviso real.
    fin(401, 'marca de tiempo fuera de rango', "ts=$ts servidor=" . time());
}

$cuerpo = (string) file_get_contents('php://input', false, null, 0, MAX_BYTES + 1);
if ($cuerpo === '') {
    fin(400, 'cuerpo vacío');
}
if (strlen($cuerpo) > MAX_BYTES) {
    fin(413, 'demasiado grande');
}

$esperada = hash_hmac('sha256', $ts . '.' . $cuerpo, $secreto);
if (!hash_equals($esperada, $firma)) {
    fin(401, 'firma inválida');
}

/* --- A partir de aquí el envío es auténtico. Falta que tenga sentido. ----- */
$j = json_decode($cuerpo, true);
if (!is_array($j)) {
    fin(400, 'no es JSON válido');
}
if (!isset($j['datos']) || !is_array($j['datos'])) {
    fin(422, 'no trae la lista de casos');
}
if (!isset($j['generado'])) {
    fin(422, 'no trae la fecha de generación');
}
foreach (['aviso', 'zona', 'estado_alerta'] as $campo) {
    if ($j['datos'] !== [] && !array_key_exists($campo, $j['datos'][0])) {
        fin(422, "el catálogo cambió de forma: falta $campo");
    }
}

/* Un catálogo que llega vacío casi siempre es un fallo del barrido, no que KFC
   cerrara los 918 casos de golpe. Se rechaza para no vaciar el buzón: si de
   verdad hay que dejarlo en cero, se sube a mano. */
$antes = is_file(DESTINO) ? json_decode((string) file_get_contents(DESTINO), true) : null;
$nAntes = is_array($antes) ? count($antes['datos'] ?? []) : 0;
$nAhora = count($j['datos']);
if ($nAhora === 0 && $nAntes > 0) {
    fin(409, 'llegó vacío y había ' . $nAntes . ': no se reemplaza');
}

if (!is_dir(dirname(DESTINO)) && !@mkdir(dirname(DESTINO), 0755, true)) {
    fin(500, 'no se pudo crear la carpeta de catálogos');
}

// Atómico: se escribe al lado y se renombra. `rename` en el mismo sistema de
// archivos es una sola operación; nadie llega a ver un JSON a medio escribir.
$tmp = DESTINO . '.tmp' . bin2hex(random_bytes(4));
if (@file_put_contents($tmp, $cuerpo) !== strlen($cuerpo) || !@rename($tmp, DESTINO)) {
    @unlink($tmp);
    fin(500, 'no se pudo guardar');
}

/* Resumen diminuto al lado del catálogo.
   La pantalla del buzón pregunta cada 30 segundos si hay novedades. Si para
   contestar hubiera que abrir y parsear el JSON de 1 MB, serían decenas de
   lecturas completas por minuto en un hosting compartido, para responder casi
   siempre "no cambió nada". Con esto la respuesta cuesta leer 200 bytes. */
$porZona = [];
foreach ($j['datos'] as $c) {
    $z = $c['zona'] ?: 'SIN_ZONA';
    $porZona[$z] = ($porZona[$z] ?? 0) + 1;
}
$desde7 = date('Y-m-d', strtotime('-7 days'));
$resumen = [
    'generado'  => $j['generado'],
    'recibido'  => date('c'),
    'total'     => $nAhora,
    'anterior'  => $nAntes,
    'por_zona'  => $porZona,
    'con_alerta'=> count(array_filter($j['datos'], fn($c) => ($c['estado_alerta'] ?? '') === 'CON_ALERTA')),
    'semana'    => count(array_filter($j['datos'], fn($c) => ($c['fecha_creacion'] ?? '') >= $desde7)),
];
$tmp2 = dirname(DESTINO) . '/casos_resumen.json.tmp' . bin2hex(random_bytes(4));
if (@file_put_contents($tmp2, json_encode($resumen, JSON_UNESCAPED_UNICODE)) !== false) {
    @rename($tmp2, dirname(DESTINO) . '/casos_resumen.json');
} else {
    @unlink($tmp2);   // que falle el resumen no invalida el catálogo, que ya está
}

fin(200, "ok casos=$nAhora antes=$nAntes generado=" . substr((string) $j['generado'], 0, 10));
