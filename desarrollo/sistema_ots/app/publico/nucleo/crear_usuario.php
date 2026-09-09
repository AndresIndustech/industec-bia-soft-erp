<?php
declare(strict_types=1);

/**
 * crear_usuario.php — Alta de usuarios desde la línea de comandos.
 *
 * La clave NO se elige aquí ni se escribe en ningún archivo: se genera al azar,
 * se muestra UNA sola vez y se guarda solo su hash. El usuario está obligado a
 * cambiarla al primer ingreso.
 *
 * Se usa para el arranque —crear los dos superadministradores— y como respaldo
 * si alguien queda fuera. Del día a día se encarga la pantalla de usuarios.
 *
 * Uso:
 *   php crear_usuario.php <usuario> <rol> "<nombre completo>" [zona] [correo]
 *
 *   php crear_usuario.php cbasantes SUPERADMIN "Cesar Basantes" - cesar@industec.me
 *   php crear_usuario.php abasantes SUPERADMIN "Andres Basantes" - andres@industech.me
 *   php crear_usuario.php admin     ADMIN      "Administracion INDUSTEC"
 *   php crear_usuario.php jefe.uio  JEFE_ZONA  "Jefe de zona UIO" UIO
 */

require_once __DIR__ . '/Db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por línea de comandos.\n");
}

$ROLES = ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO'];
$ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];

$usuario = $argv[1] ?? null;
$rol     = strtoupper($argv[2] ?? '');
$nombre  = $argv[3] ?? null;
$zona    = ($argv[4] ?? '-') === '-' ? null : strtoupper($argv[4]);
$correo  = $argv[5] ?? null;

if (!$usuario || !$nombre || !in_array($rol, $ROLES, true)) {
    exit("Uso: php crear_usuario.php <usuario> <" . implode('|', $ROLES) . "> \"<nombre>\" [zona] [correo]\n");
}
if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) {
    exit("El usuario solo admite minúsculas, números, punto, guion y guion bajo (3-40).\n");
}
if ($zona !== null && !in_array($zona, $ZONAS, true)) {
    exit("Zona inválida. Debe ser una de: " . implode(', ', $ZONAS) . "\n");
}
// El alcance es obligatorio justamente donde limita: un jefe de zona sin zona
// vería todo, que es lo contrario de lo que el rol significa.
if (in_array($rol, ['JEFE_ZONA', 'TECNICO'], true) && $zona === null) {
    exit("Un $rol necesita zona: es su alcance. Sin ella vería las tres.\n");
}
if (in_array($rol, ['SUPERADMIN', 'ADMIN'], true) && $zona !== null) {
    exit("Un $rol no lleva zona: ve las tres. Pon '-' en ese argumento.\n");
}

if (Db::uno('SELECT usuario_id FROM usuarios WHERE usuario = ?', [$usuario])) {
    exit("Ya existe el usuario '$usuario'. No se toca.\n");
}

/** Clave legible pero no adivinable: 4 bloques de 4, sin caracteres ambiguos. */
function claveTemporal(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // sin I, O, 0, 1
    $out = [];
    for ($b = 0; $b < 4; $b++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) {
            $s .= $abc[random_int(0, strlen($abc) - 1)];
        }
        $out[] = $s;
    }
    return implode('-', $out);
}

$clave = claveTemporal();
Db::ejecutar(
    'INSERT INTO usuarios (usuario, nombre, correo, clave_hash, rol, zona, debe_cambiar_clave)
     VALUES (?,?,?,?,?,?,1)',
    [$usuario, $nombre, $correo, password_hash($clave, PASSWORD_DEFAULT), $rol, $zona]
);

$linea = str_repeat('=', 62);
echo "$linea\n";
echo "  Usuario creado\n";
echo "$linea\n";
echo "  usuario  : $usuario\n";
echo "  nombre   : $nombre\n";
echo "  rol      : $rol" . ($zona ? "  (zona $zona)" : "  (las tres zonas)") . "\n";
echo "  clave    : $clave\n";
echo "$linea\n";
echo "  Esta clave NO se vuelve a mostrar y no queda guardada en ningún lado:\n";
echo "  la base solo tiene su hash. Entrégasela por un canal aparte y el\n";
echo "  sistema le va a exigir cambiarla en el primer ingreso.\n";
echo "$linea\n";
