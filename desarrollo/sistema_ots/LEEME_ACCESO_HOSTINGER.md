# Acceso a Hostinger y despliegue de los parches

Guía operativa para Andrés. Cada paso trae **cómo se verifica que salió bien** —
no "revisar que esté bien", sino una comprobación con respuesta objetiva.

**Cuenta:** `u671729428` · **Sistema de OTs en producción:** `yellow-elephant-166233.hostingersite.com`
(carpeta `ot/produccion/`; no se toca, regla 9) · **Sitio de pruebas del sistema nuevo:**
`darkviolet-armadillo-872352.hostingersite.com` (`darkorchid` se descartó el 2026-09-08)

> **Actualizado el 2026-09-10.** SSH ya está habilitado y en uso: `u671729428@82.25.73.181`,
> puerto 65002, con una llave por equipo (la de la estación es `desarrollo\agentes\config\clave_hostinger`).
> No hace falta generar otra en la estación: los pasos 1 y 2 quedan como referencia. `crontab`
> no existe por SSH: los cron se revisan en hPanel → Avanzado → Cron Jobs. Donde los pasos 7.x
> dicen `ot/pruebas/`, hoy es `ot/produccion/`. Los pasos 6 y 7 tocan producción: los ejecuta
> Andrés. El servidor corre PHP 8.2.33.

---

## Paso 0 · Comprobar que el pipeline está sano (30 segundos, sin tocar nada)

```bash
cd "D:\INDUSTECH IA\desarrollogentes"
.venv/Scripts/python.exe scripts/t2_4_pruebas.py
```

Trece comprobaciones sobre los puntos donde esto se rompería en silencio: nombres
con espacios, archivos sin hash, líneas de error coladas como archivos, y la
compuerta que impide borrar algo sin copia local. **No toca Hostinger ni escribe
en la base.** Si alguna falla, no sigas.

---

## Paso 1 · Habilitar SSH (5 minutos, en hPanel)

SSH viene **desactivado por defecto**. Sin él no hay descarga automática ni purga verificada.

1. hPanel → el sitio → **Avanzado → Acceso SSH** → activar.
2. Anota lo que muestra: **IP del servidor**, **puerto** (Hostinger usa `65002`,
   no el 22) y **usuario** (`u671729428`).
3. En la misma página, pestaña **Claves SSH** → *Añadir nueva clave*.

En la estación, generar el par de claves (la privada nunca sale de este PC):

```bash
ssh-keygen -t ed25519 -f "$HOME/.ssh/industec_hostinger" -C "estacion-industec" -N ""
cat "$HOME/.ssh/industec_hostinger.pub"
```

Pega el contenido de `.pub` en hPanel. **La privada no se sube a ningún lado ni
entra a git.**

**Verificación:** este comando debe imprimir la ruta del home y nada más.

```bash
ssh -i ~/.ssh/industec_hostinger -p 65002 -o BatchMode=yes u671729428@<IP> "pwd && sha256sum --version | head -1"
```

Si pide contraseña, la clave no quedó registrada. Si responde `pwd` y la versión
de `sha256sum`, está listo — y de paso queda confirmado que el servidor tiene la
herramienta con la que se verifican los hashes.

---

## Paso 2 · Guardar las credenciales en `config/.env`

Añade estas cinco líneas al final de `D:\INDUSTECH IA\desarrollo\agentes\config\.env`
(ese archivo está fuera de git por `.gitignore`; verifícalo con `git check-ignore -v`):

```ini
HOSTINGER_USER=u671729428
HOSTINGER_HOST=<la IP que muestra hPanel>
HOSTINGER_PORT=65002
HOSTINGER_DOCROOT=/home/u671729428/domains/yellow-elephant-166233.hostingersite.com/public_html
HOSTINGER_SSH_KEY=C:/Users/indus/.ssh/industec_hostinger
```

**Verificación:**

```bash
cd "D:\INDUSTECH IA\desarrollo\agentes"
.venv/Scripts/python.exe scripts/t2_4_sync_hostinger.py --inventario
```

