<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Pendientes.php';
require_once __DIR__ . '/nucleo/Ui.php';

/**
 * pendientes.php — Los repuestos y equipos que quedaron sin concluir, y su reloj.
 *
 * ============================================================================
 * LA CADENA REAL (D3, desde la 009)
 *
 *   1. El TÉCNICO deja el equipo sin concluir y pide el repuesto con su
 *      diagnóstico (desde la app, con el diagnóstico pre-redactado y las
 *      partes en lista). Nace SOLICITADO y, si el equipo quedó parado, corre
 *      el plazo de 48 horas.
 *   2. El JEFE DE ZONA valida que el diagnóstico y el repuesto son correctos
 *      y fija la vía (repuesto, reparación, garantía o baja). VALIDADO_JEFE:
 *      el reloj se detiene ahí, porque el plazo mide la decisión.
 *   3. La ADMINISTRADORA registra el requerimiento en SAP, con su número.
 *      REGISTRADO_SAP: el caso queda esperando a Grupo KFC.
 *   4. GRUPO KFC decide: envía el repuesto, lo manda al taller de INDUSTEC, a
 *      otro proveedor, o da de baja el equipo. Cada decisión deja su camino
 *      corto hasta RESUELTO; «otro proveedor» resuelve en el acto.
 *
 * La palabra «veredicto» se reserva para lo que decide Grupo KFC. Lo del jefe
 * es «validar»; lo de la administración, «registrar». Mezclarlas era lo que
 * hacía que la pantalla vieja hablara de una compra propia que no existe.
 *
 * ============================================================================
 * EL ORDEN NO ES UNA PREFERENCIA
 *
 * Primero lo parado sin validar —el reloj corriendo—, después el resto de lo
 * parado, y dentro de cada grupo lo más viejo arriba. Los filtros de arriba son
 * las colas de cada rol: «por validar» es la del jefe, «por registrar en SAP»
 * la de la administración, «esperando a KFC» la que se le reclama al cliente.
 * Al entrar sin filtro, cada rol cae en su cola si tiene algo en ella.
 *
 * ============================================================================
 * QUIÉN PUEDE QUÉ (se revalida en el servidor; ocultar un botón no protege)
 *
 *   TECNICO    ve los suyos e insiste. Aporta el diagnóstico, no decide.
 *   JEFE_ZONA  valida (repuestos.validar) y responde en el hilo
 *              (repuestos.responder) para pedir un dato antes de validar.
 *   ADMIN      registra en SAP (repuestos.registrar_sap), anota la decisión de
 *              Grupo KFC y avanza los caminos (repuestos.gestionar). Si valida
 *              en ausencia del jefe queda marcado aparte en la bitácora.
 *
 * Los pendientes anteriores a la 009 (cotizando, comprado, en bodega…) siguen
 * su camino de siempre con «Avanzar»; a los nuevos no se les ofrece.
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
   responde con redirección para que recargar no la repita. Todas exigen el
   token CSRF (D13): un enlace ajeno no puede validar ni registrar nada.
   ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::exigirCsrf();
    $accion = (string) ($_POST['accion'] ?? '');
    $id     = (int) ($_POST['pendiente_id'] ?? 0);
    $nota   = (string) ($_POST['nota'] ?? '');
    $prom   = ($_POST['prometido'] ?? '') !== '' ? (string) $_POST['prometido'] : null;

    if ($accion === 'validar' || $accion === 'veredicto') {
        // «veredicto» era el nombre de antes de la 009; se acepta para no
        // romper un formulario que quedó abierto en otra pestaña.
        [$ok, $msg] = Pendientes::validar($id, (string) ($_POST['via'] ?? ''), $nota);
    } elseif ($accion === 'registrar_sap') {
        [$ok, $msg] = Pendientes::registrarSap($id, (string) ($_POST['requerimiento_sap'] ?? ''), $nota);
    } elseif ($accion === 'kfc') {
        [$ok, $msg] = Pendientes::decidioKfc($id, (string) ($_POST['veredicto_kfc'] ?? ''),
                                             ($_POST['referencia'] ?? '') !== '' ? (string) $_POST['referencia'] : null,
                                             ($_POST['tercero'] ?? '') !== '' ? (string) $_POST['tercero'] : null,
                                             $nota);
    } elseif ($accion === 'mover') {
        [$ok, $msg] = Pendientes::mover($id, (string) ($_POST['estado'] ?? ''), $nota, $prom);
    } elseif ($accion === 'responder') {
        [$ok, $msg] = Pendientes::responder($id, $nota);
    } elseif ($accion === 'insistir') {
        [$ok, $msg] = Pendientes::insistir($id, $nota, isset($_POST['urgente']));
    } elseif ($accion === 'regularizar') {
        [$ok, $msg] = Pendientes::regularizar((string) ($_POST['envio_uuid'] ?? ''), (string) ($_POST['aviso'] ?? ''));
    } else {
        [$ok, $msg] = [false, 'Acción no reconocida.'];
    }

    $_SESSION['flash'] = $ok ? ['ok' => $msg] : ['error' => $msg];
    header('Location: pendientes.php?' . (string) ($_SERVER['QUERY_STRING'] ?? ''));
    exit;
}

$esTecnico      = $u['rol'] === 'TECNICO';
$puedeValidar   = Ui::puedeModulo('repuestos.validar',       ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA'], $u);
$puedeRegistrar = Ui::puedeModulo('repuestos.registrar_sap', ['SUPERADMIN', 'ADMIN'], $u);
$puedeGestionar = Ui::puedeModulo('repuestos.gestionar',     ['SUPERADMIN', 'ADMIN'], $u);
$puedeResponder = Ui::puedeModulo('repuestos.responder',     ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA'], $u);
$puedeInsistir  = Ui::puedeModulo('repuestos.pedir',         ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO'], $u);

$cont = Pendientes::contadores();
$q    = trim((string) ($_GET['q'] ?? ''));

// Sin filtro, cada rol cae en su propia cola si tiene algo en ella: el jefe en
// «por validar», la administración en «por registrar en SAP». Es lo mismo que
// pide la asignación: que la pantalla lleve a lo que toca hacer, no a buscarlo.
$grupo = (string) ($_GET['g'] ?? '');
if ($grupo === '') {
    if ($puedeRegistrar && $cont['por_registrar'] > 0)   { $grupo = 'por_registrar'; }
    elseif ($puedeValidar && $cont['por_validar'] > 0)   { $grupo = 'por_validar'; }
    else                                                 { $grupo = 'abiertos'; }
}

$lista = Pendientes::lista(['grupo' => $grupo, 'q' => $q]);
$hilos = Pendientes::notasDe(array_map(fn($p) => (int) $p['pendiente_id'], $lista));
$c48   = Pendientes::cumplimiento48();
$sinAviso = $esTecnico ? [] : Pendientes::sinAviso();

Auth::bitacora('CONSULTAR', 'pendientes', $grupo, 'visibles=' . count($lista));

/* El título del diagnóstico pre-redactado (009), para no enseñar «FRE-03» a
   secas. Una consulta para toda la lista; sin la tabla, no se enseña nada. */
