<?php
declare(strict_types=1);

/**
 * hora_ecuador.php — T2.13.7: pasa a la hora de Ecuador (UTC−5) las fechas que el
 * servidor escribió con su propio reloj, que corría en UTC. Una sola vez, justo
 * después de subir el Db.php que fija `time_zone = '-05:00'`.
 *
 * Uso, desde la carpeta ot/ del sitio de pruebas:
 *     php ~/respaldos/hora_ecuador.php         -> ensayo: dice qué correría y cuántas filas
 *     php ~/respaldos/hora_ecuador.php --si    -> lo hace, en una sola transacción
 *
 * Qué se corre: toda columna DATETIME de la base (se descubren en
 * information_schema, no a mano, para que una tabla nueva no se quede atrás).
 * Qué NO se corre, y por qué:
 *   - casos_gestion.atendido_en: es la fecha de la orden de cierre, que llega del
 *     informe ya en hora local (cae unos minutos antes del alta del caso menos
 *     5 h). No la escribió el reloj del servidor.
 *   - las columnas DATE (fecha_baja, prometido_para): son fechas de negocio.
 *
 * Tres seguros: se niega si la base no escribía en UTC (la de la estación ya va
 * en hora de Ecuador y la dejaría cinco horas atrás); se niega a correr dos veces
 * (deja la marca HORA_ECUADOR en la bitácora); y antes de confirmar comprueba que
 * ninguna fecha quedó en el futuro, que es justo lo que delata una fecha en UTC
 * leída en hora de Ecuador. Si algo no cuadra, revierte todo.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$cfg = require getcwd() . '/nucleo/config.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_port'], $cfg['db_name']),
    $cfg['db_user'], $cfg['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES => false,
     PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci']
);
$si = in_array('--si', $argv, true);
$EXCLUIDAS = ['casos_gestion.atendido_en'];
$PUEDEN_SER_FUTURAS = ['usuarios.bloqueado_hasta'];

$desfase = (int) $pdo->query('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) d')->fetch()['d'];
if ($desfase !== 0) {
    fwrite(STDERR, "Esta base no escribía en UTC (su reloj va $desfase min del de UTC): no hay nada que correr.\n");
    exit(1);
}
$pdo->exec("SET time_zone = '-05:00'");   // de aquí en adelante, como Db.php

if ((int) $pdo->query("SELECT COUNT(*) n FROM bitacora WHERE accion = 'HORA_ECUADOR'")->fetch()['n']) {
    echo "Ya se pasó a la hora de Ecuador (hay una marca HORA_ECUADOR en la bitácora). No se repite.\n";
    exit(0);
}

$cols = [];
foreach ($pdo->query("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'datetime'
                       ORDER BY TABLE_NAME, ORDINAL_POSITION") as $r) {
    if (in_array("{$r['t']}.{$r['c']}", $EXCLUIDAS, true)) { continue; }
    $cols[$r['t']][] = $r['c'];
}

/** Las fechas que quedarían en el futuro en hora de Ecuador: [tabla.columna => máximo]. */
$futuras = static function () use ($pdo, $cols, $PUEDEN_SER_FUTURAS): array {
    $f = [];
    foreach ($cols as $t => $cs) {
        foreach ($cs as $c) {
            if (in_array("$t.$c", $PUEDEN_SER_FUTURAS, true)) { continue; }
            $m = $pdo->query("SELECT MAX(`$c`) m, MAX(`$c`) > NOW() + INTERVAL 1 MINUTE f FROM `$t`")->fetch();
            if ($m['f']) { $f["$t.$c"] = $m['m']; }
        }
    }
    return $f;
};

echo $si ? "Pasando a la hora de Ecuador (−5 h):\n" : "ENSAYO. Pasaría a la hora de Ecuador (−5 h) (agrega --si para hacerlo):\n";
$total = [];
foreach ($cols as $t => $cs) {
    $n = [];
    foreach ($cs as $c) { $n[$c] = (int) $pdo->query("SELECT COUNT(`$c`) n FROM `$t`")->fetch()['n']; }
    $total[$t] = $n;
    printf("  %-17s %s\n", $t, implode(', ', array_map(static fn($c) => "$c ({$n[$c]})", $cs)));
}
echo "  no se tocan: ", implode(', ', $EXCLUIDAS), ", y las columnas DATE\n";
$antes = $futuras();
echo "  hoy, leídas en hora de Ecuador, quedan en el futuro: ", $antes ? implode(', ', array_keys($antes)) : 'ninguna', "\n";
if (!$si) { exit(0); }

$pdo->beginTransaction();
try {
    foreach ($cols as $t => $cs) {
        // Una sola sentencia por tabla: al fijar tocado_en en el mismo SET, su
        // ON UPDATE CURRENT_TIMESTAMP no la pisa con la hora del momento.
        $set = implode(', ', array_map(static fn($c) => "`$c` = `$c` - INTERVAL 5 HOUR", $cs));
        $pdo->exec("UPDATE `$t` SET $set");
    }
    $quedan = $futuras();
    if ($quedan) {
        throw new RuntimeException('siguen en el futuro: ' . json_encode($quedan));
    }
    $pdo->prepare("INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia, estado_antes,
                                         estado_despues, exito, detalle, datos, ip, equipo)
                   VALUES (NULL, NULL, 'HORA_ECUADOR', 'base', 'T2.13.7', 'UTC', 'UTC-5', 1, ?, ?, NULL, ?)")
        ->execute(['Fechas del reloj del servidor pasadas a hora de Ecuador; no se tocó atendido_en ni las DATE',
                   json_encode($total, JSON_UNESCAPED_UNICODE), 'CLI por SSH']);
    $pdo->commit();
    echo "hecho. Ninguna fecha quedó en el futuro. Marca HORA_ECUADOR en la bitácora.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, 'SIN CAMBIOS: ' . $e->getMessage() . "\n");
    exit(1);
}
