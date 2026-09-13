<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';
require_once __DIR__ . '/nucleo/Catalogo.php';

/**
 * cronograma_accion.php — El cronograma de preventivos que escribe (D15, T2.14.5).
 *
 * Hasta el 2026-09-13 confirmar el kit, reagendar, cerrar y agendar terminaban
 * en un `alert()` que decía «la acción todavía no se guarda». Desde la 009
 * existen `ingresos_preventivos` y `cronograma_novedades`, y este extremo es
 * el único que les escribe. Recibe JSON por POST con la cabecera X-Csrf (como
 * `envio.php`) y responde JSON.
 *
 * LAS TRES REGLAS DEL CRONOGRAMA, ahora en el servidor:
 *   1. EL PLAN ORIGINAL NO SE PISA: reagendar mueve solo `plan_vigente_*`; lo
 *      acordado con Grupo KFC queda en `plan_original_*` y contra eso se mide
 *      el cumplimiento. La primera fecha de un ingreso sin plan sí lo fija.
 *   2. LA NOVEDAD ES OBLIGATORIA AL MOVER UNA FECHA: sin motivo no se guarda.
 *      Cada movimiento deja su fila en `cronograma_novedades` (REAGENDA) con
 *      la fecha de antes y la de después: es lo que se le reporta a KFC.
 *   3. EL KIT ES UNA PRECONDICIÓN: su estado se guarda con fecha y nota, y
 *      agendar sin kit confirmado queda dicho en la novedad de AGENDA.
 *
 * El alcance va en el WHERE: un jefe de zona solo toca los ingresos de su
 * zona, y el 403 queda en la bitácora igual que en el buzón.
 */

$u = Auth::exigir('cronograma.editar', true);
Auth::exigirCsrf();

header('Content-Type: application/json; charset=utf-8');

function responder(int $codigo, array $cuerpo): void
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) { responder(400, ['ok' => false, 'error' => 'Se esperaba JSON.']); }

try { Db::todos('SELECT 1 FROM ingresos_preventivos LIMIT 1'); }
catch (Throwable $ex) { responder(503, ['ok' => false, 'error' => 'El cronograma todavía no está en la base (migración 009).']); }

$accion = (string) ($in['accion'] ?? '');
$za = Auth::zonaAlcance();
$hoy = (new DateTimeImmutable('now', new DateTimeZone('America/Guayaquil')))->format('Y-m-d');
$ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];

