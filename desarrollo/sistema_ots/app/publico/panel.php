<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Pendientes.php';
require_once __DIR__ . '/nucleo/Reconciliar.php';
require_once __DIR__ . '/nucleo/Ui.php';
require_once __DIR__ . '/nucleo/Vocabulario.php';

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

$zonaAlc  = Auth::zonaAlcance();
$fuente   = Casos::catalogo();
$gestion  = Casos::gestion();
$aten     = Casos::atenciones();
$casos    = Casos::enAlcance($fuente['datos'] ?? [], $gestion);
$pend     = Pendientes::contadores();
$hoy      = date('Y-m-d');

/* -------------------------------------------------------------------------
   La tarjeta por zona, el total general, el cuadro «TOTAL DE ÓRDENES
   ABIERTAS» y las tareas que cuentan órdenes abiertas salen de UNA función,
   `Casos::tarjetasPorZona()`, que es la misma que usa el buzón para
   `casos.php?grupo=`. Antes este panel contaba por su cuenta en un bucle y
   `vencidos48DeZona()` iba a la base otra vez por cada zona: dos cálculos de
   la misma cifra, que es como terminó `sinAsignar` diciendo dos números.
   Sobre los casos de `enAlcance()`: el jefe de zona recibe solo su zona.
   ------------------------------------------------------------------------- */
$informes = Casos::informesPorAviso($gestion);
$tz       = Casos::tarjetasPorZona($casos, $gestion, $informes, $hoy, $zonaAlc !== null ? [$zonaAlc] : []);

/** El rótulo de una zona desde el diccionario: «ZONA CUENCA-LOJA», nunca el
 *  código CNLJ; '' es «SIN ZONA». Un código que el diccionario no conoce se
 *  muestra tal como vino, como hace `Ui::zona()`: es el dato de la base, no un
 *  nombre inventado (I-7), y una zona rara no deja a la administradora sin
 *  inicio. */
$tituloZona = static function (string $z): string {
    try {
        return Vocabulario::titulo(Vocabulario::deEstado($z, 'zona'));
    } catch (VocabularioError $ex) {
        return $z;
    }
};

/* -------------------------------------------------------------------------
   Las cifras de contexto del buzón (gráficos y cuadros de abajo).
   Una sola pasada sobre el arreglo en vez de varios `array_filter`
   encadenados: son 900 órdenes y esto se dibuja en cada carga.
   ------------------------------------------------------------------------- */
$n = ['total' => count($casos), 'alerta_vieja' => 0, 'hoy' => 0, 'semana' => 0, 'sin_zona' => 0];
// 'OTRA' entra al mismo mapa que UIO/LARB/CNLJ (ASG-21): antes una orden
// derivada a OTRA no sumaba en ningún contador y el panel la perdía de vista.
$porZona = ['UIO' => 0, 'LARB' => 0, 'CNLJ' => 0, 'OTRA' => 0];
$porEstado = [];
// Hoy más los seis días anteriores: con '-7 days' y un «>=» entraban OCHO días
// de órdenes en un número que dice «7 días».
$desde7 = date('Y-m-d', strtotime('-6 days'));

