# Estado del proyecto INDUSTEC

> **Empieza por aquí.** Este archivo dice dónde vamos; [`PLAN_INDUSTEC.md`](PLAN_INDUSTEC.md) dice qué hay que construir y con qué criterios.
> Si vas a trabajar, **anótate primero en §5 (Trabajo en paralelo)** antes de tocar nada.

**Última actualización:** 2026-09-18
**Fase en curso:** 2 · Automatización — **construida y desplegada en el sitio de pruebas; lo que sigue es el piloto en UIO** (paquete en `desarrollo/sistema_ots/piloto/`). La Fase 1 quedó cerrada
**Repositorio git:** la raíz del proyecto, `D:\INDUSTECH IA` — cubre el código **y** estos documentos, para que quede historial de las decisiones. Fuera del control de versiones: `ENTRADAS IA`, `SALIDAS IA`, el entorno virtual y las credenciales.

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
| Corpus histórico saneado | ✅ | **7.070** órdenes en el árbol canónico + 5 informes técnicos + 25 de otros clientes |
| **Robot del correo → informes nuevos** | ⚠️ **captación reparada; falta el `--ejecutar`** | El robot lee `INBOX`+`Trash`+`INFORMES OT` (T2.21.1, confirmado por el servidor: `n=193 antes=122`), reintenta y avisa si el empuje falla (T2.21.4), y desde el 2026-09-20 también espeja producción al detectar un correo (T2.21.7: 2.323 PDF, 246 nuevos de verdad que ni el árbol ni el correo tenían). Sigue sin promover al árbol: **107 informes en `_ORIGEN_BUZON`**, simulación en verde (92/13), a la espera de 4 arreglos de diseño (ver plan, T2.21) y del `--ejecutar` de Andrés |
| Base de datos poblada | ✅ | **7.118 órdenes activas** (era 7.069 hasta el 2026-09-13: `t2_18_rescatar_buzon.py` recuperó 53 OTs que llevaban 4 días en `_DEL_BUZON` sin clasificar ni ingestar — ver T2.18 en el plan) · 9.071 equipos · 100 locales · 145 alias · 6.450 avisos SAP · 19 técnicos |
| Auditor de calidad (Agente 1) | ✅ | 2.644 observaciones abiertas, con veredicto editable por la administración |
| Consolidador de plan de zona (Agente 2) | ⚠️ v1 | 87,3% de coincidencia celda a celda en el piloto UIO |
| **Histórico en formato de planificación** | ✅ | **39 planes mensuales** de correctivo (7.863 filas, 3 zonas × 13 meses) + **seguimiento de preventivos** de 96 locales, reconstruidos desde las OTs y SAP. LOCAL, FECHA DE INICIO y ESTADO al 100%; EQUIPO al 99,5% |
| Respaldo TrueNAS | 🔒 | Bloqueado: falta acceso físico al equipo |
| Capacitación de cierre | 🔒 | Bloqueada: falta agendar con el personal |
| Repositorio git sincronizado con GitHub | ✅ **2026-09-12** | `origin` es `git@github.com:AndresIndustech/industec-bia-soft-erp.git`. **Comprobado:** `ssh -T git@github.com` responde «Hi AndresIndustech! You've successfully authenticated», `git fetch` trae, y `master` tiene upstream `origin/master`. Andrés ya agregó la clave pública, así que el bloqueo del 2026-09-11 está levantado. **Se acabó el proyecto en un solo disco.** Ojo con lo que esto destapa: existe `origin/pc/auditoria-2026-09-10` **29 commits por delante de `master`**, con `master` como ancestro — ver §5.2b |

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
| Consola local de revisión | `desarrollo\sistema_ots\localpp.py` → http://127.0.0.1:8010 |
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
| ~~T2.21 · producción como fuente de informes~~ | Revisado, probado y commiteado el 2026-09-20 (`a99554e`) | 2026-09-19/20 | ✅ El diff que dejó el agente cortado se revisó línea por línea, compiló y se probó en vivo: T2.21.4 (endpoint apagado → reintenta y sale con 1) y T2.21.7 (espejo 2.323/2.323, `--recientes` probado). Lo que sigue —los 5 hallazgos del workflow adversarial, antes del `--ejecutar` de T2.21.2/T2.21.3— está en `PLAN_INDUSTEC.md` bajo T2.21, no aquí |
| ~~T2.14 · Pulido para las pruebas del cliente, T2.15, T2.17~~ | Conversación desde el PC de Andrés — rama `pc/pulido-2026-09-12` en GitHub | 2026-09-12 (noche) | ✅ **Fusionada en `master` el 2026-09-13** por la estación (ff, sin conflictos), junto con `pc/archivo-zona-franquicia-2026-09-13` (T2.18) |
| **T2.18 · Falta el `--ejecutar` de `t2_18_clasificar_archivo.py` y `t2_18_atender_pedidos_copia.py`** | Libre — nadie la tiene tomada | 2026-09-13/14 | El rescate de `_DEL_BUZON` (53 OTs) ya se hizo y está empujado. Queda pendiente por espacio: `G:\Mi unidad` (cupo real de Drive) tenía 4,3 GB libres frente a los ~5 GB a copiar — resolver el espacio en Drive antes de correr `--ejecutar`. También quedan 4 conflictos de correlativo en `_DEL_BUZON` esperando decisión de Andrés (detalle en el plan, T2.18) |
| ~~T2.5 · Captura — formulario único, v1 para revisión~~ | Conversación "app captura v1" | 2026-09-08 | ✅ Terminada y en §1b. El formulario único está desplegado y preguntado por pasos |
| ~~Buscador de órdenes y avisos por coincidencia parcial~~ | Conversación "cotejo SAP" | 2026-09-10 | ✅ **Commiteado el 2026-09-12 por la estación, con tres defectos corregidos** — ver la fila del buscador en §1b. Si esa conversación sigue viva: `Ui::normalizarBusqueda()` **cambió de implementación** (tabla de 123 entradas generada, no las 16 a mano) y `prueba_contratos.mjs` ahora ejecuta el PHP de verdad. Toma lo de `master` antes de seguir. Sus dos scripts de SAP (`t2_11`, `t2_12`) tardaron más en entrar a git, pero ya están: ambos commiteados el 2026-09-18, ver §5.2b |
| T2.12 · **solo queda T2.12.12**, las tres hojas de capacitación | Libre — nadie la tiene tomada | 2026-09-10 | **Escribe** en `SALIDAS IA\OTS\`, una hoja por rol. Todo lo demás de T2.12 está hecho y desplegado, incluida la verificación por rol (87 comprobaciones con ingreso real). Es la acción **D** de §11b |
| ~~Auditoría del robot del correo~~ | Conversación "auditoría correo→informes" (estación) | 2026-09-18 | ✅ **Terminada el 2026-09-18.** Cifras en §1c; qué construir, en `PLAN_INDUSTEC.md` **T2.21** (acción **H** de §11b). No tocó nada: solo lectura |
| **T2.21 · Cerrar la cadena del correo** | Libre — nadie la tiene tomada | 2026-09-18 | Cuando se tome: **escribe en el árbol canónico y en `ots`** (T2.21.2 + ingesta), así que choca con `t1_7_ingesta.py`, con los scripts de T1.6b y con `t2_4_normalizar_nuevas.py --ejecutar`. Va de a uno. T2.21.1 (leer `Trash`) y las simulaciones no bloquean nada |
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

---

## 7. El criterio ganado está en las skills

Seis skills en `.claude/skills/`, minadas del propio código de la Fase 1 para que no se pierda lo aprendido. Invócalas según lo que vayas a hacer:

| Skill | Cuándo |
|---|---|
| **`industec-invariantes`** | **Siempre, al empezar.** Rutas, permisos, invariantes y cómo se cierra una tarea |
| `industec-escritura-mysql` | Antes de cualquier `INSERT`/`UPDATE`/`DELETE` o cambio de esquema |
| `industec-lectura-excel` | Al leer un Excel de la administración o de SAP |
| `industec-extraccion-pdf` | Al extraer campos de un PDF por patrones |
| `industec-archivos-canonicos` | Al mover, renombrar o clasificar documentos del corpus |
| `industec-agentes-y-entregables` | Al construir un agente o un archivo que va a leer una persona |

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
