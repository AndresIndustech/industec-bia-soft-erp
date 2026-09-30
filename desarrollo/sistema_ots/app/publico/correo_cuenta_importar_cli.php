<?php
declare(strict_types=1);

/**
 * correo_cuenta_importar_cli.php — Trae a `correo_cuentas` la cuenta con la que
 * el formulario viejo manda las OT: reclutamiento@industec.me en
 * smtp.titan.email (T2.29, decisión de Andrés del 2026-09-29: «los correos deben
 * enviarse desde reclutamiento, al igual que está configurado en el sistema
 * antiguo»).
 *
 * SOLO LEE EL SISTEMA VIEJO, Y COMO TEXTO. Sus cinco config.php no se incluyen
 * ni se ejecutan: al cargarse crean carpetas y archivos de registro y cambian
 * permisos dentro de producción (`@mkdir`, `@file_put_contents`, `@chmod`), y
 * producción no se toca (regla 9). Se leen como texto y se extraen las cinco
 * variables $SMTP_* con una expresión.
 *
 * NO SE FÍA DE UNA SOLA COPIA (I-10). Los cinco módulos traen su propia copia
 * de la cuenta; si una sola difiere en algo, aborta sin escribir y dice cuál.
 * El 2026-09-29 las cinco coincidían: smtp.titan.email:587 STARTTLS, usuario
 * reclutamiento@industec.me, clave de 13 caracteres (huella 1f9f059a).
 *
 * LA CLAVE NUNCA SE IMPRIME. Se muestra su largo y los ocho primeros
 * caracteres de su sha256, que bastan para comprobar que la copia es la misma
 * sin verla. Se guarda cifrada (Correo::cifrar) y, antes de dar la importación
 * por buena, se descifra de la base y se compara su huella con la del origen.
 *
 * Uso, desde ot/ (por SSH):
 *     php correo_cuenta_importar_cli.php              # simulacro: lee y compara, no escribe
 *     php correo_cuenta_importar_cli.php --ejecutar   # la guarda (y la deja activa si no hay otra)
 *     php correo_cuenta_importar_cli.php --ejecutar --probar   # además conecta y se autentica (no envía nada)
 *
 * Idempotente: si la cuenta ya está y su clave es la misma, no cambia nada. Si
 * la clave del viejo cambió (alguien la rotó allá), aborta salvo con
 * --actualizar-clave.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/nucleo/Correo.php';
require_once __DIR__ . '/nucleo/EnvioZonas.php';

const MODULOS_VIEJO = [
    'ot_normal_v3/uio/config.php', 'ot_normal_v3/larb/config.php', 'ot_normal_v3/cnlj/config.php',
    'ot_mantenimiento/config.php', 'ot_normal_otros/config.php',
];
// El nombre con que el viejo firma los correos (setFrom de submit.php). Se
// conserva tal cual: es el que KFC y la administración ya reconocen.
const SUBMIT_VIEJO = 'ot_normal_v3/uio/submit.php';

$ejecutar = in_array('--ejecutar', $argv, true);
$probar   = in_array('--probar', $argv, true);
$actualizar = in_array('--actualizar-clave', $argv, true);

$raiz = EnvioZonas::raizViejo();
if ($raiz === null) {
    fwrite(STDERR, "no encuentro la carpeta del sistema viejo (ot/produccion): nada que importar\n");
    exit(3);
}

/** Las variables $SMTP_* de un config.php, leído como texto. */
function leerSmtp(string $texto): array
{
    $v = [];
    foreach (['HOST', 'SECURE', 'USER', 'PASS'] as $k) {
        $v[$k] = preg_match('/^\s*\$SMTP_' . $k . '\s*=\s*([\'"])(.*?)\1\s*;/m', $texto, $m) ? $m[2] : null;
    }
    $v['PORT'] = preg_match('/^\s*\$SMTP_PORT\s*=\s*(\d+)\s*;/m', $texto, $m) ? (int) $m[1] : null;
    return $v;
}
$huella = static fn(?string $s): string => $s === null ? '(falta)' : 'largo ' . strlen($s) . ', huella ' . substr(hash('sha256', $s), 0, 8);

