<?php
declare(strict_types=1);

/**
 * regenerar_pdf_piloto_cli.php — Los 14 PDF de las OT INDUSTEC del piloto,
 * otra vez, sin la franja «DOCUMENTO DE PRUEBA» (pedido de Andrés del
 * 28-sep-2026).
 *
 * POR QUÉ EXISTE
 * Del 24 al 27-sep-2026 los técnicos de UIO emitieron desde la app 14 OT en
 * el sitio de pruebas (OT-9125 a OT-9152, capturas 166, 171, 176 y 193-203).
 * Ninguna llegó a Grupo KFC. El 28-sep Andrés decidió que ningún PDF lleve ya
 * la franja (Emision::html() pasa `prueba = false` siempre) y que estos 14 se
 * regeneren sin ella, como COPIA INTERNA: el mismo número, los mismos datos,
 * fotos y firma, y la fecha de emisión ORIGINAL (`emitida_en`, no la de hoy).
 * En 6 de ellos, además, la fecha de atención se corrige: el técnico la emitió
 * días después y dejó la fecha del día, que no coincide con el inicio y el fin
 * de la visita. En 193 y 195 las propias horas se contradicen y en 176 la
 * fecha choca con la OT-1921 de producción: esos tres NO se corrigen.
 *
 * QUÉ ESCRIBE, POR CAPTURA, EN UNA TRANSACCIÓN (solo con --ejecutar)
 *   - La corrección (las 6): `carga.correccion_admin` {fecha_atencion, antes,
 *     por, en, motivo} con JSON_INSERT, al lado de `carga.fecha_atencion`, que
 *     queda tal cual la escribió el técnico. La leen Emision::fechaAtencion()
 *     —html() y archivo_indexar_cli.php—, así que sobrevive al índice nocturno
 *     y a cualquier regeneración futura.
 *   - El PDF nuevo en `ordenes_pdf/`, en el mismo nombre (temporal + rename).
 *   - `ot_capturadas.pdf_sha256_regen` = la huella nueva; `pdf_sha256` sigue
 *     siendo la del documento original (E-10).
 *   - `ot_archivo`: sha256, bytes y, en las 6, fecha_atencion.
 *   - Una fila REGENERAR_PDF en la bitácora, con usuario = esta consola y el
 *     pedido de Andrés en el detalle; la corrección de fecha, en antes/después
 *     y en `datos`.
 * Si algo falla dentro de la transacción, se revierte y, si el PDF ya se había
 * reemplazado, se repone desde el respaldo y se comprueba su huella.
 *
 * EL RESPALDO VA ANTES (--respaldar, y --ejecutar lo hace si falta)
 *   ~/respaldos/pdf_piloto_con_franja_20260928/: los 14 PDF actuales, su lista
 *   SHA256SUMS y `filas_antes.json` (las filas de ot_capturadas y ot_archivo
 *   tal como estaban). Nunca se sobrescribe: si ya está, se comprueba.
 *
 * GUARDAS. El simulacro (por omisión, SOLO LEE) comprueba todo lo que el
 * `--ejecutar` va a dar por cierto y aborta si algo no cuadra:
 *   - las 14 capturas existen, con su número, su aviso, EMITIDA, emitida_en y
 *     su huella; y las OT del piloto emitidas hasta el 27-sep son exactamente
 *     esas 14 (las posteriores se listan aparte y no se tocan);
 *   - en las 6, la fecha del técnico es la que Andrés dio como «antes», y el
 *     inicio y el fin de la carga caen EXACTAMENTE el día nuevo;
 *   - el PDF en disco es el original (su huella = pdf_sha256), las fotos que
 *     lista la orden están todas en disco y la fila del Archivo existe;
 *   - el HTML nuevo no trae la franja, trae la fecha de atención que vale y
 *     la fecha de emisión original.
 * Cada captura queda en uno de tres estados: PENDIENTE (todo como el día de la
 * emisión), HECHA (esta consola ya la regeneró: la segunda corrida no cambia
 * nada) o INCONSISTENTE (cualquier otra cosa: aborta sin escribir nada).
 *
 * DÓNDE VIVE. Como el resto de `pruebas/servidor/*.php`: se sube por scp a
 * `~/respaldos/` (fuera de la web) y se corre con el directorio de trabajo en
 * `ot/`. Por eso usa getcwd() y no __DIR__ (errores nº 42 y 47).
 *
 * USO (en el servidor, DESDE la carpeta de la app):
 *   cd domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot
 *   php ~/respaldos/regenerar_pdf_piloto_cli.php                # simulacro: solo lee
 *   php ~/respaldos/regenerar_pdf_piloto_cli.php --muestra=DIR  # simulacro + los PDF que saldrían, en DIR (fuera de ot/)
 *   php ~/respaldos/regenerar_pdf_piloto_cli.php --respaldar    # solo el respaldo
 *   php ~/respaldos/regenerar_pdf_piloto_cli.php --ejecutar     # respaldo + regeneración
 *
 * Código de salida: 0 bien; 1 no cuadra o falló (no se escribió nada de esa
 * captura ni de las siguientes); 3 falta el código nuevo en el sitio.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$APP = getcwd();
if (!is_file($APP . '/nucleo/Emision.php')) {
    fwrite(STDERR, "corre esta consola desde la carpeta de la app (ot/)\n");
    exit(3);
}
require_once $APP . '/nucleo/Db.php';
require_once $APP . '/nucleo/Emision.php';

const PEDIDO     = 'pedido de Andrés del 28-sep-2026';
const ACTOR      = 'regenerar_pdf_piloto_cli';          // bitacora.usuario, VARCHAR(40)
const SUBDIR     = 'respaldos/pdf_piloto_con_franja_20260928';
const FRANJA     = 'DOCUMENTO DE PRUEBA';
const HASTA_PEDIDO = '2026-09-27 23:59:59';             // las emitidas hasta aquí son «las 14»

/* Las 14, tal como las dio Andrés (captura → OT y aviso), y las 6 fechas:
   la que registró el técnico («antes») y la del inicio y el fin («nueva»). */
