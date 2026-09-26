<?php
declare(strict_types=1);

/**
 * prueba_lista_negra.php — Ningún texto visible usa una palabra retirada.
 *
 * POR QUE EXISTE
 * El diccionario único (vocabulario.json, 24-sep-2026) retira nombres viejos:
 * «asignado» en masculino, «veredicto», «sin repartir», «Casos abiertos»… Si
 * solo se cambian donde uno se acuerda, en un mes vuelven a convivir dos
 * nombres para lo mismo, que es exactamente lo que Isabel pidió arreglar. Esta
 * prueba recorre el código y señala cada texto que una persona puede LEER y
 * que todavía dice una palabra de `lista_negra` (o de `lista_negra_contextual`
 * en su archivo).
 *
 * SOLO TEXTOS VISIBLES, no el código:
 *   PHP     con token_get_all: cadenas (T_CONSTANT_ENCAPSED_STRING,
 *           T_ENCAPSED_AND_WHITESPACE) y el HTML suelto (T_INLINE_HTML).
 *           Los comentarios no cuentan.
 *   JS      se quitan los comentarios y se miran los literales ('…', "…", `…`).
 *   HTML    el texto y los atributos, sin comentarios ni <style>; el <script>
 *           se lee como JS.
 *   Python  con el módulo tokenize (se llama a python): cadenas y trozos de
 *           f-string, sin comentarios ni docstrings.
 * Se saltan las cadenas que son CLAVES de código (`$f['veredicto']`,
 * `'asignado' => …`, `{ veredicto: …}`), porque nadie las lee en pantalla.
 * Se descuenta todo lo que el diccionario declara contrato (`contratos_externos`:
 * el PDF, el asunto del correo, las hojas de Isabel y del export SAP): eso no
 * se cambia por decisión de Andrés (D-C).
 *
 * La comparación distingue mayúsculas —la lista trae cada variante que se usa
 * («Asignados», «asignados»)— y va por palabra entera: «asignado» no salta
 * dentro de «asignados» ni de `est-asignado`.
 *
 * Uso:
 *   php prueba_lista_negra.php            sale con 1 si hay hallazgos
 *   php prueba_lista_negra.php --informe  lista los hallazgos y el conteo por archivo, sale con 0
 *
 * El 24-sep-2026, al crearse, DEBE dar hallazgos: las pantallas se migran en
 * la ola 2. Cuando llegue a cero, se quita --informe de donde se llame.
 * Llegó a cero al cerrar la ola 2 (integración, 26-sep-2026, vocabulario .5):
 * desde entonces se corre SIN --informe y un hallazgo nuevo es una regresión.
 * Lo que quedaba eran códigos (permisos, alias SQL, clases CSS, códigos de
 * grupo o de estado que se comparan), declarados en `lista_negra_excepciones`
 * con su `maximo` (ver más abajo).
 */

require_once __DIR__ . '/../publico/nucleo/Vocabulario.php';

$informe = in_array('--informe', $argv, true);
$DES = realpath(dirname(__DIR__, 3));                 // desarrollo/
$PUB = realpath(dirname(__DIR__) . '/publico');
$PY  = realpath($DES . '/agentes/scripts');

$d = Vocabulario::todo();

/* --- Lo que se busca ------------------------------------------------------ */
$negra = array_values(array_unique(array_filter($d['lista_negra'], 'is_string')));
usort($negra, fn($a, $b) => strlen($b) <=> strlen($a));   // la más larga gana
$FRONTERA_I = '(?<![\p{L}\p{N}_-])';
$FRONTERA_D = '(?![\p{L}\p{N}_-])';
$reNegra = '/' . $FRONTERA_I . '(' . implode('|', array_map(fn($t) => preg_quote($t, '/'), $negra)) . ')'
         . $FRONTERA_D . '/u';

$contratos = [];
foreach ($d['contratos_externos'] as $c) {
    foreach ((array) ($c['textos'] ?? []) as $t) { if (is_string($t) && $t !== '') { $contratos[] = $t; } }
}
usort($contratos, fn($a, $b) => strlen($b) <=> strlen($a));

$contextual = [];                                          // [archivo relativo a desarrollo/ => [texto…]]
foreach ($d['lista_negra_contextual'] as $c) {
    $contextual[str_replace('\\', '/', (string) $c['archivo'])][] = (string) $c['texto'];
}
$excepciones = array_filter((array) ($d['lista_negra_excepciones'] ?? []), 'is_array');

