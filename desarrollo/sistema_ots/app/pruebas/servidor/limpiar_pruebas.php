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
 *
 * DESDE EL 2026-09-28: LAS FILAS HUÉRFANAS DEL ARCHIVO. El 28-sep quedaban en
 * `ot_archivo` cuatro filas APP de OT de prueba (OT-9117 a OT-9120, técnico
 * «Prueba Tecnico Uio A», avisos 9999xxxx, PDF ya borrados) cuya captura ya no
 * existía. Este script solo las buscaba por la lista de capturas de las cuentas
 * de prueba: si la captura ya se había borrado (una corrida anterior, o
 * deshacer_prueba.php) y el índice nocturno ya la había copiado al Archivo, la
 * fila quedaba colgando para siempre con un enlace roto. Ahora también entra
 * toda fila APP SIN captura que sea de prueba por su aviso (9999…) o por su
 * técnico («Prueba Tecnico …»). Una fila con captura —de cualquier usuario— no
 * entra por esa vía jamás, y las OT del piloto de los técnicos reales (serie
 * 9000, avisos reales) tienen captura: no se tocan.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require getcwd() . '/nucleo/Db.php';

const CUENTAS = ['tec_prueba_uio_a', 'tec_prueba_uio_b', 'jefe_prueba_uio', 'jefe_prueba_cnlj', 'admin_prueba'];
/* Hasta el 2026-09-24 aquí había una lista fija con los dos avisos reales que
   el arnés tomó el 2026-09-21 (10355931, 10356012), y el paso de casos_gestion
   BORRABA sus filas para «devolverlos». Después de T2.28.1 Kevin Chimbo los
   asignó a técnicos reales (ajumbo y amorales, 2026-09-23 20:56): esa línea
   habría borrado sus asignaciones. Lo detuvo la guarda de más abajo. Ya no hay
   nada que devolver —el arnés no toma casos reales—, así que este script no
   toca ningún aviso que no empiece por 9999, jamás (I-2). */

$ejecutar = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--ejecutar=')) { $ejecutar = json_decode(substr($a, 11), true); }
}

$en  = implode(',', array_fill(0, count(CUENTAS), '?'));
$ids = array_map('intval', array_column(Db::todos("SELECT usuario_id FROM usuarios WHERE usuario IN ($en)", CUENTAS), 'usuario_id'));

/* Las filas APP del Archivo que son de prueba y ya no tienen captura (ver la
   cabecera). Se buscan aunque ya no queden cuentas de prueba: las cuatro del
   28-sep-2026 sobrevivieron justo a eso. */
const HUERFANA_PRUEBA = "a.origen = 'APP'
    AND NOT EXISTS (SELECT 1 FROM ot_capturadas c WHERE c.id_industec = a.id_industec)
    AND (a.aviso LIKE '9999%' OR a.tecnico LIKE 'Prueba Tecnico%')";
$huerfanas = array_column(Db::todos('SELECT a.id_industec FROM ot_archivo a WHERE ' . HUERFANA_PRUEBA), 'id_industec');

if (!$ids && !$huerfanas) { echo "No hay cuentas de prueba ni filas de prueba sueltas en el Archivo: nada que limpiar.\n"; exit(0); }
// Sin cuentas, `0` no calza con ningún usuario: solo corren los pasos por patrón.
$ei = $ids ? implode(',', $ids) : '0';   // enteros salidos de la base: seguros para interpolar

$ots = array_column(Db::todos("SELECT id_industec FROM ot_capturadas WHERE usuario_id IN ($ei) AND id_industec IS NOT NULL"), 'id_industec');
$eo  = $ots ? implode(',', array_map(fn($o) => Db::conn()->quote($o), $ots)) : "''";

// Si una cuenta de prueba tiene asignado un caso REAL, algo se lo dio por fuera
// del arnés (T2.28.1 ya no lo hace). No se «devuelve» borrando: se detiene y lo
// dice, para que una persona decida qué pasa con ese caso.
$reales = Db::todos("SELECT aviso FROM casos_gestion WHERE asignado_a IN ($ei) AND aviso NOT LIKE '9999%'");
if ($reales) {
    fwrite(STDERR, 'ABORTA: una cuenta de prueba tiene casos reales asignados; nada se tocó: '
                 . json_encode($reales) . "\n");
    exit(1);
}

