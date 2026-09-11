# Estado del proyecto INDUSTEC

> **Empieza por aquí.** Este archivo dice dónde vamos; [`PLAN_INDUSTEC.md`](PLAN_INDUSTEC.md) dice qué hay que construir y con qué criterios.
> Si vas a trabajar, **anótate primero en §5 (Trabajo en paralelo)** antes de tocar nada.

**Última actualización:** 2026-09-10
**Fase en curso:** 2 · Automatización — arrancada. La Fase 1 quedó sustancialmente cerrada
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
| Base de datos poblada | ✅ | **7.069 órdenes activas** · 9.071 equipos · 100 locales · 145 alias · 6.450 avisos SAP · 19 técnicos |
| Auditor de calidad (Agente 1) | ✅ | 2.644 observaciones abiertas, con veredicto editable por la administración |
| Consolidador de plan de zona (Agente 2) | ⚠️ v1 | 87,3% de coincidencia celda a celda en el piloto UIO |
| **Histórico en formato de planificación** | ✅ | **39 planes mensuales** de correctivo (7.863 filas, 3 zonas × 13 meses) + **seguimiento de preventivos** de 96 locales, reconstruidos desde las OTs y SAP. LOCAL, FECHA DE INICIO y ESTADO al 100%; EQUIPO al 99,5% |
| Respaldo TrueNAS | 🔒 | Bloqueado: falta acceso físico al equipo |
| Capacitación de cierre | 🔒 | Bloqueada: falta agendar con el personal |

**Cuadre del corpus, exacto contra los 7.333 PDFs originales:**
`7.070 (órdenes) + 5 (informes técnicos) + 25 (otros clientes) + 233 (duplicados descartados) = 7.333`

**Reparto de las órdenes activas:** CNLJ 2.589 · LARB 2.498 · UIO 1.978 · OTRA 4 — · Correctivo 6.349 · Preventivo 720

---

## 1b. Lo construido el 2026-09-06 — continuidad de datos y sistema nuevo

Todo lo de esta tabla está **entregado y con sus pruebas en verde**. Lo que no
corre todavía dice por qué.

