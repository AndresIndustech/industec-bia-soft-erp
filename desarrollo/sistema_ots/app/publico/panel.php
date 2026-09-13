<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Pendientes.php';
require_once __DIR__ . '/nucleo/Reconciliar.php';
require_once __DIR__ . '/nucleo/Ui.php';

/**
 * panel.php — Lo primero que se ve al entrar.
 *
 * ============================================================================
 * QUE CAMBIO, Y POR QUE
 *
 * Antes esto era una rejilla de tarjetas: nueve recuadros con el nombre de un
 * módulo y una frase. Bonito y perfectamente inútil, porque contestaba
 * «¿qué hay en este sistema?» cuando la pregunta de quien entra a las 7 de la
 * mañana es **«¿qué me toca hoy?»**.
 *
 * Ahora es un tablero de trabajo. La navegación entre módulos vive en la barra
 * de arriba —que está en todas las pantallas— y este espacio se usa para lo
 * único que justifica una pantalla de inicio: decirle a cada quien qué tiene
 * pendiente, cuánto, y llevarlo ahí de un clic.
 *
 * ============================================================================
 * CADA ROL VE OTRA COSA, PORQUE CADA ROL RESPONDE POR OTRA COSA
 *
 *   TECNICO     no llega aquí. Su producto es la bandeja de `mis.php`: móvil,
 *               con sus órdenes y sin una sola cifra de gestión, porque él no
 *               administra, atiende.
 *
 *   JEFE_ZONA   su misión es que en su zona no se atasque nada: repartir lo que
 *               llega, y que ningún equipo pase de 48 h sin veredicto. Esas dos
 *               cosas son las dos primeras cifras que ve.
 *
 *   ADMIN       responde ante Grupo KFC por las tres zonas. Lo suyo son los
 *   SUPERADMIN  pendientes que solo puede resolver ella: confirmar cierres en
 *               SAP, dar veredictos, regularizar lo que se cerró sin atender y
 *               pedirle a KFC los avisos de las novedades reportadas.
 *
 * ============================================================================
 * LO QUE ESTA PANTALLA NO HACE
 *
 * No inventa un número. Si el buzón no tiene datos, lo dice; si la migración
 * 007 no está aplicada, lo dice; si una cifra no se puede calcular, no aparece
 * en vez de aparecer en cero. Un tablero con un cero falso es peor que un
 * tablero incompleto: el cero se lee como «no hay nada que hacer» (I-7).
 */

$u = Auth::exigir();
if ($u['debe_cambiar_clave']) { header('Location: clave.php'); exit; }

// El técnico tiene su propia aplicación. Este tablero está pensado para una
// pantalla ancha y para decisiones de reparto; a él no le sirve ninguna de las
// dos cosas.
if ($u['rol'] === 'TECNICO') { header('Location: mis.php'); exit; }

$e = fn(?string $s): string => Ui::e($s);

/** Los vencidos de 48 h de UNA zona, calculado aquí porque
 *  `Pendientes::cumplimiento48()` no acepta zona todavía (se apoya en
 *  `Auth::zonaAlcance()` de la sesión, no en un parámetro): mismo criterio de
 *  «vencido» de esa función, misma resolución de zona que usa `Pendientes::alcance()`
 *  (la del caso manda sobre la del pendiente, porque un caso derivado se lleva
 *  su pendiente). Si S3 le agrega el parámetro, esta función deja de hacer falta. */
function vencidos48DeZona(string $zona): ?int
{
    if (!Pendientes::disponible()) { return null; }
    $f = Db::uno(
        "SELECT SUM(p.via = 'SIN_VEREDICTO' AND p.deshabilitado = 1
                    AND p.estado NOT IN ('RESUELTO','CANCELADO')
                    AND COALESCE(p.plazo_desde, p.abierto_en) < DATE_SUB(NOW(), INTERVAL 48 HOUR)) AS vencidos
           FROM pendientes p
          WHERE COALESCE((SELECT g.zona FROM casos_gestion g WHERE g.aviso = p.aviso), p.zona) = ?",
        [$zona]
    );
    return (int) ($f['vencidos'] ?? 0);
}

