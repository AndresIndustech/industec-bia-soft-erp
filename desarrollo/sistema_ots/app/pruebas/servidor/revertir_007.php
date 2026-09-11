<?php
declare(strict_types=1);

/**
 * revertir_007.php — Deshace la migración 007 en el sitio de pruebas. Solo si algo salió mal.
 *
 * Uso, desde la carpeta ot/ del sitio:
 *     php ~/respaldos/revertir_007.php               -> solo dice lo que haría
 *     php ~/respaldos/revertir_007.php --si          -> lo hace
 *     php ~/respaldos/revertir_007.php --si --forzar -> aunque las tablas nuevas tengan filas
 *
 * No es un DELETE a ciegas: sin --forzar se niega si pendientes, ot_capturadas o
 * novedades ya tienen datos, porque se perderían. El respaldo completo de antes
 * de la 007 está en ~/respaldos/darkviolet_bd_antes_007_20260911_033811.sql.gz.
 *
 * No va por aplicar_sql.php porque ese no admite DELETE, a propósito.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require getcwd() . '/nucleo/Db.php';

$si     = in_array('--si', $argv, true);
$forzar = in_array('--forzar', $argv, true);

$tablas   = ['pendiente_notas', 'pendientes', 'ot_capturadas', 'novedades'];
$permisos = ['repuestos.ver', 'repuestos.pedir', 'repuestos.veredicto', 'repuestos.gestionar',
             'novedades.ver', 'novedades.reportar', 'novedades.gestionar'];

$filas = [];
foreach ($tablas as $t) {
    $existe = (int) Db::uno('SELECT COUNT(*) c FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t])['c'];
    $filas[$t] = $existe ? (int) Db::uno("SELECT COUNT(*) c FROM `$t`")['c'] : null;
}
$espera = (int) Db::uno("SELECT COUNT(*) c FROM casos_gestion WHERE estado = 'ESPERA_REPUESTO'")['c'];

echo "Estado actual:\n";
foreach ($filas as $t => $n) { echo "  $t: ", $n === null ? 'no existe' : "$n filas", "\n"; }
echo "  casos en ESPERA_REPUESTO: $espera\n";

$conDatos = array_keys(array_filter($filas, static fn($n) => (int) $n > 0));
if ($conDatos && !$forzar) {
    echo "\nHay filas en " . implode(', ', $conDatos) . ". No se revierte sin --forzar: se perderían.\n";
    exit(1);
}

$pasos = [
    'DROP TRIGGER IF EXISTS tr_pnota_insiste',
    'DROP TABLE IF EXISTS pendiente_notas',
    'DROP TABLE IF EXISTS pendientes',
    'DROP TABLE IF EXISTS ot_capturadas',
    'DROP TABLE IF EXISTS novedades',
    // rol_permisos y usuario_permisos tienen ON DELETE CASCADE hacia permisos:
    // sus filas se van con el permiso.
    "DELETE FROM permisos WHERE codigo IN ('" . implode("','", $permisos) . "')",
];
if ($espera === 0) {
    // El ENUM exacto de antes de la 007 (esquema vivo del 2026-09-11).
    $pasos[] = "ALTER TABLE casos_gestion MODIFY COLUMN estado
                  ENUM('NUEVO','ASIGNADO','EN_REVISION','RESUELTO','NO_COMPETE','ATENDIDO','CERRADO_SIN_ATENCION')
                  NOT NULL DEFAULT 'NUEVO'
                  COMMENT 'CERRADO_SIN_ATENCION: paso una semana y nadie lo atendio. Arrastra pendiente'";
} else {
    echo "  El ENUM de casos_gestion.estado NO se revierte: $espera caso(s) están en ESPERA_REPUESTO.\n";
}

echo "\n", $si ? "Ejecutando:\n" : "Haría esto (agrega --si para ejecutarlo):\n";
foreach ($pasos as $p) {
    echo '  ', preg_replace('/\s+/', ' ', $p), "\n";
    if ($si) { Db::conn()->exec($p); echo "    ok\n"; }
}

if ($si) {
    $tot = Db::todos('SELECT rol, COUNT(*) n FROM rol_permisos GROUP BY rol ORDER BY rol');
    echo "\nPermisos por rol ahora: ",
         implode(' · ', array_map(static fn($r) => $r['rol'] . ' ' . $r['n'], $tot)),
         "\n(antes de la 007: SUPERADMIN 19 · ADMIN 18 · JEFE_ZONA 11 · TECNICO 5)\n",
         "Luego: php verificar_esquema.php tiene que decir «permisos (sin la 007)» y TODO OK.\n";
}