| Pieza | Estado | Verificación |
|---|---|---|
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
| 🔴 **Dos endpoints entregaban datos del cliente sin sesión** | ✅ **Cerrados en el código el 10-sep, y en el servidor recién el 10-sep ~20:00**: hasta entonces seguían respondiendo 200 sin sesión (1,1 MB y 505 KB). Ver [`AUDITORIA_2026-09-10.md`](AUDITORIA_2026-09-10.md) §2 | `catalogos.php` servía los 100 locales con su correo, los 1.173 activos y **los nombres de los 19 técnicos** a cualquiera con la URL. `cronograma.php`, además, el cronograma completo, el padrón y **los 918 casos vivos con sus alertas** — y **sin filtrar por zona**. El mismo error las dos veces: el `.htaccess` cerró los `.json` y se dio por hecho que el `.php` que los sirve estaba cubierto. Ahora exigen sesión y el cronograma recorta por zona **en el servidor**. Datos personales del personal y correos del cliente: cuenta para el riesgo LOPDP ya registrado. Revisado y **correcto**: `aplicar_sql.php`, `verificar_esquema.php` y `minar.php` cortan con 404 fuera de la línea de órdenes, y `sync_casos.php` tiene su HMAC |
| **Cuatro defectos propios, encontrados al revisar y corregidos** | ✅ **2026-09-10** | Los cuatro fallaban **en silencio**. (1) La pantalla de novedades **sobrescribió `novedades.php`**, que ya estaba en uso como el extremo que consulta el buzón cada 30 s: `ui.js` recibía HTML donde esperaba JSON y la barra de «el buzón se actualizó» dejó de aparecer. Restaurado desde git; la pantalla pasó a `novedades_visita.php`. (2) `guia.js` se tragaba el **botón de enviar** dentro del último paso plegado: el técnico llenaba la orden y no tenía dónde pulsar. (3) El contenedor de novedades del formulario compartía el id `#novedades` con la barra del buzón, y a los 30 s le ponía `hidden` a lo que el técnico acababa de escribir. (4) Un **401 por sesión caducada** se trataba como «orden inválida» y no se reintentaba — veinte minutos de trabajo perdidos por volver a entrar. Se escribió `pruebas/prueba_contratos.mjs` (48 comprobaciones) para esa clase de defecto |
| **Tres suites de prueba nuevas** | ✅ **2026-09-10** | `prueba_48h.php` **96 comprobaciones · 0 fallos** (el reloj mide el veredicto y no la reparación; lo vencido cuenta ahora; ningún estado sale en crudo; el rojo de la antigüedad cae en el corte de 7 días). `prueba_graficos.mjs` **62 · 0** (total cero, un dato, negativos, basura, JSON roto, 8 porciones plegadas, el color sigue a la entidad). `prueba_contratos.mjs` **48 · 0** (los acuerdos entre JS y pantallas, y que los extremos con datos del cliente sigan exigiendo sesión). **Nada se abrió contra datos reales**: no hay base levantada en esta máquina, así que la verificación con los tres roles sigue pendiente en el sitio de pruebas |
| **`Casos::etiquetaEstado()` mostraba estados en crudo** | ✅ **2026-09-10** | Le faltaban `ATENDIDO` y `CERRADO_SIN_ATENCION`, así que esos casos salían como «CERRADO_SIN_ATENCION», en mayúsculas y con guion bajo, en la pantalla que mira la administradora. Ahora hay **una sola lista**, en `Ui::ESTADOS`, con los ocho estados y su explicación |
| **`ESPERA_REPUESTO` pasa a `Reconciliar::INTOCABLES`** | ✅ **2026-09-10** | Sin esto, la reconciliación habría marcado ATENDIDO al caso que espera una pieza —porque ese caso **sí** tiene informe: el técnico fue y diagnosticó— y la administradora lo habría cerrado en SAP con el equipo todavía parado |
| ⚠️ **El buzón solo trae el 37% de los casos de SAP** | 🔴 **por confirmar la causa** | El 0% de `Mant. Preventivo` **está explicado**: no se pide por correo, sigue cronograma. Lo que sigue sin causa es el **36% del correctivo**. Ver [`SALIDAS IA\OTS\HALLAZGO_BUZON_VS_SAP.md`](SALIDAS%20IA/OTS/HALLAZGO_BUZON_VS_SAP.md) |

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
| 2 | **Sacar el proyecto del único disco** | `git remote -v` no devuelve nada. Los 40 commits, las 7.069 órdenes y el árbol canónico están en un solo disco. La regla de las dos copias no se cumple para nada de lo que produjimos |
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
| **Habilitar SSH en Hostinger** (hPanel → Avanzado → Acceso SSH) | Toda la sincronización T2.4. **No bloquea** el parche de seguridad, que sube por el gestor de archivos |
| **Lista de técnicos vigentes** | El desplegable del formulario nuevo. El padrón ya está listo para marcar |
| **Decisión: ¿usuario por técnico o código de zona?** | La etapa de login del sistema nuevo |
| **Encuadre LOPDP: quién es responsable y quién encargado, y contrato de encargo INDUSTEC↔INDUSTECH** | Nada técnico, pero define quién debe actuar ante la exposición verificada. Ver `SISTEMA_COMPLETO.md` §5b |
| **Revisar si `cleanup.php` está en algún cron** | Nada, pero es lo más urgente: borra `uploads` y los logs sin verificar copia |

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
| **T2.12** | **Puesta en marcha de las interfaces por rol.** Doce subtareas con compuerta, en [`PLAN_INDUSTEC.md`](PLAN_INDUSTEC.md). Arranca con **aplicar la migración 007** (requiere aprobación). Lo construido está probado en local —206 comprobaciones, 0 fallos— pero **nunca se abrió contra la base real**: la verificación de alcance con los tres roles, la prueba sin señal de punta a punta y el reloj de 48 h contra datos reales siguen pendientes | Aprobación para la 007 y para desplegar | **No**: cada subtarea es compuerta de la siguiente |
| **T1.11-b** | Llevar el consolidador vivo (`agente2_consolidador.py`) al modelo del histórico: agrupar por (aviso, zona), arrastrar por evidencia y leer los campos SAP nuevos. Hoy sigue con la lógica vieja del 87,3% | nada | Sí |
| **T2.4.0–T2.4.4** | Continuidad de datos del sistema en producción: parches de seguridad, espejo verificado, purga con compuerta de hash, normalización y publicación. Ver [`ARQUITECTURA_SISTEMA_OTS.md`](ARQUITECTURA_SISTEMA_OTS.md) | SSH habilitado | Sí, salvo la purga |
| **T2.5.2** | Los controles del formato único como código probable, y medidos contra las 7.069 órdenes. **Hecho**: 14 pruebas en verde, informe en `SALIDAS IA\OTS\CONTROLES_MEDIDOS.md` | T2.5.1 | Sí |
| **T2.5.1** | Catálogos del formulario único: 100 locales, 1.173 activos en 94 locales, 222 tipos, 19 técnicos. **Hecho**; quedan 3 decisiones en `catalogos\COBERTURA.md` | nada | Sí |
| **Migración `003`** | Prepara la base para que el formulario escriba directo: `FORMULARIO_WEB` en `fuente`, tabla `correlativos` con reserva atómica, `email_queue`. **Escrita, no aplicada**: cambia el esquema y requiere aprobación | nada | No: va antes de T2.1.1 |
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

