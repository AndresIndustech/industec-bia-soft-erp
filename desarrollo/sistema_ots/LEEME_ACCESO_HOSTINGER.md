# Acceso a Hostinger: lo vigente para el sitio de pruebas, y lo diferido al corte

> Reescrito el 13 de septiembre de 2026 (T2.14.8). Antes era una guía de ocho pasos escrita el 6 de septiembre, cuando SSH no existía y el sistema nuevo no estaba desplegado; describía como pendiente lo ya hecho y como inmediato lo que se difirió a producción. Ahora tiene **dos partes que no se mezclan**: la **A**, lo que se usa hoy contra el sitio de pruebas, y la **B**, lo que solo se hace con el corte y con la autorización de Andrés en el momento.

**Cuenta:** `u671729428` · **SSH:** `82.25.73.181`, puerto **65002** (no el 22) · **Producción** (formularios viejos): `yellow-elephant-166233.hostingersite.com`, carpeta `ot/produccion/` (**solo lectura**, regla 9 del `CLAUDE.md`) · **Sitio de pruebas** (el sistema nuevo y la web corporativa): `darkviolet-armadillo-872352.hostingersite.com`, carpeta `public_html/ot/` · PHP **8.2.33**, MariaDB 11.8, Hostinger Premium.

---

# Parte A · Lo vigente: el sitio de pruebas

## A.1 Las llaves, una por equipo

SSH está habilitado desde el 9 de septiembre de 2026 con **una llave por equipo**, registradas en hPanel → Avanzado → Acceso SSH → Claves SSH:

| Equipo | Llave privada | Nombre en hPanel |
|---|---|---|
| La estación de INDUSTEC | `desarrollo\agentes\config\clave_hostinger` (fuera de git) | «industec» |
| El PC de Andrés | `C:\Users\andre\.ssh\industec_hostinger_pc` | «PC Andres» |

Los scripts de la estación las resuelven solos (`hostinger_ssh.py`: `INDUSTEC_LLAVE_SSH` en el entorno o `config/clave_hostinger`); las pruebas y el desplegador, con `INDUSTEC_LLAVE_SSH`. **La privada no se copia a otro equipo ni entra a git.** Si se necesita un tercer equipo, se genera otro par y se registra otra llave; no se comparte una.

**Comprobación** (desde cualquiera de los dos):

```bash
ssh -i <llave> -p 65002 -o BatchMode=yes u671729428@82.25.73.181 "pwd && php -v | head -1"
```

Debe imprimir el home y `PHP 8.2.33`. Desde la estación: `.venv\Scripts\python.exe scripts\hostinger_ssh.py --probar`.

`crontab` no existe por SSH: los cron se ven y se programan en hPanel → Avanzado → Cron Jobs (hoy no hay ninguno del sistema nuevo).

## A.2 Qué hay en el servidor y qué no se toca

```
~/                                        el home; nada de aquí se sirve por web
  respaldos/                              volcados, herramientas de prueba, claves_prueba.json (0600)
  lib/ot/vendor/                          dompdf, PHPMailer, PhpSpreadsheet, PhpPresentation (composer)
  domains/darkviolet-…/public_html/       la web corporativa (raíz) y…
    ot/                                   el sistema nuevo (lo que hay en app/publico/)
      nucleo/config.php                   credenciales y secretos: INTOCABLE, no viaja en despliegues
      catalogos/, ordenes_pdf/, ordenes_fotos/, documentos/   datos: 403 por web, salen por PHP
  domains/yellow-elephant-…/public_html/ot/produccion/        el sistema viejo: SOLO LECTURA
```

Reglas que hacen cumplir las herramientas: el desplegador aborta si el destino nombra `yellow-elephant`; `hostinger_ssh.py` rechaza cualquier escritura cuyo guion mencione el docroot de producción; nada lee ni pega el contenido de `config.php`; ningún correo sale de darkviolet (`emision_modo = PRUEBA`, cola `RETENIDO`).

