<?php
declare(strict_types=1);

/**
 * alta_padron_cli.php — El alta del padrón, pero corrida desde la estación.
 *
 * POR QUE EXISTE, SI YA ESTA alta_padron.php
 * Aquella es una pantalla y pide sesión de superadministrador. Las claves de
 * los dos superadministradores se generaron en el servidor y nunca pasaron por
 * un archivo ni por el chat — que es justo lo que se quería—, así que desde la
 * estación no hay forma de iniciar esa sesión. Este script hace lo mismo por la
 * vía de la máquina.
 *
 * APLICA LAS MISMAS REGLAS, no una versión relajada: valida el nombre de
 * usuario, exige zona a técnicos y jefes, se la prohíbe a la administración,
 * salta a quien ya existe y deja `debe_cambiar_clave = 1`. Lo único que cambia
 * es de dónde viene la autorización.
 *
 * LAS CLAVES SALEN POR STDOUT Y NO SE GUARDAN EN NINGUN LADO DEL SERVIDOR.
 * Quien lo invoca las redirige a un archivo en la estación. La base solo
 * guarda el hash, igual que siempre.
 *
 * ES DE UN SOLO USO. Se sube, se corre y se borra. No queda en el servidor:
 * un archivo que crea usuarios no tiene por qué estar en la raíz web, ni
 * siquiera protegido.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);           // por si acaso queda un momento colgado
    exit;
}

$cfg = require __DIR__ . '/nucleo/config.php';
date_default_timezone_set('America/Guayaquil');
$db = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['db_host'], $cfg['db_port'], $cfg['db_name']),
    $cfg['db_user'], $cfg['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES => false,
     // La misma sesión que Db.php: la fecha de alta, en hora de Ecuador.
     PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '-05:00'"]
);

$j = json_decode((string) file_get_contents(__DIR__ . '/nucleo/padron.json'), true);
if (!is_array($j) || !isset($j['personas'])) {
    fwrite(STDERR, "No pude leer nucleo/padron.json\n");
    exit(1);
}

/** Igual que en usuarios.php: sin I, O, 0, 1, que se confunden al dictarla. */
function claveTemporal(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = [];
    for ($b = 0; $b < 4; $b++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) { $s .= $abc[random_int(0, strlen($abc) - 1)]; }
        $out[] = $s;
    }
    return implode('-', $out);
}

$ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];
$autor = $db->query("SELECT usuario_id FROM usuarios WHERE rol='SUPERADMIN' ORDER BY usuario_id LIMIT 1")
            ->fetchColumn();

$antes = (int) $db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
$creadas = $saltadas = 0;

echo "# Claves provisionales del padrón de técnicos\n";
echo "# Generadas el " . date('Y-m-d H:i') . ". La base solo guarda el hash.\n";
echo "# Cada persona debe cambiarla en su primer ingreso.\n#\n";
printf("# %-12s %-6s %-38s %s\n", 'USUARIO', 'ZONA', 'NOMBRE', 'CLAVE');

$existe = $db->prepare('SELECT usuario_id FROM usuarios WHERE usuario = ?');
$alta = $db->prepare(
    'INSERT INTO usuarios (usuario, nombre, clave_hash, rol, zona, debe_cambiar_clave, creado_por)
     VALUES (?,?,?,?,?,1,?)');
$log = $db->prepare(
    'INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia, detalle)
     VALUES (?,?,?,?,?,?)');

foreach ($j['personas'] as $p) {
    $usuario = strtolower(trim((string) ($p['usuario'] ?? '')));
    $nombre  = trim((string) ($p['nombre'] ?? ''));
    $rol     = (string) ($p['rol'] ?? '');
    $zona    = ($p['zona'] ?? null) ?: null;

    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario) || $nombre === '') {
        fwrite(STDERR, "  se salta {$usuario}: datos incompletos\n"); $saltadas++; continue;
    }
    if (!in_array($rol, ['JEFE_ZONA', 'TECNICO'], true)) {
        // Este script solo da de alta al personal de campo. Un ADMIN o un
        // SUPERADMIN se crea a mano, mirando a quien se le da.
        fwrite(STDERR, "  se salta {$usuario}: rol {$rol} fuera de alcance\n"); $saltadas++; continue;
    }
    if (!in_array($zona, $ZONAS, true)) {
        fwrite(STDERR, "  se salta {$usuario}: sin zona válida\n"); $saltadas++; continue;
    }
    $existe->execute([$usuario]);
    if ($existe->fetchColumn()) {
        fwrite(STDERR, "  se salta {$usuario}: ya existe\n"); $saltadas++; continue;
    }

    $clave = claveTemporal();
    $alta->execute([$usuario, $nombre, password_hash($clave, PASSWORD_DEFAULT), $rol, $zona, $autor ?: null]);
    $log->execute([$autor ?: null, 'estacion', 'CREAR_USUARIO', 'usuario', $usuario,
                   "alta del padron desde la estacion, rol=$rol zona=$zona"]);
    printf("  %-12s %-6s %-38s %s\n", $usuario, $zona, $nombre, $clave);
    $creadas++;
}

$despues = (int) $db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
echo "#\n# creadas: $creadas | saltadas: $saltadas | usuarios: $antes -> $despues\n";
fwrite(STDERR, "creadas $creadas, saltadas $saltadas, usuarios $antes -> $despues\n");
exit($antes + $creadas === $despues ? 0 : 1);
