<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Pendientes.php';
require_once __DIR__ . '/nucleo/Ui.php';

/**
 * mis.php — La aplicación del técnico. Bandeja, en el celular, y sin señal.
 *
 * ============================================================================
 * POR QUE ES UNA BANDEJA DE CORREO Y NO UNA TABLA
 *
 * El técnico no administra: atiende. Su pregunta es siempre la misma —«¿qué
 * tengo pendiente y qué sigue?»— y esa es exactamente la pregunta que ya
 * responde una bandeja de correo. Nadie tiene que explicarle cómo se usa: lo
 * de arriba es lo más nuevo, lo que no ha abierto resalta, se toca y se abre.
 *
 * Ninguna de las cifras de gestión que ve un jefe de zona aparece aquí. No le
 * sirven y le estorban: él no reparte trabajo, lo hace.
 *
 * ============================================================================
 * LAS CONDICIONES REALES DE USO MANDAN SOBRE CUALQUIER GUSTO
 *
 *   De pie, en la cocina de un local, con una mano y a veces con guantes.
 *     -> Objetivos táctiles grandes, acciones apiladas y no en fila, nada de
 *        menús desplegables ni tablas con desplazamiento horizontal.
 *
 *   Con señal intermitente o sin ninguna.
 *     -> La lista tiene que verse igual sin red, y decir de cuándo es. Lo que
 *        se llene sin señal se guarda en el celular y sale solo al reconectar.
 *
 *   A plena luz, con el brillo al mínimo por batería.
 *     -> Contraste alto y tipografía grande. Nada de gris sobre gris.
 *
 * ============================================================================
 * LAS TRES PESTAÑAS SON EL CICLO DE VIDA DE SU TRABAJO
 *
 *   Pendientes  lo que tiene asignado y todavía no atendió. Es su día.
 *   Esperando   lo que fue a ver y quedó trabado por un equipo. Aquí es donde
 *               insiste — la acción que hoy es un mensaje de WhatsApp que se
 *               pierde y que nadie puede demostrar después.
 *   Atendidas   lo que ya cerró, para consultar y para mandar el informe.
 *
 * ============================================================================
 * EL TECNICO YA NO ELIGE SU NOMBRE
 *
 * Hasta hoy el formulario abría con un desplegable de 19 técnicos y él tenía
 * que buscarse. Eso es un error de diseño y un agujero de trazabilidad a la
 * vez: cuesta un gesto en cada orden, y permite firmar como otro. Ahora la
 * identidad sale de la sesión y viaja con el envío. No se pregunta lo que ya
 * se sabe.
 */

$u = Auth::exigir('ots.crear');
if ($u['debe_cambiar_clave']) { header('Location: clave.php'); exit; }

$e = fn(?string $s): string => Ui::e($s);

$fuente  = Casos::catalogo();
$gestion = Casos::gestion();
$aten    = Casos::atenciones();
/* Al técnico, sus casos salen de la base y no del catálogo del buzón (T2.13.3):
   el catálogo es una ventana que la estación reescribe entera, y un caso
   asignado que quedaba fuera de ella desaparecía de su bandeja sin aviso. */
$esTecnico = $u['rol'] === 'TECNICO';
$mios    = $esTecnico
         ? Casos::delTecnico((int) $u['usuario_id'],
                             array_merge(Casos::ABIERTOS_TECNICO, Casos::CERRADOS_TECNICO), $gestion)
         : Casos::enAlcance($fuente['datos'] ?? [], $gestion);

Auth::bitacora('CONSULTAR', 'bandeja', 'mis', 'visibles=' . count($mios));

