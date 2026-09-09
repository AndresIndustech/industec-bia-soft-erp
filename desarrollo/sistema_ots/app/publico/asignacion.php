<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Casos.php';

/**
 * asignacion.php — Repartir el trabajo, mirando la carga de cada uno.
 *
 * EN QUE SE DIFERENCIA DEL BUZON
 * El buzón se mira caso por caso: «qué pasa con este aviso». Esta pantalla se
 * mira al revés: «cómo está repartido el trabajo y a quién le cabe uno más».
 * Son dos preguntas distintas y por eso son dos pantallas; meterlas en una deja
 * una tabla que no responde bien ninguna de las dos.
 *
 * LO QUE HAY QUE REPARTIR ES POCO, Y ESO ES EL PUNTO
 * De 914 casos, la mayoría ya se cerró por falta de atención o ya tiene orden.
 * Lo que queda sin asignar y todavía es reciente es lo que cabe en un día de
 * trabajo. Mostrar los 914 haría que lo urgente se pierda entre lo viejo.
 *
 * ASIGNA POR POST A `casos.php`, que es donde vive la validación. Duplicar aquí
 * el `UPDATE` sería duplicar también las tres comprobaciones de permiso,
 * alcance y dato, y la copia se quedaría atrás el día que cambie una.
 */

$u = Auth::exigir('casos.asignar');
if ($u['debe_cambiar_clave']) { header('Location: clave.php'); exit; }

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$gestion  = Casos::gestion();
$catalogo = Casos::catalogo()['datos'] ?? [];
$mios     = Casos::enAlcance($catalogo, $gestion);
$aten     = Casos::atenciones();
$tecnicos = Casos::tecnicosAsignables();
$hoy      = date('Y-m-d');

// --- La carga de cada técnico, contando solo lo que sigue vivo -------------
$carga = [];
foreach ($tecnicos as $t) {
    $carga[(int) $t['usuario_id']] = ['t' => $t, 'abiertos' => 0, 'atendidos' => 0];
}
foreach ($gestion as $g) {
    $id = (int) ($g['asignado_a'] ?? 0);
    if ($id === 0 || !isset($carga[$id])) { continue; }
    if ($g['estado'] === 'ASIGNADO') { $carga[$id]['abiertos']++; }
    elseif (in_array($g['estado'], ['ATENDIDO', 'RESUELTO'], true)) { $carga[$id]['atendidos']++; }
}
uasort($carga, fn($a, $b) => [$b['abiertos'], $a['t']['nombre']] <=> [$a['abiertos'], $b['t']['nombre']]);

// --- Lo que falta repartir -------------------------------------------------
$sinAsignar = [];
foreach ($mios as $c) {
    $aviso = (string) ($c['aviso'] ?? '');
    $g = $gestion[$aviso] ?? null;
    $estado = $g['estado'] ?? 'NUEVO';
    // Un caso cerrado por falta de atención NO se reparte desde aquí: primero
    // hay que regularizarlo, y eso es del buzón. Si apareciera en la lista de
    // trabajo del día volvería a mezclarse lo viejo con lo vivo.
    if (!in_array($estado, ['NUEVO', 'EN_REVISION'], true)) { continue; }
    if (isset($aten[$aviso])) { continue; }        // ya tiene informe
    $sinAsignar[] = ['c' => $c, 'estado' => $estado,
                     'revision' => $g['revision_motivo'] ?? null];
}
usort($sinAsignar, function ($a, $b) {
    $pa = ['ALTA' => 0, 'MEDIA' => 1, 'BAJA' => 2][$a['c']['prioridad'] ?? ''] ?? 3;
    $pb = ['ALTA' => 0, 'MEDIA' => 1, 'BAJA' => 2][$b['c']['prioridad'] ?? ''] ?? 3;
    if ($pa !== $pb) { return $pa <=> $pb; }
    return strcmp((string) ($b['c']['fecha_creacion'] ?? ''), (string) ($a['c']['fecha_creacion'] ?? ''));
});