// Cada paso: [tabla, WHERE]. El orden respeta las llaves foráneas (RESTRICT hacia usuarios).
$pasos = [
    'ot_fotos'           => "usuario_id IN ($ei)",
    // Las de las capturas de las cuentas de prueba, y las huérfanas de prueba
    // (28-sep-2026). El alias `a` es el de HUERFANA_PRUEBA.
    'ot_archivo'         => "id_industec IN (SELECT id_industec FROM (SELECT a.id_industec FROM ot_archivo a
                                 WHERE (a.origen = 'APP' AND a.id_industec IN ($eo)) OR (" . HUERFANA_PRUEBA . ")) x)",
    'email_queue'        => "captura_id IN (SELECT captura_id FROM ot_capturadas WHERE usuario_id IN ($ei))",
    'ot_capturadas'      => "usuario_id IN ($ei)",
    'pendiente_notas'    => "pendiente_id IN (SELECT pendiente_id FROM pendientes WHERE abierto_por IN ($ei) OR activo_fijo LIKE 'PRUEBA-%') OR usuario_id IN ($ei)",
    'pendientes'         => "abierto_por IN ($ei) OR activo_fijo LIKE 'PRUEBA-%'",
    'novedades'          => "reportada_por IN ($ei) OR novedad_uuid LIKE '99990000-%'",
    'equipos_propuestos' => "propuesto_por IN ($ei) OR equipo_uuid LIKE '99990000-%'",
    // T2.28.6: la ficha del equipo (marca, modelo, serie) y su historial de
    // cambios que dejaron las cuentas de prueba. El arnés solo escribe fichas
    // de equipos de prueba (clave PROPUESTO:99990000-...), nunca de un
    // equipo real: los dos patrones cubren lo mismo por dos caminos.
    'equipos_ficha_cambios' => "por IN ($ei) OR equipo_clave LIKE 'PROPUESTO:99990000-%'",
    'equipos_ficha'      => "actualizado_por IN ($ei) OR equipo_clave LIKE 'PROPUESTO:99990000-%'",
    'casos_seguimientos' => "tecnico_id IN ($ei) OR pedido_por IN ($ei)",
    'casos_gestion'      => "aviso LIKE '9999%'",
    // Aprendidos del campo «administrador» de las órdenes de prueba.
    'locales_admin'      => "nombre = 'Administrador de Prueba'",
    // T2.28.2: lo que dejaron las cuentas de prueba en el módulo de correos
    // -propuestas de correo de local, filas que una cuenta de prueba dio de
    // alta o editó, y sus cambios-. correo_destinatarios va ANTES que
    // `usuarios`: su `creado_por` tiene clave foránea (fk_dest_creado) y un
    // DELETE de la cuenta con filas todavía colgando ahí abortaría por RESTRICT.
    'locales_correo_propuesto'    => "propuesto_por IN ($ei) OR revisado_por IN ($ei)",
    'correo_destinatarios_cambios' => "por IN ($ei)",
    'correo_destinatarios' => "creado_por IN ($ei) OR actualizado_por IN ($ei)",
    'ot_archivo_solicitudes' => "usuario_id IN ($ei)",
    // T2.29 (29-sep-2026): el contador de la serie de ensayo de las cuentas de
    // prueba (8000-8999). Sin esto, cada corrida sube la serie y el rango de 999
    // números se agota en unas 50 baterías. Se borra en la misma pasada que sus
    // capturas: ninguna OT 8xxx queda viva que un número repetido pudiera pisar.
    'correlativos'       => "serie LIKE 'ENSAYO:%'",
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
$archivos = array_map(fn($o) => "ordenes_pdf/$o.pdf", array_values(array_unique(array_merge($ots, $huerfanas))));
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
                 ['Se retiraron las cuentas de prueba, todo lo que generaron y las filas de prueba sueltas del Archivo',
                  json_encode($hecho + ['huerfanas_archivo' => $huerfanas])]);
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
