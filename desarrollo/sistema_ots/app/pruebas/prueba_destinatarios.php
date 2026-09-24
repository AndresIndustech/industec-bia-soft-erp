<?php
declare(strict_types=1);

/**
 * Prueba de unidad: Destinatarios::resolver() — a quién va cada correo de una
 * orden (T2.28.2, obs. 2 y 8; D-C, D-G, S-1).
 *
 * NO TOCA LA BASE. Prueba la lógica pura, `resolverConFilas()`, con filas
 * fabricadas a mano en vez de leerlas de `correo_destinatarios`: el mismo
 * criterio que prueba_continuidad.php y prueba_casos_prueba.php, porque esto
 * decide a quién sale una orden y tiene que poder comprobarse sin MySQL. Lo
 * que sí toca la base -que la tabla exista, que las claves únicas frenen un
 * segundo jefe de zona- lo comprueba verificar_esquema.php contra el
 * servidor; que la cola quede con el correo del local y el jefe de zona en
 * copia, verificar_emision.py.
 *
 *   php pruebas/prueba_destinatarios.php
 */

require_once __DIR__ . '/../publico/nucleo/Destinatarios.php';

$fallos = 0;
$total  = 0;

function afirmar(string $que, $real, $esperado): void
{
    global $fallos, $total;
    $total++;
    $bien = $real === $esperado;
    if (!$bien) { $fallos++; }
    printf("  %-72s %s\n", $que,
           $bien ? 'ok' : 'FALLA (obtuvo ' . var_export($real, true) . ', esperaba ' . var_export($esperado, true) . ')');
}

/** Una fila de correo_destinatarios, con los valores de sobra ya puestos. */
function fila(array $over): array
{
    return array_merge([
        'uso' => 'ORDEN', 'destino' => 'INTERNO', 'ambito' => 'GENERAL', 'zona' => null,
        'local_codigo' => null, 'cadena' => null, 'rol' => 'OTRO', 'tipo' => 'COPIA',
        'correo' => 'x@industec.me', 'nombre' => null, 'activo' => 1,
    ], $over);
}

$localG007 = ['codigo' => 'G007EC', 'nombre' => 'RECREO PLAZA QUITO', 'cadena' => 'GUS', 'zona' => 'UIO',
              'correo_local' => 'servicioalcliente@industec.me', 'correo_jefe_op' => 'jefezona-uio@industec.me'];

echo "=== 1. GENERAL entra en cualquier zona y local ===\n";
$filas = [fila(['ambito' => 'GENERAL', 'correo' => 'general@industec.me'])];
$r = Destinatarios::resolverConFilas($filas, 'UIO', 'G007EC', 'GUS', null, $localG007);
afirmar('general@industec.me en cc para UIO/G007EC', in_array('general@industec.me', $r['cc'], true), true);
$r2 = Destinatarios::resolverConFilas($filas, 'CNLJ', 'K041EC', 'KFC', null, []);
afirmar('y también para otra zona y local (sin importar el maestro)', in_array('general@industec.me', $r2['cc'], true), true);

echo "\n=== 2. ZONA solo entra en su zona ===\n";
$filas = [fila(['ambito' => 'ZONA', 'zona' => 'UIO', 'correo' => 'zona-uio@industec.me'])];
$rUio = Destinatarios::resolverConFilas($filas, 'UIO', 'G007EC', null, null, $localG007);
$rLarb = Destinatarios::resolverConFilas($filas, 'LARB', 'X001EC', null, null, []);
afirmar('entra en UIO', in_array('zona-uio@industec.me', $rUio['cc'], true), true);
afirmar('no entra en LARB', in_array('zona-uio@industec.me', $rLarb['cc'], true), false);

echo "\n=== 3. LOCAL solo entra en ese local ===\n";
$filas = [fila(['ambito' => 'LOCAL', 'local_codigo' => 'G007EC', 'destino' => 'CLIENTE',
                'rol' => 'JEFE_OPERACIONES', 'correo' => 'jefeop.g007@kfc.example'])];
$rAqui = Destinatarios::resolverConFilas($filas, 'UIO', 'G007EC', 'GUS', null, $localG007);
$rOtro = Destinatarios::resolverConFilas($filas, 'UIO', 'G018EC', 'GUS', null, []);
afirmar('entra en G007EC', in_array('jefeop.g007@kfc.example', $rAqui['cc'], true), true);
afirmar('no entra en G018EC', in_array('jefeop.g007@kfc.example', $rOtro['cc'], true), false);

