# Qué probar — guion del piloto de UIO, día 1 a día 5

> Cinco días de trabajo real con la app **además** del formulario viejo. Cada día tiene un objetivo y una lista por rol con el resultado esperado. Lo que no salga como dice aquí se reporta con [`COMO_REPORTAR_FALLOS.md`](COMO_REPORTAR_FALLOS.md).

**Criterio del piloto** (PLAN, T2.16): al menos **50 órdenes correctivas y 5 preventivas** enviadas desde la app **sin una orden perdida ni duplicada**, y KFC ha visto el PDF nuevo. Una orden perdida o dos duplicadas paran el piloto hasta entender por qué.

**Regla de los cinco días:** el formulario viejo sigue mandándose. La orden de la app lleva número de la serie de pruebas (90xx); la del formulario viejo, el número real. Al corte, la app continúa la numeración real.

## Día 1 · Entrar, instalar, mirar

**Objetivo:** todos entran, cambian la clave y reconocen su pantalla.

| Rol | Prueba | Resultado esperado |
|---|---|---|
| Todos | Entrar con la clave temporal | Pide cambiarla antes de mostrar nada; la nueva funciona; la temporal deja de valer |
| Todos | Entrar desde un segundo aparato con la misma cuenta | Pregunta si desplaza la sesión; el primero queda fuera |
| Técnico | Agregar la app a la pantalla de inicio; abrirla en modo avión | Abre, muestra la bandeja y el formulario carga los locales desde la copia local |
| Técnico | Bandeja: Pendientes, Esperando, Atendidas, Avisos | Sus casos de SAP, ninguno de otro técnico; las 90xx aparecen como de prueba |
| Técnico | Archivo → abrir el PDF de una orden de otra zona | Abre; en la bitácora queda «consultar» con su usuario |
| Jefe de zona | Inicio y Buzón | Solo UIO; pedir `?zona=CNLJ` en la dirección devuelve vacío |
| Administración | Inicio: «Por zona» y «Lo que te toca ahora» | Tres columnas con cifras; cada cifra abre el Buzón filtrado |
| Administración | Usuarios: la lista de UIO | 1 jefe + 5 técnicos activos; las cuentas `*_prueba_*` inactivas |

## Día 2 · La primera orden y el reparto

**Objetivo:** el ciclo básico: SAP → asignar → orden → PDF.

| Rol | Prueba | Resultado esperado |
|---|---|---|
| Administración | Inicio → «casos sin repartir» → Asignar | Llega a la tabla «por repartir» de UIO ya filtrada |
| Jefe de zona | Asignar dos casos a dos técnicos distintos | A cada técnico le aparece el caso en Pendientes y un aviso; el equipo se reordena por carga |
| Jefe de zona | Reasignar uno de esos casos | Al anterior le llega «te quitaron el caso»; al nuevo, «te asignaron» |
| Técnico | Nueva orden → De mis órdenes → el caso asignado | Local, equipo y tipo llenos; el equipo del aviso preseleccionado |
| Técnico | Elegir el administrador del local de los ya ingresados | Aparece en la lista; si no está, se escribe y queda para la próxima |
| Técnico | Actividades: elegir un diagnóstico pre-redactado y editarlo | El texto se puede completar |
| Técnico | Fotos (3), firma, «Revisar y enviar» | Muestra el número 90xx y «Ver PDF»; el PDF trae fotos y firma |
| Técnico | Enviar una orden **sin señal** y volver a la señal | Queda «en cola», sale sola, aparece en Atendidas con su número; **no se duplica** al reintentar |
| Jefe de zona | Buzón | El caso pasó de «asignado» a «atendido» solo, con la orden enlazada |
| Administración | Buzón → Veredicto → Resuelto sobre ese caso | Se cierra; sobre un caso «asignado» (sin orden) el sistema **no** deja dar «resuelto» |
| Administración | Archivo | La orden nueva aparece con su PDF; «Compartir» da un enlace que abre sin sesión y caduca a las 24 h |

## Día 3 · Un equipo que quedó parado: el repuesto

**Objetivo:** el flujo completo de repuestos, de la solicitud al cierre.

