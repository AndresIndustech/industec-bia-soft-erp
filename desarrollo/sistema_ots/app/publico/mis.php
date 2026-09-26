<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Pendientes.php';
require_once __DIR__ . '/nucleo/Ui.php';
// H-02: `Emision::existePdf()` hace falta también en la ficha del caso
// (más abajo, antes del `require_once` de más adelante), así que se carga
// aquí arriba y sirve para las dos ramas de este archivo.
require_once __DIR__ . '/nucleo/Emision.php';
// Las palabras de la barra, de las pestañas y de los estados salen del
// diccionario único (vocabulario.json): las mismas que ven la oficina y KFC.
require_once __DIR__ . '/nucleo/Vocabulario.php';

/**
 * mis.php — La aplicación del técnico. «Mis órdenes», en el celular, y sin señal.
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
 * (los rótulos salen del diccionario; las claves `t=` de la URL no cambian)
 *
 *   Asignadas             (t=pendientes) lo que tiene asignado y todavía no
 *                         atendió. Es su día.
 *   A espera de repuesto  (t=esperando) lo que fue a ver y el equipo no quedó
 *                         operativo. Aquí es donde insiste — la acción que hoy
 *                         es un mensaje de WhatsApp que se pierde y que nadie
 *                         puede demostrar después.
 *   Historial             (t=atendidas) lo que ya salió de sus manos, para
 *                         consultar y para mandar la OT INDUSTEC.
 *   Notificaciones        (t=avisos, solo el técnico) lo que le pasó.
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
/* Pedido de INDUSTEC (2026-09-24): el jefe de zona también se asigna casos y los
   atiende. Para «sus» casos se lo trata como a un técnico: ve los asignados a él,
   no todo el buzón de la zona (eso lo tiene en casos.php). */
$propios = in_array($u['rol'], ['TECNICO', 'JEFE_ZONA'], true);
$mios    = $propios
         ? Casos::delTecnico((int) $u['usuario_id'],
                             array_merge(Casos::ABIERTOS_TECNICO, Casos::CERRADOS_TECNICO), $gestion)
         : Casos::enAlcance($fuente['datos'] ?? [], $gestion);

/* El tab se resuelve aquí, ANTES del bloque de avisos (H-12): es lo que
   decide si esta visita cuenta como «abrió la pestaña Avisos» o no. Antes se
   calculaba más abajo, contra `$grupos` (que todavía no existe en este
   punto), así que había que repetirlo; con la lista fija de pestañas válidas
   alcanza con calcularlo una sola vez. */
$tab = (string) ($_GET['t'] ?? 'pendientes');
if (!in_array($tab, ['pendientes', 'esperando', 'atendidas'], true) && !($tab === 'avisos' && $esTecnico)) {
    $tab = 'pendientes';
}

/* Los avisos del técnico (T2.13.5): lo que le pasó en las dos últimas semanas y
   qué es nuevo desde su visita anterior.
   H-12: «visto» se marca SOLO al abrir la pestaña Avisos, con su propia
   acción (`VER_AVISOS`) -- antes cualquier GET a mis.php (abrir una ficha,
   cambiar a Pendientes) contaba como «ya los vio», y el técnico nunca
   alcanzaba a leerlos con el marcador puesto. El `CONSULTAR` de más abajo
   sigue existiendo, para auditoría, pero ya no es lo que mueve el «visto». */
$avisos = [];
$vistoAntes = null;
if ($esTecnico) {
    require_once __DIR__ . '/nucleo/Avisos.php';
    $vistoAntes = Avisos::visto((int) $u['usuario_id']);
    $avisos = Avisos::delTecnico((int) $u['usuario_id'], date('Y-m-d H:i:s', time() - 14 * 86400));
    if ($tab === 'avisos') {
        Auth::bitacora('VER_AVISOS', 'bandeja', 'mis', 'avisos=' . count($avisos));
        Avisos::marcarSeguimientosVistos((int) $u['usuario_id']);
    }
}
$esNuevo = fn(array $a): bool => $vistoAntes === null || $a['cuando'] > $vistoAntes;
$nuevos  = count(array_filter($avisos, $esNuevo));

Auth::bitacora('CONSULTAR', 'bandeja', 'mis', 'visibles=' . count($mios));

/* Puede declarar que un caso continúa un trabajo anterior (T2.25). Mientras la
   012 no esté aplicada el permiso no existe todavía, y entonces manda el rol:
   es el mismo respaldo que usa `Ui::puedeModulo()`, y evita que la pantalla se
   quede sin la salida nueva por una migración pendiente. */
