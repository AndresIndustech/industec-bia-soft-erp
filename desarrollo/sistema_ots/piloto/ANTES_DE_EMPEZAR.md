# Antes de empezar el piloto — lista de comprobación para Andrés

> Esta hoja es para quien prepara el sitio de pruebas el día anterior al arranque. Las otras hojas del paquete son para las personas que van a usar el sistema. Fecha de preparación: 13 de septiembre de 2026.

El piloto corre **en el sitio de pruebas** (`darkviolet-armadillo-872352.hostingersite.com/ot/`), con la zona **UIO**. El sistema de producción (`yellow-elephant`) no se toca: los técnicos siguen mandando sus órdenes por el formulario de siempre **además** de la app nueva, hasta el corte (PLAN, T2.16).

Cada paso trae **cómo se comprueba que salió bien**. Si uno falla, no se empieza.

## 1. Las baterías de prueba en verde (desde el PC, 10 minutos)

Antes de retirar los datos de prueba, correr las siete baterías contra el servidor. Se corren desde `desarrollo\sistema_ots\app\pruebas\servidor\`:

```bat
set INDUSTEC_LLAVE_SSH=C:\Users\andre\.ssh\industec_hostinger_pc
set PYTHONUTF8=1
python verificar_http.py
python verificar_bandeja.py
python verificar_ciclo.py
python verificar_emision.py
python verificar_archivo.py
python verificar_reportes.py
python verificar_seguridad.py
```

**Comprobación:** las siete terminan con «0 fallos» (el 13 de septiembre de 2026: 78 · 37 · 48 · 34 · 50 · 36 · 28, **311 comprobaciones en verde**). Si alguna falla, se arregla antes de seguir: es más barato que descubrirlo con un técnico delante de una freidora.

## 2. Retirar los datos de prueba del servidor

✅ **Hecho el 2026-09-13 (noche), a pedido explícito de Andrés.** Las baterías dejaban cuentas de prueba (`tec_prueba_uio_a`, `tec_prueba_uio_b`, `jefe_prueba_uio`, `jefe_prueba_cnlj`, `admin_prueba`), dos casos reales de UIO asignados a una cuenta de prueba, avisos sintéticos `9999xxxx`, cuatro pendientes y dos novedades marcados PRUEBA, y 72 órdenes de la serie 9000. Se revirtió con un solo comando, por SSH, desde la carpeta `ot/` del sitio de pruebas:

```bash
cd domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot
php ~/respaldos/deshacer_prueba.php
```

**Comprobado:** con respaldo previo (`mysqldump` manual a `~/respaldos/darkviolet_bd_antes_limpieza_prueba_20260914_022707.sql.gz`, 144 009 bytes, credenciales leídas de `nucleo/config.php` sin imprimirlas), el script devolvió «órdenes de prueba borradas: 72 · del Archivo: 66 · pendientes: 4 · novedades: 2 · casos devueltos: 2 · avisos sintéticos borrados: 2 · cuentas desactivadas: 5». Verificado después, por consulta directa: las 5 cuentas de prueba están **inactivas** (no borradas: la bitácora las referencia), 0 filas de `ot_capturadas`, `ot_archivo` solo tiene sus 143 filas reales (`origen = CORREO`), y los avisos `10353767`/`10353788` (los **dos casos reales de UIO** que estaban mal asignados a `tec_prueba_uio_a`) volvieron a **0 filas en `casos_gestion`**: no existían ahí antes de la prueba, así que quedan otra vez como cualquier aviso sin abrir del catálogo del buzón, listos para que el jefe de zona los asigne de verdad. **No se borró ningún dato real**, solo la asignación y el rastro que dejó la prueba.

**Hallazgo y corrección de paso:** `deshacer_prueba.php` no limpiaba `ot_archivo` (el índice que llena `archivo_indexar_cli.php`), así que las 66 órdenes ya indexadas sobrevivían con el PDF ya borrado del disco — un enlace roto en la pantalla «Archivo». Corregido en el propio script (commit `3a5b284` en `pc/pulido-2026-09-12`, subido a GitHub) y en el servidor: ahora `deshacer_prueba.php` también borra de `ot_archivo` las órdenes que borra de `ot_capturadas`. Detalle en `desarrollo/sistema_ots/app/pruebas/servidor/LEEME.md`.

Si hace falta volver a correr las baterías más adelante, `preparar_prueba.php` lo deja todo como estaba y `deshacer_prueba.php` (ya corregido) lo vuelve a retirar completo, Archivo incluido.

## 3. Las órdenes de la serie de pruebas — decisión tomada

✅ **Resuelto el 2026-09-13: Andrés pidió borrarlas**, no dejarlas. Las órdenes emitidas por las baterías llevaban números de la serie **9000** (`CORRECTIVO:UIO` llegó a 9072). No chocaban con la numeración real, que se siembra al corte desde los contadores del sistema viejo (`t2_14_sembrar_correlativos.py`, en ensayo: 1856 para UIO), pero tampoco hacía falta dejarlas para el piloto. Se borraron con el paso 2 de arriba (`ot_capturadas` + `ot_archivo`, con su PDF), con el volcado previo ya mencionado. El contador de la serie de pruebas (`correlativos`, fila `CORRECTIVO:UIO`) se dejó en 9072 a propósito: es solo un contador, no dato de cliente, y el próximo uso de prueba sigue en 9073 sin chocar con nada; si se prefiere reiniciarlo a 9000 antes del piloto, es un `DELETE` de una fila y se puede pedir aparte.

## 4. Las cuentas de UIO

Ir a `Usuarios` con la cuenta de administración. Tiene que haber, en el grupo **UIO**: el jefe de zona y sus técnicos (hoy son **1 jefe y 5 técnicos**, además de la administración y las dos cuentas de dirección sin zona).

**Comprobación:** cada persona del piloto tiene una fila **activa**, con su rol y su zona correctos. La casilla «cambio de clave pendiente» está marcada en quienes nunca han entrado. Cómo se entregan las claves está en [`CUENTAS.md`](CUENTAS.md).

Si falta alguien: «Crear un usuario» (rol TÉCNICO o JEFE DE ZONA, zona UIO). Si alguien ya no trabaja: editar y desactivar; **no se borra** (la bitácora lo referencia).

## 5. El padrón de técnicos y los catálogos

La app exige que quien firma una orden esté en el padrón (`catalogos/tecnicos.json`) y que el local y los equipos estén en el maestro. Los publica la estación.

**Comprobación:** desde el PC o la estación, `desarrollo\agentes\.venv\Scripts\python.exe scripts\t2_5_catalogos.py --empujar` termina sin error, y en la app del técnico el buscador de locales devuelve los 100 locales. `deshacer_prueba.php` (paso 2) ya restauró el padrón sin las cuentas de prueba.

En el servidor, el 13 de septiembre de 2026: **159 diagnósticos pre-redactados**, **109 repuestos frecuentes**, **368 ingresos del cronograma preventivo** y **209 órdenes en el Archivo** (180 con PDF en Hostinger). El histórico completo de 7.069 órdenes entra al Archivo cuando Andrés decida subir los PDF (D2 del PLAN); hasta entonces el índice muestra «pedir copia» en las que solo están en la estación.

## 6. El buzón de SAP sigue llegando al sistema nuevo

Los casos nuevos entran al `Buzón` porque la estación vigila el correo de SAP y los empuja al sitio de pruebas (`t2_9_buzon_vigilante.py`).

**Comprobación:** en `Inicio` de la administración, «Cómo va el buzón» muestra una hora de actualización de hoy. Si no, en la estación: el vigilante está corriendo y `config\.env` tiene `SYNC_URL` apuntando al sitio de pruebas.

## 7. Ningún correo sale del sitio de pruebas

`nucleo/config.php` del servidor tiene `emision_modo = PRUEBA`: la orden recibe su número y su PDF, pero el correo al local queda **RETENIDO** en la cola. Así se prueba todo sin que a un local de KFC le llegue una orden de ensayo.

**Comprobación:** en el servidor, `php verificar_esquema.php` dice TODO OK, y tras enviar una orden de prueba la fila de `email_queue` queda en `RETENIDO`. **No cambiar `emision_modo` durante el piloto.**

## 8. La app en el celular de cada técnico

- Se abre en `https://darkviolet-armadillo-872352.hostingersite.com/ot/` (también desde la web: `/acceso/` → «Abrir la app del técnico»).
- Se entra con usuario y clave; el sistema obliga a cambiar la clave la primera vez.
- Se agrega a la pantalla de inicio (Chrome/Edge en Android: menú → «Agregar a la pantalla principal»; Safari en iPhone: compartir → «Agregar a inicio»). Así abre como una app y guarda las órdenes sin señal.
- Quien ya la tenía instalada de antes recibe sola la versión nueva (`sw.js` v10): basta con cerrarla y volver a abrirla.

