<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Ui.php';
require_once __DIR__ . '/nucleo/Vocabulario.php';   // los rótulos de las cifras y de las listas

/**
 * asignacion.php — Repartir el trabajo, mirando la carga de cada uno.
 *
 * EN QUE SE DIFERENCIA DEL BUZON
 * El buzón se mira caso por caso: «qué pasa con este aviso». Esta pantalla se
 * mira al revés: «cómo está repartido el trabajo y a quién le cabe uno más».
 * Son dos preguntas distintas y por eso son dos pantallas; meterlas en una deja
 * una tabla que no responde bien ninguna de las dos.
 *
 * POR ZONA, NO EN UNA SOLA REJILLA (ASG-07, ASG-08, DOC-03).
 * Andrés probó el sitio el 12-sep-2026 y lo dijo con todas las letras: «los
 * técnicos salen todos mezclados y amontonados». La administradora responde
 * por las tres zonas a la vez, pero cada zona es un equipo y un «por repartir»
 * distintos; sumarlos en una sola rejilla no deja ver cómo está cada una. Cada
 * zona tiene aquí su propio bloque, con sus cuatro cifras, su «Por repartir»
 * inmediatamente debajo y su equipo. El jefe de zona ve un solo bloque, porque
 * su alcance ya lo limita a una zona (`Casos::tecnicosAsignables()` filtra por
 * `Auth::zonaAlcance()` en el servidor).
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

// --- El filtro de zona. El alcance manda: a un jefe de zona el filtro solo
//     puede estrechar lo que ya ve, nunca ampliarlo (regla 1: se filtra en el
//     servidor, no se confía en lo que llegue por GET). --------------------
$zonaAlc = Auth::zonaAlcance();
$ZONAS_VALIDAS = ['UIO', 'LARB', 'CNLJ'];
$fz = (string) ($_GET['zona'] ?? '');
if ($zonaAlc !== null) {
    $fz = $zonaAlc;               // el jefe no elige: ya está en su zona
} elseif ($fz !== '' && $fz !== 'SIN' && !in_array($fz, $ZONAS_VALIDAS, true)) {
    $fz = '';                      // valor inventado en la URL: se ignora
}

// --- La carga de cada técnico, contando solo lo que sigue vivo -------------
// ESPERA_REPUESTO cuenta como abierto (Casos::ABIERTOS_TECNICO): el técnico
// tiene el equipo trabado en su bandeja aunque no dependa de él (ASG-11). Y se
// marca aparte cuántos ASIGNADO llevan 3+ días sin informe, para verlo venir
// antes de que el caso se cierre solo por falta de atención (ASG-15).
$carga = [];
foreach ($tecnicos as $t) {
    $carga[(int) $t['usuario_id']] = ['t' => $t, 'abiertos' => 0, 'atendidos' => 0,
                                       'espera' => 0, 'viejos' => 0];
}
foreach ($gestion as $g) {
    $id = (int) ($g['asignado_a'] ?? 0);
    if ($id === 0 || !isset($carga[$id])) { continue; }
    if (in_array($g['estado'], Casos::ABIERTOS_TECNICO, true)) {
        $carga[$id]['abiertos']++;
        if ($g['estado'] === 'ESPERA_REPUESTO') {
            $carga[$id]['espera']++;
        } else {
            $dias = Ui::dias(substr((string) ($g['asignado_en'] ?? ''), 0, 10) ?: null);
            if ($dias !== null && $dias >= 3) { $carga[$id]['viejos']++; }
        }
    } elseif (in_array($g['estado'], ['ATENDIDO', 'RESUELTO'], true)) {
        $carga[$id]['atendidos']++;
    }
}
uasort($carga, fn($a, $b) => [$b['abiertos'], $a['t']['nombre']] <=> [$a['abiertos'], $b['t']['nombre']]);

/** Las iniciales del avatar: primera palabra y última. Es un ancla visual para
 *  recorrer veinte tarjetas, no un dato — por eso va `aria-hidden`. */
