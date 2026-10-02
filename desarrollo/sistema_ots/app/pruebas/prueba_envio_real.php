<?php
declare(strict_types=1);

/**
 * prueba_envio_real.php — El envío real de las OT INDUSTEC por zona y la
 * cuenta de envío configurable (T2.29, 29-sep-2026).
 *
 * POR QUÉ EXISTE. Hasta el 29-sep el sitio entero era PRUEBA: ninguna OT le
 * llegaba a nadie. Pasar a producción toca lo más delicado del sistema —qué
 * recibe Grupo KFC, con qué número y desde qué cuenta—, así que cada regla que
 * decide eso es una función pura y se prueba aquí, sin base ni SMTP:
 *
 *   1. la clave de la cuenta, cifrada: ida y vuelta, llave equivocada, texto
 *      alterado, y que nunca aparezca en un mensaje de error;
 *   2. la validación de una cuenta;
 *   3. el modo: config.php manda (freno de emergencia), luego la zona;
 *   4. el modo de cada OT: cuentas de prueba (ENSAYO), llenada antes de activar;
 *   5. desde qué número sigue la serie real y la vigilancia del formulario viejo;
 *   6. las series: piloto (9000), ensayo (8000) y real;
 *   7. guardas escritas en el código que no pueden perderse (forma del SQL,
 *      permisos, lectura del sistema viejo como texto).
 *
 *   php pruebas/prueba_envio_real.php        (sale con 1 si algo falla)
 */

require_once __DIR__ . '/../publico/nucleo/Correo.php';
require_once __DIR__ . '/../publico/nucleo/EnvioZonas.php';

$fallos = 0;
$total  = 0;

function afirmar(string $que, $real, $esperado): void
{
    global $fallos, $total;
    $total++;
    $bien = $real === $esperado;
    if (!$bien) { $fallos++; }
    printf("  %-78s %s\n", $que, $bien ? 'ok' : 'FALLA (dio ' . var_export($real, true) . ', esperaba ' . var_export($esperado, true) . ')');
}

/* -------------------------------------------------------------------------
   1. La clave, cifrada.
   ------------------------------------------------------------------------- */
echo "=== 1. La clave de la cuenta, cifrada ===\n";
$cfg = ['sync_secreto' => str_repeat('a1', 32)];
$llave = Correo::llave($cfg);
afirmar('la llave mide 32 bytes (AES-256)', strlen($llave), 32);
afirmar('la llave no es el secreto de sincronización tal cual', $llave !== $cfg['sync_secreto'], true);
afirmar('correo_llave de 32+ manda sobre sync_secreto',
        Correo::llave(['correo_llave' => str_repeat('z', 40), 'sync_secreto' => str_repeat('a1', 32)]) !== $llave, true);
afirmar('correo_llave corta se ignora (se usa sync_secreto)',
        Correo::llave(['correo_llave' => 'corta', 'sync_secreto' => str_repeat('a1', 32)]), $llave);
$lanzo = false;
try { Correo::llave(['sync_secreto' => 'corto']); } catch (RuntimeException $e) { $lanzo = true; }
afirmar('sin un secreto de 32+ caracteres no hay llave (lanza, no inventa una)', $lanzo, true);

$clave = 'Cl4ve-De_Prueba!';
$c1 = Correo::cifrar($clave, $llave);
$c2 = Correo::cifrar($clave, $llave);
afirmar('ida y vuelta devuelve la misma clave', Correo::descifrar($c1, $llave), $clave);
afirmar('dos cifrados de la misma clave no se parecen (IV al azar)', $c1 !== $c2, true);
afirmar('el cifrado no contiene la clave en claro', str_contains(base64_decode($c1), $clave), false);
afirmar('con otra llave no se lee (null, no basura)', Correo::descifrar($c1, Correo::llave(['sync_secreto' => str_repeat('b2', 32)])), null);
$b = base64_decode($c1);
$b[strlen($b) - 1] = chr(ord($b[strlen($b) - 1]) ^ 1);
afirmar('un solo bit alterado se detecta (GCM)', Correo::descifrar(base64_encode($b), $llave), null);
afirmar('basura no se lee', Correo::descifrar('no-es-base64-%%%', $llave), null);
afirmar('la huella de la llave es estable', Correo::huellaLlave($llave), Correo::huellaLlave(Correo::llave($cfg)));
afirmar('la huella de la llave mide 16', strlen(Correo::huellaLlave($llave)), 16);
afirmar('la huella distingue llaves', Correo::huellaLlave($llave) !== Correo::huellaLlave(Correo::llave(['sync_secreto' => str_repeat('b2', 32)])), true);
afirmar('limpiar() tacha la clave si el SMTP la repitiera',
        Correo::limpiar("535 Authentication failed for user x pass $clave", $clave), '535 Authentication failed for user x pass ********');
