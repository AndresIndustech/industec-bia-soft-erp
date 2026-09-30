<?php
declare(strict_types=1);

/**
 * envio_real_cli.php — El envío real de las OT INDUSTEC, desde la línea de
 * órdenes (T2.29, 29-sep-2026). Hace lo mismo que las pestañas «Envío de las
 * OT» y «Cuenta de envío» de Correos, por las mismas funciones
 * (EnvioZonas, Correo), para quien trabaja por SSH sin sesión web: no hay una
 * segunda regla aquí.
 *
 * Uso, desde ot/:
 *   php envio_real_cli.php estado                           las zonas, la cuenta, la cola y lo que falta para activar
 *   php envio_real_cli.php probar      [--cuenta N]          conecta y se autentica con la cuenta (no envía)
 *   php envio_real_cli.php prueba DESTINO@industec.me [--cuenta N] --a-nombre-de USUARIO
 *                                                           manda un correo de prueba con PDF adjunto
 *   php envio_real_cli.php activar ZONA --a-nombre-de USUARIO [--nota "…"]
 *   php envio_real_cli.php piloto  ZONA --a-nombre-de USUARIO --motivo "…"
 *   php envio_real_cli.php despachar [--max N]              lo mismo que el cron
 *
 * `--a-nombre-de` es el login de un SUPERADMIN activo: activar una zona o
 * mandar una prueba queda a nombre de una persona (la que lo pidió), nunca
 * anónimo. Activar exige lo mismo que la pantalla (EnvioZonas::comprobaciones).
 * SOLO CLI.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/nucleo/Correo.php';
require_once __DIR__ . '/nucleo/EnvioZonas.php';

$args = array_slice($argv, 1);
$op = (string) ($args[0] ?? 'estado');
$pos = static function (int $i) use ($args): ?string { return isset($args[$i]) && !str_starts_with($args[$i], '--') ? $args[$i] : null; };
$opcion = static function (string $n) use ($args): ?string {
    $k = array_search($n, $args, true);
    return $k !== false && isset($args[$k + 1]) ? (string) $args[$k + 1] : null;
};
$persona = static function () use ($opcion): array {
    $login = $opcion('--a-nombre-de');
    $u = $login !== null ? Db::uno("SELECT usuario_id, usuario, nombre FROM usuarios WHERE usuario = ? AND rol = 'SUPERADMIN' AND activo = 1", [$login]) : null;
    if ($u === null) {
        fwrite(STDERR, "falta --a-nombre-de <login de un SUPERADMIN activo>: esto queda a nombre de una persona\n");
        exit(3);
    }
    return $u;
};

if ($op === 'estado') {
    echo "== zonas\n";
    foreach (EnvioZonas::estado() as $z => $e) {
        printf("  %-5s %-10s desde %s\n", $z, Emision::modo($z), $e['desde'] ?? '—');
        foreach (EnvioZonas::series($z) as $serie => $s) {
            printf("        %-16s real %-6s viejo %-6s al activar %-6s usos del viejo desde entonces %d%s\n", $serie,
                   $s['real'] ?? '—', $s['viejo'] ?? '—', $s['viejo_al_activar'] ?? '—', $s['vigilancia']['usos'],
                   $s['vigilancia']['choque'] ? '  ¡NÚMEROS REPETIDOS CON EL VIEJO: ' . implode(', ', $s['vigilancia']['repetidos']) . '!' : '');
        }
        if (Emision::modo($z) !== 'PRODUCCION' && in_array($z, EnvioZonas::ACTIVABLES, true)) {
            foreach (EnvioZonas::comprobaciones($z) as $c) { echo '        ' . ($c['ok'] ? '✓ ' : '✕ ') . $c['que'] . "\n"; }
        }
    }
    echo "== cuentas de envío\n";
    foreach (Correo::cuentas() as $c) {
        printf("  %d %s <%s> %s:%d %s · %s%s · prueba: %s %s\n", $c['cuenta_id'], $c['remitente_nombre'], $c['remitente'],
               $c['host'], $c['puerto'], $c['seguridad'], (int) $c['activa'] === 1 ? 'ACTIVA' : 'no activa',
               (int) $c['habilitada'] === 1 ? '' : ' (deshabilitada)', $c['probada_en'] ?? 'nunca',
               $c['probada_ok'] === null ? '' : ((int) $c['probada_ok'] === 1 ? 'bien' : 'FALLÓ'));
    }
    echo "== cola\n  " . json_encode(Correo::resumenCola(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$cuentaId = static function () use ($opcion): int {
    $n = $opcion('--cuenta');
    if ($n !== null) { return (int) $n; }
    $a = Db::uno('SELECT cuenta_id FROM correo_cuentas WHERE activa = 1');
    if ($a === null) { fwrite(STDERR, "no hay cuenta activa: indica --cuenta N\n"); exit(3); }
    return (int) $a['cuenta_id'];
};

if ($op === 'probar') {
    $u = Db::uno("SELECT usuario_id FROM usuarios WHERE rol = 'SUPERADMIN' AND activo = 1 ORDER BY usuario_id LIMIT 1");
    $r = Correo::probarYAnotar($cuentaId(), (int) ($u['usuario_id'] ?? 0));
    echo ($r['ok'] ? 'OK: ' : 'FALLA: ') . $r['detalle'] . "\n";
    exit($r['ok'] ? 0 : 1);
}

if ($op === 'prueba') {
    $destino = $pos(1);
    if ($destino === null) { fwrite(STDERR, "falta el destino (un buzón @industec.me)\n"); exit(3); }
    $u = $persona();
    $r = Correo::enviarPrueba($cuentaId(), $destino, (int) $u['usuario_id'], (string) $u['nombre'] . ' (por SSH)');
    echo ($r['ok'] ? 'OK: ' : 'FALLA: ') . $r['detalle'] . "\nreferencia: " . $r['referencia'] . "\n";
    exit($r['ok'] ? 0 : 1);
}

if ($op === 'activar' || $op === 'piloto') {
    $zona = strtoupper((string) $pos(1));
    if (!in_array($zona, EnvioZonas::ZONAS, true)) { fwrite(STDERR, "zona desconocida: $zona\n"); exit(3); }
    $u = $persona();
    if ($op === 'activar') {
        $r = EnvioZonas::activar($zona, (int) $u['usuario_id'], (string) ($opcion('--nota') ?? ''));
        if (!$r['ok']) { fwrite(STDERR, "NO se activó $zona:\n  - " . implode("\n  - ", $r['errores']) . "\n"); exit(1); }
        echo "envío real ACTIVO en $zona, a nombre de {$u['usuario']}:\n";
        foreach ($r['detalle'] as $serie => $d) {
            printf("  %-16s formulario viejo en %d · la serie queda en %d · primera OT de la app: %04d\n",
                   $serie, $d['viejo'], $d['sembrado'], $d['primera']);
        }
        exit(0);
    }
    $r = EnvioZonas::desactivar($zona, (int) $u['usuario_id'], (string) ($opcion('--motivo') ?? ''));
    if (!$r['ok']) { fwrite(STDERR, implode("\n", $r['errores']) . "\n"); exit(1); }
    echo "$zona volvió al piloto.\n";
    exit(0);
}

if ($op === 'despachar') {
    $r = Correo::despachar(max(1, min(200, (int) ($opcion('--max') ?? 30))));
    echo $r['estado'] . "\n" . implode("\n", $r['lineas']) . "\n";
    exit($r['fallidos'] > 0 ? 2 : 0);
}

fwrite(STDERR, "operación desconocida: $op (estado | probar | prueba | activar | piloto | despachar)\n");
exit(3);