/* =========================================================================
   LAS DOS ACCIONES QUE EL TECNICO HACE DESDE AQUI.
   Las dos revalidan permiso, alcance y dato EN EL SERVIDOR. Que el botón esté
   escondido no protege nada: un POST se fabrica a mano desde cualquier lado.
   Se responde con redirección (POST-redirect-GET) para que recargar no repita
   la acción — y en un celular con mala señal, recargar es lo primero que se
   hace cuando la pantalla se queda pensando.
   ========================================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $vuelta = 'mis.php';

    if ($accion === 'no_concluye') {
        // «Fui, y el equipo quedó trabado.» Es la única salida legítima a la
        // premisa de que una intervención concluye el trabajo, así que exige
        // decir qué se encontró.
        [$ok, $msg, $id] = Pendientes::abrir([
            'aviso'         => $_POST['aviso'] ?? '',
            'activo_fijo'   => $_POST['activo_fijo'] ?? '',
            'equipo_desc'   => $_POST['equipo_desc'] ?? '',
            'diagnostico'   => $_POST['diagnostico'] ?? '',
            'parte'         => $_POST['parte'] ?? '',
            'deshabilitado' => isset($_POST['deshabilitado']),
            'nota'          => $_POST['nota'] ?? '',
        ]);
        $_SESSION['flash'] = $ok ? ['ok' => $msg] : ['error' => $msg];
        $vuelta = 'mis.php?ver=' . rawurlencode((string) ($_POST['aviso'] ?? ''));

    } elseif ($accion === 'insistir') {
        [$ok, $msg] = Pendientes::insistir(
            (int) ($_POST['pendiente_id'] ?? 0),
            (string) ($_POST['texto'] ?? ''),
            isset($_POST['urgente'])
        );
        $_SESSION['flash'] = $ok ? ['ok' => $msg] : ['error' => $msg];
        $vuelta = 'mis.php?t=esperando';

    } else {
        Auth::bitacora('DENEGADO', 'bandeja', 'mis', "accion=$accion desconocida",
                       null, null, [], false);
        $_SESSION['flash'] = ['error' => 'Acción no reconocida.'];
    }
    header('Location: ' . $vuelta);
    exit;
}

/* =========================================================================
   LA FICHA DE UN CASO.
   Todo lo que el técnico necesita para decidir qué hace, y las tres cosas que
   puede hacer, en botones del ancho de la pantalla. Nada más: cada elemento
   de más en esta pantalla es un toque equivocado con guantes puestos.
   ========================================================================= */
if (isset($_GET['ver'])) {
    $avisoVer = (string) $_GET['ver'];
    $caso = null;
    foreach ($mios as $c) { if (($c['aviso'] ?? '') === $avisoVer) { $caso = $c; break; } }

    if ($caso === null) {
        // No es suyo o no existe. Se dice sin rodeos y sin filtrar si el caso
        // existe para otro: eso ya sería contar algo de otra zona.
        Auth::bitacora('DENEGADO', 'caso', $avisoVer, 'ver fuera de su alcance',
                       null, null, [], false);
        http_response_code(404);
        $_SESSION['flash'] = ['error' => 'Ese caso no está entre los tuyos.'];
        header('Location: mis.php');
        exit;
    }

    $g   = $gestion[$avisoVer] ?? [];
    $est = $g['estado'] ?? 'NUEVO';
    $a   = $aten[$avisoVer] ?? null;
    $sinCat = !empty($caso['sin_catalogo']);
    // Lo cerrado es historial: se consulta, pero no se vuelve a emitir ni a reportar.
    $abierto = !$esTecnico || in_array($est, Casos::ABIERTOS_TECNICO, true);
    $pendCaso = Pendientes::disponible()
        ? array_values(array_filter(Pendientes::lista(['grupo' => 'abiertos']),
                                    fn($p) => (string) $p['aviso'] === $avisoVer))
        : [];
    $errFlash = Ui::errorFlash();
    $okFlash  = $_SESSION['flash']['ok'] ?? null;
    unset($_SESSION['flash']);
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b4f8f">
<title><?= $e($caso['local'] ?? 'Caso') ?> · B.IA Soft ERP</title>
<link rel="stylesheet" href="estilo.css">
</head>
<body class="con-nav-abajo">
<div id="estadoRed" class="estado-red" hidden></div>

<header class="mov-cab">
  <div class="fila">
    <a class="btn sm" href="mis.php<?= $abierto ? '' : '?t=atendidas' ?>"
       aria-label="<?= $abierto ? 'Volver a la bandeja' : 'Volver al historial' ?>">←</a>
    <div style="flex:1;min-width:0">
      <div class="hola"><?= $sinCat ? 'Aviso ' . $e($avisoVer) : $e($caso['local'] ?? '—') ?></div>
      <div class="quien"><?= $sinCat ? 'sin dato en el catálogo'
                                     : $e($caso['local_nombre'] ?? $caso['restaurante_sap'] ?? '') ?></div>
    </div>
  </div>
</header>

<?php if ($okFlash): ?><div style="padding:12px 14px 0"><?= Ui::aviso('ok', $e((string) $okFlash), true) ?></div><?php endif; ?>
<?php if ($errFlash): ?><div style="padding:12px 14px 0"><?= Ui::aviso('err', $e($errFlash), true) ?></div><?php endif; ?>

<div class="mov-ficha">
  <span class="aviso-n">Aviso <?= $e($avisoVer) ?><?= !empty($caso['orden_trabajo']) ? ' · orden ' . $e($caso['orden_trabajo']) : '' ?></span>
  <h1><?= $e($caso['caso'] ?? 'Sin descripción') ?></h1>
  <div class="chips">
    <?= Ui::prioridad($caso['prioridad'] ?? null) ?>
    <?= Ui::estado($est) ?>
    <?= Ui::edad($caso['fecha_creacion'] ?? null) ?>
  </div>

  <?php if ($sinCat): ?>
    <div style="margin-top:12px">
      <?= Ui::aviso('info', '<b>Este caso no está en el listado del buzón.</b>'
        . '<p>Solo se conoce su número de aviso: el local, el equipo y el pedido no llegaron por '
        . 'correo o ya salieron de la ventana del buzón. Si te falta un dato, pregúntale a tu jefe de zona.</p>') ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($caso['descripcion_trabajo'])): ?>
    <div class="ficha" style="margin-top:12px">
      <div class="cita" style="margin-top:0"><?= $e($caso['descripcion_trabajo']) ?></div>
    </div>
  <?php endif; ?>

  <dl>
    <?php if (!empty($caso['activo_fijo'])): ?>
      <dt>Equipo</dt><dd><?= $e($caso['activo_fijo']) ?></dd>
    <?php endif; ?>
    <dt>Creado</dt><dd class="mono"><?= $e($caso['fecha_creacion'] ?? '—') ?></dd>
    <?php if (!empty($g['asignado_en'])): ?>
      <dt>Asignado</dt><dd class="mono"><?= $e(substr((string) $g['asignado_en'], 0, 16)) ?></dd>
    <?php endif; ?>
    <dt>Comprometido</dt>
    <dd class="mono">
      <?= $e($caso['fecha_estimada'] ?? '—') ?>
      <?php if (!empty($caso['fecha_estimada']) && $caso['fecha_estimada'] < date('Y-m-d')): ?>
        <span class="desc">SAP casi siempre compromete para el día siguiente; que esté
          pasada no significa por sí solo que vayas atrasado.</span>
      <?php endif; ?>
    </dd>
  </dl>
