<?php
declare(strict_types=1);

/**
 * reconciliar_cli.php — Corre la reconciliación desde la línea de órdenes.
 *
 * Dos pasos, y el segundo NO se ejecuta sin pedirlo:
 *
 *   1. Enlazar cada caso atendido con quien lo atendió (siempre).
 *   2. Cerrar por falta de atención lo que lleva más de N días sin informe.
 *      Por omisión solo cuenta. Para escribir hay que pasar `--ejecutar`, y
 *      antes imprime cuántos va a tocar.
 *
 * El paso 2 cambia el estado de cientos de casos de una vez. Un `--ejecutar`
 * explícito y un recuento previo es lo mínimo antes de una escritura así: si el
 * catálogo llegó incompleto, el número se ve raro y se para a tiempo.
 *
 * Solo CLI.
 *
 * Uso:
 *   php reconciliar_cli.php                 # enlaza y cuenta, no cierra
 *   php reconciliar_cli.php --ejecutar      # cierra de verdad
 *   php reconciliar_cli.php --dias 14 --ejecutar
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Reconciliar.php';
require_once __DIR__ . '/nucleo/Vocabulario.php';

$args = $argv;
$ejecutar = in_array('--ejecutar', $args, true);
$dias = 7;
$i = array_search('--dias', $args, true);
if ($i !== false && isset($args[$i + 1])) { $dias = max(1, (int) $args[$i + 1]); }

$catalogo = Casos::catalogo();
$casos = $catalogo['datos'] ?? [];
$aten = Casos::atenciones();

if ($casos === []) {
    fwrite(STDERR, "No hay catálogo de órdenes. No se toca nada.\n");
    exit(1);
}
echo 'órdenes en el catálogo : ' . count($casos) . "\n";
echo 'con OT INDUSTEC         : ' . count($aten) . "\n\n";

echo "1. Enlazando cada orden con OT INDUSTEC con su técnico...\n";
$r1 = Reconciliar::atenciones($aten);
echo '   con ' . Vocabulario::t('OT_CIERRE') . '   : ' . $r1['atendidos'] . "\n";
echo '   sin técnico legible             : ' . $r1['sin_tecnico'] . "\n\n";

echo "2. Cerrar sin atención (más de $dias días sin ninguna OT INDUSTEC)\n";
$r2 = Reconciliar::cerrarSinAtencion($casos, $aten, $dias, $ejecutar);
echo '   corte              : llegadas antes del ' . $r2['corte'] . "\n";
echo '   candidatas         : ' . $r2['candidatos'] . "\n";
if ($ejecutar) {
    echo '   ' . str_pad(Vocabulario::t('CERRADA_SIN_ATENCION', 2), 19) . ': ' . $r2['cerrados'] . "\n";
} else {
    echo "   NO se escribio nada. Agrega --ejecutar para aplicarlo.\n";
}

echo "\nEstado de la tabla:\n";
// El código de la base y, al lado, su nombre en el diccionario: quien lee la
// consola es la misma persona que después ve la pantalla.
foreach (Db::todos('SELECT estado, COUNT(*) n FROM casos_gestion GROUP BY estado ORDER BY n DESC') as $x) {
    // Un estado que el diccionario no conoce no tumba la consola: se dice (I-7).
    try { $txt = Vocabulario::t(Vocabulario::deEstado((string) $x['estado'])); }
    catch (VocabularioError $ex) { $txt = '(sin concepto en el diccionario)'; }
    printf("   %-22s %-30s %5d\n", $x['estado'], $txt, $x['n']);
}
$p = Db::uno("SELECT COUNT(*) n FROM casos_gestion
               WHERE estado = 'CERRADO_SIN_ATENCION' AND regularizado_en IS NULL");
echo '   ' . Vocabulario::t('SIN_REGULARIZAR', 2) . ': ' . $p['n'] . "\n";
