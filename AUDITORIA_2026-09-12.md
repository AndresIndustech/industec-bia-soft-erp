# Auditoría del 2026-09-12 — antes de pulir el sistema para las pruebas del cliente

**Pedido de Andrés:** revisar y auditar todo de forma exhaustiva, mejorar lo necesario manteniendo
las premisas (mejorar la operación, no alterar la operación actual, trabajar en el entorno aislado,
cero costos, aprovechar al máximo Hostinger, Claude, la estación y a futuro el equipo Veeam), y
dejar el sistema pulido para que técnicos, jefes y administradora empiecen las pruebas.
**Desde dónde:** el PC de Andrés, sobre `master` = 50ec53d (la fusión de las dos verdades ya hecha por
la estación el 2026-09-12 más el último commit del PC), contra el sitio de pruebas en **solo lectura**.
Rama de trabajo: `pc/pulido-2026-09-12`.

> Este documento es el informe. Lo que se construye a partir de él está en `PLAN_INDUSTEC.md`
> **T2.14 a T2.17** (con las decisiones por defecto D1–D16) y lo que quedó hecho, en `ESTADO.md`.

## 1. Cómo se hizo

1. **Hechos en vivo, sin tocar nada**: estado del repositorio y de las ramas, respuestas HTTP del sitio
   de pruebas, versión del `sw.js` servido, extensiones y librerías del servidor (PHP 8.2.33 con gd,
   imagick, intl, zip, xsl; composer 2.9.8; dompdf en `~/lib/ot`), conteos de la base
   (`casos_gestion`: NUEVO 2 · ASIGNADO 86 · ATENDIDO 55 · CERRADO_SIN_ATENCION 731 · ESPERA_REPUESTO 1;
   `ot_capturadas` 24; `email_queue` 4 RETENIDO; 26 usuarios), PDF en disco (120) contra los que
   referencia `atenciones.json` (130 OT: 111 con PDF, 19 sin él).
2. **Línea base de las verificaciones por rol** contra el sitio de pruebas, con las cuentas de prueba:
   **148 de 148 en verde** (`verificar_http` 66, `verificar_bandeja` 32, `verificar_ciclo` 21,
   `verificar_emision` 29). Baterías locales con un PHP 8.2.33 portable verificado por SHA-256:
   `php -l` 43/43, `prueba_contratos` 57·0, `prueba_graficos` 62·0, fixtures 32/32; `prueba_48h.php`
   no corría en el PC por su ruta pegada a `d:/INDUSTECH IA`.
3. **Diez frentes auditados por agentes independientes** (cada uno con su lista de archivos, leídos
   enteros) y **verificación escéptica** de todo lo crítico y alto por un segundo agente que intentó
   refutar cada hallazgo releyendo el código: **219 hallazgos**; de los 69 críticos y altos, **47
   confirmados tal cual, 22 matizados en gravedad, 0 refutados**; los verificadores agregaron 9
   defectos que los auditores no vieron (§3).
4. **Lo que Andrés reportó ese día probando** —el técnico no ve los PDF de sus casos; la asignación
   mezcla técnicos sin división por zona— se midió antes de leer código: `pdf.php` niega al técnico
   toda orden que no sea la de cierre ni una emitida por la app, y **76 de los 87 casos abiertos**
   tienen una orden previa que su técnico no puede abrir (403 «Esa orden no es tuya»); además 19 de
   las 130 órdenes que referencia el cruce de informes no están en el servidor.

Gravedad final, tras la verificación: media 117, alta 63, baja 37, critica 2.

**Lo que NO se pudo comprobar:** no hay base local en este PC (el antivirus del equipo bloqueó y
eliminó `mariadbd.exe` al intentar levantar una MariaDB portable), así que todo lo que necesita base
se comprueba contra el sitio de pruebas; la caducidad real de la sesión y la IP detrás del CDN
(SEG-05, SEG-16) requieren medirse en vivo; nada de la estación (`D:\RESPALDOS`, MariaDB local,
`config/.env`) existe aquí, así que los scripts de saneamiento se corrigen y se prueban con carpetas
temporales, no con datos reales.

## 2. Los hallazgos, frente por frente

Cada tabla trae la gravedad **final** (la del verificador cuando lo hubo) y el archivo y la línea
donde está el defecto. El detalle (evidencia literal, impacto, corrección propuesta y decisiones
que exige) está en los JSON de la auditoría que acompañan a este informe en el scratchpad de la
sesión y, resumido, en las subtareas de T2.14.

### La app del técnico (celular) → T2.14.1

El frente está bien pensado (cola idempotente, identidad de sesión, validación compartida) pero varias promesas de la interfaz no las cumple el código, y lo que Andrés pidió para el formulario (prellenados, equipos nuevos, diagnósticos y repuestos estructurados) todavía no existe. Lo más grave para lo que reportó hoy: pdf.php niega al técnico todo PDF que no sea el `ot_cierre` de un caso asignado a él (las órdenes «en curso» de atenciones.json dan 403 «Esa orden no es tuya»), mis.php no tiene entrada al archivo histórico de todas las zonas que ahora se decidió abrir a todos, y el recibo del formulario sigue diciendo que «el PDF y el correo todavía no salen de aquí» mientras cola.js anuncia lo contrario; además, si la emisión del PDF falla, nadie la reintenta (el único llamador de Emision::emitir es envio.php y el celular ya marcó ENVIADA). En el formulario, con el catálogo caído el botón «Revisar y enviar» muere en silencio (validar es null), la guía por pasos pierde el ✓ y el resumen de cada paso al plegarlo (display:none anula offsetParent) y una orden rechazada solo se puede descartar, nunca corregir, lo que obliga a rehacer veinte minutos de trabajo y una firma que ya no está.

| # | Gravedad | Dónde | Qué | Verificación |
|---|---|---|---|---|
| H-01 | critica | `pdf.php:106` | pdf.php niega al técnico todo PDF que no sea el ot_cierre de un caso asignado a él: por eso «no ve los PDF de sus casos» | confirmado |
| H-02 | alta | `mis.php:302` | La app del técnico no tiene entrada al archivo histórico de OT de todas las zonas y pinta «Ver PDF» sin comprobar que el archivo exista | confirmado |
| H-03 | alta | `envio.php:357` | Si la emisión del PDF falla, nadie la reintenta: el celular ya marcó ENVIADA y Emision::emitir() solo se llama desde envio.php | confirmado |
| H-04 | alta | `index.html:390` | El recibo del formulario dice que «el PDF y el correo todavía no salen de aquí» mientras cola.js anuncia «Orden X emitida. El PDF está en tu historial», y nunca muestra el número real | confirmado |
| H-05 | alta | `app.js:952` | Con el catálogo caído, «Revisar y enviar» muere en silencio (validar es null) y el nombre del técnico se queda en «Cargando…» | confirmado |
| H-06 | alta | `guia.js:199` | La guía por pasos pierde el ✓ y el resumen de cada paso en cuanto se pliega: los campos de un paso cerrado tienen display:none y completo() devuelve null | confirmado |
| H-07 | alta | `cola.js:318` | Una orden RECHAZADA solo se puede descartar: no hay forma de corregirla y reenviarla, y se pierden veinte minutos, las fotos y la firma del administrador | confirmado |
| H-08 | alta | `index.html:149` | El administrador del local se teclea a mano en cada orden: no hay lista de administradores ya ingresados por local | confirmado |
| H-09 | alta | `catalogos.php:85` | El equipo del aviso nunca se preselecciona: catalogos.php manda equipo_sap=null y app.js solo preselecciona por equipo_sap | confirmado |
| H-10 | alta | `app.js:542` | No existe registro de equipos nuevos: si el activo no está en el catálogo del local no hay cómo agregarlo, y marca/modelo/serie se pierden dentro del JSON de la orden | matizado |
| H-11 | alta | `index.html:205` | Diagnósticos, actividades y repuestos son texto libre: no hay diagnósticos pre-redactados por daño común ni identificación estructurada del repuesto a solicitar | matizado |
| H-12 | media | `mis.php:84` | Cualquier navegación en la bandeja (abrir una ficha, cambiar de pestaña) marca como «vistos» los avisos aunque nunca se haya abierto la pestaña Avisos | no se verificó aparte (media/baja) |
| H-13 | media | `offline.js:164` | La bandeja del técnico nunca dice que está sin señal ni de cuándo son sus datos: offline.js sale antes si no hay #otForm y nadie envía el mensaje 'fecha-datos' al service worker | no se verificó aparte (media/baja) |
| H-14 | media | `offline.js:131` | «Retomar» el borrador deja el formulario a medias: los combos no reaccionan al valor oculto restaurado, los equipos no se guardan y tecSesion hace que siempre haya «una orden a medio llenar» | no se verificó aparte (media/baja) |
| H-15 | media | `guia.js:206` | El paso «Datos generales» se marca completo sin local elegido: el `required` del hidden #local no se cuenta porque nunca es «visible» | no se verificó aparte (media/baja) |
| H-16 | media | `app.js:334` | La app avisa «el sistema no va a aceptar la orden» cuando el técnico no está en el padrón, pero deja enviarla: sube las fotos y recibe un 400 TECNICO_NO_VIGENTE que la deja RECHAZADA | no se verificó aparte (media/baja) |
| H-17 | media | `app.js:1119` | Los preventivos no llegan prellenados: el formulario solo lee ?aviso= y ?local=, no tipo ni día, y el día obligatorio no se sincroniza cuando el tipo se fija por programa | no se verificó aparte (media/baja) |
| H-18 | media | `index.html:36` | No hay tipo de orden para «trabajos con otros proveedores»: el formulario solo admite CORRECTIVO y PREVENTIVO y cualquier otro valor bloquea | no se verificó aparte (media/baja) |
| H-19 | media | `novedades.php:41` | Cada técnico dispara cada 30 s cuatro consultas sobre bitácora, pendientes y novedades (una con subconsulta correlacionada sin índice) solo para saber si tiene avisos nuevos | no se verificó aparte (media/baja) |
| H-20 | media | `cola.js:390` | Una orden «de otro usuario» o «sin permiso» se queda en la cola del celular para siempre: no tiene botón de descartar ni de rescatar | no se verificó aparte (media/baja) |
| H-21 | baja | `index.html:286` | Notas de desarrollo visibles para el técnico en la cocina («PDFs en blanco», «se cruzaban 4 locales de Quito a Cuenca», «1 de cada 6 órdenes…») | no se verificó aparte (media/baja) |
| H-22 | baja | `mis.php:473` | El historial de órdenes enviadas desde la app se corta en 60 sin aviso ni paginación | no se verificó aparte (media/baja) |
| H-23 | baja | `app.js:43` | Si la sesión venció al abrir index.html?aviso=…, el reingreso vuelve a index.html sin el aviso y se pierde la precarga | no se verificó aparte (media/baja) |
| H-24 | baja | `foto.php:82` | foto.php decodifica la imagen entera en memoria: una foto grande que el navegador no pudo reducir provoca un 500 y la orden reintenta ese mismo 500 cada dos minutos sin salir nunca | no se verificó aparte (media/baja) |
| H-25 | baja | `cola.js:153` | Las filas ENVIADA nunca se purgan de IndexedDB: la cola del celular crece sin límite | no se verificó aparte (media/baja) |

