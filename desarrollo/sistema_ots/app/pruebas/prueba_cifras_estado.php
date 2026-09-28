<?php
declare(strict_types=1);

/**
 * Prueba del cuadro «EN QUÉ ESTADO ESTÁN» del inicio (27-sep-2026, pedido de
 * la administradora): las cuatro cifras que ella usa, con los rótulos del
 * diccionario y contadas por `Casos::cifrasEstado()`.
 *
 * NO TOCA LA BASE NI LEE DATOS DE LA OPERACIÓN. Son órdenes sintéticas en
 * memoria (avisos 9100xxxx), como en `prueba_panel_zona.php`.
 *
 * Lo que defiende:
 *   - Cada cifra es EXACTAMENTE la suma de los estados de `Casos::CIFRAS_ESTADO`,
 *     comparada contra un conteo independiente escrito aquí, estado por estado
 *     (no contra la propia tabla: sería tautológico).
 *   - Las cerradas sin atención —regularizadas o no—, las sin asignar y las en
 *     revisión no suman a NINGUNA de las cuatro: siguen solo en el gráfico.
 *   - El estado de vista manda: una cerrada sin atención ya regularizada cuenta
 *     como REGULARIZADO en `por_estado`, no como CERRADO_SIN_ATENCION.
 *   - `por_estado` suma el total de órdenes (nada se pierde ni se duplica), y
 *     coincide con lo que dibuja el gráfico de barras del panel.
 *   - Las cuatro claves existen en el diccionario con término, plural, título y
 *     ayuda; los estados de la tabla son estados que `Ui::ESTADOS` conoce; y los
 *     rótulos no caen en la lista negra ni usan «cerrada» sola (VOCABULARIO §5).
 *   - Los enlaces del panel: una cifra de un solo estado va a `casos.php?est=`,
 *     que filtra por `Ui::estadoVista()`; la cifra es las filas del filtro.
 *
 *   php pruebas/prueba_cifras_estado.php
 */

require_once __DIR__ . '/../publico/nucleo/Casos.php';
require_once __DIR__ . '/../publico/nucleo/Ui.php';
require_once __DIR__ . '/../publico/nucleo/Vocabulario.php';

$fallos = 0;
$total  = 0;

function afirmar(string $que, $real, $esperado): void
{
    global $fallos, $total;
    $total++;
    $bien = $real === $esperado;
    if (!$bien) { $fallos++; }
    printf("  %-70s %-14s %s\n", $que,
           is_bool($real) ? ($real ? 'true' : 'false')
                          : ($real === null ? 'null' : (is_scalar($real) ? (string) $real : json_encode($real, JSON_UNESCAPED_UNICODE))),
           $bien ? 'ok' : 'FALLA (esperaba ' . var_export($esperado, true) . ')');
}

/* -------------------------------------------------------------------------
   LA BASE SINTÉTICA. Cada estado de la base al menos dos veces, en zonas
   distintas, y los casos borde: sin fila de gestión, estado en minúsculas,
   regularizada, y una orden del catálogo cuya fila de gestión no existe.
   ------------------------------------------------------------------------- */
$casos = [];
$gestion = [];
$orden = static function (string $aviso, string $zona, ?array $g) use (&$casos, &$gestion): void {
    $casos[] = ['aviso' => $aviso, 'zona' => $zona, 'fecha_creacion' => '2026-09-20'];
    if ($g !== null) { $gestion[$aviso] = $g + ['aviso' => $aviso]; }
};

$orden('91000001', 'UIO',  null);                                   // NUEVO sin fila de gestión
$orden('91000002', 'LARB', ['estado' => 'NUEVO']);
$orden('91000003', 'UIO',  ['estado' => 'ASIGNADO', 'asignado_a' => 7]);
$orden('91000004', 'CNLJ', ['estado' => 'ASIGNADO', 'asignado_a' => 9]);
$orden('91000005', 'LARB', ['estado' => 'asignado', 'asignado_a' => 8]);   // en minúsculas: cuenta igual
$orden('91000006', 'UIO',  ['estado' => 'EN_REVISION', 'asignado_a' => 7]);
$orden('91000007', 'UIO',  ['estado' => 'ESPERA_REPUESTO', 'asignado_a' => 7]);
$orden('91000008', 'CNLJ', ['estado' => 'ESPERA_REPUESTO', 'asignado_a' => 9]);
$orden('91000009', 'LARB', ['estado' => 'ESPERA_REPUESTO', 'asignado_a' => 8, 'continua_de' => '91000007']); // cadena: cuenta por orden
$orden('91000010', 'UIO',  ['estado' => 'ATENDIDO', 'ot_cierre' => 'OT-9010-X-91000010-UIO']);
$orden('91000011', 'LARB', ['estado' => 'ATENDIDO', 'ot_cierre' => 'OT-9011-X-91000011-LARB']);
$orden('91000012', 'UIO',  ['estado' => 'RESUELTO']);
$orden('91000013', 'CNLJ', ['estado' => 'RESUELTO']);
$orden('91000014', 'CNLJ', ['estado' => 'RESUELTO']);
$orden('91000015', 'UIO',  ['estado' => 'NO_COMPETE']);
$orden('91000016', 'OTRA', ['estado' => 'NO_COMPETE']);
$orden('91000017', 'UIO',  ['estado' => 'CERRADO_SIN_ATENCION']);                                   // sin regularizar
$orden('91000018', 'LARB', ['estado' => 'CERRADO_SIN_ATENCION', 'regularizado_en' => '2026-09-21 10:00:00']);
$orden('91000019', 'CNLJ', ['estado' => 'CERRADO_SIN_ATENCION', 'regularizado_en' => '2026-09-22 10:00:00']);
$orden('91000020', '',     ['estado' => 'ASIGNADO', 'asignado_a' => 9]);                            // sin zona: cuenta igual