afirmar('limpiar() junta los espacios y recorta a 300', strlen(Correo::limpiar(str_repeat("a  \n", 200), null)) <= 300, true);

/* -------------------------------------------------------------------------
   2. La validación de una cuenta.
   ------------------------------------------------------------------------- */
echo "\n=== 2. La validación de una cuenta ===\n";
$buena = ['nombre' => 'Órdenes de trabajo', 'host' => 'smtp.titan.email', 'puerto' => '587', 'seguridad' => 'starttls',
          'usuario' => 'reclutamiento@industec.me', 'clave' => 'x', 'remitente' => 'Reclutamiento@Industec.me',
          'remitente_nombre' => 'Ordenes de Trabajo INDUSTEC'];
[$err, $d] = Correo::validarCuenta($buena, true);
afirmar('la cuenta del formulario viejo pasa', $err, null);
afirmar('  … la seguridad queda en mayúsculas', $d['seguridad'], 'STARTTLS');
afirmar('  … el remitente en minúsculas', $d['remitente'], 'reclutamiento@industec.me');
afirmar('  … el puerto como número', $d['puerto'], 587);
afirmar('sin clave en un alta → error', is_string(Correo::validarCuenta(['clave' => ''] + $buena, true)[0]), true);
afirmar('sin clave en una edición → vale (no cambia)', Correo::validarCuenta(['clave' => ''] + $buena, false)[0], null);
afirmar('sin cifrar (NINGUNA) → error: la clave viajaría en claro', is_string(Correo::validarCuenta(['seguridad' => 'NINGUNA'] + $buena, true)[0]), true);
afirmar('servidor con espacios → error', is_string(Correo::validarCuenta(['host' => 'smtp titan'] + $buena, true)[0]), true);
afirmar('servidor sin punto → error', is_string(Correo::validarCuenta(['host' => 'localhost'] + $buena, true)[0]), true);
afirmar('puerto 0 → error', is_string(Correo::validarCuenta(['puerto' => '0'] + $buena, true)[0]), true);
afirmar('remitente que no es correo → error', is_string(Correo::validarCuenta(['remitente' => 'reclutamiento'] + $buena, true)[0]), true);
afirmar('sin nombre del remitente → error', is_string(Correo::validarCuenta(['remitente_nombre' => ' '] + $buena, true)[0]), true);

/* -------------------------------------------------------------------------
   3. El modo que rige.
   ------------------------------------------------------------------------- */
echo "\n=== 3. El modo: config.php manda, después la zona ===\n";
$todas = static function (string $m): array {
    $o = [];
    foreach (EnvioZonas::ZONAS as $z) { $o[$z] = ['modo' => $m, 'desde' => null]; }
    return $o;
};
$mixto = $todas('PRUEBA');
$mixto['UIO'] = ['modo' => 'PRODUCCION', 'desde' => '2026-09-29 18:00:00'];
afirmar('config PRUEBA frena todo, aunque la zona esté activa', EnvioZonas::resolverModo('PRUEBA', $todas('PRODUCCION'), 'UIO'), 'PRUEBA');
afirmar('config PRODUCCION (el sitio definitivo) manda', EnvioZonas::resolverModo('PRODUCCION', $todas('PRUEBA'), 'UIO'), 'PRODUCCION');
afirmar('sin config: la zona UIO activa → PRODUCCION', EnvioZonas::resolverModo(null, $mixto, 'UIO'), 'PRODUCCION');
afirmar('sin config: LARB en piloto → PRUEBA', EnvioZonas::resolverModo(null, $mixto, 'LARB'), 'PRUEBA');
afirmar('una zona que no existe → PRUEBA (nunca enciende por descuido)', EnvioZonas::resolverModo(null, $todas('PRODUCCION'), 'XYZ'), 'PRUEBA');
afirmar('sin zona y solo UIO activa → el sitio sigue en PRUEBA', EnvioZonas::resolverModo(null, $mixto, null), 'PRUEBA');
$tres = $todas('PRODUCCION');
$tres['OTRA'] = ['modo' => 'PRUEBA', 'desde' => null];
afirmar('sin zona y las tres del contrato activas → PRODUCCION (OTRA no cuenta)', EnvioZonas::resolverModo(null, $tres, null), 'PRODUCCION');
afirmar('sin la 023 (estado vacío) → PRUEBA', EnvioZonas::resolverModo(null, [], 'UIO'), 'PRUEBA');
afirmar('OTRA no se puede activar hoy (contador compartido del viejo)', in_array('OTRA', EnvioZonas::ACTIVABLES, true), false);