### Asignación, buzón y reconciliación → T2.14.2

El frente funciona como buzón caso por caso, pero no como mesa de servicio por zona: asignacion.php ordena a los 19 técnicos solo por carga (uasort línea 53) y agrega las cifras de las tres zonas, las tarjetas no son enlaces y el desplegable de «Asignar a» no muestra la zona ni el servidor impide que la administradora asigne un caso de UIO a un técnico de CNLJ. Lo más grave en correctitud: el veredicto «RESUELTO» no valida el estado de origen y se ofrece sobre casos en ESPERA_REPUESTO (salta el cierre de dos manos con el equipo parado y el pendiente ya no vuelve solo), «Asignar» sobre un caso CERRADO_SIN_ATENCION cambia el técnico pero no el estado (el técnico nunca lo ve), el formulario de asignacion.php no manda `volver` y devuelve al buzón, el cierre automático a los 7 días solo existe en `reconciliar_cli.php --ejecutar` (nadie lo lanza), y los casos ATENDIDO fuera de la ventana de 90 días del catálogo son invisibles para la lista «a registrar en SAP». La reconciliación respeta las decisiones humanas (asignado_por/derivado_en/INTOCABLES) salvo que una OT abierta posterior regresa un ATENDIDO a ASIGNADO y el cierre por falta de atención pisa alertas de alcance pendientes de veredicto; el buscador tiene el defecto conocido de ceros internos.

| # | Gravedad | Dónde | Qué | Verificación |
|---|---|---|---|---|
| ASG-01 | alta | `casos.php:133` | «Veredicto → RESUELTO» no valida el estado de origen: cierra casos en ESPERA_REPUESTO con el equipo parado y salta el cierre de dos manos | matizado |
| ASG-02 | alta | `casos.php:712` | «Asignar» sobre un caso CERRADO_SIN_ATENCION cambia el técnico pero no el estado: la administradora cree que lo repartió y el técnico nunca lo ve | confirmado |
| ASG-03 | alta | `casos.php:90` | La administradora puede asignar un caso a un técnico de otra zona: el servidor solo verifica que el técnico esté en SU lista, y para ADMIN esa lista son las tres zonas | confirmado |
| ASG-04 | alta | `casos.php:253` | La lista «a registrar en SAP» (?est=ATENDIDO) y las acciones de administración solo ven los casos que siguen en la ventana de 90 días del catálogo: los ATENDIDO fuera de ella son invisibles y «fuera de tu alcance» | confirmado |
| ASG-05 | alta | `nucleo/Reconciliar.php:160` | El cierre automático a los 7 días que prometen todas las pantallas no ocurre: `cerrarSinAtencion()` solo se ejecuta a mano con `reconciliar_cli.php --ejecutar` | matizado |
| ASG-06 | alta | `asignacion.php:237` | Asignar desde la pantalla de asignación devuelve al buzón: el formulario no manda `volver` | confirmado |
| ASG-07 | alta | `asignacion.php:53` | El equipo se ordena solo por carga y se pinta en una única rejilla: para la administradora las tres zonas quedan mezcladas y las cifras son la suma de las tres | confirmado |
| ASG-08 | alta | `asignacion.php:122` | «Por repartir» no es un enlace ni lista los casos al lado: la tabla queda al final, debajo de las 19 tarjetas, y sin división por zona | confirmado |
| ASG-09 | alta | `panel.php:71` | El panel de la administradora no separa por zona ni «lo que te toca» ni el resumen atendidos/pendientes | confirmado |
| ASG-10 | media | `casos.php:95` | Asignar a mano no pone `tecnico_auto = 0`: el caso reasignado sigue mostrando «(del informe)» | no se verificó aparte (media/baja) |
| ASG-11 | media | `asignacion.php:50` | La carga por técnico ignora ESPERA_REPUESTO: un técnico con equipos trabados en su bandeja aparece «libre» | no se verificó aparte (media/baja) |
| ASG-12 | media | `asignacion.php:83` | El contador «sin repartir» cambia de valor según la pantalla: asignacion.php cuenta NUEVO+EN_REVISION, el buzón y el panel solo NUEVO; y repartir un caso EN_REVISION anula la revisión en silencio | no se verificó aparte (media/baja) |
| ASG-13 | media | `nucleo/Reconciliar.php:116` | Una OT abierta posterior regresa un caso ATENDIDO a ASIGNADO: desaparece el botón «Ya lo cerré en SAP» hasta que llegue otra orden de cierre | no se verificó aparte (media/baja) |
| ASG-14 | media | `casos.php:713` | El aviso se incrusta dentro de una cadena JavaScript en `onclick` con escape HTML: el mismo patrón del XSS de usuarios.php | no se verificó aparte (media/baja) |
| ASG-15 | media | `casos.php:85` | No existe «pedir seguimiento a un técnico» ni ninguna señal sobre casos ASIGNADO que llevan días sin informe | no se verificó aparte (media/baja) |
| ASG-16 | media | `panel.php:163` | El panel no ofrece la lista de «repuestos a solicitar»: solo muestra vencidos y sin veredicto | no se verificó aparte (media/baja) |
| ASG-17 | media | `nucleo/Reconciliar.php:170` | El cierre por falta de atención no mira `estado_alerta`: cierra como «sin atender» casos con alerta de alcance que esperaban veredicto de no competencia | no se verificó aparte (media/baja) |
| ASG-18 | media | `casos.php:110` | «revision» y «derivar» no validan el estado de origen: un POST fabricado reabre un caso RESUELTO/NO_COMPETE | no se verificó aparte (media/baja) |
| ASG-19 | baja | `asignacion.php:188` | El enlace al buzón para regularizar cerrados sin atención apunta a `casos.php?atn=` (sin filtro) | no se verificó aparte (media/baja) |
| ASG-20 | baja | `nucleo/Ui.php:452` | `busquedaSinCeros` borra ceros internos y los campos se pegan sin separador: buscar 2466 encuentra la OT 20466 y 352936 encuentra 10352936 | no se verificó aparte (media/baja) |
| ASG-21 | baja | `panel.php:292` | «Sin zona resuelta» enlaza al buzón sin filtro, el buzón no puede filtrar «sin zona», y la zona OTRA no se cuenta en el panel | no se verificó aparte (media/baja) |

### Pendientes, repuestos, 48 h y novedades → T2.14.3

La máquina de estados de pendientes está bien construida como mecanismo (alcance en el WHERE, reloj calculado en MySQL, idempotencia por (aviso, activo_fijo), hilo con trigger) pero modela otro negocio: un veredicto único de INDUSTEC y una compra propia (COTIZANDO→COMPRADO→EN_BODEGA), cuando el flujo real es técnico solicita → jefe valida → administradora registra en SAP → KFC decide (repuesto, taller INDUSTEC, otro proveedor o baja). Lo más grave: no existe el paso «validado por el jefe», no hay columna ni paso «registrado en SAP» con su número, el veredicto de KFC no puede registrarse porque la vía no se cambia una vez decidida, la columna `tercero` nunca se escribe (no se distingue taller propio de proveedor externo), la administradora no tiene la lista de aprobados por registrar, y el ciclo no se cierra solo cuando el técnico instala el repuesto y emite la orden concluida. Además, un equipo parado reportado sin aviso queda solo en la bitácora, el reloj no se reinicia cuando un equipo pasa de operando a parado en un segundo reporte, y el indicador de cumplimiento trunca a horas mientras la fila mide en minutos.

