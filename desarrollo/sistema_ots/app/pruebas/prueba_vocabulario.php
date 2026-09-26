<?php
declare(strict_types=1);

/**
 * prueba_vocabulario.php — El diccionario único llega igual a todas partes.
 *
 * Qué comprueba (24-sep-2026):
 *   1. vocabulario.json se carga y cada concepto trae sus textos.
 *   2. Todo estado de la base, en todos los dominios, apunta a un concepto que
 *      existe.
 *   3. Las listas centrales (Ui::ESTADOS, Pendientes::ESTADOS / VIAS /
 *      decisiones de KFC, Novedades::ESTADOS, las zonas de Ui) dicen
 *      EXACTAMENTE lo que dice el JSON, estado por estado, y el generador no
 *      encuentra nada desviado.
 *   4. Vocabulario::t() en singular y en plural (también con 0), titulo(),
 *      ayuda(), corto() y deEstado(), y la excepción con clave o estado que no
 *      existen (I-7: nunca inventa texto).
 *   5. El respaldo embebido en ui.js es el JSON, y UI.T (Node) y termino()
 *      (Python) contestan lo mismo que PHP para cada concepto y cada estado.
 *
 * No toca la base ni la red. Si Node o Python no están, las comprobaciones de
 * esas dos puertas FALLAN en vez de saltarse: un «ok» que en realidad fue «no
 * lo pude comprobar» es peor que un fallo.
 *
 * Uso:  php prueba_vocabulario.php     (sale con 1 si algo falla)
 */

require_once __DIR__ . '/../publico/nucleo/Ui.php';
require_once __DIR__ . '/../publico/nucleo/Pendientes.php';
require_once __DIR__ . '/../publico/nucleo/Novedades.php';
require_once __DIR__ . '/../publico/nucleo/Vocabulario.php';
require_once __DIR__ . '/../herramientas/generar_vocabulario.php';

$PUB = dirname(__DIR__) . '/publico';
$fallos = 0;
$total = 0;

function afirmar(string $que, bool $ok, string $detalle = ''): void
{
    global $fallos, $total;
    $total++;
    if (!$ok) { $fallos++; }
    echo '  ', $ok ? 'ok    ' : 'FALLA ', $que, ($detalle !== '' && !$ok) ? ' · ' . $detalle : '', "\n";
}

/** Corre $f y devuelve el mensaje de la excepción de vocabulario, o null si no lanzó. */
function lanza(callable $f): ?string
{
    try { $f(); } catch (VocabularioError $e) { return $e->getMessage(); }
    return null;
}

/** Ejecuta un programa con el código por la entrada estándar; devuelve [código, salida, error]. */
function correr(array $cmd, string $entrada, array $env = []): array
{
    $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos,
                    null, $env ? array_merge(getenv(), $env) : null);
    if (!is_resource($p)) { return [-1, '', 'no se pudo lanzar ' . $cmd[0]]; }
    fwrite($tubos[0], $entrada);
    fclose($tubos[0]);
    $out = stream_get_contents($tubos[1]);
    $err = stream_get_contents($tubos[2]);
    fclose($tubos[1]); fclose($tubos[2]);
    return [proc_close($p), (string) $out, (string) $err];
}

$d = Vocabulario::todo();
$version = Vocabulario::version();
$mapas = Vocabulario::mapas();

echo "=== 1. El diccionario se carga (versión $version) ===\n";
afirmar('vocabulario.json trae versión', $version !== '');
afirmar('y conceptos (' . count($d['conceptos']) . ')', count($d['conceptos']) > 0);
$sinTexto = [];
foreach ($d['conceptos'] as $k => $c) {
    foreach (['termino', 'plural', 'titulo', 'ayuda'] as $campo) {
        if (!is_string($c[$campo] ?? null) || $c[$campo] === '') { $sinTexto[] = "$k.$campo"; }
    }
}
afirmar('todo concepto trae término, plural, título y ayuda', !$sinTexto, implode(', ', $sinTexto));
afirmar('todo() devuelve el mismo arreglo que el JSON',
        $d === json_decode((string) file_get_contents(Vocabulario::ruta()), true));