/* -------------------------------------------------------------------------
   4. El modo de cada OT.
   ------------------------------------------------------------------------- */
echo "\n=== 4. El modo de cada OT INDUSTEC ===\n";
$desde = '2026-09-29 18:00:00';
afirmar('técnico real, zona activa, llenada después → PRODUCCION',
        EnvioZonas::modoDeCaptura('PRODUCCION', $desde, '2026-09-29 18:05:00', 'jperez'), 'PRODUCCION');
afirmar('  … llenada en el mismo segundo de la activación → PRODUCCION',
        EnvioZonas::modoDeCaptura('PRODUCCION', $desde, $desde, 'jperez'), 'PRODUCCION');
afirmar('llenada ANTES de activar y enviada después → PRUEBA (la app le dijo que era del piloto)',
        EnvioZonas::modoDeCaptura('PRODUCCION', $desde, '2026-09-29 11:30:00', 'jperez'), 'PRUEBA');
afirmar('zona en piloto → PRUEBA', EnvioZonas::modoDeCaptura('PRUEBA', null, '2026-09-29 18:05:00', 'jperez'), 'PRUEBA');
afirmar('cuenta de prueba en zona activa → ENSAYO (el correo no sale)',
        EnvioZonas::modoDeCaptura('PRODUCCION', $desde, '2026-09-29 18:05:00', 'tec_prueba_uio_a'), 'ENSAYO');
afirmar('cuenta de prueba en zona en piloto → ENSAYO (prueba el camino real)',
        EnvioZonas::modoDeCaptura('PRUEBA', null, '2026-09-29 18:05:00', 'admin_prueba'), 'ENSAYO');
afirmar('sin fecha de activación (config manda) → PRODUCCION', EnvioZonas::modoDeCaptura('PRODUCCION', null, '2026-09-29 11:30:00', 'jperez'), 'PRODUCCION');
// modo_visto (app.js desde el 29-sep): lo que la app le mostraba al técnico.
afirmar('vio la franja del piloto y la OT llega después de activar → PRUEBA',
        EnvioZonas::modoDeCaptura('PRODUCCION', $desde, '2026-09-29 18:05:00', 'jperez', 'PRUEBA'), 'PRUEBA');
afirmar('no vio franja pero el reloj del teléfono va atrasado → PRODUCCION (manda lo que vio)',
        EnvioZonas::modoDeCaptura('PRODUCCION', $desde, '2026-09-29 17:10:00', 'jperez', 'PRODUCCION'), 'PRODUCCION');
afirmar('modo_visto nunca vuelve real una OT de una zona en piloto',
        EnvioZonas::modoDeCaptura('PRUEBA', null, '2026-09-29 18:05:00', 'jperez', 'PRODUCCION'), 'PRUEBA');
afirmar('cuenta de prueba con cualquier modo_visto → ENSAYO',
        EnvioZonas::modoDeCaptura('PRODUCCION', $desde, '2026-09-29 18:05:00', 'tec_prueba_uio_b', 'PRUEBA'), 'ENSAYO');
afirmar('modo_visto de una cuenta de prueba (ENSAYO) en un técnico real se ignora → regla de la hora',
        EnvioZonas::modoDeCaptura('PRODUCCION', $desde, '2026-09-29 11:30:00', 'jperez', 'ENSAYO'), 'PRUEBA');

/* -------------------------------------------------------------------------
   5. La siembra y la vigilancia del formulario viejo.
   ------------------------------------------------------------------------- */
