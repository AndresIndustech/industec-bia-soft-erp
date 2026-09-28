<?php
declare(strict_types=1);

/**
 * router_franja_piloto.php — Router del servidor embebido de PHP para
 * `prueba_franja_piloto.mjs`. NO toca ninguna base: `yo.php` se contesta aquí
 * con el modo que pide la prueba y el resto de los .php devuelve 401 (sin
 * sesión), que es lo que la app ya sabe manejar.
 *
 *   FRANJA_MODO=PRUEBA|PRODUCCION   lo que dice yo.php
 *   FRANJA_VOC_VIEJO=1              sirve un vocabulario_publico.json SIN la
 *                                   clave OT_PILOTO (el de antes del 28-sep)
 */
$ruta = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$pub  = realpath(__DIR__ . '/../publico');

if ($ruta === '/yo.php') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    echo json_encode([
        'ok' => true, 'id' => 900, 'usuario' => 'prueba.franja', 'nombre' => 'Prueba Franja',
        'rol' => 'TECNICO', 'zona' => 'UIO', 'csrf' => 'x',
        'puede' => ['crear' => true, 'pendiente' => true, 'novedad' => true],
        'emision_modo' => getenv('FRANJA_MODO') ?: 'PRUEBA',
    ], JSON_UNESCAPED_UNICODE);
    return true;
}
if ($ruta === '/catalogos.php') {
    // Un catálogo mínimo y sintético: con 401 la app manda al ingreso y la
    // prueba terminaría mirando login.php.
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    echo json_encode([
        'locales'  => [['codigo' => 'Z999EC', 'nombre' => 'Local de prueba', 'zona' => 'UIO']],
        'tecnicos' => [], 'tipos' => ['CORRECTIVO', 'PREVENTIVO'], 'equipos' => new stdClass(),
        'avisos'   => ['datos' => [], 'cobertura' => null],
    ], JSON_UNESCAPED_UNICODE);
    return true;
}
if ($ruta === '/vocabulario_publico.json' && getenv('FRANJA_VOC_VIEJO') === '1') {
    $d = json_decode((string) file_get_contents($pub . '/vocabulario_publico.json'), true);
    unset($d['conceptos']['OT_PILOTO']);
    $d['version'] = '2026-09-27.2';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    return true;
}
if (str_ends_with($ruta, '.php')) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"motivo":"sin sesión"}';
    return true;
}
return false;   // lo estático, tal cual desde publico/