## A.3 Desplegar código

```bash
cd desarrollo/agentes
set INDUSTEC_LLAVE_SSH=C:\Users\andre\.ssh\industec_hostinger_pc   # o la de la estación
set INDUSTEC_SSH_USER=u671729428
set PYTHONUTF8=1
.venv/Scripts/python.exe scripts/t2_10_desplegar.py panel.php nucleo/Ui.php   # archivos concretos
.venv/Scripts/python.exe scripts/t2_10_desplegar.py --todo                    # la lista blanca entera
```

Sube por SFTP, **verifica por hash** cada archivo en el disco del servidor y comprueba lo que la web entrega. Solo suben los archivos de la lista blanca `ARCHIVOS`; lo que no está ahí (CLI, `.sql`, herramientas de prueba) va por `scp`:

```bash
scp -P 65002 -i <llave> app/sql/010_x.sql u671729428@82.25.73.181:domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot/sql/
scp -P 65002 -i <llave> app/pruebas/servidor/*.php u671729428@82.25.73.181:respaldos/
```

Si cambió algo de la app del técnico (`index.html`, `app.js`, `estilo.css`, `cola.js`…), **sube `VERSION` en `sw.js`**: sin eso el celular sigue sirviendo el armazón viejo desde su caché.

## A.4 Migraciones

```bash
ssh … "cd domains/darkviolet-…/public_html/ot && php aplicar_sql.php sql/010_sesiones_sin_sesion.sql && php verificar_esquema.php"
```

`aplicar_sql.php` corre el archivo sentencia a sentencia y lo anota en `migraciones`; `verificar_esquema.php` comprueba bloque por bloque (007, 008, 009, 010) y termina en TODO OK. Antes de una migración que cambie el esquema: **volcado previo** (`t2_4_volcado_bd.py` desde la estación, o `mysqldump` por SSH a `~/respaldos/`). Las aplicadas al 13 de septiembre de 2026: 001 a 010 en darkviolet.

## A.5 Probar contra el servidor

`app/pruebas/servidor/LEEME.md`: `preparar_prueba.php` (en el servidor), las siete baterías `verificar_*.py` (desde el PC o la estación; 311 comprobaciones el 13 de septiembre), `capturar_pantallas.mjs`, y `deshacer_prueba.php` al terminar. Las claves de las cuentas de prueba viven solo en `~/respaldos/claves_prueba.json` y los scripts las leen por SSH.

## A.6 Lo que la estación hace cada noche

`t2_4_sync_hostinger.py` espeja el sistema viejo (solo lectura) y `ordenes_pdf/`, `ordenes_fotos/` del sitio de pruebas; `t2_4_volcado_bd.py` baja el volcado verificado de la base; `t2_15_exportar_archivo.py --empujar` publica el catálogo histórico en el Archivo. Todo dentro de `saneamiento_nocturno.py` (`desarrollo/agentes/scripts/SANEAMIENTO.md`). La **purga** del servidor no está en la cadena y exige dos copias y autorización del momento.