foreach ($casos as $c) {
    $aviso  = (string) ($c['aviso'] ?? '');
    $g      = $gestion[$aviso] ?? null;
    $estado = $g['estado'] ?? 'NUEVO';
    // El gráfico cuenta por el estado de vista: una cerrada sin atención ya
    // regularizada se ve neutra. El 2026-09-22 eran 644 de 884, todas
    // regularizadas, pintadas de rojo en la barra más grande del inicio.
    $vista = Ui::estadoVista($estado, $g);
    $porEstado[$vista] = ($porEstado[$vista] ?? 0) + 1;

    $z = (string) ($c['zona'] ?? '');
    if (isset($porZona[$z])) { $porZona[$z]++; } elseif ($z === '') { $n['sin_zona']++; }

    if (($c['fecha_creacion'] ?? '') >= $desde7) { $n['semana']++; }
    // «Con fecha SAP hoy» solo cuenta lo que sigue en el TOTAL DE ÓRDENES
    // ABIERTAS: una atendida o cerrada ya no está comprometida para nada.
    if (in_array($estado, Casos::ABIERTAS_INDUSTEC, true) && ($c['fecha_estimada'] ?? '') === $hoy) { $n['hoy']++; }
    if (($c['estado_alerta'] ?? '') === 'CON_ALERTA') {
        // La que de verdad urge: fuera del área, por decidir, y ya lleva una
        // semana así (ASG-17): se le puede escapar tanto a la reconciliación
        // como a quien mira el panel.
        // Autorizada —o no— como «otro trabajo» (011) ya tiene decisión.
        if (!in_array($estado, ['RESUELTO', 'NO_COMPETE'], true) && empty($g['otro_trabajo'])) {
            $edadAlerta = Ui::dias($c['fecha_creacion'] ?? null);
            if ($edadAlerta !== null && $edadAlerta >= 7) { $n['alerta_vieja']++; }
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

// Órdenes sin técnico: la misma cifra que la tarea «N órdenes sin asignar» y
// que las sublíneas «sin asignar» de las tarjetas (fila 1 + fila 2).
$cuentas = [
    'casos'     => $tz['sin_tecnico'] > 0 ? ['n' => $tz['sin_tecnico']] : null,
    'asignar'   => $tz['sin_tecnico'] > 0 ? ['n' => $tz['sin_tecnico'], 'tono' => 'ojo'] : null,
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
        Ves y gestionas <b>la <?= $e($tituloZona($zonaAlc)) ?></b>.
        Tu trabajo es que aquí no se atasque nada: asignar las órdenes que
        llegan y que ninguna solicitud con el equipo deshabilitado pase de
        48 horas sin validar.
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

  /* La zona en el texto de la tarea: «(ZONA UIO: 2 · ZONA CUENCA-LOJA: 1)».
     La administradora responde por las tres a la vez y así sabe a qué zona ir
     sin entrar al buzón a filtrar. Solo las zonas que tienen algo. */
  $desglose = static function (string $k) use ($tz, $tituloZona): string {
      $partes = [];
      foreach ($tz['zonas'] as $z => $t) {
          if (($t[$k] ?? 0) > 0) { $partes[] = $tituloZona((string) $z) . ': ' . $t[$k]; }
      }
      return $partes ? ' (' . implode(' · ', $partes) . ')' : '';
  };
  $V = static fn(string $clave, int $cuantos): string => Vocabulario::t($clave, $cuantos);

  if ($tz['sin_tecnico'] > 0 && Auth::puede('casos.asignar')) {
      $k = $tz['sin_tecnico'];
      $tareas[] = ['urge', $k, $V('ORDEN', $k) . ' ' . $V('SIN_ASIGNAR', $k) . $desglose('sin_tecnico'),
          'Órdenes que llegaron y todavía no tienen técnico. Las que pasan de 7 días sin ninguna OT INDUSTEC se cierran por falta de atención desde este panel, con el botón de abajo.',
          'asignacion.php', 'Asignar'];
  }
  if ($pend['vencidos'] > 0) {
      $k = $pend['vencidos'];
      $tareas[] = ['urge', $k, $V('SOLICITUD', $k) . ' ' . $V('VENCIDO_48H', $k),
          'Pasaron las 48 horas y nadie validó por dónde va: repuesto, reparación, garantía o baja. El local sigue con ese equipo deshabilitado.',
          'pendientes.php?g=vencidos', 'Validar'];
  }
  if ($pend['sin_veredicto'] > $pend['vencidos']) {
      $k = $pend['sin_veredicto'] - $pend['vencidos'];
      $tareas[] = ['ojo', $k, $V('SOLICITUD', $k) . ' ' . $V('POR_VALIDAR', $k) . ', a tiempo',
          'Todavía se está a tiempo. El plazo de 48 horas cuenta desde que el técnico reportó el equipo deshabilitado.',
          'pendientes.php?g=sin_veredicto', 'Ver'];
  }
  if ($esAdmin && $tz['atendidas'] > 0) {
      $k = $tz['atendidas'];
      $tareas[] = ['ojo', $k, $V('ATENDIDA', $k) . $desglose('atendidas'),
          'INDUSTEC ya emitió la OT INDUSTEC de cierre. Falta tu confirmación de que además la cerraste del lado de Grupo KFC, que es el dato que el correo nunca trae.',
          'casos.php?est=ATENDIDO', 'Confirmar'];
  }
  if ($esAdmin && $tz['en_revision'] > 0) {
      $k = $tz['en_revision'];
      $tareas[] = ['ojo', $k, $V('EN_REVISION', $k) . ', por resolver' . $desglose('en_revision'),
          'Un jefe de zona vio algo que cree que no nos compete y te lo mandó con el motivo escrito.',
          'casos.php?est=EN_REVISION', 'Resolver'];
  }
  if ($esAdmin && $tz['sin_regularizar'] > 0) {
      $k = $tz['sin_regularizar'];
      $tareas[] = ['', $k, $V('SIN_REGULARIZAR', $k) . $desglose('sin_regularizar'),
          'Se cerraron por pasar una semana sin ninguna OT INDUSTEC. Siguen constando como no atendidas hasta que se expliquen ante el cliente.',
          'casos.php?est=CERRADO_SIN_ATENCION', 'Regularizar'];
  }
  if ($novPendientes) {
      $k = $novPendientes;
      $tareas[] = ['ojo', $k, $V('NOVEDAD', $k) . ' ' . $V('NOVEDAD_REPORTADA', $k) . ' o ' . $V('NOVEDAD_EN_ESTUDIO', $k)
                              . ', ' . $V('NOVEDAD_POR_DECIDIR', $k),
          'Lo que los técnicos vieron y no era su orden: correctivos que vienen, y cosas de otras áreas (eléctrico, ventilación, desagüe) que hacen fallar los equipos. Hay que decidir cuáles se le piden a Grupo KFC como aviso SAP nuevo.',
          'novedades_visita.php', 'Revisar'];
  }
  // Fuera del área y sin decidir (011, «otros trabajos»). Antes contaba
  // también las ya decididas, así que la cifra nunca bajaba.
  $nOtros = count(array_filter($casos, fn($c) =>
      Casos::otroTrabajoPorDecidir($c, $gestion[(string) ($c['aviso'] ?? '')] ?? null)));
  if ($nOtros > 0) {
      $tareas[] = [$esAdmin ? 'ojo' : '', $nOtros, $V('OTRO_TRABAJO_POR_DECIDIR', $nOtros),
          $esAdmin
            ? 'Órdenes que parecen no ser de INDUSTEC. Tú resuelves: si hubo acuerdo con Grupo KFC, se autoriza como «otro trabajo» y se reporta aparte como extra; si no, «no nos compete» y se le pide a KFC que la derive.'
            : 'Órdenes que parecen no ser de INDUSTEC. La alerta no decide: la administración resuelve si es un «otro trabajo» acordado con KFC o si no nos compete.',
          'casos.php?otro=por_decidir', $esAdmin ? 'Resolver' : 'Mirar'];
  }
  if ($n['alerta_vieja'] > 0) {
      // Una orden fuera del área no se cierra por falta de atención (ASG-17):
      // se queda esperando que la administración resuelva, y por eso necesita
      // su propia tarea o se pierde entre las 900 que sí siguen la línea normal.
      $k = $n['alerta_vieja'];
      $tareas[] = ['urge', $k, $V('OTRO_TRABAJO_POR_DECIDIR', $k) . ' hace más de 7 días',
          'La reconciliación no las cierra solas por falta de atención: esperan a que la administración resuelva si nos compete. Llevan ya una semana así.',
          'casos.php?alerta=CON_ALERTA', $esAdmin ? 'Resolver' : 'Mirar'];
  }
  if (Auth::puede('casos.asignar')) {
      // «Asignada» no es «atendida»: una orden puede quedarse semanas en la
      // bandeja de un técnico sin que nadie lo note (ASG-15). Es la otra mitad
      // de «pedir seguimiento», que vive en casos.php. Se cuentan solo las que
      // siguen A ESPERA DE INFORME TÉCNICO: la que ya tiene su OT INDUSTEC de
      // evaluación no está quieta (antes sí se contaba).
      if ($tz['asignadas_3d'] !== null) {
          $k = $tz['asignadas_3d'];
          if ($k > 0) {
              $tareas[] = ['ojo', $k, $V('ASIGNADA_3D', $k) . $desglose('asignadas_3d'),
                  'Tienen técnico, pero todavía no hay ninguna OT INDUSTEC. Puede que solo falte pedirle que avise cómo va.',
                  'casos.php?grupo=espera_informe&dias_asignado=' . Casos::DIAS_ASIGNADA, 'Revisar'];
          }
      } elseif ($tz['asignadas_3d_por_estado'] > 0) {
          // Sin las fuentes de OT no se pueden descontar las que ya tienen la
          // suya. La alarma no se esconde: se da con la cifra por estado y se
          // dice qué no se pudo comprobar (I-7).
          $k = $tz['asignadas_3d_por_estado'];
          $tareas[] = ['ojo', $k, $V('ASIGNADA', $k) . ' hace 3+ días (no se pudo comprobar si ya tienen OT INDUSTEC)',
              'Falta una de las fuentes de OT INDUSTEC (atenciones.json, las emitidas desde la app o el archivo), así que algunas de estas ya pueden tener la suya.',
              'casos.php?est=ASIGNADO&dias_asignado=' . Casos::DIAS_ASIGNADA, 'Revisar'];
      }
  }
  if ($esAdmin && ($pend['por_registrar'] ?? 0) > 0) {
      // Lo que compra la administradora: repuestos validados por el jefe de
      // zona y ya listos para el número de SAP (ASG-16). `?? 0` porque
      // `Pendientes::contadores()` puede no traer todavía esta clave si S3
      // no ha terminado su parte del corte (interfaz fijada en ola2_specs.md).
      $k = $pend['por_registrar'];
      $tareas[] = ['ojo', $k, $V('SOLICITUD', $k) . ' ' . $V('POR_REGISTRAR_SAP', $k),
          'El jefe de zona ya validó el diagnóstico y la vía. Falta anotar el número del requerimiento en SAP.',
          'pendientes.php?g=por_registrar', 'Registrar'];
  }
  if ($esAdmin) {
      // Equipos que un técnico registró como «nuevo / no está en la lista» (D8):
      // ya se ofrecen en la lista del local, pero nadie los ha confirmado para
      // el maestro. Sin la 009 la tabla no existe y la línea no aparece.
      // `$ex` y no `$e`: `$e` es el escape de toda la página, y el catch lo
      // pisaba con la excepción justo cuando faltaba la tabla.
      try {
          $nEq = (int) ((Db::uno("SELECT COUNT(*) AS n FROM equipos_propuestos WHERE estado = 'PROPUESTO'") ?: [])['n'] ?? 0);
      } catch (Throwable $ex) {
          $nEq = 0;
      }
      if ($nEq > 0) {
          $tareas[] = ['', $nEq, $V('EQUIPO_PROPUESTO', $nEq) . ', registrados por los técnicos',
              'Un técnico marcó «Equipo nuevo / no está en la lista» en una OT INDUSTEC. Ya se ofrece en la lista de ese local; falta aprobarlo para el maestro, o rechazarlo con motivo.',
              'equipos.php?est=PROPUESTO', 'Confirmar'];
      }
  }
  ?>

  <h2 style="margin-top:4px">Lo que te toca ahora</h2>
  <?php if (!$tareas): ?>
    <?= Ui::aviso('ok',
        '<b>No tienes nada esperando por ti.</b>'
      . '<p>Ni órdenes sin asignar, ni solicitudes por validar, ni atendidas por '
      . 'cerrar en SAP' . ($zonaAlc ? ' en la ' . $e($tituloZona($zonaAlc)) : '') . '. '
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
     POR ZONA: LAS CUATRO CIFRAS DE ISABEL (VOCABULARIO.md §8).
     ÓRDENES ABIERTAS · ÓRDENES A ESPERA DE INFORME TÉCNICO · EQUIPOS
     DESHABILITADOS · TOTAL DE ÓRDENES ABIERTAS: los términos con que ella
     trabaja con SAP/KFC. Los rótulos salen del diccionario (`tarjeta_zona`),
     no de este archivo, y las cifras de `Casos::tarjetasPorZona()`, la misma
     función que filtra el buzón: cada cifra es un enlace que deja delante
     EXACTAMENTE esas órdenes.

     Las alarmas de la tarjeta vieja no se pierden: «sin asignar» y
     «asignadas hace 3+ días» son sublíneas de la fila 2, «vencidas (48 h)» de
     la fila 3, y «atendidas», «en revisión» y «sin regularizar» van al pie.
     El jefe de zona ve UNA tarjeta, la de su zona, con los mismos términos
     (antes el bloque solo salía para la administración).
     ===================================================================== */
  $TZ = Vocabulario::todo()['tarjeta_zona'];
  $filaDe = [];
  foreach ($TZ['filas'] as $f) { $filaDe[$f['clave']] = $f; }
  /** El rótulo de una sublínea. Si el diccionario no la trae, error y no un
   *  texto inventado (I-7), igual que `Vocabulario::t()`. */
  $subDe = static function (string $fila, string $sub) use ($filaDe): array {
      foreach ($filaDe[$fila]['sublineas'] ?? [] as $s) {
          if (($s['clave'] ?? '') === $sub) { return $s; }
      }
      throw new VocabularioError('Vocabulario: tarjeta_zona no tiene la sublínea «' . $sub . '» en «' . $fila . '»');
  };
  /** Una cifra. null = no se pudo calcular: «no disponible», nunca 0 (I-7). */
  $cifra = static function (?int $v, ?string $url) use ($e): string {
      if ($v === null) { return '<span class="zona-nd">no disponible</span>'; }
      return $v > 0 && $url !== null
          ? '<a href="' . $e($url) . '"><b>' . $v . '</b></a>'
          : '<b>' . $v . '</b>';
  };

  /** La tarjeta de una zona, entera: cuatro filas con sus sublíneas y el pie. */
  $tarjeta = static function (string $z, array $t) use ($e, $cifra, $filaDe, $subDe, $TZ, $tituloZona): string {
      $qz  = $z === '' ? 'SIN' : $z;                 // `casos.php?zona=SIN` es «sin zona»
      $cl  = in_array($z, ['UIO', 'LARB', 'CNLJ'], true) ? strtolower($z) : 'otra';
      $buz = static fn(string $resto): string => 'casos.php?zona=' . rawurlencode($qz) . '&' . $resto;
      $asig = 'asignacion.php' . ($z !== '' ? '?zona=' . rawurlencode($z) : '') . '#sin-asignar-' . $qz;

      // [clave de la fila, cifra, enlace, [[sublínea, cifra, enlace], …]]
      $filas = [
          ['ABIERTA', $t['abiertas'], $buz('grupo=abiertas'), [
              ['ESPERA_REPUESTO', $t['abiertas_espera_repuesto'], $buz('grupo=abiertas&est=ESPERA_REPUESTO')],
              ['SIN_ASIGNAR',     $t['abiertas_sin_asignar'],     $asig],
          ]],
          ['ESPERA_INFORME', $t['espera_informe'], $buz('grupo=espera_informe'), [
              ['SIN_ASIGNAR',              $t['sin_asignar'],      $asig],
              ['ASIGNADA_3D',              $t['asignadas_3d'],     $buz('grupo=espera_informe&dias_asignado=' . Casos::DIAS_ASIGNADA)],
              ['OTRO_TRABAJO_POR_DECIDIR', $t['otro_por_decidir'], $buz('grupo=espera_informe&otro=por_decidir')],
              ['OT_NO_EMITIDA',            $t['ot_no_emitida'],    null],
          ]],
          // No se suma: es un subconjunto del TOTAL. Deshabilitados + con
          // equipo operativo + sin dato del equipo = TOTAL, como el RESUMEN de Isabel.
          ['EQUIPO_DESHABILITADO', $t['deshabilitados'], $buz('grupo=deshabilitados'), [
              ['VENCIDO_48H',      $t['vencidas'],   $z !== '' ? 'pendientes.php?g=vencidos&zona=' . rawurlencode($z) : null],
              ['EQUIPO_OPERATIVO', $t['operativos'], null],
              ['SIN_DATO_EQUIPO',  $t['sin_dato'],   null],
          ]],
          // La sublínea «con más de 90 días (fuera del catálogo)» no se pinta:
          // el universo es el catálogo del buzón (el mismo que lista el
          // enlace), así que esa cifra es siempre 0 por construcción.
          ['TOTAL_ABIERTAS', $t['total'], $buz('grupo=total'), []],
      ];

      $h = '<div class="zona-card zona-' . $cl . '">'
         . '<h3>' . $e($tituloZona($z))
         . ' <span class="zona-ayuda" tabindex="0" title="' . $e($TZ['ayuda_comun']) . '" aria-label="'
         . $e($TZ['ayuda_comun']) . '">ⓘ</span></h3><ul class="zona-filas">';
      foreach ($filas as [$clave, $v, $url, $subs]) {
          $sep = !empty($filaDe[$clave]['separador_arriba']) ? ' zf-total' : '';
          $h .= '<li class="zf' . $sep . '"><span class="zf-r" title="' . $e(Vocabulario::ayuda($clave)) . '">'
              . $e($filaDe[$clave]['rotulo']) . '</span><span class="zf-n">' . $cifra($v, $url) . '</span>';
          // Sin la cifra de la fila no hay sublíneas: una sublínea sin su fila
          // no se puede leer como «de ellas».
          $lis = '';
          if ($v !== null) {
              foreach ($subs as [$sc, $sv, $su]) {
                  $s = $subDe($clave, $sc);
                  if (!empty($s['solo_si_mayor_que_cero']) && !$sv) { continue; }
                  $lis .= '<li>' . $e($s['rotulo']) . ': ' . $cifra($sv, $su) . '</li>';
              }
          }
          $h .= ($lis !== '' ? '<ul class="zf-sub">' . $lis . '</ul>' : '') . '</li>';
      }
      $h .= '</ul><ul class="zona-pie">';
      $pieUrl = ['ATENDIDA' => $buz('est=ATENDIDO'), 'EN_REVISION' => $buz('est=EN_REVISION'),
                 'SIN_REGULARIZAR' => $buz('est=CERRADO_SIN_ATENCION')];
      $pieN   = ['ATENDIDA' => $t['atendidas'], 'EN_REVISION' => $t['en_revision'],
                 'SIN_REGULARIZAR' => $t['sin_regularizar']];
      foreach ($TZ['pie'] as $p) {
          $pc = (string) $p['clave'];
          if (!array_key_exists($pc, $pieN)) {
              throw new VocabularioError('Vocabulario: la tarjeta no sabe contar el pie «' . $pc . '»');
          }
          $h .= '<li>' . $e($p['rotulo']) . ': ' . $cifra($pieN[$pc], $pieUrl[$pc]) . '</li>';
      }
      return $h . '</ul></div>';
  };

  $zonasPanel = Casos::zonasVisibles($tz, $zonaAlc);
  $sinZona = $tz['zonas']['']['total'] ?? 0;
  $otras   = 0;
  foreach ($tz['zonas'] as $zk => $t) {
      if (!in_array((string) $zk, ['', 'UIO', 'LARB', 'CNLJ'], true)) { $otras += $t['total']; }
  }
  ?>
    <h2 style="margin-top:26px">Por zona</h2>
    <?php if ($zonaAlc === null): ?>
      <?php
      /* La fila 5 del RESUMEN de Isabel: el total de las tres zonas, con sus
         equipos deshabilitados y operativos. OTRA ZONA y «sin zona» no entran
         en él, pero se dicen al lado para que cuadre con el cuadro de abajo:
         tres zonas + OTRA + sin zona = TOTAL DE ÓRDENES ABIERTAS del buzón. */
      $ltg = $TZ['linea_total_general'];
      $partes = [];
      foreach (['UIO', 'LARB', 'CNLJ'] as $zk) {
          $partes[] = $e($tituloZona($zk)) . ' <b>' . (int) $tz['zonas'][$zk]['total'] . '</b>';
      }
      $partes[] = $e(Vocabulario::titulo('EQUIPO_DESHABILITADO')) . ' ' . $cifra($tz['tres_zonas']['deshabilitados'], null);
      $partes[] = $e(Vocabulario::titulo('EQUIPO_OPERATIVO')) . ' ' . $cifra($tz['tres_zonas']['operativos'], null);
      ?>
      <p class="zona-total">
        <span class="zf-r" title="<?= $e(Vocabulario::ayuda($ltg['clave'])) ?>"><?= $e($ltg['rotulo']) ?></span>:
        <b data-total-general="<?= (int) $tz['tres_zonas']['total'] ?>"><?= (int) $tz['tres_zonas']['total'] ?></b>
        <span class="zona-total-det">· <?= implode(' · ', $partes) ?></span>
      </p>
      <?php if ($otras > 0 || $sinZona > 0): ?>
        <p class="zona-total zona-total-fuera">
          Fuera de las tres zonas:
          <?php if ($otras > 0): ?>
            <?= $e(Vocabulario::titulo('ZONA_OTRA')) ?> <a href="casos.php?zona=OTRA&amp;grupo=total"><b><?= $otras ?></b></a>
          <?php endif; ?>
          <?php if ($otras > 0 && $sinZona > 0): ?> · <?php endif; ?>
          <?php if ($sinZona > 0): ?>
            <?= $e(Vocabulario::titulo('SIN_ZONA')) ?> <a href="casos.php?zona=SIN&amp;grupo=total"><b><?= $sinZona ?></b></a>
            (el local no calza con el maestro)
          <?php endif; ?>
          → <?= $e(Vocabulario::titulo('TOTAL_ABIERTAS')) ?> del buzón: <b><?= (int) $tz['total'] ?></b>
        </p>
      <?php endif; ?>
    <?php endif; ?>

    <div class="panel-zonas<?= count($zonasPanel) === 1 ? ' una' : '' ?>">
      <?php foreach ($zonasPanel as $zp): ?>
        <?= $tarjeta((string) $zp, $tz['zonas'][$zp]) ?>
      <?php endforeach; ?>
    </div>
    <p class="sub zona-nota">
      <?= $e($TZ['ayuda_comun']) ?>
      Cuenta las órdenes de los últimos 90 días del buzón; no es la cifra de SAP.
    </p>
    <?php
    /* Lo que no se pudo calcular se dice con su motivo, debajo de las
       tarjetas: el «no disponible» de la celda sola no explica qué falta. */
    $nombresFuente = [
        'atenciones' => 'las OT INDUSTEC leídas del correo (atenciones.json)',
        'capturadas' => 'las OT INDUSTEC emitidas desde la app (migración 008)',
        'archivo'    => 'el archivo de OT INDUSTEC (migración 009)',
        'pendientes' => 'las solicitudes de repuesto (migración 007)',
    ];
    $faltan = static fn(array $cuales): array => array_values(array_map(
        static fn($k) => $nombresFuente[$k],
        array_filter($cuales, static fn($k) => empty($informes['fuentes'][$k]))));
    ?>
    <?php if (!$tz['ot_disponible']): ?>
      <p class="sub zona-nota"><b><?= $e(Vocabulario::titulo('ABIERTA')) ?></b> y
        <b><?= $e(Vocabulario::titulo('ESPERA_INFORME')) ?></b>: no disponible, porque no se pudo leer
        <?= $e(implode(', ', $faltan(['atenciones', 'capturadas', 'archivo']))) ?>. Sin esa fuente, una orden
        con su OT INDUSTEC se contaría a espera de informe técnico. El
        <?= $e(Vocabulario::t('TOTAL_ABIERTAS')) ?> sí se muestra: depende solo del estado de cada orden.</p>
    <?php endif; ?>
    <?php if (!$tz['equipo_disponible']): ?>
      <p class="sub zona-nota"><b><?= $e(Vocabulario::titulo('EQUIPO_DESHABILITADO')) ?></b>: no disponible, porque no se pudo leer
        <?= $e(implode(', ', $faltan(['atenciones', 'capturadas', 'pendientes']))) ?>.</p>
    <?php endif; ?>
    <?php
    // Las OT del correo las cruza la estación una vez al día; si el cruce se
    // quedó atrás, la fila 1 sale más baja de lo que es, y se avisa.
    $genAt = (string) ($informes['generado'] ?? '');
    $horasAt = $genAt !== '' && strtotime($genAt) !== false ? (int) floor((time() - strtotime($genAt)) / 3600) : null;
    ?>
    <?php if ($horasAt !== null && $horasAt > 24): ?>
      <p class="sub zona-nota">Las OT INDUSTEC leídas del correo son de hace <?= $horasAt ?> h
        (<?= $e(substr($genAt, 0, 16)) ?>): las que llegaron después todavía no cuentan.</p>
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
      <b><?= $candidatosSinAtencion ?> <?= $e(Vocabulario::t('ORDEN', $candidatosSinAtencion)) ?> sin OT INDUSTEC hace más de 7 días.</b>
      <p style="margin:6px 0 10px">
        Nadie las tocó desde que llegaron. Cerrarlas por falta de atención las
        saca de «sin asignar» y las deja en el buzón para que se regularicen
        ante KFC: no se borran ni se dan por cerradas en SAP.
      </p>
      <form method="post" action="casos.php"
            onsubmit="return confirm('¿Cerrar ' + <?= (int) $candidatosSinAtencion ?> + ' <?= $e(Vocabulario::t('ORDEN', $candidatosSinAtencion)) ?> por falta de atención?');">
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
      Un equipo deshabilitado en un local no puede pasar de 48 horas sin que el
      jefe de zona valide por dónde va: <b>repuesto</b>, <b>reparación</b>,
      <b>garantía</b> o <b>baja</b>. El plazo mide <b>la validación</b>, no la
      reparación completa: una garantía puede tardar semanas sin que eso sea un
      incumplimiento.
    </p>
    <?php if ($tot48 === 0): ?>
      <?= Ui::aviso('ok', 'Ningún equipo deshabilitado' . ($zonaAlc ? ' en la ' . $e($tituloZona($zonaAlc)) : '')
                        . '. Todas las intervenciones concluyeron en la visita.') ?>
    <?php else: ?>
      <?php /* Las etiquetas del diccionario (VOCABULARIO.md §8.8): las mismas que
               salen en el reporte a KFC. */ ?>
      <div class="tiles">
        <div class="tile <?= $c48['vencidos'] ? 'alerta' : '' ?>">
          <div class="n" data-n="<?= $c48['vencidos'] ?>">0</div>
          <div class="t"><?= $e(Vocabulario::titulo('VENCIDO_48H')) ?></div>
          <div class="pie">Equipo deshabilitado, sin validar y con más de 48 h</div>
        </div>
        <div class="tile <?= $c48['corriendo'] ? 'vence' : '' ?>">
          <div class="n" data-n="<?= $c48['corriendo'] ?>">0</div>
          <div class="t"><?= $e(Vocabulario::titulo('POR_VALIDAR')) ?>, a tiempo</div>
          <div class="pie">Todavía dentro del plazo</div>
        </div>
        <div class="tile atend">
          <div class="n" data-n="<?= $c48['a_tiempo'] ?>">0</div>
          <div class="t"><?= $e(Vocabulario::titulo('VALIDADA')) ?> a tiempo</div>
          <div class="pie">Histórico</div>
        </div>
        <div class="tile">
          <div class="n" data-n="<?= $c48['tarde'] ?>">0</div>
          <div class="t"><?= $e(Vocabulario::titulo('VALIDADA')) ?> tarde</div>
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
      <div class="t"><?= $e(Vocabulario::titulo('ORDENES_NUEVAS_7D')) ?></div></div>
    <div class="tile"><div class="n" data-n="<?= $n['hoy'] ?>">0</div>
      <div class="t"><?= $e(Vocabulario::titulo('FECHA_SAP_HOY')) ?></div>
      <div class="pie">Fecha estimada de SAP hoy, en órdenes abiertas</div></div>
    <?php /* Sale de la misma función que la tarjeta: son las «de ellas, a
             espera de repuesto» de la fila ÓRDENES ABIERTAS, sumadas. */ ?>
    <a class="tile <?= $tz['espera_repuesto'] ? 'vence' : '' ?>" href="casos.php?est=ESPERA_REPUESTO&amp;grupo=total"><div class="n" data-n="<?= (int) $tz['espera_repuesto'] ?>">0</div>
      <div class="t"><?= $e(Vocabulario::titulo('ESPERA_REPUESTO')) ?></div></a>
    <?php /* Antes decía «Vivos en 90 días» (884 el 2026-09-22, incluidas 766
             ya cerradas o regularizadas) y después «Siguen abiertos», que
             contaba también las ATENDIDO. Ahora es el TOTAL DE ÓRDENES
             ABIERTAS de las tarjetas, de la misma función: las tarjetas (con
             OTRA ZONA) + «sin zona» = este cuadro. */ ?>
    <a class="tile" href="casos.php?grupo=total"><div class="n" data-n="<?= (int) $tz['total'] ?>">0</div>
      <div class="t"><?= $e(Vocabulario::titulo('TOTAL_ABIERTAS')) ?></div>
      <div class="pie">De <?= $n['total'] ?> <?= $e(Vocabulario::t('ORDEN', $n['total'])) ?> en la ventana de 90 días<?php
        if ($zonaAlc === null && ($otras > 0 || $sinZona > 0)) {
            echo $e(' · incluye ' . implode(' y ', array_filter([
                $otras > 0 ? $otras . ' de ' . Vocabulario::titulo('ZONA_OTRA') : '',
                $sinZona > 0 ? $sinZona . ' ' . Vocabulario::t('SIN_ZONA', $sinZona) : '',
            ])));
        } ?></div></a>
    <?php if ($n['sin_zona']): ?>
      <a class="tile viol" href="casos.php?zona=SIN"><div class="n" data-n="<?= $n['sin_zona'] ?>">0</div>
        <div class="t"><?= $e(Vocabulario::titulo('SIN_ZONA')) ?></div>
        <div class="pie">El nombre de SAP no calza con el maestro</div></a>
    <?php endif; ?>
  </div>

  <?php if ($zonaAlc === null): ?>
    <?php
    // Los tres colores de zona pasaron la validación de la paleta contra TODOS
    // los pares, no solo los adyacentes: aquí conviven en el mismo gráfico.
    // La etiqueta es la del diccionario (CUENCA-LOJA, no CNLJ), y por eso el
    // color va explícito: graficos.js lo busca por el código de zona, que ya
    // no es la etiqueta. Son los mismos de su mapa ZONA.
    $colorZona = ['UIO' => '#7c3aed', 'LARB' => '#0d9488', 'CNLJ' => '#ea580c', 'OTRA' => '#64748b'];
    $datosZona = [];
    foreach ($porZona as $z => $c) {
        if ($c > 0) {
            $datosZona[] = ['e' => Vocabulario::corto(Vocabulario::deEstado($z, 'zona')), 'v' => $c, 'c' => $colorZona[$z]];
        }
    }
    if ($n['sin_zona']) { $datosZona[] = ['e' => Vocabulario::titulo('SIN_ZONA'), 'v' => $n['sin_zona'], 'c' => '#94a3b8']; }

    $datosEstado = [];
    foreach ($porEstado as $est => $c) {
        $datosEstado[] = ['e' => Ui::etiquetaEstado($est), 'v' => $c,
                          'c' => Ui::colorEstado($est)];
    }
    usort($datosEstado, fn($a, $b) => $b['v'] <=> $a['v']);
    ?>
    <div class="viz-grid" style="margin-top:14px">
      <figure class="viz" data-viz="anillo"
              data-titulo="<?= $e(Vocabulario::titulo('ORDEN')) ?> por zona"
              data-sub="Las <?= $n['total'] ?> <?= $e(Vocabulario::t('ORDEN', $n['total'])) ?> de la ventana de 90 días del correo, en cualquier estado"
              data-centro="<?= $e(Vocabulario::t('ORDEN', 2)) ?>"
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
      El correo avisa cuando Grupo KFC <b>crea</b> o <b>elimina</b> una orden, pero
      <b>no cuando la cierra</b>: una orden puede figurar aquí como abierta y
      estar cerrada en SAP. Lo que manda es el estado del export de SAP.
    </p>
  <?php endif; ?>
</div>

<?php
Ui::pie(['js' => ['graficos.js'], 'novedades' => true]);
