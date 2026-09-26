<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Reportes.php';

/**
 * reportes.php — El tablero de la administración y la gerencia.
 *
 * ============================================================================
 * QUÉ INDICADORES, Y POR QUÉ ESTOS
 *
 * Hay dos familias, y conviene no mezclarlas:
 *
 * LO QUE PIDE GRUPO KFC — volumen, reparto por zona, antigüedad del total de
 * órdenes abiertas, cumplimiento de plazos y del cronograma preventivo. Es lo
 * que se responde en una reunión de servicio, y desde T2.14.5 se descarga en
 * Excel, PDF y PowerPoint con los mismos números de esta pantalla
 * (`Reportes::calcular`) y, desde el 24-sep-2026, con las mismas palabras
 * (vocabulario único): lo que Isabel lee aquí es lo que KFC lee en el archivo.
 *
 * LO QUE MIDE SI EL SERVICIO ESTÁ SANO — y que nadie pide, pero es lo que
 * anticipa los problemas: se concluye en una visita, antigüedad del total de
 * órdenes abiertas, reincidencia por local, validación dentro de las 48 horas,
 * y el rendimiento por técnico que pidieron los jefes de zona.
 *
 * ============================================================================
 * DOS CORTES, LOS DOS EN EL SERVIDOR
 *   zona (`?zona=`): la administración elige las tres o una; el jefe de zona
 *   recibe la suya aunque pida otra. mes (`?mes=AAAA-MM`): sobre la fecha de
 *   creación de la orden; sin mes, todo el periodo que trae el buzón.
 *
 * ============================================================================
 * LOS GRÁFICOS
 * Anillo para repartir un total en pocas partes; barras para comparar
 * magnitudes con nombre; columnas para el tiempo. Cada gráfico lleva su tabla
 * debajo, plegada: «¿y cuántos exactamente?» no se responde con el dedo.
 *
 * Lo que NO se puede calcular con lo que hay se dice en la propia pantalla en
 * vez de aproximarse (I-7).
 */

$u = Auth::exigir('reportes.ver');

$e = fn(?string $s): string => Ui::e($s);
$j = fn(array $d): string => Ui::e(json_encode($d, JSON_UNESCAPED_UNICODE));

$zonaGet = strtoupper((string) ($_GET['zona'] ?? ''));
$mesGet  = (string) ($_GET['mes'] ?? '');
$r = Reportes::calcular($zonaGet !== '' ? $zonaGet : null, $mesGet !== '' ? $mesGet : null);
$zona = $r['meta']['zona'];
$mes  = $r['meta']['mes'];
$fijo = $r['meta']['alcance_fijo'];

Auth::bitacora('CONSULTAR', 'reportes', 'tablero', 'zona=' . ($zona ?? 'todas') . ' mes=' . ($mes ?? 'todo') . ' casos=' . $r['meta']['casos'],
               null, null, ['zona' => $zona, 'mes' => $mes]);

// Los últimos doce meses para el selector, del más reciente al más antiguo.
$meses = [];
for ($k = 0; $k < 12; $k++) { $meses[] = date('Y-m', strtotime("first day of -$k month")); }
$enlace = static fn(array $c = []) => 'reportes.php?' . http_build_query(array_filter(
    array_merge(['zona' => $zona ?? '', 'mes' => $mes ?? ''], $c), static fn($v) => $v !== '' && $v !== null));
$exportar = static fn(string $f) => 'reporte_exportar.php?' . http_build_query(array_filter(
    ['formato' => $f, 'zona' => $zona ?? '', 'mes' => $mes ?? ''], static fn($v) => $v !== ''));

