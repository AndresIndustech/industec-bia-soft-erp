<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Pendientes.php';
require_once __DIR__ . '/nucleo/Novedades.php';

/**
 * reportes.php — El tablero de la administración y la gerencia.
 *
 * ============================================================================
 * QUE INDICADORES, Y POR QUE ESTOS
 *
 * Hay dos familias, y conviene no mezclarlas:
 *
 * LO QUE PIDE GRUPO KFC — volumen, reparto por zona, antigüedad de lo abierto
 * y cumplimiento de plazos. Es lo que se responde en una reunión de servicio.
 *
 * LO QUE MIDE SI EL SERVICIO ESTA SANO — y que nadie pide, pero es lo que
 * anticipa los problemas. De la práctica de mantenimiento en operaciones como
 * esta, cuatro se sostienen solos:
 *
 *   1. **Se concluye en una visita.** Es la premisa de INDUSTEC. Cuando este
 *      número baja, sube todo lo demás: más viajes, más horas, más equipos
 *      parados. Es el indicador que hay que mirar primero.
 *   2. **Antigüedad de lo abierto**, no solo cuánto hay abierto. Cien casos de
 *      hoy es una operación normal; diez de hace un mes es un problema.
 *   3. **Reincidencia por local y equipo.** El mismo equipo que rompe tres
 *      veces al año casi nunca es mala reparación: es causa de otra área.
 *   4. **Veredictos dentro de las 48 horas.** El compromiso duro del servicio.
 *
 * ============================================================================
 * LOS GRAFICOS
 *
 * Anillo cuando hay que repartir un total en pocas partes: zonas, correctivo
 * contra preventivo, cumplimiento del plazo. Barras cuando hay que comparar
 * magnitudes con nombre: locales, técnicos, áreas. Columnas para el tiempo.
 *
 * **Con más de cinco porciones no se usa anillo**, se usa barras. No es un
 * capricho: comparar dos ángulos pequeños es imposible, y un gráfico que no se
 * puede leer es peor que una tabla. El dibujante pliega solo la cola en «Otros»
 * si le llegan más.
 *
 * Cada gráfico lleva **su tabla debajo**, plegada. No es un adorno de
 * accesibilidad: es que a la administradora le van a preguntar «¿y cuántos
 * exactamente?» y la respuesta no puede ser pasar el dedo por una barra.
 *
 * ============================================================================
 * DE DONDE SALE CADA CIFRA, Y QUE NO SE PUEDE CALCULAR TODAVIA
 *
 * Todo esto sale del catálogo del buzón (los 90 días del correo de SAP), de
 * las decisiones guardadas en `casos_gestion`, y del cruce de informes de OT.
 * Lo que NO se puede calcular con eso se dice en la propia pantalla en vez de
 * aproximarse: el histórico completo vive en la estación, no aquí (I-7).
 */

$u = Auth::exigir('reportes.ver');
if ($u['debe_cambiar_clave']) { header('Location: clave.php'); exit; }

$e = fn(?string $s): string => Ui::e($s);
$j = fn(array $d): string => Ui::e(json_encode($d, JSON_UNESCAPED_UNICODE));

$zonaAlc = Auth::zonaAlcance();
$fuente  = Casos::catalogo();
$gestion = Casos::gestion();
$aten    = Casos::atenciones();
$casos   = Casos::enAlcance($fuente['datos'] ?? [], $gestion);
$hoy     = date('Y-m-d');

Auth::bitacora('CONSULTAR', 'reportes', 'tablero', 'casos=' . count($casos));

/* -------------------------------------------------------------------------
   Una sola pasada sobre los casos. Son ~918 y aquí se calculan diez cortes:
   hacerlo con diez `array_filter` encadenados es diez recorridos.
   ------------------------------------------------------------------------- */
