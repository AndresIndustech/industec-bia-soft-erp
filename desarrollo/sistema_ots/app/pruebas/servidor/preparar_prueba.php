<?php
declare(strict_types=1);

/**
 * preparar_prueba.php — Cuentas y datos de prueba para verificar el alcance por
 * rol (T2.12.4, T2.12.5, T2.12.6, T2.12.9 y T2.13.1) en el sitio de pruebas.
 *
 * Solo línea de órdenes, desde la carpeta ot/:  php ~/respaldos/preparar_prueba.php
 *
 * - Crea (o renueva) 4 cuentas: tec_prueba_uio_a, tec_prueba_uio_b, jefe_prueba_uio
 *   y jefe_prueba_cnlj, activas y sin clave provisional. Las claves se generan al
 *   azar y van SOLO a ~/respaldos/claves_prueba.json (0600, fuera de la web).
 * - Asigna al técnico A dos casos abiertos reales de UIO que estén en el catálogo y
 *   sin técnico, y le abre un pendiente de prueba en el primero.
 * - Crea en CNLJ un pendiente vencido y una novedad, ambos marcados PRUEBA, para
 *   intentar alcanzarlos desde UIO.
 * - Agrega los dos técnicos de prueba a catalogos/tecnicos.json (con copia previa),
 *   porque envio.php exige que quien firma esté en el padrón.
 *
 * Idempotente. Todo lo que cambia queda en ~/respaldos/prueba_deshacer.json y se
 * revierte con deshacer_prueba.php. No toca datos del cliente salvo la asignación
 * de esos dos casos, que se devuelve a como estaba.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require getcwd() . '/nucleo/Db.php';

$R   = rtrim((string) getenv('HOME'), '/') . '/respaldos';
$DES = "$R/prueba_deshacer.json";
$d   = is_file($DES) ? (json_decode((string) file_get_contents($DES), true) ?: []) : [];
$d  += ['cuentas' => [], 'casos' => [], 'pendientes' => [], 'novedades' => [], 'tecnicos_json' => null];
umask(077);

// ---------------------------------------------------------------- 1. cuentas
$CUENTAS = [
    'tec_prueba_uio_a' => ['TECNICO',   'UIO',  'Prueba Tecnico Uio A'],
    'tec_prueba_uio_b' => ['TECNICO',   'UIO',  'Prueba Tecnico Uio B'],
    'jefe_prueba_uio'  => ['JEFE_ZONA', 'UIO',  'Prueba Jefe Uio'],
    'jefe_prueba_cnlj' => ['JEFE_ZONA', 'CNLJ', 'Prueba Jefe Cnlj'],
    // La administración, para abrir las 13 pantallas con los tres roles (T2.12.3)
    // sin usar la clave de ninguna persona.
    'admin_prueba'     => ['ADMIN',     null,   'Prueba Administracion'],
];
$claves = [];
$ids    = [];
foreach ($CUENTAS as $usr => [$rol, $zona, $nombre]) {
    $clave = bin2hex(random_bytes(12));
    $hash  = password_hash($clave, PASSWORD_DEFAULT);
    $f = Db::uno('SELECT usuario_id FROM usuarios WHERE usuario = ?', [$usr]);
    if ($f) {
        Db::ejecutar('UPDATE usuarios SET nombre = ?, rol = ?, zona = ?, clave_hash = ?, activo = 1,
                             debe_cambiar_clave = 0, intentos_fallidos = 0, bloqueado_hasta = NULL,
                             sesion_token = NULL, fecha_baja = NULL
                       WHERE usuario_id = ?', [$nombre, $rol, $zona, $hash, (int) $f['usuario_id']]);
        $ids[$usr] = (int) $f['usuario_id'];
    } else {
        Db::ejecutar('INSERT INTO usuarios (usuario, nombre, clave_hash, rol, zona, activo, debe_cambiar_clave)
                      VALUES (?, ?, ?, ?, ?, 1, 0)', [$usr, $nombre, $hash, $rol, $zona]);
        $ids[$usr] = (int) Db::conn()->lastInsertId();
    }
    $claves[$usr] = $clave;
}
$d['cuentas'] = $ids;
file_put_contents("$R/claves_prueba.json", json_encode(['claves' => $claves, 'ids' => $ids], JSON_PRETTY_PRINT));
chmod("$R/claves_prueba.json", 0600);

$tecA = $ids['tec_prueba_uio_a'];
$jefe = $ids['jefe_prueba_uio'];
$jcnl = $ids['jefe_prueba_cnlj'];

// ------------------------------------------- 2. dos casos de UIO para el técnico A
$cat   = json_decode((string) file_get_contents('catalogos/casos_sap.json'), true) ?: [];
$casos = $cat['datos'] ?? [];
$elegidos = array_column(Db::todos('SELECT aviso FROM casos_gestion WHERE asignado_a = ?', [$tecA]), 'aviso');
foreach ($casos as $c) {
    if (count($elegidos) >= 2) { break; }
    $a = (string) ($c['aviso'] ?? '');
    if ($a === '' || ($c['zona'] ?? '') !== 'UIO' || in_array($a, $elegidos, true)) { continue; }
    $g = Db::uno('SELECT * FROM casos_gestion WHERE aviso = ?', [$a]);
    if ($g !== null && !($g['estado'] === 'NUEVO' && $g['asignado_a'] === null
                         && in_array($g['zona'], [null, 'UIO'], true))) { continue; }
    $d['casos'][$a] = ['existia' => $g !== null, 'antes' => $g];
    Db::ejecutar('INSERT INTO casos_gestion (aviso, zona) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE zona = COALESCE(zona, VALUES(zona))', [$a, 'UIO']);
    Db::ejecutar("UPDATE casos_gestion SET asignado_a = ?, asignado_por = ?, asignado_en = NOW(),
                         tecnico_auto = 0, estado = 'ASIGNADO'
                   WHERE aviso = ? AND asignado_a IS NULL", [$tecA, $jefe, $a]);
    $elegidos[] = $a;
}
if (count($elegidos) < 2) { fwrite(STDERR, "No hay dos casos de UIO libres en el catálogo.\n"); exit(1); }
$d['elegidos'] = array_values($elegidos);

// ------------------- 3. pendiente de prueba del técnico A (el reloj corre: parado)
if (empty($d['pendientes']['uio'])) {
    Db::ejecutar("INSERT INTO pendientes (aviso, zona, activo_fijo, equipo_desc, deshabilitado, diagnostico,
                                          abierto_por, abierto_en)
                  VALUES (?, 'UIO', 'PRUEBA-UIO', 'Equipo de prueba', 1,
                          'PRUEBA de alcance (T2.12.6): no es un equipo real', ?, NOW() - INTERVAL 2 HOUR)
                  ON DUPLICATE KEY UPDATE diagnostico = VALUES(diagnostico)", [$elegidos[0], $tecA]);
    $d['pendientes']['uio'] = (int) Db::uno("SELECT pendiente_id FROM pendientes
                                              WHERE aviso = ? AND activo_fijo = 'PRUEBA-UIO'", [$elegidos[0]])['pendiente_id'];
    Db::ejecutar("UPDATE casos_gestion SET estado = 'ESPERA_REPUESTO' WHERE aviso = ?", [$elegidos[0]]);
}

// --------- 4. en CNLJ: un pendiente vencido (50 h, parado) y una novedad, de prueba
if (empty($d['pendientes']['cnlj'])) {
    Db::ejecutar("INSERT INTO pendientes (aviso, zona, activo_fijo, equipo_desc, deshabilitado, diagnostico,
                                          abierto_por, abierto_en)
                  VALUES ('99990001', 'CNLJ', 'PRUEBA-CNLJ', 'Equipo de prueba', 1,
                          'PRUEBA de alcance (T2.12.5): no es un equipo real', ?, NOW() - INTERVAL 50 HOUR)
                  ON DUPLICATE KEY UPDATE diagnostico = VALUES(diagnostico)", [$jcnl]);
    $d['pendientes']['cnlj'] = (int) Db::uno("SELECT pendiente_id FROM pendientes
                                               WHERE aviso = '99990001' AND activo_fijo = 'PRUEBA-CNLJ'")['pendiente_id'];
}
if (empty($d['novedades']['cnlj'])) {
    $uuid = '99990000-0000-4000-8000-000000000001';
    Db::ejecutar("INSERT INTO novedades (novedad_uuid, zona, tipo, descripcion, riesgo, responsable_prop, reportada_por)
                  VALUES (?, 'CNLJ', 'OTRO', 'PRUEBA de alcance (T2.12.5): no es una novedad real', 'BAJO', 'INDUSTEC', ?)
                  ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)", [$uuid, $jcnl]);
    $d['novedades']['cnlj'] = (int) Db::uno('SELECT novedad_id FROM novedades WHERE novedad_uuid = ?', [$uuid])['novedad_id'];
}

// ------------------ 5. los técnicos de prueba en el padrón (con copia de antes)
$tj = 'catalogos/tecnicos.json';
if ($d['tecnicos_json'] === null) {
    copy($tj, "$R/tecnicos.json.antes_prueba");
    $d['tecnicos_json'] = "$R/tecnicos.json.antes_prueba";
}
$j = json_decode((string) file_get_contents($tj), true);
$tipo = 'TECNICO';
foreach ($j['datos'] as $t) { if (($t['zona'] ?? '') === 'UIO') { $tipo = $t['tipo'] ?? $tipo; break; } }
$nombres = array_column($j['datos'], 'nombre');
foreach (['Prueba Tecnico Uio A' => 90001, 'Prueba Tecnico Uio B' => 90002] as $n => $id) {
    if (!in_array($n, $nombres, true)) { $j['datos'][] = ['id' => $id, 'nombre' => $n, 'tipo' => $tipo, 'zona' => 'UIO']; }
}
file_put_contents("$tj.tmp", json_encode($j, JSON_UNESCAPED_UNICODE));
rename("$tj.tmp", $tj);

// ------------------------------------------------------------ 6. registro
file_put_contents($DES, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
chmod($DES, 0600);
Db::ejecutar("INSERT INTO bitacora (accion, entidad, referencia, estado_despues, exito, detalle, datos, ip, equipo)
              VALUES ('PRUEBA_PREPARAR', 'prueba', 'T2.12.4-6', 'PREPARADA', 1, ?, ?, '', 'CLI por SSH (PC de Andrés)')",
             ['Cuentas y datos de prueba del alcance por rol; se revierten con deshacer_prueba.php',
              json_encode(['cuentas' => $ids, 'casos' => $d['elegidos'], 'pendientes' => $d['pendientes'],
                           'novedades' => $d['novedades']], JSON_UNESCAPED_UNICODE)]);

echo "cuentas: ", json_encode($ids), "\n";
echo "casos del técnico A: ", implode(', ', $d['elegidos']), "\n";
echo "pendientes de prueba: ", json_encode($d['pendientes']), " · novedades: ", json_encode($d['novedades']), "\n";
echo "claves en $R/claves_prueba.json (0600) · deshacer: $DES\n";
