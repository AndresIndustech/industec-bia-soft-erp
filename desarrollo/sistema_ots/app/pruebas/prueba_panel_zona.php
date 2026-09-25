<?php
declare(strict_types=1);

/**
 * Prueba de la TARJETA POR ZONA del panel (VOCABULARIO.md §8 y §9.1).
 *
 * NO TOCA LA BASE NI LEE DATOS DE LA OPERACIÓN. Todo lo que se cuenta aquí
 * son órdenes sintéticas armadas en memoria (avisos 9000xxxx), pasadas a las
 * funciones deterministas de `Casos`: `indiceInformes()`, `grupoOrden()`,
 * `clasificar()`, `enGrupo()`, `tarjetasPorZona()` y `zonasVisibles()`. Es lo
 * mismo que hacen el panel y `casos.php?grupo=` con lo que leen de la base, así
 * que lo que aquí cuadra cuadra allá.
 *
 * Lo que defiende, porque son cifras que Isabel lleva a KFC:
 *   - D-A: ESPERA_REPUESTO va SIEMPRE a ÓRDENES ABIERTAS; con OT INDUSTEC
 *     emitida → ABIERTAS; sin ninguna → A ESPERA DE INFORME TÉCNICO. Una OT
 *     NUMERADA o FALLIDA no cuenta como emitida.
 *   - D-B: TOTAL = ABIERTAS + A ESPERA, y ese total se compara contra un
 *     conteo INDEPENDIENTE por estado (no contra la suma: sería tautológico).
 *   - Sublínea ≤ fila, siempre. Deshabilitados + operativos + sin dato = TOTAL.
 *   - I-7: si falta una fuente, la cifra es null («no disponible»), nunca 0.
 *
 *   php pruebas/prueba_panel_zona.php
 */

require_once __DIR__ . '/../publico/nucleo/Casos.php';
require_once __DIR__ . '/../publico/nucleo/Vocabulario.php';

$fallos = 0;
$total  = 0;

function afirmar(string $que, $real, $esperado): void
{
    global $fallos, $total;
    $total++;
    $bien = $real === $esperado;
    if (!$bien) { $fallos++; }
    printf("  %-66s %-16s %s\n", $que,
           is_bool($real) ? ($real ? 'true' : 'false')
                          : ($real === null ? 'null' : (is_scalar($real) ? (string) $real : json_encode($real))),
           $bien ? 'ok' : 'FALLA (esperaba ' . var_export($esperado, true) . ')');
}

const HOY = '2026-09-24';

/* -------------------------------------------------------------------------
   LA BASE SINTÉTICA. Cada orden lleva al lado lo que prueba.
   ------------------------------------------------------------------------- */
$casos = [];
$gestion = [];
$orden = static function (string $aviso, string $zona, ?array $g, string $creada = '2026-09-15', string $alerta = 'SIN_ALERTA')
    use (&$casos, &$gestion): void {
    $casos[] = ['aviso' => $aviso, 'zona' => $zona, 'fecha_creacion' => $creada, 'estado_alerta' => $alerta];
    if ($g !== null) { $gestion[$aviso] = $g + ['aviso' => $aviso]; }
};