$porZona = ['UIO' => 0, 'LARB' => 0, 'CNLJ' => 0];
$sinZona = 0;
$porEstado = $porMes = $porLocal = $porTipo = $porTecnico = [];
$edad = ['Hoy y ayer' => 0, 'De 2 a 3 días' => 0, 'De 4 a 7 días' => 0, 'Más de una semana' => 0];
$abiertos = 0;
$conInforme = 0;
$cadenas = [];

foreach ($casos as $c) {
    $aviso  = (string) ($c['aviso'] ?? '');
    $estado = $gestion[$aviso]['estado'] ?? 'NUEVO';
    $porEstado[$estado] = ($porEstado[$estado] ?? 0) + 1;

    $z = (string) ($c['zona'] ?? '');
    if (isset($porZona[$z])) { $porZona[$z]++; } else { $sinZona++; }

    $mes = substr((string) ($c['fecha_creacion'] ?? ''), 0, 7);
    if ($mes !== '') { $porMes[$mes] = ($porMes[$mes] ?? 0) + 1; }

    $loc = trim((string) ($c['local'] ?? ''));
    if ($loc !== '') { $porLocal[$loc] = ($porLocal[$loc] ?? 0) + 1; }

    $cad = trim((string) ($c['cadena'] ?? ''));
    if ($cad !== '') { $cadenas[$cad] = ($cadenas[$cad] ?? 0) + 1; }

    $tp = trim((string) ($c['caso'] ?? '')) ?: 'Sin clasificar';
    $porTipo[$tp] = ($porTipo[$tp] ?? 0) + 1;

    // La antigüedad SOLO de lo que sigue abierto. Medirla sobre todo, incluido
    // lo ya cerrado, da un número que solo crece con el tiempo y no dice nada
    // de cómo va la operación hoy.
    $vivo = !in_array($estado, ['RESUELTO', 'NO_COMPETE', 'CERRADO_SIN_ATENCION'], true);
    if ($vivo) {
        $abiertos++;
        $d = Ui::dias($c['fecha_creacion'] ?? null);
        if ($d !== null) {
            if ($d <= 1)      { $edad['Hoy y ayer']++; }
            elseif ($d <= 3)  { $edad['De 2 a 3 días']++; }
            elseif ($d <= 7)  { $edad['De 4 a 7 días']++; }
            else              { $edad['Más de una semana']++; }
        }
    }

    if (isset($aten[$aviso])) {
        $conInforme++;
        foreach (($aten[$aviso]['tecnicos'] ?? []) as $t) {
            $porTecnico[$t] = ($porTecnico[$t] ?? 0) + 1;
        }
    }
}

arsort($porLocal); arsort($porTipo); arsort($porTecnico); ksort($porMes); arsort($cadenas);

/* -------------------------------------------------------------------------
   La reincidencia: locales con varios casos en la ventana de 90 días.
   Es el indicador que apunta a causa de otra área en vez de a mala reparación.
   ------------------------------------------------------------------------- */
$reincidentes = array_filter($porLocal, fn($n) => $n >= 5);

/* -------------------------------------------------------------------------
   Se concluye en una visita: el indicador central del servicio.
   Se calcula sobre los casos que YA tienen informe, porque de los que no lo
   tienen todavía no se sabe si concluyeron. Contarlos como «no concluidos»
   haría que el número empeorara solo por tener trabajo reciente.
   ------------------------------------------------------------------------- */
/* Sin la 007 no hay de dónde saber qué quedó trabado: el número es «sin dato»,
   no 100 % en verde. Y se restan solo los casos de ESTA población —con informe
   y en el alcance de quien mira—, no la tabla entera de pendientes. */
$conPendiente = 0;
$concluyeUna = 0;
$pctConcluye = null;
if (Pendientes::disponible() && $conInforme > 0) {
    $trabados = array_flip(array_column(
        Db::todos("SELECT DISTINCT aviso FROM pendientes WHERE estado <> 'CANCELADO'"), 'aviso'));
    foreach ($casos as $c) {
        $av = (string) ($c['aviso'] ?? '');
        if (isset($aten[$av], $trabados[$av])) { $conPendiente++; }
    }
    $concluyeUna = max(0, $conInforme - $conPendiente);
    $pctConcluye = round($concluyeUna * 100 / $conInforme);
}

