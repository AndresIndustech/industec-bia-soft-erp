<?php
declare(strict_types=1);
/**
 * automatizacion.php — El panel «Automatización»: lo que el sistema hace solo, y a quién le escribe.
 *
 * POR QUÉ EXISTE (T2.27.7, pedido de Andrés del 2026-09-23)
 * Los reportes que la administración le manda a Grupo KFC cada semana (T2.27)
 * ya se generan con un comando. Andrés pidió que las tareas programadas se
 * construyan dentro del panel donde también va a ir la configuración de correos,
 * y que NO se activen todavía: se activan después, con la aprobación de la
 * administradora. Este panel es ese lugar:
 *   - REPORTES PROGRAMADOS (esta tarea): horario, modo y destinatarios de cada
 *     reporte, activar con la aprobación registrada, y lo que corrió.
 *   - CORREOS DE LAS ÓRDENES: es de T2.28.2 y se construye allí, en este mismo
 *     panel. Mientras no exista, la sección lo dice en vez de esconderse.
 *
 * DÓNDE CORRE. La configuración vive en la base del sitio; los reportes se
 * generan en la estación de INDUSTEC (necesitan la base local, el correo y
 * Excel): `t2_27_programador.py` lee este panel y corre solo lo ACTIVO.
 *
 * Permiso: `automatizacion.configurar` (SUPERADMIN y ADMIN). POST con CSRF y
 * patrón POST-redirect-GET, como equipos.php. Nada se borra: se desactiva.
 */
require_once __DIR__ . '/nucleo/Ui.php';
require_once __DIR__ . '/nucleo/Automatizacion.php';

$u = Auth::exigir();
if (!Ui::puedeModulo('automatizacion.configurar', ['SUPERADMIN', 'ADMIN'], $u)) {
    Auth::bitacora('DENEGADO', 'automatizacion_tarea', '', 'sin permiso automatizacion.configurar', null, null, [], false);
    http_response_code(403);
    exit('No tienes acceso al panel de automatización.');
}
$e = fn(?string $s): string => Ui::e($s);

