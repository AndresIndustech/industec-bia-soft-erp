# Hoja de la administración — la operación de las tres zonas en una pantalla

> Para la administradora durante el piloto (y para la dirección, que tiene los mismos permisos más la gestión de cuentas). Se lee en 20 minutos. Las pantallas son capturas reales del sitio de pruebas con una cuenta de prueba de administración; los nombres del personal están reemplazados por «Técnico 1», «Jefe de zona 2»…

## Qué ves

**Todo**: las tres zonas (UIO, LARB, CNLJ), cada una separada, y el consolidado. Durante el piloto solo UIO trabaja con la app; LARB y CNLJ siguen apareciendo con los casos que llegan de SAP, y podrás asignarlos y verlos, pero sus técnicos aún no entran.

Entras en `https://darkviolet-armadillo-872352.hostingersite.com/ot/login.php`. La primera vez el sistema pide cambiar la clave temporal. Ver [`CUENTAS.md`](CUENTAS.md).

## 1. Inicio: lo que te toca ahora, y las tres zonas

![Inicio de la administración](capturas/admin/panel_php.png)

Arriba, **«Lo que te toca ahora»**: cada línea es algo que solo la administración resuelve, con su cifra y el enlace que te deja delante de esos casos.

| Línea | Qué es | Dónde te lleva |
|---|---|---|
| casos sin repartir | Llegaron de SAP y no tienen técnico | Asignar |
| en revisión, esperando tu veredicto | Un jefe de zona los mandó con motivo | Buzón, filtrado |
| repuestos validados, por registrar en SAP | El jefe ya confirmó diagnóstico y vía; falta el número de requerimiento | Repuestos, «por registrar» |
| asignados hace 3 días o más, sin informe | Tienen técnico y ninguna orden todavía | Buzón, filtrado |
| casos con más de 7 días sin informe | Candidatos a cerrarse por falta de atención | Botón **«Cerrar por falta de atención»**, con confirmación: **no es automático**, lo decides tú |
| casos con alerta de alcance | Parecen no corresponder a INDUSTEC; la alerta no decide, los pone a mano | Buzón, filtrado |
| novedades por decidir | Reportadas en las visitas | Novedades |

Debajo, **«Por zona»**: una columna por zona con sus cifras enlazadas (sin asignar, asignados, esperando repuesto, atendidos, cerrados sin atención), «Cómo va el buzón» y «Equipos deshabilitados · el plazo de 48 horas».

## 2. Buzón: decidir sobre los casos

![El buzón de casos](capturas/admin/casos_php.png)

Todos los casos de SAP con su estado. Filtros por **zona**, estado, local, técnico, antigüedad y texto. **«Casos sin local resuelto»** agrupa los que llegaron con un local que el maestro no reconoce, para corregirlos.

![Casos sin asignar](capturas/admin/casos_sin_asignar.png)

Tus acciones sobre un caso:

- **Veredicto**: **resuelto** (cerrado por las dos partes: lo registras en SAP) o **no nos compete** (con motivo). Resuelto solo se puede dar sobre un caso **atendido** y **sin un repuesto vivo**: si hay un pendiente abierto, primero se resuelve el repuesto.
- **Derivar** a otra zona (un local que cambió de zona o un técnico que cubre).
- **En revisión** (con motivo) y **Pedir seguimiento** (nota al técnico, que le llega a sus Avisos).
- **Asignar** también desde aquí; sobre un caso «sin atender» lo **reabre** con bitácora.

Lo que cada acción hace y desde qué estado se permite está en «Qué hace cada acción», al pie.

## 3. Asignar: las tres zonas, cada una con su equipo

![Asignación de las tres zonas](capturas/admin/asignacion_php.png)

Arriba, un tile por zona con lo que tiene por repartir. Debajo, **un bloque por zona**: sus cuatro cifras, **su equipo ordenado por carga** (casos abiertos, esperando repuesto, atendidos por técnico) y su tabla **«por repartir»**. Tocar la cifra «por repartir» filtra esa tabla.

![La zona UIO](capturas/admin/asignacion_uio.png)

En cada caso eliges un técnico **de esa zona** y pulsas Asignar. Un técnico de otra zona se ofrece aparte y exige marcar «confirmo que es de otra zona». Al técnico le llega el aviso en el momento.

## 4. Repuestos y equipos sin concluir: el flujo con KFC

![Repuestos](capturas/admin/pendientes_php.png)

Cada equipo que un técnico dejó parado abre un pendiente. El flujo, de principio a fin:

| Paso | Estado | Quién |
|---|---|---|
| El técnico envía la orden no concluida con el diagnóstico y las piezas | **Solicitado** | técnico |
| El jefe de zona confirma diagnóstico y vía | **Validado por el jefe** | jefe de zona (tú, por excepción: queda en bitácora) |
| Registras el requerimiento en SAP | **Registrado en SAP** (número obligatorio) | administración |
| Se espera a KFC | **Esperando a KFC** | — |
| KFC decide | **Repuesto enviado** · **Taller INDUSTEC** · **Otro proveedor** (con el nombre del tercero) · **Baja** | administración anota la decisión |
| El técnico instala y envía la orden concluida | **Resuelto** (solo) | técnico |

Los tiles de arriba son tu lista de trabajo: **por registrar en SAP** (abre por defecto), esperando a KFC, compromisos vencidos, y **por regularizar** (equipos parados en una orden que llegó sin aviso: hay que crearle el aviso en SAP). Cada pendiente tiene su hilo: las insistencias del técnico con fecha, tus respuestas, las del jefe y los cambios de estado. **Mover** permite corregir un estado (al retroceder pide nota); **Cancelar** cierra con motivo.

El reloj de **48 horas** mide la validación del jefe, no la respuesta de KFC. El cumplimiento de cada zona sale en Reportes.

