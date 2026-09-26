# Hoja del técnico — la app de OT INDUSTEC

> Para los técnicos de la zona UIO durante el piloto. Se lee en 10 minutos. Las pantallas son capturas reales del sitio de pruebas, tomadas con una cuenta de prueba: los nombres que aparecen no son de personas reales.
>
> **Las capturas son del 13 de septiembre de 2026 y muestran rótulos anteriores**. La app trabaja igual; solo cambiaron los nombres, y esta hoja ya usa los nuevos.

## Las palabras de la app

- **Orden**: el trabajo que te pide KFC, con su **aviso SAP**.
- **OT INDUSTEC**: el documento que llenas y envías. Es **de evaluación** si el trabajo sigue (Estado de OT: Abierta) y **de cierre** si terminaste (Estado de OT: Cerrada). El PDF sigue titulado «ORDEN DE TRABAJO INDUSTEC» y su número sigue siendo OT-NNNN.
- **Solicitud**: lo que se abre cuando el equipo no quedó operativo y hace falta un repuesto, un taller, una garantía o una baja.
- **Notificaciones**: los mensajes del sistema para ti. «Aviso» es solo el número de SAP.

## Qué cambia para ti

Hoy llenas la orden en el formulario de siempre y el PDF te llega por correo. Con la app:

- **Tus órdenes ya vienen cargadas**: el local, el equipo y lo que pidió KFC. No tecleas números.
- **El administrador del local, los diagnósticos y los repuestos se eligen** de listas que ya conocen tu trabajo. Puedes editar el texto.
- **La OT INDUSTEC sale aunque no tengas señal**: se guarda en el celular y se envía sola cuando vuelve la conexión.
- **Ves el PDF de cada OT INDUSTEC** en el momento, y el archivo de todas las OT INDUSTEC de todas las zonas, para consultar.
- Tus **Notificaciones** te cuentan cuando te asignan o te quitan una orden, cuando responden sobre una solicitud de repuesto o cuando resuelven una novedad tuya.

Durante el piloto **sigues mandando la orden por el formulario viejo además de por la app**: así, si algo falla, no se pierde nada. Al corte se apaga el viejo.

## 1. Entrar y dejar la app en el celular

![La pantalla de ingreso](capturas/entrada/ingreso.png)

1. Abre `https://darkviolet-armadillo-872352.hostingersite.com/ot/` en el navegador del celular.
2. Escribe tu **usuario** y la **clave temporal** que te dio la administración. La primera vez, el sistema te pide cambiarla: escribe la temporal, la nueva y repítela. La nueva es tuya.
3. Agrégala a la pantalla de inicio: en Chrome (Android) menú ⋮ → **«Agregar a la pantalla principal»**; en Safari (iPhone) botón de compartir → **«Agregar a inicio»**. Desde ahí abre como una app y funciona sin señal.

Si te equivocas cinco veces con la clave, el ingreso se bloquea 15 minutos. Si la olvidas, pídele una nueva a la administración.

## 2. «Mis órdenes»

![Mis órdenes](capturas/tecnico/mis_php.png)

Es lo primero que ves. Tiene cuatro pestañas:

| Pestaña | Qué hay |
|---|---|
| **Asignadas** | Las órdenes que te asignaron y todavía no terminas. Cada una muestra el aviso SAP, el local, el equipo y desde cuándo está asignada; las urgentes y las más viejas van arriba. |
| **A espera de repuesto** | Las órdenes donde ya fuiste y el equipo quedó a espera de un repuesto, una reparación o una garantía. |
| **Historial** | Las órdenes que ya terminaste, y las OT INDUSTEC que enviaste desde la app, con su número y su **PDF** (botón «Ver»). Las de número 90xx son de las pruebas automáticas, no del piloto. |
| **Notificaciones** | Lo que antes te llegaba por WhatsApp: te asignaron o te quitaron una orden, te respondieron sobre una solicitud, te resolvieron una novedad, te pidieron seguimiento. El globo con número dice cuántas tienes sin leer. |

