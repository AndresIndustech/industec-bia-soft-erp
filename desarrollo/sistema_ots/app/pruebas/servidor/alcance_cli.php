<?php
declare(strict_types=1);

/**
 * alcance_cli.php — Lo que ve un usuario, calculado con las MISMAS funciones que
 * usan las pantallas (Casos::enAlcance, Pendientes::lista, Novedades::lista), sin
 * pasar por HTTP. Un proceso por usuario: Auth guarda los permisos en caché.
 *
 *     php ~/respaldos/alcance_cli.php <usuario>     (o ADMIN para la primera administradora activa)
 *
 * Solo lee. Imprime un JSON.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require getcwd() . '/nucleo/Casos.php';
require getcwd() . '/nucleo/Pendientes.php';
require getcwd() . '/nucleo/Novedades.php';

$arg = $argv[1] ?? '';
$u = $arg === 'ADMIN'
    ? Db::uno("SELECT * FROM usuarios WHERE rol = 'ADMIN' AND activo = 1 ORDER BY usuario_id LIMIT 1")
    : Db::uno('SELECT * FROM usuarios WHERE usuario = ?', [$arg]);
if (!$u) { fwrite(STDERR, "No existe el usuario '$arg'.\n"); exit(1); }
$rp = new ReflectionProperty(Auth::class, 'usuario');
$rp->setAccessible(true);
$rp->setValue(null, $u);

$zonas = static function (array $filas, string $campo = 'zona'): array {
    $z = array_count_values(array_map(static fn($f) => (string) ($f[$campo] ?? '(sin)'), $filas));
    ksort($z);
    return $z;
};

$casos = Casos::enAlcance(Casos::catalogo()['datos'] ?? [], Casos::gestion());
$pend  = Pendientes::lista(['grupo' => 'abiertos']);
$nov   = Novedades::lista([]);

echo json_encode([
    'usuario'      => $u['usuario'],
    'rol'          => $u['rol'],
    'zona'         => $u['zona'],
    'zona_alcance' => Auth::zonaAlcance(),
    'casos'        => count($casos),
    'casos_zonas'  => $zonas($casos),
    'casos_avisos' => $u['rol'] === 'TECNICO' ? array_values(array_column($casos, 'aviso')) : null,
    'pendientes'   => count($pend),
    'pend_ids'     => array_values(array_map('intval', array_column($pend, 'pendiente_id'))),
    'pend_contad'  => Pendientes::contadores(),
    'novedades'    => count($nov),
    'nov_ids'      => array_values(array_map('intval', array_column($nov, 'novedad_id'))),
], JSON_UNESCAPED_UNICODE), "\n";