$zonaAlc  = Auth::zonaAlcance();
$fuente   = Casos::catalogo();
$gestion  = Casos::gestion();
$aten     = Casos::atenciones();
$casos    = Casos::enAlcance($fuente['datos'] ?? [], $gestion);
$pend     = Pendientes::contadores();
$hoy      = date('Y-m-d');

/* -------------------------------------------------------------------------
   Los recuentos del buzón.
   Se hacen en una sola pasada sobre el arreglo en vez de seis `array_filter`
   encadenados: son 918 casos y esto se dibuja en cada carga.
   ------------------------------------------------------------------------- */
$n = ['total' => count($casos), 'sin_asignar' => 0, 'alerta' => 0, 'alerta_vieja' => 0, 'hoy' => 0,
      'semana' => 0, 'atendidos_por_cerrar' => 0, 'en_revision' => 0,
      'sin_regularizar' => 0, 'sin_zona' => 0, 'espera' => 0, 'asignados_viejos' => 0];
// 'OTRA' entra al mismo mapa que UIO/LARB/CNLJ (ASG-21): antes un caso
// derivado a OTRA no sumaba en ningún contador y el panel lo perdía de vista.
$porZona = ['UIO' => 0, 'LARB' => 0, 'CNLJ' => 0, 'OTRA' => 0];
// El desglose por zona de "lo que te toca ahora" (ASG-09, TR-09): antes era
// un solo acumulador y la administradora no podía leer «cuántos sin repartir
// hay en CNLJ» sin entrar al buzón y filtrar a mano.
$nz = [];
foreach (array_keys($porZona) as $zk) {
    $nz[$zk] = ['sin_asignar' => 0, 'atendidos_por_cerrar' => 0, 'en_revision' => 0,
                'sin_regularizar' => 0, 'espera' => 0, 'asignados_viejos' => 0];
}
$porEstado = [];
$desde7 = date('Y-m-d', strtotime('-7 days'));

foreach ($casos as $c) {
    $aviso  = (string) ($c['aviso'] ?? '');
    $g      = $gestion[$aviso] ?? null;
    $estado = $g['estado'] ?? 'NUEVO';
    $porEstado[$estado] = ($porEstado[$estado] ?? 0) + 1;

    $z = (string) ($c['zona'] ?? '');
    if (isset($porZona[$z])) { $porZona[$z]++; } elseif ($z === '') { $n['sin_zona']++; }
    $zk = isset($nz[$z]) ? $z : null;

    if (($c['fecha_creacion'] ?? '') >= $desde7) { $n['semana']++; }
    if (($c['fecha_estimada'] ?? '') === $hoy)   { $n['hoy']++; }
    if (($c['estado_alerta'] ?? '') === 'CON_ALERTA') {
        $n['alerta']++;
        // La que de verdad urge: con alerta, sin veredicto, y ya lleva una
        // semana así (ASG-17): se le puede escapar tanto a la reconciliación
        // como a quien mira el panel.
        if (!in_array($estado, ['RESUELTO', 'NO_COMPETE'], true)) {
            $edadAlerta = Ui::dias($c['fecha_creacion'] ?? null);
            if ($edadAlerta !== null && $edadAlerta >= 7) { $n['alerta_vieja']++; }
        }
    }

    if ($estado === 'NUEVO' && !isset($aten[$aviso])) { $n['sin_asignar']++; if ($zk !== null) { $nz[$zk]['sin_asignar']++; } }
    if ($estado === 'ATENDIDO')        { $n['atendidos_por_cerrar']++; if ($zk !== null) { $nz[$zk]['atendidos_por_cerrar']++; } }
    if ($estado === 'EN_REVISION')     { $n['en_revision']++; if ($zk !== null) { $nz[$zk]['en_revision']++; } }
    if ($estado === 'ESPERA_REPUESTO') { $n['espera']++; if ($zk !== null) { $nz[$zk]['espera']++; } }
    if ($estado === 'CERRADO_SIN_ATENCION' && empty($g['regularizado_en'])) {
        $n['sin_regularizar']++;
        if ($zk !== null) { $nz[$zk]['sin_regularizar']++; }
    }
    // Asignado y sin informe hace 3 días o más (ASG-15): lo único que hoy dice
    // si un caso repartido se está quedando quieto en la bandeja de alguien.
    if ($estado === 'ASIGNADO') {
        $diasAsig = Ui::dias(substr((string) ($g['asignado_en'] ?? ''), 0, 10) ?: null);
        if ($diasAsig !== null && $diasAsig >= 3) {
            $n['asignados_viejos']++;
            if ($zk !== null) { $nz[$zk]['asignados_viejos']++; }
        }
    }
}

