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
 * Uso:  php aplicar_sql.php ruta/al/archivo.sql
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$archivo = $argv[1] ?? '';
if ($archivo === '' || !is_file($archivo)) {
    fwrite(STDERR, "Uso: php aplicar_sql.php <archivo.sql>\n");
    exit(1);
}

$cfg = require __DIR__ . '/nucleo/config.php';
$db = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['db_host'], $cfg['db_port'], $cfg['db_name']),
    $cfg['db_user'], $cfg['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
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

echo basename($archivo) . ': ' . count($sentencias) . " sentencias\n";
foreach ($sentencias as $i => $s) {
    $db->exec($s);
    echo '  ' . ($i + 1) . '. ' . strtok(preg_replace('/\s+/', ' ', $s), ' ') . ' ok' . "\n";
}
echo "aplicado\n";
