<?php
declare(strict_types=1);

/**
 * aplicar_sql.php — Corre una migración en el servidor, desde la línea de órdenes.
 *
 * POR QUE EXISTE
 * Aplicar SQL metiéndolo dentro de un `ssh ... php -r '...'` obliga a anidar
 * comillas de bash, de PHP y de SQL a la vez, y ahí se rompe todo o —peor— se
 * escapa mal y se ejecuta algo distinto de lo que se escribió. Se sube el
 * archivo .sql tal cual y lo corre esto.
 *
 * SOLO CLI. Por web devuelve 404: es una herramienta de mantenimiento, no una
 * pantalla, y desde luego no un endpoint que ejecute SQL.
 *
 * NO PARTE POR `;` A CIEGAS. Un punto y coma dentro de un comentario o de una
 * cadena partiría la sentencia por la mitad y dejaría el esquema a medio migrar,
 * que es peor que no migrarlo. Se quitan primero los comentarios de línea y
 * luego se parte, comprobando que cada trozo empiece por una palabra esperada.
 *
 * LLEVA EL LIBRO. Desde la 009 existe la tabla `migraciones`: al terminar se
 * anota el archivo con su huella, y un archivo ya anotado no se vuelve a aplicar
 * salvo con --forzar (las 004, 005 y 006 no eran idempotentes y un segundo pase
 * fallaba con error 1060 sin que nadie supiera por la base qué se había aplicado).
 *
 * Uso:  php aplicar_sql.php ruta/al/archivo.sql [--forzar]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$args    = array_slice($argv, 1);
$forzar  = in_array('--forzar', $args, true);
$archivo = (string) (array_values(array_filter($args, static fn($a) => $a !== '--forzar'))[0] ?? '');
if ($archivo === '' || !is_file($archivo)) {
    fwrite(STDERR, "Uso: php aplicar_sql.php <archivo.sql> [--forzar]\n");
    exit(1);
}

$cfg = require __DIR__ . '/nucleo/config.php';
date_default_timezone_set('America/Guayaquil');
$db = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['db_host'], $cfg['db_port'], $cfg['db_name']),
    $cfg['db_user'], $cfg['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     // La misma sesión que Db.php: sin la zona, lo que una migración siembre
     // con NOW() o CURRENT_TIMESTAMP quedaría en UTC y el resto en hora de Ecuador.
     PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '-05:00'"]
);

$sql = (string) file_get_contents($archivo);
$sinComentarios = implode("\n", array_filter(
    explode("\n", $sql),
    static fn($l) => !preg_match('/^\s*--/', $l)
));

/**
 * Parte por `;`, pero solo por los que estan FUERA de una cadena.
 *
 * Con un `explode(';', ...)` a secas, un COMMENT como
 *     'ATENDIDO lo pone el sistema; RESUELTO lo pone la administradora'
 * corta la sentencia por la mitad. Paso de verdad al aplicar la 004: la
 * compuerta de mas abajo lo detuvo antes de ejecutar el pedazo suelto, pero la
 * migracion no se aplico. Aqui se recorre carácter a carácter llevando la
 * cuenta de si estamos dentro de comillas.
 */
function partirSentencias(string $sql): array
{
    $fuera = [];
    $actual = '';
    $enCadena = false;
    $largo = strlen($sql);
    for ($i = 0; $i < $largo; $i++) {
        $ch = $sql[$i];
        if ($enCadena) {
            $actual .= $ch;
            // '' dentro de una cadena es una comilla escapada, no el final.
            if ($ch === "'" && ($sql[$i + 1] ?? '') === "'") { $actual .= $sql[++$i]; continue; }
            if ($ch === "'") { $enCadena = false; }
            continue;
        }
        if ($ch === "'") { $enCadena = true; $actual .= $ch; continue; }
        if ($ch === ';') { $fuera[] = $actual; $actual = ''; continue; }
        $actual .= $ch;
    }
    $fuera[] = $actual;
    return array_values(array_filter(array_map('trim', $fuera)));
}

$sentencias = partirSentencias($sinComentarios);
$esperadas = '/^(CREATE|ALTER|INSERT|UPDATE|DROP|SET|USE)\b/i';

foreach ($sentencias as $i => $s) {
    if (!preg_match($esperadas, $s)) {
        fwrite(STDERR, "ABORTADO: la sentencia " . ($i + 1) . " no empieza por una palabra esperada.\n");
        fwrite(STDERR, "  " . substr($s, 0, 120) . "\n");
        exit(1);
    }
}

/* El libro de migraciones. Antes de la 009 la tabla no existe: entonces no hay
   nada que consultar y la propia 009 la crea en su primera sentencia. */
$nombre = basename($archivo);
$huella = hash_file('sha256', $archivo);
$hayLibro = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migraciones'")->fetchColumn() > 0;
if ($hayLibro) {
    $st = $db->prepare('SELECT sha256, aplicada_en FROM migraciones WHERE archivo = ?');
    $st->execute([$nombre]);
    $previa = $st->fetch();
    if ($previa && !$forzar) {
        echo "$nombre ya está aplicada (el {$previa['aplicada_en']}"
           . ($previa['sha256'] !== '' && $previa['sha256'] !== $huella ? ', con OTRO contenido' : '')
           . "). Nada que hacer; --forzar para repetirla.\n";
        exit(0);
    }
}

echo $nombre . ': ' . count($sentencias) . " sentencias\n";
foreach ($sentencias as $i => $s) {
    $db->exec($s);
    echo '  ' . ($i + 1) . '. ' . strtok(preg_replace('/\s+/', ' ', $s), ' ') . ' ok' . "\n";
}

$hayLibro = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migraciones'")->fetchColumn() > 0;
if ($hayLibro) {
    $db->prepare('INSERT INTO migraciones (archivo, sha256, aplicada_por) VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE sha256 = VALUES(sha256), aplicada_en = NOW(), aplicada_por = VALUES(aplicada_por)')
       ->execute([$nombre, $huella, 'aplicar_sql.php' . ($forzar ? ' --forzar' : '')]);
    echo "anotada en migraciones\n";
}
echo "aplicado\n";