| # | Gravedad | Dónde | Qué | Verificación |
|---|---|---|---|---|
| P-01 | alta | `nucleo/Pendientes.php:67` | La vía REPUESTO modela una compra de INDUSTEC, no la solicitud a KFC: faltan «validado por el jefe» y «registrado en SAP» | matizado |
| P-02 | alta | `app/sql/007_pendientes_y_captura.sql:97` | No existe columna para el número de requerimiento SAP del pendiente (novedades sí lo tiene) | confirmado |
| P-03 | alta | `nucleo/Pendientes.php:500` | El veredicto de KFC (enviar repuesto / taller INDUSTEC / otro proveedor / baja) no puede registrarse: la vía no se cambia una vez decidida | confirmado |
| P-04 | media | `nucleo/Pendientes.php:527` | No se distingue «taller INDUSTEC» de «otro proveedor»: la columna `tercero` nunca se escribe | matizado |
| P-05 | alta | `pendientes.php:100` | La administradora no tiene la lista de repuestos validados por el jefe pendientes de registrar en SAP | matizado |
| P-06 | alta | `envio.php:271` | El ciclo no se cierra solo: la orden concluida del técnico tras instalar el repuesto no resuelve el pendiente ni saca el caso de ESPERA_REPUESTO | matizado |
| P-07 | alta | `envio.php:273` | Un equipo parado reportado en una orden sin aviso (o fuera de alcance) no entra a ninguna bandeja: solo queda en la bitácora | confirmado |
| P-08 | media | `app/sql/007_pendientes_y_captura.sql:299` | Nada obliga a la secuencia técnico → jefe → administradora: ADMIN también tiene repuestos.veredicto y puede saltarse la validación del jefe | no se verificó aparte (media/baja) |
| P-09 | media | `nucleo/Pendientes.php:390` | El reloj de 48 h no arranca cuando un equipo pasa de «operando» a «parado» en un segundo reporte: nace vencido | no se verificó aparte (media/baja) |
| P-10 | media | `nucleo/Pendientes.php:781` | El indicador de cumplimiento trunca a horas (TIMESTAMPDIFF HOUR) mientras la fila y los contadores miden en minutos: un veredicto a las 48 h 59 min cuenta a tiempo y la fila dice «se decidió a las 49 h» | no se verificó aparte (media/baja) |
| P-11 | media | `nucleo/Auth.php:394` | El flujo habla de UN «jefe técnico» que valida; el sistema solo tiene JEFE_ZONA con alcance cerrado a su zona | no se verificó aparte (media/baja) |
| P-12 | media | `nucleo/Pendientes.php:704` | El jefe de zona no puede responder en el hilo: `responder` exige repuestos.gestionar, que JEFE_ZONA no tiene | no se verificó aparte (media/baja) |
| P-13 | media | `nucleo/Pendientes.php:667` | Las insistencias se contaminan: ADMIN y JEFE pueden «Recordar», el trigger cuenta todo RECORDATORIO y el tile «dos o más recordatorios» y el chip «insististe N veces» del técnico mezclan autores | no se verificó aparte (media/baja) |
| P-14 | media | `nucleo/Novedades.php:179` | La zona de la novedad se pisa con la zona del usuario: una novedad detectada en un local de otra zona se archiva donde su jefe no la ve | no se verificó aparte (media/baja) |
| P-15 | media | `novedades_visita.php:243` | Una novedad DERIVADA_SAP o ASUMIDA_INDUSTEC no se puede corregir ni pasar a RESUELTA: el estado «resuelta» es inalcanzable por la interfaz | no se verificó aparte (media/baja) |
| P-16 | media | `pendientes.php:258` | La fecha comprometida vencida no se señala y, tras el veredicto, nada mide cuánto lleva el caso esperando a KFC | no se verificó aparte (media/baja) |
| P-17 | media | `nucleo/Pendientes.php:581` | `mover` acepta cualquier paso de la vía sin orden: se puede retroceder ENTREGADO → COTIZANDO o saltar a RESUELTO sin nota | no se verificó aparte (media/baja) |
| P-18 | baja | `nucleo/Pendientes.php:670` | `insistir` y `responder` no comprueban que el pendiente esté abierto: se escribe y se cuentan insistencias sobre pendientes RESUELTO/CANCELADO | no se verificó aparte (media/baja) |
| P-19 | baja | `mis.php:245` | La ficha del técnico muestra «sin veredicto · sin veredicto» para un pendiente recién abierto | no se verificó aparte (media/baja) |
| P-20 | baja | `nucleo/Pendientes.php:74` | Etiquetas ambiguas: «veredicto» nombra a la vez la decisión del jefe de INDUSTEC y la de KFC; el módulo se llama Repuestos en la navegación, Pendientes en la clase y Repuestos::mover en el SQL | no se verificó aparte (media/baja) |
| P-21 | baja | `nucleo/Pendientes.php:460` | `abrir` acepta una `nota` que se guarda como RECORDATORIO (cuenta como insistencia) pero ningún formulario la envía | no se verificó aparte (media/baja) |
| P-22 | baja | `novedades_visita.php:58` | La acción POST `reportar` existe pero la pantalla no tiene formulario para registrar una novedad desde la oficina | no se verificó aparte (media/baja) |
| P-23 | baja | `nucleo/Pendientes.php:526` | El jefe de zona fija `prometido_para` en el veredicto aunque el diseño diga que no compromete fechas con proveedores | no se verificó aparte (media/baja) |

### Tableros, reportes, órdenes, cronograma y usuarios → T2.14.4 / T2.14.5

El frente está bien construido como tablero de una zona (código limpio, alcance en servidor, gráficos propios sin CDN, sistema de diseño coherente), pero no cumple lo que Andrés pide para la administradora y los jefes: ni reportes.php ni panel.php tienen corte ni vista consolidada por zona para la administración, no existe exportación a Excel/PDF/PowerPoint, el "rendimiento por técnico" es solo un conteo de carga y el cumplimiento del preventivo no aparece en ningún reporte. Lo más grave: el cronograma de preventivos no escribe nada (kit, reagendas y novedades terminan en un alert y no hay ninguna migración 001-008 que cree sus tablas), así que el cumplimiento contra el plan original acordado con KFC no se puede medir; ordenes.php y pdf.php siguen cortando por zona/técnico y solo listan la ventana de 90 días del correo, en contra de la decisión nueva del archivo histórico de todas las zonas para todos. En diseño, el cruce CSS volvió a encontrar clases sin regla (.acciones y tr.baja en usuarios.php, .chip.abierta en ordenes.php, .et.cumplido/.chip-local.cumplido en el cronograma), la barra de alertas del cronograma se superpone a la barra de la aplicación al hacer scroll, y los chips/filas clicables del calendario no se operan con teclado.