| Rol | Prueba | Resultado esperado |
|---|---|---|
| Técnico | Orden **no concluida**: qué encontró, qué equipo, qué repuesto | El pendiente aparece en Repuestos como **Solicitado**; el caso pasa a «espera repuesto» |
| Técnico | «Insistir» al día siguiente | Queda en el hilo con fecha; cuenta como insistencia del técnico |
| Jefe de zona | Repuestos → «por validar» → Validar (vía y nota) | Pasa a **Validado por el jefe**; el técnico recibe un aviso |
| Administración | Intentar registrar en SAP **sin** número | El sistema lo rechaza |
| Administración | Registrar en SAP con el número del requerimiento | **Registrado en SAP** → **Esperando a KFC** |
| Administración | «KFC decidió»: probar «Otro proveedor» sin nombre del tercero | Rechazado; con nombre, se anota y resuelve |
| Administración | En otro pendiente, «KFC decidió: Repuesto enviado» | Estado **Repuesto enviado**; el técnico recibe el aviso |
| Técnico | Instalar y enviar la orden **concluida** de ese equipo | El pendiente pasa a **Resuelto** solo; el caso, a «atendido» |
| Administración | Reportes → «La salud del servicio» | El cumplimiento de 48 h refleja la validación del jefe (a tiempo o tarde) |
| Administración | Bitácora, filtrada por el pendiente | Los cinco pasos con quién y cuándo |

## Día 4 · Preventivos, novedades y equipos nuevos

**Objetivo:** lo que no es un correctivo.

| Rol | Prueba | Resultado esperado |
|---|---|---|
| Jefe de zona | Preventivos: confirmar el kit de un ingreso; reagendar otro con motivo | El primero muestra kit confirmado; el segundo conserva la fecha original y muestra la nueva; el motivo sale en Reportes |
| Jefe de zona | Agendar un local sin fecha | Pide la fecha; el ingreso aparece en el mes; no deja agendarlo dos veces |
| Técnico | Preventivos → el ingreso → «Llenar la orden» | El formulario abre en preventivo con local, día y equipos |
| Técnico | Enviar la orden preventiva | Número 90xx, PDF; el jefe puede «cerrar el ingreso» y queda a tiempo o tarde |
| Técnico | En una orden, «Equipo nuevo / no está en la lista» (marca, modelo, serie) | Aparece en la lista del local marcado como propuesto; a la administración le aparece en Equipos nuevos |
| Administración | Equipos nuevos → aprobar uno, rechazar otro con nota | El aprobado sigue en la lista; el rechazado desaparece de ella; ambos en la bitácora |
| Técnico | «+ Reportar una novedad» en una orden (otra área: eléctrico) | Al jefe le aparece en Novedades, «por decidir» |
| Jefe de zona | Decidir: derivar a SAP | El técnico recibe el aviso; la novedad queda «derivada» |
| Administración | Registrar una novedad que llegó por teléfono | Con local del maestro; se puede resolver después |
| Técnico | Orden con «trabajo con otro proveedor» (nombre y qué hizo) | El PDF trae el bloque «Trabajo con otro proveedor» |

## Día 5 · Aprendizaje, reportes para KFC y cierre de la semana

**Objetivo:** lo que se entrega hacia afuera y hacia adentro.

| Rol | Prueba | Resultado esperado |
|---|---|---|
| Jefe de zona | Aprendizaje → subir un manual (PDF) de un tipo de equipo | Queda «en revisión»; el jefe lo ve en «lo que subiste y todavía no está publicado» |
| Técnico | Aprendizaje → proponer una guía | Igual, «en revisión»; el técnico no lo ve publicado hasta la aprobación |
| Administración | Aprobar el manual, rechazar la guía con nota | El manual queda publicado con versión 1; el rechazo le llega al técnico |
| Administración | Publicar un comunicado con acuse | Los técnicos ven «Marcar como leído»; la administración ve «visto por N de M» |
| Técnico | Abrir el manual desde el celular | Abre; queda «abrir documento» en la bitácora |
| Administración | Reportes → UIO → mes actual → Descargar Excel, PDF y PowerPoint | Los tres archivos abren; el Excel tiene ocho hojas; el PowerPoint, siete diapositivas; las cifras coinciden con la pantalla |
| Administración | Inicio → «casos con más de 7 días sin informe» → Cerrar por falta de atención | Pide confirmación; los cierra y los marca para regularizar; **no** toca los que esperan repuesto |
| Administración | Bitácora → exportar CSV de la semana | Abre en Excel con todas las acciones de los cinco días |
| Administración | Usuarios → «Ver actividad» de un técnico | La bitácora filtrada por esa persona |
| Todos | Contar: órdenes enviadas por la app vs. por el formulario viejo | Las mismas; ninguna perdida, ninguna duplicada |

## Lo que se anota al final de cada día

Un mensaje en el grupo del piloto, de la administración o del jefe de zona, con cuatro líneas:

```
Órdenes por la app hoy: N (correctivas / preventivas)
Fallos reportados: N (bloquean / estorban / mejoras)
Algo que salió mejor que con el formulario viejo:
Algo que salió peor:
```

Con eso, el viernes se decide si el piloto sigue una segunda semana, si se extiende a otra zona o si se corrige algo antes.