$REGENERAR = [
    166 => ['ot' => 'OT-9125-A010EC-10355442-UIO', 'aviso' => '10355442', 'fecha' => ['2026-09-24', '2026-09-18']],
    171 => ['ot' => 'OT-9128-I016EC-10355361-UIO', 'aviso' => '10355361', 'fecha' => ['2026-09-24', '2026-09-17']],
    176 => ['ot' => 'OT-9131-R001EC-10356680-UIO', 'aviso' => '10356680'],
    193 => ['ot' => 'OT-9142-R002EC-10339233-UIO', 'aviso' => '10339233'],
    194 => ['ot' => 'OT-9143-G015EC-10347336-UIO', 'aviso' => '10347336'],
    195 => ['ot' => 'OT-9144-R007EC-10347813-UIO', 'aviso' => '10347813'],
    196 => ['ot' => 'OT-9145-K170EC-10349426-UIO', 'aviso' => '10349426'],
    197 => ['ot' => 'OT-9146-G021EC-10354744-UIO', 'aviso' => '10354744'],
    198 => ['ot' => 'OT-9147-G013EC-10356500-UIO', 'aviso' => '10356500', 'fecha' => ['2026-09-26', '2026-09-24']],
    199 => ['ot' => 'OT-9148-R002EC-10356710-UIO', 'aviso' => '10356710'],
    200 => ['ot' => 'OT-9149-R007EC-10355280-UIO', 'aviso' => '10355280', 'fecha' => ['2026-09-26', '2026-09-17']],
    201 => ['ot' => 'OT-9150-R009EC-10356734-UIO', 'aviso' => '10356734', 'fecha' => ['2026-09-26', '2026-09-24']],
    202 => ['ot' => 'OT-9151-R014EC-10356794-UIO', 'aviso' => '10356794', 'fecha' => ['2026-09-27', '2026-09-24']],
    203 => ['ot' => 'OT-9152-G002EC-10355352-UIO', 'aviso' => '10355352'],
    // La 15.ª: ftipan la emitió el 28-sep a las 17:30, antes de desplegar el PDF
    // sin franja. Andrés: «de ahora en adelante ninguna orden salga con esa franja».
    // Su fecha de atención (25-ago) cuadra con el inicio y el fin: no se corrige.
    204 => ['ot' => 'OT-9001-K061EC-10346775-CNLJ', 'aviso' => '10346775'],
];

/* Lo que la copia cambia además de la franja y la fecha, dicho en la bitácora.
   La OT-9125 se emitió el 24-sep a las 10:15, antes de T2.28.2 (D-G): su PDF
   imprimía en «Correo de Jefe de Operaciones Local» el buzón de zona de
   INDUSTEC, que no es el contacto de Grupo KFC. La copia dice lo que dice toda
   OT emitida desde ese día. El original, con esa línea, queda en el respaldo. */
$NOTAS = [
    166 => 'La línea «Correo de Jefe de Operaciones Local» dice «sin configurar»: el original (emitido antes de '
         . 'T2.28.2, D-G) imprimía ahí jefezona-uio@industec.me, el buzón de zona de INDUSTEC, no el contacto de KFC. '
         . 'El original sigue en el respaldo.',
];

/* --- Opciones -------------------------------------------------------------- */
$modo = 'SIMULACRO';
$muestra = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--ejecutar')        { $modo = 'EJECUTAR'; }
    elseif ($a === '--respaldar')   { $modo = 'RESPALDAR'; }
    elseif (preg_match('/^--muestra=(.+)$/', $a, $m)) { $muestra = rtrim($m[1], '/'); }
    else { fwrite(STDERR, "opción no reconocida: $a\n"); exit(1); }
}
if ($muestra !== null && $modo !== 'SIMULACRO') {
    fwrite(STDERR, "--muestra es solo del simulacro\n"); exit(1);
}

function salir(string $motivo, int $codigo = 1): never
{
    echo "\nABORTA: $motivo\n";
    exit($codigo);
}

function corto(?string $h): string { return $h === null || $h === '' ? '—' : $h; }
function miles(int $n): string { return number_format($n, 0, ',', '.'); }