// --- UIO -------------------------------------------------------------------
$orden('90000001', 'UIO', null);                                                              // NUEVO sin fila de gestión, sin OT
$orden('90000002', 'UIO', ['estado' => 'ASIGNADO', 'asignado_a' => 7, 'asignado_en' => '2026-09-22 08:00:00']); // OT de evaluación (atenciones, con ceros)
$orden('90000003', 'UIO', ['estado' => 'ASIGNADO', 'asignado_a' => 7, 'asignado_en' => '2026-09-20 08:00:00']); // sin OT, 4 días
$orden('90000004', 'UIO', ['estado' => 'ASIGNADO', 'asignado_a' => 7, 'asignado_en' => '2026-09-19 08:00:00']); // OT en ot_archivo, 5 días
$orden('90000005', 'UIO', ['estado' => 'ESPERA_REPUESTO', 'asignado_a' => 7, 'asignado_en' => '2026-09-18']);   // sin NINGÚN documento
$orden('90000006', 'UIO', ['estado' => 'ASIGNADO', 'asignado_a' => 7, 'asignado_en' => '2026-09-23 08:00:00']); // solo captura NUMERADA
$orden('90000016', 'UIO', ['estado' => 'ASIGNADO', 'asignado_a' => 7, 'asignado_en' => '2026-09-23 08:00:00']); // solo captura FALLIDA
$orden('90000007', 'UIO', ['estado' => 'ATENDIDO', 'ot_cierre' => 'OT-9007-X-90000007-UIO']);  // al pie, fuera del total
$orden('90000008', 'UIO', ['estado' => 'EN_REVISION', 'asignado_a' => 7, 'ot_cierre' => 'OT-9008-X-90000008-UIO']); // con OT, en revisión
$orden('90000009', 'UIO', ['estado' => 'CERRADO_SIN_ATENCION']);                               // sin regularizar
$orden('90000010', 'UIO', ['estado' => 'CERRADO_SIN_ATENCION', 'regularizado_en' => '2026-09-20 10:00:00']); // regularizada: en ningún lado
$orden('90000011', 'UIO', ['estado' => 'NUEVO']);                                              // OT con firma no reconocida; Deshabilitado y después Operativo
$orden('90000012', 'UIO', ['estado' => 'ASIGNADO', 'asignado_a' => 7, 'asignado_en' => '2026-09-24 07:00:00']); // OT sin estado del equipo
$orden('90000013', 'UIO', ['estado' => 'ASIGNADO', 'asignado_a' => 7, 'asignado_en' => '2026-09-01'], '2026-09-01'); // raíz de una cadena
$orden('90000014', 'UIO', ['estado' => 'NUEVO', 'continua_de' => '90000013'], '2026-09-10');   // continúa 90000013: cuentan 1
$orden('90000061', 'UIO', ['estado' => 'RESUELTO']);                                           // fuera
// --- LARB ------------------------------------------------------------------
$orden('90000021', 'LARB', ['estado' => 'NUEVO']);                                             // captura emitida: un equipo Operativo y otro sin estado
$orden('90000022', 'LARB', ['estado' => 'ASIGNADO', 'asignado_a' => 8, 'asignado_en' => '2026-09-24']); // mismo día: Operativo (correo) y Deshabilitado (app)
$orden('90000023', 'LARB', ['estado' => 'NUEVO'], '2026-09-10', 'CON_ALERTA');                  // fuera del área, por decidir
$orden('90000062', 'LARB', ['estado' => 'NO_COMPETE']);                                        // fuera
// --- CNLJ, OTRA y sin zona -------------------------------------------------
$orden('90000031', 'CNLJ', ['estado' => 'ESPERA_REPUESTO', 'asignado_a' => 9, 'asignado_en' => '2026-09-10']);
$orden('90000041', 'OTRA', ['estado' => 'NUEVO']);
$orden('90000051', '',     ['estado' => 'ASIGNADO', 'asignado_a' => 9, 'asignado_en' => '2026-09-14']);