echo "\n=== 2. Todo estado de la base tiene un concepto que existe ===\n";
foreach ($mapas as $dom => $mapa) {
    $huerfanos = array_filter($mapa, fn($c) => !isset($d['conceptos'][$c]));
    afirmar("dominio $dom: " . count($mapa) . ' estados, todos con concepto', count($mapa) > 0 && !$huerfanos,
            implode(', ', array_keys($huerfanos)));
}
foreach ($d['preventivo']['grupo_base'] as $c => $base) {
    if (!isset($d['conceptos'][$c])) { afirmar("preventivo: grupo_base $c existe", false); }
}
foreach ($d['detalle_pendiente_heredado'] as $e => $_) {
    afirmar("heredado $e va a SOLICITUD_HEREDADA", ($mapas['pendiente'][$e] ?? '') === 'SOLICITUD_HEREDADA');
}

echo "\n=== 3a. Ui::ESTADOS = conceptos de la orden ===\n";
afirmar('las mismas claves que estados_caso del JSON',
        array_keys(Ui::ESTADOS) === array_keys($mapas['caso']),
        implode(',', array_keys(Ui::ESTADOS)));
foreach (Ui::ESTADOS as $e => [$et, $ay]) {
    $c = Vocabulario::deEstado($e, 'caso');
    afirmar("$e -> $c: «" . $et . '»',
            $et === Vocabulario::t($c) && $ay === Vocabulario::ayuda($c)
            && Ui::etiquetaEstado($e) === $et && Ui::ayudaEstado($e) === $ay
            && str_contains(Ui::estado($e), '>' . Ui::e($et) . '<'));
}
afirmar('estadoVista: sin atención ya regularizada se muestra REGULARIZADO',
        Ui::estadoVista('CERRADO_SIN_ATENCION', ['regularizado_en' => '2026-09-21']) === 'REGULARIZADO'
        && isset($mapas['caso']['REGULARIZADO']));
afirmar('estadoVista: vacío sigue siendo NUEVO', Ui::estadoVista(null) === 'NUEVO');
afirmar('un estado que la base no conoce se sigue mostrando como vino',
        Ui::etiquetaEstado('ALGO_RARO') === 'algo raro');

echo "\n=== 3b. Pendientes: estados de la solicitud, vías y decisión de KFC ===\n";
afirmar('las mismas claves que estados_pendiente del JSON',
        array_keys(Pendientes::ESTADOS) === array_keys($mapas['pendiente']));
foreach (Pendientes::ESTADOS as $e => [$et, $ay]) {
    $c = Vocabulario::deEstado($e, 'pendiente');
    $esperado = $c === 'SOLICITUD_HEREDADA'
        ? Vocabulario::t($c) . ' · ' . $d['detalle_pendiente_heredado'][$e]
        : Vocabulario::t($c);
    afirmar("$e -> $c: «" . $et . '»',
            $et === $esperado && $ay === Vocabulario::ayuda($c)
            && Pendientes::etiquetaEstado($e) === $et && Pendientes::ayudaEstado($e) === $ay);
}
afirmar('las mismas vías que el JSON', array_keys(Pendientes::VIAS) === array_keys($mapas['via']));
foreach (Pendientes::VIAS as $v => [$et, $ay, $paso]) {
    $c = Vocabulario::deEstado($v, 'via');
    afirmar("vía $v -> $c: «" . $et . '»',
            $et === Vocabulario::titulo($c) && $ay === Vocabulario::ayuda($c)
            && Pendientes::etiquetaVia($v) === $et && $paso === Pendientes::PASOS[$v][0]);
}
foreach (array_keys($mapas['decision_kfc']) as $k) {
    $c = Vocabulario::deEstado($k, 'decision_kfc');
    afirmar("decisión de KFC $k -> $c", Pendientes::etiquetaVeredictoKfc($k) === Vocabulario::t($c));
}

echo "\n=== 3c. Novedades::ESTADOS ===\n";
afirmar('las mismas claves que estados_novedad del JSON',
        array_keys(Novedades::ESTADOS) === array_keys($mapas['novedad']));
foreach (Novedades::ESTADOS as $e => [$et, $ay]) {
    $c = Vocabulario::deEstado($e, 'novedad');
    afirmar("$e -> $c: «" . $et . '»',
            $et === Vocabulario::t($c) && $ay === Vocabulario::ayuda($c) && Novedades::etiquetaEstado($e) === $et);
}

echo "\n=== 3d. Las zonas ===\n";
$cnlj = Ui::zona('CNLJ');
afirmar('CNLJ se lee «' . Vocabulario::corto('ZONA_CNLJ') . '» y conserva la clase zona-cnlj',
        str_contains($cnlj, '>' . Vocabulario::corto('ZONA_CNLJ') . '<') && str_contains($cnlj, 'zona-cnlj')
        && !str_contains($cnlj, '>CNLJ<'), $cnlj);
