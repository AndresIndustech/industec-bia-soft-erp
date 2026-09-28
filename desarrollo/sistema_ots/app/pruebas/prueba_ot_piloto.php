<?php
declare(strict_types=1);

/**
 * prueba_ot_piloto.php — La OT INDUSTEC del piloto no atiende la orden ni
 * cuenta como emitida, y la de producción la reemplaza (28-sep-2026).
 *
 * POR QUÉ EXISTE
 * Del 24 al 27-sep-2026 los técnicos de UIO emitieron desde la app 14 OT en el
 * sitio de pruebas (modo PRUEBA: serie 9000, correo RETENIDO) y las tomaron por
 * reales. Ninguna llegó a Grupo KFC. Doce órdenes quedaron ATENDIDO con una
 * OT-91xx como cierre y se marcaron cerradas en SAP; la del aviso 10356500
 * (OT-9147) sostiene una solicitud de repuesto. Decisión de Andrés: mientras
 * dure el piloto, esa OT no cierra nada y se marca en todas las pantallas.
 *
 * NO TOCA LA BASE NI LEE DATOS DE LA OPERACIÓN: órdenes sintéticas en memoria
 * (avisos 9100xxxx) pasadas a las funciones deterministas de `Casos` y
 * `Emision`. Lo que depende de MariaDB —el UPDATE de `atenderPorOrden()`, el
 * de `Reconciliar::atenciones()` y el CASE de `Pendientes::liberarCaso()`— se
 * comprueba aquí en su forma (qué SQL arman) y en el servidor, con un SELECT
 * de solo lectura que evalúa las mismas expresiones sobre las filas reales.
 *
 *   php pruebas/prueba_ot_piloto.php
 */

require_once __DIR__ . '/../publico/nucleo/Casos.php';
require_once __DIR__ . '/../publico/nucleo/Pendientes.php';
require_once __DIR__ . '/../publico/nucleo/Vocabulario.php';

$fallos = 0;
$total  = 0;

function afirmar(string $que, $real, $esperado): void
{
    global $fallos, $total;
    $total++;
    $bien = $real === $esperado;
    if (!$bien) { $fallos++; }
    printf("  %-72s %-14s %s\n", $que,
           is_bool($real) ? ($real ? 'true' : 'false')
                          : ($real === null ? 'null' : (is_scalar($real) ? (string) $real : json_encode($real))),
           $bien ? 'ok' : 'FALLA (esperaba ' . var_export($esperado, true) . ')');
}

/* -------------------------------------------------------------------------
   1. El criterio: Emision::esDePrueba() por el número.
   ------------------------------------------------------------------------- */
echo "=== 1. Emision::esDePrueba() ===\n";
$casosId = [
    'OT-9147-G013EC-10356500-UIO'   => true,    // la del reclamo
    'OT-9000-A010EC-UIO'            => true,    // el primer número de la serie
    'OT-9152-G002EC-10355352-UIO'   => true,
    'OT-12000-K146EC-10352088-CNLJ' => true,    // cinco cifras también
    'OT-09147-G013EC-10356500-UIO'  => true,    // con cero a la izquierda
    'ot-9147-g013ec-10356500-uio'   => true,    // en minúsculas
    '  OT-9147-G013EC-10356500-UIO ' => true,   // con espacios
    'OT-8999-G013EC-10356500-UIO'   => false,   // el último antes de la serie
    'OT-2621-K061EC-10351229-CNLJ'  => false,   // el más alto de producción al 28-sep
    'OT-1911-G013-10355811-UIO'     => false,   // producción, nombre del correo
    'OT-0005-K061EC-D5-CNLJ'        => false,   // preventivo sin aviso
    'OT-Cajun-10280653-CNLJ-023'    => false,   // el segundo patrón de la empresa
    ''                              => false,
    'OT-'                           => false,
    '9147'                          => false,
];
foreach ($casosId as $id => $esp) {
    afirmar('esDePrueba(' . var_export($id, true) . ')', Emision::esDePrueba((string) $id), $esp);
}
afirmar('esDePrueba(null)', Emision::esDePrueba(null), false);

/* El SQL tiene que decir lo mismo que el PHP: se traduce la expresión REGEXP a
   PCRE (MariaDB usa PCRE2 desde la 10.0.5) y se compara número por número. */