$c48 = Pendientes::cumplimiento48();
$novC = Novedades::contadores();

/* Novedades por área: qué hace fallar los equipos y de quién es. */
$novPorArea = [];
if (Novedades::disponible()) {
    $sql = 'SELECT tipo, COUNT(*) n FROM novedades'
         . ($zonaAlc !== null ? ' WHERE zona = ?' : '') . ' GROUP BY tipo ORDER BY n DESC';
    foreach (Db::todos($sql, $zonaAlc !== null ? [$zonaAlc] : []) as $r) {
        $novPorArea[] = ['e' => Novedades::etiquetaTipo($r['tipo']), 'v' => (int) $r['n']];
    }
}

/* ---- Los arreglos que consume el dibujante ------------------------------ */
$dZona = [];
foreach ($porZona as $z => $c) { if ($c > 0) { $dZona[] = ['e' => $z, 'v' => $c]; } }
if ($sinZona > 0) { $dZona[] = ['e' => 'Sin zona', 'v' => $sinZona, 'c' => '#94a3b8']; }

$dEstado = [];
foreach ($porEstado as $k => $c) {
    $dEstado[] = ['e' => Ui::etiquetaEstado($k), 'v' => $c, 'c' => Ui::colorEstado($k)];
}
usort($dEstado, fn($a, $b) => $b['v'] <=> $a['v']);

$dEdad = [];
$colorEdad = ['Hoy y ayer' => '#1baf7a', 'De 2 a 3 días' => '#2a78d6',
              'De 4 a 7 días' => '#eda100', 'Más de una semana' => '#e34948'];
foreach ($edad as $k => $c) { $dEdad[] = ['e' => $k, 'v' => $c, 'c' => $colorEdad[$k]]; }

$dMes = [];
foreach (array_slice($porMes, -12, 12, true) as $m => $c) {
    $dMes[] = ['e' => substr($m, 5, 2) . '/' . substr($m, 2, 2), 'v' => $c];
}

$dLocal = [];
foreach (array_slice($porLocal, 0, 12, true) as $l => $c) { $dLocal[] = ['e' => $l, 'v' => $c]; }

$dTipo = [];
foreach (array_slice($porTipo, 0, 10, true) as $t => $c) {
    $dTipo[] = ['e' => mb_strimwidth($t, 0, 34, '…', 'UTF-8'), 'v' => $c];
}

$dTecnico = [];
foreach (array_slice($porTecnico, 0, 12, true) as $t => $c) { $dTecnico[] = ['e' => $t, 'v' => $c]; }

$d48 = [
    ['e' => 'Decididos a tiempo', 'v' => $c48['a_tiempo'],  'c' => '#1baf7a'],
    ['e' => 'Decididos tarde',    'v' => $c48['tarde'],     'c' => '#eda100'],
    ['e' => 'Reloj corriendo',    'v' => $c48['corriendo'], 'c' => '#2a78d6'],
    ['e' => 'Vencidos ahora',     'v' => $c48['vencidos'],  'c' => '#e34948'],
];

$dCadena = [];
foreach (array_slice($cadenas, 0, 8, true) as $k => $c) { $dCadena[] = ['e' => $k, 'v' => $c]; }

$generado = (string) ($fuente['generado'] ?? '');

Ui::cabecera($u, 'reportes.php', [], ['titulo' => 'Reportes']);
?>