</div>

<?php /* Lo que ya quedó trabado en este caso, con su reloj y su hilo. */ ?>
<?php foreach ($pendCaso as $p): ?>
  <div style="padding:14px 14px 0">
    <div class="rep <?= !empty($p['reloj']) && $p['reloj']['vencido'] ? 'vencido' : ($p['deshabilitado'] ? 'deshabilitado' : '') ?>">
      <div class="cab">
        <div style="min-width:0">
          <div class="que"><?= $e($p['equipo_desc'] ?: ($p['activo_fijo'] ?: 'Equipo')) ?></div>
          <div class="meta">
            <?= $e(Pendientes::etiquetaVia($p['via'])) ?> ·
            <?= $e(Pendientes::etiquetaEstado($p['estado'])) ?>
            <?php if (!empty($p['prometido_para'])): ?>
              · comprometido para <?= $e($p['prometido_para']) ?>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($p['reloj'])): ?>
          <span class="<?= $e($p['reloj']['clase']) ?>"><?= $e($p['reloj']['texto']) ?></span>
        <?php endif; ?>
      </div>
      <?= Pendientes::barra48($p) ?>
      <div class="nota"><?= $e($p['diagnostico']) ?></div>

      <?php $hilo = Pendientes::notas((int) $p['pendiente_id']); ?>
      <?php if ($hilo): ?>
        <div class="hilo">
          <?php foreach (array_slice($hilo, -3) as $nt): ?>
            <div class="msg <?= $nt['urgente'] ? 'insiste' : '' ?>">
              <span class="quien"><?= $e($nt['nombre']) ?></span>
              <span class="cuando" data-hace="<?= $e($nt['creado_en']) ?>"><?= $e(substr((string) $nt['creado_en'], 0, 16)) ?></span>
              <div class="txt"><?= $e($nt['texto']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (Auth::puede('repuestos.pedir')): ?>
        <form method="post" style="margin-top:11px">
          <input type="hidden" name="accion" value="insistir">
          <input type="hidden" name="pendiente_id" value="<?= (int) $p['pendiente_id'] ?>">
          <label for="ins<?= (int) $p['pendiente_id'] ?>">Recordarle a la administración</label>
          <textarea id="ins<?= (int) $p['pendiente_id'] ?>" name="texto" rows="2" required
            placeholder="p. ej. el administrador insiste en que envíen el repuesto urgente, el equipo sigue deshabilitado"></textarea>
          <label style="display:flex;align-items:center;gap:8px;margin:9px 0 0;font-size:13.5px;color:var(--ink)">
            <input type="checkbox" name="urgente" value="1"
                   style="width:auto;height:auto;margin:0">
            Marcarlo como urgente
          </label>
          <button class="btn primary" type="submit" style="width:100%;margin-top:10px;padding:12px">
            Enviar recordatorio
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($a !== null && !empty($a['ots'])): ?>
  <div style="padding:14px 14px 0">
    <?php foreach ($a['ots'] as $o): ?>
      <div class="rep atendido">
        <div class="cab">
          <div><div class="que">Orden <?= $e($o['ot']) ?></div>
            <div class="meta"><?= $e(substr((string) $o['fecha'], 0, 10)) ?> ·
              <?= ($o['estado_ot'] ?? '') === 'Cerrada' ? 'con cierre' : 'en curso' ?></div></div>
          <?php if (Auth::puede('ots.pdf')): ?>
            <a class="btn sm" href="pdf.php?ot=<?= rawurlencode((string) $o['ot']) ?>"
               target="_blank" rel="noopener">Ver PDF</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php /* La orden que cerró el caso, de la base (T2.13.3). Si ya salió arriba entre
         las del informe no se repite; si no hay, se dice en vez de callarlo (I-7). */ ?>
<?php if (!$abierto && !in_array($g['ot_cierre'] ?? null, array_column($a['ots'] ?? [], 'ot'), true)): ?>
  <div style="padding:14px 14px 0">
    <?php if (!empty($g['ot_cierre'])): ?>
      <div class="rep atendido">
        <div class="cab">
          <div><div class="que">Orden de cierre <?= $e($g['ot_cierre']) ?></div>
            <div class="meta"><?= $e(substr((string) ($g['atendido_en'] ?? ''), 0, 10)) ?> ·
              <?= $e(Casos::etiquetaEstado($est)) ?></div></div>
          <?php if (Auth::puede('ots.pdf')): ?>
            <a class="btn sm" href="pdf.php?ot=<?= rawurlencode((string) $g['ot_cierre']) ?>"
               target="_blank" rel="noopener">Ver PDF</a>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <?= Ui::aviso('info', '<b>No hay PDF de cierre registrado para este caso.</b>'
        . '<p>Quedó como «' . $e(Casos::etiquetaEstado($est)) . '» sin una orden de cierre asociada.</p>') ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="mov-acciones">
  <?php if ($abierto): ?>
    <a class="btn primary" href="index.html?aviso=<?= rawurlencode($avisoVer) ?>">
      Emitir la orden de este caso
    </a>
  <?php else: ?>
    <p class="sub" style="margin:0;text-align:center">Este caso ya está cerrado: queda aquí para consultarlo.</p>
  <?php endif; ?>

  <?php if ($abierto && Auth::puede('repuestos.pedir') && !$pendCaso): ?>
    <button class="btn ghost" type="button" onclick="document.getElementById('dlgTrabado').showModal()">
      No pude concluir: el equipo quedó trabado
    </button>
  <?php endif; ?>
</div>

<?php if ($abierto && Auth::puede('repuestos.pedir') && !$pendCaso): ?>
<dialog id="dlgTrabado">
  <form method="post">
    <input type="hidden" name="accion" value="no_concluye">
    <input type="hidden" name="aviso" value="<?= $e($avisoVer) ?>">
    <input type="hidden" name="activo_fijo" value="<?= $e($caso['activo_fijo'] ?? '') ?>">

    <h2>El equipo quedó sin concluir</h2>
    <p class="sub" style="margin:0 0 14px">
      La intervención debería cerrar el trabajo en la visita. Cuando no se puede,
      hay que dejar por escrito qué encontraste: es lo que sostiene el veredicto
      de tu jefe de zona y la respuesta a Grupo KFC.
    </p>

    <div style="margin-bottom:12px">
      <label for="td">Qué encontraste y por qué no se pudo cerrar</label>
      <textarea id="td" name="diagnostico" rows="3" required
        placeholder="p. ej. la resistencia de la freidora 2 está abierta; se midió continuidad y no pasa"></textarea>
    </div>

    <div style="margin-bottom:12px">
      <label for="te">Qué equipo es</label>
      <input type="text" id="te" name="equipo_desc"
             value="<?= $e($caso['activo_fijo'] ?? '') ?>"
             placeholder="freidora 2, cámara de frío, plancha…">
    </div>

    <div style="margin-bottom:12px">
      <label for="tp">Qué haría falta, si ya lo sabes</label>
      <input type="text" id="tp" name="parte"
             placeholder="resistencia de 5 kW — opcional">
      <span class="derivado">
        Si no estás seguro, déjalo vacío. Tu jefe de zona decide la vía: comprar
        el repuesto, mandarlo a reparación, reclamar garantía o darlo de baja.
      </span>
    </div>

    <label style="display:flex;align-items:flex-start;gap:9px;padding:11px;border:1px solid var(--danger-bd);
                  background:var(--danger-bg);border-radius:var(--r);font-size:13.5px;color:#7f1d1d">
      <input type="checkbox" name="deshabilitado" value="1" style="width:auto;height:auto;margin:2px 0 0">
      <span><b>El equipo quedó fuera de servicio.</b>
        Marca esto solo si el local no lo puede usar. Enciende el plazo de
        <b>48 horas</b> para que se decida qué se hace, y aparece en rojo en el
        tablero de tu jefe de zona.</span>
    </label>

    <div class="row" style="margin-top:16px;gap:8px">
      <button class="btn primary" type="submit">Registrar</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<nav class="nav-abajo" aria-label="Principal">
  <a href="mis.php" class="<?= $abierto ? 'on' : '' ?>"><span class="ic">▤</span>Bandeja</a>
  <a href="mis.php?t=atendidas" class="<?= $abierto ? '' : 'on' ?>"><span class="ic">✓</span>Historial</a>
  <a href="index.html"><span class="ic">✎</span>Emitir</a>
  <a href="pendientes.php"><span class="ic">◷</span>Repuestos</a>
  <a href="cronograma.html"><span class="ic">▦</span>Preventivos</a>
</nav>

<div id="toasts" role="status" aria-live="polite"></div>
<script src="ui.js"></script>
<script src="offline.js"></script>
</body>
</html>
    <?php
    exit;
}

