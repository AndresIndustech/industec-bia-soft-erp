<?php
/**
 * plantilla_ot.php — El PDF de la orden de trabajo. Lo arma Emision::html() con $d.
 *
 * Es la plantilla de producción (ot_normal_v3/{zona}/plantilla-pdf.php), con los
 * mismos bloques en el mismo orden —es el formato que Grupo KFC ya conoce—, y
 * tres diferencias:
 *   - varios equipos por orden, con los datos del maestro de activos (la de
 *     producción tenía uno, tecleado). Lo que el maestro no trae —marca, modelo,
 *     serie— sale solo si el técnico lo escribió: no se inventa;
 *   - la franja «DOCUMENTO DE PRUEBA» en el sitio de pruebas;
 *   - si faltan fotos que la orden anunciaba, lo dice en vez de callarlo (I-7).
 */
if (!isset($d) || !is_array($d)) { return; }
$e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$hora = static fn($f): string => $f ? str_replace('T', ' ', substr((string) $f, 0, 16)) : '';

$tiempo = '';
if (!empty($d['inicio']) && !empty($d['fin'])) {
    $ini = strtotime((string) $d['inicio']);
    $fin = strtotime((string) $d['fin']);
    if ($ini && $fin && $fin > $ini) {
        $s = $fin - $ini;
        $tiempo = intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm';
    }
}
$atiempo = strtoupper(str_replace('Í', 'I', mb_strtoupper(trim($d['atiempo']))));
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= $e($d['id']) ?></title>
<style>
  @page { margin:15mm; }
  body { font-family:DejaVu Sans, Arial, sans-serif; font-size:11px; color:#111; background:#fff; }
  h1 { font-size:16px; margin:0 0 6px; font-weight:bold; }
  .header { margin-bottom:10px; }
  .logo { height:40px; margin-bottom:6px; }
  .h2 { background:#f3f4f6; padding:5px; font-weight:bold; border:1px solid #e5e7eb; margin-top:10px; font-size:12px; }
  .box { border:1px solid #e5e7eb; padding:6px; margin-top:4px; }
  .text { white-space:pre-wrap; }
  .prueba { border:2px solid #b91c1c; color:#b91c1c; padding:6px; margin-bottom:8px; font-weight:bold; text-align:center; }
  .eq { border-top:1px dashed #e5e7eb; padding-top:4px; margin-top:4px; }
  .eq.primero { border-top:0; padding-top:0; margin-top:0; }
  .photo-table { width:100%; border-collapse:collapse; }
  .photo-table td { width:33.33%; padding:6px; vertical-align:top; page-break-inside:avoid; }
  .photo-table img { display:block; width:100%; height:auto; border:1px solid #ccc; padding:3px; background:#fff; }
</style>
</head>
<body>

<?php if ($d['prueba']): ?>
  <div class="prueba">DOCUMENTO DE PRUEBA — generado por el sistema en pruebas. No es una orden de trabajo válida y no se envió a nadie.</div>
<?php endif; ?>

<div class="header">
  <?php if ($d['logo']): ?><img src="<?= $d['logo'] ?>" class="logo" alt="INDUSTEC"><?php endif; ?>
  <h1>ORDEN DE TRABAJO <?= $e($d['id']) ?></h1>
  <small>Documento generado automáticamente el <?= $e($d['emitida']) ?></small>
</div>

<div class="h2">DATOS GENERALES</div>
<div class="box">
  <b>ID-ORDEN-INDUSTEC:</b> <?= $e($d['id']) ?><br>
  <b>ID-ORDEN-GRUPOKFC:</b> <?= $e($d['aviso'] !== '' ? $d['aviso'] : 'Sin aviso de SAP') ?><br>
  <b>Tipo de Trabajo:</b> <?= $e(ucfirst(strtolower($d['modulo']))) ?><?= $d['dia'] ? ' · día ' . $e($d['dia']) : '' ?><br>
  <b>Fecha de Atención:</b> <?= $e($d['fecha']) ?><br>
  <b>Cliente:</b> <?= $e($d['cliente']) ?><br>
  <b>Local:</b> <?= $e($d['local']) ?><br>
  <b>Técnico Asignado:</b> <?= $e($d['tecnico']) ?><br>
  <b>Administrador del local:</b> <?= $e($d['admin']) ?><br>
  <b>Correo del Local:</b> <?= $e($d['correo_local']) ?><br>
  <b>Correo de Jefe de Operaciones Local:</b> <?= $e($d['correo_jefe_op']) ?><br>
</div>

<div class="h2">DETALLE DEL EQUIPO</div>
<div class="box">
  <?php if (!$d['equipos']): ?>Sin equipo registrado.<?php endif; ?>
  <?php foreach ($d['equipos'] as $i => $q): ?>
    <div class="eq<?= $i === 0 ? ' primero' : '' ?>">
      <b>Equipo:</b> <?= $e($q['tipo']) ?><?= $q['clase'] ? ' · ' . $e($q['clase']) : '' ?><br>
      <?php if ($q['equipo_sap']): ?><b>Equipo SAP:</b> <?= $e($q['equipo_sap']) ?><br><?php endif; ?>
      <?php if ($q['marca'] || $q['modelo'] || $q['serie']): ?>
        <b>Marca:</b> <?= $e($q['marca'] ?: '—') ?> · <b>Modelo:</b> <?= $e($q['modelo'] ?: '—') ?>
        · <b>Serie:</b> <?= $e($q['serie'] ?: '—') ?><br>
      <?php endif; ?>
      <b>Código Activo Fijo:</b> <?= $e($q['codigo_activo'] ?: 'sin dato en el maestro') ?><br>
      <?php if ($q['ubicacion']): ?><b>Ubicación técnica:</b> <?= $e($q['ubicacion']) ?><br><?php endif; ?>
      <b>Estado del Equipo:</b> <?= $e($q['estado'] ?: 'sin dato') ?>
      <?php if ($q['obs']): ?><br><b>Observación:</b> <?= $e($q['obs']) ?><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="h2">DETALLE DE LA INTERVENCIÓN</div>
<div class="box">
  <b>Hora Inicio:</b> <?= $e($hora($d['inicio'])) ?><br>
  <b>Hora Fin:</b> <?= $e($hora($d['fin'])) ?><br>
  <b>Tiempo de Atención:</b> <?= $e($tiempo) ?><br>
  <b>Actividades:</b><br>
  <div class="text"><?= nl2br($e($d['actividades'])) ?></div>
</div>

<div class="h2">REPUESTOS</div>
<div class="box"><div class="text"><?= nl2br($e($d['repuestos'])) ?></div></div>

<?php if (($d['con_proveedor'] ?? '') !== ''): ?>
<div class="h2">TRABAJO CON OTRO PROVEEDOR</div>
<div class="box"><div class="text">
  Intervención realizada por <b><?= $e($d['con_proveedor']) ?></b>. INDUSTEC registra y acompaña
  el trabajo en el local; la garantía de lo ejecutado corresponde a ese proveedor.
</div></div>
<?php endif; ?>

<div class="h2">OBSERVACIONES</div>
<div class="box"><div class="text"><?= nl2br($e($d['observaciones'] !== '' ? $d['observaciones'] : 'Sin observaciones.')) ?></div></div>

<div class="h2">ESTADO DE LA OT</div>
<div class="box"><div class="text"><?= $e($d['estado_ot']) ?></div></div>

<div class="h2">EVIDENCIA FOTOGRÁFICA</div>
<div class="box">
  <?php if (!$d['fotos']): ?>
    <p>Sin fotos<?= $d['fotos_esperadas'] ? ': la orden anunciaba ' . (int) $d['fotos_esperadas'] . ' y no llegó ninguna' : '' ?></p>
  <?php else: ?>
    <?php if ($d['fotos_esperadas'] > count($d['fotos'])): ?>
      <p>Faltan <?= (int) $d['fotos_esperadas'] - count($d['fotos']) ?> de las <?= (int) $d['fotos_esperadas'] ?> fotos que anunciaba la orden.</p>
    <?php endif; ?>
    <table class="photo-table"><tr>
      <?php foreach ($d['fotos'] as $i => $img): ?>
        <?php if ($i > 0 && $i % 3 === 0): ?></tr><tr><?php endif; ?>
        <td><img src="<?= $img ?>" alt="Foto <?= $i + 1 ?>"></td>
      <?php endforeach; ?>
      <?php for ($k = count($d['fotos']) % 3; $k > 0 && $k < 3; $k++): ?><td></td><?php endfor; ?>
    </tr></table>
  <?php endif; ?>
</div>

<div class="h2">SATISFACCIÓN DEL CLIENTE</div>
<div class="box">
  <div><b>Su requerimiento fue atendido a tiempo:</b>
    <?= $atiempo === 'SI' ? 'SI' : ($atiempo === 'NO' ? 'NO' : 'No respondido') ?></div>
  <br>
  <?php if ($d['satisfaccion'] > 0): ?>
    <div><b>Calificación:</b> <?= (int) $d['satisfaccion'] ?>/10</div>
    <div style="font-size:18px; color:#fbbf24; line-height:1.2;"><?=
      str_repeat('★', (int) $d['satisfaccion']) . str_repeat('☆', 10 - (int) $d['satisfaccion']) ?></div>
  <?php else: ?>
    <p>No se registró calificación</p>
  <?php endif; ?>
</div>

<div class="h2">FIRMA DEL ADMINISTRADOR</div>
<div class="box">
  <?php if ($d['firma']): ?>
    <img src="<?= $d['firma'] ?>" style="max-width:250px;" alt="Firma">
    <div><b>Administrador:</b> <?= $e($d['admin']) ?></div>
  <?php else: ?>
    <p>Sin firma</p>
  <?php endif; ?>
</div>

</body>
</html>