// Las fuentes, con la forma que devuelven atenciones.json y las consultas.
$aten = [
    '000090000002' => ['ots' => [['ot' => 'OT-1-A', 'fecha' => '2026-09-22', 'estado_ot' => 'Abierta', 'estado_equipo' => 'Operativo']]],
    '90000011'     => ['ots' => [['ot' => 'OT-2-A', 'fecha' => '2026-09-23', 'estado_ot' => 'Abierta', 'estado_equipo' => 'Operativo']]],
    '90000012'     => ['ots' => [['ot' => 'OT-3-A', 'fecha' => '2026-09-24', 'estado_ot' => 'Abierta', 'estado_equipo' => null]]],
    '90000013'     => ['ots' => [['ot' => 'OT-4-A', 'fecha' => '2026-09-02', 'estado_ot' => 'Abierta', 'estado_equipo' => 'Deshabilitado']]],
    '90000022'     => ['ots' => [['ot' => 'OT-5-A', 'fecha' => '2026-09-23', 'estado_ot' => 'Abierta', 'estado_equipo' => 'Operativo']]],
    '90000031'     => ['ots' => [['ot' => 'OT-6-A', 'fecha' => '2026-09-11', 'estado_ot' => 'Abierta', 'estado_equipo' => 'Operativo']]],
];
$capturadas = [
    ['aviso' => '90000006', 'estado' => 'NUMERADA', 'emitida_en' => null, 'equipos' => null],
    ['aviso' => '90000016', 'estado' => 'FALLIDA',  'emitida_en' => '2026-09-23 09:00:00', 'equipos' => null],
    ['aviso' => '90000011', 'estado' => 'ENVIADA',  'emitida_en' => '2026-09-20 09:00:00',
     'equipos' => json_encode([['estado' => 'Deshabilitado']])],
    ['aviso' => '90000021', 'estado' => 'EMITIDA',  'emitida_en' => '2026-09-21 09:00:00',
     'equipos' => json_encode([['estado' => 'Operativo'], ['estado' => null]])],
    ['aviso' => '90000022', 'estado' => 'ENVIADA',  'emitida_en' => '2026-09-23 15:00:00',
     'equipos' => json_encode([['estado' => 'Deshabilitado']])],
];
$archivo = [['aviso' => '90000004', 'fecha_atencion' => '2026-09-19']];
$pendientes = [
    // Solicitud en trámite con el equipo deshabilitado, 60 h: vencida.
    ['aviso' => '90000005', 'desde' => '2026-09-21 10:00:00', 'vencido' => 1],
    // Vencida también, pero un informe POSTERIOR dice Operativo.
    ['aviso' => '90000011', 'desde' => '2026-09-19 08:00:00', 'vencido' => 1],
];

$inf = Casos::indiceInformes($gestion, $aten, $capturadas, $archivo, $pendientes, '2026-09-24 06:00');
$tz  = Casos::tarjetasPorZona($casos, $gestion, $inf, HOY);
$U = $tz['zonas']['UIO'];
$L = $tz['zonas']['LARB'];
$C = $tz['zonas']['CNLJ'];

/* -------------------------------------------------------------------------
   1. grupoOrden(): la precedencia de D-A, orden por orden.
   ------------------------------------------------------------------------- */
echo "=== grupoOrden(): la precedencia de D-A ===\n";
$grupo = static fn(string $a): ?string => Casos::grupoOrden(['aviso' => $a], $gestion[$a] ?? null, $inf);
afirmar('NUEVO sin OT → a espera de informe técnico', $grupo('90000001'), 'ESPERA_INFORME');
afirmar('ASIGNADO con OT de evaluación (aviso con ceros) → abierta', $grupo('90000002'), 'ABIERTA');
afirmar('ESPERA_REPUESTO sin ningún documento → abierta (D-A)', $grupo('90000005'), 'ABIERTA');
afirmar('solo captura NUMERADA → a espera de informe técnico', $grupo('90000006'), 'ESPERA_INFORME');
afirmar('solo captura FALLIDA (aunque tenga emitida_en) → a espera', $grupo('90000016'), 'ESPERA_INFORME');
afirmar('OT solo en ot_archivo → abierta', $grupo('90000004'), 'ABIERTA');
afirmar('OT de cierre en la gestión, estado EN_REVISION → abierta', $grupo('90000008'), 'ABIERTA');
afirmar('NUEVO con OT (firma no reconocida) → abierta', $grupo('90000011'), 'ABIERTA');
afirmar('ATENDIDO → fuera del total (null)', $grupo('90000007'), null);
afirmar('RESUELTO → fuera del total (null)', $grupo('90000061'), null);
afirmar('CERRADO_SIN_ATENCION → fuera del total (null)', $grupo('90000009'), null);
afirmar('la continuación hereda la OT de su raíz → abierta', $grupo('90000014'), 'ABIERTA');

