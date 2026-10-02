# Estado del proyecto INDUSTEC

> **Empieza por aquí.** Este archivo dice dónde vamos; [`PLAN_INDUSTEC.md`](PLAN_INDUSTEC.md) dice qué hay que construir y con qué criterios.
> Si vas a trabajar, **anótate primero en §5 (Trabajo en paralelo)** antes de tocar nada.

**Última actualización:** 2026-10-01 (noche)
**Fase en curso:** 2 · Automatización — **el envío real de las OT está ACTIVO desde el 2026-09-29 21:45 en UIO, LARB y CNLJ** (T2.29, §1z): lo que se emite en la app sale a Grupo KFC, al local y a la administración desde reclutamiento@industec.me. La Fase 1 quedó cerrada
**Repositorio git:** la raíz del proyecto, `D:\INDUSTECH IA` — cubre el código **y** estos documentos, para que quede historial de las decisiones. Fuera del control de versiones: `ENTRADAS IA`, `SALIDAS IA`, el entorno virtual y las credenciales.

> ⚠️ **El remoto de GitHub es PÚBLICO** (comprobado el 2026-10-01: `https://api.github.com/repos/AndresIndustech/industec-bia-soft-erp` responde 200 sin credenciales y trae `"private": false`), aunque el `CLAUDE.md` y este documento lo llaman privado. Las llaves y el `.env` están en `.gitignore` y **no** aparecen en el historial (comprobado), pero el código y estos documentos —nombres de personas, avisos, rutas, IP y puerto del SSH— son legibles por cualquiera. Decide Andrés: pasarlo a privado en GitHub (Settings → Danger Zone) o aceptarlo. Andrés eligió empujar sabiendo esto (1-oct, noche): la conversación empujó `9b8ea60` y `6fe6fa8` (`9a43f00..6fe6fa8`).

**Nombre del sistema, decidido por Andrés el 2026-09-10: B.IA Soft ERP.**
✅ **Aplicado en la interfaz el 2026-09-10**, por la conversación del rediseño
—que era la que tenía `app/publico/` en uso, así que le tocaba—: el `<title>` de
las trece pantallas (sale de `Ui::cabecera()`, un solo sitio), la marca de la
barra (`B.IA Soft ERP`), el ingreso, el cambio de clave, el alta del padrón, y
`manifest.json` con el nombre que ve el celular al instalar la app
(`B.IA Soft`). Comprobado: no queda ninguna mención de «Sistema de OTs» ni de
«OT INDUSTEC» en `.php`, `.html` ni `.json`. **Sin desplegar**, como el resto
del rediseño.

> **Si esta es una conversación nueva: empieza leyendo esto, en orden.**
> 1. **[`PLAN_INDUSTEC.md`](PLAN_INDUSTEC.md) §11b — «Arranque para una conversación nueva».**
>    Dice en un párrafo dónde está parado el proyecto, cuál es la siguiente acción
>    concreta, qué está bloqueado y por quién, y los errores que este proyecto **ya
>    pagó**. Es el punto de entrada.
> 2. Esta sección y la §1b completas — qué funciona hoy, con su cifra verificada.
> 3. La tarea que te toque en el plan, con su criterio de aceptación y su tabla
>    *autónomo / requiere aprobación / prohibido*.
> 4. [`SISTEMA_COMPLETO.md`](SISTEMA_COMPLETO.md) solo si vas a tocar seguridad o
>    datos personales: su §5b tiene lo de la LOPDP.
>
> **Y no cierres la conversación sin actualizar el plan y este archivo.** Es
> directiva del cliente desde el 2026-09-10 y está en el `CLAUDE.md` del
> proyecto: el proyecto se ejecuta en conversaciones que no se conocen entre sí,
> y estos dos documentos son la única continuidad que hay.

---

## 1. Qué está funcionando hoy

| Pieza | Estado | Cifra verificada |
|---|---|---|
| Corpus histórico saneado | ✅ **regularizado el 2026-09-20/21** | **7.457** PDF en el árbol canónico (eran 7.123: entraron 334 de producción y del correo, cero pendientes) + 5 informes técnicos + 25+4 de otros clientes (METALZA gana 3) |
| **Robot del correo y de producción → informes nuevos** | ✅ **T2.21 cerrada del todo el 2026-09-21** | Lee `INBOX`+`Trash`+`INFORMES OT` (T2.21.1, el servidor confirmó `n=193 antes=122`); reintenta y **sale con código 1** si el empuje falla (T2.21.4, probado con el endpoint apagado); y **espeja producción al detectar un correo** (T2.21.7). El `--ejecutar` corrió dos veces: **+334 órdenes** al árbol, a la base y al servidor, con cuadre exacto en las tres. **0 documentos pendientes de archivar.** El promotor del buzón quedó encadenado al saneamiento nocturno, que ya tiene su Tarea programada. Detalle en **§1d** |
| Base de datos poblada | ✅ **2026-09-21** | **7.452 órdenes activas** (eran 7.118; +334 de la regularización, con 7.452 hashes distintos: cero duplicados) · 9.071 equipos · 100 locales · **149 alias** (145 + 4 confirmados el 21-sep) · 6.450 avisos SAP · 19 técnicos<br>Histórico: 7.118 activas era 7.069 hasta el 2026-09-13 (`t2_18_rescatar_buzon.py` recuperó 53 OTs de `_DEL_BUZON`) |
| Auditor de calidad (Agente 1) | ✅ | 2.644 observaciones abiertas, con veredicto editable por la administración |
| Consolidador de plan de zona (Agente 2) | ⚠️ v1 | 87,3% de coincidencia celda a celda en el piloto UIO |
| **Histórico en formato de planificación** | ✅ | **39 planes mensuales** de correctivo (7.863 filas, 3 zonas × 13 meses) + **seguimiento de preventivos** de 96 locales, reconstruidos desde las OTs y SAP. LOCAL, FECHA DE INICIO y ESTADO al 100%; EQUIPO al 99,5% |
| Respaldo TrueNAS | 🔒 | Bloqueado: falta acceso físico al equipo |
| Capacitación de cierre | 🔒 | Bloqueada: falta agendar con el personal |
| Repositorio git sincronizado con GitHub | ✅ **2026-09-12** | `origin` es `git@github.com:AndresIndustech/industec-bia-soft-erp.git`. **Comprobado:** `ssh -T git@github.com` responde «Hi AndresIndustech! You've successfully authenticated», `git fetch` trae, y `master` tiene upstream `origin/master`. Andrés ya agregó la clave pública, así que el bloqueo del 2026-09-11 está levantado. **Se acabó el proyecto en un solo disco.** Ojo con lo que esto destapa: existe `origin/pc/auditoria-2026-09-10` **29 commits por delante de `master`**, con `master` como ancestro — ver §5.2b |
| **Continuidad entre casos del mismo equipo** (T2.25) | ✅ **desplegada y verificada contra el servidor el 2026-09-21** | Migración **012 aplicada** (`verificar_esquema.php` → **TODO OK**, con 0 casos enlazados: no enlaza nada por su cuenta), `verificar_continuidad.py` en **25 · 0** —incluido el arrastre real del pendiente huérfano—, `verificar_http.py` **86 · 0** y `verificar_bandeja.py` **37 · 0**. Locales: **36 · 0** la nueva, 120 · 0, 57 · 0, 62 · 0. El fenómeno, medido: **185 grupos** local+equipo con más de un caso en el buzón vivo y **509 avisos** del histórico SAP que nacen dentro de la semana de otro del mismo equipo. Detalle en **§1l** |
| **Buzón de la administradora, regularizado en bloque** | ✅ **2026-09-21** | **124 ATENDIDO → cerrados en SAP** + **773 CERRADO_SIN_ATENCION → regularizados**, a pedido de Andrés y asumiendo que ella ya lo hizo en SAP (sin verificar caso por caso). Pendientes de regularizar: **0**. Detalle, la herramienta y la nota sobre los «656» vs 773 reales en **§1h** |
| **Pantalla de preventivos** | ✅ **rediseñada y desplegada, 2026-09-21/22 (T2.23 + T2.24.2)** | De **~110 elementos** en la primera pantalla a **~28**. Tres horizontes en pestañas en vez de apilados; el atraso como cola de trabajo. Destapó tres cifras que se leían al revés: los **174 ingresos «sin cerrar»** (ya cerrados en bloque, ver §1m), el «0 % a tiempo» que en realidad era «ningún ingreso cerrado todavía», y el «sin kit» que salía en el 86 % de las filas. Baterías locales **120·0, 57·0, 62·0, 11·0**; cuadre de bloques **82+174+19+16+77 = 368**. Detalle en **§1k** (pantalla) y **§1m** (cierre masivo). **Falta correr las siete baterías de servidor** |
| **Envío real de las OT INDUSTEC (T2.29)** | ✅ **ACTIVO desde el 2026-09-29 21:45 en UIO, LARB y CNLJ** (`45142ae` y siguiente); OTRA en piloto | Cuenta `reclutamiento@industec.me` importada del viejo (5/5 copias iguales, huella `1f9f059a`), conexión OK y **correo de prueba recibido en servicioalcliente@ con su PDF** (IMAP). Series sembradas = contador viejo + 5: próximas OT-1952 (UIO) · OT-2327 (LARB) · OT-2652 (CNLJ). Baterías: emisión 69·0 (antes y después de activar), ciclo 54·0, continuidad 30·0, bandeja 37·0, http 89·0; locales 112·0 + 119·0 + integración 81·0. La primera OT real salió el 30-sep a las 13:11 (T2.29.7 ✅); el 1-oct chocaron los números con el formulario viejo y se enviaron 10 OT del piloto (T2.29.8): ver **§1z-bis**. Detalle del envío en **§1z** |
| **10 OT del piloto enviadas a Grupo KFC (T2.29.8)** | ✅ **2026-10-01 19:03–19:04** | Diez de las 18 OT de la serie 9000 —trabajos reales de UIO sin informe del formulario viejo para su aviso— salieron desde reclutamiento@industec.me: **10 de 10 llegaron a servicioalcliente@ con su PDF intacto** (huella = la del servidor). Casos: 8 de RESUELTO a ATENDIDO (pendiente de SAP), 1 de ASIGNADO a ATENDIDO, 1 sin cambio (la OT no concluyó). Nueva marca `liberada_en` (migración 024). Baterías: emisión 69·0, http 89·0; unitarias 24·0 la nueva. **Decidido por Andrés esa noche:** las OT-1952 y OT-1964 de la app se dejan y se anotan; LARB y CNLJ vuelven al piloto (UIO sigue en producción). Detalle y lo no comprobado en **§1z-bis** |
| **La OT aparece en el Archivo al emitirse y la cola del celular no se traba (T2.29.10)** | ✅ **2026-10-01 noche, desplegado en darkviolet** | (1) `Emision::emitir()` indexa la OT en `ot_archivo` en el momento (antes, solo el índice de las 03:50): `verificar_emision.py` con la comprobación nueva **70·0**. (2) `cola.js` corta cada petición a su tiempo límite: el fallo se **reprodujo** en Chrome (la cola queda trabada con una subida colgada) y con el arreglo se recupera (`prueba_cola_timeout.mjs`, nueva); `verificar_sync_cerrada.mjs` 4·0 y `verificar_formulario.mjs` 39·0 contra el servidor. **Falta:** que los celulares reales (iPhone) lo reciban y se vea en uso; detalle en **§1z-bis** |
| **OT INDUSTEC del piloto: marcada y sin cerrar órdenes** | ✅ **desplegada en darkviolet el 2026-09-28** (`4240a67`) | 19/19 archivos = rama; `tarjeta_cli` 34·0 ×2; 14 OT del piloto, 0 discrepancias PHP/SQL; 12 cerradas en SAP con OT del piloto señaladas en el buzón; bitácora sin lo de prueba (−2.642 filas, candado probado). Detalle y lo no comprobado en **§1w** |

**Cuadre del corpus, exacto contra los 7.333 PDFs originales:**
`7.070 (órdenes) + 5 (informes técnicos) + 25 (otros clientes) + 233 (duplicados descartados) = 7.333`

**Reparto de las órdenes activas:** CNLJ 2.589 · LARB 2.498 · UIO 1.978 · OTRA 4 — · Correctivo 6.349 · Preventivo 720

---

## 1b-bis. T2.21.1 cerrado y comprobado en producción (2026-09-19)

El robot abría solo `INBOX`, de las 10 carpetas de la cuenta. Ahora abre una
lista blanca declarada en el código —`INBOX`, `Trash`, `INFORMES OT`— con el
motivo de cada exclusión escrito al lado, para que nadie la «optimice» de
vuelta. Todas con EXAMINE y `BODY.PEEK[]`: no se restaura, no se mueve y no se
borra un correo, tampoco en `Trash` (I-2, I-3).

**Medido a mano antes de commitear** (`--sin-pdf`, contra el buzón real):

| | Solo INBOX | INBOX + Trash + INFORMES OT |
|---|---|---|
| Informes leídos | 591 | **748** |
| Casos pendientes con atención | 122 | **193** |
| Ya cerradas por INDUSTEC | 31 | **106** |

**Comprobado después en la corrida programada**, sin intervención de nadie: la
Tarea «INDUSTEC - Informes de OT» corrió a las 00:23 y el servidor devolvió
`HTTP 200 ok tipo=atenciones n=193 antes=122`. Es el propio servidor diciendo
de cuánto a cuánto subió.

**Y la prueba de que el agujero era real, capturada en vivo el 2026-09-19:**
entre la tercera y la cuarta corrida del día, `INBOX` bajó de 587 a 579 y
`Trash` subió de 157 a 167 — la administradora borró correos mientras el robot
trabajaba, y el total leído no cayó (744 → 746) porque la papelera los recogió.
Con el código de ayer, esos ocho informes se habrían perdido y sus casos
seguirían figurando pendientes.

**Prueba negativa en verde:** `grep -nE "M\.(store|copy|move|expunge)" scripts/t2_*.py`
no devuelve nada, y los seis `select()` del proyecto llevan `readonly=True`.

`INFORMES OT` existe en la cuenta y está **vacía**: confirmado, nadie escribe ahí.

---

## 1d. La regularización ejecutada, 2026-09-20 (T2.21, cierre)

Andrés autorizó el `--ejecutar` que llevaba pendiente desde el 18-sep. Corrió
la cadena entera y cuadró en las tres dimensiones.

**Cifra final, tras dos rondas (el `--ejecutar` masivo del 20-sep y el cierre
caso por caso del 20/21-sep):**

| | Antes de todo | Después | Diferencia |
|---|---|---|---|
| PDF en el árbol canónico | 7.123 | **7.457** | +334 |
| Filas en `ots` | 7.469 | **7.803** | +334 |
| Órdenes activas | 7.118 | **7.452** | +334 |

**Cero duplicados:** las 7.452 activas tienen 7.452 hashes distintos.
**Subida:** el «faltan: 0» tautológico que la auditoría había detectado en
`t2_19` dio un número real dos veces — 329 y después 5 — y las dos veces se
subieron y verificaron los 334, **0 fallidos**. Índice del Archivo: 7.710
órdenes, 7.621 con el PDF ahí. Verificado **contra el servidor**, no contra el
log: los 4 casos de la segunda ronda (G016, el del espacio, dos por alias) dan
`en_servidor=1`.
**Respaldo previo:** `D:\RESPALDOS\_LOGS\respaldos_bd\` (13 MB, 13 tablas), tomado
antes del primer `--ejecutar`.
**Nada se borró de ningún origen (I-2):** los 2.338 de `_ORIGEN_SISTEMA` y los
117 de `_ORIGEN_BUZON` siguen donde estaban.

**El correo dejó de ser necesario como fuente, y está medido.** Tras promover
producción, el promotor del buzón sobre sus 117 PDF dio **«promovidos 0, ya
estaban 103»**: los informes ya habían entrado por producción. Es el punto 2
de Andrés, confirmado con datos.

**Deduplicación por contenido (decisión de Andrés).** Los scripts comparaban
por ruta, así que un informe reenviado con otro correlativo se archivaba dos
veces. Ahora se cruza por hash: **13 repetidos descartados**, todos el mismo
documento con el nombre crudo distinto del canónico ya archivado — local
compuesto (`K073H015` vs `K073EC`), aviso sin ceros a la izquierda (`1031` vs
`00001031`) y un código mal escrito (`BS17` vs `BR17EC`).

**Los informes enviados dos veces: deciden las personas.** 5 pares, todos el
mismo técnico, el mismo día y la misma hora de inicio y fin — una sola visita
documentada dos veces. Pero en **tres de ellos el técnico corrigió el informe**
al reenviarlo (actividades, equipos o repuestos) y en dos solo cambió el número
de fotos. Quedarse con el primero habría archivado la versión que él quiso
corregir, así que Andrés decidió archivar los dos y que la administración elija.
`t2_23_informes_repetidos.py` arma esa vista en `SALIDAS IA\CALIDAD\`.

**Los 4 «conflictos de correlativo» no eran conflictos.** Cruzados contra la
base, los cuatro son **dos visitas legítimas al mismo aviso**, con 14, 5, 3 y
16 días de diferencia, y dos de ellas con otro técnico (aviso 10351998: Pablo
Ortiz el 02-sep y Alfredo Montoya el 16-sep). El técnico volvió. Las ocho
órdenes ya estaban archivadas y activas.

**Lo que NO se archivó en la primera ronda: los 41, todos cerrados** (resuelto
el 2026-09-20/21 con Andrés, caso por caso — **0 pendientes**).

- **28 formularios vacíos → descartados.** Se abrieron los 28 antes de borrar:
  ni un campo de texto con valor, ni un equipo con datos. Pesan entre 124.049 y
  126.687 bytes — **2.638 bytes de diferencia entre los 28**, que es la firma de
  una plantilla. Borrarlos del espejo no alcanzaba (el sync los repone en la
  siguiente corrida, que ahora se dispara con cada correo), así que van por
  SHA-256 en `config/descartados_sha256.txt`. **Siguen en producción:** la lista
  solo dice «no me lo traigas al espejo». Comprobado: 7+6+9+5+1 = 28 descartados
  y «0 por bajar» en los cinco módulos.
- **1 archivado: el aviso tecleado con espacio.** `1034 7791` no encajaba con
  ningún patrón. Los patrones ahora aceptan espacios y los quitan antes de
  validar los 8 dígitos. **SAP lo confirma de forma independiente:** el aviso
  `10347791` tiene centro de coste `M044`, el local del archivo. Archivado como
  `OT-0336-M044EC-10347791-D2-LARB.pdf`.
- **2 resueltos por el interior del PDF, ya estaban** (`t2_24_promover_por_evidencia.py`),
  porque el nombre no alcanzaba: `MENESTRASDELNEGRO` trae la **cadena** y hay 8
  locales de ella, pero dentro coinciden `cliente=M036` y
  `correo_local=m36@menestrasdelnegro.com.ec` → **M036EC**; y `RestauranteElvita`
  **no es de KFC** — su `correo_jefe_op` es `gerenciageneral@industec.me` y el
  aviso viene en `0`. Los dos ya estaban archivados por otra vía, comprobado por
  hash: no se copió nada nuevo. **No se creó un alias `MENESTRASDELNEGRO`→`M036EC`:**
  sería el falso positivo contra el que avisa `industec-archivos-canonicos`.
- **4 con local mal escrito → alias confirmado y archivados.** `locales_alias`
  pasó de 145 a 149 filas: `kh073`→K073EC, `H071k197`→K197EC,
  `G015Michelena`→G015EC, `G021Colonial`→G021EC (nivel de confianza 2, con
  la evidencia citada en `regla_aplicada`). De los 4, `kh073` ya estaba
  archivado por otra vía; los otros 3 se copiaron y verificaron por hash.
- **6 de otros clientes → verificados uno por uno, no clasificados a ciegas.**
  La primera lectura los daba a todos por «otros clientes fuera de contrato»,
  y era **incorrecta para 1 de los 6**: `G016` resuelve limpio contra el
  maestro (`G016EC`, SUR UNO VILLAFLORA QUITO, cadena GUS) — se promovió al
  árbol canónico, no a `OTROS CLIENTES`. Los otros 5 (TropiBurger ×4 y el
  evento de Baños) sí son de clientes fuera de contrato, confirmado con
  evidencia independiente: `METALZA` y `NOVO EVENTOS` ya eran carpetas de
  cliente existentes en `OTROS CLIENTES` con historial de 2025. De los 5, 2
  ya estaban archivados por hash y 3 se copiaron y verificaron.

**Lo que no se pudo comprobar (I-7):** 4 PDF entraron con `fecha_atencion`
nula porque su fecha es ilegible en el documento — uno dice el año `20026`.
Están archivados e ingestados, pero sin fecha utilizable.

**El promotor del buzón quedó encadenado al nocturno** (T2.21, cierre
definitivo): `t2_18_rescatar_buzon.py --origen "D:\RESPALDOS\_ORIGEN_BUZON" --ejecutar`
es ahora un paso de `saneamiento_nocturno.py`, entre `normalizar` e `ingesta`.
Antes salía siempre con código 0 pasara lo que pasara; ahora aborta solo ante
una colisión real de contenido, nunca ante un caso de negocio normal. La Tarea
programada **«INDUSTEC - Saneamiento nocturno»** se creó — no existía —, diaria
a las 02:30 con reintento cada 30 min durante 2 h. Queda en modo «solo
interactivo» como las otras dos tareas del proyecto, porque esta sesión no
tenía permisos de administrador para `/ru SYSTEM`: **pendiente que alguien con
sesión de administrador la recree así, para que corra sin sesión abierta.**

---

## 1e. El robot, arreglado — y la consola que lo vigila (2026-09-20)

**El robot llevaba cinco días corriendo con código viejo y nadie se enteraba.**
El vigilante se había iniciado el 2026-09-15 a las 12:43 y un proceso de Python
ya arrancado no recoge los cambios del `.py`, así que `espejar_produccion()`
—la mitad nueva de su trabajo— no corrió ni una vez. La Tarea programada decía
«En ejecución» y el registro se veía sano: la única pista era una **ausencia**.

**Y al reiniciarlo apareció la causa de fondo: los permisos de la llave SSH.**
Hay dos `ssh.exe` en la estación y no se comportan igual — el de Git Bash
tolera los permisos del archivo de llave y el de Windows los exige.
`config/clave_hostinger` tenía acceso para «Usuarios autenticados» por herencia,
así que el de Windows la rechazaba con `UNPROTECTED PRIVATE KEY FILE` y salía
255. **Como la Tarea programada corre por `cmd.exe`, usa el de Windows: T2.21.7
nunca habría funcionado desde el Programador por más que pasara las pruebas a
mano.** Corregido con `icacls /inheritance:r`; antes daba `Permission denied`,
ahora `CONECTADO_OK` y código 0. `hostinger_ssh.py` ahora avisa si la llave
queda abierta, con el comando de corrección.

**Cadena cerrada**, con el vigilante reiniciado: existe por primera vez
`config/vigilante_estado.json`, que solo se escribe cuando el espejo **termina
bien**.

**La consola** (`scripts/consola.bat`) no muestra «el robot está corriendo»:
muestra **desde hace cuánto** no pasa cada cosa que debería estar pasando, que
es lo único que delata a un robot que corre sin hacer su trabajo. Tres bloques:
el vigilante (con su hora de arranque, que dice qué versión del código tiene
cargada), los requerimientos de SAP que van entrando, y los informes nuevos de
producción por zona. Solo lectura. Probada antes de arreglar nada, señaló los
dos problemas de una: «Última señal hace 13 min» y «Último espejo NUNCA».

---

## 1f. InspectorBot — la consola del robot, con ventana propia (2026-09-21)

Andrés pidió que la consola de T2.22 dejara de ser una ventana de CMD: que
fuera visual, con su icono y su nombre, visible como **InspectorBot** en el
Administrador de tareas, y que arrancara sola con el equipo. La consola de
texto (`scripts/consola.bat`) **se conserva**: sigue siendo la forma de mirar
esto por SSH o sin escritorio.

**Cuatro archivos nuevos, ninguno toca nada:**

| Archivo | Qué hace |
|---|---|
| `scripts/inspectorbot_estado.py` | Lee las **doce** fuentes de estado y calcula las alertas. Sin una línea de interfaz, para poder comprobarlo con `--json` |
| `scripts/inspectorbot.py` | La ventana (tkinter). Un Canvas que se redibuja; la lectura va en un hilo aparte para que PowerShell no la congele |
| `scripts/crear_inspectorbot.py` | Fabrica `InspectorBot.exe`, su icono y los accesos directos |
| `recursos/InspectorBot.ico` | El icono, 9 resoluciones (16→256 px), paleta de marca de B.IA Soft ERP |

**Lo que vigila y la consola vieja no veía.** La de texto usaba 5 de las 12
fuentes disponibles, y la 9ª mal contada. Lo que se sumó:

- **Código viejo en memoria.** Compara el `mtime` de `t2_9_buzon_vigilante.py` y
  `comun.py` con la hora de arranque del proceso. Es el fallo de los cinco días
  del 2026-09-20 y **no hay ninguna otra señal que lo delate**. Solo esos dos
  archivos: los otros tres (`t2_6`, `t2_11`, `t2_4`) se lanzan como subproceso
  en cada ciclo y leen el disco siempre, así que incluirlos daría una alarma
  roja permanente y falsa.
- **El saneamiento nocturno.** Mira `ok`, **`ensayo`** y el paso que falló. Sin
  mirar `ensayo`, un simulacro se lee como una corrida buena.
- **Cuántos robots hay de verdad.** La consola vieja pintaba «PID 13340, 13652»
  y parecían dos vigilantes. Es uno: `.venv\Scripts\python.exe` es un lanzador
  que arranca el intérprete base como hijo. Ahora se cuenta una raíz por robot,
  y **4 procesos sí serían dos robots**, que es una alarma legítima porque
  `t2_9` no tiene candado de instancia única.
- **El resumen del espejo** (`_manifiestos/resumen_*.json`): `sospechoso`,
  `error`, `fallidos`, `divergentes`. El registro solo ecoa esas cifras cuando
  no son 0, así que una corrida sana y una que no ocurrió se ven idénticas.
- **Catálogo congelado**, por hash de `datos` **sin** el campo `generado` (que
  lleva la hora dentro y cambia en cada barrido aunque no haya novedades).
- Escucha IMAP muerta, reinicios repetidos, `.tmp` huérfano, candado del
  nocturno abandonado, cuarentena, divergentes, espacio en `D:`, y los **6 casos
  con alerta** y **2 sin local** que esperan a la administración.

**Una falsa alarma corregida en el camino, con el caso real delante.** El umbral
de «lleva callado demasiado» eran 12 minutos, del ciclo IMAP de 9. Pero el
espejo tiene **una hora** de tiempo límite y no escribe una línea hasta que
termina. Medido el 2026-09-21 a las 11:25: última línea `espejando lo que
produccion emitio en los ultimos 60 min...` hace 14 minutos — con el umbral
viejo, rojo; y el robot estaba trabajando. Ahora, si la última línea es un
trabajo largo, el límite sube a 65 min. Una alarma que grita cuando todo va
bien enseña a ignorarla.

**Cifras verificadas** (`scripts/inspectorbot_estado.py`, 2026-09-21 11:25):
898 casos vigentes (UIO 280 · LARB 270 · CNLJ 348), 192 atendidos de 898,
706 sin atender, **2.319 PDF** en el espejo (uio 477 · larb 668 · cnlj 907 ·
mant 261 · otros 6), 1 robot vivo en 2 procesos, 4 alertas — de ellas **1 grave
y real: el saneamiento nocturno no ha corrido nunca** (`LastTaskResult 267011`
= `SCHED_S_TASK_HAS_NOT_RUN`, ni un `logs/saneamiento-*.log`, y el único
`estado_nocturno.json` dice `ensayo: true`).

**El ejecutable, y por qué no es el `pythonw.exe` del venv.** El del venv no es
el intérprete: es un **lanzador** que lee `pyvenv.cfg` y arranca el intérprete
real **como proceso hijo**. Copiándolo salían dos entradas en el Administrador
de tareas y la que tenía la ventana se seguía llamando `pythonw.exe` /
«Python». Se copia el intérprete **base** (`sys.base_prefix\pythonw.exe`) dentro
de `.venv\Scripts\`, donde encuentra el `pyvenv.cfg` un nivel arriba y usa el
site-packages del venv: **un solo proceso llamado InspectorBot**. Necesita
`python312.dll` al lado, porque fuera de su carpeta ya no la encuentra salvo que
Python esté en el PATH, y en eso no se puede confiar. El icono y la ficha de
versión se escriben con `UpdateResourceW` vía ctypes — sin PyInstaller, sin
bundle, y **sin reconstruir nada cuando cambia un `.py`**: el `.exe` es el
intérprete. Verificado: `Get-Process` da `ProcessName InspectorBot`,
`Description InspectorBot`, y el Administrador de tareas lo lista bajo
Aplicaciones con su icono.

**Arranque con el equipo:** acceso directo en `shell:startup` (y otro en el
Escritorio). No es Tarea programada a propósito: InspectorBot es una **ventana**,
y una tarea con `/ru SYSTEM` correría en la sesión 0, donde nadie la vería nunca.
Además, en la carpeta de Inicio Andrés lo apaga solo desde Administrador de
tareas → Inicio. Las carpetas se piden con `SHGetKnownFolderPath` y no se arman
desde `%USERPROFILE%`: aquí el Escritorio está redirigido a
`C:\Users\indus\OneDrive\Desktop` y se llama «Desktop» con el sistema en
español, así que la ruta adivinada falla.

**Probado con el entorno que tendrá de verdad** (la lección del error nº 20):
lanzado con `PATH` reducido a `C:\Windows\system32;C:\Windows`, arranca. Y desde
los dos accesos directos, no solo a mano.

⚠ **Avast marca el `.lnk` como `IDP.HELU.PSD11`** — falso positivo de reputación,
típico de un ejecutable nuevo sin firmar. El `.exe` nunca se bloqueó. Andrés
añadió la excepción el 2026-09-21 y los dos accesos directos arrancan. Si el
equipo de Andrés (u otro) vuelve a bloquearlo, la alternativa es Tarea
programada «al iniciar sesión», que no pasa por un `.lnk`.

**Lo que NO se pudo comprobar:** que arranque en un inicio de sesión real. Se
probó lanzando el propio `.lnk` de la carpeta de Inicio y con el `PATH` limpio,
que es lo más cerca que se llega sin cerrar la sesión.

---

## 1g. Red de seguridad del trabajo en curso (2026-09-21)

Andrés pidió que una conversación que se corta en seco —se agota el crédito, se
cierra la ventana— no se lleve el trabajo por delante.

`scripts/guardar_sesion.py` copia lo que `git status` ve como modificado o sin
seguimiento a `.respaldo_sesion/<fecha-hora>/`, con un `RETOMAR.md` que dice
rama, último commit y en qué se estaba. **No toca git**: no commitea, no hace
stash, no cambia de rama, no borra. La carpeta está en `.gitignore` y se puede
borrar entera sin perder nada que ya esté en git.

Lo dispara solo el hook **`Stop`** de `.claude/settings.json` (nuevo archivo,
versionado, así que el PC de Andrés lo hereda), al final de cada turno.

**Dos decisiones que se tomaron con el dato delante:**
1. **Solo lo modificado en las últimas 12 h.** La primera corrida copió **217
   archivos**: se llevaba `desarrollo/sitio_web/` entero (215 archivos, 22 MB),
   que lleva sin commitear desde el 2026-09-12 **a propósito** (§5.1). Copiar
   eso en cada turno convierte la red de seguridad en un estorbo. Con el filtro:
   3 archivos.
2. **`git()` devuelve stdout SIN recortar.** El `.strip()` se comía el espacio
   de la primera columna de `status --porcelain` en la primera línea, y
   ` M ESTADO.md` se leía como `M ESTADO.md`: el archivo se respaldaba como
   **`STADO.md`**. En un script cuyo trabajo es no perder nada, un recorte
   silencioso es justo lo que no puede pasar. Encontrado probando, no leyendo.

Lo que el hook **no** puede hacer es detectar que se acaba el presupuesto: eso
no es un evento, es un número que llega en cada turno. Queda como regla de
trabajo — al ~10 % restante, dejar de construir y cerrar ordenadamente
(guardar, commitear, dejar plan y estado al día).

**Verificado:** `guardar_sesion.py` → «Guardados 3 archivos en
`.respaldo_sesion/20260921-111838/`», con `ESTADO.md` entre ellos (el caso que
el bug se comía).

---

## 1h. Regularización masiva del buzón — el pendiente de la administradora en cero (2026-09-21)

El buzón de `casos.php` tenía **124 ATENDIDO** (esperando el botón «Ya lo cerré
en SAP») y **773 CERRADO_SIN_ATENCION sin regularizar**, y a la administradora
le resultaba abrumador. **Andrés pidió tratarlos en bloque, asumiendo que ella
ya hizo las dos cosas en SAP** — no se verificó caso por caso contra SAP, es
una decisión de negocio suya, no una comprobación de datos. **No toca
`avisos_sap.estatus_general`** (regla 5 de §6): solo mueve la capa de gestión
interna (`casos_gestion`) que decide qué le sigue apareciendo pendiente.

**Nota sobre la cifra que dio Andrés:** pidió regularizar «los 656 cerrados sin
atención». La base viva daba **773** pendientes de regularizar, no 656 — un
18 % más (probablemente creció desde que los miró, o los recordaba
aproximados). Se procesaron los 773 reales, no 656: la instrucción era vaciar
la categoría entera («todos esos»), y el número que dio era descriptivo, no un
tope.

**Herramienta:** `desarrollo/sistema_ots/app/publico/regularizar_masivo_cli.php`
(nuevo, documentado en `t2_10_desplegar.py`), calcado de las transiciones que ya
existen en `casos.php` (`Casos::TRANSICIONES['cerrado_sap']` y
`['regularizar']`) — mismo criterio, incluido no tocar un ATENDIDO con
pendiente de repuestos vivo (ASG-01). Queda en la bitácora con
`usuario='sistema'`, no con el id de la administradora, porque ella no hizo el
clic y atribuírselo falsearía el registro (mismo patrón que
`Reconciliar::anotar()`). Es una herramienta a mano, **no** se encadenó al
nocturno ni a ningún cron: el cierre en dos manos sigue siendo la norma para
los casos que entren de ahora en adelante.

**Ejecutado contra el sitio de pruebas** (`industec_app` en Hostinger), con
conteo previo sin `--ejecutar` y una segunda corrida después que confirmó
idempotencia (0 y 0):

```
1. ATENDIDO -> cerrado en SAP
   en ATENDIDO                                : 124
   con pendiente de repuestos vivo (se saltan) : 0
   cerrados                                    : 124

2. CERRADO_SIN_ATENCION -> regularizado
   pendientes de regularizar                   : 773
   regularizados                               : 773

Estado de la tabla:
   CERRADO_SIN_ATENCION     775   (2 ya estaban regularizados antes de hoy)
   ASIGNADO                 131
   RESUELTO                 128   (4 ya estaban + 124 de esta corrida)
   NUEVO                      3
   pendientes de regularizar   : 0
   ATENDIDO sin cerrar en SAP  : 0
```

**Bitácora, verificada aparte:** `CERRADO_SAP_MASIVO: 124` ·
`REGULARIZAR_MASIVO: 773` — cuadra exacto con lo ejecutado.

**Lo que NO se comprobó:** que la administradora efectivamente haya cerrado o
reportado estos 897 casos en SAP. Es la premisa que dio Andrés, no un hecho
verificado por este agente contra una fuente independiente (I-10 no aplica
aquí: no hay con qué cruzar, es una decisión de negocio explícita).

---

## 1i. Caso puntual OT 10353660 regularizado, y el hallazgo de los 203 (2026-09-21)

Andrés reportó el aviso **10353660** (G005 Labrador Quito, GUS): el buzón lo
mostraba `ASIGNADO` — sin orden emitida — pero la orden ya estaba hecha. Se
verificó contra `ot_archivo` (fuente independiente, I-10): el documento
`OT-1851-G005EC-10353660-UIO` ya estaba archivado y verificado desde el
saneamiento del corpus histórico (T2.21), técnico Anthony Morales, fecha de
atención 2026-09-09, `en_servidor=1`. Sin pendiente de repuestos vivo (ASG-01).

**Regularizado en dos pasos**, replicando `Casos::TRANSICIONES` en vez de forzar
el estado a mano:

```
1) ASIGNADO -> ATENDIDO   (ot_cierre y atendido_en tomados de ot_archivo)
2) ATENDIDO -> RESUELTO   ('cerrado_sap', a pedido de Andrés: la administradora
                            ya lo procesó en SAP — sin verificar caso por caso
                            contra SAP, mismo patrón que §1h)
```

**Comprobado, corrida contra el sitio de pruebas** (`casos_gestion` en
Hostinger), con verificación de idempotencia (segunda corrida: «Ya está
RESUELTO. Nada que hacer.»):

```
Estado actual en casos_gestion: ASIGNADO
Documento en el archivo: OT-1851-G005EC-10353660-UIO (fecha_atencion 2026-09-09, en_servidor=1)
Pendiente de repuestos vivo: no
Paso 1 aplicado: ASIGNADO -> ATENDIDO
Paso 2 aplicado: ATENDIDO -> RESUELTO
```

Bitácora verificada con dos filas: `ATENDIDO_DESDE_ARCHIVO` (ASIGNADO→ATENDIDO)
y `CERRADO_SAP_PUNTUAL` (ATENDIDO→RESUELTO), `usuario='sistema'`, aviso
10353660, con el motivo y el pedido de Andrés citados en `detalle`.

**El hallazgo es más grande que un caso — T2.22 en el plan.** Al dimensionar
cuántos avisos tienen el mismo patrón (documento archivado desde el corpus
histórico que `casos_gestion` no refleja):

```sql
SELECT COUNT(*) FROM ot_archivo a JOIN casos_gestion g ON g.aviso = a.aviso
 WHERE a.origen = 'HISTORICO' AND g.estado IN ('NUEVO','ASIGNADO','EN_REVISION')
   AND g.ot_cierre IS NULL;
```
→ **203 avisos**, varios con más de un documento archivado (10336163, 10336625,
10336510, 10337184, 10337874...). **Ninguno de los 203 se tocó**: es un
hallazgo, no una regularización — falta que Andrés decida el criterio para los
avisos con más de un informe antes de escribir nada en bloque. Detalle y tabla
de permisos en `PLAN_INDUSTEC.md` T2.22.

**Lo que NO se comprobó:** igual que en §1h, que la administradora efectivamente
haya cerrado el 10353660 en SAP — es la premisa que dio Andrés para este caso
puntual, no un hecho verificado contra SAP.

---

## 1j. El saneamiento nocturno arreglado, y su primera corrida real (2026-09-21)

Andrés dio acceso de administrador y pidió resolver el pendiente que InspectorBot
dejó a la vista en §1f: la Tarea «INDUSTEC - Saneamiento nocturno» nunca había
corrido. Quedó **arreglada, probada y ejecutada de verdad**, con evidencia
cruzada contra la base.

**Dos bugs, no uno.** El modo «solo interactivo» era la causa documentada, pero
al exportar la definición de la Tarea (`config/tarea_nocturno_original.xml`,
guardado como respaldo) apareció un segundo bug independiente: el comando estaba
**partido en el espacio de «INDUSTECH IA»** — `Command: D:\INDUSTECH`,
`Arguments: IA\desarrollo\agentes\scripts\saneamiento_nocturno.bat`. Un comando
que no ejecuta nada con sentido, sin relación con la cuenta que la corre. Las
otras dos tareas del proyecto no tienen este defecto. Recreada con
`Register-ScheduledTask`: mismo horario (diaria 02:30, repetición cada 30 min
por 2 h), cuenta `NT AUTHORITY\SYSTEM` (`LogonType ServiceAccount`), comando
corregido.

**Un tercer bug, esperado por el error nº 20 del plan pero con una forma nueva.**
SYSTEM no tenía acceso a `config/clave_hostinger`. Dárselo con `icacls /grant`
(sumando el permiso, no reemplazando) **no sirvió**: el ssh de Windows rechazó
la llave por «UNPROTECTED PRIVATE KEY FILE» — no porque SYSTEM sea untrusted,
sino porque **el ACL mezclaba dos cuentas distintas** (`indus` y `SYSTEM`), y
eso el ssh de Windows lo trata igual de mal que un permiso abierto a cualquiera.
La llave original volvió a como estaba (solo `indus`); se creó una **copia
exclusiva** `config/clave_hostinger_system` (mismo contenido, ACL con **solo**
`SYSTEM:(F)`, sin herencia) y la Tarea la usa vía `INDUSTEC_LLAVE_SSH`, inyectada
en el propio comando de la Tarea — no toca el `.bat`, así que una corrida manual
de Andrés sigue usando su propia llave sin cambiar nada. Verificado con
`hostinger_ssh.py --probar` corrido como una Tarea temporal bajo SYSTEM antes de
tocar la Tarea real: `OK: conexion, docroots y herramientas`. La copia nueva
está en `.gitignore` (no es la misma llave que la del usuario, aunque el
contenido coincida).

**Validado en tres capas antes de la corrida real**, seco primero: (1) SSH bajo
SYSTEM con la llave dedicada, de solo lectura; (2) un `--ensayo` con la
configuración REAL de la Tarea (SYSTEM, comando corregido, llave dedicada) — los
8 pasos en `ok`, confirmando que python arranca, encuentra el venv y resuelve
las rutas bajo SYSTEM; (3) recién con eso en verde, Andrés autorizó la primera
corrida real, supervisada.

**La primera corrida real de la historia de este mecanismo — resultado
verificado, no solo el `ok: true` del propio script:**

```
OK · 213.1 min · sync:ok(9083s) · volcado:ok(9s) · normalizar:ok(52s) ·
buzon:ok(31s) · ingesta:ok(3466s) · informes:ok(9s) · archivo:ok(55s) ·
pdfs:ok(80s)
```

- **sync**: primera reconciliación **completa** (sin `--recientes`, a diferencia
  de los sync parciales del vigilante): 9.970 archivos en el servidor, **7.622
  bajados** de una vez, 0 fallidos, 0 divergentes. Es la causa de los 213 minutos:
  cada archivo abre su propia sesión SSH y nunca se había corrido sin el límite
  de tiempo. Los cinco módulos que cuenta InspectorBot (`_ORIGEN_SISTEMA`)
  apenas se movieron (2.320 → 2.320, ya los mantenía al día el vigilante); el
  grueso de lo bajado fue el módulo de la app nueva, que el vigilante nunca
  toca (corre con `--sin-app`).
- **ingesta**: 7.467 documentos procesados, 0 errores de extracción (4 avisos
  `RECUPERADO_CON_FECHA_NULL`, el camino de recuperación ya previsto para fechas
  mal formadas, no una falla).
- **normalizar**: 10 promovidos, 2.289 ya estaban, 13 repetidos resueltos por
  hash, **8 sin resolver** (6 del módulo OTROS, 2 por nombre de local ambiguo:
  `RestauranteElvita`, `MENESTRASDELNEGRO` — pendientes de revisión, no urgentes).
- **Verificado contra una fuente independiente, no solo contra el JSON que
  escribió el propio script (I-10):** `SELECT COUNT(*) FROM ots` en vivo dio
  **7.813**, con **351** en cuarentena — exacto contra lo que reportó el log. Y
  la fila en `bitacora` (`accion='SANEAMIENTO_NOCTURNO'`, id 9, la primera que
  existe) trae el JSON completo, idéntico al de `estado_nocturno.json`.

**Un defecto cosmético encontrado, ajeno a este cambio.** Los pasos `archivo` y
`pdfs` traen texto corrupto en la respuesta de `archivo_indexar_cli.php`
(`índice` → `Â\xadndice`, `aquí` → `aquÃ\xad`) **mientras el resto del mismo
texto capturado —`·`, `gestión`, `histórico`, `órdenes`— decodifica bien**. Al
romperse solo esas dos palabras y no el resto de la misma cadena, no es un
problema de codificación de consola de SYSTEM (eso habría roto todo el bloque
por igual): es un defecto ya existente en el PHP del servidor, que esta corrida
solo dejó a la vista por ser la primera vez que su salida se captura completa en
un JSON. Cosmético — no afecta los datos, solo el texto del registro. Pendiente
para cuando se toque `archivo_indexar_cli.php`, sin prisa.

**InspectorBot ya lo confirma solo:** la alerta grave «el saneamiento nocturno
no ha corrido nunca» **desapareció** al recalcular el estado después de la
corrida. Salud pasó de GRAVE a MEDIO (quedan solo avisos leves de siempre: 6
casos con alerta para la administración, 2 sin local resuelto).

**Lo que NO se pudo comprobar:** que la Tarea arranque en un inicio de sesión
real de Windows (sin nadie loggeado) — con `LogonType ServiceAccount`, SYSTEM no
necesita que haya una sesión iniciada para correr, a diferencia de las otras dos
tareas del proyecto (todavía en modo «solo interactivo»), pero eso es una
propiedad documentada de `ServiceAccount` y no algo que se haya visto disparar
de verdad sin sesión abierta.

---

## 1k. La pantalla de preventivos, rediseñada (2026-09-21, T2.23)

Pedido de Andrés, textual: *«la interfaz de preventivos me parece muy
aturdidora, quiero que sea más entendible y fácil de navegar, que no agolpe con
tanta información»*. Y un segundo pedido en la misma conversación: *«busca
información de otros softwares similares de cómo lo hacen y adopta las mejores
prácticas»*.

### Lo que se midió antes de tocar nada

La pantalla se levantó en una maqueta local con **los 368 ingresos reales de
2026** (`SALIDAS IA/OTS/catalogos/cronograma_preventivo.json`), se capturó con
un navegador de verdad y se contó lo que se ve. No era una impresión: era esto.

| Lo que medía | Antes | Después |
|---|---|---|
| Elementos informativos antes de desplazarse (admin, 3 zonas) | **~110** | **~28** |
| Tarjetas de cifras en la primera pantalla | 8, más 3 recuadros por zona con 6 cifras cada uno = **26 números** | **0** — una frase |
| Chips de la franja de alertas | hasta **5** | **1 línea**, máximo 2 cifras |
| Leyenda de colores permanente | **7 entradas** siempre visibles | plegada, en «¿Qué significa cada estado?» |
| Veces que aparece la palabra «sin kit» en el calendario | **~60** (una por chip: el 86 % del año lo tiene pendiente) | **0** — un punto, y solo donde el kit todavía se puede reclamar |
| Cifras que eran un cero permanente | **3** (cumplidos, reagendados, «0 % a tiempo») | **0** — un filtro en cero no se dibuja |

### Tres cifras que la pantalla mostraba mal, y que el rediseño destapa

Esto no es estética: son números que se leían al revés.

1. **176 ingresos (48 %) figuraban «en curso».** La regla del servidor
   (`Reportes::estadoPreventivo`) marca «en curso» todo lo que tenga una orden
   emitida, **sin caducidad**: 145 de esos 176 habían arrancado **antes de
   agosto**, y los hay de enero. Un ingreso de enero pintado de azul en
   septiembre no está en curso: está **sin cerrar**. La pantalla ahora lo dice
   con esa palabra y lo pone en su propio bloque — **174 al 2026-09-21**. El
   contrato con el servidor no se tocó: para él siguen siendo `encurso`.
2. **La tarjeta de cumplimiento decía «0 % a tiempo» en rojo.** No es que se
   incumpliera el 100 %: es que **no hay ningún ingreso cerrado** en todo 2026,
   así que no hay porcentaje que calcular. Ahora dice «Sin cifra todavía» y
   explica por qué (I-7).
3. **«Sin kit» salía en casi todas las filas** porque 316 de 368 (86 %) tienen
   el kit pendiente. Un distintivo que sale en el 86 % de los casos no
   distingue nada. Ahora se marca solo donde el kit todavía se puede reclamar
   —el ingreso aún no arrancó y arranca dentro de 15 días— y, cuando en una
   lista faltar el kit es la norma (≥ 70 %), se marca **la excepción**: los
   pocos que sí lo tienen listo, con la cifra completa en la cabecera.

### Cómo quedó

Tres horizontes y **uno solo a la vez**, que es como lo resuelven los sistemas
de mantenimiento: el mes sirve para ver dónde se amontona el trabajo, la
quincena es donde se decide, y el año es lo que se le reporta al cliente.

- **«Lo que toca»** (la que abre): una frase con lo que hay que hacer, filtros
  que son también el resumen (la cifra va dentro del botón que la filtra) y
  cuatro bloques plegables — Vencidos, Sin cerrar, En marcha y por arrancar
  (15 días), Sin agendar. Cada bloque muestra 6 filas y un «Ver N más».
- **«El mes»**: el calendario, con un chip por ingreso y el código del local, y
  debajo la lista del mes. En el celular la rejilla de siete columnas se
  reemplaza por la lista, no se encoge.
- **«El año y KFC»**: el cumplimiento con su regla escrita, la tabla por zona
  y el avance mes a mes. Aquí sí tienen sentido las cifras por zona: es la
  pantalla de preparar el reporte, no la del trabajo del día.

### Lo que se adoptó de los CMMS de referencia

Se revisaron Limble, MaintainX, Fiix, UpKeep, MPulse, Sockeye e IDCON, y **SAP
PM**, que es además el sistema de Grupo KFC. Lo que entró:

| Práctica | Cómo quedó aquí |
|---|---|
| Tres horizontes (mes = densidad, semana/quincena = decisión, día = supervisión) | Las tres pestañas. El mes deja de ser el centro |
| El atraso es una **cola de trabajo**, no un color dentro del calendario | Bloques «Vencidos» y «Sin cerrar», ordenados por lo más atrasado |
| En el celular la rejilla del mes **se reemplaza** por lista, no se encoge | `@media (max-width:700px)` oculta el calendario y deja la lista |
| El kit es precondición de «listo para ejecutar» (*parts kitting*) | El kit se marca donde todavía sirve, con su cifra por bloque |
| La fórmula del cumplimiento **se declara**, no se supone | Escrita en «El año y KFC», con por qué reagendar no la maquilla |
| Programa **fijo** (la fecha sale del acuerdo) frente a flotante (sale del cierre anterior) | INDUSTEC es fijo puro, que es lo correcto para un contrato — y ahora la pantalla lo dice |
| Filtros por zona, y visibilidad por rol | Ya existían; se conservan tal cual |

### Verificado

```
node pruebas/prueba_barra_tecnico.mjs                    → 11 · 0 fallos
PHP_BIN=D:/SOFTWARE/PHP83/php.exe node pruebas/prueba_contratos.mjs → 57 · 0
D:/SOFTWARE/PHP83/php.exe pruebas/prueba_48h.php         → 120 · 0
node pruebas/prueba_graficos.mjs                         → 62 · 0
```

Y contra la maqueta con los 368 ingresos reales, contando **las filas que
pinta el código**, no las que debería pintar:

```
BLOQUES DE «LO QUE TOCA»
vencido=82    (cabecera dice 82)
sincerrar=174 (cabecera dice 174)
quincena=19   (cabecera dice 19)
sinagendar=16 (cabecera dice 16)
suma en bloques = 291
```

**291 + 77 planificados a más de 15 días = 368.** Cuadra exacto: ningún ingreso
se queda fuera de todos los bloques. Ese cuadre encontró un defecto real antes
de subir nada: un ingreso que debía arrancar hace tres días y cuyo fin es hoy
—todavía no vencido— **no caía en ningún bloque** y desaparecía de la pantalla.

Capturas de los tres roles (administración, jefe de LARB, técnico en celular de
390 px) y de las tres pestañas, revisadas una por una con navegador real.

### Lo que NO se pudo comprobar

- **No se desplegó a darkviolet** ni se corrieron las baterías contra el
  servidor (`verificar_http.py` y las otras seis). Lo verificado es local y
  contra maqueta con datos reales.
- **No se probó el guardado de verdad**: la maqueta intercepta `fetch`, así que
  agendar, reagendar, kit, cierre y novedad se comprobaron **por lectura del
  código** —los cinco cuerpos JSON quedaron idénticos a los de antes— y por la
  batería `verificar_reportes.py`, que **no se corrió en esta sesión**.
- **No se midió con una persona usándola.** Las cifras de arriba son de
  elementos en pantalla, no de tiempo hasta encontrar algo.

### `sw.js` subió a v11

`cronograma.html`, `.css` y `.js` están en la lista de precarga del trabajador
de servicio. Sin subir la versión, a quien ya tiene la aplicación instalada le
seguiría saliendo la pantalla vieja aunque el servidor tenga la nueva. Quedó
anotado en el propio `sw.js`, porque el comentario decía «se sube cuando cambia
la lista» y el caso real es «cuando cambia el contenido».

---

## 1l. Continuidad entre casos: un trabajo, varios avisos (2026-09-21, T2.25)

**Pedido de Andrés**, mirando la cuenta de Anthony Jumbo (`ajumbo`): en la ficha
de un caso el técnico solo podía *emitir la orden de cierre* o decir *el equipo
quedó trabado*. Faltaba la tercera salida, porque **SAP cierra solo el aviso que
nadie atendió en 48 horas** y KFC abre otro por el mismo equipo.

**El caso que lo destapó** es el que él tenía en pantalla: el horno
`HORNO-S/M-2023-118` de `G006EC` tiene **cuatro avisos por el mismo problema**
—10342524 (18-jul), 10342924 (20-jul), 10343636 (23-jul), 10349666 (20-ago)—
y para el segundo KFC ya está pidiendo «el repuesto del horno», o sea que el
técnico ya había ido y el aviso con el que empezó murió solo.

**Cuánto pesa, medido contra los datos reales:**

| Fuente | Ventana | Cifra |
|---|---|---|
| `casos_sap.json` (buzón vivo) | 918 casos, 90 días | **185 grupos** local+equipo con más de un caso · **489 casos** implicados · **94 de los 304 pares** consecutivos a ≤7 días (11 el mismo día, 24 a 1-2 d, 59 a 3-7 d) |
| `casos_sap.json`, lo que vería el técnico | 30 días atrás | **231 de 918 casos** tienen al menos un candidato a trabajo anterior |
| `avisos_sap` (histórico SAP) | 6.450 avisos, ene–ago 2026 | **509 avisos** nacen dentro de la semana de otro del mismo local y equipo · **68** el mismo día |

**Los dos agujeros que se taparon:**

1. `mis.php` no tenía salida para esto: el técnico o emitía **una orden
   duplicada** o dejaba el caso pendiente para siempre.
2. `Pendientes::resolverPorOrden()` filtraba por `aviso = ?`. Si el repuesto
   quedaba trabado en el aviso viejo y la orden se emitía sobre el nuevo, **el
   pendiente del viejo se quedaba abierto con su reloj de 48 h corriendo**, sin
   ninguna ruta que lo cerrara. Ahora acepta la cadena entera.

**Lo que NO hace, porque los mismos datos lo desaconsejan.** No cierra nada
automáticamente. En `J022EC` el par del mismo equipo es «informe técnico para
dar de baja» → «instalando el nuevo equipo», y en `K124EC` «no emite sonido» →
«escape de aceite»: trabajos distintos sobre el mismo equipo. El sistema
**propone** (local + equipo + 30 días, ordenado por cercanía, con el texto de
KFC a la vista) y **el técnico confirma de un toque**; queda en bitácora a su
nombre y el jefe de zona lo ve en `casos.php`. Es la decisión de Andrés del
2026-09-21, sobre tres opciones. **`avisos_sap.estatus_general` no se toca**
(regla 5 de §6): un caso enlazado queda ATENDIDO *nuestro*, no cerrado en SAP.

### Qué quedó verificado, y con qué

Las cuatro baterías locales, corridas con el PHP 8.3 de la estación:

```
php pruebas/prueba_continuidad.php     ->  36 comprobaciones · 0 fallos   (nueva)
php pruebas/prueba_48h.php             -> 120 comprobaciones · 0 fallos
PHP_BIN=… node pruebas/prueba_contratos.mjs ->  57 comprobaciones · 0 fallos
node pruebas/prueba_graficos.mjs       ->  62 comprobaciones · 0 fallos
```

`prueba_continuidad.php` corre **sin base**, contra el catálogo real, y fija el
caso del horno como prueba: que 10342924 propone 10342524 a dos días, que
10343636 propone los dos anteriores en orden de cercanía, que 10349666 solo
propone lo que cae dentro de los 30 días (10343636 a 28 entra; los de 31 y 33
no), que una freidora del mismo local no entra, que un ciclo escrito a mano en
la base no cuelga la pantalla, y que consultar 918 casos no enlaza ninguno.

### Contra el servidor: 23 · 0, y el bug que solo se veía ahí

Andrés autorizó aplicar y desplegar el 2026-09-21. **La 012 está aplicada** en
darkviolet —con respaldo previo de `casos_gestion` (1.041 filas, 266 KB, en
`~/respaldos/casos_gestion_pre012_20260922_001719.sql`)— y los seis archivos
desplegados, con el desplegador confirmando que *«la web entrega exactamente lo
que se subió»*.

```
php verificar_esquema.php        -> TODO OK
  migracion 012
    casos_gestion lleva la continuidad (5 columnas)          5    OK
    indice idx_gestion_continua                              si   OK
    permiso casos.continuidad repartido a los cuatro roles   4    OK
    (casos enlazados a un trabajo anterior: 0)

python verificar_continuidad.py  -> 25 de 25 comprobaciones pasan; 0 fallan
python verificar_http.py         -> 86 de 86 comprobaciones pasan; 0 fallan
python verificar_bandeja.py      -> 37 de 37 comprobaciones pasan; 0 fallan
```

El recorrido que prueba es el del caso real: el equipo queda trabado en el aviso
viejo → **la rama de control** (sin enlace, la orden del aviso nuevo no toca ese
pendiente: sigue en ENTREGADO) → el técnico declara que es el mismo trabajo →
enlazar al revés se rechaza y otro técnico no puede enlazar un caso ajeno → la
orden nueva deja **los dos casos con la MISMA orden de cierre**
(`OT-9076-G018EC-99990012-UIO`) y el pendiente del viejo en **RESUELTO**, con la
nota diciendo con qué orden → el catálogo de SAP con **la misma huella**
(`d4bea28db752…`), ningún caso cerrado con un pendiente vivo, ninguno
continuándose a sí mismo → el enlace se deshace → y el **bloque 8** devuelve
el terreno como estaba y lo comprueba: los sintéticos vuelven a
`{99990011: ASIGNADO, 99990012: ATENDIDO}` y no queda ningún pendiente vivo.

**🔴 El bug que solo apareció en el servidor, y por qué importa.** La primera
corrida dio **21 · 2**: los dos casos quedaban sin orden de cierre aunque el
pendiente sí se cerraba y el recibo decía que todo había ido bien. La causa:
`Casos::cadena()` devolvía `array_keys()`, y **PHP convierte a int las claves
numéricas de un array** — un aviso es todo dígitos. Con `declare(strict_types=1)`,
`atenderPorOrden(string $aviso, …)` lanzaba `TypeError`, la excepción se tragaba
en el `catch` de `envio.php` y **la orden se emitía sin cerrar ningún caso de la
cadena, en silencio**. Ni la prueba local lo veía (comparaba con `implode()`,
que convierte) ni el `error_log` de CLI (los errores de la web van a
`~/.logs/error_log_<dominio>`). Arreglado con `array_map('strval', …)` y **fijado
con una comprobación de tipos** en la prueba local, que por eso pasó de 35 a 36.
Es el mismo tropiezo que `Casos::delTecnico()` ya documentaba desde antes.

**`sw.js` NO cambió de versión:** no se tocó ningún archivo de la lista de
precarga (`mis.php` y `casos.php` son PHP servidos en vivo). Sigue en **v11**,
la que dejó T2.23.

### Dos baterías que chocaron, y lo que se arregló para que no vuelva

`verificar_bandeja.py` costó dos vueltas, ninguna por el código de T2.25:

1. La primera dio **17·37**, por lanzarla **en paralelo** con `verificar_ciclo`
   y `verificar_emision`. Las tres usan las mismas cuentas de prueba y se
   pisaron las sesiones: el síntoma fue un `401 sesion_requerida` donde se
   esperaba un 403, y un `KeyError: 'avisos'` en emisión porque
   `catalogos.php` le respondió sin sesión. **Las baterías de servidor se
   corren de a una.**
2. La segunda, corrida sola, **abortó** con «los avisos sintéticos no están
   como los deja `preparar_prueba.php`». Era cierto:
   `verificar_continuidad.py` los dejaba a los dos en ATENDIDO, y bandeja los
   necesita uno ASIGNADO y otro ATENDIDO para distinguir la bandeja del
   historial. **Arreglado en la propia batería**, que ahora cierra con un
   bloque 8 que devuelve el terreno como lo encontró —los dos casos a su
   estado original y el pendiente de la prueba a CANCELADO, no borrado— y lo
   **comprueba con sus propias afirmaciones** en vez de confiar en que salió
   bien: por eso pasó de 23 a 25 comprobaciones, y la última corrida deja
   `{99990011: ASIGNADO, 99990012: ATENDIDO}` y 0 pendientes vivos. Son los
   errores nº 30 y 31 del plan.

   **Confirmado de vuelta:** `verificar_bandeja.py`, corrida sola después de
   `verificar_continuidad.py`, da **37 · 0**. Las dos conviven.

### 🔴 Lo que sigue sin comprobarse

- **Nadie la ha usado todavía con datos reales.** Las 25 comprobaciones corren
  sobre los avisos sintéticos del arnés (`99990011`/`99990012`), a propósito:
  así la prueba no mueve ni un caso del cliente. La **propuesta automática** —la
  que mira local + equipo + 30 días— solo está probada contra el catálogo, sin
  base, con el caso del horno de `G006EC`. Falta verla en la pantalla real.
- **No la ha mirado Andrés.** El criterio de si la propuesta se entiende en un
  celular, con guantes, es suyo.
- El arnés de pruebas quedó **puesto** en el servidor (`preparar_prueba.php`
  corrido: cinco cuentas de prueba y los casos 10356012 y 10355931 asignados al
  técnico A). Se revierte con `php ~/respaldos/deshacer_prueba.php` cuando ya no
  haga falta para las otras baterías.

---

## 1z. T2.29 · El envío real de las OT INDUSTEC, ACTIVO en UIO, LARB y CNLJ, y la cuenta de envío configurable (2026-09-29, noche, pedido de Andrés, ✅ salvo T2.29.7)

**Qué cambió para cada persona, desde las 21:45 del 29-sep:**

- **Técnico (UIO, LARB, CNLJ):** ya no ve la franja del piloto. Su OT sale con la
  numeración de siempre y el recibo dice a quién salió el correo según la cola
  (local / Grupo KFC / administración). Si no escribió el correo del
  administrador del local, se le dice que el local no lo recibió. Y se le pide
  **no emitirla otra vez en el formulario de siempre**.
- **Grupo KFC, el local y la administración:** reciben cada OT como la mandaba
  el viejo. El remitente es «Ordenes de Trabajo INDUSTEC» <reclutamiento@industec.me>;
  el asunto, `ORDEN DE TRABAJO INDUSTEC - OT-…`; el cuerpo es el que lee el robot,
  y va el PDF adjunto.
- **Administración (Correos):** pestaña **«Envío de las OT»** (el estado por zona,
  el número desde el que sigue cada serie, la vigilancia del formulario viejo, la
  cola —enviados, por enviar, no salieron, retenidos— y los últimos 25 correos con
  su motivo) y pestaña **«Cuenta de envío»**. Activar o volver al piloto y
  cambiar la cuenta lo hace solo un SUPERADMIN.

**Evidencia, en el orden en que se hizo (salida literal resumida):**

| Paso | Comando | Resultado |
|---|---|---|
| Volcado previo | `t2_4_volcado_bd.py --sin-retencion` | `volcado_20260929T211808Z.sql.gz` · sha256 `e98f9a4e…` · OK |
| Migración, antes en local | volcado restaurado en `industec_prueba_023` + `aplicar_sql.php` | **destapó el error 1052** (nº 54) → corregida; después 10/10 sentencias, dos veces (idempotente), `verificar_esquema.php` TODO OK. La base local se borró al terminar |
| Migración en darkviolet | `php aplicar_sql.php sql/023_envio_real.sql` | 10/10 · `verificar_esquema.php` → **TODO OK** (bloque «migracion 023»: 4 tablas, `uq_cuenta`, `uq_cuenta_activa`, 4 zonas, columnas, 2/2 permisos solo SUPERADMIN, series 9xxx con su copia `PRUEBA:`) |
| Despliegue | `t2_10_desplegar.py` (14 + 6 archivos) | 14/14 y 6/6 por hash; «la web entrega exactamente lo que se subió». Antes: los 10 reemplazados eran `= HEAD` en el servidor |
| Cuenta de envío | `correo_cuenta_importar_cli.php --ejecutar --probar --a-nombre-de abasantes` | 5/5 copias del viejo iguales; cuenta 1; «la clave guardada se descifra con la misma huella que la del viejo (largo 13, huella 1f9f059a)»; activa; «conectó con smtp.titan.email:587 y el servidor aceptó el usuario y la clave» |
| Correo de prueba | `envio_real_cli.php prueba servicioalcliente@industec.me` | referencia `P-20260929-212519-0272`; **IMAP (solo lectura): llegó a INBOX de servicioalcliente@**, de «Ordenes de Trabajo INDUSTEC <reclutamiento@industec.me>», con `prueba-P-…pdf` (17.263 bytes) |
| Baterías, antes de activar | una por limpiar+preparar | `verificar_emision.py` **69/69** · `verificar_ciclo.py` **54/54** · `verificar_continuidad.py` **30/30** · `verificar_bandeja.py` **37/37** · `verificar_http.py` **89/89** |
| Activación | `envio_real_cli.php activar UIO\|LARB\|CNLJ --a-nombre-de abasantes` | UIO: viejo 1946 → serie 1951 (primera **1952**), preventivo 226 → 231 (**0232**) · LARB: 2321 → 2326 (**2327**), 353 → 358 (**0359**) · CNLJ: 2646 → 2651 (**2652**), 242 → 247 (**0248**) |
| Después de activar | `verificar_esquema.php`, `Emision::modo()`, `verificar_emision.py` | TODO OK · UIO/LARB/CNLJ/sitio `PRODUCCION`, OTRA `PRUEBA` · 69/69; los correos de la cuenta de prueba (OT-8001…8005, UIO) **RETENIDO «cuenta de prueba»**; series reales intactas |
| Limpieza | `limpiar_pruebas.php` | 0 cuentas `_prueba`; nada suelto en el Archivo |
| El despacho en lote, contra Titan | script de una vez (`SMTPKeepAlive`, un `smtpConnect()`, dos `send()` por la misma conexión, como `Correo::despachar()`) | referencia `L-20260929-215226`: **llegaron los 2** a servicioalcliente@ (IMAP), cada uno con su PDF (14.506 bytes). El script se borró del servidor |
| Correos, con una sesión de ADMIN (cuenta de prueba) | GET y POST por HTTP | «Envío de las OT» 200: aviso verde, reclutamiento@, próximas OT-1952/2327/2652, **sin** botones de activar para ADMIN · «Cuenta de envío» 200, solo lectura, sin campo de clave · POST `zona_piloto` y `cuenta_probar` de ADMIN → **403** (UIO sigue en PRODUCCION) · sin errores de PHP |

Locales: `prueba_envio_real.php` **112·0**, `prueba_ot_piloto.php` **119·0** (la falla
previa del `sw.js` v25 se corrigió), el resto sin cambios (`prueba_contratos` 57·0,
`prueba_franja_piloto` 20·0, etc.); **integración local** sobre el volcado, con dobles de
PHPMailer y dompdf: **81·0** (activar, emitir 1951, saltar 1952 del Archivo, saltar sobre
el viejo, retener ENSAYO/piloto, CUENTA_FALLA sin gastar intentos, 550 → FALLIDO,
cupo por hora, reencolar con intentos en 0, volver al piloto, reactivar sin bajar,
activar LARB después de una OT de ensayo → 2325).

**Defectos encontrados y corregidos antes de activar** (los cuatro, en los errores
nº 54 a 57 del plan): el 1052 de la migración y del sembrador `t2_14`; `mayorNumeroReal`
contando la serie de ensayo (lo encontró la revisión adversarial: habría sembrado en
8001); la alarma del formulario viejo que no avisaba nunca; y el orden de las
asignaciones del `ON DUPLICATE` de la cola. Además: `t2_14` nunca bajaba un contador
del piloto (`GREATEST` con 9205), y `verificar_http.py` dependía de los datos reales.

**Lo que NO se comprobó (I-7):**
- **Ninguna OT real salió todavía.** Hasta las 21:55 no entró ninguna desde la
  activación. El envío de la cola con el SMTP real se probó por partes: el mismo
  `Correo::mailer()` mandó el correo de prueba con adjunto; el lote de dos por una
  sola conexión (el patrón de `despachar()`) llegó entero; y `despachar()` con su
  cola se probó con dobles (integración 81·0). **La primera OT de un técnico es
  T2.29.7**: mirar `php envio_real_cli.php estado` (la cola) y el correo en
  servicioalcliente@.
- **La revisión adversarial quedó a medias:** de cuatro revisores, tres cayeron
  por el límite de sesión (numeración, envío y seguridad) y solo respondió el del
  flujo completo. Esas tres lentes las cubrí yo leyendo el código y con las
  pruebas, no con revisores independientes.
- **No hay cron en hPanel** para el despachador ni para el reemisor. Sin él, un
  correo que falle se reintenta con la siguiente OT que entre o con «Enviar
  ahora» en Correos. Lo programa Andrés.
- **Las pantallas nuevas de Correos no se vieron con la sesión de un
  SUPERADMIN** (no hay cuenta de prueba con ese rol): se vieron con la de ADMIN
  (arriba), y los botones que solo ve el SUPERADMIN (activar, volver al piloto,
  probar, correo de prueba, agregar y usar otra cuenta) se ejercitaron por la
  línea de órdenes, que llama a las mismas funciones. Nadie los pulsó en un
  navegador.
- **Diferencia con el viejo que decide Andrés:** el viejo mandaba cada OT
  también al **jefe de operaciones de KFC del local** (lo escribía el técnico). En el
  nuevo va por local en Correos y hoy no hay ninguno cargado. Propuesta en
  `SALIDAS IA\OTS\propuesta_jefes_operaciones_por_local_2026-09-29 (generado agente).csv`
  (96 locales). Otra: Miguel Vásquez (KFC) recibe también los preventivos y los
  correctivos de CNLJ, porque así lo cargó la administración; en el viejo no le
  llegaban.
- **Los técnicos todavía no están avisados.** Esa noche el formulario viejo se
  siguió usando (UIO pasó de 1945 a 1946 entre la tarde y la activación). Si lo
  siguen usando después de activar, KFC recibe el trabajo dos veces. Correos lo
  avisa como «usos del viejo desde que se activó», y la app numera por encima.
- **El robot del buzón va a ver tres correos de prueba** de reclutamiento@ en
  servicioalcliente@ (`P-20260929-212519-0272` y los dos `L-20260929-215226`).
  No dicen «nueva OT:», así que `t2_11_informes_ot.py` los cuenta como «sin
  parsear» y no los toma por OT: no es una falla. Las OT reales de la app sí
  llevan el cuerpo de siempre y el robot las procesa como las del viejo. Su
  nombre trae el local con `EC` (OT-1952-G018EC-…), la forma canónica.

---

## 1z-bis. T2.29.7 y T2.29.8 · La primera OT real, el choque de numeración con el formulario viejo y las diez OT del piloto enviadas a Grupo KFC (2026-09-30 / 10-01, pedido de Andrés, ✅ salvo la decisión T2.29.9)

**Qué pasó, en orden.**

1. **30-sep 12:55 — la OT que «no salía bien».** Anthony Morales (`amorales`) llenó la OT del aviso 10354880 (M063EC). El celular mostró «Enviando 1 OT INDUSTEC…» y el nombre `OT-NNNN-M063EC-10354880-UIO.pdf`. **El NNNN no es un defecto**: es el recibo provisional (`app.js`), porque el correlativo lo reserva el servidor al emitir. La OT llegó al servidor a las **13:11:04**, 16 minutos después: la primera foto subió a las 12:55:46 y la segunda, junto con la orden, a las 13:11:04. El servidor no registra ningún intento entre las dos horas. **Causa probable, no comprobada:** `cola.js` no pone tiempo límite a sus `fetch`; un envío colgado deja `enviando` en true y bloquea todos los reintentos (T2.29.10).
2. **13:11 — salió bien.** Emitida `OT-1952-M063EC-10354880-UIO` (captura 293); correo ENVIADO a las 13:11:08 desde reclutamiento@ (correo 287). **No apareció en el Archivo** hasta el índice nocturno del 1-oct a las 03:50: `ot_archivo` se llena de noche, no al emitir.
3. **El choque de números.** El formulario viejo siguió en uso y alcanzó los números que la app había tomado (UIO: 1946 → 1965 en 45 h; LARB 2335 y CNLJ 2664 al 1-oct). Hoy hay dos repetidos en UIO:

   | Número | La app | El formulario viejo |
   |---|---|---|
   | **OT-1952** | M063EC · aviso 10354880 · 30-sep 13:11 | K170 · aviso 10352225 · 30-sep 23:56 |
   | **OT-1964** | G021EC · aviso 10358019 · 1-oct 09:49 | G018 · aviso 10357626 · 1-oct 11:33 |

   Dos de dos OT reales de la app chocaron: el margen de 5 se agotó en un día.
4. **Lo que cuesta aplicar la regla de Andrés** («deja la del viejo y elimina la de la app») **a esas dos.** Ninguna fuente muestra OT del formulario viejo para sus avisos: cada una es **la única OT de su trabajo**, y las dos ya estaban en Grupo KFC. El 10354880 lo cerró Isabel en SAP el 30-sep a las 13:19:47 **citando esa OT**, y a las 13:20:03 aprobó el equipo nuevo que esa OT propuso. El 10358019 tiene una solicitud de repuesto viva (pendiente 116) y Isabel abrió su PDF. Además, el robot ya archivó la OT-1952 de la app en la estación (árbol canónico y `ots`) y, como relee el buzón cada 3 h, la volvería a traer si solo se borrara. **Andrés decidió, con estos hechos a la vista (1-oct, noche): dejarlas y anotarlas** (T2.29.9; ver «Lo que decidió Andrés esa noche», abajo).

**T2.29.8 — las 10 OT del piloto.** Andrés pidió: si el formulario viejo ya reportó el trabajo, el caso se resuelve con ese informe; si no, enviar la OT que solo existía en el sistema y dejar el caso pendiente de SAP. La conciliación (`SALIDAS IA\OTS\conciliacion_ot_piloto_2026-10-01 (generado agente).csv`) clasificó las 18:

| Clase | OT | Qué se hizo |
|---|---|---|
| Sin informe del viejo, trabajo reciente | 9125, 9128, 9146, 9147, 9148, 9149, 9150, 9151, 9152, 9153 | **Enviadas** |
| Cubierta por el viejo | 9154 (OT-1955 Cerrada, caso ya ATENDIDO) · 9131 (OT-1921 Abierta, caso sigue abierto) | Nada: ya las cubre el viejo |
| Re-registro: el aviso ya tiene OT del viejo, pero de una visita **anterior** | 9142, 9143, 9144, 9145 (Kevin) · 9001, 9002 (CNLJ, ftipan, sobre avisos que SAP cerró en agosto) | **Retenidas**, a la espera de Andrés |
| Real de la app, choca de número | OT-1952-M063EC, OT-1964-G021EC | Ver T2.29.9 |

**Cómo se verificó antes de mandar nada a KFC (I-10).** La estación (`t2_29_8_conciliar_ot_piloto.py`) volvió a comprobar, con tres fuentes que no dependen de la app, que los 10 avisos tienen **cero** OT del formulario viejo: el buzón servicioalcliente@ por IMAP de solo lectura (EXAMINE + BODY.PEEK, 759 correos de reclutamiento@ desde el 15-jun), la base de la estación (`ots`) y los PDF del servidor del formulario viejo (solo lectura). Bajó cada PDF, comprobó su huella contra la del servidor y la del Archivo, y leyó su texto (su número, su aviso y su local, y ninguna marca de prueba). Armó `ot_a_liberar.json` y recién entonces corrió la consola del servidor.

**Qué hace la marca «liberada» y por qué hizo falta.** El criterio «es del piloto» es el **número** (`Emision::esDePrueba`, serie ≥ 9000) y se repite en unos 50 sitios de la web: sin una excepción, una OT-9125 que sí llegó a KFC habría seguido figurando «del piloto · no enviada a KFC», no habría atendido el caso, no habría contado en los reportes y el despachador la habría devuelto a RETENIDO (D-7). La migración **024** agrega `ot_capturadas.liberada_en/por/nota`; `esDePrueba()` y `sqlEsDePrueba()` dejan de marcar lo liberado. Solo la libera `liberar_ot_piloto_cli.php`, con lista explícita y `--a-nombre-de`. Una OT-9xxx nueva en modo PRODUCCION sigue siendo un error.

**Evidencia, en el orden en que se hizo:**

| Paso | Comando | Resultado |
|---|---|---|
| Volcado previo | `t2_4_volcado_bd.py --sin-retencion` | `volcado_20261001T235314Z.sql.gz` · sha256 `96f185e8…` · OK (en `D:\RESPALDOS\_ORIGEN_APP\_bd\`) |
| Pruebas locales | `prueba_ot_liberadas.php` (nueva) · `prueba_ot_piloto` · `prueba_envio_real` · `prueba_despacho` · `prueba_destinatarios` · `prueba_continuidad` · `prueba_panel_zona` · `prueba_cifras_estado` · `prueba_48h` | **24·0** · 119·0 · 112·0 · 20·0 · 34·0 · 42·0 · 91·0 · 143·0 · 125·0 |
| Despliegue | `t2_10_desplegar.py nucleo/Emision.php` (antes: el del servidor = el de HEAD, `71438580…`) | «la web entrega exactamente lo que se subió»; `sql/024_…` y la consola, por scp con su hash |
| Migración | `php aplicar_sql.php sql/024_ot_liberadas.sql` (dos veces) | 1/1 sentencia; la segunda: «ya está aplicada»; las 3 columnas existen; 0 liberadas |
| Humo en el servidor | `Emision::esDePrueba` y `sqlEsDePrueba` desplegados; `login.php`; `catalogos/locales.json` | 9125 → piloto; 1952 y 1964 → no; SQL idéntico al de antes con el conjunto vacío; 200; **403** |
| Las sentencias, sin escribir | `liberar_ot_piloto_cli.php … --probar-sql` | Las 10 OT y sus 10 casos, dentro de **una transacción que se revierte**: `esDePrueba` tras liberar = no; REVERTIDO: liberadas 0 · PENDIENTE 0 · RETENIDO 18 |
| Ejecución | `t2_29_8_conciliar_ot_piloto.py --ejecutar` | Tres fuentes en cero → respaldo `~/respaldos/liberar_ot_piloto_20261001_190343` → 10 liberadas → **Despacho: enviados 10 · temporales 0 · fallidos 0** (19:03:46 a 19:04:00) |
| Llegada | IMAP de solo lectura de servicioalcliente@ | **10 de 10 con su PDF, huella = la del servidor**, para/cc como se calcularon hoy |
| Estado final | `SELECT` | 10 capturas ENVIADA con `liberada_en`; 10 correos ENVIADO desde reclutamiento@; 8 casos RESUELTO → ATENDIDO con su OT de cierre; 9153 ASIGNADO → ATENDIDO; 9147 ASIGNADO (la OT no concluyó); bitácora: 10 `OT_LIBERADA`, 10 `CORREO_ENVIADO`, 10 `CASO_POR_ENVIO_TARDIO` |
| Regresión en el servidor | `verificar_emision.py` · `verificar_http.py` (las versiones de HEAD, contra el código desplegado), un ciclo limpiar+preparar cada una | **69·0** · **89·0**. Arnés al final: 0 cuentas de prueba, 0 casos 9999 |
| Respaldo local | copia de `liberar_ot_piloto_20261001_190343` a `D:\RESPALDOS\_ORIGEN_APP\` | 14 archivos; `SHA256SUMS` 10 de 10 |

**Lo que se hizo con los casos, dicho sin adornos.** Los 8 casos que Isabel había marcado «cerrada en SAP» el 28-sep con un número del piloto (los otros 4 de las doce, los de las OT retenidas 9142 a 9145, siguen RESUELTO) **volvieron a ATENDIDO**, que es «pendiente de SAP», por orden expresa de Andrés («marcarla pendiente para procesar en SAP para que la administradora proceda según sea el caso»). La máquina de estados no tiene esa transición a propósito (ASG-18): se hizo con una sentencia propia, y el veredicto que tenían (quién, cuándo) quedó en `bitacora.datos.antes` de la acción `CASO_POR_ENVIO_TARDIO` y en la nota del caso. Para deshacerlo: el respaldo trae `filas_antes.json`.

**Lo que NO se comprobó (I-7).**
- **Ninguna pantalla con sesión** se abrió con las OT liberadas (buzón, Archivo, Mis órdenes): se verificó que `esDePrueba` da false, que el SQL evalúa bien en MariaDB y que las baterías HTTP pasan, pero esas baterías no ven las filas reales liberadas.
- **Qué hace el robot de la estación** con los 10 correos (llevan número 9xxx): no se observó; lo sabremos en su próxima corrida (cada 3 h) y en el saneamiento nocturno. El robot no trata la serie 9000 de forma especial; la web, con la marca, tampoco.
- **Que los destinatarios externos los hayan recibido o leído:** el SMTP aceptó los 10 y llegó la copia a servicioalcliente@; no hay acuse de los locales ni de KFC.
- **La causa de los 16 minutos** (ver arriba): no se comprobó.
- **Las baterías del árbol de trabajo:** se corrieron las versiones de HEAD porque otra conversación tiene `verificar_*.py` modificados sin commitear.
- `prueba_pdf_sin_franja.php` no corre en la PHP de la estación (sin `pdo_sqlite`) y, con el controlador, falla con «no such column: equipo_n» (su base en memoria no tiene las columnas de T2.28.7): **igual antes y después de este cambio**. `prueba_lista_negra` da 3 hallazgos, los tres en archivos de otra conversación (`t2_28_exportar_equipos.py`, `t2_28_marcas.py`, `app.js:1281`).
- **`verificar_esquema.php` no trae el bloque de la 024** (el archivo lo tiene modificado otra conversación): queda pendiente (T2.29.10).

### 1z-bis · Lo que decidió Andrés esa noche y lo que se hizo

| Decisión de Andrés | Qué se hizo | Evidencia |
|---|---|---|
| **OT-1952 y OT-1964: dejarlas y anotar** | No se borró nada. La bitácora lleva `CHOQUE_NUMERO_ANOTADO` para cada una (**#10044 y #10045**, por PHP). Las filas **#10042 y #10043** salieron del cliente mysql con la hora en UTC (+5 h) y el texto doble-codificado; la bitácora no se corrige, y las nuevas dicen que las reemplazan (error nº 63) | `SELECT … FROM bitacora WHERE accion = 'CHOQUE_NUMERO_ANOTADO'` |
| **El piloto es así:** LARB y CNLJ siguen con el formulario viejo; en Quito se empieza a usar el nuevo, y mientras se termina de corregir, cuando algo falla los técnicos mandan por el viejo, porque «no se puede dejar de enviar los informes a KFC» | **LARB y CNLJ volvieron al piloto** (19:39:59, `envio_real_cli.php piloto`, a nombre de abasantes). Nadie había emitido una OT real ahí: las dos reales de la app son de UIO. **UIO sigue en PRODUCCION.** En UIO la convivencia con el viejo es **por diseño**, así que los choques de numeración seguirán mientras el viejo sea el respaldo: **abierto** (T2.29.9) | `envio_real_cli.php estado`: UIO PRODUCCION; LARB y CNLJ PRUEBA |
| **Las seis retenidas: no enviarlas** (9142 a 9145, 9001, 9002) | Siguen RETENIDO. Los 4 casos de Kevin siguen RESUELTO con un número del piloto como OT de cierre (el aviso del buzón «cerradas en SAP con una OT del piloto» cuenta 4) | cola: RETENIDO 8, ENVIADO 12 |
| **Que la OT se vea en el Archivo al emitirla** | `Emision::emitir()` llama a `indexarEnArchivo()`: la OT entra a `ot_archivo`, con su huella, en el momento. El SQL del upsert vive ahora en `Emision::archivoGuardar()` y el índice nocturno lo usa: una sola copia. Nunca rompe la emisión (try/catch) | `verificar_emision.py` + la comprobación nueva: **70·0** (HEAD + 1). `verificar_archivo.py` (HEAD): **48·0 y 2 fallas ajenas** (ver abajo) |
| **Tiempo límite en el celular** | `cola.js`: cada petición se corta a su tiempo (sesión 30 s, foto 120 s, orden 120 s) y la cola sigue sola; son largos a propósito, para no cortar una subida lenta que sí avanza. **Se reprodujo el fallo** en Chrome con un servidor cuya primera subida de foto nunca contesta: con el `cola.js` de antes la cola queda trabada (1 pendiente, 0 envíos, 0 intentos); con el nuevo se recupera (2 peticiones de foto, 1 envío, 0 pendientes). Desplegado con el `sw.js` de HEAD y la versión `ot-industec-v27-cola` | `prueba_cola_timeout.mjs` (nueva): **antes FALLA, después PASA**. Contra el servidor: `verificar_sync_cerrada.mjs` **4·0** y `verificar_formulario.mjs` **39·0**. Locales: contratos 64·0, franja 20·0, offline OK |
| **Empujar a GitHub** | Empujado (`9a43f00..6fe6fa8`) por elección de Andrés, sabiendo que el repositorio es público | `git rev-list --left-right --count master...origin/master` → 0 0 |

**Para quien despliegue el `sw.js` del árbol de trabajo (v28, T2.28.11):** el servidor tiene hoy la variante `ot-industec-v27-cola` (= HEAD con otra cadena de versión), porque el `sw.js` del árbol lleva trabajo sin commitear de otra conversación. El v28 lista `partstown.js` en su precarga: hay que subirlo **a la vez** (con `index.html` y `app.js`), o el service worker no instala y la app pierde el modo sin señal. El `cola.js` del servidor ya trae el tiempo límite: no se toca.

**Lo que NO se comprobó de esta tanda (I-7).**
- **La causa de los 16 minutos sigue siendo una hipótesis**: se reprodujo el MECANISMO (una petición colgada traba la cola) pero no se vio en el celular de Anthony.
- **Los celulares reales:** el service worker nuevo se instala cuando abren la app con señal; no se verificó en un iPhone.
- **`verificar_archivo.py`: 2 fallas, ninguna de este cambio.** (1) «compartir una orden que no está en el servidor → 404» da 409: la batería usa una OT sintética de cinco cifras (`OT-99901-…`) y la regla del piloto del 28-sep la trata como del piloto. (2) «bitacora.php como administración con las filas del técnico» ya no las ve: `bitacora.php` (T2.28.9, otra conversación, ya desplegado) esconde las filas de cuentas de prueba por omisión. Hay que actualizar la batería (T2.29.10).
- **`verificar_emision.py` del árbol de trabajo** da 73 de 76: las tres fallas son de T2.28.11 (la captura del repuesto), trabajo de otra conversación que aún no está desplegado en `foto.php`.
- Las filas de la bitácora #10042 y #10043 quedaron con la hora y el texto mal; no se pueden corregir.

---

## 1y. Los 14 PDF del piloto, sin la franja y con la fecha corregida en 6: desplegado y EJECUTADO en darkviolet (2026-09-28, noche, decisiones A–E de Andrés, ✅)

**Conclusión.** Desde las 23:20 UTC del 28-sep (18:20 en Ecuador) ninguna OT sale con la franja «DOCUMENTO DE PRUEBA», tampoco en el sitio del piloto. Los 14 PDF de las capturas 166, 171, 176 y 193-203 (OT-9125…OT-9152) están regenerados sin ella, como copia interna: el mismo número, los mismos datos, las mismas fotos y firma, y la fecha de emisión original. En 6 de ellos la fecha de atención quedó corregida en el PDF y en el Archivo, y el registro del técnico sigue intacto. El índice del Archivo se corrió como lo corre el robot cada noche y la corrección se conserva. Queda **una OT del piloto con franja, la OT-9001-K061EC-10346775-CNLJ (captura 204)**. La emitió ftipan a las 17:30 de hoy, antes de este despliegue, y espera la decisión de Andrés (ver «Abierto», abajo).

**Revisión adversarial antes de desplegar** (6 hallazgos MENORES; commit `b6c1cc7`):
1. La frase «sumar la 204 es una línea» era falsa, porque la guarda del conjunto solo miraba las emitidas hasta el 27-sep. **Corregido:** ahora entra al conjunto toda captura que nombre `$REGENERAR`, sea cual sea su fecha. Además, ya ejecutada la regeneración, apareció otro tope: `--respaldar` abortaba si `SHA256SUMS` no era idéntico, así que sumar la 204 tampoco era una línea. **Corregido también:** `SHA256SUMS` suma lo nuevo y comprueba lo que ya estaba sin tocarlo; `filas_antes.json` no se reescribe nunca, y las filas de una captura sumada van a `filas_antes_<fecha>.json`. Se probó en el servidor, sobre una **copia** del respaldo en `~/regen_test_20260928/` (borrada después), con la 204 añadida:
   - simulacro: «las 15 del pedido … ✓», PENDIENTE 1 · HECHA 14;
   - `--respaldar`: 15 en `SHA256SUMS` y 15 OK en `sha256sum -c`, `filas_antes.json` con la misma huella `1caea957…` y la 204 sola en `filas_antes_20260928_183259.json`;
   - segunda corrida: «0 nuevos … ya tomadas».

   El respaldo real no se tocó: 16 archivos, 14 OK. La bitácora tampoco: siguió en #8221.
2. Si el proceso moría entre el `rename` del PDF nuevo y el `commit`, la captura quedaba INCONSISTENTE y no había remedio. **Mitigado:** el simulacro reconoce ese caso y dice el remedio exacto: `cp -p` del original del respaldo y `sha256sum -c`. No lo repone solo, porque un PDF que nadie registró no se pisa sin mirarlo.
3. En la primera emisión, el PDF tomaba `date()` antes de dompdf y `emitida_en` tomaba `NOW()` después. Si el render cruzaba un cambio de minuto, toda regeneración discrepaba en «generado automáticamente el». **Corregido en `Emision::emitir()`:** hay un solo instante (`$emitidaEn`), que va al PDF y a `COALESCE(emitida_en, ?)`. Si la orden ya estaba emitida, se usa la `emitida_en` leída bajo el candado. El `UPDATE` nuevo se validó contra MariaDB con `EXPLAIN`, que no escribe.
4. Al retrasar la fecha de las 6 también se corre hacia atrás el corte `desde` de `Reconciliar::atenciones()` y de `Casos::marcarDocumentos`. **No requiere código; queda anotado:** si llega por el buzón una OT de producción de esos avisos fechada entre la fecha nueva y la vieja, ahora reemplazaría a la del piloto como `ot_cierre`. Con la visita real en la fecha nueva, eso es lo correcto. Hoy ninguno de los 6 avisos tiene OT de producción en `ot_archivo`.
5. La consola no comprobaba el sitio. **Corregido:** sale con código 3 si `Emision::modo()` no es PRUEBA.
6. En el servidor, los archivos subidos desde el checkout de Windows quedan con CRLF, así que su sha256 crudo no es el del blob de git. **Documentado:** la comprobación previa se hizo contra las dos formas del blob, LF y CRLF (script `precheck_b.py` del scratchpad). `t2_10_desplegar.py` compara contra el archivo del disco local, que es lo que sube, así que no le afecta.

Pruebas locales tras la revisión:
- `prueba_pdf_sin_franja` **56·0** (antes 48), `prueba_ot_piloto` 118·0, vocabulario 156·0, claves 788·0, lista negra 0.
- `panel_zona` 91·0, `cifras_estado` 143·0, `48h` 125·0, despacho 20·0, destinatarios 22·0, continuidad 42·0, `casos_prueba` 7·0, validación 37 OK.
- contratos 57·0, gráficos 62·0, diálogo 23·0, barra OK, `prueba_franja_piloto.mjs` 20·0.
- `php -l` sin errores (8.3 local y 8.2 del servidor).

**Despliegue** (desde `b6c1cc7`, con `t2_10_desplegar.py`). Antes de subir se comprobó que los seis archivos vivos eran exactamente los de `4240a67`: `archivo_indexar_cli.php` coincidía en LF y los otros cinco en CRLF, así que nadie había desplegado encima. Se guardó copia viva en el scratchpad `ot10356500/rollback_2026-09-28b/`, con `SHA256SUMS`.

| Archivo | sha256 vivo = rama (`b6c1cc7`) | sha256 anterior (rollback) |
|---|---|---|
| `nucleo/Emision.php` | `bb9cc856259100da267b1fa37a0b8394fd2a3f33a97250bde9687700d1231ca3` | `afbf535fab9e176ec4ddb8d73023c78766c75046939d5a50ae1b2de167ddede2` |
| `archivo_indexar_cli.php` | `c5d8eed13fb93ca1d8ea1bd88830eae8c652e288efc23ff1c6ba7b95b5a8ff3f` | `28f67c47a284ca59ab1f156491e005f7e7585266f1a3d350cc45d0ddc7be5a14` |
| `vocabulario.json` | `0a5cc71fdde13e5f79b99badb94d90aa238f6baddf2454c9387fa6207fd17d15` | `f8b1befe5e2d5e6d3738640fed8ed5ecea16d086a24373cf4aac6268f589ef13` |
| `vocabulario_publico.json` | `54f09c6046aec04edaff24ebcdd3191220ac24a3562f2beb269ffb0644e9d8a6` | `73506d0a9e6ca32620340b87b1f14cf9a1c041bb0b7f9396476cb0f83c4bb8d1` |
| `ui.js` | `ba500cb4c8dabe707dfc063adb9f0de42f963d1a1158837e0546d2260fb247c5` | `a9a29d12e5cad3ed271d4f81cd891992725be45ebd418843b032ea3f11f0c730` |
| `sw.js` (**v25**) | `db67ff3ffb13873bcefcb8e2fb919b82d1a68ccf7207645f3d7814d77db3023e` | `ac805496f45f00910107b63df199cba6eb8ef87a7bd10f956413120d8432a4f1` |

Comprobaciones después de subir:
- `php -l` sin errores en el servidor.
- `tarjeta_cli.php ADMIN` y `JEFE_ZONA:UIO`: **34·0** cada una.
- `nucleo/config.php` intacto: 855 B, mtime 2026-09-09 18:02:18 UTC, sha256 `75b3da7b…`, igual antes y después.
- `error_log` sin líneas nuevas: 734 líneas, mtime 19:35 UTC, igual antes y después.
- La web entrega lo subido.

En `~/respaldos/` (modo 600, sha256 verificado contra la rama):
- `regenerar_pdf_piloto_cli.php`: primero `deeb0c46…`, que fue la versión que corrió. Después se subió `de42550180f794416af05f2b2a373045ae3f1e359659b631295d35e50143540c`, que es la misma más el respaldo que suma; con ella, el simulacro da HECHA 14.
- `limpiar_pruebas.php` `3f301049…`
- `deshacer_prueba.php` `48f35241…`

Las dos versiones viejas, del 24-sep, quedaron copiadas en `rollback_2026-09-28b/respaldos/`.

**Ejecución** (desde `ot/`, con la salida literal en el scratchpad `ot10356500/regen_*.txt`):
1. **Simulacro** a las 18:22:02 EC: **PENDIENTE 14 · INCONSISTENTE 0**. Las huellas son las mismas del simulacro de §1x. Fuera del pedido, solo la captura 204.
2. **`--respaldar`**: los 14 originales, `SHA256SUMS` y `filas_antes.json` (sha256 `1caea957…`) en `~/respaldos/pdf_piloto_con_franja_20260928/`, que es la carpeta 700. `sha256sum -c` dio 14 OK en el servidor. La copia local está en el scratchpad `ot10356500/pdf_con_franja/`, también con 14 OK.
3. **`--ejecutar`**: **14 regeneradas ✓**, a las 18:22:40–43 EC, con la bitácora **#8206–#8219** (`REGENERAR_PDF`, usuario `regenerar_pdf_piloto_cli`, equipo `consola`, «pedido de Andrés del 28-sep-2026» en las 14). En las 6, `estado_antes → estado_despues` es la fecha vieja y la nueva. La 9125 lleva además la nota de la línea del jefe de operaciones.
4. **Segundo simulacro** a las 18:24:55 EC, después de la pasada `--solo-pdf` del índice (y antes de la `--catalogo`): **PENDIENTE 0 · HECHA 14 · INCONSISTENTE 0**, «Nada que hacer: las 14 ya están regeneradas». En el Archivo, la fecha es la corregida y la huella es la del disco en las 14.

**El índice nocturno no pisa la corrección, comprobado corriéndolo.** El robot corre dos pasadas cada noche, según la bitácora `INDEXAR_ARCHIVO` del 25 al 28-sep:
- 03:4x: `--catalogo ~/respaldos/archivo_ot.json` y enseguida `--solo-pdf` (lo lanza `t2_19`).
- 05:1x: otra vez `--catalogo`.

Las dos pasan por la fuente (b), que en la línea ~139 relee la carga de las filas APP, **incluso en `--solo-pdf`**: `$deApp` se arma siempre y es la base del upsert de (a). Se corrieron las dos, en el mismo orden:
- `--solo-pdf`: 45 s.
- `--catalogo`: 30 s.

Antes se tomó una huella por fila de las 7.858 filas de `ot_archivo` (las 15 columnas), ya con la corrección escrita. **Después de las dos pasadas: 0 filas cambiadas.** Hay 13 filas nuevas: la de la OT-9001 de la captura 204 y 12 del buzón y de la gestión llegadas desde las 05:18. Las 6 fechas corregidas siguieron igual en ambas pasadas.

⚠ **Ojo con el orden:** `--solo-pdf` a solas deja el índice en el estado intermedio que el robot produce cada madrugada entre las 03:4x y las 05:1x. Pasa `origen` de HISTORICO a CORREO en unas 7.600 filas, porque el upsert de (a) solo respeta APP. Aquí ese estado duró unos 3 minutos (23:24:48 a ~23:27 UTC), hasta la pasada `--catalogo`. Es un comportamiento anterior a este trabajo, que no se tocó.

**Los PDF, comprobados contra el documento y la base.** Los 14 se bajaron a `ot10356500/pdf_regenerados/`; sus huellas son iguales a las del servidor. Con PyMuPDF, en los 14:
- «DOCUMENTO DE PRUEBA»: 0 veces.
- «Fecha de Atención»: la que vale, es decir la corregida en 166/171/198/200/201/202 y la del técnico en las otras 8.
- «Documento generado automáticamente el …»: igual a `emitida_en` al minuto.
- Huella en disco = `pdf_sha256_regen` = `ot_archivo.sha256`, y los bytes = `ot_archivo.bytes`.
- `pdf_sha256` = la huella del original respaldado.

Resultado: **14 de 14 OK**. `carga.fecha_atencion` sigue siendo la del técnico en las 6, y `carga.correccion_admin` trae {fecha_atencion, antes, por, en, motivo}.

`comparar_pdf_regenerados.py pdf_con_franja pdf_regenerados` con las 6 `--fecha`:
- Sin `--permitir` da **salida 1**, y solo por la línea conocida de la 9125: «Jefe de Operaciones Local: [jefezona-uio@industec.me]» → «[sin configurar]».
- Con `--permitir` de esa línea da **0 diferencias no permitidas, salida 0**.
- Los 13 restantes salen «igual salvo la franja» y, en las 6, la fecha, con las mismas imágenes por página.

**Rollback exacto** (si hubiera que volver atrás):
1. Código: subir por nombre, con `t2_10_desplegar.py`, los seis archivos de `rollback_2026-09-28b/`. Son `4240a67` y sus huellas están en su `SHA256SUMS`.
2. Los PDF: `cp -p ~/respaldos/pdf_piloto_con_franja_20260928/OT-91*.pdf <ot>/ordenes_pdf/` y `sha256sum -c`. Los PDF traen de vuelta la franja.
3. La base, por captura, con `filas_antes.json`:
   - `UPDATE ot_capturadas SET pdf_sha256_regen = NULL, carga = JSON_REMOVE(carga, '$.correccion_admin') WHERE captura_id IN (…)`.
   - `ot_archivo` con los `sha256`, `bytes` y `fecha_atencion` de ese archivo.
4. La bitácora se deja: es el registro de lo que pasó.
5. Los tres PHP de `~/respaldos/` tienen sus versiones viejas en `rollback_2026-09-28b/respaldos/`.

**Abierto para Andrés:**
- **(a) La OT-9001-K061EC-10346775-CNLJ (captura 204)**, de CNLJ, sigue con la franja. Su fecha, su inicio y su fin son coherentes (2026-08-25, de 08:00 a 08:30). Incluirla ya es, de verdad, añadir una línea a `$REGENERAR` (`204 => ['ot' => 'OT-9001-K061EC-10346775-CNLJ', 'aviso' => '10346775'],`), subir la consola y correr simulacro, `--respaldar`, copiar al PC y `--ejecutar`. Las 14 salen HECHA y no se tocan. El simulacro y `--respaldar` ya se probaron así sobre una copia; `--ejecutar` todavía no.
- **(b) La OT-9125** quedó regenerada con «sin configurar» en la línea del jefe de operaciones: es el error D-G que se corrigió el 24-sep, y es lo que dice toda OT emitida desde ese día. No hay una tercera opción sin tocar código: o esta copia, o el original, que trae la franja y está en el respaldo.

**Lo que NO se comprobó:**
- Las pantallas con sesión: el Archivo abriendo los PDF nuevos por `pdf.php`. Se comprobó el disco, la base y el texto de los documentos, no la web con un usuario.
- El `emitir()` nuevo en una primera emisión real contra MariaDB: solo con `EXPLAIN` y la prueba unitaria. La próxima OT que emita un técnico lo ejercita. Conviene mirar que su `emitida_en` y su «generado automáticamente el» coincidan al minuto.
- `verificar_emision.py`: necesita el arnés `preparar_prueba.php`.
- Los celulares toman la v25 del `sw.js` al cerrar y abrir la app con señal. Solo cambió el texto de ayuda de OT_PILOTO.
- MariaDB reescribió el texto crudo de la carga de las 6 (los separadores del JSON); el contenido no cambió. El crudo original está en `filas_antes.json`.

## 1x. El PDF sin la franja «DOCUMENTO DE PRUEBA», y los 14 del piloto regenerados como copia interna (2026-09-28, noche, decisiones A–E de Andrés, 🔨 construido; ✅ desplegado y ejecutado el mismo día: ver §1y)

**Por qué.** Tras el despliegue de §1w Andrés decidió: (A) «de ahora en adelante ninguna orden salga con esa franja» —el PDF ya no la lleva en ningún modo; se quedan la caja naranja, las franjas del formulario, el chip «del piloto · no enviada a KFC» y el Archivo sin «Compartir», y el modo PRUEBA sigue rigiendo la serie 9000 y el correo RETENIDO—; (B) los 14 PDF del piloto (capturas 166, 171, 176, 193-203; OT-9125…OT-9152) se regeneran sin franja como **copia interna**, con la fecha de emisión original; (C) en 6 se corrige la fecha de atención (166→18-sep, 171→17-sep, 198→24-sep, 200→17-sep, 201→24-sep, 202→24-sep), no en 193, 195 ni 176; (D) bitácora REGENERAR_PDF por OT, `pdf_sha256` intacta y `pdf_sha256_regen` la nueva, `ot_archivo` al día; (E) respaldo antes.

**Qué quedó hecho** (rama `pc/ot-piloto-no-cierra-2026-09-28`, sobre `923ca59`):
- **A** — `Emision::html()` pasa `'prueba' => false` siempre, con el comentario de la decisión. `plantilla_ot.php` (contrato) sin tocar. Nada más dibuja la franja: `modo()` sigue mandando la serie (`reservar()`), el correo (`encolar()`, `despachar_correo_cli.php`) y la franja naranja de la app (`yo.php`).
- **B** — `Emision::fechaEmision(emitida_en)`: «Documento generado automáticamente el …» es `emitida_en` si existe; la hora de ahora solo en la primera emisión. Toda regeneración futura la conserva (también la de `emitir()` para un PDF perdido, E-10).
- **C, el camino elegido: una clave dentro de la carga, no una columna.** `carga.correccion_admin` {fecha_atencion, antes, por, en, motivo}, escrita con `JSON_INSERT` (no se reescribe la carga en PHP), al lado de `carga.fecha_atencion`, que queda como la escribió el técnico. **Una sola función decide la fecha que vale, `Emision::fechaAtencion()`**, y la usan `html()` y `archivo_indexar_cli.php` (que ahora extrae `$.correccion_admin` y deja de copiar `$.fecha_atencion` a `ot_archivo` cada noche). Sin migración: la corrección es de 6 filas, vive con el registro que corrige, y los dos únicos lectores del dato pasan por la función. Una corrección que no sea AAAA-MM-DD válida no cuenta.
- **Consola `pruebas/servidor/regenerar_pdf_piloto_cli.php`** (se sube a `~/respaldos/`, corre desde `ot/` con `getcwd()`): simulacro por omisión; `--muestra=DIR` (los PDF que saldrían, fuera del sitio); `--respaldar`; `--ejecutar` (una transacción por captura: corrección, PDF por temporal + rename, `pdf_sha256_regen`, `ot_archivo`, bitácora; si falla con el PDF ya reemplazado, lo repone del respaldo y comprueba su huella). Estados PENDIENTE / HECHA / INCONSISTENTE: la segunda corrida no escribe nada. Se niega a `--ejecutar` si el `archivo_indexar_cli.php` del sitio es el viejo, y a todo si el `Emision.php` del sitio no tiene las funciones nuevas.
- **`comparar_pdf_regenerados.py`** (PC): texto de original contra regenerado, palabra por palabra, y el número de imágenes por página; solo admite la franja, las fechas pedidas y lo aceptado con `--permitir`.
- Vocabulario **2026-09-28.2** (la ayuda de `OT_PILOTO` ya no nombra la franja) y `sw.js` **v25**; `verificar_emision.py` (008 exige que **no** esté la franja y que la emisión sea `emitida_en`; el «es de prueba» de `mis.php` pasa a «del piloto · no enviada a KFC», que era un fallo latente desde `4240a67`); `config.ejemplo.php`, `ANTES_DE_EMPEZAR.md` §7 y su tabla, `VOCABULARIO.md`, `LEEME.md` de servidor.

**Simulacro contra la base real** (28-sep 18:03, desde una copia del código de la rama en `~/regen_sim_20260928/x/y/ot`, con `INDUSTEC_CONFIG` apuntando al `config.php` vivo sin copiarlo ni imprimirlo, y `ordenes_pdf`/`ordenes_fotos`/`catalogos` enlazados; **borrada** al terminar, sin tocar los destinos): **PENDIENTE 14 · INCONSISTENTE 0**, salida 0. Las 14 son exactamente las OT del piloto emitidas hasta el 27-sep; huella en disco = `pdf_sha256` en las 14; fotos N/N en todas; el Archivo con la misma huella; las 6 fechas cuadran con el inicio y el fin. **Prueba de las guardas:** con la fecha de la 200 corrida un día (17→16) → `INCONSISTENTE 1`, «ABORTA», salida 1; sin la 203 en la lista → «el conjunto de 14 no cuadra», salida 1. Después, por consulta aparte: 0 filas REGENERAR_PDF, 0 de la consola, las 14 intactas, bitácora hasta el id 8.205.

**Los PDF que saldrían, comparados con los de hoy** (`--muestra`, bajados al scratchpad `ot10356500/verificacion_simulacro/`): **13 de 14 idénticos palabra por palabra salvo la franja** (y la fecha en las 6), con las mismas imágenes; la línea «Documento generado automáticamente el …» coincide en las 14 con la del original, así que B está comprobado contra el documento, no solo contra `emitida_en`. **La OT-9125 difiere en una línea más:** «Correo de Jefe de Operaciones Local: jefezona-uio@industec.me» → «sin configurar». Se emitió el 24-sep a las 10:15, antes de T2.28.2, que ese día dejó de imprimir el buzón de zona de INDUSTEC ahí (D-G); la copia dice lo que dice toda OT emitida desde entonces.

**Pruebas locales:** nueva `prueba_pdf_sin_franja.php` **48·0** (y con el `Emision.php` de `923ca59` falla), `prueba_ot_piloto` 118·0, vocabulario 156·0, claves 788·0, lista negra 0, panel_zona 91·0, cifras_estado 143·0, 48h 125·0, despacho 20·0, destinatarios 22·0, continuidad 42·0, casos_prueba 7·0, validación 37 OK, contratos 57·0, gráficos 62·0, diálogo 23·0, barra OK, `prueba_franja_piloto.mjs` 20·0, `prueba_offline.mjs publico 8099` OK. `php -l` 90 archivos (8.3) y los 4 cambiados también en el PHP 8.2 del servidor, por stdin.

**Lo que falta, en este orden** (requiere autorización de Andrés): (1) desplegar `nucleo/Emision.php`, `archivo_indexar_cli.php`, `vocabulario.json`, `vocabulario_publico.json`, `ui.js`, `sw.js`; (2) subir a `~/respaldos/` `regenerar_pdf_piloto_cli.php`, `limpiar_pruebas.php` y `deshacer_prueba.php`; (3) simulacro desde `ot/`; (4) `--respaldar`, copiar `~/respaldos/pdf_piloto_con_franja_20260928/` al scratchpad `ot10356500/pdf_con_franja/` y comprobar `SHA256SUMS`; (5) `--ejecutar`; (6) simulacro otra vez (14 HECHA) y `comparar_pdf_regenerados.py` del respaldo contra lo vivo con las 6 `--fecha` y el `--permitir` de la 9125.

**Abierto para Andrés:** (a) ~~la OT-9001 (captura 204)~~ **cerrado el 28-sep a las 18:41 EC** por la sesión principal, aplicando la decisión de Andrés «ninguna orden salga con esa franja»: línea 204 añadida a `$REGENERAR` (consola sha256 `cd3ced3d…`), simulacro `PENDIENTE 1 · HECHA 14`, `--respaldar` (15 en `SHA256SUMS`, 15 OK en el servidor y en el PC; filas en `filas_antes_20260928_184107.json`), `--ejecutar` «captura 204 · regenerada ✓ · 44.076 B · f8b2a589…», segundo simulacro `PENDIENTE 0 · HECHA 15`; `pdf_sha256` original `3ac15168…` conservado, `pdf_sha256_regen` = `ot_archivo.sha256` = `f8b2a589…`, fecha 2026-08-25 sin corregir. Texto de los 15 PDF leído con PyMuPDF: **15 sin franja**. Ojo: es un registro tardío de una visita del 25-ago que ya tiene OT de producción (OT-2157 y OT-2250 del mismo aviso): no va a la hoja de reemisión. (b) Aceptar la línea del jefe de operaciones de la OT-9125.

**El `JSON_INSERT` sobre las 6 cargas reales, probado con un SELECT** (no escribe): en las 6, la carga decodificada es idéntica en PHP estricto (`===`, el mismo criterio de la guarda de `--ejecutar`) salvo la clave nueva, que queda al final y como objeto, y `fecha_atencion` sigue siendo la del técnico. **Ojo:** MariaDB reescribe el texto del JSON (separadores con espacio), así que el texto crudo de la carga cambia aunque su contenido no; el crudo original queda en `filas_antes.json` del respaldo.

**Lo que NO se comprobó:** `--respaldar` y `--ejecutar` no han corrido (solo el simulacro, la muestra y el SELECT de arriba); `archivo_indexar_cli.php` nuevo no se corrió contra una base (su efecto se probó con la función y la forma de sus datos, no con el SQL de MariaDB).

## 1w. La OT INDUSTEC del piloto no cierra la orden y se marca «no enviada a KFC» (2026-09-28, decisión de Andrés, ✅ DESPLEGADA en darkviolet)

**Por qué.** Darkviolet corre en modo PRUEBA (no hay `emision_modo` en su `config.php`): toda OT que emite la app sale de la serie 9000, con la franja «DOCUMENTO DE PRUEBA» y el correo RETENIDO. Del 24 al 27-sep los técnicos de UIO emitieron 14 (OT-9125 a OT-9152) y las tomaron por reales, porque la app les decía «OT INDUSTEC emitida» con un ✓ verde. Ninguna llegó a Grupo KFC. Doce órdenes quedaron ATENDIDO con una de ellas como OT de cierre y el 28-sep se marcaron cerradas en SAP; la del aviso 10356500 (OT-9147) abrió la solicitud de repuesto 53. Decisiones de Andrés: las OT que faltan se emiten por producción; B.IA sigue en PRUEBA; mientras dure el piloto la OT del piloto no cierra nada y se marca en Archivo, buzón, Repuestos y en la app del técnico; desplegar tras pruebas y revisión; limpiar la bitácora de lo de prueba.

**Qué quedó hecho** (rama `pc/ot-piloto-no-cierra-2026-09-28`, commit desplegado **`4240a67`**; se construyó sobre `4c38913`, la foto exacta del servidor, y `2cb2d6e` repuso el buzón simplificado que el despliegue del panel había pisado):
- **Un solo criterio, por el número:** `Emision::esDePrueba()` (serie ≥ 9000) y `Emision::sqlEsDePrueba()` (REGEXP con `UPPER(TRIM())`, sin tope de cifras, equivalente al PHP). **El número y el modo ya no pueden contradecirse:** `Emision::reservar()` lanza, dentro de la transacción de `emitir()`, si en PRODUCCION sale un número ≥ 9000 (el día del corte, sin cargar el contador real, la primera OT que SÍ va a KFC saldría OT-9153 y todo el sistema la trataría como del piloto) o si en PRUEBA sale uno < 9000. La OT queda FALLIDA con el motivo y el contador vuelve atrás.
- **La del piloto no atiende:** `envio.php` solo asigna la orden a quien fue, sin arrastrar la cadena, no resuelve solicitudes y **sí** abre la de repuesto; en PRUEBA toda OT es del piloto. `Casos::indiceInformes()` no la cuenta como emitida ni como evidencia del equipo (una del piloto trabada, NUMERADA o FALLIDA, sigue levantando «OT INDUSTEC no emitida»).
- **La de producción la reemplaza, solo si es de esa visita:** `atenderPorOrden()`, `Reconciliar::atenciones()` y `enlazar()` sustituyen una `ot_cierre` del piloto (antes el `COALESCE` la dejaba para siempre) y lo anotan (`OT_CIERRE_REEMPLAZA_PILOTO`). Reconciliar exige una OT «Cerrada» con fecha igual o posterior a la visita del piloto (fecha de atención del Archivo, si no `atendido_en`); si no la hay, no toca la orden. **El estado que puso Isabel no se toca**: en una RESUELTO solo cambia el número.
- **Buzón (`casos.php`):** bajo el resumen, el aviso **«N cerradas en SAP con una OT INDUSTEC del piloto como OT de cierre»** con el filtro `?est=RESUELTO&piloto=1` (cifra `cerradas_sap_piloto` de `tarjetasPorZona()`; deja de contar sola cuando llega la de producción). En la fila: el chip, y «Registra en SAP la OT INDUSTEC de cierre del formulario de siempre» con el número **solo si es de cierre** (la última desde la visita del piloto); si llegó una de evaluación, se nombra y se dice que falta la de cierre. Archivo (`ordenes.php`) con chip y sin «Compartir» (el servidor también lo niega: 409 + DENEGADO). Repuestos, historial del técnico (`mis.php`) y Reportes (el rendimiento por técnico ya no cuenta las del piloto: 14 → 0).
- **App del técnico:** caja naranja «!» en vez del ✓, franja fija arriba del formulario **y otra junto a «Revisar y enviar»**, aviso largo de la cola, con el texto «Emítela hoy también por el formulario de siempre: solo esa llega a Grupo KFC y es la que vale.» `ui.js` toma del respaldo embebido una clave que falte en un `vocabulario_publico.json` viejo (sin esto, la franja lanzaba en la carga y dejaba el formulario sin combos: comprobado en Chrome quitando el arreglo). `sw.js` v23 → **v24**. Vocabulario `2026-09-28.1` (concepto `OT_PILOTO`).
- **`despachar_correo_cli.php`** con PHPMailer en `dirname(__DIR__, 4)`; **`limpiar_pruebas.php` / `deshacer_prueba.php`** retiran también las filas APP de prueba sin captura.

**Revisión (dos revisores, LISTO_CON_OBSERVACIONES): 2 mayores y 11 menores, todos corregidos** en `4240a67` antes de desplegar (el detalle de cada uno, en el mensaje del commit).

**Despliegue, 28-sep ~19:30 UTC.** Antes: los 87 archivos de código del servidor = `vivo_base` por sha256 crudo (0 diferencias, 0 nuevos; el último cambio era el del panel, 08:16:57 UTC), así que nadie había desplegado encima. Copias vivas de los 19 archivos guardadas y verificadas (0 diferencias) en el scratchpad `ot10356500/rollback_2026-09-28/`. Subidos con `t2_10_desplegar.py`, en este orden: `vocabulario.json`, `vocabulario_publico.json`, `nucleo/Emision.php`, `nucleo/Casos.php`, `nucleo/Pendientes.php`, `nucleo/Reconciliar.php`, `nucleo/Reportes.php`, `ui.js`, `envio.php`, `yo.php`, `casos.php`, `ordenes.php`, `pendientes.php`, `mis.php`, `despachar_correo_cli.php`, `index.html`, `app.js`, `cola.js`, `sw.js`.

| Comprobación, después | Resultado |
|---|---|
| sha256 vivo = rama, los 19 | **19/19**; ningún otro archivo cambió |
| La web (CDN, sin comprimir y gzip) | «la web entrega exactamente lo que se subió»; `sw.js` → `ot-industec-v24` |
| `php -l` en el servidor (PHP 8.2), en su sitio | **12/12** sin errores |
| `tarjeta_cli.php ADMIN` y `JEFE_ZONA:UIO` | **34 · 0** y **34 · 0** |
| HTTP sin sesión | `login.php` 200, pantallas 302, `yo.php` 401, `envio.php` 405, `catalogos/locales.json` **403** |
| `nucleo/config.php` | intacto: sha256 `75b3da7b…`, mtime 2026-09-09 18:02:18 UTC, antes y después |
| `error_log` desde el despliegue | **0 errores de pantallas**. 7 líneas nuevas, todas de consolas mías: 6 *warnings* de sesión de `Auth.php` (el mismo patrón que ya tenía 71 veces por los CLI) y 1 *Fatal* de un SELECT de verificación con una columna mal escrita (`bitacora_id`) |
| Criterio contra la base | 7.866 números distintos, **14** del piloto en PHP y en SQL, **0** discrepancias |
| Cifras vivas | UIO: cerradas en SAP **37**, de ellas **12 con OT del piloto** (el aviso nuevo del buzón); documentos del buzón 836 en 739 avisos, 13 del piloto en alcance (el 10356680 está fuera del buzón y su OT-1921 queda «llegó, sin constancia de cierre»); `CORRECTIVO:UIO` = 9152, y `errorDeSerie()` en PRODUCCION lanzaría |
| Pruebas locales | `prueba_ot_piloto` **118·0**, vocabulario 156·0, claves 788·0, lista negra 0, panel_zona 91·0, cifras_estado 143·0, 48h 125·0, despacho 20·0, destinatarios 22·0, continuidad 42·0, casos_prueba 7·0, validación 37 OK, contratos 57·0, gráficos 62·0, diálogo 23·0, barra OK, **`prueba_offline.mjs` OK** (volvió a pasar), nueva **`prueba_franja_piloto.mjs` 20·0** en Chrome |

**Rollback** (devuelve los 19 archivos vivos de antes, verificados por hash; no toca la base): `bash "C:/Users/andre/AppData/Local/Temp/claude/d--PROYECTOS-IA-AGENTES-INDUSTECH/07d21ced-62fe-45f4-b2d1-243e30ffcc98/scratchpad/ot10356500/rollback_2026-09-28/rollback.sh"` y después `t2_10_desplegar.py --comprobar-web sw.js` (debe volver a v23).

**La bitácora, limpiada (decisión 4).** Volcado previo `~/respaldos/bitacora_antes_limpieza_20260928T193755Z.sql.gz` (sha256 `3328cd2a…`, igual en el servidor y en la copia local; **6.504 tuplas = 6.504 filas**, ids 1–8.150, con los dos disparadores). Criterio estricto, el mismo SQL en el simulacro y en el DELETE: **A** filas de las cuentas de prueba borradas (ids 23–72, que no existen en `usuarios` y cuyo nombre lleva «prueba»): **2.624** (cuadra con la suma por cuenta); **B** filas de un usuario real o del sistema sobre un aviso sintético 9999 o una OT de prueba sin captura con aviso 9999: **1 + 8**; **C** `LIMPIEZA_PRUEBAS`: **9**. Total **2.642**; quedan **3.863** (2.642 + 3.863 = 6.505, contando la fila real que entró durante el simulacro). Compuertas: ningún usuario real tiene id 23–72, 0 filas de usuario real sobre dato real. Se quitó solo `bitacora_sin_delete` (el de UPDATE no estorba para borrar), DELETE en una transacción con conteo exacto (**2.642**), el disparador vuelto a crear con su `sql_mode` y colación, e **idénticos los dos** a su `SHOW CREATE TRIGGER` de antes (guardado en `~/respaldos/bitacora_disparadores_20260928.json`). Prueba del candado sobre la última fila de Isabel, dentro de una transacción revertida: el DELETE da «1644 La bitacora no se borra (009)» y los dos UPDATE «1644 La bitacora no se edita (009)»; la fila sigue. Después, por consultas aparte: 3.863 filas, 0 de ids 23–72, 0 con referencia 9999, 0 `LIMPIEZA_PRUEBAS`, 1.767 de usuarios reales (las mismas de antes). **No se escribió ninguna fila que cuente la limpieza.** Quedaron **a propósito**, porque son de una persona real o del sistema sobre una orden real: 4 aperturas de PDF de OT de prueba en avisos reales (abasantes 3, irodriguez 1: 10353767 y 10355931), 12 «enlace con firma inválida» del sistema sobre esas mismas OT, 12 `PRUEBA_PREPARAR` (el arnés que creó las cuentas y tomó los avisos 10355931/10356012) y 1 de `auditoria-cli` (id 999998, del 10-sep).

**Lo que NO se comprobó:**
- **Ninguna pantalla con sesión** se abrió en un navegador ni se renderizó contra la base (renderizarlas escribe `CONSULTAR` en la bitácora). La franja sí se vio en Chrome, contra un router sin base (`prueba_franja_piloto.mjs`).
- **Los UPDATE nuevos no han corrido sobre datos reales** (el reemplazo de la OT de cierre del piloto en Reconciliar, `atenderPorOrden` y `enlazar`): hoy no hay ninguna OT de producción de esas visitas que dispare el reemplazo.
- **Los celulares de ftipan, ajumbo y kchimbo** siguen con la v23 hasta que abran la app con señal; con la app cerrada, una OT que sale por Background Sync no muestra nada (el historial de `mis.php`, que viene del servidor, sí la marca).
- `~/respaldos/limpiar_pruebas.php` y `deshacer_prueba.php` del servidor son **los de antes** (sin el arreglo de las filas sueltas del Archivo): hay que subir los de la rama antes de volver a correr el arnés.
- Las cuatro consolas `emitir_pendientes_cli.php`, `reconciliar_cli.php`, `regularizar_masivo_cli.php` y `t2_24_2_cerrar_masivo_cli.php` siguen vivas con la versión de `master` (sin la ola 2 del vocabulario); las guardas del piloto están en `nucleo/`, así que las cubren.
- **Dos decisiones siguen abiertas para Andrés** (ver el plan): que una OT del piloto «cerrada» deje la orden asignada en vez de intacta, y que el 10356500 cuente como orden abierta (tiene la solicitud de repuesto) y no como «a espera de informe técnico».

## 1v. Buzón simplificado y cifras de Asignación con los términos de la administradora (2026-09-27, fuera del plan, pedido de Isabel)

**Rama `pc/buzon-simplificado-2026-09-27`** (a partir de `c53d4fd`, lo desplegado en darkviolet el 26-sep), subida a
GitHub, **sin desplegar**. Isabel (capturas del buzón y de Asignación, 27-sep) quiere en el buzón solo tres cosas —el
resumen general con los términos de su registro SAP/KFC, cuántas órdenes se generaron en la semana y cuántas por zona
con lo abierto y lo cerrado— y en Asignación «sin asignar» como cifra general, «pendientes de informe técnico» y
«pendientes de repuesto», las mismas para el jefe de zona. No se inventó ningún término: los rótulos son los del
diccionario (`vocabulario.json` **no cambió**, sigue en `2026-09-24.6`), y las cifras salen de **una sola función**,
`Casos::tarjetasPorZona()`, la misma de la tarjeta «Por zona» del panel y del filtro `?grupo=` del buzón.

**Qué se quitó y qué se puso** (código: `casos.php`, `asignacion.php`, `nucleo/Casos.php`):

| Antes (captura de Isabel) | Ahora | Clave del diccionario · definición |
|---|---|---|
| «Llegaron ayer y hoy», «Órdenes nuevas en 7 días», «Fuera del área, por resolver», «Con fecha SAP hoy», «Con OT INDUSTEC · N de cierre», «En la ventana de 90 días», «Sin zona resuelta» y los tres cuadros de texto (OT INDUSTEC, fecha comprometida, alertas) | **Retirados.** Las órdenes fuera del área siguen al alcance por el filtro «Alerta» (y por la sublínea de la tarjeta del panel); la fecha SAP sigue en cada fila | — |
| Línea de estados «Sin asignar · Asignadas · A espera de repuesto · Atendidas, por cerrar en SAP · Cerradas en SAP» | **Resumen general**, cinco cuadros: ÓRDENES A ESPERA DE INFORME TÉCNICO (pie: «de ellas, N sin asignar») · ÓRDENES ABIERTAS (pie: «de ellas, N a espera de repuesto») · TOTAL DE ÓRDENES ABIERTAS · Atendidas, por cerrar en SAP · Cerradas en SAP. Cada uno enlaza a sus filas (`?grupo=` / `?est=`) | `ESPERA_INFORME` = abierta sin ninguna OT INDUSTEC emitida (lo que KFC llama ABIERTO · SIN GESTIÓN) · `ABIERTA` = con OT de evaluación y sin cierre, siempre con las que esperan repuesto (TRATAMIENTO · INFORME TÉCNICO) · `TOTAL_ABIERTAS` = las dos · `ATENDIDA` = ATENDIDO · `CERRADA_SAP` = RESUELTO (D-A, D-B del 24-sep) |
| «Fuera de esa línea: en revisión · no nos compete · cerradas sin atención · regularizadas» | Se queda, como «También en el buzón:» | los mismos conceptos |
| — | **Órdenes nuevas en 7 días**: hoy más los seis días anteriores por `fecha_creacion`, con el desglose por zona en el pie; enlaza a `?dias=6` | `ORDENES_NUEVAS_7D`. Corrige un desfase: `casos.php` contaba `-7 días` con `>=`, ocho días en un número que dice «7» (el panel ya contaba seis) |
| — | **Por zona**: tabla ZONA UIO · LARB · CUENCA-LOJA (+ OTRA y sin zona solo si traen algo) × Órdenes generadas (90 días) · A ESPERA DE INFORME TÉCNICO · ÓRDENES ABIERTAS · TOTAL DE ÓRDENES ABIERTAS · Atendidas, por cerrar en SAP · Cerradas en SAP, y la fila TOTAL de las tres zonas; cada cifra enlaza a `casos.php?zona=X&grupo=…` o `&est=…`. Debajo, la línea que cuadra el TOTAL del resumen con la fila (OTRA y sin zona) | claves nuevas de `tarjetasPorZona()`: `en_buzon`, `cerradas_sap`, `nuevas_7d` por zona y total, y `abiertas` / `espera_informe` del conjunto |
| Asignación: «Sin asignar · en todas las zonas», «Sin órdenes asignadas · de N en el equipo», «Con 8 o más · piénsalo antes de darle otra», «Asignadas y a espera de repuesto · de todo el equipo» | **Sin asignar** (cifra general, sin pie) · **ÓRDENES A ESPERA DE INFORME TÉCNICO** · **A espera de repuesto**; lo mismo en el bloque de cada zona (y por tanto en el del jefe de zona). Los dos cuadros de técnicos se retiraron; la carga de cada técnico sigue abajo, persona por persona | `SIN_ASIGNAR` (sigue contando con `Casos::sinAsignar()`: NUEVO + EN_REVISION, la diferencia con el panel sigue pendiente de Andrés) · `ESPERA_INFORME` · `ESPERA_REPUESTO` |

El técnico ve el resumen y el cuadro semanal de sus órdenes, no la tabla por zona. El jefe de zona ve una sola
fila. Si falta una fuente de OT INDUSTEC, las filas 1 y 2 dicen «no disponible», nunca 0 (I-7).

**Cómo se comprobó** (Git Bash, `desarrollo/sistema_ots/app`, PHP 8.3.35 de `D:\SOFTWARE\PHP83`):

| Prueba | Antes | Después |
|---|---|---|
| `prueba_panel_zona.php` (+3 órdenes sintéticas fuera del TOTAL y 15 afirmaciones: generadas, cerradas en SAP, nuevas ≤ 6 días y el borde de 7, totales, subconjuntos, «no disponible», y que el buzón y Asignación piden las claves nuevas y ya no pintan los cuadros retirados) | 70·0 | **85·0** |
| `prueba_vocabulario.php` · `prueba_claves_vocabulario.php` · `prueba_lista_negra.php` | 154·0 · 748·0 · 0 | 154·0 · **749·0** · **0** |
| Compuerta de cobertura (`verificar_diccionario.py` de la sesión del 24-sep, contra el mismo JSON) | APRUEBA `.6`, todo en 0 | APRUEBA `.6`, todo en 0 |
| `prueba_48h` · `prueba_despacho` · `prueba_destinatarios` · `prueba_casos_prueba` · `validacion_test` | 125·0 · 20·0 · 22·0 · 7·0 · 37/37 | iguales |
| `prueba_contratos.mjs` · `prueba_graficos.mjs` · `prueba_barra_tecnico.mjs` | 57·0 · 62·0 · OK | iguales |
| `php -l` sobre `publico/` | 66/66 | 66/66 |
| Render sin base (doble de `Db`, datos sintéticos; el arnés del 24-sep) de `casos.php` y `asignacion.php` como ADMIN, JEFE_ZONA:UIO y TECNICO | — | 0 avisos PHP nuevos (los 2 `tecnico_auto` ya salían con el `casos.php` original: el doble no trae esa columna); el resumen, el cuadro semanal y la tabla cuadran entre sí (UIO 7 generadas = 5 del TOTAL + 1 atendida + 1 cerrada sin atención; fila TOTAL 8 + OTRA 1 + sin zona 1 = 10 del resumen); el jefe ve una fila y ningún TOTAL; el técnico no ve la tabla |

**Lo que NO se comprobó:** nada en darkviolet ni con sesión (no se desplegó); `verificar_http.py`, `verificar_cifras.py` y
`capturar_pantallas.mjs` (necesitan el servidor; ninguno afirma los textos retirados); `prueba_continuidad.php` no corre
en este PC porque no hay `catalogos/casos_sap.json` local (no es de este cambio); `prueba_offline.mjs` ya fallaba.
Las hojas del piloto solo cambiaron en la frase de las cifras de Asignación; las capturas del piloto siguen siendo las viejas.

**DESPLEGADO A DARKVIOLET EL 2026-09-28** (Andrés: «despliega todo»). Antes de subir se comparó por SSH (sha256 sin
CRLF/LF; `--comprobar-web` no sirve para `.php`, la web lo ejecuta): `nucleo/Casos.php` y `asignacion.php` vivos =
`c53d4fd`; **`casos.php` vivo = `pc/doble-enter-cerrado-sap-2026-09-27`** (§1u ya estaba desplegado). Se fusionó esa rama
en esta (`0b3ee13`; solo `ESTADO.md` chocó, se conservaron §1u y §1v) y se volvió a probar: `prueba_panel_zona` 85·0,
lista negra 0, claves 749·0, vocabulario 154·0, 48h 125·0, contratos 57·0, gráficos 62·0, `prueba_dialogo_seguro` 23·0.
`t2_10_desplegar.py nucleo/Casos.php casos.php asignacion.php` → 3 de 3, hash vivo = local (`495ffe5e…`, `0b68879…`,
`20b9bc4…`); `php -l` con el PHP del servidor 3/3 sin errores; `login.php` 200; `error_log` sin entradas nuevas.
`tarjeta_cli.php` (subido a `~/respaldos`, corrido y borrado) contra la base real: ADMIN, JZ:UIO y JZ:CNLJ **20·0 cada
uno**; TOTAL 136 = conteo directo por estado; con las claves nuevas: tres zonas generadas 875, cerradas en SAP 128,
nuevas en 7 días 57, atendidas 21 (UIO 53 = 16 + 37 · LARB 23 · CNLJ 60 = 38 + 22). **Falta:** que Andrés o Isabel
miren buzón y Asignación con sesión (administración, jefe de zona, técnico) y respondan las 5 preguntas de abajo.

**El comando que se usó (por nombre, no `--todo`):**

```
cd "…\INDUSTECH IA" (la rama pc/buzon-simplificado-2026-09-27)
INDUSTEC_LLAVE_SSH=C:/Users/andre/.ssh/industec_hostinger_pc INDUSTEC_SSH_USER=u671729428 \
  python desarrollo/agentes/scripts/t2_10_desplegar.py nucleo/Casos.php casos.php asignacion.php
```
El script sube, verifica por hash y comprueba lo que entrega la web en el mismo paso; `--comprobar-web` es
**excluyente** (solo compara, no sube), así que va en una corrida aparte, antes, para ver que esos 3 archivos son los
únicos que difieren del servidor (comparando sin CRLF/LF). Después: entrar como administración, jefe de zona y técnico y mirar
el buzón y Asignación; `php ~/respaldos/tarjeta_cli.php ADMIN` sigue sirviendo (no imprime las claves nuevas, pero
`Casos.php` es el mismo). Rollback: subir los 3 archivos desde `pc/vocabulario-sobre-vivo-2026-09-26`.

**Ojo con la fusión:** el árbol principal del PC tiene sin confirmar el «doble Enter» de `casos.php` (§1u, otra zona del
archivo) y hay un worktree `_wt_panel_estados_2026-09-27` con el cuadro «En qué estado están» de `panel.php` + 4 conceptos
nuevos en `vocabulario.json` (otra conversación). Tres cambios de tres conversaciones sobre el mismo repo: fusionar de
uno en uno y correr `prueba_panel_zona.php`, `prueba_claves_vocabulario.php` y `prueba_lista_negra.php` después de cada uno.

**Preguntas que solo Andrés o Isabel pueden responder:** (1) «semana» = hoy más seis días anteriores (como el panel) o
lunes a domingo; si es lo segundo, es una línea en `tarjetasPorZona()`. (2) «OTs generadas» = órdenes que KFC creó
(`fecha_creacion` del aviso, lo implementado) o OT INDUSTEC emitidas por los técnicos. (3) «Resumen general de OTs
atendidas»: se leyó como el resumen de estados; si ella quiere solo atendidas + cerradas en SAP, se quitan tres cuadros.
(4) «Cerradas» por zona: hoy son dos columnas (atendidas, por cerrar en SAP · cerradas en SAP) porque «cerrada» nunca va
sola en el diccionario; si Isabel quiere una sola, hay que decidir si suma las dos (su plan de seguimiento marca CERRADA
con la OT INDUSTEC de cierre, no con SAP). (5) Los términos de KFC (ABIERTO · SIN GESTIÓN / TRATAMIENTO · INFORME
TÉCNICO / CERRADO) son contrato del libro de KFC (§6 de `VOCABULARIO.md`) y **no** se usaron como rótulo: se usaron los
de Isabel del 24-sep, que son su traducción; si ella prefiere los de KFC en pantalla, es un cambio de diccionario.
## 1u. Doble Enter = Confirmar, con pantalla de seguridad, en «Marcar como cerrada en SAP» (2026-09-27, fuera del plan, pedido de Andrés)

**Commit `190eea3` en la rama `pc/doble-enter-cerrado-sap-2026-09-27` (empujada a GitHub) y desplegado a darkviolet el
2026-09-27 con autorización de Andrés** (`t2_10_desplegar.py casos.php` → `1 de 1 archivos`; SHA-256 del archivo en el
servidor `7d7a352f…bdb6`, idéntico al local; `php -l` sobre el archivo desplegado sin errores). Antes de subir se comprobó
que lo vivo era exactamente el `casos.php` del 26-sep (`HEAD~1`, idéntico salvo CRLF). Ojo: la línea «la web entrega
exactamente lo que se subió» del desplegador **no cubre `.php`** (solo compara `.js/.css/.html`), así que la prueba de la
web es el SHA en disco más el 302 al login que da la URL sin sesión. **Pendiente: fusión en `master` desde la estación**
y que alguien lo mire con sesión de administración (`PLAN_INDUSTEC.md` §11b, acción **R**).
Andrés pidió que en el diálogo «Marcar como cerrada en SAP · NNNN» un doble Enter haga Confirmar, y que
antes de confirmar aparezca «¿Está seguro?» por si el doble Enter fue involuntario.

**Qué quedó funcionando** — todo en `desarrollo/sistema_ots/app/publico/casos.php`:

- **El diálogo nuevo `#acc-seguro`** (líneas 1288-1301, comentario incluido; el `<dialog>` va de la 1294 a la 1301): «¿Estás seguro de que deseas confirmar?», el texto
  «Vas a marcar la orden NNNN como cerrada en SAP. Si el doble Enter fue sin querer, vuelve.» y dos botones,
  «No, volver» (con `autofocus` **y** `focus()` explícito) y «Sí, marcar como cerrada». No es `window.confirm()`
  a propósito: ese acepta con Enter, y un tercer Enter involuntario habría cerrado la orden igual.
- **La bandera `seguro:true` en `ACC.cerrado_sap`** (línea 1318), única acción que la lleva. Las otras siete
  (`asignar`, `seguimiento`, `revision`, `veredicto`, `derivar`, `regularizar`, `otro_trabajo`) no cambian.
- **El handler de `submit`** (líneas 1364-1389): conserva la validación de `confirmo_zona` para `asignar` y,
  para una acción con `seguro`, intercepta el envío —venga del botón Confirmar o del doble Enter— y abre
  `#acc-seguro`. Solo «Sí» pone la bandera `seguroOk` y reenvía con `requestSubmit()`; «No» o Esc cierran la
  seguridad y devuelven el foco a la nota, que nunca se borró porque `#acc` no se cerró.
- **El doble Enter** (líneas 1403-1420): en el textarea de la nota, Enter sin Shift no inserta salto (la nota
  es «en una línea», como dice el placeholder) y dos Enter dentro de 1,5 s llaman a `requestSubmit()`, que
  respeta `required` y pasa por el handler de arriba. Shift+Enter sigue insertando salto. `abrir()` reinicia la
  bandera y el contador (líneas 1424-1425).

**Cómo se comprobó:**

| Comprobación | Resultado |
|---|---|
| `ssh -p 65002 -i ~/.ssh/industec_hostinger_pc u671729428@82.25.73.181 'php -l' < casos.php` (por stdin, no escribe nada en el servidor) | `No syntax errors detected in Standard input code` |
| `node --check` sobre el bloque `<script>` extraído | sintaxis OK |
| `node prueba_contratos.mjs` (todo `getElementById` tiene su `id`, incluidos los cuatro nuevos) | **57 · 0** |
| `node prueba_graficos.mjs` | **62 · 0** |
| **`node prueba_dialogo_seguro.mjs`** — nueva: recorta el diálogo y su JS **reales** de `casos.php`, los carga en **Edge sin ventana** y dispara las teclas y clics | **23 · 0**: un Enter no envía; el doble Enter abre la seguridad **con el foco en «No, volver»** y con el número de orden; «No» y Esc vuelven a la nota **sin perderla**; Confirmar también pasa por la seguridad; «Sí» envía **una sola vez**; dos Enter a más de 1,5 s no confirman; Shift+Enter no cuenta; `revision` no intercepta Enter, respeta `required` y envía sin seguridad; `asignar` a otra zona sin la casilla sigue avisando |

**Lo que NO se comprobó:** la pantalla **con sesión real** en darkviolet (no se desplegó), el diálogo **en un celular**
(la seguridad hereda el estilo `@media (max-width:560px)` del `dialog`, pero nadie lo miró), y que un **Enter físico** sobre
«No, volver» dispare el clic: la prueba verifica que el foco está ahí, que es lo que hace que el navegador convierta
Enter en clic; la activación por teclado no se puede simular con un evento sintético.

## 1t. Vocabulario SAP: las mismas palabras para todos los roles (2026-09-24/26, fuera del plan, pedido de la administradora)

**Rama `pc/vocabulario-sap-2026-09-24`** (commits `ab887a8` y `f14bd3f`, subida a GitHub, **sin desplegar**).
Isabel pidió que el panel «Por zona» hable como SAP y KFC: ÓRDENES ABIERTAS · ÓRDENES A ESPERA DE INFORME
TÉCNICO · EQUIPOS DESHABILITADOS · TOTAL DE ÓRDENES ABIERTAS. Andrés fijó que **esos términos sean los mismos
en todo el sistema y para todos los roles**, no solo en su pantalla. Especificación completa, tabla concepto ×
término × rol y decisiones en [`desarrollo/sistema_ots/VOCABULARIO.md`](desarrollo/sistema_ots/VOCABULARIO.md).

**Qué se encontró antes de tocar nada** (2.158 correos enviados por Isabel, la bandeja, el export SAP semanal de
KFC, sus planes y todo el código, en solo lectura): **ningún rótulo del panel de entonces** («sin repartir»,
«vencidos», «asignados», «por cerrar»…) aparece en lo que ella escribe; KFC traduce MEAB→ABIERTO,
METR→TRATAMIENTO (rótulo «INFORME TÉCNICO») y MECE→CERRADO; y **el formato que ella pide ya existe** en la hoja
RESUMEN del STATUS_PENDIENTES de los martes. Dentro del sistema, un mismo estado tenía hasta 15 nombres según la
pantalla y el rol, y hacia KFC salían dos vocabularios distintos.

**Decisiones de Andrés (24-sep):** «a espera de informe técnico» = abiertas **sin ninguna OT INDUSTEC** emitida;
«órdenes abiertas» = con OT de evaluación y sin cierre (ESPERA_REPUESTO siempre aquí); TOTAL por zona = suma de las
dos, más el total de las tres zonas; **«orden» = el trabajo que pide KFC (aviso SAP)** y el documento del técnico
= **«OT INDUSTEC»** (título del PDF, asunto y número OT-NNNN no cambian: contrato); el estado SAP se trae al
servidor **por fases** (hoy la cifra es la de B.IA y la ayuda lo dice).

**Qué quedó hecho:** `publico/vocabulario.json` (v2026-09-24.6: 104 conceptos, palabras reservadas, contratos que no
cambian) que leen PHP (`nucleo/Vocabulario.php`), el celular sin señal (`UI.T`, con
`vocabulario_publico.json` recortado) y los scripts de la estación (`comun.termino`); la tarjeta por zona
(`Casos::grupoOrden`, `?grupo=` en el buzón para que la cifra sea las filas de su enlace, jefe de zona con la suya,
«no disponible» en vez de un cero falso); y la migración de **todo** texto visible: oficina, solicitudes y
preventivo, app del técnico, reportes/PDF/PPT/correos a KFC y los `t2_27_*.py`. Cambian de cifra, y Isabel lo va a
notar: el TOTAL ya **no incluye las atendidas** por cerrar en SAP (van al pie) y cuenta una vez cada cadena de
continuidad; «asignadas hace 3+ días» ya no cuenta las que tienen OT de evaluación.

**Verificación (corrida de nuevo por el coordinador, no solo reportada):**

```
compuerta de cobertura contra las 1.224 apariciones del inventario: 0 en los 9 contadores → APRUEBA
prueba_lista_negra (sin --informe): 0 hallazgos en 154 archivos      (al empezar: 493)
prueba_claves_vocabulario: 745 · 0     prueba_vocabulario: 154 · 0     prueba_panel_zona: 70 · 0
prueba_48h 125·0 · continuidad 42·0 · destinatarios 22·0 · despacho 20·0 · casos_prueba 7·0 · validacion 37/37
prueba_contratos.mjs 57·0 · prueba_graficos.mjs 62·0 · prueba_barra_tecnico OK · php -l 67/67 · node --check · py_compile
```

Dos verificadores independientes revisaron el resultado: uno **renderizó las pantallas como administración, jefe
de zona y técnico** y armó la matriz concepto × rol; otro buscó defectos en el diff. **20 hallazgos, ninguno
bloqueante, los 20 corregidos**, entre ellos: la frase del correo del martes que se había desviado del contrato con
Isabel (restaurada palabra por palabra) y una **fuga** — el diccionario público llevaba notas internas con el nombre
de una empleada y rutas de scripts (ahora solo se sirve la copia recortada; se comprobó que no contiene «Isabel»,
rutas ni contratos).

**Lo que NO se pudo comprobar:** las baterías de servidor (`verificar_*.py`, `capturar_pantallas.mjs`) y una revisión
en navegador — necesitan el sitio de pruebas; el render de los verificadores usó una base falsa en memoria, así que
**dos consultas SQL nuevas** (`JSON_EXTRACT` sobre `ot_capturadas` y el vencido calculado en SQL) no se han corrido
contra MariaDB. `prueba_offline.mjs` ya fallaba antes de este trabajo.

### Despliegue a darkviolet: HECHO el 2026-09-26 (solo código, por decisión de Andrés)

El servidor tenía **T2.28.3** (correo del local editable) y **T2.28.6** (ficha del equipo, `sw.js` v20) que la
estación desplegó y **no ha empujado** (`origin/master` sigue en `bdaaf92`). Desplegar la rama tal cual los habría
pisado, así que, con «fusionar sobre lo vivo» de Andrés:

1. `pc/estado-vivo-darkviolet-2026-09-26` (`d35dfbc`): **copia de 16 archivos bajados del servidor** (15 de
   `publico/` + `sql/014_ficha_equipo.sql`), cada uno verificado por sha256. Es una **reconstrucción, no autoría de
   la estación**: cuando la estación empuje su versión, esta copia se descarta (el contenido es idéntico byte a byte
   en esos archivos, así que la fusión posterior no debería chocar).
2. `pc/vocabulario-sobre-vivo-2026-09-26`: la fusión. De los 13 archivos que ambos lados tocaban, git fusionó 11
   solo; los 2 choques (`catalogos.php`, `sw.js`) eran triviales. `sw.js` pasa a **v21**. Cinco textos nuevos de la
   estación que llamaban «orden» al documento del técnico ahora dicen «OT INDUSTEC».

**Desplegado: 47 archivos por nombre** (44 cambian + `vocabulario.json`, `vocabulario_publico.json`,
`nucleo/Vocabulario.php`; no `--todo`, para que los otros 26 conserven su fin de línea). Los 3 nuevos primero.

```
t2_10_desplegar.py: 47 de 47 archivos en el sitio de pruebas · «la web entrega exactamente lo que se subió»
php -l con el PHP DEL SERVIDOR (8.2.33): 33 archivos PHP, 0 errores de sintaxis
verificar_esquema.php: TODO OK (incluye equipos_ficha de T2.28.6)
web: vocabulario_publico.json 200 · vocabulario.json 403 · catalogos/locales.json 403 · nucleo/config.php 403
     el JSON público servido: 104 conceptos, 0 menciones de «Isabel», rutas, contratos ni lista negra
error_log de la web tras el despliegue: 0 errores (las 4 líneas del día son de mis corridas de CLI, ya corregido)
tarjeta_cli.php contra la base REAL (nuevo, solo lectura, solo agregados) — ADMIN, JEFE_ZONA:UIO, JEFE_ZONA:CNLJ:
     20 comprobaciones · 0 fallos cada uno, 0,03 s. Incluye las dos consultas SQL nuevas que hasta ahora solo se
     habían probado con una base falsa. TOTAL 125 = conteo directo por estado (105 asignadas + 1 a espera de
     repuesto + 19 nuevas). UIO 52 (19 + 33) · LARB 24 (24 + 0) · CNLJ 49 (32 + 17) · deshabilitados 13 · operativos 61.
     El jefe de zona ve solo su zona, con las mismas cifras que administración.
```

**Cómo se revierte:** subir de `pc/estado-vivo-darkviolet-2026-09-26` los mismos 47 archivos (es una copia exacta de
lo que corría) y borrar los 3 nuevos. **NO se corrió, a propósito:** las baterías `verificar_*.py` (crean cuentas y
casos de prueba y dejan filas en la bitácora inalterable) ni `capturar_pantallas.mjs`; y **nadie ha visto todavía las
pantallas con una sesión iniciada**: hace falta que Andrés (o Isabel) entre a `/ot/` como administración, como jefe
de zona y como técnico. Un celular con la app instalada recibirá el `sw.js` v21 en su próxima apertura.
Pendientes de personas: avisar a Isabel del cambio de cifras (el TOTAL ya no incluye las atendidas por cerrar en
SAP, que van al pie); ¿el remitente del correo de la OT («INDUSTEC · Órdenes de trabajo») es contrato?; ¿el % a
tiempo del preventivo debe contar las «atrasadas por marcar»? Trampas que costaron tiempo: comparar con el servidor
ignorando CRLF/LF (local CRLF, servidor LF), y `lstrip("./")` se come el punto de `.htaccess`.

## 1u. El cuadro «En qué estado están» del inicio, con las cuatro cifras de la administradora (2026-09-27, fuera del plan, pedido de Isabel)

**Rama `pc/panel-estados-administradora-2026-09-27`** (a partir de `pc/vocabulario-sobre-vivo-2026-09-26`, lo desplegado en
darkviolet el 26-sep), **sin desplegar y sin empujar**: el despliegue lo autoriza Andrés.

**Pedido (27-sep-2026).** Isabel: «en las estadísticas, que se muestre información que ella usa más como: cuántos casos se
crearon y no nos competían, cuántos casos están asignados (en gestión técnica), atendido (cerrado), a espera de repuestos
(gestión proveedores KFC)». El panel «En qué estado están» era una barra por estado con nueve rótulos, con las «atendidas»
partidas en dos (por cerrar en SAP / cerradas en SAP) y sin la palabra «gestión» por ningún lado.

**Qué quedó hecho.** Encima de los gráficos del inicio, un cuadro de cuatro tarjetas con los rótulos del diccionario
(`vocabulario.json` v2026-09-27.1: cuatro conceptos derivados nuevos, ningún término existente cambió), para la administración
y para el jefe de zona (sobre su zona). Qué estado de la base suma a cada cifra está en **una sola tabla**,
`Casos::CIFRAS_ESTADO`, que leen el panel, la prueba sintética y `tarjeta_cli.php`; se cuenta orden por orden sobre el
catálogo de 90 días del correo, por el estado de vista (una cerrada sin atención ya regularizada no cuenta como alarma):

| Tarjeta | Suma | Enlace |
|---|---|---|
| **Creadas que no nos competen** | NO_COMPETE | `casos.php?est=NO_COMPETE` |
| **Asignadas · en gestión técnica** | solo ASIGNADO (no EN_REVISION, que está en manos de la administración; no ESPERA_REPUESTO, que es la cuarta) | `casos.php?est=ASIGNADO` |
| **Atendidas · cerradas por INDUSTEC** | ATENDIDO + RESUELTO (RESUELTO solo se alcanza desde ATENDIDO; las cerradas *sin* atención quedan fuera) | el pie desglosa «N atendidas, por cerrar en SAP · N cerradas en SAP», cada parte con su `?est=` |
| **A espera de repuesto · gestión proveedores KFC** | ESPERA_REPUESTO, orden por orden | `casos.php?est=ESPERA_REPUESTO` |

Cada cifra de un solo estado es exactamente las filas de su enlace (el buzón filtra con la misma `Ui::estadoVista()`). Los
otros estados (sin asignar, en revisión, cerradas sin atención, regularizadas) siguen debajo en el gráfico de barras —ahora
«Todos los estados»— y en su «Ver los números». La tarjeta «A espera de repuesto» de «Cómo va el buzón» se retiró: es la
cuarta cifra del cuadro. `sw.js` pasa a **v22**. Como «cerrada» nunca va sola (VOCABULARIO §5), «atendido (cerrado)» se
rotula «cerradas por INDUSTEC». Detalle en `VOCABULARIO.md` (versión `2026-09-27.1`, §4, §8.8 y §11 preguntas 6-8).

**Verificación (local, PHP 8.3.35 del PC; sin servidor):**

```
prueba_cifras_estado.php (nueva, base sintética de 20 órdenes con los 9 estados): 77 · 0
prueba_vocabulario 154·0 · prueba_claves_vocabulario 749·0 · prueba_lista_negra 0 hallazgos en 154 archivos · prueba_panel_zona 70·0
generar_vocabulario.php --comprobar: todas las listas coinciden · prueba_contratos.mjs 57·0 · prueba_graficos.mjs 62·0
php -l: todo publico/, nucleo/, pruebas/ sin errores · node --check ui.js sw.js · py_compile verificar_cifras.py
render sin base (Db falsa en el scratchpad, mismas 20 órdenes) como ADMIN: 4 tarjetas 2 · 4 · 5 (2 + 3) · 3, sin Warning/Notice;
     como JEFE_ZONA UIO: 1 · 1 · 2 · 1, solo su zona; el gráfico «Todos los estados» suma las mismas 20
```

**Lo que NO se comprobó:** nada contra darkviolet ni contra la base real. `tarjeta_cli.php` (7 comprobaciones nuevas contra el
conteo directo por estado) y `verificar_cifras.py` (10 nuevas: las cuatro tarjetas contra la referencia SQL por estado de vista y
`casos.php?est=` para los cinco estados) están escritos pero **sin correr**: el primero necesita la base, el segundo crea huella
en la bitácora. Nadie ha visto el cuadro en un navegador con sesión. Al desplegar: `panel.php`, `nucleo/Casos.php`,
`vocabulario.json`, `vocabulario_publico.json`, `ui.js`, `sw.js` (6 archivos), y correr `tarjeta_cli.php ADMIN` en el servidor.

**AMPLIACIÓN (27-sep-2026): la gestión del repuesto y las marcas de la novedad — HECHA en la rama, sin desplegar.** Isabel
precisó los pasos SAP del repuesto (PENDIENTE GESTIÓN PROVEEDORES NACIONALES · BODEGA KFC · JEFES TEC. DE MANTENIMIENTO KFC ·
APROBACIÓN PARA DESPACHO) y cuatro identificaciones «en novedades» (PROVEEDOR EXTERNO · POR DECIDIR, QUITAR O MEJORAR · RIESGO
ALTO, DERIVAR RESPONSABLE · YA TIENE AVISO EN SAP, MANT. CONSTRUCTIVO INDUSTEC). Tres decisiones mías, **pendientes de Andrés**
(preguntas 9-11 de `VOCABULARIO.md` §11): el paso del repuesto es un dato **de la orden** (`casos_gestion.repuesto_gestion`, NULL
= sin precisar; aparte del estado, como `otro_trabajo`), no de la solicitud; las cuatro identificaciones son **marcas de la
novedad** (`novedad_marcas`, varias por novedad), no de la orden — corrección al coordinador: el módulo de novedades ya tenía
riesgo, responsable propuesto y aviso SAP; y «POR DECIDIR» se rotula como decisión de KFC (regla de la versión .6).

Qué quedó: `sql/022_gestion_repuesto_y_marcas_novedad.sql` (columna + tabla + permiso `casos.repuesto_gestion` a ADM/JZ;
idempotente, con su reversión escrita); `vocabulario.json` **v2026-09-27.2** (+9 conceptos `REP_*`/`MARCA_*`; títulos de los
pasos en MAYÚSCULAS porque copian el registro SAP; regenerados `ui.js` y `vocabulario_publico.json`; `sw.js` v23);
`Casos::REPUESTO_GESTION` y el desglose `repuesto_gestion` en `cifrasEstado()`; `Novedades::MARCAS/VIVAS/marcas()/marcar()`,
contadores con `marcas` (null si la 022 no está: el panel dice «no disponible»), grupo `vivas` y filtro `marca`; `casos.php`
acción «Gestión del repuesto» (diálogo, botón en la fila a espera de repuesto, filtro `?rep=`, paso visible en la fila);
`novedades_visita.php` acción «Identificar» (casillas, chips en la fila, filtros rápidos por marca con cifra; el técnico ve los
chips, no identifica); `panel.php` pie de la cuarta cifra desglosado por paso con enlace, y fila «Novedades · identificadas»;
`verificar_esquema.php` bloque «migracion 022» y `$mas022` en los permisos.

**Auditoría de los dos WIP (a03f066 → 2517bbc → 692f522):** el `2517bbc` del coordinador solo confirmó la 022 y mi `692f522`
la dejó byte a byte igual (no hay duplicado). Leídos a mano los diffs de `casos.php`, `novedades_visita.php`, `panel.php`,
`Casos.php` y `Novedades.php`: ningún cambio de fondo. Lo corregido en el cierre: la comprobación «con cero órdenes» de
`prueba_cifras_estado.php` (retorno ampliado) y una expectativa mía en `prueba_panel_zona.php` que había pisado por error una
línea original (CNLJ `[1, 1, 1]`, restaurada y verificada contra git).

**Verificación (local, PHP 8.3.35, sin servidor):**

```
prueba_cifras_estado 143·0 (desglose por paso, filtro ?rep=, claves REP_*/MARCA_* con lista negra, la 022 declara los mismos valores que los mapas)
prueba_panel_zona 76·0 (el paso no mueve la tarjeta) · prueba_vocabulario 154·0 · prueba_claves_vocabulario 760·0 · prueba_lista_negra 0 hallazgos
prueba_48h 125·0 · prueba_despacho 20·0 · prueba_contratos.mjs 57·0 · prueba_graficos.mjs 62·0 · generar_vocabulario --comprobar al día
php -l 85 archivos sin errores · node --check ui.js sw.js · py_compile verificar_cifras.py
render con Db falsa (20 órdenes + 2 novedades sintéticas con 3 marcas), sin Warning en lo del producto:
  panel ADMIN: pie de la 4.ª cifra «1 pendiente gestión bodega KFC · 1 … jefes técnicos … · 1 sin precisar la gestión» con sus 3 enlaces;
        novedades 1·0·1·1 · panel JEFE_ZONA UIO: «1 sin precisar» y las mismas 4 marcas de su zona
  casos ADMIN: 3 botones «Gestión del repuesto» (las 3 a espera de repuesto), filtro f-rep, diálogo acc-rep, el paso en cada fila;
        ?est=ESPERA_REPUESTO&rep=BODEGA_KFC → 1 fila, &rep=SIN → 1 fila · JEFE_ZONA: 1 botón · TECNICO: 0
  novedades ADMIN: 2 «Identificar», 3 chips, 4 filtros por marca, diálogo · TECNICO: 3 chips, 0 «Identificar», 0 filtros
  (19 «Undefined array key nombre» en Casos.php:1374 —quienAtendio— son de la Db falsa, que no une usuarios; no del producto)
prueba_continuidad, prueba_destinatarios y prueba_casos_prueba no corren aquí: exigen nucleo/config.php (base real)
```

**NO comprobado:** nada contra darkviolet ni la base real; la 022 no se ha aplicado en ningún sitio; `tarjeta_cli.php` (+7
comprobaciones del desglose) y `verificar_cifras.py` (+desglose y `?rep=`) están escritos sin correr; ningún navegador con sesión.

**Para desplegar (cuando Andrés autorice; primero UIO, I-8):** aplicar `sql/022_gestion_repuesto_y_marcas_novedad.sql` con
`aplicar_sql.php` y correr `verificar_esquema.php`; subir 9 archivos: `panel.php`, `casos.php`, `novedades_visita.php`,
`nucleo/Casos.php`, `nucleo/Novedades.php`, `vocabulario.json`, `vocabulario_publico.json`, `ui.js`, `sw.js` (+
`verificar_esquema.php` y `pruebas/servidor/tarjeta_cli.php` como herramientas); después `tarjeta_cli.php ADMIN` en el servidor.
Sin la 022 aplicada, el código no rompe: el paso sale «sin precisar» y las marcas «no disponible».

### Despliegue a darkviolet: HECHO el 2026-09-28 (autorizado por Andrés: «despliega todo»)

**Antes de subir** se comparó por hash (LF normalizado) lo vivo contra `c53d4fd` (lo desplegado el 26-sep) y contra `d5b6cfe`:
9 de los 10 archivos vivos eran iguales a la base. **`casos.php` vivo NO era la base**: era el commit `190eea3` («doble Enter
confirma “Marcar como cerrada en SAP”», ramas `pc/doble-enter-cerrado-sap-2026-09-27` y `pc/buzon-simplificado-2026-09-27`), es
decir, trabajo que sí está en git, desplegado desde el PC después del 26-sep. No se pisó: se hizo una **fusión a tres vías**
(`git merge-file`, base `c53d4fd`, mío `d5b6cfe`, vivo `190eea3`), 0 conflictos, `php -l` limpio, lista negra 0 y claves
760·0; el `casos.php` desplegado lleva las dos cosas (diálogo `#acc-seguro` y «Gestión del repuesto») y queda commiteado en
esta rama. Copia de los 10 archivos vivos previos en el scratchpad del PC (`…/scratchpad/vivo_2026-09-28/`); además, lo que
corría es reconstruible desde git: `c53d4fd` para 9 archivos y `190eea3` para `casos.php`. `~/respaldos/tarjeta_cli.php` no
existía en el servidor (el del 26-sep no quedó): se subió el nuevo.

**Migración 022**: `aplicar_sql.php ~/respaldos/022_gestion_repuesto_y_marcas_novedad.sql` → 4 sentencias (ALTER, CREATE,
INSERT, INSERT) ok, anotada en `migraciones`. Respaldo de la base: `~/respaldos/darkviolet_bd_tras_022_20260928_081440.sql.gz`
(917.631 bytes) — **tomado justo después de aplicar la 022**, no antes: el primer intento de `mysqldump` desde `php -r` no dejó
archivo; como la 022 solo agrega columnas y una tabla vacía, el respaldo sirve igual para los datos, y la reversión de esquema
está escrita en el propio `.sql`.

```
t2_10_desplegar.py: 10 de 10 archivos en el sitio de pruebas (los 2 del diccionario y los 2 nucleo/ primero) · «la web entrega exactamente lo que se subió»
sha256 exacto local = servidor en los 10 · php -l con el PHP del servidor (8.2.33): 6 PHP sin errores
verificar_esquema.php: permisos SUPERADMIN 43 · ADMIN 42 · JEFE_ZONA 29 (con la 022) OK · bloque «migracion 022» OK (marcas hoy: 0 · órdenes con gestión del repuesto: 0) · TODO OK
web: vocabulario.json 403 · vocabulario_publico.json 200 (v2026-09-27.2, 117 conceptos, sin «Isabel» ni rutas) · nucleo/config.php 403 · sw.js sirve v23
tarjeta_cli.php ADMIN: 34 comprobaciones · 0 fallos · cuadro {no nos competen 1 · en gestión técnica 107 · atendidas/cerradas 149 · a espera de repuesto 2}
     gestión del repuesto: las 2 sin precisar (esperado: la 022 no inventa el paso) · JEFE_ZONA:UIO: 34 · 0, cuadro {0 · 46 · 37 · 2}
error_log de la web tras el despliegue: 0 errores nuevos (las 2 líneas del día son de mi corrida de tarjeta_cli.php ANTES de subir Casos.php)
```

**NO se hizo, a propósito:** `verificar_cifras.py` ni ninguna batería `verificar_*.py` (crean datos y huella en la bitácora);
ningún navegador con sesión —hace falta que Andrés o Isabel entren como administración y jefe de zona y prueben «Gestión del
repuesto» en una orden a espera de repuesto e «Identificar» en una novedad—. Un celular con la app recibirá el `sw.js` v23 al
abrirla. **Cómo se revierte:** subir por nombre los 9 archivos desde `c53d4fd` y `casos.php` desde `190eea3` (o la copia del
scratchpad), borrar `~/respaldos/tarjeta_cli.php`, y para el esquema las cuatro sentencias de reversión de la cabecera del
`022_…sql` (la columna y la tabla no tienen datos todavía). **Pendiente de fusión:** `pc/buzon-simplificado-2026-09-27` también
parte de `190eea3`; cuando se despliegue habrá que fusionar su `casos.php` con este.

**Preguntas para Andrés** (ninguna bloquea; la 1 solo cambia el número grande): (1) ¿«atendido (cerrado)» = ATENDIDO +
RESUELTO, o solo las ya cerradas en SAP? (2) ¿«asignados (en gestión técnica)» = solo ASIGNADO, sin EN_REVISION? (3) Isabel dice
«a espera de repuestos» (plural) y «gestión proveedores KFC»; se mantuvo «a espera de repuesto» (singular, como `ESPERA_REPUESTO`)
con el calificativo: ¿se deja o se cambia el término en todo el sistema?

## 1s. T2.28 · Lo que se midió para planificar las observaciones de INDUSTEC (2026-09-22/23, solo lectura)

**No se construyó nada: es la medición que sostiene el plan.** La especificación,
con cada cifra y su fuente, está en [`T2_28_OBSERVACIONES_INDUSTEC.md`](T2_28_OBSERVACIONES_INDUSTEC.md)
§2, §2b y §2c; aquí van las que cambian decisiones.

| Qué | Cifra | Cómo se midió |
|---|---|---|
| Correo real del local en el maestro | **4 de 100** (95 son `servicioalcliente@industec.me`) | `SELECT` en `locales` (estación) |
| La orden lleva el correo del local | **No**: `reunirOrden()` no lo manda; la cola y el PDF lo toman del maestro | lectura de `app.js` y `Emision.php:251, 371` |
| `G:\Mi unidad\INDUSTEC IA\CORREOS LOCALES INDUSTEC.xlsx` (22-sep) | **95 locales; 92 iguales al histórico de órdenes, 0 distintos**; zona igual en todos; 5 locales sin correo | cruce con `ots.correo_local` |
| Buzón del jefe de zona | UIO `jefezona-uio@`, LARB `jefetecniconacional@`, CNLJ `jefezonacuenca-loja@` — **iguales en producción (`submit.php:165-169`) y en el maestro** | dos fuentes |
| Tope del SMTP | **50 correos por hora**; producción lo excedió 40 veces el 2025-09-22 | `error_normal.log` del sistema viejo |
| Administradores por local en el histórico | **1.294 pares en 99 locales**; mediana 8 por local; variantes y ruido que hay que limpiar | `ots.admin_nombre` |
| Cronograma de preventivos | **87 ingresos reprogramados** por la administradora en su Excel el 22-sep y no en el sistema; **37** convierten y difieren (UIO, 9 a 2027); ~50 en formatos que el importador no lee | Excel contra `ingresos_preventivos` por `sql_remoto` |
| Catálogo de bodega de KFC (PDF) | **1.611 códigos SAP y 1.586 imágenes**, con el número de parte en el formato de Parts Town; se extrae limpio con `pdfplumber` | `extract_tables()` |
| Histórico de marca/modelo/serie | 9.646 filas, pero **5 de 767** cruzan con el catálogo del formulario (otra numeración, `S/N`, `Xxx`) | cruce (error nº 35) |
| Texto de actividades para la semilla | **2.956** descripciones de preventivo por equipo y **6.619** de correctivo | estación |
| Archivo | **7.772** órdenes; **7.640** con PDF íntegro en disco; **132 sin PDF** = 24 que la estación sí tiene + **97 duplicados por nombre** (`R002` y `R002EC`) + 11 por investigar; 1 PDF sin fila | `sql_remoto` + `find` por SSH |
| InspectorBot, 2026-09-23 09:59 | **GRAVE**: el paso `pdfs` del nocturno abortó las dos corridas del 23 por un `ssh … mkdir` colgado 300 s; 9 errores en 24 h | `inspectorbot_estado.py` |
| «Errores» que no lo son | reconexión del IMAP cada ~10 min; 4 PDF con fecha inválida (`''`, `20026-07-30`) que **sí se guardan** en cuarentena pero se anotan como `ERROR` cada noche | `vigilante-*.log`, `t1_7_ingesta.py:211` |
| Casos sin local | **2**, ambos `V090 SUPER AKI LA JOYA GYE` | `casos_sap.json` |

**Lo que NO se comprobó:** que los 7.640 PDF abran por `pdf.php` con sesión (se
comprobó el disco, no la web: hace falta el arnés); la causa de los cuelgues de SSH
(hay una hipótesis —sesiones simultáneas del vigilante y del nocturno— y T2.28.18a
la mide antes de arreglar nada); y el formato exacto de los enlaces de Parts Town
(el sitio se arma con JavaScript: T2.28.11 lo prueba con un navegador).

### 1s-bis. T2.28.0 · Línea base antes de empezar a construir (2026-09-23)

`master` al día con `origin/master` (0 commits de diferencia) y las cuatro ramas
`pc/*` ya fusionadas — confirmado con el bucle de §11b. `git status --short` solo
muestra `desarrollo/sitio_web/` sin trackear (ajeno, ver su fila en §5.1) y la
edición de la conversación "estadísticas" en esta misma tabla (T2.27.7, no toca
nada de T2.28). Nada que fusionar ni que resolver antes de construir.

Batería local completa, las seis piezas, **todas en verde** — línea base que hay
que igualar o mejorar en cada subtarea:

```
prueba_48h.php               120 comprobaciones · 0 fallos
prueba_contratos.mjs          57 comprobaciones · 0 fallos
prueba_graficos.mjs           62 comprobaciones · 0 fallos
prueba_continuidad.php        42 comprobaciones · 0 fallos
reglas.fixture.mjs (JS)       37/37 casos del fixture
validacion_test.php (PHP)     37/37 casos del fixture
t2_5_validacion.py --fixture  37/37 casos del fixture (Python)
```

Las tres implementaciones de reglas de validación (`reglas.js`, `Validacion.php`,
`t2_5_validacion.py`) siguen de acuerdo en los 37 casos del fixture común
(`fixture_validacion.json`) — condición de partida del error nº 33 evitado, que
cualquier regla nueva de T2.28 debe conservar en las tres.

`sw.js` parte de **`ot-industec-v15`** (línea 38: «estilo.css — distintivo
"regularizado", neutro en vez del rojo de "sin atender"», 2026-09-22). Cada
subtarea que toque la lista de precarga suma 1 (§5.2 del plan).

*Criterio de T2.28.0 cumplido: línea base pegada aquí, fila en §5.1.* Nada de
código tocado en esta fase.

### 1s-ter. T2.28.4a · Correos del maestro, analizado (2026-09-23, segunda pasada)

`t2_28_correos.py --analizar` (solo lectura, ya existía escrito de un intento
anterior sin cuota: se revisó, compiló con `py_compile` y se corrió contra la
base real). Fuente `G:\Mi unidad\INDUSTEC IA\CORREOS LOCALES INDUSTEC.xlsx`,
sha256 `5f4f8d3b0f926c9e78d18bab7d3108d0f6c934dd8c4ca3ae04a14ff068d5fad5`
(anotado también en la hoja «De donde sale» del Excel de salida). **95 filas
leídas, las 95 resueltas** (0 en la hoja «Códigos por alias» sin resolver).

**Correo del local:** A=**94**, B=**6**, C=**0** (repetible: dos corridas
seguidas dan lo mismo). La especificación (línea 503) esperaba A=92/B=8/C=0.
**No se forzó el número esperado (I-7):** el script solo avisa por consola y
sale con código 1, tal como lo dejó el intento anterior.

**La discrepancia tiene una explicación con evidencia, no solo confirmación.**
Los +2 en A son exactamente `BR17EC` y `CN042EC` — los **únicos dos códigos de
todo el Excel que resuelven por ALIAS** (`BS17EC`→`BR17EC`, `CN42EC`→`CN042EC`,
hoja «Códigos por alias»), los mismos dos ejemplos del error nº 1 del proyecto
que ya cita el propio script. Para ambos, el correo más frecuente en `ots`
coincide exacto con el del Excel. Se descartó que fuera un dato nuevo del día
(`SELECT ... GROUP BY local_codigo HAVING MIN(fecha_atencion)='2026-09-23'` →
0 filas: ningún local tiene su primer correo histórico fechado hoy). Hipótesis
más probable, **sin confirmar al 100 %** (no hay acceso a la consulta exacta de
la medición original): esa medición cruzó el Excel contra `ots.correo_local`
por código directo, sin pasar por `locales_alias`, y esos dos locales cayeron
a "sin histórico". Quedó documentado en el propio script, junto a
`ESPERADO_A`, para que no haya que reinvestigarlo. **Decisión pendiente de
persona:** si se corrige la cifra de la línea 503 del documento a 94/6/0 o se
deja como está — no es una decisión de este script.

**Jefe KFC** (sin cifra esperada, informativo): A=79, B=2, C=11, SIN_DATO=8.

**Sin prueba local dedicada:** no existe una batería para este script; la
única verificación disponible fue correrlo contra la base real, dos veces.

Salida: `SALIDAS IA\OTS\CORREOS LOCALES (generado agente).xlsx`. **T2.28.4b
sigue bloqueada por la puerta D1** — no se tocó (ni el `--ejecutar` del script,
que sigue saliendo solo con el mensaje de bloqueo, ni `locales`, ni ninguna
tabla del servidor).

### 1s-quater. T2.28.10a · Catálogo de repuestos, analizado (2026-09-23, segunda pasada)

`t2_28_repuestos.py --analizar` (solo lectura, ya existía escrito de un
intento anterior sin cuota: se leyó entero, compiló limpio con `py_compile` y
se corrió contra las tres fuentes reales, sin cambiarle una línea — estaba
completo). Las fuentes 2 y 3 son de solo lectura bajo `D:\RESPALDOS\_ORIGEN_DRIVE`
y la fuente 1 bajo `G:\Mi unidad` (ninguna se tocó, I-2/I-3).

**Fuente 1 — catálogo de bodega (PDF), 1.623 filas leídas, 0 descartadas:**
**1.611 códigos SAP distintos** (criterio cumplido exacto) y **1.574 con
imagen guardada** en `SALIDAS IA\OTS\repuestos_img\` (criterio ≥ 1.500
cumplido; contados también en disco: `ls repuestos_img | wc -l` → 1574). Las
tres muestras de la especificación aparecen con su código SAP: `Hen22455` →
**15000758**, `Hen29898` → **17000121**, `Man000007926` → **17000144**.

**Fuente 2 — stock de bodega ene-2026 (Excel), 129 filas leídas, 0
descartadas, 129 códigos SAP distintos.** La especificación (línea 660) dice
«130 repuestos»; la hoja real tiene 129. Mismo patrón que el error nº 21 (un
conteo se refuta contra el dato, no al revés): gana el código, se deja la
diferencia anotada aquí y en la hoja «Resumen» del Excel de salida, no se
fuerza el 130.

**Fuente 3 — Inventario 2023, dónde se usa (Excel): 57.451 filas leídas,
10.190 descartadas** (sin nombre de parte y sin número de parte), **47.261
cargables, 11.854 combinaciones (marca, número) distintas.**

**Cruces:** de los 1.611 códigos de la fuente 1, **118 tienen fila de stock**
(fuente 2, por código SAP) y **777 encontraron dónde se usan** (fuente 3, por
marca + número de parte, con y sin el prefijo de Parts Town).

**Salida:** `SALIDAS IA\OTS\CATALOGO DE REPUESTOS - PROPUESTA (generado
agente).xlsx`, con las seis hojas que pide la especificación (Resumen,
Catalogo con 1.611 filas de datos, Sin numero de parte con 58, Marcas por
unificar con 69, Cruces, Vigencia) — verificado abriendo el archivo y
contando filas por hoja, no solo por el log de la corrida.

**Sin prueba local dedicada:** no existe una batería para este script (mismo
caso que T2.28.4a); la única verificación disponible fue correr
`--analizar` contra las fuentes reales y comprobar el Excel y las imágenes
resultantes a mano.

**No se tocó nada de 10b/10c/10d** (migración `017_catalogo_repuestos.sql`,
`--exportar`, `repuestos_cargar_cli.php`, `repuestos_catalogo.php`): están
detrás de la puerta **D2** (decisión de Andrés sobre la fuente elegida y el N
a cargar), fuera del alcance de esta subtarea. No se tocó la base, ningún PHP
del servidor, ni se hizo `git commit`/`git push` — cambios en el árbol de
trabajo, sin confirmar.

---

### 1s-quinquies. T2.28.16a/16b · El cronograma de preventivos, convertido y comparado contra el sistema (2026-09-23, segunda pasada, solo lectura)

**16a — `t2_7_cronograma_preventivo.py` lee el Excel de hoy (22-sep) con tres
reglas nuevas:** abreviaturas de mes (`sep`, `dic`, `nov`, con o sin punto ni
«y»), el día pegado al mes sin espacio (`julio3` → `julio 3`, typeo real de la
administradora en K121EC) y el cambio de año dentro de la fila cuando un
ingreso en palabras da una fecha anterior al ingreso previo del mismo local
(G020EC ingreso 4, `11 Y 12 ENERO` → 2027, porque el ingreso 3 ya fue en
octubre-2026). La resolución vive en `resolver_fila()`/`resolver_ingreso()`,
una sola implementación que también usa 16b — no hay una segunda copia de la
regla que pueda desalinearse.

```
$ .venv/Scripts/python.exe scripts/t2_7_cronograma_preventivo.py --pruebas
PRUEBAS: 15 casos, 0 fallos.

$ .venv/Scripts/python.exe scripts/t2_7_cronograma_preventivo.py
cronograma leido: 92 locales
formas de las celdas: {'NO_RECONOCIDO': 1, 'NUMERICA': 85, 'PALABRAS': 214, 'PENDIENTE': 10, 'TIPADA': 54, 'VACIA': 4}
con fecha real         : 353 (95.9%)
sin convertir          : 5
no se convirtieron (no se adivinan) -- una por una:
   G051EC ingreso 3: None (VACIA)
   G051EC ingreso 4: None (VACIA)
   R001EC ingreso 3: None (VACIA)
   R001EC ingreso 4: None (VACIA)
   H032EC ingreso 4: '01-12/2026' (NO_RECONOCIDO)
```

**Criterio «0 `NO_RECONOCIDO` con números de día»: 1 excepción real,
documentada, no adivinada (I-7).** `H032EC` ingreso 4 trae `'01-12/2026'` —
ni una fecha `dd-dd/mm/aaaa` (falta el mes) ni ninguna de las formas de §2b.
Es la única celda de las 368 con esa forma (barrida por regex sobre las 4
columnas de fecha, 0 más). Adivinar si «01-12» es día-día o mes-mes habría
sido inventar un dato que va a KFC (I-7): queda `NO_RECONOCIDO` y listada,
para que la administradora la aclare.

**Prueba de no regresión contra el snapshot del 8-sep**
(`SALIDAS IA/OTS/app_ots/catalogos/cronograma_preventivo.json`, generado
2026-09-08 14:54, ANTES de estos cambios): de los 352 ingresos que ya
convertían entonces, reprocesados hoy con `parsear_ingreso()`/`a_fechas()` dan
**el mismo resultado en 351 de 352**. La única diferencia es, otra vez,
`K121EC` ingreso 3 (`'30 y 31 de julio3 de agosto'`): el 8-sep dio
`2026-08-30..31` (2 días, todos en agosto — el bug del typeo pegado, que
asignaba todo a agosto y perdía el 3 de agosto); hoy da `2026-07-30..08-03`
(3 días: 30 y 31 de julio, 3 de agosto), que es lo que el texto realmente
dice. **Es la corrección a propósito de 16a, no una regresión** — está
documentada en el comentario de `parsear_ingreso()` con esta misma celda como
caso real. Verificación ad hoc en el scratchpad de la sesión (no forma parte
del repo; se puede rehacer con el snapshot citado arriba).

**16b — `t2_28_cronograma.py --comparar`** (solo lectura: Excel +
`ingresos_preventivos` del sitio de pruebas vía `sql_remoto`/SSH). Salida:
`SALIDAS IA\OTS\CRONOGRAMA - EXCEL CONTRA SISTEMA (generado agente).xlsx`
(hojas `DIFERENCIAS`, `RESUMEN`, `CONFLICTO Y NO_CONVERTIBLE`).

```
$ .venv/Scripts/python.exe scripts/t2_28_cronograma.py --comparar
ingresos comparados : 368
  REAGENDAR       : 52
  SIN_CAMBIO      : 300
  CONFLICTO       : 1
  NO_CONVERTIBLE  : 15
  SIN_FILA_SISTEMA: 0
CONFLICTO con CUMPLIDO: 1
```

**Desglose de los 52 `REAGENDAR`** (script ad hoc que simula el parser previo
a 16a sobre las mismas celdas de hoy): **37 ya los daba el parser anterior**
— coincide exacto con la cifra de la medición original del plan (§1s) — y
**15 son nuevos, todos por la abreviatura de mes** (`21 y 22 sep`,
`10 11 DIC`, `16 17 Y 18 NOV`, etc., en G001/G002/G003/G005/G006/R002/R006/
R007/R009/R011/R014EC). Es exactamente «37 + lo que destape 16a» del
criterio.

**El único `CONFLICTO` es con un `CUMPLIDO`, y tiene la misma causa que la
excepción de arriba: `K121EC` ingreso 3.** El sistema tiene
`plan_original = plan_vigente = 2026-08-30..31` (lo que el importador del
8-sep leyó, ya con el bug del typeo) y esa orden ya está `CUMPLIDO`. El Excel
de hoy, leído bien, dice `2026-07-30..08-03`. **No es un defecto de 16a: es
16a destapando que el cronograma vigente del sistema para este ingreso nació
mal leído**, y el técnico ya trabajó contra esa fecha equivocada. No se tocó
nada (`plan_original_*` intocable, prohibido reagendar un `CUMPLIDO` en esta
subtarea) — queda en la hoja `CONFLICTO Y NO_CONVERTIBLE` del informe para
que D7 lo resuelva con la administradora.

**3 locales revisados a mano contra el Excel** (criterio de 16b): `G001EC`
(`21 y 22 sep` → `2026-09-21..22` REAGENDAR; `10 11 DIC` → `2026-12-10..11`
REAGENDAR), `G020EC` (`16 Y 19 octubre` → `2026-10-16..19` REAGENDAR;
`11 Y 12 ENERO` → `2027-01-11..12`, con el año corregido y motivo explicado,
REAGENDAR), `K121EC` (el caso de arriba, CONFLICTO). Las tres coinciden con
la celda cruda del Excel, comprobado con `openpyxl` directo.

**No se tocó `hostinger_ssh.py`** (modificado por T2.28.18 en otro carril, se
usó tal cual está en el working tree, solo lectura vía `sql_remoto`). **No se
tocaron `plan_original_*`, no se reagendó nada, no se escribió en el Excel de
la administradora.** 16c y 16d (detrás de D7) no se empezaron.

**`t2_28_cronograma.py` no tiene batería de pruebas propia** (no existe un
`--pruebas` para 16b, a diferencia de 16a): su corrección descansa en reusar
`resolver_fila()` de 16a (ya con 15/15 pruebas) y en la verificación ad hoc de
esta pasada (no forma parte del repo) — el desglose 37+15 y el cruce manual
de 3 locales contra la celda cruda del Excel. Dicho explícitamente para que
la siguiente conversación no lo dé por probado con un fixture que no existe.

*Verificado: compila (`py_compile`), 15/15 pruebas unitarias, 351/352 sin
regresión contra el snapshot del 8-sep (1 corrección documentada), informe
16b generado y sus cifras coinciden con la cuenta impresa por el script y con
3 locales revisados a mano. **No se corrió ninguna batería de servidor**
(esta subtarea es de solo lectura sobre `sql_remoto`, no toca el formulario
ni PHP; no aplica).*

---

### 1s-sexies. T2.28.1 · El arnés ya no secuestra casos reales, y T2.28.17b · el Archivo accesible por la web (2026-09-24, segunda pasada)

**Carril: el arnés de pruebas del servidor** (`nucleo/Casos.php`,
`preparar_prueba.php`, `limpiar_pruebas.php`, `verificar_http.py`,
`verificar_emision.py`, `verificar_bandeja.py`, `verificar_formulario.mjs`,
`pruebas/servidor/LEEME.md`, y el nuevo `verificar_archivo_pdf.py`). Un
intento anterior había dejado el código sustancialmente escrito (el diseño de
T2.28.1 completo, `prueba_casos_prueba.php` con sus 7 casos) y se quedó sin
cuota antes de correrlo de verdad contra el servidor. Esta pasada verificó lo
hecho, encontró y corrigió **dos bugs reales** que esa corrida habría
destapado, y completó el ciclo entero contra darkviolet.

**Lo que ya estaba bien y se confirmó, sin reescribir:** `Casos::catalogo()`
fusiona `catalogos/casos_prueba.json` solo para una cuenta de prueba (login
con `_prueba`) o por CLI, nunca para una cuenta real; `preparar_prueba.php`
ya no toca ningún aviso que no empiece por `9999`; `prueba_casos_prueba.php`
(nuevo, prueba de unidad PHP, no toca el servidor) pasa **7/7**.

**Dos bugs reales, encontrados al correr contra el servidor de verdad (no
los atrapa ninguna prueba local) y corregidos, en `preparar_prueba.php`:**
1. Consultaba `SELECT nombre, cadena FROM locales WHERE local_codigo = ?`
   — esa tabla **no existe** en el esquema de darkviolet; el maestro de
   locales del sitio vive en `catalogos/locales.json`. Corregido: se lee ese
   JSON (mismo campo `codigo` que usa `Catalogo.php`).
2. Al iterar `foreach ($SINT_LOCAL as $aviso => $info)` con claves
   `'99990021'`/`'99990022'`, PHP convierte a **entero** cualquier clave que
   sea solo dígitos sin ceros a la izquierda: `$aviso` llegaba `int` y
   `str_pad()` reventaba bajo `strict_types`. Corregido con un cast explícito
   a `string`.

**Un tercer hallazgo, más caro:** el `nucleo/Casos.php` corregido nunca se
había desplegado al sitio — `pruebas/servidor/*.php` sube por scp a
`~/respaldos/`, pero `app/publico/nucleo/*.php` sube por `t2_10_desplegar.py`,
y solo se había corrido lo primero. Con el `Casos.php` viejo en el servidor,
`catalogo()` seguía devolviendo solo `casos_sap.json` (885 avisos, cero
`9999xxxx`), aunque `pruebaAplica()` diera `true` y `casos_prueba.json`
estuviera bien escrito — un falso negativo silencioso. Desplegado con
`t2_10_desplegar.py nucleo/Casos.php`; hash confirmado igual en disco y en
"la web entrega exactamente lo que se subió". Detalle y regla para que no se
repita: error nº 42 del plan.

**Paso 1 (T2.28.1) — evidencia:**
```
SELECT COUNT(*) FROM casos_gestion g JOIN usuarios u ON u.usuario_id=g.asignado_a
WHERE u.usuario LIKE '%_prueba%' AND g.aviso NOT LIKE '9999%'          -> 0
```
Locales, todas en verde y sin regresión: `prueba_48h.php` 120·0,
`prueba_contratos.mjs` 57·0, `prueba_graficos.mjs` 62·0,
`prueba_continuidad.php` 42·0, `prueba_casos_prueba.php` 7·0, y las tres
implementaciones de reglas de validación 37/37 (JS, PHP, Python). Ciclo
completo de servidor, con `preparar_prueba.php` → las cuatro baterías (en
cualquier orden, cada una con su aviso: `verificar_http.py` 99990022,
`verificar_emision.py` 99990021) → `limpiar_pruebas.php`:
`verificar_http.py` **86·0**, `verificar_emision.py` **34·0**,
`verificar_bandeja.py` **37·0**, `verificar_formulario.mjs` (con Edge)
**12·0**. `limpiar_pruebas.php --ejecutar` cuadró exacto contra su propio
simulacro las dos veces que corrió.

**Paso 2 (T2.28.17b) — evidencia.** `verificar_archivo_pdf.py`, nuevo:
muestra estratificada de 300 órdenes `en_servidor=1` (origen × zona × año,
semilla fija 20280917, reproducible). **300/300 con sesión ADMIN** (200,
`application/pdf`, cuerpo `%PDF-`), **300/300 con sesión TECNICO** (D1
2026-09-12 dio lectura del Archivo a los cuatro roles — confirmado en
`rol_permisos`, así que un técnico entra a cualquier orden, no solo a las
suyas) y **300/300 sin sesión → 302**. Los **9 sitios** que arman
`pdf.php?ot=` (grep contra lo desplegado): `app.js:1490` (guarda `emitida`),
`casos.php:926,1027` (`$d['pdf']` / `Emision::existePdf(`), `mis.php:417,
439,484,1009` (`Emision::existePdf(`), `ordenes.php:356,357`
(`en_servidor`) — los 9 con guarda. Solo **`ordenes.php`** ofrece el botón
literal «Pedir copia»; en `casos.php`/`mis.php`/`app.js` el enlace
simplemente se omite o se avisa en texto cuando el PDF no está, sin un botón
alterno (se anota tal cual, no se hace pasar por lo mismo, I-7). Total:
**914/914 comprobaciones pasan, 0 fallan**.

**Lo que NO se pudo comprobar tal como pedía el diseño (I-7):** la muestra de
300 no se estratificó por origen HISTORICO/CORREO/APP porque hoy
`ot_archivo` en darkviolet **solo tiene origen=CORREO** con `en_servidor=1`
(7.664 filas de 7.772; ver `DISTINCT origen`). El volcado del árbol canónico
(HISTORICO) y de las órdenes de la app (APP) a esa tabla del sitio de pruebas
es T2.28.17a/17c/17d/17e, de otro carril, y no había corrido a esta fecha. La
estratificación real que sí se hizo fue por origen (el único presente) ×
zona × año.

**Limpieza:** las dos corridas de `limpiar_pruebas.php --ejecutar` dejaron el
sitio en 0 cuentas de prueba, 0 avisos `9999xxxx`, 0 casos reales tocados —
confirmado después de cada una.

*No se tocó ningún archivo fuera del carril declarado. No se hizo `git
commit` ni `git push` (regla de esta pasada). Nada de esto escribió en
`casos_gestion` sobre un aviso que no empiece por `9999`.*

### 1s-septies. T2.28.18a/18b/18c · El robot ya no se cuelga en silencio, y T2.28.17a/17c/17e · el Archivo, subido y reconciliado (2026-09-24, segunda pasada)

**Carril: el robot y el Archivo** (`hostinger_ssh.py`, `t2_19_subir_pdfs.py`,
`t1_7_ingesta.py`, `t2_9_buzon_vigilante.py`, `saneamiento_nocturno.py`, y el
nuevo `archivo_verificar_cli.php`). Un intento anterior dejó escrita la
instrumentación completa y se quedó sin cuota antes de correrla contra el
servidor real; esta pasada corrigió dos bugs que solo salieron al correr de
verdad, y completó 17a/17c/17e.

**18a — instrumentación de `logs/ssh_llamadas.csv`, validada con tráfico
real** (no la medición de 48 h, que sigue pendiente de dos noches reales):
183 llamadas reales anotadas en la sesión, 179 `ok`, 1 `timeout` (900 s, a las
22:09:23, del propio vigilante en vivo contra producción — dato real,
consistente con el síntoma que motivó T2.28.18). `logs/ssh_sesiones/` quedó
vacío al terminar (0 candados huérfanos) con el vigilante en vivo (PID 13340)
corriendo en paralelo — el semáforo funciona bajo concurrencia real entre dos
procesos. **Tope de sesiones de Hostinger sin confirmar de primera mano**: no
hubo acceso a hPanel en esta sesión.

**18b — mejoras de buena práctica aplicadas** (`ConnectTimeout=10`,
`ConnectionAttempts=2`, `ServerAliveCountMax=3`, timeouts de 60 s para
comandos triviales con reintento 15/60/180 s solo si son idempotentes,
semáforo de 2 sesiones simultáneas entre procesos, `t2_19_subir_pdfs.py`
reintenta un lote antes de darlo por fallido). `saneamiento_nocturno.py` ya
anota `INDUSTEC_PROCESO=nocturno`. **No se reinició el vigilante en vivo**
(un proceso ya arrancado no recoge el `.py` nuevo): queda para que Andrés lo
reinicie tras revisar el diff. El criterio «dos noches seguidas con
`pdfs: ok`» necesita el nocturno real corriendo con este código, dos
madrugadas — no se puede cumplir en una sesión.

**18c — la entrega a `observaciones_calidad` ya está hecha** (verificado por
SQL directo): 4 filas, regla `FECHA_INVALIDA_EN_PDF_ORIGINAL`, estado
`ABIERTA`, exactamente los 4 casos de la especificación, con el valor leído
tal cual (sin corregir, I-7).

**17a — `archivo_verificar_cli.php` contra el servidor real (solo
lectura).** Se encontraron y corrigieron dos bugs de la pasada anterior que
ningún `py_compile`/`php -l` atrapa: el CLI usaba `__DIR__` en vez de
`getcwd()` (marcaba las 7.640 filas como íntegras=0, falso) y
`hostinger_ssh.ssh()` no reenviaba `idempotente` a `ssh_crudo()` (tumbaba la
primera llamada real de `t2_19`). Corregidos, subidos y corridos:
**7.640/7.640 íntegros** antes de subir los 24 PDF pendientes, **7.664/7.664**
después.

**17c — los 24 PDF que la estación tenía, subidos de verdad.** Simulación
(«faltan: 24, 18.4 MB») → `--ejecutar` («subidos y verificados: 24 ·
fallidos: 0») → reindexado automático. Criterio exacto:
`SELECT COUNT(*) FROM ot_archivo WHERE origen='HISTORICO' AND en_servidor=0`
→ **0**. `SELECT COUNT(*) FROM ot_archivo WHERE en_servidor=1` → **7.664**.

**17e — reconciliación de las 108 filas restantes sin PDF** (97 duplicados
por nombre medidos hace dos días + 11 «por investigar»: el número subió a 108
porque 17a/17c ya cambiaron la foto). Cruce por correlativo+aviso+zona contra
las filas que sí tienen PDF: **las 108 tienen una hermana con el documento —
0 casos genuinamente sin documento hoy.** El PDF huérfano
`OT-2180-CN042-10347141-CNLJ.pdf` quedó indexado como
`OT-2180-CN042EC-10347141-CNLJ` (`en_servidor=1`) dentro de la misma subida.
**No se tocó `duplicado_de`** (T2.28.17d, requiere D8): las 108 siguen
visibles en el Archivo hasta que Andrés apruebe el `UPDATE` con la lista
exacta. Hallazgo aparte, sin tocar: en el servidor hay además una tercera
variante en minúsculas del mismo archivo
(`OT-2180-Cn042-10347141-CNLJ.pdf`) — anomalía para revisar junto con 17d.

**Escrituras reales contra el sitio de pruebas en esta pasada:** 24 PDF
subidos a `darkviolet-armadillo-872352/public_html/ot/ordenes_pdf/` +
reindexado. Dentro del alcance autorizado (regla 9) y verificado con el
criterio SQL exacto.

*Verificado: `py_compile`/`php -l` en verde en los 7 archivos tocados;
`t2_19_pruebas.py` 16/16 (única prueba local existente para este carril). No
existe prueba local para `hostinger_ssh.py`, `t1_7_ingesta.py`,
`t2_9_buzon_vigilante.py` ni `saneamiento_nocturno.py` — dicho explícito, no
asumido. No se hizo `git commit` ni `git push`.*

### 1s-octies. El vigilante en vivo, reiniciado (2026-09-23, tercera pasada)

Andrés pidió directamente «reinicia el robot tú», retomando el pendiente que
§1s-septies había dejado para él (un proceso de Python ya arrancado no recoge
el `.py` nuevo, así que el `PID 13340` seguía trabajando con el código de
antes de `5318497` aunque el fix ya estaba en el árbol). Es el reinicio que la
tabla de T2.28.18 en `T2_28_OBSERVACIONES_INDUSTEC.md` ya listaba como
**autónomo**.

**Antes de tocar nada:** cero procesos `python.exe`/`pythonw.exe` vivos en la
estación (el `PID 13340` ya no existía; el ciclo de reintento de la propia
Tarea programada lo había vuelto a lanzar y a perder varias veces en los
minutos previos — tres arranques en `logs/vigilante-2026-09-23.log` entre
21:06 y 21:14, los dos últimos sin llegar a «escuchando»). `git log`/`git
status` confirmaron que el árbol está en `5318497` (el commit del fix), sin
cambios sin commitear en el carril del robot.

**Reinicio:** `Start-ScheduledTask -TaskName "INDUSTEC - Vigilante del buzon"`
(el mismo lanzador que usa la Tarea programada — `scripts\vigilante.bat` —,
no un `python` suelto). Un solo par de procesos resultante (`PID 29360`
lanzador → `31104` hijo), sin instancia duplicada.

**Evidencia, literal de `logs/vigilante-2026-09-23.log`:**
```
[2026-09-23 21:17:33] vigilante en marcha. Ctrl+C para parar.
[2026-09-23 21:17:52] revisando los informes de OT...
[2026-09-23 21:18:46]    empujado (atenciones): HTTP 200 ok tipo=atenciones n=113 antes=113 ...
[2026-09-23 21:18:46] espejando lo que produccion emitio en los ultimos 60 min...
[2026-09-23 21:18:56] escuchando (renovación cada 9 min)
```
Pasó limpio el paso donde los dos intentos anteriores se habían quedado
colgados («revisando los informes de OT...», 54 s — el mismo orden de
magnitud que una corrida sana) y llegó a régimen estable.

**Verificación cruzada, con InspectorBot (fuente independiente del propio
log):** `python scripts/inspectorbot_estado.py` →
`Robot vivo, PID 29360, arrancado hace 1 min (1 robot(s), 2 procesos en la
lista)` · `Señal hace 31 s`. Un solo robot, el PID nuevo, sin el candado de
instancia única que el propio InspectorBot avisa que falta (línea 710-711 de
su código) pero sin evidencia de que haya dos corriendo a la vez ahora.

**Lo que NO arregla este reinicio, dicho explícito:** InspectorBot sigue en
salud **GRAVE** por dos alertas que no tienen que ver con este paso —
«el saneamiento nocturno falló» (se cortó en el paso `pdfs`, con **código
viejo**, la noche del 22 al 23) y «el robot arrancó 4 veces en 24 h» (cuenta
los intentos fallidos previos a este, documentados arriba). Ninguna de las
dos se resuelve reiniciando: la primera necesita que el saneamiento nocturno
corra con el código nuevo (T2.28.18b, criterio «dos noches con `pdfs: ok`»,
que recién puede empezar a contar desde ahora); la segunda es historial de
hoy y se diluye sola en 24 h. **No se tocó ningún otro archivo ni proceso.**

### 1s-nonies. Arreglo urgente del formulario del técnico, desplegado y verificado (2026-09-24)

Andrés lo pidió «ya funcionando», por encima de la Fase 2. Tres problemas que
reportó INDUSTEC; los tres **desplegados en darkviolet** y comprobados con
toques de verdad en un navegador a tamaño de celular (390×844):

| Reporte | Causa encontrada | Arreglo |
|---|---|---|
| El correo del administrador muestra `servicioalcliente@` y no deja editar | `#correolocal` con `readonly` justo cuando el maestro trae el buzón genérico (95 de 100 locales). **Y aunque se editara, la cola y el PDF leían el maestro** (error nº 40) | Editable siempre, sin proponer nunca un `@industec.me`. Viaja en la orden (`correo_local`) y `Emision::correoLocal()` lo usa en la cola y el PDF de **esa** orden; el maestro no se toca. `envio.php` lo aprende en `locales_admin.correo` y `catalogos.php` lo ofrece como sugerencia (`admins_v2`, clave nueva para no romper la app en caché) |
| Repuestos: además de seleccionar, poder escribir | El campo ya era texto libre, pero con `<datalist>`, que en varios Android se ve como un selector cerrado | Sugerencias propias (`crearSugerencias()` en `app.js`) en repuestos, administrador y correo: siempre se escribe; tocar una sugerencia la copia y se sigue editando; completa el n.° de parte si está vacío |
| En «Emitir», la lista de casos se ve muy reducida y no deja elegir | `.paso-caja` tenía `overflow:hidden`: recortaba la lista flotante al borde del paso, y el buscador de casos está al final del primero | `overflow` visible; las esquinas del encabezado se sostienen con su propio radio |

Archivos: `app.js`, `index.html`, `estilo.css`, `sw.js` (**v15 → v16**),
`envio.php`, `catalogos.php`, `nucleo/Catalogo.php`, `nucleo/Emision.php`.
`t2_10_desplegar.py` → «8 de 8 archivos… la web entrega exactamente lo que se
subió».

**Evidencia, literal:**
```
verificar_formulario.mjs  25 comprobaciones · 0 fallos   (bloque G nuevo, 12 de ellas)
  PASA  G1 la lista de casos se abre al tocar el buscador   {"visible":true,"opciones":3,"alto":227,"overflow":"visible"}
  PASA  G1 el caso se puede tocar (nada lo tapa ni lo recorta)   alto de la opción 61px
  PASA  G1 tocar el caso lo elige                            99990021
  PASA  G2 el correo del local ya no es de solo lectura      {"soloLectura":false,"valor":""}
  PASA  G2 se puede escribir el correo / el administrador
  PASA  G3 se puede escribir un repuesto que no está en la lista   repuesto escrito a mano xyz
  PASA  G3 al escribir, sugiere los repuestos conocidos      «Conj» → 4 sugerencias
  PASA  G3 después de elegir, se sigue pudiendo escribir     Conjunto de placa de control (modificado)
  PASA  la orden lleva el correo del local que se escribió   admin.prueba@local-prueba.ec
verificar_emision.py      38 de 38 (eran 34; 4 nuevas)
  PASA  correo  la cola va al correo del local que escribió el técnico  ["admin.prueba@local-prueba.ec","jefezona-uio@industec.me"]
  PASA  correo  el PDF imprime el correo del local que escribió el técnico
  PASA  correo  el correo queda aprendido para la próxima orden de ese local
verificar_http.py 86 de 86 · verificar_bandeja.py 37 de 37
locales: 120·0, 57·0, 62·0, 42·0, 7·0; reglas 37/37 en JS y PHP
```

**Un hallazgo al limpiar, y es serio:** `limpiar_pruebas.php` seguía con una
lista fija de los dos avisos reales que el arnés viejo tomó (10355931,
10356012) y un paso que **borraba sus filas de `casos_gestion`**. Kevin Chimbo
los había asignado la noche anterior a técnicos reales (ajumbo, amorales). La
guarda del propio script abortó y no se perdió nada. Corregido: la limpieza
ya no toca ningún aviso que no empiece por `9999` y se detiene si una cuenta
de prueba tiene un caso real. Ejecutada después con cifras exactas: **0
cuentas de prueba, 0 avisos 9999, y los dos casos reales intactos** con
ajumbo y amorales. Es el error nº 43 del plan.

**Lo que NO se comprobó:** un celular Android de verdad (la prueba emula uno
en Edge; el `<datalist>` de Android no se puede reproducir ahí, y por eso se
reemplazó en vez de afinarlo); y una orden real con correo del local, porque
en el sitio de pruebas los correos quedan retenidos. `prueba_offline.mjs`
falla con `"[object Object]" is not valid JSON` **también en el commit
anterior** (comprobado en una copia aparte): es previo y ajeno a este cambio.
**Cómo lo verá el técnico:** al abrir la app, la primera recarga instala el
`sw.js` v16 y la segunda sirve el formulario nuevo.

### 1s-decies. El robot sano, el padrón al día, y tres pedidos de INDUSTEC en el formulario (2026-09-24, madrugada)

**El robot: de GRAVE a BIEN.** InspectorBot a las 05:02 → `salud: BIEN`, un
solo robot (PID 26220, lanzado por su Tarea programada), señal hace 2 min; solo
quedan dos alertas leves que decide la administración (5 casos con alerta, 2
sin local: V090, puerta D5).

- **El nocturno del 24 corrió completo con el código nuevo** (1.ª de las dos
  noches que pide T2.28.18b):
  `OK · 106.7 min · sync:ok(2605s) · volcado:ok · normalizar:ok · buzon:ok · ingesta:ok(3533s) · informes:ok · archivo:ok · pdfs:ok(80s)`
  — 10 PDF subidos, 0 fallidos, y las 4 fechas inválidas ya salen como `AVISO`.
- **T2.28.18a, medición real** (`logs/ssh_llamadas.csv`, 23-sep 19:54 → 24-sep
  09:30 UTC): **324 llamadas, 311 ok, 9 timeout, 4 código 1**. Los **9 timeouts
  fueron contra carpetas de PRODUCCIÓN** (`contadores`, `registros`, `uploads`
  de `yellow-elephant`), **ninguno contra el sitio de pruebas**, y las carpetas
  de auxiliares tienen de 1 a 4 archivos: no es tamaño, es la sesión que se
  cuelga. Responde con cifra la pregunta 3 de 18a («¿con un tipo de comando?»).
  Faltan las otras dos y la segunda noche.
- **Los 2 «errores» de InspectorBot no eran errores**: cortes de red de la
  estación de 21 s (sesión cortada y enseguida `getaddrinfo failed`), ya
  reconectados y barridos. `t2_9`: un intento fallido es `AVISO`; `ERROR` solo si
  lleva más de 5 min sin reconectar, y dice «reconectado tras N intentos».
- **Candado de instancia única** (`logs/vigilante.lock`, bloqueo del sistema,
  sin candado huérfano). Una instancia rechazada deja su nota en
  `logs/candado_vigilante.log` y **no** en `vigilante-*.log`. Si el candado no se
  puede abrir, el robot sigue y lo anota como ERROR.
- **Revisión adversarial con dos revisores independientes** (concurrencia en
  Windows y operación): los dos dijeron SEGURO, con 5 observaciones menores,
  **todas aplicadas**: el candado se toma antes de abrir el registro; `os.open`
  dentro del `try`; **latido** «escuchando (sin novedades…)» en cada renovación
  (sin él, InspectorBot daba GRAVE «callado» de noche con el robot sano); la
  puesta al día si el arranque falló; y el espejo saltado por el candado del
  nocturno ya no mueve el sello (`t2_4` imprime `SALTADO_POR_CANDADO`).
  InspectorBot ya no aconseja lanzar el `.bat` a mano.
- **`t2_4_sync_hostinger.py`, auxiliares**: `ls` a 60 s con reintento
  (idempotente) y tope de 15 min para todo el paso (esta noche fueron 40 min en
  8 cuelgues de 300 s). `t2_4_pruebas.py` estaba roto **antes** de este cambio
  (firma vieja de `inventario_remoto`, comprobado en una copia del commit
  anterior): arreglado, «Todas las pruebas pasan».
- Pruebas nuevas: `scripts/t2_9_pruebas.py` **13·0** (el vigilante no tenía
  ninguna).

**El padrón de técnicos del servidor estaba 18 días atrasado — bloqueaba a 9
técnicos reales.** `catalogos/tecnicos.json` era del 2026-09-06; el 08-sep
`t2_8` actualizó la tabla `tecnicos` de la estación con el listado nuevo (9 altas,
9 bajas), pero nadie regeneró ni subió el catálogo. Resultado: Anthony Morales,
Diego Sisalema, Fernando Tipán, Heitan Taco, Pablo Ortiz, Carlos Farfán, Luis
Erazo, Diego Cuenca y Adrián Barrozo —cuentas activas— **no estaban en el padrón
y la regla `TECNICO_NO_VIGENTE` (BLOQUEA) les rechazaba la orden**; y Kevin
Chimbo figuraba como técnico de CNLJ. Regenerado con `t2_5_catalogos.py`,
**cruzado antes de escribir contra las cuentas activas del servidor (I-10):
19 = 19, 0 diferencias, los 3 jefes coinciden**; respaldo del viejo en
`~/respaldos/tecnicos.json.antes_20260924` (sha `388fa40d…`) y subido con
sha256 verificado. Es el error nº 45 del plan.

**Los tres pedidos de INDUSTEC en el formulario, desplegados (`sw.js` v18):**

| Pedido | Qué quedó |
|---|---|
| Buscar el equipo escribiendo y, si no está, crearlo | Buscador sobre el `<select>` (que sigue siendo la fuente de verdad, oculto). Filtra por nombre, área, tipo y código; si lo escrito no calza con nada, ofrece **«+ Crear «…» como equipo nuevo»** y lo deja como equipo nuevo (va a `equipos_propuestos`, la administración lo aprueba) |
| Acompañantes: solo los empleados activos de la zona, con el jefe | La lista sale del padrón al día, **solo de la zona del local de la orden**, el **jefe de zona primero** y sin quien emite; se actualiza al cambiar de local |
| El jefe de zona también se asigna casos y los atiende | Asignarse ya podía. Ahora **migración 021** (`ots.crear` para `JEFE_ZONA`, volcado previo `volcado_20260924T095314Z.sql.gz`, sha `f8d3a75b…`), **«Mis órdenes»** en su menú con **sus** casos asignados y el botón «Emitir la orden de este caso» |

`verificar_esquema.php` → **TODO OK**. De paso se corrigió una **falla previa**:
la 020 (panel de Automatización) sumó `automatizacion.configurar` a
SUPERADMIN y ADMIN sin actualizar el verificador, que daba FALLA en esos dos
roles.

**Evidencia, literal, contra darkviolet:**
```
verificar_formulario.mjs  32 comprobaciones · 0 fallos
  PASA  H1 escribir filtra la lista del equipo    1 opciones · EQUIPO PRUEBA ARNES · … · propuesto
  PASA  H2 si no está, ofrece crearlo             aparece «+ Crear…»
  PASA  H2 crearlo lo deja como equipo nuevo      TIPO:TOSTADORA DE PRUEBA XYZ · «Equipo nuevo · TOSTADORA DE PRUEBA XYZ»
  PASA  I solo ofrece empleados de la zona de la orden (UIO)   ["UIO"]
  PASA  I son todos los de la zona, menos quien emite          7 de 7
  PASA  I el jefe de zona va primero              Kevin Omar Chimbo Amaguaña · JEFE TÉCNICO
verificar_http.py  89 de 89 (3 nuevas: el jefe tiene «Mis órdenes» y mis.php da 200; la administración no)
verificar_emision.py 38 de 38 · verificar_bandeja.py 37 de 37
locales: 120·0, 57·0, 62·0, 42·0, 7·0; reglas 37/37; t2_9_pruebas 13·0; t2_4_pruebas OK
limpiar_pruebas.php --ejecutar: 0 cuentas de prueba, 0 avisos 9999; 10355931 → ajumbo y 10356012 → amorales, intactos
```
La primera corrida de la batería H falló (el buscador borraba lo que se escribía
cuando ya había un equipo elegido); se corrigió, se subió `sw.js` a v18 por si
algún celular alcanzó a guardar la v17, y la segunda dio 32·0.

**Lo que NO se comprobó:** un jefe de zona **real** emitiendo una orden (la
cuenta de jefe de prueba no está en el padrón; se comprobó el permiso y la
pantalla, no la emisión entera); celulares Android reales; y la segunda noche
del nocturno.

---

### 1s-undecies. T2.28.2 · El módulo de correos, construido, desplegado y verificado — pendiente solo de la siembra con aprobación (2026-09-24)

La Fase 2 se relanzó: `sql/013_correos.sql` (el borrador que dejó el intento
detenido por error nº 44) se revisó línea por línea contra la especificación y
contra `Emision::correoLocal()` — coincidía exactamente, sin necesidad de
reescribirlo — y se le quitó el aviso «NO APLICAR».

**Construido:**
- `nucleo/Destinatarios.php` (nuevo): `resolver()` decide el «para» y el «cc»
  de cada orden a partir de `correo_destinatarios`, llamando a
  `Emision::correoLocal()` para el correo del local (no hay una segunda regla).
  Sin la 013 o con la tabla vacía, hace exactamente lo de antes (maestro +
  `config.php`), para que ninguna orden se quede sin destinatarios mientras se
  siembra. La lógica de filtrado (`resolverConFilas()`) está separada de la
  lectura a la base para poder probarla sin MySQL.
- `Emision::encolar()` ahora llama a `Destinatarios::resolver()` y guarda
  `para` y `cc` (columna nueva en `email_queue`, congelada al encolar).
  `Emision::html()` imprime el `JEFE_OPERACIONES` resuelto para ese local, o
  «sin configurar» si nadie lo cargó todavía (ya no confunde el buzón de zona
  de INDUSTEC con el contacto de KFC, que era el problema de fondo, D-G).
- `Catalogo::fusionarCorreos()`: superpone sobre el maestro el correo del
  local que la administración ya **aprobó** en `locales_correo_propuesto`.
- `nucleo/Despacho.php` (nuevo): separa del despachador las dos decisiones
  puras — el tope de 45 correos por hora y la clasificación del error del
  SMTP — para poder probarlas sin conectar a ningún SMTP.
  `despachar_correo_cli.php` las usa; además manda `addCC()` con las copias
  congeladas y trata «Sender Hourly Quota Exceeded» como temporal, sin que
  cuente para los 6 intentos ni se marque FALLIDO nunca por eso.
- `envio.php`: si el correo del local de una orden **nueva** es válido, no es
  `@industec.me` y es distinto del maestro, queda `PROPUESTO` en
  `locales_correo_propuesto` (try/catch, no interrumpe la orden si falta la 013).
- `correos.php` (nueva pantalla; permiso `correos.configurar`, CSRF + PRG como
  `equipos.php`): pestañas Por zona, Generales, Por local, Reportes
  automáticos, Propuestos (aprobar/rechazar) y Vista previa
  (`Destinatarios::resolver()` con el detalle de origen de cada dirección).
  Nada se borra, solo se activa o desactiva; el buzón del jefe de zona se
  puede editar pero nunca desactivar (400 si se intenta por POST). Cada
  cambio va a `correo_destinatarios_cambios` y a la bitácora. Enlazada desde
  el panel «Automatización», con el número de propuestas pendientes (decisión
  de Andrés del 2026-09-23).
- `correos_sembrar_cli.php` (nuevo, CLI): siembra el buzón del jefe de zona de
  cada zona, comprobado contra el maestro (I-10) antes de sembrar nada —
  **aborta si una sola zona no coincide**; simulacro libre, `--ejecutar`
  requiere aprobación.
- `verificar_esquema.php`: bloque «migracion 013»; `limpiar_pruebas.php`:
  retira las propuestas, destinatarios y cambios que dejaron las cuentas de
  prueba (`correo_destinatarios` va **antes** que `usuarios` en el orden de
  borrado: su `creado_por` es clave foránea).

**Verificado, con la migración ya aplicada en darkviolet:**

```
php verificar_esquema.php                     → TODO OK (bloque «migracion 013»,
                                                  SUPERADMIN 42 · ADMIN 41 · JEFE_ZONA 28 · TECNICO 14)
php correos_sembrar_cli.php (simulacro)        → coincide con el maestro: 3/3
verificar_http.py                              → 89 de 89
verificar_emision.py                           → 40 de 40, incluidas las 2 nuevas:
    "el jefe de zona de UIO va en copia, como JSON en email_queue.cc"  → ["jefezona-uio@industec.me"]
    "el correo del local queda PROPUESTO en locales_correo_propuesto" → estado=PROPUESTO
verificar_seguridad.py                         → 34 de 34, incluidas las 6 nuevas (sección 9):
    JEFE_ZONA y TECNICO → 403 por GET y por POST fabricado a correos.php
    ADMIN sin csrf → 403
    desactivar el buzón del jefe de zona por POST → 400
node verificar_formulario.mjs (sola)           → 32 de 32
locales: pruebas/prueba_48h.php 120·0 · prueba_contratos.mjs 57·0 · prueba_graficos.mjs 62·0 ·
         prueba_continuidad.php 42·0 · reglas.fixture.mjs/validacion_test.php/t2_5_validacion.py --fixture: 37/37 los tres
         prueba_destinatarios.php (nueva) 22·0 · prueba_despacho.php (nueva) 20·0
limpiar_pruebas.php --ejecutar: cifras exactas, incluida 1 fila de locales_correo_propuesto retirada
```

Volcado previo verificado por hash antes de migrar
(`D:\RESPALDOS\_ORIGEN_APP\_bd\volcado_20260924T152506Z.sql.gz`, sha256
`841e8cfb…`). Despliegue de código confirmado con «la web entrega exactamente
lo que se subió» (`correos.php`, `nucleo/Destinatarios.php`,
`nucleo/Despacho.php`, `nucleo/Emision.php`, `nucleo/Catalogo.php`,
`nucleo/Ui.php`, `envio.php`, `automatizacion.php`; `despachar_correo_cli.php`
y `correos_sembrar_cli.php` por scp, al no ir en `ARCHIVOS`).

**Andrés aprobó la siembra en el momento** (no era D1: T2.28.2 la marca aparte
en su propia tabla de permisos, sin cifra que perjudique un dato real). Al
correrla apareció un **bug real**: `correos_sembrar_cli.php` requería con
`__DIR__` en vez de `getcwd()` — exactamente el mismo patrón que el error
nº 42 (`archivo_verificar_cli.php`), porque el CLI se sube por scp a
`~/respaldos/` y corre desde `ot/`: `__DIR__` apunta a donde vive el archivo,
no a donde se ejecuta. `php -l` no lo detecta (es válido, solo la ruta en
tiempo de ejecución es la que no existe). Corregido, resubido, y sembrado:

```
$ php correos_sembrar_cli.php --ejecutar
  UIO    jefezona-uio@industec.me         maestro: jefezona-uio@industec.me         coincide
  LARB   jefetecniconacional@industec.me  maestro: jefetecniconacional@industec.me  coincide
  CNLJ   jefezonacuenca-loja@industec.me  maestro: jefezonacuenca-loja@industec.me  coincide
  coincide con el maestro: 3/3
  SEMBRADO: 3 fila(s) (3 jefes de zona).
  correo_destinatarios activos con rol JEFE_ZONA: 3 (esperado 3)
```
Verificado aparte por SQL directo: las 3 filas, `origen='PRODUCCION'`,
`activo=1`. **T2.28.2 queda 100 % en verde.** Es el error nº 47 del plan.

**Lo que NO se pudo comprobar:** el envío real por SMTP (el sitio de pruebas
nunca conecta) ni el tope de 45/hora o el «Sender Hourly Quota Exceeded»
contra un SMTP de verdad — sí sus dos reglas puras, con `prueba_despacho.php`.
Tampoco se activó ningún jefe de mantenimiento de KFC (producción los tiene
apagados a propósito; quedan solo como sugerencia en el simulacro de la siembra).

---

### 1s-duodecies. T2.28.3 (resto) · El correo del jefe de operaciones deja de ser un campo, «también se enviará a», y `CORREO_INVALIDO` (2026-09-24)

Lo que ya dejó el arreglo urgente (correo y administrador editables, ambos
usados en la emisión) no se repitió. Se construyó solo lo que faltaba:

- **`Destinatarios::filas()` pública y `Destinatarios::copiasPorLocal()`
  (nuevo)**: las COPIAS de una orden (jefe de zona + jefe de operaciones si
  ya está configurado + otras copias), para la línea del formulario. NO llama
  a `resolver()` a propósito: `resolver()` recalcula el «para» con
  `Emision::correoLocal()` → `localFila()`, que relee TODO el catálogo (4 JSON
  + 2 consultas) por cada llamada, y `catalogos.php` la necesita para los 100
  locales de una sola vez. `copiasPorLocal()` recibe `$filas` (una sola
  lectura de `correo_destinatarios`) y la fila del local que el propio
  llamador ya tiene cargada — cero consultas de más.
- **`catalogos.php`**: suma `destinatarios_cc` (por local, con `jefe_zona`
  marcado en cada dirección para poder armar la frase). **No se agregó
  `Catalogo::correosDelLocal()` ni `correos_locales`** (sí los pedía la
  especificación): `app.js` ya arma exactamente esa lista en el cliente, con
  `admins_v2` + `locales[].correo_local` — agregar el mismo dato por el
  servidor no mejoraba nada, solo duplicaba la regla (I-6, «no dupliques si
  no aporta», instrucción explícita de esta subtarea).
- **`index.html`**: fuera `#correojefeop`. En su lugar, una línea de solo
  lectura «Esta orden también se enviará a: …» con un botón «ver» que lista
  las direcciones (sin `.combo-lista`: es una lista fija, no un buscador).
- **`app.js`**: `actualizarTambienEnvio()` arma la frase desde
  `CAT.destinatarios_cc` (que ya viaja en la misma copia cacheada de
  `catalogos.php`, así que funciona sin señal sin código aparte);
  `reunirOrden()` suma `formulario_v: 2` (no bloquea nada; es la marca que
  T2.28.15 va a usar para cerrar la ventana de compatibilidad — §5.4).
- **`CORREO_INVALIDO`** (ADVIERTE, solo `CAPTURA`) en `reglas.js`,
  `Validacion.php`, `t2_5_validacion.py` y el fixture (4 casos nuevos:
  inválido en CAPTURA, válido, vacío, inválido en HISTÓRICO no se revisa).
- **`envio.php`**: el upsert de `locales_admin` **no llenaba
  `correo_veces`/`correo_visto`** aunque la 013 ya trajera esas columnas
  (hueco real de la migración anterior, no de esta subtarea) — corregido, y
  ahora anota `ADMIN_CORREO_CAMBIO` en la bitácora cuando el correo de un
  administrador ya conocido cambia (con el valor anterior, leído antes del
  upsert, en la misma transacción).
- `sw.js` → **v19**.

**Verificado:**

```
node reglas.fixture.mjs                        → 41/41 (reglas.js)
php pruebas/validacion_test.php                 → 41 casos (PHP)
t2_5_validacion.py --fixture                     → 41 casos (Python) -- los tres iguales
php pruebas/prueba_destinatarios.php            → 34·0 (12 nuevas: copiasPorLocal)
pruebas/prueba_48h.php 120·0 · prueba_contratos.mjs 57·0 · prueba_graficos.mjs 62·0 · prueba_continuidad.php 42·0 ·
prueba_despacho.php 20·0 · prueba_casos_prueba.php 7·0 (sin regresión)

Despliegue (t2_10_desplegar.py): 8/8 archivos, «la web entrega exactamente lo que se subió»
php verificar_esquema.php                        → TODO OK («migracion 013» sigue OK; ORDEN: 3 destinatarios activos)

verificar_http.py                                → 89·0
verificar_emision.py                             → 41·0, incluida la nueva:
    "correo_veces queda en 1 (primera orden del ciclo con ese correo)" → [{'correo': '...', 'correo_veces': 1}]
node verificar_formulario.mjs (sola)             → 39·0, incluidas las 7 nuevas:
    "G4 ya no existe el campo fijo #correojefeop"                          → sinCampoFijo:true
    "G4 la línea dice que también se enviará al jefe de zona"              → "jefe de zona de INDUSTEC"
    "G4 «ver» lista el correo del jefe de zona de UIO"                     → "jefezona-uio@industec.me"
    "sin senal, sigue sin existir el campo fijo #correojefeop"             → sinCampoFijo:true
    "sin senal, «también se enviará a» igual dice el jefe de zona"         → "jefe de zona de INDUSTEC"
    "sin senal, «ver» igual lista el correo del jefe de zona de UIO"       → "jefezona-uio@industec.me"
limpiar_pruebas.php --ejecutar: cifras exactas (simulacro corrido primero, sin sorpresas)
```

**Lo que NO se pudo comprobar:** el envío real de `ADMIN_CORREO_CAMBIO` a la
bitácora (necesita dos órdenes del mismo administrador con dos correos
distintos en el mismo ciclo de prueba; se revisó por lectura de código, no
por batería automática — el camino "primera vez" sí está probado, con
`correo_veces = 1`). Tampoco se probó con un jefe de zona real (sigue sin
cuenta en el padrón, igual que T2.28.2).

Siguiente en el carril: **T2.28.6** — ✅ terminada, ver §1s-terdecies abajo.

---

### 1s-terdecies. T2.28.6 · La ficha del equipo (marca, modelo, serie que se quedan), migrada, desplegada y verificada (2026-09-24)

**Punto de partida:** un intento anterior de esta misma subtarea se había
quedado sin cuota a medio camino, con casi todo el código ya escrito en disco
(`equipos.php`, `plantilla_ot.php`, `nucleo/Catalogo.php`, `Validacion.php`,
`Emision.php`, `Destinatarios.php`, `envio.php`, `catalogos.php`,
`verificar_esquema.php`, `index.html`, `app.js`, `reglas.js`, `offline.js`,
`sw.js`, `saneamiento_nocturno.py`, `t2_5_validacion.py`,
`fixture_validacion.json` con 49 casos, y `sql/014_ficha_equipo.sql` sin
trackear). Se leyó todo contra el documento y contra los criterios antes de
tocar nada (instrucción explícita): **estaba prácticamente completo y bien
hecho** — `esMarcador()`/`normMarca()` en las tres implementaciones, la
casilla «sin placa», el prellenado desde la ficha con su aviso, la búsqueda de
equipo extendida por marca/modelo (`buscarEn` en `crearCombo`, sin segundo
buscador), el upsert de `envio.php` con `COALESCE` (nunca pisa un dato real
con un vacío o un marcador) y el historial en `equipos_ficha_cambios`, la
bitácora `EQUIPO_SERIE_CAMBIO`, la sección «Series que cambiaron (90 días)»
en `equipos.php`, el «sin placa o ilegible» del PDF, y el paso `equipos` del
saneamiento nocturno. **Lo único que faltaba de verdad:** los dos scripts de
la estación (`t2_28_marcas.py`, `t2_28_exportar_equipos.py`) y todo el tramo
de migración/despliegue/baterías.

**Lo que se construyó en esta vuelta:**
- `t2_28_marcas.py`: combina `ot_equipos` (base local, 9.702 filas) e
  `Inventario 2023.xlsx` (57.451 filas) con la MISMA `es_marcador`/`norm_marca`
  que valida el formulario (importadas de `t2_5_validacion.py`, no copiadas).
  Filtra además candidatos sin ninguna letra (`----` y similares no son una
  marca bajo ninguna lectura; hallazgo propio antes de correrlo la primera
  vez, sin tocar la lista de marcadores compartida). Salida:
  `catalogos/marcas.json` (455 marcas con frecuencia ≥ 3), `catalogos/modelos.json`
  (3.083 modelos, hasta 60 por marca) y el Excel
  `MARCAS POR UNIFICAR (generado agente).xlsx` para que Andrés o César decidan
  los sinónimos. `--subir` los sube por scp a `catalogos/` y verifica el
  sha256 remoto.
- `t2_28_exportar_equipos.py`: lee `equipos_ficha`, `equipos_ficha_cambios` y
  `equipos_propuestos` del servidor por `sql_remoto` (solo `SELECT`), los
  enriquece con `locales.json`/`equipos_por_local.json` de la estación, y
  escribe `MAESTRO DE EQUIPOS (generado agente).xlsx` (hojas «Equipos» y
  «Cambios de serie»). Aborta con mensaje claro si el Excel está abierto (I-4).
  Es el paso `equipos` que el saneamiento nocturno ya invocaba desde la vuelta
  anterior.
- **Bug real encontrado corriendo el simulacro, no la puerta** (mandato
  explícito de esta tarea): `verificar_emision.py`, `verificar_http.py`,
  `verificar_bandeja.py`, `verificar_ciclo.py`, `verificar_continuidad.py` y
  `prueba_cola_vivo.mjs` arman su orden de prueba con `equipos[0]` del local
  **a ciegas** — antes de esta subtarea eso solo afectaba texto superficial
  del PDF, pero ahora `envio.php` escribe una ficha por cada equipo de
  cualquier orden, y `equipos[0]` podía ser un activo SAP **real** del local
  (confirmado: la primera corrida dejó fichas en `30004677`/G007EC con
  `marca='MARCAPRUEBA'` y en `30004735`/G018EC con todo `NULL` — dos equipos
  reales de KFC). Corregido en las seis pruebas: prefieren el equipo
  `PROPUESTO` que `preparar_prueba.php` ya siembra para el aviso sintético
  (uuid `99990000-…`), y solo caen al primero de la lista si no hay ninguno
  propuesto. Verificado después del arreglo: `equipos_ficha` con exactamente
  3 filas, las tres `PROPUESTO:99990000-…` — cero equipos reales tocados.
- `verificar_emision.py` suma el gesto completo de T2.28.6 (7 comprobaciones
  nuevas, con su propio equipo `PROPUESTO:99990000-…-0f1` para no interferir
  con el resto de la batería): sin marca/modelo/sin-placa → 400 con
  `EQUIPO_SIN_DATOS_DE_PLACA`; con marca/modelo/serie → emitida y
  `equipos_ficha` las guarda; `catalogos.php` prellena esa ficha; un segundo
  envío con otra serie deja 1 fila en `equipos_ficha_cambios` y la bitácora
  `EQUIPO_SERIE_CAMBIO`.
- `verificar_formulario.mjs`: sin marcar la casilla «sin placa» del equipo
  propuesto, la orden síntetica del arnés quedaba bloqueada por
  `EQUIPO_SIN_DATOS_DE_PLACA` (4 fallos, detectados en la primera corrida
  después de desplegar) — corregido marcando `[data-eq-sinplaca]` antes de
  enviar, como haría el técnico si no puede leer la placa.

**Migración `014_ficha_equipo.sql`** (revisada contra el documento antes de
aplicarla: coincidía exacto con el borrador que ya estaba en disco) aplicada
en darkviolet con volcado previo (`t2_4_volcado_bd.py`,
`volcado_20260924T200601Z.sql.gz`, sha256 `63bcb1d6…`):

```
014_ficha_equipo.sql: 2 sentencias
  1. CREATE ok
  2. CREATE ok
anotada en migraciones
aplicado
```

**Despliegue** (`t2_10_desplegar.py`, 14 archivos — todo lo tocado por esta
subtarea más lo que ya venía de T2.28.3): `14 de 14 archivos en el sitio de
pruebas · la web entrega exactamente lo que se subió`. `marcas.json` y
`modelos.json` subidos aparte por `t2_28_marcas.py --subir`, sha256 verificado
en los dos.

`php verificar_esquema.php` → **TODO OK**, con el bloque nuevo:

```
migracion 014
  tabla equipos_ficha                            si                             OK
  tabla equipos_ficha_cambios                    si                             OK
  equipos_ficha: PK equipo_clave (I-9)           equipo_clave                   OK
  equipos_ficha.marca / .modelo / .serie / .sin_placa / .fuente / .actualizado_por   si   OK (×6)
  equipos_ficha_cambios.campo / .antes / .despues                                    si   OK (×3)
```

**Baterías de servidor**, ciclo limpio y único (`preparar_prueba.php` → las
tres → `limpiar_pruebas.php`, sin repetir `preparar_prueba.php` a medio
camino — la primera vuelta sí lo hizo por iterar sobre el bug de arriba, y
dejó un pendiente de CNLJ en un estado que dos comprobaciones de T2.12.4/9/5
no reconocían; se limpió con `limpiar_pruebas.php --ejecutar` y se repitió
todo desde cero):

```
verificar_http.py         → 88 de 89 (1 fallo: T2.12.4 "admin_prueba: pendientes.php
                              muestra el pendiente de CNLJ" — ver «no comprobado» abajo)
verificar_emision.py      → 48 de 48, con las 7 nuevas de T2.28.6:
    "sin marca, sin modelo y sin 'sin placa' -> 400, EQUIPO_SIN_DATOS_DE_PLACA"
    "con marca, modelo y serie -> emitida"
    "equipos_ficha guarda esa marca, modelo y serie"                      → MANITOWOC/IYT0500A/SN-PRUEBA-1
    "catalogos.php prellena la ficha para el próximo formulario"          → ídem
    "un segundo envío con otra serie -> emitida"
    "equipos_ficha_cambios deja 1 fila: SN-PRUEBA-1 -> SN-PRUEBA-2"
    "y queda en la bitácora como posible reemplazo del equipo"            → 1
node verificar_formulario.mjs (sola)  → 39 de 39
limpiar_pruebas.php --ejecutar: cifras exactas del simulacro (sin sorpresas)
   → "archivos borrados del disco: 6 de 6 · padrón de técnicos: 21 → 19 · casos_prueba.json borrado: sí"
SELECT COUNT(*) FROM equipos_ficha / equipos_ficha_cambios  → 0 / 0 (nada quedó, ni real ni de prueba)
```

Locales, en el mismo ciclo: `prueba_48h.php` 120·0 · `prueba_contratos.mjs`
57·0 · `prueba_graficos.mjs` 62·0 · `prueba_continuidad.php` 42·0 ·
`reglas.fixture.mjs`/`validacion_test.php`/`t2_5_validacion.py --fixture` →
49/49 los tres iguales.

**Lo que NO se pudo comprobar:** el único fallo de `verificar_http.py`
(T2.12.4, «admin_prueba: pendientes.php muestra el pendiente de CNLJ») es
anterior a esta subtarea y no tiene relación con equipos/marca/modelo — el
propio caso que revisa (aviso sintético `99990001`, fijo, no uno de los
`9999002x` de T2.28.6) ni pasa por `envio.php`. No se investigó a fondo
(fuera de alcance de T2.28.6): es candidato a revisarse aparte, quizás
paginación o un backlog de pendientes `CNLJ` de prueba que ya lleva muchas
corridas acumuladas. Tampoco se corrió `verificar_ciclo.py`/
`verificar_bandeja.py`/`verificar_continuidad.py`/`prueba_cola_vivo.mjs` en
esta vuelta (no los pide la compuerta de T2.28.6) — se les aplicó el mismo
arreglo del equipo `PROPUESTO` por prevención (comparten el mismo patrón
`equipos[0]` y la próxima subtarea que los corra habría tropezado con el
mismo bug), pero **sin volver a ejecutarlos**: queda para quien los corra la
próxima vez confirmar que el arreglo no rompió nada suyo.

Siguiente en el carril: **T2.28.7** (fotos del antes y del después, por
equipo).

---

## 1r. T2.27 · Los reportes que KFC le pide a la administración, generados, y el tablero de gerencia (2026-09-23)

Andrés pidió armar los reportes «tal cual se los pide KFC», revisando el correo
de la administradora, y sumar reportes para monitorear el negocio. Se leyó
`servicioalcliente@industec.me` (2.130 enviados, 8.829 recibidos) **en solo
lectura** (`EXAMINE` + `BODY.PEEK`: ningún mensaje cambió de estado) y el espejo
del Drive. Plan, subtareas y permisos en **T2.27** del plan.

**Lo que pide KFC, del correo:**

| Cuándo | Qué | Quién |
|---|---|---|
| Lunes | Export de SAP de todo el año, ~44.000 filas, 16 MB | Erika Zambrano (KFC) a los 4 proveedores |
| Martes | `STATUS_PENDIENTES_SEMANA N MES` (lo mira por los equipos parados) | Isabel → Lincango, Vásquez, Valero |
| **Miércoles 13h00** | El export devuelto con la hoja RESUMEN y el ESTATUS IND | Isabel → Erika Zambrano |
| Jueves | Reunión semanal y CONTACT REPORT, con un número por proveedor | KFC |
| Por vuelta | Pedido de kits de preventivo | Isabel → Edgar Armero (bodega) |
| Periódica | Presentación de gestión por zona | Reuniones de indicadores |

**Lo construido, y con qué se verificó:**

| Generador | Verificación | Resultado |
|---|---|---|
| `t2_27_status_semanal.py` (martes) | Semana 4 desde la 3 y semana 3 desde la 2; celda a celda por aviso contra lo que ella mandó | Objetivas **95,3 %** y **96,1 %** (criterio ≥ 95 %). Excel abre sin reparar; su recálculo = los **17** valores guardados |
| `t2_27_respuesta_kfc.py` (miércoles) | Semana 38 contra su respuesta | RESUMEN **65 de 65** avisos; ESTATUS IND **36 de 65** (55 %, es propuesta); las **5** tablas dinámicas de KFC intactas |
| `t2_27_tablero_gerencia.py` | El indicador de KFC contra lo que KFC publicó; la serie se arma sola bajando del correo sus Excel de las últimas 5 semanas (11 archivos) | **7 de 7** exactas (la semana 34, 29,55 %, no tiene cifra publicada con qué compararla): sem. 35 INDUSTEC 18,87 · IN HOUSE 11,11 · Megaservicios 58,46 · Servicenturiosa 40,71 · ND 60,42; sem. 37 INDUSTEC 47,62 · IN HOUSE 93,43 · Megaservicios 56,00 |
| `t2_27_presentacion_gestion.py` | Órdenes por zona y mes contra SQL directo; se abrió en PowerPoint | Iguales (UIO 163/177, LARB 242/234, CNLJ 276/295); **26** diapositivas |
| `t2_27_kits_preventivo.py` | Contra el pedido real de UIO del 14-sep | Los **5** locales pedidos están en la propuesta |

**El indicador de KFC, reconstruido.** No estaba escrito en ningún lado: de los
correctivos abiertos del lunes (por el proveedor del lunes), cuántos avisos del
lunes siguen abiertos el viernes (por el proveedor del viernes). Un ND que KFC
reasigna a INDUSTEC a mitad de semana le cuenta en contra. Con eso, **semana 39:
INDUSTEC tiene 41 abiertos en SAP y 10 ya los cerró con su orden: cerrarlos en SAP
(IW22) da 24,4 % sin una sola visita.**

**Defectos del trabajo a mano que el generador no repite:** las fórmulas del
RESUMEN de los martes tenían rangos fijos y la semana 4 le dijo a KFC «Cuenca–Loja:
9 órdenes» cuando eran 21. El subtítulo de la semana no se actualizaba, y la
presentación de oct–nov 2025 traía un local que no existe («K131»).

**Evidencia** (salida literal, recortada a las líneas del criterio):

```
$ python scripts/t2_27_status_semanal.py --fecha 2026-09-22 --anterior <S3> --sap <SEMANA 39> --comparar <S4 real>
  OBJETIVAS (A–H, L), en ambos: 326 de 342 idénticas (95.3 %).
  Criterio ≥95 % de celdas objetivas idénticas: CUMPLE
$ (Excel por COM) Valores guardados : 53,9,16,28,12,41,9,7,2,16,13,3,28,21,7,53,82
                  Recalculado Excel : 53,9,16,28,12,41,9,7,2,16,13,3,28,21,7,53,82   Iguales: True
$ python scripts/t2_27_respuesta_kfc.py --sap <SEMANA 38> ... --comparar <su respuesta>
  Avisos: generado 65 · real 65 · en ambos 65 / Idénticos: 36 de 65 (55.4 %)
$ (Excel por COM) FILTRO(0 TD), TD(5 TD), #O_ND(0 TD), RESUMEN, RESUMEN IND, ND INDUSTEC
$ python scripts/t2_27_tablero_gerencia.py ...
  semana 35: abiertos lunes INDUSTEC 53 · indicador KFC INDUSTEC 18.87 %
  semana 37: abiertos lunes INDUSTEC 42 · indicador KFC INDUSTEC 47.62 %
$ cmd /c scripts\reportes_kfc.bat miercoles          (modo real, bajando del correo)
  41 correctivos de INDUSTEC abiertos o en tratamiento · ND de INDUSTEC: 5
```

**Hallazgos, medidos y sin tocar nada:**
- **El cronograma de preventivos no está reprogramado.** Ninguno de los 368 ingresos
  cambió de fecha, y la ejecución va 2 a 3 semanas detrás. K062 y V074 figuran
  CUMPLIDOS, pero se volvió a pedir kit para ellos. R006 figura con el ingreso 1
  pendiente desde enero. Por eso el pedido de kits es propuesta y no pedido.
- **Las «cerradas de la semana» del martes no salen de ninguna fuente.** Se probaron
  las órdenes de cierre y el cierre técnico de SAP, en dos ventanas cada uno. El
  generador usa una definición explícita (órdenes de cierre del martes al lunes) y
  deja imponer otra con `--cerradas`.
- **KFC cambia columnas entre semanas.** Las semanas 32, 34 y 36 no traen «ESTATUS B»
  ni «ÁREA», y el ACTUALIZADO llama «Notificación» al aviso. El lector exige solo 6
  columnas y acepta sinónimos.

**Lo que NO se pudo comprobar:**
- Que la administradora use los archivos generados, y cuánto tiempo le ahorran.
  Nadie los ha abierto todavía fuera de esta conversación.
- El ESTATUS IND de la semana 39: todavía no hay respuesta suya con qué compararlo.
- La facturación semanal (T2.27.6): no hay fuente de valores monetarios.
- Nada de esto corre solo todavía: las tareas programadas están construidas e **INACTIVAS** en el panel «Automatización» (§1r-bis) y se activan con la aprobación de la administradora.

### 1s-quaterdecies. Fusión de `estacion/wip-2026-09-29` sobre el piloto del PC — 8 conflictos resueltos a mano (2026-09-29)

**Punto de partida.** El PC había construido y desplegado en darkviolet, el
28-sep, la OT del piloto que no cierra (§1w), el PDF sin franja (§1x/§1y), el
panel de estados con la migración 022 (§1u de más abajo) y el buzón
simplificado (§1v) — todo fusionado en `origin/master` como `b4886ca`. La
estación tenía, sin commitear, T2.28.3/T2.28.6 (ya desplegadas antes del
28-sep, así que no chocaban) y T2.28.7 completa en código pero **nunca
desplegada ni migrada**. Instrucción explícita de Andrés: resguardar todo en
una rama antes de tocar nada, fusionar `b4886ca` con fast-forward, empujarlo,
y solo entonces traer el resguardo encima — sin `git stash pop` ni `reset`, y
sin desplegar hasta comprobar el servidor.

**Lo que se hizo:**
1. `estacion/wip-2026-09-29` (commit `f56804b`): todo el trabajo sin commitear,
   a salvo.
2. `git merge --ff-only origin/pc/ot-piloto-no-cierra-2026-09-28`: `bdaaf92` →
   `b4886ca`, sin conflicto (130 archivos). Empujado a `origin/master`.
3. `git merge estacion/wip-2026-09-29`: **8 conflictos reales** (no eran
   choques de intención, sino inserciones adyacentes de ambos lados en el
   mismo bloque): `app.js`, `catalogos.php`, `index.html`, `nucleo/Emision.php`,
   `nucleo/Validacion.php`, `reglas.js`, `sw.js`, `verificar_esquema.php`.
   Resueltos a mano, uno por uno, verificando antes de decidir — por ejemplo:
   `index.html` traía el picker único «Evidencia fotográfica» (PC, vivo) y mi
   rama lo había retirado; antes de aceptar el retiro se comprobó que `app.js`
   ya construye el picker por equipo dentro de `bloqueEquipo()`
   (`inicializarFotosEq`) y que `cola.js`/`foto.php` ya hablan
   `equipo_n`/`momento` de punta a punta (ninguno de los tres en conflicto,
   fusionados solos) — sin esa comprobación, retirar el picker viejo habría
   dejado a los técnicos sin ninguna forma de fotografiar. `nucleo/Emision.php`
   quedó con `fechaAtencion()`/`fechaEmision()`/`CORRECCION` (seguridad del
   piloto, PC) seguidas de `fotoReducidaParaPdf()` (T2.28.7, mía, ya usada más
   arriba en `html()`). Los mensajes con «orden» a secas se unificaron al
   término del vocabulario, «OT INDUSTEC» (criterio: la terminología unificada
   es la más nueva y ya vivía en el resto del archivo). `sw.js` sube a **v26**.
   Commit `ffe0e76`.

**Verificado tras la fusión:**
```
grep -rln conflicto-residual (<<<<<<<, =======, >>>>>>>) en desarrollo/sistema_ots/: sin resultados
node --check app.js reglas.js sw.js: los tres OK
balance de llaves { } por profundidad (awk), Emision.php/Validacion.php/catalogos.php/verificar_esquema.php: los cuatro en 0
curl https://darkviolet-armadillo-872352.hostingersite.com/ot/sw.js → const VERSION = 'ot-industec-v25'
  (coincide exacto con lo que ESTADO.md §1y documenta como el último desplegado: nada vivo que esta base no contemple)
```

**Lo que NO se pudo comprobar — bloqueado por el clasificador de modo
automático, dos veces, ambas por tocar infraestructura compartida:**
- `git push origin master`: el commit `ffe0e76` **sigue solo en la
  estación**, no en GitHub. El PC no lo verá hasta que alguien con permiso
  corra el push.
- La comprobación por SSH de si la migración 014 (`equipos_ficha`,
  T2.28.6) sigue intacta en darkviolet tras los despliegues del piloto del
  28-sep (el patrón exacto del error nº 48: un despliegue posterior desde
  otra base puede pisar tablas o archivos sin que nadie lo note). No hay PHP
  instalado en la estación para correr `verificar_esquema.php` en local, así
  que esta comprobación **solo se puede hacer contra el servidor**.
- No se desplegó nada (T2.28.7 sigue sin migrar y sin subir), tal como pidió
  Andrés: «no despliegues nada hasta comprobar el servidor».

**Siguiente acción, de Andrés:** aprobar el `git push` y correr o autorizar la
comprobación por SSH de la migración 014 antes de que esta conversación (o
cualquier otra) retome T2.28.7 o despliegue algo nuevo.

**Cerrado el mismo día (2026-09-29), con la aprobación de Andrés** («avanza
con lo pendiente... despliégalo en la página»): `git push` empujado
(`de68901`) y comprobación por SSH de la migración 014 — **14 fichas de
equipo y 23 cambios de serie intactos**, nada vivo sin contemplar (detalle
en §1s-quindecies).

### 1s-quindecies. T2.28.7 · Fotos del antes y del después, por equipo — MIGRADA, DESPLEGADA y verificada en darkviolet (2026-09-29)

**Migración `015_fotos_por_equipo.sql`** (idempotente, `ADD COLUMN IF NOT
EXISTS` × 3 + índice) aplicada con `aplicar_sql.php`, tras el volcado de
respaldo verificado por hash (`volcado_20260929T174107Z.sql.gz`,
`f221cb0b…`). `verificar_esquema.php` en el servidor: bloque «migracion 015»
**TODO OK**, y el resto del esquema (007 a 022) sigue en verde con los
mismos permisos por rol que documenta la fusión (SUPERADMIN 43, ADMIN 42,
JEFE_ZONA 29, TECNICO 14).

**Desplegado:** `app.js`, `index.html`, `cola.js`, `foto.php`,
`nucleo/Emision.php`, `nucleo/Validacion.php`, `nucleo/plantilla_ot.php`,
`reglas.js`, `sw.js` (**v26**), `verificar_esquema.php` — verificado con
`t2_10_desplegar.py`: «la web entrega exactamente lo que se subió» en los
dos despliegues (el primero de 10 archivos olvidó `plantilla_ot.php`, ver
error nº 52 del plan; el segundo lo corrigió).

**Un bug real encontrado y corregido antes de dar la tarea por hecha:**
`plantilla_ot.php` cambió en la fusión (sin conflicto, se fusionó solo) pero
no estaba en la primera lista de archivos a subir. Síntoma: el PDF de una
orden con fotos por equipo salía sin la sección «EVIDENCIA FOTOGRÁFICA POR
EQUIPO», con `Emision::html()` construyendo `fotos_por_equipo` perfectamente
(confirmado con trazas temporales dentro del propio método, luego
retiradas) — la plantilla que lo imprime era la de antes de T2.28.7.
Encontrado comparando sha256 del archivo local contra el del servidor,
archivo por archivo. Detalle completo en el error nº 52 del plan.

**Verificación (contra darkviolet, arnés recién preparado cada vez):**

```
verificar_esquema.php: TODO OK (bloque «migracion 015»: ot_fotos.equipo_n/momento/tomada_en, índice idx_foto_equipo, todo "si")
verificar_emision.py: 68 de 68 comprobaciones pasan; 0 fallan
  -- incluye los tres criterios exactos de la especificación (T2.28.7 §): 2 equipos x 1 foto de cada momento -> PDF con
     "Antes" x4 y "Después" x4 (pypdf); 6.a foto de un equipo -> 400 "máximo"; 7 equipos x 5 fotos (35) emitida en 1.8 s
     y sin error nuevo en el log de la web (criterio: <30 s)
verificar_bandeja.py: 37 de 37 comprobaciones pasan; 0 fallan
verificar_formulario.mjs (navegador real, Chrome DevTools Protocol): 39 comprobaciones, 0 fallos, 0 excepciones JS
```

**Tres aserciones de prueba corregidas** (commit `bc98263`), las tres por
texto que el vocabulario del piloto cambió a propósito el 28-sep, no por
ningún defecto: el recibo ya no dice «el correo no salió (sistema en
pruebas)» sino «es del piloto: NO llegó a Grupo KFC ni al local»; el
historial dice «Aviso SAP N», no «Aviso N»; y la ficha de T2.28.6 necesita
declarar `fotos_antes`/`fotos_despues` para no chocar con la regla nueva
`FOTOS_ANTES_DESPUES` que **si** debe bloquear ese caso.

**Lo que NO se pudo comprobar / quedó pendiente, ajeno a esta tarea:**
`verificar_http.py` sigue con 1 falla ya conocida (T2.12.4, arrastrada desde
T2.28.6, sin relación con equipos ni fotos). `verificar_ciclo.py` y
`verificar_continuidad.py` fallan 10 comprobaciones por el apagado
intencional de `resolverPorOrden()`/la cadena completa de avisos para
órdenes del piloto (decisión de Andrés del 28-sep) — las pruebas no se
actualizaron cuando se tomó esa decisión; **ninguna de las 10 la causó
T2.28.7**. `prueba_cola_vivo.mjs` (navegador) también encontró una falla
ajena: toma `avisos.datos[0]` a ciegas y el sintético sin local (99990011)
ahora ordena antes que los que sí tienen local. Las tres cosas quedaron
como error nº 53 del plan, con la pregunta pendiente para Andrés: ¿se
actualizan esas baterías al nuevo comportamiento del piloto, o se corren
solo contra `emision_modo=PRODUCCION`? Migración 022 (repuesto/marcas,
del PC) no se tocó ni se auditó: fuera del alcance de esta tarea.

**No se desplegó nada a producción** (`yellow-elephant`): todo lo de arriba
es exclusivamente el sitio de pruebas (`darkviolet-armadillo-872352`).

### 1r-bis. T2.27.7 · El panel «Automatización», con las cinco tareas INACTIVAS (2026-09-23)

Andrés pidió construir las tareas programadas **dentro del panel donde va a ir la
configuración de correos**, y **no activarlas**: se activan luego con la
aprobación de la administradora. Quedó así:

| Pieza | Dónde | Qué hace |
|---|---|---|
| `automatizacion.php` | darkviolet, menú «Automatización» (solo SUPERADMIN y ADMIN) | Horario, modo y destinatarios de cada reporte; **Activar** pide la nota de quién aprobó; historial de cambios; últimas corridas. Sección «Correos de las órdenes» reservada para T2.28.2 |
| Migración `020_automatizacion.sql` | darkviolet | 4 tablas nuevas (aditiva), el permiso y las 5 tareas **inactivas** con 23 destinatarios tomados de los hilos reales. La 013–019 siguen reservadas para T2.28 |
| `automatizacion_cli.php` | darkviolet, solo por línea de órdenes (404 por web) | Lo único que la estación puede hacer: leer la configuración y registrar corridas. Rechaza una corrida «de horario» de una tarea inactiva |
| `t2_27_programador.py` | estación | Corre lo ACTIVO en su horario, avisa o envía según el modo y registra. **No está registrado en Windows**: `--instalar-tarea` imprime el comando para cuando se active |

**Evidencia** (respaldo previo de la base: `D:\RESPALDOS\_ORIGEN_APP\_bd\volcado_20260923T154323Z.sql.gz`,
sha256 `6b01035588eeb3c0…`; la 020 aplicada dos veces sin duplicar nada):

```
$ PYTHONUTF8=1 python verificar_automatizacion.py
ok  020: las cinco INACTIVAS                                                   [0, 0, 0, 0, 0]
ok  020: 23 destinatarios sembrados del correo enviado                         23
ok  cli registrar: rechaza una corrida de horario de una tarea INACTIVA        {"error": "la tarea está inactiva: ..."}
ok  activar sin nota: se rechaza
ok  activar con la nota de quién aprobó: se activa                             (dentro de una transacción revertida)
ok  con la tarea activa no se cambia el modo
ok  la transacción se revirtió: sigue INACTIVA                                 0
ok  ni una corrida ni un cambio quedaron guardados                             {corridas: 0, cambios: 0} → {0, 0}
ok  JEFE_ZONA: no entra (403) / TECNICO: no entra (403)
ok  programador sin tareas activas: no corre nada                              "Tareas activas en el panel: 0 de 5."
27 comprobaciones · 0 fallos
```

Además: en modo ENVIAR al cliente solo va el reporte (nunca la REVISIÓN ni el texto del
correo) y con su nombre de siempre, sin «(generado agente)»; y sin `SMTP_*` en
`config/.env` —hoy no están— no puede salir ningún correo. Baterías locales 120·0 y 57·0.

**Lo que NO se comprobó:** la pantalla no se abrió en un navegador (se dibujó por
línea de órdenes como la administradora y se revisó el HTML); el envío real por SMTP
nunca se probó, a propósito; y el programador no corrió ninguna tarea de verdad.
Para activarla: T2.27 del plan, «Para activar».

## 1q. Las cifras del inicio y de Reportes, contra los datos reales (2026-09-22, a pedido de Andrés)

Andrés pidió revisar que las estadísticas del inicio y de Reportes reportaran
información real, y que los «sin atender» ya regularizados dejaran de verse como
alarma. Se midió cada cifra contra darkviolet antes de tocar nada. **Tres decían
otra cosa**, y una cuarta estaba mal rotulada:

| Cifra | Antes | Ahora | Por qué estaba mal |
|---|---|---|---|
| «sin atender» en el gráfico de estados | **644 en rojo**, la barra más grande | **644 «regularizado» en gris**; «sin atender» = **0** | Los 644 del buzón de 90 días estaban **todos** regularizados (§1h) |
| «Se concluye en una visita» | **100 %** (110 de 110) | **80 %** (24 de 30 con orden de cierre) | Salía de `pendientes`, que tenía 4 filas y todas de prueba. **80** casos tienen visita con la orden **abierta** y ahora se muestran aparte |
| «Vivos en 90 días» (inicio) | **884** | «Siguen abiertos»: **118** | Contaba toda la ventana, incluidos 766 cerrados o regularizados |
| «Llegaron esta semana» / «Comprometidos hoy» | 8 días / con cerrados | 7 días / solo abiertos | `-7 days` con `>=` son ocho días |

**Cómo quedó.** `Ui::estadoVista()` devuelve `REGULARIZADO` para un
`CERRADO_SIN_ATENCION` con `regularizado_en`: gris, con borde punteado, en los
gráficos del inicio y de Reportes, en el distintivo del buzón y en la ficha del
técnico. **La base no cambia.** El filtro `casos.php?est=CERRADO_SIN_ATENCION`
—al que manda el enlace «sin regularizar» del inicio— lista solo los que faltan;
`?est=REGULARIZADO`, los ya explicados. «En una visita» se mide ahora con las
órdenes del caso (`Reportes::unaVisita()`): cuenta solo lo que tiene orden
«Cerrada», y una visita = todas sus órdenes del **mismo día** (se emite una orden
por equipo: R001EC tiene dos del 18-sep que fueron una sola visita). Por técnico,
igual, y «el mejor técnico» exige 5 casos cerrados. Excel, PDF y PowerPoint
llevan las mismas cifras. `sw.js` → **v15** (estilo.css va en la precarga).

**Evidencia** (desplegado en darkviolet; batería nueva, solo lectura):

```
$ cd desarrollo/sistema_ots/app/pruebas/servidor && PYTHONUTF8=1 python verificar_cifras.py
referencia: {"CSA_SIN": 0, "REG": 644, "ABIERTOS": 118, "N": 884, "concluidos": 30, "una": 24, "en_curso": 80}
ok  reportes: regularizados aparte, con su cifra                       644
ok  reportes: % en una visita = referencia                             80
ok  panel: «Siguen abiertos» = SQL                                     "118"
ok  panel: barra «regularizado» con la cifra y en gris                 {"e": "regularizado", "v": 644, "c": "#64748b"}
ok  casos.php: los regularizados salen en gris y ninguno en rojo       "644 gris · 0 rojo"
21 comprobaciones · 0 fallos
```

Locales: `prueba_48h.php` **120·0**, `prueba_contratos.mjs` **57·0**,
`prueba_graficos.mjs` **62·0**. Antes de subir se comprobó que el servidor tenía
exactamente lo de `HEAD` en los once archivos (solo difería el fin de línea).

**Hallazgos que quedaron medidos y NO se tocaron:**

- **17 de los 85 preventivos «vencidos»** tienen una orden preventiva archivada
  dentro de su ventana y sin enlazar a ningún ingreso (p. ej. R001EC ingreso 2,
  plan 16–18 sep, `OT-0222/0223-R001EC-…-D2` del 18-sep). El cálculo es correcto
  sobre la tabla; la tabla está atrasada. Es de T2.24, y enlazarlos escribe en la base.
- **74 casos `ASIGNADO` con orden abierta desde jun–jul** (técnico tomado del
  informe, `asignado_en` NULL). Son trabajo real sin orden de cierre; el inicio no
  los muestra como tarea.
- `verificar_http.py` buscaba la llave de la estación en `desarrollo/desarrollo/`
  (`parents[3]` en vez de `parents[4]`): corregido.

**Lo que NO se comprobó:** `reportes.php` no se dibujó en la verificación porque
anota una consulta en la bitácora a nombre de la administradora; se verificó
`Reportes::calcular()`, que es lo que la pantalla dibuja. Tampoco se abrió en un
navegador ni en un celular.

## 1p. Sitio de pruebas sin datos ni usuarios de prueba (2026-09-22, a pedido de Andrés)

Andrés pidió «dejar solo la información real», cuentas de prueba incluidas.
Respaldo previo: `D:\RESPALDOS\_ORIGEN_APP\_bd\volcado_20260923T003724Z.sql.gz`
(sha256 `a4ed5e56ec90…`, verificado local contra remoto por `t2_4_volcado_bd.py`).
Script nuevo: `app/pruebas/servidor/limpiar_pruebas.php` (simulacro por defecto;
`--ejecutar='<cifras>'` aborta si una sola cambió; todo en una transacción).

| Retirado de darkviolet | Filas |
|---|---|
| Cuentas `tec_prueba_uio_a/b`, `jefe_prueba_uio`, `jefe_prueba_cnlj`, `admin_prueba` | **5 borradas** (no desactivadas) |
| Órdenes OT-9073…OT-9112, sus correos retenidos y su entrada en el Archivo | 40 · 40 · 10 |
| PDF y fotos en disco | 49 (40 + 9) |
| Pendientes y sus notas · novedades · equipo propuesto · seguimientos | 4 y 42 · 2 · 1 · 11 |
| «Administrador de Prueba» aprendidos en `locales_admin` | 2 |
| `sesiones_log` de las cuentas de prueba | 347 |
| `casos_gestion`: avisos sintéticos 99990011/12 | 2 |
| Técnicos de prueba en `catalogos/tecnicos.json` | 21 → 19 |
| `~/respaldos/claves_prueba.json`, `prueba_deshacer.json`, `tecnicos.json.antes_prueba` | borrados |
| Espejo local `_ORIGEN_APP`: OT-9073…9082 y 1 carpeta de fotos | 11 |

**🔴 Dos casos reales devueltos:** `preparar_prueba.php` había tomado los avisos
**10355931** (impresora, G018EC) y **10356012** (máquina de hielo, G007EC),
**abiertos en SAP, prioridad ALTA, del 2026-09-21**. Los tenía el técnico de
prueba y los «cerraban» órdenes de prueba; Isabel incluso marcó el 10355931 como
cerrado en SAP. Nadie real los veía. Ahora vuelven a **NUEVO**, sin fila en
`casos_gestion`, que es el estado previo que registró el propio arnés
(`existia: false`). **Kevin Chimbo tiene que asignarlos, y hay que confirmar con
Isabel si el 10355931 está cerrado de verdad en SAP.**

**Verificado después** (consultas independientes del script): usuarios **22**, 0
con «prueba»; `ot_capturadas` 0, `email_queue` 0, `ot_fotos` 0, `ot_archivo`
APP 0 (total 7.738); pendientes 0, novedades 0, seguimientos 0, `locales_admin`
0; 0 casos con cierre OT-9xxx; 0 PDF OT-9xxx y 0 carpetas de fotos en disco; el
simulacro de nuevo responde «No hay cuentas de prueba: nada que limpiar». Intactos
los datos reales: `cronograma_novedades` 175, `ot_archivo_solicitudes` 15.

**Lo que NO se borró, y por qué:**
- **La bitácora** (1.219 filas de prueba): la 009 la hizo inalterable con
  disparadores. El primer intento abortó con «La bitacora no se borra (009)» y la
  transacción revirtió todo. Quedan como historial, con la fila
  `LIMPIEZA_PRUEBAS` que registra lo retirado. Borrarlas exige quitar el
  disparador: es decisión de Andrés.
- El contador `correlativos` (`CORRECTIVO:UIO` = 9112): solo es un número, como
  se decidió el 2026-09-13.
- Los 15 «pedir copia» de `ajumbo`/`abasantes` del 2026-09-13: son cuentas reales.

**Consecuencia:** las baterías de servidor (`verificar_*.py`) necesitan de nuevo
`preparar_prueba.php`, que recrea las cuentas **y vuelve a tomar dos casos reales
abiertos**. Después de correrlas: `limpiar_pruebas.php` (error nº 37 del plan).

---

## 1o. T2.26 · El formulario del técnico: desbloqueado, predictivo y capaz de trabajar sin señal (2026-09-22)

Andrés entró como `ajumbo` al caso **10355894**, pulsó «Emitir la orden de este
caso» y el formulario no avanzaba ni mostraba los datos del caso. De ahí salió
todo lo de abajo.

### Lo que estaba roto, y por qué ninguna prueba lo vio

**Ninguna cabecera del guiado abría su paso.** En `guia.js`, cada cabecera
registraba su clic cerrando sobre `actual`, **una sola variable** de `envolver()`
que se reasigna en cada `<h2>`. Cuando alguien pulsaba, ya valía el último paso:
`pasos.indexOf(actual)` daba siempre **11** y *cualquier* cabecera abría «Firma
del administrador». El técnico no podía abrir «La orden» ni «Datos generales»,
así que **el caso precargado —que sí se cargaba bien— no se veía por ningún
lado**, y nueve de los doce pasos quedaban en gris.

**Las 319 comprobaciones del proyecto estaban TODAS en verde** con el formulario
inutilizable. Ninguna pulsa un botón: comprueban códigos HTTP, contratos de
archivos y datos. Ese hueco es el error nº 33 del plan, y lo cubre la batería
nueva `verificar_formulario.mjs`.

### Lo que se midió antes de tocar nada

| Qué | Cifra medida | Contra qué |
|---|---|---|
| El equipo del aviso se preseleccionaba | **0 de 885** | los casos del buzón, con `Catalogo::cargar()` |
| Locales con administrador prellenado | **0 de 100** | `locales_admin` tiene 1 fila, y es de prueba |
| Locales con correo real del restaurante | **4 de 100** (95 son el buzón genérico) | maestro de locales |
| Órdenes históricas con el administrador | **7.386**, cubren **99 de 100** locales | `ots.admin_nombre`, estación |

La preselección del equipo **nunca** podía acertar: el aviso trae la
denominación entera (`MAQUINA DE HIELO-WM-IM100-000000000010176992`) y el
catálogo guarda un código de seis dígitos (`003769`). **Son dos numeraciones
distintas**, y la regla comparaba una con otra por igualdad.

### Lo que quedó hecho

**1 · El guiado, arreglado** (`guia.js`). El paso se captura en una variable por
vuelta. De paso, el resumen plegado del primer paso decía «Correctivo · **on**»:
una casilla sin atributo `value` vale la cadena `"on"` esté marcada o no, y la
de «otro proveedor» se colaba sola.

**2 · El equipo del caso, preseleccionado por TIPO** (`app.js`). Medido con el
mismo JavaScript que corre en el celular, sobre los 883 casos con local y
activo:

| | casos | qué hace |
|---|---|---|
| tipo exacto, uno solo | **425 (48,1 %)** | **se preselecciona** |
| tipo exacto, varios iguales | 134 (15,2 %) | dice cuántos hay; elige el técnico |
| solo parientes (`FREIDORA` → `FREIDORA ABIERTA`) | 105 (11,9 %) | los enseña; elige el técnico |
| el local no tiene nada de ese estilo | 219 (24,8 %) | lo dice y ofrece «Equipo nuevo» |

**Solo se preselecciona el calce exacto.** Un `FREIDORA → MESA` puesto por el
sistema acabaría impreso en un documento que lee Grupo KFC: eso es justo lo que
I-7 prohíbe. Y nunca se dice «este local no tiene ninguna FREIDORA» cuando tiene
dos de un subtipo — sería falso.

**3 · Las órdenes salen solas con la app CERRADA** (`cola.js` + `sw.js`). Hasta
aquí `cola.js` reintentaba al cargar, al volver la señal, al volver a la pestaña
y cada dos minutos: **todo eso solo mientras la aplicación siguiera abierta**. El
técnico que llena la orden, bloquea el teléfono y se va al siguiente local no
cumple ninguna de las cuatro. Ahora `cola.js` registra un `sync` y `sw.js` lo
atiende: si hay una pantalla abierta le pide a ella que mande —es la única que
sabe subir fotos y tratar las once respuestas del servidor—, y **solo si no hay
ninguna** manda él, y solo las órdenes sin fotos pendientes. Reenviar es
inofensivo: `envio.php` es idempotente por `envio_uuid`.

**4 · Un 401 ya no borra la copia local** (`sw.js`). Borraba **toda** la caché de
datos. La intención era buena —que lo de una sesión no se le sirva a otra— pero
el precio lo pagaba el técnico: la sesión vence a las 12 h, o la desplaza su
propio ingreso desde otro teléfono, y con eso se le borraban catálogos, bandeja
e identidad. Entraba al local sin señal y la aplicación estaba **vacía**.
Reproducido el 2026-09-22. Ahora se borra **cuando `yo.php` contesta con otro
usuario**, no cuando la sesión vence. Es el error nº 34.

`sw.js` va a **v13**: `guia.js`, `app.js` y `cola.js` están en la precarga del
armazón y sin subir la versión el arreglo no llega a un celular ya instalado.

### Evidencia

```
Locales (estación, PHP 8.3)
  prueba_48h.php          120 · 0
  prueba_contratos.mjs     57 · 0
  prueba_graficos.mjs      62 · 0
  prueba_continuidad.php   42 · 0

Contra darkviolet
  verificar_bandeja.py       37 · 0
  verificar_emision.py       34 · 0
  verificar_sync_cerrada.mjs (nueva)   4 · 0
  verificar_formulario.mjs   (nueva)  12 · 0
```

`verificar_formulario.mjs` **12 · 0** con el caso real 10356012: el aviso dice
`MAQUINA DE HIELO-WM-IM100-000000000010176992` y el formulario **preselecciona
`MAQUINA DE HIELO · SAP 30045350`** — con señal y también sin ella.

`verificar_sync_cerrada.mjs` es la prueba que importa y dio **4 · 0**: con la
aplicación **cerrada** (0 pantallas), se dispara el `sync` y **el servidor
registra el POST**, contado por `envio_uuid` en la bitácora antes de reabrir
nada. No crea ninguna orden: encola a propósito una inválida, y `envio.php`
responde 400 **antes** de insertar en `ot_capturadas`.

En `verificar_formulario.mjs`, con el caso 10355931: cada cabecera abre su paso;
sin señal el caso sigue precargado (`aviso=10355931`, `local=G018EC · SAN
BARTOLO QUITO`, catálogo de 100 locales desde la copia); y la orden llenada sin
señal **queda guardada con su caso, su local y su firma**, con el recibo
diciendo la verdad: «Orden guardada en este celular. No hay señal, así que
todavía no salió».

### Instalable en el teléfono: comprobado

`Page.getAppManifest` contra darkviolet, con un navegador de verdad: manifiesto
servido y **sin errores**, `display: standalone`, `start_url: index.html`,
iconos 192 y 512 (más el *maskable*) que devuelven **200 image/png**, HTTPS, y
trabajador de servicio activo con manejador de `fetch`. Cumple lo que Chrome y
Edge piden para ofrecer «Agregar a la pantalla de inicio».

**El logo de la cabecera daba 404 en cada carga** desde que se escribió
`index.html`: apuntaba a `assets/logo-industec.png`, la carpeta no existía y el
archivo vivía en `nucleo/`, que la web sirve con **403** a propósito. El
`onerror` del `<img>` lo escondía, así que nadie lo vio. Copiado a `assets/` y
metido en la precarga (**sw.js v14**). Ojo al comprobarlo: Hostinger tiene un
CDN delante (`Server: hcdn`) que **reoptimiza las imágenes**, así que el hash de
lo que entrega la web NO coincide con el del disco —7.495 bytes contra 6.412—
aunque sea el mismo PNG de 768×143. El hash en el servidor sí cuadra.

### Qué sobrevive sin señal, por rol (medido el 2026-09-22)

| Pantalla | Técnico | Jefe de zona |
|---|---|---|
| `index.html` (el formulario) | ✅ | — |
| `mis.php` (su bandeja) | ✅ | — |
| `pendientes.php` | ✅ | ✅ |
| `cronograma.html` | ✅ | ✅ |
| `ordenes.php` | ❌ | ❌ |
| `documentos.php` | ❌ | — |
| `panel.php`, `casos.php`, `asignacion.php` | — | ❌ |
| `novedades_visita.php`, `reportes.php` | — | ❌ |

**El técnico tiene cubierto su trabajo entero sin señal.** El jefe de zona tiene
**2 de 8**: en el campo solo le sirven repuestos y el cronograma. Ampliarlo es
una decisión con filo —cachear pantallas PHP es lo que puede servirle a alguien
lo que no le toca— y es de Andrés: hay que decidir **qué necesita un jefe con el
teléfono en la mano** antes de cachear nada más.

### Lo que NO abrió un agujero: dos personas en el mismo teléfono

Al quitar el borrado en el 401 parecía que el jefe veía, sin señal, las
pantallas cacheadas del técnico. **No es así, y la primera medición era mía y
estaba mal**: el ingreso como jefe nunca llegó a ocurrir —`login.php` con sesión
activa redirige, así que el formulario no estaba y todo seguía siendo la sesión
del técnico—. Repetido cerrando sesión de verdad (`salir.php` cierra **solo por
POST con token**, SEG-25: por GET enseña un botón), el jefe ve **lo suyo**, con
y sin señal, y la caché del técnico desaparece.

El cambio de persona ya estaba cubierto y no por el 401: **`salir.php` borra las
cachés `-datos` del teléfono** al cerrar sesión. Y en un mismo teléfono no se
puede cambiar de usuario sin pasar por ahí, porque `login.php` con sesión activa
redirige. La comprobación de identidad por `yo.php` que se añadió es el segundo
cerrojo, no el único.

### Lo que NO se pudo comprobar, y por qué

- **`verificar_http.py` queda en 81/86, y el motivo está medido.** No es por
  esto: `verificar_emision.py` —que da **34·0**— **emite una orden de verdad** y
  con eso el caso con local del técnico de prueba pasa a ATENDIDO. A la de HTTP
  solo le queda el sintético `99990011`, que no trae local, y sus cinco envíos
  de T2.13.1 fallan con «sin local». Ninguna de las dos carga `app.js`,
  `cola.js` ni `sw.js`: son POST de Python contra `envio.php`. **El orden es
  `preparar_prueba.php` → `verificar_http.py` → `verificar_emision.py`** (error
  nº 36). La batería ya **lo dice en su salida** en vez de dejar cinco fallos
  crípticos. Queda **correr `preparar_prueba.php` y repetir la de HTTP** cuando
  la otra conversación suelte el arnés.
- **No se entró con la cuenta real de `ajumbo`**: no se tiene su clave y no se
  iba a tocar. El defecto era del guion, igual para todas las cuentas, pero
  queda dicho.
- **La entrega con la app cerrada se probó con una orden inválida**, a propósito,
  para no escribir en el servidor. El camino (despertar → token → POST → tratar
  la respuesta) se recorrió entero; **una orden VÁLIDA entregada por el
  trabajador de servicio no se ha visto todavía**.
- **iOS no tiene Background Sync.** Ahí siguen valiendo los cuatro disparos de
  siempre, que exigen abrir la app. Es mejora progresiva, no un cambio de
  contrato.
- **`prueba_offline.mjs` estaba rota antes de tocar nada** (comprobado con
  `git stash`): es una de las dos que §5.2b ya daba por rotas.
- **Nada de lo medido para el administrador del local se ha aplicado**: sembrar
  `locales_admin` desde las 7.386 órdenes históricas **escribe en la base del
  servidor** y no se hizo. Es lo que sigue (acción **O** del plan).
- **El jefe de zona sigue con 2 de 8 pantallas sin señal.** Está medido, no
  resuelto: qué cachear es decisión de Andrés.
- **iOS no se probó.** No hay ningún iPhone en el arnés. Background Sync no
  existe ahí, y lo que se midió de instalación es contra Chrome/Edge.

---

## 1n. T2.25.4 · El origen ya no tenía que ser "suyo" — Andrés lo encontró probando el propio 10342924 (2026-09-22)

Andrés, mirando otra vez el caso 10342924 (el mismo de T2.25.2), se topó con «No
encuentro ese trabajo anterior entre los tuyos» al poner un aviso anterior real
como origen. La restricción exigía que el ORIGEN estuviera asignado al mismo
técnico — y **ese es justo el caso que T2.25 existe para resolver**: el trabajo
lo termina, seguido, un técnico distinto del que lo empezó, porque las
asignaciones se reparten por urgencia, no por quién atendió el aviso viejo.

**Lo que cambió, y lo que no:**

| | Antes | Ahora |
|---|---|---|
| El caso ACTUAL (`avisoN`) | tiene que ser del técnico | **sin cambio** — sigue siendo suyo |
| El ORIGEN (el trabajo anterior) | tenía que ser del técnico | **cualquiera de su misma ZONA** |

`Casos::alcanzaAviso()` —el candado de «esto es mío», que usan `envio.php` y
`Pendientes.php` para emitir una orden o abrir un pendiente— **no se tocó**.
Se agregó `Casos::zonaDeAviso()` (gestión si la tiene, si no el catálogo) y
`mis.php` valida el origen contra `Auth::alcanzaZona()`, que ya usan el jefe de
zona y la administración: el aislamiento entre zonas, la razón original de la
restricción, se mantiene intacto.

**Comprobado, tres corridas limpias consecutivas contra darkviolet**
(`verificar_continuidad.py`, reescrita para probar las dos ramas — antes solo
probaba que "otro técnico no enlaza un caso ajeno" con dos cuentas de la MISMA
zona, lo cual en realidad medía el candado que no cambió, no el que se
relajó):

```
28 de 28 comprobaciones pasan; 0 fallan
```

incluyendo, nuevas: «un técnico no declara continuidad sobre el caso de otro»
(sin cambio, sigue prohibido) y «el dueño del caso nuevo SÍ enlaza contra un
origen que no es suyo» + «queda en bitácora a nombre del técnico que declaró»
(el cambio). **Dos corridas intermedias dieron 401/302 en pasos de sesión no
tocados por este cambio** (login y `envio.php`) — es el mismo flakiness ya
documentado en §1l cuando las cuentas de prueba se pisan entre corridas
seguidas; no es un efecto de este código, y las corridas que llegaron completas
pasaron siempre 28/28.

`prueba_continuidad.php` (local, sin base) también se corrió contra el
catálogo real del servidor tras el despliegue: **36 comprobaciones, 0 fallos**,
sin cambios porque no toca lo que se modificó.

Desplegado en darkviolet (`mis.php`, `nucleo/Casos.php`) el 2026-09-22,
verificado con `t2_10_desplegar.py` que la web entrega exactamente lo subido.

### ✅ Confirmado por el uso real, no por una prueba: la bitácora de `ajumbo`

Lo que esta sección daba por «no comprobado» —el clic real de un técnico— lo
comprobó el propio uso. La bitácora del aviso **10342924** cuenta la historia
completa, y es la mejor evidencia que tiene este arreglo:

| Cuándo | Usuario | Qué pasó |
|---|---|---|
| 2026-09-21 21:54:45 | `ajumbo` | `DENEGADO` · continuidad contra 10342524, fuera de su alcance |
| 2026-09-21 21:54:55 | `ajumbo` | `DENEGADO` · lo intentó de nuevo |
| 2026-09-22 06:09:07 | `ajumbo` | `DENEGADO` |
| 2026-09-22 06:09:34 | `ajumbo` | `DENEGADO` · el cuarto intento, ya con la captura mandada |
| — | — | *(aquí entra el arreglo)* |
| 2026-09-22 06:30:02 | `ajumbo` | **`CASO_CONTINUA`** · continúa el trabajo del aviso 10342524 |
| 2026-09-22 06:30:29 | `ajumbo` | `CASO_DESCONTINUA` · probó deshacerlo, y se deshizo |
| 2026-09-22 11:47:49 | `ajumbo` | **`CASO_CONTINUA`** · con la nota «Mismo equipo, mismo trabajo» |

Cuatro rechazos antes, dos enlaces logrados después, el mismo usuario y el
mismo par de avisos. **`ajumbo` es Anthony Medardo Jumbo Rojano, TECNICO de
UIO** — un técnico de verdad, no una cuenta de prueba, y el caso que enlazó es
el del horno de G006EC que dio origen a T2.25 entera.

**Lo que sigue sin comprobarse:** la rama cross-zona (que sigue prohibida) no
tiene cuenta de otra zona en el arnés para ejercitarse de punta a punta contra
el servidor — queda cubierta por revisión de código (`Auth::alcanzaZona()` ya
está probada en otras rutas) pero no por esta batería.

**La política que fija esto, dicha por Andrés en el momento:** enlazar con el
trabajo de otro técnico sigue siendo la EXCEPCIÓN — la norma a la que el
sistema se alinea es que quien empieza un caso lo lleve hasta el cierre. Por
eso el pedido no terminó en «que funcione»: pidió que el jefe de zona y la
administración puedan **ver, sin abrir cada caso, cuándo pasó**. Eso es
T2.25.5, **hecho el mismo día** — sigue aquí abajo.

---

## 1ñ. T2.25.5 · «Quién empezó y quién sigue», en el buzón del jefe (2026-09-22)

El buzón del jefe de zona ya decía «continúa el aviso 10342524». Ahora dice,
debajo y en ámbar cuando el trabajo cruzó de técnico:

```
continúa el aviso 10342524 · sin orden propia
⚠ lo empezó Marco Taipe · sigue Anthony Medardo Jumbo Rojano
```

**El dato tumbó el diseño antes de escribirlo.** La tarea, tal como la dejé
escrita en el plan una hora antes, decía comparar `continua_por` contra el
`asignado_a` del aviso de origen. Medido contra la base: **no detecta nada**.
El único caso real de continuidad tiene el aviso viejo en
`CERRADO_SIN_ATENCION` y `asignado_a` en **NULL** —SAP lo cerró solo a las
48 h, que es la razón misma por la que existe T2.25—, así que esa comparación
habría dicho «sin dato» justo en el caso que hay que ver. La fuente buena es
la **firma de la orden archivada** (`ot_archivo.tecnico`): existe, es
inmutable, y dice *Marco Taipe*. **Como el dato ya estaba, no hizo falta
migración** — se descartó la columna nueva en `casos_gestion` que la tarea iba
a pedir.

| Cifra | Valor |
|---|---|
| Casos enlazados hoy en toda la base | **1** (el real: 10342924 → 10342524) |
| De ellos, con cruce de técnico | **1** — el primer caso real ya es el flujo alternativo |
| `prueba_continuidad.php` | **42 comprobaciones · 0 fallos** (eran 36) |
| `verificar_continuidad.py` | **29 de 30**, con las 2 nuevas de T2.25.5 en verde |
| `casos.php` como jefe de zona | **HTTP 200**, con la línea exacta y **0 avisos de PHP** |

**El único rojo, y es ajeno:** «ningún caso cerrado con un pendiente vivo» da
1 fila — el aviso **10356012** (G007, **real**), `ATENDIDO` con el pendiente 22
en `SIN_VEREDICTO`. No lo causó este cambio: es el **arnés de pruebas** —que
tomó prestados dos casos reales (§1l lo advertía) y dejó ese pendiente abierto
en las pruebas de T2.13.5— chocando con la **reconciliación automática**, que
lo pasó a `ATENDIDO` el 2026-09-22 a las 12:35 (`ATENDIDO_AUTO`, usuario
`sistema`). **No se tocó**: es un dato de un local real (regla 2). Lo que
corresponde es correr `php ~/respaldos/deshacer_prueba.php` cuando las demás
baterías ya no necesiten el arnés.

**Lo que NO se hizo:** el filtro y el conteo por zona («cuántos cruzaron este
mes»), que es lo que armaría la norma. Con **un solo caso enlazado** en toda la
base, contar no dice nada; y filtrar por técnico exige antes resolver la
identidad entre la firma del PDF y el nombre del padrón —el mismo pendiente que
tiene «Las mías» del Archivo—. Se retoma cuando el piloto deje casos de verdad.

---

## 1m. Cierre masivo de los 174 preventivos "sin cerrar" — ✅ EJECUTADO Y VERIFICADO (2026-09-22, T2.24.2)

> **Cerrado el 2026-09-22.** El bloqueo de escritura de más abajo lo tuvo esta
> sesión de Claude Code, no Andrés: él corrió el comando él mismo, en su
> propia terminal, con `--ejecutar`. Salida: `CERRADOS: 174`. Verificado
> después por lectura, contra el servidor:
> - **0** ingresos siguen `EN_CURSO` y vencidos — el atraso quedó en cero.
> - **174** quedaron `CUMPLIDO`, con `actualizado_por=2` (Andrés) y `real_fin`
>   puesto.
> - **174** novedades `CIERRE` con el motivo del cierre masivo, una por
>   ingreso.
> - **368** filas totales en `ingresos_preventivos`, igual que antes: nada se
>   perdió ni se duplicó.
> - Conteo final por estado: **192 PLANIFICADO + 2 EN_CURSO + 174 CUMPLIDO =
>   368.** Los 2 `EN_CURSO` que quedan no estaban vencidos — no eran parte de
>   los 174, así que es correcto que sigan así.
>
> El texto de abajo (armado el 2026-09-21) documenta cómo se llegó hasta acá
> y por qué las fechas usadas son reales y no inventadas; se conserva tal
> cual como evidencia del trabajo previo a la ejecución.

Decisión de Andrés, textual: *«respecto a los 174 cierres, ya que estos son de
hasta hace más de 7 días atrás entonces deben ya cerrarse porque KFC ya los
cerró y la administradora también, ya que ella continúa haciendo estas labores
de regularización manualmente y mañana recién empezará a probar la
plataforma»*. Resuelve el punto 2 de T2.24.2 que había quedado abierto en la
sesión anterior: cerrar en bloque, sin verificar caso por caso.

### Lo que se hizo antes de escribir nada

1. **Se leyó el estado real del servidor, no la maqueta local.** Contra
   `ingresos_preventivos` en Hostinger: `SELECT ... WHERE estado='EN_CURSO' AND
   plan_vigente_fin < CURDATE()` → **exactamente 174**, ninguno tocado desde
   la app (`actualizado_por IS NULL` en los 174). Cuadra con lo medido en la
   maqueta de T2.23.
2. **Se cruzó cada uno contra una fuente independiente (I-10): la fecha real
   de sus propias órdenes.** `ot_ids` de cada ingreso ya tenía los
   identificadores de las OT emitidas; se buscó cada una en `ots.fecha_atencion`
   de la base local `industec_ots` — la fecha extraída del PDF, ajena por
   completo al cronograma. **174 de 174 tuvieron al menos una fecha real.**
   Ninguna fecha se inventó (I-7): donde faltaba, no se habría escrito nada,
   pero no fue el caso.
3. **7 casos con más de 30 días de diferencia entre la fecha real y la
   planificada se inspeccionaron uno por uno.** Todos explicables — trabajo
   real hecho semanas antes o después de la fecha acordada, con correlativos
   y fechas internamente consistentes (D1/D2/D3 seguidos). No es un error de
   datos, es que el plan reconstruido de Fase 1 y lo que pasó de verdad no
   siempre coinciden.
4. **Un defecto de datos encontrado y sorteado sin perder información:** el
   ingreso 187 (K041EC) tiene tantas variantes de nombre para sus órdenes
   que el campo `ot_ids` (varchar 400) llega truncado a la mitad de un JSON.
   `json.loads()` fallaba ahí; se recuperaron las 8 órdenes completas que sí
   entraron en los 400 caracteres con un patrón sobre el texto crudo, en vez
   de descartar la fila entera.
5. **Los duplicados en cuarentena (I-11) se excluyeron** del cálculo de
   fecha real: una orden `en_cuarentena=1` no debe decidir cuándo se cerró
   el ingreso.

### Lo que quedó armado, probado y desplegado

- `desarrollo/agentes/scripts/t2_24_2_consultar_fechas_reales.py` — cruza
  los 174 contra `ots.fecha_atencion` (local, industec_ots). Solo lectura.
- `desarrollo/sistema_ots/app/pruebas/servidor/t2_24_2_consultar_sin_cerrar.py`
  — identifica los "sin cerrar" contra el servidor real. Solo lectura.
- `desarrollo/sistema_ots/app/publico/t2_24_2_cerrar_masivo_cli.php` — el
  script que escribe, siguiendo **exactamente** el patrón ya usado y
  aprobado de `regularizar_masivo_cli.php`: por omisión solo cuenta, exige
  `--ejecutar` para escribir, y antes de tocar cada fila **vuelve a leerla
  de la base viva** (no del JSON precalculado) y la salta si ya no cumple
  las tres condiciones (`EN_CURSO`, vencida, sin tocar por un usuario).
  Reproduce el mismo cálculo de "a tiempo" que usa `cronograma_accion.php`.
  Atribuido a `usuario_id=2` (Andrés Basantes) en `cronograma_novedades.por`
  — es su decisión, y esa tabla no admite `usuario='sistema'` como la
  bitácora general porque su columna `por` es `NOT NULL` con FK a `usuarios`.
  Verificado con `php -l` (sin errores de sintaxis) y **ya desplegado** al
  sitio de pruebas junto con su payload de datos
  (`catalogos/t2_24_2_cierres_masivo.json`, 174 filas con `real_inicio`,
  `real_fin` y de dónde salió cada fecha — gitignorado por ser dato, no
  código, igual que el resto de `catalogos/*.json`).

### Lo que NO se pudo hacer: la ejecución quedó bloqueada

El clasificador de seguridad de esta sesión denegó el comando SSH que corre
el script en el servidor — **incluso en modo de solo conteo, sin
`--ejecutar`** — con el motivo «Modify Shared Resources». No se intentó
sortear el bloqueo por otra vía (otra herramienta, otro camino): es una
capa de seguridad del entorno, no del proyecto, y no le corresponde a un
agente decidir pasarla por alto.

**Para que alguien con permisos lo termine**, el comando exacto, ya
verificado que existe y responde (`--probar` de `t2_10_desplegar.py` dio
OK), es:

```bash
cd "D:/INDUSTECH IA/desarrollo/sistema_ots/app/pruebas/servidor"
INDUSTEC_LLAVE_SSH="D:/INDUSTECH IA/desarrollo/agentes/config/clave_hostinger" \
  python -c "import verificar_http as vh; print(vh.ssh('cd ' + vh.D + ' && php t2_24_2_cerrar_masivo_cli.php'))"
# Repasa la salida: debe decir "validados y listos para cerrar: 174" y "saltados (0)".
# Si cuadra, se agrega --ejecutar al final del comando dentro de las comillas.
```

O, más simple, entrando por SSH a mano:
```bash
ssh -i "D:/INDUSTECH IA/desarrollo/agentes/config/clave_hostinger" -p 65002 \
    u671729428@82.25.73.181
cd domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot
php t2_24_2_cerrar_masivo_cli.php              # cuenta, no escribe
php t2_24_2_cerrar_masivo_cli.php --ejecutar   # escribe de verdad
```

**Criterio de aceptación, para quien lo corra:** la salida debe decir
`validados y listos para cerrar : 174` y `saltados (0)`. Si sale un número
distinto de 174 o hay saltados, algo cambió desde que se generó el payload
(por ejemplo, alguien tocó un ingreso desde la app) — no forzar, revisar
antes de `--ejecutar`.

---

## 1c. La cadena del correo, medida el 2026-09-18 (auditoría de T2.21)

Andrés pidió validar si el robot del correo identifica los informes nuevos, los
clasifica en el respaldo, los enlaza al caso abierto y los muestra en el buzón
del técnico. Se auditó con 26 agentes (21 completados). **Qué construir con esto
está en `PLAN_INDUSTEC.md` T2.21; aquí van solo las cifras.**

| Etapa | Veredicto | Cifra verificada |
|---|---|---|
| **Identifica los informes nuevos** | ⚠️ a medias | **918** informes en `INBOX` (de `reclutamiento@industec.me`, ventana de 90 días), y de ahí **581 leídos → 129 con PDF bajado**. Pero los scripts abren **1 de las 10 carpetas** de la cuenta: en `Trash` hay **157 informes, 123 con «Estado de OT: Cerrada», y 76 de casos que el sitio sigue mostrando pendientes**. La carpeta **`INFORMES OT` ya existe y está vacía**: nadie la lee |
| **Los clasifica en el respaldo** | ❌ no | **104 PDF en `D:\RESPALDOS\_ORIGEN_BUZON`** (33 UIO, 17 LARB, 54 CNLJ): **0** con archivo en el árbol canónico bajo su propio correlativo, **0** con fila en `ots` por (correlativo, zona), **99** sin ningún rastro. `ots.fuente='IMAP_EN_VIVO'` → **0 de 7.469** (las 7.469 son `DRIVE_HISTORICO`). El árbol canónico no recibe un archivo desde el **2026-09-13 22:22**. Los 104 son documentos únicos: **0 duplicados por hash** contra los 18.188 PDF de todo `D:\RESPALDOS` |
| **Los sube y enlaza al caso, con su estatus** | ⚠️ enlaza, no sube | El enlace por número de aviso funciona y la distinción «INDUSTEC atendió» ≠ «SAP cerró» **se respeta** (`Ui::ESTADOS`, chip «atendido, en curso» / «con orden de cierre»). Pero **0 de 104 PDF están en el servidor**: `t2_19_subir_pdfs.py` recorre `ORDENES DE TRABAJO` + `_DEL_BUZON` (7.123 + 164 = 7.287) y su simulación dice «faltan: 0» sin haberlos visto. **19 casos** con «PDF no cargado al archivo todavía», **3 de ellos órdenes de cierre** (avisos 10355449, 10355399, 10355488) |
| **Se ve en el buzón del técnico y su historial** | ⚠️ llega poco | La atención sí llega: **121 casos** con atención, **88 en bandeja** entre 13 personas, **31 en historial**. El aislamiento por técnico y por zona **está bien** (el bug de los 300 casos de la zona está cerrado). Pero «Las mías» del Archivo está roto porque `ot_archivo.tecnico` guarda la firma cruda del PDF y el filtro compara con el nombre del padrón. **21 de las 40 personas del padrón no tienen usuario del sistema.** ⚠️ **La cifra «1 fila de 7.118 / 0 para los 16 técnicos» es FALSA** — la desmintió la verificación contra el servidor del 2026-09-19, ver §1c-bis |
| **¿Corre, y se notaría si se para?** | ⚠️ corre a medias | Tareas programadas: **2 de 3**. «Informes de OT» cada 3 h (última 15:23:10, resultado 0) y «Vigilante del buzon» viva desde el 2026-09-15 12:43:08. **«Saneamiento nocturno» no existe** y nunca corrió: 0 filas en `bitacora` con `agente='saneamiento'`, 0 logs `saneamiento-*.log`, `estado_nocturno.json` inexistente. Eso deja **4 de los 7 pasos** (normalizar, ingesta, archivo, pdfs) sin correr nunca en cadena. **13 empujes fallaron** el 2026-09-18 por corte de TLS y `t2_11` salió con **código 0** en todos: el Programador registró éxito. **0 mecanismos de alerta** en todo el proyecto |

**Dos hallazgos que la verificación tumbó** — no hay que arreglarlos: el
historial del técnico **no** se recorta a 90 días (`casos_gestion` acumula), y
los 2 casos sin técnico **sí** dejan rastro (`Casos::sinAsignar()` los ofrece
para asignar a mano).

**Tres cifras de este documento que la auditoría corrigió:**

1. Los «**69 sin PDF**» de la fila de T2.19 **no** eran «de origen CORREO/GESTIÓN
   sin archivo local». Los archivos **sí están** en la estación, en
   `_ORIGEN_BUZON`, y `t2_19` no mira esa carpeta.
2. **§5.2b está vencido en su primera fila:** `prueba_48h.php` da hoy **120·0**,
   no «96 · 4 fallos». Alguien la arregló y no lo anotó.
3. El «pedir copia» de `OT-2503-K146-10354374-CNLJ` que §1b da por pendiente
   («nunca se descargó del buzón») **ya está**: el informe de ese aviso está en
   `_ORIGEN_BUZON` y en el árbol canónico. El pedido se puede cerrar.

**Lo que NO se pudo comprobar, y hay que cerrar en T2.21.6 (I-7):**

- **Los cuatro refutadores de la dimensión de captación cayeron por límite de
  sesión.** Dos de sus hallazgos se verificaron a mano y **quedan confirmados**:
  el del `Trash` (la salida de arriba) y el tráfico ajeno — en la ventana de 90
  días el `INBOX` tiene 4.912 mensajes, de los cuales 591 de `reclutamiento`,
  939 de `sgerente@kfc.com.ec` y **3.382 de otros remitentes, 302 con adjunto
  PDF, para los que el robot no tiene ni un contador**. Ojo: 302 es **techo de
  tráfico ignorado, no cifra de informes perdidos** — nadie abrió esos PDF para
  saber cuántos son informes de cierre y cuántos firmas o fotos. Siguen **sin
  refutar**: los 2 informes con parseo fallido que el resumen publica como
  `informes_no_parseados: 0`, el nombre truncado en el primer espacio
  (`OT-0179-M055-10340532-Dia`) y la comparación con el literal `Cerrada`.
- **Nadie leyó la base del servidor ni abrió una pantalla.** Todo lo de
  `casos_gestion`, `ot_archivo` y las pantallas es lectura de código cruzada con
  la base local y los JSON que la estación empuja. Hace falta SSH.
- **No se determinó la causa del corte de TLS.** 11 de los 13 fallos caen en la
  ventana 12:33–17:05, la misma en que se subieron 6.672 PDF al mismo host:
  correlación, no causa comprobada.

**Una cosa que se encontró de paso y quedó resuelta:**
`t2_11_informes_ot.py` llevaba **+35 / −2 líneas sin commitear** (la función
`num_aviso()`, que cruza el aviso ignorando los ceros de la izquierda) y era
**la copia del disco la que corría cada 3 h** por la Tarea programada:
producción ejecutaba código que no estaba en el repositorio y el PC de Andrés no
lo tenía. ✅ **Commiteado el 2026-09-18 por decisión de Andrés** (`f6ac8ca`).
**Lo que sigue sin verificarse de ese cambio:** el camino nuevo no se ha
ejercitado ni una vez — `grep "cruzaron por el numero"` sobre todos los logs da
**0 apariciones**, así que del 09-13 al 09-18 ningún informe necesitó la
normalización. Solo está comprobado que compila y que las 6 corridas del 18-sep
pasaron por el archivo sin fallar; falta un caso real con ceros a la izquierda.
Los otros dos hallazgos de §5.2b
sobre esos scripts **siguen abiertos**: la compuerta de cuadre I-10 de
`t2_12:286-289` sigue siendo tautológica, y `%TEMP%\_cotejo_sap.xlsx` con
órdenes abiertas de SAP del cliente sigue ahí desde el **2026-09-10** (8 días,
14.743 bytes, sin cifrar y fuera de las rutas del proyecto — cuenta para el
riesgo LOPDP). `EMISOR_OT` no está en `.env`, pero **no rompe nada**: la línea
158 tiene el remitente por omisión.

Toda la auditoría fue de **solo lectura**: IMAP con EXAMINE + `BODY.PEEK[]`,
cero `INSERT/UPDATE/DELETE`, cero `--ejecutar` y cero `--empujar`. Nada de
`G:\Mi unidad`, del árbol canónico ni del servidor se tocó. Efecto lateral
declarado: la simulación de `t2_18_rescatar_buzon.py` escribió por diseño su
manifiesto en `SALIDAS IA\OTS\RESCATE_DEL_BUZON_*.csv`, que es escritura libre.

## 1b. Lo construido — continuidad de datos y sistema nuevo

Todo lo de esta tabla está **entregado y con sus pruebas en verde**. Lo que no
corre todavía dice por qué. Las filas de arriba son las del **pulido del 12 y 13
de septiembre de 2026** (T2.14, T2.15 y T2.17, rama `pc/pulido-2026-09-12` en
GitHub, pendiente de fusionar en `master` desde la estación); las de abajo, lo
anterior, con las notas «sin desplegar» o «falta la 007» ya superadas (todo eso
está desplegado desde el 2026-09-11).

| Pieza | Estado | Verificación |
|---|---|---|
| **Buzón con todos los documentos del caso** (2026-09-14) | ✅ desplegado en darkviolet, commit `58aea8c` | `Casos::documentos()` relaciona **por aviso** las cuatro fuentes (índice del Archivo, orden de cierre de la gestión, correo, app): el mismo informe llega como `OT-2488-K061-…` por correo y `OT-2488-K061EC-…` en el árbol, y se muestran los dos. Casos «sin atender» **820 → 784** (36 atendidos que se veían sin atender, p. ej. 10354415 y 10354383); la administración ve el informe de cierre junto a «Ya lo cerré en SAP». Índice del Archivo **143 → 164** y órdenes de cierre fuera del índice **19 → 0** (fuente (e) de `archivo_indexar_cli.php`). El enlace solo se ofrece si el PDF está: **116 de 164** hoy |
| **T2.20 · Otros trabajos** (2026-09-14) | ✅ desplegado en darkviolet; migración **011** aplicada (`verificar_esquema.php`: TODO OK, huella `f8d1529d…`) | Marca aparte del estado en `casos_gestion` (autorizado / no autorizado, acuerdo con KFC, quién y cuándo), decidida solo por la administración (`casos.veredicto`); bitácora `OTRO_TRABAJO`. **7 casos fuera del área por decidir** (5 constructivos de CNLJ/LARB, entre ellos el 10351229 ya RESUELTO, y 2 correctivos de LARB con alerta). Panel «fuera del área, por decidir», filtro `casos.php?otro=`, sección en reportes y en las tres descargas. `Reportes::calcular()` con un superadmin en memoria: 927 casos, 7 por decidir, 0 autorizados; el HTML del PDF trae la sección. **Sin probar con una sesión real:** la acción del diálogo y las descargas Excel/PowerPoint (ver §8) |
| **T2.19 · Todos los PDF en el servidor** | ✅ **corrido en la estación el 2026-09-18** | `t2_19_pruebas.py`: 16/16. `t2_19_subir_pdfs.py`: simulación 7.172 por subir (0 colisiones, 0 fuera de patrón); `--ejecutar --limite 500` (500/500 verificados) y `--ejecutar` para el resto (6.672/6.672, 0 fallidos). **7.287 de 7.287 PDF de la estación ahora en `ordenes_pdf/` de darkviolet.** `t2_15_exportar_archivo.py --desde-mariadb --empujar`: 7.118 órdenes exportadas, índice del Archivo en 7.356 filas, `en_servidor=1` en 7.287 (69 sin PDF, de origen CORREO/GESTIÓN sin archivo en `D:\RESPALDOS`). **Bug encontrado y corregido:** el fallback de la llave SSH en `t2_15_exportar_archivo.py:138` apuntaba a la ruta del PC (`~/.ssh/industec_hostinger_pc`) en vez de `config/clave_hostinger` de la estación — inconsistente con `t2_10_desplegar.py` y `hostinger_ssh.py`. **Criterio de cierre verificado:** los avisos 10354415, 10354383 y 10351229 tienen su PDF en `ordenes_pdf/` y su fila en `ot_archivo` con `en_servidor=1` y `ruta` válida |
| **T2.14 · Pulido para el piloto (S0–S7)** | ✅ **2026-09-13**, desplegado en el sitio de pruebas, commits `a4b239d`…`415865c` | Migraciones 009 y 010 aplicadas; app del técnico (PDF siempre visible, caso prellenado, administrador del local, equipo nuevo, diagnóstico pre-redactado, repuestos, otro proveedor, corrección de una orden rechazada); asignación por bloques de zona con «por repartir» que filtra; repuestos con el flujo D3 (solicitado → validado por el jefe → registrado en SAP → veredicto de KFC → resuelto solo con la orden concluida); Archivo de todas las zonas (`ot_archivo`, 209 órdenes, 180 con PDF) con compartir trazado; bitácora con pantalla y CSV; usuarios editables; Aprendizaje con aprobación y acuses; reportes por zona y mes con Excel/PDF/PowerPoint; cronograma que escribe; sesión que caduca, CSRF, bloqueo a los 5 fallos. **Siete baterías contra el servidor: 319 comprobaciones en verde** (`verificar_http` 86, bandeja 37, ciclo 48, emisión 34, archivo 50, reportes 36, seguridad 28); locales `prueba_48h` 120·0, `prueba_contratos` 57·0, `prueba_graficos` 62·0. `sw.js` en **v10** |
| **T2.14.8 · Paquete del piloto y documentación** | ✅ **2026-09-13**, commit `9ccd0af` | `desarrollo/sistema_ots/piloto/` (fuente) y `SALIDAS IA\OTS\paquete_piloto_uio\` (copia): `ANTES_DE_EMPEZAR`, `CUENTAS` (sin ninguna clave), `HOJA_TECNICO`, `HOJA_JEFE_ZONA`, `HOJA_ADMINISTRACION`, `QUE_PROBAR` (día 1 a 5), `COMO_REPORTAR_FALLOS`, con 36 capturas reales anonimizadas (`capturar_pantallas.mjs --piloto --recorte --anonimizar`). Pantalla nueva **`equipos.php`** (los equipos que los técnicos registran como nuevos: aprobar, rechazar, ya existía; D8 no tenía interfaz). `publico/LEEME.md`, `app/LEEME.md` y `LEEME_ACCESO_HOSTINGER.md` (parte A vigente, parte B diferida al corte) reescritos; cabeceras de `PLAN_APP_GESTION.md`, la 007 y la 003 al día |
| **T2.15 · Saneamiento continuo en la estación** | ✅ **2026-09-13**, commit `d09a5df`, probado desde el PC contra Hostinger | `comun.py` + `hostinger_ssh.py` (un solo contrato SSH; producción solo lectura por código); `t2_4_sync_hostinger.py` espeja el sistema viejo (436 PDF de uio) y la app (187 PDF, 20 fotos) con divergentes aparte; `t2_4_volcado_bd.py` (volcado verificado por hash, 30 diarios + 12 mensuales); purga con cinco compuertas y `SEGUNDA_COPIA`; `saneamiento_nocturno.py/.bat` + `SANEAMIENTO.md` (Tarea programada: la crea Andrés); `t2_14_sembrar_correlativos.py` en ensayo (CORRECTIVO UIO 1856 · LARB 2262 · CNLJ 2503; la app está en la serie de pruebas 9071). `t2_4_pruebas --sin-base` 16·0 |
| **T2.17 · Web corporativa rediseñada y publicada** | ✅ **2026-09-13**, publicada en la raíz de darkviolet y verificada por hash | Siete ilustraciones SVG a mano en el estilo In Tune industrial (`DIRECCION_ARTE.md`), Barlow autoalojada, azul de marca / rojo solo urgencia / verde solo WhatsApp, WhatsApp primario en el menú, servicios antes que los logos, logos en franja gris, formulario con enlaces reales. `verificar-sitio` y `capturar-sitio` en verde; `publicar_sitio.py --si` 104 de 104 por hash; `verificar-publicacion.mjs` OK; `/ot/login.php` 200 y `/ot/catalogos/locales.json` 403. Detalle en `web_corporativa/LEEME.md` §10 |
| **T2.18 · Archivo clasificado por zona y franquicia, y enlace con «pedir copia»** | ✅ **código y pruebas, 2026-09-13**, rama `pc/archivo-zona-franquicia-2026-09-13` — **falta que la estación lo corra contra datos reales** | `t2_18_clasificar_archivo.py`: espeja el árbol canónico a `SALIDAS IA\ARCHIVO OTS INDUSTEC\<ZONA>\<CADENA>\` (como se llevaba en Drive), simula por defecto, copiar→verificar por hash, nunca sobreescribe un divergente ni borra un huérfano. **`t2_18_pruebas.py` sobre un árbol sintético: 6/6 en verde** (simulación no escribe, copia y aplana bien, ignora `_ORIGEN_BUZON`, idempotente, detecta divergente sin pisar, detecta huérfano sin borrar) — no se pudo correr contra `D:\RESPALDOS` real porque esa carpeta no existe en el PC. `t2_18_atender_pedidos_copia.py` + `hostinger_ssh.scp_subir()` (nuevo): atiende `ot_archivo_solicitudes` («pedir copia») subiendo el PDF puntual a darkviolet y reindexando; **corrido en modo simulación de verdad contra darkviolet el 2026-09-13 desde el PC** (solo lectura, ninguna fila tocada): **1 solicitud real pendiente**, `OT-2503-K146-10354374-CNLJ` (origen CORREO, sin `fuente_ruta` — nunca se descargó del buzón; ni este script ni el clasificado la pueden resolver hoy, hace falta bajarla con `t2_11_informes_ot.py` o pedirla al local). No sube el histórico completo (sigue siendo D2, decisión de Andrés, no tocada) |
| **Datos de prueba retirados del sitio de pruebas (pasos 2 y 3 de `ANTES_DE_EMPEZAR.md`)** | ✅ **2026-09-13 (noche)**, a pedido explícito de Andrés | Auditado primero: 5 cuentas de prueba (`tec_prueba_uio_a/b`, `jefe_prueba_uio`, `jefe_prueba_cnlj`, `admin_prueba`) activas; 2 casos **reales** de UIO (avisos `10353767` y `10353788`) mal asignados a `tec_prueba_uio_a` sin existir antes en `casos_gestion`; 72 órdenes de la serie de pruebas 9000 en `ot_capturadas`, 66 ya indexadas en `ot_archivo`; 4 pendientes y 2 novedades marcados PRUEBA. Con respaldo previo (`mysqldump` manual por SSH a `~/respaldos/darkviolet_bd_antes_limpieza_prueba_20260914_022707.sql.gz`, 144 009 bytes, credenciales leídas de `nucleo/config.php` sin imprimirlas), se corrió `deshacer_prueba.php`: **5 cuentas desactivadas** (no borradas, la bitácora las referencia), **72 órdenes + 4 pendientes + 2 novedades + 2 avisos sintéticos `9999xxxx` borrados**, `catalogos/tecnicos.json` restaurado, y los **2 casos reales devueltos a su estado de antes** (0 filas en `casos_gestion`: vuelven a estar sin abrir en el catálogo del buzón, para que el jefe de zona los asigne de verdad; ningún dato real se borró). **Hallazgo de paso:** `deshacer_prueba.php` no limpiaba `ot_archivo` — las 66 filas ya indexadas quedaban con el PDF ya borrado del disco (enlace roto). Corregido en el script (commit `3a5b284` en `pc/pulido-2026-09-12`, subido a GitHub) y aplicado también a mano en el servidor con el mismo alcance verificado (66 de 66). **Verificado después:** 0 cuentas de prueba activas de 5, 0 filas en `ot_capturadas`, `ot_archivo` con solo sus 143 filas reales (`origen=CORREO`), 0 pendientes/novedades/equipos_propuestos de prueba. La decisión del paso 3 (dejar o borrar las órdenes 90xx) quedó **resuelta: se borran**; el contador `correlativos` (`CORRECTIVO:UIO`) se dejó en 9072 a propósito, por ser solo un contador sin dato de cliente — el próximo uso de prueba sigue en 9073 sin chocar con la numeración real. Detalle en `desarrollo/sistema_ots/piloto/ANTES_DE_EMPEZAR.md` §2–3 |
| Espejo verificado de Hostinger (`t2_4_sync_hostinger.py`) | ⏸ escrito, **falta SSH** | 13 pruebas de parseo en verde. Rutas corregidas a `ot/produccion/` |
| Purga con cuatro compuertas (`t2_4_purga_hostinger.py`) | ⏸ escrito, **falta SSH** | Simula por defecto; sin copia local verificada no borra ni con `--ejecutar` |
| Normalización al árbol canónico (`t2_4_normalizar_nuevas.py`) | ✅ probado en seco | **98,4%** sobre los 1.952 nombres reales |
| Catálogo para INDUSTEC (`t2_4_publicar_ots.py`) | ✅ **corrido** | 7.069 órdenes publicadas, 0 sin PDF localizable |
| Catálogos del formulario (`t2_5_catalogos.py`) | ✅ **corrido** | 100 locales · 1.173 activos en 94 locales · 222 tipos · 19 técnicos |
| Reglas del formato único (`t2_5_validacion.py` + `Validacion.php` + `publico/reglas.js`) | ✅ | **32 casos del fixture pasan en Python, PHP y JS** |
| **Modo sin conexión (PWA)** | ✅ **probado 2026-09-08** | `sw.js` + `manifest.json` + `offline.js`. Con el servidor **apagado de verdad**: abre, carga 100 locales y 918 órdenes desde la copia local, firma y valida, y avisa de cuándo son los datos. **Todavía NO encola envíos**: enviar necesita señal. Prueba: `app/pruebas/prueba_offline.mjs` |
| **Etapa 1 — usuarios, roles, permisos, sesión única** | ✅ **INSTALADA Y EN USO** en el sitio de pruebas | Base `industec_app` (separada del archivo histórico). 4 roles: SUPERADMIN 17 permisos · ADMIN 16 · JEFE_ZONA 10 · TECNICO 4. El alcance por zona filtra **en el servidor**, no escondiendo botones. Base `u671729428_ots`. **Verificado el 2026-09-08:** `nucleo/` da 403 (las credenciales no son alcanzables), `panel.php` sin sesión redirige al login, `instalar.php` borrado (404). Los 2 superadministradores creados con claves generadas que **nunca pasaron por un archivo ni por el chat**. Pasos en `SALIDAS IA\OTS\PASOS_INSTALAR_ETAPA1.md`. **Módulo de usuarios en uso**: la administradora ya existe y se comprobó que no ve ni gestiona superadministradores. Escalada de privilegios cerrada: un POST directo pidiendo crear un SUPERADMIN se rechaza en el servidor |
| **App desplegada en el sitio de pruebas** | ✅ **2026-09-08** | `darkviolet-armadillo-872352.hostingersite.com/ot/` — sitio **nuevo, sin WordPress** (se descartó `darkorchid`, que traía WP 7.1 con `wp-login` abierto y la REST API exponiendo el usuario admin). Formulario y cronograma cargando con los datos reales. **Los JSON de catálogos dan 403**: solo salen por `catalogos.php` |
| **Rediseño y migración 007 en el sitio de pruebas** | ✅ **2026-09-11**, desde el PC | La 007 se aplicó con `aplicar_sql.php` y se corrió dos veces sin duplicar: `verificar_esquema.php` dice «permisos (con la 007)» y TODO OK, y pasan las 20 comprobaciones de los dos bloques del pie (las pruebas de escritura, revertidas). Subieron los 48 archivos **confirmados** del rediseño —no el trabajo a medias de otras conversaciones— con `t2_10`, verificados por hash; `php -l` 28 de 28 en el servidor; 38 rutas sin sesión con el código esperado. Respaldo previo de la base y del sitio en `~/respaldos/`. Falta la verificación por rol (T2.12.4 a T2.12.6) |
| **Verificación por rol en el sitio de pruebas** | ✅ **2026-09-11**, desde el PC | Con cuentas de prueba (`app/pruebas/servidor/`): las 13 pantallas abren para administración, jefe y técnico sin errores de PHP; cada jefe ve solo su zona (UIO 255 y CNLJ 356 casos, sin solaparse, contra 910 de la administración) aunque pida otra por la URL; el técnico ve exactamente sus casos; un POST fabricado contra otra zona no cambia nada y queda en la bitácora; la orden de la app llega una sola vez, firmada con el nombre de la sesión, y la de otro técnico da 409; el ciclo asignar → trabado → veredicto → vía → resuelto deja el caso en ASIGNADO y la reconciliación no lo pisa. **Se encontró y corrigió** que el veredicto, el cierre de un pendiente, la resolución de novedades y el «cerrado en SAP» de `casos.php` daban **error 500 en Hostinger** por la intercalación de la conexión (`NULLIF(?, '')`); en la estación no se veía. Con un navegador de verdad (Edge por CDP, `prueba_cola_vivo.mjs`): sin servidor la orden queda guardada en el celular y sale sola al volver la señal, una sola vez; con la sesión desplazada queda «falta entrar» y sale al volver a entrar (T2.12.7 y T2.12.8). **El CDN de Hostinger entregaba el `sw.js` v2 y el `estilo.css` anterior** en su copia comprimida —la que reciben los navegadores— horas después de subir los nuevos: corregido con `no-cache` para el código en el `.htaccess` y una purga del CDN hecha por Andrés; `t2_10` ahora compara lo que entrega la web en sus dos variantes |
| **La hora de Ecuador en el sitio de pruebas** | ✅ **2026-09-11**, desde el PC | T2.13.7: la base y el PHP corrían en UTC (una orden de las 19:30 caía «mañana» y el «hace 3 h» salía corrido cinco horas). `Db.php` fija ahora las dos zonas, y las fechas que el servidor había guardado en UTC se corrieron −5 h una sola vez con `hora_ecuador.php`, con respaldo previo en `~/respaldos/`; `atendido_en`, que llega del informe en hora local, no se tocó. Verificado: PHP y la base dan la misma hora, ninguna fecha quedó en el futuro, el recibo de la orden sale con la hora local y las 87 comprobaciones por rol siguen pasando |
| **La bandeja, el historial y el formulario del técnico, desde la base** | ✅ **2026-09-11**, desde el PC | T2.13.2 y T2.13.3: el formulario le ofrece al técnico solo sus casos abiertos (ASIGNADO o ESPERA_REPUESTO), y la bandeja y el historial salen de `casos_gestion`, no del catálogo del buzón: los 4 casos asignados que habían quedado fuera de él ya los ven sus técnicos, con «sin dato en el catálogo», y se pueden reportar trabados y emitir sin la marca «fuera de alcance». El historial suma las órdenes enviadas desde la app (dice que el PDF todavía no sale de ahí) y la ficha de un caso cerrado muestra su orden de cierre o dice que no hay. «Historial» en la barra del técnico. 19 comprobaciones nuevas con avisos sintéticos (`verificar_bandeja.py`); las 87 anteriores siguen pasando |
| **El buzón de avisos del técnico** | ✅ **2026-09-11**, desde el PC | T2.13.5, sin tabla nueva: la pestaña «Avisos» de su bandeja, con globo, y una barra que avisa sin recargar le cuentan lo que hoy le llega por WhatsApp, cuando le llega —le asignaron o le quitaron un caso, le respondieron o decidieron sobre un equipo trabado, le resolvieron una novedad—, desde lo que el sistema ya registra; «visto hasta» es su último ingreso a la bandeja. «Te quitaron un caso» solo se detecta si la asignación anterior pasó por la pantalla de casos (las del automatismo no dejan rastro de quién lo tenía). 10 comprobaciones nuevas en `verificar_bandeja.py` (29 en total) y las 87 por rol siguen pasando |
| **El cronograma del técnico** | ✅ **2026-09-11**, desde el PC | T2.13.4: el cronograma ya no le manda al técnico el padrón de técnicos ni el maestro de locales —nombres del personal y correos de cada local que viajaban a su celular sin que nada los usara—, y en el celular lleva la misma barra de abajo que su bandeja, sin «agendar un local». `prueba_barra_tecnico.mjs` comprueba que esa barra sea la misma que la de `mis.php`, y `verificar_bandeja.py`, el criterio del PLAN contra el servidor (32 comprobaciones) |
| **La web corporativa de INDUSTEC, en la raíz de darkviolet** | ✅ **2026-09-11**, desde el PC | La web remodelada de www.industec.me está publicada en la raíz del dominio temporal: inicio, nosotros, servicios, contacto y `/acceso/`, el ingreso del personal al sistema de gestión, con botones a `/ot/login.php` y `/ot/`. **Segunda entrega, el mismo día**, por el pedido de César (proveedor técnico de cadenas; nada que se lea como servicio para el hogar): «A quién servimos», la línea «No reparamos equipos domésticos ni atendemos a particulares», y los logos de los 20 clientes (KFC primero) y de las marcas de equipo profesional en portada y servicios. **Ronda 3 (11-sep-2026, tarde), por pedido directo de Andrés sobre el resultado**: la portada cambia otra vez, de la foto a una **ilustración propia** de la cocina de un local de cadena (línea caliente, línea fría, ventilación y un técnico con multímetro), elegida por jueces entre 3 gradaciones de foto y 2 ilustraciones; la foto real se conserva en «Nuestro trabajo» de servicios y en contacto, con otro tratamiento de color. La guía «¿Qué servicio necesitas?» de servicios pasa a una lista de decisiones, y contacto se rediseña como una conversación con INDUSTEC. Samsung, LG y Westinghouse, retiradas unas horas, quedan repuestas (18 marcas). `/acceso/` ya nombra «B.IA Soft ERP». Textos de Valentina, construcción de Steven y varias rondas de revisión. Solo datos públicos: lo que espera el visto bueno de César está en `desarrollo/web_corporativa/NOTAS_PARA_CESAR.md`. Verificado: los archivos del sitio cuadran por hash en el disco del servidor y por la web, salvo los PNG y JPG, que el CDN recomprime; `/ot/login.php` responde 200 y la raíz sigue sin `.htaccess`. En el dominio temporal, Hostinger sirve su propio `robots.txt` (a Googlebot le cierra todo, al resto no): mientras tanto protege el `noindex` de cada página. Detalle en `desarrollo/web_corporativa/LEEME.md` §8 y §9 |
| **La orden sale de la app con su número, su PDF y su correo en cola (la 008)** | ✅ **2026-09-11**, desde el PC, en el sitio de pruebas | T2.13 por el camino (a): las fotos suben de una en una antes que la orden (`foto.php`, recodificadas sin EXIF), el servidor reserva el correlativo con una sentencia atómica, genera el PDF con dompdf y la plantilla de producción —con las fotos, la firma y quién firmó— y deja el correo en `email_queue`. **En el sitio de pruebas** la serie arranca en 9000, el PDF lleva la franja «DOCUMENTO DE PRUEBA» y el correo queda RETENIDO: no sale nunca, porque iría al local, a Grupo KFC y al buzón de la administradora, que se lee solo. dompdf va en `~/lib/ot`, fuera de la carpeta web (`app/lib/LEEME.md`). Verificado con `verificar_emision.py` (29 comprobaciones, entre ellas 10 reservas simultáneas sin repetir, T2.1.5); las 119 anteriores siguen pasando. **Falta para producción:** el despachador de la cola (PHPMailer) y cargar los contadores reales y los destinatarios en el corte |
| **Captura — formulario único, v1 para revisión** (`sistema_ots/app/publico/`) | ✅ **corre en local** | Unifica los 3 formularios de hoy. El técnico **no teclea ningún número**: elige de sus órdenes. Local por buscador, listas cerradas, repuesto por casilla. **No persiste, no genera PDF, no envía correo todavía.** Reseña en `SALIDAS IA\OTS\REVISION_CAPTURA_v1.md` |
| **Lector del buzón SAP** (`t2_6_imap_avisos.py`) | ✅ **corrido** | IMAP **de solo lectura** (EXAMINE + BODY.PEEK, no marca leído). 918 casos vivos de 90 días, 7 órdenes eliminadas excluidas, 99,8% resuelve local. Credencial en `config/.env` |
| **Filtro de alcance** (`config/alcance_trabajos.json`) | ✅ | El alcance como dato editable, no como código. **Levanta alertas, no decide**: el veredicto es de la administradora. 11 alertas sobre 918 casos |
| **Cronograma de preventivos** (`t2_7_cronograma_preventivo.py` + `cronograma.html`) | ✅ **corre en local** | Convierte el Excel de la administración (fechas como frases) a fechas reales: **352 de 368 ingresos, 95,7%**. Calendario con alertas de 3 días. **68 vencidos, 316 sin kit** |
| **Padrón de técnicos al día** (`t2_8_padron_tecnicos.py` + `sql/004`, `sql/005`) | ✅ **cargado y verificado 2026-09-09** | 40 personas: **19 vigentes** (CNLJ 7 · LARB 6 · UIO 6, con 3 jefes de zona) + 21 que salieron. Distingue las tres situaciones: sigue trabajando / salió tal día / salió sin fecha registrada. **Corrige un hallazgo del proyecto**: el catálogo viejo tenía 19 filas todas activas, y por eso 1.362 órdenes figuraban «fuera de nómina» cuando su autor trabaja hoy. El nombre de usuario se **calcula** del padrón y se compara contra los 19 aprobados: si el Excel cambia un apellido, aborta. Idempotente en 3 corridas |
| **Alta masiva de los 19 usuarios** (`alta_padron.php`) | ✅ **probada en local, lista para subir** | Lee `nucleo/padron.json` (fuera de git: son nombres de personal). Revalida rol, rango y zona fila por fila. **Probada con un padrón alterado** que pedía SUPERADMIN y ADMIN: rechazados, 0 creados; el jefe de zona ni siquiera abre la pantalla (403). Muestra las 19 contraseñas una sola vez y obliga a cambiarlas. Paquete en `SALIDAS IA\OTS\paquete_alta_padron` |
| **Buzón operativo: asignar, derivar, veredicto, cierre** (`casos.php`, `asignacion.php`, `nucleo/Casos.php`) | ✅ **en uso 2026-09-09** | Las acciones **persisten** en `casos_gestion` (clave: el aviso). Cada una revalida permiso, alcance y dato **en el servidor**; probado: técnico asignando → «no tienes permiso», jefe con técnico de otra zona → rechazado, jefe con caso de otra zona → «fuera de tu alcance». El cierre es **de dos manos**: el sistema marca ATENDIDO al ver la orden, la administradora confirma que lo cerró en SAP |
| **Órdenes emitidas y visor de PDF** (`ordenes.php`, `pdf.php`) | ✅ **en uso 2026-09-09** | 116 informes en el servidor, **fuera del alcance web**. El técnico ve solo las suyas: probado que pedir el PDF de otro da 403, recorrido de rutas 400, sin sesión 302, archivo directo 403. **Enlace compartible firmado y con caducidad de 24 h** para mandarle el informe al local por correo o WhatsApp: firma alterada 403, caducidad movida 403, firma reusada en otra orden 403. Cada apertura queda registrada, también las de enlace |
| **Cierre por falta de atención** (`nucleo/Reconciliar.php`) | ✅ **corrido 2026-09-09** | **735 casos** de más de una semana sin ningún informe, cerrados y marcados como pendiente de regularizar. El número se verificó por dos vías independientes antes de escribir; los 7 sin fecha de creación quedaron fuera. Los que esperan repuesto quedan excluidos solos: esos **sí** tienen informe |
| **Bitácora minable** (`sql/005`) | ✅ **2026-09-09** | Cada fila es una transición (estado antes → después), con los datos en JSON y una marca de éxito/rechazo. Las seis consultas de detección están escritas y **corridas**: saltos de estado imposibles, casos que rebotan de técnico, intentos rechazados por persona, ráfagas, actividad fuera de horario, veredictos sin revisión previa |
| **Qué casos ya se atendieron y por quién** (`t2_11_informes_ot.py`) | ✅ **corriendo 2026-09-09** · **temporal** | Lee los informes de OT que llegan al mismo buzón desde `reclutamiento@industec.me` y cruza por `ORDEN SAP`. **114 de los 917 pendientes ya se atendieron; 30 tienen orden de cierre.** El técnico sale del PDF: se bajan solo los 123 que tocan un caso pendiente, no los 584. **123 de 123 firmas identificadas**, resolviendo los casos en que escriben varios nombres en un solo campo (`Sergio Torres, Vinicio Campos`). Corre cada 3 horas con caché por OT. Se elimina cuando la emisión pase al sistema nuevo |
| **Sincronización en vivo del buzón** (`t2_9_buzon_vigilante.py` + `sync_casos.php` + `novedades.php`) | ✅ **corriendo 2026-09-09** | IMAP **IDLE** (no sondeo): el servidor avisa y la estación reacciona en segundos. Sigue siendo SOLO LECTURA — envuelve a `t2_6` en vez de reescribirlo. Empuje firmado HMAC-SHA256 con el timestamp dentro de la firma; probado: legítimo 200, sin firma 401, firma falsa 401, **reenvío de hace 1 h 401**, GET 405, vacío 409. La clave del correo **no viaja**: se queda en la estación. Corre por Tarea programada al iniciar sesión, con reintento cada 5 min. Extremo a extremo: correo→estación segundos, barrido ~1 min, pantalla ≤30 s |
| **Despliegue por SSH** (`t2_10_desplegar.py`) | ✅ **en uso 2026-09-09** | `u671729428@82.25.73.181:65002`, llave ed25519. Sube y **verifica por hash**. Se acabó el administrador de archivos, que no sobreescribe y ya causó dos fallos. La ruta de destino es compuerta que aborta: apuntado a `yellow-elephant` corta antes de conectar. `nucleo/config.php` es lo único intocable del sitio de pruebas |
| **Buzón de casos** (`casos.php`) | ✅ **probado en local, listo para subir** | Muestra los 918 casos vigentes del correo. **Alcance por zona en el servidor**: administradora 918, jefe UIO 258, jefe CNLJ 360; pedir otra zona por la URL da 0 filas y 0 fugas. **El técnico ve solo lo suyo** — se detectó probando que veía los 300 de su zona con el usuario de KFC de cada caso; corregido. Botones de la fase siguiente apagados, con lo que hará cada uno escrito. La cifra de «911 pasados de fecha» se bajó de titular a nota, porque no es un atraso: SAP compromete para el día siguiente y el correo no avisa cierres. Paquete en `SALIDAS IA\OTS\paquete_buzon` |
| **MariaDB como servicio de Windows** | ✅ **2026-09-09** | No estaba registrado: había que arrancarlo a mano y tras un reinicio la estación quedaba sin base. Ahora `MariaDB` arranca con Windows (`StartType Automatic`) |
| **Rediseño completo de las interfaces** (`estilo.css`, `nucleo/Ui.php`, `ui.js`) | ✅ **2026-09-10** · ⏸ sin desplegar | Un solo sistema de diseño para las 13 pantallas: antes cada una copiaba en su propio `<style>` la barra, las tarjetas de cifras, la tabla y los avisos — **seis copias** que se desincronizaban, y no había forma de saltar de un módulo a otro sin volver al panel. Ahora la barra y la navegación por rol las sirve `Ui::cabecera()`. Avisos con tres formas y su momento: fijo si hay que actuar, efímero si solo hay que enterarse, diálogo si no se deshace. **Un error nunca va en aviso efímero.** Animaciones con `prefers-reduced-motion` respetado. Reseña completa en [`SALIDAS IA\OTS\REDISENO_INTERFACES.md`](SALIDAS%20IA/OTS/REDISENO_INTERFACES.md) |
| **Tablero de trabajo por rol** (`panel.php` reescrito) | ✅ **2026-09-10** · ⏸ sin desplegar | Dejó de ser una rejilla con el nombre de cada módulo. Ahora arranca con **«lo que te toca ahora»**: cada línea es una acción que solo esa persona puede resolver, con el enlace que la deja delante de esos casos y no de la lista completa. El técnico ni entra: se le redirige a su bandeja |
| **App del técnico: bandeja móvil y envío sin señal** (`mis.php`, `cola.js`, `envio.php`, `yo.php`) | ✅ **2026-09-10** · ⏸ sin desplegar | Bandeja tipo correo con tres pestañas —Pendientes / Esperando / Atendidas— y **cola de envíos en IndexedDB**: la orden se guarda ENTERA en el celular *antes* del primer intento y sale sola al reconectar. Idempotente por un **UUID que genera el celular** y no cambia entre reintentos: es lo único que distingue «la mandó dos veces» de «se reintentó el mismo envío». Un 4xx no se reintenta (la orden no sirve); un 5xx sí. **El técnico ya no elige su nombre**: sale de la sesión, y `envio.php` la vuelve a tomar de la sesión al recibir, así que editar el HTML no cambia quién firma |
| **Equipos deshabilitados: el reloj de 48 h y los cuatro veredictos** (`pendientes.php`, `nucleo/Pendientes.php`) | ✅ **2026-09-10** · ⏸ **falta aplicar la 007** | La regla del negocio, hecha sistema: una intervención concluye el trabajo, y si un equipo queda deshabilitado hay **48 horas** para decidir entre **repuesto, reparación, garantía o baja**. El plazo mide **la decisión**, no la reparación completa — una garantía puede tardar semanas sin que sea incumplimiento. Lo que sigue sin veredicto y ya se pasó **cuenta como incumplido ahora**, o el indicador mejoraría solo con no decidir. El jefe de zona da el veredicto (está en el terreno); la administración lo ejecuta (no compra ni da de baja un activo del cliente) |
| **Insistencias con fecha, en vez de WhatsApp** (`pendiente_notas`) | ✅ **2026-09-10** · ⏸ falta la 007 | El hilo de cada pendiente: quién preguntó, cuándo y cuántas veces. Es lo que hoy se pierde y lo que permite responderle a Grupo KFC por qué un caso lleva tres semanas. **Sin UNIQUE de negocio a propósito** —excepción razonada a I-9—: insistir dos veces es el dato, no un duplicado. El doble toque accidental se corta en la aplicación, a 90 segundos |
| **Novedades del preventivo hacia otras áreas** (`novedades.php`, `nucleo/Novedades.php`) | ✅ **2026-09-10** · ⏸ falta la 007 | Lo que el técnico ve en la visita y no era su orden: el correctivo que se viene, y lo de **otras áreas** —eléctrico, ventilación, desagüe, obra civil— que hace fallar los equipos una y otra vez. El técnico **propone** de quién es; el jefe de zona o la administración **deciden**. Si se le pide el aviso a KFC, el número vuelve aquí: es la prueba de que se avisó y cuándo. El filtro «no es de INDUSTEC» es el que sostiene esa conversación con el cliente |
| **Formulario guiado y preguntado por pasos** (`guia.js`, `index.html`) | ✅ **2026-09-10** · ⏸ sin desplegar | Doce secciones y 40 campos pasaron a pasos que se abren según lo respondido, y **lo primero que se pregunta es qué va a hacer**, porque de eso depende todo lo demás. El formulario ahora pregunta **si el trabajo quedó concluido** y, si no, exige el diagnóstico. Es **mejora progresiva**: `guia.js` carga después de `app.js` y solo envuelve lo que ya funciona — si no carga, sale el formulario de siempre, entero |
| **Reportes gráficos** (`reportes.php`, `graficos.js`) | ✅ **2026-09-10** · ⏸ sin desplegar | Barras, columnas, anillo y apilada en **SVG escrito a mano, sin librería**: la política es software libre y lo mínimo, son datos de un cliente, y tiene que dibujarse sin señal. **La paleta pasó las seis comprobaciones** del método (luminosidad, croma, daltonismo protan/deutan/tritán, visión normal y contraste); las zonas contra **todos** los pares, porque conviven en un gráfico. Todo gráfico lleva **su tabla debajo**. Primero el indicador del negocio —**% que se concluye en una visita**— y solo después el volumen |
| **La pantalla de asignación salía sin estilos** (`asignacion.php`, `estilo.css` §22) | ✅ **2026-09-11** · **desplegada el 2026-09-12** | El rediseño del 2026-09-10 recogió los `<style>` de cada página en `estilo.css` y dejó fuera las clases de esta: `.equipo`, `.persona`, `.cifras`, `.cifra` y `.asignar` **no existían**, así que las 23 personas del equipo salían como una columna de texto plano y «14abiertos» pegado. No daba ningún error. Ahora cada técnico es una tarjeta con su barra de carga —medida **contra el más cargado del día**, no contra un tope inventado— y las dos cifras alineadas al fondo, para que la fila se lea de un barrido. Los dos extremos llevan **la palabra además del color** («libre», «cargado»), que es la regla 1 de la hoja. Arriba, las cuatro cifras con que se decide: por repartir, sin nada abierto, con 8 o más, y en manos del equipo. **Verificado:** `php -l` limpio, las **212 comprobaciones** de las tres baterías en verde (96 · 62 · 54, 0 fallos), y la pantalla renderizada con datos de prueba a 1400 px y a 400 px sin desborde horizontal. **Verificado contra la web, no contra el disco del servidor:** `estilo.css` sale con **73.725 bytes y SHA idéntico** al archivo local, comprimido y sin comprimir, con `Cache-Control: no-cache` y el CDN en `MISS` |
| **Buscador por coincidencia parcial** (`busqueda.js`, `Ui::normalizarBusqueda`, `casos.php`, `ordenes.php`) | ✅ **2026-09-12** · **desplegado y verificado** | Escribir `2466` encuentra `OT-2466-V093-…`, y el aviso da igual con o sin los ceros de SAP. Se commiteó **con tres defectos corregidos** que encontró la auditoría: **(1) los gemelos no eran gemelos.** El JS usaba `normalize('NFD')`, que cubre toda letra acentuada; el PHP, una tabla a mano de **16 entradas** que no incluía `â ê î ô û ã õ ç` — y el filtro final las **borraba**. Medido: `Sâo Paulo` daba `saopaulo` en el navegador y `sopaulo` en el servidor; `François` → `francois` / `franois`. El síntoma era el peor para una mesa de servicio: escribes y salen tres resultados, recargas y salen otros, y la URL compartida no muestra lo que vio quien la mandó. La tabla del PHP ahora tiene **123 entradas generadas en tiempo de desarrollo desde la misma descomposición NFD que usa el JS**, así que coinciden por construcción; **no se usa `Normalizer` en ejecución** porque exige la extensión `intl`, que no está garantizada en Hostinger y habría hecho que el servidor se comportara distinto solo allí. **(2) la prueba daba falsa confianza:** comparaba el JS contra una reimplementación del PHP escrita **en JS**, así que pasaba con la tabla rota. Ahora **ejecuta el PHP de verdad** sobre 138 muestras —una por cada uno de los 122 acentos de la tabla, más los casos límite— y si no encuentra PHP **falla** en vez de pasar de largo (I-7). **(3) asimetría en `ordenes.php`:** el servidor comparaba 6 campos y el `data-b` 7 (le faltaba `caso`), con un comentario que afirmaba lo contrario. Corregido, y con prueba que compara las dos listas. **`prueba_contratos.mjs`: 57 · 0.** Las dos comprobaciones nuevas se validaron rompiéndolas a propósito: sin la entrada de `â` la prueba nombra `"Sâo Paulo": JS=saopaulo PHP=sopaulo`, y sin `caso` nombra las dos listas de campos. **Desplegado el 2026-09-12** (`busqueda.js`, `nucleo/Ui.php`, `casos.php`, `ordenes.php`, `sw.js`) y comprobado: `busqueda.js` con hash idéntico al local, `nucleo/Ui.php` **403** —el núcleo compartido sigue inalcanzable—, `config.php` 403, `catalogos/locales.json` 403, y las pantallas de gestión en 302 sin sesión. `busqueda.js` **no va en `PRECARGA`** a propósito: solo lo usan pantallas de escritorio, que no funcionan sin señal, así que va a la red y no necesita subir la versión del trabajador de servicio. **Desfase del `sw.js` cerrado:** el repositorio pasó a **v7**, que es lo que el servidor corre desde este mismo día |
| **La contraseña temporal salía en texto corrido** (`estilo.css` §23) | ✅ **2026-09-12** · desplegado | **No era una sola pantalla: eran dos.** El cruce de clases usadas contra definidas se hizo mal la primera vez y dio un falso «era la única». `usuarios.php:171` muestra con `.clave` la contraseña recién generada de un técnico, y esa clase solo existía dentro del `<style>` de `alta_padron.php` e `instalar.php`; `usuarios.php` no tiene `<style>`, así que salía en tipografía normal. Es la **única cadena del sistema que una persona copia a mano**, se muestra una sola vez —la base solo guarda el hash— y en texto corrido `l`/`1`/`I` y `O`/`0` se confunden. Ahora va monoespaciada, con `letter-spacing` y `user-select:all`. El resto del cruce sí se sostiene: `cronograma.css` cubre el calendario, y `login`, `clave`, `alta_padron` e `instalar` conservan su `<style>`; queda `.proximo` en `casos.php`, un `div` sin consecuencia visual |
| **El trabajador de servicio servía la hoja vieja** (`sw.js` v5 → **v7**) | ✅ **2026-09-12** · desplegado | `estilo.css` está en `PRECARGA` y se sirve **cache-first**: sin subir `VERSION`, todo navegador con la aplicación instalada seguía pintando la hoja anterior, sin dar ningún error. Se subió **partiendo del `sw.js` de `origin/pc/auditoria-2026-09-10`**, que es byte a byte lo que está desplegado — no del de `master`, que está 29 commits atrás y lo habría revertido. Comprobado antes de subir que `activate` solo borra cachés y **nunca toca IndexedDB**: la cola de envíos del técnico sobrevive. Dos subidas: v6 con la hoja, y **v7** al corregir el `hover` muerto de `.persona`. Verificado contra la web, no contra el disco: `estilo.css` sale con **73.725 bytes y SHA idéntico** al archivo local, y `sw.js` en v7. **Desfase cerrado el mismo día:** el repositorio también dice v7, así que el archivo versionado y el que corre el servidor son el mismo. `busqueda.js`, que se desplegó después, **no** va en `PRECARGA` y por eso no hizo falta una v8 |
| 🔴 **Dos endpoints entregaban datos del cliente sin sesión** | ✅ **Cerrados en el código el 10-sep, y en el servidor recién el 10-sep ~20:00**: hasta entonces seguían respondiendo 200 sin sesión (1,1 MB y 505 KB). Ver [`AUDITORIA_2026-09-10.md`](AUDITORIA_2026-09-10.md) §2 | `catalogos.php` servía los 100 locales con su correo, los 1.173 activos y **los nombres de los 19 técnicos** a cualquiera con la URL. `cronograma.php`, además, el cronograma completo, el padrón y **los 918 casos vivos con sus alertas** — y **sin filtrar por zona**. El mismo error las dos veces: el `.htaccess` cerró los `.json` y se dio por hecho que el `.php` que los sirve estaba cubierto. Ahora exigen sesión y el cronograma recorta por zona **en el servidor**. Datos personales del personal y correos del cliente: cuenta para el riesgo LOPDP ya registrado. Revisado y **correcto**: `aplicar_sql.php`, `verificar_esquema.php` y `minar.php` cortan con 404 fuera de la línea de órdenes, y `sync_casos.php` tiene su HMAC |
| **Cuatro defectos propios, encontrados al revisar y corregidos** | ✅ **2026-09-10** | Los cuatro fallaban **en silencio**. (1) La pantalla de novedades **sobrescribió `novedades.php`**, que ya estaba en uso como el extremo que consulta el buzón cada 30 s: `ui.js` recibía HTML donde esperaba JSON y la barra de «el buzón se actualizó» dejó de aparecer. Restaurado desde git; la pantalla pasó a `novedades_visita.php`. (2) `guia.js` se tragaba el **botón de enviar** dentro del último paso plegado: el técnico llenaba la orden y no tenía dónde pulsar. (3) El contenedor de novedades del formulario compartía el id `#novedades` con la barra del buzón, y a los 30 s le ponía `hidden` a lo que el técnico acababa de escribir. (4) Un **401 por sesión caducada** se trataba como «orden inválida» y no se reintentaba — veinte minutos de trabajo perdidos por volver a entrar. Se escribió `pruebas/prueba_contratos.mjs` (48 comprobaciones) para esa clase de defecto |
| **Tres suites de prueba nuevas** | ✅ **2026-09-10** | `prueba_48h.php` **96 comprobaciones · 0 fallos** (el reloj mide el veredicto y no la reparación; lo vencido cuenta ahora; ningún estado sale en crudo; el rojo de la antigüedad cae en el corte de 7 días). `prueba_graficos.mjs` **62 · 0** (total cero, un dato, negativos, basura, JSON roto, 8 porciones plegadas, el color sigue a la entidad). `prueba_contratos.mjs` **48 · 0** (los acuerdos entre JS y pantallas, y que los extremos con datos del cliente sigan exigiendo sesión). **Nada se abrió contra datos reales**: no hay base levantada en esta máquina, así que la verificación con los tres roles sigue pendiente en el sitio de pruebas |
| **`Casos::etiquetaEstado()` mostraba estados en crudo** | ✅ **2026-09-10** | Le faltaban `ATENDIDO` y `CERRADO_SIN_ATENCION`, así que esos casos salían como «CERRADO_SIN_ATENCION», en mayúsculas y con guion bajo, en la pantalla que mira la administradora. Ahora hay **una sola lista**, en `Ui::ESTADOS`, con los ocho estados y su explicación |
| **`ESPERA_REPUESTO` pasa a `Reconciliar::INTOCABLES`** | ✅ **2026-09-10** | Sin esto, la reconciliación habría marcado ATENDIDO al caso que espera una pieza —porque ese caso **sí** tiene informe: el técnico fue y diagnosticó— y la administradora lo habría cerrado en SAP con el equipo todavía parado |
| ⚠️ **El buzón solo trae la mitad de los casos de SAP** | ✅ **causa identificada el 2026-09-10** | Cotejado contra `ORDENES ABIERTAS EN SAP.xlsx`: SAP tiene **62 órdenes abiertas** y el buzón capturó **31**. **Hipótesis principal descartada**: los **213 avisos originales de SIR** desde el 20-ago llevan `servicioalcliente@industec.me` en el destinatario, el **100%**. **La causa (aportada por Andrés y respaldada por los datos): SIR avisa cuando se *crea* una orden, no cuando se *reabre*.** Buena parte de estos 31 son reaperturas —volver a cerrar tras poner el repuesto, o documentar un trabajo hecho—: de los 33 abiertos con OT, en **ninguno** la OT es anterior al aviso (mismo día o +1 a +4). INDUSTEC los trabaja igual: **27 de 31 ya tienen OT emitida**; solo **4 están sueltos de verdad**. El dato compartido **coincide 31 de 31**. El flujo correcto ya existe: el técnico cierra con su informe → el sistema avisa a la administradora para cerrar en SAP. Ver [`SALIDAS IA\OTS\COTEJO_SAP_ABIERTAS_2026-09-10.md`](SALIDAS%20IA/OTS/COTEJO_SAP_ABIERTAS_2026-09-10.md) |
| **La reconciliación de los 735, validada contra SAP** | ✅ **2026-09-10** | **Cero** de los 735 casos cerrados por falta de atención figura abierto en SAP. La regla de «más de una semana sin ningún informe» no se llevó por delante ningún caso vivo |

**Arquitectura decidida el 2026-09-08:** la **base operativa vive en Hostinger**
(usuarios, casos abiertos, asignaciones, cronograma vigente) y la **memoria
completa y el análisis en la estación** (7.069 órdenes, cruces con SAP, KPIs,
PDFs). La app de administración es **web**, servida por Hostinger: no se instala
nada y entran desde cualquier computador. Los jefes técnicos están en Ambato y
Cuenca, así que la base en la estación habría exigido VPN y que este equipo
estuviera siempre encendido.

**La decisión completa, cruzada con la LOPDP, está en
[`DECISION_ARQUITECTURA_Y_DATOS.md`](DECISION_ARQUITECTURA_Y_DATOS.md)** — manda
sobre las demás. Sus tres puntos que no dependen de programar nada y son los que
más bajan el riesgo: **cerrar la exposición de los PDFs** (verificado el
2026-09-08: no afecta en nada al sistema actual — los 5 `submit.php` mandan el
PDF como adjunto y ningún HTML enlaza a `uploads/`), **inscribir el delegado de
protección de datos de INDUSTECH** (plazo vencido hace 8 meses; encargado a otra
IA con [`BRIEF_DPD_INDUSTECH.md`](BRIEF_DPD_INDUSTECH.md)) y **firmar el
contrato de encargo con INDUSTEC**.

**El hosting se queda en INDUSTECH** (decisión del 2026-09-08: INDUSTEC no
asume responsabilidades adicionales). Eso deja vigente el riesgo del Art. 43 del
Reglamento —INDUSTECH puede ser tratada como *responsable* por elegir dónde se
alojan los datos—, y se compensa haciendo constar en el contrato que **INDUSTEC
aprueba el uso de ese hosting**, más el delegado y el registro de actividades de
tratamiento de INDUSTECH.

Roles, módulos, tableros, seguridad y el orden de las etapas:
[`PLAN_APP_GESTION.md`](PLAN_APP_GESTION.md).
| Padrón de técnicos (`t2_5_padron_tecnicos.py`) | ✅ **corrido** | 164 personas reconstruidas desde las órdenes, listas para que INDUSTEC marque |
| Consola local de revisión (`sistema_ots/local/app.py`) | ✅ **corriendo** | 7 rutas en 200; cifras iguales al estado |
| **Exposición pública de los PDFs** | ✅ **CERRADA el 2026-09-08** | `.htaccess` en `ot/produccion/`, subido por Andrés. **403 en origen y en CDN** en los 8 recursos; los 2 formularios siguen en 200. Se corrigieron las rutas del parche viejo: el sistema se había movido de `ot/pruebas/` a `ot/produccion/` |
| **`cleanup.php` borrado** | ✅ **2026-09-08** | La URL pública que borraba `uploads` y `registros` sin verificar copia → 404. **Falta revisar si algún cron lo llamaba** |
| Verificador de exposición (`verificar_exposicion.py`) | ✅ | Solo HEAD, nunca descarga. Mide **CDN y origen por separado**: el 08-sep el origen ya bloqueaba y el CDN seguía sirviendo PDFs cacheados con `Age: 974`. Medir solo con cache-buster habría dado un "cerrado" falso |
| `guardas.php` | 🔴 **listo, sin desplegar** | Requiere aprobación para tocar producción |
| **Migración `007_pendientes_y_captura.sql`** | 🔴 **escrita, sin aplicar** | Crea `pendientes`, `pendiente_notas`, `ot_capturadas` y `novedades`; agrega `ESPERA_REPUESTO` al ENUM del caso (al final, para no mover el significado de los existentes) y siete permisos nuevos. **Cambia el esquema: requiere aprobación.** Trae sus consultas de verificación al pie. Mientras no se aplique, las pantallas nuevas dicen que el módulo no está instalado y devuelven listas vacías: no revientan ni muestran un cero que se leería como «no hay nada que hacer» |
| Migración `003` | 🔴 **escrita, sin aplicar** | Cambia el esquema: requiere aprobación |

**Documentos rectores nuevos:**
[`SISTEMA_COMPLETO.md`](SISTEMA_COMPLETO.md) (el sistema de punta a punta) ·
[`ESPECIFICACION_OT_UNICA.md`](ESPECIFICACION_OT_UNICA.md) (el formato único) ·
[`ARQUITECTURA_SISTEMA_OTS.md`](ARQUITECTURA_SISTEMA_OTS.md) ·
[`DISENO_APP_OTS.md`](DISENO_APP_OTS.md)

### Lo urgente que NO depende de nadie más

| # | Qué | Por qué ahora |
|---|---|---|
| ~~1~~ | ~~Cerrar la exposición pública de los PDFs~~ | ✅ **Hecho el 2026-09-08.** Ver §1b |
| **1b** | **15 vulnerabilidades en el WordPress que está encima del sistema de OTs** | Detectadas por el propio hPanel el 2026-09-08, con WordPress 6.8.8, tema Astra y **19 plugins** sin actualizar. **El `.htaccess` no protege contra esto**: código ejecutándose en el servidor lee `ot/` igual, incluidos los PDFs y el `config.php` con la clave SMTP en texto plano |
| **2** | **Sacar del único disco lo que NO es código** | ✅ **El código ya salió:** `origin` es `github.com:AndresIndustech/industec-bia-soft-erp` y `master` quedó empujado y sincronizado el 2026-09-12 (32 commits). **Lo que sigue en un solo disco son los datos:** las **7.069 órdenes**, el árbol canónico de `D:\RESPALDOS` y la base MariaDB de la estación. Para eso la regla de las dos copias todavía **no se cumple**, y git no la resuelve: el corpus no va al repositorio. Lo desbloquea el TrueNAS (T1.9), que espera acceso físico al equipo |
| 3 | **Revisar el cron y borrar `cleanup.php`** | Es una **URL pública** que borra `uploads` y `registros` sin verificar copia. Cualquiera la dispara desde el navegador |
| 4 | **Inscribir el delegado de protección de datos de INDUSTECH ante la SPDP** | El plazo del sector privado (Resolución SPDP-SPD-2025-0028-R, Art. 10.13, servicios de TI/IA) corrió del 1-nov al 31-dic-2025 y **ya venció hace más de 8 meses**. No inscribir a tiempo ya cuenta, según la propia SPDP, como incumplimiento. **Guía paso a paso, ya escrita:** [`TRAMITE_DELEGADO_DATOS.md`](TRAMITE_DELEGADO_DATOS.md) — es gratis, en línea, y no exige certificación (esa obligación no rige hasta 2029) |

### Investigación LOPDP — hallazgo importante del 2026-09-08

Se investigó a fondo el régimen sancionatorio de la LOPDP en fuente primaria
(cuatro ángulos, cada cita verificada cruzando dos dominios `.gob.ec`
independientes). **La conclusión anterior — "hasta el 1% de la facturación" —
era demasiado suave.** Lo verificado y corregido, con todo el detalle, fuentes
y lo que quedó sin confirmar, está en
[`SISTEMA_COMPLETO.md` §5b](SISTEMA_COMPLETO.md#5b-seguridad-y-datos-personales--lo-que-este-sistema-maneja-de-verdad).
Los tres titulares:

- La **Superintendencia de Protección de Datos ya sanciona**: 4 resoluciones
  contra LigaPro/FEF por ~USD 744.472, con un precedente (app con IDs
  enumerables) que calza exactamente con la exposición de este proyecto.
- **INDUSTECH puede estar obligada a tener delegado de protección de datos**
  desde diciembre de 2025 (punto 4 de la tabla de arriba).
- El **Art. 43 del Reglamento** puede reclasificar a INDUSTECH de "encargado" a
  "responsable" por haber elegido dónde alojar los datos — con su propio
  catálogo de infracciones y su propia multa.

**No pasó por verificación adversarial** (el workflow murió dos veces por
límite de sesión): es investigación seria en fuente primaria, no un hecho
cerrado. Antes de actuar sobre esto, un abogado ecuatoriano de la materia tiene
que confirmarlo.

---

## 1c-bis. Lo que la auditoría solo leyó en código, medido ya contra el servidor (2026-09-19)

Es la deuda de I-7 que §1c dejaba anotada («nadie leyó la base del servidor ni
abrió una pantalla»). Consultado en **solo lectura** sobre darkviolet, con el
PHP por stdin: ningún archivo queda en el servidor y son todos `SELECT`.

| Qué | Cifra verificada |
|---|---|
| `casos_gestion` | **1.021 casos**: 775 `CERRADO_SIN_ATENCION`, 125 `ASIGNADO`, 115 `ATENDIDO`, 4 `RESUELTO`, 2 `NUEVO`. 120 traen orden de cierre |
| `ot_archivo` | **7.356 filas**, **7.287** con el PDF en el servidor — confirma la cifra de T2.19 |
| Firmas de técnico | **425 distintas** para 22 usuarios. Solo **12** calzan exacto con un usuario; **413 quedan huérfanas y arrastran 7.144 filas** |
| Usuarios del sistema | 16 `TECNICO`, 3 `JEFE_ZONA`, 2 `SUPERADMIN`, 1 `ADMIN` |

**Dos cosas que esta verificación corrigió, y que estaban mal escritas:**

1. **«Las mías» NO devuelve 1 fila.** Reproducido el filtro exacto de
   `ordenes.php:149` (`a.tecnico LIKE '%{usuarios.nombre}%'`) usuario por
   usuario: **suma 239 filas de 7.356**. Nueve técnicos ven 0; el resto ve
   entre 9 y 33. Sigue roto —es el 3,2%— pero el diagnóstico anterior («1 fila,
   y esa de un JEFE_ZONA») es falso, y sobre un dato falso se construye la
   solución equivocada.
2. **La tabla `tecnicos` no existe en el servidor:**
   `SQLSTATE[42S02] Table 'u671729428_ots.tecnicos' doesn't exist`. El criterio
   de **T2.21.5** dice «exportar la persona resuelta contra `tecnicos`»: esa
   tabla es **local**, así que la resolución se hace en la estación y se
   exporta ya resuelta. Allá no hay contra qué resolver.

**La causa de «Las mías», aislada:** `usuarios.nombre` guarda el nombre legal
completo (`Pablo Andrés Ortiz Villarruel`) y `ot_archivo.tecnico` guarda lo que
el técnico firmó en el PDF (`Sergio Torres`, `Henry Melendrez`,
`Diego Meléndrez`). El `LIKE '%nombre completo%'` solo acierta cuando el PDF
trae el nombre entero, que es la minoría.

---

## 2. Tareas terminadas

| Tarea | Qué dejó |
|---|---|
| T1.1–T1.3 | Inventario con hash de 12.616 archivos y copia verificada a `D:\RESPALDOS` (100% de hashes coincidentes) |
| T1.4 | Stack instalado: Python 3.12 + venv, MariaDB, Poppler |
| T1.5 | Esquema de 13 tablas y maestro de locales, con verificación cruzada contra la hoja GENERAL |
| T1.6 | Saneamiento canónico de los 7.333 PDFs, con manifiesto reversible |
| **T1.6b** | Resolución de la cuarentena cruzando 4 fuentes independientes. 519 de 554 casos resueltos |
| **T1.6c** | Fechas inválidas recuperadas del `CreationDate` del PDF. Ninguna orden activa sin fecha |
| **T1.6d** | 25 trabajos fuera del Grupo KFC archivados en su propia rama |
| **T1.6e** | 5 altas al maestro (95 → 100 locales) y 2 códigos mal escritos resueltos como alias |
| T1.7 | Extracción por patrones e ingesta idempotente a la base |
| T1.8 | ❌ **Cancelada** por directiva del cliente. El Drive queda intocable de forma indefinida |
| T1.10 | Agente 1 · auditor de calidad, con 10 reglas |
| T1.11 | Agente 2 · consolidador, primera versión |
| **Histórico base** | Las 7.065 órdenes (6.345 correctivas + 720 preventivas) reconstruidas en el formato con que se planifica, tomando de los archivos de la administración solo el formato y su seguimiento. Punto de partida para los planes diarios de Fase 2 |
| **Campos SAP faltantes** | Migración `002`: `Estatus 2 de la Orden` y `Denominación objeto` importados a `avisos_sap`. Era el techo de calidad: subió EQUIPO de la mitad a 99,5% y habilitó la columna ESTATUS SAP |
| **Discrepancias de los planes manuales** | 1.552 diferencias detectadas contra la evidencia de las OTs y SAP, 1.114 de ellas contradiciendo evidencia estable, entregadas en Excel para revisión de la administración |
| **Cierre de todos los casos** | El histórico queda con las 7.863 filas en CERRADA, cada una con la evidencia que sostiene su cierre. Solo 191 son una decisión sin ningún registro detrás, listadas para revisión. Se descartaron, por falta de evidencia, el autocierre de KFC a los 7 días y el cierre bajo otro número de caso |

---

## 3. Qué sigue

### Bloqueado por el cliente (no depende de nosotros)

| Pendiente | Bloquea |
|---|---|
| Contraseña del buzón Titan + acceso de aplicaciones de terceros | Lectura automática de avisos SAP por IMAP (parte de T1.10) |
| Acceso físico al TrueNAS | T1.9 |
| Anexo de niveles de servicio del contrato con KFC | Metas reales de SLA en T2.2 |
| Confirmar la zona real de los 4 locales Pollo Gus dados de alta con zona `OTRA` | Reportes por zona de esos locales |
| Decidir sobre las altas propuestas en `SALIDAS IA\CALIDAD\PROPUESTA_ALTAS_MAESTRO_LOCALES.xlsx` | Nada; el sistema ya opera con ellas cargadas en la base |
| **Reportes históricos de SAP** (más allá del export actual, que solo cubre ene–ago 2026) | Cerrar las inquietudes del histórico — ver abajo |
| ~~Habilitar SSH en Hostinger~~ | ✅ Hecho el 2026-09-09 (llave por equipo; `LEEME_ACCESO_HOSTINGER.md` parte A) |
| ~~Lista de técnicos vigentes~~ · ~~usuario por técnico o código de zona~~ | ✅ Resueltos el 2026-09-09: padrón de 19 vigentes y una cuenta por persona |
| **Antes del piloto (2026-09-13):** correr `deshacer_prueba.php`, entregar las claves de UIO, decidir sobre las órdenes 90xx de las pruebas, crear la Tarea programada del saneamiento en la estación y fijar `SEGUNDA_COPIA` | El arranque del piloto. Lista con comprobaciones en `desarrollo/sistema_ots/piloto/ANTES_DE_EMPEZAR.md` |
| **Subir los 7.069 PDF históricos a Hostinger** (D2) | Que el Archivo abra el histórico completo; hoy muestra «pedir copia» en los que solo están en la estación |
| **Cron en hPanel** (reemisor 10 min, despachador 5 min, `archivo_indexar_cli` 1 h, `purgar_cli` semanal) y **segundo usuario MySQL** | El corte (T2.16); mientras `emision_modo = PRUEBA` no hace falta |
| **PDF de `OT-2488-K061-10351229-CNLJ` sin subir al servidor** (reportado por Andrés el 2026-09-13; el enlace ya no da error, dice «PDF no cargado al archivo todavía») | La estación, con `D:\RESPALDOS`. Instrucciones exactas en `PLAN_INDUSTEC.md` §11b, tabla «Lo que está bloqueado, y por quién». Mismo cuadro que `OT-2503-K146-10354374-CNLJ` (T2.18) |
| **Fusionar `pc/pulido-2026-09-12` en `master`** desde la estación (`git fetch origin && git merge origin/pc/pulido-2026-09-12`) | Que la estación corra los scripts de T2.15 y despliegue sin pisar lo del PC |
| **Encuadre LOPDP: quién es responsable y quién encargado, y contrato de encargo INDUSTEC↔INDUSTECH** | Nada técnico, pero define quién debe actuar ante la exposición verificada. Ver `SISTEMA_COMPLETO.md` §5b |
| **Aprobar los 4 parches de seguridad del servidor de Hostinger** (`phpinfo.php` público, OTs en blanco por `GET` en `submit.php`, revisión de logs de acceso, WordPress) — César. Andrés los difirió otra vez el 2026-09-28 en el comité de gerencia: «No por el momento, pero actualízalo dentro del plan» | Nada técnico; el riesgo LOPDP (2 días para avisar al responsable si hubo acceso indebido) corre mientras tanto. Ver `PLAN_INDUSTEC.md` §8 y §10 |
| ~~Revisar si `cleanup.php` está en algún cron~~ | ✅ Borrado el 2026-09-08; no había cron |
| **Confirmar con quién generó `ORDENES ABIERTAS EN SAP.xlsx` si el export lleva filtro** | Nada técnico, pero decide cuánto vale el cotejo: las 62 filas son **todas** `Mant. Correctivo` clase `L1` y ninguna anterior al 26-ago. Si el export está completo, los 909 casos que la pantalla muestra como vivos ya están cerrados en SAP |
| **Export de órdenes abiertas de SAP, periódico** | Que el sistema deje de depender solo del correo. Quedó probado que **la mitad de los casos abiertos no llega por el buzón**, así que esta es la única vía para verlos y la única que cierra casos |

### Etapa del histórico: cerrada, con estas inquietudes anotadas

La reconstrucción del histórico se dio por cerrada el **2026-09-05**. Lo que sigue abierto no se
resuelve con más código: necesita datos del cliente. Cuando lleguen los reportes históricos de SAP,
se contrasta contra ellos y se corrige lo que haga falta.

| Inquietud | Cifra | Con qué se resuelve |
|---|---|---|
| Cierres asumidos sin ningún registro detrás | 191 filas | Reporte histórico de SAP: ver si registran cierre y cuándo |
| Avisos que SAP daba por abiertos al corte del 31-ago, cerrados igual en el histórico | 296 | Un export posterior dice si cerraron y con qué fecha |
| Filas de 2025 sin cobertura del catálogo SAP | 2.726 | Export de SAP que alcance sep–dic 2025 |
| Casos dentro de la cobertura y aun así ausentes del catálogo | 31 | Revisión individual contra SAP |
| Discrepancias de los planes manuales contra evidencia estable | 1.114 | Revisión de la administración sobre el Excel entregado |
| `ESTATUS SAP` llenado al 21,3% | 1.659 de 6.450 avisos | Es el dato real: solo esos tuvieron trámite de repuesto. Se confirma con el histórico de SAP |
| El autocierre de KFC a los 7 días, afirmado pero sin huella en los datos | — | Confirmarlo con el cliente o con un export que registre el motivo de cierre |

### Disponible para trabajar ya

| # | Tarea | Depende de | ¿Paralelizable? |
|---|---|---|---|
| **T2.16** | **El corte y la unificación**, después del piloto: siete pasos con camino de vuelta en [`PLAN_INDUSTEC.md`](PLAN_INDUSTEC.md) §T2.16 y la parte B de `LEEME_ACCESO_HOSTINGER.md`. Cada paso requiere a Andrés en el momento | El piloto en UIO con ≥ 50 correctivas y 5 preventivas sin una orden perdida | No |
| ~~T2.12~~ | ✅ Hecha (2026-09-11) y superada por T2.14 (2026-09-13). Se conserva la fila por el historial: **Puesta en marcha de las interfaces por rol.** Doce subtareas con compuerta, en [`PLAN_INDUSTEC.md`](PLAN_INDUSTEC.md). Arrancó con **aplicar la migración 007**. Lo construido está probado en local —206 comprobaciones, 0 fallos— pero **nunca se abrió contra la base real**: la verificación de alcance con los tres roles, la prueba sin señal de punta a punta y el reloj de 48 h contra datos reales siguen pendientes | Aprobación para la 007 y para desplegar | **No**: cada subtarea es compuerta de la siguiente |
| **T1.11-b** | Llevar el consolidador vivo (`agente2_consolidador.py`) al modelo del histórico: agrupar por (aviso, zona), arrastrar por evidencia y leer los campos SAP nuevos. Hoy sigue con la lógica vieja del 87,3% | nada | Sí |
| **T2.4.0–T2.4.4** | Continuidad de datos del sistema en producción: parches de seguridad, espejo verificado, purga con compuerta de hash, normalización y publicación. Ver [`ARQUITECTURA_SISTEMA_OTS.md`](ARQUITECTURA_SISTEMA_OTS.md) | SSH habilitado | Sí, salvo la purga |
| **T2.5.2** | Los controles del formato único como código probable, y medidos contra las 7.069 órdenes. **Hecho**: 14 pruebas en verde, informe en `SALIDAS IA\OTS\CONTROLES_MEDIDOS.md` | T2.5.1 | Sí |
| **T2.5.1** | Catálogos del formulario único: 100 locales, 1.173 activos en 94 locales, 222 tipos, 19 técnicos. **Hecho**; quedan 3 decisiones en `catalogos\COBERTURA.md` | nada | Sí |
| **Migración `003`** | **Superada en parte** (2026-09-13): `correlativos` y `email_queue` los creó la 008 en Hostinger, donde nace la orden; queda para la estación solo `FORMULARIO_WEB` en `fuente` (T2.16 paso 7). Cambia el esquema y requiere aprobación | nada | No: va antes de T2.1.1 |
| **T2.1.1–T2.1.6** | Intervención al sistema de OTs en producción (PHP/Hostinger), subtarea por subtarea | nada | Sí, entre sí no chocan si se despliegan de a una |
| **T2.2.1–T2.2.3** | Agente 3 · reportes diario, mensual y KFC | nada | Sí |
| **T2.3.1–T2.3.4** | Cola de correo `email_queue` con reintentos y detección de rebotes | nada | Sí |
| **T3.1** | Bot de Telegram para la Gerencia | T2.2 conviene, no obliga | Sí |
| **T3.2** | Analista de confiabilidad y repotenciación | nada | Sí |
| **T3.3** | Manual y continuidad | que lo demás exista | No: se escribe al final |

Cada subtarea está especificada con su criterio de aceptación y su tabla *autónomo / requiere aprobación / prohibido* en `PLAN_INDUSTEC.md`.

---

## 4. Dónde está cada cosa

| Qué | Dónde |
|---|---|
| Plan maestro | `D:\INDUSTECH IA\PLAN_INDUSTEC.md` |
| Código y scripts | `D:\INDUSTECH IA\desarrollo\agentes\scripts\` (repo git) |
| Entorno de Python | `D:\INDUSTECH IA\desarrollo\agentes\.venv\Scripts\python.exe` |
| Credenciales de la base | `D:\INDUSTECH IA\desarrollo\agentes\config\.env` (fuera de git) |
| Esquema SQL | `D:\INDUSTECH IA\desarrollo\agentes\sql\001_esquema_inicial.sql` |
| Corpus canónico de órdenes | `D:\RESPALDOS\ORDENES DE TRABAJO\{año}\{módulo}\{zona}\{cadena}\` |
| Informes técnicos sueltos | `D:\RESPALDOS\INFORMES TECNICOS\` |
| Trabajos de otros clientes | `D:\RESPALDOS\OTROS CLIENTES\{año}\{cliente}\` |
| Espejo intacto del origen (Drive) | `D:\RESPALDOS\_ORIGEN_DRIVE\` — **nunca se modifica** |
| Espejo intacto del origen (sistema en producción) | `D:\RESPALDOS\_ORIGEN_SISTEMA\` — lo que baja de Hostinger, con sus nombres crudos. **Nunca se modifica** |
| Decisión de arquitectura y hallazgos de la auditoría | `D:\INDUSTECH IA\ARQUITECTURA_SISTEMA_OTS.md` |
| **El sistema completo, de punta a punta** | `D:\INDUSTECH IA\SISTEMA_COMPLETO.md` — documento rector |
| **Formato único de OT** y sus controles | `D:\INDUSTECH IA\ESPECIFICACION_OT_UNICA.md` |
| Diseño de la app y el orden de construcción | `D:\INDUSTECH IA\DISENO_APP_OTS.md` |
| Catálogos del formulario único | `SALIDAS IA\OTS\catalogos\` — 100 locales, 1.173 activos, 222 tipos |
| Cómo acceder a Hostinger y desplegar los parches | `desarrollo\sistema_ots\LEEME_ACCESO_HOSTINGER.md` |
| Salidas para la administración | `D:\INDUSTECH IA\SALIDAS IA\CALIDAD\` |
| Catálogo de OTs para INDUSTEC | `SALIDAS IA\OTS\` — 7.069 órdenes con la ruta de su PDF |
| Consola local de revisión | `desarrollo/sistema_ots/local/app.py` → http://127.0.0.1:8010 |
| Histórico en formato de planificación | `SALIDAS IA\MANTENIMIENTO\` — correctivos por zona y mes, preventivos por local y año, discrepancias de los planes manuales, con `LEEME_HISTORICO.md` |
| Drive de la empresa | `G:\Mi unidad` — **solo lectura, indefinidamente** |

**Base de datos:** MariaDB local, esquema `industec_ots`. Tablas: `ots`, `ot_equipos`, `ot_fotos`, `locales`, `locales_alias`, `avisos_sap`, `tecnicos`, `observaciones_calidad`, `manifiesto_saneamiento`, `plan_snapshots`, `correcciones`, `consultas_gerente`, `bitacora`.

---

## 5. Trabajo en paralelo

Varias conversaciones pueden avanzar a la vez, **si respetan qué recurso toca cada una**. El problema no es el disco: es la base de datos y el árbol canónico.

### 5.1 Anótate antes de empezar

Edita esta tabla al tomar una tarea y bórrate al terminar. Si la tabla está vacía, nadie está trabajando.

> **Al 2026-09-12, nadie tiene nada reservado.** La conversación de la estación
> cerró su trabajo (dos pantallas sin estilos, la fusión de la rama del PC y el
> buscador), lo empujó a `origin/master` y se borró de esta tabla. Las filas que
> quedan están **tachadas o son de seguimiento**: puedes tomar cualquier tarea
> de la lista A–E de §11b del plan sin pisar a nadie. Lo único que pide
> coordinación es lo de §5.2b, que está fuera de git a propósito.

| Tarea | Conversación / responsable | Desde | Recursos que bloquea |
|---|---|---|---|
| **T2.28.8 → T2.28.9 → T2.28.17f → T2.28.11** (casos sin local, bitácora de prueba escondida, vigilancia nocturna del Archivo, Parts Town) — continuación del plan, pedido de Andrés del 2026-09-30 | 🔨 **En curso** — conversación de la estación (Claude) | 2026-09-30 | T2.28.8: migración 016, `casos.php`, `verificar_esquema.php`; en la estación `t2_6_imap_avisos.py` y `t1_5_importar_maestro_locales.py` (este último, **solo con el diff aprobado por Andrés**) y la tabla `locales_alias`. T2.28.9: `bitacora.php`. T2.28.17f: `saneamiento_nocturno.py`, `inspectorbot_estado.py`. T2.28.11: `partstown.js`, `nucleo/PartsTown.php`, `app.js`, `index.html`, `pendientes.php`, `sw.js`. No toca `ots` ni el árbol canónico |
| ~~**Envío real de las OT (fin del modo piloto) + cuenta de correo configurable** (`reclutamiento@industec.me`, tomada del sistema viejo) — pedido de Andrés del 2026-09-29~~ | ✅ **Terminada, desplegada y ACTIVA el 2026-09-29 21:45** (T2.29) — conversación de la estación. Queda solo T2.29.7: ver salir la primera OT real | 2026-09-29 | Ya no bloquea nada. Tocó: migración 023, `nucleo/EnvioZonas.php` y `nucleo/Correo.php` (nuevos), `Emision.php`, `Casos.php`, `envio.php`, `yo.php`, `correos.php`, `automatizacion.php`, `verificar_esquema.php`, `despachar_correo_cli.php`, `correo_cuenta_importar_cli.php` y `envio_real_cli.php` (nuevos), `app.js`, `sw.js` v27; en darkviolet `emision_zonas`, `correo_cuentas`, `correlativos`. **config.php no se tocó.** Del viejo solo se LEYÓ. Detalle en **§1z** |
| ~~El PDF de la OT INDUSTEC sin la franja «DOCUMENTO DE PRUEBA», y los 14 del piloto regenerados como copia interna (6 con la fecha de atención corregida)~~ (decisiones A–E de Andrés del 28-sep-2026) | ✅ **Desplegada y ejecutada en darkviolet el 2026-09-28** (18:20–18:27 EC): 14 HECHA · 0 INCONSISTENTE, índice nocturno corrido sin pisar la corrección — Steven, worktree `_wt_ot_piloto_2026-09-28` (rama `pc/ot-piloto-no-cierra-2026-09-28`, empujada). Cifras, rollback y lo abierto (captura 204; línea de la OT-9125) en **§1y** | 2026-09-28 | Ya no bloquea nada. Lo que tocó: `nucleo/Emision.php`, `archivo_indexar_cli.php`, `vocabulario*.json`, `ui.js`, `sw.js` v25; los 14 de `ordenes_pdf/`, `ot_capturadas` (carga.correccion_admin en 6, `pdf_sha256_regen` en 14), `ot_archivo` y la bitácora #8206–#8219 |
| ~~Cuadro «En qué estado están» con las cuatro cifras de la administradora, + gestión del repuesto y marcas de la novedad (migración 022)~~ (pedido de Isabel del 27-sep-2026, fuera del plan) | ✅ **Hecha y DESPLEGADA en darkviolet el 2026-09-28** (rama `pc/panel-estados-administradora-2026-09-27`, sin empujar a GitHub) — Steven, desde el PC de Andrés, en un worktree aparte (`_wt_panel_estados_2026-09-27`) para no cruzarse con la conversación que edita `casos.php` | 2026-09-27 | `panel.php`, `Casos.php` (función nueva), `vocabulario.json` (+4 conceptos, v2026-09-27.1) y lo que regenera, `sw.js` v22, `tarjeta_cli.php`, `verificar_cifras.py`, `VOCABULARIO.md`. No toca la base ni el árbol canónico. Detalle, cifras y preguntas en **§1u** |
| ~~Buzón simplificado y cifras de Asignación~~ (pedido de Isabel, fuera del plan) | ✅ **Desplegada el 2026-09-28 a las 08:13 UTC**, pisada en `casos.php` y `nucleo/Casos.php` por el despliegue del panel (08:16) y **repuesta a las ~19:30 UTC** por el de la OT del piloto (`4240a67`, que contiene las dos ramas; §1w) — rama `pc/buzon-simplificado-2026-09-27` en GitHub | 2026-09-27 | Solo `casos.php`, `asignacion.php`, `nucleo/Casos.php` (tres claves nuevas en `tarjetasPorZona()`), `prueba_panel_zona.php` (85·0) y una frase en las dos hojas del piloto. No toca la base, el árbol canónico ni `vocabulario.json`. Detalle, cifras y comando de despliegue en **§1v**. Conviven con dos cambios sin confirmar de otras conversaciones (§1u en el árbol principal y el worktree `_wt_panel_estados_2026-09-27`): fusionar de uno en uno |
| ~~Vocabulario SAP en todo el sistema~~ (pedido de la administradora, fuera del plan) | ✅ **Terminada y desplegada en darkviolet el 2026-09-26** — ramas `pc/vocabulario-sap-2026-09-24` y `pc/vocabulario-sobre-vivo-2026-09-26` | 2026-09-24 | Solo texto visible, `vocabulario.json` y la tarjeta «Por zona»; no tocó la base ni el árbol canónico. Falta que la **estación empuje T2.28.3/T2.28.6** y fusione estas ramas en `master`, y que alguien vea las pantallas con sesión. Detalle, cifras y cómo se revierte en **§1t** |
| ~~T2.27.7 · Panel «Automatización» con las tareas programadas, INACTIVAS~~ | ✅ **Terminada el 2026-09-23** | — | Panel y migración 020 en darkviolet, las 5 tareas **inactivas**; `verificar_automatizacion.py` 27·0. Detalle en **§1r-bis**. La sección «Correos de las órdenes» del panel queda para T2.28.2 |
| ~~T2.28 · Fase 1 (línea base, robot, Archivo, arnés, análisis de solo lectura)~~ | ✅ **Terminada el 2026-09-24**, salvo lo que depende de personas o de tiempo real | — | Los tres carriles de la Fase 1 cerrados: **estación** (18a/18b/18c el robot, 17a/17c/17e el Archivo — `§1s-septies`), **web** (T2.28.1 el arnés, 17b el Archivo por la web — `§1s-sexies`) y **análisis** (4a correos, 3-siembra admins, 10a repuestos, 12a actividades, 16a/16b cronograma, 18d el robot de punta a punta — `§1s-ter` a `§1s-quinquies`). ✅ **El vigilante en vivo se reinició el 2026-09-23** (PID 13340 con código viejo → PID 29360 con el código de `5318497`, a pedido directo de Andrés — `§1s-octies`). Pendiente de **personas**: que Andrés confirme el tope de sesiones de Hostinger en hPanel y decida las discrepancias de T2.28.4a (94/6/0 vs 92/8/0, con hipótesis) y T2.28.16b (`K121EC` CUMPLIDO con fecha mal importada, a D7). Pendiente de **tiempo real**: la medición de 48 h de 18a y el criterio de dos noches de 18b, que recién puede empezar a contar desde el código nuevo. ✅ **Arreglo urgente del formulario desplegado el 2026-09-24** (correo y administrador editables y usados en la emisión, repuestos con texto libre, lista de casos sin recortar; `sw.js` v16 — `§1s-nonies`). ✅ **El robot confirmado `BIEN` y tres pedidos más desplegados, madrugada del 2026-09-24** (equipo buscable y creable, acompañantes por zona con el jefe primero, migración 021 para que el jefe de zona también atienda, padrón de técnicos regenerado tras 18 días atrasado; `sw.js` v18 — `§1s-decies`). La **Fase 2** (T2.28.2 en adelante, en serie) se lanzó, se detuvo a propósito una vez (error nº 44) y se relanzó. ✅ **T2.28.2, el módulo de correos, construido, desplegado y verificado el 2026-09-24** (013 aplicada, `TODO OK`; `correos.php`; `Destinatarios::resolver()`; el tope y el cupo por hora del despachador — `§1s-undecies`), **y sembrado**: los tres jefes de zona ya están en `correo_destinatarios` (3/3 contra el maestro). ✅ **T2.28.3 terminada el 2026-09-24** (`§1s-duodecies`): fuera `#correojefeop`, línea «también se enviará a» resuelta con `Destinatarios::copiasPorLocal()` (funciona sin señal), `formulario_v: 2`, regla `CORREO_INVALIDO` en las tres implementaciones y el fixture, y `envio.php` corregido para que `locales_admin.correo_veces`/`correo_visto` sí se llenen. `sw.js` v19. ✅ **T2.28.6, la ficha del equipo, terminada el 2026-09-24** (`§1s-terdecies`): migración 014 aplicada (`equipos_ficha`/`equipos_ficha_cambios`), `t2_28_marcas.py`/`t2_28_exportar_equipos.py` escritos y corridos (455 marcas, 3.083 modelos), desplegado y verificado (`verificar_emision.py` 48·0, `verificar_formulario.mjs` 39·0). De paso, un bug real (error nº 48): seis baterías de prueba elegían el equipo a ciegas y podían escribir la ficha de un equipo SAP real — corregido. Sigue **T2.28.7** en el carril |
| ~~T2.28.7 · Fotos del antes y del después, por equipo~~ | ✅ **Terminada, fusionada, migrada y DESPLEGADA en darkviolet el 2026-09-29** — 8 conflictos de la fusión resueltos a mano (commit `ffe0e76`), migración 015 aplicada, `verificar_emision.py` 68·0, `verificar_bandeja.py` 37·0, `verificar_formulario.mjs` 39·0. Detalle en **§1s-quindecies** | — | Migración `015_fotos_por_equipo.sql`, `app.js`, `index.html`, `cola.js`, `foto.php`, `Emision.php`, `plantilla_ot.php`, `sw.js` v26, reglas (cuatro implementaciones) y fixture. Sigue **T2.28.8** en el carril (casos sin local) |
| ~~T2.28.16a/16b · Cronograma de preventivos contra el Excel de hoy~~ | ✅ **Terminada el 2026-09-23** | — | `t2_7_cronograma_preventivo.py` (16a) y `t2_28_cronograma.py --comparar` (16b), solo lectura. 15/15 pruebas unitarias; 351/352 sin regresión contra el snapshot del 8-sep (1 corrección a propósito, documentada); informe 52 REAGENDAR (37 + 15 que destapa 16a) y 1 CONFLICTO con CUMPLIDO (mismo caso, K121EC ingreso 3 — a D7). Detalle en **§1s-quinquies**. No tocó 16c/16d (puerta D7) |
| ~~Revisión de las estadísticas del inicio y de Reportes~~ · ~~Reportes para Grupo KFC y tablero de gerencia (T2.27)~~ | ✅ **Terminadas el 2026-09-23** | — | Estadísticas desplegadas en darkviolet (§1q, `verificar_cifras.py` 21·0). Cinco generadores nuevos en `desarrollo/agentes/scripts/t2_27_*.py` y el lanzador `reportes_kfc.bat`; salidas en `SALIDAS IA\REPORTES\KFC`. **No escribió en ninguna tabla ni en el correo** (solo lectura). Detalle en §1r |
| ~~Limpieza de datos y usuarios de prueba~~ | ✅ **Terminada el 2026-09-22** | — | 5 cuentas y todo lo que generaron, retirados de darkviolet y del espejo local; 2 casos reales devueltos a NUEVO. Cifras y lo que no se borró en **§1p** |
| ~~**T2.26 · El formulario del técnico: desbloqueado, predictivo y sin señal**~~ | ✅ **Terminada y desplegada el 2026-09-22** | — | `guia.js`, `app.js`, `cola.js`, `sw.js` (v13) y dos baterías nuevas. **No tocó la base, ni el árbol canónico, ni ningún PHP.** Verificado: 120·0, 57·0, 62·0, 42·0 locales; 37·0 bandeja; **4·0** `verificar_sync_cerrada.mjs`. Detalle, cifras y lo que quedó sin comprobar en **§1o**. ⚠ `verificar_http.py` (81/86) y `verificar_emision.py` (aborta) piden **`preparar_prueba.php`**: el arnés se quedó sin ningún caso con local |
| ~~**T2.25 · Continuidad entre casos del mismo equipo**~~ | ✅ **Terminada, aplicada y desplegada el 2026-09-21** | — | Migración 012 en darkviolet y seis archivos desplegados. Verificado: **25·0** la batería nueva, 37·0 bandeja, 86·0 http, `verificar_esquema.php` TODO OK; y 36·0 · 120·0 · 57·0 · 62·0 en local. Lo único que queda es **que Andrés la mire**, y que alguien la use con un caso real. Detalle en **§1l** |
| ~~T2.23 · Rediseño de la interfaz de preventivos~~ | ✅ **Terminada el 2026-09-21** | — | `cronograma.html/.js/.css` y `sw.js` a v11. No tocó la base, ni el árbol canónico, ni el contrato de `cronograma.php`/`cronograma_accion.php`. Cifras, evidencia y lo que quedó sin comprobar en **§1k**. Falta desplegar a darkviolet y correr las baterías de servidor |
| ~~Regularización masiva del buzón (ATENDIDO → cerrado SAP, CERRADO_SIN_ATENCION → regularizado)~~ | ✅ **Terminada el 2026-09-21** | — | 124 + 773 casos regularizados en `casos_gestion` (Hostinger). Detalle en **§1h** |
| ~~T2.22b · InspectorBot — consola gráfica del robot~~ | ✅ **Terminada el 2026-09-21** | — | Ventana, icono, nombre propio en el Administrador de tareas y arranque con el equipo, todo verificado. Cifras y método en **§1f**. Sumó además la red de seguridad del trabajo en curso (`scripts/guardar_sesion.py` + hook `Stop`), ver §1g. `consola.bat` se conserva |
| ~~T2.21 · la cadena del correo y de producción~~ | ✅ **Cerrada del todo el 2026-09-21** | Regularización ejecutada con cuadre exacto y 0 documentos pendientes (§1d), robot arreglado y consola de estado en marcha (§1e), promotor del buzón encadenado al nocturno con su Tarea programada creada. Queda abierto solo T2.21.5 («Las mías» del Archivo) y recrear la Tarea con `/ru SYSTEM` cuando haya sesión de administrador |
| ~~T2.14 · Pulido para las pruebas del cliente, T2.15, T2.17~~ | Conversación desde el PC de Andrés — rama `pc/pulido-2026-09-12` en GitHub | 2026-09-12 (noche) | ✅ **Fusionada en `master` el 2026-09-13** por la estación (ff, sin conflictos), junto con `pc/archivo-zona-franquicia-2026-09-13` (T2.18) |
| **T2.18 · Falta el `--ejecutar` de `t2_18_clasificar_archivo.py` y `t2_18_atender_pedidos_copia.py`** | Libre — nadie la tiene tomada | 2026-09-13/14 | El rescate de `_DEL_BUZON` (53 OTs) ya se hizo y está empujado. Queda pendiente por espacio: `G:\Mi unidad` (cupo real de Drive) tenía 4,3 GB libres frente a los ~5 GB a copiar — resolver el espacio en Drive antes de correr `--ejecutar`. También quedan 4 conflictos de correlativo en `_DEL_BUZON` esperando decisión de Andrés (detalle en el plan, T2.18) |
| ~~T2.5 · Captura — formulario único, v1 para revisión~~ | Conversación "app captura v1" | 2026-09-08 | ✅ Terminada y en §1b. El formulario único está desplegado y preguntado por pasos |
| ~~Buscador de órdenes y avisos por coincidencia parcial~~ | Conversación "cotejo SAP" | 2026-09-10 | ✅ **Commiteado el 2026-09-12 por la estación, con tres defectos corregidos** — ver la fila del buscador en §1b. Si esa conversación sigue viva: `Ui::normalizarBusqueda()` **cambió de implementación** (tabla de 123 entradas generada, no las 16 a mano) y `prueba_contratos.mjs` ahora ejecuta el PHP de verdad. Toma lo de `master` antes de seguir. Sus dos scripts de SAP (`t2_11`, `t2_12`) tardaron más en entrar a git, pero ya están: ambos commiteados el 2026-09-18, ver §5.2b |
| T2.12 · **solo queda T2.12.12**, las tres hojas de capacitación | Libre — nadie la tiene tomada | 2026-09-10 | **Escribe** en `SALIDAS IA\OTS\`, una hoja por rol. Todo lo demás de T2.12 está hecho y desplegado, incluida la verificación por rol (87 comprobaciones con ingreso real). Es la acción **D** de §11b |
| ~~Auditoría del robot del correo~~ | Conversación "auditoría correo→informes" (estación) | 2026-09-18 | ✅ **Terminada el 2026-09-18.** Cifras en §1c; qué construir, en `PLAN_INDUSTEC.md` **T2.21** (acción **H** de §11b). No tocó nada: solo lectura |
| Sitio web corporativo industec.me | Conversación "web corporativa industec.me" | 2026-09-10 | La **raíz** del sitio de pruebas (`public_html/`), con archivos nuevos. **No toca `public_html/ot/`**, ni la base, ni el árbol canónico. ⚠ **Ojo, 2026-09-12:** el sitio ya está publicado y versionado desde el PC en `desarrollo/web_corporativa/`. El `desarrollo/sitio_web/` de la estación es una **segunda copia sin commitear** (215 archivos, 22 MB de imágenes): decidir cuál queda antes de commitear ninguna de las dos |

> **El aviso de «no despliegues a `public_html/ot/`» se retiró el 2026-09-12**,
> al fusionarse la rama del PC en `master`. Su motivo era que el árbol local
> estaba por detrás y un despliegue habría revertido lo puesto desde el PC. Ya
> no aplica: `master` contiene esa rama.
>
> Lo que **sí** sigue vigente es el método, porque es lo que hizo seguro el
> despliegue del 2026-09-12: antes de subir un archivo, comprobar con
> `git diff master origin/<otra-rama> -- <archivo>` que nadie más lo haya
> tocado, y después verificar **lo que entrega la web**, no el disco del
> servidor — `curl` y comparar SHA contra el archivo local.
>
> Y el aviso del `.htaccess` de la raíz, que no tiene nada que ver con la
> fusión: un `.htaccess` en `public_html/` **se hereda en `ot/`**, así que un
> `RewriteRule` de tipo SPA, un `DirectoryIndex` o una cabecera `CSP` globales
> pueden romper el sistema de OTs sin dar un solo error — la CSP, en particular,
> deja el service worker sin registrar y la cola de envíos del técnico sin
> funcionar. Si hay rewrites en la raíz, la primera condición va
> `RewriteCond %{REQUEST_URI} !^/ot/`. Después de subir a la raíz se comprueba
> que `/ot/login.php` siga dando 200 y que `/ot/catalogos/locales.json` siga
> dando **403**: si ese pasa a 200, la raíz desarmó la protección del padrón.

### 5.2b El repositorio dejó de estar partido — fusión del 2026-09-12

Durante dos días hubo **dos verdades**: `master`, en la estación, y
`pc/auditoria-2026-09-10`, en GitHub, **29 commits por delante**. La rama traía
la 007 aplicada, el rediseño desplegado, el `sw.js` en v5, la 008 y la web
corporativa publicada, mientras el plan de la estación seguía proponiendo
aplicar la 007 como siguiente acción. **Fusionada en `master` el 2026-09-12.**

Cómo se resolvió, porque el método es lo reutilizable:

- **La superficie de conflicto se midió antes de fusionar**, no después:
  `comm -12` entre los archivos que tocaba cada lado dio exactamente tres
  —`.gitignore`, `ESTADO.md` y `PLAN_INDUSTEC.md`—, todos de documentación.
  Ningún archivo de código chocó.
- **El trabajo sin commitear de las otras conversaciones sobrevivió intacto.**
  Se comprobó primero que la rama no tocaba ninguno de los archivos sucios
  (`casos.php`, `ordenes.php`, `Ui.php`, `prueba_contratos.mjs`,
  `t2_11_informes_ot.py`), así que la fusión no tuvo que pisar nada y no hizo
  falta ningún `stash`.
- **Respaldo antes de tocar nada:** rama `respaldo/pre-fusion-2026-09-12`
  apuntando al `master` de antes de la fusión.

**La lección, que es el error nº 15 del plan:** el disco local no es el estado
del proyecto. Un `git log --oneline master..origin/<rama>` cuesta un segundo y
habría evitado escribir en el plan dos cosas falsas.

#### 🔴 Lo que la fusión destapó: dos pruebas rotas que la rama ya traía

**No las causó la fusión.** Se comprobó levantando un árbol aparte
(`git worktree add --detach` sobre la rama sola, sin ningún cambio de la
estación) y corriendo ahí las mismas pruebas: fallan igual. Quedan **en
`master`** y hay que resolverlas.

| Prueba | Síntoma | Sospecha |
|---|---|---|
| `prueba_48h.php` | **96 comprobaciones · 4 fallos**: «veredicto a las 55 h: queda como tarde», «el reloj deja de correr», «veredicto a las 10 h: a tiempo», «garantía de semanas NO cuenta como incumplida» | Muy probablemente **prueba obsoleta, no defecto**: el error nº 11 del plan dice que el reloj de 48 h se pasó a calcular **en SQL** justo porque restar fechas en PHP dependía de la configuración del hosting. La prueba sigue midiendo la función PHP. Hay que decidir si se reescribe contra SQL o se retira |
| `prueba_offline.mjs` | Corta en el primer paso: `ERR "[object Object]" is not valid JSON` | Falla antes de comprobar nada. Puede ser de entorno (necesita el servidor local encendido) o una respuesta que dejó de ser JSON |

**Por qué importa más de lo que parece:** una batería que informa «4 fallos» de
forma permanente se vuelve ruido, y a la tercera vez nadie la mira. Entonces el
fallo número cinco —el real— entra sin que nadie lo note. O se arreglan o se
retiran, pero no se quedan así. Las otras tres pasan limpias: `prueba_contratos`
57 · 0, `prueba_graficos` 62 · 0 y `prueba_barra_tecnico` en verde.

#### Sin commitear a propósito, con sus hallazgos abiertos

| Qué | Por qué no entró | Hallazgo concreto |
|---|---|---|
| `desarrollo/sitio_web/` (215 archivos) | **Duplica** `desarrollo/web_corporativa/`, que ya está versionado y publicado desde el PC | **22 MB de imágenes** (202 archivos, el 99,2% del peso) que git no suelta nunca: el `.git` pasaría de 11 a ~33 MB y deshacerlo exige reescribir el historial. Y `assets/opt/` (6,8 MB) lo **regenera** `optimizar_imagenes.py` desde los originales: versionar las dos copias es guardar lo mismo dos veces. No tiene `index.html`. Y `publico/contacto.php:35` redirige a `contacto.html`, que **no existe**: publicarlo deja un formulario cuyo acuse de recibo da 404 |
| ~~`t2_11_informes_ot.py`~~ y ~~`t2_12_cotejo_sap_abiertas.py`~~ | Eran de la conversación «cotejo SAP» y el encargo de aquel día era el buscador. **Los dos ya entraron: `t2_11` el 2026-09-18** (`f6ac8ca`, porque la Tarea programada corría la copia sin commitear) **y `t2_12` el 2026-09-18** (`e4cafb9`), con sus tres hallazgos corregidos, no solo commiteado tal cual | ✅ Cerrado. La compuerta de I-10 tautológica (`en_buzon + fuera` no podía ser distinto de `len(filas)`) se quitó en vez de dejarla mintiendo «Cuadre: OK»; `leer_export()` borra ahora el temporal de `%TEMP%` en un `finally` (riesgo LOPDP); y `EMISOR_OT` vive en `config/.env`, leído por los dos scripts — ya no hay dos literales a mano |

### 5.2 Qué choca con qué

| Si vas a... | Bloqueas | No puede correr a la vez |
|---|---|---|
| Correr `t1_7_ingesta.py` | tabla `ots`, `ot_equipos` | cualquier otro script que escriba `ots` |
| Correr `t1_6b_ejecutar_resolucion.py` | tabla `ots` + árbol canónico | la ingesta, y cualquier movimiento de archivos |
| Correr `agente1_auditor_calidad.py` | tabla `observaciones_calidad` | otra corrida del auditor |
| Correr `agente2_consolidador.py` | solo lee `ots`; escribe en `SALIDAS IA` | nada |
| Trabajar en T2.1 (sistema en producción) | Hostinger y su MySQL | otro despliegue a producción |
| Correr `t2_4_sync_hostinger.py` | espejo `_ORIGEN_SISTEMA` | otra sincronización |
| Correr `t2_4_purga_hostinger.py --ejecutar` | uploads de Hostinger | la sincronización, y cualquier despliegue |
| Correr `t2_4_normalizar_nuevas.py --ejecutar` | árbol canónico | la ingesta y los scripts de T1.6b |
| Trabajar en T2.2 / T3.1 / T3.2 | solo lectura de la base | nada |
| Escribir documentación o investigar | nada | nada |

**Regla simple:** lo que solo lee puede correr siempre en paralelo. Lo que escribe en `ots` o mueve archivos del árbol va de a uno.

### 5.3 Orden obligatorio cuando se re-procesa el corpus

Si tocas la resolución de documentos, el ciclo completo es **siempre en este orden**, y cada paso debe terminar antes del siguiente:

```
1. t1_6b_resolver_cuarentena.py     analiza, no escribe nada
2. t1_6b_verificar_resolucion.py    5 comprobaciones; si falla, NO sigas
3. t1_6b_ejecutar_resolucion.py     copia, verifica por hash, actualiza la base
4. t1_7_ingesta.py                  crea las filas de los documentos nuevos
5. t1_6b_ejecutar_resolucion.py     otra vez: activa las filas que acaba de crear la ingesta
6. t1_6c_corregir_fechas.py         rellena fechas desde el PDF
7. agente1_auditor_calidad.py       recalcula observaciones y cierra las que ya no aplican
```

Los pasos 4 y 5 se repiten porque el renombrado cambia el `id_industec`: la ingesta crea la fila canónica y la segunda pasada del ejecutor la activa. Todos los scripts son idempotentes: correrlos de nuevo no duplica nada.

### 5.4 Cómo comprobar que no rompiste nada

```bash
cd "D:\INDUSTECH IA\desarrollo\agentes"
.venv/Scripts/python.exe scripts/t1_6b_verificar_resolucion.py
```

Y el cuadre global, que debe dar exactamente 7.333:

```
PDFs en ORDENES DE TRABAJO + INFORMES TECNICOS + OTROS CLIENTES + _CUARENTENA + 233 duplicados
```

Señales de que algo se rompió: alguna orden activa sin local o sin fecha, algún archivo del árbol con más de una orden activa, o el verificador fallando cualquiera de sus 5 comprobaciones.

---

## 6. Decisiones vigentes que no se re-discuten

Están razonadas en el plan; aquí solo el titular, para que ninguna conversación las revierta por desconocerlas.

1. **El Drive de INDUSTEC (`G:\Mi unidad`) es de solo lectura, indefinidamente.** T1.8 quedó cancelada. Nada se borra de ahí sin autorización explícita y puntual.
2. **Nunca se borra información del cliente sin que él lo pida en el momento.** Aprobar un plan no autoriza ejecutar un borrado.
3. **Los archivos de la administración no se sobrescriben.** Los agentes escriben a nombre nuevo; ella promueve.
4. **El aviso SAP es opcional en el nombre canónico.** Los preventivos no nacen de un aviso, y algunos correctivos tampoco (§6.4b del plan).
5. **`estatus_general` de SAP es el único criterio de "cerrado".** Nunca una señal interna del sistema de OTs.
6. **El interior del PDF manda sobre SAP y sobre el plan de la administración** cuando hay que decidir a qué local pertenece un documento: las otras señales se derivan del aviso o del correlativo, que son los campos que se digitan mal.
7. **Software libre o gratuito**, el mejor de su categoría. Lo de pago se propone con justificación y costo antes de adquirir nada.
8. Se puede instalar sin pedir permiso previo, siempre que sea de fuente verificada y necesario; se informa después.
9. **Los parches de seguridad de producción siguen diferidos** (Andrés, 2026-09-28, comité de gerencia): `phpinfo.php`, `GET` en `submit.php`, logs de acceso y WordPress no se tocan hasta que César lo apruebe o llegue el paso 5 de T2.16. Ninguna conversación los aplica «de paso». Riesgo asumido: LOPDP, 2 días para avisar al responsable si hubo acceso indebido (`PLAN_INDUSTEC.md` §8).
10. **Desde el 2026-09-29 el trabajo de INDUSTEC corre desde la cuenta de Claude propia de César/INDUSTEC** (Andrés, 2026-09-28): el 2026-09-28 se cierran los últimos arreglos desde la cuenta de Andrés y no se sigue desde su plan Pro. Las Consumer Terms de Anthropic no permiten compartir la cuenta ni el uso desatendido sin API; toda automatización desatendida de F1–F2 que llame a Claude va sobre la cuenta o la API key del cliente.

---

## 7. El criterio ganado está en las skills

Siete skills en `.claude/skills/`, minadas del propio código para que no se pierda lo aprendido. Invócalas según lo que vayas a hacer:

| Skill | Cuándo |
|---|---|
| **`industec-invariantes`** | **Siempre, al empezar.** Rutas, permisos, invariantes y cómo se cierra una tarea |
| `industec-escritura-mysql` | Antes de cualquier `INSERT`/`UPDATE`/`DELETE` o cambio de esquema |
| `industec-lectura-excel` | Al leer un Excel de la administración o de SAP |
| `industec-extraccion-pdf` | Al extraer campos de un PDF por patrones |
| `industec-archivos-canonicos` | Al mover, renombrar o clasificar documentos del corpus |
| `industec-agentes-y-entregables` | Al construir un agente o un archivo que va a leer una persona |
| `industec-despliegue-web` | Al desplegar `sistema_ots` a darkviolet o correr una batería de servidor |

Cada una lleva la evidencia real de qué se rompió y el comando exacto que comprueba que la regla se cumple. No son teoría: salieron de los errores que este proyecto ya pagó.

---

## 8. Bitácora de sesiones

| Fecha | Qué se hizo |
|---|---|
| 2026-09-03 | T1.1 a T1.7: inventario, copia verificada, stack, esquema, maestro, saneamiento e ingesta |
| 2026-09-04 | Segunda pasada del plan con 26 correcciones e invariantes I-9 a I-13. T1.6b/c/d/e: cuarentena resuelta, fechas recuperadas, otros clientes separados, altas al maestro. Corregidos 4 bugs de datos anteriores (centros de coste perdidos por `max_col`, correo del maestro en columna equivocada, filas duplicadas por renombrado, falsos positivos del auditor) |
| 2026-09-04 | Proyecto reorganizado: raíz corta y todo lo técnico en `desarrollo/`. Se escribieron el `CLAUDE.md`, este `ESTADO.md` y 6 skills minadas del propio código. Corregida una deriva de esquema que impedía reconstruir la base desde cero |
| 2026-09-10 | **Auditoría antes de seguir con el plan**, pedida por Andrés y hecha desde su PC contra el servidor en solo lectura. 70 hallazgos verificados, ninguno refutado. **Se cerró en el servidor la fuga de `catalogos.php` y `cronograma.php`**, que el estado daba por cerrada y seguía viva. Corregidos en código, sin desplegar: el lector de catálogos de `envio.php` (habría rechazado el 100 % de las órdenes), las novedades que nunca se enviaban, el aviso que no se precargaba, la cola sin dueño, la caché del trabajador de servicio que servía el PDF de otra orden, la reconciliación que deshacía reasignaciones, el reloj de 48 h calculado en PHP, el vigilante que quedaba sordo y el desplegador que podía salirse del sitio de pruebas. Proyecto sincronizado por primera vez en un remoto privado. **Revisión independiente:** 6 defectos introducidos por las propias correcciones —la transacción de `envio.php`, la reapertura tardía y el estado al cerrar pendientes, el PDF de un técnico sin zona, el login guardado por `sw.js` y el combo nulo de `app.js`—, corregidos antes de confirmar (§5b del informe). **Desplegado esa noche con aprobación de Andrés:** `sw.js` v4, `pdf.php`, `Reconciliar.php`, `Auth.php` y los dos `.htaccess` (`login.php` y `usuarios.php` esperan al rediseño: necesitan `Ui.php`), y las 2 filas huérfanas pasaron a NUEVO. Para T2.13 Andrés eligió emitir el PDF y el correo en la app nueva (migración 008). Informe: [`AUDITORIA_2026-09-10.md`](AUDITORIA_2026-09-10.md) |
| 2026-09-10 | **Rediseño completo de las interfaces.** Un solo sistema de diseño para las 13 pantallas (antes, seis copias del mismo `<style>`); navegación por rol en todas; panel convertido en tablero de «lo que te toca ahora»; app del técnico móvil con bandeja tipo correo y **envío en cola que sale solo al recuperar señal**, idempotente por UUID del celular; el técnico **deja de elegir su nombre** y la identidad sale de la sesión; el reloj de **48 h** para equipos deshabilitados con sus cuatro veredictos; insistencias con fecha; novedades del preventivo hacia otras áreas; formulario preguntado por pasos; y reportes gráficos en SVG propio con la paleta validada. Se cerraron **dos endpoints que entregaban datos del cliente sin sesión** y se corrigieron dos defectos reales (`etiquetaEstado` incompleta, `ESPERA_REPUESTO` pisado por la reconciliación). **Nada desplegado**: por I-8 va primero a UIO con 48 h de verificación. Reseña en `SALIDAS IA\OTS\REDISENO_INTERFACES.md` |
| 2026-09-11 | **T2.12.1 a T2.12.3 desde el PC**, mientras la estación no tenía créditos. Decisión de Andrés: el sitio de pruebas no lo usa nadie de INDUSTEC y se puede cambiar lo que haga falta; solo quedan vedados el Google Drive de la administradora y el sistema de órdenes de producción. Revisión previa con agentes: el paquete, sin bloqueantes; antes de subir se corrigieron los avisos que se perdían en el formulario del técnico y el pendiente que no seguía al caso derivado. Se midió la zona del PHP de la web: UTC, como la base (la hora del negocio queda como T2.13.7). Respaldo, 007 dos veces, despliegue de 48 archivos y barrido sin sesión, todo verificado. Se borraron del servidor `alta_padron.php` (versión vieja) y `nucleo/config.php.previo` (copia vieja de la configuración). **Verificación por rol** con cuentas de prueba: 87 comprobaciones; corregido el error 500 del veredicto y los cierres (intercalación de la conexión en `Db.php`) y los rechazos por alcance que no quedaban en la bitácora. Para probar se asignaron a un técnico de prueba dos casos reales de UIO (se revierten con `deshacer_prueba.php`): los sintéticos no se ven hasta T2.13.3. T2.12.7 y T2.12.8, con navegador de verdad. **El CDN de Hostinger entregaba el `sw.js` v2 y el `estilo.css` anterior** en su copia comprimida —la que reciben los navegadores— horas después de subir los nuevos: corregido con `no-cache` para el código en el `.htaccess` y una purga del CDN hecha por Andrés; `t2_10` ahora compara lo que entrega la web en sus dos variantes. **T2.13.7:** la hora del negocio ya es la de Ecuador en la base y en PHP (`Db.php`); las fechas guardadas en UTC se corrieron una vez, con respaldo previo, y las 87 comprobaciones siguen pasando. **T2.13.2 y T2.13.3:** el técnico ve sus casos desde la base —también los 4 que habían quedado fuera del catálogo— y el formulario ya no le ofrece casos atendidos; 19 comprobaciones nuevas. **T2.13.5:** el buzón de avisos del técnico, sin tabla nueva; 10 comprobaciones más. **T2.13.4:** el cronograma del técnico, con su barra y sin datos ajenos. **La web corporativa de INDUSTEC**, publicada en la raíz de darkviolet con `/acceso/` al sistema, verificada por hash. **La 008:** la orden sale de la app con su número, su PDF y su correo en cola; en el sitio de pruebas, serie 9000+ y correo retenido. 29 comprobaciones nuevas |
| 2026-09-12 | **Los dos cabos sueltos que dejó el rediseño, y la fusión de las dos verdades del repositorio.** Al recoger en `estilo.css` los `<style>` sueltos de cada página se quedaron fuera las clases de **dos** pantallas: la asignación entera (`.equipo`, `.persona`, `.cifras`, `.asignar`) y la contraseña temporal de `usuarios.php` (`.clave`). Ninguna daba error: HTML intacto, pruebas en verde y la pantalla en texto plano. Secciones 22 y 23 de la hoja, con la tarjeta de cada técnico rehecha. **El primer cruce de clases concluyó «era la única» y era falso** — la comprobación vale si se hace completa. Desplegado y verificado contra la web (SHA idéntico), con `sw.js` subido a **v7** partiendo del de la rama del PC, no del de `master`. Y lo que destapó todo: `master` estaba **29 commits por detrás** de `pc/auditoria-2026-09-10`, donde la 007 ya estaba aplicada y el rediseño desplegado, mientras el plan local seguía proponiéndolos como siguiente acción. **Rama fusionada**; método y lecciones en §5.2b |
| 2026-09-12 | **Auditoría de diez frentes y plan nuevo, por pedido de Andrés** (dejar la app pulida para las pruebas de técnicos, jefes y administradora; archivo de OT para todos; sanear lo del sistema viejo y cortar tras el piloto; web con ilustraciones In Tune industriales). 205 hallazgos verificados (`AUDITORIA_2026-09-12.md`), decisiones por defecto D1–D16 y tareas T2.14 a T2.17 en el PLAN. Rama `pc/pulido-2026-09-12` |
| 2026-09-13 | **T2.14 construida entera, desplegada y probada** (S0 a S7: 009/010, app del técnico, asignación por zona, repuestos con KFC, archivo/bitácora/usuarios/aprendizaje, reportes exportables y cronograma que escribe, emisión/correo/seguridad; 319 comprobaciones de servidor en verde). **T2.15** (estación: un solo contrato SSH, espejo de la app, volcado verificado, saneamiento nocturno, siembra de correlativos en ensayo). **T2.14.8**: paquete del piloto con capturas anonimizadas, `equipos.php`, documentación reescrita. **T2.17**: la web rediseñada con siete ilustraciones a mano, publicada y verificada. Los subagentes agotaron el límite de sesión a media tarde: desde entonces todo lo hizo la sesión principal |
| 2026-09-12 | **El buscador, desplegado, y el repositorio unificado y empujado.** Se subieron `busqueda.js`, `nucleo/Ui.php`, `casos.php`, `ordenes.php` y `sw.js` al sitio de pruebas, con el desplegador nuevo que ya compara **lo que entrega la web** y no el disco. Comprobado aparte: hash idéntico en `busqueda.js`, y `nucleo/Ui.php`, `config.php` y `catalogos/locales.json` siguen en **403**. `master` empujado a GitHub (32 commits de una vez) y sincronizado. §11b del plan reescrita para que la siguiente consola arranque sin preguntar: cinco acciones ordenadas, las tres primeras autónomas, empezando por las **4 comprobaciones rotas de `prueba_48h.php`** que la fusión destapó |
| 2026-09-13 | **Bug reportado por Andrés: el número de la orden de cierre, en el buzón (`casos.php`), abría un 404** («El PDF de esa orden no está en el servidor») en vez del PDF — caso `OT-2488-K061-10351229-CNLJ` (aviso 10351229, K061EC Mall del Río Cuenca, CNLJ). Causa: `casos.php` armaba `pdf.php?ot=…` para cada orden de `atenciones.json` sin comprobar antes con `Emision::existePdf()`, el mismo guardado que **ya** llevaban `mis.php` (desde H-02) y `ordenes.php` — un hueco que la auditoría del 2026-09-12 no encontró porque no miró esa pantalla. **Corregido** (commit `00870b4`, `pc/pulido-2026-09-12`, empujado): ahora, sin PDF, se ve el número en texto y «PDF no cargado al archivo todavía», igual que en `mis.php`. **Auditadas las seis pantallas/scripts que arman `pdf.php?ot=…`** (`grep -rn 'pdf.php?ot=' app/publico`): las otras cinco (`mis.php` ×3, `ordenes.php`, `app.js`) ya estaban correctamente guardadas — `casos.php` era el único hueco. Lección #16 en PLAN §11b. **El caso concreto sigue sin PDF en el servidor** — no es un bug de código, es que la orden se cerró por informe de correo y ese informe no está copiado a `ordenes_pdf/`; instrucciones exactas para recuperarlo en PLAN_INDUSTEC.md, tabla «Lo que está bloqueado, y por quién». De paso se encontró que la rama `pc/archivo-zona-franquicia-2026-09-13` (T2.18, de Andrés, **sin fusionar**) ya construyó y probó en simulación el mecanismo que resuelve justamente esto (clasificación del árbol canónico + atención automática de «pedir copia»), y detectó un caso gemelo sin resolver, `OT-2503-K146-10354374-CNLJ`. **También se confirmó que el Archivo (`ordenes.php`) todavía no se actualiza solo**: el cron de hPanel para `archivo_indexar_cli.php` (y los otros tres) sigue sin programarse — fila ya existente en §3 de este documento —, así que hoy depende de que alguien lo corra a mano o por el `--empujar` de `t2_15_exportar_archivo.py` |
| 2026-09-14 | **Buzón, Archivo y «otros trabajos», por pedido de Andrés con capturas.** (1) En el buzón de la administración los casos atendidos no mostraban su informe y decían «sin atender» junto a «atendido · técnico (del informe)»: la atención se leía solo de `atenciones.json` y la orden de cierre vivía en `casos_gestion`. Ahora se juntan por aviso las cuatro fuentes y el informe de cierre sale junto a «Ya lo cerré en SAP» (820 → 784 sin atender; índice 143 → 164; 0 cierres fuera del índice). El `casos.php` vivo era el de `014b529`: **el arreglo `00870b4` nunca se había desplegado** y subió con este cambio. (2) **Decisiones de Andrés:** subir **todo** el histórico y lo nuevo cada noche, **solo desde la estación** (revoca D2; producción sigue de solo lectura); «otros trabajos» como **marca aparte del estado** que decide siempre la administradora; el módulo OTROS del sistema viejo son **extras de KFC dentro del mismo trato** y cuentan como otros trabajos; «otros clientes» es otro reporte, interno. (3) T2.20 desplegada con la 011; T2.19 escrita y probada contra una carpeta de prueba del servidor. **No verificado:** la acción «Otro trabajo» y las descargas Excel/PowerPoint con una sesión real — las cuentas de prueba se borraron el mismo día a pedido de Andrés y recrearlas deja rastro en la bitácora; se verificó con `php -l`, con 302 sin sesión y con `Reportes::calcular()` y el HTML del PDF bajo un superadmin en memoria. El venv del PC apunta al Python de la estación: `t2_10` corre con el Python del sistema. Rama `pc/documentos-y-otros-trabajos-2026-09-14` (lleva dentro `pc/pulido-2026-09-12` y T2.18) |
| 2026-09-18 | **Auditoría de la cadena del correo, pedida por Andrés: ¿identifica los informes nuevos, los clasifica, los enlaza al caso y se ven en el buzón del técnico?** 26 agentes, 21 completados; los 4 refutadores de la dimensión de captación y el crítico de completitud **cayeron por límite de sesión**, y sus dos hallazgos grandes se verificaron a mano. **Lo que funciona:** el robot lee el correo cada 3 h, cruza por aviso, empuja `atenciones.json`, y el estatus del caso se mueve a «atendido» **sin fingir que SAP cerró** — frente a KFC no hay riesgo. **Lo que está roto:** `t2_11` deposita en `_ORIGEN_BUZON` y **ningún consumidor lee esa carpeta** (los cinco posibles arrancan en `ORDENES DE TRABAJO`, de donde está fuera): **104 informes varados, 0 clasificados, 0 en `ots`, 0 en el servidor**, desde el 2026-09-14 y creciendo ~20 al día; `ots.fuente='IMAP_EN_VIVO'` sigue en **0 de 7.469**. Y los scripts abren **1 de las 10 carpetas** del buzón: en `Trash` hay 157 informes, 123 «Cerrada», **76 de casos que el sitio muestra pendientes**. Causa de fondo: el 2026-09-13 T2.15.3 movió el destino de descarga y **los dos consumidores quedaron mirando la carpeta vieja** — el promotor ya existe (`t2_18_rescatar_buzon.py`), solo está clavado en `_DEL_BUZON`. Es la **segunda vez con la misma forma** (el 2026-09-13 ya pasó con `_DEL_BUZON` y 53 OTs). **Refutados, no arreglar:** el historial del técnico no se recorta a 90 días, y los 2 casos sin técnico sí dejan rastro. **Tres cifras de este documento corregidas:** los «69 sin PDF» de T2.19 no eran «sin archivo local», `prueba_48h.php` da 120·0 (no 96·4) y el «pedir copia» de `OT-2503-K146-10354374-CNLJ` ya está. Resultado: lecciones **18 y 19** en PLAN §11b, tarea **T2.21** con seis subtareas y las decisiones de diseño ya cerradas, y acción **H** al frente de §11b. Cifras completas en §1c. **Todo fue solo lectura:** IMAP con EXAMINE + `BODY.PEEK[]`, cero escrituras en base, cero `--ejecutar`, cero `--empujar` |
| 2026-09-28 | **Dos decisiones de Andrés en el comité de gerencia, anotadas en el plan** (sin tocar código ni servidor). (1) Los cuatro parches de seguridad de Hostinger que propuso el comité (`phpinfo.php`, `GET` en `submit.php`, logs de acceso, WordPress) **no se aplican ahora**: pendiente explícito condicionado a la aprobación de César, con el riesgo LOPDP anotado (PLAN §8, §9 y §10; aquí §3 y §6.9). (2) Últimos arreglos hoy desde la cuenta de Andrés; **desde el 2026-09-29 se continúa desde la cuenta de Claude de César/INDUSTEC** (PLAN §9; aquí §6.10) |
| 2026-10-01 | **La primera OT real, el choque de números con el formulario viejo y diez OT del piloto enviadas a Grupo KFC** (pedido de Andrés). El «NNNN» era el recibo provisional; la OT tardó 16 min en llegar y no se vio en el Archivo hasta el índice nocturno. Se encontró que el formulario viejo y la app numeran en el mismo rango (OT-1952 y OT-1964 repetidas en UIO) y que las dos OT reales de la app son la única OT de su trabajo: **Andrés decidió dejarlas y anotarlas**, y devolver LARB y CNLJ al piloto. Se agregó la marca `liberada_en` (migración 024), se verificó con tres fuentes independientes que 10 trabajos del piloto no los había reportado el viejo y se enviaron: 10 de 10 llegaron con su PDF intacto. Baterías 69·0 y 89·0. Esa noche también: la OT entra al Archivo al emitirse y `cola.js` corta las peticiones colgadas (70·0, 4·0, 39·0; prueba nueva que reproduce el fallo). Detalle y lo no comprobado en §1z-bis |
