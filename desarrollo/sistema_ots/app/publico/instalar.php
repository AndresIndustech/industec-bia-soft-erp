<?php
declare(strict_types=1);

/**
 * instalar.php — Crea los usuarios iniciales UNA sola vez.
 *
 * POR QUE EXISTE: las claves no pueden ir en un archivo ni pasar por un chat.
 * Este script las genera al azar, las muestra en TU pantalla una única vez y
 * guarda solo el hash. Nadie más las ve, ni siquiera queda registro de ellas.
 *
 * TRES CANDADOS, porque un instalador olvidado en el servidor es una puerta:
 *
 *   1. Pide la clave de instalación que está en nucleo/config.php. Sin ella no
 *      hace nada, aunque alguien adivine la URL.
 *   2. Se niega a correr si ya existe algún usuario. No se puede usar dos veces
 *      para crear un superadministrador de contrabando.
 *   3. Te dice que lo borres al terminar, y comprueba si sigue ahí.
 *
 * BORRA ESTE ARCHIVO EN CUANTO TERMINES.
 */

require_once __DIR__ . '/nucleo/Db.php';

$cfg = Db::config();
$claveOk = !empty($cfg['clave_instalacion'])
        && hash_equals((string) $cfg['clave_instalacion'], (string) ($_GET['clave'] ?? ''));

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/** Clave legible pero no adivinable: 4 bloques de 4, sin caracteres ambiguos. */
function claveTemporal(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // sin I, O, 0, 1
    $out = [];
    for ($b = 0; $b < 4; $b++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) { $s .= $abc[random_int(0, strlen($abc) - 1)]; }
        $out[] = $s;
    }
    return implode('-', $out);
}

$error = null;
$creados = [];
$yaHay = 0;

try {
    $f = Db::uno('SELECT COUNT(*) n FROM usuarios');
    $yaHay = (int) ($f['n'] ?? 0);
} catch (Throwable $ex) {
    $error = 'No se pudo consultar la tabla `usuarios`. ¿Corriste el SQL en phpMyAdmin? '
           . 'Detalle: ' . $ex->getMessage();
}

if ($error === null && $claveOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($yaHay > 0) {
        $error = "Ya hay $yaHay usuario(s). Este instalador no vuelve a correr.";
    } else {
        $iniciales = [
            ['cbasantes', 'Cesar Basantes',  'SUPERADMIN', null, 'INDUSTEC'],
            ['abasantes', 'Andres Basantes', 'SUPERADMIN', null, 'INDUSTECH'],
        ];
        foreach ($iniciales as [$usuario, $nombre, $rol, $zona, $empresa]) {
            $clave = claveTemporal();
            Db::ejecutar(
                'INSERT INTO usuarios (usuario, nombre, clave_hash, rol, zona, debe_cambiar_clave)
                 VALUES (?,?,?,?,?,1)',
                [$usuario, $nombre, password_hash($clave, PASSWORD_DEFAULT), $rol, $zona]
            );
            $creados[] = compact('usuario', 'nombre', 'rol', 'empresa', 'clave');
        }
        $yaHay = count($creados);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalación · B.IA Soft ERP</title>
<link rel="stylesheet" href="estilo.css">
<style>
  .clave{ font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:20px;
          font-weight:700; letter-spacing:.06em; color:#0b1220;
          background:#fffbeb; border:1px solid #fde68a; border-radius:8px;
          padding:8px 12px; display:inline-block; margin-top:4px; user-select:all; }
  .err{ background:#fef2f2; border:1px solid #fecaca; color:#991b1b;
        border-radius:9px; padding:12px; font-size:13px; margin-bottom:14px; }
  .ok{ background:#f0fdf4; border:1px solid #bbf7d0; color:#166534;
       border-radius:9px; padding:12px; font-size:13px; margin-bottom:14px; }
</style>
</head>
<body>
<div class="wrap"><div class="card">
  <h1 style="font-size:20px;margin:0 0 4px">Instalación del sistema</h1>
  <p class="sub" style="margin:0 0 18px">Crea los usuarios iniciales. Se corre una sola vez.</p>

  <?php if ($error): ?>
    <div class="err"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if (!$claveOk): ?>
    <div class="err">
      <b>Falta la clave de instalación.</b>
      Abre esta página con <code>?clave=</code> seguido de lo que pusiste en
      <code>clave_instalacion</code> dentro de <code>nucleo/config.php</code>.
    </div>

  <?php elseif ($creados): ?>
    <div class="ok"><b>Listo.</b> Se crearon <?= count($creados) ?> usuarios.</div>
    <div class="nota-regular" style="margin-bottom:16px">
      <b>Anota estas contraseñas ahora.</b> No se vuelven a mostrar y no quedan
      guardadas en ningún lado: la base solo tiene su hash. Si se pierden, hay que
      volver a crear el usuario.
    </div>
    <?php foreach ($creados as $c): ?>
      <div class="bloque">
        <div class="bloque-tit"><span><?= e($c['nombre']) ?></span>
          <span class="chip"><?= e($c['empresa']) ?></span></div>
        <dl class="dl" style="display:grid;grid-template-columns:auto 1fr;gap:6px 12px;font-size:13px;margin:0">
          <dt style="color:var(--muted)">Usuario</dt><dd style="margin:0"><b><?= e($c['usuario']) ?></b></dd>
          <dt style="color:var(--muted)">Rol</dt><dd style="margin:0"><?= e($c['rol']) ?> · las tres zonas</dd>
        </dl>
        <div style="margin-top:8px">
          <span class="derivado">Contraseña temporal</span>
          <div class="clave"><?= e($c['clave']) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="nota-regular">
      <b>Ahora borra este archivo del servidor.</b> Un instalador olvidado es una
      puerta abierta. Después entra en <a href="login.php">login.php</a>: el
      sistema te va a exigir cambiar la contraseña en el primer ingreso.
    </div>

  <?php elseif ($yaHay > 0): ?>
    <div class="err">
      <b>Ya hay <?= $yaHay ?> usuario(s) en la base.</b> Este instalador no vuelve
      a correr, para que nadie lo use dos veces y se cree un superadministrador
      de contrabando. <b>Bórralo del servidor.</b>
      <br><br>Si necesitas otro usuario, créalo desde el módulo de usuarios o con
      <code>nucleo/crear_usuario.php</code> por línea de comandos.
    </div>

  <?php else: ?>
    <p style="font-size:14px">Se van a crear <b>dos superadministradores</b>, con
    contraseña generada al azar que verás una sola vez:</p>
    <ul style="font-size:14px;line-height:1.7">
      <li><b>cbasantes</b> — Cesar Basantes (INDUSTEC)</li>
      <li><b>abasantes</b> — Andres Basantes (INDUSTECH)</li>
    </ul>
    <p class="sub">Son dos a propósito: lo entregado queda en poder de INDUSTEC,
    así que el cliente tiene que poder administrar su propio sistema sin depender
    del proveedor.</p>
    <form method="post">
      <button class="btn primary" type="submit" style="padding:12px 20px">
        Crear los usuarios iniciales
      </button>
    </form>
  <?php endif; ?>
</div></div>
</body>
</html>
