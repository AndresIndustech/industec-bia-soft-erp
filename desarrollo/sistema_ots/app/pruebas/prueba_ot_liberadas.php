<?php
declare(strict_types=1);

/**
 * prueba_ot_liberadas.php — Una OT con número del piloto que se LIBERÓ a Grupo
 * KFC deja de serlo, y todo lo demás de la serie 9000 sigue igual (T2.29.8,
 * migración 024, pedido de Andrés del 2026-10-01).
 *
 * POR QUÉ EXISTE
 * El criterio «es del piloto» es el NÚMERO (Emision::esDePrueba) y se repite en
 * ~50 sitios de la web: el chip «del piloto · no enviada a KFC», si la OT
 * atiende el caso, si cuenta en los reportes, si se comparte y si el
 * despachador la deja salir. Para mandar a KFC diez OT de la serie 9000 que
 * nunca salieron hacía falta una excepción, y la excepción tiene que ser
 * estrecha: solo lo que está marcado `liberada_en`, y nada más.
 *
 * NO DEPENDE DE LA BASE DEL ENTORNO. En la estación existe un nucleo/config.php
 * local (base de desarrollo) y en otro equipo puede no haber ninguno: por eso el
 * conjunto de liberadas se INYECTA por reflexión en vez de leerlo. La lectura
 * real solo se ejerce al final, para comprobar que no rompe nada.
 *
 *   php pruebas/prueba_ot_liberadas.php
 */

require_once __DIR__ . '/../publico/nucleo/Emision.php';

$fallos = 0;
$total  = 0;

function afirmar(string $que, $real, $esperado): void
{
    global $fallos, $total;
    $total++;
    $bien = $real === $esperado;
    if (!$bien) { $fallos++; }
    printf("  %-74s %-8s %s\n", $que,
           is_bool($real) ? ($real ? 'true' : 'false') : (is_scalar($real) ? (string) $real : json_encode($real)),
           $bien ? 'ok' : 'FALLA (esperaba ' . var_export($esperado, true) . ')');
}

/** Pone el conjunto de liberadas que verá Emision en este proceso (null = que lo lea de nuevo). */
function inyectar(?array $conjunto): void
{
    $p = new ReflectionProperty(Emision::class, 'liberadas');
    $p->setAccessible(true);
    $p->setValue(null, $conjunto);
}

function cache(): ?array
{
    $p = new ReflectionProperty(Emision::class, 'liberadas');
    $p->setAccessible(true);
    return $p->getValue();
}

$SQL_BASE = "(UPPER(TRIM(x)) REGEXP '^OT-0*(9[0-9]{3}|[1-9][0-9]{4,})-')";
$LIBERADA = 'OT-9125-A010EC-10355442-UIO';
$OTRA     = 'OT-9128-I016EC-10355361-UIO';

echo "=== 1. Sin ninguna liberada: exactamente el comportamiento de antes ===\n";
// Un conjunto vacío es lo que devuelve `liberadas()` sin base, sin la 024 o con la base caída.
inyectar([]);
afirmar('liberadas() vacío', Emision::liberadas(), []);
afirmar('esDePrueba(OT-9125) sigue true', Emision::esDePrueba($LIBERADA), true);
afirmar('esDePrueba(OT-1952, real) sigue false', Emision::esDePrueba('OT-1952-M063EC-10354880-UIO'), false);
afirmar('sqlEsDePrueba() es byte a byte la de antes', Emision::sqlEsDePrueba('x'), $SQL_BASE);
afirmar('modoDeNumero(OT-9125) es PRUEBA', Emision::modoDeNumero($LIBERADA), 'PRUEBA');

echo "\n=== 2. Con una liberada: solo esa deja de ser del piloto ===\n";
inyectar([$LIBERADA => true]);
afirmar('esDePrueba(la liberada) es false', Emision::esDePrueba($LIBERADA), false);
afirmar('también en minúsculas', Emision::esDePrueba(strtolower($LIBERADA)), false);
afirmar('también con espacios alrededor', Emision::esDePrueba("  $LIBERADA  "), false);
afirmar('con ceros a la izquierda en el número NO es la misma: sigue del piloto', Emision::esDePrueba('OT-09125-A010EC-10355442-UIO'), true);
afirmar('otra OT del piloto sigue siendo del piloto', Emision::esDePrueba($OTRA), true);
afirmar('el mismo número con otro local NO queda liberado', Emision::esDePrueba('OT-9125-Z999EC-10355442-UIO'), true);
afirmar('una real sigue sin ser del piloto', Emision::esDePrueba('OT-1964-G021EC-10358019-UIO'), false);
afirmar('null y vacío no son del piloto', [Emision::esDePrueba(null), Emision::esDePrueba('')], [false, false]);
afirmar('modoDeNumero(la liberada) es PRODUCCION', Emision::modoDeNumero($LIBERADA), 'PRODUCCION');
afirmar('modoDeNumero(otra del piloto) sigue en PRUEBA', Emision::modoDeNumero($OTRA), 'PRUEBA');

echo "\n=== 3. El SQL dice lo mismo que el PHP ===\n";
$sql = Emision::sqlEsDePrueba('c.id_industec');
afirmar('empieza con el criterio de siempre', str_starts_with($sql, "((UPPER(TRIM(c.id_industec)) REGEXP '^OT-0*(9[0-9]{3}|[1-9][0-9]{4,})-')"), true);
afirmar('excluye a la liberada por su nombre', str_contains($sql, "NOT IN ('$LIBERADA')"), true);
afirmar('no nombra a ninguna otra (un OT- del REGEXP y uno de la liberada)', substr_count($sql, 'OT-'), 2);
afirmar('es una sola expresión entre paréntesis', ($sql[0] === '(' && substr($sql, -1) === ')'), true);

echo "\n=== 4. Varias liberadas, y volver a empezar ===\n";
inyectar([$LIBERADA => true, $OTRA => true]);
$sql2 = Emision::sqlEsDePrueba('ot_cierre');
afirmar('las dos van en el NOT IN, separadas por coma', str_contains($sql2, "NOT IN ('$LIBERADA','$OTRA')"), true);
afirmar('las dos dejaron de ser del piloto', [Emision::esDePrueba($LIBERADA), Emision::esDePrueba($OTRA)], [false, false]);
inyectar([]);
afirmar('con el conjunto vacío de nuevo, todo vuelve a ser del piloto', [Emision::esDePrueba($LIBERADA), Emision::esDePrueba($OTRA)], [true, true]);
Emision::olvidarLiberadas();
afirmar('olvidarLiberadas() deja el caché en null (se vuelve a leer)', cache(), null);

echo "\n=== 5. La lectura real no rompe nada, tenga o no la base la columna ===\n";
afirmar('liberadas() devuelve un arreglo (sin base, sin la 024 o con ella)', is_array(Emision::liberadas()), true);
Emision::olvidarLiberadas();

echo "\n$total comprobaciones · $fallos fallos\n";
exit($fallos === 0 ? 0 : 1);
