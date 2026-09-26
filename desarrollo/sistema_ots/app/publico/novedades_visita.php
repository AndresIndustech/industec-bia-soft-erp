<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Novedades.php';
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Catalogo.php';
require_once __DIR__ . '/nucleo/Vocabulario.php';   // filtros, tarjetas y opciones con los términos del diccionario

/**
 * novedades_visita.php — La bandeja de lo que se ve en la visita y no era la orden.
 *
 * OJO CON EL NOMBRE. `novedades.php` YA EXISTE y es otra cosa: el extremo que
 * la pantalla del buzon consulta cada 30 segundos para saber si llegaron casos
 * nuevos. Esta pantalla se llamo asi al principio y lo piso; se detecto porque
 * la barra de «el buzon se actualizo» dejo de aparecer — `ui.js` recibia una
 * pagina HTML donde esperaba JSON, y el `catch` se lo comia en silencio.
 * De ahi el nombre largo: los dos conceptos se llaman «novedad» en la
 * operacion, pero son cosas distintas y no pueden compartir archivo.
 *
 * ============================================================================
 * QUIEN MIRA ESTA PANTALLA Y PARA QUE
 *
 * El jefe de zona y la administradora. Cada fila es algo que un técnico vio en
 * un local y que hay que convertir en una de tres cosas:
 *
 *   - un aviso nuevo que se le pide a Grupo KFC (y aquí queda su número),
 *   - un trabajo que INDUSTEC asume dentro de su planificación,
 *   - o un descarte con su motivo escrito.
 *
 * La tercera importa tanto como las otras dos: el técnico que reporta y nunca
 * sabe en qué quedó, deja de reportar. Por eso descartar exige motivo, y por
 * eso el técnico ve las suyas con su resultado.
 *
 * ============================================================================
 * EL FILTRO «DE OTRAS ÁREAS» ES EL MAS VALIOSO (antes «No es de INDUSTEC»,
 * que se confundía con «no nos compete» de la orden; NOVEDAD_OTRA_AREA)
 *
 * Agrupa lo eléctrico, la ventilación, el desagüe y la obra civil: las causas
 * de que un mismo local rompa el mismo equipo tres veces al año. Sin este
 * registro, esa conversación con el cliente es la palabra de INDUSTEC contra
 * la percepción de que no sabe reparar. Con él, es una lista con fechas.
 */

$u = Auth::exigir();
if ($u['debe_cambiar_clave']) { header('Location: clave.php'); exit; }
if (!Ui::puedeModulo('novedades.ver', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO'], $u)) {
    // SEG-20: la denegación deja rastro, como en el resto de pantallas.
    Auth::bitacora('DENEGADO', 'novedades', '', 'sin permiso', null, null, [], false);
    http_response_code(403);
    exit('No tienes acceso a esta pantalla.');
}

$e = fn(?string $s): string => Ui::e($s);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::exigirCsrf();
    $accion = (string) ($_POST['accion'] ?? '');
    if ($accion === 'resolver') {
        [$ok, $msg] = Novedades::resolver(
            (int) ($_POST['novedad_id'] ?? 0),
            (string) ($_POST['estado'] ?? ''),
            (string) ($_POST['nota'] ?? ''),
            (string) ($_POST['aviso_sap'] ?? '')
        );
    } elseif ($accion === 'reportar') {
        [$ok, $msg] = Novedades::reportar([
            'descripcion' => $_POST['descripcion'] ?? '',
            'tipo'        => $_POST['tipo'] ?? '',
            'riesgo'      => $_POST['riesgo'] ?? '',
            'responsable' => $_POST['responsable'] ?? '',
            'local'       => $_POST['local'] ?? '',
            'zona'        => $_POST['zona'] ?? '',
            'equipo_desc' => $_POST['equipo_desc'] ?? '',
            'aviso'       => $_POST['aviso'] ?? '',
            'modulo'      => $_POST['modulo'] ?? '',
        ]);
    } else {
        [$ok, $msg] = [false, 'Acción no reconocida.'];
    }
    $_SESSION['flash'] = $ok ? ['ok' => $msg] : ['error' => $msg];
    header('Location: novedades_visita.php?' . (string) ($_SERVER['QUERY_STRING'] ?? ''));
    exit;
}