$titulosDiag = [];
$codigos = array_values(array_unique(array_filter(array_map(fn($p) => (string) ($p['diagnostico_codigo'] ?? ''), $lista))));
if ($codigos) {
    try {
        $marcas = implode(',', array_fill(0, count($codigos), '?'));
        foreach (Db::todos("SELECT codigo, titulo FROM diagnosticos WHERE codigo IN ($marcas)", $codigos) as $d) {
            $titulosDiag[(string) $d['codigo']] = (string) $d['titulo'];
        }
    } catch (Throwable $ex) { /* sin la 009 no hay catálogo de diagnósticos */ }
}

/* El camino que le queda a un pendiente, o null si se gestiona con las
   acciones propias (validar / registrar / KFC decidió). Misma regla que
   `Pendientes::mover()`, para que el botón «Avanzar» salga solo cuando el
   servidor lo va a aceptar. */
$caminoDe = static function (array $p): ?array {
    $kfc = (string) ($p['veredicto_kfc'] ?? 'PENDIENTE');
    if ($kfc !== 'PENDIENTE' && isset(Pendientes::PASOS_KFC[$kfc])
        && in_array($p['estado'], Pendientes::PASOS_KFC[$kfc], true)) {
        return Pendientes::PASOS_KFC[$kfc];
    }
    $via = (string) $p['via'];
    if (isset(Pendientes::PASOS[$via]) && in_array($p['estado'], Pendientes::PASOS[$via], true)) {
        return Pendientes::PASOS[$via];
    }
    return null;
};

/* La clase de color del estado. La palabra la pone la etiqueta; el color solo
   acompaña (regla 1 de estilo.css). */
$claseEstado = static function (array $p): string {
    if (!$p['abierto']) { return $p['estado'] === 'CANCELADO' ? 'no_compete' : 'resuelto'; }
    return match ($p['estado']) {
        'SOLICITADO', 'SIN_VEREDICTO'   => 'en_revision',
        'VALIDADO_JEFE'                 => 'asignado',
        'REGISTRADO_SAP', 'ESPERA_KFC'  => 'espera_repuesto',
        default                         => 'asignado',
    };
};

$partesDe = static function (array $p): array {
    $j = json_decode((string) ($p['partes'] ?? ''), true);
    return is_array($j) ? $j : [];
};

$errFlash = Ui::errorFlash();

$FILTROS = [];
if ($puedeValidar) {
    $FILTROS['por_validar'] = ['Por validar', $cont['por_validar'], 'ambar'];
}
if ($puedeRegistrar) {
    $FILTROS['por_registrar'] = ['Por registrar en SAP', $cont['por_registrar'], 'ambar'];
}
if (!$esTecnico) {
    $FILTROS['vencidos']            = ['Fuera de plazo',       $cont['vencidos'],            'roja'];
    $FILTROS['esperando_kfc']       = ['Esperando a KFC',      $cont['esperando_kfc'],       ''];
    $FILTROS['prometidos_vencidos'] = ['Compromisos vencidos', $cont['prometidos_vencidos'], 'roja'];
}
$FILTROS['abiertos'] = ['Todos los vivos', $cont['abiertos'], ''];
$FILTROS['cerrados'] = ['Ya resueltos',    null,              ''];