// Cuántos casos NUEVO llevan más de 7 días sin ningún informe, en seco (sin
// escribir nada): es la cifra que respalda «Cerrar por falta de atención»
// desde este mismo panel (ASG-05, spec S2 punto 3).
$candidatosSinAtencion = Reconciliar::cerrarSinAtencion(Casos::catalogo()['datos'] ?? [], $aten, 7, false)['candidatos'];

/* Novedades del preventivo por revisar. La tabla puede no existir todavía. */
$novPendientes = null;
try {
    $f = Db::uno("SELECT COUNT(*) c FROM novedades WHERE estado IN ('REPORTADA','EN_REVISION')"
               . ($zonaAlc !== null ? ' AND zona = ?' : ''), $zonaAlc !== null ? [$zonaAlc] : []);
    $novPendientes = (int) ($f['c'] ?? 0);
} catch (Throwable $ex) {
    $novPendientes = null;      // migración 007 sin aplicar: no se inventa un 0
}

$cuentas = [
    'casos'     => $n['sin_asignar'] > 0 ? ['n' => $n['sin_asignar']] : null,
    'asignar'   => $n['sin_asignar'] > 0 ? ['n' => $n['sin_asignar'], 'tono' => 'ojo'] : null,
    'repuestos' => $pend['vencidos'] > 0 ? ['n' => $pend['vencidos'], 'tono' => 'urge']
                                         : ($pend['abiertos'] > 0 ? ['n' => $pend['abiertos']] : null),
];

$esAdmin = in_array($u['rol'], ['ADMIN', 'SUPERADMIN'], true);
$errFlash = Ui::errorFlash();

Ui::cabecera($u, 'panel.php', $cuentas, ['titulo' => 'Inicio']);
?>

