<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Pendientes.php';
require_once __DIR__ . '/nucleo/Ui.php';

/**
 * pendientes.php — Los equipos que quedaron sin concluir, y su reloj.
 *
 * ============================================================================
 * LA PANTALLA MAS IMPORTANTE DEL JEFE DE ZONA
 *
 * Su misión, dicha por la gerencia: que ningún equipo de su zona pase
 * deshabilitado más de 48 horas sin que alguien decida qué se hace. Esta
 * pantalla es esa misión, hecha lista.
 *
 * El orden NO es una preferencia de presentación: es el orden en que hay que
 * atacar el trabajo. Primero lo parado sin veredicto —el reloj corriendo—,
 * después el resto de lo parado, y dentro de cada grupo lo más viejo arriba,
 * que es lo que lleva más tiempo esperando. Ordenarlo por fecha de creación
 * descendente, que es lo natural en una lista, pondría lo recién llegado
 * encima de lo que está a punto de incumplirse.
 *
 * ============================================================================
 * LOS CUATRO VEREDICTOS SON EL PRODUCTO DE ESTA PANTALLA
 *
 *   REPUESTO    se compra la pieza y se instala
 *   REPARACION  el equipo sale a un taller
 *   GARANTIA    se reclama al fabricante o al proveedor
 *   BAJA        no tiene arreglo razonable; se propone a Grupo KFC
 *
 * Se ofrecen como cuatro tarjetas grandes y no como un desplegable, porque son
 * cuatro caminos distintos y el que se elija define todo lo que pasa después.
 * Un `<select>` los hace ver como cuatro variantes de lo mismo.
 *
 * ============================================================================
 * QUIEN PUEDE QUE
 *
 *   TECNICO    ve los suyos e insiste. No decide: él aporta el diagnóstico,
 *              que es la evidencia sobre la que se decide.
 *   JEFE_ZONA  da el veredicto de su zona. Está en el terreno y conoce el
 *              equipo. No gestiona: no compra ni compromete fecha con un
 *              proveedor, ni da de baja un activo del cliente.
 *   ADMIN      todo, en las tres zonas.
 *
 * Todo se revalida en el servidor. Que un botón no se dibuje no protege nada.
 */

