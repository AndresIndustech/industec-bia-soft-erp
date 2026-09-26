# Qué probar — guion del piloto de UIO, día 1 a día 5

> Cinco días de trabajo real con la app **además** del formulario viejo. Cada día tiene un objetivo y una lista por rol con el resultado esperado. Lo que no salga como dice aquí se reporta con [`COMO_REPORTAR_FALLOS.md`](COMO_REPORTAR_FALLOS.md).
>
> Los nombres de pantallas, pestañas y estados son los del vocabulario único (24 de septiembre de 2026): **orden** es el trabajo que pide KFC (con su aviso SAP) y **OT INDUSTEC** es el documento que emite el técnico, de evaluación o de cierre.

**Criterio del piloto** (PLAN, T2.16): al menos **50 OT INDUSTEC correctivas y 5 preventivas** enviadas desde la app **sin una OT INDUSTEC perdida ni duplicada**, y KFC ha visto el PDF nuevo. Una OT INDUSTEC perdida o dos duplicadas paran el piloto hasta entender por qué.

**Regla de los cinco días:** el formulario viejo sigue mandándose. La OT INDUSTEC de la app lleva número de la serie de pruebas (90xx); la del formulario viejo, el número real. Al corte, la app continúa la numeración real.

## Día 1 · Entrar, instalar, mirar

**Objetivo:** todos entran, cambian la clave y reconocen su pantalla.

| Rol | Prueba | Resultado esperado |
|---|---|---|
| Todos | Entrar con la clave temporal | Pide cambiarla antes de mostrar nada; la nueva funciona; la temporal deja de valer |
| Todos | Entrar desde un segundo aparato con la misma cuenta | Pregunta si desplaza la sesión; el primero queda fuera |
| Técnico | Agregar la app a la pantalla de inicio; abrirla en modo avión | Abre, muestra Mis órdenes y el formulario carga los locales desde la copia local |
| Técnico | Mis órdenes: Asignadas, A espera de repuesto, Historial, Notificaciones | Sus órdenes de SAP, ninguna de otro técnico; las 90xx aparecen como de prueba |
| Técnico | Archivo → abrir el PDF de una OT INDUSTEC de otra zona | Abre; en la bitácora queda «consultar» con su usuario |
| Jefe de zona | Inicio y Buzón de órdenes | Solo UIO: una sola tarjeta, la de ZONA UIO; pedir `?zona=CNLJ` en la dirección devuelve vacío |
| Administración | Inicio: «Por zona» y «Lo que te toca ahora» | La línea del TOTAL GENERAL DE ÓRDENES ABIERTAS y tres tarjetas (ZONA UIO, ZONA LARB, ZONA CUENCA-LOJA) con cuatro filas; cada cifra abre el Buzón de órdenes con **el mismo número de filas** |
| Administración | En cada tarjeta, sumar las filas 1 y 2 | ÓRDENES ABIERTAS + ÓRDENES A ESPERA DE INFORME TÉCNICO = TOTAL DE ÓRDENES ABIERTAS; EQUIPOS DESHABILITADOS no se suma |
| Administración | Usuarios: la lista de UIO | 1 jefe + 5 técnicos activos; las cuentas `*_prueba_*` inactivas |

## Día 2 · La primera OT INDUSTEC y la asignación

**Objetivo:** el ciclo básico: SAP → asignar → OT INDUSTEC → PDF.

| Rol | Prueba | Resultado esperado |
|---|---|---|
| Administración | Inicio → «órdenes sin asignar» → Asignar | Llega a la tabla «Sin asignar» de UIO |
| Jefe de zona | Asignar dos órdenes a dos técnicos distintos | A cada técnico le aparece la orden en Asignadas y una notificación; el equipo se reordena por carga |
| Jefe de zona | Reasignar una de esas órdenes | El técnico anterior y el nuevo reciben cada uno su notificación |
| Técnico | Emitir → «De mis órdenes» → la orden asignada | Local, equipo y tipo llenos; el equipo del aviso preseleccionado |
| Técnico | Elegir el administrador del local de los ya ingresados | Aparece en la lista; si no está, se escribe y queda para la próxima |
| Técnico | Actividades: elegir un diagnóstico pre-redactado y editarlo | El texto se puede completar |
| Técnico | Estado de la OT INDUSTEC: Cerrada; fotos (3), firma, «Revisar y enviar» | Muestra el número 90xx y «Ver PDF»; el PDF trae fotos y firma |
| Técnico | Enviar una OT INDUSTEC **sin señal** y volver a la señal | Queda «en cola», sale sola, aparece en Historial con su número; **no se duplica** al reintentar |
| Jefe de zona | Buzón de órdenes | La orden pasó de «asignada» a «atendida, por cerrar en SAP» sola, con la OT INDUSTEC de cierre enlazada; en la tarjeta sale del total y pasa al pie |
| Administración | Buzón de órdenes → «Ya la cerré en SAP» sobre esa orden | Pasa a «cerrada en SAP»; sobre una orden «asignada» (sin OT INDUSTEC) ese botón no aparece y el sistema **no** deja darla por cerrada en SAP |
| Administración | Archivo de OT INDUSTEC | La OT INDUSTEC nueva aparece con su PDF; «Compartir» da un enlace que abre sin sesión y caduca a las 24 h |

## Día 3 · Un equipo que no quedó operativo: el repuesto

**Objetivo:** el flujo completo de la solicitud de repuesto, de la solicitud a la OT INDUSTEC de cierre.