Debe listar los 5 módulos con su conteo de PDFs. **No descarga nada.** Si un
módulo da error de ruta, la que está mal es `HOSTINGER_DOCROOT`.

---

## Paso 3 · Antes que nada: revisar el cron

Esta es la comprobación más urgente de todas. `otras_funcionalidades/cleanup.php`
borra **todo** `uploads/` y `registros/` sin filtro de antigüedad y sin verificar
que exista copia. Si estuviera en un cron, puede dispararse esta noche.

```bash
ssh -i ~/.ssh/industec_hostinger -p 65002 u671729428@<IP> "crontab -l"
```

Y en hPanel → **Avanzado → Cron Jobs**.

- **Si `cleanup.php` NO aparece:** perfecto, no hay urgencia. Igual conviene
  **borrar el archivo** para que nadie lo programe por error más adelante.
- **Si aparece:** desactívalo **hoy**, antes de la primera sincronización.
  El plan Premium solo trae respaldo **semanal** (el diario es un add-on de pago),
  así que un disparo equivocado se lleva hasta 7 días de OTs sin red debajo.

---

## Paso 4 · Primera descarga completa

```bash
cd "D:\INDUSTECH IA\desarrollo\agentes"
.venv/Scripts/python.exe scripts/t2_4_sync_hostinger.py
```

Baja los ~1.952 PDFs (~1,41 GB) a `D:\RESPALDOS\_ORIGEN_SISTEMA\`, verificando
cada uno por SHA-256 contra el hash que calcula el propio servidor. Un archivo
que no cuadra no entra al espejo: va a `_cuarentena_hash\`.

**Verificación — las tres cifras deben cuadrar:**

```bash
.venv/Scripts/python.exe scripts/t2_4_sync_hostinger.py --inventario
```

En la segunda corrida, `bajados` debe dar **0** y `ya verificados` debe igualar
al conteo remoto. Si no, algo falló y **la purga no debe correrse**.

---

## Paso 5 · Promover al árbol canónico y meterlo a la base

```bash
.venv/Scripts/python.exe scripts/t2_4_normalizar_nuevas.py            # simula
.venv/Scripts/python.exe scripts/t2_4_normalizar_nuevas.py --ejecutar # copia
.venv/Scripts/python.exe scripts/t1_7_ingesta.py                      # a la base
```

Probado en seco contra los 1.952 nombres reales: **resuelve el 98,4%**.
Los 31 que no, con motivo escrito en `SALIDAS IA\OTS\NORMALIZACION_*.csv`:

| No resuelto | Cuántos | Por qué |
|---|---|---|
| Envíos con POST vacío | 22 | No son órdenes de trabajo: son los `OT-0023---.pdf` |
| Módulo `ot_normal_otros` | 6 | No captura zona ni aviso; hay que decidir si entra a la base |
| Local ilegible | 3 | `RestauranteElvita`, `MENESTRASDELNEGRO`, `kh073` — decide una persona |

---

## Paso 6 · Purga nocturna (solo con autorización del momento)

```bash
.venv/Scripts/python.exe scripts/t2_4_purga_hostinger.py    # SIMULA, no borra
```

Lee el informe. Solo entonces, y solo si lo autorizas en ese momento:

```bash
.venv/Scripts/python.exe scripts/t2_4_purga_hostinger.py --ejecutar --confirmo-borrado
```

Un archivo se borra únicamente si pasa **cuatro compuertas**: existe copia
local, el SHA-256 recalculado coincide, tiene más de 30 días, y el propio
servidor confirma el hash en el instante del borrado. Cualquiera que falle, se
queda. Tope de 400 archivos por corrida como freno ante un bug.

> **El argumento no es el espacio.** Son 1,41 GB de 20 GB y ~2.000 archivos de
> 400.000 inodos: por espacio no hace falta purgar nada. Las razones reales, que
> sí se sostienen, son que los PDFs están públicos con datos personales y firmas
> de empleados de KFC, y que el contrato de hosting prohíbe usar el servicio
> como repositorio de archivos.

---

## Paso 7 · Parches de seguridad al sistema actual

Ninguno cambia lo que ve o hace el técnico. Despliega **primero solo en UIO**,
verifica 48 h, y recién entonces al resto.

### 7.1 Bloquear el acceso web a los PDFs — *lo más urgente*

Sube `parches/uploads.htaccess` **renombrado a `.htaccess`** a cada
`uploads/`, `registros/` y `contadores/` de los 5 módulos.

**Verificación:** antes debe dar `200`, después `403`.

```bash
curl -s -o /dev/null -w "%{http_code}\n" \
  https://yellow-elephant-166233.hostingersite.com/ot/pruebas/ot_normal_v3/uio/uploads/
