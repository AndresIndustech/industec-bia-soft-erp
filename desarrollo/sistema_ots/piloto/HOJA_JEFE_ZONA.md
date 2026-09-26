# Hoja del jefe de zona — asignar, validar y seguir a tu equipo

> Para el jefe de zona de UIO durante el piloto. Se lee en 15 minutos. Las pantallas son capturas reales del sitio de pruebas, tomadas con una cuenta de prueba de jefe de UIO: los nombres del personal están reemplazados por «Técnico 1», «Técnico 2»…
>
> **Las capturas son del 13 de septiembre de 2026 y muestran rótulos anteriores.** Desde el 24 de septiembre de 2026 el sistema usa el vocabulario único (los mismos términos para todos los roles), y esta hoja ya usa esos términos. Si una captura no coincide con la hoja, manda el texto de la hoja.

## Las palabras que usa el sistema

- **Orden**: el trabajo que pide KFC, identificado por su **aviso SAP**.
- **OT INDUSTEC**: el documento que emite el técnico, **de evaluación** (la visita dejó la orden abierta) o **de cierre**.
- **Solicitud**: lo que abre el técnico cuando el equipo no quedó operativo.
- Tú **validas** la solicitud; KFC **decide**; la administración **resuelve** las órdenes que le mandas a revisión.

## Qué ves y qué no

Ves **tu zona**: sus órdenes, sus técnicos, sus solicitudes de repuesto, sus novedades, su cronograma y sus reportes. La regla se aplica en el servidor, no escondiendo botones: pedir otra zona por la dirección devuelve vacío. Lo único de todas las zonas es el **Archivo de OT INDUSTEC** (solo consulta) y el **Aprendizaje**.

Entras en `https://darkviolet-armadillo-872352.hostingersite.com/ot/login.php` desde cualquier computador (también funciona en el celular). La primera vez el sistema te pide cambiar la clave temporal. Ver [`CUENTAS.md`](CUENTAS.md).

## 1. Inicio: lo que te toca ahora

![Inicio del jefe de zona](capturas/jefe/panel_php.png)

El tablero no es un menú: es la lista de lo que te toca hoy, con la cifra y el botón que te deja delante de esas órdenes. Las líneas que aparecen cuando hay algo:

- **N órdenes sin asignar** en tu zona → **«Asignar»** te lleva a Asignación, a la tabla «Sin asignar».
- **N solicitudes vencidas (más de 48 h sin validar)** → **«Validar»**: un equipo deshabilitado en un local cuya solicitud sigue por validar.
- **N solicitudes por validar, a tiempo** → **«Ver»**: todavía corre el plazo de 48 horas.
- **N asignadas hace 3+ días, a espera de informe técnico** → **«Revisar»**: técnicos que tienen la orden y todavía no emitieron ninguna OT INDUSTEC.
- **N novedades reportadas o en estudio, por decidir** → lo que reportaron tus técnicos en las visitas.
- **N fuera del área, por decidir** → **«Mirar»**: órdenes que parecen no ser de INDUSTEC; la administración resuelve.

Debajo va la **tarjeta de tu zona** (sección siguiente), «Equipos deshabilitados · el plazo de 48 horas» (Vencidas (48 h) · Por validar, a tiempo · Validadas a tiempo · Validadas tarde) y «Cómo va el buzón» (cuándo llegó la última orden de SAP, Órdenes nuevas en 7 días, Con fecha SAP hoy, A espera de repuesto, TOTAL DE ÓRDENES ABIERTAS).

### La tarjeta de tu zona

Ves **una sola tarjeta, la de tu zona**, con los mismos términos que usa la administración (no ves el total general de las tres zonas). Tiene cuatro filas:

| Fila | Qué cuenta | Debajo, «de ellas» |
|---|---|---|
| **ÓRDENES ABIERTAS** | Órdenes que ya tienen **OT INDUSTEC de evaluación** y les falta la de cierre, más **todas** las que están a espera de repuesto (aunque no tengan OT: hubo visita o diagnóstico) | a espera de repuesto; sin asignar (una OT INDUSTEC cuya firma no se reconoció), solo si hay |
| **ÓRDENES A ESPERA DE INFORME TÉCNICO** | Órdenes **sin ninguna OT INDUSTEC** emitida: sin asignar, o asignadas sin OT todavía | sin asignar; asignadas hace 3+ días; fuera del área, por decidir; con OT INDUSTEC no emitida, solo si hay |
| **EQUIPOS DESHABILITADOS** | Órdenes del total cuyo dato más reciente del equipo dice **Deshabilitado**. **No se suma**: es una parte del total | vencidas (48 h); con equipo operativo; sin dato del equipo, solo si hay |
| **TOTAL DE ÓRDENES ABIERTAS** | **ÓRDENES ABIERTAS + ÓRDENES A ESPERA DE INFORME TÉCNICO**: todo lo que INDUSTEC todavía no termina en tu zona | — |

