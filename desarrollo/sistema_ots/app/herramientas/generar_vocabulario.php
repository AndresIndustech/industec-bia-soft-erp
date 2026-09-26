<?php
declare(strict_types=1);

/**
 * generar_vocabulario.php — Escribe las listas centrales de estados y el
 * respaldo de ui.js a partir de `publico/vocabulario.json`.
 *
 * POR QUE HACE FALTA UN GENERADOR Y NO BASTA CON LEER EL JSON
 *
 *   1. PHP no deja calcular una constante de clase leyendo un archivo, y
 *      `Ui::ESTADOS`, `Pendientes::ESTADOS`, `Pendientes::VIAS` y
 *      `Novedades::ESTADOS` son constantes que recorren pantallas que no se
 *      tocan en esta ola (casos.php, pendientes.php, novedades_visita.php) y
 *      las pruebas. Conservar su forma es conservar su API.
 *   2. El despliegue (t2_10_desplegar.py) sube una lista blanca de archivos.
 *      Si Ui.php leyera el JSON al vuelo, un despliegue que olvidara el JSON
 *      dejaría TODAS las pantallas en blanco. Con el texto escrito en la
 *      constante, las listas centrales no dependen de que el JSON llegue.
 *   3. El celular abre la app sin señal: ui.js necesita las palabras aunque no
 *      haya podido pedir el JSON nunca. Por eso lleva un respaldo embebido.
 *
 * Es el mismo trato que ya tiene la tabla SIN_TILDE de Ui.php: generada por un
 * script, no escrita a mano. `prueba_vocabulario.php` llama a este mismo
 * archivo con --comprobar y FALLA si alguna lista se desvió del JSON, así que
 * cambiar un término sin regenerar no pasa desapercibido.
 *
 * Uso (desde cualquier carpeta):
 *   php app/herramientas/generar_vocabulario.php              reescribe los bloques
 *   php app/herramientas/generar_vocabulario.php --comprobar  solo compara; sale con 1 si difieren
 *
 * Solo toca lo que está entre las marcas `<vocabulario:NOMBRE>` y
 * `</vocabulario:NOMBRE>`. El resto del archivo es de quien lo escribió.
 *
 * Y escribe ENTERO `publico/vocabulario_publico.json`: la copia recortada que
 * se sirve sin sesión a ui.js y a sw.js. El `vocabulario.json` completo trae
 * notas internas (nombres de personas, rutas de scripts de la estación, los
 * contratos y las excepciones de la lista negra) y no se sirve: solo lo leen
 * Vocabulario.php y comun.py desde el disco (.htaccess lo niega con los demás
 * .json). La copia pública tiene la misma forma que el respaldo de ui.js.
 */

require_once __DIR__ . '/../publico/nucleo/Vocabulario.php';

const VOC_PUBLICO = __DIR__ . '/../publico';

/** Una cadena como literal PHP entre comillas simples. */
function vocPhp(string $s): string
{
    return var_export($s, true);
}

/** Las filas `'CLAVE' => valor,` alineadas como las escribe la gente aquí. */
function vocFilas(array $filas, string $sangria = '        '): string
{
    $ancho = 0;
    foreach (array_keys($filas) as $k) { $ancho = max($ancho, strlen(vocPhp((string) $k))); }
    $out = '';
    foreach ($filas as $k => $v) {
        $out .= $sangria . str_pad(vocPhp((string) $k), $ancho) . ' => ' . $v . ",\n";
    }
    return $out;
}

/**
 * El rótulo y la ayuda de cada estado de la solicitud de repuesto.
 *
 * Los nueve estados anteriores a la 009 van todos a SOLICITUD_HEREDADA, pero
 * cada fila conserva su paso de entonces como detalle («trámite anterior a la
 * 009 · cotizando»): sin él, la administración no sabría en qué punto quedó.
 * El diccionario manda que el paso sea detalle y nunca estado propio.
 *
 * @return array<string,array{0:string,1:string}>
 */
