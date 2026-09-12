<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';

Auth::iniciarCookie();
if (Auth::actual()) {
    header('Location: panel.php');
    exit;
}

$error = null;
$sesionAbierta = null;
$usuario = '';
$destino = $_GET['r'] ?? 'panel.php';
// Nunca redirigir a donde diga el parámetro sin más: eso es un open redirect.
// `//otro-sitio/x.php` pasaba la expresión: el navegador lo toma como otro dominio.
if (!preg_match('#^[a-z0-9_./-]+\.php(\?.*)?$#i', $destino) || str_contains($destino, '..')
    || str_starts_with($destino, '//')) {
    $destino = 'panel.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim((string) ($_POST['usuario'] ?? ''));
    $clave    = (string) ($_POST['clave'] ?? '');
    $desplaza = ($_POST['desplazar'] ?? '') === '1';

    $r = Auth::ingresar($usuario, $clave, $desplaza);
    if ($r['ok']) {
        $u = Auth::actual();
        header('Location: ' . ($u && $u['debe_cambiar_clave'] ? 'clave.php' : $destino));
        exit;
    }
    $error = $r['motivo'];
    $sesionAbierta = $r['sesion_abierta'] ?? null;
}

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ingreso · B.IA Soft ERP</title>
<link rel="stylesheet" href="estilo.css">
<meta name="theme-color" content="#0b4f8f">
<style>
  /* El ingreso es la unica pantalla sin barra de aplicacion, asi que la marca
     tiene que estar aqui: es lo que le dice al tecnico que abrio lo correcto
     antes de teclear su clave. */
  body{ display:flex; align-items:center; justify-content:center; min-height:100vh;
        padding:20px; }
  .entrada{ width:min(400px,100%); animation:entrar var(--t3) var(--curva) both; }
  .marca-cab{ display:flex; align-items:center; gap:10px; justify-content:center;
              margin-bottom:18px; color:var(--marca); font-weight:700; font-size:16px; }
  .marca-cab .punto{ width:11px; height:11px; border-radius:50%; background:var(--accent);
                     box-shadow:0 0 0 4px rgba(14,165,233,.18); }
  .entrada .card{ padding:26px; box-shadow:var(--sombra-3); }
  .entrada h1{ font-size:20px; margin:0 0 4px; }
  .entrada .sub{ margin:0 0 20px; }
  .campo{ margin-bottom:13px; }
  .ses dl{ display:grid; grid-template-columns:auto 1fr; gap:2px 10px; margin:8px 0 0; }
  .ses dt{ opacity:.8; }
  .ses dd{ margin:0; }
  .pie{ font-size:12px; color:var(--muted); text-align:center; margin-top:16px; }
</style>
</head>
<body>
<div class="entrada">
  <div class="marca-cab"><span class="punto"></span>B.IA Soft ERP</div>
  <div class="card">
    <h1>B.IA Soft ERP</h1>
    <p class="sub">INDUSTEC · gestión de órdenes de trabajo</p>

    <?php if ($error && !$sesionAbierta): ?>
      <div class="aviso err" role="alert">
        <span class="ic" aria-hidden="true">✕</span>
        <div class="cuerpo"><?= e($error) ?></div>
      </div>
    <?php endif; ?>

    <?php if ($sesionAbierta): ?>
      <div class="aviso warn ses" style="display:block">
        <b>Ya tienes una sesión abierta.</b>
        <dl>
          <dt>Desde</dt><dd><?= e($sesionAbierta['desde']) ?></dd>
          <dt>Equipo</dt><dd><?= e(substr((string) $sesionAbierta['equipo'], 0, 70)) ?></dd>
        </dl>
        <p style="margin:10px 0 0">
          Solo se permite una sesión por usuario. Puedes cerrar la otra y entrar aquí,
          o cancelar y seguir usándola donde está.
        </p>
      </div>
      <form method="post">
        <input type="hidden" name="usuario" value="<?= e($usuario) ?>">
        <input type="hidden" name="clave" value="<?= e($_POST['clave'] ?? '') ?>">
        <input type="hidden" name="desplazar" value="1">
        <button class="btn primary bloque" type="submit">
          Cerrar la otra sesión y entrar aquí
        </button>
      </form>
      <p class="pie"><a href="login.php">Cancelar</a></p>
    <?php else: ?>
      <form method="post" autocomplete="on">
        <div class="campo">
          <label for="usuario">Usuario</label>
          <input type="text" id="usuario" name="usuario" value="<?= e($usuario) ?>"
                 autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </div>
        <div class="campo">
          <label for="clave">Contraseña</label>
          <input type="password" id="clave" name="clave" autocomplete="current-password" required>
        </div>
        <button class="btn primary bloque" type="submit">Entrar</button>
      </form>
      <p class="pie">Si olvidaste tu contraseña, pídesela al administrador del sistema.</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
