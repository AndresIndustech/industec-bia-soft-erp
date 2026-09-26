<?php
declare(strict_types=1);

/**
 * reporte_pdf.php — La plantilla del reporte en PDF (dompdf), con la marca INDUSTEC.
 *
 * Recibe el arreglo de `Reportes::calcular()` y quién lo generó, y devuelve el
 * HTML que dompdf convierte. Los gráficos son SVG en línea generados en PHP
 * (`Reportes::svgBarras` / `svgAnillo`): la misma forma que la pantalla, sin
 * depender de un navegador. Nada remoto: el logo va embebido.
 *
 * LAS PALABRAS SON LAS DE LA OFICINA (vocabulario único, 24-sep-2026): KFC lee
 * aquí «total de órdenes abiertas», «vencidas (48 h)» o «ZONA CUENCA-LOJA»,
 * igual que Isabel en pantalla. Los nombres salen de `Vocabulario::`; solo la
 * prosa que los rodea se escribe aquí.
 */
function reportePdfHtml(array $r, array $u): string
{
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $n = fn($v) => number_format((float) $v, 0, ',', '.');
    $V = static fn(string $clave, int $cuantos = 1): string => Vocabulario::t($clave, $cuantos);
    $Vt = static fn(string $clave): string => Vocabulario::titulo($clave);
    $Z = static fn($z): string => Reportes::rotuloZona((string) $z);
    $logo = '';
    $rutaLogo = __DIR__ . '/logo-industec.png';
    if (is_file($rutaLogo)) { $logo = 'data:image/png;base64,' . base64_encode((string) file_get_contents($rutaLogo)); }
    $zona = $r['meta']['zona'] ?? null;
    $mes = $r['meta']['mes'] ?? null;
    $titulo = 'Reporte de servicio · ' . ($zona ? Ui::nombreZona($zona) : 'Las tres zonas') . ' · ' . ucfirst(Reportes::nombreMes($mes));
    $s = $r['salud']; $c48 = $r['c48']; $pre = $r['preventivo']; $nov = $r['novedades'];
    $SEM = ['amarillo' => '#fde68a', 'naranja' => '#fdba74', 'verde' => '#bbf7d0', 'rojo' => '#fecaca'];
    $SEM_T = Reportes::semaforo();
    // Una cifra que no se pudo calcular se dice, no se pinta como 0 (I-7).
    $nd = static fn($v) => $v === null ? 'no disponible' : number_format((float) $v, 0, ',', '.');
    $ejecutados = Reportes::mayuscula($V('PREV_EJECUTADO', 2));
    $atrasados  = Reportes::mayuscula($V('ATRASADO', 2));

    $tabla = static function (array $filas, array $cab) use ($e): string {
        if (!$filas) { return '<p class="sub">Sin datos en este corte.</p>'; }
        $h = '<table><thead><tr>';
        foreach ($cab as $c) { $h .= '<th' . (str_starts_with($c, '#') ? ' class="n"' : '') . '>' . $e(ltrim($c, '#')) . '</th>'; }
        $h .= '</tr></thead><tbody>';
        foreach ($filas as $f) {
            $h .= '<tr>';
            foreach ($f as $k => $v) { $h .= '<td' . (is_int($v) || is_float($v) ? ' class="n"' : '') . '>' . $e(is_float($v) ? number_format($v, 1, ',', '.') : (string) $v) . '</td>'; }
            $h .= '</tr>';
        }
        return $h . '</tbody></table>';
    };
    $kpi = static fn(string $v, string $t, string $color = '#0f172a'): string =>
        '<td class="kpi"><div class="v" style="color:' . $color . '">' . $v . '</div><div class="t">' . $t . '</div></td>';

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<title><?= $e($titulo) ?></title>
<style>
  @page { margin:14mm 13mm 16mm; }
  body { font-family:DejaVu Sans, Arial, sans-serif; font-size:10.5px; color:#111; }
  .cab { border-bottom:2px solid #1F4E79; padding-bottom:6px; margin-bottom:10px; }
  .cab img { height:34px; }
  .cab .marca { font-size:16px; font-weight:700; color:#1F4E79; }
  .cab .sub { color:#555; font-size:9.5px; }
  h1 { font-size:17px; color:#1F4E79; margin:8px 0 4px; }
  h2 { font-size:13px; color:#1F4E79; margin:14px 0 6px; border-left:4px solid #1F4E79; padding-left:6px; page-break-after:avoid; }
  p.sub { color:#555; font-size:9.5px; margin:2px 0 6px; }
  table { border-collapse:collapse; width:100%; margin:4px 0 8px; page-break-inside:auto; }
  th, td { border:1px solid #d9dee5; padding:3px 5px; text-align:left; vertical-align:top; }
  th { background:#1F4E79; color:#fff; font-weight:700; font-size:9.5px; }
  td.n, th.n { text-align:right; }
  tr:nth-child(even) td { background:#f6f8fb; }
  .kpis { width:100%; border:0; margin:6px 0 10px; }
  .kpis td { border:1px solid #d9dee5; text-align:center; padding:6px 4px; background:#fff; width:25%; }
  .kpis .v { font-size:20px; font-weight:700; }
  .kpis .t { font-size:9px; color:#555; margin-top:2px; }
  .dos { width:100%; border:0; }
  .dos td { border:0; vertical-align:top; width:50%; padding:0 6px 0 0; }
  .sem { display:inline-block; width:10px; height:10px; border:1px solid #999; margin-right:4px; vertical-align:middle; }
  .pie { position:fixed; bottom:-8mm; left:0; right:0; font-size:8.5px; color:#777; text-align:center; }
  .salto { page-break-before:always; }
  svg { max-width:100%; }
</style></head><body>
<div class="pie">INDUSTEC · B.IA Soft ERP · <?= $e($titulo) ?> · generado por <?= $e($u['nombre']) ?> el <?= $e(date('Y-m-d H:i')) ?> · uso interno y para Grupo KFC</div>
<div class="cab">
  <table class="dos"><tr>
    <td><?php if ($logo): ?><img src="<?= $logo ?>" alt="INDUSTEC"><?php endif; ?><div class="marca">INDUSTEC · Servicio técnico</div></td>
    <td style="text-align:right"><div class="sub"><b><?= $e($titulo) ?></b><br>
      Generado por <?= $e($u['nombre']) ?> el <?= $e(date('d/m/Y H:i')) ?><br>
      Fuente: buzón de SAP<?= $r['meta']['generado'] !== '' ? ' (barrido ' . $e(substr($r['meta']['generado'], 0, 10)) . ')' : '' ?>, OT INDUSTEC y lo decidido en el sistema</div></td>
  </tr></table>
</div>

<h1>La salud del servicio</h1>
<table class="kpis"><tr>
  <?= $kpi($s['pct_concluye'] === null ? '—' : $s['pct_concluye'] . '%', 'se concluye en una visita', $s['pct_concluye'] === null ? '#777' : ($s['pct_concluye'] >= 85 ? '#166534' : ($s['pct_concluye'] >= 70 ? '#92400e' : '#991b1b'))) ?>
  <?= $kpi($n($r['meta']['casos']), $e($V('ORDEN', 2)) . ' del periodo') ?>
  <?= $kpi($n($s['abiertos']), $e($V('TOTAL_ABIERTAS'))) ?>
  <?= $kpi($n($c48['vencidos']), 'solicitudes ' . $e(mb_strtolower($Vt('VENCIDO_48H'), 'UTF-8')), $c48['vencidos'] > 0 ? '#991b1b' : '#166534') ?>
</tr></table>
<p class="sub"><?= $e($Vt('TOTAL_ABIERTAS')) ?>: <?= $n($s['abiertos']) ?> = <?= $e($Vt('ABIERTA')) ?> <?= $e($nd($s['abiertas'] ?? null)) ?> + <?= $e($Vt('ESPERA_INFORME')) ?> <?= $e($nd($s['espera_informe'] ?? null)) ?>; de ellas, <?= $e($Vt('EQUIPO_DESHABILITADO')) ?> <?= $e($nd($s['deshabilitados'] ?? null)) ?>. <?= $e(Vocabulario::ayuda('TOTAL_ABIERTAS')) ?> El correo de SAP no avisa cuando Grupo KFC cierra una orden.</p>
<p class="sub">De <?= $n($s['concluidos']) ?> <?= $e($V('ORDEN', (int) $s['concluidos'])) ?> con OT INDUSTEC de cierre, <?= $n($s['concluye_una']) ?> se concluyeron el mismo día de la primera visita y sin dejar un <?= $e($V('EQUIPO_DESHABILITADO')) ?><?= $s['en_curso'] > 0 ? '; otras ' . $n($s['en_curso']) . ' tienen OT INDUSTEC de evaluación, les falta la de cierre y todavía no cuentan' : '' ?>.</p>

<table class="dos"><tr>
  <td><h2>El plazo de 48 horas</h2><?= Reportes::svgAnillo($r['d48'], $V('SOLICITUD', 2), 'El plazo de 48 horas') ?>
      <?= $tabla(array_map(fn($x) => [$x['e'], (int) $x['v']], $r['d48']), ['Qué', '#Solicitudes']) ?></td>
  <td><h2>Antigüedad del <?= $e($V('TOTAL_ABIERTAS')) ?></h2><?= Reportes::svgBarras($r['edad'], 118, 'Antigüedad del ' . $V('TOTAL_ABIERTAS')) ?></td>
</tr></table>

<h2>El volumen y cómo se reparte</h2>
<table class="dos"><tr>
  <td><?= Reportes::svgAnillo($r['zonas'], $V('ORDEN', 2), $Vt('ORDEN') . ' por zona') ?>
      <?= $tabla(array_map(fn($x) => [$x['e'], (int) $x['v']], $r['zonas']), ['Zona', '#' . $Vt('ORDEN')]) ?></td>
  <td><?= Reportes::svgBarras($r['estados'], 130, $Vt('ORDEN') . ' por estado') ?></td>
</tr></table>
<?php if (count($r['meses']) > 1): ?>
  <p class="sub"><?= $e($Vt('ORDEN')) ?> por mes de creación en SAP: <?= $e(implode(' · ', array_map(fn($x) => $x['e'] . ': ' . $x['v'], $r['meses']))) ?>.</p>
<?php endif; ?>

<h2 class="salto">Rendimiento por técnico</h2>
<p class="sub">Por quién tiene asignada la orden. «En una visita»: de sus órdenes con OT INDUSTEC de cierre, cuántas se concluyeron el mismo día de la primera visita y sin dejar un equipo deshabilitado. «Días»: promedio entre la creación en SAP y la primera OT INDUSTEC.</p>
<?= $tabla(array_map(fn($t) => [
    $t['nombre'] . ($t['activo'] ? '' : ' (de baja)'), $Z($t['zona']), (int) $t['asignados'], (int) $t['con_informe'],
    $t['pct_una_visita'] === null ? '—' : $t['una_visita'] . ' de ' . $t['cerrados'] . ' (' . $t['pct_una_visita'] . '%)',
    $t['dias_primera'] === null ? '—' : number_format((float) $t['dias_primera'], 1, ',', '.'),
    (int) $t['abiertos_ahora'], (int) $t['pendientes_vencidos'], (int) $t['novedades'], (int) $t['ordenes_app'],
], $r['rendimiento']), ['Técnico', 'Zona', '#' . $Vt('ASIGNADA'), '#Con OT INDUSTEC', '#En una visita', '#Días 1.ª at.', '#' . $Vt('ASIGNADA') . ' o ' . $V('ESPERA_REPUESTO'),
                        '#Solicitudes ' . mb_strtolower($Vt('VENCIDO_48H'), 'UTF-8'), '#Novedades', '#OT INDUSTEC por la app']) ?>

<h2>Cumplimiento del preventivo</h2>
<?php if ($pre['fuente'] === null): ?>
  <p class="sub">Sin cronograma cargado en este corte.</p>
<?php else: ?>
  <table class="kpis"><tr>
    <?= $kpi($pre['pct_a_tiempo'] === null ? '—' : $pre['pct_a_tiempo'] . '%', $e($V('PREV_EJECUTADO', 2)) . ' a tiempo (contra el plan acordado)', $pre['pct_a_tiempo'] === null ? '#777' : ($pre['pct_a_tiempo'] >= 85 ? '#166534' : ($pre['pct_a_tiempo'] >= 70 ? '#92400e' : '#991b1b'))) ?>
    <?= $kpi($n($pre['cumplidos_a_tiempo'] + $pre['cumplidos_tarde']), 'ingresos ' . $e($V('PREV_EJECUTADO', 2))) ?>
    <?= $kpi($n($pre['vencidos'] + $pre['sin_cierre']), $e($V('ATRASADO', 2)) . ' (' . $n($pre['sin_cierre']) . ' por marcar como ejecutados)', $pre['vencidos'] + $pre['sin_cierre'] > 0 ? '#991b1b' : '#166534') ?>
    <?= $kpi($n($pre['kits_confirmados']) . ' / ' . $n($pre['total']), 'kits confirmados') ?>
  </tr></table>
  <table class="dos"><tr>
    <td><?= Reportes::svgAnillo(array_values(array_filter($pre['estados'], fn($x) => $x['v'] > 0)), 'ingresos', 'Los ingresos del periodo') ?></td>
    <td><?= $tabla(array_map(fn($z, $pz) => [$Z($z), (int) $pz['total'], (int) $pz['cumplidos'], (int) $pz['a_tiempo'], (int) $pz['vencidos'], (int) $pz['sin_cierre'], (int) $pz['en_curso'], (int) $pz['sin_agendar']],
                        array_keys($pre['por_zona']), $pre['por_zona']),
                   ['Zona', '#Ingresos', '#' . $ejecutados, '#A tiempo', '#' . $atrasados, '#' . $Vt('PREV_SIN_CIERRE'), '#' . $Vt('PREV_EN_EJECUCION'), '#' . $Vt('PREV_SIN_AGENDAR')]) ?>
        <?php if ($pre['motivos']): ?><p class="sub">Por qué se reagendó: <?= $e(implode(' · ', array_map(fn($x) => $x['e'] . ': ' . $x['v'], $pre['motivos']))) ?>.</p><?php endif; ?>
        <?php if ($pre['fuente'] === 'json'): ?><p class="sub">Leído del archivo de la estación (todavía no importado a la base).</p><?php endif; ?></td>
  </tr></table>
<?php endif; ?>

<h2>Dónde se concentra el trabajo</h2>
<table class="dos"><tr>
  <td><?= Reportes::svgBarras($r['locales'], 80, 'Locales con más órdenes') ?></td>
  <td><?= Reportes::svgBarras($r['tipos'], 170, 'Qué se pide') ?></td>
</tr></table>
<?php if ($r['reincidentes']): ?>
  <p class="sub"><b><?= count($r['reincidentes']) ?> locales con 5 o más órdenes en el periodo:</b> <?= $e(implode(', ', array_map(fn($l, $c) => "$l ($c)", array_keys($r['reincidentes']), $r['reincidentes']))) ?>. Cuando un local repite así, la causa suele ser de otra área (eléctrica, ventilación, desagüe).</p>
<?php endif; ?>
<?php if ($nov['por_area']): ?>
  <h2>Qué hace fallar los equipos</h2>
  <table class="dos"><tr>
    <td><?= Reportes::svgBarras($nov['por_area'], 170, 'Novedades por área') ?></td>
    <td><p class="sub"><?= $n($nov['total']) ?> novedades reportadas en el periodo; <?= $n($nov['con_aviso']) ?> <?= $e($V('NOVEDAD_CON_AVISO', (int) $nov['con_aviso'])) ?>; <?= $n($nov['pendientes']) ?> siguen <?= $e($V('NOVEDAD_POR_DECIDIR')) ?> (<?= $n($nov['alto']) ?> de riesgo alto).</p></td>
  </tr></table>
<?php endif; ?>

<?php /* Otros trabajos (011): los extras para KFC, autorizados por la administración. */ ?>
<?php $otr = $r['otros_trabajos'] ?? []; $modOtros = (int) ($r['archivo']['modulo_otros'] ?? 0); ?>
<h2>Otros trabajos para Grupo KFC</h2>
<p class="sub"><?= $n(count($otr)) ?> trabajos fuera del área de INDUSTEC, hechos por acuerdo con Grupo KFC y autorizados por la administración<?= $modOtros ? '; además, ' . $n($modOtros) . ' OT INDUSTEC del módulo «Otros», extras dentro del mismo trato' : '' ?>.</p>
<?= $tabla(array_map(fn($o) => [$o['aviso'], trim($o['local'] . ' ' . $o['local_nombre']), $Z($o['zona']), $o['trabajo'],
                                $o['ot'] !== '' ? $o['ot'] : '—', $o['estatus'], $o['acuerdo']], $otr),
           [$Vt('AVISO_SAP'), 'Local', 'Zona', 'Trabajo', $Vt('OT_CIERRE'), 'Estado', 'Acuerdo con KFC']) ?>

<?php /* El TOTAL DE ÓRDENES ABIERTAS con las columnas del plan de zona: sale de
         la misma función que la tarjeta del panel (Casos::clasificar), así que
         la cifra y las filas son las que Isabel ve en pantalla. */ ?>
<h2 class="salto"><?= $e($Vt('TOTAL_ABIERTAS')) ?> (plan de zona)</h2>
<p class="sub">Semáforo: <?php foreach ($SEM as $k => $c): ?><span class="sem" style="background:<?= $c ?>"></span><?= $e($SEM_T[$k]) ?> &nbsp; <?php endforeach; ?></p>
<?php if (!$r['casos_abiertos']): ?>
  <p class="sub"><?= $e(Reportes::mayuscula($V('TOTAL_ABIERTAS'))) ?> de este corte: 0.</p>
<?php else: ?>
  <table><thead><tr><th><?= $e($Vt('AVISO_SAP')) ?></th><th>Técnico</th><th>Local</th><th>Zona</th><th>Fecha de inicio</th><th>Equipo</th><th>Estado</th><th>Clasificación</th><th>Estado del equipo</th><th class="n">Días</th><th>Observaciones</th></tr></thead><tbody>
  <?php foreach ($r['casos_abiertos'] as $c): ?>
    <tr>
      <td style="background:<?= $SEM[$c['semaforo']] ?? '#fff' ?>"><?= $e($c['aviso']) ?></td>
      <td><?= $e($c['tecnico'] ?: '—') ?></td>
      <td><?= $e($c['local']) ?> <?= $e($c['local_nombre']) ?></td>
      <td><?= $e($Z($c['zona'])) ?></td>
      <td><?= $e($c['fecha']) ?></td>
      <td><?= $e($c['equipo'] ?: $c['trabajo']) ?></td>
      <td><?= $e($c['estatus']) ?></td>
      <td><?= $e($c['grupo']) ?></td>
      <td><?= $e($c['equipo_estado']) ?></td>
      <td class="n"><?= $c['dias'] === null ? '—' : (int) $c['dias'] ?></td>
      <td><?= $e(mb_strimwidth($c['observaciones'], 0, 90, '…', 'UTF-8')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
<?php endif; ?>
</body></html>
    <?php
    return (string) ob_get_clean();
}