/* -------------------------------------------------------------------------
   2. Las filas de la ZONA UIO.
   ------------------------------------------------------------------------- */
echo "\n=== ZONA UIO: filas 1, 2 y 4 ===\n";
afirmar('ÓRDENES ABIERTAS', $U['abiertas'], 7);
afirmar('ÓRDENES A ESPERA DE INFORME TÉCNICO', $U['espera_informe'], 4);
afirmar('TOTAL DE ÓRDENES ABIERTAS (la cadena cuenta una vez)', $U['total'], 11);
afirmar('  de ellas, a espera de repuesto', $U['abiertas_espera_repuesto'], 1);
afirmar('  de ellas, sin asignar (OT con firma no reconocida)', $U['abiertas_sin_asignar'], 2);
afirmar('  sin asignar (fila 2)', $U['sin_asignar'], 1);
afirmar('  asignadas hace 3+ días: solo la de 4 días sin OT', $U['asignadas_3d'], 1);
afirmar('  con OT INDUSTEC no emitida (NUMERADA + FALLIDA)', $U['ot_no_emitida'], 2);
afirmar('  la de 5 días con OT no está en «3+ días», sí en el conteo por estado',
        $U['asignadas_3d_por_estado'] - $U['asignadas_3d'] >= 1, true);

echo "\n=== ZONA UIO: fila 3 y pie ===\n";
afirmar('EQUIPOS DESHABILITADOS (ESPERA_REPUESTO + cadena del horno)', $U['deshabilitados'], 2);
afirmar('  Deshabilitado y después Operativo → cuenta como operativo', $U['operativos'], 2);
afirmar('  sin dato del equipo (nunca se lee como Operativo)', $U['sin_dato'], 7);
afirmar('  de ellas, vencidas: la informada Operativo después NO cuenta', $U['vencidas'], 1);
afirmar('pie: atendidas, por cerrar en SAP (fuera del total)', $U['atendidas'], 1);
afirmar('pie: en revisión (dentro del total)', $U['en_revision'], 1);
afirmar('pie: sin regularizar (la regularizada no aparece)', $U['sin_regularizar'], 1);
afirmar('estadoEquipo(90000011): manda la evidencia más reciente', Casos::estadoEquipo('90000011', $inf), 'OPERATIVO');
afirmar('estadoEquipo(90000022): empate del mismo día → Deshabilitado', Casos::estadoEquipo('90000022', $inf), 'DESHABILITADO');
afirmar('estadoEquipo(90000021): un equipo sin estado → sin dato', Casos::estadoEquipo('90000021', $inf), null);

echo "\n=== LARB y CUENCA-LOJA ===\n";
afirmar('LARB: abiertas / a espera / total', [$L['abiertas'], $L['espera_informe'], $L['total']], [2, 1, 3]);
afirmar('LARB: fuera del área, por decidir (sublínea de la fila 2)', $L['otro_por_decidir'], 1);
afirmar('LARB: deshabilitados / operativos / sin dato', [$L['deshabilitados'], $L['operativos'], $L['sin_dato']], [1, 0, 2]);
afirmar('CNLJ: ESPERA_REPUESTO con OT → abierta; total 1', [$C['abiertas'], $C['abiertas_espera_repuesto'], $C['total']], [1, 1, 1]);

/* -------------------------------------------------------------------------
   3. Las reglas que valen en TODA zona.
   ------------------------------------------------------------------------- */