echo "\n=== 4. la cadena, cuando la fila la fija ===\n";
$filas = [fila(['ambito' => 'GENERAL', 'cadena' => 'KFC', 'correo' => 'solo-kfc@industec.me'])];
$rKfc = Destinatarios::resolverConFilas($filas, 'UIO', 'K001EC', 'KFC', null, []);
$rGus = Destinatarios::resolverConFilas($filas, 'UIO', 'G007EC', 'GUS', null, $localG007);
afirmar('entra en una orden de KFC', in_array('solo-kfc@industec.me', $rKfc['cc'], true), true);
afirmar('no entra en una orden de otra cadena (GUS)', in_array('solo-kfc@industec.me', $rGus['cc'], true), false);
afirmar('una fila sin cadena (NULL) entra en cualquiera',
        in_array('general@industec.me', Destinatarios::resolverConFilas(
            [fila(['ambito' => 'GENERAL', 'correo' => 'general@industec.me'])], 'UIO', 'K001EC', 'KFC', null, [])['cc'], true), true);

echo "\n=== 5. el jefe de zona siempre presente (si está activo) ===\n";
$filas = [fila(['ambito' => 'ZONA', 'zona' => 'UIO', 'rol' => 'JEFE_ZONA', 'correo' => 'jefezona-uio@industec.me'])];
$r = Destinatarios::resolverConFilas($filas, 'UIO', 'G007EC', 'GUS', null, $localG007);
afirmar('jefezona-uio@industec.me en copia', in_array('jefezona-uio@industec.me', $r['cc'], true), true);
$inactivo = [fila(['ambito' => 'ZONA', 'zona' => 'UIO', 'rol' => 'JEFE_ZONA', 'correo' => 'jefezona-uio@industec.me', 'activo' => 0])];
$rInactivo = Destinatarios::resolverConFilas($inactivo, 'UIO', 'G007EC', 'GUS', null, $localG007);
afirmar('desactivado, ya no', in_array('jefezona-uio@industec.me', $rInactivo['cc'], true), false);

echo "\n=== 6. duplicados: la misma dirección no se repite ===\n";
$filas = [
    fila(['ambito' => 'GENERAL', 'correo' => 'dup@industec.me']),
    fila(['ambito' => 'ZONA', 'zona' => 'UIO', 'correo' => 'DUP@industec.me']),   // mismo correo, otras mayúsculas
    // El correo del local (el que va en PARA) también configurado como
    // copia general por error: no debe salir dos veces.
    fila(['ambito' => 'GENERAL', 'correo' => 'servicioalcliente@industec.me']),
];
$r = Destinatarios::resolverConFilas($filas, 'UIO', 'G007EC', 'GUS', null, $localG007);
afirmar('dup@industec.me aparece una sola vez', count(array_filter($r['cc'], fn($c) => $c === 'dup@industec.me')), 1);
afirmar('el correo del local (PARA) no se repite en cc aunque una fila lo tenga también',
        in_array($r['para'][0] ?? '', $r['cc'], true), false);

echo "\n=== 7. correos inválidos quedan fuera ===\n";
$filas = [fila(['ambito' => 'GENERAL', 'correo' => 'esto no es un correo'])];
$r = Destinatarios::resolverConFilas($filas, 'UIO', 'G007EC', 'GUS', null, $localG007);
afirmar('ninguna dirección inválida entra', count($r['cc']), 0);
afirmar('pero el correo del local (válido) sí', $r['para'], ['servicioalcliente@industec.me']);

echo "\n=== 8. respaldo -sin filas (13 no aplicada o tabla vacía)- igual a lo de siempre ===\n";
$r = Destinatarios::resolverConFilas(null, 'UIO', 'G007EC', 'GUS', 'x@prueba.test', $localG007);
afirmar('para: el correo que escribió el técnico', $r['para'], ['x@prueba.test']);
afirmar('cc: el correo_jefe_op del maestro (lo de siempre)', in_array('jefezona-uio@industec.me', $r['cc'], true), true);
$rVacia = Destinatarios::resolverConFilas([], 'UIO', 'G007EC', 'GUS', 'x@prueba.test', $localG007);
afirmar('con la tabla vacía (013 aplicada, sin filas) da lo mismo que sin tabla (filas=null)', $rVacia, $r);

echo "\n=== El correo del local: de la orden si es válido, si no del maestro ===\n";
$r = Destinatarios::resolverConFilas([], 'UIO', 'G007EC', 'GUS', 'admin.local@prueba.ec', $localG007);
afirmar('usa el de la orden', $r['para'], ['admin.local@prueba.ec']);
$r = Destinatarios::resolverConFilas([], 'UIO', 'G007EC', 'GUS', null, $localG007);
afirmar('sin correo de la orden, usa el del maestro', $r['para'], ['servicioalcliente@industec.me']);
$r = Destinatarios::resolverConFilas([], 'UIO', 'G007EC', 'GUS', 'esto no es un correo', $localG007);
afirmar('un correo de orden inválido cae al del maestro', $r['para'], ['servicioalcliente@industec.me']);
$r = Destinatarios::resolverConFilas([], 'UIO', 'G007EC', 'GUS', 'servicioalcliente@industec.me', $localG007);
afirmar('un buzón @industec.me escrito en la orden no cuenta como "del local": cae al maestro igual', $r['para'], ['servicioalcliente@industec.me']);

printf("\n%d comprobaciones · %d fallos\n", $total, $fallos);
exit($fallos === 0 ? 0 : 1);
