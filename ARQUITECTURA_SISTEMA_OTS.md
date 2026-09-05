# Arquitectura del sistema de OTs — decisión y plan

**Fecha:** 2026-09-05 · **Decide:** Andrés Basantes (INDUSTECH) · **Estado:** vigente
**Reemplaza a:** nada. Complementa `PLAN_INDUSTEC.md` §T2.1 con lo que se descubrió al auditar el sistema en producción.

---

## 1. La decisión, en una frase

**Híbrido con un dueño único por dato:** la captura vive en Hostinger porque
tiene que estar disponible 24/7 para técnicos en campo; el cerebro, el archivo
definitivo y los reportes viven en la estación porque ahí está Python, MariaDB,
328 GB de disco y todo lo que ya se construyó en la Fase 1. **Costo incremental:
US$ 0.**

### Por qué no todo en Hostinger

**Python no existe en el hosting compartido, y no hay atajo.** No es una
limitación de configuración: hace falta root, y el compartido no lo da.

> «Python is supported exclusively on VPS Hosting» — soporte de Hostinger, act. 2026-08-03
> «Installing any compilers, compiling scripts or running compiled programs is not supported» — act. 2026-08-10

Todo el trabajo de la Fase 1 —extracción de PDFs, ingesta, el auditor de
calidad, el consolidador— es Python. Moverlo a Hostinger significa reescribirlo
todo en PHP o pagar un VPS (KVM 1: US$ 6,49/mes promo, US$ 11,99 al renovar).
Ninguna de las dos cumple la premisa.

Se suma que el plan Premium **no incluye Node.js**, aunque la página de precios
diga lo contrario. La fuente buena es la tabla técnica: *Web Premium → Node.js
websites: Not available*. Para decisiones de arquitectura no se le hace caso a
la página comercial.

### Por qué no todo en local

Los técnicos envían órdenes desde el celular, en locales de tres provincias, a
cualquier hora. Un PC doméstico en Ecuador —con sus cortes de luz, su internet
residencial y su costumbre de apagarse— no es donde va un servicio del que
depende que Grupo KFC reciba sus órdenes. Hostinger ya está pagado y ya cumple
ese rol hoy.

### Lo que decide cada frontera

| | Hostinger | Estación |
|---|---|---|
| Disponible 24/7 sin que nadie encienda nada | ✅ | ❌ |
| Corre Python | ❌ | ✅ |
| Aguanta consultas analíticas | ❌ (corte duro a los 60 s) | ✅ |
| Archivo de años de PDFs | ❌ (el contrato lo prohíbe) | ✅ 328 GB |
| Respaldo | Semanal (el diario se paga) | Nuestro, diario y verificado por hash |
| Costo incremental | US$ 0 (ya pagado) | US$ 0 (ya comprado) |

---

## 2. Qué encontramos y cambia el plan

Auditoría de los 12 archivos de código propio del sistema en producción y de los
1.952 PDFs de la copia local del 2026-09-03.