/* --- Extracción de textos visibles --------------------------------------- *
 * Cada extractor devuelve una lista de [línea, texto, esLiteral]. esLiteral
 * dice si el texto es una cadena entera del código (para comparar las
 * entradas contextuales entre comillas, que piden el literal exacto). */

function esClave(string $s): bool
{
    return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $s);
}

/** JS: literales de un trozo de código, con su línea. Un lector mínimo que
 *  entiende comentarios, cadenas, plantillas con ${…} y expresiones regulares
 *  —lo justo para no confundir un «//» dentro de una cadena con un comentario. */
function literalesJs(string $src, int $linea0 = 1): array
{
    $out = [];
    $n = strlen($src);
    $i = 0;
    $linea = $linea0;
    $pila = [];                 // profundidad de llaves de cada ${ abierto
    $prevSig = '';              // último carácter significativo fuera de cadenas
    $prevPalabra = '';
    $ultimoLiteral = null;      // índice en $out del literal recién cerrado, para mirar lo que sigue
    $antesLiteral = '';
    while ($i < $n) {
        $ch = $src[$i];
        if ($ch === "\n") { $linea++; $i++; continue; }
        if ($ch === '/' && ($src[$i + 1] ?? '') === '/') {
            while ($i < $n && $src[$i] !== "\n") { $i++; }
            continue;
        }
        if ($ch === '/' && ($src[$i + 1] ?? '') === '*') {
            $fin = strpos($src, '*/', $i + 2);
            $fin = $fin === false ? $n : $fin + 2;
            $linea += substr_count($src, "\n", $i, $fin - $i);
            $i = $fin;
            continue;
        }
        if ($ch === '/' && ($prevSig === '' || strpbrk($prevSig, '(,=:[!&|?{};+-*%<>~^') !== false
                            || in_array($prevPalabra, ['return', 'typeof', 'case', 'of', 'in'], true))) {
            // Expresión regular: hasta la barra que la cierra, fuera de una clase [...].
            $i++; $clase = false;
            while ($i < $n) {
                $c = $src[$i];
                if ($c === '\\') { $i += 2; continue; }
                if ($c === "\n") { break; }
                if ($c === '[') { $clase = true; } elseif ($c === ']') { $clase = false; }
                elseif ($c === '/' && !$clase) { $i++; break; }
                $i++;
            }
            while ($i < $n && ctype_alpha($src[$i])) { $i++; }
            $prevSig = ')'; $prevPalabra = '';
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $ini = $linea;
            // Lo que precede a la cadena: para reconocer un nombre de clase CSS
            // o un id (`classList.toggle('abierto')`), que no se leen en pantalla.
            $previo = substr($src, max(0, $i - 48), min(48, $i));
            $i++;
            $buf = '';
            while ($i < $n) {
                $c = $src[$i];
                if ($c === '\\') { $buf .= $src[$i + 1] ?? ''; if (($src[$i + 1] ?? '') === "\n") { $linea++; } $i += 2; continue; }
                if ($c === $ch) { $i++; break; }
                if ($c === "\n") { $linea++; if ($ch !== '`') { break; } }
                if ($ch === '`' && $c === '$' && ($src[$i + 1] ?? '') === '{') {
                    // Lo escrito hasta aquí es texto; lo de dentro de ${…} es código.
                    if ($buf !== '') { $out[] = [$ini, $buf, false]; }
                    $pila[] = 0;
                    $i += 2;
                    $buf = null;
                    break;
                }
                $buf .= $c;
                $i++;
            }
            if ($buf === null) { $prevSig = '{'; $prevPalabra = ''; continue; }   // seguimos en código
            $out[] = [$ini, $buf, $ch !== '`', $prevSig];
            $ultimoLiteral = count($out) - 1;
            $prevSig = 'x'; $prevPalabra = '';
            // ¿Es una clave? Se decide mirando lo que sigue.
            $j = $i; while ($j < $n && ctype_space($src[$j])) { $j++; }
            $sig = $src[$j] ?? '';
            $lit = $out[$ultimoLiteral];
            if (esClave($lit[1]) && (($lit[3] === '[' && $sig === ']') || (in_array($lit[3], ['{', ','], true) && $sig === ':')
                    || preg_match('/(classList\.(add|remove|toggle|contains|replace)|getElementById|querySelector(All)?'
                                  . '|closest|matches|[gs]etAttribute|removeAttribute|addEventListener)\(\s*$/', $previo)
                    // Un código comparado (`e === 'vencido'`, `case 'vencido':`) no se lee en pantalla.
                    || preg_match('/(===?|!==?)\s*$|\bcase\s*$/', $previo)
                    || preg_match('/^\s*(===?|!==?)/', substr($src, $i, 8)))) {
                array_pop($out);
            }
            continue;
        }
        if ($ch === '{' && $pila) { $pila[count($pila) - 1]++; }
        if ($ch === '}' && $pila) {
            if ($pila[count($pila) - 1] === 0) {
                // Se cierra ${…}: se vuelve a la plantilla.
                array_pop($pila);
                $i++;
                $ini = $linea; $buf = '';
                while ($i < $n) {
                    $c = $src[$i];
                    if ($c === '\\') { $buf .= $src[$i + 1] ?? ''; $i += 2; continue; }
                    if ($c === '`') { $i++; break; }
                    if ($c === "\n") { $linea++; }
                    if ($c === '$' && ($src[$i + 1] ?? '') === '{') { $pila[] = 0; $i += 2; $buf .= "\0"; break; }
                    $buf .= $c; $i++;
                }
                $abierto = str_ends_with($buf, "\0");
                $buf = rtrim($buf, "\0");
                if ($buf !== '') { $out[] = [$ini, $buf, false]; }
                $prevSig = $abierto ? '{' : 'x'; $prevPalabra = '';
                continue;
            }
            $pila[count($pila) - 1]--;
        }
        if (ctype_alpha($ch) || $ch === '_' || $ch === '$') {
            $j = $i; while ($j < $n && (ctype_alnum($src[$j]) || $src[$j] === '_' || $src[$j] === '$')) { $j++; }
            $prevPalabra = substr($src, $i, $j - $i);
            $prevSig = 'x';
            $i = $j;
            continue;
        }
        if (!ctype_space($ch)) { $prevSig = $ch; $prevPalabra = ''; }
        $i++;
    }
    return array_map(fn($x) => [$x[0], $x[1], $x[2]], $out);
}