$s = $r['salud']; $c48 = $r['c48']; $nov = $r['novedades']; $pre = $r['preventivo']; $arch = $r['archivo'];
$rend = $r['rendimiento'];
$dRend = [];
foreach (array_slice($rend, 0, 12) as $t) { $dRend[] = ['e' => $t['nombre'], 'v' => $t['asignados']]; }
$dPreZona = [];
foreach ($pre['por_zona'] as $z => $pz) {
    $dPreZona[] = ['e' => Reportes::rotuloZona((string) $z), 'v' => (int) $pz['cumplidos'], 'c' => Reportes::COLOR_ZONA[$z] ?? '#94a3b8'];
}
$mesTexto = Reportes::nombreMes($mes);
// Los nombres que se repiten en la pantalla, del diccionario (vocabulario.json).
$V = static fn(string $clave, int $n = 1): string => Vocabulario::t($clave, $n);
$Vt = static fn(string $clave): string => Vocabulario::titulo($clave);
$ordenes = static fn(int $n): string => number_format($n, 0, ',', '.') . ' ' . Vocabulario::t('ORDEN', $n);

Ui::cabecera($u, 'reportes.php', [], ['titulo' => 'Reportes']);
?>

<div class="wrap ancho" data-casos="<?= (int) $r['meta']['casos'] ?>">
  <div class="titulo entra">
    <h1>Tablero de servicio</h1>
    <p class="sub">
      <?= $zona === null ? 'Las tres zonas. ' : '<b>' . $e(Ui::nombreZona($zona)) . '</b>. ' ?>
      <?= $mes === null ? 'Todo el periodo que trae el buzón de SAP (<b>90 días</b>)' : 'Órdenes creadas en <b>' . $e($mesTexto) . '</b>' ?><?= $r['meta']['generado'] !== '' ? ', barrido por última vez el <b>' . $e(substr($r['meta']['generado'], 0, 10)) . '</b>' : '' ?>,
      cruzado con las OT INDUSTEC y con lo decidido en el sistema.
      <?php if ($arch !== null && $arch['total'] > 0): ?>
        El archivo general tiene <b><?= number_format($arch['total'], 0, ',', '.') ?></b> OT INDUSTEC indexadas<?= $zona !== null ? ' de la ' . $e($V(Vocabulario::deEstado($zona, 'zona'))) : '' ?>.
      <?php else: ?>
        El histórico completo de OT INDUSTEC vive en la estación hasta que se indexe el archivo.
      <?php endif; ?>
    </p>
  </div>

  <?php if (!$r['meta']['hay_fuente']): ?>
    <?= Ui::aviso('warn',
        '<b>No hay datos del buzón.</b><p>Sin <span class="mono">casos_sap.json</span> '
      . 'no hay nada que graficar, y esta pantalla no va a inventar una tendencia.</p>') ?>
    <?php Ui::pie(); exit; ?>
  <?php endif; ?>

  <div class="filtros-rapidos reporte-filtros">
    <?php if (!$fijo): ?>
      <a class="fr <?= $zona === null ? 'on' : '' ?>" href="<?= $e($enlace(['zona' => ''])) ?>">Las tres</a>
      <?php foreach (Reportes::ZONAS as $z): ?>
        <a class="fr <?= $zona === $z ? 'on' : '' ?>" href="<?= $e($enlace(['zona' => $z])) ?>"><?= $e(Reportes::rotuloZona($z)) ?></a>
      <?php endforeach; ?>
    <?php endif; ?>
    <form method="get" data-auto style="display:flex;gap:6px;align-items:center">
      <?php if ($zona !== null): ?><input type="hidden" name="zona" value="<?= $e($zona) ?>"><?php endif; ?>
      <label for="f-mes" class="sub" style="margin:0">Periodo</label>
      <select id="f-mes" name="mes" style="height:36px;width:auto;font-size:13.5px">
        <option value="">Todo el buzón (90 días)</option>
        <?php foreach ($meses as $m): ?>
          <option value="<?= $e($m) ?>" <?= $mes === $m ? 'selected' : '' ?>><?= $e(ucfirst(Reportes::nombreMes($m))) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <div class="exportar" style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
      <a class="btn sm" href="<?= $e($exportar('xlsx')) ?>">Descargar Excel</a>
      <a class="btn sm" href="<?= $e($exportar('pdf')) ?>">Descargar PDF</a>
      <a class="btn sm" href="<?= $e($exportar('pptx')) ?>">Descargar PowerPoint</a>
    </div>
  </div>

  <?php /* =====================================================================
     PRIMERO EL INDICADOR DEL NEGOCIO, no el volumen.
     ===================================================================== */ ?>
  <h2 style="margin-top:4px">La salud del servicio</h2>
  <div class="viz-grid">
    <div class="hero">
      <?php if ($s['pct_concluye'] === null): ?>
        <div class="n" style="color:var(--muted)">—</div>
        <div class="t">
          <b>Se concluye en una visita</b><br>
          Ninguna orden del corte tiene todavía su OT INDUSTEC de cierre, así que no hay
          con qué calcularlo. No se estima: sin el dato, el número diría más de lo que se sabe.
          <?php if ($s['en_curso'] > 0): ?>
            <?= $e($ordenes((int) $s['en_curso'])) ?> tienen OT INDUSTEC de evaluación y les falta la de cierre.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="n" data-n="<?= $s['pct_concluye'] ?>" style="color:<?= $s['pct_concluye'] >= 85 ? 'var(--ok)' : ($s['pct_concluye'] >= 70 ? 'var(--warn)' : 'var(--danger)') ?>">0</div>
        <div class="t">
          <b>% que se concluye en una sola visita</b><br>
          De <?= $e($ordenes((int) $s['concluidos'])) ?> con OT INDUSTEC de cierre,
          <?= number_format($s['concluye_una'], 0, ',', '.') ?> se concluyeron el mismo día de la
          primera visita y sin dejar un <?= $e($V('EQUIPO_DESHABILITADO')) ?>.
          <?php if ($s['en_curso'] > 0): ?>
            Otras <b><?= number_format($s['en_curso'], 0, ',', '.') ?></b> tienen OT INDUSTEC de evaluación
            y les falta la de cierre: todavía no cuentan.
          <?php endif; ?>
          Cuando este número baja, suben los viajes, las horas y los <?= $e($V('EQUIPO_DESHABILITADO', 2)) ?>.
        </div>
      <?php endif; ?>
    </div>

    <figure class="viz" data-viz="anillo"
            data-titulo="El plazo de 48 horas"
            data-sub="De las solicitudes con el equipo deshabilitado, cuándo las validó el jefe de zona. El plazo mide la validación, no la reparación."
            data-centro="<?= $e($V('SOLICITUD', 2)) ?>"
            data-datos='<?= $j($r['d48']) ?>'></figure>

    <figure class="viz" data-viz="barras"
            data-titulo="Antigüedad del <?= $e($V('TOTAL_ABIERTAS')) ?>"
            data-sub="Cien órdenes de hoy es operación normal; diez de hace un mes es un problema. A los 7 días sin ninguna OT INDUSTEC, la orden queda <?= $e($V('CERRADA_SIN_ATENCION')) ?>."
            data-ancho-etiqueta="132"
            data-datos='<?= $j($r['edad']) ?>'></figure>
  </div>

  <?php /* ===================================================================== */ ?>
  <h2>El volumen y cómo se reparte</h2>
  <div class="viz-grid">
    <figure class="viz" data-viz="anillo"
            data-titulo="<?= $e($Vt('ORDEN')) ?> por zona"
            data-sub="Las <?= (int) $r['meta']['casos'] ?> del periodo"
            data-centro="<?= $e($V('ORDEN', 2)) ?>"
            data-datos='<?= $j($r['zonas']) ?>'></figure>

    <figure class="viz" data-viz="barras"
            data-titulo="En qué estado están"
            data-sub="El estado que decidió una persona, dedujo la reconciliación o dejó la OT INDUSTEC emitida desde la app. NO es el estatus SAP."
            data-ancho-etiqueta="140"
            data-datos='<?= $j($r['estados']) ?>'></figure>

    <?php if (count($r['meses']) > 1): ?>
      <figure class="viz" data-viz="columnas"
              data-titulo="<?= $e($Vt('ORDEN')) ?> por mes"
              data-sub="Por fecha de creación en SAP. Los meses de los extremos de la ventana están incompletos y por eso se ven bajos."
              data-datos='<?= $j($r['meses']) ?>'></figure>
    <?php endif; ?>

    <?php if (count($r['cadenas']) > 1): ?>
      <figure class="viz" data-viz="anillo"
              data-titulo="Por cadena"
              data-sub="La cadena sale del maestro de locales, nunca del prefijo del código."
              data-centro="<?= $e($V('ORDEN', 2)) ?>"
              data-datos='<?= $j($r['cadenas']) ?>'></figure>
    <?php endif; ?>
  </div>

  <?php /* =====================================================================
     RENDIMIENTO POR TÉCNICO (TR-03): lo que pidieron los jefes de zona.
     Por quién tiene asignada la orden, no por la firma de la OT INDUSTEC.
     ===================================================================== */ ?>
  <h2>Rendimiento por técnico</h2>
  <?php if (!$rend): ?>
    <?= Ui::aviso('neutro', '<b>Sin técnicos con actividad en este corte.</b>') ?>
  <?php else: ?>
    <div class="viz-grid">
      <figure class="viz" data-viz="barras"
              data-titulo="<?= $e($Vt('ORDEN') . ' ' . $V('ASIGNADA', 2)) ?>"
              data-sub="Los doce con más órdenes a su nombre en el periodo. Es carga de trabajo; el rendimiento está en la tabla."
              data-ancho-etiqueta="150" data-sin-porcentaje
              data-datos='<?= $j($dRend) ?>'></figure>
      <div class="hero">
        <?php
        // Con una o dos órdenes con cierre, «100 %» no distingue a nadie: el que
        // cerró una sola ganaba siempre. Se exige un mínimo para comparar.
        $MIN_CERRADOS = 5;
        $conDato = array_filter($rend, fn($t) => $t['pct_una_visita'] !== null && $t['cerrados'] >= $MIN_CERRADOS);
        ?>
        <?php if ($conDato): ?>
          <?php $mejor = array_reduce($conDato, fn($a, $t) => $a === null || [$t['pct_una_visita'], $t['cerrados']] > [$a['pct_una_visita'], $a['cerrados']] ? $t : $a); ?>
          <div class="n" data-n="<?= (int) $mejor['pct_una_visita'] ?>" style="color:var(--ok)">0</div>
          <div class="t"><b>% en una visita del mejor técnico del periodo</b><br>
            <?= $e($mejor['nombre']) ?>, con <?= $e($ordenes((int) $mejor['cerrados'])) ?> con OT INDUSTEC de cierre. Cada punto por encima
            del promedio son viajes que no se hicieron.</div>
        <?php else: ?>
          <div class="n" style="color:var(--muted)">—</div>
          <div class="t"><b>% en una visita por técnico</b><br>Ningún técnico tiene todavía
            <?= $MIN_CERRADOS ?> órdenes con OT INDUSTEC de cierre en este corte, y con menos el porcentaje no compara a nadie.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php
      // Encabezados de la tabla, con los términos del diccionario (el mismo
      // texto va en data-th para la vista de tarjetas del celular).
      $thAsig = $Vt('ASIGNADA');
      $thConOt = 'Con OT INDUSTEC';
      $thEnManos = $Vt('ASIGNADA') . ' o ' . $V('ESPERA_REPUESTO');
      $thVenc = 'Solicitudes ' . mb_strtolower($Vt('VENCIDO_48H'), 'UTF-8');
      $thApp = 'OT INDUSTEC por la app';
    ?>
    <div class="tabla-wrap">
      <table class="tarjetas rendimiento">
        <thead><tr>
          <th>Técnico</th><th>Zona</th><th class="n"><?= $e($thAsig) ?></th><th class="n"><?= $e($thConOt) ?></th>
          <th class="n">En una visita</th><th class="n">Días a la 1.ª atención</th><th class="n"><?= $e($thEnManos) ?></th>
          <th class="n"><?= $e($thVenc) ?></th><th class="n">Novedades</th><th class="n"><?= $e($thApp) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rend as $t): ?>
          <tr class="<?= $t['activo'] ? '' : 'baja' ?>">
            <td data-th="Técnico"><b><?= $e($t['nombre']) ?></b><?= $t['activo'] ? '' : ' <span class="chip">de baja</span>' ?></td>
            <td data-th="Zona"><?= Ui::zona($t['zona']) ?></td>
            <td data-th="<?= $e($thAsig) ?>" class="n"><?= (int) $t['asignados'] ?></td>
            <td data-th="<?= $e($thConOt) ?>" class="n"><?= (int) $t['con_informe'] ?></td>
            <td data-th="En una visita" class="n"><?= $t['pct_una_visita'] === null ? '—' : (int) $t['una_visita'] . ' de ' . (int) $t['cerrados'] . ' <span class="desc">(' . (int) $t['pct_una_visita'] . '%)</span>' ?></td>
            <td data-th="Días a la 1.ª atención" class="n"><?= $t['dias_primera'] === null ? '—' : $e(number_format((float) $t['dias_primera'], 1, ',', '.')) ?></td>
            <td data-th="<?= $e($thEnManos) ?>" class="n"><?= (int) $t['abiertos_ahora'] ?></td>
            <td data-th="<?= $e($thVenc) ?>" class="n <?= $t['pendientes_vencidos'] ? 'mal' : '' ?>"><?= (int) $t['pendientes_vencidos'] ?></td>
            <td data-th="Novedades" class="n"><?= (int) $t['novedades'] ?></td>
            <td data-th="<?= $e($thApp) ?>" class="n"><?= (int) $t['ordenes_app'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="sub" style="margin:6px 0 0">
      «En una visita»: de sus órdenes con OT INDUSTEC de cierre, cuántas se concluyeron el mismo día de la primera visita y sin dejar
      un equipo deshabilitado; las que solo tienen OT INDUSTEC de evaluación no cuentan todavía. «Días a la 1.ª atención»:
      promedio entre la creación de la orden en SAP y la primera OT INDUSTEC. «<?= $e($thVenc) ?>»: solicitudes suyas
      con el equipo deshabilitado que llevan más de 48 h sin que el jefe de zona las valide. Una orden compleja cuenta igual que una simple: la tabla se lee junto con el trabajo.
    </p>
  <?php endif; ?>

  <?php /* =====================================================================
     CUMPLIMIENTO DEL PREVENTIVO (TR-04): contra el plan acordado con KFC.
     ===================================================================== */ ?>
  <h2>Cumplimiento del preventivo</h2>
  <?php if ($pre['fuente'] === null): ?>
    <?= Ui::aviso('neutro', '<b>Sin cronograma cargado.</b><p>Cuando la estación publique '
        . '<span class="mono">cronograma_preventivo.json</span> o se importe a la base, aquí aparece el cumplimiento.</p>') ?>
  <?php else: ?>
    <?php
      // La leyenda del jefe técnico (EJECUTADO · PENDIENTE · ATRASADO), del diccionario.
      $ejecutados = Reportes::mayuscula($V('PREV_EJECUTADO', 2));
      $atrasados  = Reportes::mayuscula($V('ATRASADO', 2));
    ?>
    <div class="viz-grid">
      <div class="hero">
        <?php if ($pre['pct_a_tiempo'] === null): ?>
          <div class="n" style="color:var(--muted)">—</div>
          <div class="t"><b>% de ingresos <?= $e($V('PREV_EJECUTADO', 2)) ?> a tiempo</b><br>Todavía no hay ingresos <?= $e($V('PREV_EJECUTADO', 2)) ?> ni <?= $e($V('ATRASADO', 2)) ?> en este corte.</div>
        <?php else: ?>
          <div class="n" data-n="<?= (int) $pre['pct_a_tiempo'] ?>" style="color:<?= $pre['pct_a_tiempo'] >= 85 ? 'var(--ok)' : ($pre['pct_a_tiempo'] >= 70 ? 'var(--warn)' : 'var(--danger)') ?>">0</div>
          <div class="t"><b>% de ingresos <?= $e($V('PREV_EJECUTADO', 2)) ?> a tiempo</b><br>
            Contra lo acordado con Grupo KFC (el plan original, que no se pisa al reagendar):
            <?= (int) $pre['cumplidos_a_tiempo'] ?> <?= $e($V('PREV_EJECUTADO', (int) $pre['cumplidos_a_tiempo'])) ?> a tiempo,
            <?= (int) $pre['cumplidos_tarde'] ?> tarde y <?= (int) $pre['vencidos'] ?> <?= $e($V('ATRASADO', (int) $pre['vencidos'])) ?>.
            <?php if ($pre['sin_cierre'] > 0): ?>
              Además, <?= (int) $pre['sin_cierre'] ?> <?= $e($V('PREV_SIN_CIERRE', (int) $pre['sin_cierre'])) ?>.
            <?php endif; ?>
            <?= (int) $pre['reagendados'] ?> <?= $e($V('PREV_REAGENDADO', (int) $pre['reagendados'])) ?>; kit confirmado en <?= (int) $pre['kits_confirmados'] ?> de <?= (int) $pre['total'] ?>.
            <?= $pre['fuente'] === 'json' ? 'Leído del archivo de la estación (todavía no importado a la base).' : '' ?>
          </div>
        <?php endif; ?>
      </div>
      <figure class="viz" data-viz="anillo"
              data-titulo="Los ingresos del periodo"
              data-sub="<?= $e($ejecutados) ?> a tiempo o tarde, <?= $e($V('ATRASADO', 2)) ?>, <?= $e($V('PREV_SIN_CIERRE', 2)) ?>, <?= $e($V('PREV_EN_EJECUCION', 2)) ?> y <?= $e($V('PREV_SIN_AGENDAR', 2)) ?>. Los pendientes con fecha futura no entran en el anillo."
              data-centro="ingresos"
              data-datos='<?= $j(array_values(array_filter($pre['estados'], fn($x) => $x['v'] > 0))) ?>'></figure>
      <?php if ($dPreZona): ?>
        <figure class="viz" data-viz="barras"
                data-titulo="<?= $e($ejecutados) ?> por zona"
                data-sub="Cuántos ingresos marcó como ejecutados cada zona en el periodo."
                data-ancho-etiqueta="96" data-sin-porcentaje
                data-datos='<?= $j($dPreZona) ?>'></figure>
      <?php endif; ?>
      <?php if ($pre['motivos']): ?>
        <figure class="viz" data-viz="barras"
                data-titulo="Por qué se reagendó"
                data-sub="Los motivos que se registraron al mover una fecha. Es lo que se le explica a Grupo KFC."
                data-ancho-etiqueta="200"
                data-datos='<?= $j($pre['motivos']) ?>'></figure>
      <?php endif; ?>
    </div>
    <?php if ($pre['por_zona']): ?>
      <div class="tabla-wrap">
        <table class="tarjetas">
          <?php
            $thSinCierre = $Vt('PREV_SIN_CIERRE');
            $thEnEjec = $Vt('PREV_EN_EJECUCION');
            $thSinAg = $Vt('PREV_SIN_AGENDAR');
          ?>
          <thead><tr><th>Zona</th><th class="n">Ingresos</th><th class="n"><?= $e($ejecutados) ?></th><th class="n">A tiempo</th><th class="n"><?= $e($atrasados) ?></th><th class="n"><?= $e($thSinCierre) ?></th><th class="n"><?= $e($thEnEjec) ?></th><th class="n"><?= $e($thSinAg) ?></th></tr></thead>
          <tbody>
          <?php foreach ($pre['por_zona'] as $z => $pz): ?>
            <tr>
              <td data-th="Zona"><?= Ui::zona($z === 'Sin zona' ? null : $z) ?></td>
              <td data-th="Ingresos" class="n"><?= (int) $pz['total'] ?></td>
              <td data-th="<?= $e($ejecutados) ?>" class="n"><?= (int) $pz['cumplidos'] ?></td>
              <td data-th="A tiempo" class="n"><?= (int) $pz['a_tiempo'] ?><?= $pz['cumplidos'] + $pz['vencidos'] > 0 ? ' <span class="desc">(' . (int) round($pz['a_tiempo'] * 100 / ($pz['cumplidos'] + $pz['vencidos'])) . '%)</span>' : '' ?></td>
              <td data-th="<?= $e($atrasados) ?>" class="n <?= $pz['vencidos'] ? 'mal' : '' ?>"><?= (int) $pz['vencidos'] ?></td>
              <td data-th="<?= $e($thSinCierre) ?>" class="n <?= $pz['sin_cierre'] ? 'mal' : '' ?>"><?= (int) $pz['sin_cierre'] ?></td>
              <td data-th="<?= $e($thEnEjec) ?>" class="n"><?= (int) $pz['en_curso'] ?></td>
              <td data-th="<?= $e($thSinAg) ?>" class="n"><?= (int) $pz['sin_agendar'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php /* ===================================================================== */ ?>
  <h2>Dónde se concentra el trabajo</h2>
  <div class="viz-grid">
    <figure class="viz" data-viz="barras"
            data-titulo="Locales con más órdenes"
            data-sub="Los doce primeros. Un local muy arriba de la lista casi nunca es mala suerte: suele ser causa de otra área."
            data-ancho-etiqueta="88"
            data-datos='<?= $j($r['locales']) ?>'></figure>

    <figure class="viz" data-viz="barras"
            data-titulo="Qué se pide"
            data-sub="Los diez tipos de orden más frecuentes, tal como los nombra SAP."
            data-ancho-etiqueta="200"
            data-datos='<?= $j($r['tipos']) ?>'></figure>

    <?php if ($r['tecnicos_firma']): ?>
      <figure class="viz" data-viz="barras"
              data-titulo="Órdenes con OT INDUSTEC, por técnico (firma)"
              data-sub="Sale de la firma de cada OT INDUSTEC: quién fue, aunque la orden estuviera asignada a otro."
              data-ancho-etiqueta="150"
              data-datos='<?= $j($r['tecnicos_firma']) ?>'></figure>
    <?php endif; ?>
  </div>

  <?php if ($r['reincidentes']): ?>
    <h2>Locales que repiten</h2>
    <?= Ui::aviso('warn',
        '<b>' . count($r['reincidentes']) . ' locales tienen 5 o más órdenes en el periodo.</b>'
      . '<p>Cuando un local repite así, la causa casi nunca es la reparación: es la '
      . 'instalación eléctrica, la ventilación o el desagüe. Vale la pena cruzarlos '
      . 'con las <a href="novedades_visita.php?g=ajenas">novedades de otras áreas</a> antes de '
      . 'la próxima reunión con Grupo KFC.</p>') ?>
  <?php endif; ?>

  <?php if ($nov['por_area']): ?>
    <h2>Qué hace fallar los equipos</h2>
    <div class="viz-grid">
      <figure class="viz" data-viz="barras"
              data-titulo="Novedades por área"
              data-sub="Lo que los técnicos ven en la visita y no era su orden. Lo que no dice «Equipo» es de otra área del local."
              data-ancho-etiqueta="190"
              data-datos='<?= $j($nov['por_area']) ?>'></figure>
      <div class="hero">
        <div class="n" data-n="<?= (int) $nov['con_aviso'] ?>">0</div>
        <div class="t">
          <b><?= $e($V('NOVEDAD', 2) . ' ' . $V('NOVEDAD_CON_AVISO', 2)) ?></b><br>
          De <?= (int) $nov['pendientes'] ?> todavía <?= $e($V('NOVEDAD_POR_DECIDIR')) ?>, <?= (int) $nov['alto'] ?> son de
          riesgo alto. Cada aviso SAP conseguido es una avería que se evitó, y la prueba
          documentada de que se avisó a tiempo.
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php /* =====================================================================
     OTROS TRABAJOS (011). Lo que INDUSTEC hizo para Grupo KFC fuera de su
     área, por un acuerdo, y que la administradora autorizó. Va aparte porque
     es el extra que INDUSTEC da como un plus al servicio y hay que poder
     mostrárselo a KFC (decisión de Andrés, 2026-09-14). Los «otros clientes»
     —fuera de KFC— no entran aquí: son otro reporte, interno.
     ===================================================================== */ ?>
  <?php $otr = $r['otros_trabajos']; $modOtros = (int) ($arch['modulo_otros'] ?? 0); ?>
  <h2>Otros trabajos para Grupo KFC</h2>
  <p class="sub" style="margin:0 0 10px">
    Trabajos fuera del área de INDUSTEC —constructivo, infraestructura, lo que no es
    soporte de equipos— hechos por acuerdo con KFC y autorizados por la administración:
    <b><?= count($otr) ?></b> en el periodo.
    <?php if ($r['otros_por_decidir'] > 0): ?>
      <a href="casos.php?otro=por_decidir"><?= $e($ordenes((int) $r['otros_por_decidir'])) ?>
      <?= $e($V('OTRO_TRABAJO_POR_DECIDIR')) ?></a>.
    <?php endif; ?>
    <?php if ($modOtros > 0): ?>
      Además, el Archivo tiene <b><?= $modOtros ?></b> OT INDUSTEC del módulo «Otros»: extras
      dentro del mismo trato con KFC.
    <?php endif; ?>
  </p>
  <?php if ($otr): ?>
    <div class="tabla-wrap">
      <table>
        <thead><tr>
          <th><?= $e($Vt('AVISO_SAP')) ?></th><th>Local</th><th>Zona</th><th>Trabajo</th><th><?= $e($Vt('OT_CIERRE')) ?></th>
          <th>Estado</th><th>Acuerdo con KFC</th><th>Autorizó</th>
        </tr></thead>
        <tbody>
        <?php foreach ($otr as $o): ?>
          <tr>
            <td class="mono"><?= $e($o['aviso']) ?><span class="desc"><?= $e($o['fecha']) ?></span></td>
            <td><b><?= $e($o['local']) ?></b><span class="desc"><?= $e($o['local_nombre']) ?></span></td>
            <td><?= Ui::zona($o['zona'] !== '' ? $o['zona'] : null) ?></td>
            <td><?= $e($o['trabajo']) ?></td>
            <td class="mono"><?= $e($o['ot'] !== '' ? $o['ot'] : '—') ?></td>
            <td><?= $e($o['estatus']) ?></td>
            <td><?= $e($o['acuerdo']) ?></td>
            <td><?= $e($o['autorizo']) ?><span class="desc"><?= $e($o['autorizado_en']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?= Ui::aviso('neutro',
      '<b>Cómo leer estas cifras.</b>'
    . '<p>El correo de SAP avisa cuando Grupo KFC <b>crea</b> o <b>elimina</b> una orden, '
    . 'y <b>nunca cuando la cierra</b>. Así que el <b>' . $e($V('TOTAL_ABIERTAS')) . '</b> de aquí '
    . 'cuenta las que INDUSTEC aún no termina, no las que KFC tiene abiertas en SAP. '
    . $e(Vocabulario::ayuda('TOTAL_ABIERTAS')) . ' Lo que sí es exacto es todo lo que decidió '
    . 'una persona o emitió la app: asignaciones, validaciones, registros en SAP, plazos, '
    . 'novedades y OT INDUSTEC. Los tres archivos de descarga llevan estos mismos números y los mismos nombres.</p>') ?>
</div>

<?php Ui::pie(['js' => ['graficos.js']]); ?>