/* -------------------------------------------------------------------------
   El reparto en las tres pestañas.
   ------------------------------------------------------------------------- */
$grupos = ['pendientes' => [], 'esperando' => [], 'atendidas' => []];
foreach ($mios as $c) {
    $aviso  = (string) ($c['aviso'] ?? '');
    $estado = $gestion[$aviso]['estado'] ?? 'NUEVO';
    $c['_estado'] = $estado;
    $c['_gestion'] = $gestion[$aviso] ?? [];
    $c['_aten'] = $aten[$aviso] ?? null;

    if ($estado === 'ESPERA_REPUESTO') {
        $grupos['esperando'][] = $c;
    } elseif (in_array($estado, Casos::CERRADOS_TECNICO, true)
              // Al técnico lo reparte el estado de la base: un caso ASIGNADO con
              // una orden todavía abierta sigue siendo trabajo suyo (T2.13.3).
              || (!$esTecnico && isset($aten[$aviso]))) {
        $grupos['atendidas'][] = $c;
    } else {
        $grupos['pendientes'][] = $c;
    }
}

// Lo urgente arriba y, con la misma prioridad, lo más viejo: es lo que lleva
// más tiempo esperando y lo que primero se cierra por falta de atención.
$ordPrio = ['ALTA' => 0, 'MEDIA' => 1, 'BAJA' => 2];
usort($grupos['pendientes'], function ($a, $b) use ($ordPrio) {
    $pa = $ordPrio[$a['prioridad'] ?? ''] ?? 3;
    $pb = $ordPrio[$b['prioridad'] ?? ''] ?? 3;
    if ($pa !== $pb) { return $pa <=> $pb; }
    return strcmp((string) ($a['fecha_creacion'] ?? ''), (string) ($b['fecha_creacion'] ?? ''));
});
// El historial, de lo último que se atendió hacia atrás.
$cuandoCerro = fn(array $c): string => (string) ($c['_gestion']['atendido_en'] ?? $c['fecha_creacion'] ?? '');
usort($grupos['atendidas'], fn($a, $b) => strcmp($cuandoCerro($b), $cuandoCerro($a)));