| # | Gravedad | Dónde | Qué | Verificación |
|---|---|---|---|---|
| TR-01 | alta | `reportes.php:66` | La administradora no tiene corte ni vista consolidada por zona en el tablero de servicio | confirmado |
| TR-02 | media | `reportes.php:214` | No existe exportación a Excel, PDF ni PowerPoint de ningún reporte | matizado |
| TR-03 | alta | `reportes.php:329` | El 'rendimiento por técnico' que pide el jefe no existe: solo hay un conteo de carga | confirmado |
| TR-04 | alta | `reportes.php:157` | El cumplimiento del cronograma preventivo no aparece en ningún reporte | confirmado |
| TR-05 | alta | `cronograma.js:352` | El cronograma no escribe: confirmar kit, reagendar, registrar novedad y agendar terminan en un alert y no hay migración que cree sus tablas | confirmado |
| TR-06 | media | `cronograma.js:135` | Las tarjetas del cronograma no miden cumplimiento contra el plan original ni cuentan los 'cumplidos' | matizado |
| TR-07 | alta | `ordenes.php:39` | Órdenes y PDF siguen cortados por zona/técnico, en contra de la decisión nueva del archivo histórico para todos | confirmado |
| TR-08 | alta | `ordenes.php:47` | La pantalla solo lista órdenes de casos dentro de la ventana de 90 días del correo: no hay archivo histórico navegable | matizado |
| TR-09 | alta | `panel.php:71` | El inicio de la administradora mezcla las tres zonas: 'Lo que te toca ahora' y las cifras no se separan por zona | confirmado |
| TR-10 | media | `panel.php:347` | La bitácora se escribe pero no hay ninguna pantalla para consultarla: sin trazabilidad visible de usuarios | matizado |
| TR-11 | media | `cronograma.php:54` | El cronograma lee JSON generados en la estación, no la base: en Hostinger el 'real' y el kit quedan congelados | no se verificó aparte (media/baja) |
| TR-12 | media | `cronograma.html:30` | La navegación del cronograma está escrita a mano con 4 módulos y el JS nunca la rellena | no se verificó aparte (media/baja) |
| TR-13 | media | `cronograma.css:15` | La franja de alertas es sticky en top:0 con el mismo z-index que la barra de la aplicación y la tapa al hacer scroll | no se verificó aparte (media/baja) |
| TR-14 | media | `cronograma.js:108` | Chips de alerta y filas del calendario son clicables pero no operables con teclado | no se verificó aparte (media/baja) |
| TR-15 | media | `cronograma.js:180` | En la vista 'Las 3 zonas' el calendario y la lista no marcan la zona de cada ingreso | no se verificó aparte (media/baja) |
| TR-16 | media | `usuarios.php:294` | Cruce CSS: clases usadas sin regla en estilo.css (.acciones, tr.baja, .chip.abierta, .et.cumplido, .chip-local.cumplido) | no se verificó aparte (media/baja) |
| TR-17 | media | `usuarios.php:100` | Sin patrón POST-redirect-GET: recargar tras 'Nueva clave' vuelve a generar y reemplazar la contraseña | no se verificó aparte (media/baja) |
| TR-18 | media | `usuarios.php:152` | La lista de usuarios de la administración no agrupa por zona | no se verificó aparte (media/baja) |
| TR-19 | media | `usuarios.php:58` | No se puede editar nombre, correo, zona ni rol de un usuario existente | no se verificó aparte (media/baja) |
| TR-20 | media | `nucleo/Ui.php:65` | El módulo Usuarios exige 'usuarios.gestionar' en la barra, pero usuarios.php admite también 'usuarios.operativos' | no se verificó aparte (media/baja) |
| TR-21 | media | `graficos.js:118` | Los SVG llevan role="img" sin nombre accesible | no se verificó aparte (media/baja) |
| TR-22 | media | `reportes.php:223` | No hay selector de periodo: el reporte mensual para KFC no se puede acotar, y una cifra del histórico está escrita a mano | no se verificó aparte (media/baja) |
| TR-23 | media | `ordenes.php:130` | Filtros insuficientes y sin paginación para un archivo de todas las zonas | no se verificó aparte (media/baja) |
| TR-24 | baja | `cronograma.php:76` | Acceso directo a índices del JSON sin '??': un caso sin 'prioridad' o 'local_nombre' emite un Warning dentro de la respuesta JSON | no se verificó aparte (media/baja) |
| TR-25 | baja | `cronograma.php:174` | La ruta del Drive ('origen') y la lista de diagnóstico del maestro solo se quitan para jefes de zona; la administración las recibe | no se verificó aparte (media/baja) |

### Seguridad, sesión, alcance y trazabilidad → T2.14.6

La base de seguridad es sólida para una app sin framework: preparadas nativas, bcrypt, bloqueo por cuenta y por IP, sesión única con token en base, alcance filtrado en el servidor (Casos::enAlcance, Pendientes::alcance, Novedades::alcance), HMAC con hash_equals en sync_casos.php, recodificación de fotos con GD y todos los scripts de mantenimiento cerrados a CLI. Lo más grave está en el tramo de las órdenes emitidas, que es justo donde entra la nueva decisión de Andrés: los PDF con firma de personal de KFC viven dentro del docroot protegidos solo por .htaccess, ordenes.php incrusta en cada render enlaces firmados sin sesión para todos los PDF visibles sin dejar rastro de quién compartió, no registra ninguna consulta en bitácora y solo enseña las órdenes cuyo aviso sigue en la ventana de 90 días del buzón (el archivo histórico de 120 PDF no se puede recorrer). Además la caducidad por inactividad de 120 min no se cumple porque el sondeo de novedades.php cada 30 s renueva sesion_ultima, no hay token CSRF (solo SameSite=Lax), no hay CSP ni no-store en pantallas con datos personales, y quedan huecos de bitácora en denegaciones sin sesión, en catalogos.php y en el permiso novedades.reportar que no se comprueba en el servidor.