foreach ($mapas['zona'] as $cod => $c) {
    afirmar("zona «{$cod}» -> $c", str_contains(Ui::zona($cod), '>' . Ui::e(Vocabulario::corto($c)) . '<'));
}
afirmar('una zona que el diccionario no conoce sale tal cual', str_contains(Ui::zona('GYE'), '>GYE<'));
afirmar('nombreZona(CNLJ) = «Zona Cuenca-Loja»', Ui::nombreZona('CNLJ') === 'Zona Cuenca-Loja', Ui::nombreZona('CNLJ'));
afirmar('nombreZona(UIO) = «Zona UIO»', Ui::nombreZona('UIO') === 'Zona UIO');

echo "\n=== 3e. El generador no encuentra nada desviado ===\n";
foreach (vocabularioAplicar(false) as $f) {
    afirmar("{$f['archivo']} · {$f['bloque']}: {$f['estado']}", $f['estado'] === 'igual',
            'corre: php app/herramientas/generar_vocabulario.php');
}

echo "\n=== 4. La API de PHP ===\n";
$orden = $d['conceptos']['ORDEN'];
afirmar("t('ORDEN') = «{$orden['termino']}»", Vocabulario::t('ORDEN') === $orden['termino']);
afirmar("t('ORDEN', 1) = singular", Vocabulario::t('ORDEN', 1) === $orden['termino']);
afirmar("t('ORDEN', 2) = «{$orden['plural']}»", Vocabulario::t('ORDEN', 2) === $orden['plural']);
afirmar("t('ORDEN', 0) = plural («0 órdenes»)", Vocabulario::t('ORDEN', 0) === $orden['plural']);
afirmar("titulo('ABIERTA') = «ÓRDENES ABIERTAS»", Vocabulario::titulo('ABIERTA') === 'ÓRDENES ABIERTAS');
afirmar("titulo('ESPERA_INFORME') = «ÓRDENES A ESPERA DE INFORME TÉCNICO»",
        Vocabulario::titulo('ESPERA_INFORME') === 'ÓRDENES A ESPERA DE INFORME TÉCNICO');
afirmar('ayuda() devuelve la frase del JSON', Vocabulario::ayuda('ORDEN') === $orden['ayuda']);
afirmar("corto('ZONA_CNLJ') = «CUENCA-LOJA»", Vocabulario::corto('ZONA_CNLJ') === 'CUENCA-LOJA');
afirmar('corto() sin forma corta devuelve el término', Vocabulario::corto('ORDEN') === $orden['termino']);
afirmar('deEstado recorta y pasa a MAYÚSCULAS', Vocabulario::deEstado(' asignado ') === 'ASIGNADA');
afirmar('deEstado del semáforo en minúsculas', Vocabulario::deEstado('amarillo', 'semaforo') === 'LE_TOCA_INDUSTEC');
afirmar('deEstado de zona vacía = SIN_ZONA', Vocabulario::deEstado('', 'zona') === 'SIN_ZONA');
afirmar('deEstado de equipo vacío = SIN_DATO_EQUIPO (no Operativo)',
        Vocabulario::deEstado('', 'estado_equipo') === 'SIN_DATO_EQUIPO');
afirmar('deEstado del preventivo (VENCIDO -> ATRASADO)', Vocabulario::deEstado('vencido', 'preventivo') === 'ATRASADO');
afirmar('ESPERA_REPUESTO es su propio concepto', Vocabulario::deEstado('ESPERA_REPUESTO') === 'ESPERA_REPUESTO');

$m = lanza(fn() => Vocabulario::t('NO_EXISTE_XYZ'));
afirmar('t() con clave inexistente lanza VocabularioError', $m !== null);
afirmar('  y el mensaje dice la clave y la versión',
        $m !== null && str_contains($m, 'NO_EXISTE_XYZ') && str_contains($m, $version), (string) $m);