![Las notificaciones](capturas/tecnico/mis_avisos.png) ![El historial, con su PDF](capturas/tecnico/mis_atendidas.png)

Abajo va la **barra**: **Mis órdenes · Historial · Emitir · Repuestos · Preventivos**; el globo rojo sobre Repuestos avisa de una solicitud con el equipo deshabilitado. Arriba a la derecha, **Archivo** (las OT INDUSTEC de todas las zonas) y **Salir**. El botón azul **+** es lo mismo que Emitir: una OT INDUSTEC nueva. Novedades y Aprendizaje se abren desde los enlaces de Mis órdenes y del formulario.

## 3. Llenar una OT INDUSTEC

![El formulario](capturas/tecnico/index_html.png)

Toca **Emitir** (o la orden, desde Asignadas, y **«Emitir OT INDUSTEC»**). El formulario va por pasos; cada paso se abre cuando el anterior está completo y muestra un ✓ al plegarse.

1. **¿Qué vas a hacer?** **Correctivo** (una orden de SAP) o **preventivo** (un ingreso del cronograma). Si el trabajo lo hizo o lo va a hacer **otro proveedor**, marca la casilla y anota su nombre y qué hizo.
2. **La OT INDUSTEC.** Elige la orden en **«De mis órdenes»**: el local, el equipo y el tipo de requerimiento se llenan solos. Si atendiste algo que no tiene aviso, elige **«Sin aviso SAP»** y di por qué.
3. **Datos generales.** Fecha de atención, local (ya viene), **administrador del local**: elige uno de los ya ingresados o escribe uno nuevo. Si intervino otro técnico, «+ Añadir otro técnico».
4. **Equipos intervenidos.** El equipo del aviso viene preseleccionado. «+ Añadir equipo» para otro del mismo local. Si el equipo **no está en la lista**, elige «Equipo nuevo / no está en la lista» y escribe marca, modelo y serie: queda **por confirmar**, guardado para todas las zonas, y la administración lo confirma en el catálogo.
5. **Detalle de la intervención.** Inicio y fin, **actividades realizadas** (elige un **diagnóstico pre-redactado** por tipo de equipo y complétalo con lo que viste), **¿Se usó repuesto?** y su detalle (pieza, cantidad, número de parte si lo sabes).
6. **¿Quedó concluido el trabajo?** Si eliges **«No, el equipo no quedó operativo»**, te pide: qué encontraste y por qué no se pudo terminar, qué equipo es y **qué repuesto haría falta**. Si el local no puede usar el equipo, marca **«El equipo quedó deshabilitado»**: eso enciende el plazo de 48 horas para que tu jefe de zona valide la vía. Con eso se abre la **solicitud** de repuesto sin que tengas que hacer nada más (ver el punto 5).
7. **Estado de la OT INDUSTEC.** **Abierta** = OT INDUSTEC de evaluación (el trabajo sigue); **Cerrada** = OT INDUSTEC de cierre (terminaste). Que la orden quede cerrada en SAP lo confirma la administración, nunca esta casilla.
8. **Evidencia fotográfica.** Toma las fotos desde la app. Una foto demasiado grande se recorta sola; si una no sube, la OT INDUSTEC sale igual y lo dice.
9. **¿Viste algo más en el local?** «+ Reportar una novedad»: lo que no era tu orden (otro equipo fallando, un problema eléctrico, de ventilación, de obra civil). Le llega al jefe de zona.
10. **Satisfacción del cliente** y **firma del administrador** en pantalla.
11. **«Revisar y enviar».** Ves cómo va a quedar archivada y envías.

Al enviar, la pantalla te muestra **el número real de la OT INDUSTEC y el enlace al PDF**. Si no había señal, la OT INDUSTEC queda **en cola** en el celular («enviando» cuando vuelve la señal) y sale sola; la ves en Historial cuando llegue. Si dice **«detenida en el celular»**, falta que vuelvas a entrar con tu usuario para que salga.

Si una OT INDUSTEC sale **«rechazada al enviar»** (te lo dice la app y tus Notificaciones), la abres, corriges y reenvías: las fotos y la firma se conservan.