echo "\n=== 5. Desde qué número sigue la serie real ===\n";
afirmar('UIO correctivo: viejo en 1945 → la serie queda en 1950 (la primera, OT-1951)', EnvioZonas::siguienteSembrado(1945, 0, 0), 1950);
afirmar('LARB: 2320 → 2325', EnvioZonas::siguienteSembrado(2320, 0, 0), 2325);
afirmar('la serie real ya iba más arriba (reactivar) → no baja', EnvioZonas::siguienteSembrado(1945, 1990, 0), 1990);
afirmar('la app ya emitió números más altos → no baja', EnvioZonas::siguienteSembrado(1945, 0, 1995), 1995);
afirmar('el margen es 5', EnvioZonas::MARGEN, 5);
afirmar('vigilar: el viejo no se movió → 0 usos', EnvioZonas::vigilar(1945, 1945, [1951, 1952]), ['usos' => 0, 'choque' => false, 'repetidos' => []]);
afirmar('vigilar: 2 usos dentro del margen (la app va en 1951, 1952)', EnvioZonas::vigilar(1945, 1947, [1951, 1952]), ['usos' => 2, 'choque' => false, 'repetidos' => []]);
afirmar('vigilar: el viejo llegó a 1950 (último libre) → sin choque', EnvioZonas::vigilar(1945, 1950, [1951, 1952]), ['usos' => 5, 'choque' => false, 'repetidos' => []]);
afirmar('vigilar: el viejo llegó a 1952 → repitió 1951 y 1952 de la app', EnvioZonas::vigilar(1945, 1952, [1952, 1951, 1953]), ['usos' => 7, 'choque' => true, 'repetidos' => [1951, 1952]]);
afirmar('vigilar: el viejo pasó el margen pero la app todavía no dio esos números → sin choque', EnvioZonas::vigilar(1945, 1960, []), ['usos' => 15, 'choque' => false, 'repetidos' => []]);
afirmar('vigilar: sin dato del viejo → no afirma nada', EnvioZonas::vigilar(null, null, [1951]), ['usos' => 0, 'choque' => false, 'repetidos' => []]);
afirmar('el mapa de contadores es el mismo que el de t2_14_sembrar_correlativos.py', (static function (): bool {
    $py = (string) @file_get_contents(__DIR__ . '/../../../agentes/scripts/t2_14_sembrar_correlativos.py');
    foreach (EnvioZonas::CONTADORES_VIEJO as $serie => $ruta) {
        if (!preg_match('/"' . preg_quote($serie, '/') . '":\s*\["' . preg_quote($ruta, '/') . '"\]/', $py)) { return false; }
    }
    return true;
})(), true);

/* -------------------------------------------------------------------------
   6. Las series.
   ------------------------------------------------------------------------- */
echo "\n=== 6. Las tres series: real, piloto y ensayo ===\n";
afirmar('PRODUCCION con 1951 → vale', Emision::errorDeSerie('CORRECTIVO:UIO', 1951, 'PRODUCCION'), null);
afirmar('PRODUCCION con 9206 (sin sembrar) → error', is_string(Emision::errorDeSerie('CORRECTIVO:UIO', 9206, 'PRODUCCION')), true);
afirmar('PRUEBA con 9206 → vale', Emision::errorDeSerie('CORRECTIVO:UIO', 9206, 'PRUEBA'), null);
afirmar('PRUEBA con 1951 → error', is_string(Emision::errorDeSerie('CORRECTIVO:UIO', 1951, 'PRUEBA')), true);
afirmar('ENSAYO con 8001 → vale', Emision::errorDeSerie('CORRECTIVO:UIO', 8001, 'ENSAYO'), null);
afirmar('ENSAYO con 8999 → vale', Emision::errorDeSerie('CORRECTIVO:UIO', 8999, 'ENSAYO'), null);
afirmar('ENSAYO con 9000 → error (se agotó el rango)', is_string(Emision::errorDeSerie('CORRECTIVO:UIO', 9000, 'ENSAYO')), true);
afirmar('ENSAYO con 1951 → error', is_string(Emision::errorDeSerie('CORRECTIVO:UIO', 1951, 'ENSAYO')), true);
afirmar('una OT de ensayo NO es del piloto (se comporta como real)', Emision::esDePrueba('OT-8001-K146EC-99990021-UIO'), false);
afirmar('una OT del piloto sigue siéndolo', Emision::esDePrueba('OT-9154-R002EC-10353373-UIO'), true);
afirmar('la serie del piloto va aparte de la real', Emision::seriePrueba('CORRECTIVO:UIO'), 'PRUEBA:CORRECTIVO:UIO');
afirmar('la de ensayo también', Emision::serieEnsayo('CORRECTIVO:UIO'), 'ENSAYO:CORRECTIVO:UIO');
afirmar('las dos caben en correlativos.serie (VARCHAR 30)', strlen(Emision::serieEnsayo('PREVENTIVO:LARB')) <= 30 && strlen(Emision::seriePrueba('PREVENTIVO:LARB')) <= 30, true);
afirmar('la serie de ensayo empieza en 8000, por debajo del piloto', Emision::SERIE_ENSAYO < Emision::SERIE_PRUEBA, true);
afirmar('PRODUCCION con 8001 → error: la serie real nunca entra al rango de ensayo',
        is_string(Emision::errorDeSerie('CORRECTIVO:UIO', 8001, 'PRODUCCION')), true);
