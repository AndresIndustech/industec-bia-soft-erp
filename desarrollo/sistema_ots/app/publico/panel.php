<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';

$u = Auth::exigir();
if ($u['debe_cambiar_clave']) {
    header('Location: clave.php');
    exit;
}
$permisos = Auth::permisos();
$zona = Auth::zonaAlcance();

/**
 * Los módulos, agrupados por área.
 *
 * Esta lista SOLO decide qué se dibuja. La protección de verdad está en cada
 * página, que empieza con su propio Auth::exigir(). Esconder una tarjeta no
 * protege nada: el endpoint sigue abierto para quien conozca la URL.
 */
$AREAS = [
    'Operación del día' => [
        ['casos.ver',      'Buzón de casos',  'casos.php',
         'Lo que llega del correo de SAP, con las alertas de alcance marcadas'],
        ['casos.asignar',  'Asignación',      'asignacion.php',
         'Repartir los casos entre los técnicos de la zona'],
        ['ots.crear',      'Emitir orden',    'index.html',
         'El formulario de orden de trabajo, con o sin señal'],
        ['ots.ver',        'Órdenes emitidas','ordenes.php',
         'Buscar, ver y reenviar el PDF de una orden'],
    ],
    'Planificación' => [
        ['cronograma.ver', 'Cronograma de preventivos', 'cronograma.html',
         'Calendario del año, novedades y alertas de 3 días'],
    ],
    'Análisis' => [
        ['reportes.ver',   'Tableros',        'reportes.php',
         'Casos abiertos por antigüedad, cerrados de la semana, cumplimiento'],
        ['reportes.generar','Reportes',       'reportes_generar.php',
         'El mensual y el de Grupo KFC, sobre sus plantillas reales'],
    ],
    'Administración del sistema' => [
        ['maestros.editar',   'Maestros',            'maestros.php',
         'Locales, técnicos y equipos'],
        ['usuarios.operativos','Usuarios y permisos', 'usuarios.php',
         'Crear usuarios, asignar rol y alcance, cerrar sesiones'],
        ['bitacora.ver',      'Bitácora',            'bitacora.php',
         'Quién hizo qué, y quién consultó qué'],
    ],
];

// Lo que ya existe. El resto se muestra apagado en vez de dar 404.
$LISTOS = ['cronograma.html', 'index.html', 'usuarios.php'];

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$ROL = ['SUPERADMIN' => 'Superadministrador', 'ADMIN' => 'Administración',
        'JEFE_ZONA' => 'Jefe de zona', 'TECNICO' => 'Técnico'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panel · Sistema de OTs INDUSTEC</title>
<link rel="stylesheet" href="estilo.css">
<style>
  .barra{ display:flex; justify-content:space-between; align-items:center; gap:12px;
          flex-wrap:wrap; padding:10px 14px; background:#fff;
          border-bottom:1px solid var(--border); position:sticky; top:0; z-index:40; }
  .barra .yo{ display:flex; align-items:center; gap:10px; font-size:13px; }
  .barra .nom{ font-weight:700; }
  .mods{ display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:12px; }
  .mod{ display:block; text-decoration:none; color:inherit; border:1px solid var(--border);
        border-radius:11px; padding:14px; background:#fff; }
  .mod:hover{ border-color:var(--accent); box-shadow:0 4px 14px rgba(14,165,233,.10); }
  .mod h3{ margin:0 0 4px; font-size:15px; }
  .mod p{ margin:0; font-size:12.5px; color:var(--muted); line-height:1.4; }
  .mod.pronto{ opacity:.55; pointer-events:none; }
  .mod.pronto h3::after{ content:' · en construcción'; font-weight:400; font-size:11px; color:var(--muted); }
</style>
</head>
<body>

<div class="barra">
  <strong>Sistema de OTs · INDUSTEC</strong>
  <div class="yo">
    <span class="nom"><?= e($u['nombre']) ?></span>
    <span class="chip"><?= e($ROL[$u['rol']] ?? $u['rol']) ?></span>
    <span class="chip"><?= $zona ? 'Zona ' . e($zona) : 'Las tres zonas' ?></span>
    <a class="btn" href="salir.php">Salir</a>
  </div>
</div>

<div class="wrap">
  <div class="card">
    <h1 style="font-size:19px;margin:0 0 4px">Hola, <?= e(explode(' ', $u['nombre'])[0]) ?></h1>
    <p class="sub" style="margin:0 0 18px">
      <?php if ($zona): ?>
        Ves y gestionas únicamente lo de la zona <b><?= e($zona) ?></b>.
      <?php else: ?>
        Ves y gestionas las tres zonas.
      <?php endif; ?>
    </p>

    <?php
    $total = 0; $disponibles = 0;
    foreach ($AREAS as $mods) {
        foreach ($mods as [$perm, , $url, ]) {
            if (!in_array($perm, $permisos, true)) continue;
            $total++;
            if (in_array($url, $LISTOS, true)) $disponibles++;
        }
    }
    ?>
    <?php foreach ($AREAS as $area => $mods): ?>
      <?php
      $visibles = array_filter($mods, fn($m) => in_array($m[0], $permisos, true));
      if (!$visibles) continue;   // un área sin permisos no se dibuja vacía
      ?>
      <h2><?= e($area) ?></h2>
      <div class="mods">
        <?php foreach ($visibles as [$perm, $titulo, $url, $desc]): ?>
          <?php $listo = in_array($url, $LISTOS, true); ?>
          <a class="mod<?= $listo ? '' : ' pronto' ?>" href="<?= $listo ? e($url) : '#' ?>"
             <?= $listo ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
            <h3><?= e($titulo) ?></h3>
            <p><?= e($desc) ?></p>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <p class="sub" style="margin-top:20px">
      <?= $disponibles ?> de <?= $total ?> módulos están construidos.
      Los apagados todavía no existen: se muestran para que sepas qué falta,
      no para dar un error al entrar.
    </p>
  </div>
</div>

</body>
</html>