| Hallazgo | Evidencia | Qué obliga |
|---|---|---|
| **El sistema no escribe en ninguna base.** El único registro de una OT es el PDF y el correo | Cero sentencias SQL en todo el código | La persistencia (T2.1.1) es lo más urgente, por encima del borrado nocturno |
| **El 82% de los correlativos ya no existe** en el servidor | Contadores en 1820/2220/2423, sobreviven 399/598/742 archivos | Cada día sin espejo local se pierden ~20 OTs solas |
| **Los PDFs son públicos y el nombre es adivinable** | Sin `.htaccess` ni `index.php` en ningún `uploads/`. Contienen nombre, correo y **firma manuscrita** de administradores de KFC | Bloquear el acceso web es lo primero que se despliega |
| **Un GET a `submit.php` genera una OT en blanco y la envía a KFC** | Contadores de zona vacía: 27+31+14+15 = **87 envíos**. 58 llegaron al Jefe Técnico de KFC | `guardas.php` rechaza lo que no es POST |
| **Escritura de archivos fuera del directorio** vía el campo `zona` | `zona` es el único campo sin `preg_replace`; se concatena en `counter_{$zona}.txt` y en el nombre del PDF | Lista blanca `UIO/LARB/CNLJ` |
| **Contraseña SMTP en texto plano, 5 copias dentro de `public_html`** | `config.php:23-27` | Rotarla y sacarla del docroot |
| **`phpinfo.php` público** y `display_errors=1` en producción | `ot_mantenimiento/phpinfo.php` | Borrar y apagar |
| **Contador sin bloqueo:** dos envíos simultáneos comparten correlativo | `file_get_contents` → `++` → `file_put_contents`, sin `flock` | Se resuelve con la misma transacción que introduce T2.1.1 |
| **158 grafías distintas de local en 3 meses**, solo 26 con sufijo `EC` | `<input type="text" name="local">` | Confirma T2.1.2 con datos: el selector no es cosmético |
| **El sistema "de producción" vive bajo `/ot/pruebas/`** | Rutas en `error_normal.log` | El staging nuevo permite dejar de trabajar sobre producción |
| **`cleanup.php` borra `uploads` y los logs sin filtro ni verificación** | `glob()` + `unlink()`, sin mtime ni hash | No se usa. Ver §5 |

### La clave `(zona, módulo, correlativo)` no es única, y eso cambia T2.1.5

Al preparar la migración se verificó contra los datos, no contra el diseño. La
clave compuesta **no se puede forzar**:

- **9 pares de órdenes distintas** comparten `(zona, módulo, correlativo)` —
  `OT-0023-K170EC-10279789-UIO` y `OT-0023-T050EC-UIO`, entre otras. Son
  documentos legítimos: es la huella de la condición de carrera del contador.
- **El correlativo se solapa entre años.** CNLJ 2025 llega a 1225 y CNLJ 2026
  arranca en 622.
- **El contador de UIO está en 1820, pero la base ya tiene 4 órdenes UIO entre
  1821 y 2064.** Cuando el contador vuelva a pasar por ahí, colisionan.

La clave de negocio real ya está declarada y ya es única: `id_industec`, la
PRIMARY KEY, exactamente como dice `PLAN_INDUSTEC.md` §6.4. El invariante I-9 se
cumple por ahí. La compuesta pasa a índice normal, y detectar colisiones queda
como trabajo del auditor de calidad — porque una colisión es información
operativa real: significa que el contador se reinició o hubo una carrera.

**Y la semilla de `correlativos` no puede salir de `MAX(correlativo)`:** 347
filas llevan correlativos sintéticos en el rango 90000+, puestos por el
saneamiento a documentos cuyo número no se pudo leer. Sembrar con `MAX` daría
90056 y la próxima orden saldría como `OT-90057`. La única fuente válida es el
contador del servidor.

### Un hallazgo que no es un error

`K073EC`/`H015EC`, `K099EC`/`H032EC`, `K146EC`/`H052EC`, `K069EC`/`H014EC`
parecían discrepancias entre el nombre del archivo y el aviso SAP. **Son pares
de marcas en el mismo sitio físico:** el KFC y la Heladería de Miraflores Cuenca
comparten local y nombre en el maestro. El técnico nombra el sitio; SAP registra
la marca cuyo equipo falló. Los dos tienen razón.

Tratarlo como error mandaba **60 órdenes correctas** a revisión manual. Con el
criterio corregido —el interior del PDF manda sobre SAP, decisión 6 del
proyecto— la resolución automática sube de **95,3% a 98,4%**.

---

## 3. Cómo fluye un dato

Un técnico en Cuenca envía una OT a las 15:00:

```
15:00  Formulario en Hostinger
       -> valida local contra el catálogo (no texto libre)
       -> valida aviso: 8 dígitos, contra avisos conocidos
       -> reserva correlativo con INSERT ... ON DUPLICATE KEY (atómico)
       -> escribe la fila en MySQL de Hostinger      [la OT ya existe como dato]
       -> genera el PDF y lo envía por correo         [igual que hoy]

21:30  La estación baja lo nuevo por SFTP y verifica cada PDF por SHA-256
       contra el hash que calcula el propio servidor
21:50  Normaliza el nombre al canónico y lo copia al árbol de D:\RESPALDOS
22:00  Ingesta a MariaDB local: la OT queda cruzada con SAP, con su local
       canónico, su equipo y su técnico
22:10  Se recalculan los planes diarios y se publican en SALIDAS IA\OTS

02:00  Informe de purga (no borra: deja el informe para revisar)
```

### Quién es dueño de qué — la regla que evita conflictos

Cada tabla tiene **un solo escritor**. Sin esto, dos bases sincronizándose
terminan siempre en conflictos que hay que resolver a mano.

| Dato | Dueño | Dirección |
|---|---|---|
| `locales`, `tecnicos`, `equipos`, `repuestos` | **MariaDB local** (el maestro de la Fase 1) | Sube a Hostinger como catálogo de solo lectura |
| Órdenes nuevas del formulario | **MySQL de Hostinger** en el momento del envío | Baja a la estación cada noche |
| Histórico, cruces con SAP, calidad, reportes | **MariaDB local** | No sube |
| PDFs | Hostinger los genera · **la estación los archiva** | Bajan, y a los 30 días se purgan del servidor |

**`estatus_general` de SAP sigue siendo el único criterio de "cerrado".** Ninguna
señal interna del sistema lo reemplaza.

---

## 4. Con qué se construye

Todo libre o ya pagado. **Costo incremental: US$ 0.**

| Pieza | Dónde | Tecnología | Por qué esa |
|---|---|---|---|
| Captura de OTs | Hostinger | **PHP 8.3 + PDO**, sin framework | Es lo único que corre ahí. Sin framework porque el código lo mantiene un agente: tiene que caber entero en una lectura |
| Base operativa | Hostinger | MySQL | 3 GB por base; sobra para 7.069 OTs |
| Base maestra y análisis | Estación | **MariaDB 11** | Ya poblada con 7.069 órdenes, 9.071 equipos, 6.450 avisos |
| Sincronización | Estación | **Python 3.12 + OpenSSH** | Ya instalados. `sftp -b`: una conexión por lote, no una por archivo |
| Interfaz de revisión | Estación | **FastAPI + HTMX**, en `localhost` | Sin build, sin npm, sin node_modules. Un agente lo mantiene sin cadena de compilación |
| Reportes a KFC | Estación | **openpyxl** sobre las plantillas reales | Ya probado: conserva estilos, formato condicional y validaciones |
| Consulta del gerente | Estación | **python-telegram-bot** | Ya instalado. Gratis, sin IP fija, admite adjuntar PDFs |

**Lo que se descarta y por qué:** Node.js (Premium no lo trae), un framework PHP
(inodos y complejidad sin retorno), Remote MySQL abierto a `%` (§6), un VPS
(US$ 6,49–11,99/mes contra la premisa de costo cero), y React o cualquier cosa
con `npm build` (una cadena de compilación que mantener a cambio de nada).

### El sitio de pruebas

`darkorchid-crane-387868.hostingersite.com` trae un **WordPress 7.1 vacío** que
el autoinstalador de Hostinger puso solo. Antes de montar nada encima:

1. **Desinstalarlo.** Son ~2.000 archivos que consumen inodos y superficie de
   ataque, y no sirven para una app de OTs.
2. Su REST API publica el usuario administrador
   (`GET /wp-json/wp/v2/users` devuelve `slurmfood@gmail.com`) y tiene
   `wp-login.php` y `xmlrpc.php` abiertos. Es la mitad de un login regalada.