/* --- 0. El código del sitio es el nuevo ------------------------------------ */
if (!method_exists(Emision::class, 'fechaAtencion') || !method_exists(Emision::class, 'fechaEmision')) {
    salir('el nucleo/Emision.php de este sitio es el anterior al 28-sep-2026 (sin fechaAtencion/fechaEmision): '
        . 'despliega primero el de la rama', 3);
}
/* Solo en el sitio del piloto: en producción no hay serie 9000 que regenerar,
   y el modo es lo que separa un sitio del otro (revisión del 28-sep-2026). */
if (Emision::modo() !== 'PRUEBA') {
    salir('este sitio está en modo ' . Emision::modo() . ': la consola es solo para el sitio del piloto (modo PRUEBA)', 3);
}
$indexadorNuevo = str_contains((string) @file_get_contents($APP . '/archivo_indexar_cli.php'), 'Emision::fechaAtencion(');

$HOME = rtrim((string) (getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '')), '/');
if ($HOME === '') { salir('no se sabe cuál es la carpeta personal (HOME)'); }
$DIR_RESP = $HOME . '/' . SUBDIR;

echo "regenerar_pdf_piloto_cli · modo $modo · " . date('Y-m-d H:i:s') . " (hora de Ecuador)\n";
echo "sitio:    $APP\n";
echo "PDF en:   " . Emision::dirPdf() . "\n";
echo "respaldo: $DIR_RESP" . (is_dir($DIR_RESP) ? ' (ya existe)' : ' (todavía no existe)') . "\n";
echo "índice nocturno (archivo_indexar_cli.php) con la fecha corregida: " . ($indexadorNuevo ? 'sí' : 'NO — es el anterior') . "\n";

/* --- 1. El conjunto: las del pedido, y ninguna otra del piloto hasta el 27-sep
   Entran a la comparación las emitidas hasta HASTA_PEDIDO y, de cualquier
   fecha, las que la lista nombra: así, si Andrés suma una posterior (p. ej. la
   captura 204, emitida el 28-sep), basta con añadir su línea a $REGENERAR
   (revisión del 28-sep-2026: antes también había que mover HASTA_PEDIDO). */
$delPiloto = Db::todos(
    'SELECT captura_id, id_industec, emitida_en FROM ot_capturadas
      WHERE id_industec IS NOT NULL AND ' . Emision::sqlEsDePrueba('id_industec') . '
        AND emitida_en IS NOT NULL ORDER BY captura_id'
);
$hastaPedido = [];
$fuera = [];
foreach ($delPiloto as $f) {
    $k = (int) $f['captura_id'];
    if ((string) $f['emitida_en'] <= HASTA_PEDIDO || isset($REGENERAR[$k])) { $hastaPedido[$k] = (string) $f['id_industec']; }
    else { $fuera[] = $f; }
}
$esperado = array_map(fn($r) => $r['ot'], $REGENERAR);
$N = count($REGENERAR);
ksort($hastaPedido);
if ($hastaPedido !== $esperado) {
    echo "\nLas OT del piloto emitidas hasta el 27-sep (más las que nombra la lista) no son las $N del pedido:\n";
    foreach (array_diff_assoc($esperado, $hastaPedido) as $k => $v) { echo "  falta o cambió   captura $k: $v\n"; }
    foreach (array_diff_assoc($hastaPedido, $esperado) as $k => $v) { echo "  no está en el pedido  captura $k: $v\n"; }
    salir("el conjunto de $N no cuadra");
}
echo "\nconjunto: las $N del pedido son exactamente las OT del piloto emitidas hasta el 27-sep (y las que nombra la lista) ✓\n";
if ($fuera) {
    echo "fuera del pedido (NO se tocan; con la franja hasta que Andrés decida):\n";
    foreach ($fuera as $f) {
        echo "  captura {$f['captura_id']} · {$f['id_industec']} · emitida {$f['emitida_en']}\n";
    }
}