echo "Cuenta de envío del formulario viejo · " . ($ejecutar ? 'EJECUCIÓN' : 'SIMULACRO') . "\n";
echo "origen (solo lectura): $raiz\n\n";

$leidas = [];
foreach (MODULOS_VIEJO as $rel) {
    $txt = @file_get_contents($raiz . '/' . $rel);
    if ($txt === false) {
        fwrite(STDERR, "ABORTA: no se pudo leer $rel\n");
        exit(1);
    }
    $leidas[$rel] = leerSmtp($txt);
    $s = $leidas[$rel];
    printf("  %-32s %s:%s %s · %s · clave %s\n", $rel, $s['HOST'] ?? '?', $s['PORT'] ?? '?', $s['SECURE'] ?? '?',
           $s['USER'] ?? '?', $huella($s['PASS']));
}

// I-10: las cinco copias tienen que decir exactamente lo mismo.
$ref = reset($leidas);
$distintas = [];
foreach ($leidas as $rel => $s) {
    if ($s !== $ref) { $distintas[] = $rel; }
}
if (in_array(null, $ref, true)) {
    fwrite(STDERR, "ABORTA: a la primera copia le falta alguna variable \$SMTP_*: no se adivina (I-7)\n");
    exit(1);
}
if ($distintas !== []) {
    fwrite(STDERR, "ABORTA: estas copias no coinciden con la primera: " . implode(', ', $distintas) . ". No se importa nada.\n");
    exit(1);
}
echo "\nlas 5 copias coinciden: 5/5\n";

$seguridad = match (strtolower((string) $ref['SECURE'])) { 'tls' => 'STARTTLS', 'ssl' => 'SSL', default => '' };
if ($seguridad === '') {
    fwrite(STDERR, "ABORTA: SMTP_SECURE = '{$ref['SECURE']}' no es tls ni ssl\n");
    exit(1);
}
$remitenteNombre = 'Ordenes de Trabajo INDUSTEC';
$sub = @file_get_contents($raiz . '/' . SUBMIT_VIEJO);
if ($sub !== false && preg_match('/setFrom\(\s*\$SMTP_USER\s*,\s*([\'"])(.+?)\1\s*\)/', $sub, $m)) {
    $remitenteNombre = $m[2];
}
$datos = [
    'nombre'           => 'Órdenes de trabajo (reclutamiento)',
    'host'             => (string) $ref['HOST'],
    'puerto'           => (int) $ref['PORT'],
    'seguridad'        => $seguridad,
    'usuario'          => (string) $ref['USER'],
    'clave'            => (string) $ref['PASS'],
    'remitente'        => strtolower((string) $ref['USER']),
    'remitente_nombre' => $remitenteNombre,
];
[$error] = Correo::validarCuenta($datos, true);
if ($error !== null) {
    fwrite(STDERR, "ABORTA: la cuenta del viejo no pasa la validación: $error\n");
    exit(1);
}
printf("se importaría: %s:%d %s · usuario %s · remitente «%s» <%s> · clave %s\n",
       $datos['host'], $datos['puerto'], $datos['seguridad'], $datos['usuario'], $datos['remitente_nombre'],
       $datos['remitente'], $huella($datos['clave']));