| Rol | Prueba | Resultado esperado |
|---|---|---|
| Técnico | OT INDUSTEC con «No, el equipo no quedó operativo» y «El equipo quedó deshabilitado»: qué encontró, qué equipo, qué repuesto | La solicitud aparece en Repuestos como **por validar**; la orden pasa a «a espera de repuesto» y en la tarjeta cuenta en ÓRDENES ABIERTAS y en EQUIPOS DESHABILITADOS |
| Técnico | «Insistir» al día siguiente | Queda en el hilo con fecha; cuenta como insistencia del técnico |
| Jefe de zona | Repuestos y equipos → «Por validar» → Validar la solicitud (vía y nota) | Pasa a **validada, por registrar en SAP**; el técnico recibe una notificación |
| Administración | Intentar registrar en SAP **sin** número | El sistema lo rechaza |
| Administración | Registrar en SAP con el número del requerimiento | Pasa a **pendiente OK de OP´S** |
| Administración | «KFC decidió»: probar «a otro proveedor» sin nombre del tercero | Rechazado; con nombre, se anota y la solicitud queda **con otro proveedor** |
| Administración | En otra solicitud, «KFC decidió: envía el repuesto» | Estado **repuesto despachado**; el técnico recibe la notificación |
| Técnico | Instalar y enviar la OT INDUSTEC de cierre de ese equipo | La solicitud pasa a **terminada** sola; la orden, a «atendida, por cerrar en SAP» |
| Administración | Reportes → «La salud del servicio» | El cumplimiento de 48 h refleja la validación del jefe (a tiempo o tarde) |
| Administración | Bitácora, filtrada por la solicitud | Los cinco pasos con quién y cuándo |

## Día 4 · Preventivos, novedades y equipos nuevos

**Objetivo:** lo que no es un correctivo.

| Rol | Prueba | Resultado esperado |
|---|---|---|
| Jefe de zona | Preventivos: confirmar el kit de un ingreso; reagendar otro con motivo | El primero muestra kit confirmado; el segundo conserva la fecha acordada y muestra la nueva; el motivo sale en Reportes y en «Movimientos del ingreso» |
| Jefe de zona | Agendar un local sin fecha | Pide la fecha; el ingreso aparece en el mes; no deja agendarlo dos veces |
| Técnico | Preventivos → el ingreso → «OT INDUSTEC del día 1» | El formulario abre en preventivo con local, día y equipos |
| Técnico | Enviar la OT INDUSTEC preventiva | Número 90xx, PDF; el jefe puede «Marcar como ejecutado» y queda **Ejecutado**, a tiempo o tarde |
| Técnico | En una OT INDUSTEC, «Equipo nuevo / no está en la lista» (marca, modelo, serie) | Aparece en la lista del local como **por confirmar**; a la administración le aparece en Equipos nuevos |
| Administración | Equipos nuevos → aprobar uno, rechazar otro con nota | El aprobado sigue en la lista; el rechazado desaparece de ella; ambos en la bitácora |
| Técnico | «+ Reportar una novedad» en una OT INDUSTEC (otra área: eléctrico) | Al jefe le aparece en Novedades, «Por decidir» y en «De otras áreas» |
| Jefe de zona | Decidir: con aviso SAP | El técnico recibe la notificación; la novedad queda «con aviso SAP» |
| Administración | Registrar una novedad que llegó por teléfono | Con local del maestro; se puede resolver después |
| Técnico | OT INDUSTEC con «el trabajo lo hizo otro proveedor» (nombre y qué hizo) | El PDF trae el bloque «Trabajo con otro proveedor» |

## Día 5 · Aprendizaje, reportes para KFC y cierre de la semana

**Objetivo:** lo que se entrega hacia afuera y hacia adentro.

| Rol | Prueba | Resultado esperado |
|---|---|---|
| Jefe de zona | Aprendizaje → subir un manual (PDF) de un tipo de equipo | Queda «por aprobar»; el jefe lo ve en «Lo que subiste y todavía no está publicado» |
| Técnico | Aprendizaje → proponer una guía | Igual, «por aprobar»; el técnico no la ve publicada hasta la aprobación |
| Administración | Aprobar el manual, rechazar la guía con nota | El manual queda publicado con versión 1; el rechazo le llega al técnico |
| Administración | Publicar un comunicado con acuse | Los técnicos ven «Marcar como leído»; la administración ve «visto por N de M» |
| Técnico | Abrir el manual desde el celular | Abre; queda «abrir documento» en la bitácora |
| Administración | Reportes → UIO → mes actual → Descargar Excel, PDF y PowerPoint | Los tres archivos abren, con los mismos términos de la pantalla (entre ellas, la hoja «TOTAL DE ÓRDENES ABIERTAS»); las cifras coinciden con la pantalla |
| Administración | Inicio → «Cerrar por falta de atención» (órdenes sin OT INDUSTEC hace más de 7 días) | Pide confirmación; las deja «cerradas sin atención», para regularizar; **no** toca las que están a espera de repuesto |
| Administración | Bitácora → exportar CSV de la semana | Abre en Excel con todas las acciones de los cinco días |
| Administración | Usuarios → «Ver actividad» de un técnico | La bitácora filtrada por esa persona |
| Todos | Contar: OT INDUSTEC enviadas por la app vs. por el formulario viejo | Las mismas; ninguna perdida, ninguna duplicada |

## Lo que se anota al final de cada día

Un mensaje en el grupo del piloto, de la administración o del jefe de zona, con cuatro líneas:

```
OT INDUSTEC por la app hoy: N (correctivas / preventivas)
Fallos reportados: N (bloquean / estorban / mejoras)
Algo que salió mejor que con el formulario viejo:
Algo que salió peor:
```

Con eso, el viernes se decide si el piloto sigue una segunda semana, si se extiende a otra zona o si se corrige algo antes.