/* --- 2. Cada captura: qué hay, qué va a quedar, y en qué estado está -------- */
$plan = [];
$problemas = [];
foreach ($REGENERAR as $cid => $r) {
    $ot = $r['ot'];
    $p = ['cid' => $cid, 'ot' => $ot, 'estado' => null, 'por_que' => []];
    $mal = function (string $m) use (&$p) { $p['por_que'][] = $m; };

    $c = Db::uno('SELECT * FROM ot_capturadas WHERE captura_id = ?', [$cid]);
    if ($c === null) { $problemas[] = "captura $cid: no existe"; continue; }
    if ((string) $c['id_industec'] !== $ot)       { $mal("el número es {$c['id_industec']}, no $ot"); }
    if ((string) $c['aviso'] !== $r['aviso'])     { $mal("el aviso es {$c['aviso']}, no {$r['aviso']}"); }
    if (!Emision::esDePrueba($ot))                { $mal('no es de la serie del piloto'); }
    if ($c['estado'] !== 'EMITIDA')               { $mal("estado {$c['estado']}, no EMITIDA"); }
    if (empty($c['emitida_en']))                  { $mal('sin emitida_en'); }
    if (!preg_match('/^[0-9a-f]{64}$/', (string) $c['pdf_sha256'])) { $mal('sin pdf_sha256'); }
    $orden = json_decode((string) $c['carga'], true);
    if (!is_array($orden)) { $mal('la carga no es un JSON'); $orden = []; }

    $ruta = Emision::dirPdf() . '/' . $ot . '.pdf';
    $shaDisco = is_file($ruta) ? (string) hash_file('sha256', $ruta) : null;
    $bytesDisco = is_file($ruta) ? (int) filesize($ruta) : null;
    if ($shaDisco === null) { $mal('el PDF no está en disco'); }

    $arch = Db::uno('SELECT sha256, bytes, fecha_atencion, origen, en_servidor FROM ot_archivo WHERE id_industec = ?', [$ot]);
    if ($arch === null) { $mal('no tiene fila en ot_archivo'); }

    // Las fotos que lista la orden, todas en disco: la copia lleva las mismas.
    $fotos = Db::todos('SELECT ruta FROM ot_fotos WHERE envio_uuid = ?', [(string) $c['envio_uuid']]);
    $fotosEnDisco = count(array_filter($fotos, fn($f) => is_file(Emision::dirFotos() . '/' . $f['ruta'])));
    if ($fotosEnDisco !== count($fotos)) { $mal("faltan fotos en disco: $fotosEnDisco de " . count($fotos)); }

    // La fecha de atención: las 6 se corrigen, las otras 8 no se tocan.
    $fechaTec = (string) ($orden['fecha_atencion'] ?? '');
    $corr = $orden[Emision::CORRECCION] ?? null;
    $nueva = null;
    if (isset($r['fecha'])) {
        [$antes, $nueva] = $r['fecha'];
        if ($fechaTec !== $antes) { $mal("la fecha del técnico es «{$fechaTec}», no la $antes que dio Andrés"); }
        $ini = (string) ($orden['inicio'] ?? '');
        $fin = (string) ($orden['fin'] ?? '');
        if (substr($ini, 0, 10) !== $nueva || substr($fin, 0, 10) !== $nueva) {
            $mal("el inicio ($ini) y el fin ($fin) no caen el $nueva");
        }
        if ($corr !== null && !(is_array($corr) && ($corr['fecha_atencion'] ?? null) === $nueva && ($corr['antes'] ?? null) === $antes)) {
            $mal('ya tiene una correccion_admin distinta: ' . json_encode($corr, JSON_UNESCAPED_UNICODE));
        }
        $p['fecha'] = ['antes' => $antes, 'nueva' => $nueva, 'inicio' => $ini, 'fin' => $fin];
    } elseif ($corr !== null) {
        $mal('tiene una correccion_admin y no es de las 6: ' . json_encode($corr, JSON_UNESCAPED_UNICODE));
    }
    $yaCorregida = $nueva !== null && is_array($corr) && ($corr['fecha_atencion'] ?? null) === $nueva;

    $marca = Db::uno("SELECT id, cuando FROM bitacora
                       WHERE accion = 'REGENERAR_PDF' AND entidad = 'ot' AND referencia = ? AND usuario = ?
                       ORDER BY id DESC LIMIT 1", [$ot, ACTOR]);

    /* El estado. PENDIENTE: todo como el día de la emisión. HECHA: esta consola
       ya la regeneró (la marca en la bitácora, la huella nueva en disco y en la
       fila, la corrección puesta). Cualquier otra cosa es INCONSISTENTE. */
    $orig = (string) $c['pdf_sha256'];
    $regen = $c['pdf_sha256_regen'] !== null ? (string) $c['pdf_sha256_regen'] : null;
    if ($p['por_que'] === []) {
        if ($marca === null && $regen === null && $shaDisco === $orig && ($nueva === null || !$yaCorregida)) {
            $p['estado'] = 'PENDIENTE';
        } elseif ($marca !== null && $regen !== null && $regen !== $orig && $shaDisco === $regen
                  && ($nueva === null || $yaCorregida)) {
            $p['estado'] = 'HECHA';
            $indiceBien = $arch !== null && (string) $arch['sha256'] === $shaDisco && (int) $arch['bytes'] === $bytesDisco
                          && ($nueva === null || (string) $arch['fecha_atencion'] === $nueva);
            if (!$indiceBien) { $p['estado'] = 'HECHA_INDICE'; }   // la regeneración está; el Archivo quedó atrás
        } else {
            $mal(sprintf('estado a medias: bitácora %s, pdf_sha256_regen %s, huella en disco %s, corrección %s',
                         $marca ? "#{$marca['id']}" : 'sin fila', $regen ? substr($regen, 0, 12) . '…' : 'NULL',
                         $shaDisco === $orig ? '= la original' : ($shaDisco === $regen ? '= la regenerada' : 'distinta de las dos'),
                         $yaCorregida ? 'puesta' : 'sin poner'));
            /* El único corte que deja esto a medias: el proceso murió entre el
               rename del PDF nuevo y el commit (SSH caído, memoria). La base se
               revirtió sola al cerrarse la conexión; en disco quedó el PDF nuevo
               sin su fila. El remedio es el original del respaldo, y se dice cuál
               (revisión del 28-sep-2026). No se repone solo: un PDF en disco que
               nadie registró no se pisa sin que alguien lo mire. */
            $resp = $DIR_RESP . '/' . $ot . '.pdf';
            if ($marca === null && $regen === null && $shaDisco !== null && $shaDisco !== $orig
                && is_file($resp) && hash_file('sha256', $resp) === $orig) {
                $mal("parece una corrida cortada entre el reemplazo del PDF y el commit. Remedio: "
                     . "cp -p $resp $ruta && echo '$orig  $ruta' | sha256sum -c   (y otra vez el simulacro)");
            }
        }
    }

    // Lo que va a quedar: la orden con la corrección (en memoria) y su HTML.
    $objetivo = $orden;
    if ($nueva !== null && !$yaCorregida) {
        $objetivo[Emision::CORRECCION] = correccion($p['fecha'], $cid);
    }
    $p['fecha_vale'] = Emision::fechaAtencion($objetivo);
    $p['emision'] = Emision::fechaEmision((string) $c['emitida_en']);
    if ($p['por_que'] === []) {
        $html = Emision::html($c, $objetivo, $ot);
        $p['html'] = [
            'sin_franja' => !str_contains($html, FRANJA),
            'fecha'      => str_contains($html, '<b>Fecha de Atención:</b> ' . htmlspecialchars($p['fecha_vale'], ENT_QUOTES, 'UTF-8')),
            'emision'    => str_contains($html, 'generado automáticamente el ' . $p['emision']),
            'fotos'      => substr_count($html, 'data:image/jpeg;base64,'),
        ];
        if (!$p['html']['sin_franja']) { $mal('el HTML nuevo todavía trae la franja (¿Emision.php anterior?)'); }
        if (!$p['html']['fecha'])      { $mal("el HTML nuevo no dice la fecha de atención {$p['fecha_vale']}"); }
        if (!$p['html']['emision'])    { $mal("el HTML nuevo no dice la emisión original {$p['emision']}"); }
        if ($p['html']['fotos'] !== count($fotos)) { $mal("el HTML nuevo lleva {$p['html']['fotos']} fotos de " . count($fotos)); }
    }
    if ($p['por_que'] !== []) { $p['estado'] = 'INCONSISTENTE'; }

    $p += ['c' => $c, 'orden' => $orden, 'objetivo' => $objetivo, 'ruta' => $ruta, 'sha_disco' => $shaDisco,
           'bytes_disco' => $bytesDisco, 'arch' => $arch, 'fotos' => count($fotos), 'marca' => $marca,
           'carga_sha' => hash('sha256', (string) $c['carga'])];
    $plan[$cid] = $p;
}

if ($problemas) {
    foreach ($problemas as $m) { echo "  ✗ $m
"; }
    salir('faltan capturas del pedido');
}

/** La corrección de la administración que va dentro de la carga. */
function correccion(array $f, int $cid): array
{
    return [
        'fecha_atencion' => $f['nueva'],
        'antes'          => $f['antes'],
        'por'            => ACTOR . ' (' . PEDIDO . ')',
        'en'             => date('Y-m-d H:i:s'),
        'motivo'         => "La fecha de atención ({$f['antes']}) no coincidía con el inicio ({$f['inicio']}) "
                          . "y el fin ({$f['fin']}) de la visita; se corrige a {$f['nueva']}. "
                          . 'La que registró el técnico sigue en fecha_atencion.',
    ];
}

/* --- 3. Lo que se ve, captura por captura ---------------------------------- */
$cuenta = ['PENDIENTE' => 0, 'HECHA' => 0, 'HECHA_INDICE' => 0, 'INCONSISTENTE' => 0];
foreach ($plan as $cid => $p) {
    $cuenta[$p['estado']]++;
    $c = $p['c'];
    echo "\ncaptura $cid · {$p['ot']} · {$p['estado']}\n";
    printf("   huella actual   %s · %s B%s\n", corto($p['sha_disco']), miles((int) $p['bytes_disco']),
           $p['sha_disco'] === $c['pdf_sha256'] ? ' (= pdf_sha256, la original)'
           : ($p['sha_disco'] === $c['pdf_sha256_regen'] ? ' (= pdf_sha256_regen, ya regenerada)' : ''));
    printf("   emitida_en      %s → «generado automáticamente el %s»\n", $c['emitida_en'], $p['emision']);
    if (isset($p['fecha'])) {
        $f = $p['fecha'];
        printf("   fecha atención  %s → %s   (inicio %s · fin %s)\n", $f['antes'], $f['nueva'], $f['inicio'], $f['fin']);
    } else {
        printf("   fecha atención  %s (no se corrige)\n", $p['fecha_vale'] !== '' ? $p['fecha_vale'] : '—');
    }
    if (isset($p['html'])) {
        printf("   HTML nuevo      franja: %s · fecha: %s · emisión: %s · fotos %d/%d\n",
               $p['html']['sin_franja'] ? 'no' : 'SÍ', $p['html']['fecha'] ? 'ok' : 'MAL',
               $p['html']['emision'] ? 'ok' : 'MAL', $p['html']['fotos'], $p['fotos']);
    }
    if ($p['arch'] !== null && $p['estado'] !== 'INCONSISTENTE') {
        printf("   Archivo         fecha %s · huella %s\n", corto((string) $p['arch']['fecha_atencion']),
               (string) $p['arch']['sha256'] === $p['sha_disco'] ? '= la del disco' : 'DISTINTA de la del disco');
    }
    if (isset($NOTAS[$cid])) { echo "   nota            {$NOTAS[$cid]}\n"; }
    foreach ($p['por_que'] as $m) { echo "   ✗ $m\n"; }
}
echo "\n" . sprintf('PENDIENTE %d · HECHA %d · HECHA (falta el Archivo) %d · INCONSISTENTE %d',
                    $cuenta['PENDIENTE'], $cuenta['HECHA'], $cuenta['HECHA_INDICE'], $cuenta['INCONSISTENTE']) . "\n";
if ($cuenta['INCONSISTENTE'] > 0) { salir('hay capturas que no están ni como el día de la emisión ni regeneradas por esta consola: no se escribe nada'); }

/* --- 3b. Muestra: los PDF que saldrían, fuera de la app, sin tocar la base -- */
if ($muestra !== null) {
    $real = realpath(dirname($muestra)) ?: '';
    if ($real === '' || str_starts_with($real . '/', rtrim($APP, '/') . '/') || str_contains($real, 'public_html')) {
        salir('--muestra tiene que ser una carpeta fuera del sitio (ni ot/ ni public_html)');
    }
    if (!is_dir($muestra) && !mkdir($muestra, 0700, true)) { salir("no se pudo crear $muestra"); }
    echo "\nmuestra en $muestra:\n";
    foreach ($plan as $p) {
        $pdf = Emision::pdf($p['c'], $p['objetivo'], $p['ot']);
        file_put_contents($muestra . '/' . $p['ot'] . '.pdf', $pdf);
        printf("   %s · %s B · %s\n", $p['ot'], miles(strlen($pdf)), hash('sha256', $pdf));
    }
}

if ($modo === 'SIMULACRO') {
    echo "\nSIMULACRO: no se escribió nada. " . ($cuenta['PENDIENTE'] + $cuenta['HECHA_INDICE'] === 0
         ? 'Nada que hacer: las 14 ya están regeneradas.' : 'Para escribir: --respaldar y después --ejecutar.') . "\n";
    exit(0);
}

/* --- 4. El respaldo: los 14 PDF originales, su lista y las filas de antes --- */
function respaldar(array $plan, string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) { salir("no se pudo crear $dir"); }
    $lista = [];
    foreach ($plan as $p) {
        $orig = (string) $p['c']['pdf_sha256'];
        $dest = $dir . '/' . $p['ot'] . '.pdf';
        if (is_file($dest)) {
            if (hash_file('sha256', $dest) !== $orig) { salir("el respaldo $dest ya existe y no es el original"); }
        } elseif ($p['estado'] === 'PENDIENTE') {
            $tmp = $dest . '.tmp';
            if (!copy($p['ruta'], $tmp) || hash_file('sha256', $tmp) !== $orig || !rename($tmp, $dest)) {
                @unlink($tmp);
                salir("no se pudo respaldar {$p['ot']}");
            }
        } else {
            salir("{$p['ot']} ya está regenerada y su original no está en $dir");
        }
        $lista[$p['ot'] . '.pdf'] = $orig;
    }
    /* SHA256SUMS: lo que ya estaba se comprueba y no se toca; lo nuevo se suma.
       Así, si la lista crece después de una corrida (p. ej. la captura 204),
       el mismo respaldo sirve (28-sep-2026: antes abortaba «no coincide»). */
    $archSums = $dir . '/SHA256SUMS';
    $previa = [];
    if (is_file($archSums)) {
        foreach ((array) file($archSums, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            if (!preg_match('/^([0-9a-f]{64})  (\S+)$/', (string) $l, $m)) { salir("$archSums tiene una línea ilegible: $l"); }
            $previa[$m[2]] = $m[1];
        }
    }
    foreach ($lista as $nombre => $h) {
        if (isset($previa[$nombre]) && $previa[$nombre] !== $h) { salir("$archSums ya dice otra huella para $nombre"); }
    }
    $union = $previa + $lista;
    ksort($union);
    $sums = '';
    foreach ($union as $nombre => $h) { $sums .= "$h  $nombre\n"; }
    if (!is_file($archSums) || (string) file_get_contents($archSums) !== $sums) {
        if (file_put_contents($archSums . '.tmp', $sums) !== strlen($sums) || !rename($archSums . '.tmp', $archSums)) {
            salir("no se pudo escribir $archSums");
        }
    }
    /* Las filas de antes: `filas_antes.json` es el registro de la primera corrida
       y no se reescribe nunca. Las capturas que se sumen después van a un
       `filas_antes_<fecha>.json` aparte, y solo si todavía están como el día de
       la emisión: las de una ya regenerada no se pueden volver a tomar. */
    $tomadas = [];
    foreach ((array) glob($dir . '/filas_antes*.json') as $f) {
        $doc = json_decode((string) file_get_contents((string) $f), true);
        if (!is_array($doc['filas'] ?? null)) { salir("$f no se puede leer"); }
        $tomadas += array_fill_keys(array_keys($doc['filas']), basename((string) $f));
    }
    $filas = [];
    foreach ($plan as $p) {
        if (isset($tomadas[$p['ot']])) { continue; }
        if ($p['estado'] !== 'PENDIENTE') {
            salir("{$p['ot']} ya está regenerada y sus filas de antes no están en $dir: no se pueden volver a tomar");
        }
        $filas[$p['ot']] = ['ot_capturadas' => $p['c'], 'ot_archivo' => $p['arch']];
    }
    $archFilas = null;
    if ($filas !== []) {
        $archFilas = $dir . '/' . ($tomadas === [] ? 'filas_antes.json' : 'filas_antes_' . date('Ymd_His') . '.json');
        $json = json_encode(['tomado_en' => date('Y-m-d H:i:s'), 'pedido' => PEDIDO, 'filas' => $filas],
                            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($archFilas, $json) !== strlen($json)) {
            salir("no se pudo escribir $archFilas");
        }
    }
    chmod($dir, 0700);
    echo "\nrespaldo: " . count($lista) . ' PDF originales (' . (count($union) - count($previa)) . ' nuevos), SHA256SUMS con '
         . count($union) . ' y las filas de antes' . ($archFilas !== null ? ' en ' . basename($archFilas) : ' ya tomadas')
         . " en $dir ✓\n";
}
respaldar($plan, $DIR_RESP);
if ($modo === 'RESPALDAR') {
    echo "RESPALDAR: no se tocó nada más. Cópialo al PC y comprueba las huellas antes de --ejecutar.\n";
    exit(0);
}

/* --- 5. Ejecutar: una transacción por captura ------------------------------ */
if (!$indexadorNuevo) {
    salir('el archivo_indexar_cli.php del sitio es el anterior: esta noche devolvería la fecha vieja a ot_archivo. '
        . 'Despliégalo antes de --ejecutar');
}
$pdo = Db::conn();
$hechas = 0;
foreach ($plan as $cid => $p) {
    $ot = $p['ot'];
    if ($p['estado'] === 'HECHA') { continue; }
    if ($p['estado'] === 'HECHA_INDICE') {
        // Solo el Archivo, que quedó atrás (p. ej. lo reescribió un índice viejo).
        Db::ejecutar('UPDATE ot_archivo SET sha256 = ?, bytes = ?, fecha_atencion = COALESCE(?, fecha_atencion) WHERE id_industec = ?',
                     [$p['sha_disco'], $p['bytes_disco'], $p['fecha']['nueva'] ?? null, $ot]);
        echo "captura $cid · $ot · el Archivo, al día\n";
        continue;
    }
    $tmp = null;
    $reemplazado = false;
    try {
        $pdo->beginTransaction();
        $c = Db::uno('SELECT * FROM ot_capturadas WHERE captura_id = ? FOR UPDATE', [$cid]);
        if ($c === null || hash('sha256', (string) $c['carga']) !== $p['carga_sha'] || $c['pdf_sha256'] !== $p['c']['pdf_sha256']
            || $c['pdf_sha256_regen'] !== null || $c['emitida_en'] !== $p['c']['emitida_en']) {
            throw new RuntimeException('la fila cambió desde el simulacro');
        }
        if (hash_file('sha256', $p['ruta']) !== $c['pdf_sha256']) { throw new RuntimeException('el PDF en disco cambió desde el simulacro'); }
        $orden = $p['orden'];
        $f = $p['fecha'] ?? null;
        if ($f !== null) {
            $corr = correccion($f, $cid);
            $n = Db::ejecutar(
                "UPDATE ot_capturadas
                    SET carga = JSON_INSERT(carga, '$.correccion_admin',
                                            JSON_OBJECT('fecha_atencion', ?, 'antes', ?, 'por', ?, 'en', ?, 'motivo', ?))
                  WHERE captura_id = ? AND JSON_EXTRACT(carga, '$.correccion_admin') IS NULL",
                [$corr['fecha_atencion'], $corr['antes'], $corr['por'], $corr['en'], $corr['motivo'], $cid]
            );
            if ($n !== 1) { throw new RuntimeException("la corrección tocó $n filas, no 1"); }
            $nuevaCarga = json_decode((string) Db::uno('SELECT carga FROM ot_capturadas WHERE captura_id = ?', [$cid])['carga'], true);
            if (!is_array($nuevaCarga)) { throw new RuntimeException('la carga quedó ilegible'); }
            $sinCorr = $nuevaCarga;
            unset($sinCorr[Emision::CORRECCION]);
            // El registro del técnico, intacto: todo lo demás igual, fecha incluida.
            if ($sinCorr !== $orden) { throw new RuntimeException('JSON_INSERT cambió algo más que la corrección'); }
            if (($nuevaCarga['fecha_atencion'] ?? null) !== $f['antes']) { throw new RuntimeException('la fecha del técnico cambió'); }
            if (Emision::fechaAtencion($nuevaCarga) !== $f['nueva']) { throw new RuntimeException('la corrección no es la fecha que vale'); }
            $orden = $nuevaCarga;
        }
        $html = Emision::html($c, $orden, $ot);
        if (str_contains($html, FRANJA)) { throw new RuntimeException('el HTML trae la franja'); }
        if (!str_contains($html, 'generado automáticamente el ' . Emision::fechaEmision((string) $c['emitida_en']))) {
            throw new RuntimeException('el HTML no trae la emisión original');
        }
        $pdf = Emision::pdf($c, $orden, $ot);
        if (!str_starts_with($pdf, '%PDF-') || strlen($pdf) < 1024) { throw new RuntimeException('dompdf no devolvió un PDF'); }
        $sha = hash('sha256', $pdf);
        $bytes = strlen($pdf);
        $tmp = $p['ruta'] . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $pdf) !== $bytes) { throw new RuntimeException('no se pudo escribir el temporal'); }

        $n = Db::ejecutar('UPDATE ot_capturadas SET pdf_sha256_regen = ?
                            WHERE captura_id = ? AND pdf_sha256 = ? AND pdf_sha256_regen IS NULL',
                          [$sha, $cid, $c['pdf_sha256']]);
        if ($n !== 1) { throw new RuntimeException("pdf_sha256_regen tocó $n filas, no 1"); }
        $n = Db::ejecutar('UPDATE ot_archivo SET sha256 = ?, bytes = ?, fecha_atencion = COALESCE(?, fecha_atencion)
                            WHERE id_industec = ?', [$sha, $bytes, $f['nueva'] ?? null, $ot]);
        if ($n !== 1) { throw new RuntimeException("ot_archivo tocó $n filas, no 1"); }
        $detalle = 'PDF regenerado sin la franja «' . FRANJA . '», como copia interna: mismo número, datos, fotos, firma '
                 . 'y fecha de emisión (' . $c['emitida_en'] . '); ' . PEDIDO . '.'
                 . ($f !== null ? " Fecha de atención corregida {$f['antes']} → {$f['nueva']}: no coincidía con el inicio y el fin de la visita." : '')
                 . (isset($NOTAS[$cid]) ? ' ' . $NOTAS[$cid] : '');
        Db::ejecutar(
            "INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia, estado_antes, estado_despues,
                                   exito, detalle, datos, ip, equipo)
             VALUES (NULL, ?, 'REGENERAR_PDF', 'ot', ?, ?, ?, 1, ?, ?, NULL, 'consola')",
            [ACTOR, $ot, $f['antes'] ?? null, $f['nueva'] ?? null, $detalle,
             json_encode([
                 'pedido'           => PEDIDO,
                 'captura_id'       => $cid,
                 'sha256_original'  => $c['pdf_sha256'],
                 'sha256_nuevo'     => $sha,
                 'bytes_antes'      => $p['bytes_disco'],
                 'bytes_nuevo'      => $bytes,
                 'emitida_en'       => $c['emitida_en'],
                 'fecha_atencion'   => $f === null ? null : ['antes' => $f['antes'], 'despues' => $f['nueva'],
                                                             'inicio' => $f['inicio'], 'fin' => $f['fin']],
                 'respaldo'         => '~/' . SUBDIR . '/' . $ot . '.pdf',
                 'nota'             => $NOTAS[$cid] ?? null,
             ], JSON_UNESCAPED_UNICODE)]
        );
        if (!rename($tmp, $p['ruta'])) { throw new RuntimeException('no se pudo reemplazar el PDF'); }
        $tmp = null;
        $reemplazado = true;
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($tmp !== null) { @unlink($tmp); }
        if ($reemplazado) {
            $resp = $DIR_RESP . '/' . $ot . '.pdf';
            $repuesto = copy($resp, $p['ruta']) && hash_file('sha256', $p['ruta']) === $p['c']['pdf_sha256'];
            echo "captura $cid · $ot · el PDF original " . ($repuesto ? 'se repuso desde el respaldo ✓' : 'NO se pudo reponer: cópialo a mano desde ' . $resp) . "\n";
        }
        salir("captura $cid ($ot): " . $e->getMessage() . ". Esta captura quedó como estaba; las anteriores ya están hechas.");
    }
    // Después del commit, por consultas aparte: lo que quedó es lo que se quiso.
    $fila = Db::uno('SELECT pdf_sha256, pdf_sha256_regen FROM ot_capturadas WHERE captura_id = ?', [$cid]);
    $a = Db::uno('SELECT sha256, bytes, fecha_atencion FROM ot_archivo WHERE id_industec = ?', [$ot]);
    $ok = hash_file('sha256', $p['ruta']) === $sha && $fila['pdf_sha256'] === $p['c']['pdf_sha256']
          && $fila['pdf_sha256_regen'] === $sha && $a['sha256'] === $sha && (int) $a['bytes'] === $bytes
          && ($f === null || $a['fecha_atencion'] === $f['nueva']);
    printf("captura %d · %s · regenerada %s · %s B · %s%s\n", $cid, $ot, $ok ? '✓' : 'CON DIFERENCIAS', miles($bytes), $sha,
           $f !== null ? " · fecha {$f['antes']} → {$f['nueva']}" : '');
    if (!$ok) { salir("captura $cid: lo escrito no es lo esperado; revisar antes de seguir"); }
    $hechas++;
}
echo "\nEJECUTAR: $hechas regeneradas ahora; " . ($cuenta['HECHA'] ) . " ya lo estaban. Vuelve a correr el simulacro: las 14 deben salir HECHA.\n";
exit(0);