echo "\n=== Reglas en todas las zonas (incluidas OTRA y sin zona) ===\n";
$ok = ['particion' => true, 'sub' => true, 'equipo' => true, 'vencidas' => true];
foreach ($tz['zonas'] as $z => $t) {
    if ($t['abiertas'] + $t['espera_informe'] !== $t['total']) { $ok['particion'] = false; }
    foreach ([['abiertas_espera_repuesto', 'abiertas'], ['abiertas_sin_asignar', 'abiertas'],
              ['sin_asignar', 'espera_informe'], ['asignadas_3d', 'espera_informe'],
              ['otro_por_decidir', 'espera_informe'], ['ot_no_emitida', 'espera_informe']] as [$s, $f]) {
        if ($t[$s] > $t[$f]) { $ok['sub'] = false; echo "    $z: $s > $f\n"; }
    }
    if ($t['deshabilitados'] + $t['operativos'] + $t['sin_dato'] !== $t['total']) { $ok['equipo'] = false; }
    if ($t['vencidas'] > $t['deshabilitados']) { $ok['vencidas'] = false; }
}
afirmar('ABIERTAS + A ESPERA = TOTAL en cada zona (partición exacta)', $ok['particion'], true);
afirmar('toda sublínea ≤ su fila', $ok['sub'], true);
afirmar('deshabilitados + operativos + sin dato = TOTAL en cada zona', $ok['equipo'], true);
afirmar('vencidas ≤ EQUIPOS DESHABILITADOS en cada zona', $ok['vencidas'], true);

// El TOTAL contra un conteo INDEPENDIENTE: por estado de la base, una vez por
// cadena (raíz = continua_de o el propio aviso), sin pasar por grupoOrden().
$indep = [];
$vistasRaiz = [];
foreach ($casos as $c) {
    $g = $gestion[$c['aviso']] ?? ['estado' => 'NUEVO'];
    if (!in_array($g['estado'], ['NUEVO', 'ASIGNADO', 'EN_REVISION', 'ESPERA_REPUESTO'], true)) { continue; }
    $raiz = ($g['continua_de'] ?? '') !== '' ? $g['continua_de'] : $c['aviso'];
    if (isset($vistasRaiz[$raiz])) { continue; }
    $vistasRaiz[$raiz] = true;
    $indep[$c['zona']] = ($indep[$c['zona']] ?? 0) + 1;
}
$tarj = [];
foreach ($tz['zonas'] as $z => $t) { if ($t['total'] > 0) { $tarj[(string) $z] = $t['total']; } }
ksort($indep); ksort($tarj);
afirmar('TOTAL por zona = conteo independiente por estado', $tarj, $indep);
afirmar('total general de las tres zonas = UIO + LARB + CUENCA-LOJA', $tz['tres_zonas']['total'], 11 + 3 + 1);
afirmar('Σ tarjetas (con OTRA) + sin zona = cuadro TOTAL del buzón',
        array_sum(array_column($tz['zonas'], 'total')), $tz['total']);
afirmar('el cuadro TOTAL incluye OTRA y sin zona (15 + 1 + 1)', $tz['total'], 17);
afirmar('la línea general suma los deshabilitados de las tres zonas', $tz['tres_zonas']['deshabilitados'], 3);
afirmar('órdenes sin técnico (tarea «sin asignar») = fila 1 + fila 2',
        $tz['sin_tecnico'], array_sum(array_column($tz['zonas'], 'abiertas_sin_asignar'))
                          + array_sum(array_column($tz['zonas'], 'sin_asignar')));

/* -------------------------------------------------------------------------
   4. El buzón (`casos.php?grupo=`) lista EXACTAMENTE las filas de la cifra.
   ------------------------------------------------------------------------- */
