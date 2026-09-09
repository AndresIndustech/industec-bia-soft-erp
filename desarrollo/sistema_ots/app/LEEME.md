# App de OTs — código del sistema nuevo

Diseño completo en [`DISENO_APP_OTS.md`](../../DISENO_APP_OTS.md).
Formato de la orden en [`ESPECIFICACION_OT_UNICA.md`](../../ESPECIFICACION_OT_UNICA.md).

## Estructura

```
app/
  nucleo/         código del servidor, fuera de lo que se sirve por web
    Validacion.php    las reglas del formato único
  pruebas/
    fixture_validacion.json   los casos, compartidos con Python
    validacion_test.php       corredor PHP
  publico/        lo que va a public_html (en construcción)
```

## Las reglas viven en dos lados, y el fixture es el árbitro

`nucleo/Validacion.php` y `agentes/scripts/t2_5_validacion.py` implementan las
**mismas** reglas. Hay dos copias porque Python no corre en el hosting compartido
—hace falta root— así que la captura tiene que validar en PHP, y la ingesta y el
auditor validan en Python.

Duplicar reglas es un riesgo conocido. Lo que lo controla es que **los dos corren
el mismo fixture**:

```bash
D:\SOFTWARE\PHP83\php.exe pruebas/validacion_test.php
cd ../../agentes && .venv/Scripts/python.exe scripts/t2_5_validacion.py --fixture
```

Los dos deben decir *"Los 32 casos del fixture pasan"*. Si uno falla y el otro
no, las reglas se separaron: **el fixture manda**, se arregla el lado que
discrepa, y no se despliega nada hasta que coincidan.

> Ya sirvió: en su primera corrida detectó que la versión Python saltaba la regla
> `FECHA_FUTURA` en silencio cuando la fecha llegaba como texto en vez de como
> objeto `date`. PHP la aplicaba. Sin el fixture, el servidor y la ingesta habrían
> estado midiendo cosas distintas sin que nadie se enterara.

## Entorno local

PHP **8.3.33** en `D:\SOFTWARE\PHP83` — la misma versión que corre en Hostinger,
descargada de windows.php.net y verificada por SHA-256. Extensiones activadas:
`pdo_mysql`, `mysqli`, `mbstring`, `gd`, `openssl`, `zip`, `curl`, `intl`.

## Al actualizar en Hostinger: borra antes de extraer

El extractor del gestor de archivos de Hostinger **crea los archivos nuevos y
salta los que ya existen**, aunque «Overwrite existing files» esté marcado. Se
comprobó dos veces el 2026-09-08:

- Subida 1: se creó `usuarios.php` (nuevo) pero `nucleo/Auth.php` quedó viejo.
  `usuarios.php` llamaba a métodos que el `Auth.php` viejo no tenía → HTTP 500.
- Subida 2: mismo patrón con `panel.php`, que seguía mostrando el módulo apagado.

**Regla:** antes de extraer una actualización, BORRA del servidor los archivos
que cambiaron. Nunca `nucleo/config.php`, que tiene las credenciales y no viaja
en los paquetes.

Síntoma típico de este problema: una página nueva da 500 mientras el resto
funciona, o una pantalla sigue mostrando la versión anterior tras recargar.