afirmar('SERIE_PRUEBA sigue en 9000 (la expresión SQL está escrita para ese valor)', Emision::SERIE_PRUEBA, 9000);
preg_match("/REGEXP '([^']+)'/", Emision::sqlEsDePrueba('x'), $m);
$pcre = '/' . str_replace('/', '\/', $m[1] ?? '') . '/i';
$discrepan = [];
foreach (array_merge(range(0, 12000), [99999, 100000]) as $n) {
    foreach ([(string) $n, str_pad((string) $n, 4, '0', STR_PAD_LEFT), '0' . $n] as $num) {
        $id = "OT-$num-G013EC-10356500-UIO";
        if ((bool) preg_match($pcre, $id) !== Emision::esDePrueba($id)) { $discrepan[] = $id; }
    }
}
foreach (array_keys($casosId) as $id) {
    if ((bool) preg_match($pcre, strtoupper(trim((string) $id))) !== Emision::esDePrueba((string) $id)) { $discrepan[] = $id; }
}
afirmar('sqlEsDePrueba() y esDePrueba() coinciden del 0 al 12000 (con y sin ceros)', array_slice($discrepan, 0, 5), []);
afirmar('sqlEsDePrueba() pone la columna que se le pide', str_starts_with(Emision::sqlEsDePrueba('g.ot_cierre'), '(g.ot_cierre REGEXP'), true);

/* -------------------------------------------------------------------------
   2. La OT del piloto no cuenta como emitida (indiceInformes / grupoOrden).
   ------------------------------------------------------------------------- */
echo "\n=== 2. La tarjeta: la del piloto no saca la orden de «a espera de informe técnico» ===\n";
$casos = []; $gestion = [];
$orden = static function (string $aviso, ?array $g) use (&$casos, &$gestion): void {
    $casos[] = ['aviso' => $aviso, 'zona' => 'UIO', 'fecha_creacion' => '2026-09-25', 'estado_alerta' => 'SIN_ALERTA'];
    if ($g !== null) { $gestion[$aviso] = $g + ['aviso' => $aviso]; }
};
$orden('91000001', ['estado' => 'ASIGNADO', 'asignado_a' => 22, 'asignado_en' => '2026-09-26 13:00:00']); // solo OT del piloto (app)
$orden('91000002', ['estado' => 'RESUELTO', 'ot_cierre' => 'OT-9142-R002EC-91000002-UIO']);            // las doce: cerrada en SAP con una del piloto
$orden('91000003', ['estado' => 'ASIGNADO', 'asignado_a' => 22, 'ot_cierre' => 'OT-9143-G015EC-91000003-UIO']); // ot_cierre del piloto sin atender
$orden('91000004', ['estado' => 'ASIGNADO', 'asignado_a' => 22]);                                      // piloto + producción en el Archivo
$orden('91000005', ['estado' => 'ESPERA_REPUESTO', 'asignado_a' => 22]);                               // el 10356500: solicitud abierta desde la del piloto
$orden('91000006', ['estado' => 'ASIGNADO', 'asignado_a' => 22]);                                      // solo la fila APP del piloto en el Archivo
$orden('91000007', ['estado' => 'ASIGNADO', 'asignado_a' => 22]);                                      // producción por el correo
$orden('91000008', ['estado' => 'ASIGNADO', 'asignado_a' => 22]);                                      // captura sin id_industec (como antes)

$aten = ['91000007' => ['ots' => [['ot' => 'OT-1940-G021-91000007-UIO', 'estado_ot' => 'Cerrada', 'estado_equipo' => 'Operativo', 'fecha' => '2026-09-27']]]];
$capturadas = [
    ['aviso' => '91000001', 'id_industec' => 'OT-9147-G013EC-91000001-UIO', 'estado' => 'EMITIDA', 'emitida_en' => '2026-09-26 13:59:01',
     'equipos' => json_encode([['estado' => 'Operativo']])],
    ['aviso' => '91000004', 'id_industec' => 'OT-9148-R002EC-91000004-UIO', 'estado' => 'EMITIDA', 'emitida_en' => '2026-09-26 14:05:16',
     'equipos' => json_encode([['estado' => 'Deshabilitado']])],
    ['aviso' => '91000005', 'id_industec' => 'OT-9131-R001EC-91000005-UIO', 'estado' => 'EMITIDA', 'emitida_en' => '2026-09-24 11:00:00',
     'equipos' => json_encode([['estado' => 'Deshabilitado']])],
    ['aviso' => '91000008', 'estado' => 'EMITIDA', 'emitida_en' => '2026-09-20 10:00:00', 'equipos' => json_encode([['estado' => 'Operativo']])],
];
$archivo = [
    ['aviso' => '91000004', 'id_industec' => 'OT-1930-R002EC-91000004-UIO', 'fecha_atencion' => '2026-09-27'],
    ['aviso' => '91000006', 'id_industec' => 'OT-9150-R009EC-91000006-UIO', 'fecha_atencion' => '2026-09-26'],
];
$pendientes = [];
$inf = Casos::indiceInformes($gestion, $aten, $capturadas, $archivo, $pendientes, '2026-09-28 06:00');
$grupo = static fn(string $a) => Casos::grupoOrden(['aviso' => $a], $gestion[$a] ?? null, $inf);