echo "\n=== casos.php?grupo=: la cifra = las filas del enlace ===\n";
$clasif = Casos::clasificar($casos, $gestion, $inf);
$filas = static function (string $g, string $z) use ($casos, $clasif, $inf): int {
    return count(array_filter($casos, fn($c) => $c['zona'] === $z && Casos::enGrupo($g, $c, $clasif, $inf) === true));
};
$cuadra = true;
foreach ($tz['zonas'] as $z => $t) {
    foreach (['abiertas' => 'abiertas', 'espera_informe' => 'espera_informe',
              'total' => 'total', 'deshabilitados' => 'deshabilitados'] as $g => $k) {
        if ($filas($g, (string) $z) !== $t[$k]) { $cuadra = false; echo "    $z/$g: {$filas($g, (string) $z)} ≠ {$t[$k]}\n"; }
    }
}
afirmar('en cada zona y cada grupo, filas del enlace = cifra de la tarjeta', $cuadra, true);
afirmar('la raíz de la cadena no sale en el total (la representa la más nueva)',
        [$clasif['90000013']['en_total'], $clasif['90000014']['en_total']], [false, true]);

/* -------------------------------------------------------------------------
   5. I-7: sin una fuente, «no disponible»; nunca un cero.
   ------------------------------------------------------------------------- */
echo "\n=== Cuando falta una fuente (I-7) ===\n";
$sinAten = Casos::tarjetasPorZona($casos, $gestion,
    Casos::indiceInformes($gestion, null, $capturadas, $archivo, $pendientes), HOY)['zonas']['UIO'];
afirmar('sin atenciones.json: ÓRDENES ABIERTAS = null', $sinAten['abiertas'], null);
afirmar('sin atenciones.json: A ESPERA DE INFORME = null', $sinAten['espera_informe'], null);
afirmar('sin atenciones.json: sus sublíneas = null', [$sinAten['sin_asignar'], $sinAten['asignadas_3d']], [null, null]);
afirmar('sin atenciones.json: el TOTAL sí es numérico (11)', $sinAten['total'], 11);
$sinArch = Casos::indiceInformes($gestion, $aten, $capturadas, null, $pendientes);
afirmar('sin ot_archivo (009): ot_disponible = false', $sinArch['ot_disponible'], false);
$sin007 = Casos::tarjetasPorZona($casos, $gestion,
    Casos::indiceInformes($gestion, $aten, $capturadas, $archivo, null), HOY);
afirmar('sin pendientes (007): EQUIPOS DESHABILITADOS = null', $sin007['zonas']['UIO']['deshabilitados'], null);
afirmar('sin pendientes (007): vencidas = null, no 0', $sin007['zonas']['UIO']['vencidas'], null);
afirmar('sin pendientes (007): la línea general tampoco inventa', $sin007['tres_zonas']['deshabilitados'], null);
afirmar('sin pendientes (007): las filas 1 y 2 siguen', $sin007['zonas']['UIO']['abiertas'], 7);
$clasif2 = Casos::clasificar($casos, $gestion, Casos::indiceInformes($gestion, null, null, null, null));
afirmar('enGrupo(abiertas) sin fuentes → null (el buzón no lista a ojo)',
        Casos::enGrupo('abiertas', $casos[0], $clasif2, Casos::indiceInformes($gestion, null, null, null, null)), null);

/* -------------------------------------------------------------------------
   6. Qué tarjetas ve cada rol.
   ------------------------------------------------------------------------- */
echo "\n=== Qué tarjetas ve cada rol ===\n";
afirmar('administración: las tres zonas y OTRA ZONA (tiene órdenes)', Casos::zonasVisibles($tz, null), ['UIO', 'LARB', 'CNLJ', 'OTRA']);
$sinOtra = Casos::tarjetasPorZona(array_values(array_filter($casos, fn($c) => $c['zona'] !== 'OTRA')), $gestion, $inf, HOY);
afirmar('administración sin órdenes en OTRA: solo las tres', Casos::zonasVisibles($sinOtra, null), ['UIO', 'LARB', 'CNLJ']);
// El jefe de zona recibe de `enAlcance()` solo su zona; el panel le pasa la suya como fija.
$soloUio = array_values(array_filter($casos, fn($c) => $c['zona'] === 'UIO'));
$tzJefe  = Casos::tarjetasPorZona($soloUio, $gestion, $inf, HOY, ['UIO']);
afirmar('jefe de zona UIO: una sola tarjeta, la suya', Casos::zonasVisibles($tzJefe, 'UIO'), ['UIO']);
afirmar('jefe de zona UIO: con las mismas cifras que ve la administración', $tzJefe['zonas']['UIO'], $U);
afirmar('jefe de zona UIO: su cuadro TOTAL = su tarjeta', $tzJefe['total'], $U['total']);