Con el sitio limpio, T2.1 deja de significar "tocar el sistema que usan los
técnicos" y pasa a ser "construir al lado y migrar cuando esté probado" — que es
lo que ya exigía el protocolo de despliegue.

---

## 5. Sobre el borrado nocturno

Se hace, pero **no por el motivo que parecía**.

Por espacio no hace falta: son **1,41 GB de 20 GB** y ~2.000 archivos de
**400.000 inodos**. Se está usando el 7% del disco y el 0,5% de los inodos. Si
se le presenta a INDUSTEC el argumento del espacio, no resiste una revisión.

Las razones que sí se sostienen son dos:

1. **Exposición.** Los PDFs son públicos, el nombre es adivinable, y contienen
   datos personales y firmas manuscritas de empleados de Grupo KFC. Cada día que
   un PDF sobra en el servidor es un día de exposición innecesaria.
2. **Contrato.** El acuerdo de hosting (act. 2026-08-28) prohíbe usar el servicio
   como *«a repository or storage for files»* y para *«backups of content from
   another computer»*. Los 1,41 GB acumulados son exactamente eso. Purgar pone
   la cuenta en regla.

**`cleanup.php` no se usa.** Hace `glob()` + `unlink()` sobre todo `uploads/` y
`registros/`, sin filtro de antigüedad, sin verificar que exista copia, y sin
mirar si el `unlink` funcionó. Sobre el estado medido habría borrado 1.952 PDFs
y 838 KB de logs. Viola tres invariantes de una vez: un cron no es «que el
cliente lo pida en el momento» (I-2), no hay copia ni hash (I-4), y falla en
silencio (I-5). Y con respaldo solo semanal en Premium, un disparo equivocado se
lleva hasta 7 días de órdenes.

Lo reemplaza `t2_4_purga_hostinger.py`, con **cuatro compuertas**: existe copia
local, el SHA-256 recalculado coincide, el archivo tiene más de 30 días, y el
servidor reconfirma el hash en el instante del borrado. Simula por defecto;
borrar exige `--ejecutar --confirmo-borrado` y autorización del momento.

---

## 6. Lo que falta verificar contra el servidor real

Honestamente: esto todavía no está probado, y el diseño no se cierra hasta que lo esté.

| Incógnita | Cómo se resuelve | Bloquea |
|---|---|---|
| **¿`cleanup.php` está en algún cron?** | `crontab -l` y hPanel → Cron Jobs | Nada, pero es lo más urgente: podría dispararse esta noche |
| ¿Sirve un túnel SSH sobre el 65002 para llegar a MySQL? | Probarlo. Hostinger no lo documenta | Si no sirve, la alternativa es un endpoint PHP con token por HTTPS. **No** se abre Remote MySQL a `%`: sería exponer el 3306 al mundo con credenciales viajando por internet |
| ¿Los límites publicados son los de *esta* cuenta? | hPanel → Resource Usage. Hay versiones V1/V2/V3 del mismo plan | Las cifras del §1 son el caso general, no necesariamente el de INDUSTEC |
| ¿Hay más módulos en el servidor que no se descargaron? | El inventario del primer sync lo dirá | El alcance real de la sincronización |
| ¿`industech.me` (con H) es de INDUSTECH? | Solo Andrés lo sabe | Si no lo es, `ot_normal_otros` manda datos de cliente a un tercero |
| ¿Sigue siendo válida la contraseña SMTP actual? | Rotarla y ya | — |

---

## 7. Nuevas subtareas para `PLAN_INDUSTEC.md`

### T2.4 · Continuidad de datos del sistema en producción

