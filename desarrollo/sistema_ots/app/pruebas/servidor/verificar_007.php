<?php
declare(strict_types=1);

/**
 * verificar_007.php — Los dos bloques de verificación del pie de la 007, con su
 * salida literal y un PASA/FALLA contra lo esperado (T2.12.1 y T2.12.2).
 *
 * Solo línea de órdenes. Se corre desde la carpeta ot/ del sitio:
 *     php ~/respaldos/verificar_007.php
 *
 * Lee todo. Las pruebas de inserción doble y la del trigger van DENTRO de una
 * transacción que termina en ROLLBACK: no dejan filas (sí avanzan los
 * AUTO_INCREMENT, que es inocuo).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require getcwd() . '/nucleo/Db.php';

$fallas = 0;
function chequeo(string $que, bool $ok, string $obtenido): void
{
    global $fallas;
    if (!$ok) { $fallas++; }
    printf("  %-60s %-30s %s\n", $que, substr($obtenido, 0, 30), $ok ? 'PASA' : 'FALLA');
}
function crear(string $tabla): string
{
    $f = Db::uno("SHOW CREATE TABLE `$tabla`");
    return (string) ($f['Create Table'] ?? '');
}
function porRol(string $sql): array
{
    $out = [];
    foreach (Db::todos($sql) as $r) { $out[$r['rol']] = (int) $r['n']; }
    return $out;
}
function igual(array $obtenido, array $esperado): bool
{
    ksort($obtenido); ksort($esperado);
    return $obtenido === $esperado;
}
function texto(array $a): string
{
    return implode(' · ', array_map(fn($k, $v) => "$k $v", array_keys($a), $a));
}

$pdo = Db::conn();
$uid = (int) (Db::uno('SELECT MIN(usuario_id) u FROM usuarios WHERE activo = 1')['u'] ?? 0);
if ($uid === 0) { fwrite(STDERR, "No hay usuarios activos para las pruebas.\n"); exit(1); }

// ===========================================================================
echo "== VERIFICACION de las novedades ==\n\n";
$nov = crear('novedades');
echo $nov, "\n\n";
chequeo('UNIQUE KEY uq_novedad (novedad_uuid)', str_contains($nov, 'UNIQUE KEY `uq_novedad` (`novedad_uuid`)'), 'SHOW CREATE TABLE');

$uuid = '11111111-1111-1111-1111-111111111111';
$pdo->beginTransaction();
try {
    for ($i = 0; $i < 2; $i++) {
        Db::ejecutar("INSERT INTO novedades (novedad_uuid, descripcion, reportada_por) VALUES (?, 'prueba', ?)
                      ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)", [$uuid, $uid]);
    }
    $n = (int) Db::uno('SELECT COUNT(*) c FROM novedades WHERE novedad_uuid = ?', [$uuid])['c'];
} finally {
    $pdo->rollBack();
}
chequeo('el mismo envío dos veces no duplica', $n === 1, "COUNT(*) = $n");
$q = (int) Db::uno('SELECT COUNT(*) c FROM novedades WHERE novedad_uuid = ?', [$uuid])['c'];
chequeo('la prueba no dejó filas (ROLLBACK)', $q === 0, "quedan $q");

echo "\n  tipo / responsable_prop / n / con_aviso:\n";
foreach (Db::todos('SELECT tipo, responsable_prop, COUNT(*) n, SUM(aviso_sap IS NOT NULL) con_aviso
                      FROM novedades GROUP BY tipo, responsable_prop ORDER BY n DESC') as $r) {
    echo '    ', implode(' | ', $r), "\n";
}
echo "    (fin; vacío es lo esperado recién aplicada)\n";
$nr = porRol("SELECT rol, COUNT(*) n FROM rol_permisos WHERE permiso LIKE 'novedades%' GROUP BY rol");
chequeo('permisos de novedades por rol (3·3·3·2)',
        igual($nr, ['SUPERADMIN' => 3, 'ADMIN' => 3, 'JEFE_ZONA' => 3, 'TECNICO' => 2]), texto($nr));

// ===========================================================================
echo "\n== VERIFICACION despues de aplicar (1-8) ==\n\n";

// 1. La clave de negocio desde el CREATE TABLE (I-9)
$pen = crear('pendientes');
echo $pen, "\n\n";
chequeo('1. pendientes: UNIQUE KEY uq_pendiente (aviso, activo_fijo)',
        str_contains($pen, 'UNIQUE KEY `uq_pendiente` (`aviso`,`activo_fijo`)'), 'SHOW CREATE TABLE');
chequeo('   pendientes: columna plazo_desde', str_contains($pen, '`plazo_desde` datetime'), 'SHOW CREATE TABLE');
$cap = crear('ot_capturadas');
echo "\n", $cap, "\n\n";
chequeo('1. ot_capturadas: UNIQUE KEY uq_captura_envio (envio_uuid)',
        str_contains($cap, 'UNIQUE KEY `uq_captura_envio` (`envio_uuid`)'), 'SHOW CREATE TABLE');
$notas = crear('pendiente_notas');
chequeo('   pendiente_notas.tipo incluye DIAGNOSTICO', str_contains($notas, "'DIAGNOSTICO'"), 'SHOW CREATE TABLE');

// 2. El estado nuevo, al final del ENUM
$col = Db::uno("SHOW COLUMNS FROM casos_gestion LIKE 'estado'");
echo "  casos_gestion.estado: ", $col['Type'] ?? '(sin columna)', "\n";
chequeo("2. ENUM termina en ,'CERRADO_SIN_ATENCION','ESPERA_REPUESTO')",
        str_ends_with((string) ($col['Type'] ?? ''), ",'CERRADO_SIN_ATENCION','ESPERA_REPUESTO')"), 'SHOW COLUMNS');

// 3. Idempotencia del pendiente, y el trigger de insistencias
$pdo->beginTransaction();
try {
    for ($i = 0; $i < 2; $i++) {
        Db::ejecutar("INSERT INTO pendientes (aviso, diagnostico, abierto_por) VALUES ('99999999', 'prueba', ?)
                      ON DUPLICATE KEY UPDATE diagnostico = VALUES(diagnostico)", [$uid]);
    }
    $n3 = (int) Db::uno("SELECT COUNT(*) c FROM pendientes WHERE aviso = '99999999'")['c'];
    $pid = (int) Db::uno("SELECT pendiente_id FROM pendientes WHERE aviso = '99999999'")['pendiente_id'];
    Db::ejecutar("INSERT INTO pendiente_notas (pendiente_id, usuario_id, tipo, texto) VALUES (?, ?, 'RECORDATORIO', 'prueba')", [$pid, $uid]);
    Db::ejecutar("INSERT INTO pendiente_notas (pendiente_id, usuario_id, tipo, texto) VALUES (?, ?, 'RESPUESTA', 'prueba')", [$pid, $uid]);
    $ins = Db::uno('SELECT insistencias, ultima_nota_en FROM pendientes WHERE pendiente_id = ?', [$pid]);
} finally {
    $pdo->rollBack();
}
chequeo('3. abrir dos veces el mismo pendiente no duplica', $n3 === 1, "COUNT(*) = $n3");
chequeo('   trigger: 1 recordatorio + 1 respuesta = 1 insistencia',
        (int) ($ins['insistencias'] ?? -1) === 1 && !empty($ins['ultima_nota_en']),
        'insistencias = ' . ($ins['insistencias'] ?? '?'));
$q3 = (int) Db::uno("SELECT COUNT(*) c FROM pendientes WHERE aviso = '99999999'")['c'];
chequeo('   la prueba no dejó filas (ROLLBACK)', $q3 === 0, "quedan $q3");
$tr = Db::todos("SHOW TRIGGERS LIKE 'pendiente_notas'");
chequeo('   trigger tr_pnota_insiste existe', in_array('tr_pnota_insiste', array_column($tr, 'Trigger'), true), (string) count($tr) . ' trigger(s)');

// 4. El contador lo mantiene el trigger
$f4 = Db::todos("SELECT p.pendiente_id, p.insistencias,
                        (SELECT COUNT(*) FROM pendiente_notas n
                          WHERE n.pendiente_id = p.pendiente_id AND n.tipo = 'RECORDATORIO') AS real_
                   FROM pendientes p HAVING insistencias <> real_");
chequeo('4. insistencias = recordatorios reales', count($f4) === 0, count($f4) . ' filas');

// 5. La reconciliación no pisa lo que espera
$f5 = (int) Db::uno("SELECT COUNT(*) c FROM casos_gestion
                      WHERE estado IN ('CERRADO_SIN_ATENCION','ATENDIDO')
                        AND aviso IN (SELECT aviso FROM pendientes WHERE estado NOT IN ('RESUELTO','CANCELADO'))")['c'];
chequeo('5. ningún caso cerrado/atendido con pendiente vivo', $f5 === 0, "$f5 filas");

// 6. El indicador del negocio (literal; vacío recién aplicada)
$f6 = Db::uno("SELECT
                 SUM(veredicto_en IS NOT NULL AND TIMESTAMPDIFF(HOUR, abierto_en, veredicto_en) <= 48) AS a_tiempo,
                 SUM(veredicto_en IS NOT NULL AND TIMESTAMPDIFF(HOUR, abierto_en, veredicto_en) >  48) AS tarde,
                 SUM(via = 'SIN_VEREDICTO' AND deshabilitado = 1
                     AND abierto_en < DATE_SUB(NOW(), INTERVAL 48 HOUR)) AS vencidos_ahora
               FROM pendientes");
echo '  6. a_tiempo=', $f6['a_tiempo'] ?? 'NULL', ' tarde=', $f6['tarde'] ?? 'NULL',
     ' vencidos_ahora=', $f6['vencidos_ahora'] ?? 'NULL', "\n";

// 7. Los permisos, como dice el diseño
$rr = porRol("SELECT rol, COUNT(*) n FROM rol_permisos WHERE permiso LIKE 'repuestos%' GROUP BY rol");
chequeo('7. permisos de repuestos por rol (4·4·3·2)',
        igual($rr, ['SUPERADMIN' => 4, 'ADMIN' => 4, 'JEFE_ZONA' => 3, 'TECNICO' => 2]), texto($rr));
$tot = porRol('SELECT rol, COUNT(*) n FROM rol_permisos GROUP BY rol');
chequeo('   total por rol 26/25/17/9',
        igual($tot, ['SUPERADMIN' => 26, 'ADMIN' => 25, 'JEFE_ZONA' => 17, 'TECNICO' => 9]), texto($tot));
$p7 = (int) Db::uno("SELECT COUNT(*) c FROM permisos WHERE codigo LIKE 'repuestos%' OR codigo LIKE 'novedades%'")['c'];
chequeo("   permisos repuestos%/novedades% = 7 (T2.12.2)", $p7 === 7, "COUNT(*) = $p7");

// Claves foráneas que la 007 declara
$fk = [];
foreach (Db::todos("SELECT TABLE_NAME t, COUNT(*) n FROM information_schema.REFERENTIAL_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE()
                       AND TABLE_NAME IN ('pendientes','pendiente_notas','ot_capturadas','novedades')
                     GROUP BY TABLE_NAME") as $r) { $fk[$r['t']] = (int) $r['n']; }
chequeo('   claves foráneas 3·2·1·2',
        igual($fk, ['pendientes' => 3, 'pendiente_notas' => 2, 'ot_capturadas' => 1, 'novedades' => 2]),
        implode(' ', array_map(fn($k, $v) => substr($k, 0, 6) . "=$v", array_keys($fk), $fk)));

echo "\n", $fallas === 0 ? 'TODO PASA' : "$fallas FALLA(S)", "\n";
exit($fallas === 0 ? 0 : 1);