afirmar('PRODUCCION con 7999 → vale', Emision::errorDeSerie('CORRECTIVO:UIO', 7999, 'PRODUCCION'), null);
afirmar('modo de un número real → PRODUCCION', Emision::modoDeNumero('OT-1951-G018EC-10346063-UIO'), 'PRODUCCION');
afirmar('modo de un número de ensayo → ENSAYO', Emision::modoDeNumero('OT-8001-G018EC-99990021-UIO'), 'ENSAYO');
afirmar('modo de un número del piloto → PRUEBA', Emision::modoDeNumero('OT-9154-R002EC-10353373-UIO'), 'PRUEBA');
afirmar('modo de un preventivo real (0231) → PRODUCCION', Emision::modoDeNumero('OT-0231-G002EC-10347842-D2-UIO'), 'PRODUCCION');

echo "\n=== 6b. A quién sale, por grupos (el recibo del técnico) ===\n";
afirmar('local + KFC + INDUSTEC', Correo::aQuienSale(['admin@gus.com.ec'], ['miguel.vasquez@kfc.com.ec', 'jefezona-uio@industec.me']),
        ['local' => true, 'kfc' => true, 'industec' => true]);
afirmar('el maestro solo tiene servicioalcliente@: NO sale al local',
        Correo::aQuienSale(['servicioalcliente@industec.me'], ['miguel.vasquez@kfc.com.ec'])['local'], false);
afirmar('KFC también con @kfc.com', Correo::aQuienSale([], ['x@kfc.com'])['kfc'], true);
afirmar('un correo en copia no cuenta como «al local»', Correo::aQuienSale([], ['admin@gus.com.ec'])['local'], false);

/* -------------------------------------------------------------------------
   7. Guardas escritas en el código.
   ------------------------------------------------------------------------- */
echo "\n=== 7. Guardas que no pueden perderse ===\n";
// Con `core.autocrlf=true` los archivos de la estación están en CRLF y los que
// se escriben nuevos, en LF: se compara con los finales de línea normalizados.
$src = static fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../publico/' . $f));
$emi = $src('nucleo/Emision.php');
$enc = substr($emi, (int) strpos($emi, 'private static function encolar'));
$enc = substr($enc, 0, (int) strpos($enc, 'public static function reintentarPendientes'));
afirmar('encolar: con número del piloto, retenido en cualquier modo', str_contains($enc, "\$prueba = \$modo !== 'PRODUCCION' || self::esDePrueba(\$id);"), true);
afirmar('encolar: `estado` es la ÚLTIMA asignación del ON DUPLICATE KEY UPDATE',
        (bool) preg_match('/motivo\s+= IF\(estado = \'FALLIDO\'[^\n]+\n\s+estado\s+= IF\(estado = \'FALLIDO\'/', $enc), true);
afirmar('emitir: con número ya dado, el modo lo dice el número (piloto sigue piloto, real sigue real)',
        str_contains($emi, "\$modo = self::modoDeNumero((string) \$id);"), true);
$mnr = substr($emi, (int) strpos($emi, 'public static function mayorNumeroReal'), 1200);
afirmar('mayorNumeroReal: sin la serie de ensayo ni las cuentas de prueba (revisión del 29-sep)',
        str_contains($mnr, "NOT LIKE '%\\\\_prueba%'") && str_contains($mnr, '< " . self::SERIE_ENSAYO'), true);
afirmar('reservarReal: salta sobre el formulario viejo y sobre números ya usados',
        str_contains($emi, 'EnvioZonas::contadorViejo($serie)') && str_contains($emi, 'self::numeroUsado($modulo, $zona, $n)'), true);
$cor = $src('nucleo/Correo.php');
afirmar('despachar: lo del piloto o de una cuenta de prueba vuelve a RETENIDO',
        str_contains($cor, "Emision::esDePrueba((string) \$c['id_industec']) || str_contains((string) (\$c['emisor_usuario'] ?? ''), '_prueba')"), true);