afirmar('solo OT del piloto (app) → a espera de informe técnico', $grupo('91000001'), 'ESPERA_INFORME');
afirmar('  … no cuenta como OT emitida', Casos::tieneOT('91000001', $inf), false);
afirmar('  … pero queda la marca para mostrarla', Casos::tienePiloto('91000001', $inf), true);
afirmar('  … y su «Operativo» no es evidencia del equipo', Casos::estadoEquipo('91000001', $inf), null);
afirmar('RESUELTO con OT de cierre del piloto → fuera del total (no se toca lo que puso Isabel)', $grupo('91000002'), null);
afirmar('  … y esa OT de cierre no cuenta como emitida', Casos::tieneOT('91000002', $inf), false);
afirmar('ASIGNADO con ot_cierre del piloto → a espera de informe técnico', $grupo('91000003'), 'ESPERA_INFORME');
afirmar('piloto + OT de producción en el Archivo → órdenes abiertas', $grupo('91000004'), 'ABIERTA');
afirmar('  … el equipo lo dice la de producción, no la del piloto (sin dato aquí)', Casos::estadoEquipo('91000004', $inf), null);
afirmar('ESPERA_REPUESTO abierta desde una del piloto → órdenes abiertas (D-A, por la solicitud)', $grupo('91000005'), 'ABIERTA');
afirmar('  … y la OT del piloto sigue sin contar como emitida', Casos::tieneOT('91000005', $inf), false);
afirmar('solo la fila APP del piloto en el Archivo → a espera de informe técnico', $grupo('91000006'), 'ESPERA_INFORME');
afirmar('OT de producción por el correo → órdenes abiertas (como siempre)', $grupo('91000007'), 'ABIERTA');
afirmar('captura sin id_industec (filas de antes) se lee como antes: emitida', $grupo('91000008'), 'ABIERTA');

$tz = Casos::tarjetasPorZona($casos, $gestion, $inf, '2026-09-28');
afirmar('UIO: a espera de informe técnico = 91000001, 91000003, 91000006', $tz['zonas']['UIO']['espera_informe'], 3);
afirmar('UIO: órdenes abiertas = 91000004, 91000005, 91000007, 91000008', $tz['zonas']['UIO']['abiertas'], 4);
afirmar('UIO: cerradas en SAP (la RESUELTO del piloto se queda) = 1', $tz['zonas']['UIO']['cerradas_sap'], 1);

/* -------------------------------------------------------------------------
   3. Los documentos del aviso: la marca, cuál vale y el mismo informe dos veces.
   ------------------------------------------------------------------------- */
