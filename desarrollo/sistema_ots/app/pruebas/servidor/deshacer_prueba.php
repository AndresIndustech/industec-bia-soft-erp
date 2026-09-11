<?php
declare(strict_types=1);

/**
 * deshacer_prueba.php — Revierte lo que dejó preparar_prueba.php y lo que hicieron
 * las cuentas de prueba. Solo línea de órdenes, desde ot/:
 *     php ~/respaldos/deshacer_prueba.php
 *
 * - Desactiva las 4 cuentas de prueba (no se borran: la bitácora las referencia).
 * - Borra lo que crearon ellas: órdenes capturadas, pendientes (y su hilo) y
 *   novedades. Es dato de prueba, no del cliente.
 * - Devuelve los dos casos del técnico A a como estaban antes.
 * - Borra los avisos sintéticos (9999xxxx) de T2.13.2 y T2.13.3: no son del cliente.
 * - Restaura catalogos/tecnicos.json desde su copia.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require getcwd() . '/nucleo/Db.php';

$R   = rtrim((string) getenv('HOME'), '/') . '/respaldos';
$DES = "$R/prueba_deshacer.json";
if (!is_file($DES)) { echo "No hay nada que deshacer ($DES no existe).\n"; exit(0); }
$d = json_decode((string) file_get_contents($DES), true) ?: [];
$ids = array_values(array_map('intval', $d['cuentas'] ?? []));
if (!$ids) { fwrite(STDERR, "El registro no trae las cuentas.\n"); exit(1); }
$en = implode(',', array_fill(0, count($ids), '?'));

$pdo = Db::conn();
$pdo->beginTransaction();
try {
    $n1 = Db::ejecutar("DELETE FROM ot_capturadas WHERE usuario_id IN ($en)", $ids);
    $n2 = Db::ejecutar("DELETE FROM pendientes WHERE abierto_por IN ($en) OR activo_fijo LIKE 'PRUEBA-%'", $ids);
    $n3 = Db::ejecutar("DELETE FROM novedades WHERE reportada_por IN ($en) OR novedad_uuid LIKE '99990000-%'", $ids);
    $ns = 0;
    foreach ($d['sinteticos'] ?? [] as $aviso) {
        $ns += Db::ejecutar("DELETE FROM casos_gestion WHERE aviso = ? AND aviso LIKE '9999%'", [(string) $aviso]);
    }
    $nc = 0;
    foreach ($d['casos'] ?? [] as $aviso => $info) {
        if (empty($info['existia'])) {
            $nc += Db::ejecutar("DELETE FROM casos_gestion WHERE aviso = ? AND asignado_a IN ($en)",
                                array_merge([$aviso], $ids));
        } else {
            $a = $info['antes'];
            $nc += Db::ejecutar('UPDATE casos_gestion SET estado = ?, zona = ?, asignado_a = ?, asignado_por = ?,
                                        asignado_en = ?, tecnico_auto = ?
                                  WHERE aviso = ?',
                                [$a['estado'], $a['zona'], $a['asignado_a'], $a['asignado_por'],
                                 $a['asignado_en'], (int) $a['tecnico_auto'], $aviso]);
        }
    }
    $nu = Db::ejecutar("UPDATE usuarios SET activo = 0, sesion_token = NULL, fecha_baja = CURDATE()
                         WHERE usuario_id IN ($en)", $ids);
    Db::ejecutar("INSERT INTO bitacora (accion, entidad, referencia, estado_despues, exito, detalle, datos, ip, equipo)
                  VALUES ('PRUEBA_DESHACER', 'prueba', 'T2.12.4-6', 'REVERTIDA', 1, ?, ?, '', 'CLI por SSH (PC de Andrés)')",
                 ['Se revirtieron las cuentas y los datos de prueba del alcance por rol',
                  json_encode(compact('n1', 'n2', 'n3', 'nc', 'ns', 'nu'))]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, 'SIN CAMBIOS en la base: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!empty($d['tecnicos_json']) && is_file($d['tecnicos_json'])) {
    copy($d['tecnicos_json'], 'catalogos/tecnicos.json.tmp');
    rename('catalogos/tecnicos.json.tmp', 'catalogos/tecnicos.json');
    unlink($d['tecnicos_json']);
    echo "catalogos/tecnicos.json restaurado\n";
}
@unlink("$R/claves_prueba.json");
unlink($DES);
echo "órdenes de prueba borradas: $n1 · pendientes: $n2 · novedades: $n3 · casos devueltos: $nc · "
   . "avisos sintéticos borrados: $ns · cuentas desactivadas: $nu\n";
