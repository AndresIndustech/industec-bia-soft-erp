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

/* Se lee el flash pero NO se borra el exito: `Ui::pie()` lo convierte en aviso
   efimero al cerrar la pagina. El error si se consume aqui, porque se pinta
   aqui. */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']['error']);

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

require_once __DIR__ . '/nucleo/Ui.php';

Ui::cabecera($u, 'asignacion.php',
    ['asignar' => $sinAsignar ? ['n' => count($sinAsignar), 'tono' => 'ojo'] : null,
     'casos'   => $sinAsignar ? ['n' => count($sinAsignar)] : null],
    ['titulo' => 'Asignación']);
?>
<div class="wrap ancho">

  <div class="titulo entra">
    <h1>Asignación</h1>
    <p class="sub">
      Cómo está repartido el trabajo y qué falta por repartir. Es la otra mitad
      del buzón: allí se mira caso por caso, aquí se mira a quién le cabe uno más.
    </p>
  </div>

  <?php /* El exito sale como aviso efimero desde `Ui::pie()`; el error se queda
           fijo, porque hay que leerlo y corregir algo. */ ?>
  <?php if (!empty($flash['error'])): ?><?= Ui::aviso('err', e($flash['error']), true) ?><?php endif; ?>

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
              <span class="desc"><?= e($c['local_nombre'] ?? '') ?></span>
              <span class="desc"><?= Ui::zona($c['zona'] ?? null) ?></span>
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
            <td><?= Ui::prioridad($c['prioridad'] ?? null) ?></td>
            <td>
              <span class="mono"><?= e($c['fecha_creacion'] ?? '—') ?></span>
              <?php /* La antiguedad al lado de la fecha: a los 7 dias sin
                       informe el caso se cierra solo por falta de atencion, y
                       eso hay que verlo venir, no descubrirlo despues. */ ?>
              <span class="desc"><?= Ui::edad($c['fecha_creacion'] ?? null) ?></span>
            </td>
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

<?php Ui::pie(['novedades' => true]); ?>