**Lo que la app no deja hacer**, para que la OT INDUSTEC llegue completa: enviar sin equipo, sin actividades, sin firma cuando el trabajo quedó concluido, o con una fecha futura. Te lo dice al lado del campo.

## 4. Preventivos

![El cronograma en el celular](capturas/tecnico/cronograma_html.png)

En **Preventivos** ves el cronograma de tu zona: cada local con su día y su estado (**Ejecutado**, **Pendiente** o **Atrasado**). Toca el local y elige **«OT INDUSTEC del día N»**: el formulario abre ya en preventivo, con el local, el día de intervención y sus equipos. Si el kit de mantenimiento no ha llegado o hay que reagendar, se lo dices a tu jefe de zona: él lo cambia en el cronograma con el motivo.

## 5. Repuestos: qué pasa con un equipo que no quedó operativo

![Repuestos y equipos](capturas/tecnico/pendientes_php.png)

Cuando envías una OT INDUSTEC con el equipo que no quedó operativo, en **Repuestos** aparece esa **solicitud** con su **estado**:

| Estado | Qué significa |
|---|---|
| por validar | Tu solicitud espera que el jefe de zona la valide (plazo de 48 horas si el equipo quedó deshabilitado). |
| validada, por registrar en SAP | El jefe validó el diagnóstico y la vía. La administración la registra en SAP. |
| pendiente OK de OP´S | Ya tiene número de requerimiento en SAP. KFC decide: envía el repuesto, a taller de INDUSTEC, a otro proveedor o da de baja el equipo. |
| repuesto despachado / en taller de INDUSTEC / con otro proveedor / baja aprobada por KFC | Lo que decidió KFC. |
| repuesto en el local / de vuelta del taller | La pieza o el equipo ya están en el local; falta montarlo. |
| terminada | Cuando envías la OT INDUSTEC de cierre de ese equipo, la solicitud se termina sola. |

Un equipo deshabilitado tiene **48 horas** para que INDUSTEC valide la vía. Si ves que pasa el tiempo, **«Insistir»** deja un recordatorio con fecha en el hilo; ahí también lees lo que respondieron el jefe o la administración.

## 6. Novedades

En **Novedades** están las que reportaste (desde el formulario o con «Registrar una novedad») y qué se decidió con cada una: **reportada**, **en estudio**, **con aviso SAP** (se le pidió el aviso a KFC), **la asume INDUSTEC**, **descartada** o **resuelta**. Cuando cambian de estado te llega una notificación.

## 7. El Archivo de OT INDUSTEC, de todas las zonas

![El archivo](capturas/tecnico/ordenes_php.png)

En **Archivo** están las OT INDUSTEC de las tres zonas: las emitidas desde la app y las históricas, como en la carpeta de Google Drive. Puedes **buscar** (por OT, aviso, local o técnico), **ver** el PDF, **descargar** y **compartir**: «Compartir» te da un enlace que funciona 24 horas sin usuario, para mandarle la OT INDUSTEC al local por WhatsApp. Si una OT INDUSTEC histórica muestra «Pedir copia», su PDF está solo en la estación de INDUSTEC: se pide con un toque y la administración lo sube.

No se puede borrar ni editar nada del archivo, y cada consulta, descarga y enlace compartido queda registrado con tu usuario.

## 8. Aprendizaje: manuales y guías

![Aprendizaje](capturas/tecnico/documentos_php.png)

En **Aprendizaje** están los manuales y guías de los equipos, por tipo de equipo y por zona, y los comunicados de la administración. Si tienes un manual que no está, **«Proponer un documento»** lo sube; queda **por aprobar** hasta que un jefe o la administración lo apruebe y quede publicado. Un comunicado con **«Marcar como leído»** le dice a la administración que lo viste.

## Si algo no funciona

Mira [`COMO_REPORTAR_FALLOS.md`](COMO_REPORTAR_FALLOS.md): a quién escribir y qué datos mandar. Lo más útil: una captura, la hora y qué estabas haciendo.