try {
    Db::uno('SELECT 1 FROM correo_cuentas LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "falta la migración 023 (correo_cuentas): aplícala antes de importar\n");
    exit(3);
}
$ya = Db::uno('SELECT * FROM correo_cuentas WHERE host = ? AND usuario = ?', [$datos['host'], $datos['usuario']]);
if ($ya !== null) {
    $guardada = Correo::conClave($ya)['clave'];
    $igual = $guardada !== null && hash_equals(hash('sha256', $guardada), hash('sha256', $datos['clave']));
    echo "\nya existe en correo_cuentas (cuenta " . $ya['cuenta_id'] . ', ' . ((int) $ya['activa'] === 1 ? 'activa' : 'no activa')
       . '): clave ' . ($igual ? 'igual a la del viejo' : 'DISTINTA de la del viejo') . "\n";
}

if (!$ejecutar) {
    echo "\nSIMULACRO: no se escribió nada. Para importarla: php correo_cuenta_importar_cli.php --ejecutar\n";
    exit(0);
}

// A nombre de quién queda: `--a-nombre-de <login>` (el SUPERADMIN que lo
// pidió); sin eso, el primer SUPERADMIN activo, como correos_sembrar_cli.php.
$k = array_search('--a-nombre-de', $argv, true);
$login = $k !== false ? (string) ($argv[$k + 1] ?? '') : null;
$autor = $login !== null
    ? Db::uno("SELECT usuario_id, usuario FROM usuarios WHERE usuario = ? AND rol = 'SUPERADMIN' AND activo = 1", [$login])
    : Db::uno("SELECT usuario_id, usuario FROM usuarios WHERE rol = 'SUPERADMIN' AND activo = 1 ORDER BY usuario_id LIMIT 1");
if ($autor === null) {
    fwrite(STDERR, "no hay un SUPERADMIN activo" . ($login !== null ? " con el usuario «$login»" : '') . ": no hay a nombre de quién importar\n");
    exit(3);
}
$uid = (int) $autor['usuario_id'];

if ($ya !== null) {
    if ($igual) {
        echo "nada que cambiar.\n";
        $id = (int) $ya['cuenta_id'];
    } elseif (!$actualizar) {
        fwrite(STDERR, "ABORTA: la clave guardada no es la del viejo. Si se rotó a propósito, corre con --actualizar-clave.\n");
        exit(1);
    } else {
        $r = Correo::guardar(['clave' => $datos['clave']] + array_intersect_key($ya, array_flip(
            ['nombre', 'host', 'puerto', 'seguridad', 'usuario', 'remitente', 'remitente_nombre'])), (int) $ya['cuenta_id'], $uid);
        if (!$r['ok']) { fwrite(STDERR, 'ABORTA: ' . $r['error'] . "\n"); exit(1); }
        $id = (int) $ya['cuenta_id'];
        echo "clave actualizada con la del viejo.\n";
    }
} else {
    $r = Correo::guardar($datos, null, $uid, 'SISTEMA_VIEJO');
    if (!$r['ok']) { fwrite(STDERR, 'ABORTA: ' . $r['error'] . "\n"); exit(1); }
    $id = (int) $r['id'];
    echo "importada como cuenta $id, a nombre de {$autor['usuario']}.\n";
}

// La comprobación que cierra (I-10): lo que quedó en la base, descifrado, es
// exactamente la clave del viejo. Si no, la cuenta se deshabilita y se aborta.
$enBase = Correo::cuenta($id);
if ($enBase === null || $enBase['clave'] === null
    || !hash_equals(hash('sha256', (string) $enBase['clave']), hash('sha256', $datos['clave']))) {
    Db::ejecutar('UPDATE correo_cuentas SET activa = 0, habilitada = 0 WHERE cuenta_id = ?', [$id]);
    fwrite(STDERR, "ABORTA: la clave guardada no se lee igual que la del viejo; la cuenta $id quedó deshabilitada\n");
    exit(1);
}
echo "comprobado: la clave guardada se descifra con la misma huella que la del viejo (" . $huella((string) $enBase['clave']) . ")\n";

// Si no hay ninguna cuenta activa, esta pasa a serlo. No envía nada por sí
// sola: un correo solo sale de una zona con el envío real activo (EnvioZonas).
$activa = Db::uno('SELECT cuenta_id FROM correo_cuentas WHERE activa = 1');
if ($activa === null) {
    $r = Correo::activar($id, $uid, true);
    echo $r['ok'] ? "queda como la cuenta activa (desde ella saldrán los correos).\n" : 'no se pudo activar: ' . $r['error'] . "\n";
} else {
    echo 'la cuenta activa sigue siendo la ' . $activa['cuenta_id'] . ($activa['cuenta_id'] == $id ? ' (esta misma)' : '') . ".\n";
}

if ($probar) {
    $p = Correo::probarYAnotar($id, $uid);
    echo 'prueba de conexión: ' . ($p['ok'] ? 'OK' : 'FALLA') . ' · ' . $p['detalle'] . "\n";
    exit($p['ok'] ? 0 : 1);
}
exit(0);