El pie dice lo que queda fuera del total (**atendidas, por cerrar en SAP** y **cerradas sin atención, sin regularizar ante KFC**) y lo que ya está dentro (**en revisión, por resolver**). Cada cifra es un enlace a tu buzón con exactamente esas órdenes. Si una fuente no se pudo leer, la cifra dice **«no disponible»**, nunca 0.

**No es la cifra de SAP.** La tarjeta cuenta las órdenes de los últimos 90 días que llegaron por el correo de SAP (correctivos, bajas y constructivos). El correo no avisa reaperturas ni cierres y trae más o menos la mitad de las órdenes abiertas en SAP, así que la cifra puede salir más baja que la de SAP. El ícono ⓘ lo recuerda.

## 2. Buzón de órdenes: las órdenes de tu zona

![El buzón de órdenes](capturas/jefe/casos_php.png)

Cada fila es una orden: aviso SAP, local, zona, qué pide KFC, prioridad, su **OT INDUSTEC**, la fecha comprometida y el estado. Filtra por estado, OT INDUSTEC, local o texto (el buscador entiende `2466` como parte de `OT-2466-…` y el aviso con o sin ceros).

| Estado | Qué significa |
|---|---|
| sin asignar | Llegó del correo de SAP y todavía no tiene técnico |
| asignada | Ya tiene técnico |
| a espera de repuesto | El técnico ya fue o diagnosticó, y el trabajo depende de un repuesto, un taller, una garantía o una baja |
| en revisión | La mandaste a la administración con un motivo (no nos compete, el local no corresponde, hay que consultar) |
| atendida, por cerrar en SAP | INDUSTEC terminó su parte (OT INDUSTEC de cierre); falta que la administración la cierre en SAP |
| cerrada en SAP / no nos compete | La administración ya la resolvió |
| cerrada sin atención | Pasó una semana sin ninguna OT INDUSTEC y se cerró; la administración la regulariza ante KFC |

Tus acciones sobre una orden:

- **Asignar** o **Reasignar** (también desde la pantalla Asignación).
- **Mandar a revisión**: se la pasas a la administración con el motivo escrito; ella la resuelve.
- **Pedir seguimiento**: le escribes una nota al técnico («¿cómo va la orden del local X?»); le llega a sus **Notificaciones** y queda en el historial de la orden.

«Resolver» y «Derivar» son de la administración. «Qué hace cada acción», al pie de la pantalla, lo explica.

## 3. Asignación: a quién le toca cada orden

![Asignación por zona](capturas/jefe/asignacion_php.png)

Tu bloque de zona tiene cuatro cifras arriba: **Sin asignar**, **Sin órdenes asignadas** (técnicos libres), **Con 8 o más** (técnicos cargados) y **Asignadas**. Toca **«Sin asignar»** y bajas a la tabla de esas órdenes.

Debajo, **tu equipo ordenado por carga**: cada técnico con sus órdenes **asignadas**, cuántas están **a espera de repuesto** y cuántas llevan ya su **OT de cierre**, y el distintivo «asignadas hace 3+ días» si tiene órdenes quietas. Así asignas al que tiene menos.

![La tabla «Sin asignar»](capturas/jefe/asignacion_por_repartir.png)

En la tabla, cada orden trae el desplegable con **los técnicos de tu zona**; eliges y pulsas **Asignar**. Al técnico le aparece la orden en **Mis órdenes** y una notificación en el momento. Si necesitas asignar a un técnico de **otra zona** (un local vecino), el desplegable lo ofrece aparte y te pide marcar «confirmo que es de otra zona»: se permite, y queda en la bitácora.

Reasignar es lo mismo sobre una orden ya asignada: el técnico anterior y el nuevo reciben cada uno su notificación.

## 4. Repuestos y equipos: validar la solicitud

![Repuestos y equipos](capturas/jefe/pendientes_php.png)

Cuando un técnico envía una OT INDUSTEC con el equipo que **no quedó operativo**, se abre una **solicitud** en estado **por validar**, con su diagnóstico y las piezas que pidió. Tu parte es **validar**: confirmar que el diagnóstico es correcto y que la vía es la que corresponde. La pantalla abre por defecto en **«Por validar»**.

![Validar la solicitud](capturas/jefe/pendientes_validar.png)