/** HTML: texto y atributos, sin comentarios ni estilos; <script> como JS. */
function textosHtml(string $html, int $linea0 = 1): array
{
    $out = [];
    $re = '/<!--.*?-->|<style\b[^>]*>.*?<\/style>|<script\b[^>]*>(.*?)<\/script>/is';
    $pos = 0;
    $linea = $linea0;
    while (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
        $ini = $m[0][1];
        if ($ini > $pos) { $out[] = [$linea, substr($html, $pos, $ini - $pos), false]; }
        $linea += substr_count($html, "\n", $pos, $ini - $pos);
        if (isset($m[1]) && $m[1][1] >= 0) {
            $dentro = $linea + substr_count($html, "\n", $ini, $m[1][1] - $ini);
            foreach (literalesJs($m[1][0], $dentro) as $x) { $out[] = $x; }
        }
        $linea += substr_count($m[0][0], "\n");
        $pos = $ini + strlen($m[0][0]);
    }
    if ($pos < strlen($html)) { $out[] = [$linea, substr($html, $pos), false]; }
    return $out;
}

/** PHP: cadenas y HTML suelto con token_get_all. */
function textosPhp(string $src): array
{
    $out = [];
    $toks = token_get_all($src);
    $sig = function (int $k, int $paso) use ($toks) {
        for ($j = $k + $paso; $j >= 0 && $j < count($toks); $j += $paso) {
            $t = $toks[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            return $t;
        }
        return null;
    };
    $html = '';                                            // último HTML suelto: dice si estamos dentro de class="…"
    foreach ($toks as $k => $t) {
        if (!is_array($t)) { continue; }
        [$id, $txt, $linea] = $t;
        if ($id === T_INLINE_HTML) {
            $html = $txt;
            foreach (textosHtml($txt, $linea) as $x) { $out[] = $x; }
        } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
            $inner = substr($txt, 1, -1);
            // Un enlace a otra pantalla ('pendientes.php?g=vencidos&zona=') es
            // una URL, no un texto que alguien lea: igual que el href del HTML.
            if (preg_match('/^[\w\/.-]+\.php(?:[?#].*)?$/', $inner)) { continue; }
            // Un nombre de estilo que se imprime con un echo corto dentro de
            // class="…" (p. ej. la clase CSS «vencido» de la tarjeta) tampoco se lee.
            if (esClave($inner) && preg_match('/\bclass\s*=\s*"[^"]*$/i', $html)) { continue; }
            if (esClave($inner)) {
                $a = $sig($k, -1); $b = $sig($k, 1);
                $cmp = [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL, T_CASE];
                if (($a === '[' && $b === ']') || (is_array($b) && $b[0] === T_DOUBLE_ARROW)
                    || (is_array($a) && in_array($a[0], $cmp, true)) || (is_array($b) && in_array($b[0], $cmp, true))) {
                    continue;
                }
            }
            $out[] = [$linea, $inner, true];
        } elseif ($id === T_ENCAPSED_AND_WHITESPACE) {
            $out[] = [$linea, $txt, false];
        }
    }
    return $out;
}