| # | Subtarea | Ataca | Verificación exacta |
|---|---|---|---|
| **T2.4.0** | Parches de seguridad mínimos: `.htaccess`, borrar `phpinfo.php` y `debug_firma.txt`, `display_errors=0`, `guardas.php`, rotar SMTP | Exposición de datos personales de KFC y de las credenciales de correo | La URL de un PDF pasa de `200` a `403`; un GET a `submit.php` da `405` y el contador no sube; una OT de prueba genera el mismo PDF y los mismos correos |
| **T2.4.1** | Espejo verificado de los uploads | Que el 82% de las OTs solo exista 3 meses | Segunda corrida seguida: `bajados = 0` y `ya verificados = conteo remoto` |
| **T2.4.2** | Purga con compuerta de hash | Exposición pública y el incumplimiento del contrato | Un archivo sin copia local verificada nunca se borra, ni con `--ejecutar` |
| **T2.4.3** | Normalización al árbol canónico | 158 grafías de local, `Dia 3` con espacio, 43 correlativos repetidos | ≥ 98% resuelto; el resto listado con motivo en `SALIDAS IA\OTS` |
| **T2.4.4** | Publicación a INDUSTEC en `SALIDAS IA\OTS` | La clasificación manual con 405 PDFs de retraso | La administración encuentra una OT del día sin abrir una sola carpeta a mano |

**Autonomía de T2.4**

| Autónomo | Requiere aprobación en el momento | Prohibido |
|---|---|---|
| Sincronizar, normalizar, ingestar, generar informes de purga | Borrar en el servidor · desplegar cualquier parche a producción · pasar de UIO al resto de zonas | Borrar sin copia local verificada por hash · usar `cleanup.php` · abrir Remote MySQL a `%` · subir la clave SSH privada a ningún lado |

### T2.5 · Sistema nuevo de gestión de OTs

Se especifica cuando T2.4 esté corriendo y el staging esté limpio. El orden es
deliberado: **primero se deja de perder información, después se construye
encima.** Lo que ya está decidido:

- Captura en PHP 8.3 sobre `darkorchid-crane-387868`, sin WordPress.
- Catálogos servidos desde la base, no texto libre: locales, equipos, repuestos,
  evaluaciones. Es lo que ataca las 158 grafías y los avisos mal digitados.
- Correlativo por transacción atómica, nunca por archivo de texto.
- Autenticación por técnico. Hoy el endpoint es anónimo.
- Interfaz de revisión local en FastAPI, y consulta por Telegram para la Gerencia.

---

## 8. Qué se entregó ya

| Archivo | Qué hace |
|---|---|
| [`scripts/t2_4_sync_hostinger.py`](desarrollo/agentes/scripts/t2_4_sync_hostinger.py) | Espejo verificado por SHA-256. No borra nada |
| [`scripts/t2_4_purga_hostinger.py`](desarrollo/agentes/scripts/t2_4_purga_hostinger.py) | Purga con cuatro compuertas. Simula por defecto |
| [`scripts/t2_4_normalizar_nuevas.py`](desarrollo/agentes/scripts/t2_4_normalizar_nuevas.py) | Del espejo crudo al árbol canónico. 98,4% automático |
| [`parches/uploads.htaccess`](desarrollo/sistema_ots/parches/uploads.htaccess) | Cierra el acceso web a los PDFs |
| [`parches/guardas.php`](desarrollo/sistema_ots/parches/guardas.php) | Rechaza GET, POST vacío y zona inválida |
| [`scripts/t2_4_publicar_ots.py`](desarrollo/agentes/scripts/t2_4_publicar_ots.py) | Catálogo para INDUSTEC. **Ya corrido: 7.069 órdenes publicadas** |
| [`sql/003_persistencia_formulario.sql`](desarrollo/agentes/sql/003_persistencia_formulario.sql) | Prepara la base para T2.1.1. **Escrita, no aplicada** |
| [`LEEME_ACCESO_HOSTINGER.md`](desarrollo/sistema_ots/LEEME_ACCESO_HOSTINGER.md) | Guía paso a paso, cada uno con su verificación |