$tab = (string) ($_GET['t'] ?? 'pendientes');
if (!isset($grupos[$tab])) { $tab = 'pendientes'; }

/* Los pendientes de equipo que él abrió, para la pestaña «Esperando». */
$misPend = Pendientes::disponible() ? Pendientes::lista(['grupo' => 'abiertos']) : [];
$pendPorAviso = [];
foreach ($misPend as $p) { $pendPorAviso[(string) $p['aviso']][] = $p; }

/* Las órdenes que mandó desde la app, para su historial (T2.13.3). Todavía no
   generan PDF —eso llega con la emisión, la 008— y la pantalla lo dice. La
   tabla es de la misma migración que la de pendientes. */
$capturas = [];
if ($esTecnico && Pendientes::disponible()) {
    $capturas = Db::todos(
        'SELECT aviso, local_codigo, capturada_en, estado, motivo_rechazo
           FROM ot_capturadas WHERE usuario_id = ? ORDER BY capturada_en DESC LIMIT 60',
        [(int) $u['usuario_id']]
    );
}
$enviadas  = array_count_values(array_filter(array_map(fn($k) => (string) ($k['aviso'] ?? ''), $capturas)));
$misAvisos = array_flip(array_map(fn($c) => (string) ($c['aviso'] ?? ''), $mios));