/** Python: cadenas y trozos de f-string con tokenize, en una sola llamada. */
function textosPython(array $rutas): array
{
    if (!$rutas) { return []; }
    $codigo = <<<'PY'
import io, json, re, sys, token, tokenize
out = {}
for ruta in sys.argv[1:]:
    filas = []
    try:
        toks = list(tokenize.generate_tokens(io.open(ruta, encoding="utf-8").readline))
    except (SyntaxError, tokenize.TokenError, UnicodeDecodeError) as e:
        out[ruta] = {"error": str(e)}
        continue
    sig = [t for t in toks if t.type not in (tokenize.COMMENT, tokenize.NL, tokenize.ENCODING)]
    for k, t in enumerate(sig):
        tipo = token.tok_name.get(t.type, "")
        if tipo in ("FSTRING_MIDDLE", "TSTRING_MIDDLE"):
            filas.append([t.start[0], t.string, False])
            continue
        if t.type != tokenize.STRING:
            continue
        antes = sig[k - 1] if k else None
        despues = sig[k + 1] if k + 1 < len(sig) else None
        # Docstring: una cadena sola en su sentencia.
        if (antes is None or antes.type in (tokenize.NEWLINE, tokenize.INDENT, tokenize.DEDENT)) \
                and (despues is None or despues.type in (tokenize.NEWLINE, tokenize.ENDMARKER)):
            continue
        m = re.match(r'(?is)^[a-z]*("""|\'\'\'|"|\')(.*)\1$', t.string)
        texto = m.group(2) if m else t.string
        # Claves de código: d['veredicto'] y {'veredicto': ...}
        if re.match(r"^[A-Za-z_][A-Za-z0-9_]*$", texto) and antes is not None and despues is not None:
            if (antes.string == "[" and despues.string == "]") or (antes.string in ("{", ",") and despues.string == ":")                     or antes.string in ("==", "!=") or despues.string in ("==", "!="):
                continue
        filas.append([t.start[0], texto, True])
    out[ruta] = filas
sys.stdout.write(json.dumps(out, ensure_ascii=False))
PY;
    $p = proc_open(array_merge(['python', '-'], $rutas),
                   [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, null,
                   array_merge(getenv(), ['PYTHONUTF8' => '1', 'PYTHONIOENCODING' => 'utf-8']));
    if (!is_resource($p)) { fwrite(STDERR, "No se pudo lanzar python: los .py NO se revisaron\n"); exit(2); }
    fwrite($tubos[0], $codigo); fclose($tubos[0]);
    $salida = stream_get_contents($tubos[1]); $err = stream_get_contents($tubos[2]);
    fclose($tubos[1]); fclose($tubos[2]);
    $cod = proc_close($p);
    $r = json_decode((string) $salida, true);
    if ($cod !== 0 || !is_array($r)) {
        // I-7: si no se pudo mirar, se dice; no se cuenta como «cero hallazgos».
        fwrite(STDERR, "python falló ($cod): " . trim((string) $err) . "\nLos .py NO se revisaron.\n");
        exit(2);
    }
    return $r;
}

/* --- Recorrido --------------------------------------------------------------- */
$archivos = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PUB, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (preg_match('/\.(php|js|html)$/', $f->getFilename())) { $archivos[] = $f->getPathname(); }
}
sort($archivos);
$pys = glob($PY . '/*.py') ?: [];
sort($pys);

$rel = fn(string $ruta) => str_replace('\\', '/', substr(realpath($ruta), strlen($DES) + 1));

$textos = [];                                              // [rel => [[línea, texto, esLiteral]…]]
foreach ($archivos as $a) {
    $src = (string) file_get_contents($a);
    $ext = strtolower(pathinfo($a, PATHINFO_EXTENSION));
    $textos[$rel($a)] = $ext === 'php' ? textosPhp($src) : ($ext === 'js' ? literalesJs($src) : textosHtml($src));
}
$errPy = [];
foreach (textosPython($pys) as $ruta => $filas) {
    if (isset($filas['error'])) { $errPy[] = $rel($ruta) . ': ' . $filas['error']; continue; }
    $textos[$rel($ruta)] = $filas;
}

