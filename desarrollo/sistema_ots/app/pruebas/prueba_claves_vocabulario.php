<?php
declare(strict_types=1);

/**
 * prueba_claves_vocabulario.php — Toda clave que el código le pide al
 * diccionario existe en vocabulario.json.
 *
 * POR QUE EXISTE
 * Las tres puertas del diccionario (Vocabulario:: en PHP, UI.T en JS,
 * termino() en Python) LANZAN una excepción si la clave no existe: nunca
 * inventan texto (I-7). Es lo correcto, pero tiene un precio: una clave mal
 * escrita («ESPERA_INFROME») no la ve ni el lint ni una prueba de lógica, y
 * tumba la pantalla recién cuando alguien la abre en el sitio. En la ola 2
 * (24-sep-2026) cinco personas migraron textos en paralelo a más de 60
 * archivos; esta prueba hace, sin abrir ninguna pantalla, lo que haría abrirlas
 * todas: busca cada llamada con la clave ESCRITA en el código y la cruza con
 * el JSON.
 *
 * Qué mira:
 *   PHP     Vocabulario::t / titulo / ayuda / corto('CLAVE')
 *           Vocabulario::deEstado('ESTADO', 'dominio')  → el dominio existe y,
 *           si el estado va escrito, ese dominio lo conoce.
 *   JS      UI.T('CLAVE'), UI.T.titulo / ayuda / corto('CLAVE'),
 *           UI.T.deEstado(x, 'dominio'), también dentro del <script> de un
 *           .php o .html; y las funciones de una línea que envuelven a
 *           deEstado (cronograma.js: `nombre('vencido', 2)`).
 *   Python  termino / titulo / ayuda / corto / de_estado, solo en los scripts
 *           que las importan de comun.py y no las tapan con una función propia
 *           del mismo nombre (t2_4_publicar_ots.py tiene su `titulo()` de hoja).
 * También los atajos locales: `$V = static fn(string $clave…) => Vocabulario::t($clave…)`
 * de los reportes y `lin = lambda z, clave: …titulo(clave)…` de la estación.
 * Un argumento con alternativas escritas (`cond ? 'A' : 'B'`, `'A' if c else
 * 'B'`, `[... => 'A'][$g]`) se comprueba en cada una. Una clave CALCULADA
 * (`Vocabulario::t($clave)`) no se puede comprobar aquí: se cuenta y se dice.
 *
 * Los comentarios no cuentan: un ejemplo en un comentario no es una llamada.
 *
 * Uso:  php prueba_claves_vocabulario.php            (sale con 1 si algo falla)
 *       php prueba_claves_vocabulario.php --detalle  (lista además cada llamada)
 */

require_once __DIR__ . '/../publico/nucleo/Vocabulario.php';

$detalle = in_array('--detalle', $argv, true);
$DES = realpath(dirname(__DIR__, 3));                 // desarrollo/
$PUB = realpath(dirname(__DIR__) . '/publico');
$PY  = realpath($DES . '/agentes/scripts');

$d = Vocabulario::todo();
$CLAVES = $d['conceptos'];
$MAPAS = Vocabulario::mapas();                        // dominio => [estado => clave]

$fallos = 0;
$total = 0;
function afirmar(string $que, bool $ok, string $detalle = ''): void
{
    global $fallos, $total, $detalleOn, $silencio;
    $total++;
    if (!$ok) { $fallos++; }
    if (!$silencio && (!$ok || $detalleOn)) {
        echo '  ', $ok ? 'ok    ' : 'FALLA ', $que, ($detalle !== '' && !$ok) ? ' · ' . $detalle : '', "\n";
    }
}
$detalleOn = $detalle;
$silencio = false;                                    // la muestra sembrada no imprime sus fallos

/* --- Quitar comentarios, conservando las líneas ---------------------------- */

/** Cambia cada carácter que no sea salto de línea por un espacio. */
function blanco(string $s): string
{
    return preg_replace('/[^\n]/', ' ', $s) ?? $s;
}

/** PHP: fuera los comentarios de PHP con token_get_all. El HTML suelto (y el
 *  <script> que lleve) se deja: ahí están las llamadas a UI.T. */