// Una fila de gestión SIN orden en el catálogo (fuera de la ventana de 90 días):
// no cuenta en ningún lado, igual que en el buzón y en la tarjeta.
$gestion['91009999'] = ['aviso' => '91009999', 'estado' => 'ATENDIDO'];

$r = Casos::cifrasEstado($casos, $gestion);
$cifras = $r['cifras'];
$pe     = $r['por_estado'];

/* -------------------------------------------------------------------------
   1. Conteo INDEPENDIENTE, estado por estado, escrito a mano.
   ------------------------------------------------------------------------- */
echo "=== 1. Las cuatro cifras contra un conteo independiente ===\n";
$esperado = ['NUEVO' => 2, 'ASIGNADO' => 4, 'EN_REVISION' => 1, 'ESPERA_REPUESTO' => 3, 'ATENDIDO' => 2,
             'RESUELTO' => 3, 'NO_COMPETE' => 2, 'CERRADO_SIN_ATENCION' => 1, 'REGULARIZADO' => 2];
afirmar('CREADA_NO_COMPETE = NO_COMPETE (2)', $cifras['CREADA_NO_COMPETE'], 2);
afirmar('EN_GESTION_TECNICA = ASIGNADO (4: 3 zonas + sin zona, minúsculas incluidas)', $cifras['EN_GESTION_TECNICA'], 4);
afirmar('ATENDIDA_CERRADA = ATENDIDO + RESUELTO (2 + 3)', $cifras['ATENDIDA_CERRADA'], 5);
afirmar('GESTION_PROVEEDORES_KFC = ESPERA_REPUESTO (3, la cadena cuenta por orden)', $cifras['GESTION_PROVEEDORES_KFC'], 3);
afirmar('solo las cuatro claves, en el orden de la tabla', array_keys($cifras), array_keys(Casos::CIFRAS_ESTADO));

echo "\n=== 2. Lo que NO suma a ninguna cifra ===\n";
$sumaCuatro = array_sum($cifras);
afirmar('las cuatro suman 14 de 20: las otras 6 siguen solo en el gráfico', $sumaCuatro, 14);
$fueraDeLasCuatro = $esperado['NUEVO'] + $esperado['EN_REVISION'] + $esperado['CERRADO_SIN_ATENCION'] + $esperado['REGULARIZADO'];
afirmar('sin asignar + en revisión + cerradas sin atención (± regularizar) = las 6', $fueraDeLasCuatro, 6);
$estadosEnTabla = array_merge(...array_values(Casos::CIFRAS_ESTADO));
foreach (['NUEVO', 'EN_REVISION', 'CERRADO_SIN_ATENCION', 'REGULARIZADO'] as $e) {
    afirmar("$e no está en CIFRAS_ESTADO", in_array($e, $estadosEnTabla, true), false);
}
afirmar('ningún estado suma a dos cifras', count($estadosEnTabla), count(array_unique($estadosEnTabla)));
afirmar('EN_GESTION_TECNICA no cuenta EN_REVISION', in_array('EN_REVISION', Casos::CIFRAS_ESTADO['EN_GESTION_TECNICA'], true), false);
afirmar('EN_GESTION_TECNICA no cuenta ESPERA_REPUESTO', in_array('ESPERA_REPUESTO', Casos::CIFRAS_ESTADO['EN_GESTION_TECNICA'], true), false);

