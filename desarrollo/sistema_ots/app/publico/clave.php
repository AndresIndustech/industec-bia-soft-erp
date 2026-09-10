<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';

$u = Auth::exigir();
$error = null; $ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actual = (string)($_POST['actual'] ?? '');
    $nueva  = (string)($_POST['nueva'] ?? '');
    $repite = (string)($_POST['repite'] ?? '');

    $fila = Db::uno('SELECT clave_hash FROM usuarios WHERE usuario_id = ?', [$u['usuario_id']]);
    if (!password_verify($actual, $fila['clave_hash'])) {
        $error = 'La contraseña actual no es correcta.';
    } elseif (mb_strlen($nueva) < 10) {
        // Largo antes que complejidad: una frase larga resiste más que "Ab1!" y
        // no obliga a anotarla en un papel pegado al monitor.
        $error = 'La contraseña nueva necesita al menos 10 caracteres.';
    } elseif ($nueva !== $repite) {
        $error = 'Las dos contraseñas nuevas no coinciden.';
    } elseif (password_verify($nueva, $fila['clave_hash'])) {
        $error = 'La contraseña nueva no puede ser la misma de antes.';
    } else {
        Db::ejecutar('UPDATE usuarios SET clave_hash = ?, debe_cambiar_clave = 0 WHERE usuario_id = ?',
                     [password_hash($nueva, PASSWORD_DEFAULT), $u['usuario_id']]);
        Auth::bitacora('CAMBIO_CLAVE', 'usuario', (string)$u['usuario_id']);
        $ok = true;
    }
}
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cambiar contraseña · B.IA Soft ERP</title>
<link rel="stylesheet" href="estilo.css">
<meta name="theme-color" content="#0b4f8f">
<style>
  body{ display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px; }
  .entrada{ width:min(420px,100%); animation:entrar var(--t3) var(--curva) both; }
  .entrada .card{ padding:26px; box-shadow:var(--sombra-3); }
  .marca-cab{ display:flex; align-items:center; gap:10px; justify-content:center;
              margin-bottom:18px; color:var(--marca); font-weight:700; font-size:16px; }
  .marca-cab .punto{ width:11px; height:11px; border-radius:50%; background:var(--accent);
                     box-shadow:0 0 0 4px rgba(14,165,233,.18); }
  .campo{ margin-bottom:13px; }
</style>
</head>
<body>
<div class="entrada">
  <div class="marca-cab"><span class="punto"></span>B.IA Soft ERP</div>
  <div class="card">
  <h1 style="font-size:19px;margin:0 0 4px">Cambia tu contraseña</h1>
  <?php if ($u['debe_cambiar_clave'] && !$ok): ?>
    <p class="sub" style="margin:0 0 16px">La que tienes la creó otra persona. Elige una tuya antes de seguir.</p>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="aviso err" role="alert"><span class="ic" aria-hidden="true">✕</span>
      <div class="cuerpo"><?= e($error) ?></div></div>
  <?php endif; ?>
  <?php if ($ok): ?>
    <div class="aviso ok" role="status"><span class="ic" aria-hidden="true">✓</span>
      <div class="cuerpo">Contraseña actualizada.</div></div>
    <a class="btn primary" href="panel.php" style="display:inline-block;text-decoration:none">Ir al panel</a>
  <?php else: ?>
  <form method="post">
    <div class="campo"><label for="a">Contraseña actual</label>
      <input type="password" id="a" name="actual" autocomplete="current-password" required autofocus></div>
    <div class="campo"><label for="n">Contraseña nueva</label>
      <input type="password" id="n" name="nueva" autocomplete="new-password" required minlength="10">
      <span class="derivado">Mínimo 10 caracteres. Una frase que recuerdes sirve mejor que algo corto y raro.</span></div>
    <div class="campo"><label for="r">Repite la nueva</label>
      <input type="password" id="r" name="repite" autocomplete="new-password" required minlength="10"></div>
    <button class="btn primary bloque" type="submit">Guardar</button>
  </form>
  <?php endif; ?>
</div></div>
</body></html>
