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

$args = $argv;
$ejecutar = in_array('--ejecutar', $args, true);
$dias = 7;
$i = array_search('--dias', $args, true);
if ($i !== false && isset($args[$i + 1])) { $dias = max(1, (int) $args[$i + 1]); }

$catalogo = Casos::catalogo();
$casos = $catalogo['datos'] ?? [];
$aten = Casos::atenciones();

if ($casos === []) {
    fwrite(STDERR, "No hay catalogo de casos. No se toca nada.\n");
    exit(1);
}
echo 'casos en el catalogo : ' . count($casos) . "\n";
echo 'con atencion         : ' . count($aten) . "\n\n";

echo "1. Enlazando los atendidos con su tecnico...\n";
$r1 = Reconciliar::atenciones($aten);
echo '   con orden de cierre : ' . $r1['atendidos'] . "\n";
echo '   sin tecnico legible : ' . $r1['sin_tecnico'] . "\n\n";

echo "2. Cierre por falta de atencion (mas de $dias dias sin informe)\n";
$r2 = Reconciliar::cerrarSinAtencion($casos, $aten, $dias, $ejecutar);
echo '   corte              : creados antes del ' . $r2['corte'] . "\n";
echo '   candidatos         : ' . $r2['candidatos'] . "\n";
if ($ejecutar) {
    echo '   cerrados           : ' . $r2['cerrados'] . "\n";
} else {
    echo "   NO se escribio nada. Agrega --ejecutar para aplicarlo.\n";
}

echo "\nEstado de la tabla:\n";
foreach (Db::todos('SELECT estado, COUNT(*) n FROM casos_gestion GROUP BY estado ORDER BY n DESC') as $x) {
    printf("   %-22s %5d\n", $x['estado'], $x['n']);
}
$p = Db::uno("SELECT COUNT(*) n FROM casos_gestion
               WHERE estado = 'CERRADO_SIN_ATENCION' AND regularizado_en IS NULL");
echo '   pendientes de regularizar: ' . $p['n'] . "\n";
