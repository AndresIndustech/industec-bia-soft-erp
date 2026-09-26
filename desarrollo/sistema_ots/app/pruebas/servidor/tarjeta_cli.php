<?php
declare(strict_types=1);

/**
 * tarjeta_cli.php — La tarjeta «Por zona» del panel, calculada contra la base REAL
 * con las mismas funciones que usa panel.php, sin pasar por HTTP ni por el login.
 *
 *     php ~/respaldos/tarjeta_cli.php ADMIN
 *     php ~/respaldos/tarjeta_cli.php JEFE_ZONA:UIO
 *
 * PARA QUE EXISTE
 * `prueba_panel_zona.php` prueba la lógica con datos sintéticos en memoria, y el arnés
 * de render usa una base falsa: ninguno de los dos ejecuta las consultas SQL nuevas de
 * `Casos::informesPorAviso()` (el `JSON_EXTRACT` sobre `ot_capturadas` y el vencido
 * calculado en SQL). Este es el único sitio donde MariaDB las ve.
 *
 * SOLO LEE, Y SOLO IMPRIME AGREGADOS: conteos por zona y las invariantes que la tarjeta
 * promete (TOTAL = ABIERTAS + A ESPERA; las zonas suman el total general; los
 * deshabilitados y los vencidos son subconjuntos). No imprime avisos, nombres, correos
 * ni usuarios: del usuario solo el rol y la zona.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once getcwd() . '/nucleo/Casos.php';
require_once getcwd() . '/nucleo/Pendientes.php';
require_once getcwd() . '/nucleo/Vocabulario.php';

$arg = $argv[1] ?? 'ADMIN';
if ($arg === 'ADMIN') {
    $u = Db::uno("SELECT * FROM usuarios WHERE rol IN ('ADMIN','SUPERADMIN') AND activo = 1 ORDER BY usuario_id LIMIT 1");
} elseif (str_starts_with($arg, 'JEFE_ZONA:')) {
    $u = Db::uno("SELECT * FROM usuarios WHERE rol = 'JEFE_ZONA' AND zona = ? AND activo = 1 ORDER BY usuario_id LIMIT 1", [substr($arg, 10)]);
} else {
    fwrite(STDERR, "Uso: tarjeta_cli.php ADMIN | JEFE_ZONA:<zona>\n"); exit(2);
}
if (!$u) { fwrite(STDERR, "No hay un usuario activo para '$arg'.\n"); exit(1); }
$rp = new ReflectionProperty(Auth::class, 'usuario');
$rp->setAccessible(true);
$rp->setValue(null, $u);
$zonaAlc = Auth::zonaAlcance();

$t0 = microtime(true);
$fuente   = Casos::catalogo();
$gestion  = Casos::gestion();
$casos    = Casos::enAlcance($fuente['datos'] ?? [], $gestion);
$informes = Casos::informesPorAviso($gestion);
$tz       = Casos::tarjetasPorZona($casos, $gestion, $informes, date('Y-m-d'), $zonaAlc !== null ? [$zonaAlc] : []);
$seg      = round(microtime(true) - $t0, 2);

$ok = 0; $mal = 0;
$chk = static function (string $que, bool $cumple, string $dato = '') use (&$ok, &$mal): void {
    if ($cumple) { $ok++; } else { $mal++; }
    echo ($cumple ? 'PASA  ' : 'FALLA ') . $que . ($dato !== '' ? "   [$dato]" : '') . "\n";
};

echo "usuario: rol={$u['rol']} zona=" . ($u['zona'] ?? '-') . " · alcance=" . ($zonaAlc ?? 'todas') . " · casos en alcance: " . count($casos) . " · calculado en {$seg} s\n";
echo "fuentes: OT emitidas " . ($tz['ot_disponible'] ? 'disponible' : 'NO DISPONIBLE')
   . " · estado del equipo " . ($tz['equipo_disponible'] ? 'disponible' : 'NO DISPONIBLE') . "\n\n";

// Solo escalares y conteos de $informes: nunca su contenido (trae avisos).
echo "insumos (\$informes):";
foreach ($informes as $k => $v) { echo ' ' . $k . '=' . (is_array($v) ? 'n' . count($v) : var_export($v, true)); }
echo "\n\n";

$campos = ['total', 'abiertas', 'abiertas_espera_repuesto', 'espera_informe', 'sin_asignar', 'asignadas_3d',
           'deshabilitados', 'vencidas', 'operativos', 'sin_dato', 'en_revision', 'atendidas', 'sin_regularizar'];
printf("%-10s", 'zona');
foreach ($campos as $c) { printf(" %9.9s", $c); }
echo "\n";
foreach ($tz['zonas'] as $z => $f) {
    printf("%-10s", $z === '' ? '(sin zona)' : $z);
    foreach ($campos as $c) { printf(" %9s", (string) ($f[$c] ?? '-')); }
    echo "\n";
}
echo "\ntres zonas: " . json_encode($tz['tres_zonas'], JSON_UNESCAPED_UNICODE) . "  · total general: {$tz['total']}\n\n";

// ---- las invariantes que la tarjeta promete -------------------------------------------
$sumTotal = 0; $sumAbiertas = 0; $sumEspera = 0;
foreach ($tz['zonas'] as $f) { $sumTotal += (int) $f['total']; $sumAbiertas += (int) $f['abiertas']; $sumEspera += (int) $f['espera_informe']; }
foreach ($tz['zonas'] as $z => $f) {
    $n = $z === '' ? 'sin zona' : $z;
    if ($tz['ot_disponible']) {
        $chk("$n: TOTAL = ÓRDENES ABIERTAS + A ESPERA DE INFORME TÉCNICO", (int) $f['total'] === (int) $f['abiertas'] + (int) $f['espera_informe'], "{$f['total']} = {$f['abiertas']} + {$f['espera_informe']}");
        $chk("$n: 'sin asignar' ≤ A ESPERA DE INFORME", (int) $f['sin_asignar'] <= (int) $f['espera_informe']);
        $chk("$n: 'asignadas 3+ días' ≤ A ESPERA DE INFORME", (int) $f['asignadas_3d'] <= (int) $f['espera_informe']);
    }
    if ($tz['equipo_disponible']) {
        $chk("$n: EQUIPOS DESHABILITADOS ≤ TOTAL", (int) $f['deshabilitados'] <= (int) $f['total']);
        $chk("$n: 'vencidas' ≤ EQUIPOS DESHABILITADOS", (int) $f['vencidas'] <= (int) $f['deshabilitados']);
        $chk("$n: deshabilitados + operativos + sin dato = TOTAL", (int) $f['deshabilitados'] + (int) $f['operativos'] + (int) $f['sin_dato'] === (int) $f['total'],
             "{$f['deshabilitados']} + {$f['operativos']} + {$f['sin_dato']} vs {$f['total']}");
    }
}
$chk('total general = suma de las tarjetas', $sumTotal === (int) $tz['total'], "$sumTotal vs {$tz['total']}");

// ---- conteo INDEPENDIENTE por estado (sin grupoOrden), para no probar la función con ella misma ----
$abiertos = ['NUEVO', 'ASIGNADO', 'EN_REVISION', 'ESPERA_REPUESTO'];
$indep = 0; $porEstado = [];
foreach ($casos as $c) {
    $e = (string) ($gestion[(string) ($c['aviso'] ?? '')]['estado'] ?? 'NUEVO');
    $porEstado[$e] = ($porEstado[$e] ?? 0) + 1;
    if (in_array($e, $abiertos, true)) { $indep++; }
}
ksort($porEstado);
echo "\nestados en el alcance (conteo directo): " . json_encode($porEstado, JSON_UNESCAPED_UNICODE) . "\n";
echo "abiertos por estado (sin cadenas de continuidad): $indep  ·  TOTAL de la tarjeta: {$tz['total']}\n";
$chk('TOTAL de la tarjeta ≤ abiertos por estado (la tarjeta cuenta una vez cada cadena de continuidad)', (int) $tz['total'] <= $indep, "{$tz['total']} ≤ $indep");

echo "\n" . $ok . ' comprobaciones · ' . $mal . " fallos\n";
exit($mal === 0 ? 0 : 1);