Ui::cabecera($u, 'pendientes.php',
    ['repuestos' => $cont['vencidos'] > 0 ? ['n' => $cont['vencidos'], 'tono' => 'urge'] : null],
    ['titulo' => 'Repuestos y equipos']);

$csrf = Auth::csrfToken();
$enlaceG = fn(string $g): string => '?g=' . $g . ($q !== '' ? '&q=' . rawurlencode($q) : '');
?>

<div class="wrap ancho">
  <div class="titulo entra">
    <h1>Repuestos y equipos sin concluir</h1>
    <p class="sub">
      <?php if ($esTecnico): ?>
        Los equipos que dejaste trabados y en qué va cada solicitud: si el jefe
        ya la validó, si administración la registró en SAP y qué decidió Grupo
        KFC. Desde aquí insistes, y todo lo que escribas queda con su fecha.
      <?php else: ?>
        El técnico pide el repuesto con su diagnóstico, el <b>jefe de zona lo
        valida</b>, la <b>administración lo registra en SAP</b> y el caso queda
        abierto hasta que <b>Grupo KFC</b> envíe la pieza o decida qué se hace con
        el equipo. Si el equipo quedó <b>deshabilitado</b>, hay <b>48 horas</b>
        para validarlo: el plazo mide la decisión, no la reparación.
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
      <?php /* Cada cifra es un enlace a su filtro: la lista de abajo cambia
               con un toque, igual que «casos sin asignar» en la asignación. */ ?>
      <div class="tiles tiles-enlace">
        <a class="tile <?= $c48['vencidos'] ? 'alerta' : '' ?>" href="<?= $enlaceG('vencidos') ?>">
          <div class="n" data-n="<?= $c48['vencidos'] ?>">0</div>
          <div class="t">Fuera de plazo</div>
          <div class="pie">Parados, sin validar, más de 48 h</div>
        </a>
        <?php if ($puedeValidar): ?>
        <a class="tile <?= $cont['por_validar'] ? 'vence' : '' ?>" href="<?= $enlaceG('por_validar') ?>">
          <div class="n" data-n="<?= $cont['por_validar'] ?>">0</div>
          <div class="t">Por validar</div>
          <div class="pie">Le toca al jefe de zona</div>
        </a>
        <?php endif; ?>
        <?php if ($puedeRegistrar): ?>
        <a class="tile <?= $cont['por_registrar'] ? 'vence' : '' ?>" href="<?= $enlaceG('por_registrar') ?>">
          <div class="n" data-n="<?= $cont['por_registrar'] ?>">0</div>
          <div class="t">Por registrar en SAP</div>
          <div class="pie">Ya validados; falta el requerimiento</div>
        </a>
        <?php endif; ?>
        <a class="tile azul" href="<?= $enlaceG('esperando_kfc') ?>">
          <div class="n" data-n="<?= $cont['esperando_kfc'] ?>">0</div>
          <div class="t">Esperando a KFC</div>
          <div class="pie">Registrados en SAP, sin decisión</div>
        </a>
        <a class="tile <?= $cont['prometidos_vencidos'] ? 'alerta' : '' ?>" href="<?= $enlaceG('prometidos_vencidos') ?>">
          <div class="n" data-n="<?= $cont['prometidos_vencidos'] ?>">0</div>
          <div class="t">Compromisos vencidos</div>
          <div class="pie">Pasó la fecha comprometida</div>
        </a>
        <a class="tile atend" href="<?= $enlaceG('cerrados') ?>">
          <div class="n" data-n="<?= $cont['cerrados_semana'] ?>">0</div>
          <div class="t">Cerrados esta semana</div>
        </a>
      </div>
    <?php endif; ?>

    <?php if ($sinAviso): ?>
      <?php /* P-07: un equipo trabado reportado en una orden SIN aviso no
               arranca reloj ni entra a ninguna cola. Aquí se le asigna el
               aviso y recién ahí se abre el pendiente de verdad. */ ?>
      <section class="card regularizar" id="por-regularizar">
        <h2>Por regularizar (<?= count($sinAviso) ?>)</h2>
        <p class="sub" style="margin:0 0 10px">
          Equipos trabados que llegaron en una orden <b>sin aviso de SAP</b>: no
          tienen reloj ni cola hasta que alguien les ponga el aviso que les
          corresponde. Al asignarlo se abre la solicitud como si el técnico la
          hubiera enviado con él.
        </p>
        <div class="tabla-wrap">
          <table class="repartir">
            <thead><tr>
              <th>Local</th><th>Equipo</th><th>Qué encontró</th><th>Reportó</th><th>Aviso</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sinAviso as $s): ?>
              <tr>
                <td data-th="Local">
                  <b><?= $e($s['local_codigo'] ?: '—') ?></b>
                  <span class="desc"><?= Ui::zona($s['zona'] ?? null) ?></span>
                </td>
                <td data-th="Equipo">
                  <?= $e($s['equipo_desc'] ?: ($s['activo_fijo'] ?: 'Equipo sin identificar')) ?>
                  <?php if (!empty($s['parado']) && $s['parado'] !== 'false'): ?>
                    <span class="chip" style="background:#fee2e2;color:#991b1b;border-color:#fecaca">deshabilitado</span>
                  <?php endif; ?>
                </td>
                <td data-th="Qué encontró"><?= $e(mb_strimwidth((string) $s['diagnostico'], 0, 160, '…', 'UTF-8')) ?></td>
                <td data-th="Reportó">
                  <?= $e($s['reporto']) ?>
                  <span class="desc mono"><?= $e(substr((string) $s['capturada_en'], 0, 16)) ?></span>
                </td>
                <td data-th="Aviso">
                  <?php if ($puedeGestionar): ?>
                    <form method="post" class="asignar">
                      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="accion" value="regularizar">
                      <input type="hidden" name="envio_uuid" value="<?= $e($s['envio_uuid']) ?>">
                      <input type="text" name="aviso" inputmode="numeric" required maxlength="20"
                             placeholder="N.º de aviso SAP" style="height:38px;min-width:150px">
                      <button class="btn primary sm" type="submit">Asignar y abrir</button>
                    </form>
                  <?php else: ?>
                    <span class="sub">Lo regulariza administración.</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <div class="filtros-rapidos">
      <?php foreach ($FILTROS as $k => [$et, $n, $tono]): ?>
        <a class="fr <?= $grupo === $k ? 'on' : '' ?> <?= $e($tono) ?>" href="<?= $enlaceG($k) ?>">
          <?= $e($et) ?>
          <?php if ($n !== null && $n > 0): ?><span class="n"><?= (int) $n ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <form method="get" style="display:flex;gap:6px;margin-left:auto">
        <input type="hidden" name="g" value="<?= $e($grupo) ?>">
        <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Local, equipo, pieza, requerimiento…"
               style="height:36px;min-width:190px;font-size:13.5px">
        <button class="btn sm" type="submit">Buscar</button>
      </form>
    </div>

    <?php if (!$lista): ?>
      <?php
      $vacio = [
        'vencidos'            => ['Ningún equipo fuera de plazo', 'Todos los que están parados se validaron dentro de las 48 horas. Es exactamente lo que se busca.'],
        'por_validar'         => ['Nada por validar', 'Todas las solicitudes de tu zona ya tienen la vía fijada por el jefe.'],
        'por_registrar'       => ['Nada por registrar en SAP', 'Todo lo validado ya tiene su requerimiento registrado.'],
        'esperando_kfc'       => ['Nada esperando a Grupo KFC', 'Ningún requerimiento registrado está sin respuesta del cliente.'],
        'prometidos_vencidos' => ['Ningún compromiso vencido', 'Todas las fechas comprometidas siguen vigentes.'],
        'abiertos'            => ['No hay equipos trabados', 'Todas las intervenciones concluyeron en la visita, que es la premisa del servicio.'],
        'cerrados'            => ['Todavía no hay pendientes resueltos', 'Aquí aparecen los que ya volvieron a operar o cuya baja quedó ejecutada.'],
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
        Primero lo que está parado sin validar, y dentro de cada grupo lo más
        viejo arriba: es lo que lleva más tiempo esperando.
      </p>

      <?php foreach ($lista as $i => $p): ?>
        <?php
        $id = (int) $p['pendiente_id'];
        $r  = $p['reloj'];
        $vencido = $r !== null && $r['vencido'] && empty($r['cerrado']);
        $hilo = $hilos[$id] ?? [];
        $sinValidar = $p['via'] === 'SIN_VEREDICTO' && $p['abierto'];
        $estado = (string) $p['estado'];
        $kfc = (string) ($p['veredicto_kfc'] ?? 'PENDIENTE');
        $camino = $caminoDe($p);
        $partes = $partesDe($p);
        $codigo = (string) ($p['diagnostico_codigo'] ?? '');
        $nuevaCadena = in_array($estado, ['SOLICITADO', 'VALIDADO_JEFE', 'REGISTRADO_SAP', 'ESPERA_KFC'], true)
                    || $kfc !== 'PENDIENTE' || !empty($p['validado_en']);
        ?>
        <article class="rep <?= $vencido ? 'vencido' : ($p['deshabilitado'] ? 'deshabilitado' : '') ?><?= $p['abierto'] ? '' : ' atendido' ?>"
                 id="p<?= $id ?>" style="--i:<?= $i ?>">
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
                lo pidió <?= $e($p['abrio']) ?> el <?= $e(substr((string) $p['abierto_en'], 0, 10)) ?>
                <?php if (!empty($p['activo_fijo']) && $p['equipo_desc']): ?>
                  · activo <span class="mono"><?= $e($p['activo_fijo']) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div style="text-align:right">
              <?php if ($r !== null): ?>
                <span class="<?= $e($r['clase']) ?>"><?= $e($r['texto']) ?></span>
              <?php endif; ?>
              <div style="margin-top:6px">
                <span class="est est-<?= $claseEstado($p) ?>" title="<?= $e(Pendientes::ayudaEstado($estado)) ?>">
                  <?= $e(Pendientes::etiquetaEstado($estado)) ?>
                </span>
              </div>
            </div>
          </div>

          <?= Pendientes::barra48($p) ?>

          <?php if ($nuevaCadena): ?>
            <?php /* Los cuatro pasos de la cadena, con el que va. Se lee de un
                     vistazo dónde está atorado sin descifrar el estado. */ ?>
            <?php
            $pasos = [
              ['Solicitado',      true],
              ['Validado',        !empty($p['validado_en']) || !empty($p['veredicto_en'])],
              ['En SAP',          !empty($p['registrado_sap_en'])],
              ['KFC decidió',     $kfc !== 'PENDIENTE'],
              ['Resuelto',        !$p['abierto'] && $estado === 'RESUELTO'],
            ];
            $actual = null;
            foreach ($pasos as $k => [$_, $hecho]) { if (!$hecho) { $actual = $k; break; } }
            ?>
            <ol class="pasos" aria-label="Pasos de la solicitud">
              <?php foreach ($pasos as $k => [$et, $hecho]): ?>
                <li class="<?= $hecho ? 'hecho' : ($k === $actual ? 'actual' : '') ?>"><?= $e($et) ?></li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>

          <div class="nota">
            <?php if ($codigo !== ''): ?>
              <b class="diag-cod"><?= $e($codigo) ?><?= isset($titulosDiag[$codigo]) ? ' · ' . $e($titulosDiag[$codigo]) : '' ?></b>
            <?php endif; ?>
            <?= $e($p['diagnostico']) ?>
            <span class="de">Diagnóstico de <?= $e($p['abrio']) ?></span>
          </div>

          <?php if ($partes): ?>
            <ul class="partes">
              <?php foreach ($partes as $pt): ?>
                <li>
                  <b><?= (int) ($pt['cantidad'] ?? 1) ?> ×</b> <?= $e((string) ($pt['descripcion'] ?? '')) ?>
                  <?php if (!empty($pt['numero_parte'])): ?><span class="mono">· n.º parte <?= $e((string) $pt['numero_parte']) ?></span><?php endif; ?>
                  <?php if (!empty($pt['codigo'])): ?><span class="mono">· <?= $e((string) $pt['codigo']) ?></span><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php elseif (!empty($p['parte'])): ?>
            <p class="sub" style="margin-top:8px">Pieza: <?= $e($p['parte']) ?><?= (int) $p['cantidad'] > 1 ? ' × ' . (int) $p['cantidad'] : '' ?></p>
          <?php endif; ?>

          <?php if (!$sinValidar): ?>
            <div class="seguimiento">
              <p>
                <b>Vía: <?= $e(Pendientes::etiquetaVia($p['via'])) ?></b><?php
                  if (!empty($p['decidio'])) { echo ' · validó ' . $e($p['decidio']); }
                  if (!empty($p['veredicto_en'])) { echo ' el ' . $e(substr((string) $p['veredicto_en'], 0, 10)); }
                ?>
              </p>
              <?php if (!empty($p['requerimiento_sap'])): ?>
                <p>
                  <b>Requerimiento SAP <span class="mono"><?= $e($p['requerimiento_sap']) ?></span></b><?php
                    if (!empty($p['registro_sap'])) { echo ' · registró ' . $e($p['registro_sap']); }
                    if (!empty($p['registrado_sap_en'])) { echo ' el ' . $e(substr((string) $p['registrado_sap_en'], 0, 10)); }
                  ?>
                  <?php if ($kfc === 'PENDIENTE' && $p['abierto'] && ($p['dias_esperando_kfc'] ?? null) !== null): ?>
                    <?php $d = (int) $p['dias_esperando_kfc']; ?>
                    · <span class="<?= $d >= 7 ? 'edad edad-viejo' : 'edad edad-3' ?>">
                      <?= $d === 0 ? 'registrado hoy' : ($d === 1 ? '1 día esperando a KFC' : $d . ' días esperando a KFC') ?>
                    </span>
                  <?php endif; ?>
                </p>
              <?php elseif ($estado === 'VALIDADO_JEFE'): ?>
                <p style="color:var(--warn)">Falta registrar el requerimiento en SAP.</p>
              <?php endif; ?>
              <?php if ($kfc !== 'PENDIENTE'): ?>
                <p>
                  <b>Grupo KFC: <?= $e(Pendientes::etiquetaVeredictoKfc($kfc)) ?></b><?php
                    if (!empty($p['veredicto_kfc_ref'])) { echo ' · ref. <span class="mono">' . $e($p['veredicto_kfc_ref']) . '</span>'; }
                    if (!empty($p['tercero']) && in_array($kfc, ['OTRO_PROVEEDOR', 'TALLER_INDUSTEC'], true)) { echo ' · ' . $e($p['tercero']); }
                    if (!empty($p['decidio_kfc'])) { echo ' · anotó ' . $e($p['decidio_kfc']); }
                    if (!empty($p['veredicto_kfc_en'])) { echo ' el ' . $e(substr((string) $p['veredicto_kfc_en'], 0, 10)); }
                  ?>
                </p>
              <?php endif; ?>
              <?php if (!empty($p['prometido_para'])): ?>
                <p>
                  Comprometido para <b><?= $e($p['prometido_para']) ?></b>
                  <?php if (($p['dias_vencido_prom'] ?? null) !== null && $p['abierto']): ?>
                    · <span class="edad edad-viejo">vencido hace <?= (int) $p['dias_vencido_prom'] ?> día<?= (int) $p['dias_vencido_prom'] === 1 ? '' : 's' ?></span>
                  <?php endif; ?>
                </p>
              <?php elseif ($p['abierto'] && $kfc !== 'PENDIENTE' && $kfc !== 'OTRO_PROVEEDOR'): ?>
                <p style="color:var(--warn)">Sin fecha comprometida todavía.</p>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($hilo): ?>
            <div class="hilo">
              <?php foreach ($hilo as $nt): ?>
                <div class="msg <?= $nt['urgente'] ? 'insiste' : '' ?> tipo-<?= $e(strtolower((string) $nt['tipo'])) ?>">
                  <span class="quien"><?= $e($nt['nombre']) ?></span>
                  <span class="chip" style="font-size:10.5px"><?= $e(Ui::ROL[$nt['rol']] ?? $nt['rol']) ?></span>
                  <?php if (!empty($nt['estado_desp'])): ?>
                    <span class="chip paso" title="<?= $e(Pendientes::etiquetaEstado($nt['estado_antes'] ?? null)) ?> → <?= $e(Pendientes::etiquetaEstado($nt['estado_desp'])) ?>">
                      → <?= $e(Pendientes::etiquetaEstado($nt['estado_desp'])) ?>
                    </span>
                  <?php endif; ?>
                  <span class="cuando" data-hace="<?= $e($nt['creado_en']) ?>"><?= $e(substr((string) $nt['creado_en'], 0, 16)) ?></span>
                  <div class="txt">
                    <?php if ($nt['urgente']): ?><b>Urgente — </b><?php endif; ?>
                    <?php if ($nt['tipo'] === 'AVISO_INTERNO'): ?><i>(aviso interno)</i> <?php endif; ?>
                    <?= $e($nt['texto']) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ((int) $p['insistencias'] >= 2 && $p['abierto']): ?>
            <p class="sub" style="color:var(--danger);margin-top:8px">
              El técnico ya insistió <b><?= (int) $p['insistencias'] ?> veces</b> por este pendiente.
            </p>
          <?php endif; ?>

          <?php if ($p['abierto']): ?>
            <div class="acciones">
              <?php if ($estado === 'SOLICITADO' && $puedeValidar): ?>
                <button class="btn primary sm" type="button"
                        onclick="validar(<?= $id ?>, <?= $e(json_encode($p['equipo_desc'] ?: $p['activo_fijo'])) ?>)">
                  Validar la solicitud
                </button>
              <?php elseif ($sinValidar && $puedeValidar): ?>
                <?php /* Fila anterior a la 009 (SIN_VEREDICTO sin estado
                         SOLICITADO): se valida igual, con la misma acción. */ ?>
                <button class="btn primary sm" type="button"
                        onclick="validar(<?= $id ?>, <?= $e(json_encode($p['equipo_desc'] ?: $p['activo_fijo'])) ?>)">
                  Validar la solicitud
                </button>
              <?php endif; ?>

              <?php if ($estado === 'VALIDADO_JEFE' && $puedeRegistrar): ?>
                <button class="btn primary sm" type="button" onclick="registrarSap(<?= $id ?>)">Registrar en SAP</button>
              <?php endif; ?>

              <?php if (in_array($estado, ['REGISTRADO_SAP', 'ESPERA_KFC'], true) && $puedeGestionar): ?>
                <button class="btn primary sm" type="button" onclick="kfc(<?= $id ?>)">KFC decidió</button>
              <?php endif; ?>

              <?php if ($camino !== null && $puedeGestionar): ?>
                <button class="btn sm" type="button"
                        onclick="mover(<?= $id ?>, <?= $e(json_encode($camino)) ?>, '<?= $e($estado) ?>')">
                  Avanzar
                </button>
              <?php endif; ?>

              <?php if ($puedeResponder): ?>
                <button class="btn sm" type="button" onclick="responder(<?= $id ?>)">Responder</button>
              <?php endif; ?>

              <?php if ($puedeInsistir): ?>
                <button class="btn sm ghost" type="button" onclick="insistir(<?= $id ?>)">
                  <?= $esTecnico ? 'Insistir' : 'Aviso interno' ?>
                </button>
              <?php endif; ?>

              <?php if ($puedeGestionar): ?>
                <button class="btn sm ghost" type="button" onclick="cancelar(<?= $id ?>)">Cancelar</button>
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
   LOS DIÁLOGOS.
   Uno por acción y no uno por fila: con cien pendientes serían cientos de
   formularios en el HTML y la página se arrastraría en el celular del jefe de
   zona, que es donde más se usa. Todos llevan el token CSRF.
   ========================================================================= */ ?>

<dialog id="dlgValidar">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="accion" value="validar">
    <input type="hidden" name="pendiente_id" id="v-id">
    <h2>Validar la solicitud</h2>
    <p class="sub" style="margin:0 0 4px" id="v-equipo"></p>
    <p class="sub" style="margin:0 0 14px">
      Confirmas que el diagnóstico y el repuesto que pide el técnico son
      correctos, y por cuál vía va. Es la decisión que el plazo de 48 horas
      mide; queda registrada con tu nombre y la hora, y con ella la
      administración lo registra en SAP.
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

    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit">Validar</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>

<dialog id="dlgSap">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="accion" value="registrar_sap">
    <input type="hidden" name="pendiente_id" id="s-id">
    <h2>Registrar en SAP</h2>
    <p class="sub" style="margin:0 0 12px">
      El número del requerimiento es obligatorio: es lo que después se le
      reclama a Grupo KFC. Desde aquí el pendiente queda esperando su respuesta.
    </p>
    <div style="margin-bottom:12px">
      <label for="s-req">Número del requerimiento en SAP</label>
      <input type="text" id="s-req" name="requerimiento_sap" required maxlength="30" inputmode="numeric" autocomplete="off">
    </div>
    <div style="margin-bottom:12px">
      <label for="s-nota">Nota para el hilo</label>
      <textarea id="s-nota" name="nota" rows="2" placeholder="Opcional: lo que el técnico y el jefe deben saber."></textarea>
    </div>
    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit">Registrar</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>

<dialog id="dlgKfc">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="accion" value="kfc">
    <input type="hidden" name="pendiente_id" id="k-id">
    <h2>¿Qué decidió Grupo KFC?</h2>
    <p class="sub" style="margin:0 0 14px">
      Lo que el cliente respondió al requerimiento. Cada decisión deja su
      propio camino corto hasta resolver; «otro proveedor» cierra el pendiente
      en el acto, porque el seguimiento deja de ser de INDUSTEC.
    </p>
    <div class="opciones" style="margin-bottom:14px">
      <?php foreach (['REPUESTO_ENVIADO' => 'Envía el repuesto: falta que llegue al local y se instale.',
                      'TALLER_INDUSTEC'  => 'El equipo se repara en los talleres de INDUSTEC.',
                      'OTRO_PROVEEDOR'   => 'Lo manda a otro proveedor. Di a cuál.',
                      'BAJA'             => 'Da de baja el equipo.'] as $k => $ayuda): ?>
        <label class="opcion">
          <input type="radio" name="veredicto_kfc" value="<?= $e($k) ?>" required>
          <span class="t"><?= $e(ucfirst(Pendientes::etiquetaVeredictoKfc($k))) ?></span>
          <span class="d"><?= $e($ayuda) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div style="margin-bottom:12px">
      <label for="k-ref">Referencia de KFC</label>
      <input type="text" id="k-ref" name="referencia" maxlength="60" autocomplete="off"
             placeholder="Opcional: guía, orden o correo con que respondió">
    </div>
    <div style="margin-bottom:12px" id="k-tercero-wrap">
      <label for="k-tercero">Proveedor o taller</label>
      <input type="text" id="k-tercero" name="tercero" maxlength="120" autocomplete="off"
             placeholder="Obligatorio si lo manda a otro proveedor">
    </div>
    <div style="margin-bottom:12px">
      <label for="k-nota">Nota para el hilo</label>
      <textarea id="k-nota" name="nota" rows="2"></textarea>
    </div>
    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit">Guardar la decisión</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>

<dialog id="dlgMover">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="accion" value="mover">
    <input type="hidden" name="pendiente_id" id="m-id">
    <h2>Avanzar el pendiente</h2>
    <p class="sub" style="margin:0 0 12px">
      Solo se ofrecen los pasos del camino que ya se decidió. Retroceder o
      saltar un paso exige la nota.
    </p>
    <div style="margin-bottom:12px">
      <label for="m-est">Nuevo estado</label>
      <select name="estado" id="m-est" required></select>
    </div>
    <div style="margin-bottom:12px">
      <label for="m-nota">Nota para el hilo</label>
      <textarea id="m-nota" name="nota" rows="2"
        placeholder="Lo que el técnico va a leer."></textarea>
    </div>
    <div style="margin-bottom:12px">
      <label for="m-prom">Fecha comprometida</label>
      <input type="date" id="m-prom" name="prometido">
      <span class="derivado">Opcional. Si la dejas vacía, la pantalla dirá que no hay fecha comprometida en vez de inventar una.</span>
    </div>
    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit">Guardar</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>

<dialog id="dlgTexto">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="accion" id="t-accion">
    <input type="hidden" name="estado" id="t-estado" disabled>
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
/* Las etiquetas de los estados salen de aquí y no del HTML de cada fila para
   que la lista esté en un solo sitio; el servidor vuelve a comprobar cada
   paso igual, porque un POST se fabrica a mano. */
var NOMBRES = <?= json_encode(array_map(fn($v) => $v[0], Pendientes::ESTADOS), JSON_UNESCAPED_UNICODE) ?>;

function marcarOpcion(dlg) {
  document.querySelectorAll('#' + dlg + ' .opcion').forEach(function (o) { o.classList.remove('on'); });
}
/* La tarjeta entera se ilumina al elegir, no solo el punto del radio: con
   guantes o con el celular al sol, un radio de 14 px no se ve marcado. */
document.querySelectorAll('dialog .opcion input').forEach(function (r) {
  r.addEventListener('change', function () {
    var dlg = r.closest('dialog');
    dlg.querySelectorAll('.opcion').forEach(function (o) { o.classList.remove('on'); });
    if (r.checked) { r.closest('.opcion').classList.add('on'); }
    if (dlg.id === 'dlgValidar') {
      // Dar de baja un activo del cliente exige el sustento escrito.
      document.getElementById('v-nota').required = (r.value === 'BAJA');
    }
    if (dlg.id === 'dlgKfc') {
      var t = document.getElementById('k-tercero');
      t.required = (r.value === 'OTRO_PROVEEDOR');
      t.placeholder = r.value === 'TALLER_INDUSTEC' ? 'Opcional: queda como INDUSTEC si lo dejas vacío'
                    : (r.value === 'OTRO_PROVEEDOR' ? 'Obligatorio: a qué proveedor lo mandó' : 'No aplica para esta decisión');
      t.disabled = !(r.value === 'OTRO_PROVEEDOR' || r.value === 'TALLER_INDUSTEC');
    }
  });
});

function validar(id, equipo) {
  document.getElementById('v-id').value = id;
  document.getElementById('v-equipo').textContent = equipo || '';
  document.getElementById('v-nota').required = false;
  marcarOpcion('dlgValidar');
  document.getElementById('dlgValidar').showModal();
}

function registrarSap(id) {
  document.getElementById('s-id').value = id;
  document.getElementById('s-req').value = '';
  document.getElementById('dlgSap').showModal();
  document.getElementById('s-req').focus();
}

function kfc(id) {
  document.getElementById('k-id').value = id;
  marcarOpcion('dlgKfc');
  var t = document.getElementById('k-tercero');
  t.value = ''; t.required = false; t.disabled = false;
  document.getElementById('dlgKfc').showModal();
}

function mover(id, camino, actual) {
  document.getElementById('m-id').value = id;
  var s = document.getElementById('m-est');
  s.innerHTML = '';
  (camino || []).forEach(function (p) {
    if (p === actual) { return; }          // no se ofrece el estado en que ya está
    var o = document.createElement('option');
    o.value = p;
    o.textContent = NOMBRES[p] || p;
    s.appendChild(o);
  });
  document.getElementById('dlgMover').showModal();
}

function responder(id) { texto(id, 'responder', 'Responder en el hilo',
  'Lo que escribas lo ve el técnico en su bandeja, con tu nombre y la fecha. Sirve para pedir un dato antes de validar.', 'Enviar', false); }

function insistir(id) { texto(id, 'insistir',
  <?= json_encode($esTecnico ? 'Insistir por este pendiente' : 'Aviso interno', JSON_UNESCAPED_UNICODE) ?>,
  <?= json_encode($esTecnico
      ? 'Queda registrado con la fecha. Es lo que permite demostrar después que se avisó a tiempo.'
      : 'Un recordatorio de la oficina. No cuenta como insistencia del técnico ante Grupo KFC.', JSON_UNESCAPED_UNICODE) ?>,
  'Enviar', true); }

function cancelar(id) {
  texto(id, 'mover', 'Cancelar el pendiente',
        'No procedía o se resolvió de otra forma. El motivo es obligatorio y lo lee el técnico.', 'Cancelar el pendiente', false);
  var est = document.getElementById('t-estado');
  est.value = 'CANCELADO'; est.disabled = false;
}

function texto(id, accion, titulo, ayuda, ok, urgente) {
  document.getElementById('t-id').value = id;
  document.getElementById('t-accion').value = accion;
  document.getElementById('t-estado').disabled = true;   // solo «cancelar» lo manda
  document.getElementById('t-titulo').textContent = titulo;
  document.getElementById('t-ayuda').textContent = ayuda;
  document.getElementById('t-ok').textContent = ok;
  document.getElementById('t-urg').hidden = !urgente;
  var t = document.getElementById('t-texto');
  t.value = '';
  t.placeholder = urgente
    ? 'p. ej. el administrador insiste en que envíen el repuesto urgente, el equipo sigue deshabilitado'
    : 'p. ej. la pieza llega el jueves; ya está registrado el requerimiento';
  document.getElementById('dlgTexto').showModal();
}
</script>

<?php Ui::pie(); ?>