function iniciales(string $nombre): string
{
    $p = array_values(array_filter(preg_split('/\s+/u', trim($nombre)) ?: []));
    if (!$p) { return '?'; }
    return mb_strtoupper(mb_substr($p[0], 0, 1, 'UTF-8')
         . (count($p) > 1 ? mb_substr($p[count($p) - 1], 0, 1, 'UTF-8') : ''), 'UTF-8');
}

// --- Lo que falta repartir (ASG-12). Incluye los NUEVO con informe abierto de
//     usuario desconocido, marcados `informe_sin_usuario` (cabecera de la ola 2).
//     OJO (verificación de la ola 2 del vocabulario, 26-sep-2026): desde la
//     tarjeta por zona, el panel y el buzón cuentan «sin técnico» (NUEVO o
//     EN_REVISION sin asignado_a), y esto cuenta todo EN_REVISION. Unificarlo es
//     lógica pendiente de Andrés; mientras, los rótulos de aquí dicen lo que cuentan. ----
$sinAsignarTodo = Casos::sinAsignar($mios, $gestion, $aten);

// --- Todo agrupado por zona --------------------------------------------
$porZonaCarga = ['UIO' => [], 'LARB' => [], 'CNLJ' => []];
foreach ($carga as $id => $c) {
    $z = (string) ($c['t']['zona'] ?? '');
    $porZonaCarga[$z][$id] = $c;   // conserva el orden del uasort de arriba
}
$porZonaSin = [];
foreach ($sinAsignarTodo as $s) {
    $z = (string) ($s['c']['zona'] ?? '');
    $porZonaSin[$z][] = $s;        // conserva el orden de Casos::sinAsignar()
}

if ($zonaAlc !== null) {
    $zonasMostrar = [$zonaAlc];
} elseif ($fz === 'SIN') {
    $zonasMostrar = [''];
} elseif ($fz !== '') {
    $zonasMostrar = [$fz];
} else {
    $zonasMostrar = $ZONAS_VALIDAS;
    if (!empty($porZonaSin[''])) { $zonasMostrar[] = ''; }   // solo si hay algo que ver
}

$totalSinAsignar = count($sinAsignarTodo);
$totalLibres   = count(array_filter($carga, fn($c) => $c['abiertos'] === 0));
$totalCargados = count(array_filter($carga, fn($c) => $c['abiertos'] >= 8));
$totalEnManos  = array_sum(array_map(fn($c) => $c['abiertos'], $carga));

require_once __DIR__ . '/nucleo/Ui.php';

Ui::cabecera($u, 'asignacion.php',
    ['asignar' => $totalSinAsignar ? ['n' => $totalSinAsignar, 'tono' => 'ojo'] : null,
     'casos'   => $totalSinAsignar ? ['n' => $totalSinAsignar] : null],
    ['titulo' => 'Asignación']);