afirmar('titulo() con clave inexistente lanza', lanza(fn() => Vocabulario::titulo('NO_EXISTE_XYZ')) !== null);
afirmar('ayuda() con clave inexistente lanza', lanza(fn() => Vocabulario::ayuda('NO_EXISTE_XYZ')) !== null);
afirmar('corto() con clave inexistente lanza', lanza(fn() => Vocabulario::corto('NO_EXISTE_XYZ')) !== null);
$m = lanza(fn() => Vocabulario::deEstado('INVENTADO'));
afirmar('deEstado() con estado desconocido lanza, con el estado y la versión',
        $m !== null && str_contains($m, 'INVENTADO') && str_contains($m, $version), (string) $m);
afirmar('deEstado() con dominio desconocido lanza', lanza(fn() => Vocabulario::deEstado('NUEVO', 'inventado')) !== null);
afirmar('la excepción es un RuntimeException (la atrapa cualquier catch genérico)',
        is_subclass_of('VocabularioError', RuntimeException::class));

echo "\n=== 5a. El respaldo de ui.js es el JSON ===\n";
$ui = (string) file_get_contents("$PUB/ui.js");
$respaldo = null;
if (preg_match('/<vocabulario:RESPALDO>[^\n]*\n\s*var RESPALDO = (.*?);\s*\n[^\n]*<\/vocabulario:RESPALDO>/s', $ui, $mm)) {
    $respaldo = json_decode($mm[1], true);
}
afirmar('ui.js lleva el bloque RESPALDO y es JSON válido', is_array($respaldo));
if (is_array($respaldo)) {
    afirmar("misma versión ($version)", ($respaldo['version'] ?? null) === $version);
    afirmar('los mismos conceptos, en el mismo orden',
            array_keys($respaldo['conceptos'] ?? []) === array_keys($d['conceptos']));
    $dif = [];
    foreach ($d['conceptos'] as $k => $c) {
        foreach (['termino', 'plural', 'titulo', 'corto', 'ayuda'] as $campo) {
            if (($respaldo['conceptos'][$k][$campo] ?? null) !== ($c[$campo] ?? null)) { $dif[] = "$k.$campo"; }
        }
    }
    afirmar('cada texto del respaldo es el del JSON', !$dif, implode(', ', array_slice($dif, 0, 8)));
    foreach (['estados_caso', 'estados_pendiente', 'estados_novedad', 'via', 'decision_kfc',
              'estado_ot', 'estado_equipo', 'zona', 'semaforo'] as $sec) {
        afirmar("mapa $sec igual al del JSON", ($respaldo[$sec] ?? null) === $d[$sec]);
    }
    afirmar('mapa del preventivo igual al del JSON', ($respaldo['preventivo']['estados'] ?? null) === $d['preventivo']['estados']);
    afirmar('y es exactamente lo que proyecta el generador', $respaldo === vocRespaldoJs());
}
$sw = (string) file_get_contents("$PUB/sw.js");
// Desde el 26-sep-2026 lo que se sirve sin sesión es la copia RECORTADA
// (vocabulario_publico.json): el JSON completo trae notas internas —nombres de
// personas, rutas de la estación, contratos, excepciones— y no sale del disco.
afirmar('sw.js precarga vocabulario_publico.json',
        (bool) preg_match("/const PRECARGA = \\[[^\\]]*'vocabulario_publico\\.json'/s", $sw));
afirmar('sw.js ya no precarga el vocabulario.json completo',
        !preg_match("/const PRECARGA = \\[[^\\]]*'vocabulario\\.json'/s", $sw));
$ui = (string) file_get_contents("$PUB/ui.js");
afirmar('ui.js pide vocabulario_publico.json y no el completo',
        str_contains($ui, "fetch('vocabulario_publico.json'") && !str_contains($ui, "fetch('vocabulario.json'"));
$ht = (string) file_get_contents("$PUB/.htaccess");
afirmar('.htaccess deja pasar vocabulario_publico.json (los demás .json siguen cerrados)',
        (bool) preg_match('/<Files "vocabulario_publico\.json">\s*<IfModule mod_authz_core\.c>\s*Require all granted/s', $ht)
        && (bool) preg_match('/<FilesMatch "\\\\\.\(json\|/', $ht));
afirmar('.htaccess ya no abre el vocabulario.json completo', !str_contains($ht, '<Files "vocabulario.json">'));
$pubJson = json_decode((string) file_get_contents("$PUB/vocabulario_publico.json"), true);
afirmar('la copia pública es exactamente lo que proyecta el generador', $pubJson === vocRespaldoJs());
$pubTexto = (string) file_get_contents("$PUB/vocabulario_publico.json");
afirmar('la copia pública no trae notas, reemplazos, contratos ni excepciones',
        !preg_match('/"(notas|reemplaza|conserva|roles|contratos_externos|lista_negra\w*|reservadas|fuera_de_alcance)"/', $pubTexto));