echo "\n=== 3. Casos::marcarDocumentos(): marca del piloto, «la que vale» y duplicados ===\n";
$conPdf = ['OT-9147-G013EC-91000010-UIO' => true, 'OT-1935-G013EC-91000010-UIO' => true];
$hayPdf = static fn(string $ot): bool => $conPdf[$ot] ?? false;
$docs = Casos::marcarDocumentos([
    '91000010' => [
        'OT-9147-G013EC-91000010-UIO' => ['ot' => 'OT-9147-G013EC-91000010-UIO', 'fecha' => '2026-09-26', 'cierre' => true],  // ot_cierre del piloto
        'OT-1935-G013-91000010-UIO'   => ['ot' => 'OT-1935-G013-91000010-UIO',   'fecha' => '2026-09-28', 'cierre' => true],  // CORREO
        'OT-1935-G013EC-91000010-UIO' => ['ot' => 'OT-1935-G013EC-91000010-UIO', 'fecha' => '2026-09-27', 'cierre' => false], // HISTORICO
    ],
    '91000011' => [
        'OT-9131-R001EC-91000011-UIO' => ['ot' => 'OT-9131-R001EC-91000011-UIO', 'fecha' => '2026-09-24', 'cierre' => false],
    ],
    '91000012' => [
        'OT-1800-K170-91000012-UIO'   => ['ot' => 'OT-1800-K170-91000012-UIO', 'fecha' => '2026-08-20', 'cierre' => false],
        'OT-1850-K170-91000012-UIO'   => ['ot' => 'OT-1850-K170-91000012-UIO', 'fecha' => '2026-08-30', 'cierre' => true],
    ],
    '91000013' => [
        'OT-9145-K170EC-91000013-UIO' => ['ot' => 'OT-9145-K170EC-91000013-UIO', 'fecha' => '2026-09-26', 'cierre' => false],
        'OT-1724-K170-91000013-UIO'   => ['ot' => 'OT-1724-K170-91000013-UIO', 'fecha' => '2026-08-20', 'cierre' => true],  // otra visita, anterior
        'OT-1950-K170-91000013-UIO'   => ['ot' => 'OT-1950-K170-91000013-UIO', 'fecha' => '2026-09-27', 'cierre' => false], // evaluación, después
        'OT-1960-K170-91000013-UIO'   => ['ot' => 'OT-1960-K170-91000013-UIO', 'fecha' => '2026-09-29', 'cierre' => true],  // cierre, después
    ],
    // Como el 10339233 el 28-sep: la del piloto del 26-sep y una de producción
    // de julio, de otra visita.
    '91000014' => [
        'OT-9142-R002EC-91000014-UIO' => ['ot' => 'OT-9142-R002EC-91000014-UIO', 'fecha' => '2026-09-26', 'cierre' => true],
        'OT-1523-R002-91000014-UIO'   => ['ot' => 'OT-1523-R002-91000014-UIO', 'fecha' => '2026-07-06', 'cierre' => false],
    ],
], $hayPdf);
$d10 = $docs['91000010'];
afirmar('el mismo informe por el correo y del árbol es UN documento (quedan 2 de 3)', count($d10), 2);
$prod = array_values(array_filter($d10, fn($d) => !$d['prueba']))[0] ?? [];
$pil  = array_values(array_filter($d10, fn($d) => $d['prueba']))[0] ?? [];
afirmar('  … con el nombre cuyo PDF está (el del árbol, con EC)', $prod['ot'] ?? null, 'OT-1935-G013EC-91000010-UIO');
afirmar('  … la fecha más temprana de las dos', $prod['fecha'] ?? null, '2026-09-27');
afirmar('  … y «de cierre» si alguna de las dos lo era', $prod['cierre'] ?? null, true);
afirmar('la del piloto lleva la marca', $pil['prueba'] ?? null, true);
afirmar('la del piloto nunca es «de cierre», aunque fuera la ot_cierre', $pil['cierre'] ?? null, false);
afirmar('la de producción es la que vale', $prod['vale'] ?? null, true);
afirmar('otQueVale() la devuelve', Casos::otQueVale($d10), 'OT-1935-G013EC-91000010-UIO');
afirmar('solo la del piloto: no vale ninguna todavía', Casos::otQueVale($docs['91000011']), null);
afirmar('  … y no hay de producción', Casos::hayDeProduccion($docs['91000011']), false);
afirmar('sin nada del piloto no se marca «la que vale» (la pantalla no cambia)',
        array_column($docs['91000012'], 'vale'), [false, false]);
afirmar('  … y hay de producción', Casos::hayDeProduccion($docs['91000012']), true);
afirmar('piloto + evaluación y cierre posteriores: vale la de cierre posterior, no la de agosto',
        Casos::otQueVale($docs['91000013']), 'OT-1960-K170-91000013-UIO');
afirmar('solo una de producción ANTERIOR (otra visita): no vale ninguna (caso 10339233)',
        Casos::otQueVale($docs['91000014']), null);
afirmar('  … aunque sí hay de producción en el aviso', Casos::hayDeProduccion($docs['91000014']), true);

/* -------------------------------------------------------------------------
   4. Lo que depende de MariaDB: qué SQL arman (la semántica se prueba en el
      servidor con un SELECT de solo lectura, ver ESTADO.md).
   ------------------------------------------------------------------------- */
echo "\n=== 4. Las rutas que escriben: la del piloto no atiende, la de producción la reemplaza ===\n";
$src = static fn(string $f): string => (string) file_get_contents(__DIR__ . '/../publico/' . $f);
$casosPhp = $src('nucleo/Casos.php');
$fn = substr($casosPhp, (int) strpos($casosPhp, 'public static function atenderPorOrden'));
$fn = substr($fn, 0, (int) strpos($fn, 'public static function alcanzaAviso'));
afirmar('atenderPorOrden: la del piloto se trata como «sin concluir» (solo asigna)',
        (bool) preg_match('/\$esPiloto = Emision::esDePrueba\(\$idIndustec\);\s*if \(\$esPiloto\) \{ \$concluida = false; \}/', $fn), true);
afirmar('atenderPorOrden: la de producción reemplaza una ot_cierre del piloto (no COALESCE)',
        str_contains($fn, 'ot_cierre    = IF(ot_cierre IS NULL OR $piloto, ?, ot_cierre)') && !str_contains($fn, 'COALESCE(ot_cierre, ?)'), true);