<div class="wrap ancho">
  <div class="titulo entra">
    <h1>Tablero de servicio</h1>
    <p class="sub">
      <?= $zonaAlc === null ? 'Las tres zonas. '
            : ($zonaAlc !== '' ? 'Zona <b>' . $e($zonaAlc) . '</b>. ' : 'Tu cuenta no tiene zona asignada. ') ?>
      Todo lo de esta pantalla sale de la ventana de <b>90 días</b> del correo de
      SAP<?= $generado !== '' ? ', barrida por última vez el <b>' . $e(substr($generado, 0, 10)) . '</b>' : '' ?>,
      cruzada con los informes de orden que llegan al mismo buzón. El histórico
      completo de 7.069 órdenes vive en la estación, no aquí.
    </p>
  </div>

  <?php if (!$fuente): ?>
    <?= Ui::aviso('warn',
        '<b>No hay datos del buzón.</b><p>Sin <span class="mono">casos_sap.json</span> '
      . 'no hay nada que graficar, y esta pantalla no va a inventar una tendencia.</p>') ?>
    <?php Ui::pie(); exit; ?>
  <?php endif; ?>

  <?php /* =====================================================================
     PRIMERO EL INDICADOR DEL NEGOCIO, no el volumen.
     Cuántos casos hay es contexto; si el servicio concluye en una visita es
     el estado de salud, y es lo que hay que ver antes que nada.
     ===================================================================== */ ?>
  <h2 style="margin-top:4px">La salud del servicio</h2>
  <div class="viz-grid">
    <div class="hero">
      <?php if ($pctConcluye === null): ?>
        <div class="n" style="color:var(--muted)">—</div>
        <div class="t">
          <b>Se concluye en una visita</b><br>
          Todavía no hay informes cruzados suficientes para calcularlo. No se
          estima: sin el dato, el número diría más de lo que se sabe.
        </div>
      <?php else: ?>
        <div class="n" data-n="<?= $pctConcluye ?>" style="color:<?= $pctConcluye >= 85 ? 'var(--ok)' : ($pctConcluye >= 70 ? 'var(--warn)' : 'var(--danger)') ?>">0</div>
        <div class="t">
          <b>% que se concluye en una sola visita</b><br>
          De <?= number_format($conInforme, 0, ',', '.') ?> casos con informe,
          <?= number_format($concluyeUna, 0, ',', '.') ?> cerraron sin dejar equipo trabado.
          Es la premisa del servicio: cuando este número baja, suben los viajes,
          las horas y los equipos parados.
        </div>
      <?php endif; ?>
    </div>

    <figure class="viz" data-viz="anillo"
            data-titulo="El plazo de 48 horas"
            data-sub="De los equipos que quedaron deshabilitados, cuándo se decidió la vía. El plazo mide la decisión, no la reparación completa."
            data-centro="equipos"
            data-datos='<?= $j($d48) ?>'></figure>

    <figure class="viz" data-viz="barras"
            data-titulo="Antigüedad de lo que sigue abierto"
            data-sub="Cien casos de hoy es operación normal; diez de hace un mes es un problema. A los 7 días sin informe, el caso se cierra por falta de atención."
            data-ancho-etiqueta="132"
            data-datos='<?= $j($dEdad) ?>'></figure>
  </div>

  <?php /* ===================================================================== */ ?>
  <h2>El volumen y cómo se reparte</h2>
  <div class="viz-grid">
    <figure class="viz" data-viz="anillo"
            data-titulo="Casos por zona"
            data-sub="Los <?= count($casos) ?> vivos en la ventana de 90 días"
            data-centro="casos"
            data-datos='<?= $j($dZona) ?>'></figure>

    <figure class="viz" data-viz="barras"
            data-titulo="En qué estado están"
            data-sub="El estado que decidió una persona o dedujo la reconciliación. NO es el estado de SAP: el correo avisa cuando KFC crea un caso, nunca cuando lo cierra."
            data-ancho-etiqueta="140"
            data-datos='<?= $j($dEstado) ?>'></figure>

    <?php if (count($dMes) > 1): ?>
      <figure class="viz" data-viz="columnas"
              data-titulo="Casos por mes"
              data-sub="Por fecha de creación en SAP. Los meses de los extremos de la ventana están incompletos y por eso se ven bajos."
              data-datos='<?= $j($dMes) ?>'></figure>
    <?php endif; ?>

    <?php if (count($dCadena) > 1): ?>
      <?php /* La cadena es dimensión de primera clase, no un detalle: el mismo
               sistema tiene que servir cuando INDUSTEC atienda a más clientes,
               y ese día este gráfico es el que dice cuánto pesa cada uno. */ ?>
      <figure class="viz" data-viz="anillo"
              data-titulo="Por cadena"
              data-sub="La cadena sale del maestro de locales, nunca del prefijo del código."
              data-centro="casos"
              data-datos='<?= $j($dCadena) ?>'></figure>
    <?php endif; ?>
  </div>

  <?php /* ===================================================================== */ ?>
  <h2>Dónde se concentra el trabajo</h2>
  <div class="viz-grid">
    <figure class="viz" data-viz="barras"
            data-titulo="Locales con más casos"
            data-sub="Los doce primeros. Un local muy arriba de la lista casi nunca es mala suerte: suele ser causa de otra área."
            data-ancho-etiqueta="88"
            data-datos='<?= $j($dLocal) ?>'></figure>

    <figure class="viz" data-viz="barras"
            data-titulo="Qué se pide"
            data-sub="Los diez tipos de caso más frecuentes, tal como los nombra SAP."
            data-ancho-etiqueta="200"
            data-datos='<?= $j($dTipo) ?>'></figure>

    <?php if ($dTecnico): ?>
      <figure class="viz" data-viz="barras"
              data-titulo="Casos atendidos por técnico"
              data-sub="Sale de la firma del informe de cada orden. Es carga de trabajo, no una medida de desempeño: un caso complejo cuenta igual que uno simple."
              data-ancho-etiqueta="150"
              data-datos='<?= $j($dTecnico) ?>'></figure>
    <?php endif; ?>
  </div>

  <?php if ($reincidentes): ?>
    <h2>Locales que repiten</h2>
    <?= Ui::aviso('warn',
        '<b>' . count($reincidentes) . ' locales tienen 5 o más casos en 90 días.</b>'
      . '<p>Cuando un local repite así, la causa casi nunca es la reparación: es la '
      . 'instalación eléctrica, la ventilación o el desagüe. Vale la pena cruzarlos '
      . 'con las <a href="novedades_visita.php?g=ajenas">novedades de otras áreas</a> antes de '
      . 'la próxima reunión con Grupo KFC.</p>') ?>
  <?php endif; ?>

  <?php if ($novPorArea): ?>
    <h2>Qué hace fallar los equipos</h2>
    <div class="viz-grid">
      <figure class="viz" data-viz="barras"
              data-titulo="Novedades por área"
              data-sub="Lo que los técnicos ven en la visita y no era su orden. Lo que no dice «Equipo» es de otra área del local: son las causas de que un mismo equipo rompa varias veces al año."
              data-ancho-etiqueta="190"
              data-datos='<?= $j($novPorArea) ?>'></figure>
      <div class="hero">
        <div class="n" data-n="<?= $novC['con_aviso'] ?>">0</div>
        <div class="t">
          <b>novedades que llegaron a tener aviso en SAP</b><br>
          De <?= $novC['pendientes'] ?> todavía sin decidir, <?= $novC['alto'] ?> son de
          riesgo alto. Cada aviso conseguido es una avería que se evitó, y la prueba
          documentada de que se avisó a tiempo.
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?= Ui::aviso('neutro',
      '<b>Cómo leer estas cifras.</b>'
    . '<p>El correo de SAP avisa cuando Grupo KFC <b>crea</b> o <b>elimina</b> un caso, '
    . 'y <b>nunca cuando lo cierra</b>. Así que «abierto» aquí significa «INDUSTEC no lo '
    . 'ha cerrado», no «KFC lo tiene abierto». Para el estado real hace falta el export '
    . 'de SAP, y ese cruce se hace en la estación. Lo que sí es exacto en esta pantalla '
    . 'es todo lo que decidió una persona: asignaciones, veredictos, plazos y novedades.</p>') ?>
</div>

<?php Ui::pie(['js' => ['graficos.js']]); ?>