/* --- 5b. Las tres puertas contestan lo mismo -------------------------------
   Se arma, en cada lenguaje, la misma tabla: por concepto [t(1), t(2), t(0),
   titulo, ayuda, corto] y por dominio cada estado -> concepto. Se compara
   contra la de PHP. No se reimplementa UI.T ni termino(): se ejecutan. */
$esperado = ['conceptos' => [], 'estados' => []];
foreach (array_keys($d['conceptos']) as $k) {
    $esperado['conceptos'][$k] = [Vocabulario::t($k), Vocabulario::t($k, 2), Vocabulario::t($k, 0),
                                  Vocabulario::titulo($k), Vocabulario::ayuda($k), Vocabulario::corto($k)];
}
foreach ($mapas as $dom => $mapa) {
    foreach (array_keys($mapa) as $e) { $esperado['estados'][$dom][(string) $e] = Vocabulario::deEstado((string) $e, $dom); }
}

echo "\n=== 5b. UI.T (ui.js en Node) contesta lo mismo que PHP ===\n";
$js = <<<'JS'
const fs = require('fs'), vm = require('vm');
const [ruta, rutaJson] = process.argv.slice(2);
function cargar(fetch) {
  const doc = { readyState: 'loading', addEventListener() {}, getElementById() { return null; }, querySelectorAll() { return []; } };
  const ctx = { document: doc, console };
  if (fetch) { ctx.fetch = fetch; }
  ctx.window = ctx; ctx.self = ctx; ctx.globalThis = ctx;
  vm.createContext(ctx);
  vm.runInContext(fs.readFileSync(ruta, 'utf8'), ctx);
  return ctx.UI;
}
(async () => {
  const UI = cargar(null);                       // sin fetch: solo el respaldo
  const dic = JSON.parse(fs.readFileSync(rutaJson, 'utf8'));
  const out = { conceptos: {}, estados: {}, errores: {} };
  for (const k of Object.keys(dic.conceptos)) {
    out.conceptos[k] = [UI.T(k), UI.T(k, 2), UI.T(k, 0), UI.T.titulo(k), UI.T.ayuda(k), UI.T.corto(k)];
  }
  const dom = { caso: dic.estados_caso, pendiente: dic.estados_pendiente, novedad: dic.estados_novedad,
    preventivo: dic.preventivo.estados, via: dic.via, decision_kfc: dic.decision_kfc, estado_ot: dic.estado_ot,
    estado_equipo: dic.estado_equipo, zona: dic.zona, semaforo: dic.semaforo };
  for (const [d, m] of Object.entries(dom)) {
    out.estados[d] = {};
    for (const e of Object.keys(m)) { out.estados[d][e] = UI.T.deEstado(e, d); }
  }
  const atrapa = (f) => { try { f(); return null; } catch (e) { return String(e.message); } };
  out.errores.clave = atrapa(() => UI.T('NO_EXISTE_XYZ'));
  out.errores.titulo = atrapa(() => UI.T.titulo('NO_EXISTE_XYZ'));
  out.errores.estado = atrapa(() => UI.T.deEstado('INVENTADO'));
  out.errores.dominio = atrapa(() => UI.T.deEstado('NUEVO', 'inventado'));
  out.version = UI.T.version();
  out.normaliza = UI.T.deEstado(' asignado ');
  // Cuando llega el JSON de verdad, reemplaza al respaldo; si llega algo a
  // medias, se queda el respaldo.
  const otro = JSON.parse(JSON.stringify(dic)); otro.version = 'VERSION-DE-PRUEBA';
  const UI2 = cargar(() => Promise.resolve({ ok: true, json: () => Promise.resolve(otro) }));
  const UI3 = cargar(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ version: 'X' }) }));
  const UI4 = cargar(() => Promise.reject(new Error('sin red')));
  await new Promise((r) => setTimeout(r, 20));
  out.conFetch = UI2.T.version();
  out.fetchAMedias = UI3.T.version();
  out.sinRed = UI4.T.version();
  process.stdout.write(JSON.stringify(out));
})().catch((e) => { process.stderr.write(String(e && e.stack || e)); process.exit(3); });
JS;
[$cod, $out, $err] = correr(['node', '-', "$PUB/ui.js", Vocabulario::ruta()], $js);
$n = $cod === 0 ? json_decode($out, true) : null;
afirmar('Node corre ui.js', is_array($n), trim($err) ?: "código $cod");
if (is_array($n)) {
    afirmar('UI.T, titulo, ayuda y corto = PHP en los ' . count($esperado['conceptos']) . ' conceptos',
            $n['conceptos'] === $esperado['conceptos']);
    afirmar('UI.T.deEstado = Vocabulario::deEstado en todos los dominios', $n['estados'] === $esperado['estados']);
    afirmar('UI.T con clave inexistente lanza, con la clave y la versión',
            is_string($n['errores']['clave']) && str_contains($n['errores']['clave'], 'NO_EXISTE_XYZ')
            && str_contains($n['errores']['clave'], $version), (string) $n['errores']['clave']);
    afirmar('UI.T.titulo con clave inexistente lanza', is_string($n['errores']['titulo']));
    afirmar('UI.T.deEstado con estado o dominio desconocido lanza',
            is_string($n['errores']['estado']) && is_string($n['errores']['dominio']));
    afirmar('UI.T.deEstado recorta y pasa a MAYÚSCULAS', $n['normaliza'] === 'ASIGNADA');
    afirmar("sin fetch, UI.T usa el respaldo ($version)", $n['version'] === $version);
    afirmar('cuando llega vocabulario.json, reemplaza al respaldo', $n['conFetch'] === 'VERSION-DE-PRUEBA');
    afirmar('un JSON a medias no reemplaza al respaldo', $n['fetchAMedias'] === $version);
    afirmar('sin red se queda el respaldo, sin error', $n['sinRed'] === $version);
}