$fecha = static function ($v): ?string {
    $s = trim((string) ($v ?? ''));
    if ($s === '') { return null; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return ($d !== false && $d->format('Y-m-d') === $s) ? $s : false;
};

/** El ingreso, solo si está en el alcance de quien pregunta. */
function ingreso(int $id, ?string $za): ?array
{
    if ($id <= 0) { return null; }
    $sql = 'SELECT * FROM ingresos_preventivos WHERE ingreso_id = ?';
    $par = [$id];
    if ($za !== null) { $sql .= ' AND zona = ?'; $par[] = $za !== '' ? $za : '-'; }
    return Db::uno($sql, $par);
}

function novedad(int $ingresoId, string $tipo, ?string $motivo, ?string $detalle, ?string $antes, ?string $despues, int $por): void
{
    Db::ejecutar(
        'INSERT INTO cronograma_novedades (ingreso_id, tipo, motivo, detalle, fecha_antes, fecha_despues, por)
         VALUES (?,?,?,?,?,?,?)',
        [$ingresoId, $tipo, $motivo !== null ? mb_substr($motivo, 0, 120) : null,
         $detalle !== null && $detalle !== '' ? mb_substr($detalle, 0, 600) : null, $antes, $despues, $por]
    );
}

function denegado(string $accion, string $ref, string $motivo): void
{
    Auth::bitacora('DENEGADO', 'cronograma', $ref, $accion . ': ' . $motivo, null, null, [], false);
    responder(403, ['ok' => false, 'error' => 'Ese ingreso no existe o no está en tu alcance.']);
}

$uid = (int) $u['usuario_id'];
$nota = trim((string) ($in['nota'] ?? $in['detalle'] ?? ''));

if ($accion === 'kit') {
    $i = ingreso((int) ($in['ingreso_id'] ?? 0), $za);
    if (!$i) { denegado('kit', (string) ($in['ingreso_id'] ?? ''), 'fuera de alcance'); }
    $estado = strtoupper((string) ($in['estado'] ?? 'CONFIRMADO'));
    if (!in_array($estado, ['SIN_KIT', 'SOLICITADO', 'CONFIRMADO', 'ENTREGADO'], true)) {
        responder(400, ['ok' => false, 'error' => 'Estado del kit no válido.']);
    }
    $f = $fecha($in['fecha'] ?? $hoy);
    if ($f === false) { responder(400, ['ok' => false, 'error' => 'La fecha del kit no es una fecha.']); }
    Db::ejecutar('UPDATE ingresos_preventivos SET kit_estado = ?, kit_fecha = ?, kit_nota = NULLIF(?, ""),
                         actualizado_por = ?, actualizado_en = NOW() WHERE ingreso_id = ?',
                 [$estado, $f, mb_substr($nota, 0, 300), $uid, (int) $i['ingreso_id']]);
    novedad((int) $i['ingreso_id'], 'KIT', 'Kit ' . strtolower(str_replace('_', ' ', $estado)), $nota, null, $f, $uid);
    Auth::bitacora('CRONOGRAMA_KIT', 'cronograma', (string) $i['ingreso_id'],
                   $i['local_codigo'] . ' ingreso ' . $i['numero'] . ': kit ' . strtolower($estado),
                   $i['kit_estado'], $estado, ['local' => $i['local_codigo'], 'fecha' => $f]);
    responder(200, ['ok' => true, 'mensaje' => 'Kit ' . strtolower(str_replace('_', ' ', $estado)) . ' para ' . $i['local_codigo'] . '.']);
}

if ($accion === 'reagendar') {
    $i = ingreso((int) ($in['ingreso_id'] ?? 0), $za);
    if (!$i) { denegado('reagendar', (string) ($in['ingreso_id'] ?? ''), 'fuera de alcance'); }
    $ini = $fecha($in['inicio'] ?? null);
    $fin = $fecha($in['fin'] ?? null) ?? $ini;
    if ($ini === false || $fin === false || $ini === null) { responder(400, ['ok' => false, 'error' => 'Hace falta la fecha de inicio (AAAA-MM-DD).']); }
    if ($fin < $ini) { responder(400, ['ok' => false, 'error' => 'El fin no puede ser anterior al inicio.']); }
    $motivo = trim((string) ($in['motivo'] ?? ''));
    if ($motivo === '') {
        // Regla 2: sin motivo no se mueve. Es lo que se le explica a KFC.
        responder(400, ['ok' => false, 'error' => 'El motivo es obligatorio: es lo que se le reporta a Grupo KFC.']);
    }
    if ($i['estado'] === 'CUMPLIDO') { responder(409, ['ok' => false, 'error' => 'Ese ingreso ya está cumplido: no se reagenda.']); }
    $antes = $i['plan_vigente_inicio'];
    // La primera fecha de un ingreso sin plan también fija el plan original:
    // a partir de ahí, lo acordado no se toca.
    Db::ejecutar('UPDATE ingresos_preventivos
                     SET plan_vigente_inicio = ?, plan_vigente_fin = ?,
                         plan_original_inicio = COALESCE(plan_original_inicio, ?),
                         plan_original_fin    = COALESCE(plan_original_fin, ?),
                         estado = IF(estado IN ("VENCIDO","PLANIFICADO"), "PLANIFICADO", estado),
                         actualizado_por = ?, actualizado_en = NOW()
                   WHERE ingreso_id = ?',
                 [$ini, $fin, $ini, $fin, $uid, (int) $i['ingreso_id']]);
    novedad((int) $i['ingreso_id'], 'REAGENDA', $motivo, $nota, $antes, $ini, $uid);
    Auth::bitacora('CRONOGRAMA_REAGENDA', 'cronograma', (string) $i['ingreso_id'],
                   $i['local_codigo'] . ' ingreso ' . $i['numero'] . ': ' . ($antes ?? 'sin fecha') . ' → ' . $ini . ' — ' . $motivo,
                   $antes, $ini, ['local' => $i['local_codigo'], 'motivo' => $motivo, 'fin' => $fin,
                                  'plan_original' => $i['plan_original_inicio']]);
    responder(200, ['ok' => true, 'mensaje' => 'Reagendado ' . $i['local_codigo'] . ' al ' . $ini . '. La novedad queda para el reporte a KFC.']);
}

if ($accion === 'cerrar') {
    $i = ingreso((int) ($in['ingreso_id'] ?? 0), $za);
    if (!$i) { denegado('cerrar', (string) ($in['ingreso_id'] ?? ''), 'fuera de alcance'); }
    $rIni = $fecha($in['real_inicio'] ?? ($i['real_inicio'] ?? $hoy));
    $rFin = $fecha($in['real_fin'] ?? $hoy);
    if ($rIni === false || $rFin === false) { responder(400, ['ok' => false, 'error' => 'Las fechas reales no son fechas.']); }
    if ($rFin < $rIni) { responder(400, ['ok' => false, 'error' => 'El fin real no puede ser anterior al inicio real.']); }
    $ot = strtoupper(trim((string) ($in['ot'] ?? '')));
    $ots = json_decode((string) ($i['ot_ids'] ?? ''), true);
    $ots = is_array($ots) ? $ots : [];
    if ($ot !== '' && !in_array($ot, $ots, true)) { $ots[] = mb_substr($ot, 0, 60); }
    Db::ejecutar('UPDATE ingresos_preventivos
                     SET real_inicio = ?, real_fin = ?, estado = "CUMPLIDO", ot_ids = ?,
                         actualizado_por = ?, actualizado_en = NOW()
                   WHERE ingreso_id = ?',
                 [$rIni, $rFin, $ots ? json_encode($ots, JSON_UNESCAPED_UNICODE) : null, $uid, (int) $i['ingreso_id']]);
    $aTiempo = $i['plan_original_fin'] === null || $rFin <= $i['plan_original_fin'];
    novedad((int) $i['ingreso_id'], 'CIERRE', $aTiempo ? 'Cumplido a tiempo' : 'Cumplido tarde', $nota, $i['plan_original_fin'], $rFin, $uid);
    Auth::bitacora('CRONOGRAMA_CIERRE', 'cronograma', (string) $i['ingreso_id'],
                   $i['local_codigo'] . ' ingreso ' . $i['numero'] . ' cumplido el ' . $rFin . ($aTiempo ? ' (a tiempo)' : ' (tarde)'),
                   $i['estado'], 'CUMPLIDO', ['local' => $i['local_codigo'], 'ot' => $ot ?: null, 'a_tiempo' => $aTiempo]);
    responder(200, ['ok' => true, 'mensaje' => 'Ingreso cumplido' . ($aTiempo ? ' a tiempo.' : ', fuera del plan original: queda dicho para KFC.')]);
}

if ($accion === 'agendar') {
    $local = strtoupper(trim((string) ($in['local'] ?? '')));
    $numero = (int) ($in['numero'] ?? 0);
    $anio = (int) ($in['anio'] ?? substr($hoy, 0, 4));
    $ini = $fecha($in['inicio'] ?? null);
    $fin = $fecha($in['fin'] ?? null) ?? $ini;
    if ($local === '' || $numero < 1 || $numero > 4) { responder(400, ['ok' => false, 'error' => 'Hace falta el local y cuál ingreso del año (1 a 4).']); }
    if ($ini === false || $fin === false || $ini === null) { responder(400, ['ok' => false, 'error' => 'Hace falta la fecha de inicio.']); }
    if ($fin < $ini) { responder(400, ['ok' => false, 'error' => 'El fin no puede ser anterior al inicio.']); }
    // La zona sale del maestro del local, nunca del POST (misma regla que las órdenes).
    $zona = null;
    foreach ((Catalogo::cargar()['locales'] ?? []) as $l) {
        if (strtoupper((string) ($l['codigo'] ?? '')) === $local) { $zona = strtoupper((string) ($l['zona'] ?? '')) ?: null; break; }
    }
    if ($zona === null) { responder(400, ['ok' => false, 'error' => 'Ese local no está en el maestro.']); }
    if (!in_array($zona, $ZONAS, true)) { $zona = 'OTRA'; }
    if ($za !== null && $zona !== $za) { denegado('agendar', $local, 'local de otra zona'); }
    $kit = !empty($in['kit_confirmado']);
    $previo = Db::uno('SELECT * FROM ingresos_preventivos WHERE local_codigo = ? AND anio = ? AND numero = ?', [$local, $anio, $numero]);
    if ($previo && $previo['plan_vigente_inicio'] !== null) {
        responder(409, ['ok' => false, 'error' => 'Ese ingreso ya está agendado para el ' . $previo['plan_vigente_inicio'] . '. Usa «reagendar».']);
    }
    Db::ejecutar('INSERT INTO ingresos_preventivos
                     (local_codigo, zona, anio, numero, plan_original_inicio, plan_original_fin,
                      plan_vigente_inicio, plan_vigente_fin, kit_estado, kit_fecha, estado, actualizado_por, actualizado_en)
                  VALUES (?,?,?,?,?,?,?,?,?,?,"PLANIFICADO",?,NOW())
                  ON DUPLICATE KEY UPDATE
                     plan_original_inicio = COALESCE(plan_original_inicio, VALUES(plan_original_inicio)),
                     plan_original_fin    = COALESCE(plan_original_fin, VALUES(plan_original_fin)),
                     plan_vigente_inicio  = VALUES(plan_vigente_inicio), plan_vigente_fin = VALUES(plan_vigente_fin),
                     kit_estado = IF(kit_estado = "SIN_KIT", VALUES(kit_estado), kit_estado),
                     kit_fecha  = COALESCE(kit_fecha, VALUES(kit_fecha)),
                     estado = "PLANIFICADO", actualizado_por = VALUES(actualizado_por), actualizado_en = NOW()',
                 [$local, $zona, $anio, $numero, $ini, $fin, $ini, $fin,
                  $kit ? 'CONFIRMADO' : 'SIN_KIT', $kit ? $hoy : null, $uid]);
    $i = Db::uno('SELECT * FROM ingresos_preventivos WHERE local_codigo = ? AND anio = ? AND numero = ?', [$local, $anio, $numero]);
    novedad((int) $i['ingreso_id'], 'AGENDA', $kit ? 'Agendado con kit confirmado' : 'Agendado sin kit confirmado', $nota, null, $ini, $uid);
    Auth::bitacora('CRONOGRAMA_AGENDA', 'cronograma', (string) $i['ingreso_id'],
                   $local . ' ingreso ' . $numero . ' de ' . $anio . ': ' . $ini . ' a ' . $fin . ($kit ? '' : ' (sin kit)'),
                   null, 'PLANIFICADO', ['local' => $local, 'zona' => $zona, 'kit' => $kit]);
    responder(200, ['ok' => true, 'mensaje' => 'Agendado ' . $local . ' del ' . $ini . ' al ' . $fin . '.', 'ingreso_id' => (int) $i['ingreso_id']]);
}

if ($accion === 'nota') {
    $i = ingreso((int) ($in['ingreso_id'] ?? 0), $za);
    if (!$i) { denegado('nota', (string) ($in['ingreso_id'] ?? ''), 'fuera de alcance'); }
    if ($nota === '') { responder(400, ['ok' => false, 'error' => 'Escribe la novedad.']); }
    $tipo = trim((string) ($in['tipo'] ?? 'Otro'));
    novedad((int) $i['ingreso_id'], 'NOTA', $tipo, $nota, null, null, $uid);
    Db::ejecutar('UPDATE ingresos_preventivos SET actualizado_por = ?, actualizado_en = NOW() WHERE ingreso_id = ?', [$uid, (int) $i['ingreso_id']]);
    Auth::bitacora('CRONOGRAMA_NOTA', 'cronograma', (string) $i['ingreso_id'],
                   $i['local_codigo'] . ' ingreso ' . $i['numero'] . ': ' . $tipo . ' — ' . mb_substr($nota, 0, 100),
                   null, null, ['local' => $i['local_codigo'], 'tipo' => $tipo]);
    responder(200, ['ok' => true, 'mensaje' => 'Novedad registrada.']);
}

responder(400, ['ok' => false, 'error' => 'Acción no reconocida.']);