**Validar la solicitud** te pide la vía (repuesto, reparación en taller, garantía o baja) y una nota. Con tu validación queda **validada, por registrar en SAP**; la administración la **registra en SAP** con el número del requerimiento y pasa a **pendiente OK de OP´S** hasta que KFC decida (envía el repuesto, a taller de INDUSTEC, a otro proveedor o da de baja el equipo). Cuando el técnico instala y envía la OT INDUSTEC de cierre de ese equipo, la solicitud queda **terminada** sola.

El plazo de **48 horas** mide **tu validación**: es la decisión de INDUSTEC. Lo que tarde KFC se mide aparte y no cuenta contra la zona.

También puedes **Responder** en el hilo de la solicitud (el técnico lo ve en sus Notificaciones) y usar los filtros **Vencidas (48 h)**, **PENDIENTE OK OP´S**, **Compromisos atrasados**, **En trámite** y **Terminadas y canceladas** para hacer seguimiento.

## 5. Novedades de las visitas

![Novedades](capturas/jefe/novedades_visita_php.png)

Lo que el técnico vio en el local y no era su orden: otro equipo fallando (el correctivo que se viene) o un problema **de otra área** (eléctrico, ventilación, desagüe, obra civil) que hace fallar los equipos una y otra vez. El técnico **propone** de quién es; tú decides con **«¿Qué se hace con esta novedad?»**: **con aviso SAP** (se le pide el aviso a KFC), **la asume INDUSTEC**, **en estudio**, **descartada** con motivo, o **resuelta**. Al técnico le llega una notificación con tu decisión.

**«Registrar una novedad»** sirve para anotar una que llegó por teléfono o por WhatsApp, eligiendo el local del maestro.

## 6. Preventivos: el cronograma de tu zona

![El cronograma](capturas/jefe/cronograma_html.png)

El cronograma con los ingresos preventivos de tus locales, en tres vistas: **Lo que toca**, **El mes** y **El año y KFC**. Arriba, una frase con lo que hay que hacer hoy. Cada ingreso está **Ejecutado**, **Pendiente** (también «arranca en 3 días o menos», «sin agendar» o «en ejecución») o **Atrasado** (también «atrasado · por marcar como ejecutado»). Al tocar un ingreso se abre su panel con:

- **Kit de mantenimiento**: confirmar que llegó (o marcarlo pendiente).
- **Reagendar**: fecha nueva **con motivo obligatorio** (kit no llegó, el local no abrió, técnico enfermo…). La fecha acordada con KFC se conserva: el cumplimiento se mide contra ella.
- **Marcar como ejecutado**: cuando la OT INDUSTEC preventiva está enviada; se anota a tiempo o tarde.
- **Agendar**: para un local del mes que quedó sin fecha.
- **Movimientos del ingreso**: lo que pasó con ese ingreso (agenda, reagenda, kit, ejecución, notas).

Al técnico le llega el ingreso en su cronograma, y el formulario le abre prellenado en preventivo con el local y sus equipos.

## 7. Reportes de tu zona

![Reportes](capturas/jefe/reportes_php.png)

El **Tablero de servicio** con tus datos: salud del servicio, volumen, **rendimiento por técnico** (órdenes asignadas, con OT INDUSTEC, las que se concluyen en una visita, días a la primera atención, solicitudes vencidas, novedades), **cumplimiento del preventivo** (ejecutados a tiempo y tarde, atrasados, sin agendar, reagendados y sus motivos), locales que repiten y qué hace fallar los equipos. Puedes cambiar el **mes** y **descargar en Excel, PDF o PowerPoint** con el formato de INDUSTEC y los mismos términos de la pantalla.

## 8. Archivo y Aprendizaje

![El archivo de OT INDUSTEC](capturas/jefe/ordenes_php.png)

**Archivo de OT INDUSTEC**: las de las tres zonas (las de la app y las históricas), con filtros por zona, local, técnico, fechas y origen; ver, descargar y **compartir** (enlace de 24 horas para el local). Solo consulta; cada acción queda registrada.

![Aprendizaje](capturas/jefe/documentos_php.png)

**Aprendizaje**: manuales, guías y comunicados. Puedes **subir** un manual o una guía (por tipo de equipo y zona); queda **por aprobar** hasta que la administración lo apruebe y se publique con su versión. Lo que propongan tus técnicos también pasa por ahí.

## 9. Lo que el sistema te evita hacer, y por qué

- Resolver o derivar una orden: es de la administración, que la cierra en SAP.
- Cerrar en SAP una orden con una solicitud en trámite: primero se termina la solicitud.
- Borrar algo: nada se borra; se cancela o se corrige con nota, y la bitácora guarda quién y cuándo.
- Ver la clave de un técnico: si la olvidó, la administración le genera una nueva.

## Si algo no funciona

[`COMO_REPORTAR_FALLOS.md`](COMO_REPORTAR_FALLOS.md): a quién escribir y qué datos mandar.