function vocEstadosPendiente(): array
{
    $d = Vocabulario::todo();
    $out = [];
    foreach (Vocabulario::mapas()['pendiente'] as $estado => $clave) {
        $rotulo = Vocabulario::t($clave);
        if ($clave === 'SOLICITUD_HEREDADA') {
            $det = $d['detalle_pendiente_heredado'][$estado] ?? null;
            if (!is_string($det) || $det === '') {
                throw new VocabularioError("Vocabulario: el estado heredado $estado no tiene detalle_pendiente_heredado");
            }
            $rotulo .= ' · ' . $det;
        }
        $out[$estado] = [$rotulo, Vocabulario::ayuda($clave)];
    }
    return $out;
}

/** Lo que ui.js necesita para UI.T sin red: los textos de cada concepto y los
 *  mapas de estado, con la MISMA forma que el JSON (así, cuando llega el
 *  archivo de verdad, reemplaza al respaldo tal cual, sin traducirlo). Sin
 *  `reemplaza`, `notas` ni `roles`: son para las personas, no para la pantalla,
 *  y el técnico descarga esto con datos móviles. */
function vocRespaldoJs(): array
{
    $d = Vocabulario::todo();
    $con = [];
    foreach ($d['conceptos'] as $k => $c) {
        $f = ['termino' => $c['termino'], 'plural' => $c['plural'], 'titulo' => $c['titulo']];
        if (isset($c['corto'])) { $f['corto'] = $c['corto']; }
        $f['ayuda'] = $c['ayuda'];
        $con[$k] = $f;
    }
    return [
        'version'           => $d['version'],
        'conceptos'         => $con,
        'estados_caso'      => $d['estados_caso'],
        'estados_pendiente' => $d['estados_pendiente'],
        'estados_novedad'   => $d['estados_novedad'],
        'preventivo'        => ['estados' => $d['preventivo']['estados']],
        'via'               => $d['via'],
        'decision_kfc'      => $d['decision_kfc'],
        'estado_ot'         => $d['estado_ot'],
        'estado_equipo'     => $d['estado_equipo'],
        'zona'              => $d['zona'],
        'semaforo'          => $d['semaforo'],
    ];
}