function sinComentariosPhp(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { $out .= blanco($t[1]); continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

/** JS (y el HTML, que se lee como JS después de tapar <!-- -->): fuera // y
 *  los bloques, respetando las cadenas. Las expresiones regulares no se
 *  distinguen; en el código de hoy ninguna contiene una comilla ni «//». */
function sinComentariosJs(string $src): string
{
    $src = preg_replace_callback('/<!--.*?-->/s', fn($m) => blanco($m[0]), $src) ?? $src;
    $n = strlen($src);
    $out = '';
    $i = 0;
    while ($i < $n) {
        $c = $src[$i];
        $c2 = $src[$i + 1] ?? '';
        if ($c === '/' && $c2 === '/') {
            $j = strpos($src, "\n", $i);
            $j = $j === false ? $n : $j;
            $out .= blanco(substr($src, $i, $j - $i));
            $i = $j;
            continue;
        }
        if ($c === '/' && $c2 === '*') {
            $j = strpos($src, '*/', $i + 2);
            $j = $j === false ? $n : $j + 2;
            $out .= blanco(substr($src, $i, $j - $i));
            $i = $j;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $j = $i + 1;
            while ($j < $n && $src[$j] !== $c) {
                if ($src[$j] === '\\') { $j++; }
                elseif ($src[$j] === "\n" && $c !== '`') { break; }
                $j++;
            }
            $out .= substr($src, $i, $j + 1 - $i);
            $i = $j + 1;
            continue;
        }
        $out .= $c;
        $i++;
    }
    return $out;
}

/** Python: fuera los comentarios «#», respetando las cadenas (también las
 *  triples). Los docstrings se quedan: son cadenas, no llamadas. */
function sinComentariosPy(string $src): string
{
    $n = strlen($src);
    $out = '';
    $i = 0;
    while ($i < $n) {
        $c = $src[$i];
        if ($c === '#') {
            $j = strpos($src, "\n", $i);
            $j = $j === false ? $n : $j;
            $out .= blanco(substr($src, $i, $j - $i));
            $i = $j;
            continue;
        }
        if ($c === "'" || $c === '"') {
            $triple = substr($src, $i, 3) === str_repeat($c, 3);
            $cierre = $triple ? str_repeat($c, 3) : $c;
            $j = $i + strlen($cierre);
            while ($j < $n) {
                if ($src[$j] === '\\') { $j += 2; continue; }
                if (substr($src, $j, strlen($cierre)) === $cierre) { break; }
                if (!$triple && $src[$j] === "\n") { break; }
                $j++;
            }
            $j = min($n, $j + strlen($cierre));
            $out .= substr($src, $i, $j - $i);
            $i = $j;
            continue;
        }
        $out .= $c;
        $i++;
    }
    return $out;
}

/* --- Argumentos de una llamada --------------------------------------------- */

/**
 * Los argumentos de la llamada cuyo «(» está en $ini, separados por las comas
 * de primer nivel. null si el paréntesis no cierra (código a medias).
 *
 * @return list<string>|null
 */
function argumentos(string $src, int $ini): ?array
{
    $n = strlen($src);
    $prof = 0;
    $args = [];
    $buf = '';
    for ($i = $ini + 1; $i < $n; $i++) {
        $c = $src[$i];
        if ($c === "'" || $c === '"' || $c === '`') {
            $j = $i + 1;
            while ($j < $n && $src[$j] !== $c) { if ($src[$j] === '\\') { $j++; } $j++; }
            $buf .= substr($src, $i, $j + 1 - $i);
            $i = $j;
            continue;
        }
        if ($c === '(' || $c === '[' || $c === '{') { $prof++; }
        if ($c === ')' || $c === ']' || $c === '}') {
            if ($prof === 0) { $args[] = trim($buf); return $args; }
            $prof--;
        }
        if ($c === ',' && $prof === 0) { $args[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    return null;
}

/** Si el argumento es una cadena escrita tal cual, su contenido; si no, null. */
function literal(string $arg): ?string
{
    return preg_match('/^[rbuRBU]?([\'"`])([^\'"`\\\\]*)\1$/', $arg, $m) ? $m[2] : null;
}

/**
 * Las claves ESCRITAS de un argumento. Un literal solo es una clave (aunque
 * esté mal escrita: justo lo que se busca). Si no, se miran las alternativas
 * escritas en MAYÚSCULAS (ternario, `??`, arreglo de claves), pero solo cuando
 * el argumento no llama a otra función: `t(deEstado('ASIGNADO'))` lleva un
 * ESTADO, no una clave, y esa llamada interna se revisa por su lado.
 * Tampoco es clave el valor con que se COMPARA la condición del ternario:
 * en `t($ats === 'CERRADA' ? 'OT_CIERRE' : 'OT_EVALUACION')` las claves son
 * las dos ramas; 'CERRADA' es un dato de la base.
 *
 * @return array{0: list<string>, 1: bool}  [claves, ¿se pudo comprobar?]
 */
function clavesDe(string $arg): array
{
    $lit = literal($arg);
    if ($lit !== null) { return [[$lit], true]; }
    if (preg_match('/[\w\]\)]\s*\(/', $arg)) { return [[], false]; }  // llama a otra función
    preg_match_all('/([\'"])([A-Z][A-Z0-9_]*)\1/', $arg, $m, PREG_OFFSET_CAPTURE);
    $claves = [];
    foreach ($m[0] as $k => [$txt, $pos]) {
        $antes = rtrim(substr($arg, 0, $pos));
        $despues = ltrim(substr($arg, $pos + strlen($txt)));
        if (preg_match('/(===|!==|==|!=|<>|\bin|\bnot in)$/', $antes)
            || preg_match('/^(===|!==|==|!=|<>|=>|\bin\b)/', $despues)) {
            continue;                                          // se compara (o es llave de arreglo), no se pide
        }
        $claves[] = $m[2][$k][0];
    }
    return [array_values(array_unique($claves)), $claves !== []];
}

/* --- Comprobación de una llamada ------------------------------------------- */

$calculadas = 0;
$porLengua = ['php' => 0, 'js' => 0, 'py' => 0];

/** Una llamada que recibe una CLAVE de concepto como primer argumento. */
function comprobarClave(string $donde, string $fn, string $arg, string $lengua): void
{
    global $CLAVES, $calculadas, $porLengua;
    [$claves, $escrita] = clavesDe($arg);
    if (!$escrita) { $calculadas++; return; }
    foreach ($claves as $k) {
        $porLengua[$lengua]++;
        afirmar("$donde $fn('$k')", isset($CLAVES[$k]) && is_array($CLAVES[$k]),
                "la clave «{$k}» no existe en vocabulario.json (versión " . Vocabulario::version() . ')');
    }
}

/** Una llamada a deEstado: el dominio debe existir y, si el estado va escrito,
 *  el dominio debe conocerlo (con el mismo recorte y mayúsculas que la API). */
function comprobarEstado(string $donde, string $fn, array $args, string $lengua, string $domPorDefecto = 'caso'): void
{
    global $MAPAS, $calculadas, $porLengua;
    $dom = isset($args[1]) && $args[1] !== '' ? literal($args[1]) : $domPorDefecto;
    if ($dom === null) { $calculadas++; return; }             // dominio calculado ($dom)
    $porLengua[$lengua]++;
    afirmar("$donde $fn(…, '$dom')", isset($MAPAS[$dom]),
            "el dominio «{$dom}» no existe (hay: " . implode(', ', array_keys($MAPAS)) . ')');
    if (!isset($MAPAS[$dom])) { return; }
    $est = literal($args[0] ?? '');
    if ($est === null) { return; }
    $porLengua[$lengua]++;
    $e = strtoupper(trim($est));
    afirmar("$donde $fn('$est', '$dom')", isset($MAPAS[$dom][$e]),
            "el estado «{$e}» no tiene concepto en el dominio «{$dom}»");
}

/** La línea de una posición, para el mensaje. */
function lineaDe(string $src, int $pos): int
{
    return substr_count($src, "\n", 0, $pos) + 1;
}

/* --- Revisión por lengua ---------------------------------------------------- */

function revisarPhp(string $rel, string $src): void
{
    $re = '/Vocabulario::(t|titulo|ayuda|corto|deEstado)\s*\(/';
    if (!preg_match_all($re, $src, $mm, PREG_OFFSET_CAPTURE)) { return; }
    foreach ($mm[0] as $k => [$txt, $pos]) {
        $fn = $mm[1][$k][0];
        $args = argumentos($src, $pos + strlen($txt) - 1);
        $donde = $rel . ':' . lineaDe($src, $pos);
        if ($args === null) { afirmar("$donde Vocabulario::$fn(", false, 'el paréntesis no cierra'); continue; }
        if ($fn === 'deEstado') { comprobarEstado($donde, "Vocabulario::$fn", $args, 'php'); }
        else { comprobarClave($donde, "Vocabulario::$fn", $args[0] ?? '', 'php'); }
    }
    // Atajos locales: los reportes escriben `$V = static fn(string $clave, int $n = 1)
    // => Vocabulario::t($clave, $n);` y luego `$V('ESPERA_INFORME', 2)` decenas de
    // veces. Sin esto, justo la hoja que recibe KFC quedaba sin comprobar.
    $reAlias = '/\$(\w+)\s*=\s*(?:static\s+)?fn\s*\(([^)]*)\)\s*(?::\s*\??\w+\s*)?=>\s*([^;]*);/';
    preg_match_all($reAlias, $src, $defs, PREG_SET_ORDER);
    foreach ($defs as [, $alias, $params, $cuerpo]) {
        preg_match_all('/\$(\w+)/', $params, $pp);
        foreach ($pp[1] as $pos => $param) {
            if (!preg_match('/Vocabulario::(t|titulo|ayuda|corto)\(\s*\$' . $param . '\b/', $cuerpo, $mf)) { continue; }
            revisarAlias($rel, $src, '/(?<![\w])\$' . $alias . '\s*\(/', '$' . $alias, $pos, 'php');
            break;
        }
    }
}

/** Las llamadas a un atajo local ($V, lin…) con la clave en la posición $pos. */
function revisarAlias(string $rel, string $src, string $reLlamada, string $nombre, int $pos, string $lengua): void
{
    if (!preg_match_all($reLlamada, $src, $ll, PREG_OFFSET_CAPTURE)) { return; }
    foreach ($ll[0] as [$txt, $at]) {
        $args = argumentos($src, $at + strlen($txt) - 1);
        if ($args === null || !isset($args[$pos])) { continue; }
        comprobarClave($rel . ':' . lineaDe($src, $at), $nombre, $args[$pos], $lengua);
    }
}

function revisarJs(string $rel, string $src): void
{
    $re = '/(?<![\w$.])UI\.T(?:\.(titulo|ayuda|corto|deEstado))?\s*\(/';
    if (preg_match_all($re, $src, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[0] as $k => [$txt, $pos]) {
            $fn = $mm[1][$k][0] ?? '';
            $nombre = 'UI.T' . ($fn !== '' ? ".$fn" : '');
            $args = argumentos($src, $pos + strlen($txt) - 1);
            $donde = $rel . ':' . lineaDe($src, $pos);
            if ($args === null) { afirmar("$donde $nombre(", false, 'el paréntesis no cierra'); continue; }
            if ($fn === 'deEstado') { comprobarEstado($donde, $nombre, $args, 'js'); }
            else { comprobarClave($donde, $nombre, $args[0] ?? '', 'js'); }
        }
    }
    // Funciones de una línea que envuelven a deEstado, directa o a través de
    // otra envoltura: `function claveDe(c) { return UI.T.deEstado(c, 'preventivo'); }`
    // y `function nombre(c, n) { return UI.T(claveDe(c), n); }`. Una llamada
    // con el código escrito (`nombre('vencido', 2)`) se comprueba contra ese dominio.
    preg_match_all('/function\s+(\w+)\s*\(\s*(\w+)[^)]*\)\s*\{\s*return\s+([^;{}]*);\s*\}/', $src, $defs, PREG_SET_ORDER);
    $envolturas = [];
    for ($vuelta = 0; $vuelta < 3; $vuelta++) {
        foreach ($defs as [, $nombre, $param, $cuerpo]) {
            if (isset($envolturas[$nombre])) { continue; }
            $p = preg_quote($param, '/');
            if (preg_match("/UI\\.T\\.deEstado\\(\\s*$p\\s*,\\s*'(\\w+)'\\s*\\)/", $cuerpo, $m)) {
                $envolturas[$nombre] = $m[1];
                continue;
            }
            foreach ($envolturas as $otra => $dom) {
                if (preg_match('/(?<![\w.])' . preg_quote($otra, '/') . "\\(\\s*$p\\s*[,)]/", $cuerpo)) {
                    $envolturas[$nombre] = $dom;
                    break;
                }
            }
        }
    }
    foreach ($envolturas as $nombre => $dom) {
        if (!preg_match_all('/(?<![\w$.])' . preg_quote($nombre, '/') . '\s*\(/', $src, $ll, PREG_OFFSET_CAPTURE)) { continue; }
        foreach ($ll[0] as [$txt, $pos]) {
            $args = argumentos($src, $pos + strlen($txt) - 1);
            if ($args === null || literal($args[0] ?? '') === null) { continue; }   // la definición o un código calculado
            comprobarEstado($rel . ':' . lineaDe($src, $pos), $nombre, [$args[0], "'$dom'"], 'js');
        }
    }
}

function revisarPy(string $rel, string $src): void
{
    // Solo los nombres que el script importa de comun.py.
    $nombres = [];
    if (preg_match_all('/^\s*from\s+comun\s+import\s+(\([^)]*\)|[^\n]+)/m', $src, $imp)) {
        foreach ($imp[1] as $lista) {
            foreach (preg_split('/[\s,()]+/', $lista) ?: [] as $n) {
                if (in_array($n, ['termino', 'titulo', 'ayuda', 'corto', 'de_estado'], true)) { $nombres[$n] = true; }
            }
        }
    }
    foreach (array_keys($nombres) as $n) {
        // Una función propia del mismo nombre tapa a la de comun.py.
        if (preg_match('/^\s*def\s+' . $n . '\s*\(/m', $src)) { unset($nombres[$n]); }
    }
    if (!$nombres) { return; }
    $re = '/(?<![\w.])(' . implode('|', array_keys($nombres)) . ')\s*\(/';
    if (!preg_match_all($re, $src, $mm, PREG_OFFSET_CAPTURE)) { return; }
    foreach ($mm[0] as $k => [$txt, $pos]) {
        $fn = $mm[1][$k][0];
        $args = argumentos($src, $pos + strlen($txt) - 1);
        $donde = $rel . ':' . lineaDe($src, $pos);
        if ($args === null) { afirmar("$donde $fn(", false, 'el paréntesis no cierra'); continue; }
        if ($fn === 'de_estado') { comprobarEstado($donde, $fn, $args, 'py'); }
        else { comprobarClave($donde, $fn, $args[0] ?? '', 'py'); }
    }
    // Atajos locales con lambda: `lin = lambda z, clave: f"{titulo(clave)}…"` y
    // luego `lin("ZONA UIO", "ZONA_UIO")` (t2_27_status_semanal.py).
    $sinDe = array_diff(array_keys($nombres), ['de_estado']);
    if (!$sinDe) { return; }
    preg_match_all('/^\s*(\w+)\s*=\s*lambda\s+([^:]*):(.*)$/m', $src, $defs, PREG_SET_ORDER);
    foreach ($defs as [, $alias, $params, $cuerpo]) {
        foreach (array_map('trim', explode(',', $params)) as $pos => $param) {
            if ($param === '' || !preg_match('/(?<![\w.])(' . implode('|', $sinDe) . ')\(\s*' . preg_quote($param, '/') . '\b/', $cuerpo)) { continue; }
            revisarAlias($rel, $src, '/(?<![\w.])' . $alias . '\s*\(/', $alias, $pos, 'py');
            break;
        }
    }
}

/* --- Primero, que el detector detecte ---------------------------------------
 * Una prueba que no encuentra nada «pasa» también cuando está rota (I-7). Se
 * le da un código con errores conocidos y tiene que contarlos. */
echo "=== El detector encuentra lo que debe ===\n";
[$f0, $t0] = [$fallos, $total];
$silencio = true;
revisarPhp('muestra.php', sinComentariosPhp(
    "<?php\n// Vocabulario::t('COMENTADA_NO_CUENTA');\necho Vocabulario::t('ORDEN', 2) . Vocabulario::titulo('NO_EXISTE_XYZ');\n"
    . "echo Vocabulario::deEstado('ASIGNADO') . Vocabulario::deEstado(\$x, 'dominio_falso') . Vocabulario::t(\$c ? 'ORDEN' : 'OTRA_FALSA');\n"
    // El valor comparado en la condición es un dato, no una clave: no cuenta.
    . "echo Vocabulario::t(\$e === 'CERRADA' ? 'OT_CIERRE' : 'OT_EVALUACION');\n"
    // Un atajo local de los reportes.
    . "\$V = static fn(string \$clave, int \$n = 1): string => Vocabulario::t(\$clave, \$n);\n"
    . "echo \$V('ORDEN', 2) . \$V('ALIAS_FALSA') . \$V(\$calculada);\n"));
revisarJs('muestra.js', sinComentariosJs(
    "/* UI.T('COMENTADA') */ var a = UI.T('ORDEN') + UI.T.corto('FALSA_JS');\n"
    . "function k(c) { return UI.T.deEstado(c, 'preventivo'); }\nfunction n(c, x) { return UI.T(k(c), x); }\nn('vencido', 2); n('no_es_estado', 2);\n"));
revisarPy('muestra.py', sinComentariosPy(
    "from comun import termino, titulo, de_estado\n# termino('COMENTADA')\ns = termino(\"ORDEN\", 2) + termino('FALSA_PY' if x else 'ORDEN')\nde_estado(e, 'zona')\n"
    . "lin = lambda z, clave: f'{titulo(clave)}: {z}'\nlin('UIO', 'ZONA_UIO') + lin('X', 'LAMBDA_FALSA')\n"));
$esperado = 8;   // NO_EXISTE_XYZ, dominio_falso, OTRA_FALSA, ALIAS_FALSA, FALSA_JS, no_es_estado, FALSA_PY, LAMBDA_FALSA
$vistos = $fallos - $f0;
$fallos = $f0; $total = $t0;
$silencio = false;
$calculadas = 0;
$porLengua = ['php' => 0, 'js' => 0, 'py' => 0];
afirmar("la muestra con $esperado errores sembrados da $esperado fallos (y los comentarios no cuentan)",
        $vistos === $esperado, "dio $vistos");

/* --- El recorrido ----------------------------------------------------------- */
echo "=== Claves del código contra vocabulario.json " . Vocabulario::version() . " ===\n";
$rel = fn(string $ruta) => str_replace('\\', '/', substr(realpath($ruta), strlen($DES) + 1));
$archivos = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PUB, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (preg_match('/\.(php|js|html)$/', $f->getFilename())) { $archivos[] = $f->getPathname(); }
}
sort($archivos);
$pys = glob($PY . '/*.py') ?: [];
sort($pys);

foreach ($archivos as $a) {
    $src = (string) file_get_contents($a);
    $ext = strtolower(pathinfo($a, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        $limpio = sinComentariosPhp($src);
        revisarPhp($rel($a), $limpio);
        revisarJs($rel($a), sinComentariosJs($limpio));    // el <script> de la página
    } else {
        revisarJs($rel($a), sinComentariosJs($src));
    }
}
foreach ($pys as $p) {
    revisarPy($rel($p), sinComentariosPy((string) file_get_contents($p)));
}

// Si una lengua no dio ninguna llamada, el detector se rompió o alguien quitó
// el diccionario de esa puerta: las dos cosas hay que saberlas.
foreach ($porLengua as $lengua => $n) {
    afirmar("se encontraron llamadas con clave escrita en $lengua ($n)", $n > 0);
}

printf("\n%d archivos (%d de publico/, %d .py) · %d llamadas con clave calculada (no se comprueban aquí)\n",
       count($archivos) + count($pys), count($archivos), count($pys), $calculadas);
printf("%d comprobaciones · %d fallos\n", $total, $fallos);
exit($fallos ? 1 : 0);
