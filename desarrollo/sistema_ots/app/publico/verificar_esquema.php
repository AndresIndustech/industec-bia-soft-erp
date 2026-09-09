<?php
declare(strict_types=1);

/**
 * verificar_esquema.php — Comprueba que la base quedó como dicen las migraciones.
 *
 * No basta con que el ALTER no diera error: hay que mirar lo que quedó. Estas
 * son las comprobaciones que van al pie de cada archivo de `sql/`, ejecutadas
 * de verdad en vez de copiadas a mano.
 *
 * Solo CLI, y solo lee.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$cfg = require __DIR__ . '/nucleo/config.php';
$db = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['db_host'], $cfg['db_port'], $cfg['db_name']),
    $cfg['db_user'], $cfg['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$ok = true;
function comprobar(string $que, $real, $esperado): void
{
    global $ok;
    $bien = is_callable($esperado) ? $esperado($real) : ($real == $esperado);
    $ok = $ok && $bien;
    printf("  %-46s %-30s %s\n", $que,
           is_scalar($real) ? (string) $real : gettype($real),
           $bien ? 'OK' : 'FALLA');
}

echo "casos_gestion\n";
$e = $db->query("SHOW COLUMNS FROM casos_gestion LIKE 'estado'")->fetch();
comprobar('estado tiene ATENDIDO', str_contains($e['Type'], "'ATENDIDO'") ? 'si' : 'no', 'si');
comprobar('estado tiene CERRADO_SIN_ATENCION',
          str_contains($e['Type'], "'CERRADO_SIN_ATENCION'") ? 'si' : 'no', 'si');
$pk = $db->query("SHOW KEYS FROM casos_gestion WHERE Key_name='PRIMARY'")->fetch();
comprobar('clave primaria (I-9: la de negocio)', $pk['Column_name'], 'aviso');

$cols = array_column($db->query('SHOW COLUMNS FROM casos_gestion')->fetchAll(), 'Field');
foreach (['ot_cierre', 'atendido_en', 'tecnico_auto', 'regularizado_por', 'regularizado_en'] as $c) {
    comprobar("columna $c", in_array($c, $cols, true) ? 'si' : 'no', 'si');
}
$fk = $db->query("SELECT COUNT(*) n FROM information_schema.KEY_COLUMN_USAGE
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'casos_gestion'
                     AND REFERENCED_TABLE_NAME = 'usuarios'")->fetch();
comprobar('clave foranea a usuarios', $fk['n'], 1);

echo "\nbitacora\n";
$cols = array_column($db->query('SHOW COLUMNS FROM bitacora')->fetchAll(), 'Field');
foreach (['estado_antes', 'estado_despues', 'exito', 'datos', 'equipo'] as $c) {
    comprobar("columna $c", in_array($c, $cols, true) ? 'si' : 'no', 'si');
}
$k = array_unique(array_column($db->query('SHOW KEYS FROM bitacora')->fetchAll(), 'Key_name'));
comprobar('indice de transiciones', in_array('idx_bitacora_transicion', $k, true) ? 'si' : 'no', 'si');
comprobar('indice de rechazos', in_array('idx_bitacora_rechazos', $k, true) ? 'si' : 'no', 'si');

echo "\npermisos\n";
$r = [];
foreach ($db->query('SELECT rol, COUNT(*) n FROM rol_permisos GROUP BY rol') as $x) {
    $r[$x['rol']] = (int) $x['n'];
}
comprobar('SUPERADMIN', $r['SUPERADMIN'] ?? 0, 19);
comprobar('ADMIN', $r['ADMIN'] ?? 0, 18);
comprobar('JEFE_ZONA', $r['JEFE_ZONA'] ?? 0, 11);
comprobar('TECNICO', $r['TECNICO'] ?? 0, 5);
comprobar('ots.pdf existe',
          (int) $db->query("SELECT COUNT(*) FROM permisos WHERE codigo='ots.pdf'")->fetchColumn(), 1);

echo "\n" . ($ok ? 'TODO OK' : 'HAY FALLAS') . "\n";
exit($ok ? 0 : 1);