**Comprobación:** con el celular en modo avión, la app abre, muestra la bandeja y deja llenar una orden; al volver la señal, la orden sale sola y aparece su número.

## 9. El saneamiento nocturno en la estación (paralelo al piloto)

No es requisito para empezar, pero conviene que corra desde la primera noche: espeja cada día lo que produce el sistema viejo **y** lo que produce la app (PDF, fotos y un volcado verificado de la base). Está en `desarrollo\agentes\scripts\SANEAMIENTO.md` (Tarea programada «INDUSTEC - Saneamiento nocturno»; la crea Andrés en la estación).

**Comprobación:** a la mañana siguiente, `SALIDAS IA\OTS\estado_nocturno.json` tiene `"ok": true`.

## 10. Lo que NO se hace antes del piloto

- No se tocan los formularios viejos ni la carpeta `ot/produccion/` de `yellow-elephant`.
- No se programan cron de envío de correo en hPanel (el reemisor y el despachador se programan al corte).
- No se siembran los correlativos reales (`--ejecutar`): eso es el paso 2 del corte, con el sistema viejo detenido.
- No se sube el histórico de PDF a Hostinger sin la decisión de Andrés (D2).

## Resumen de un vistazo

| # | Paso | Comprobación | Hecho |
|---|---|---|---|
| 1 | Siete baterías en verde | 0 fallos en cada una | ☐ |
| 2 | `deshacer_prueba.php` | cuentas de prueba inactivas, sin avisos 9999 | ✅ 2026-09-13 |
| 3 | Decisión sobre las órdenes 90xx | anotada en ESTADO.md | ✅ 2026-09-13 — se borraron |
| 4 | Cuentas de UIO activas | 1 jefe + 5 técnicos + administración | ☐ |
| 5 | Padrón y catálogos al día | buscador de locales con 100 locales | ☐ |
| 6 | Buzón de SAP llegando | «Cómo va el buzón» con hora de hoy | ☐ |
| 7 | Correo retenido | `emision_modo = PRUEBA`, fila RETENIDO | ☐ |
| 8 | App instalada en cada celular | abre y llena una orden sin señal | ☐ |
| 9 | Saneamiento nocturno | `estado_nocturno.json` con `ok: true` | ☐ |