echo "\n=== 3. por_estado: el gráfico ===\n";
ksort($pe); ksort($esperado);
afirmar('por_estado = el conteo independiente, estado por estado', $pe, $esperado);
afirmar('por_estado suma las 20 órdenes (nada se pierde ni se duplica)', array_sum($pe), count($casos));
afirmar('la regularizada cuenta como REGULARIZADO (estado de vista)', $pe['REGULARIZADO'], 2);
afirmar('y solo la sin regularizar como CERRADO_SIN_ATENCION', $pe['CERRADO_SIN_ATENCION'], 1);
afirmar('la fila de gestión sin orden en el catálogo no cuenta', isset($pe['ATENDIDO']) && $pe['ATENDIDO'] === 2, true);
afirmar('con cero órdenes: cuatro ceros y ningún estado', Casos::cifrasEstado([], $gestion),
        ['cifras' => ['CREADA_NO_COMPETE' => 0, 'EN_GESTION_TECNICA' => 0, 'ATENDIDA_CERRADA' => 0, 'GESTION_PROVEEDORES_KFC' => 0],
         'por_estado' => []]);

/* -------------------------------------------------------------------------
   4. El diccionario: las claves existen, los rótulos son los de la
   administradora, y no caen en la lista negra.
   ------------------------------------------------------------------------- */
echo "\n=== 4. Los rótulos, del diccionario ===\n";
$d = Vocabulario::todo();
$negra = array_map('mb_strtolower', $d['lista_negra'] ?? []);
foreach (Casos::CIFRAS_ESTADO as $clave => $estados) {
    $c = $d['conceptos'][$clave] ?? null;
    afirmar("$clave existe con término, plural, título y ayuda",
            $c !== null && !empty($c['termino']) && !empty($c['plural']) && !empty($c['titulo']) && !empty($c['ayuda']), true);
    if ($c === null) { continue; }
    afirmar("$clave lo ven ADM y JZ (los dos roles que llegan al inicio)",
            in_array('ADM', $c['roles'] ?? [], true) && in_array('JZ', $c['roles'] ?? [], true), true);
    foreach (['termino', 'plural', 'titulo', 'ayuda'] as $campo) {
        $txt = mb_strtolower((string) $c[$campo]);
        $malas = [];
        foreach ($negra as $t) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($t, '/') . '(?![\p{L}\p{N}])/u', $txt)) { $malas[] = $t; }
        }
        afirmar("  $campo sin palabras de la lista negra", $malas, []);
        // «cerrada» nunca va sola (VOCABULARIO §5): si aparece, lleva calificativo.
        afirmar("  $campo: «cerrada» solo con calificativo",
                (bool) preg_match('/\bcerradas?\b(?!\s+(en sap|por industec|sin atención|por falta))/u', $txt), false);
    }
    foreach ($estados as $e) {
        afirmar("  $e es un estado que Ui::ESTADOS conoce", isset(Ui::ESTADOS[$e]), true);
        afirmar("  $e tiene concepto en el dominio caso", Vocabulario::deEstado($e) !== '', true);
    }
}
afirmar('la ayuda de ATENDIDA_CERRADA nombra las dos partes (en SAP y por cerrar en SAP)',
        str_contains(Vocabulario::ayuda('ATENDIDA_CERRADA'), 'cerrada en SAP') && str_contains(Vocabulario::ayuda('ATENDIDA_CERRADA'), 'por cerrar en SAP'), true);
afirmar('los tres títulos de un solo estado repiten el término de su estado',
        [str_contains(mb_strtolower(Vocabulario::titulo('CREADA_NO_COMPETE')), 'no nos compete'),
         str_contains(mb_strtolower(Vocabulario::titulo('EN_GESTION_TECNICA')), Vocabulario::t('ASIGNADA', 2)),
         str_contains(mb_strtolower(Vocabulario::titulo('GESTION_PROVEEDORES_KFC')), Vocabulario::t('ESPERA_REPUESTO'))],
        [true, true, true]);

/* -------------------------------------------------------------------------
   5. El enlace: `casos.php?est=X` filtra con Ui::estadoVista(), así que la
   cifra de un solo estado es exactamente las filas de su enlace. Se imita
   aquí el filtro (casos.php:512) sobre la misma base sintética.
   ------------------------------------------------------------------------- */
echo "\n=== 5. La cifra es las filas de su enlace ===\n";
$filtroEst = static function (string $est) use ($casos, $gestion): int {
    $n = 0;
    foreach ($casos as $c) {
        $g = $gestion[(string) $c['aviso']] ?? null;
        if (Ui::estadoVista($g['estado'] ?? null, $g) === $est) { $n++; }
    }
    return $n;
};
foreach (Casos::CIFRAS_ESTADO as $clave => $estados) {
    $filas = 0;
    foreach ($estados as $e) { $filas += $filtroEst($e); }
    afirmar("$clave = filas de casos.php?est=" . implode('+', $estados), $cifras[$clave], $filas);
}
afirmar('la regularizada NO sale en casos.php?est=CERRADO_SIN_ATENCION', $filtroEst('CERRADO_SIN_ATENCION'), 1);

echo "\n$total comprobaciones · $fallos fallos\n";
exit($fallos === 0 ? 0 : 1);
