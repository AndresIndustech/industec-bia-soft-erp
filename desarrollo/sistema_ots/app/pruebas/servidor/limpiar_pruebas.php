<?php
declare(strict_types=1);

/**
 * limpiar_pruebas.php — Retira del sitio TODO lo que dejó el arnés de pruebas y
 * sus cuentas, incluidas las cuentas. Solo línea de órdenes, desde ot/:
 *
 *     php ~/respaldos/limpiar_pruebas.php                      # simulacro: solo cuenta
 *     php ~/respaldos/limpiar_pruebas.php --ejecutar='<json>'  # borra, si las cifras cuadran
 *
 * Por qué existe, si ya está deshacer_prueba.php: ese script solo DESACTIVA las
 * cuentas y solo conoce lo que preparar_prueba.php registró. El 2026-09-22 Andrés
 * pidió dejar solo la información real, cuentas incluidas, y el inventario
 * encontró lo que deshacer no cubre: seguimientos pedidos por admin_prueba,
 * administradores de local «de Prueba» aprendidos de las órdenes y 347 filas de
 * sesiones de las cuentas de prueba. Y, lo grave, dos avisos
 * REALES y abiertos en SAP (10355931, 10356012) asignados al técnico de prueba y
 * cerrados con órdenes de prueba: nadie de verdad los veía en su buzón.
 *
 * El alcance se fija por NOMBRE de cuenta, no por id: los ids cambian si alguien
 * vuelve a correr preparar_prueba.php. `--ejecutar` recibe el JSON de cifras que
 * imprimió el simulacro y aborta sin tocar nada si una sola no coincide (I-10):
 * entre el simulacro y la ejecución pudo entrar una orden real.
 *
 * DESDE EL 2026-09-23 (T2.28.1) preparar_prueba.php ya no toma casos reales: sus
 * dos avisos con local (99990021, 99990022) son sintéticos, escritos en
 * catalogos/casos_prueba.json, y por eso este script también borra ese archivo.
 * Las filas de casos_gestion y equipos_propuestos de esos dos avisos ya las
 * cubrían los patrones genéricos de abajo (`aviso LIKE '9999%'` y `equipo_uuid
 * LIKE '99990000-%'`): no hizo falta agregar un paso nuevo para ellas.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require getcwd() . '/nucleo/Db.php';

const CUENTAS = ['tec_prueba_uio_a', 'tec_prueba_uio_b', 'jefe_prueba_uio', 'jefe_prueba_cnlj', 'admin_prueba'];
// Los dos avisos reales que preparar_prueba.php tomó el 2026-09-21. Su registro
// dice `existia: false`: antes del arnés no tenían fila en casos_gestion, así que
// «devolverlos» es borrar la fila y dejarlos NUEVOS para que el jefe los asigne.
const AVISOS_REALES_TOMADOS = ['10355931', '10356012'];

$ejecutar = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--ejecutar=')) { $ejecutar = json_decode(substr($a, 11), true); }
}

$en  = implode(',', array_fill(0, count(CUENTAS), '?'));
$ids = array_map('intval', array_column(Db::todos("SELECT usuario_id FROM usuarios WHERE usuario IN ($en)", CUENTAS), 'usuario_id'));
if (!$ids) { echo "No hay cuentas de prueba: nada que limpiar.\n"; exit(0); }
$ei = implode(',', $ids);   // enteros salidos de la base: seguros para interpolar

$ots = array_column(Db::todos("SELECT id_industec FROM ot_capturadas WHERE usuario_id IN ($ei) AND id_industec IS NOT NULL"), 'id_industec');
$eo  = $ots ? implode(',', array_map(fn($o) => Db::conn()->quote($o), $ots)) : "''";
$ea  = implode(',', array_map(fn($a) => "'$a'", AVISOS_REALES_TOMADOS));

// Un caso real solo se devuelve si lo único que tiene es obra de las cuentas de
// prueba: asignado a una de ellas y sin orden de cierre real.
$ajenos = Db::todos("SELECT aviso FROM casos_gestion WHERE aviso IN ($ea)
                       AND NOT (asignado_a IN ($ei) AND (ot_cierre IS NULL OR ot_cierre IN ($eo)))");
if ($ajenos) {
    fwrite(STDERR, 'ABORTA: un aviso real tiene actividad que no es de prueba: ' . json_encode($ajenos) . "\n");
    exit(1);
}

// Cada paso: [tabla, WHERE]. El orden respeta las llaves foráneas (RESTRICT hacia usuarios).
$pasos = [
    'ot_fotos'           => "usuario_id IN ($ei)",
    'ot_archivo'         => "origen = 'APP' AND id_industec IN ($eo)",
    'email_queue'        => "captura_id IN (SELECT captura_id FROM ot_capturadas WHERE usuario_id IN ($ei))",
    'ot_capturadas'      => "usuario_id IN ($ei)",
    'pendiente_notas'    => "pendiente_id IN (SELECT pendiente_id FROM pendientes WHERE abierto_por IN ($ei) OR activo_fijo LIKE 'PRUEBA-%') OR usuario_id IN ($ei)",
    'pendientes'         => "abierto_por IN ($ei) OR activo_fijo LIKE 'PRUEBA-%'",
    'novedades'          => "reportada_por IN ($ei) OR novedad_uuid LIKE '99990000-%'",
    'equipos_propuestos' => "propuesto_por IN ($ei) OR equipo_uuid LIKE '99990000-%'",
    'casos_seguimientos' => "tecnico_id IN ($ei) OR pedido_por IN ($ei)",
    'casos_gestion'      => "aviso LIKE '9999%' OR aviso IN ($ea)",
    // Aprendidos del campo «administrador» de las órdenes de prueba.
    'locales_admin'      => "nombre = 'Administrador de Prueba'",
    'ot_archivo_solicitudes' => "usuario_id IN ($ei)",
    'usuario_permisos'   => "usuario_id IN ($ei)",
    'sesiones_log'       => "usuario_id IN ($ei) OR usuario IN ($en)",
    // La bitácora NO está: la 009 la hizo inalterable con un disparador (la
    // primera corrida, el 2026-09-22, abortó ahí con «La bitacora no se borra»).
    // Sus filas de prueba quedan como historial; usuario_id no es llave foránea,
    // así que la cuenta se puede borrar igual y la fila conserva el nombre.
    'usuarios'           => "usuario_id IN ($ei)",
];
$params = ['sesiones_log' => CUENTAS];

$cifras = [];
foreach ($pasos as $t => $w) {
    $cifras[$t] = (int) Db::uno("SELECT COUNT(*) n FROM `$t` WHERE $w", $params[$t] ?? [])['n'];
}
$archivos = array_map(fn($o) => "ordenes_pdf/$o.pdf", $ots);
foreach (Db::todos("SELECT ruta FROM ot_fotos WHERE usuario_id IN ($ei)") as $r) { $archivos[] = 'ordenes_fotos/' . $r['ruta']; }
$cifras['archivos_en_disco'] = count(array_filter($archivos, 'is_file'));

// El catálogo de prueba del arnés (T2.28.1): 0 o 1, nunca más de un archivo.
$casosPruebaJson = 'catalogos/casos_prueba.json';
$cifras['casos_prueba_json'] = is_file($casosPruebaJson) ? 1 : 0;

if ($ejecutar === null) {
    echo "SIMULACRO — no se tocó nada. Cuentas: ", implode(', ', CUENTAS), " (ids $ei)\n";
    foreach ($cifras as $t => $n) { printf("  %-24s %6d\n", $t, $n); }
    echo "\nPara ejecutar, pasa estas cifras tal cual:\n--ejecutar='", json_encode($cifras), "'\n";
    exit(0);
}
if ($ejecutar !== $cifras) {
    fwrite(STDERR, "ABORTA sin tocar nada: las cifras cambiaron desde el simulacro.\n  esperadas: "
        . json_encode($ejecutar) . "\n  ahora:     " . json_encode($cifras) . "\n");
    exit(1);
}

$pdo = Db::conn();
$pdo->beginTransaction();
try {
    $hecho = [];
    foreach ($pasos as $t => $w) {
        $hecho[$t] = Db::ejecutar("DELETE FROM `$t` WHERE $w", $params[$t] ?? []);
        // email_queue y pendiente_notas también caen en cascada; el resto debe cuadrar exacto.
        if ($hecho[$t] !== $cifras[$t]) {
            throw new RuntimeException("$t: se borraron {$hecho[$t]}, se esperaban {$cifras[$t]}");
        }
    }
    Db::ejecutar("INSERT INTO bitacora (accion, entidad, referencia, estado_despues, exito, detalle, datos, ip, equipo)
                  VALUES ('LIMPIEZA_PRUEBAS', 'sistema', 'datos de prueba', 'RETIRADOS', 1, ?, ?, '', 'CLI por SSH (estación)')",
                 ['Se retiraron las cuentas de prueba y todo lo que generaron, a pedido de Andrés Basantes; '
                  . 'los avisos 10355931 y 10356012 vuelven a NUEVO', json_encode($hecho)]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, 'SIN CAMBIOS en la base: ' . $e->getMessage() . "\n");
    exit(1);
}

$borrados = 0;
foreach ($archivos as $a) {
    if (is_file($a) && unlink($a)) { $borrados++; }
    $dir = dirname($a);
    if (str_starts_with($dir, 'ordenes_fotos/') && is_dir($dir) && count(scandir($dir)) === 2) { rmdir($dir); }
}

// El padrón de firmantes: fuera los dos técnicos de prueba (ids 90001/90002).
$tj = 'catalogos/tecnicos.json';
$j  = json_decode((string) file_get_contents($tj), true);
$antes = count($j['datos']);
$j['datos'] = array_values(array_filter($j['datos'], fn($t) => !str_starts_with((string) ($t['nombre'] ?? ''), 'Prueba Tecnico')));
file_put_contents("$tj.tmp", json_encode($j, JSON_UNESCAPED_UNICODE));
rename("$tj.tmp", $tj);

$R = rtrim((string) getenv('HOME'), '/') . '/respaldos';
foreach (['claves_prueba.json', 'prueba_deshacer.json', 'tecnicos.json.antes_prueba'] as $f) { @unlink("$R/$f"); }

// El catálogo de prueba (T2.28.1): sin él, Casos::catalogo() no tiene qué
// fusionar en la próxima preparar_prueba.php, así que no deja basura si esta
// corrida es la última del ciclo.
$casosPruebaBorrado = $cifras['casos_prueba_json'] === 1 && @unlink($casosPruebaJson);

echo "HECHO: ", json_encode($hecho), "\n";
echo "archivos borrados del disco: $borrados de {$cifras['archivos_en_disco']} · padrón de técnicos: $antes → ", count($j['datos']),
     " · casos_prueba.json borrado: ", $casosPruebaBorrado ? 'sí' : ($cifras['casos_prueba_json'] === 0 ? 'no existía' : 'NO'), "\n";