/** Tapa los valores de los atributos técnicos (class, id, name, for, href,
 *  src, type): `class="caja abierto"` es un nombre de estilo, no un texto que
 *  alguien lea. title, aria-label, placeholder y value sí se leen, y se miran. */
function sinAtributosTecnicos(string $s): string
{
    return preg_replace_callback('/\b(class|id|name|for|href|src|type|action|method)\s*=\s*("[^"]*"|\'[^\']*\')/i',
        fn($m) => str_repeat(' ', strlen($m[0])), $s) ?? $s;
}

/** Tapa los contratos con espacios del mismo largo: así no cuentan y las
 *  posiciones (y las líneas) siguen cuadrando. */
function sinContratos(string $s, array $contratos): string
{
    foreach ($contratos as $c) {
        if (str_contains($s, $c)) { $s = str_replace($c, str_repeat(' ', strlen($c)), $s); }
    }
    return $s;
}

$hallazgos = [];                                           // [rel, línea, término]
foreach ($textos as $archivo => $filas) {
    $ctx = $contextual[$archivo] ?? [];
    foreach ($filas as [$linea, $texto, $esLit]) {
        $limpio = sinAtributosTecnicos(sinContratos((string) $texto, $contratos));
        if (preg_match_all($reNegra, $limpio, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[1] as [$term, $off]) {
                $hallazgos[] = [$archivo, $linea + substr_count($limpio, "\n", 0, $off), $term];
            }
        }
        foreach ($ctx as $t) {
            $entreComillas = strlen($t) >= 2 && ($t[0] === "'" || $t[0] === '"') && $t[strlen($t) - 1] === $t[0];
            if ($entreComillas) {
                if ($esLit && $texto === substr($t, 1, -1)) { $hallazgos[] = [$archivo, $linea, $t]; }
            } elseif (preg_match('/' . $GLOBALS['FRONTERA_I'] . preg_quote($t, '/') . $GLOBALS['FRONTERA_D'] . '/u',
                                 $limpio, $m1, PREG_OFFSET_CAPTURE)) {
                $hallazgos[] = [$archivo, $linea + substr_count($limpio, "\n", 0, $m1[0][1]), $t];
            }
        }
    }
}

// Excepciones que el diccionario declare a mano: {"archivo", "texto", "maximo", "motivo"}.
// Van por archivo + palabra, no por línea (las líneas se mueven con cada
// cambio). Para que una excepción no tape un texto visible que alguien agregue
// después en el mismo archivo, `maximo` fija cuántas apariciones de código
// cubre: si aparecen más, se informan TODAS las de ese par y se dice por qué,
// y quien la agregó decide si es código (sube el máximo) o texto (lo migra).
$excedidas = [];
$porPar = [];
foreach ($hallazgos as $h) { $porPar[$h[0] . "\0" . $h[2]] = ($porPar[$h[0] . "\0" . $h[2]] ?? 0) + 1; }
$hallazgos = array_values(array_filter($hallazgos, function ($h) use ($excepciones, $porPar, &$excedidas) {
    foreach ($excepciones as $e) {
        if (($e['archivo'] ?? null) !== $h[0] || ($e['texto'] ?? null) !== $h[2]) { continue; }
        $max = $e['maximo'] ?? null;
        $hay = $porPar[$h[0] . "\0" . $h[2]];
        if ($max === null || $hay <= (int) $max) { return false; }
        $excedidas["{$h[0]} «{$h[2]}»"] = "$hay apariciones y la excepción cubre $max";
        return true;
    }
    return true;
}));
usort($hallazgos, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

foreach ($hallazgos as [$a, $l, $t]) { echo "$a:$l:$t\n"; }

$porArchivo = [];
foreach ($hallazgos as [$a]) { $porArchivo[$a] = ($porArchivo[$a] ?? 0) + 1; }
arsort($porArchivo);
echo "\n=== Conteo por archivo ===\n";
foreach ($porArchivo as $a => $n) { printf("%6d  %s\n", $n, $a); }
foreach ($errPy as $e) { echo "  NO SE PUDO LEER: $e\n"; }
foreach ($excedidas as $par => $por) { echo "  EXCEPCIÓN EXCEDIDA: $par — $por\n"; }

printf("\n%d archivos revisados (%d de publico/, %d .py) · vocabulario %s · %d términos en la lista negra\n",
       count($textos), count($archivos), count($pys) - count($errPy), Vocabulario::version(), count($negra));
printf("%d hallazgos\n", count($hallazgos));
exit(($informe || !$hallazgos) && !$errPy ? 0 : 1);