$errFlash = Ui::errorFlash();
$okFlash  = $_SESSION['flash']['ok'] ?? null;
unset($_SESSION['flash']);

$ETIQ = ['pendientes' => 'Pendientes', 'esperando' => 'Esperando', 'atendidas' => 'Atendidas'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b4f8f">
<title>Mis órdenes · B.IA Soft ERP</title>
<link rel="stylesheet" href="estilo.css">
<link rel="manifest" href="manifest.json">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="B.IA Soft">
<link rel="apple-touch-icon" href="iconos/icono-192.png">
</head>
<body class="con-nav-abajo">

<?php /* La franja de conexión la pinta `offline.js` cuando hace falta. Va
         antes de la cabecera para que empuje el contenido y no lo tape. */ ?>
<div id="estadoRed" class="estado-red" hidden></div>

<header class="mov-cab">
  <div class="fila">
    <div style="flex:1;min-width:0">
      <div class="hola">Hola, <?= $e(Ui::nombrePila($u['nombre'])) ?></div>
      <div class="quien">
        Técnico<?= $u['zona'] ? ' · ' . $e((string) $u['zona']) : '' ?>
      </div>
    </div>
    <a class="btn sm" href="salir.php">Salir</a>
  </div>

  <nav class="mov-tabs" aria-label="Mis órdenes">
    <?php foreach ($ETIQ as $k => $et): ?>
      <?php
      $cn = count($grupos[$k]) + ($k === 'atendidas' ? count($capturas) : 0);
      // El globo de «Esperando» se pone rojo si alguno de sus equipos pasó de
      // las 48 h sin veredicto: es lo único de esta pantalla que corre contra
      // reloj, y el técnico es quien puede empujarlo insistiendo.
      $urge = false;
      if ($k === 'esperando') {
          foreach ($misPend as $p) {
              if (!empty($p['reloj']) && $p['reloj']['vencido'] && empty($p['reloj']['cerrado'])) {
                  $urge = true; break;
              }
          }
      }
      ?>
      <a href="?t=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"
         <?= $tab === $k ? 'aria-current="page"' : '' ?>>
        <?= $e($et) ?>
        <?php if ($cn): ?><span class="n <?= $urge ? 'urge' : '' ?>"><?= $cn ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
</header>

<?php if ($okFlash): ?>
  <div style="padding:12px 14px 0"><?= Ui::aviso('ok', $e((string) $okFlash), true) ?></div>
<?php endif; ?>
<?php if ($errFlash): ?>
  <div style="padding:12px 14px 0"><?= Ui::aviso('err', $e($errFlash), true) ?></div>
<?php endif; ?>

<?php /* La cola de envíos. La pinta `cola.js` leyendo lo que hay guardado en el
         celular. Es la pieza que hace que el técnico confíe en trabajar sin
         conexión: si no ve dónde quedó su orden, la vuelve a llenar, y entonces
         sí llegan dos PDFs a Grupo KFC. */ ?>
<div id="cola" hidden></div>

<?php if (!$fuente): ?>
  <div style="padding:14px">
    <?= Ui::aviso('warn',
        '<b>No se pudo leer el listado de casos.</b>'
      . '<p>Si estás sin señal, lo que ves es lo último que se guardó en el celular. '
      . 'Puedes seguir llenando órdenes: se envían solas al reconectar.</p>') ?>
  </div>
<?php endif; ?>

<main>
<?php
$lista = $grupos[$tab];
$verCapturas = $tab === 'atendidas' && $capturas;

if (!$lista && !$verCapturas):
    /* El vacío también informa. Cada pestaña vacía significa algo distinto y
       decir «no hay nada» en las tres sería desperdiciar el único momento en
       que el técnico tiene toda la pantalla para leer. */
    $vacios = [
        'pendientes' => ['✓', 'No tienes órdenes pendientes',
            'Cuando la administración o tu jefe de zona te asignen un caso, aparece aquí. '
          . 'Si atendiste algo sin orden asignada, puedes emitirla igual con el botón azul.'],
        'esperando'  => ['—', 'No tienes equipos trabados',
            'Aquí aparecen los equipos que fuiste a ver y que quedaron esperando un repuesto, '
          . 'una reparación o una garantía. Desde aquí les puedes insistir a la administración.'],
        'atendidas'  => ['—', 'Todavía no hay órdenes tuyas atendidas',
            'En cuanto emitas una orden y llegue su informe al buzón de la empresa, aparece aquí '
          . 'con su PDF para consultarlo o mandárselo al administrador del local.'],
    ][$tab];
?>
  <div class="vacio" style="padding:44px 24px">
    <span class="icono"><?= $vacios[0] ?></span>
    <b style="display:block;font-size:15px;color:var(--ink);margin-bottom:6px"><?= $e($vacios[1]) ?></b>
    <span style="display:block;max-width:44ch;margin:0 auto;line-height:1.55"><?= $e($vacios[2]) ?></span>
  </div>
<?php else: ?>

  <?php if ($tab === 'esperando' && !Pendientes::disponible()): ?>
    <div style="padding:14px">
      <?= Ui::aviso('info',
          '<b>Los recordatorios todavía no están activos.</b>'
        . '<p>Falta aplicar la migración que crea la tabla. Mientras tanto puedes ver '
        . 'aquí qué quedó esperando, pero no insistir desde la aplicación.</p>') ?>
    </div>
  <?php endif; ?>

  <?php if ($lista): ?>
  <ul class="bandeja">
    <?php foreach ($lista as $i => $c): ?>
      <?php
      $aviso = (string) ($c['aviso'] ?? '');
      $prio  = strtolower((string) ($c['prioridad'] ?? '')) ?: 'sd';
      $sinCat = !empty($c['sin_catalogo']);
      // Sin fecha del catálogo, la edad se cuenta desde que se lo asignaron.
      $dias  = Ui::dias($c['fecha_creacion'] ?? ($c['_gestion']['asignado_en'] ?? null));
      $pendCaso = $pendPorAviso[$aviso] ?? [];
      // «Nuevo» = asignado y sin abrir todavía. Es el punto azul del correo, y
      // se apoya en el estado, no en una marca de lectura que habría que
      // mantener: un caso deja de ser nuevo cuando alguien lo movió.
      $sinAbrir = $c['_estado'] === 'NUEVO' || $c['_estado'] === 'ASIGNADO';
      ?>
      <li style="--i:<?= $i ?>" class="<?= $sinAbrir ? '' : 'leido' ?>">
        <a href="?ver=<?= rawurlencode($aviso) ?>" class="p-<?= $e($prio) ?>">
          <span class="marca"><?php if ($sinAbrir): ?><i class="nuevo"></i><?php endif; ?></span>
          <span class="cuerpo">
            <span class="lin1">
              <span class="local"><?php if ($sinCat): ?>Aviso <?= $e($aviso) ?> · sin dato en el catálogo<?php else: ?><?= $e($c['local'] ?? '—') ?> ·
                <?= $e($c['local_nombre'] ?? $c['restaurante_sap'] ?? '') ?><?php endif; ?></span>
              <span class="cuando"><?= $dias === null ? '' : ($dias <= 0 ? 'hoy' : ($dias === 1 ? 'ayer' : $dias . ' d')) ?></span>
            </span>
            <span class="asunto"><?= $e($c['caso'] ?? ($sinCat ? 'No está en el listado del buzón' : 'Sin descripción')) ?></span>
            <?php if (!empty($c['descripcion_trabajo'])): ?>
              <span class="vista"><?= $e($c['descripcion_trabajo']) ?></span>
            <?php endif; ?>
            <span class="pie">
              <?= Ui::prioridad($c['prioridad'] ?? null) ?>
              <?php if (!empty($c['activo_fijo'])): ?>
                <span class="chip"><?= $e($c['activo_fijo']) ?></span>
              <?php endif; ?>
              <?php foreach ($pendCaso as $p): ?>
                <?php if (!empty($p['reloj'])): ?>
                  <span class="<?= $e($p['reloj']['clase']) ?>"><?= $e($p['reloj']['texto']) ?></span>
                <?php endif; ?>
                <?php if ((int) $p['insistencias'] > 0): ?>
                  <span class="chip">insististe <?= (int) $p['insistencias'] ?> ve<?= (int) $p['insistencias'] === 1 ? 'z' : 'ces' ?></span>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if ($tab === 'atendidas' && !empty($c['_aten']['ots'])): ?>
                <span class="chip cerrada"><?= count($c['_aten']['ots']) ?> orden<?= count($c['_aten']['ots']) === 1 ? '' : 'es' ?></span>
              <?php endif; ?>
              <?php if ($tab !== 'atendidas' && !empty($enviadas[$aviso])): ?>
                <span class="chip">enviaste una orden</span>
              <?php endif; ?>
            </span>
          </span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <?php if ($verCapturas): ?>
    <?php $ETIQ_CAP = ['RECIBIDA' => 'recibida en la oficina', 'PROCESADA' => 'procesada', 'RECHAZADA' => 'rechazada']; ?>
    <div style="padding:14px">
      <h2 style="font-size:14px;margin:0 0 10px;color:var(--ink)">Órdenes que enviaste desde la app</h2>
      <?php foreach ($capturas as $k): ?>
        <?php $avk = (string) ($k['aviso'] ?? ''); ?>
        <div class="rep atendido" style="margin-bottom:10px">
          <div class="cab">
            <div style="min-width:0">
              <div class="que"><?= $avk !== '' ? 'Aviso ' . $e($avk) : 'Sin aviso' ?> · <?= $e($k['local_codigo'] ?? '—') ?></div>
              <div class="meta">llenada el <?= $e(substr((string) $k['capturada_en'], 0, 16)) ?> ·
                <?= $e($ETIQ_CAP[$k['estado']] ?? strtolower((string) $k['estado'])) ?></div>
            </div>
            <?php if ($avk !== '' && isset($misAvisos[$avk])): ?>
              <a class="btn sm" href="?ver=<?= rawurlencode($avk) ?>">Ver el caso</a>
            <?php endif; ?>
          </div>
          <div class="nota"><?= $k['estado'] === 'RECHAZADA'
              ? $e('Motivo: ' . ($k['motivo_rechazo'] ?? 'sin dato'))
              : 'El PDF de esta orden todavía no se genera desde la app.' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
</main>

<?php /* El botón flotante. Emitir una orden es LA acción del técnico y tiene que
         estar a un pulgar de distancia desde cualquier punto de la lista. */ ?>
<a class="fab" href="index.html" aria-label="Emitir una orden de trabajo" title="Emitir una orden">+</a>

<nav class="nav-abajo" aria-label="Principal">
  <a href="mis.php" class="<?= $tab === 'atendidas' ? '' : 'on' ?>"><span class="ic">▤</span>Bandeja
    <?php if (count($grupos['pendientes'])): ?>
      <span class="globo"><?= count($grupos['pendientes']) ?></span>
    <?php endif; ?>
  </a>
  <?php /* El historial es la pestaña «Atendidas»; va en la barra porque es lo
           segundo que busca el técnico: la orden que ya mandó (T2.13.3). */ ?>
  <a href="mis.php?t=atendidas" class="<?= $tab === 'atendidas' ? 'on' : '' ?>"><span class="ic">✓</span>Historial</a>
  <a href="index.html"><span class="ic">✎</span>Emitir</a>
  <a href="pendientes.php"><span class="ic">◷</span>Repuestos
    <?php $pc = Pendientes::contadores(); if ($pc['abiertos']): ?>
      <span class="globo"><?= $pc['abiertos'] ?></span>
    <?php endif; ?>
  </a>
  <a href="cronograma.html"><span class="ic">▦</span>Preventivos</a>
</nav>

<div id="toasts" role="status" aria-live="polite"></div>
<script src="ui.js"></script>
<script src="offline.js"></script>
<script src="cola.js"></script>
</body>
</html>