$grupo = (string) ($_GET['g'] ?? 'pendientes');
$tipo  = (string) ($_GET['tipo'] ?? '');
$q     = trim((string) ($_GET['q'] ?? ''));
$lista = Novedades::lista(['grupo' => $grupo, 'tipo' => $tipo, 'q' => $q]);
$cont  = Novedades::contadores();

Auth::bitacora('CONSULTAR', 'novedades', $grupo, 'visibles=' . count($lista));

$errFlash = Ui::errorFlash();
$puedeResolver = Ui::puedeModulo('novedades.gestionar', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA'], $u);
$puedeReportar = Ui::puedeModulo('novedades.reportar', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO'], $u);
$esTecnico = $u['rol'] === 'TECNICO';
$csrf = Auth::csrfToken();
// P-22: los locales para el formulario de la oficina, del mismo maestro que
// valida el servidor. Con alcance de zona, solo los de esa zona.
$localesForm = [];
if ($puedeReportar && !$esTecnico) {
    $za = Auth::zonaAlcance();
    foreach ((Catalogo::cargar()['locales'] ?? []) as $l) {
        if ($za !== null && strtoupper((string) ($l['zona'] ?? '')) !== $za) { continue; }
        $localesForm[] = $l;
    }
}

/* Los rótulos salen del diccionario único. El grupo «resueltas» junta tres
   estados (con aviso SAP, la asume INDUSTEC y resuelta): se nombra con los
   tres, porque «resuelta» sola es solo el último. Los códigos de grupo
   (?g=pendientes, ?g=ajenas…) no cambian. */
$rotConDestino = Vocabulario::titulo('NOVEDAD_CON_AVISO') . ' · ' . Vocabulario::titulo('NOVEDAD_ASUMIDA')
               . ' · ' . Vocabulario::titulo('NOVEDAD_RESUELTA');
$FILTROS = [
    'pendientes'  => [Vocabulario::titulo('NOVEDAD_POR_DECIDIR'), $cont['pendientes'], 'ambar'],
    'ajenas'      => [Vocabulario::titulo('NOVEDAD_OTRA_AREA'),   $cont['ajenas'],     ''],
    'resueltas'   => [$rotConDestino,                             null,                ''],
    'descartadas' => [Vocabulario::titulo('NOVEDAD_DESCARTADA'),  null,                ''],
];

Ui::cabecera($u, 'novedades_visita.php',
    ['novedades' => $cont['pendientes'] > 0
        ? ['n' => $cont['pendientes'], 'tono' => $cont['alto'] ? 'urge' : 'ojo'] : null],
    ['titulo' => Vocabulario::titulo('NOVEDAD')]);
?>

<div class="wrap ancho">
  <div class="titulo entra">
    <h1>Novedades detectadas en visita</h1>
    <p class="sub">
      <?php if ($esTecnico): ?>
        Lo que reportaste durante tus visitas y en qué quedó cada cosa.
      <?php else: ?>
        Lo que los técnicos vieron y no era su orden: el correctivo que se viene,
        y lo de otras áreas —eléctrico, ventilación, desagüe, obra civil— que hace
        fallar los equipos una y otra vez. Aquí se resuelve qué se le pide a
        Grupo KFC como aviso SAP nuevo, qué asume INDUSTEC y qué se descarta.
      <?php endif; ?>
    </p>
  </div>

  <?php if ($errFlash): ?><?= Ui::aviso('err', $e($errFlash), true) ?><?php endif; ?>

  <?php if (!Novedades::disponible()): ?>
    <?= Ui::aviso('info',
        '<b>Este módulo todavía no está instalado en la base.</b>'
      . '<p>La migración <span class="mono">007_pendientes_y_captura.sql</span> crea la '
      . 'tabla <span class="mono">novedades</span> y está escrita, sin aplicar: cambia el '
      . 'esquema y eso requiere aprobación.</p>') ?>
  <?php else: ?>

    <?php if ($puedeReportar && !$esTecnico && $localesForm): ?>
      <?php /* P-22: lo que llega por teléfono o por WhatsApp a la oficina se
               registra aquí, con el mismo rastro que lo que reporta el
               técnico desde la visita. */ ?>
      <p style="margin:0 0 12px">
        <button class="btn sm" type="button" onclick="reportar()">Registrar una novedad</button>
      </p>
    <?php endif; ?>

    <?php if (!$esTecnico): ?>
      <div class="tiles">
        <div class="tile <?= $cont['alto'] ? 'alerta' : '' ?>">
          <div class="n" data-n="<?= $cont['alto'] ?>">0</div>
          <div class="t">Riesgo alto, <?= $e(Vocabulario::t('NOVEDAD_POR_DECIDIR')) ?></div>
          <div class="pie">Va a deshabilitar un equipo, o es riesgo para personas</div>
        </div>
        <div class="tile <?= $cont['pendientes'] ? 'vence' : '' ?>">
          <div class="n" data-n="<?= $cont['pendientes'] ?>">0</div>
          <div class="t" title="<?= $e(Vocabulario::ayuda('NOVEDAD_POR_DECIDIR')) ?>"><?= $e(Vocabulario::titulo('NOVEDAD_POR_DECIDIR')) ?></div>
        </div>
        <div class="tile viol">
          <?php /* El contador «ajenas» cuenta solo las que siguen por resolver
                   (Novedades::contadores): el pie lo dice. */ ?>
          <div class="n" data-n="<?= $cont['ajenas'] ?>">0</div>
          <div class="t" title="<?= $e(Vocabulario::ayuda('NOVEDAD_OTRA_AREA')) ?>"><?= $e(Vocabulario::titulo('NOVEDAD_OTRA_AREA')) ?></div>
          <div class="pie"><?= $e(Vocabulario::titulo('NOVEDAD_POR_DECIDIR')) ?>: eléctrico, ventilación, desagüe, obra</div>
        </div>
        <div class="tile atend">
          <div class="n" data-n="<?= $cont['con_aviso'] ?>">0</div>
          <div class="t" title="<?= $e(Vocabulario::ayuda('NOVEDAD_CON_AVISO')) ?>"><?= $e(Vocabulario::titulo('NOVEDAD_CON_AVISO')) ?></div>
        </div>
      </div>
    <?php endif; ?>

    <div class="filtros-rapidos">
      <?php foreach ($FILTROS as $k => [$et, $n, $tono]): ?>
        <a class="fr <?= $grupo === $k ? 'on' : '' ?> <?= $e($tono) ?>" href="?g=<?= $k ?>">
          <?= $e($et) ?>
          <?php if ($n): ?><span class="n"><?= (int) $n ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <form method="get" data-auto style="display:flex;gap:6px;margin-left:auto">
        <input type="hidden" name="g" value="<?= $e($grupo) ?>">
        <select name="tipo" style="height:36px;min-width:180px;font-size:13.5px">
          <option value="">Toda área</option>
          <?php foreach (Novedades::TIPOS as $k => [$et, $_]): ?>
            <option value="<?= $e($k) ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= $e($et) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <?php if (!$lista): ?>
      <div class="vacio card">
        <span class="icono">✓</span>
        <b style="display:block;font-size:15px;color:var(--ink);margin-bottom:6px">
          <?= $grupo === 'pendientes' ? $e('No hay novedades ' . Vocabulario::t('NOVEDAD_POR_DECIDIR')) : 'Nada con esos filtros' ?>
        </b>
        <span style="display:block;max-width:52ch;margin:0 auto">
          <?= $grupo === 'pendientes'
              ? 'Todo lo que los técnicos reportaron ya tiene su resolución.'
              : 'Prueba con otro filtro.' ?>
        </span>
      </div>
    <?php else: ?>

      <?php foreach ($lista as $i => $n): ?>
        <?php
        $ajena = $n['tipo'] !== 'EQUIPO_CORRECTIVO';
        $alto  = $n['riesgo'] === 'ALTO';
        $abierta = in_array($n['estado'], Novedades::PENDIENTES, true);
        ?>
        <article class="rep <?= $alto && $abierta ? 'vencido' : ($abierta ? 'deshabilitado' : 'atendido') ?>"
                 style="--i:<?= $i ?>">
          <div class="cab">
            <div style="min-width:0;flex:1">
              <div class="que">
                <?= $e(Novedades::etiquetaTipo($n['tipo'])) ?>
                <?php if ($ajena): ?>
                  <span class="chip" style="background:var(--viol-bg);color:var(--viol);border-color:var(--viol-bd)"
                        title="<?= $e(Vocabulario::ayuda('NOVEDAD_OTRA_AREA')) ?>">
                    <?= $e(Vocabulario::corto('NOVEDAD_OTRA_AREA')) ?>
                  </span>
                <?php endif; ?>
              </div>
              <div class="meta">
                <?= Ui::zona($n['zona']) ?>
                <b><?= $e($n['local_codigo'] ?: '—') ?></b>
                <?php if (!empty($n['equipo_desc'])): ?> · <?= $e($n['equipo_desc']) ?><?php endif; ?>
                · lo vio <?= $e($n['reporto']) ?>
                <span data-hace="<?= $e($n['reportada_en']) ?>"><?= $e(substr((string) $n['reportada_en'], 0, 10)) ?></span>
                <?php if (!empty($n['modulo_origen'])): ?>
                  · durante un <?= $e(strtolower((string) $n['modulo_origen'])) ?>
                <?php endif; ?>
              </div>
            </div>
            <div style="text-align:right">
              <span class="edad <?= $alto ? 'edad-viejo' : ($n['riesgo'] === 'MEDIO' ? 'edad-7' : 'edad-3') ?>">
                riesgo <?= $e(strtolower((string) $n['riesgo'])) ?>
              </span>
              <div style="margin-top:6px">
                <span class="est est-<?= $abierta ? 'en_revision' : ($n['estado'] === 'DESCARTADA' ? 'no_compete' : 'resuelto') ?>">
                  <?= $e(Novedades::etiquetaEstado($n['estado'])) ?>
                </span>
              </div>
            </div>
          </div>

          <div class="nota">
            <?= $e($n['descripcion']) ?>
            <span class="de">
              El técnico cree que le toca a
              <b><?= $e(['INDUSTEC' => 'INDUSTEC', 'CLIENTE' => 'Grupo KFC', 'TERCERO' => 'un tercero'][$n['responsable_prop']] ?? '?') ?></b>.
              Es su propuesta; la resuelve el jefe de zona o la administración.
            </span>
          </div>

          <?php if (!empty($n['aviso_sap'])): ?>
            <p class="sub" style="margin-top:9px">
              <?= $e(Vocabulario::titulo('AVISO_SAP')) ?>: <b class="mono"><?= $e($n['aviso_sap']) ?></b>
              <?php if (!empty($n['decidio'])): ?> · lo gestionó <?= $e($n['decidio']) ?><?php endif; ?>
            </p>
          <?php endif; ?>
          <?php if (!empty($n['veredicto_nota'])): ?>
            <p class="sub" style="margin-top:6px"><?= $e($n['veredicto_nota']) ?></p>
          <?php endif; ?>

          <?php if ($abierta && $puedeResolver): ?>
            <div class="acciones">
              <button class="btn primary sm" type="button" onclick="resolver(<?= (int) $n['novedad_id'] ?>, '<?= $e($n['estado']) ?>')">
                Resolver
              </button>
            </div>
          <?php elseif ($puedeResolver && in_array($n['estado'], ['DERIVADA_SAP', 'ASUMIDA_INDUSTEC'], true)): ?>
            <?php /* P-15: una novedad con aviso SAP o asumida se da por
                     resuelta cuando queda hecha, o se corrige su número de aviso. */ ?>
            <div class="acciones">
              <button class="btn sm" type="button" onclick="resolver(<?= (int) $n['novedad_id'] ?>, '<?= $e($n['estado']) ?>')">
                Darla por resuelta o corregir
              </button>
            </div>
          <?php elseif ($abierta && $esTecnico): ?>
            <p class="sub" style="margin-top:9px"><?= $e(Vocabulario::titulo('NOVEDAD_POR_DECIDIR')) ?>: la resuelve tu jefe de zona o la administración.</p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<dialog id="dlgResolver">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="accion" value="resolver">
    <input type="hidden" name="novedad_id" id="r-id">
    <h2>¿Qué se hace con esta novedad?</h2>
    <p class="sub" style="margin:0 0 14px">
      El sistema no crea nada en SAP: el aviso lo abre Grupo KFC. Lo que se
      guarda aquí es la decisión y, si aplica, el número que dieron.
    </p>

    <?php /* El rótulo de cada opción es el estado al que lleva, con su nombre
             del diccionario (el mismo que luego sale en la fila); la
             descripción de debajo se queda. El valor enviado no cambia. */ ?>
    <div class="opciones" style="margin-bottom:14px">
      <label class="opcion" data-desde="REPORTADA EN_REVISION DERIVADA_SAP">
        <input type="radio" name="estado" value="DERIVADA_SAP" required>
        <span class="t"><?= $e(Vocabulario::titulo('NOVEDAD_CON_AVISO')) ?></span>
        <span class="d">Ya tiene número de aviso. Es la prueba de que se avisó y cuándo. Desde una que ya tiene aviso SAP, corrige el número.</span>
      </label>
      <label class="opcion" data-desde="REPORTADA EN_REVISION ASUMIDA_INDUSTEC">
        <input type="radio" name="estado" value="ASUMIDA_INDUSTEC">
        <span class="t"><?= $e(Vocabulario::titulo('NOVEDAD_ASUMIDA')) ?></span>
        <span class="d">Entra en nuestra planificación sin aviso nuevo.</span>
      </label>
      <label class="opcion" data-desde="REPORTADA EN_REVISION">
        <input type="radio" name="estado" value="EN_REVISION">
        <span class="t"><?= $e(Vocabulario::titulo('NOVEDAD_EN_ESTUDIO')) ?></span>
        <span class="d">Se marca como tomada para que no la revisen dos personas.</span>
      </label>
      <label class="opcion" data-desde="REPORTADA EN_REVISION DERIVADA_SAP ASUMIDA_INDUSTEC">
        <input type="radio" name="estado" value="RESUELTA">
        <span class="t"><?= $e(ucfirst(Vocabulario::t('NOVEDAD_RESUELTA'))) ?></span>
        <span class="d">Lo que se pidió a KFC o se asumió quedó hecho. Con una nota de qué se hizo.</span>
      </label>
      <label class="opcion" data-desde="REPORTADA EN_REVISION">
        <input type="radio" name="estado" value="DESCARTADA">
        <span class="t"><?= $e(ucfirst(Vocabulario::t('NOVEDAD_DESCARTADA'))) ?></span>
        <span class="d">Con motivo obligatorio: el técnico la reportó y merece saber por qué.</span>
      </label>
    </div>

    <div style="margin-bottom:12px">
      <label for="r-aviso">Número de aviso SAP</label>
      <input type="text" id="r-aviso" name="aviso_sap" inputmode="numeric"
             placeholder="Obligatorio si queda <?= $e(Vocabulario::t('NOVEDAD_CON_AVISO')) ?>">
    </div>
    <div style="margin-bottom:12px">
      <label for="r-nota">Nota</label>
      <textarea id="r-nota" name="nota" rows="2"
        placeholder="Qué se resolvió y por qué. Obligatorio si se descarta."></textarea>
    </div>

    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit">Guardar</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>

<?php if ($puedeReportar && !$esTecnico && $localesForm): ?>
<dialog id="dlgReportar">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="accion" value="reportar">
    <h2>Registrar una novedad</h2>
    <p class="sub" style="margin:0 0 12px">
      Lo que llegó a la oficina por teléfono o por mensaje y no era una orden.
      Queda con tu nombre; la zona y la cadena salen del maestro del local.
    </p>
    <div class="grid g2">
      <div>
        <label for="n-local">Local</label>
        <input type="text" id="n-local" name="local" list="n-locales" required maxlength="12"
               placeholder="Código del local" autocomplete="off" style="text-transform:uppercase">
        <datalist id="n-locales">
          <?php foreach ($localesForm as $l): ?>
            <option value="<?= $e((string) $l['codigo']) ?>"><?= $e(trim((string) ($l['nombre'] ?? '') . ' · ' . (string) ($l['zona'] ?? ''), ' ·')) ?></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div>
        <label for="n-tipo">Área</label>
        <select id="n-tipo" name="tipo" required>
          <?php foreach (Novedades::TIPOS as $k => [$et, $_]): ?>
            <option value="<?= $e($k) ?>"><?= $e($et) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="n-riesgo">Riesgo</label>
        <select id="n-riesgo" name="riesgo">
          <?php foreach (Novedades::RIESGOS as $k => $et): ?>
            <option value="<?= $e($k) ?>" <?= $k === 'MEDIO' ? 'selected' : '' ?>><?= $e($et) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="n-resp">A quién le toca (propuesta)</label>
        <select id="n-resp" name="responsable">
          <option value="INDUSTEC">INDUSTEC</option>
          <option value="CLIENTE">Grupo KFC</option>
          <option value="TERCERO">Un tercero</option>
        </select>
      </div>
    </div>
    <div style="margin:12px 0">
      <label for="n-equipo">Equipo (si aplica)</label>
      <input type="text" id="n-equipo" name="equipo_desc" maxlength="160" autocomplete="off">
    </div>
    <div style="margin-bottom:12px">
      <label for="n-aviso">Aviso SAP relacionado (si hay)</label>
      <input type="text" id="n-aviso" name="aviso" maxlength="20" inputmode="numeric" autocomplete="off">
    </div>
    <div style="margin-bottom:12px">
      <label for="n-desc">Qué pasa</label>
      <textarea id="n-desc" name="descripcion" rows="3" required maxlength="800"
                placeholder="Qué se vio o qué avisaron, y dónde exactamente."></textarea>
    </div>
    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit">Registrar</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<script>
function resolver(id, desde) {
  document.getElementById('r-id').value = id;
  // P-15: solo se ofrecen las salidas válidas desde el estado actual; el
  // servidor las vuelve a comprobar (Novedades::TRANSICIONES).
  document.querySelectorAll('#dlgResolver .opcion').forEach(function (o) {
    o.classList.remove('on');
    var permitidas = (o.dataset.desde || '').split(' ');
    var r = o.querySelector('input');
    var ok = permitidas.indexOf(desde || 'REPORTADA') !== -1;
    o.hidden = !ok; r.disabled = !ok; r.checked = false;
  });
  document.getElementById('r-aviso').required = false;
  document.getElementById('r-nota').required = false;
  document.getElementById('dlgResolver').showModal();
}
function reportar() {
  var d = document.getElementById('dlgReportar');
  if (d) { d.showModal(); document.getElementById('n-local').focus(); }
}
document.querySelectorAll('#dlgResolver .opcion input').forEach(function (r) {
  r.addEventListener('change', function () {
    document.querySelectorAll('#dlgResolver .opcion').forEach(function (o) { o.classList.remove('on'); });
    if (r.checked) { r.closest('.opcion').classList.add('on'); }
    // El campo del aviso solo hace falta en una de las cuatro salidas. Se
    // marca como requerido al vuelo en vez de pedirlo siempre.
    document.getElementById('r-aviso').required = (r.value === 'DERIVADA_SAP');
    document.getElementById('r-nota').required  = (r.value === 'DESCARTADA');
  });
});
</script>

<?php Ui::pie(); ?>