function terminarAut(?string $ok, ?string $error, string $ancla = ''): void
{
    $_SESSION['flash'] = array_filter(['ok' => $ok, 'error' => $error]);
    header('Location: automatizacion.php' . ($ancla !== '' ? '#' . $ancla : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::exigirCsrf();
    if (!Automatizacion::disponible()) {
        terminarAut(null, 'Falta la migración 020 en la base: el panel todavía no puede guardar nada.');
    }
    $accion = (string) ($_POST['accion'] ?? '');
    $id = (int) ($_POST['tarea'] ?? 0);
    $ancla = 'tarea-' . $id;
    $err = match ($accion) {
        'activar'    => Automatizacion::activar($id, (string) ($_POST['nota'] ?? ''), $u),
        'desactivar' => Automatizacion::desactivar($id, (string) ($_POST['nota'] ?? ''), $u),
        'horario'    => Automatizacion::cambiarHorario($id, (array) ($_POST['dias'] ?? []),
                            ($_POST['dia_mes'] ?? '') !== '' ? (int) $_POST['dia_mes'] : null, (string) ($_POST['hora'] ?? ''), $u),
        'modo'       => Automatizacion::cambiarModo($id, (string) ($_POST['modo'] ?? ''), $u),
        'dest_alta'  => Automatizacion::agregarDestinatario($id, (string) ($_POST['tipo'] ?? ''), (string) ($_POST['correo'] ?? ''),
                            (string) ($_POST['nombre'] ?? ''), $u),
        'dest_on'    => Automatizacion::destinatarioActivo((int) ($_POST['dest'] ?? 0), true, $u),
        'dest_off'   => Automatizacion::destinatarioActivo((int) ($_POST['dest'] ?? 0), false, $u),
        default      => 'Acción desconocida.',
    };
    if ($err !== null) {
        Auth::bitacora('AUTOMATIZACION_RECHAZADO', 'automatizacion_tarea', (string) $id, mb_substr($err, 0, 200), null, null, ['accion' => $accion], false);
        terminarAut(null, $err, $ancla);
    }
    $mensajes = [
        'activar' => 'Tarea activada. La estación la corre en su próximo horario; la aprobación quedó registrada.',
        'desactivar' => 'Tarea desactivada. Deja de correr desde ahora.',
        'horario' => 'Horario guardado.', 'modo' => 'Modo guardado.',
        'dest_alta' => 'Destinatario agregado.', 'dest_on' => 'Destinatario activado.', 'dest_off' => 'Destinatario desactivado.',
    ];
    terminarAut($mensajes[$accion] ?? 'Hecho.', null, $ancla);
}

$okFlash = $_SESSION['flash']['ok'] ?? null;
unset($_SESSION['flash']['ok']);
$errFlash = Ui::errorFlash();
$hay = Automatizacion::disponible();
$tareas = $hay ? Automatizacion::tareas() : [];
$cambios = $hay ? Automatizacion::cambios(25) : [];
$activas = count(array_filter($tareas, fn($t) => (int) $t['activa'] === 1));
// T2.28.2: cuántas propuestas de correo de local esperan aprobación en
// correos.php. try/catch porque la 013 puede no estar aplicada todavía.
try {
    $correosPendientes = (int) (Db::uno("SELECT COUNT(*) n FROM locales_correo_propuesto WHERE estado = 'PROPUESTO'")['n'] ?? 0);
} catch (Throwable $ex) {
    $correosPendientes = 0;
}

Ui::cabecera($u, 'automatizacion.php', ['correos' => $correosPendientes], ['titulo' => 'Automatización']);
?>
<div class="wrap ancho">
  <div class="titulo entra">
    <h1>Automatización</h1>
    <p class="sub">
      Lo que el sistema hace solo y a quién le escribe. Los reportes programados se generan en la
      estación de INDUSTEC en su horario; <b>solo corren los que estén activos</b>, y activar uno
      queda registrado con quién lo aprobó.
    </p>
  </div>

  <?php if ($okFlash): ?><?= Ui::aviso('ok', $e((string) $okFlash), true) ?><?php endif; ?>
  <?php if ($errFlash): ?><?= Ui::aviso('err', $e($errFlash), true) ?><?php endif; ?>

  <?php if (!$hay): ?>
    <?= Ui::aviso('warn', '<b>Falta la migración 020.</b><p>Sin ella el panel no tiene dónde guardar las tareas. '
        . 'Se aplica con <span class="mono">php aplicar_sql.php sql/020_automatizacion.sql</span>.</p>') ?>
  <?php else: ?>

  <?= Ui::aviso($activas ? 'info' : 'neutro',
      '<b>' . ($activas ? $activas . ' de ' . count($tareas) . ' tareas activas.' : 'Ninguna tarea está activa todavía.') . '</b>'
      . '<p>Se activan con la aprobación de la administración. Activar pide escribir quién aprobó y cómo, '
      . 'y exige los destinatarios del modo elegido. En el sitio de pruebas no sale ningún correo.</p>') ?>

  <h2 id="reportes">Reportes programados</h2>
  <?php foreach ($tareas as $t):
      $id = (int) $t['tarea_id'];
      $activa = (int) $t['activa'] === 1;
      $prox = Automatizacion::proxima($t);
      $falta = Automatizacion::faltaParaActivar($t, $t['destinatarios']); ?>
    <section class="rep" id="tarea-<?= $id ?>" style="margin-bottom:18px">
      <div class="cab">
        <div style="min-width:0">
          <div class="que"><?= $e($t['nombre']) ?>
            <span class="estado-red <?= $activa ? 'con' : 'sin' ?>" style="margin-left:8px"><?= $activa ? 'activa' : 'inactiva' ?></span>
          </div>
          <div class="meta" style="max-width:90ch"><?= $e($t['descripcion']) ?></div>
          <div class="sub" style="margin-top:6px">
            <b>Cuándo:</b> <?= $e(Automatizacion::horarioTexto($t)) ?>
            <?php if ($prox): ?> · próxima: <span class="mono"><?= $e($prox->format('d/m/Y H:i')) ?></span><?= $activa ? '' : ' <i>(no correrá mientras esté inactiva)</i>' ?><?php endif; ?>
            · <b>Modo:</b> <?= $e(Automatizacion::MODOS[$t['modo']][0]) ?>
            · <span class="mono"><?= $e($t['comando']) ?></span>
          </div>
          <?php if ($activa): ?>
            <div class="sub" style="color:#166534">Activada el <?= $e(substr((string) $t['aprobada_en'], 0, 16)) ?> por <?= $e($t['aprobada_nombre'] ?? '—') ?>: «<?= $e($t['aprobacion_nota']) ?>»</div>
          <?php elseif ($falta): ?>
            <div class="sub" style="color:#92400e">Para activarla: <?= $e($falta) ?></div>
          <?php endif; ?>
        </div>
        <div class="acciones-fila">
          <?php if ($activa): ?>
            <button class="btn" type="button" data-tarea="<?= $id ?>" data-nombre="<?= $e($t['nombre']) ?>" data-accion="desactivar" onclick="abrirDlg(this)">Desactivar</button>
          <?php else: ?>
            <button class="btn primary" type="button" data-tarea="<?= $id ?>" data-nombre="<?= $e($t['nombre']) ?>" data-accion="activar"
                    <?= $falta ? 'disabled title="' . $e($falta) . '"' : '' ?> onclick="abrirDlg(this)">Activar…</button>
          <?php endif; ?>
        </div>
      </div>

      <details style="margin-top:10px">
        <summary>Horario, modo y destinatarios (<?= count(array_filter($t['destinatarios'], fn($d) => (int) $d['activo'] === 1)) ?> activos)</summary>
        <div class="viz-grid" style="margin-top:10px">
          <form method="post" action="automatizacion.php" class="rep" style="margin:0">
            <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
            <input type="hidden" name="accion" value="horario"><input type="hidden" name="tarea" value="<?= $id ?>">
            <b>Horario</b>
            <div class="sub">Días de la semana:</div>
            <?php $sel = explode(',', (string) $t['dias']); foreach (Automatizacion::DIAS as $n => $nom): ?>
              <label style="display:inline-block;margin-right:8px"><input type="checkbox" name="dias[]" value="<?= $n ?>" <?= in_array((string) $n, $sel, true) && empty($t['dia_mes']) ? 'checked' : '' ?>> <?= $e($nom) ?></label>
            <?php endforeach; ?>
            <div class="sub" style="margin-top:6px">…o un día del mes (1 a 28; si lo llenas, manda sobre los días):
              <input type="number" name="dia_mes" min="1" max="28" value="<?= $e((string) ($t['dia_mes'] ?? '')) ?>" style="width:70px"></div>
            <div class="sub" style="margin-top:6px">Hora: <input type="time" name="hora" value="<?= $e(substr((string) $t['hora'], 0, 5)) ?>" required></div>
            <button class="btn sm" type="submit" style="margin-top:8px">Guardar horario</button>
          </form>

          <form method="post" action="automatizacion.php" class="rep" style="margin:0">
            <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
            <input type="hidden" name="accion" value="modo"><input type="hidden" name="tarea" value="<?= $id ?>">
            <b>Modo</b>
            <?php foreach (Automatizacion::MODOS as $k => [$et, $ayuda]): ?>
              <label style="display:block;margin:6px 0"><input type="radio" name="modo" value="<?= $k ?>" <?= $t['modo'] === $k ? 'checked' : '' ?> <?= $activa ? 'disabled' : '' ?>>
                <b><?= $e($et) ?></b> — <span class="sub"><?= $e($ayuda) ?></span></label>
            <?php endforeach; ?>
            <?php if ($activa): ?>
              <div class="sub" style="color:#92400e">Para cambiar el modo, desactiva primero la tarea: pasar a enviar al cliente se vuelve a aprobar.</div>
            <?php else: ?>
              <button class="btn sm" type="submit">Guardar modo</button>
            <?php endif; ?>
            <?php if ($t['asunto']): ?><div class="sub" style="margin-top:8px">Asunto: <span class="mono"><?= $e($t['asunto']) ?></span></div><?php endif; ?>
          </form>
        </div>

        <div class="tabla-wrap" style="margin-top:10px">
          <table class="tarjetas">
            <thead><tr><th>Tipo</th><th>Correo</th><th>Nombre</th><th>Origen</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php if (!$t['destinatarios']): ?><tr><td colspan="6" class="vacio">Sin destinatarios.</td></tr><?php endif; ?>
            <?php foreach ($t['destinatarios'] as $d): $on = (int) $d['activo'] === 1; ?>
              <tr>
                <td data-th="Tipo"><?= $e(Automatizacion::TIPOS[$d['tipo']] ?? $d['tipo']) ?></td>
                <td data-th="Correo" class="mono"><?= $e($d['correo']) ?></td>
                <td data-th="Nombre"><?= $e($d['nombre'] ?? '') ?></td>
                <td data-th="Origen" class="sub"><?= $d['origen'] === 'CORREO_ENVIADO' ? 'del correo enviado' : 'agregado a mano' ?></td>
                <td data-th="Estado"><span class="estado-red <?= $on ? 'con' : 'sin' ?>"><?= $on ? 'activo' : 'desactivado' ?></span></td>
                <td>
                  <form method="post" action="automatizacion.php" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="accion" value="<?= $on ? 'dest_off' : 'dest_on' ?>">
                    <input type="hidden" name="tarea" value="<?= $id ?>"><input type="hidden" name="dest" value="<?= (int) $d['destinatario_id'] ?>">
                    <button class="btn sm" type="submit"><?= $on ? 'Desactivar' : 'Activar' ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <form method="post" action="automatizacion.php" class="filtros" style="margin-top:8px">
          <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
          <input type="hidden" name="accion" value="dest_alta"><input type="hidden" name="tarea" value="<?= $id ?>">
          <label>Tipo <select name="tipo"><?php foreach (Automatizacion::TIPOS as $k => $et): ?><option value="<?= $k ?>"><?= $e($et) ?></option><?php endforeach; ?></select></label>
          <label>Correo <input type="email" name="correo" required maxlength="160" placeholder="nombre@dominio"></label>
          <label>Nombre <input type="text" name="nombre" maxlength="120" placeholder="a quién corresponde"></label>
          <button class="btn" type="submit">Agregar</button>
        </form>

        <p class="sub" style="margin:12px 0 4px"><b>Últimas corridas</b></p>
        <?php if (!$t['corridas']): ?>
          <p class="sub" style="margin:0">Todavía no ha corrido.</p>
        <?php else: ?>
          <ul class="sub" style="margin:0">
            <?php foreach ($t['corridas'] as $c): ?>
              <li><span class="mono"><?= $e(substr((string) $c['programada_para'], 0, 16)) ?></span> ·
                <b><?= $e($c['estado']) ?></b><?= $c['enviado'] !== 'NO' ? ' · enviado a ' . ($c['enviado'] === 'CLIENTE' ? 'el cliente' : 'los revisores') : '' ?>
                <?= (int) $c['manual'] ? ' · a mano' : '' ?><?= $c['mensaje'] ? ' — ' . $e(mb_strimwidth((string) $c['mensaje'], 0, 180, '…', 'UTF-8')) : '' ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </details>
    </section>
  <?php endforeach; ?>

  <h2 id="correos">Correos de las OT INDUSTEC</h2>
  <?= Ui::aviso($correosPendientes > 0 ? 'ambar' : 'neutro',
      '<b>Quién recibe cada OT INDUSTEC emitida</b> -el buzón del jefe de zona, las copias internas y las del cliente, '
      . 'el jefe de operaciones de cada local- se configura en <a href="correos.php"><b>Correos</b></a>, con vista '
      . 'previa de a quién llega.'
      . ($correosPendientes > 0
          ? '<p><b>' . $correosPendientes . '</b> correo(s) de local propuestos por técnicos esperan aprobación.</p>'
          : '<p>Sin propuestas de correo de local pendientes.</p>')) ?>

  <h2>Historial de cambios</h2>
  <?php if (!$cambios): ?>
    <p class="sub">Sin cambios todavía.</p>
  <?php else: ?>
    <div class="tabla-wrap">
      <table class="tarjetas">
        <thead><tr><th>Cuándo</th><th>Tarea</th><th>Qué</th><th>Detalle</th><th>Quién</th></tr></thead>
        <tbody>
        <?php foreach ($cambios as $c): ?>
          <tr>
            <td data-th="Cuándo" class="mono"><?= $e(substr((string) $c['en'], 0, 16)) ?></td>
            <td data-th="Tarea"><?= $e($c['tarea_nombre']) ?></td>
            <td data-th="Qué"><?= $e($c['accion']) ?></td>
            <td data-th="Detalle" class="sub"><?= $e(mb_strimwidth(trim(($c['nota'] ?? '') . ' ' . ($c['despues'] ?? '')), 0, 200, '…', 'UTF-8')) ?></td>
            <td data-th="Quién"><?= $e($c['por_nombre'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <?php endif; /* $hay */ ?>
</div>

<dialog id="dlgAut">
  <form method="post" action="automatizacion.php">
    <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
    <input type="hidden" name="accion" id="dlgAccion" value="">
    <input type="hidden" name="tarea" id="dlgTarea" value="">
    <h2 id="dlgTitulo">Activar</h2>
    <p class="sub" id="dlgSub" style="margin:0 0 10px"></p>
    <textarea name="nota" id="dlgNota" rows="3" maxlength="300"></textarea>
    <div class="acciones" style="margin-top:12px">
      <button class="btn primary" type="submit" id="dlgBoton">Activar</button>
      <button class="btn" type="button" onclick="document.getElementById('dlgAut').close()">Cancelar</button>
    </div>
  </form>
</dialog>
<script>
function abrirDlg(b) {
  const activar = b.dataset.accion === 'activar';
  document.getElementById('dlgAccion').value = b.dataset.accion;
  document.getElementById('dlgTarea').value = b.dataset.tarea;
  document.getElementById('dlgTitulo').textContent = (activar ? 'Activar: ' : 'Desactivar: ') + b.dataset.nombre;
  document.getElementById('dlgSub').textContent = activar
    ? 'Escribe quién aprobó la activación y cómo (por ejemplo, «Aprobado por Isabel Rodríguez por correo del 25/09»). Queda registrado con tu nombre.'
    : 'Motivo (opcional). La tarea deja de correr desde ahora.';
  const n = document.getElementById('dlgNota');
  n.value = ''; n.required = activar; n.minLength = activar ? 10 : 0;
  n.placeholder = activar ? 'Quién aprobó y cómo' : 'Motivo';
  document.getElementById('dlgBoton').textContent = activar ? 'Activar' : 'Desactivar';
  document.getElementById('dlgAut').showModal();
  n.focus();
}
</script>
<?php Ui::pie(); ?>
