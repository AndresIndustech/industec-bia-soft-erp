<?php
declare(strict_types=1);

/**
 * Corre el fixture compartido contra la implementacion PHP.
 *
 * El mismo archivo lo corre el lado Python (scripts/t2_5_validacion.py --fixture).
 * Si los dos no dan lo mismo, las reglas se separaron y hay que arreglarlo antes
 * de desplegar nada: el servidor y la ingesta estarian midiendo cosas distintas.
 *
 *   D:\SOFTWARE\PHP83\php.exe pruebas/validacion_test.php
 */

require_once __DIR__ . '/../publico/nucleo/Validacion.php';

$fixture = json_decode(
    file_get_contents(__DIR__ . '/fixture_validacion.json'),
    true, 512, JSON_THROW_ON_ERROR
);

$v = new Validacion($fixture['catalogo']);
$base = $fixture['orden_base'];

$fallos = [];
$n = 0;

foreach ($fixture['casos'] as $caso) {
    $n++;
    $orden = array_merge($base, $caso['cambios']);
    // Una clave puesta a null en `cambios` significa "quitala", no "ponla en
    // null": es como se expresa un campo vacio en el fixture.
    foreach ($caso['cambios'] as $k => $val) {
        if ($val === null && $k !== 'uso_repuesto') {
            $orden[$k] = null;
        }
    }
    $contexto = $caso['contexto'] ?? 'CAPTURA';

    $obtenidas = [];
    foreach ($v->validar($orden, $contexto) as $x) {
        $obtenidas[$x->regla] = true;
    }
    $obtenidas = array_keys($obtenidas);
    sort($obtenidas);

    $esperadas = $caso['espera'];
    sort($esperadas);

    $ok = $obtenidas === $esperadas;
    printf("  %s %s\n", $ok ? 'OK   ' : 'FALLA', $caso['nombre']);
    if (!$ok) {
        $fallos[] = sprintf(
            "%s\n       esperaba: [%s]\n       dio     : [%s]",
            $caso['nombre'], implode(', ', $esperadas), implode(', ', $obtenidas)
        );
    }
}

echo "\n" . str_repeat('=', 62) . "\n";
if ($fallos) {
    printf("%d de %d casos FALLAN:\n\n", count($fallos), $n);
    foreach ($fallos as $f) {
        echo "  - $f\n\n";
    }
    exit(1);
}
printf("Los %d casos del fixture pasan en PHP.\n", $n);