function vocJson($v): string
{
    // Un objeto vacío tiene que salir como {} y no como [] (JSON_FORCE_OBJECT
    // estropearía las listas, y aquí no hay listas).
    if (is_array($v) && $v === []) { return '{}'; }
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** La copia pública del diccionario: el respaldo de ui.js en un archivo, un
 *  concepto por línea para que un cambio se lea en el diff. */
function vocPublicoJson(): string
{
    $r = vocRespaldoJs();
    $out = "{\n";
    $out .= '  "version": ' . vocJson($r['version']) . ",\n";
    $out .= "  \"conceptos\": {\n";
    $n = count($r['conceptos']); $i = 0;
    foreach ($r['conceptos'] as $k => $c) {
        $out .= '    ' . vocJson((string) $k) . ': ' . vocJson($c) . (++$i < $n ? ',' : '') . "\n";
    }
    $out .= "  },\n";
    $resto = array_diff_key($r, ['version' => 1, 'conceptos' => 1]);
    $n = count($resto); $i = 0;
    foreach ($resto as $k => $v) {
        $out .= '  ' . vocJson((string) $k) . ': ' . vocJson($v) . (++$i < $n ? ',' : '') . "\n";
    }
    return $out . "}\n";
}

/**
 * Los bloques, por archivo, sin las líneas de marca.
 *
 * @return array<string,array<string,string>>  [ruta relativa a publico/ => [bloque => texto]]
 */
function vocabularioBloques(): array
{
    $m = Vocabulario::mapas();

    // --- Ui.php -------------------------------------------------------------
    $filas = [];
    foreach ($m['caso'] as $estado => $clave) {
        $filas[$estado] = '[' . vocPhp(Vocabulario::t($clave)) . ', ' . vocPhp(Vocabulario::ayuda($clave)) . ']';
    }
    $uiEstados = "    public const ESTADOS = [\n" . vocFilas($filas) . "    ];\n";

    $filas = [];
    foreach ($m['zona'] as $codigo => $clave) {
        $filas[$codigo] = '[' . vocPhp(Vocabulario::corto($clave)) . ', ' . vocPhp(Vocabulario::t($clave))
                        . ', ' . vocPhp(Vocabulario::ayuda($clave)) . ']';
    }
    $uiZonas = "    private const ZONAS = [\n" . vocFilas($filas) . "    ];\n";

    // --- Pendientes.php ------------------------------------------------------
    $filas = [];
    foreach ($m['via'] as $via => $clave) {
        // El tercer elemento es el primer paso de la compra propia de antes de
        // la 009. No es vocabulario: se toma de PASOS para no escribirlo dos veces.
        $filas[$via] = '[' . vocPhp(Vocabulario::titulo($clave)) . ', ' . vocPhp(Vocabulario::ayuda($clave))
                     . ', self::PASOS[' . vocPhp((string) $via) . '][0]]';
    }
    $penVias = "    public const VIAS = [\n" . vocFilas($filas) . "    ];\n";

    $filas = [];
    foreach ($m['decision_kfc'] as $dec => $clave) {
        $filas[$dec] = vocPhp(Vocabulario::t($clave));
    }
    $penKfc = "    private const VEREDICTOS_KFC = [\n" . vocFilas($filas) . "    ];\n";

    $filas = [];
    foreach (vocEstadosPendiente() as $estado => [$rot, $ay]) {
        $filas[$estado] = '[' . vocPhp($rot) . ', ' . vocPhp($ay) . ']';
    }
    $penEstados = "    public const ESTADOS = [\n" . vocFilas($filas) . "    ];\n";

    // --- Novedades.php -------------------------------------------------------
    $filas = [];
    foreach ($m['novedad'] as $estado => $clave) {
        $filas[$estado] = '[' . vocPhp(Vocabulario::t($clave)) . ', ' . vocPhp(Vocabulario::ayuda($clave)) . ']';
    }
    $novEstados = "    public const ESTADOS = [\n" . vocFilas($filas) . "    ];\n";

    // --- ui.js ---------------------------------------------------------------
    // Un concepto por línea: que un cambio en el JSON se lea en el diff.
    $r = vocRespaldoJs();
    $js = "  var RESPALDO = {\n";
    $js .= '    "version": ' . vocJson($r['version']) . ",\n";
    $js .= "    \"conceptos\": {\n";
    $n = count($r['conceptos']); $i = 0;
    foreach ($r['conceptos'] as $k => $c) {
        $js .= '      ' . vocJson((string) $k) . ': ' . vocJson($c) . (++$i < $n ? ',' : '') . "\n";
    }
    $js .= "    },\n";
    $resto = array_diff_key($r, ['version' => 1, 'conceptos' => 1]);
    $n = count($resto); $i = 0;
    foreach ($resto as $k => $v) {
        $js .= '    ' . vocJson((string) $k) . ': ' . vocJson($v) . (++$i < $n ? ',' : '') . "\n";
    }
    $js .= "  };\n";

    return [
        'nucleo/Ui.php'         => ['ESTADOS' => $uiEstados, 'ZONAS' => $uiZonas],
        'nucleo/Pendientes.php' => ['VIAS' => $penVias, 'VEREDICTOS_KFC' => $penKfc, 'ESTADOS' => $penEstados],
        'nucleo/Novedades.php'  => ['ESTADOS' => $novEstados],
        'ui.js'                 => ['RESPALDO' => $js],
    ];
}

/**
 * Compara (y, si se pide, reescribe) cada bloque.
 *
 * @return list<array{archivo:string,bloque:string,estado:string}>  estado: igual | distinto | sin marcas
 */
function vocabularioAplicar(bool $escribir): array
{
    $informe = [];
    foreach (vocabularioBloques() as $rel => $bloques) {
        $ruta = VOC_PUBLICO . '/' . $rel;
        $texto = (string) file_get_contents($ruta);
        $nuevo = $texto;
        foreach ($bloques as $nombre => $contenido) {
            // La marca de apertura es la línea entera que contiene <vocabulario:NOMBRE>;
            // la de cierre, la que contiene </vocabulario:NOMBRE>. Lo de en medio se reemplaza.
            $re = '/(^[^\n]*<vocabulario:' . preg_quote($nombre, '/') . '>[^\n]*\n)(.*?)(^[^\n]*<\/vocabulario:'
                . preg_quote($nombre, '/') . '>[^\n]*$)/ms';
            if (!preg_match($re, $nuevo, $mm)) {
                $informe[] = ['archivo' => $rel, 'bloque' => $nombre, 'estado' => 'sin marcas'];
                continue;
            }
            // Los archivos del repositorio pueden venir con CRLF; se compara sin
            // eso para que un checkout de Windows no se lea como desviación.
            $igual = str_replace("\r\n", "\n", $mm[2]) === $contenido;
            $informe[] = ['archivo' => $rel, 'bloque' => $nombre, 'estado' => $igual ? 'igual' : 'distinto'];
            if (!$igual) {
                $eol = str_contains($mm[1], "\r\n") ? "\r\n" : "\n";
                $nuevo = preg_replace_callback($re, fn($x) => $x[1] . str_replace("\n", $eol, $contenido) . $x[3], $nuevo, 1);
            }
        }
        if ($escribir && $nuevo !== $texto) {
            file_put_contents($ruta, $nuevo);
        }
    }

    // La copia pública va entera: no tiene marcas, la escribe solo este script.
    $ruta = VOC_PUBLICO . '/vocabulario_publico.json';
    $contenido = vocPublicoJson();
    $actual = is_file($ruta) ? str_replace("\r\n", "\n", (string) file_get_contents($ruta)) : null;
    $igual = $actual === $contenido;
    $informe[] = ['archivo' => 'vocabulario_publico.json', 'bloque' => 'ARCHIVO',
                  'estado' => $igual ? 'igual' : ($actual === null ? 'sin marcas' : 'distinto')];
    if ($escribir && !$igual) {
        file_put_contents($ruta, $contenido);
        // Recién escrito: «regenerado», aunque antes no existiera (no es «sin marcas»).
        $informe[count($informe) - 1]['estado'] = 'distinto';
    }
    return $informe;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $comprobar = in_array('--comprobar', $argv, true);
    try {
        $inf = vocabularioAplicar(!$comprobar);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }
    $malos = 0;
    foreach ($inf as $f) {
        $ok = $f['estado'] === 'igual';
        if (!$ok) { $malos++; }
        $que = $ok ? 'al día' : ($f['estado'] === 'sin marcas' ? 'SIN MARCAS' : ($comprobar ? 'DESVIADO' : 'regenerado'));
        printf("  %-24s %-16s %s\n", $f['archivo'], $f['bloque'], $que);
    }
    echo 'Vocabulario ', Vocabulario::version(), ': ';
    if ($comprobar) {
        echo $malos ? "$malos bloque(s) no coinciden con vocabulario.json\n" : "todas las listas coinciden con vocabulario.json\n";
        exit($malos ? 1 : 0);
    }
    $sinMarcas = count(array_filter($inf, fn($f) => $f['estado'] === 'sin marcas'));
    echo $sinMarcas ? "$sinMarcas bloque(s) sin marcas: no se pudieron escribir\n" : "listas regeneradas\n";
    exit($sinMarcas ? 1 : 0);
}
