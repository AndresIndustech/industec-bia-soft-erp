<?php
// Las consultas de deteccion que documenta sql/005. Se corren de verdad, para
// comprobar que la bitacora sirve para lo que se hizo: encontrar anomalias.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/nucleo/Db.php';

echo "volumen del registro\n";
foreach (Db::todos("SELECT accion, exito, COUNT(*) n FROM bitacora
                    GROUP BY accion, exito ORDER BY n DESC LIMIT 14") as $r) {
    printf("  %-22s %-9s %5d\n", $r['accion'], $r['exito'] ? 'ok' : 'RECHAZO', $r['n']);
}

echo "\n1. Intentos rechazados, por persona y accion\n";
$f = Db::todos("SELECT usuario, accion, COUNT(*) n FROM bitacora
                 WHERE exito = 0 GROUP BY usuario, accion ORDER BY n DESC");
foreach ($f as $r) { printf("   %-12s %-14s %d\n", $r['usuario'], $r['accion'], $r['n']); }
if (!$f) { echo "   ninguno\n"; }

echo "\n2. Transiciones registradas (origen -> destino)\n";
foreach (Db::todos("SELECT COALESCE(estado_antes,'-') a, COALESCE(estado_despues,'-') d,
                           COUNT(*) n FROM bitacora
                     WHERE entidad='caso' GROUP BY a, d ORDER BY n DESC LIMIT 10") as $r) {
    printf("   %-22s -> %-22s %5d\n", $r['a'], $r['d'], $r['n']);
}

echo "\n3. Saltos imposibles: RESUELTO sin haber pasado por ATENDIDO\n";
$s = Db::todos("SELECT referencia, usuario, estado_antes, cuando FROM bitacora
                 WHERE entidad='caso' AND estado_despues='RESUELTO'
                   AND (estado_antes IS NULL OR estado_antes NOT IN ('ATENDIDO','EN_REVISION'))");
foreach ($s as $r) { printf("   %s por %s desde %s\n", $r['referencia'], $r['usuario'], $r['estado_antes']); }
if (!$s) { echo "   ninguno\n"; }

echo "\n4. Casos que rebotan de tecnico (mas de 2 asignaciones)\n";
$b = Db::todos("SELECT referencia, COUNT(*) n FROM bitacora
                 WHERE accion='ASIGNAR' GROUP BY referencia HAVING n > 2 ORDER BY n DESC");
foreach ($b as $r) { printf("   %s: %d veces\n", $r['referencia'], $r['n']); }
if (!$b) { echo "   ninguno\n"; }

echo "\n5. Quien abrio PDFs, y cuantos\n";
$p = Db::todos("SELECT usuario, COUNT(*) n FROM bitacora
                 WHERE accion='ABRIR_PDF' GROUP BY usuario ORDER BY n DESC");
foreach ($p as $r) { printf("   %-12s %d\n", $r['usuario'], $r['n']); }
if (!$p) { echo "   ninguno todavia\n"; }

echo "\n6. Lo que hizo el sistema solo, separado de lo que hizo una persona\n";
foreach (Db::todos("SELECT CASE WHEN usuario='sistema' THEN 'automatico' ELSE 'persona' END q,
                           COUNT(*) n FROM bitacora GROUP BY q") as $r) {
    printf("   %-12s %d\n", $r['q'], $r['n']);
}

echo "\n7. Los datos en JSON son consultables?\n";
$j = Db::uno("SELECT datos FROM bitacora WHERE accion='ASIGNAR' AND datos IS NOT NULL LIMIT 1");
echo '   ejemplo: ' . ($j['datos'] ?? '(sin datos)') . "\n";