echo "\n=== 5c. termino() (comun.py) contesta lo mismo que PHP ===\n";
$scripts = dirname(__DIR__, 3) . '/agentes/scripts';
$py = <<<'PY'
import json, sys
sys.path.insert(0, sys.argv[1])
import comun as c
d = c.vocabulario()
out = {"conceptos": {}, "estados": {}, "errores": {}}
for k in d["conceptos"]:
    out["conceptos"][k] = [c.termino(k), c.termino(k, 2), c.termino(k, 0), c.titulo(k), c.ayuda(k), c.corto(k)]
for dom, camino in c._DOMINIOS.items():
    m = d
    for p in camino:
        m = m[p]
    out["estados"][dom] = {e: c.de_estado(e, dom) for e in m}
def atrapa(f):
    try:
        f()
        return None
    except c.VocabularioError as e:
        return str(e)
out["errores"]["clave"] = atrapa(lambda: c.termino("NO_EXISTE_XYZ"))
out["errores"]["estado"] = atrapa(lambda: c.de_estado("INVENTADO"))
out["errores"]["dominio"] = atrapa(lambda: c.de_estado("NUEVO", "inventado"))
out["ruta"] = str(c.VOCABULARIO.resolve())
sys.stdout.write(json.dumps(out, ensure_ascii=False))
PY;
[$cod, $out, $err] = correr(['python', '-', $scripts], $py, ['PYTHONUTF8' => '1', 'PYTHONIOENCODING' => 'utf-8']);
$p = $cod === 0 ? json_decode($out, true) : null;
afirmar('Python importa comun y lee el diccionario', is_array($p), trim($err) ?: "código $cod");
if (is_array($p)) {
    afirmar('comun.py lee el MISMO vocabulario.json que PHP',
            realpath($p['ruta']) === realpath(Vocabulario::ruta()), $p['ruta']);
    afirmar('termino, titulo, ayuda y corto = PHP en los ' . count($esperado['conceptos']) . ' conceptos',
            $p['conceptos'] === $esperado['conceptos']);
    afirmar('de_estado = Vocabulario::deEstado en todos los dominios', $p['estados'] === $esperado['estados']);
    afirmar('termino con clave inexistente lanza, con la clave y la versión',
            is_string($p['errores']['clave']) && str_contains($p['errores']['clave'], 'NO_EXISTE_XYZ')
            && str_contains($p['errores']['clave'], $version), (string) $p['errores']['clave']);
    afirmar('de_estado con estado o dominio desconocido lanza',
            is_string($p['errores']['estado']) && is_string($p['errores']['dominio']));
}

echo "\n";
printf("%d comprobaciones · %d fallos\n", $total, $fallos);
exit($fallos > 0 ? 1 : 0);
