<?php
declare(strict_types=1);

/**
 * verificar_esquema.php — Comprueba que la base quedó como dicen las migraciones.
 *
 * No basta con que el ALTER no diera error: hay que mirar lo que quedó. Estas
 * son las comprobaciones que van al pie de cada archivo de `sql/`, ejecutadas
 * de verdad en vez de copiadas a mano.
 *
 * Distingue si la 007 está aplicada. Sin eso, los conteos fijos de permisos
 * (19/18/11/5) daban FALLA justo después de aplicarla bien, y nada de lo que
 * crea la 007 se comprobaba (auditoría del 2026-09-10).
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

$hayTabla = static fn(string $t): bool => (int) $db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $db->quote($t))->fetchColumn() > 0;
$hay007 = $hayTabla('pendientes');
$hay008 = $hayTabla('correlativos');
$hay009 = $hayTabla('migraciones');
// La 010 amplía el ENUM de sesiones_log con SIN_SESION (SEG-20).
$hay010 = str_contains((string) ($db->query("SHOW COLUMNS FROM sesiones_log LIKE 'evento'")->fetch()['Type'] ?? ''), 'SIN_SESION');

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
// La 003 trae `asignado_a`; la 012 suma `continua_por` (quien declaro que un
// caso continua un trabajo anterior). Las dos con ON DELETE SET NULL.
$hay012 = (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'casos_gestion'
                               AND COLUMN_NAME = 'continua_de'")->fetchColumn() > 0;
comprobar('clave foranea a usuarios', $fk['n'], $hay012 ? 2 : 1);

echo "\nbitacora\n";
$cols = array_column($db->query('SHOW COLUMNS FROM bitacora')->fetchAll(), 'Field');
foreach (['estado_antes', 'estado_despues', 'exito', 'datos', 'equipo'] as $c) {
    comprobar("columna $c", in_array($c, $cols, true) ? 'si' : 'no', 'si');
}
$k = array_unique(array_column($db->query('SHOW KEYS FROM bitacora')->fetchAll(), 'Key_name'));
comprobar('indice de transiciones', in_array('idx_bitacora_transicion', $k, true) ? 'si' : 'no', 'si');
comprobar('indice de rechazos', in_array('idx_bitacora_rechazos', $k, true) ? 'si' : 'no', 'si');

echo "\npermisos" . ($hay009 ? ' (con la 009)' : ($hay007 ? ' (con la 007)' : ' (sin la 007)')) . "\n";
$r = [];
foreach ($db->query('SELECT rol, COUNT(*) n FROM rol_permisos GROUP BY rol') as $x) {
    $r[$x['rol']] = (int) $x['n'];
}
// La 007 suma 7 permisos a SUPERADMIN y ADMIN, 6 a JEFE_ZONA y 4 a TECNICO.
// La 009 suma 13 a SUPERADMIN y ADMIN, 9 a JEFE_ZONA y 4 a TECNICO.
// La 012 suma 1 a los cuatro: `casos.continuidad`.
$mas012 = $hay012 ? 1 : 0;
$esperado = $hay009
    ? ['SUPERADMIN' => 39 + $mas012, 'ADMIN' => 38 + $mas012,
       'JEFE_ZONA' => 26 + $mas012, 'TECNICO' => 13 + $mas012]
    : ($hay007
        ? ['SUPERADMIN' => 26, 'ADMIN' => 25, 'JEFE_ZONA' => 17, 'TECNICO' => 9]
        : ['SUPERADMIN' => 19, 'ADMIN' => 18, 'JEFE_ZONA' => 11, 'TECNICO' => 5]);
foreach ($esperado as $rol => $n) {
    comprobar($rol, $r[$rol] ?? 0, $n);
}
comprobar('ots.pdf existe',
          (int) $db->query("SELECT COUNT(*) FROM permisos WHERE codigo='ots.pdf'")->fetchColumn(), 1);

if ($hay007) {
    echo "\nmigracion 007\n";
    $e = $db->query("SHOW COLUMNS FROM casos_gestion LIKE 'estado'")->fetch();
    comprobar('ENUM del caso termina en ESPERA_REPUESTO',
              str_ends_with($e['Type'], ",'ESPERA_REPUESTO')") ? 'si' : 'no', 'si');

    $unica = function (string $tabla, string $indice) use ($db): string {
        $f = $db->query("SHOW KEYS FROM `$tabla` WHERE Key_name = " . $db->quote($indice)
                      . " AND Non_unique = 0")->fetchAll();
        return implode(',', array_column($f, 'Column_name'));
    };
    comprobar('uq_pendiente (I-9)', $unica('pendientes', 'uq_pendiente'), 'aviso,activo_fijo');
    comprobar('uq_captura_envio (I-9)', $unica('ot_capturadas', 'uq_captura_envio'), 'envio_uuid');
    comprobar('uq_novedad (I-9)', $unica('novedades', 'uq_novedad'), 'novedad_uuid');

    $cols = array_column($db->query('SHOW COLUMNS FROM pendientes')->fetchAll(), 'Field');
    comprobar('columna plazo_desde', in_array('plazo_desde', $cols, true) ? 'si' : 'no', 'si');
    $t = $db->query("SHOW COLUMNS FROM pendiente_notas LIKE 'tipo'")->fetch();
    comprobar('nota DIAGNOSTICO', str_contains($t['Type'], "'DIAGNOSTICO'") ? 'si' : 'no', 'si');
    $tr = $db->query("SHOW TRIGGERS WHERE `Table` = 'pendiente_notas'")->fetchAll();
    comprobar('trigger de insistencias', count($tr), 1);
    $fk = $db->query("SELECT COUNT(*) n FROM information_schema.KEY_COLUMN_USAGE
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pendientes'
                         AND REFERENCED_TABLE_NAME = 'usuarios'")->fetch();
    // La 009 agrega tres (validado_por, registrado_sap_por, veredicto_kfc_por).
    comprobar('claves foraneas de pendientes a usuarios', $fk['n'], $hay009 ? 6 : 3);
}

if ($hay008) {
    echo "\nmigracion 008\n";
    $unica ??= function (string $tabla, string $indice) use ($db): string {
        $f = $db->query("SHOW KEYS FROM `$tabla` WHERE Key_name = " . $db->quote($indice)
                      . " AND Non_unique = 0")->fetchAll();
        return implode(',', array_column($f, 'Column_name'));
    };
    comprobar('uq_cap_industec (I-9)', $unica('ot_capturadas', 'uq_cap_industec'), 'id_industec');
    comprobar('uq_foto (I-9)', $unica('ot_fotos', 'uq_foto'), 'foto_uuid');
    comprobar('uq_correo (I-9)', $unica('email_queue', 'uq_correo'),
              $hay009 ? 'id_industec,tipo,secuencia' : 'id_industec,tipo');
    $pk = $db->query("SHOW KEYS FROM correlativos WHERE Key_name='PRIMARY'")->fetch();
    comprobar('correlativos: PK serie', $pk['Column_name'] ?? '', 'serie');
    $cols = array_column($db->query('SHOW COLUMNS FROM ot_capturadas')->fetchAll(), 'Field');
    foreach (['emitida_en', 'pdf_sha256', 'emision_error'] as $c) {
        comprobar("columna $c", in_array($c, $cols, true) ? 'si' : 'no', 'si');
    }
    $fk = $db->query("SELECT COUNT(*) n FROM information_schema.KEY_COLUMN_USAGE
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_queue'
                         AND REFERENCED_TABLE_NAME = 'ot_capturadas'")->fetch();
    comprobar('email_queue apunta a ot_capturadas', $fk['n'], 1);
}

if ($hay009) {
    echo "\nmigracion 009\n";
    foreach (['migraciones', 'ot_archivo', 'ot_archivo_solicitudes', 'equipos_propuestos',
              'familias_equipo', 'diagnosticos', 'repuestos_frecuentes', 'locales_admin',
              'documentos', 'documento_versiones', 'documento_acuses', 'ingresos_preventivos',
              'cronograma_novedades', 'casos_seguimientos'] as $t) {
        comprobar("tabla $t", $hayTabla($t) ? 'si' : 'no', 'si');
    }
    $e = $db->query("SHOW COLUMNS FROM pendientes LIKE 'estado'")->fetch();
    comprobar('ENUM de pendientes termina en OTRO_PROVEEDOR',
              str_ends_with($e['Type'], ",'OTRO_PROVEEDOR')") ? 'si' : 'no', 'si');
    $e = $db->query("SHOW COLUMNS FROM email_queue LIKE 'estado'")->fetch();
    comprobar('ENUM de email_queue termina en ENVIANDO',
              str_ends_with($e['Type'], ",'ENVIANDO')") ? 'si' : 'no', 'si');
    $e = $db->query("SHOW COLUMNS FROM ot_capturadas LIKE 'estado'")->fetch();
    comprobar('ENUM de ot_capturadas termina en FALLIDA',
              str_ends_with($e['Type'], ",'FALLIDA')") ? 'si' : 'no', 'si');
    $e = $db->query("SHOW COLUMNS FROM pendiente_notas LIKE 'tipo'")->fetch();
    comprobar('nota VEREDICTO_KFC', str_contains($e['Type'], "'VEREDICTO_KFC'") ? 'si' : 'no', 'si');
    $cols = array_column($db->query('SHOW COLUMNS FROM pendientes')->fetchAll(), 'Field');
    foreach (['validado_por', 'requerimiento_sap', 'veredicto_kfc', 'diagnostico_codigo', 'partes'] as $c) {
        comprobar("pendientes.$c", in_array($c, $cols, true) ? 'si' : 'no', 'si');
    }
    $cols = array_column($db->query('SHOW COLUMNS FROM email_queue')->fetchAll(), 'Field');
    foreach (['tomado_en', 'proximo_intento_en', 'error_ultimo', 'secuencia'] as $c) {
        comprobar("email_queue.$c", in_array($c, $cols, true) ? 'si' : 'no', 'si');
    }
    $cols = array_column($db->query('SHOW COLUMNS FROM ot_capturadas')->fetchAll(), 'Field');
    foreach (['ultimo_reintento_en', 'pdf_sha256_regen', 'con_proveedor'] as $c) {
        comprobar("ot_capturadas.$c", in_array($c, $cols, true) ? 'si' : 'no', 'si');
    }
    $k = array_unique(array_column($db->query('SHOW KEYS FROM ot_capturadas')->fetchAll(), 'Key_name'));
    foreach (['idx_cap_aviso', 'idx_cap_zona', 'idx_cap_local', 'idx_cap_emision'] as $i) {
        comprobar("indice $i", in_array($i, $k, true) ? 'si' : 'no', 'si');
    }
    $tr = array_column($db->query("SHOW TRIGGERS WHERE `Table` = 'bitacora'")->fetchAll(), 'Trigger');
    comprobar('bitacora protegida contra DELETE', in_array('bitacora_sin_delete', $tr, true) ? 'si' : 'no', 'si');
    comprobar('bitacora protegida contra UPDATE', in_array('bitacora_sin_update', $tr, true) ? 'si' : 'no', 'si');
    comprobar('migraciones anotadas',
              (int) $db->query('SELECT COUNT(*) FROM migraciones')->fetchColumn(), fn($n) => $n >= 9);
    echo "\nmigracion 010\n";
    comprobar('sesiones_log.evento admite SIN_SESION', $hay010 ? 'si' : 'no', 'si');
    // La 011 es la marca «otros trabajos» (decisión de Andrés del 2026-09-14).
    echo "\nmigracion 011\n";
    $cols011 = array_column($db->query("SHOW COLUMNS FROM casos_gestion LIKE 'otro_trabajo%'")->fetchAll(), 'Field');
    comprobar('casos_gestion lleva la marca de otros trabajos (4 columnas)', count($cols011), 4);
    // La 012 es la continuidad entre casos del mismo equipo (T2.25): el aviso
    // que SAP cerró a las 48 h y KFC volvió a abrir.
    echo "\nmigracion 012\n";
    $cols012 = array_column($db->query("SHOW COLUMNS FROM casos_gestion LIKE 'continua%'")->fetchAll(), 'Field');
    comprobar('casos_gestion lleva la continuidad (5 columnas)', count($cols012), 5);
    $k012 = array_unique(array_column($db->query('SHOW KEYS FROM casos_gestion')->fetchAll(), 'Key_name'));
    comprobar('indice idx_gestion_continua', in_array('idx_gestion_continua', $k012, true) ? 'si' : 'no', 'si');
    comprobar('permiso casos.continuidad repartido a los cuatro roles',
              (int) $db->query("SELECT COUNT(*) FROM rol_permisos WHERE permiso = 'casos.continuidad'")->fetchColumn(), 4);
    /* La prueba negativa que importa: aplicar la migración NO enlaza casos por
       su cuenta. Cada fila con `continua_de` la escribió una persona desde la
       ficha del caso, y esta cifra es la que lo demuestra al aplicarla. */
    echo '  (casos enlazados a un trabajo anterior: '
       . (int) $db->query('SELECT COUNT(*) FROM casos_gestion WHERE continua_de IS NOT NULL')->fetchColumn()
       . ")\n";
    // La semilla (009_semilla_diagnosticos.sql) va aparte: si no se aplicó, aquí se ve.
    comprobar('familias de equipo (semilla)',
              (int) $db->query('SELECT COUNT(*) FROM familias_equipo')->fetchColumn(), fn($n) => $n >= 20);
    comprobar('diagnosticos pre-redactados (semilla)',
              (int) $db->query('SELECT COUNT(*) FROM diagnosticos WHERE activo = 1')->fetchColumn(), fn($n) => $n >= 130);
    comprobar('repuestos frecuentes (semilla)',
              (int) $db->query('SELECT COUNT(*) FROM repuestos_frecuentes WHERE activo = 1')->fetchColumn(), fn($n) => $n >= 70);
    foreach (['ots.archivo', 'ots.compartir', 'repuestos.validar', 'repuestos.registrar_sap',
              'documentos.aprobar', 'cronograma.editar', 'casos.seguimiento'] as $p) {
        comprobar("permiso $p",
                  (int) $db->prepare('SELECT COUNT(*) FROM permisos WHERE codigo = ?')
                           ->execute([$p]) ? (int) $db->query("SELECT COUNT(*) FROM permisos WHERE codigo = " . $db->quote($p))->fetchColumn() : 0, 1);
    }
}

echo "\n" . ($ok ? 'TODO OK' : 'HAY FALLAS') . "\n";
exit($ok ? 0 : 1);