$ROL = ['SUPERADMIN' => 'Superadministrador', 'ADMIN' => 'Administración',
        'JEFE_ZONA' => 'Jefe de zona', 'TECNICO' => 'Técnico'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Asignación · OTs INDUSTEC</title>
<link rel="stylesheet" href="estilo.css">
<style>
  .barra{ display:flex; justify-content:space-between; align-items:center; gap:12px;
          flex-wrap:wrap; padding:10px 14px; background:#fff;
          border-bottom:1px solid var(--border); position:sticky; top:0; z-index:40; }
  .equipo{ display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
           gap:10px; margin-bottom:22px; }
  .persona{ background:#fff; border:1px solid var(--border); border-radius:10px; padding:11px 13px; }
  .persona .n{ font-weight:700; font-size:13.5px; }
  .persona .u{ font-family:ui-monospace,Menlo,monospace; font-size:11.5px; color:var(--muted); }
  .persona .cifras{ display:flex; gap:14px; margin-top:8px; }
  .persona .cifra b{ display:block; font-size:20px; line-height:1.1; font-variant-numeric:tabular-nums; }
  .persona .cifra span{ font-size:10.5px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  .persona.cargado{ border-color:#fde68a; background:var(--warn-bg); }
  .persona.libre .cifra b{ color:var(--ok); }
  table{ width:100%; border-collapse:collapse; font-size:13px; }
  th{ text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.05em;
      color:var(--muted); padding:8px 9px; border-bottom:1px solid var(--border); background:#fff; }
  td{ padding:9px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
  .mono{ font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12.5px; }
  .desc{ color:var(--muted); font-size:12px; display:block; margin-top:3px; max-width:44ch; }
  .tabla-wrap{ overflow-x:auto; border:1px solid var(--border); border-radius:10px; background:#fff; }
  .asignar{ display:flex; gap:6px; align-items:center; }
  .asignar select{ height:34px; min-width:150px; font-size:12.5px; }
  .asignar .btn{ padding:6px 12px; font-size:12.5px; }
  .ok{ background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:9px;padding:10px 12px;font-size:13px;margin-bottom:14px; }
  .err{ background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:9px;padding:10px 12px;font-size:13px;margin-bottom:14px; }
  .vacio{ padding:28px 14px; text-align:center; color:var(--muted); }
</style>
</head>
<body>

<div class="barra">
  <strong><a href="panel.php" style="text-decoration:none;color:inherit">← Sistema de OTs</a></strong>
  <div style="display:flex;align-items:center;gap:10px;font-size:13px">
    <span style="font-weight:700"><?= e($u['nombre']) ?></span>
    <span class="chip"><?= e($ROL[$u['rol']] ?? $u['rol']) ?><?= Auth::zonaAlcance() ? ' · ' . e((string) Auth::zonaAlcance()) : '' ?></span>
    <a class="btn" href="casos.php">Buzón</a>
    <a class="btn" href="salir.php">Salir</a>
  </div>
</div>

<div class="wrap">
  <div class="card">
    <h1 style="font-size:19px;margin:0 0 4px">Asignación</h1>
    <p class="sub" style="margin:0 0 16px">
      Cómo está repartido el trabajo, y qué falta por repartir.
    </p>

    <?php if (!empty($flash['ok'])): ?><div class="ok"><?= e($flash['ok']) ?></div><?php endif; ?>
    <?php if (!empty($flash['error'])): ?><div class="err"><?= e($flash['error']) ?></div><?php endif; ?>

    <h2>El equipo (<?= count($carga) ?>)</h2>
    <p class="sub" style="margin:0 0 10px">
      «Abiertos» es lo que tiene entre manos. «Atendidos» son las órdenes que ya
      emitió, contando las que el sistema le reconoció por el informe.
    </p>
    <div class="equipo">
      <?php foreach ($carga as $c): ?>
        <div class="persona <?= $c['abiertos'] >= 8 ? 'cargado' : ($c['abiertos'] === 0 ? 'libre' : '') ?>">
          <div class="n"><?= e($c['t']['nombre']) ?></div>
          <div class="u"><?= e($c['t']['usuario']) ?> · <?= e((string) $c['t']['zona']) ?></div>
          <div class="cifras">
            <div class="cifra"><b><?= $c['abiertos'] ?></b><span>abiertos</span></div>
            <div class="cifra"><b><?= $c['atendidos'] ?></b><span>atendidos</span></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <h2>Por repartir (<?= count($sinAsignar) ?>)</h2>
    <p class="sub" style="margin:0 0 10px">
      Casos sin asignar y sin informe. Los que llevaban más de una semana sin que
      nadie los tocara ya se cerraron por falta de atención y están en el
      <a href="casos.php?atn=">buzón</a>, esperando que administración los
      regularice: no se reparten desde aquí.
    </p>

    <div class="tabla-wrap">
      <table>
        <thead><tr>
          <th>Aviso</th><th>Local</th><th>Qué pide</th><th>Prioridad</th>
          <th>Creado</th><th>Asignar a</th>
        </tr></thead>
        <tbody>
        <?php if (!$sinAsignar): ?>
          <tr><td colspan="6" class="vacio">
            No queda nada por repartir<?= Auth::zonaAlcance() ? ' en ' . e((string) Auth::zonaAlcance()) : '' ?>.
          </td></tr>
        <?php endif; ?>
        <?php foreach ($sinAsignar as $s): ?>
          <?php $c = $s['c']; $prio = strtolower((string) ($c['prioridad'] ?? '')); ?>
          <tr>
            <td>
              <span class="mono"><?= e($c['aviso'] ?? '—') ?></span>
              <?php if ($s['estado'] === 'EN_REVISION'): ?>
                <span class="chip">en revisión</span>
                <span class="desc"><?= e((string) $s['revision']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <b><?= e($c['local'] ?? '—') ?></b>
              <span class="desc"><?= e($c['local_nombre'] ?? '') ?> · <?= e($c['zona'] ?? '') ?></span>
            </td>
            <td>
              <?= e($c['caso'] ?? '') ?>
              <?php if (!empty($c['activo_fijo'])): ?>
                <span class="desc"><?= e($c['activo_fijo']) ?></span>
              <?php endif; ?>
              <?php if (!empty($c['descripcion_trabajo'])): ?>
                <span class="desc"><?= e(mb_strimwidth((string) $c['descripcion_trabajo'], 0, 120, '…', 'UTF-8')) ?></span>
              <?php endif; ?>
            </td>
            <td><span class="prio prio-<?= e($prio ?: 'sd') ?>"><?= e($c['prioridad'] ?? 'S/D') ?></span></td>
            <td class="mono"><?= e($c['fecha_creacion'] ?? '—') ?></td>
            <td>
              <form method="post" action="casos.php" class="asignar">
                <input type="hidden" name="accion" value="asignar">
                <input type="hidden" name="aviso" value="<?= e($c['aviso'] ?? '') ?>">
                <select name="tecnico" required>
                  <option value="">Elige…</option>
                  <?php foreach ($carga as $x): ?>
                    <option value="<?= (int) $x['t']['usuario_id'] ?>">
                      <?= e($x['t']['nombre']) ?> (<?= $x['abiertos'] ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <button class="btn primary" type="submit">Asignar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>