?>
<div class="wrap ancho">

  <div class="titulo entra">
    <h1>Asignación</h1>
    <p class="sub">
      Cómo está repartido el trabajo y qué órdenes siguen sin asignar, zona
      por zona. Es la otra mitad del buzón: allí se mira orden por orden, aquí
      a quién le cabe una más.
    </p>
  </div>

  <?php /* El exito sale como aviso efimero desde `Ui::pie()`; el error se queda
           fijo, porque hay que leerlo y corregir algo. */ ?>
  <?php if (!empty($flash['error'])): ?><?= Ui::aviso('err', e($flash['error']), true) ?><?php endif; ?>

  <?php if ($zonaAlc === null): ?>
    <p class="sub" style="margin:0 0 14px">
      Ver:
      <a href="asignacion.php" class="<?= $fz === '' ? 'chip' : '' ?>">Las tres zonas</a>
      <?php foreach ($ZONAS_VALIDAS as $zv): ?>
        · <a href="asignacion.php?zona=<?= $zv ?>" class="<?= $fz === $zv ? 'chip' : '' ?>"><?= e(Ui::zonaCorta($zv)) ?></a>
      <?php endforeach; ?>
      <?php if (!empty($porZonaSin[''])): ?>
        · <a href="asignacion.php?zona=SIN" class="<?= $fz === 'SIN' ? 'chip' : '' ?>">Sin zona</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php /* Las cuatro cifras del conjunto, para no perder el total de vista
           cuando hay varias zonas. Con una sola a la vista (el jefe, o el
           admin filtrando) se omiten: repetirían, número por número, las del
           bloque de esa zona, que es lo que se veía en la captura del 13-sep. */ ?>
  <?php if (count($zonasMostrar) > 1): ?>
  <div class="tiles">
    <div class="tile <?= $totalSinAsignar ? 'ambar' : 'verde' ?>">
      <div class="n"><?= $totalSinAsignar ?></div>
      <div class="t"><?= e(Vocabulario::titulo('SIN_ASIGNAR')) ?></div>
      <?php /* Casos::sinAsignar() cuenta NUEVO y EN_REVISION (esta con o sin técnico) de
               TODAS las zonas, OTRA y «sin zona» incluidas: el pie dice eso, no «tres». Que
               la cifra no calce con el «sin asignar» del panel (que cuenta solo lo que no
               tiene técnico) es una diferencia de lógica pendiente de Andrés. */ ?>
      <div class="pie"><?= $zonaAlc === null && $fz === '' ? 'en todas las zonas' : 'sin técnico o ' . e(Vocabulario::t('EN_REVISION')) ?></div>
    </div>
    <div class="tile <?= $totalLibres ? 'verde' : '' ?>">
      <div class="n"><?= $totalLibres ?></div>
      <div class="t">Sin órdenes asignadas</div>
      <div class="pie">de <?= count($carga) ?> en el equipo</div>
    </div>
    <div class="tile <?= $totalCargados ? 'ambar' : '' ?>">
      <div class="n"><?= $totalCargados ?></div>
      <div class="t">Con 8 o más</div>
      <div class="pie">piénsalo antes de darle otra</div>
    </div>
    <div class="tile azul">
      <div class="n"><?= $totalEnManos ?></div>
      <?php /* Suma ASIGNADO + ESPERA_REPUESTO (Casos::ABIERTOS_TECNICO): el título lo
               dice entero, porque «Asignadas» a secas es solo ASIGNADO en el buzón. */ ?>
      <div class="t"><?= e(Vocabulario::titulo('ASIGNADA') . ' y ' . Vocabulario::t('ESPERA_REPUESTO')) ?></div>
      <div class="pie">de todo el equipo</div>
    </div>
  </div>
  <?php endif; ?>

  <?php foreach ($zonasMostrar as $z): ?>
    <?php
    $zc = $porZonaCarga[$z] ?? [];
    $zs = $porZonaSin[$z] ?? [];
    $zLibres   = count(array_filter($zc, fn($c) => $c['abiertos'] === 0));
    $zCargados = count(array_filter($zc, fn($c) => $c['abiertos'] >= 8));
    $zEnManos  = array_sum(array_map(fn($c) => $c['abiertos'], $zc));
    $zTope     = max(1, ...array_map(fn($c) => $c['abiertos'], $zc ?: [['abiertos' => 0]]));
    $cl = strtolower($z ?: 'otra');
    $idZona = $z ?: 'SIN';
    // Con una sola zona a la vista (jefe, o admin filtrando) el bloque va
    // siempre desplegado; con las tres, solo la que se pidió por `?zona=`
    // (ASG-07): las otras quedan plegadas pero no desaparecen.
    $abierto = $zonaAlc !== null || $fz === '' || $fz === $z || ($fz === 'SIN' && $z === '');
    ?>
    <details class="zona-bloque zona-<?= e($cl) ?>" id="zona-<?= e($idZona) ?>" <?= $abierto ? 'open' : '' ?>>
      <summary>
        <h2><?= Ui::zona($z ?: null) ?> <span class="cuenta"><?= count($zc) ?> técnico<?= count($zc) === 1 ? '' : 's' ?></span></h2>
      </summary>

      <div class="tiles">
        <div class="tile <?= $zs ? 'ambar' : 'verde' ?>">
          <div class="n"><?= count($zs) ?></div>
          <div class="t"><?= e(Vocabulario::titulo('SIN_ASIGNAR')) ?></div>
          <div class="pie">sin técnico o <?= e(Vocabulario::t('EN_REVISION')) ?></div>
        </div>
        <div class="tile <?= $zLibres ? 'verde' : '' ?>">
          <div class="n"><?= $zLibres ?></div>
          <div class="t">Sin órdenes asignadas</div>
          <div class="pie">de <?= count($zc) ?> en el equipo</div>
        </div>
        <div class="tile <?= $zCargados ? 'ambar' : '' ?>">
          <div class="n"><?= $zCargados ?></div>
          <div class="t">Con 8 o más</div>
          <div class="pie">piénsalo antes de darle otra</div>
        </div>
        <div class="tile azul">
          <div class="n"><?= $zEnManos ?></div>
          <div class="t"><?= e(Vocabulario::titulo('ASIGNADA') . ' y ' . Vocabulario::t('ESPERA_REPUESTO')) ?></div>
          <div class="pie">de los técnicos de la zona</div>
        </div>
      </div>

      <h3 id="sin-asignar-<?= e($idZona) ?>" style="margin:14px 0 6px"><?= e(Vocabulario::titulo('SIN_ASIGNAR')) ?> (<?= count($zs) ?>)</h3>
      <p class="sub" style="margin:0 0 10px">
        <?php /* Sin «a espera de informe técnico»: Casos::sinAsignar() también trae las
                 órdenes en revisión que ya tienen técnico y OT INDUSTEC (llevan su marca). */ ?>
        Órdenes <?= e(Vocabulario::t('SIN_ASIGNAR')) ?> o <?= e(Vocabulario::t('EN_REVISION')) ?><?= $z === '' ? ', sin una zona resuelta contra el maestro de locales' : ', de ' . e(Ui::nombreZona($z)) ?>.
        Las que llevaban más de una semana sin ninguna OT INDUSTEC ya están
        <?= e(Vocabulario::t('CERRADA_SIN_ATENCION', 2)) ?>, en el
        <a href="casos.php?est=CERRADO_SIN_ATENCION">buzón</a>, a espera de que la
        administración las regularice ante KFC: no se asignan desde aquí.
      </p>

      <?php /* `data-th` en cada celda: en celular (≤640 px) la tabla pasa a
               tarjetas y el CSS pinta esa etiqueta delante de cada valor. */ ?>
      <div class="tabla-wrap">
        <table class="repartir">
          <thead><tr>
            <th><?= e(Vocabulario::titulo('AVISO_SAP')) ?></th><th>Local</th><th>Qué pide</th><th>Prioridad</th>
            <th>Llegó</th><th>Asignar a</th>
          </tr></thead>
          <tbody>
          <?php if (!$zs): ?>
            <tr><td colspan="6" class="vacio">No queda ninguna orden <?= e(Vocabulario::t('SIN_ASIGNAR')) ?><?= $z !== '' ? ' en ' . e(Ui::nombreZona($z)) : '' ?>.</td></tr>
          <?php endif; ?>
          <?php foreach ($zs as $s): ?>
            <?php
            $c = $s['c'];
            $propios = $z !== '' ? ($porZonaCarga[$z] ?? []) : [];
            $otros   = $z !== '' ? array_diff_key($carga, $propios) : $carga;
            ?>
            <tr>
              <td data-th="<?= e(Vocabulario::titulo('AVISO_SAP')) ?>">
                <span class="mono"><?= e($c['aviso'] ?? '—') ?></span>
                <?php if ($s['estado'] === 'EN_REVISION'): ?>
                  <span class="chip"><?= e(Vocabulario::t('EN_REVISION')) ?></span>
                  <span class="desc"><?= e((string) $s['revision']) ?></span>
                <?php endif; ?>
                <?php if (!empty($s['informe_sin_usuario'])): ?>
                  <span class="chip" title="Hay una OT INDUSTEC de cierre, pero la firma no cruzó con nadie del padrón"><?= e(Vocabulario::t('OT_CIERRE')) ?> con firma no reconocida</span>
                <?php endif; ?>
              </td>
              <td data-th="Local">
                <b><?= e($c['local'] ?? '—') ?></b>
                <span class="desc"><?= e($c['local_nombre'] ?? '') ?></span>
                <?php if ($z === ''): ?><span class="desc"><?= Ui::zona($c['zona'] ?? null) ?></span><?php endif; ?>
              </td>
              <td data-th="Qué pide">
                <?= e($c['caso'] ?? '') ?>
                <?php if (!empty($c['activo_fijo'])): ?>
                  <span class="desc"><?= e($c['activo_fijo']) ?></span>
                <?php endif; ?>
                <?php if (!empty($c['descripcion_trabajo'])): ?>
                  <span class="desc"><?= e(mb_strimwidth((string) $c['descripcion_trabajo'], 0, 120, '…', 'UTF-8')) ?></span>
                <?php endif; ?>
              </td>
              <td data-th="Prioridad"><?= Ui::prioridad($c['prioridad'] ?? null) ?></td>
              <td data-th="Llegó">
                <span class="mono sin-cortar"><?= e($c['fecha_creacion'] ?? '—') ?></span>
                <?php /* La antiguedad al lado de la fecha: a los 7 dias sin
                         informe el caso se cierra solo por falta de atencion, y
                         eso hay que verlo venir, no descubrirlo despues. */ ?>
                <span class="desc"><?= Ui::edad($c['fecha_creacion'] ?? null) ?></span>
              </td>
              <td data-th="Asignar a">
                <form method="post" action="casos.php" class="asignar">
                  <input type="hidden" name="csrf" value="<?= e(Auth::csrfToken()) ?>">
                  <input type="hidden" name="accion" value="asignar">
                  <input type="hidden" name="aviso" value="<?= e($c['aviso'] ?? '') ?>">
                  <input type="hidden" name="volver" value="asignacion.php<?= $fz !== '' ? '?zona=' . e($fz) : '' ?>#sin-asignar-<?= e($idZona) ?>">
                  <select name="tecnico" required class="sel-tec" data-zona="<?= e((string) ($c['zona'] ?? '')) ?>">
                    <option value="">Elige…</option>
                    <?php foreach ($propios as $x): ?>
                      <option value="<?= (int) $x['t']['usuario_id'] ?>" data-zona="<?= e((string) $x['t']['zona']) ?>">
                        <?= e($x['t']['nombre']) ?> (<?= $x['abiertos'] ?>)
                      </option>
                    <?php endforeach; ?>
                    <?php if ($otros): ?>
                      <optgroup label="Otras zonas (confirmar)">
                        <?php foreach ($otros as $x): ?>
                          <option value="<?= (int) $x['t']['usuario_id'] ?>" data-zona="<?= e((string) $x['t']['zona']) ?>">
                            <?= e($x['t']['nombre']) ?> · <?= e(Ui::zonaCorta((string) $x['t']['zona'])) ?> (<?= $x['abiertos'] ?>)
                          </option>
                        <?php endforeach; ?>
                      </optgroup>
                    <?php endif; ?>
                  </select>
                  <label class="chk-otra-zona" hidden>
                    <input type="checkbox" name="confirmo_zona" value="1"> otra zona, confirmo
                  </label>
                  <button class="btn primary" type="submit">Asignar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <h3 style="margin:20px 0 10px">El equipo (<?= count($zc) ?>)</h3>
      <?php if ($zc): ?>
      <p class="sub" style="margin:0 0 10px">
        «Asignadas» es lo que tiene entre manos, contando las órdenes
        <?= e(Vocabulario::t('ESPERA_REPUESTO')) ?>. «OT de cierre» son las
        órdenes a las que ya les emitió su OT INDUSTEC de cierre (atendidas, por
        cerrar en SAP, o ya cerradas en SAP), contando las que el sistema le
        reconoció por la firma. La barra compara contra el más cargado de
        <?= e($z !== '' ? Ui::nombreZona($z) : 'esta lista') ?><?= $zTope > 1 ? ', que lleva ' . $zTope : '' ?>.
      </p>
      <div class="equipo escalona">
        <?php foreach (array_values($zc) as $i => $c): ?>
          <?php $cargadoP = $c['abiertos'] >= 8; $libreP = $c['abiertos'] === 0; ?>
          <div class="persona <?= $cargadoP ? 'cargado' : ($libreP ? 'libre' : '') ?>" style="--i:<?= $i ?>">
            <div class="quien">
              <span class="ini" aria-hidden="true"><?= e(iniciales((string) $c['t']['nombre'])) ?></span>
              <div class="id">
                <div class="n"><?= e($c['t']['nombre']) ?></div>
                <div class="u">
                  <span class="usr"><?= e($c['t']['usuario']) ?></span>
                </div>
              </div>
            </div>
            <div class="carga">
              <?php /* `aria-hidden`: la barra repite lo que ya dicen las cifras de
                       abajo. Un lector de pantalla no debe oír el dato dos veces. */ ?>
              <div class="barra" aria-hidden="true">
                <i style="width:<?= round($c['abiertos'] / $zTope * 100) ?>%"></i>
              </div>
              <div class="pie">
                <div class="cifras">
                  <?php /* Las palabras del diccionario: «abiertos» y «atendidos»
                           nombraban aquí otras cosas que en la tarjeta por zona.
                           La tercera cifra cuenta ATENDIDO + RESUELTO, así que no
                           es «atendidas» (solo ATENDIDO): es su OT de cierre. */ ?>
                  <div class="cifra"><b><?= $c['abiertos'] ?></b><span><?= e(Vocabulario::t('ASIGNADA', $c['abiertos'])) ?></span></div>
                  <?php if ($c['espera'] > 0): ?>
                    <div class="cifra espera"><b><?= $c['espera'] ?></b><span><?= e(Vocabulario::t('ESPERA_REPUESTO', $c['espera'])) ?></span></div>
                  <?php endif; ?>
                  <div class="cifra"><b><?= $c['atendidos'] ?></b><span>OT de cierre</span></div>
                </div>
                <?php /* La palabra, no solo el color: regla 1 de `estilo.css`. */ ?>
                <?php if ($cargadoP): ?><span class="chip">cargado</span>
                <?php elseif ($libreP): ?><span class="chip">libre</span><?php endif; ?>
              </div>
              <?php if ($c['viejos'] > 0): ?>
                <span class="chip chip-viejo" title="<?= e(Vocabulario::ayuda('ASIGNADA_3D')) ?>">
                  <?= $c['viejos'] ?> <?= e(mb_strtolower(Vocabulario::titulo('ASIGNADA_3D'), 'UTF-8')) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <p class="sub">No hay técnicos activos en esta zona.</p>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>
</div>

<script>
/* Un solo listener para todas las filas de todas las zonas: el select de
   "Asignar a" ofrece primero los técnicos de la zona del caso y, aparte, un
   optgroup "Otras zonas (confirmar)" (ASG-03, D4). Elegir uno de otra zona
   exige marcar la casilla antes de enviar el formulario. */
document.addEventListener('change', function (ev) {
  var sel = ev.target.closest('select.sel-tec');
  if (!sel) { return; }
  var opt = sel.options[sel.selectedIndex];
  var otra = opt && opt.value !== '' && opt.dataset.zona !== sel.dataset.zona;
  var chk = sel.parentElement.querySelector('.chk-otra-zona');
  if (chk) {
    chk.hidden = !otra;
    if (!otra) { chk.querySelector('input').checked = false; }
  }
});
document.addEventListener('submit', function (ev) {
  var form = ev.target.closest('form.asignar');
  if (!form) { return; }
  var chk = form.querySelector('.chk-otra-zona');
  if (chk && !chk.hidden && !chk.querySelector('input').checked) {
    ev.preventDefault();
    alert('Marca "otra zona, confirmo" antes de asignarlo a un técnico de otra zona.');
  }
});
</script>

<?php Ui::pie(['novedades' => true]); ?>
