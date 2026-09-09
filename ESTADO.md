# Estado del proyecto INDUSTEC

> **Empieza por aquí.** Este archivo dice dónde vamos; [`PLAN_INDUSTEC.md`](PLAN_INDUSTEC.md) dice qué hay que construir y con qué criterios.
> Si vas a trabajar, **anótate primero en §5 (Trabajo en paralelo)** antes de tocar nada.

**Última actualización:** 2026-09-08
**Fase en curso:** 2 · Automatización — arrancada. La Fase 1 quedó sustancialmente cerrada
**Repositorio git:** la raíz del proyecto, `D:\INDUSTECH IA` — cubre el código **y** estos documentos, para que quede historial de las decisiones. Fuera del control de versiones: `ENTRADAS IA`, `SALIDAS IA`, el entorno virtual y las credenciales.

> **Si esta es una conversación nueva: empieza leyendo esto, en orden.**
> 1. Esta sección y la §1b completas.
> 2. [`SISTEMA_COMPLETO.md`](SISTEMA_COMPLETO.md) — el sistema de punta a punta, incluida la §5b de LOPDP (recién ampliada, ver abajo).
> 3. §3 de aquí abajo, para lo que sigue.
> No hace falta releer el resto del repositorio: estos tres documentos son la
> foto completa al 2026-09-08.

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
