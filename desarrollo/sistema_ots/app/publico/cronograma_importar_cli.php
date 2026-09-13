<?php
declare(strict_types=1);

/**
 * cronograma_importar_cli.php — Carga el cronograma preventivo en la base (D15, T2.14.5).
 *
 * SOLO POR LÍNEA DE ÓRDENES. Lee `catalogos/cronograma_preventivo.json` (lo
 * que publica `t2_7_cronograma_preventivo.py` desde la estación) y lo vuelca en
 * `ingresos_preventivos`, idempotente por (local, año, número):
 *
 *   - el plan ORIGINAL se fija la primera vez y no se vuelve a tocar (es lo
 *     acordado con Grupo KFC, contra lo que se mide el cumplimiento);
 *   - el plan VIGENTE, el kit y las órdenes se refrescan desde el JSON SOLO
 *     mientras nadie los haya editado desde la app (`actualizado_por IS NULL`):
 *     una reagenda hecha por el jefe de zona no se pisa con el JSON viejo;
 *   - las fechas reales se completan si faltaban; un ingreso CUMPLIDO desde la
 *     app nunca vuelve atrás.
 *
 * USO (en el servidor, desde la carpeta de la app):
 *     php cronograma_importar_cli.php            # importa
 *     php cronograma_importar_cli.php --ensayo   # cuenta y no escribe
 *
 * Código de salida: 0 bien, 1 sin JSON, 3 sin tabla.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/nucleo/Db.php';

$ensayo = in_array('--ensayo', array_slice($argv, 1), true);

try { Db::todos('SELECT 1 FROM ingresos_preventivos LIMIT 1'); }
catch (Throwable $e) { fwrite(STDERR, "falta la tabla ingresos_preventivos: aplica la 009\n"); exit(3); }

$ruta = null;
foreach ([__DIR__ . '/catalogos', __DIR__ . '/../../../../SALIDAS IA/OTS/catalogos'] as $c) {
    if (is_file($c . '/cronograma_preventivo.json')) { $ruta = $c . '/cronograma_preventivo.json'; break; }
}
if ($ruta === null) { fwrite(STDERR, "no está cronograma_preventivo.json\n"); exit(1); }
$json = json_decode((string) file_get_contents($ruta), true);
$ingresos = $json['ingresos'] ?? null;
if (!is_array($ingresos)) { fwrite(STDERR, "el JSON no trae ingresos\n"); exit(1); }
$anioJson = (int) ($json['anio'] ?? date('Y'));

$KIT = ['PENDIENTE' => 'SIN_KIT', 'SOLICITADO' => 'SOLICITADO', 'DISPONIBLE' => 'CONFIRMADO',
        'CONFIRMADO' => 'CONFIRMADO', 'ENTREGADO' => 'ENTREGADO'];
$ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];
$n = ['leidos' => 0, 'nuevos' => 0, 'refrescados' => 0, 'protegidos' => 0, 'saltados' => 0];

$previos = [];
foreach (Db::todos('SELECT local_codigo, anio, numero, actualizado_por, estado FROM ingresos_preventivos') as $p) {
    $previos[$p['local_codigo'] . '|' . $p['anio'] . '|' . $p['numero']] = $p;
}

foreach ($ingresos as $i) {
    $n['leidos']++;
    $local = strtoupper(trim((string) ($i['local'] ?? '')));
    $numero = (int) ($i['numero'] ?? 0);
    if ($local === '' || $numero < 1 || $numero > 4) { $n['saltados']++; continue; }
    $anio = $anioJson;
    if (preg_match('/-(\d{4})-\d$/', (string) ($i['id'] ?? ''), $m)) { $anio = (int) $m[1]; }
    $zona = strtoupper((string) ($i['zona'] ?? ''));
    if (!in_array($zona, $ZONAS, true)) { $zona = null; }
    $po = $i['plan_original'] ?? $i['plan_vigente'] ?? null;
    $pv = $i['plan_vigente'] ?? $po;
    $real = $i['real'] ?? null;
    $ots = is_array($real['ots'] ?? null) ? array_values(array_filter(array_map(
        static fn($o) => is_array($o) ? (string) ($o['id'] ?? '') : (string) $o, $real['ots']))) : [];
    $cerrado = !empty($real['cerrado']);
    $estado = $cerrado ? 'CUMPLIDO' : ($ots ? 'EN_CURSO' : 'PLANIFICADO');
    $kit = $KIT[strtoupper((string) ($i['kit'] ?? 'PENDIENTE'))] ?? 'SIN_KIT';
    $clave = $local . '|' . $anio . '|' . $numero;
    $prev = $previos[$clave] ?? null;

    if ($prev === null) { $n['nuevos']++; }
    elseif ($prev['actualizado_por'] !== null || $prev['estado'] === 'CUMPLIDO') { $n['protegidos']++; }
    else { $n['refrescados']++; }
    if ($ensayo) { continue; }

    Db::ejecutar(
        'INSERT INTO ingresos_preventivos
            (local_codigo, zona, anio, numero, plan_original_inicio, plan_original_fin,
             plan_vigente_inicio, plan_vigente_fin, kit_estado, kit_nota, real_inicio, real_fin, estado, ot_ids)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            zona                 = COALESCE(zona, VALUES(zona)),
            plan_original_inicio = COALESCE(plan_original_inicio, VALUES(plan_original_inicio)),
            plan_original_fin    = COALESCE(plan_original_fin, VALUES(plan_original_fin)),
            -- Lo que alguien editó desde la app no se pisa con el JSON.
            plan_vigente_inicio  = IF(actualizado_por IS NULL, VALUES(plan_vigente_inicio), plan_vigente_inicio),
            plan_vigente_fin     = IF(actualizado_por IS NULL, VALUES(plan_vigente_fin), plan_vigente_fin),
            kit_estado           = IF(actualizado_por IS NULL, VALUES(kit_estado), kit_estado),
            kit_nota             = IF(actualizado_por IS NULL, VALUES(kit_nota), kit_nota),
            real_inicio          = COALESCE(real_inicio, VALUES(real_inicio)),
            real_fin             = COALESCE(real_fin, VALUES(real_fin)),
            ot_ids               = IF(actualizado_por IS NULL, VALUES(ot_ids), COALESCE(ot_ids, VALUES(ot_ids))),
            estado               = IF(estado = "CUMPLIDO" OR actualizado_por IS NOT NULL, estado, VALUES(estado))',
        [$local, $zona, $anio, $numero, $po['inicio'] ?? null, $po['fin'] ?? null,
         $pv['inicio'] ?? null, $pv['fin'] ?? null, $kit,
         ($i['kit_texto'] ?? '') !== '' ? mb_substr((string) $i['kit_texto'], 0, 300) : null,
         $real['inicio'] ?? null, $real['fin'] ?? null, $estado,
         $ots ? json_encode($ots, JSON_UNESCAPED_UNICODE) : null]
    );
}

$tot = Db::uno('SELECT COUNT(*) n, SUM(estado = "CUMPLIDO") c, SUM(plan_vigente_inicio IS NULL) s FROM ingresos_preventivos');
printf("%s: leídos %d · nuevos %d · refrescados %d · protegidos (editados en la app o cumplidos) %d · saltados %d\n",
       $ensayo ? 'ENSAYO' : 'importado', $n['leidos'], $n['nuevos'], $n['refrescados'], $n['protegidos'], $n['saltados']);
printf("tabla: %d ingresos, %d cumplidos, %d sin agendar\n", (int) ($tot['n'] ?? 0), (int) ($tot['c'] ?? 0), (int) ($tot['s'] ?? 0));
if (!$ensayo) {
    try {
        Db::ejecutar("INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia, detalle, datos, exito)
                      VALUES (NULL, 'automatico', 'IMPORTAR_CRONOGRAMA', 'cronograma', ?, ?, ?, 1)",
                     [basename($ruta), sprintf('%d ingresos en la tabla', (int) ($tot['n'] ?? 0)), json_encode($n)]);
    } catch (Throwable $e) { /* la bitácora nunca frena la importación */ }
}
exit(0);