$puedeContinuidad = Ui::puedeModulo('casos.continuidad',
                                    ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO'], $u);

/* =========================================================================
   LAS ACCIONES QUE EL TECNICO HACE DESDE AQUI.
   Todas revalidan permiso, alcance y dato EN EL SERVIDOR. Que el botón esté
   escondido no protege nada: un POST se fabrica a mano desde cualquier lado.
   Se responde con redirección (POST-redirect-GET) para que recargar no repita
   la acción — y en un celular con mala señal, recargar es lo primero que se
   hace cuando la pantalla se queda pensando.
   ========================================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::exigirCsrf();
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

    } elseif ($accion === 'continua' || $accion === 'descontinua') {
        /* «Este caso es la continuación de un trabajo que ya empecé» (T2.25.2).
           SAP cierra el aviso que nadie atendió en 48 h y KFC abre otro por el
           mismo equipo; sin esto el técnico o emite una orden duplicada o deja
           el caso pendiente para siempre.

           El alcance se revalida aquí aunque el botón ya lo haya comprobado:
           enlazar un caso lo deja ATENDIDO ante la administración, así que un
           POST fabricado no puede cerrar el caso de otra zona. */
        $avisoN = trim((string) ($_POST['aviso'] ?? ''));
        $vuelta = 'mis.php?ver=' . rawurlencode($avisoN);
        $suyo = null;
        foreach ($mios as $c) { if (($c['aviso'] ?? '') === $avisoN) { $suyo = $c; break; } }

        if (!$puedeContinuidad || $suyo === null) {
            Auth::bitacora('DENEGADO', 'caso', $avisoN,
                           $suyo === null ? 'continuidad sobre una orden fuera de su alcance'
                                          : 'continuidad sin permiso',
                           null, null, [], false);
            $_SESSION['flash'] = ['error' => 'Esa orden no está entre las tuyas.'];
        } elseif ($accion === 'descontinua') {
            try {
                [$ok, $msg] = Casos::desenlazar($avisoN, (int) $u['usuario_id'],
                                                (string) ($_POST['nota'] ?? ''));
            } catch (Throwable $ex) {
                error_log('mis.php: desenlazar: ' . $ex->getMessage());
                [$ok, $msg] = [false, 'Todavía no se puede deshacer el enlace en este servidor.'];
            }
            $_SESSION['flash'] = $ok ? ['ok' => $msg] : ['error' => $msg];
        } else {
            $origen = trim((string) ($_POST['origen'] ?? ''));
            /* El origen solo tiene que ser de su ZONA, no de su propia bandeja:
               las urgencias reasignan casos todo el tiempo, y es el patrón más
               común que el trabajo anterior lo haya atendido otro técnico. Lo
               que sí sigue cortando es la zona — si no, el enlace serviría para
               averiguar qué pasa en otra zona, y por eso se responde lo mismo
               que a un caso inexistente, sin distinguir cuál es cuál (T2.25.2,
               ampliado el 2026-09-22 a pedido de Andrés). */
            $zonaOrigen = Casos::zonaDeAviso($origen, $gestion);
            $alcanzaOrigen = $zonaOrigen !== null && Auth::alcanzaZona($zonaOrigen);
            if (!$alcanzaOrigen) {
                Auth::bitacora('DENEGADO', 'caso', $avisoN,
                               'continuidad contra ' . $origen . ', fuera de su alcance',
                               null, null, [], false);
                $_SESSION['flash'] = ['error' => 'No encuentro ese trabajo anterior en tu zona. '
                                               . 'Revisa el número, o pregúntale a tu jefe de zona.'];
            } else {
                try {
                    [$ok, $msg] = Casos::enlazar($avisoN, $origen, $suyo['zona'] ?? null,
                                                 (int) $u['usuario_id'],
                                                 (string) ($_POST['nota'] ?? ''));
                } catch (Throwable $ex) {
                    /* Sin la 012 no existen las columnas. La orden del técnico no
                       se pierde por eso: se le dice que todavía no está puesto,
                       en vez de un error que no significa nada para él (I-7). */
                    error_log('mis.php: enlazar: ' . $ex->getMessage());
                    [$ok, $msg] = [false, 'Este servidor todavía no tiene puesta la continuidad entre órdenes. '
                                        . 'Avisa a la administración; tu orden queda como está.'];
                }
                $_SESSION['flash'] = $ok ? ['ok' => $msg] : ['error' => $msg];
            }
        }

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
        $_SESSION['flash'] = ['error' => 'Esa orden no está entre las tuyas.'];
        header('Location: mis.php');
        exit;
    }

    $g   = $gestion[$avisoVer] ?? [];
    $est = $g['estado'] ?? 'NUEVO';
    $a   = $aten[$avisoVer] ?? null;
    $sinCat = !empty($caso['sin_catalogo']);
    // Lo cerrado es historial: se consulta, pero no se vuelve a emitir ni a reportar.
    $abierto = !$propios || in_array($est, Casos::ABIERTOS_TECNICO, true);
    $pendCaso = Pendientes::disponible()
        ? array_values(array_filter(Pendientes::lista(['grupo' => 'abiertos']),
                                    fn($p) => (string) $p['aviso'] === $avisoVer))
        : [];
    $errFlash = Ui::errorFlash();
    $okFlash  = $_SESSION['flash']['ok'] ?? null;
    unset($_SESSION['flash']);

    /* La continuidad (T2.25.2). `continua_de` puede no venir si la 012 todavía
       no está aplicada: `gestion()` hace `SELECT g.*` y simplemente no trae la
       columna, así que esto queda en null y la pantalla se ve como antes. */
    $continuaDe = trim((string) ($g['continua_de'] ?? ''));
    $continuaOt = trim((string) ($g['continua_ot'] ?? ''));
    /* Solo se proponen candidatos si hay algo que decidir: un caso ya enlazado
       no vuelve a preguntarse, y uno cerrado ya no se cierra con nada. */
    $candidatos = ($abierto && $puedeContinuidad && $continuaDe === '')
                ? Casos::continuidadPosible($caso, $gestion, $aten)
                : [];
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b4f8f">
<?php /* offline.js lo compara con Date.now() para saber si esta pantalla viene
        servida en vivo o de una copia guardada por el trabajador de servicio
        (H-13): sin `#otForm` no hay ningún `fetch` propio de esta pantalla que
        traiga esa fecha, así que la pone el propio PHP al generarla. */ ?>
<meta name="generado" content="<?= date('c') ?>">
<title><?= $e($caso['local'] ?? Vocabulario::titulo('ORDEN')) ?> · B.IA Soft ERP</title>
<link rel="stylesheet" href="estilo.css">
</head>
<body class="con-nav-abajo">
<div id="estadoRed" class="estado-red" hidden></div>

<header class="mov-cab">
  <div class="fila">
    <a class="btn sm" href="mis.php<?= $abierto ? '' : '?t=atendidas' ?>"
       aria-label="<?= $e('Volver a ' . ($abierto ? Vocabulario::titulo('MIS_ORDENES') : Vocabulario::t('HISTORIAL'))) ?>">←</a>
    <div style="flex:1;min-width:0">
      <div class="hola"><?= $sinCat ? $e(Vocabulario::titulo('AVISO_SAP') . ' ' . $avisoVer) : $e($caso['local'] ?? '—') ?></div>
      <div class="quien"><?= $sinCat ? 'sin dato en el catálogo'
                                     : $e($caso['local_nombre'] ?? $caso['restaurante_sap'] ?? '') ?></div>
    </div>
  </div>
</header>

<?php if ($okFlash): ?><div style="padding:12px 14px 0"><?= Ui::aviso('ok', $e((string) $okFlash), true) ?></div><?php endif; ?>
<?php if ($errFlash): ?><div style="padding:12px 14px 0"><?= Ui::aviso('err', $e($errFlash), true) ?></div><?php endif; ?>

