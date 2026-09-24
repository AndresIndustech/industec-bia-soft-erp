<?php
declare(strict_types=1);

/**
 * preparar_prueba.php — Cuentas y datos de prueba para verificar el alcance por
 * rol (T2.12.4, T2.12.5, T2.12.6, T2.12.9 y T2.13.1) en el sitio de pruebas.
 *
 * Solo línea de órdenes, desde la carpeta ot/:  php ~/respaldos/preparar_prueba.php
 *
 * - Crea (o renueva) 5 cuentas: tec_prueba_uio_a, tec_prueba_uio_b, jefe_prueba_uio,
 *   jefe_prueba_cnlj y admin_prueba, activas y sin clave provisional. Las claves se
 *   generan al azar y van SOLO a ~/respaldos/claves_prueba.json (0600, fuera de la web).
 * - Le crea al técnico A dos avisos SINTÉTICOS CON LOCAL (T2.28.1): 99990021
 *   (G007EC, UIO) y 99990022 (G018EC, UIO), escritos en
 *   catalogos/casos_prueba.json — nunca tomados de casos_sap.json, que es lo que
 *   pidió KFC. `Casos::catalogo()` los fusiona SOLO para una cuenta de prueba o
 *   por CLI (nucleo/Casos.php, pruebaAplica()); la administradora, que también
 *   prueba el sitio con su cuenta real, no los ve en su buzón. Los dos quedan
 *   ASIGNADO al técnico A; 99990022 pasa además a ESPERA_REPUESTO con un
 *   pendiente abierto, A PROPÓSITO: así sigue «abierto» (Casos::ABIERTOS_TECNICO
 *   incluye ESPERA_REPUESTO) incluso después de que una batería lo concluya, y
 *   ninguna bateria deja a otra sin ningún caso con local (error nº 36). Cada uno
 *   lleva su equipo de prueba en `equipos_propuestos` (uuid 99990000-…-0021 y
 *   …-0022), con una denominación que la preselección de T2.26 encuentra por tipo.
 *
 *   HASTA el 2026-09-23 esto asignaba al técnico A dos casos REALES y abiertos
 *   de UIO —porque los sintéticos no tenían local y envio.php los rechazaba— y
 *   así quedaron secuestrados los avisos 10355931 y 10356012: un jefe de zona de
 *   verdad dejó de verlos en su buzón. No se repite: el arnés ya no toca ningún
 *   caso que no empiece por 9999 (T2.28.1).
 * - Le crea al técnico A otros dos avisos sintéticos que no están en NINGÚN
 *   catálogo, ni siquiera el de prueba (T2.13.2 y T2.13.3): 99990011 ASIGNADO y
 *   99990012 ATENDIDO sin orden de cierre. Estos siguen sin local a propósito:
 *   prueban el camino de un caso «sin dato en el catálogo».
 * - Crea en CNLJ un pendiente vencido y una novedad, ambos marcados PRUEBA, para
 *   intentar alcanzarlos desde UIO.
 * - Agrega los dos técnicos de prueba a catalogos/tecnicos.json (con copia previa),
 *   porque envio.php exige que quien firma esté en el padrón.
 *
 * Idempotente. Todo lo que cambia queda en ~/respaldos/prueba_deshacer.json y se
 * revierte con deshacer_prueba.php. No toca ningún caso real (I-2, T2.28.1).
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

// ------------------------- 2. dos avisos sintéticos CON LOCAL (T2.28.1), nunca un
// caso real (I-2). `Casos::catalogo()` los fusiona desde catalogos/casos_prueba.json
// solo para una cuenta de prueba o por CLI (nucleo/Casos.php, pruebaAplica()): la
// administradora que prueba el sitio con SU cuenta no los ve en su buzón. El
// `activo_fijo` lleva el mismo TIPO que el equipo de `equipos_propuestos` de abajo
// para que la preselección de T2.26 lo encuentre por tipo (app.js, normalizarTipo()).
$TIPO_PRUEBA = 'EQUIPO PRUEBA ARNES';
$SINT_LOCAL  = [
    '99990021' => ['local' => 'G007EC', 'uuid' => '99990000-0000-4000-8000-000000000021'],
    '99990022' => ['local' => 'G018EC', 'uuid' => '99990000-0000-4000-8000-000000000022'],
];
// El maestro de locales del sitio NO es una tabla SQL: vive en
// catalogos/locales.json (Catalogo::carpeta(), campo `codigo`), igual que lo
// lee el resto de la app (envio.php, catalogos.php). Una versión anterior de
// este script consultaba `SELECT ... FROM locales`, una tabla que nunca
// existió en esta base y que hacía abortar preparar_prueba.php entero.
$localesJson = json_decode((string) file_get_contents('catalogos/locales.json'), true);
$localesPorCodigo = [];
foreach (($localesJson['datos'] ?? $localesJson ?? []) as $l) { $localesPorCodigo[$l['codigo'] ?? ''] = $l; }

// array_map('strval', ...) porque PHP convierte a int cualquier clave de
// array que sea una cadena solo de dígitos ('99990021' -> 99990021): sin
// esto, $aviso llega como int al foreach y str_pad() revienta más abajo bajo
// strict_types (ya pasó una vez al escribir este script).
$elegidos    = array_map('strval', array_keys($SINT_LOCAL));
$casosPrueba = [];
foreach ($SINT_LOCAL as $aviso => $info) {
    $aviso = (string) $aviso;
    $localFila = $localesPorCodigo[$info['local']] ?? null;
    if ($localFila === null) {
        fwrite(STDERR, "El local de prueba {$info['local']} no está en catalogos/locales.json: revisa SINT_LOCAL.\n");
        exit(1);
    }
    $activoFijo = $TIPO_PRUEBA . '-MOD-SN' . $aviso;
    $casosPrueba[] = [
        'aviso'               => $aviso,
        'aviso_crudo'         => str_pad($aviso, 12, '0', STR_PAD_LEFT),
        'caso'                => 'Mant. Correctivo',
        'detalle'             => 'PRUEBA del arnés (T2.28.1): no es un aviso real',
        'prioridad'           => 'MEDIA',
        'fecha_creacion'      => date('Y-m-d'),
        'fecha_estimada'      => date('Y-m-d'),
        'activo_fijo'         => $activoFijo,
        'descripcion_trabajo' => 'PRUEBA automatizada (T2.28.1): no es una intervención real.',
        'local'               => $info['local'],
        'local_nombre'        => $localFila['nombre'],
        'cadena'              => $localFila['cadena'],
        'zona'                => 'UIO',
        'zona_por_buzon'      => 'UIO',
        'zona_por_local'      => 'UIO',
        'zona_discrepa'       => '',
        'estado_alerta'       => 'SIN_ALERTA',
        'alertas'             => [],
        'recibido'            => date('Y-m-d'),
    ];
    // El equipo de prueba, visible en el catálogo del local (Catalogo::
    // fusionarPropuestos()) para que app.js lo ofrezca y lo preseleccione.
    Db::ejecutar("INSERT INTO equipos_propuestos
                      (equipo_uuid, local_codigo, zona, tipo, marca, modelo, serie, activo_fijo, propuesto_por)
                  VALUES (?, ?, 'UIO', ?, 'MARCA DE PRUEBA', 'MOD', ?, ?,
                          (SELECT usuario_id FROM usuarios WHERE usuario = 'tec_prueba_uio_a'))
                  ON DUPLICATE KEY UPDATE estado = 'PROPUESTO', revisado_por = NULL, revisado_en = NULL,
                                          nota = NULL, local_codigo = VALUES(local_codigo)",
                 [$info['uuid'], $info['local'], $TIPO_PRUEBA, 'SN' . $aviso, $activoFijo]);
    // Siempre ASIGNADO al técnico A al preparar: es nuestro propio dato de
    // prueba, así que se reinicia en cada corrida (mismo criterio que los
    // sintéticos 99990011/12 de abajo), sin el candado "asignado_a IS NULL"
    // que sí hacía falta cuando esto tomaba casos reales de otra persona.
    Db::ejecutar('INSERT INTO casos_gestion (aviso, zona) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE zona = COALESCE(zona, VALUES(zona))', [$aviso, 'UIO']);
    Db::ejecutar("UPDATE casos_gestion SET asignado_a = ?, asignado_por = ?, asignado_en = NOW(),
                         tecnico_auto = 0, estado = 'ASIGNADO', ot_cierre = NULL, atendido_en = NULL
                   WHERE aviso = ?", [$tecA, $jefe, $aviso]);
}
file_put_contents('catalogos/casos_prueba.json.tmp',
    json_encode(['generado' => date('c'), 'fuente' => 'preparar_prueba.php (T2.28.1)', 'datos' => $casosPrueba],
                JSON_UNESCAPED_UNICODE));
rename('catalogos/casos_prueba.json.tmp', 'catalogos/casos_prueba.json');
$d['elegidos'] = $elegidos;

// ------ 2b. avisos sintéticos del técnico A, fuera del catálogo (T2.13.2 y T2.13.3)
// Los 9999xxxx no existen en SAP: prueban lo que no está en el catálogo sin tocar
// casos del cliente. Uno abierto (se ofrece en el formulario y se ve en la bandeja)
// y uno atendido sin orden de cierre (se ve en el historial, diciendo que no hay PDF).
$SINT = ['99990011' => 'ASIGNADO', '99990012' => 'ATENDIDO'];
foreach ($SINT as $a => $est) {
    Db::ejecutar("INSERT INTO casos_gestion (aviso, zona, estado, asignado_a, asignado_por, asignado_en, nota)
                  VALUES (?, 'UIO', ?, ?, ?, NOW(), 'PRUEBA: aviso sintético, no existe en SAP')
                  ON DUPLICATE KEY UPDATE estado = VALUES(estado), asignado_a = VALUES(asignado_a),
                                          asignado_por = VALUES(asignado_por), ot_cierre = NULL",
                 [(string) $a, $est, $tecA, $jefe]);
}
$d['sinteticos'] = array_map('strval', array_keys($SINT));

// ------------------- 3. pendiente de prueba del técnico A (el reloj corre: parado)
// Sobre $elegidos[1] (99990022), A PROPÓSITO y no sobre [0]: ESPERA_REPUESTO
// sigue contando como «abierto» (Casos::ABIERTOS_TECNICO), así que este aviso
// queda disponible con local para cualquier batería aunque otra ya lo haya usado
// para concluir una orden -- es lo que evita que una deje a otra sin ningún caso
// con local para trabajar (error nº 36). $elegidos[0] (99990021) queda limpio,
// para la batería que necesita emitir de verdad y cerrarlo (verificar_emision.py).
if (empty($d['pendientes']['uio'])) {
    Db::ejecutar("INSERT INTO pendientes (aviso, zona, activo_fijo, equipo_desc, deshabilitado, diagnostico,
                                          abierto_por, abierto_en)
                  VALUES (?, 'UIO', 'PRUEBA-UIO', 'Equipo de prueba', 1,
                          'PRUEBA de alcance (T2.12.6): no es un equipo real', ?, NOW() - INTERVAL 2 HOUR)
                  ON DUPLICATE KEY UPDATE diagnostico = VALUES(diagnostico)", [$elegidos[1], $tecA]);
    $d['pendientes']['uio'] = (int) Db::uno("SELECT pendiente_id FROM pendientes
                                              WHERE aviso = ? AND activo_fijo = 'PRUEBA-UIO'", [$elegidos[1]])['pendiente_id'];
    Db::ejecutar("UPDATE casos_gestion SET estado = 'ESPERA_REPUESTO' WHERE aviso = ?", [$elegidos[1]]);
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

// ---------------------------------------------------------- 4b. equipo propuesto
// Un equipo «nuevo / no está en la lista» registrado por el técnico A (D8,
// T2.14.8), para probar equipos.php. El uuid empieza por 99990000- como los
// avisos sintéticos: deshacer_prueba.php lo borra por ese prefijo. Idempotente:
// si ya existe vuelve a PROPUESTO para que la verificación lo decida de nuevo.
Db::ejecutar("INSERT INTO equipos_propuestos
                  (equipo_uuid, local_codigo, zona, tipo, marca, modelo, serie, propuesto_por)
              VALUES ('99990000-0000-4000-8000-0000000000e1', 'G007EC', 'UIO',
                      'PRUEBA: equipo de prueba (no es real)', 'MARCA DE PRUEBA', 'MODELO-1', 'SN-PRUEBA',
                      (SELECT usuario_id FROM usuarios WHERE usuario = 'tec_prueba_uio_a'))
              ON DUPLICATE KEY UPDATE estado = 'PROPUESTO', revisado_por = NULL, revisado_en = NULL, nota = NULL");

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
              json_encode(['cuentas' => $ids, 'casos' => $d['elegidos'], 'sinteticos' => $d['sinteticos'],
                           'pendientes' => $d['pendientes'],
                           'novedades' => $d['novedades']], JSON_UNESCAPED_UNICODE)]);

echo "cuentas: ", json_encode($ids), "\n";
echo "casos del técnico A: ", implode(', ', $d['elegidos']), " · sintéticos: ", implode(', ', $d['sinteticos']), "\n";
echo "pendientes de prueba: ", json_encode($d['pendientes']), " · novedades: ", json_encode($d['novedades']), "\n";
echo "claves en $R/claves_prueba.json (0600) · deshacer: $DES\n";
