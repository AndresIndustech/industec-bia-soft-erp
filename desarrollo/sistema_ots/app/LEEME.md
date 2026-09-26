# B.IA Soft ERP — el código de la app de OT INDUSTEC

> Al día el 13 de septiembre de 2026 (T2.14.8); vocabulario y pruebas locales al día el 24 de septiembre de 2026. El sistema corre en el **sitio de pruebas** `darkviolet-armadillo-872352.hostingersite.com/ot/` sobre PHP **8.2.33** y MariaDB **11.8** de Hostinger Premium. Producción (`yellow-elephant`) sigue con los formularios viejos hasta el corte (PLAN, T2.16).

Diseño en [`DISENO_APP_OTS.md`](../../DISENO_APP_OTS.md) · formato de la orden en [`ESPECIFICACION_OT_UNICA.md`](../../ESPECIFICACION_OT_UNICA.md) · roles y módulos en [`PLAN_APP_GESTION.md`](../../PLAN_APP_GESTION.md) · lo hecho, en [`ESTADO.md`](../../ESTADO.md) §1b · las decisiones D1–D16 del pulido, en `PLAN_INDUSTEC.md` §T2.14.

## Estructura

```
app/
  lib/            composer.json: dompdf, PHPMailer, PhpSpreadsheet, PhpPresentation.
                  Se instalan en el servidor FUERA de la web (~/lib/ot); ver lib/LEEME.md
  nucleo/         lo que corre en el servidor; también hay una copia en publico/nucleo/
    Validacion.php    las reglas del formato único (la copia que manda)
  publico/        lo que se despliega a public_html/ot/ (ver publico/LEEME.md)
    nucleo/           Auth, Db, Ui, Casos, Pendientes, Novedades, Emision, Reportes,
                      Catalogo, Avisos, Reconciliar, Validacion, Vocabulario, plantilla_ot, reporte_pdf
    vocabulario.json  el diccionario único de términos (ver «El vocabulario único», abajo)
    catalogos/        los JSON que empuja la estación (403 por web; salen por catalogos.php)
    documentos/       manuales y guías subidos (403 por web; salen por documento.php)
    ordenes_pdf/, ordenes_fotos/   lo que emite la app (403 por web; salen por pdf.php)
  pruebas/        lo que se corre en el PC o la estación (ver abajo)
    servidor/         lo que se corre CONTRA el sitio de pruebas (ver servidor/LEEME.md)
  herramientas/   generar_vocabulario.php: escribe desde el JSON las constantes de estado y el respaldo de ui.js
  sql/            migraciones 001…021, se aplican con publico/aplicar_sql.php
```

## El vocabulario único

Desde el 24 de septiembre de 2026, **los mismos términos para todos los roles** (administración, jefe de zona, técnico, KFC en PDF/Excel/PPT/correo, gerencia y hojas del piloto). La fuente es `publico/vocabulario.json`; la especificación, con la tarjeta «Por zona», está en [`../VOCABULARIO.md`](../VOCABULARIO.md).

- **orden** = el trabajo que pide KFC, identificado por su **aviso SAP**. **OT INDUSTEC** = el documento que emite el técnico, de evaluación o de cierre. No cambian (son contrato): el título del PDF «ORDEN DE TRABAJO INDUSTEC», el asunto del correo, el número OT-NNNN y los campos «Estado de OT: Abierta/Cerrada» y «Estado de Equipo: Operativo/Deshabilitado».
- El código pide el texto por su **clave**: `Vocabulario::t()` / `::titulo()` / `::ayuda()` / `::deEstado()` en PHP, `UI.T()` en JS y `termino()` en Python (`agentes/scripts/comun.py`). Una clave que no existe **lanza excepción**: nunca se inventa un texto.
- `Ui::ESTADOS`, `Pendientes::ESTADOS` / `VIAS` / `VEREDICTOS_KFC`, `Novedades::ESTADOS` y el respaldo embebido de `ui.js` **no se editan a mano**: tras cambiar el JSON se corre `php herramientas/generar_vocabulario.php` y luego `--comprobar`.
- Un literal viejo que es un **dato que se compara** o un **contrato** va a `lista_negra_excepciones` del JSON, con archivo, texto y motivo; no se cambia.

## Las reglas viven en tres lados, y el fixture es el árbitro

`publico/reglas.js` (navegador) · `nucleo/Validacion.php` (servidor, **la que manda**) · `agentes/scripts/t2_5_validacion.py` (ingesta e histórico). Los tres corren `pruebas/fixture_validacion.json`:

```bash
cd publico && node reglas.fixture.mjs                 # 37/37
php pruebas/validacion_test.php                       # 37/37
cd ../../agentes && .venv/Scripts/python.exe scripts/t2_5_validacion.py --fixture   # 37/37
```