afirmar('despachar: el freno de emergencia de config.php no conecta', str_contains($cor, "if (Emision::modoConfig() === 'PRUEBA')"), true);
afirmar('despachar: un fallo de la cuenta devuelve todo a la cola sin gastar intentos', str_contains($cor, "'CUENTA_FALLA'") && str_contains($cor, 'self::devolver(array_column($salen'), true);
afirmar('el correo de prueba solo va a un buzón @industec.me', str_contains($cor, "str_ends_with(\$destino, '@industec.me')"), true);
afirmar('la cuenta activa no cambia de remitente aquí', str_contains($cor, 'El remitente de la cuenta con la que salen los correos no se cambia aquí'), true);
$env = $src('envio.php');
afirmar('envio.php: la cuenta de prueba (ENSAYO) no es del piloto', str_contains($env, "\$prueba = \$modoOt === 'PRUEBA';"), true);
afirmar('envio.php: el correo sale después de responderle al técnico',
        str_contains($env, 'register_shutdown_function') && str_contains($env, 'litespeed_finish_request()') && str_contains($env, 'session_write_close()'), true);
afirmar('envio.php: le dice al técnico que no la emita otra vez en el formulario de siempre',
        str_contains($env, 'No la emitas otra vez en el formulario de siempre.'), true);
afirmar('envio.php: el recibo dice a quién salió según la cola, no lo supone',
        str_contains($env, 'Correo::aQuienSale(') && str_contains($env, 'NO le llega al local'), true);
$crr = $src('correos.php');
afirmar('correos.php: activar una zona exige emision.activar', str_contains($crr, "if (!\$puedeActivar) {"), true);
afirmar('correos.php: la cuenta exige correos.cuenta', str_contains($crr, "if (!\$puedeCuenta) {"), true);
afirmar('correos.php: nunca pinta una clave (el campo de clave va vacío)', !str_contains($crr, "name=\"clave\" value="), true);
$imp = $src('correo_cuenta_importar_cli.php');
afirmar('el importador LEE los config.php del viejo como texto (no los incluye)',
        str_contains($imp, "@file_get_contents(\$raiz . '/' . \$rel)") && !preg_match('/(require|include)(_once)?\s*\(?\s*\$raiz/', $imp), true);
afirmar('el importador nunca imprime la clave (solo largo y huella)', !preg_match('/echo[^;]*\[\'PASS\'\]|echo[^;]*\$datos\[\'clave\'\]/', $imp), true);
$yo = $src('yo.php');
afirmar('yo.php: la franja depende de la zona de quien entra', str_contains($yo, "Emision::modo(\$u['zona'] ?? null)"), true);
afirmar('app.js: la OT lleva el modo que la app mostraba al llenarla', str_contains($src('app.js'), 'modo_visto: (YO && YO.emision_modo) || null,'), true);
afirmar('Emision::modoCaptura lee modo_visto de la carga', str_contains($emi, "['modo_visto'] ?? null"), true);
// v27 o más: una subida posterior (v28, T2.28.11) no invalida que esta llegó.
afirmar('sw.js subió a v27 (o más) por el cambio de app.js',
        preg_match("/const VERSION = 'ot-industec-v(\d+)'/", $src('sw.js'), $mv) === 1 && (int) $mv[1] >= 27, true);
$sql = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../sql/023_envio_real.sql'));
afirmar('la 023 nace con las cuatro zonas en PRUEBA (no activa nada)',
        str_contains($sql, "('UIO', 'PRUEBA'), ('LARB', 'PRUEBA'), ('CNLJ', 'PRUEBA'), ('OTRA', 'PRUEBA')"), true);
afirmar('la 023 aparta las series del piloto', str_contains($sql, "SELECT CONCAT('PRUEBA:', x.s), x.u"), true);
afirmar('la 023 no usa correlativos.ultimo en el ON DUPLICATE (error 1052, ambiguo)',
        !str_contains($sql, 'GREATEST(correlativos.ultimo'), true);
afirmar('la 023 da los dos permisos solo a SUPERADMIN',
        str_contains($sql, "('SUPERADMIN', 'emision.activar'), ('SUPERADMIN', 'correos.cuenta')") && !str_contains($sql, "('ADMIN', 'correos.cuenta')"), true);

printf("\n%d comprobaciones · %d fallos\n", $total, $fallos);
exit($fallos === 0 ? 0 : 1);
