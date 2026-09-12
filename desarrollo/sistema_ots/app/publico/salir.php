<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';
Auth::salir();
header('Cache-Control: no-store');

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
