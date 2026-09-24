<?php
declare(strict_types=1);

/**
 * correos_sembrar_cli.php — Siembra el buzón institucional del jefe de zona
 * en `correo_destinatarios` (T2.28.2, obs. 2 y 8; migración 013).
 *
 * POR QUÉ EXISTE. Hasta esta migración, el correo del jefe de zona -el buzón
 * de INDUSTEC que va en copia en toda orden- vivía fijo en el código de
 * producción (`submit.php` de cada zona, `ot_normal_v3/{zona}/`) y, aparte,
 * en el maestro de locales (`correo_jefe_op`). Este script trae el mapa de
 * producción a la tabla nueva, PERO NO SE FÍA DE SÍ MISMO: lo comprueba
 * contra el maestro antes de sembrar nada y ABORTA si una sola zona no
 * coincide (I-10) -sin eso, una zona con el buzón mal escrito quedaría fijada
 * por este script sin que nadie lo note-.
 *
 * NO SIEMBRA a los jefes de mantenimiento de Grupo KFC: producción los tiene
 * APAGADOS (comentados en `submit.php:171-175` de cada zona) -alguien los
 * apagó a propósito-, así que aquí solo se muestran como sugerencia; activarlos
 * es decisión de Andrés, hecha a mano en correos.php.
 *
 * Uso, desde ot/ (por SSH):
 *     php correos_sembrar_cli.php              # simulacro: solo muestra el mapa
 *     php correos_sembrar_cli.php --ejecutar    # siembra de verdad
 *
 * REQUIERE APROBACIÓN (tabla de permisos de T2.28.2): el `--ejecutar` no lo
 * corre un agente por su cuenta. Se propone con el simulacro y lo ejecuta
 * Andrés, o alguien con su autorización explícita para esa cifra exacta.
 *
 * Idempotente: correrlo dos veces dos veces no duplica filas (UPDATE por las
 * dos claves únicas de la 013, `uq_destinatario` y `uq_rol`).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// getcwd(), no __DIR__: este CLI se sube por scp a ~/respaldos/ y se corre
// desde ot/ (ver el uso, arriba). __DIR__ apuntaría a ~/respaldos/nucleo/,
// que no existe -mismo bug que ya se dio en archivo_verificar_cli.php-.
require getcwd() . '/nucleo/Db.php';
require getcwd() . '/nucleo/Catalogo.php';

// El mapa de producción: idéntico en las tres copias de submit.php
// (ot_normal_v3/{uio,larb,cnlj}/submit.php:165-169, $correosPorZona). OTRA no
// tiene buzón propio en producción, así que no entra aquí.
const MAPA_PRODUCCION = [
    'UIO'  => 'jefezona-uio@industec.me',
    'LARB' => 'jefetecniconacional@industec.me',
    'CNLJ' => 'jefezonacuenca-loja@industec.me',
];

// Los jefes de mantenimiento de Grupo KFC que producción tiene APAGADOS
// (submit.php:171-175, comentados). Se muestran, nunca se siembran: la tabla
// de permisos de T2.28.2 prohíbe activarlos sin que Andrés lo diga.
const KFC_APAGADOS = [
    'UIO'  => 'andres.rigoli@kfc.com.ec',
    'LARB' => 'andres.rigoli@kfc.com.ec',
    'CNLJ' => 'ronald.valero@kfc.com.ec',
];

try {
    Db::uno('SELECT 1 FROM correo_destinatarios LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "falta la migración 013 (correo_destinatarios): aplícala antes de sembrar\n");
    exit(3);
}

// I-10: verificación cruzada contra una fuente independiente -el maestro de
// locales, no el propio mapa que trae este script- antes de escribir nada.
$cat = Catalogo::cargar();
if ($cat === null) {
    fwrite(STDERR, "no pude leer catalogos/locales.json: sin maestro no hay con qué comparar\n");
    exit(3);
}
$delMaestro = [];   // zona => {correo_jefe_op distintos que aparecen en esa zona}
foreach ($cat['locales'] as $l) {
    $zona   = (string) ($l['zona'] ?? '');
    $correo = strtolower(trim((string) ($l['correo_jefe_op'] ?? '')));
    if ($zona === '' || $correo === '') { continue; }
    $delMaestro[$zona][$correo] = true;
}

$discrepancias = [];
foreach (MAPA_PRODUCCION as $zona => $correo) {
    $vistos = array_keys($delMaestro[$zona] ?? []);
    if ($vistos !== [$correo]) {
        $discrepancias[$zona] = ['produccion' => $correo, 'maestro' => $vistos];
    }
}
$coincide = count($discrepancias) === 0;

echo "Mapa de producción (submit.php, las tres zonas) contra el maestro (locales.json, correo_jefe_op):\n";
foreach (MAPA_PRODUCCION as $zona => $correo) {
    $marca = isset($discrepancias[$zona]) ? 'DISCREPA' : 'coincide';
    printf("  %-6s %-32s maestro: %-32s %s\n", $zona, $correo,
           implode(',', array_keys($delMaestro[$zona] ?? [])) ?: '(vacío)', $marca);
}
printf("coincide con el maestro: %d/3\n", 3 - count($discrepancias));
if (!$coincide) {
    fwrite(STDERR, "ABORTA: al menos una zona no coincide con el maestro (I-10). No se siembra nada.\n"
                 . json_encode($discrepancias, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

echo "\nJefes de mantenimiento de Grupo KFC que producción tiene APAGADOS -sugerencia, no se siembran-:\n";
foreach (KFC_APAGADOS as $zona => $correo) { printf("  %-6s %s\n", $zona, $correo); }

// Lo que tenga config.php en correo_por_zona / correo_fijos: se siembra con
// origen CONFIG_PHP para no perder una copia que alguien ya hubiera cargado
// ahí a mano.
$cfg = Db::config();
$deConfig = [];
foreach ((array) ($cfg['correo_por_zona'] ?? []) as $zona => $lista) {
    foreach ((array) $lista as $m) {
        $m = strtolower(trim((string) $m));
        if ($m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL)) {
            $deConfig[] = ['ambito' => 'ZONA', 'zona' => (string) $zona, 'correo' => $m];
        }
    }
}
foreach ((array) ($cfg['correo_fijos'] ?? []) as $m) {
    $m = strtolower(trim((string) $m));
    if ($m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL)) {
        $deConfig[] = ['ambito' => 'GENERAL', 'zona' => null, 'correo' => $m];
    }
}

$ejecutar = in_array('--ejecutar', $argv, true);
if (!$ejecutar) {
    echo "\nSIMULACRO -- no se tocó nada. Para sembrar de verdad: php correos_sembrar_cli.php --ejecutar\n";
    echo 'config.php aportaría ' . count($deConfig) . " fila(s) más (origen CONFIG_PHP).\n";
    exit(0);
}

$autor = Db::uno("SELECT usuario_id FROM usuarios WHERE rol = 'SUPERADMIN' ORDER BY usuario_id LIMIT 1")['usuario_id'] ?? null;
if ($autor === null) {
    fwrite(STDERR, "no hay ningún SUPERADMIN en la base: no hay a nombre de quién sembrar\n");
    exit(3);
}

$pdo = Db::conn();
$pdo->beginTransaction();
try {
    $sembrados = 0;
    foreach (MAPA_PRODUCCION as $zona => $correo) {
        Db::ejecutar(
            "INSERT INTO correo_destinatarios
                (uso, destino, ambito, zona, rol, tipo, correo, nombre, activo, origen, creado_por)
             VALUES ('ORDEN', 'INTERNO', 'ZONA', ?, 'JEFE_ZONA', 'COPIA', ?, ?, 1, 'PRODUCCION', ?)
             ON DUPLICATE KEY UPDATE correo = VALUES(correo), activo = 1,
                                     actualizado_por = VALUES(creado_por), actualizado_en = NOW()",
            [$zona, $correo, 'Jefe de zona ' . $zona, $autor]
        );
        $sembrados++;
    }
    foreach ($deConfig as $f) {
        Db::ejecutar(
            "INSERT INTO correo_destinatarios
                (uso, destino, ambito, zona, rol, tipo, correo, activo, origen, creado_por)
             VALUES ('ORDEN', 'INTERNO', ?, ?, 'OTRO', 'COPIA', ?, 1, 'CONFIG_PHP', ?)
             ON DUPLICATE KEY UPDATE correo = VALUES(correo), activo = 1",
            [$f['ambito'], $f['zona'], $f['correo'], $autor]
        );
        $sembrados++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, 'SIN CAMBIOS: ' . $e->getMessage() . "\n");
    exit(1);
}

$total = (int) (Db::uno("SELECT COUNT(*) n FROM correo_destinatarios WHERE uso='ORDEN' AND rol='JEFE_ZONA' AND activo=1")['n'] ?? 0);
echo "\nSEMBRADO: $sembrados fila(s) (3 jefes de zona" . ($deConfig !== [] ? ' + ' . count($deConfig) . ' de config.php' : '') . ").\n";
echo "correo_destinatarios activos con rol JEFE_ZONA: $total (esperado 3)\n";
exit($total === 3 ? 0 : 1);