Si uno discrepa, **el fixture manda**: se arregla el lado que se separó y no se despliega hasta que coincidan. Ninguna validación de cliente reemplaza a la del servidor (I-13).

## Pruebas locales (sin servidor)

Con el PHP portable del PC (en Git Bash: `export PATH="/d/SOFTWARE/PHP83:$PATH" PHP_BIN=D:/SOFTWARE/PHP83/php.exe PYTHONUTF8=1`) o el de la estación en `PHP_BIN`. Línea base del 24 de septiembre de 2026, que ningún cambio puede empeorar:

| Prueba | Qué comprueba | Al 24-sep-2026 |
|---|---|---|
| `php pruebas/prueba_48h.php` | El reloj de 48 h, los estados de la 009, las etiquetas | 120 · 0 |
| `php pruebas/prueba_continuidad.php` | Las órdenes que continúan otra (`continua_de`) | 42 · 0 |
| `php pruebas/prueba_destinatarios.php` | A quién va cada correo de una OT INDUSTEC (`Destinatarios::resolver`) | 22 · 0 |
| `php pruebas/prueba_despacho.php` | Las dos decisiones del despacho del correo (tope por hora y clasificación), sin SMTP | 20 · 0 |
| `php pruebas/prueba_casos_prueba.php` | El catálogo de prueba del arnés solo se fusiona para quien está probando, nunca para una cuenta real | 7 · 0 |
| `php pruebas/validacion_test.php` | El fixture de validación | 37/37 |
| `php pruebas/prueba_vocabulario.php` | El diccionario: estructura, todo estado con concepto, un solo destino por nombre viejo | 148 · 0 |
| `php pruebas/prueba_panel_zona.php` | La tarjeta «Por zona» sobre una base sintética: cada cifra igual a las filas de su enlace | 70 · 0 |
| `php pruebas/prueba_lista_negra.php --informe` | Textos visibles que todavía usan una palabra retirada (sin `--informe` sale con 1 si hay alguno) | informa; la meta es 0 |
| `PHP_BIN=… node pruebas/prueba_contratos.mjs` | Los contratos entre pantallas y JS (clases CSS usadas contra definidas, `data-*`, extremos) | 57 · 0 |
| `node pruebas/prueba_graficos.mjs` | Los SVG de reportes con datos raros | 62 · 0 |
| `node pruebas/prueba_barra_tecnico.mjs` | La barra del técnico: la misma en todas las pantallas y con los rótulos del diccionario | OK |
| `PHP_BIN=… node pruebas/prueba_offline.mjs` | La app sin servidor (PWA) | falla desde antes del 24-sep (conocido) |
| `php -l` sobre `publico/` | Sintaxis | 67/67 |

## Pruebas contra el sitio de pruebas

En `pruebas/servidor/`, con `INDUSTEC_LLAVE_SSH` y `PYTHONUTF8=1`: `verificar_http` (78), `verificar_bandeja` (37), `verificar_ciclo` (48), `verificar_emision` (34), `verificar_archivo` (50), `verificar_reportes` (36), `verificar_seguridad` (28): **311 comprobaciones**, más las de `equipos.php` de T2.14.8. Requieren `preparar_prueba.php` corrido en el servidor y se deshacen con `deshacer_prueba.php`. `capturar_pantallas.mjs` toma las capturas por rol (y con `--piloto --recorte --anonimizar`, las de las hojas del piloto; las que hay hoy en `piloto/capturas/` son del 13-sep-2026 y muestran los rótulos anteriores al vocabulario único, así que hay que volver a tomarlas). Detalle en [`pruebas/servidor/LEEME.md`](pruebas/servidor/LEEME.md).

## Cómo se despliega

Solo por SSH con `desarrollo/agentes/scripts/t2_10_desplegar.py <archivos>` (lista blanca `ARCHIVOS`, verifica por hash y contra lo que sirve la web). El administrador de archivos de Hostinger **no sobreescribe** y ya causó dos fallos: no se usa. Los CLI (`emitir_pendientes_cli.php`, `despachar_correo_cli.php`, `archivo_indexar_cli.php`, `cronograma_importar_cli.php`, `purgar_cli.php`) y los `.sql` van por `scp`; las migraciones se aplican con `php aplicar_sql.php sql/<archivo>` y se comprueban con `php verificar_esquema.php`. `nucleo/config.php` del servidor **no se toca nunca**. Acceso y reglas en [`../LEEME_ACCESO_HOSTINGER.md`](../LEEME_ACCESO_HOSTINGER.md).

## Lo que no está en git a propósito

`publico/nucleo/config.php` (credenciales), `publico/nucleo/padron.json` (nombres del personal), `publico/catalogos/*.json` salvo tres de ejemplo, `publico/ordenes_pdf/`, `publico/ordenes_fotos/`, `publico/documentos/` (datos del cliente).
