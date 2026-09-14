# `publico/` — lo que se sirve en `public_html/ot/`

> Al día el 13 de septiembre de 2026 (T2.14.8). Son las tres superficies de [`DISENO_APP_OTS.md`](../../../DISENO_APP_OTS.md) §2 en una sola carpeta: la **app del técnico** (celular, PWA), la **mesa de servicio** (escritorio: administración, dirección, jefes de zona) y los **extremos** que consultan las pantallas y la estación. Sin framework: HTML, CSS y JS a mano, PHP 8.2 detrás.

## La app del técnico (celular)

| Archivo | Qué hace |
|---|---|
| `index.html` + `app.js` + `guia.js` | El formulario único por pasos: correctivo, preventivo u otro proveedor; el caso elegido de «mis órdenes» prellena local, equipo y tipo; administrador del local de los ya ingresados (`locales_admin`); «Equipo nuevo / no está en la lista» (`equipos_propuestos`); diagnóstico pre-redactado y repuestos estructurados; fotos, novedades, satisfacción, firma; revisar y enviar |
| `reglas.js` + `reglas.fixture.mjs` | Las reglas del formato único del lado del navegador, atadas al fixture |
| `cola.js` + `offline.js` + `sw.js` + `manifest.json` | La PWA: la orden se guarda entera en IndexedDB antes del primer intento y sale sola al reconectar (idempotente por UUID); `sw.js` sirve el armazón cache-first (**subir `VERSION` en cada despliegue de la app**; hoy `ot-industec-v10`) |
| `mis.php` | La bandeja: Pendientes · Esperando · Atendidas (con el PDF) · Avisos (`nucleo/Avisos.php`); barra inferior |
| `foto.php`, `envio.php`, `yo.php` | Recibe las fotos de una en una, recibe la orden (número, PDF, cola de correo; `Casos::atenderPorOrden`; resuelve el pendiente si la orden concluye), y la sesión del técnico |
| `cronograma.html` + `cronograma.js` + `cronograma.css` + `cronograma.php` + `cronograma_accion.php` | El cronograma de preventivos por zona; kit, reagendar con motivo, cerrar, agendar (D15); enlaza al formulario prellenado |

## La mesa de servicio (escritorio)

| Archivo | Qué hace |
|---|---|
| `login.php`, `clave.php`, `salir.php` | Ingreso, cambio obligatorio de clave, cierre por POST; bloqueo de 15 min a los 5 fallos; sesión única con desplazamiento |
| `panel.php` | «Lo que te toca ahora» por rol; para la administración, una columna por zona; cierre por falta de atención con confirmación (D5) |
| `casos.php`, `asignacion.php` | El buzón de SAP con sus acciones (asignar, en revisión, derivar, veredicto, pedir seguimiento) y la asignación por bloques de zona con el equipo ordenado por carga |
| `pendientes.php` | Repuestos y equipos sin concluir: el flujo D3 (solicitado → validado → registrado en SAP → veredicto de KFC → resuelto), hilo, insistencias, 48 h |
| `novedades_visita.php` | Las novedades de las visitas y su decisión; registrar una desde la oficina |
| `ordenes.php` + `pdf.php` | El Archivo de todas las zonas (`ot_archivo`), solo lectura para los cuatro roles (D1): ver, descargar, compartir con enlace firmado de 24 h, pedir copia |
| `reportes.php` + `reporte_exportar.php` + `graficos.js` | El tablero por zona y mes, con Excel/PDF/PowerPoint (`nucleo/Reportes.php`, `nucleo/reporte_pdf.php`) |
| `documentos.php` + `documento.php` + `documentos/.htaccess` | Aprendizaje: manuales, guías y comunicados con aprobación, versiones y acuses (D11) |
| `equipos.php` | Los equipos nuevos propuestos por los técnicos: aprobar, rechazar, ya existía (D8) |
| `usuarios.php`, `bitacora.php` | Cuentas por zona (crear, editar, nueva clave, ver actividad) y la bitácora con filtros y CSV |
| `estilo.css`, `ui.js`, `busqueda.js`, `nucleo/Ui.php` | Un solo sistema de diseño para todas las pantallas; la barra y los avisos; el buscador por coincidencia parcial |

## Los extremos y las herramientas de línea de órdenes

| Archivo | Quién lo llama |
|---|---|
| `catalogos.php`, `novedades.php` (JSON del buzón, cada 30 s), `sync_casos.php` (la estación empuja los casos de SAP, firmado HMAC) | Las pantallas y la estación |
| `aplicar_sql.php`, `verificar_esquema.php` | Migraciones (`../sql/`) y su comprobación |
| `emitir_pendientes_cli.php`, `despachar_correo_cli.php` | Reemisión de PDF fallidos y despacho de la cola de correo (cron de hPanel al corte; hoy en modo PRUEBA nada sale) |
| `archivo_indexar_cli.php`, `cronograma_importar_cli.php`, `purgar_cli.php`, `reconciliar_cli.php`, `alta_padron_cli.php` | La estación por SSH o el cron |
| `minar.php` | Las consultas de detección sobre la bitácora |

`instalar.php` y `alta_padron.php` son de instalación: no se despliegan.

## Correr en local

```bash
php -S 127.0.0.1:8021        # desde esta carpeta, con el PHP 8.2 portable o el de la estación
```

`catalogos.php` lee los JSON de `catalogos/` (aquí hay tres de ejemplo; los completos los publica `t2_5_catalogos.py`). Las pantallas con sesión necesitan la base: en local se usa `nucleo/config.ejemplo.php` contra una MariaDB propia.

## Las reglas viven en tres lados

`reglas.js` · `nucleo/Validacion.php` (la que manda) · `agentes/scripts/t2_5_validacion.py`. Los tres corren el mismo fixture (37 casos) — ver [`../LEEME.md`](../LEEME.md).