/* -------------------------------------------------------------------------
   7. Los rótulos salen del diccionario y el panel no pide uno que no existe.
   ------------------------------------------------------------------------- */
echo "\n=== Rótulos del diccionario ===\n";
$TZ = Vocabulario::todo()['tarjeta_zona'];
afirmar('las cuatro filas de Isabel, en orden', array_column($TZ['filas'], 'clave'),
        ['ABIERTA', 'ESPERA_INFORME', 'EQUIPO_DESHABILITADO', 'TOTAL_ABIERTAS']);
afirmar('los rótulos de las filas van en MAYÚSCULAS',
        array_map(fn($f) => $f['rotulo'] === mb_strtoupper($f['rotulo']), $TZ['filas']), [true, true, true, true]);
// Las sublíneas y el pie que pinta panel.php: si el diccionario pierde una,
// la página lanza VocabularioError; aquí se ve antes.
$usadas = ['ABIERTA' => ['ESPERA_REPUESTO', 'SIN_ASIGNAR'],
           'ESPERA_INFORME' => ['SIN_ASIGNAR', 'ASIGNADA_3D', 'OTRO_TRABAJO_POR_DECIDIR', 'OT_NO_EMITIDA'],
           'EQUIPO_DESHABILITADO' => ['VENCIDO_48H', 'EQUIPO_OPERATIVO', 'SIN_DATO_EQUIPO']];
$faltan = [];
foreach ($TZ['filas'] as $f) {
    $hay = array_column($f['sublineas'] ?? [], 'clave');
    foreach ($usadas[$f['clave']] ?? [] as $s) { if (!in_array($s, $hay, true)) { $faltan[] = $f['clave'] . '/' . $s; } }
}
afirmar('toda sublínea que pinta el panel existe en tarjeta_zona', $faltan, []);
afirmar('el pie: atendidas, en revisión, sin regularizar', array_column($TZ['pie'], 'clave'), ['ATENDIDA', 'EN_REVISION', 'SIN_REGULARIZAR']);
afirmar('CNLJ se rotula ZONA CUENCA-LOJA', Vocabulario::titulo(Vocabulario::deEstado('CNLJ', 'zona')), 'ZONA CUENCA-LOJA');
afirmar('«sin zona» tiene su rótulo', Vocabulario::titulo(Vocabulario::deEstado('', 'zona')), 'SIN ZONA');
afirmar('ATENDIDO se llama «atendidas, por cerrar en SAP»', Vocabulario::t('ATENDIDA', 3), 'atendidas, por cerrar en SAP');

// La lista negra en el texto visible de panel.php (no en comentarios ni en
// identificadores o URL): lo que la tarjeta y las tareas dicen ya es el
// vocabulario único.
$neg = Vocabulario::todo()['lista_negra'];
$hallados = [];
foreach (token_get_all((string) file_get_contents(__DIR__ . '/../publico/panel.php')) as $tk) {
    if (!is_array($tk) || !in_array($tk[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) { continue; }
    $txt = trim($tk[1], "'\"");
    if (preg_match('/^[a-z0-9_]+$|^[A-Z0-9_]+$/', $txt) || str_contains($txt, '.php?')) { continue; }
    foreach ($neg as $w) {
        if (preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($w, '/') . '(?![\p{L}\p{N}_])/u', $txt)) {
            $hallados[] = $tk[2] . ':' . $w;
        }
    }
}
afirmar('panel.php: ningún término de la lista negra en texto visible', $hallados, []);

printf("\n%d comprobaciones · %d fallos\n", $total, $fallos);
exit($fallos === 0 ? 0 : 1);