afirmar('atenderPorOrden: atendido_en se evalúa ANTES de reescribir ot_cierre',
        strpos($fn, 'SET atendido_en') !== false && strpos($fn, 'SET atendido_en') < strpos($fn, 'ot_cierre    = IF('), true);
afirmar('atenderPorOrden: deja dicho qué OT del piloto reemplazó', str_contains($fn, "'OT_CIERRE_REEMPLAZA_PILOTO'"), true);

$rec = $src('nucleo/Reconciliar.php');
afirmar('Reconciliar: la OT del piloto del correo no cuenta como cierre',
        str_contains($rec, "=== 'Cerrada' && !Emision::esDePrueba("), true);
afirmar('Reconciliar: en una orden intocable (RESUELTO) solo cambia el número, no el estado',
        str_contains($rec, "'UPDATE casos_gestion SET ot_cierre = ? WHERE aviso = ? AND ot_cierre = ?'"), true);

$pen = $src('nucleo/Pendientes.php');
afirmar('Pendientes::liberarCaso: una ot_cierre del piloto no devuelve la orden a ATENDIDO',
        str_contains($pen, "CASE WHEN ot_cierre IS NOT NULL AND NOT \$piloto THEN 'ATENDIDO'"), true);
afirmar('Pendientes::resolverPorOrden(OT del piloto) = 0, sin tocar la base',
        Pendientes::resolverPorOrden(['91000005'], null, 'OT-9131-R001EC-91000005-UIO', 22), 0);

$env = $src('envio.php');
afirmar('envio.php: con la del piloto solo el aviso propio, sin arrastrar la cadena',
        str_contains($env, 'foreach (($piloto ? [$aviso] : $cadena) as $unAviso)'), true);
afirmar('envio.php: la del piloto no resuelve solicitudes',
        str_contains($env, "if (\$concluida && \$aviso !== '' && !\$piloto && method_exists('Pendientes', 'resolverPorOrden'))"), true);
afirmar('envio.php: la del piloto sí puede ABRIR la solicitud (Pendientes::abrir sin guarda)',
        (bool) preg_match('/\[\$ok, \$msg, \$idPen\] = Pendientes::abrir\(/', $env) && !str_contains($env, '!$piloto) {' . "\n" . '                [$ok'), true);
afirmar("envio.php: el recibo conserva 'estado' EMITIDA y agrega 'prueba'",
        str_contains($env, "'estado'      => \$em['pdf'] ? 'EMITIDA' : 'RECIBIDA',") && str_contains($env, "'prueba'      => \$piloto,"), true);
afirmar('envio.php: que_sigue pide emitirla también por el formulario de siempre',
        str_contains($env, 'Emítela hoy también por el formulario de siempre; la que vale es esa.'), true);

$ord = $src('ordenes.php');
afirmar('ordenes.php: el servidor no comparte una OT del piloto (el POST se fabrica a mano)',
        (bool) preg_match('/if \(Emision::esDePrueba\(\$ot\)\) \{\s*Auth::bitacora\(\'DENEGADO\'/', $ord), true);
afirmar('ordenes.php: ni pinta «Compartir» en esas filas', str_contains($ord, '<?php if ($puedeCompartir && !$piloto): ?>'), true);

/* -------------------------------------------------------------------------
   5. El diccionario.
   ------------------------------------------------------------------------- */
echo "\n=== 5. Vocabulario ===\n";
afirmar('OT_PILOTO existe con su término', Vocabulario::t('OT_PILOTO'), 'OT INDUSTEC del piloto');
afirmar('OT_PILOTO corto (el chip)', Vocabulario::corto('OT_PILOTO'), 'del piloto · no enviada a KFC');
afirmar('ENVIO_EMITIDA ya no afirma que el correo salió siempre',
        str_contains(Vocabulario::ayuda('ENVIO_EMITIDA'), 'la del piloto (serie 9000) no sale a nadie'), true);
$pub = json_decode((string) file_get_contents(__DIR__ . '/../publico/vocabulario_publico.json'), true);
afirmar('la copia pública trae OT_PILOTO (la usan app.js y cola.js)', ($pub['conceptos']['OT_PILOTO']['titulo'] ?? null), 'OT INDUSTEC del piloto');
afirmar('sw.js subió de versión', (bool) preg_match("/const VERSION = 'ot-industec-v24'/", $src('sw.js')), true);

printf("\n%d comprobaciones · %d fallos\n", $total, $fallos);
exit($fallos === 0 ? 0 : 1);