$u = Auth::exigir();
if ($u['debe_cambiar_clave']) { header('Location: clave.php'); exit; }
if (!Ui::puedeModulo('repuestos.ver', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO'], $u)) {
    Auth::bitacora('DENEGADO', 'pendientes', '', 'sin permiso', null, null, [], false);
    http_response_code(403);
    exit('No tienes acceso a esta pantalla.');
}

$e = fn(?string $s): string => Ui::e($s);

/* -------------------------------------------------------------------------
   Las acciones. Cada una revalida permiso, alcance y dato en el servidor, y
   responde con redirección para que recargar no la repita.
   ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $id     = (int) ($_POST['pendiente_id'] ?? 0);
    $nota   = (string) ($_POST['nota'] ?? '');
    $prom   = ($_POST['prometido'] ?? '') !== '' ? (string) $_POST['prometido'] : null;

    if ($accion === 'veredicto') {
        [$ok, $msg] = Pendientes::veredicto($id, (string) ($_POST['via'] ?? ''), $nota, $prom);
    } elseif ($accion === 'mover') {
        [$ok, $msg] = Pendientes::mover($id, (string) ($_POST['estado'] ?? ''), $nota, $prom);
    } elseif ($accion === 'responder') {
        [$ok, $msg] = Pendientes::responder($id, $nota);
    } elseif ($accion === 'insistir') {
        [$ok, $msg] = Pendientes::insistir($id, $nota, isset($_POST['urgente']));
    } else {
        [$ok, $msg] = [false, 'Acción no reconocida.'];
    }

    $_SESSION['flash'] = $ok ? ['ok' => $msg] : ['error' => $msg];
    header('Location: pendientes.php?' . (string) ($_SERVER['QUERY_STRING'] ?? ''));
    exit;
}

$grupo = (string) ($_GET['g'] ?? 'abiertos');
$q     = trim((string) ($_GET['q'] ?? ''));
$cont  = Pendientes::contadores();
$lista = Pendientes::lista(['grupo' => $grupo, 'q' => $q]);
$hilos = Pendientes::notasDe(array_map(fn($p) => (int) $p['pendiente_id'], $lista));
$c48   = Pendientes::cumplimiento48();

Auth::bitacora('CONSULTAR', 'pendientes', $grupo, 'visibles=' . count($lista));

$errFlash = Ui::errorFlash();
$esTecnico = $u['rol'] === 'TECNICO';
$puedeVeredicto = Ui::puedeModulo('repuestos.veredicto', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA'], $u);
$puedeGestionar = Ui::puedeModulo('repuestos.gestionar', ['SUPERADMIN', 'ADMIN'], $u);
$puedeInsistir  = Ui::puedeModulo('repuestos.pedir', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO'], $u);

$FILTROS = [
    'vencidos'      => ['Fuera de plazo',   $cont['vencidos'],      'roja'],
    'sin_veredicto' => ['Sin veredicto',    $cont['sin_veredicto'], 'ambar'],
    'abiertos'      => ['Todos los vivos',  $cont['abiertos'],      ''],
    'cerrados'      => ['Ya resueltos',     null,                   ''],
];

Ui::cabecera($u, 'pendientes.php',
    ['repuestos' => $cont['vencidos'] > 0 ? ['n' => $cont['vencidos'], 'tono' => 'urge'] : null],
    ['titulo' => 'Repuestos y equipos']);
?>

<div class="wrap ancho">
  <div class="titulo entra">
    <h1>Repuestos y equipos sin concluir</h1>
    <p class="sub">
      <?php if ($esTecnico): ?>
        Los equipos que dejaste trabados y en qué va cada uno. Desde aquí le
        insistes a la administración, y todo lo que escribas queda con su fecha.
      <?php else: ?>
        En INDUSTEC una intervención concluye el trabajo. Lo que queda aquí es
        la excepción: el equipo que depende de una pieza o de un tercero. Si el
        equipo quedó <b>deshabilitado</b>, hay <b>48 horas</b> para decidir por
        cuál de las cuatro vías va — el plazo mide la decisión, no la reparación
        completa.
      <?php endif; ?>
    </p>
  </div>

  <?php if ($errFlash): ?><?= Ui::aviso('err', $e($errFlash), true) ?><?php endif; ?>

  <?php if (!Pendientes::disponible()): ?>
    <?= Ui::aviso('info',
        '<b>Este módulo todavía no está instalado en la base.</b>'
      . '<p>La migración <span class="mono">007_pendientes_y_captura.sql</span> está escrita '
      . 'y sin aplicar: crea las tablas y cambia el estado del caso, y eso requiere '
      . 'aprobación. Hasta entonces la pantalla existe pero no tiene qué mostrar, y '
      . 'no va a simular datos.</p>') ?>
  <?php else: ?>

    <?php if (!$esTecnico): ?>
      <div class="tiles">
        <div class="tile <?= $c48['vencidos'] ? 'alerta' : '' ?>">
          <div class="n" data-n="<?= $c48['vencidos'] ?>">0</div>
          <div class="t">Fuera de plazo</div>
          <div class="pie">Parados, sin veredicto, más de 48 h</div>
        </div>
        <div class="tile <?= $c48['corriendo'] ? 'vence' : '' ?>">
          <div class="n" data-n="<?= $c48['corriendo'] ?>">0</div>
          <div class="t">Reloj corriendo</div>
          <div class="pie">Todavía a tiempo de decidir</div>
        </div>
        <div class="tile azul">
          <div class="n" data-n="<?= $cont['abiertos'] ?>">0</div>
          <div class="t">Vivos en total</div>
          <div class="pie">Con veredicto o sin él</div>
        </div>
        <div class="tile <?= $cont['insistidos'] ? 'vence' : '' ?>">
          <div class="n" data-n="<?= $cont['insistidos'] ?>">0</div>
          <div class="t">Con dos o más recordatorios</div>
          <div class="pie">Alguien ya preguntó varias veces</div>
        </div>
        <div class="tile atend">
          <div class="n" data-n="<?= $cont['cerrados_semana'] ?>">0</div>
          <div class="t">Cerrados esta semana</div>
        </div>
      </div>
    <?php endif; ?>

    <div class="filtros-rapidos">
      <?php foreach ($FILTROS as $k => [$et, $n, $tono]): ?>
        <a class="fr <?= $grupo === $k ? 'on' : '' ?> <?= $e($tono) ?>"
           href="?g=<?= $k ?><?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?>">
          <?= $e($et) ?>
          <?php if ($n !== null && $n > 0): ?><span class="n"><?= (int) $n ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <form method="get" style="display:flex;gap:6px;margin-left:auto">
        <input type="hidden" name="g" value="<?= $e($grupo) ?>">
        <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Local, equipo, pieza…"
               style="height:36px;min-width:190px;font-size:13.5px">
        <button class="btn sm" type="submit">Buscar</button>
      </form>
    </div>

    <?php if (!$lista): ?>
      <?php
      $vacio = [
        'vencidos'      => ['Ningún equipo fuera de plazo', 'Todos los que están parados tienen su veredicto dentro de las 48 horas. Es exactamente lo que se busca.'],
        'sin_veredicto' => ['Nada esperando decisión', 'Todos los pendientes vivos ya tienen su vía elegida.'],
        'abiertos'      => ['No hay equipos trabados', 'Todas las intervenciones concluyeron en la visita, que es la premisa del servicio.'],
        'cerrados'      => ['Todavía no hay pendientes resueltos', 'Aquí aparecen los que ya volvieron a operar o cuya baja quedó ejecutada.'],
      ][$grupo] ?? ['Sin resultados', 'Prueba con otro filtro o sin texto de búsqueda.'];
      ?>
      <div class="vacio card">
        <span class="icono">✓</span>
        <b style="display:block;font-size:15px;color:var(--ink);margin-bottom:6px"><?= $e($vacio[0]) ?></b>
        <span style="display:block;max-width:52ch;margin:0 auto"><?= $e($vacio[1]) ?></span>
      </div>
    <?php else: ?>

      <p class="sub" style="margin:0 0 10px">
        <b><?= count($lista) ?></b> <?= count($lista) === 1 ? 'pendiente' : 'pendientes' ?>.
        Primero lo que está parado sin veredicto, y dentro de cada grupo lo más
        viejo arriba: es lo que lleva más tiempo esperando.
      </p>

      <?php foreach ($lista as $i => $p): ?>
        <?php
        $id = (int) $p['pendiente_id'];
        $r  = $p['reloj'];
        $vencido = $r !== null && $r['vencido'] && empty($r['cerrado']);
        $hilo = $hilos[$id] ?? [];
        $sinVeredicto = $p['via'] === 'SIN_VEREDICTO' && $p['abierto'];
        ?>
        <article class="rep <?= $vencido ? 'vencido' : ($p['deshabilitado'] ? 'deshabilitado' : '') ?><?= $p['abierto'] ? '' : ' atendido' ?>"
                 style="--i:<?= $i ?>">
          <div class="cab">
            <div style="min-width:0;flex:1">
              <div class="que">
                <?= $e($p['equipo_desc'] ?: ($p['activo_fijo'] ?: 'Equipo sin identificar')) ?>
                <?php if ($p['deshabilitado'] && $p['abierto']): ?>
                  <span class="chip" style="background:#fee2e2;color:#991b1b;border-color:#fecaca">deshabilitado</span>
                <?php endif; ?>
              </div>
              <div class="meta">
                <?= Ui::zona($p['zona']) ?>
                <b><?= $e($p['local_codigo'] ?: '—') ?></b> ·
                aviso <span class="mono"><?= $e($p['aviso']) ?></span> ·
                lo abrió <?= $e($p['abrio']) ?> el <?= $e(substr((string) $p['abierto_en'], 0, 10)) ?>
              </div>
            </div>
            <div style="text-align:right">
              <?php if ($r !== null): ?>
                <span class="<?= $e($r['clase']) ?>"><?= $e($r['texto']) ?></span>
              <?php endif; ?>
              <div style="margin-top:6px">
                <span class="est est-<?= $sinVeredicto ? 'en_revision' : ($p['abierto'] ? 'asignado' : 'resuelto') ?>"
                      title="<?= $e(Pendientes::ayudaEstado($p['estado'])) ?>">
                  <?= $e(Pendientes::etiquetaEstado($p['estado'])) ?>
                </span>
              </div>
            </div>
          </div>

          <?= Pendientes::barra48($p) ?>

          <div class="nota">
            <?= $e($p['diagnostico']) ?>
            <span class="de">Diagnóstico de <?= $e($p['abrio']) ?></span>
          </div>

          <?php if ($p['via'] !== 'SIN_VEREDICTO'): ?>
            <p class="sub" style="margin-top:9px">
              <b>Vía: <?= $e(Pendientes::etiquetaVia($p['via'])) ?></b><?php
                if (!empty($p['decidio'])) { echo ' · lo decidió ' . $e($p['decidio']); }
                if (!empty($p['veredicto_en'])) { echo ' el ' . $e(substr((string) $p['veredicto_en'], 0, 10)); }
                if (!empty($p['parte'])) { echo ' · pieza: ' . $e($p['parte']); }
                if (!empty($p['prometido_para'])) { echo ' · comprometido para ' . $e($p['prometido_para']); }
              ?>
              <?php if (empty($p['prometido_para']) && $p['abierto']): ?>
                <br><span style="color:var(--warn)">Sin fecha comprometida todavía.</span>
              <?php endif; ?>
            </p>
          <?php endif; ?>

          <?php if ($hilo): ?>
            <div class="hilo">
              <?php foreach ($hilo as $nt): ?>
                <div class="msg <?= $nt['urgente'] ? 'insiste' : '' ?>">
                  <span class="quien"><?= $e($nt['nombre']) ?></span>
                  <span class="chip" style="font-size:10.5px"><?= $e(Ui::ROL[$nt['rol']] ?? $nt['rol']) ?></span>
                  <span class="cuando" data-hace="<?= $e($nt['creado_en']) ?>"><?= $e(substr((string) $nt['creado_en'], 0, 16)) ?></span>
                  <div class="txt">
                    <?php if ($nt['urgente']): ?><b>Urgente — </b><?php endif; ?>
                    <?= $e($nt['texto']) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ((int) $p['insistencias'] >= 2 && $p['abierto']): ?>
            <p class="sub" style="color:var(--danger);margin-top:8px">
              Ya se preguntó <b><?= (int) $p['insistencias'] ?> veces</b> por este pendiente.
            </p>
          <?php endif; ?>

          <?php if ($p['abierto']): ?>
            <div class="acciones">
              <?php if ($sinVeredicto && $puedeVeredicto): ?>
                <button class="btn primary sm" type="button"
                        onclick="veredicto(<?= $id ?>, <?= $e(json_encode($p['equipo_desc'] ?: $p['activo_fijo'])) ?>)">
                  Dar el veredicto
                </button>
              <?php endif; ?>

              <?php if (!$sinVeredicto && $puedeGestionar): ?>
                <button class="btn sm" type="button"
                        onclick="mover(<?= $id ?>, '<?= $e($p['via']) ?>', '<?= $e($p['estado']) ?>')">
                  Avanzar
                </button>
              <?php endif; ?>

              <?php if ($puedeGestionar): ?>
                <button class="btn sm" type="button" onclick="responder(<?= $id ?>)">Responder</button>
              <?php endif; ?>

              <?php if ($puedeInsistir): ?>
                <button class="btn sm ghost" type="button" onclick="insistir(<?= $id ?>)">
                  <?= $esTecnico ? 'Insistir' : 'Recordar' ?>
                </button>
              <?php endif; ?>
            </div>
          <?php elseif (!empty($p['nota_cierre'])): ?>
            <p class="sub" style="margin-top:9px">Cierre: <?= $e($p['nota_cierre']) ?></p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php /* =========================================================================
   LOS DIALOGOS.
   Uno por acción y no uno por fila: con cien pendientes serían cuatrocientos
   formularios en el HTML y la página se arrastraría en el celular del jefe de
   zona, que es donde más se usa.
   ========================================================================= */ ?>

<dialog id="dlgVeredicto">
  <form method="post">
    <input type="hidden" name="accion" value="veredicto">
    <input type="hidden" name="pendiente_id" id="v-id">
    <h2>¿Por dónde va?</h2>
    <p class="sub" style="margin:0 0 4px" id="v-equipo"></p>
    <p class="sub" style="margin:0 0 14px">
      Es la decisión que el compromiso de 48 horas exige. Elige una de las
      cuatro y queda registrada con tu nombre y la hora.
    </p>

    <div class="opciones" style="margin-bottom:14px">
      <?php foreach (Pendientes::VIAS as $k => [$et, $ayuda, $_]): ?>
        <label class="opcion">
          <input type="radio" name="via" value="<?= $e($k) ?>" required>
          <span class="t"><?= $e($et) ?></span>
          <span class="d"><?= $e($ayuda) ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <div style="margin-bottom:12px">
      <label for="v-nota">Por qué esa vía</label>
      <textarea id="v-nota" name="nota" rows="2"
        placeholder="El sustento técnico. Obligatorio si propones dar de baja el equipo."></textarea>
    </div>
    <div style="margin-bottom:12px">
      <label for="v-prom">Para cuándo se compromete</label>
      <input type="date" id="v-prom" name="prometido">
      <span class="derivado">
        Opcional. Si lo dejas vacío, la pantalla dirá que no hay fecha comprometida
        en vez de inventar una estimación.
      </span>
    </div>

    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit">Guardar el veredicto</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>

<dialog id="dlgMover">
  <form method="post">
    <input type="hidden" name="accion" value="mover">
    <input type="hidden" name="pendiente_id" id="m-id">
    <h2>Avanzar el pendiente</h2>
    <p class="sub" style="margin:0 0 12px">
      Solo se ofrecen los pasos de la vía que ya se decidió: un equipo que va
      por garantía no puede pasar a «en bodega».
    </p>
    <div style="margin-bottom:12px">
      <label for="m-est">Nuevo estado</label>
      <select name="estado" id="m-est" required></select>
    </div>
    <div style="margin-bottom:12px">
      <label for="m-nota">Nota para el hilo</label>
      <textarea id="m-nota" name="nota" rows="2"
        placeholder="Lo que el técnico va a leer. Obligatorio si cancelas."></textarea>
    </div>
    <div style="margin-bottom:12px">
      <label for="m-prom">Fecha comprometida</label>
      <input type="date" id="m-prom" name="prometido">
    </div>
    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit">Guardar</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>

<dialog id="dlgTexto">
  <form method="post">
    <input type="hidden" name="accion" id="t-accion">
    <input type="hidden" name="pendiente_id" id="t-id">
    <h2 id="t-titulo"></h2>
    <p class="sub" style="margin:0 0 12px" id="t-ayuda"></p>
    <textarea name="nota" id="t-texto" rows="3" required></textarea>
    <label id="t-urg" style="display:flex;align-items:center;gap:8px;margin:11px 0 0;font-size:13.5px;color:var(--ink)">
      <input type="checkbox" name="urgente" value="1" style="width:auto;height:auto;margin:0">
      Marcarlo como urgente
    </label>
    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit" id="t-ok">Enviar</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>

<script>
/* Los pasos de cada vía. Salen de aquí y no del HTML de cada fila para que la
   lista esté en un solo sitio; el servidor la vuelve a comprobar igual, porque
   un POST se fabrica a mano. */
var PASOS = <?= json_encode(Pendientes::PASOS, JSON_UNESCAPED_UNICODE) ?>;
var NOMBRES = <?= json_encode(array_map(fn($v) => $v[0], Pendientes::ESTADOS), JSON_UNESCAPED_UNICODE) ?>;

function veredicto(id, equipo) {
  document.getElementById('v-id').value = id;
  document.getElementById('v-equipo').textContent = equipo || '';
  document.querySelectorAll('#dlgVeredicto .opcion').forEach(function (o) { o.classList.remove('on'); });
  document.getElementById('dlgVeredicto').showModal();
}

/* La tarjeta entera se ilumina al elegir, no solo el punto del radio: con
   guantes o con el celular al sol, un radio de 14 px no se ve marcado. */
document.querySelectorAll('#dlgVeredicto .opcion input').forEach(function (r) {
  r.addEventListener('change', function () {
    document.querySelectorAll('#dlgVeredicto .opcion').forEach(function (o) { o.classList.remove('on'); });
    if (r.checked) { r.closest('.opcion').classList.add('on'); }
  });
});

function mover(id, via, actual) {
  document.getElementById('m-id').value = id;
  var s = document.getElementById('m-est');
  s.innerHTML = '';
  (PASOS[via] || []).concat(['CANCELADO']).forEach(function (p) {
    if (p === actual) { return; }          // no se ofrece el estado en que ya está
    var o = document.createElement('option');
    o.value = p;
    o.textContent = NOMBRES[p] || p;
    s.appendChild(o);
  });
  document.getElementById('dlgMover').showModal();
}

function responder(id) { texto(id, 'responder', 'Responder al técnico',
  'Lo que escribas aparece en su bandeja, con tu nombre y la fecha.', 'Enviar', false); }

function insistir(id) { texto(id, 'insistir', 'Recordar este pendiente',
  'Queda registrado con la fecha. Es lo que permite demostrar después que se avisó a tiempo.',
  'Enviar recordatorio', true); }

function texto(id, accion, titulo, ayuda, ok, urgente) {
  document.getElementById('t-id').value = id;
  document.getElementById('t-accion').value = accion;
  document.getElementById('t-titulo').textContent = titulo;
  document.getElementById('t-ayuda').textContent = ayuda;
  document.getElementById('t-ok').textContent = ok;
  document.getElementById('t-urg').hidden = !urgente;
  var t = document.getElementById('t-texto');
  t.value = '';
  t.placeholder = urgente
    ? 'p. ej. el administrador insiste en que envíen el repuesto urgente, el equipo sigue deshabilitado'
    : 'p. ej. la pieza llega el jueves; ya está la orden de compra';
  document.getElementById('dlgTexto').showModal();
}
</script>

<?php Ui::pie(); ?>