<div class="wrap ancho">

  <div class="titulo entra">
    <h1>Hola, <?= $e(Ui::nombrePila($u['nombre'])) ?></h1>
    <p class="sub">
      <?php if ($zonaAlc): ?>
        Ves y gestionas <b>la zona <?= $e($zonaAlc) ?></b>.
        Tu trabajo es que aquí no se atasque nada: repartir lo que llega y que
        ningún equipo pase de 48 horas sin veredicto.
      <?php else: ?>
        Ves y gestionas <b>las tres zonas</b>. Abajo está lo que solo puedes
        resolver tú; el resto lo mueven los jefes de zona.
      <?php endif; ?>
    </p>
  </div>

  <?php if ($errFlash): ?>
    <?= Ui::aviso('err', $e($errFlash), true) ?>
  <?php endif; ?>

  <?php if (!$fuente): ?>
    <?= Ui::aviso('warn',
        '<b>No hay datos del buzón.</b>'
      . '<p>Falta <span class="mono">catalogos/casos_sap.json</span>, que genera '
      . '<span class="mono">t2_6_imap_avisos.py</span> al leer el correo. Hasta que '
      . 'esté, este tablero no puede decir qué hay pendiente — y no va a inventarlo.</p>') ?>
  <?php else: ?>

  <?php
  /* =====================================================================
     LO QUE TE TOCA AHORA.
     Va antes que cualquier cifra de contexto. Cada línea es una acción que
     esta persona —y solo esta persona— puede resolver, con el enlace que la
     deja exactamente delante de esos casos y no en la lista completa.
     ===================================================================== */
  $tareas = [];

  if ($n['sin_asignar'] > 0 && Auth::puede('casos.asignar')) {
      $tareas[] = ['urge', $n['sin_asignar'], 'sin repartir',
          'Casos que llegaron y todavía no tienen técnico. Los que pasan de 7 días sin ningún informe se cierran por falta de atención desde este panel, con el botón de abajo.',
          'asignacion.php', 'Repartir'];
  }
  if ($pend['vencidos'] > 0) {
      $tareas[] = ['urge', $pend['vencidos'], 'equipos parados sin veredicto, fuera de plazo',
          'Pasaron las 48 horas y nadie decidió si va por repuesto, reparación, garantía o baja. El local sigue sin ese equipo.',
          'pendientes.php?g=vencidos', 'Decidir ahora'];
  }
  if ($pend['sin_veredicto'] > $pend['vencidos']) {
      $tareas[] = ['ojo', $pend['sin_veredicto'] - $pend['vencidos'], 'esperando veredicto, con plazo vivo',
          'Todavía se está a tiempo. El plazo cuenta desde que el técnico dejó el equipo sin concluir.',
          'pendientes.php?g=sin_veredicto', 'Ver'];
  }
  if ($esAdmin && $n['atendidos_por_cerrar'] > 0) {
      $tareas[] = ['ojo', $n['atendidos_por_cerrar'], 'atendidos, esperando que los cierres en SAP',
          'INDUSTEC ya emitió la orden. Falta tu confirmación de que además lo cerraste del lado de Grupo KFC, que es el dato que el correo nunca trae.',
          'casos.php?est=ATENDIDO', 'Confirmar'];
  }
  if ($esAdmin && $n['en_revision'] > 0) {
      $tareas[] = ['ojo', $n['en_revision'], 'en revisión, esperando tu veredicto',
          'Un jefe de zona vio algo que cree que no nos compete y te lo mandó con el motivo escrito.',
          'casos.php?est=EN_REVISION', 'Resolver'];
  }
  if ($esAdmin && $n['sin_regularizar'] > 0) {
      $tareas[] = ['', $n['sin_regularizar'], 'cerrados sin atención, sin regularizar ante KFC',
          'Se cerraron por pasar una semana sin ningún informe. Siguen constando como no atendidos hasta que se expliquen ante el cliente.',
          'casos.php?est=CERRADO_SIN_ATENCION', 'Regularizar'];
  }
  if ($novPendientes) {
      $tareas[] = ['ojo', $novPendientes, 'novedades reportadas en visita, sin decidir',
          'Lo que los técnicos vieron y no era su orden: correctivos que vienen, y cosas de otras áreas (eléctrico, ventilación, desagüe) que hacen fallar los equipos. Hay que decidir cuáles se le piden a Grupo KFC como aviso nuevo.',
          'novedades_visita.php', 'Revisar'];
  }
  if ($n['alerta'] > 0) {
      $tareas[] = ['', $n['alerta'], 'con alerta de alcance',
          'Casos que parecen no corresponder a INDUSTEC. La alerta no decide: solo los pone a mano para que alguien los mire.',
          'casos.php?alerta=CON_ALERTA', 'Mirar'];
  }
  if ($n['alerta_vieja'] > 0) {
      // Un caso con alerta no se cierra por falta de atención (ASG-17): se
      // queda esperando veredicto, y por eso necesita su propia tarea o se
      // pierde entre los 918 que sí siguen la línea normal.
      $tareas[] = ['urge', $n['alerta_vieja'], 'con alerta de alcance y más de 7 días sin veredicto',
          'La reconciliación no los cierra solos por falta de atención: esperan a que decidas si nos compete. Llevan ya una semana así.',
          'casos.php?alerta=CON_ALERTA', 'Decidir'];
  }
  if ($n['asignados_viejos'] > 0 && Auth::puede('casos.asignar')) {
      // «Asignado» no es «atendido»: hoy un caso puede quedarse semanas en la
      // bandeja de un técnico sin que nadie lo note (ASG-15). Es la otra mitad
      // de «pedir seguimiento», que vive en casos.php.
      $tareas[] = ['ojo', $n['asignados_viejos'], 'asignados hace 3 días o más, sin informe',
          'Tienen técnico, pero nadie ha mandado la orden todavía. Puede que solo falte pedirle que avise cómo va.',
          'casos.php?est=ASIGNADO&dias_asignado=3', 'Revisar'];
  }
  if ($esAdmin && ($pend['por_registrar'] ?? 0) > 0) {
      // Lo que compra la administradora: repuestos validados por el jefe de
      // zona y ya listos para el número de SAP (ASG-16). `?? 0` porque
      // `Pendientes::contadores()` puede no traer todavía esta clave si S3
      // no ha terminado su parte del corte (interfaz fijada en ola2_specs.md).
      $tareas[] = ['ojo', $pend['por_registrar'], 'repuestos validados, por registrar en SAP',
          'El jefe de zona ya confirmó el diagnóstico y la vía. Falta anotar el número del requerimiento en SAP.',
          'pendientes.php?g=por_registrar', 'Registrar'];
  }
  ?>

  <h2 style="margin-top:4px">Lo que te toca ahora</h2>
  <?php if (!$tareas): ?>
    <?= Ui::aviso('ok',
        '<b>No tienes nada esperando por ti.</b>'
      . '<p>Ni casos sin repartir, ni equipos parados sin veredicto, ni cierres '
      . 'por confirmar' . ($zonaAlc ? ' en ' . $e($zonaAlc) : '') . '. '
      . 'Lo que hay en curso lo están moviendo otros.</p>') ?>
  <?php else: ?>
    <div class="escalona" style="margin-bottom:20px">
      <?php foreach ($tareas as [$tono, $cuantos, $que, $porque, $url, $accion]): ?>
        <a class="rep <?= $tono === 'urge' ? 'vencido' : ($tono === 'ojo' ? 'deshabilitado' : '') ?>"
           href="<?= $e($url) ?>" style="display:block;text-decoration:none;color:inherit">
          <div class="cab">
            <div style="min-width:0">
              <div class="que">
                <span class="num" style="font-size:20px"><?= (int) $cuantos ?></span>
                <?= $e($que) ?>
              </div>
              <div class="meta" style="max-width:76ch"><?= $e($porque) ?></div>
            </div>
            <span class="btn <?= $tono === 'urge' ? 'danger' : 'primary' ?> sm"><?= $e($accion) ?> →</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php /* =====================================================================
     EL DESGLOSE POR ZONA (ASG-09, TR-09).
     La administradora responde por las tres zonas a la vez; sin esto, para
     saber «cuántos sin repartir hay en CNLJ» tenía que entrar al buzón y
     filtrar a mano. Cada celda es un enlace que deja delante exactamente esos
     casos, igual que las tareas de arriba.
     ===================================================================== */ ?>
  <?php if ($zonaAlc === null):
    $zonasPanel = array_values(array_filter(['UIO', 'LARB', 'CNLJ', 'OTRA'], fn($zk) => $zk !== 'OTRA' || $porZona['OTRA'] > 0));
  ?>
    <h2 style="margin-top:26px">Por zona</h2>
    <div class="panel-zonas">
      <?php foreach ($zonasPanel as $zp): ?>
        <?php $v48 = vencidos48DeZona($zp); $zonaCasos = $zp === 'OTRA' ? 'OTRA' : $zp; ?>
        <div class="zona-card zona-<?= strtolower($zp) ?>">
          <h3><?= Ui::zona($zp) ?></h3>
          <ul>
            <li>
              <?php if ($nz[$zp]['sin_asignar'] > 0): ?>
                <a href="asignacion.php?zona=<?= $zp ?>#por-repartir-<?= $zp ?>"><b><?= $nz[$zp]['sin_asignar'] ?></b> sin repartir</a>
              <?php else: ?><b>0</b> sin repartir<?php endif; ?>
            </li>
            <li>
              <?php if ($v48 === null): ?>
                <span class="sub">vencidos 48 h: no disponible</span>
              <?php elseif ($v48 > 0): ?>
                <a href="pendientes.php?g=vencidos"><b><?= $v48 ?></b> vencidos 48 h</a>
              <?php else: ?><b>0</b> vencidos 48 h<?php endif; ?>
            </li>
            <li>
              <?php if ($nz[$zp]['asignados_viejos'] > 0): ?>
                <a href="casos.php?zona=<?= $zonaCasos ?>&est=ASIGNADO&dias_asignado=3"><b><?= $nz[$zp]['asignados_viejos'] ?></b> asignados 3+ días sin informe</a>
              <?php else: ?><b>0</b> asignados 3+ días sin informe<?php endif; ?>
            </li>
            <li>
              <?php if ($nz[$zp]['atendidos_por_cerrar'] > 0): ?>
                <a href="casos.php?zona=<?= $zonaCasos ?>&est=ATENDIDO"><b><?= $nz[$zp]['atendidos_por_cerrar'] ?></b> atendidos por cerrar</a>
              <?php else: ?><b>0</b> atendidos por cerrar<?php endif; ?>
            </li>
            <li>
              <?php if ($nz[$zp]['en_revision'] > 0): ?>
                <a href="casos.php?zona=<?= $zonaCasos ?>&est=EN_REVISION"><b><?= $nz[$zp]['en_revision'] ?></b> en revisión</a>
              <?php else: ?><b>0</b> en revisión<?php endif; ?>
            </li>
            <li>
              <?php if ($nz[$zp]['sin_regularizar'] > 0): ?>
                <a href="casos.php?zona=<?= $zonaCasos ?>&est=CERRADO_SIN_ATENCION"><b><?= $nz[$zp]['sin_regularizar'] ?></b> sin regularizar</a>
              <?php else: ?><b>0</b> sin regularizar<?php endif; ?>
            </li>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!Pendientes::disponible()): ?>
      <p class="sub" style="margin:6px 0 0">Los vencidos de 48 h no se pueden calcular: falta la migración de pendientes.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php /* =====================================================================
     CERRAR POR FALTA DE ATENCIÓN (ASG-05).
     El texto de esta pantalla y el de asignación prometían un cierre
     automático a los 7 días que en el código solo existía en
     `reconciliar_cli.php --ejecutar`, y nadie lo estaba lanzando. Mientras se
     decide si eso se automatiza (sync_casos.php no es un archivo de este
     corte), queda aquí un botón explícito: la administradora ve cuántos
     candidatos hay y decide cuándo cerrarlos, con la misma guarda del 15 %
     que usaría un cron. */ ?>
  <?php if ($esAdmin && Auth::puede('casos.cerrar_sin_atencion') && $candidatosSinAtencion > 0): ?>
    <div class="nota-regular" style="margin:14px 0">
      <b><?= $candidatosSinAtencion ?> caso<?= $candidatosSinAtencion === 1 ? '' : 's' ?> con más de 7 días sin ningún informe.</b>
      <p style="margin:6px 0 10px">
        Nadie los tocó desde que llegaron. Cerrarlos por falta de atención los
        saca de «por repartir» y los deja en el buzón para que se regularicen
        ante KFC — no se borran ni se dan por resueltos.
      </p>
      <form method="post" action="casos.php"
            onsubmit="return confirm('¿Cerrar ' + <?= (int) $candidatosSinAtencion ?> + ' casos por falta de atención?');">
        <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
        <input type="hidden" name="accion" value="cerrar_sin_atencion">
        <button class="btn danger" type="submit">Cerrar por falta de atención</button>
      </form>
    </div>
  <?php endif; ?>

  <?php /* =====================================================================
     EL COMPROMISO DE LAS 48 HORAS.
     Se le da su propio bloque porque es la promesa más dura del servicio y la
     única que se mide en horas. Un local sin su freidora no factura.
     ===================================================================== */ ?>
  <?php if (Pendientes::disponible()): ?>
    <?php $c48 = Pendientes::cumplimiento48(); $tot48 = array_sum($c48); ?>
    <h2>Equipos deshabilitados · el plazo de 48 horas</h2>
    <p class="sub" style="margin:-4px 0 12px;max-width:80ch">
      Un equipo parado en un local no puede pasar de 48 horas sin que alguien
      decida por dónde va: <b>repuesto</b>, <b>reparación</b>, <b>garantía</b> o
      <b>baja</b>. El plazo mide <b>la decisión</b>, no la reparación completa —
      una garantía puede tardar semanas sin que eso sea un incumplimiento.
    </p>
    <?php if ($tot48 === 0): ?>
      <?= Ui::aviso('ok', 'Ningún equipo deshabilitado' . ($zonaAlc ? ' en ' . $e($zonaAlc) : '')
                        . '. Todas las intervenciones concluyeron en la visita.') ?>
    <?php else: ?>
      <div class="tiles">
        <div class="tile <?= $c48['vencidos'] ? 'alerta' : '' ?>">
          <div class="n" data-n="<?= $c48['vencidos'] ?>">0</div>
          <div class="t">Vencidos ahora</div>
          <div class="pie">Parados, sin veredicto y con más de 48 h</div>
        </div>
        <div class="tile <?= $c48['corriendo'] ? 'vence' : '' ?>">
          <div class="n" data-n="<?= $c48['corriendo'] ?>">0</div>
          <div class="t">Con el reloj corriendo</div>
          <div class="pie">Todavía dentro del plazo</div>
        </div>
        <div class="tile atend">
          <div class="n" data-n="<?= $c48['a_tiempo'] ?>">0</div>
          <div class="t">Decididos a tiempo</div>
          <div class="pie">Histórico</div>
        </div>
        <div class="tile">
          <div class="n" data-n="<?= $c48['tarde'] ?>">0</div>
          <div class="t">Decididos tarde</div>
          <div class="pie">Histórico</div>
        </div>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <?= Ui::aviso('info',
        '<b>El módulo de equipos deshabilitados todavía no está en la base.</b>'
      . '<p>La migración <span class="mono">007_pendientes_y_captura.sql</span> está '
      . 'escrita y sin aplicar: cambia el esquema y eso requiere aprobación. Mientras '
      . 'tanto, el plazo de 48 horas no se puede medir y esta pantalla no lo simula.</p>') ?>
  <?php endif; ?>

  <?php /* =====================================================================
     EL CONTEXTO. Va al final a propósito: son cifras para saber cómo va la
     operación, no cosas que hacer. Ponerlas arriba empujaría las acciones
     fuera de la primera pantalla, que es donde tienen que estar.
     ===================================================================== */ ?>
  <h2>Cómo va el buzón</h2>
  <div class="tiles">
    <div class="tile azul"><div class="n" data-n="<?= $n['semana'] ?>">0</div>
      <div class="t">Llegaron esta semana</div></div>
    <div class="tile"><div class="n" data-n="<?= $n['hoy'] ?>">0</div>
      <div class="t">Comprometidos hoy</div></div>
    <div class="tile <?= $n['espera'] ? 'vence' : '' ?>"><div class="n" data-n="<?= $n['espera'] ?>">0</div>
      <div class="t">Esperando un equipo</div></div>
    <div class="tile"><div class="n" data-n="<?= $n['total'] ?>">0</div>
      <div class="t">Vivos en 90 días</div></div>
    <?php if ($n['sin_zona']): ?>
      <a class="tile viol" href="casos.php?zona=SIN"><div class="n" data-n="<?= $n['sin_zona'] ?>">0</div>
        <div class="t">Sin zona resuelta</div>
        <div class="pie">El nombre de SAP no calza con el maestro</div></a>
    <?php endif; ?>
  </div>

  <?php if ($zonaAlc === null): ?>
    <?php
    // Los tres colores de zona pasaron la validación de la paleta contra TODOS
    // los pares, no solo los adyacentes: aquí conviven en el mismo gráfico.
    $datosZona = [];
    foreach ($porZona as $z => $c) { if ($c > 0) { $datosZona[] = ['e' => $z, 'v' => $c]; } }
    if ($n['sin_zona']) { $datosZona[] = ['e' => 'Sin zona', 'v' => $n['sin_zona'], 'c' => '#94a3b8']; }

    $datosEstado = [];
    foreach ($porEstado as $est => $c) {
        $datosEstado[] = ['e' => Ui::etiquetaEstado($est), 'v' => $c,
                          'c' => Ui::colorEstado($est)];
    }
    usort($datosEstado, fn($a, $b) => $b['v'] <=> $a['v']);
    ?>
    <div class="viz-grid" style="margin-top:14px">
      <figure class="viz" data-viz="anillo"
              data-titulo="Casos vivos por zona"
              data-sub="De los <?= $n['total'] ?> que siguen en la ventana de 90 días del correo"
              data-centro="casos"
              data-datos='<?= $e(json_encode($datosZona, JSON_UNESCAPED_UNICODE)) ?>'></figure>
      <figure class="viz" data-viz="barras"
              data-titulo="En qué estado están"
              data-sub="El estado que decidió una persona o dedujo la reconciliación, no el de SAP"
              data-ancho-etiqueta="140"
              data-datos='<?= $e(json_encode($datosEstado, JSON_UNESCAPED_UNICODE)) ?>'></figure>
    </div>
  <?php endif; ?>

  <?php endif; /* $fuente */ ?>

  <?php
  /* =====================================================================
     QUE TODAVIA NO EXISTE.
     El panel viejo dibujaba los modulos no construidos como tarjetas
     apagadas. Ese tablero se reemplazo por la lista de acciones, y con el se
     perdio algo que si valia: saber que falta. Una persona que no encuentra
     la bitacora no sabe si la esta buscando mal o si no existe, y acaba
     preguntando. Se dice en una linea, al pie, sin ocupar el sitio de lo que
     hay que hacer.
     ===================================================================== */
  $porConstruir = [];
  if (Auth::puede('reportes.generar')) {
      $porConstruir[] = 'los reportes mensual y de Grupo KFC sobre sus plantillas reales';
  }
  if (Auth::puede('maestros.editar')) {
      $porConstruir[] = 'la edición de maestros (locales, técnicos y equipos)';
  }
  if (Auth::puede('bitacora.ver')) {
      $porConstruir[] = 'la pantalla de bitácora — el registro ya se está guardando, '
                      . 'lo que falta es cómo mirarlo';
  }
  ?>
  <?php if ($porConstruir): ?>
    <p class="sub" style="margin-top:22px">
      <b>Todavía no está construido:</b>
      <?= $e(implode('; ', $porConstruir)) ?>.
      No es que no lo encuentres: no existe aún. Está en el plan.
    </p>
  <?php endif; ?>

  <?php
  /* La fecha del último barrido va al pie y no de titular: es contexto sobre
     la frescura del dato, no una tarea. Pero tiene que estar, porque el correo
     avisa cuando KFC crea o elimina un caso y NUNCA cuando lo cierra. */
  $gen = (string) ($fuente['generado'] ?? '');
  if ($gen !== ''):
      $d = (int) floor((strtotime($hoy) - strtotime(substr($gen, 0, 10))) / 86400);
  ?>
    <p class="sub" style="margin-top:24px">
      Último barrido del correo: <b><?= $e(substr($gen, 0, 10)) ?></b><?php
        if ($d > 0) { echo ' · hace ' . $d . ' día' . ($d === 1 ? '' : 's'); } ?>.
      El correo avisa cuando Grupo KFC <b>crea</b> o <b>elimina</b> un caso, pero
      <b>no cuando lo cierra</b>: un caso puede figurar aquí como pendiente y
      estar cerrado en SAP. Lo que manda es el estado del export de SAP.
    </p>
  <?php endif; ?>
</div>

<?php
Ui::pie(['js' => ['graficos.js'], 'novedades' => true]);