| Tarea | Conversación / responsable | Desde | Recursos que bloquea |
|---|---|---|---|
| T2.5 · Captura — formulario único, v1 para revisión | Conversación "app captura v1" | 2026-09-08 | Solo `desarrollo/sistema_ots/app/publico/` (código nuevo). No toca base ni árbol canónico |
| Auditoría completa y sus correcciones | Conversación desde el PC de Andrés — rama `pc/auditoria-2026-09-10` en GitHub | 2026-09-10 | Corrigió código en `app/publico/` (**no** tocó `casos.php`, `ordenes.php`, `Ui.php`, `estilo.css` ni `busqueda.js`), en la 007 y en `t2_6`, `t2_9` y `t2_10`. En el servidor subió `catalogos.php` y `cronograma.php` (la fuga) y, con aprobación de Andrés, `sw.js` v4, `pdf.php`, `nucleo/Reconciliar.php`, `nucleo/Auth.php` y los dos `.htaccess`; pasó a NUEVO las 2 filas huérfanas (bitácora 937). **Sigue anotada hasta que la estación fusione la rama**: quien despliegue antes pisa esas correcciones. Detalle en [`AUDITORIA_2026-09-10.md`](AUDITORIA_2026-09-10.md) |
| T2.12.1 y T2.12.3 · aplicar la 007 y desplegar el rediseño en el sitio de pruebas | Conversación desde el PC de Andrés — rama `pc/auditoria-2026-09-10` | 2026-09-10 (noche) | Decisión de Andrés de esa noche: el sitio de pruebas no lo usa nadie de INDUSTEC y se puede cambiar lo que haga falta; solo quedan vedados la información de la administradora en el Google Drive y el sistema de órdenes de producción (`yellow-elephant`). **Hecho el 2026-09-11:** la 007 aplicada dos veces sin duplicar (`verificar_esquema.php` TODO OK; 20 de 20 comprobaciones del pie) y los 48 archivos del rediseño desplegados y verificados por hash. La verificación por rol también está hecha (T2.12.3 a T2.12.6, T2.12.9 y T2.12.10: 87 comprobaciones con ingreso real); T2.12.7 y T2.12.8 también pasan, con un navegador de verdad; falta T2.12.12. **Mientras esta fila siga aquí, la estación no despliega**: al fusionar la rama verá el resultado. |

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
| 2026-09-11 | **T2.12.1 a T2.12.3 desde el PC**, mientras la estación no tenía créditos. Decisión de Andrés: el sitio de pruebas no lo usa nadie de INDUSTEC y se puede cambiar lo que haga falta; solo quedan vedados el Google Drive de la administradora y el sistema de órdenes de producción. Revisión previa con agentes: el paquete, sin bloqueantes; antes de subir se corrigieron los avisos que se perdían en el formulario del técnico y el pendiente que no seguía al caso derivado. Se midió la zona del PHP de la web: UTC, como la base (la hora del negocio queda como T2.13.7). Respaldo, 007 dos veces, despliegue de 48 archivos y barrido sin sesión, todo verificado. Se borraron del servidor `alta_padron.php` (versión vieja) y `nucleo/config.php.previo` (copia vieja de la configuración). **Verificación por rol** con cuentas de prueba: 87 comprobaciones; corregido el error 500 del veredicto y los cierres (intercalación de la conexión en `Db.php`) y los rechazos por alcance que no quedaban en la bitácora. Para probar se asignaron a un técnico de prueba dos casos reales de UIO (se revierten con `deshacer_prueba.php`): los sintéticos no se ven hasta T2.13.3. T2.12.7 y T2.12.8, con navegador de verdad. **El CDN de Hostinger entregaba el `sw.js` v2 y el `estilo.css` anterior** en su copia comprimida —la que reciben los navegadores— horas después de subir los nuevos: corregido con `no-cache` para el código en el `.htaccess` y una purga del CDN hecha por Andrés; `t2_10` ahora compara lo que entrega la web en sus dos variantes |