```

Y una OT concreta también debe pasar a `403`. Que el técnico envíe una orden de
prueba y le llegue el correo con el PDF adjunto: eso confirma que no se rompió nada.

### 7.2 Borrar lo que sobra

```bash
ssh -i ~/.ssh/industec_hostinger -p 65002 u671729428@<IP> \
  "cd <DOCROOT>/ot && rm -f produccion/ot_mantenimiento/phpinfo.php produccion/ot_mantenimiento/debug_firma.txt"
```

`phpinfo.php` publica rutas absolutas, versión de PHP y variables del servidor
—es el primer archivo que busca cualquier escáner—. `debug_firma.txt` es una
firma manuscrita capturada, volcada a un `.txt` público.

### 7.3 Apagar `display_errors`

En los 5 `config.php`, cambiar `ini_set('display_errors', 1);` por `0`.
Dejar `log_errors` en `1`. Hoy filtra rutas absolutas a cualquiera que provoque
un warning.

### 7.4 Enganchar `guardas.php`

Sube `parches/guardas.php` junto a cada `submit.php`. En `submit.php`, dos cambios:

```php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/guardas.php';        // <-- añadir esta línea
```

```php
$zona = $campos['zona'] ?? '';                // <-- reemplazar por:
$zona = zona_valida($campos['zona'] ?? '');
```

> En `ot_normal_otros` **no** se aplica el segundo cambio: ese formulario no
> captura zona.

**Verificación:**

| Prueba | Resultado esperado |
|---|---|
| `curl -I .../uio/submit.php` (un GET) | `405`, y el contador **no** sube |
| Enviar una OT normal desde el formulario | Igual que siempre: PDF y correo |
| `curl -X POST -d "zona=../../x" .../submit.php` | `400`, ningún archivo escrito |

Antes y después de las pruebas, comparar el contador — solo debe haber subido
por el envío legítimo:

```bash
ssh ... "cat <DOCROOT>/ot/pruebas/ot_normal_v3/uio/contadores/counter_UIO.txt"
```

### 7.5 Rotar la contraseña SMTP

La contraseña de `reclutamiento@industec.me` está **en texto plano en 5
copias de `config.php` dentro de `public_html`**, y también en la copia local en
`ENTRADAS IA`. Es la cuenta que firma todos los correos hacia Grupo KFC.

Cámbiala en Titan, y guarda la nueva **fuera de `public_html`**, en
`/home/u671729428/secretos.ini`, leído desde `config.php` con `parse_ini_file()`.
Hostinger permite leer fuera del docroot; lo que no permite es servirlo por web.

---

## Paso 8 · Automatizar

Programador de tareas de Windows, en la estación (no en Hostinger: allí no hay
Python, solo VPS lo tiene).

| Hora Ecuador | Qué corre | Por qué a esa hora |
|---|---|---|
| 21:30 | `t2_4_sync_hostinger.py` | Terminada la jornada de campo |
| 21:50 | `t2_4_normalizar_nuevas.py --ejecutar` | Con el espejo ya verificado |
| 22:00 | `t1_7_ingesta.py` | Ordenes nuevas en la base para el plan del día siguiente |
| 02:00 | `t2_4_purga_hostinger.py` (**sin** `--ejecutar`) | Deja el informe listo para revisar |

La purga **real** no se automatiza mientras no lleve varias semanas de informes
limpios. Aprobar un plan no autoriza ejecutar un borrado.

> Si algún día se programa desde el cron de Hostinger, recuerda que corre en
> **UTC**: las 02:00 de Ecuador son las `0 7 * * *`.