## 5. Novedades

![Novedades](capturas/admin/novedades_visita_php.png)

Lo que los técnicos vieron en las visitas y no era su orden. Las decides igual que el jefe de zona (asumir, derivar a SAP con el aviso, descartar con motivo, resolver) y puedes **registrar una novedad** que llegó por otro canal, con el local del maestro. Las derivadas a SAP se cierran cuando KFC abre el aviso o cuando lo corriges.

## 6. Reportes: por zona, por mes, y para KFC

![El tablero de servicio](capturas/admin/reportes_php.png)

Arriba, los filtros rápidos **Las tres · UIO · LARB · CNLJ** y el **mes**. Secciones: la salud del servicio (cumplimiento de 48 horas, edad de los abiertos, semáforo), el volumen y cómo se reparte, **rendimiento por técnico**, **cumplimiento del preventivo**, dónde se concentra el trabajo, locales que repiten, qué hace fallar los equipos y las novedades.

![Solo UIO](capturas/admin/reportes_uio.png)

**Descargar Excel / PDF / PowerPoint**: el mismo tablero con el formato de INDUSTEC. El Excel trae ocho hojas (resumen, casos abiertos con semáforo y autofiltro, por zona, rendimiento, cumplimiento 48 h, preventivo, novedades, locales); el PowerPoint, siete diapositivas 16:9 para la reunión con KFC. Cada exportación queda en la bitácora. Cuando KFC entregue sus plantillas exactas, se mapean sobre estas.

## 7. Preventivos: el cronograma de las tres zonas

![El cronograma](capturas/admin/cronograma_html.png)

El cronograma del mes con la **marca de zona** en cada fila y los tiles por zona (cumplidos, % a tiempo, reagendados, sin agendar). Las mismas acciones que el jefe: kit, reagendar con motivo, cerrar el ingreso, agendar. El cumplimiento se mide contra el **plan original**, y los motivos de reagenda salen en Reportes.

## 8. Archivo: las órdenes de INDUSTEC, de todas las zonas

![El archivo](capturas/admin/ordenes_php.png)

El índice de todas las órdenes: las emitidas por la app, las que el sistema viejo sigue produciendo (las trae la estación cada noche) y el histórico. Filtros por zona, local, técnico, fechas, origen y «dónde está el PDF». Ver y descargar quedan registrados por separado.

![Compartir un informe](capturas/admin/ordenes_compartir.png)

**Compartir** genera un enlace firmado que caduca a las 24 horas, para mandárselo al local; la bitácora guarda quién lo compartió y quién lo abrió. Las órdenes históricas cuyo PDF está solo en la estación muestran **«Pedir copia»**; la petición queda registrada para que la estación la suba. Subir el histórico completo a Hostinger es una decisión pendiente de Andrés.

## 9. Aprendizaje: manuales, guías y comunicados con aprobación

![Aprendizaje](capturas/admin/documentos_php.png)

Los jefes suben manuales y guías y los técnicos los proponen; **tú apruebas** (o rechazas con nota, o retiras uno publicado). Cada aprobación publica una **versión** con su huella; el mismo archivo no entra dos veces. Un **comunicado** puede pedir acuse: «visto por 4 de 6» te dice quién lo leyó. Todo queda en la bitácora: quién subió, quién aprobó, quién abrió, quién descargó.

## 10. Equipos nuevos propuestos por los técnicos

![Equipos nuevos](capturas/admin/equipos_php.png)

Cuando un técnico registra en una orden un equipo que **no estaba en la lista** del local, el equipo aparece de inmediato en esa lista (marcado como propuesto) y en **Equipos nuevos** para que lo confirmes: **aprobar** (pasa al catálogo y la estación lo incorpora al maestro), **rechazar** con nota (era un error) o marcar que **ya existía** (se fusiona con el que estaba). Nada se pierde: la orden conserva el equipo tal como lo escribió el técnico.

## 11. Usuarios

![Usuarios y permisos](capturas/admin/usuarios_php.png)

Agrupados por zona. **Crear un usuario** (técnico o jefe de zona; la dirección crea también administración), **editar** nombre, correo, zona y rol (si cambias de zona a un técnico con casos asignados, el sistema te avisa cuántos), **Nueva clave** (temporal, se muestra una sola vez, y obliga a cambiarla), desactivar (nunca borrar), y **«Ver actividad»**, que abre la bitácora filtrada por esa persona. Las reglas de las claves y las sesiones están en [`CUENTAS.md`](CUENTAS.md).

## 12. Bitácora: la trazabilidad de todo

![La bitácora](capturas/admin/bitacora_php.png)

Cada acción de cada usuario, con fecha, resultado (hecho o rechazado), estado antes y después y los datos. Filtros por usuario, acción, entidad (caso, pendiente, orden, documento, usuario…), referencia, fechas y texto; exporta a **CSV**. Nadie puede borrar ni editar una fila de la bitácora, ni siquiera la dirección: la base lo impide.

## 13. Lo que el sistema no hace solo, a propósito

- **No cierra casos por falta de atención** sin que lo confirmes (la cifra aparece en Inicio; el botón pide confirmación).
- **No manda correos** desde el sitio de pruebas: las órdenes quedan con su PDF y el correo **retenido** hasta el corte.
- **No decide** si un caso corresponde a INDUSTEC: la alerta de alcance lo señala, el veredicto es tuyo.
- **No borra** nada: casos, órdenes, pendientes, documentos y usuarios se cancelan, retiran o desactivan.

## Si algo no funciona

[`COMO_REPORTAR_FALLOS.md`](COMO_REPORTAR_FALLOS.md): el canal y los datos. La bitácora te sirve para reconstruir qué hizo cada quien antes de reportarlo.
