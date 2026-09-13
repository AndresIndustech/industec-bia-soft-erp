<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';

/*
 * salir.php — Cerrar la sesión.
 *
 * SOLO POR POST, CON EL TOKEN (SEG-25). Un cierre de sesión por GET se dispara
 * con cualquier imagen o enlace que apunte a `salir.php`: bastaba un mensaje con
 * esa dirección para sacar a un técnico en medio de una orden. Por GET se muestra
 * un botón; el cierre real solo ocurre con el POST que ese botón manda.
 */
Auth::iniciarCookie();
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $token = Auth::csrfToken();
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Salir · B.IA Soft ERP</title>
<link rel="stylesheet" href="estilo.css">
</head>
<body>
<div class="wrap" style="max-width:420px;margin:48px auto;padding:0 16px">
  <div class="card">
    <h1 style="font-size:19px;margin:0 0 8px">¿Cerrar la sesión?</h1>
    <p class="sub" style="margin:0 0 16px">Se borran del teléfono los catálogos y la bandeja. Las órdenes que
      todavía no salieron se quedan guardadas y saldrán cuando vuelvas a entrar.</p>
    <form method="post" action="salir.php">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
      <button class="btn primary" type="submit" style="width:100%;padding:12px">Salir</button>
    </form>
    <p style="margin:12px 0 0"><a href="panel.php">Volver</a></p>
  </div>
</div>
</body>
</html><?php
    exit;
}

Auth::exigirCsrf();
Auth::salir();

/* Al salir se borra del celular lo que se bajó con esta sesión: los catálogos,
   la bandeja y el borrador del formulario. Es un teléfono personal y eso son
   datos de KFC y del personal. La cola de órdenes NO se borra: es trabajo del
   técnico que todavía no llegó, y queda ligada a quien la llenó. */
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Saliendo · B.IA Soft ERP</title>
<noscript><meta http-equiv="refresh" content="0;url=login.php"></noscript>
</head>
<body style="font-family:system-ui;padding:24px">
<p>Cerrando sesión…</p>
<script>
(function () {
  var listo = false;
  function fin() { if (!listo) { listo = true; location.replace('login.php'); } }
  try { localStorage.removeItem('ot_borrador_v1'); } catch (e) {}
  var borrar = ('caches' in window)
    ? caches.keys().then(function (ks) {
        return Promise.all(ks.filter(function (k) { return /-datos$/.test(k); })
                             .map(function (k) { return caches.delete(k); }));
      })
    : Promise.resolve();
  borrar.then(fin, fin);
  setTimeout(fin, 1500);
})();
</script>
</body>
</html>