<div class="mov-ficha">
  <?php /* El número de la orden de trabajo de SAP no es «orden» (el trabajo de KFC):
           tiene su propio nombre en el diccionario, el mismo que en el buzón. */ ?>
  <span class="aviso-n"><?= $e(Vocabulario::titulo('AVISO_SAP')) ?> <?= $e($avisoVer) ?><?= !empty($caso['orden_trabajo']) ? ' · ' . $e(Vocabulario::t('NUM_ORDEN_SAP') . ' ' . $caso['orden_trabajo']) : '' ?></span>
  <h1><?= $e($caso['caso'] ?? 'Sin descripción') ?></h1>
  <div class="chips">
    <?= Ui::prioridad($caso['prioridad'] ?? null) ?>
    <?= Ui::estado(Ui::estadoVista($est, $g)) ?>
    <?= Ui::edad($caso['fecha_creacion'] ?? null) ?>
  </div>

  <?php if ($sinCat): ?>
    <div style="margin-top:12px">
      <?= Ui::aviso('info', '<b>Esta orden no está en el listado del buzón.</b>'
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
    <?php /* «Fecha SAP», como la cifra «con fecha SAP hoy» de la oficina: es la
             fecha comprometida en SAP. Una fecha que ya pasó es «atrasada»
             (vencida es solo el plazo de 48 h), y la nota explica por qué eso,
             solo, no es culpa del técnico. */ ?>
    <dt>Fecha SAP</dt>
    <dd class="mono">
      <?= $e($caso['fecha_estimada'] ?? '—') ?>
      <?php if (!empty($caso['fecha_estimada']) && $caso['fecha_estimada'] < date('Y-m-d')): ?>
        <span class="desc">SAP casi siempre compromete para el día siguiente; que la
          fecha figure atrasada no significa por sí solo que vayas tarde.</span>
      <?php endif; ?>
    </dd>
  </dl>
</div>

<?php /* Las solicitudes de esta orden (el equipo que no quedó operativo), con su reloj y su hilo. */ ?>
<?php foreach ($pendCaso as $p): ?>
  <div style="padding:14px 14px 0">
    <div class="rep <?= !empty($p['reloj']) && $p['reloj']['vencido'] ? 'vencido' : ($p['deshabilitado'] ? 'deshabilitado' : '') ?>">
      <div class="cab">
        <div style="min-width:0">
          <div class="que"><?= $e($p['equipo_desc'] ?: ($p['activo_fijo'] ?: 'Equipo')) ?></div>
          <div class="meta">
            <?php /* P-19: mientras el jefe no valida, la vía no existe: se
                     enseña solo el estado («solicitado»), no «sin veredicto ·
                     sin veredicto». */ ?>
            <?php if (($p['via'] ?? 'SIN_VEREDICTO') !== 'SIN_VEREDICTO'): ?>
              <?= $e(Pendientes::etiquetaVia($p['via'])) ?> ·
            <?php endif; ?>
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
          <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
          <input type="hidden" name="accion" value="insistir">
          <input type="hidden" name="pendiente_id" value="<?= (int) $p['pendiente_id'] ?>">
          <?php /* El mismo nombre que en «Repuestos» (pendientes.php): una acción, un nombre. */ ?>
          <label for="ins<?= (int) $p['pendiente_id'] ?>">Insistir por esta solicitud</label>
          <textarea id="ins<?= (int) $p['pendiente_id'] ?>" name="texto" rows="2" required
            placeholder="p. ej. el administrador insiste en que envíen el repuesto urgente, el equipo sigue deshabilitado"></textarea>
          <label style="display:flex;align-items:center;gap:8px;margin:9px 0 0;font-size:13.5px;color:var(--ink)">
            <input type="checkbox" name="urgente" value="1"
                   style="width:auto;height:auto;margin:0">
            Marcarlo como urgente
          </label>
          <button class="btn primary" type="submit" style="width:100%;margin-top:10px;padding:12px">
            Enviar
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($a !== null && !empty($a['ots'])): ?>
  <div style="padding:14px 14px 0">
    <?php foreach ($a['ots'] as $o): ?>
      <?php /* El número ya dice OT-NNNN; debajo, qué OT INDUSTEC es. La regla
               es la de siempre (solo «Cerrada» es la de cierre); cambia el
               nombre: «con cierre / en curso» pasan a los del diccionario. */ ?>
      <div class="rep atendido">
        <div class="cab">
          <div><div class="que"><?= $e($o['ot']) ?></div>
            <div class="meta"><?= $e(substr((string) $o['fecha'], 0, 10)) ?> ·
              <?= $e(Vocabulario::t(($o['estado_ot'] ?? '') === 'Cerrada' ? 'OT_CIERRE' : 'OT_EVALUACION')) ?></div></div>
          <?php if (Auth::puede('ots.pdf') && Emision::existePdf((string) $o['ot'])): ?>
            <a class="btn sm" href="pdf.php?ot=<?= rawurlencode((string) $o['ot']) ?>"
               target="_blank" rel="noopener">Ver PDF</a>
          <?php elseif (Auth::puede('ots.pdf')): ?>
            <span class="derivado">PDF no cargado al archivo todavía</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php /* La OT INDUSTEC de cierre de esta orden, de la base (T2.13.3). Si ya salió
         arriba entre las del buzón no se repite; si no hay, se dice en vez de
         callarlo (I-7). */ ?>
<?php if (!$abierto && !in_array($g['ot_cierre'] ?? null, array_column($a['ots'] ?? [], 'ot'), true)): ?>
  <div style="padding:14px 14px 0">
    <?php if (!empty($g['ot_cierre'])): ?>
      <div class="rep atendido">
        <div class="cab">
          <div><div class="que"><?= $e(Vocabulario::titulo('OT_CIERRE')) ?> <?= $e($g['ot_cierre']) ?></div>
            <div class="meta"><?= $e(substr((string) ($g['atendido_en'] ?? ''), 0, 10)) ?> ·
              <?= $e(Casos::etiquetaEstado($est)) ?></div></div>
          <?php if (Auth::puede('ots.pdf') && Emision::existePdf((string) $g['ot_cierre'])): ?>
            <a class="btn sm" href="pdf.php?ot=<?= rawurlencode((string) $g['ot_cierre']) ?>"
               target="_blank" rel="noopener">Ver PDF</a>
          <?php elseif (Auth::puede('ots.pdf')): ?>
            <span class="derivado">PDF no cargado al archivo todavía</span>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <?= Ui::aviso('info', '<b>No hay ' . $e(Vocabulario::t('OT_CIERRE')) . ' registrada para esta orden.</b>'
        . '<p>Quedó como «' . $e(Casos::etiquetaEstado($est)) . '» sin una '
        . $e(Vocabulario::t('OT_CIERRE')) . ' asociada.</p>') ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php /* =====================================================================
     ESTE CASO PUEDE SER LA CONTINUACION DE UN TRABAJO ANTERIOR (T2.25.2).

     SAP cierra solo el aviso que nadie atendió en 48 horas, y KFC abre otro
     por el mismo equipo. El trabajo es uno; los avisos, varios. Va ANTES de
     los botones a propósito: si el técnico ya emitió la orden de este trabajo
     la semana pasada, lo que tiene que hacer aquí no es emitir otra.

     El sistema propone; decide él. Se le enseña de cada candidato la fecha, lo
     que escribió KFC y la orden que salió de ahí, porque dos casos del mismo
     equipo pueden ser dos trabajos distintos —«dar de baja» y «instalar el
     nuevo»— y el único que sabe cuál es cuál es el que estuvo ahí.
     ===================================================================== */ ?>
<?php if ($continuaDe !== ''): ?>
  <div style="padding:14px 14px 0">
    <div class="rep atendido">
      <div class="cab">
        <div style="min-width:0">
          <?php /* «Continúa la orden N» (CONTINUIDAD): el aviso SAP es el número de
                   la orden anterior. Lo que la cubre es su OT INDUSTEC; antes
                   decía «orden», que ahora es solo el trabajo que pide KFC. */ ?>
          <div class="que"><?= $e(Vocabulario::titulo('CONTINUIDAD')) ?> <?= $e($continuaDe) ?></div>
          <div class="meta">
            <?= $continuaOt !== '' ? 'cubierta por la ' . $e(Vocabulario::t('OT_INDUSTEC')) . ' ' . $e($continuaOt)
                                   : 'esa orden todavía no tiene ' . $e(Vocabulario::t('OT_INDUSTEC')) . ' emitida' ?>
            <?php if (!empty($g['continua_en'])): ?>
              · <?= $e(substr((string) $g['continua_en'], 0, 16)) ?>
            <?php endif; ?>
            <?php if (!empty($g['continua_nombre'])): ?>
              · lo declaró <?= $e($g['continua_nombre']) ?>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($continuaOt !== '' && Auth::puede('ots.pdf') && Emision::existePdf($continuaOt)): ?>
          <a class="btn sm" href="pdf.php?ot=<?= rawurlencode($continuaOt) ?>"
             target="_blank" rel="noopener">Ver PDF</a>
        <?php endif; ?>
      </div>
      <?php if (!empty($g['continua_nota'])): ?>
        <div class="nota"><?= $e($g['continua_nota']) ?></div>
      <?php endif; ?>
      <?php if ($puedeContinuidad): ?>
        <form method="post" style="margin-top:10px">
          <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
          <input type="hidden" name="accion" value="descontinua">
          <input type="hidden" name="aviso" value="<?= $e($avisoVer) ?>">
          <button class="btn sm" type="submit">No era el mismo trabajo: deshacer</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php elseif ($candidatos): ?>
  <div style="padding:14px 14px 0">
    <?php /* Enlazar no la cierra: la deja atendida, por cerrar en SAP, con la OT
             INDUSTEC de la anterior (el «queda cerrado» de antes era falso). */ ?>
    <?= Ui::aviso('info', '<b>¿Esto continúa un trabajo que ya empezaste?</b>'
      . '<p>Hay ' . count($candidatos) . ' ' . $e(Vocabulario::t('ORDEN', count($candidatos)))
      . ' anterior' . (count($candidatos) === 1 ? '' : 'es') . ' de este mismo equipo. Si es el mismo trabajo, '
      . 'no hace falta emitir otra ' . $e(Vocabulario::t('OT_INDUSTEC')) . ': enlázala y esta orden queda '
      . 'atendida con la ' . $e(Vocabulario::t('OT_INDUSTEC')) . ' de aquella.</p>') ?>

    <?php foreach ($candidatos as $k): ?>
      <div class="rep" style="margin-top:10px">
        <div class="cab">
          <div style="min-width:0">
            <div class="que"><?= $e(Vocabulario::titulo('AVISO_SAP')) ?> <?= $e($k['aviso']) ?></div>
            <div class="meta">
              <?= $e($k['fecha']) ?> · hace <?= (int) $k['dias'] ?> día<?= $k['dias'] === 1 ? '' : 's' ?>
              · <?= $k['ot'] !== null ? $e($k['ot']) : 'sin ' . $e(Vocabulario::t('OT_INDUSTEC')) ?>
            </div>
          </div>
        </div>
        <?php if (trim((string) $k['pedido']) !== ''): ?>
          <div class="cita" style="margin-top:9px"><?= $e($k['pedido']) ?></div>
        <?php endif; ?>
        <form method="post" style="margin-top:10px">
          <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
          <input type="hidden" name="accion" value="continua">
          <input type="hidden" name="aviso" value="<?= $e($avisoVer) ?>">
          <input type="hidden" name="origen" value="<?= $e($k['aviso']) ?>">
          <input type="hidden" name="nota" value="Mismo equipo, mismo trabajo">
          <button class="btn primary" type="submit" style="width:100%;padding:11px">
            Es el mismo trabajo
          </button>
        </form>
      </div>
    <?php endforeach; ?>

    <p class="sub" style="margin:10px 0 0">
      Si ninguna es, sigue abajo y emite la <?= $e(Vocabulario::t('OT_INDUSTEC')) ?> de esta orden como siempre.
    </p>
  </div>
<?php endif; ?>

<div class="mov-acciones">
  <?php if ($abierto): ?>
    <a class="btn primary" href="index.html?aviso=<?= rawurlencode($avisoVer) ?>">
      Emitir <?= $e(Vocabulario::titulo('OT_INDUSTEC')) ?>
    </a>
  <?php else: ?>
    <?php /* No se dice «cerrada»: en el historial hay atendidas (por cerrar en
             SAP), cerradas en SAP y las que no nos competen. El chip de arriba
             ya dice cuál de ellas es. */ ?>
    <p class="sub" style="margin:0;text-align:center">Esta orden ya no está en tus manos: queda en tu
      <?= $e(Vocabulario::t('HISTORIAL')) ?> para consultarla.</p>
  <?php endif; ?>

  <?php if ($abierto && Auth::puede('repuestos.pedir') && !$pendCaso): ?>
    <button class="btn ghost" type="button" onclick="document.getElementById('dlgTrabado').showModal()">
      El equipo no quedó operativo: pedir repuesto
    </button>
  <?php endif; ?>

  <?php /* El buscador a mano. El sistema propone por local y equipo, pero KFC
           a veces abre el aviso contra otro equipo del mismo local, o sin
           equipo: entonces la propuesta no lo encuentra y hay que poder
           decirlo. Si el sistema no lo sabe, deja buscar; no inventa (I-7). */ ?>
  <?php if ($abierto && $puedeContinuidad && $continuaDe === ''): ?>
    <button class="btn ghost" type="button" onclick="document.getElementById('dlgContinua').showModal()">
      <?= $candidatos ? 'Es la continuación de otro trabajo que no está en la lista'
                      : 'Esto continúa un trabajo que ya empecé' ?>
    </button>
  <?php endif; ?>
</div>

<?php if ($abierto && Auth::puede('repuestos.pedir') && !$pendCaso): ?>
<dialog id="dlgTrabado">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
    <input type="hidden" name="accion" value="no_concluye">
    <input type="hidden" name="aviso" value="<?= $e($avisoVer) ?>">
    <input type="hidden" name="activo_fijo" value="<?= $e($caso['activo_fijo'] ?? '') ?>">

    <?php /* «veredicto» se retiró: el jefe de zona valida, KFC decide. */ ?>
    <h2>El equipo no quedó operativo</h2>
    <p class="sub" style="margin:0 0 14px">
      La intervención debería terminar el trabajo en la visita. Cuando no se puede,
      hay que dejar por escrito qué encontraste: es lo que sostiene la validación
      de tu jefe de zona y la respuesta a Grupo KFC.
    </p>

    <div style="margin-bottom:12px">
      <label for="td">Qué encontraste y por qué no se pudo terminar</label>
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
      <?php /* Las vías son las de hoy (modelo de la 009), con sus nombres del
               diccionario; la frase vieja describía el modelo de antes, cuando
               INDUSTEC compraba la pieza. */ ?>
      <span class="derivado">
        Si no estás seguro, déjalo vacío. Tu jefe de zona valida la vía:
        <?= $e(implode(', ', array_map(fn($k) => mb_strtolower(Vocabulario::titulo($k), 'UTF-8'),
                                       ['VIA_REPUESTO', 'VIA_REPARACION', 'VIA_GARANTIA']))) ?>
        o <?= $e(mb_strtolower(Vocabulario::titulo('VIA_BAJA'), 'UTF-8')) ?>.
      </span>
    </div>

    <label style="display:flex;align-items:flex-start;gap:9px;padding:11px;border:1px solid var(--danger-bd);
                  background:var(--danger-bg);border-radius:var(--r);font-size:13.5px;color:#7f1d1d">
      <input type="checkbox" name="deshabilitado" value="1" style="width:auto;height:auto;margin:2px 0 0">
      <span><b>El equipo quedó deshabilitado.</b>
        Marca esto solo si el local no lo puede usar. Enciende el plazo de
        <b>48 horas</b> para que tu jefe de zona valide la vía, y aparece en rojo
        en su tablero.</span>
    </label>

    <div class="row" style="margin-top:16px;gap:8px">
      <button class="btn primary" type="submit">Registrar</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($abierto && $puedeContinuidad && $continuaDe === ''): ?>
<dialog id="dlgContinua">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
    <input type="hidden" name="accion" value="continua">
    <input type="hidden" name="aviso" value="<?= $e($avisoVer) ?>">

    <h2>Continúa un trabajo anterior</h2>
    <p class="sub" style="margin:0 0 14px">
      Cuando SAP cerró solo el aviso anterior a las 48 horas y KFC abrió este
      otro por el mismo equipo, el trabajo sigue siendo uno — hayas ido tú
      o un compañero, las asignaciones cambian con la urgencia.
      Pon el número del aviso anterior y esta orden queda atendida con la
      <?= $e(Vocabulario::t('OT_INDUSTEC')) ?> de aquella, sin emitir otra.
    </p>

    <div style="margin-bottom:12px">
      <label for="cOrigen">Número del aviso anterior</label>
      <input type="text" id="cOrigen" name="origen" inputmode="numeric" required
             pattern="[0-9]{6,12}" placeholder="p. ej. 10342524">
      <span class="derivado">
        Son los 8 dígitos del aviso de SAP. Está en el correo de KFC y en la
        <?= $e(Vocabulario::t('OT_INDUSTEC')) ?> de ese trabajo, la haya emitido quien la haya emitido.
        Si no lo tienes a mano, pregúntale a tu jefe de zona.
      </span>
    </div>

    <div style="margin-bottom:12px">
      <label for="cNota">Por qué es el mismo trabajo</label>
      <textarea id="cNota" name="nota" rows="2" required
        placeholder="p. ej. es el mismo horno: fui el 18-jul, quedó a espera de la resistencia y hoy la instalé"></textarea>
      <span class="derivado">
        Es lo que ve tu jefe de zona, y lo que responde a Grupo KFC si pregunta
        por qué esta orden no tiene una <?= $e(Vocabulario::t('OT_INDUSTEC')) ?> propia.
      </span>
    </div>

    <div class="row" style="margin-top:16px;gap:8px">
      <button class="btn primary" type="submit">Enlazar</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php /* La misma barra que UI.BARRA_TECNICO (ui.js), con los mismos rótulos del
         diccionario; prueba_barra_tecnico.mjs compara las dos. */ ?>
<nav class="nav-abajo" aria-label="Principal">
  <a href="mis.php" class="<?= $abierto ? 'on' : '' ?>"><span class="ic">▤</span><?= $e(Vocabulario::titulo('MIS_ORDENES')) ?></a>
  <a href="mis.php?t=atendidas" class="<?= $abierto ? '' : 'on' ?>"><span class="ic">✓</span><?= $e(Vocabulario::titulo('HISTORIAL')) ?></a>
  <a href="index.html"><span class="ic">✎</span>Emitir</a>
  <a href="pendientes.php"><span class="ic">◷</span><?= $e(Vocabulario::corto('MODULO_REPUESTOS')) ?></a>
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
              || (!$propios && isset($aten[$aviso]))) {
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

// `$tab` ya se resolvió arriba, antes del bloque de avisos (H-12).

/* Los pendientes de equipo que él abrió, para la pestaña «Esperando». */
$misPend = Pendientes::disponible() ? Pendientes::lista(['grupo' => 'abiertos']) : [];
$pendPorAviso = [];
foreach ($misPend as $p) { $pendPorAviso[(string) $p['aviso']][] = $p; }

/* Las órdenes que mandó desde la app, para su historial (T2.13.3). Desde la
   008 cada una trae su número y su PDF; las de antes, o las que no se pudieron
   emitir, lo dicen. La tabla es de la misma migración que la de pendientes. */
require_once __DIR__ . '/nucleo/Emision.php';
$prueba = Emision::modo() === 'PRUEBA';
$capturas = [];
// H-22: antes se cortaba en 60 sin decirlo. `?desde=<capturada_en>` es el
// cursor de «Ver anteriores»; se pide una fila de más (61) para saber si hay
// "anteriores" sin una segunda consulta, y se descarta antes de pintar.
$desdeCap = (string) ($_GET['desde'] ?? '');
$hayMasCapturas = false;
if ($esTecnico && Pendientes::disponible()) {
    $condDesde = $desdeCap !== '' ? ' AND capturada_en < ?' : '';
    $paramsCap = [(int) $u['usuario_id']];
    if ($desdeCap !== '') { $paramsCap[] = $desdeCap; }
    $sqlCap = "SELECT aviso, local_codigo, capturada_en, estado, motivo_rechazo, id_industec, %s
                 FROM ot_capturadas WHERE usuario_id = ?$condDesde ORDER BY capturada_en DESC LIMIT 61";
    try {
        $capturas = Db::todos(sprintf($sqlCap, 'emitida_en, emision_error'), $paramsCap);
    } catch (Throwable $ex) {
        // Sin la 008 no hay emisión que mostrar.
        $capturas = Db::todos(sprintf($sqlCap, 'NULL AS emitida_en, NULL AS emision_error'), $paramsCap);
    }
    $hayMasCapturas = count($capturas) > 60;
    if ($hayMasCapturas) { array_pop($capturas); }
}
$enviadas  = array_count_values(array_filter(array_map(fn($k) => (string) ($k['aviso'] ?? ''), $capturas)));
$misAvisos = array_flip(array_map(fn($c) => (string) ($c['aviso'] ?? ''), $mios));

$errFlash = Ui::errorFlash();
$okFlash  = $_SESSION['flash']['ok'] ?? null;
unset($_SESSION['flash']);

/* Los rótulos de las pestañas son los del diccionario, iguales en la oficina:
   «Asignadas» y «A espera de repuesto» son los estados de la orden; la tercera
   se llama como el botón de la barra, «Historial» (un solo nombre por lista); y
   «Notificaciones» deja «aviso» solo para el aviso SAP. Las claves (`t=`) no
   cambian: son las de la URL y de novedades.php. */
$ETIQ = ['pendientes' => Vocabulario::titulo('ASIGNADA'),
         'esperando'  => Vocabulario::titulo('ESPERA_REPUESTO'),
         'atendidas'  => Vocabulario::titulo('HISTORIAL')]
      + ($esTecnico ? ['avisos' => Vocabulario::titulo('NOTIFICACION')] : []);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b4f8f">
<meta name="generado" content="<?= date('c') ?>">
<title><?= $e(Vocabulario::titulo('MIS_ORDENES')) ?> · B.IA Soft ERP</title>
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
        <?php /* La zona con su rótulo (CNLJ se lee «Zona Cuenca-Loja»), no el código. El
                 rol, el mismo de las otras pantallas (Ui::ROL): desde la 021 el jefe de zona
                 también atiende y abre esta pantalla, y no es «Técnico». */ ?>
        <?= $e(Ui::ROL[$u['rol']] ?? 'Técnico') ?><?= $u['zona'] ? ' · ' . $e(Ui::nombreZona((string) $u['zona'])) : '' ?>
      </div>
    </div>
    <?php /* H-02: entrada al archivo histórico de TODAS las zonas. No va en
             `nav-abajo` -- esa barra la define `UI.BARRA_TECNICO` en `ui.js`,
             que no es de esta sección, y `prueba_barra_tecnico.mjs` exige que
             las dos barras de mis.php calcen exactas con ella-- así que el
             enlace va aquí, junto a Salir. */ ?>
    <?php if (Auth::puede('ots.archivo')): ?>
      <a class="btn sm" href="ordenes.php">Archivo</a>
    <?php endif; ?>
    <a class="btn sm" href="salir.php">Salir</a>
  </div>

  <nav class="mov-tabs" aria-label="<?= $e(Vocabulario::titulo('MIS_ORDENES')) ?>">
    <?php foreach ($ETIQ as $k => $et): ?>
      <?php
      // En «Notificaciones» el globo cuenta solo lo que no ha leído.
      $cn = $k === 'avisos' ? $nuevos : count($grupos[$k]) + ($k === 'atendidas' ? count($capturas) : 0);
      // El globo de «A espera de repuesto» se pone rojo si alguno de sus
      // equipos pasó de las 48 h sin validar: es lo único de esta pantalla que
      // corre contra reloj, y el técnico es quien puede empujarlo insistiendo.
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

<?php if ($esTecnico): ?>
  <?php /* La barra de notificaciones: novedades.php le cuenta al técnico SUS
           notificaciones sin leer y ui.js la muestra sin recargarle la pantalla
           (T2.13.5); ui.js reescribe este texto con la cifra. */ ?>
  <div id="novedades" hidden><span class="vivo"></span><span id="nov-txt">Tienes <?= $e(Vocabulario::t('NOTIFICACION', 2)) ?> sin leer</span>
    <button class="btn primary" type="button" id="nov-ver">Ver</button>
    <button class="btn" type="button" id="nov-no" title="Se vuelve a avisar en el próximo cambio">Ahora no</button></div>
<?php endif; ?>

<?php if (!$fuente): ?>
  <div style="padding:14px">
    <?= Ui::aviso('warn',
        '<b>No se pudo leer el listado de ' . $e(Vocabulario::t('ORDEN', 2)) . '.</b>'
      . '<p>Si estás sin señal, lo que ves es lo último que se guardó en el celular. '
      . 'Puedes seguir llenando ' . $e(Vocabulario::t('OT_INDUSTEC', 2)) . ': se envían solas al reconectar.</p>') ?>
  </div>
<?php endif; ?>

<main>
<?php if ($tab === 'avisos'): ?>
  <?php /* Las notificaciones (T2.13.5): lo de las dos últimas semanas, con lo que
           no ha leído desde su visita anterior marcado. Sale de lo que el sistema
           ya registra (clase Avisos, que no se renombra). */ ?>
  <?php if (!$avisos): ?>
    <div class="vacio" style="padding:44px 24px">
      <span class="icono">✓</span>
      <b style="display:block;font-size:15px;color:var(--ink);margin-bottom:6px">No tienes <?= $e(Vocabulario::t('NOTIFICACION', 2)) ?></b>
      <span style="display:block;max-width:44ch;margin:0 auto;line-height:1.55">Aquí te avisamos cuando te
        asignen o te quiten una orden, cuando respondan sobre un equipo que no quedó operativo y cuando
        resuelvan una novedad que reportaste.</span>
    </div>
  <?php else: ?>
    <div style="padding:14px">
      <?php foreach ($avisos as $av): ?>
        <?php $nuevo = $esNuevo($av);
              $suyo  = $av['aviso'] !== null && isset($misAvisos[$av['aviso']]); ?>
        <div class="rep <?= $av['tipo'] === 'QUITADO' ? '' : 'atendido' ?>"
             style="margin-bottom:10px<?= $nuevo ? ';border-left:4px solid var(--accent)' : '' ?>">
          <div class="cab">
            <div style="min-width:0">
              <?php /* «sin leer», no «nuevo»: en masculino, «nuevo» es el código
                       crudo de la orden sin asignar. */ ?>
              <div class="que"><?= $e($av['titulo']) ?><?= $nuevo ? ' <span class="chip">sin leer</span>' : '' ?></div>
              <div class="meta"><?= $av['aviso'] !== null ? $e(Vocabulario::titulo('AVISO_SAP') . ' ' . $av['aviso']) . ' · ' : '' ?><span
                   data-hace="<?= $e($av['cuando']) ?>"><?= $e(substr($av['cuando'], 0, 16)) ?></span></div>
            </div>
            <?php if ($suyo): ?>
              <a class="btn sm" href="?ver=<?= rawurlencode($av['aviso']) ?>">Ver la orden</a>
            <?php endif; ?>
          </div>
          <?php if ($av['texto'] !== ''): ?><div class="nota"><?= $e($av['texto']) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php else: ?>
<?php
$lista = $grupos[$tab];
$verCapturas = $tab === 'atendidas' && $capturas;

if (!$lista && !$verCapturas):
    /* El vacío también informa. Cada pestaña vacía significa algo distinto y
       decir «no hay nada» en las tres sería desperdiciar el único momento en
       que el técnico tiene toda la pantalla para leer. */
    $ot = Vocabulario::t('OT_INDUSTEC');
    $vacios = [
        'pendientes' => ['✓', 'No tienes ' . Vocabulario::t('ORDEN', 2) . ' ' . Vocabulario::t('ASIGNADA', 2),
            ($u['rol'] === 'JEFE_ZONA' ? 'Cuando la administración te asigne una orden, aparece aquí. '
                                       : 'Cuando la administración o tu jefe de zona te asignen una orden, aparece aquí. ')
          . 'Si atendiste algo sin aviso SAP, puedes emitir la ' . $ot . ' igual con el botón azul.'],
        'esperando'  => ['—', 'No tienes ' . Vocabulario::t('ORDEN', 2) . ' ' . Vocabulario::t('ESPERA_REPUESTO', 2),
            'Aquí aparecen las órdenes que fuiste a ver y cuyo equipo quedó a espera de un repuesto, '
          . 'una reparación o una garantía. Desde aquí le puedes insistir a la administración.'],
        'atendidas'  => ['—', 'Tu ' . Vocabulario::t('HISTORIAL') . ' todavía está vacío',
            'En cuanto emitas una ' . $ot . ' y llegue al buzón de la empresa, aparece aquí '
          . 'con su PDF para consultarla o mandársela al administrador del local.'],
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
        . 'aquí qué quedó ' . $e(Vocabulario::t('ESPERA_REPUESTO')) . ', pero no insistir desde la aplicación.</p>') ?>
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
              <span class="local"><?php if ($sinCat): ?><?= $e(Vocabulario::titulo('AVISO_SAP')) ?> <?= $e($aviso) ?> · sin dato en el catálogo<?php else: ?><?= $e($c['local'] ?? '—') ?> ·
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
              <?php /* Lo que se cuenta aquí son los documentos del técnico (OT
                       INDUSTEC), no órdenes: la orden es la fila entera. */ ?>
              <?php if ($tab === 'atendidas' && !empty($c['_aten']['ots'])): ?>
                <span class="chip cerrada"><?= count($c['_aten']['ots']) ?> <?= $e(Vocabulario::t('OT_INDUSTEC', count($c['_aten']['ots']))) ?></span>
              <?php endif; ?>
              <?php if ($tab !== 'atendidas' && !empty($enviadas[$aviso])): ?>
                <span class="chip">enviaste una <?= $e(Vocabulario::t('OT_INDUSTEC')) ?></span>
              <?php endif; ?>
            </span>
          </span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <?php if ($verCapturas): ?>
    <?php /* El paso del envío de cada OT INDUSTEC, con los términos del diccionario
             (los mismos de la cola del celular). Los valores de
             ot_capturadas.estado no cambian: solo cómo se leen. EMITIDA,
             ENVIADA y PROCESADA son la misma cosa para el técnico (salió el
             PDF); NUMERADA y FALLIDA, la que se numeró y no salió. Un valor
             que no está aquí se sigue mostrando tal como viene. */
          $ETIQ_CAP = ['RECIBIDA'  => Vocabulario::t('ENVIO_RECIBIDA'),
                       'EMITIDA'   => Vocabulario::t('ENVIO_EMITIDA'),
                       'ENVIADA'   => Vocabulario::t('ENVIO_EMITIDA'),
                       'PROCESADA' => Vocabulario::t('ENVIO_EMITIDA'),
                       'NUMERADA'  => Vocabulario::t('OT_NO_EMITIDA'),
                       'FALLIDA'   => Vocabulario::t('OT_NO_EMITIDA'),
                       'RECHAZADA' => Vocabulario::t('ENVIO_RECHAZADA')]; ?>
    <div style="padding:14px">
      <h2 style="font-size:14px;margin:0 0 10px;color:var(--ink)"><?= $e(Vocabulario::titulo('OT_INDUSTEC')) ?> que enviaste desde la app</h2>
      <?php foreach ($capturas as $k): ?>
        <?php $avk = (string) ($k['aviso'] ?? ''); ?>
        <div class="rep atendido" style="margin-bottom:10px">
          <div class="cab">
            <div style="min-width:0">
              <div class="que"><?= $avk !== '' ? $e(Vocabulario::titulo('AVISO_SAP') . ' ' . $avk) : $e(Vocabulario::titulo('SIN_AVISO_SAP')) ?> · <?= $e($k['local_codigo'] ?? '—') ?></div>
              <div class="meta">llenada el <?= $e(substr((string) $k['capturada_en'], 0, 16)) ?> ·
                <?= $e($ETIQ_CAP[$k['estado']] ?? strtolower((string) $k['estado'])) ?></div>
            </div>
            <?php if ($avk !== '' && isset($misAvisos[$avk])): ?>
              <a class="btn sm" href="?ver=<?= rawurlencode($avk) ?>">Ver la orden</a>
            <?php endif; ?>
          </div>
          <div class="nota">
            <?php /* El número OT-NNNN ya dice que es una OT INDUSTEC. */ ?>
            <?php if ($k['estado'] === 'RECHAZADA'): ?>
              <?= $e('Motivo: ' . ($k['motivo_rechazo'] ?? 'sin dato')) ?>
            <?php elseif (!empty($k['emitida_en'])): ?>
              <b><?= $e($k['id_industec']) ?></b> ·
              <?php if (Emision::existePdf((string) $k['id_industec'])): ?>
                <a href="pdf.php?ot=<?= rawurlencode((string) $k['id_industec']) ?>" target="_blank" rel="noopener">Ver PDF</a><?=
                  $prueba ? ' · es de prueba: no se envió a nadie' : '' ?>
              <?php else: ?>
                PDF no cargado al archivo todavía
              <?php endif; ?>
            <?php elseif (!empty($k['emision_error'])): ?>
              El PDF no se pudo generar todavía: se reintenta desde el servidor cada 10 minutos.
            <?php elseif (!empty($k['id_industec'])): ?>
              <b><?= $e($k['id_industec']) ?></b> · el PDF se reintenta desde el servidor cada 10 minutos.
            <?php else: ?>
              <?= $e(Vocabulario::titulo('OT_INDUSTEC')) ?> recibida antes de la emisión automática (anterior al 2026-09-11): no tiene PDF.
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php /* H-22: antes se cortaba en 60 sin decirlo. */ ?>
      <?php if ($hayMasCapturas): ?>
        <div class="row" style="margin-top:4px">
          <a class="btn" href="?t=atendidas&desde=<?= rawurlencode((string) end($capturas)['capturada_en']) ?>">Ver anteriores</a>
        </div>
      <?php elseif (Auth::puede('ots.archivo')): ?>
        <p class="sub">Esto es lo que enviaste desde la app. El historial completo, de todas las
          zonas, está en <a href="ordenes.php">Archivo</a>.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php endif; /* avisos */ ?>
</main>

<?php /* El botón flotante. Emitir una OT INDUSTEC es LA acción del técnico y
         tiene que estar a un pulgar de distancia desde cualquier punto de la lista. */ ?>
<a class="fab" href="index.html" aria-label="Emitir una <?= $e(Vocabulario::t('OT_INDUSTEC')) ?>"
   title="Emitir una <?= $e(Vocabulario::t('OT_INDUSTEC')) ?>">+</a>

<?php /* La misma barra que UI.BARRA_TECNICO (ui.js), con los mismos rótulos del
         diccionario; prueba_barra_tecnico.mjs compara las dos. */ ?>
<nav class="nav-abajo" aria-label="Principal">
  <a href="mis.php" class="<?= $tab === 'atendidas' ? '' : 'on' ?>"><span class="ic">▤</span><?= $e(Vocabulario::titulo('MIS_ORDENES')) ?>
    <?php /* Cuenta lo mismo que dice «Mis órdenes» (su ayuda en el diccionario): las
             asignadas y las que están a espera de repuesto, las dos pestañas que abre. */ ?>
    <?php $nMias = count($grupos['pendientes']) + count($grupos['esperando']); if ($nMias): ?>
      <span class="globo"><?= $nMias ?></span>
    <?php endif; ?>
  </a>
  <?php /* El historial es la pestaña del mismo nombre; va en la barra porque es
           lo segundo que busca el técnico: la OT INDUSTEC que ya mandó (T2.13.3). */ ?>
  <a href="mis.php?t=atendidas" class="<?= $tab === 'atendidas' ? 'on' : '' ?>"><span class="ic">✓</span><?= $e(Vocabulario::titulo('HISTORIAL')) ?></a>
  <a href="index.html"><span class="ic">✎</span>Emitir</a>
  <a href="pendientes.php"><span class="ic">◷</span><?= $e(Vocabulario::corto('MODULO_REPUESTOS')) ?>
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