| # | Gravedad | Dónde | Qué | Verificación |
|---|---|---|---|---|
| SEG-01 | alta | `pdf.php:35` | Los PDF y las fotos de KFC viven dentro del docroot, protegidos solo por .htaccess | confirmado |
| SEG-02 | alta | `ordenes.php:217` | Compartir no deja rastro: los enlaces firmados sin sesión se generan para todos los PDF al pintar la tabla | confirmado |
| SEG-03 | alta | `ordenes.php:36` | La consulta del archivo de órdenes no se registra, y ver no se distingue de descargar | confirmado |
| SEG-04 | alta | `ordenes.php:46` | El «archivo histórico» solo enseña órdenes cuyo aviso sigue en la ventana de 90 días del buzón | confirmado |
| SEG-05 | alta | `nucleo/Auth.php:194` | La caducidad por inactividad (120 min) nunca se cumple en las pantallas con sondeo: novedades.php renueva la sesión cada 30 s | confirmado |
| SEG-06 | alta | `pdf.php:106` | El alcance por zona/técnico sobre las órdenes emitidas contradice la nueva decisión (todos leen todo) | confirmado |
| SEG-07 | media | `nucleo/Auth.php:59` | Ningún POST lleva token CSRF: la única defensa es SameSite=Lax | no se verificó aparte (media/baja) |
| SEG-08 | media | `.htaccess:35` | Sin Content-Security-Policy ni Permissions-Policy | no se verificó aparte (media/baja) |
| SEG-09 | media | `nucleo/Ui.php:142` | Las pantallas con datos personales no envían Cache-Control: no-store | no se verificó aparte (media/baja) |
| SEG-10 | media | `login.php:96` | La contraseña se devuelve en claro dentro del HTML cuando hay otra sesión abierta | no se verificó aparte (media/baja) |
| SEG-11 | media | `clave.php:14` | El cambio de contraseña no bloquea ni registra intentos fallidos de la clave actual | no se verificó aparte (media/baja) |
| SEG-12 | media | `nucleo/Novedades.php:154` | Novedades::reportar no comprueba el permiso novedades.reportar ni valida local/zona en el servidor | no se verificó aparte (media/baja) |
| SEG-13 | media | `pdf.php:74` | El mismo secreto firma la sincronización de la estación y los enlaces públicos de PDF, sin separación de dominio | no se verificó aparte (media/baja) |
| SEG-14 | media | `instalar.php:51` | instalar.php sigue desplegable por web y muestra el mensaje de excepción de la base sin clave | no se verificó aparte (media/baja) |
| SEG-15 | media | `nucleo/Db.php:40` | Sin manejador global de excepciones ni display_errors forzado a 0: un fallo de base pinta traza y credenciales parciales | no se verificó aparte (media/baja) |
| SEG-16 | media | `nucleo/Auth.php:464` | La IP de bloqueo y de bitácora es REMOTE_ADDR; detrás del CDN de Hostinger el tope por IP puede convertirse en bloqueo global | no se verificó aparte (media/baja) |
| SEG-17 | media | `catalogos.php:137` | El catálogo con 19 nombres de técnicos, 100 locales con correo y 1.173 activos sale a todo usuario con ots.crear sin dejar rastro | no se verificó aparte (media/baja) |
| SEG-18 | media | `aplicar_sql.php:88` | La bitácora no es de solo-anexar: el usuario MySQL de la app puede borrarla (mismas credenciales para DDL y operación) | no se verificó aparte (media/baja) |
| SEG-19 | media | `foto.php:82` | La foto se decodifica entera con GD sin comprobar dimensiones: una imagen «bomba» agota la memoria y deja la cola reintentando para siempre | no se verificó aparte (media/baja) |
| SEG-20 | media | `nucleo/Auth.php:272` | Huecos de bitácora en denegaciones: sin sesión no se registra nada, y varias denegaciones con sesión van sin exito=false o sin fila | no se verificó aparte (media/baja) |
| SEG-21 | media | `casos.php:714` | El aviso va dentro de un onclick con comillas simples: htmlspecialchars no protege un literal JavaScript | no se verificó aparte (media/baja) |
| SEG-22 | baja | `nucleo/Ui.php:214` | El flash se incrusta en <script> con json_encode sin JSON_HEX_TAG | no se verificó aparte (media/baja) |
| SEG-23 | baja | `sync_casos.php:114` | La firma HMAC no cubre la cabecera X-Industec-Tipo, admite repetición dentro de ±5 min y el log anónimo crece sin tope | no se verificó aparte (media/baja) |
| SEG-24 | baja | `.htaccess:10` | La lista negra de extensiones no cubre dotfiles ni copias de respaldo: .gitignore y *.orig/*.zip se sirven | no se verificó aparte (media/baja) |
| SEG-25 | baja | `salir.php:4` | Cierre de sesión por GET, cookie de sesión no invalidada en el navegador y sin session.use_strict_mode | no se verificó aparte (media/baja) |

### Esquema, migraciones y emisión → T2.14.0 / T2.14.6

El esquema 001-008 está bien pensado en claves de negocio e idempotencia de captura, y la reserva del correlativo es correcta; lo que no cierra es el tramo posterior a la captura: no existe ningún proceso que despache email_queue ni que reintente una emisión fallida, de modo que una OT cuyo PDF falló (o cuyo local es de zona OTRA, 4 locales reales) queda con número reservado, sin PDF ni correo e invisible para administración, mientras el reintento del celular pisa la carga de una orden ya emitida. Frente a lo que el negocio pidió hoy, al esquema le faltan de plano el índice del archivo histórico de las 7.069 OT de todas las zonas, los estados del flujo repuesto→validación del jefe→registro SAP→veredicto KFC, y las tablas de equipos nuevos, diagnósticos pre-redactados, administrador del local, manuales con versiones y comunicados. Lo más grave: la ausencia de reemisión y despachador (hallazgos 2 y 3), la exclusión de la zona OTRA (1) y el pisado de la carga tras emitir (4).

| # | Gravedad | Dónde | Qué | Verificación |
|---|---|---|---|---|
| E-01 | alta | `nucleo/Emision.php:46` | La zona OTRA existe en el esquema y en 4 locales reales, pero la emisión y pdf.php la rechazan: esas órdenes nunca reciben número ni PDF | confirmado |
| E-02 | alta | `envio.php:357` | Si dompdf falla, la orden queda con número reservado y sin PDF ni correo, y no existe ningún camino de reemisión: el único llamador es el envío del celular, que ya borró la orden al recibir 200 | confirmado |
| E-03 | alta | `app/sql/008_emision.sql:97` | email_queue no tiene despachador en la app, y al esquema le faltan las columnas que un despachador necesita (reintentos, reclamo, error) | confirmado |
| E-04 | alta | `envio.php:251` | El reintento del celular sobrescribe la carga y la fecha de recepción de una orden que ya fue emitida, dejando el registro distinto del PDF firmado y de su sha256 | confirmado |
| E-05 | alta | `app/sql/008_emision.sql:49` | No existe el índice del archivo histórico de OT de todas las zonas (7.069 órdenes) que Andrés decidió hoy que todos puedan consultar y descargar; ot_capturadas solo conoce lo emitido por la app y pdf.php/ordenes.php recortan por zona y técnico | confirmado |
| E-06 | media | `app/sql/007_pendientes_y_captura.sql:79` | El flujo repuesto → validación del jefe → registro en SAP → veredicto de KFC (taller INDUSTEC / otro proveedor / baja) no cabe en pendientes: falta la validación, falta el registro SAP y el veredicto del cliente se confunde con el de INDUSTEC | matizado |
| E-07 | media | `nucleo/Catalogo.php:40` | Un equipo nuevo registrado desde el formulario no tiene dónde persistir: el maestro de equipos es un JSON que la estación reescribe entero, y el PDF solo resuelve activos que ya estén en él | matizado |
| E-08 | media | `app/sql/007_pendientes_y_captura.sql:75` | No hay catálogo de diagnósticos pre-redactados ni administrador del local reutilizable: el diagnóstico es texto libre y el nombre del administrador vive solo dentro del JSON de cada orden | no se verificó aparte (media/baja) |
| E-09 | media | `app/sql/001_app_usuarios_hostinger.sql:56` | El esquema no tiene manuales/guías con aprobación y versiones, ni comunicados con acuse, ni permisos para ellos | no se verificó aparte (media/baja) |
| E-10 | media | `nucleo/Emision.php:143` | Regenerar un PDF perdido pisa pdf_sha256 y le pone fecha de emisión «ahora»: se pierde la huella del documento que realmente recibió KFC | no se verificó aparte (media/baja) |
| E-11 | media | `app/sql/008_emision.sql:66` | No existe ninguna política ni proceso de retención/purga: fotos huérfanas de borradores abandonados, .tmp residuales y filas RETENIDO con correos reales se acumulan sin límite, y los PDF —que por decisión de hoy son permanentes— no tienen esa condición escrita | no se verificó aparte (media/baja) |
| E-12 | media | `app/sql/007_pendientes_y_captura.sql:262` | La máquina de estados de ot_capturadas no refleja la emisión: RECHAZADA/motivo_rechazo nunca se escriben y los estados reales (numerada sin PDF, PDF sin correo, correo fallido) solo existen como combinaciones de NULL | no se verificó aparte (media/baja) |
| E-13 | media | `nucleo/Emision.php:203` | Un correo FALLIDO nunca vuelve a PENDIENTE al reemitir, y el permiso ots.reenviar es irrealizable con la UNIQUE (id_industec, tipo) | no se verificó aparte (media/baja) |
| E-14 | media | `app/sql/005_bitacora_analizable.sql:43` | Las migraciones 004, 005 y 006 no son idempotentes y aplicar_sql.php no lleva libro de migraciones: un segundo pase falla con error 1060 y nadie sabe por la base qué se aplicó | no se verificó aparte (media/baja) |
| E-15 | media | `nucleo/Emision.php:189` | En producción una orden sin destinatarios válidos se encola PENDIENTE con para='[]' y nadie lo detecta | no se verificó aparte (media/baja) |
| E-16 | media | `nucleo/config.hostinger.php:29` | La plantilla de configuración de producción no declara ninguna de las claves que la emisión lee (emision_modo, correo_fijos, correo_por_zona, dompdf_autoload) | no se verificó aparte (media/baja) |
| E-17 | media | `foto.php:82` | La recodificación en el servidor ignora la orientación EXIF: cuando el navegador no pudo reducir la foto, sale girada en el PDF que recibe KFC | no se verificó aparte (media/baja) |
| E-18 | media | `app/sql/007_pendientes_y_captura.sql:269` | ot_capturadas carece de índices para aviso, zona, local y emisión: las consultas del archivo de todas las zonas, de la reconciliación y del reemisor harán recorrido completo | no se verificó aparte (media/baja) |
| E-19 | media | `verificar_esquema.php:85` | verificar_esquema.php no comprueba nada de la 008 ni la intercalación de las tablas: puede decir «TODO OK» con la emisión rota | no se verificó aparte (media/baja) |
| E-20 | media | `nucleo/Emision.php:258` | El PDF embebe todas las fotos subidas bajo el envio_uuid, no las que la orden lista: una foto descartada en el formulario después de subida aparece igual en el documento | no se verificó aparte (media/baja) |
| E-21 | baja | `nucleo/Emision.php:147` | La etapa del PDF no tiene candado y usa un .tmp de nombre fijo: dos reintentos simultáneos chocan en el rename y uno anota un error de emisión falso | no se verificó aparte (media/baja) |
| E-22 | baja | `app/sql/003_gestion_casos.sql:46` | casos_gestion solo tiene FK en asignado_a; asignado_por, revision_por, veredicto_por, derivado_por y regularizado_por quedan sin integridad referencial, al revés que pendientes/novedades | no se verificó aparte (media/baja) |
| E-23 | baja | `foto.php:53` | El servidor no impone el tope de fotos por orden ni un tamaño realista de carga: acepta 99 posiciones y 12 MB de JSON cuando el cliente limita a 8 fotos y la carga ya solo lleva identificadores | no se verificó aparte (media/baja) |
| E-24 | baja | `app/sql/007_pendientes_y_captura.sql:5` | La 007 sigue diciendo que está «ESCRITA, NO APLICADA», que la orden queda sin PDF hasta «la 003» y cita una clase Repuestos que no existe | no se verificó aparte (media/baja) |

### Las pruebas → T2.14.7

Los 4 fallos de prueba_48h.php son de la PRUEBA, no del producto: desde que las horas del reloj las calcula MySQL (constante Pendientes::MINUTOS, min_plazo/min_veredicto), Pendientes::reloj() devuelve null cuando la fila con veredicto no trae min_veredicto, y la prueba sigue pasando abierto_en/veredicto_en como si el cálculo fuera en PHP; se arregla reescribiendo las 4 afirmaciones contra el contrato actual (min_veredicto en minutos), no retirándolas. Sí hay un defecto real colateral: cumplimiento48() trunca a horas enteras (TIMESTAMPDIFF HOUR) y reloj() compara minutos/60, así que un veredicto a las 48 h 50 min cuenta «a tiempo» en el panel y «tarde» en la fila. prueba_offline.mjs y prueba_contratos.mjs llevan D:/SOFTWARE/PHP83 pegado y prueba_48h.php d:/INDUSTECH IA; el «[object Object] is not valid JSON» nace de la doble serialización (JSON.stringify en la página + JSON.parse en Node sobre lo que devuelve ev() sin comprobar el tipo). Los verificar_*.py revientan en cp1252 por la flecha «→» de sus mensajes; basta sys.stdout.reconfigure(encoding='utf-8'). Nada prueba el alcance del selector de técnicos de asignacion.php, no existe CSRF en todo publico/ (0 coincidencias; solo SameSite=Lax), verificar_emision.py fija 403 para el técnico B y el jefe CNLJ —expectativa que la decisión nueva de Andrés (archivo de OT consultable por todos) invierte—, y ninguna prueba ejercita el despacho de la cola de correo ni la emisión con dompdf ausente (Emision::cargarDompdf lanza RuntimeException). Para correr en el PC de Andrés sin base: php -l, prueba_48h (tras el arreglo), prueba_contratos con PHP_BIN, prueba_graficos, fixtures y validacion_test; los verificar_*.py solo contra el sitio de pruebas por SSH, con PYTHONUTF8=1 mientras no se corrija.

| # | Gravedad | Dónde | Qué | Verificación |
|---|---|---|---|---|
| P-01 | alta | `app/pruebas/prueba_48h.php:80` | Los 4 fallos del reloj son prueba obsoleta: reloj() ya no calcula con abierto_en/veredicto_en sino con min_veredicto de SQL | sin verificar |
| P-02 | media | `nucleo/Pendientes.php:782` | cumplimiento48() trunca a horas enteras y reloj() compara minutos: el panel y la fila discrepan entre 48:00 y 48:59 | no se verificó aparte (media/baja) |
| P-03 | alta | `app/pruebas/prueba_48h.php:12` | Rutas absolutas d:/INDUSTECH IA/ pegadas en los require_once | sin verificar |
| P-04 | alta | `app/pruebas/prueba_offline.mjs:14` | PHP y Chrome con ruta pegada, y la carpeta a servir sin valor por defecto relativo a import.meta.url | sin verificar |
| P-05 | media | `app/pruebas/prueba_offline.mjs:30` | «[object Object] is not valid JSON»: doble serialización y ev() devuelve lo que sea sin comprobar el tipo | no se verificó aparte (media/baja) |
| P-06 | baja | `app/pruebas/prueba_contratos.mjs:283` | D:/SOFTWARE/PHP83/php.exe delante de 'php' en la resolución del binario (y en el comentario de uso de validacion_test.php) | no se verificó aparte (media/baja) |
| P-07 | media | `app/pruebas/servidor/verificar_http.py:43` | UnicodeEncodeError en Windows (cp1252) por la flecha «→» y otros símbolos en los mensajes de anotar() | no se verificó aparte (media/baja) |
| P-08 | alta | `app/pruebas/servidor/verificar_emision.py:171` | La prueba del PDF fija 403 para el técnico B y el jefe CNLJ: contradice la decisión nueva (archivo de OT consultable por todos) y no cubre el caso que reportó Andrés | sin verificar |
| P-09 | alta | `app/pruebas/servidor/verificar_http.py:116` | asignacion.php solo se comprueba como «carga sin error PHP»: nada prueba qué técnicos ofrece el selector ni a quién se puede asignar | sin verificar |
| P-10 | media | `nucleo/Auth.php:59` | No existe CSRF en todo publico/: la única barrera es SameSite=Lax y ninguna prueba lo mide | no se verificó aparte (media/baja) |
| P-11 | media | `app/pruebas/servidor/verificar_emision.py:147` | La cola de correo solo se comprueba como fila RETENIDO: nadie ejercita el despacho, el rechazo SMTP ni el reintento sin duplicar | no se verificó aparte (media/baja) |
| P-12 | alta | `nucleo/Emision.php:331` | Emisión con dompdf caído: cargarDompdf() lanza RuntimeException y ninguna prueba dice qué pasa con la orden ya recibida y el número reservado | sin verificar |
| P-13 | media | `app/pruebas/servidor/LEEME.md:36` | No está escrito cómo correr la batería en el PC de Andrés (PHP 8.2 portable, sin base) ni qué prueba corre dónde | no se verificó aparte (media/baja) |
| P-14 | baja | `app/pruebas/prueba_48h.php:18` | afirmar() no es a prueba de null: un contrato roto se reporta como fallo de valor y con Warning en vez de como «la función devolvió null» | no se verificó aparte (media/baja) |

### La estación, el sistema viejo y el corte → T2.15 / T2.16

El saneamiento continuo está escrito pero no corre: t2_4_sync_hostinger.py exige cinco claves HOSTINGER_* (USER/HOST/PORT/DOCROOT/SSH_KEY) que no existen en config/.env (solo hay SSH_USER, SYNC_*, IMAP_*, DB_*), así que desde la copia del 3-sep no hay espejo y cada día se pierden ~20 OT del sistema viejo; ESTADO.md:65-66 sigue diciendo «falta SSH» porque nadie adaptó el sync al contrato de llave/usuario que t2_10 estrenó el 9-sep (último commit del sync: 0b0907b). Después de eso, lo más grave es que el espejo ignora todo lo que produce el sistema nuevo (ordenes_pdf/, ordenes_fotos/, base u671729428_ots), sobreescribe divergencias en el espejo declarado inmutable, sale con código 0 cuando un módulo entero falla, y t2_11 deja PDFs del buzón dentro del árbol canónico donde t1_7_ingesta los recoge por rglob como órdenes con id_industec = nombre crudo. Para el corte hoy no existe ni desplegador a producción (t2_10 aborta fuera del sitio de pruebas y no puede subir los parches), ni siembra de contadores desde counter_*.txt, ni segunda copia que autorice purgar; guardas.php y produccion.htaccess son correctos en su lógica pero con instrucciones de enganche incompletas y rutas viejas.

| # | Gravedad | Dónde | Qué | Verificación |
|---|---|---|---|---|
| H01 | critica | `agentes/scripts/t2_4_sync_hostinger.py:89` | El espejo nunca ha corrido: exige claves HOSTINGER_* que config/.env no tiene; por eso ESTADO sigue en «falta SSH» aunque SSH funciona desde el 9-sep | confirmado |
| H02 | alta | `agentes/scripts/t2_4_sync_hostinger.py:61` | El espejo no cubre nada del sistema nuevo: ni ordenes_pdf/, ni ordenes_fotos/, ni un volcado de la base u671729428_ots | matizado |
| H03 | alta | `agentes/scripts/t2_4_sync_hostinger.py:266` | Un PDF divergente sobreescribe la copia anterior del espejo «crudo, nunca se modifica»: se pierde la única copia de una de las dos órdenes | confirmado |
| H04 | alta | `agentes/scripts/t2_4_sync_hostinger.py:384` | Si un módulo entero falla (p. ej. volvieron a mover la carpeta: exit 7), el script termina con código 0 y una tarea nocturna lo daría por bueno | confirmado |
| H05 | alta | `agentes/scripts/t2_11_informes_ot.py:93` | Los PDF bajados del buzón se guardan DENTRO del árbol canónico y la ingesta los recoge como órdenes con nombre crudo | confirmado |
| H06 | media | `agentes/scripts/t2_4_purga_hostinger.py:103` | La compuerta de «copia local» es un solo disco: purgar el servidor dejaría las OT en una única copia, contra la regla de las dos copias | matizado |
| H07 | alta | `agentes/scripts/t2_10_desplegar.py:64` | El desplegador no sirve para el corte: destino fijo al sitio de pruebas, origen fijo a app/publico, y los parches del sistema viejo ni siquiera pasan la compuerta | matizado |
| H08 | alta | `agentes/scripts/t2_4_sync_hostinger.py:302` | Los contadores reales se bajan sin verificar y nadie los consume: no existe la siembra de `correlativos` que el corte exige | confirmado |
| H09 | media | `agentes/scripts/t2_4_normalizar_nuevas.py:79` | La distinción «MARCA CONTIGUA» nunca se aplica: el SELECT del maestro no trae `nombre` | no se verificó aparte (media/baja) |
| H10 | media | `agentes/scripts/t2_4_normalizar_nuevas.py:49` | Rutas absolutas D:\INDUSTECH IA pegadas en cinco scripts, mientras t2_9 y t2_10 ya son relativas: el mismo pipeline apunta a carpetas distintas según el script | no se verificó aparte (media/baja) |
| H11 | media | `agentes/scripts/t2_4_sync_hostinger.py:195` | El lote sftp lleva el destino local con barras invertidas de Windows dentro de comillas simples, y nunca se ejercitó contra el servidor | no se verificó aparte (media/baja) |
| H12 | media | `agentes/scripts/t2_11_informes_ot.py:367` | Dos informes con el mismo nombre de adjunto (mismo correlativo por la carrera del contador) se confunden: gana el primer PDF y la caché atribuye el técnico equivocado | no se verificó aparte (media/baja) |
| H13 | media | `agentes/scripts/t2_11_informes_ot.py:82` | Al rotar SMTP o cambiar el remitente en el corte, el lector de informes queda ciego y sigue reportando éxito con 0 informes | no se verificó aparte (media/baja) |
| H14 | media | `sistema_ots/parches/guardas.php:10` | Instrucciones de enganche con rutas que ya no existen (ot/pruebas/) y la guarda 3 (escape de imágenes) no se engancha en ningún paso | no se verificó aparte (media/baja) |
| H15 | media | `agentes/scripts/t2_4_purga_hostinger.py:59` | La retención de la purga está en 30 días en el código y en 90 días en el documento rector | no se verificó aparte (media/baja) |
| H16 | media | `agentes/scripts/t2_4_normalizar_nuevas.py:109` | El módulo OTROS (clientes no-KFC) nunca se promueve: se acumula en el espejo y no entra al histórico | no se verificó aparte (media/baja) |
| H17 | media | `agentes/scripts/t2_4_sync_hostinger.py:28` | No existe el saneamiento nocturno: sync, normalización, ingesta y volcado son comandos manuales sin tarea, sin orquestador, sin verificación ni bitácora | no se verificó aparte (media/baja) |
| H18 | media | `agentes/scripts/t2_10_desplegar.py:17` | No existe un procedimiento de corte escrito: qué se congela, en qué orden, con qué verificación y con qué camino de vuelta | no se verificó aparte (media/baja) |
| H19 | baja | `sistema_ots/parches/uploads.htaccess:1` | Parche superado (rutas ot/pruebas/) sigue en parches/ junto al vigente y puede desplegarse por error | no se verificó aparte (media/baja) |
| H20 | baja | `agentes/scripts/t2_4_sync_hostinger.py:359` | Cada corrida vuelve a calcular el sha256 de todos los PDF locales dos veces (1,4 GB × 2) | no se verificó aparte (media/baja) |
| H21 | baja | `agentes/scripts/t2_11_informes_ot.py:486` | Las OT de un aviso se ordenan por `fecha` como texto: con dd/mm/aaaa, `ultima_fecha` sale mal | no se verificó aparte (media/baja) |
| H22 | baja | `agentes/scripts/t2_9_buzon_vigilante.py:115` | `empujar`, `abrir_log` y `cargar_env` están copiados en varios scripts y ya divergen | no se verificó aparte (media/baja) |

### Documentación contra realidad → T2.14.8

La documentación del proyecto está un paso atrás del sistema en dos sentidos opuestos: conserva como pendiente lo que ya está hecho y desplegado (la 007, el rediseño, el PDF de la app, SSH, el padrón) y, a la vez, describe como verificado lo que Andrés acaba de comprobar que falla en el sitio de pruebas (la pantalla de asignación, el PDF en el formulario del técnico). Lo más grave es lo que FALTA: no existe ninguna guía por rol, ninguna lista de cuentas ni un procedimiento para reportar fallos, así que INDUSTEC no puede empezar las pruebas con técnicos, jefes y administradora aunque el sistema esté listo; y la decisión nueva de Andrés (archivo histórico de todas las zonas para todos, solo lectura) contradice el alcance escrito en los cuatro documentos rectores y en las cabeceras de ordenes.php y pdf.php, además de exigir una decisión de arquitectura porque los 7.069 PDF históricos no viven en Hostinger sino en D:\RESPALDOS. Completan el cuadro los conteos de pruebas contradictorios (96·0 frente a 96·4, 54 frente a 57, v6 frente a v7, 45/48/55 archivos), las rutas absolutas D:\INDUSTECH IA y D:/SOFTWARE/PHP83 que impiden correr prueba_48h.php y prueba_offline.mjs en el PC, y dos casos reales de UIO que siguen documentados como asignados a una cuenta de prueba sin que ningún documento diga si se deshizo.

| # | Gravedad | Dónde | Qué | Verificación |
|---|---|---|---|---|
| DOC-01 | alta | `PLAN_INDUSTEC.md:575` | No existe ninguna documentación para que INDUSTEC empiece las pruebas: ni hojas por rol, ni cuentas, ni qué probar, ni cómo reportar fallos | confirmado |
| DOC-02 | alta | `ESTADO.md:89` | La decisión nueva de Andrés (archivo de OT de todas las zonas para todos, solo lectura) contradice el alcance escrito en los cuatro documentos rectores y no está registrada en ninguno | confirmado |
| DOC-03 | alta | `asignacion.php:53` | ESTADO da por verificada la pantalla de asignación («se lee de un barrido») pero el equipo sale mezclado sin división por zona y la cifra «Por repartir» no lleva al listado | confirmado |
| DOC-04 | alta | `app.js:17` | app.js, mis.php, publico/LEEME.md y ESTADO §1b siguen diciendo que la app «NO genera el PDF» cuando la 008 lo genera desde el 2026-09-11 — y es el texto que Andrés vio hoy | matizado |
| DOC-05 | alta | `ESTADO.md:150` | ESTADO §1b conserva siete filas «sin desplegar», «falta la 007» y «escrita, sin aplicar» que contradicen a la propia §1b (la 007 aplicada y el rediseño desplegado el 2026-09-11) | confirmado |
| DOC-06 | media | `PLAN_INDUSTEC.md:589` | Los conteos de las baterías de prueba están en tres versiones incompatibles dentro de PLAN y ESTADO (96·0 / 96·4, 54 / 57, 212 en total) | matizado |
| DOC-07 | alta | `app/pruebas/servidor/LEEME.md:54` | Dos casos reales de UIO (10353767 y 10353788) quedaron asignados a una cuenta de prueba y ningún documento dice si se deshizo antes del piloto | confirmado |
| DOC-08 | alta | `ESTADO.md:264` | ESTADO §3 y PLAN T2.1 ofrecen como «disponible» intervenir el sistema de producción, contra la decisión (a) del 2026-09-10 y la premisa de no tocar yellow-elephant | confirmado |
| DOC-09 | media | `app/pruebas/prueba_48h.php:12` | prueba_48h.php tiene pegada la ruta absoluta d:/INDUSTECH IA/ y no corre en el PC | no se verificó aparte (media/baja) |
| DOC-10 | media | `app/pruebas/prueba_offline.mjs:14` | prueba_offline.mjs tiene pegado D:/SOFTWARE/PHP83/php.exe y un Chrome fijo; prueba_contratos.mjs ya resolvió el mismo problema con PHP_BIN | no se verificó aparte (media/baja) |
| DOC-11 | media | `app/LEEME.md:44` | app/LEEME.md, ARQUITECTURA_SISTEMA_OTS.md y DISENO_APP_OTS.md afirman PHP 8.3 «la misma versión que corre en Hostinger»; el servidor corre 8.2.33 (la propia AUDITORIA lo señaló y sigue sin corregir) | no se verificó aparte (media/baja) |
| DOC-12 | media | `PLAN_APP_GESTION.md:184` | PLAN_APP_GESTION sigue diciendo que los PDF «siguen respondiendo HTTP 200 sin autenticación» y que el parche está «sin desplegar», cerrado desde el 2026-09-08; el documento entero es una «propuesta» ya ejecutada | no se verificó aparte (media/baja) |
| DOC-13 | media | `ESTADO.md:230` | ESTADO §3 «Bloqueado por el cliente» mantiene cinco bloqueos ya resueltos: SSH, contraseña del buzón Titan, lista de técnicos vigentes, usuario por técnico; y §1b dice «falta SSH» en dos filas | no se verificó aparte (media/baja) |
| DOC-14 | media | `PLAN_INDUSTEC.md:1057` | El PLAN dice en la misma sección que el servidor va en sw.js v6 y en v7 | no se verificó aparte (media/baja) |
| DOC-15 | media | `PLAN_INDUSTEC.md:1044` | La lista blanca del desplegador se documenta con 45 archivos (PLAN) y 48 (ESTADO); el código tiene 55 | no se verificó aparte (media/baja) |
| DOC-16 | media | `CLAUDE.md:71` | Todas las rutas operativas están pegadas a D:\INDUSTECH IA (la estación) y no aplican al PC; dos de ellas llevan además un carácter de control (\a) que rompe la ruta | no se verificó aparte (media/baja) |
| DOC-17 | media | `ESTADO.md:263` | La migración 003 de agentes/ (FORMULARIO_WEB, correlativos, email_queue) sigue como «va antes de T2.1.1» cuando la 008 de app/ ya creó correlativos y email_queue en la base operativa | no se verificó aparte (media/baja) |
| DOC-18 | media | `sistema_ots/LEEME_ACCESO_HOSTINGER.md:176` | La «guía operativa» instruye tocar producción (pasos 6 y 7: purga, parches, rotar SMTP, borrar phpinfo) sin decir que está diferida al corte, y sus pasos 1-3 describen un SSH que ya existe y un crontab que no | no se verificó aparte (media/baja) |
| DOC-19 | media | `PLAN_INDUSTEC.md:993` | §11b conserva en imperativo, bajo una nota «es registro de lo ya hecho», el procedimiento completo para aplicar la 007 y las instrucciones «mientras la aprobación llega» | no se verificó aparte (media/baja) |
| DOC-20 | media | `PLAN_INDUSTEC.md:967` | El mismo hecho («hecho el 2026-09-11 en el sitio de pruebas») está escrito tres veces —PLAN T2.12/T2.13, PLAN §11b y ESTADO §1b/§8— contra la regla «nada duplicado» de CLAUDE.md | no se verificó aparte (media/baja) |
| DOC-21 | baja | `ESTADO.md:6` | La cabecera de ESTADO dice «Última actualización: 2026-09-10» y «Fase 2 arrancada» mientras el cuerpo tiene entradas del 2026-09-12 y el PLAN dice «Fase 2 hecha» | no se verificó aparte (media/baja) |
| DOC-22 | baja | `LEEME.md:38` | publico/LEEME.md y app/LEEME.md describen una app de 6 archivos «en construcción» con catálogos «producción: MySQL»; hoy son 55 archivos, los catálogos salen de JSON empujados por la estación y el LEEME no nombra mis.php, cola.js, sw.js ni la 008 | no se verificó aparte (media/baja) |
| DOC-23 | baja | `AUDITORIA_2026-09-10.md:184` | La auditoría deja abierto que casos.php responde «fuera de su alcance» a un caso que salió del catálogo del buzón (aviso 10353555, incluso a un superadmin) y ESTADO no lo recoge entre lo pendiente | no se verificó aparte (media/baja) |

### La web corporativa → T2.17

El sitio está técnicamente sano (HTML semántico, contraste AA, foco visible, reduce-motion, 891 KB, verificadores en verde) pero visualmente es una plantilla "startup" genérica: system-ui, ilustración vectorial en la portada, 18 íconos de trazo en círculos rosados, cabeceras todas centradas, 38 logos a color en tarjetas y un rojo que significa a la vez «botón principal» y «emergencia»; no se lee como proveedor técnico industrial de cadenas sino como una app. Lo más grave para vender: en la portada la oferta (los 5 servicios) queda enterrada bajo dos paredes de logos (unos 1.700 px en celular), el único botón destacado del menú es el acceso del personal y no un canal de cliente (contenido.md 2.2 pedía WhatsApp; LEEME lo dejó pendiente de Andrés), y la «consultoría integral gratuita», la oferta de menor fricción, va como enlace subrayado bajo los botones. Dirección de rediseño (mismos textos, otra forma) — A «Sala de máquinas»: hero oscuro azul marino (#1E2438) a todo el ancho con la foto real del técnico en clave baja, tipografía display industrial autoalojada (Barlow/Barlow Semi Condensed, OFL, ~40 KB), rojo #CC504B solo como acento y emergencia, servicios antes que logos y logos en una franja monocroma de una fila; B «Contrato en marcha»: evolución clara del actual (fondo blanco, paleta vigente) reorganizada alrededor del ciclo «eliges → instalamos → mantenemos → respondemos 24/7» con las tres líneas (caliente, fría, ventilación) como eje visual y cabeceras alineadas a la izquierda con etiqueta; C «Cocina en operación»: fotográfico, cada sección anclada en una foto real a sangre con superposiciones, que exige las fotos de refrigeración y campana que César aún no entrega (NOTAS B2). Recomiendo A con la estructura de B: es la que más se aleja de «servicio para el hogar», depende menos del volumen de fotos disponible (solo hay 5), aprovecha el par rojo/azul del logo y cabe en las restricciones (sin CDN, sin compilar, < 1,5 MB, verificadores intactos salvo actualizar PAGINAS_CON_* si se separan los servicios por URL).

| # | Gravedad | Dónde | Qué | Verificación |
|---|---|---|---|---|
| W-01 | media | `web_corporativa/sitio/index.html:176` | La oferta (servicios) queda enterrada bajo 38 logos antes de «Qué hacemos» | matizado |
| W-02 | alta | `web_corporativa/sitio/index.html:87` | El único botón destacado del menú es el acceso del personal, no un canal para el cliente | matizado |
| W-03 | media | `web_corporativa/sitio/assets/css/estilos.css:120` | El rojo significa a la vez «botón principal» y «urgencia», y el color de WhatsApp cambia según el sitio de la página | matizado |
| W-04 | media | `web_corporativa/sitio/index.html:109` | La portada usa una ilustración vectorial en vez de la foto real: se lee como app, no como proveedor técnico industrial | no se verificó aparte (media/baja) |
| W-05 | media | `web_corporativa/sitio/assets/css/estilos.css:32` | Tipografía solo system-ui: la marca se ve distinta en cada dispositivo y no alcanza el registro «moderno/industrial» | no se verificó aparte (media/baja) |
| W-06 | media | `web_corporativa/sitio/index.html:104` | La «consultoría integral gratuita» (oferta de menor fricción) va como enlace subrayado y la portada acumula cuatro llamados en el mismo bloque | no se verificó aparte (media/baja) |
| W-07 | media | `web_corporativa/sitio/assets/css/estilos.css:328` | Ritmo monótono: todas las cabeceras centradas y 18 tarjetas «ícono de trazo en círculo rosado» iguales | no se verificó aparte (media/baja) |
| W-08 | media | `web_corporativa/sitio/index.html:122` | Franja de confianza: «2010» junto a «+40 años» invita a la duda y «1 solo» no funciona como cifra | no se verificó aparte (media/baja) |
| W-09 | media | `web_corporativa/sitio/nosotros/index.html:66` | Nosotros: el visual de cabecera es un ícono de medalla gigante en un círculo rosado y la página no tiene ni una foto | no se verificó aparte (media/baja) |
| W-10 | media | `web_corporativa/sitio/index.html:290` | Solo 5 fotos reales, repetidas en 3 páginas, a 640 px sin srcset y sin ampliación: la prueba visual del trabajo es débil | no se verificó aparte (media/baja) |
| W-11 | media | `web_corporativa/sitio/servicios/index.html:93` | SEO para el dominio definitivo: los cinco servicios no tienen URL propia y el LocalBusiness va sin dirección ni ciudad | no se verificó aparte (media/baja) |
| W-12 | media | `web_corporativa/sitio/assets/js/sitio.js:178` | El envío del formulario por WhatsApp depende de un clic programático sobre un enlace oculto con target=_blank, sin probar en Safari de iPhone | no se verificó aparte (media/baja) |
| W-13 | media | `web_corporativa/sitio/assets/css/estilos.css:14` | El azul del logo (#48537E) casi no aparece: la paleta real es un azul marino inventado (#1E2438/#242B45) más rojo | no se verificó aparte (media/baja) |
| W-14 | baja | `web_corporativa/sitio/assets/css/estilos.css:677` | Guía «¿Qué servicio necesitas?»: el nombre del servicio va con `white-space: nowrap` en una columna fija de 20,5 rem, con margen de pocos píxeles entre 768 y 959 px | no se verificó aparte (media/baja) |
| W-15 | baja | `web_corporativa/sitio/index.html:69` | Cabecera, pie, barra móvil y botón flotante están copiados a mano en las 5 páginas: cada cambio del rediseño se multiplica por cinco | no se verificó aparte (media/baja) |
| W-16 | baja | `web_corporativa/sitio/assets/css/estilos.css:407` | Logos a color, en 38 tarjetas con borde y alturas mixtas (48/64 px): ruido visual y lectura de catálogo | no se verificó aparte (media/baja) |
| W-17 | baja | `web_corporativa/sitio/contacto/index.html:74` | Contacto: la foto que se ve en el celular no lleva prioridad de carga y la oculta en escritorio sí | no se verificó aparte (media/baja) |

## 3. Lo que agregaron los verificadores (no estaba en las listas)

1. `pdf.php` rechaza enlaces con firma inválida (403) o caducados (410) **sin dejar fila** en bitácora
   ni en `sesiones_log`: un reenvío masivo de enlaces viejos o forjados no se ve en `minar.php`.
2. `pdf.php:44` valida el identificador sin distinguir mayúsculas, pero arma la ruta tal como llegó: en
   Linux `ot-…` pasa la validación, registra `ABRIR_PDF` y termina en 404.
3. `envio.php:375-381`: si el PDF salió pero `encolar()` falla, el recibo dice `EMITIDA` y «el correo al
   local sale de la cola» sin que exista fila en la cola (contra I-7).
4. `cola.js:269-277`: cuando la emisión falla el servidor responde 200 `ok:true` con estado `RECIBIDA` y
   el celular **destruye** la orden y las fotos igual; nada la reintenta (ver T2.14.6).
5. `Reconciliar.php:114`: un caso NUEVO con informe abierto firmado por un usuario desconocido queda en
   un **limbo**: no aparece en «sin repartir» (panel y asignación lo excluyen por tener informe) ni
   vence nunca (la reconciliación lo excluye por lo mismo). Solo se ve en el buzón sin filtro.
6. `Pendientes::veredicto()` (l.500-537): dos POST simultáneos pasan la comprobación; el segundo
   `UPDATE` afecta 0 filas y aun así se escriben la nota y la bitácora.
7. `envio.php:271` acepta `pendiente` con `concluida = 1` si el JSON viene fabricado (la app lo evita
   solo en el cliente).
8. `cronograma.js:192-226`: los ingresos **sin agendar** nunca se listan; solo son un número en la tile.
9. `app.js:1095-1098`: `pintarYo()` corre antes de que llegue el catálogo y lanza `TypeError` (se
   autocorrige después, pero es el mismo origen de H-05).

Todos entran en las subtareas de T2.14 junto a los hallazgos de su frente.

## 4. Lo que se decidió sin Andrés

Andrés no estaba disponible durante la construcción. Las decisiones que el diseño exigía se tomaron
por defecto, siempre la opción más conservadora, y están escritas una por una en
`PLAN_INDUSTEC.md` T2.14 (**D1 a D16**) para que las corrija con una palabra. Las que sí requieren
que él actúe o autorice: subir los PDF históricos a Hostinger (D2), programar los cron en hPanel
(D16), el segundo usuario MySQL sin `DROP/ALTER` para la web (SEG-18), la Tarea programada del
saneamiento nocturno en la estación (T2.15.4), y todo T2.16.