## A.7 Comprobación rápida de que todo está sano (2 minutos)

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://darkviolet-armadillo-872352.hostingersite.com/ot/login.php            # 200
curl -s -o /dev/null -w "%{http_code}\n" https://darkviolet-armadillo-872352.hostingersite.com/ot/catalogos/locales.json # 403
curl -s -o /dev/null -w "%{http_code}\n" https://darkviolet-armadillo-872352.hostingersite.com/ot/nucleo/config.php    # 403
ssh … "cd domains/darkviolet-…/public_html/ot && php verificar_esquema.php | tail -1"                                    # TODO OK
```

---

# Parte B · Diferido al corte (T2.16): producción se toca una sola vez, en bloque

Decisión del 10 de septiembre de 2026: **`yellow-elephant` no se modifica durante el piloto**. Lo de abajo estaba en la guía anterior como pasos 6 y 7 «urgentes»; se hace **con el corte**, con respaldo previo del sitio y de la base, y cada paso con autorización de Andrés en el momento. El orden y los caminos de vuelta están en `PLAN_INDUSTEC.md` §T2.16.

| # | Qué | Por qué se difirió | Cómo se verifica |
|---|---|---|---|
| B.1 | `.htaccess` en `uploads/`, `registros/` y `contadores/` de los cinco módulos viejos (`parches/uploads.htaccess`) | El acceso web a los PDF ya quedó **cerrado el 8 de septiembre** con el `.htaccess` de `ot/produccion/`; lo que falta es el cierre por carpeta, que es cosmético mientras el general esté | `curl -I …/uploads/` → 403 |
| B.2 | Borrar `phpinfo.php` y `debug_firma.txt` de `ot_mantenimiento` | Publican rutas y una firma; tocan producción | 404 en ambos |
| B.3 | `display_errors = 0` en los cinco `config.php` | Toca producción | Un warning provocado no muestra rutas |
| B.4 | `guardas.php` junto a cada `submit.php` (`parches/guardas.php`): rechaza GET, valida la zona | Toca la lógica del formulario viejo en pleno uso | GET → 405; POST con `zona=../x` → 400; el contador no sube |
| B.5 | **Rotar la contraseña SMTP** de `reclutamiento@industec.me`, que está en texto plano en los cinco `config.php`, y moverla a `~/secretos.ini` leído con `parse_ini_file()` | Cambia el correo que firma las órdenes a KFC: se hace con el envío de prueba del corte (T2.16 paso 3) | Un envío de prueba llega con la clave nueva |
| B.6 | Purga de PDF del servidor viejo (`t2_4_purga_hostinger.py --ejecutar --confirmo-borrado`) | Exige la segunda copia (`SEGUNDA_COPIA` en `.env`, el equipo Veeam/TrueNAS) que aún no existe; sin ella solo informa | «0 borrables, motivo: sin segunda copia» hoy; con la copia, las cinco compuertas por archivo |
| B.7 | PDF y fotos del sistema nuevo **fuera del docroot** (D12) | Cambia rutas del servidor en medio del piloto | `pdf.php` sigue sirviendo; la ruta directa da 404 |
| B.8 | Segundo usuario MySQL sin `DROP/ALTER` para la web | Cambia `config.php`, que es intocable durante el piloto | La app funciona con el usuario nuevo; `DROP` rechazado |
| B.9 | Cron de hPanel: reemisor cada 10 min, despachador de correo cada 5, `archivo_indexar_cli.php` cada hora, `purgar_cli.php` semanal | Mientras `emision_modo = PRUEBA` no hace falta; el reemisor se dispara de forma oportunista al recibir | hPanel muestra los cuatro; la cola vacía a los 5 min |
| B.10 | `emision_modo = PRODUCCION`, destinatarios reales (`correo_fijos`, `correo_por_zona`), `enlace_secreto` propio | Es el interruptor del corte | Un envío a un buzón interno antes de habilitar a los locales |
| B.11 | Sembrar los correlativos reales con `t2_14_sembrar_correlativos.py --ejecutar` (sistema viejo detenido) | Sembrar con el viejo emitiendo genera duplicados; el script aborta si el contador se mueve entre dos lecturas | El acta en `SALIDAS IA\OTS\correlativos_sembrados_*.json`; la primera orden nueva continúa la numeración |
| B.12 | Dominio definitivo y retiro de los formularios viejos al día 8 sin envíos | Es el final del corte | El formulario viejo se conserva 30 días sin enlace |

Lo que ya **no** está pendiente de esta guía: habilitar SSH (hecho), generar llaves (hechas), las variables `HOSTINGER_*` en `.env` (sustituidas por `hostinger_ssh.py`), la primera descarga completa (el espejo corre cada noche), revisar `cleanup.php` (borrado el 8 de septiembre; no había cron), y el cierre general del acceso web a los PDF (hecho el 8 de septiembre).
