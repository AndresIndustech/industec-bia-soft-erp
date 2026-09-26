# Vocabulario único de B.IA Soft ERP

**Versión del diccionario:** `2026-09-24.6` · **Fuente única:** `app/publico/vocabulario.json` · **Fecha:** 24 de septiembre de 2026

Este documento es para Andrés, para Isabel y para quien programe. La fuente es el JSON: si este documento y el JSON no coinciden, **manda el JSON**. La tabla de conceptos de la §4 se generó desde el JSON.

---

## 0. En una página

- **Una palabra, un significado, para todos.** Administración, jefe de zona, técnico, KFC (PDF, Excel, PPT y correos), tablero de gerencia y hojas del piloto usan los mismos términos. El código deja de escribir textos de estado a mano: pide el término por su **clave de concepto**, con `Vocabulario::t()` en PHP, `UI.T()` en JS o `termino()` en Python.
- **«orden»** es el trabajo que pide KFC, identificado por su **aviso SAP**. El documento que emite el técnico es la **OT INDUSTEC** (de evaluación / de cierre). No cambian el título del PDF «ORDEN DE TRABAJO INDUSTEC», el asunto del correo, el número OT-NNNN ni los campos «Estado de OT» y «Estado de Equipo».
- **La tarjeta «Por zona»** usa las cuatro filas que pidió Isabel: **ÓRDENES ABIERTAS**, **ÓRDENES A ESPERA DE INFORME TÉCNICO**, **EQUIPOS DESHABILITADOS** y **TOTAL DE ÓRDENES ABIERTAS**. Encima de las tarjetas, solo para la administración, va el **total general de las tres zonas**. Ninguna alarma de la tarjeta de antes se pierde: las seis quedan como sublínea o en el pie (§8.7). Cuenta **solo el catálogo de 90 días del buzón**, el mismo que lista el enlace de cada cifra (§8.1). Qué cambia frente a las cifras que Isabel ya conoce: §8.9.
- **No es la cifra de SAP.** El servidor no conoce el estado de SAP, y el correo trae más o menos la mitad de las órdenes que SAP tiene abiertas (cotejo del 10-sep-2026: SAP 62, buzón 31). Así lo dice la ayuda de la tarjeta. Traer el export de SAP al servidor es una tarea posterior.
- **Qué quedó hecho (ola 1, commit `ab887a8`, y fase Diccionario de la ola 2):**
  - `vocabulario.json` (103 conceptos, versión `2026-09-24.4`), `nucleo/Vocabulario.php`, `UI.T` en `ui.js` (con el respaldo embebido) y `termino()` en `comun.py`, las tres leyendo el mismo archivo. `Ui::ESTADOS`, `Pendientes::ESTADOS` / `VIAS` / `VEREDICTOS_KFC`, `Novedades::ESTADOS` y el respaldo de `ui.js` los escribe `herramientas/generar_vocabulario.php` desde el JSON (no se editan a mano; `--comprobar` dice si alguno se desvió).
  - La tarjeta «Por zona» y su clasificador (`Casos::informesPorAviso()`, `grupoOrden()`, `clasificar()`, `tarjetasPorZona()`, `casos.php?grupo=`), probados con `prueba_panel_zona.php` sobre una base sintética (70·0).
  - La compuerta de cobertura (un script de solo lectura que cruza el JSON con las constantes vivas del código y con el inventario de 1.224 textos de estado) **APRUEBA con cero en todo** contra la versión `.4` (§1, «Versión `2026-09-24.4`»). Comprobó que:
    - los 9 estados de `Ui::ESTADOS`, los 21 de `Pendientes::ESTADOS` y los 6 de `Novedades::ESTADOS` tienen **exactamente un** concepto: su mapa lleva a uno y su rótulo actual se retira hacia ese mismo concepto (los 9 heredados, con el rótulo «trámite anterior a la 009 · <paso>» que compone el JSON). También tienen concepto las 4 `VIAS`, las 5 decisiones de KFC y los 8 estados del preventivo de `cronograma.js`;
    - todo nombre actual del inventario tiene **un solo destino**: 990 nombres por su literal, 128 armados con nombres del diccionario («Casos sin asignar» = orden + sin asignar) y los 211 del cruce por concepto. Un botón «verbo + nombre» se mide por el nombre que toca («Cancelar el pendiente» → «pendiente»); solo los 7 que son un verbo solo o sin nombre de estado («Validar», «Empezar de nuevo») no cuentan;
    - ningún término elegido nombra dos conceptos ni cae en la lista negra, y ninguna ayuda pone el verbo de un actor en boca de otro (el jefe de zona valida, KFC decide, la administración resuelve);
    - todo concepto que un rol ve hoy lista ese rol: no hay vocabulario por rol.
- **Ola 2 (migración de textos, 24 a 26-sep-2026, versión `.5`):** pantallas de la oficina, módulo de repuestos y novedades, app del técnico, reportes a KFC y scripts de la estación ya hablan con el diccionario. `pruebas/prueba_lista_negra.php` pasó de **518 hallazgos a 0** y se corre **sin `--informe`**; lo que quedaba son códigos (permisos, alias SQL, clases CSS, códigos de grupo o de estado que se comparan), declarados uno por uno en `lista_negra_excepciones`. `pruebas/prueba_claves_vocabulario.php` comprueba que toda clave escrita en el código exista en el JSON. Lo que falta: la etapa E7 (hojas del piloto y capturas), el despliegue (primero UIO, I-8) y las decisiones abiertas de la ola 2 (§1, «Versión `2026-09-24.5`»).

---

## 1. Qué pidió Isabel y qué decidió Andrés

**Pedido (24-sep-2026).** Isabel, la administradora de INDUSTEC, pidió que el panel «Por zona» use los términos con que trabaja con SAP y KFC: ÓRDENES ABIERTAS, ÓRDENES A ESPERA DE INFORME TÉCNICO, EQUIPOS DESHABILITADOS y TOTAL DE ÓRDENES ABIERTAS, por zona.

**Directiva de Andrés (24-sep-2026).** Los **mismos términos en todo el sistema y para todos los roles**, no solo en el panel de la administradora.

**Decisiones de Andrés (24-sep-2026), cerradas:**

| # | Decisión |
|---|---|
| D-A | **ÓRDENES A ESPERA DE INFORME TÉCNICO** = órdenes abiertas **sin ninguna OT INDUSTEC emitida** (sin asignar + asignadas sin OT). **ÓRDENES ABIERTAS** = órdenes abiertas que **ya tienen OT INDUSTEC de evaluación** y les falta el cierre. Incluye las que esperan repuesto, con esta precedencia: **ESPERA_REPUESTO va SIEMPRE a ÓRDENES ABIERTAS**, aunque no tenga OT, porque hubo visita o diagnóstico. |
| D-B | **TOTAL DE ÓRDENES ABIERTAS** por zona = ÓRDENES ABIERTAS + ÓRDENES A ESPERA DE INFORME TÉCNICO. Es una partición exacta y la hace **una sola función**. Además va una línea con el **total general de las tres zonas**, como el «TOTAL ORDENES» del STATUS del martes. |
| D-C | **«orden»** = el trabajo que pide KFC, identificado por su aviso SAP. El documento del técnico es la **«OT INDUSTEC»** (de evaluación / de cierre), como las columnas «#OT INDUSTEC EVALUACION / CIERRE» del plan de Isabel. Son **contrato** y no cambian: el título del PDF, el asunto del correo, el número OT-NNNN y los campos «Estado de OT: Abierta/Cerrada» y «Estado de Equipo: Operativo/Deshabilitado». |
| D-D | **Por fases.** Ahora: el vocabulario único, la tarjeta con los datos de B.IA y una ayuda que diga que no es la cifra de SAP. **Traer el export SAP al servidor es posterior.** |

**Correcciones que fijó la verificación (24-sep-2026), aplicadas aquí:**
- **EQUIPOS DESHABILITADOS cuenta órdenes (avisos)** del TOTAL cuya evidencia más reciente del equipo dice Deshabilitado, una por orden, como el COUNTIFS de Isabel. Es un subconjunto del TOTAL y **no se suma**. Un estado vacío es «sin dato», **no Operativo**.
- ATENDIDO se llama **«atendida, por cerrar en SAP»**. «Cerrada» siempre lleva calificativo: en SAP / sin atención / por falta de OK.
- **Un estado de base → un solo concepto. Un nombre viejo → un solo destino.**
- **«pendiente»** se admite como sinónimo en prosa hacia Isabel y KFC, en «PENDIENTE OK OP´S» y como estado del preventivo (EJECUTADO · PENDIENTE · ATRASADO). No está en la lista negra.
- «deshabilitado» puede calificar a una orden: «orden con equipo deshabilitado».
- **«veredicto» se retira.** El jefe de zona **valida**, KFC **decide** y la administración **resuelve**.
- La pestaña «Avisos» del técnico pasa a **«Notificaciones»**, para que «aviso» quede solo para SAP.
- CNLJ se rotula **«ZONA CUENCA-LOJA»** en pantallas, PDF y correos. «ZONA C-L» queda solo en el STATUS, porque es contrato.
- Se cuenta **todo el buzón**, no solo los correctivos, y la ayuda lo dice.
- El **jefe de zona ve la tarjeta de su zona**, con los mismos términos.
- «OT CERRADA por falta de OK» entra al diccionario como concepto **reservado** (`CERRADA_SIN_OK`). Su estado nuevo, que requiere migración, es tarea posterior.

**Cómo se atendieron las objeciones de los cuatro revisores** (en `resultado_fuentes.json`):
- Los 11 estados de solicitud sin concepto ahora tienen uno: `SOLICITUD_TERMINADA`, `SOLICITUD_CANCELADA`, `SOLICITUD_HEREDADA` para los 9 anteriores a la 009, y conceptos propios para ENTREGADO, DEVUELTO_TALLER, TALLER_INDUSTEC y OTRO_PROVEEDOR.
- `DECISION_KFC` se separó de `VIA`: la vía la propone el técnico y la valida el jefe; la decisión es de KFC. Cada valor es su propio concepto.
- VALIDADO_JEFE tiene un solo destino, `POR_REGISTRAR_SAP`, y REPUESTO_ENVIADO otro, `REPUESTO_DESPACHADO`. «validada» queda como atributo (`VALIDADA`), no como estado.
- «trabado» → `EQUIPO_DESHABILITADO`, salvo «va como trabado» (app.js:1782), que va a `EQUIPO_OPERATIVO`. «Pendiente INDUSTEC / KFC / repuestos SAP» → solo el semáforo.
- «cerrada» nunca va sola. «abierta» sola es la fila 1, y el universo completo se dice siempre «total de órdenes abiertas». «en curso» sale de la orden y del preventivo.
- El «N órdenes abiertas» del técnico (app.js:1990) pasa a «N órdenes asignadas».
- La barra y las pestañas del técnico tienen un solo nombre por lista: Mis órdenes, Historial, Repuestos.
- El vocabulario de gerencia («SIN ORDEN», «con visita sin cierre») tiene destino, y se agregó el rol `GER`.
- El preventivo usa la leyenda del jefe técnico, con PENDIENTE incluido.
- «órdenes pendientes» y «en gestión» son sinónimos en prosa de `TOTAL_ABIERTAS`.
- Los 43 nombres que quedaban sin destino ya lo tienen.
- En la tarjeta, las fórmulas son las de la lente «CÁLCULO Y DATOS»: precedencia de ESPERA_REPUESTO; capturas FALLIDA y NUMERADA sin contar; conteo por aviso y no por equipo; guarda de disponibilidad de la 007; una sola vez por cadena de continuidad; sin avisos 9999 de prueba; OTRA y «sin zona».

**Versión `2026-09-24.2`: primera ronda de la compuerta de cobertura (24-sep-2026).** La versión `.1` dejaba 84 nombres sin destino, 4 con dos destinos, 3 estados con dos conceptos, 1 término con dos conceptos y 19 conceptos que un rol veía sin tenerlos. Se corrigió así (ningún significado de la tarjeta cambió):
- **Tres estados de la solicitud con dos conceptos.** SIN_VEREDICTO, TALLER_INDUSTEC y OTRO_PROVEEDOR tenían el mapa hacia un concepto y su rótulo en otro. Ahora su rótulo se retira hacia el mismo concepto del mapa, con contexto: «sin validar (estado heredado SIN_VEREDICTO)» → `SOLICITUD_HEREDADA`; «a taller de INDUSTEC (estado de la solicitud)» → `EN_TALLER_INDUSTEC`; «a otro proveedor (estado de la solicitud)» → `CON_OTRO_PROVEEDOR`. Sin contexto, «a taller de INDUSTEC» y «a otro proveedor» siguen siendo la decisión de KFC.
- **«Repuestos» nombraba dos cosas**: el módulo y el plural de la vía. «Repuestos» queda para el módulo (`MODULO_REPUESTOS`, que ahora también ve KFC en el Excel). La vía se dice «vía de repuesto» en frases y su rótulo sigue siendo «Repuesto».
- **«Resolver»** era el título de `RESOLUCION_ADMIN` y a la vez el botón de una novedad. El título pasa a «Resolución» y «Resolver» queda como verbo de botón, que no nombra ningún concepto.
- **«por confirmar»** del filtro de alerta de alcance va a `OTRO_TRABAJO_POR_DECIDIR` (junto con «con alerta»). Sin contexto sigue siendo el equipo nuevo por confirmar. «sin alerta» es la negación del filtro y está en fuera de alcance.
- **Concepto nuevo `PREV_MOVIMIENTO`**, «movimiento del ingreso». El panel «Novedades» del cronograma registra agenda, reagenda, kit, cierre y nota de un ingreso preventivo, y decía «Novedad registrada.», igual que una novedad del local. Así, «novedad» queda solo para lo que el técnico ve en el local.
- **«cierre» a secas es la OT INDUSTEC de cierre**: en la lista del buzón, «Cierre INDUSTEC», «con cierre», «Correctivo cerrado» y «Orden concluida <ID INDUSTEC>». El cierre de SAP se dice «cerrada en SAP» («Cierre SAP», «% cerrado», «cerrado sap»). El fin de una solicitud se dice «terminada» («Cerrados esta semana», «Llegó después del cierre»).
- **«pasada»**, la fecha comprometida en SAP que ya se cumplió sin atención (casos.php:948), va a `ATRASADO`, junto con «con atraso» y «DÍAS DE ATRASO» del preventivo. «vencida» sigue siendo solo el plazo de 48 h.
- **Los 84 nombres restantes** quedaron en el `reemplaza` o el `conserva` de un solo concepto: «registrado en SAP» → `PENDIENTE_OK_OPS`; «Sin orden asignada» → `SIN_AVISO_SAP`; «en cola» → `ENVIO_OT`; «Vivos en 90 días» → `TOTAL_ABIERTAS`, entre otros. Tres fueron a contratos: «sin aviso» (valor de «ORDEN SAP:» en el correo), «Atendido a tiempo» (aria-label de la pregunta de satisfacción) y «Cerradas» (RESUMEN del STATUS de Isabel). «decidido» (equipo propuesto) fue a fuera de alcance. La lista completa está en la §4.
- **Roles.** Se agregó el rol que ya ve hoy cada concepto: TEC en 13 conceptos, JZ en 5 y KFC en 2. Ejemplos: el técnico ve «en revisión», «regularizada» y «fuera del área»; el jefe de zona ve el semáforo en el Excel; KFC ve el módulo de repuestos en el Excel.

**Versión `2026-09-24.3`: segunda ronda de la compuerta (24-sep-2026).** La compuerta se endureció: ya mide el nombre que toca un botón «verbo + nombre», busca una palabra retirada sola hacia un concepto dentro del término de otro y revisa el reparto de verbos en las ayudas. Quedaban 2 nombres con dos destinos, 3 términos que nombraban dos conceptos y 2 ayudas con el verbo equivocado. Se corrigió así (ningún significado de la tarjeta cambió) y la compuerta **APRUEBA con cero en todo**:
- **«cierre» en el preventivo.** «cierre» sola es la OT INDUSTEC de cierre (`OT_CIERRE`), pero el preventivo decía «Atrasado · sin cierre registrado» y el botón «Registrar el cierre» (cronograma.js:747 y :893). El preventivo deja de usar esa palabra: `PREV_SIN_CIERRE` pasa a **«Atrasado · por marcar como ejecutado»** y el botón a **«Marcar como ejecutado»** (`PREV_EJECUTADO`, que retira «cierre (ingreso preventivo)» y «Registrar el cierre (ingreso preventivo)»). La clave `PREV_SIN_CIERRE` y el estado SINCERRAR de la base no cambian. El panel de movimientos del ingreso habla de «marca de ejecutado», no de «cierre».
- **«pendiente» como registro de repuesto.** Los botones del módulo de repuestos «Avanzar el pendiente» (pendientes.php:706), «Insistir por este pendiente» (:823) y «Cancelar el pendiente» (:830) ya tienen destino: los dos primeros van a `SOLICITUD` («Avanzar la solicitud», «Insistir por esta solicitud») y el tercero a `SOLICITUD_CANCELADA` («Cancelar la solicitud»). `SOLICITUD` retira además «pendiente (registro de repuesto)»; «pendiente» a secas sigue siendo el estado del preventivo. Los cuatro literales viejos entran a `lista_negra_contextual`, con su archivo.
- **Verbos.** La ayuda de `RESOLUCION_ADMIN` decía «lo que decide la administración» y la de `OTRO_TRABAJO_POR_DECIDIR`, «espera que la administración decida». Ahora: «lo que **resuelve** la administración» y «espera que la administración la **resuelva**».


**Versión `2026-09-24.4`: tercera ronda de la compuerta (24-sep-2026).** Un sondeo de lo que la compuerta no medía (rótulos sin palabra de estado conocida, «ok» que caían fuera del concepto del ítem) encontró **24 nombres sin destino y 3 con dos destinos**, y la ronda 3 dio NO APRUEBA. Se cerraron así; ningún significado de la tarjeta cambió y la compuerta **APRUEBA con cero en todo**:
- **El envío de la OT INDUSTEC tiene sus pasos con término propio.** `ENVIO_OT` queda como el concepto que los agrupa, y cada paso es un concepto: `ENVIO_EN_COLA` «en cola» (conserva «enviando», la misma OT saliendo con señal; retira «en espera» de la cola, «Enviando N órdenes…» y «N órdenes guardadas, esperando señal»), `ENVIO_DETENIDA` «detenida en el celular» (conserva sus tres motivos: «falta entrar», «de otro usuario», «sin permiso»), `ENVIO_RECIBIDA` «recibida en la oficina» (retira «Orden recibida.»), `ENVIO_EMITIDA` «emitida» (retira «procesada» y «Orden emitida.») y `ENVIO_RECHAZADA` «rechazada al enviar» (retira «rechazada» de la cola y de las capturas, y «Orden rechazada»). Son los rótulos de `cola.js`, de «Órdenes que enviaste desde la app» (`mis.php`) y del resultado del formulario (`app.js`). Los valores de `ot_capturadas.estado` no cambian.
- **Los estados de la novedad y las opciones del formulario dicen lo mismo.** «La estoy revisando» → `NOVEDAD_EN_ESTUDIO` («En estudio»); «Ya se atendió» → `NOVEDAD_RESUELTA` («Resuelta»: «atendida» es solo la orden, y la ayuda ya no dice «se atendió»); «No procede» → `NOVEDAD_DESCARTADA` («Descartada»); «Se le pidió el aviso a KFC» → `NOVEDAD_CON_AVISO`; «Lo asume INDUSTEC» → `NOVEDAD_ASUMIDA`. La descripción de debajo de cada opción se queda.
- **Concepto nuevo `NOVEDAD_OTRA_AREA`**, «de otra área» / «De otras áreas»: la categoría «ajenas» (novedad de un tipo distinto de `EQUIPO_CORRECTIVO`: eléctrico, ventilación, desagüe, obra civil). Retira «No es de INDUSTEC», «No son de INDUSTEC» y «De otras áreas sin decidir» (pasa a «De otras áreas, por decidir»); el chip ya decía «otra área». Se deja de decir «no es de INDUSTEC» porque se confunde con «no nos compete», que es la orden.
- **Equipo nuevo por confirmar, con sus formas.** `EQUIPO_PROPUESTO` gana la forma corta «por confirmar» y retira «propuesto» (opción del formulario), «Propuestos por otros técnicos (pendientes de aprobar)» y «Propuestos por los técnicos». Solo cambian rótulos: el formulario funciona igual (I-8). «Marcado como ya existente» (el mensaje al fusionar) va con «ya existía» a fuera de alcance.
- **Hojas de la estación.** «AGREGADAS» es una hoja que genera la estación en el STATUS (no es de la plantilla de Isabel) y trae correctivas nuevas con OT INDUSTEC de evaluación, repuesto pedido y sin cierre: son `ABIERTA`, y la hoja pasa a «ABIERTAS NUEVAS», junto a «OTRAS ABIERTAS». «ND INDUSTEC», la hoja que la respuesta del miércoles le agrega al libro de KFC, es **contrato**: la nombra la semilla 020 y la recibe KFC. La consola que decía «ND de INDUSTEC» pasa a «órdenes N/D de INDUSTEC» (`ND_SIN_PROVEEDOR`).
- **Tres palabras con dos sentidos, separadas.** «en espera»: la de la orden queda con su contexto («orden que espera repuesto», `ESPERA_REPUESTO`) y la de la cola pasa a «en cola». «rechazada»: a secas es el documento de Aprendizaje; el envío dice «rechazada al enviar». «nuevo»: el chip de una notificación sin abrir pasa a «sin leer» (`NOTIFICACION`); «nuevo» es el código crudo de la orden sin asignar.
- **Sueltos.** «confirmado en SAP» (bitácora) → `CERRADA_SAP`. «kit listo» (cronograma) va con los demás estados del kit a fuera de alcance: junta confirmado, entregado y disponible.
- **La tarjeta, como está hecha.** La sublínea «de ellas, con más de 90 días (fuera del catálogo)» sale de `tarjeta_zona`: el universo es solo el catálogo de 90 días (§8.1) y valdría 0 siempre. `FUERA_CATALOGO` queda **reservado** hasta que el servidor reciba la lista de avisos que KFC eliminó.
- **Lista negra.** Los 22 literales viejos de esta ronda entran en `lista_negra_contextual`, cada uno con su archivo y su reemplazo; por eso `prueba_lista_negra.php` sube de 493 a 518 hallazgos.
- **La compuerta siguió al JSON, sin aflojar ningún criterio:** la familia «envío de la OT» del inventario incluye los cinco pasos; la familia de la novedad incluye `NOVEDAD_OTRA_AREA`; y el rótulo de los 9 estados heredados de la solicitud se exige **exactamente** como lo compone el JSON («trámite anterior a la 009 · cotizando»), porque desde el commit `ab887a8` ya no es el término a secas.

**Versión `2026-09-24.5`: integración de la ola 2 (26-sep-2026).** Ningún término ni significado cambió; solo se registró lo que la migración de textos dejó como código o contrato:
- **`lista_negra_excepciones`** deja de estar vacía: 17 pares archivo + palabra, cada uno revisado en el código y con su motivo (el permiso `casos.veredicto` y el valor de acción `veredicto`; las claves de `Casos::TRANSICIONES`; los alias SQL `vencido`, `vencidos`, `abiertos`, `parado(s)`; los códigos de grupo `?g=abiertos|vencidos|cerrados`; las clases `est-asignado` y `est-resuelto`; el código `vencido` de `estado_hoy` del preventivo; el valor `fuente => 'asignado'` de `Casos::quienAtendio()`; y el COMMENT SQL de la migración de la Fase 1 en `t1_6e_altas_maestro.py`). Cada excepción lleva **`maximo`**: cuántas apariciones de código cubre. Si en ese archivo aparece una más, `prueba_lista_negra.php` vuelve a informar todas las de ese par y dice «EXCEPCIÓN EXCEDIDA»: así una excepción por archivo no tapa un texto visible que alguien agregue después.
- **Correo de la OT (contrato):** se completó con «Se ha generado una nueva OT», «Zona:», «Local:» y «Tipo de Trabajo:», que `t2_11_informes_ot.py` lee campo por campo. El valor de «Zona:» es el **código** (CNLJ), no el rótulo ZONA CUENCA-LOJA.
- **Mensajes de validación:** los 5 que llamaban «orden» al documento del técnico dicen ahora «OT INDUSTEC», iguales en `Validacion.php`, `reglas.js` y `agentes/scripts/t2_5_validacion.py`. El resto sigue fuera de alcance.

**Versión `2026-09-24.6`: correcciones de la verificación de la ola 2 (26-sep-2026).** Los verificadores leyeron cada pantalla con los ojos de cada rol y encontraron términos que nombraban dos cosas o le atribuían a un actor lo que hace otro. Ningún número de la tarjeta cambió; la compuerta sigue en **APRUEBA con cero en todo** y `prueba_lista_negra.php` en **0**:
- **«decidir» vuelve a ser solo de KFC.** `OTRO_TRABAJO_POR_DECIDIR` pasa a **«fuera del área, por resolver»** y `NOVEDAD_POR_DECIDIR` a **«por resolver»**: los resuelven la administración o el jefe de zona (el botón de la novedad ya decía «Resolver»). Las claves y el código de filtro `por_decidir` no cambian. En pantalla: el panel («Hay que resolver cuáles…»), el formulario del técnico («Lo revisa tu jefe de zona y resuelve si…») y la acción sobre un otro trabajo («Cómo se resuelve» en vez de «Decisión»). La columna «Decisión» / «decidido» de equipos propuestos sigue en `fuera_de_alcance`, como estaba.
- **Semáforo verde sin responsable.** `LE_TOCA_KFC_REPUESTO` decía «Responsable: KFC (repuesto en SAP)», pero el verde es ESPERA_REPUESTO **sin** ninguna solicitud a espera de KFC: incluye las por validar, por registrar en SAP, en taller de INDUSTEC, despachadas y sin solicitud. Le atribuía a KFC trabajo de INDUSTEC en el Excel, el PDF y el PPT que recibe KFC. Pasa a **«Repuesto en seguimiento»**. Partir el verde por quién debe la acción es lógica y queda para Andrés con Isabel (§11). La ayuda de `EMERGENTE` dice ahora lo que se calcula: más de 7 días **desde que llegó** la orden, o prioridad alta sin asignar.
- **Concepto nuevo `NUM_ORDEN_SAP`**, «n.º de orden SAP»: el número de la orden de trabajo que SAP crea para el aviso (campo `orden_trabajo`). Antes cada rol lo leía con un rótulo distinto («orden N» en la ficha del técnico, «Orden SAP» en el buzón, sin rótulo en la fila). «Orden SAP» chocaba con el «ORDEN SAP:» del correo de la OT, que es el **aviso** (contrato). La ficha del técnico dice además «Aviso SAP N», como el resto.
- **«orden en proceso»** (equipos.php) va a `ENVIO_RECIBIDA`, no a `ENVIO_EN_COLA`: la fila de `equipos_propuestos` la inserta `envio.php` en el servidor, así que la OT ya salió del celular. Se muestra «OT INDUSTEC recibida en la oficina, todavía sin número».
- **«Pendientes del STATUS del martes»** deja de ser `TOTAL_ABIERTAS`: las filas del STATUS son el plan de Isabel (arrastradas + altas con repuesto pedido), no el total de órdenes abiertas del buzón. En el tablero de gerencia se dice «Órdenes del STATUS del martes». El **correo del martes** vuelve a su texto de contrato, palabra por palabra («Durante la semana se registraron {total} órdenes abiertas y {cer} órdenes cerradas.», con UIO / LARB / Cuenca–Loja).
- **Copia pública del diccionario.** `vocabulario.json` se servía sin sesión con notas internas (nombres de personas, rutas de scripts, contratos, excepciones). Ahora `generar_vocabulario.php` escribe `vocabulario_publico.json` (término, plural, título, corto, ayuda y los mapas; la misma forma que el respaldo de `ui.js`), que es lo único que abren `.htaccess`, `ui.js` y `sw.js`. El JSON completo lo leen solo `Vocabulario.php` y `comun.py` desde el disco. `--comprobar` y `prueba_vocabulario.php` fallan si la copia se desvía.
- **Datos históricos, intactos.** Los literales que `regularizar_masivo_cli.php` y `t2_24_2_cerrar_masivo_cli.php` grabaron en la base el 21-sep-2026 («cerrado en SAP (…)», «regularizado (…)», «Cumplido a tiempo / tarde (cierre masivo)») vuelven a como se grabaron, para que una búsqueda por el texto encuentre esas filas. Están en `lista_negra_excepciones`; la consola sí usa el diccionario.
- **Textos sueltos** (sin cambio en el diccionario): «Notificación interna» de la oficina → «Recordatorio de la oficina» (no llega a la pestaña «Notificaciones»); el técnico dice «Insistir por esta solicitud» en las dos pantallas; «Hay N advertencias para revisar» (no «avisos»); «Máximo 7 equipos por OT INDUSTEC»; el aviso de permiso de la cola habla de «OT INDUSTEC»; el rol en «Mis órdenes» sale de `Ui::ROL` (el jefe de zona ya no aparece como «Técnico»); el globo de «Mis órdenes» cuenta asignadas + a espera de repuesto, como dice su ayuda; el cuadro de Asignación que sumaba las dos se titula «Asignadas y a espera de repuesto»; «en el total de órdenes abiertas» en los dos textos del panel que contaban el universo completo; los últimos «esperando» en prosa; el código de zona crudo del formulario pasa a su nombre; `catalogos.php` ya manda `estatus_clave` (§10.2).
- **Queda para Andrés (lógica, no texto):** Asignación cuenta «sin asignar» con `Casos::sinAsignar()` (NUEVO y **todo** EN_REVISION, con o sin técnico y con o sin OT), y el panel y el buzón cuentan «sin técnico» (NUEVO o EN_REVISION **sin** `asignado_a`). Con los mismos datos, la administración puede leer 4 en el panel y 5 en Asignación. Mientras se decide, Asignación dice lo que cuenta: «sin técnico o en revisión», «en todas las zonas» (OTRA y sin zona incluidas) y ya no rotula esa lista «a espera de informe técnico».

---

## 2. Reglas del diccionario

1. **El código conoce claves, no textos.** Una clave (`ESPERA_INFORME`) no cambia nunca; su texto se cambia en el JSON.
2. **Los estados de la orden van en femenino** porque acompañan a «orden»: asignada, atendida, cerrada en SAP, regularizada. Una forma en masculino (asignado, atendido, resuelto, abierto, vencido, cerrado, validado) es la huella de un texto viejo que hablaba de «caso». Por eso la lista negra puede incluirlas.
3. **`termino`** va en singular y en minúscula, para frases. **`plural`** se usa cuando `n ≠ 1`, también con `n = 0` («0 órdenes»). **`titulo`** es el rótulo, y va en MAYÚSCULAS solo en la tarjeta y en los términos que copian el registro de Isabel. **`corto`** es opcional y solo sirve para la barra del técnico y el chip de zona.
4. **`ayuda`**: una sola frase, igual para todos los roles.
5. **`reemplaza`**: los nombres que el concepto retira. **`conserva`**: los que ya existían y se quedan. **`sinonimos_prosa`**: formas de Isabel y KFC que se admiten **solo en prosa dirigida a ellos**, nunca como rótulo de pantalla.
6. **Nada de texto inventado.** Si una clave no existe, las tres API lanzan una excepción con la clave y la versión (I-7).

Roles: `ADM` administración (SUPERADMIN y ADMIN) · `JZ` jefe de zona (desde la 021 también atiende órdenes) · `TEC` técnico · `KFC` lo que recibe Grupo KFC · `GER` tablero y presentación de gerencia.

---

## 3. Estados de la base → concepto

| Dominio | Estado de la base | Concepto |
|---|---|---|
| orden (`casos_gestion.estado`) | NUEVO → `SIN_ASIGNAR` · ASIGNADO → `ASIGNADA` · EN_REVISION → `EN_REVISION` · ESPERA_REPUESTO → `ESPERA_REPUESTO` · ATENDIDO → `ATENDIDA` · RESUELTO → `CERRADA_SAP` · NO_COMPETE → `NO_COMPETE` · CERRADO_SIN_ATENCION → `CERRADA_SIN_ATENCION` · REGULARIZADO (de vista) → `REGULARIZADA` | 9 de 9 |
| solicitud (`pendientes.estado`) | SOLICITADO → `POR_VALIDAR` · VALIDADO_JEFE → `POR_REGISTRAR_SAP` · REGISTRADO_SAP y ESPERA_KFC → `PENDIENTE_OK_OPS` · REPUESTO_ENVIADO → `REPUESTO_DESPACHADO` · ENTREGADO → `REPUESTO_EN_LOCAL` · TALLER_INDUSTEC → `EN_TALLER_INDUSTEC` · DEVUELTO_TALLER → `DEVUELTO_TALLER` · OTRO_PROVEEDOR → `CON_OTRO_PROVEEDOR` · BAJA_APROBADA → `BAJA_APROBADA` · RESUELTO → `SOLICITUD_TERMINADA` · CANCELADO → `SOLICITUD_CANCELADA` · SIN_VEREDICTO, COTIZANDO, COMPRADO, EN_BODEGA, EN_TALLER, GARANTIA_RECLAMADA, GARANTIA_APROBADA, GARANTIA_NEGADA, BAJA_PROPUESTA → `SOLICITUD_HEREDADA` (se muestra con su paso de entonces, `detalle_pendiente_heredado`) | 21 de 21 |
| novedad | REPORTADA → `NOVEDAD_REPORTADA` · EN_REVISION → `NOVEDAD_EN_ESTUDIO` · DERIVADA_SAP → `NOVEDAD_CON_AVISO` · ASUMIDA_INDUSTEC → `NOVEDAD_ASUMIDA` · DESCARTADA → `NOVEDAD_DESCARTADA` · RESUELTA → `NOVEDAD_RESUELTA` | 6 de 6 |
| preventivo (`estado_hoy`) | CUMPLIDO → `PREV_EJECUTADO` · PLANIFICADO → `PREV_PENDIENTE` · PORINICIAR → `PREV_POR_INICIAR` · SINAGENDAR → `PREV_SIN_AGENDAR` · ENCURSO → `PREV_EN_EJECUCION` · SINCERRAR → `PREV_SIN_CIERRE` · VENCIDO → `ATRASADO` · CANCELADO → `PREV_CANCELADO` | 8 de 8 |
| vía / decisión de KFC | REPUESTO, REPARACION, GARANTIA, BAJA → `VIA_*` · PENDIENTE, REPUESTO_ENVIADO, TALLER_INDUSTEC, OTRO_PROVEEDOR, BAJA → `KFC_*` | 4 + 5 |
| formulario | estado_ot ABIERTA → `OT_EVALUACION`, CERRADA → `OT_CIERRE` · estado del equipo OPERATIVO → `EQUIPO_OPERATIVO`, DESHABILITADO → `EQUIPO_DESHABILITADO`, vacío → `SIN_DATO_EQUIPO` | — |
| zona / semáforo | UIO, LARB, CNLJ, OTRA, vacío → `ZONA_*` / `SIN_ZONA` · amarillo, naranja, verde, rojo → `LE_TOCA_INDUSTEC`, `LE_TOCA_KFC`, `LE_TOCA_KFC_REPUESTO`, `EMERGENTE` | — |

El preventivo se agrupa en las tres bases de la leyenda del jefe técnico nacional (CRONOGRAMA 3RA Y 4TA VUELTA LARB, 14-sep-2026):
- **EJECUTADO**: ejecutado.
- **PENDIENTE**: pendiente, arranca en 3 días o menos, sin agendar, en ejecución.
- **ATRASADO**: atrasado, y atrasado por marcar como ejecutado.

Cancelado queda fuera de la leyenda. FERIADO todavía no existe en B.IA.

---

## 4. Concepto × término × quién lo ve × qué reemplaza

«Estado BD» indica qué valor de la base apunta al concepto. «—» significa que el concepto es derivado, un atributo o un rótulo.

#### La orden y sus documentos

| Clave | Término (singular / plural) | Título | Estado BD | Quién lo ve | Reemplaza |
|---|---|---|---|---|---|
| `ORDEN` | orden / órdenes | Órdenes | — | ADM JZ TEC KFC GER | «caso (el trabajo que pide KFC)»; «casos»; «Buzón de casos»; «Casos por zona»; «N casos en tu alcance»; «casos vigentes»; «lo abierto por KFC» |
| `AVISO_SAP` | aviso SAP / avisos SAP | Aviso SAP | — | ADM JZ TEC KFC GER | «# OT (columna del aviso en los reportes de B.IA)» |
| `NUM_ORDEN_SAP` | n.º de orden SAP | N.º de orden SAP | — | ADM JZ TEC | «Orden SAP (columna del buzón con el número de la orden de trabajo de SAP)»; «orden (número de la orden de trabajo de SAP en la ficha del técnico)» |
| `OT_INDUSTEC` | OT INDUSTEC | OT INDUSTEC | — | ADM JZ TEC KFC GER | «orden (como documento que emite el técnico)»; «orden de trabajo»; «informe (como documento del técnico)»; «informe de la orden»; «Con informe»; «Emitir la orden»; «Emitir la orden de este caso»; «Orden X emitida»; «Archivo de órdenes»; «Órdenes que enviaste desde la app»; «Atención (columna y filtro del buzón)»; «PDF (en pantallas, por el documento)»; «Nueva orden»; «Órdenes emitidas»; «Casos atendidos por técnico» |
| `OT_EVALUACION` | OT INDUSTEC de evaluación | OT INDUSTEC de evaluación | estado_ot:ABIERTA | ADM JZ TEC KFC GER | «Correctivo (evaluación abierta)»; «Orden no concluida»; «orden X sin concluir» |
| `OT_CIERRE` | OT INDUSTEC de cierre | OT INDUSTEC de cierre | estado_ot:CERRADA | ADM JZ TEC KFC GER | «orden de cierre»; «Orden de cierre (columna)»; «Con orden de cierre (filtro del buzón)»; «No hay PDF de cierre»; «CERRADAS SEMANA (hoja del STATUS generado)»; «cierre»; «Cierre INDUSTEC»; «con cierre»; «Correctivo cerrado»; «cerradas de la semana»; «orden X concluida»; «Orden concluida <ID INDUSTEC>»; «Orden concluida OT»; «informe sin técnico reconocido» |
| `OT_NO_EMITIDA` | OT INDUSTEC no emitida / OT INDUSTEC no emitidas | OT INDUSTEC no emitida | — | ADM JZ TEC | — |
| `ENVIO_OT` | envío de la OT INDUSTEC / envíos de OT INDUSTEC | Envío de la OT | — | ADM JZ TEC | «enviaste una orden (chip)» |
| `ENVIO_EN_COLA` | en cola | En cola | — | ADM JZ TEC | «en espera (cola de envío de la OT INDUSTEC)»; «orden en cola»; «N órdenes guardadas, esperando señal»; «Enviando N órdenes…» |
| `ENVIO_DETENIDA` | detenida en el celular / detenidas en el celular | Detenida en el celular | — | ADM JZ TEC | «N órdenes esperando que vuelvas a entrar» |
| `ENVIO_RECIBIDA` | recibida en la oficina / recibidas en la oficina | Recibida en la oficina | — | ADM JZ TEC | «Orden recibida.»; «orden en proceso» |
| `ENVIO_EMITIDA` | emitida / emitidas | Emitida | — | ADM JZ TEC | «procesada»; «Orden emitida.» |
| `ENVIO_RECHAZADA` | rechazada al enviar / rechazadas al enviar | Rechazada al enviar | — | ADM JZ TEC | «rechazada (envío de la OT INDUSTEC)»; «Orden rechazada» |
| `INFORME_DETALLADO` | informe técnico detallado / informes técnicos detallados | Informe técnico detallado | — | ADM JZ TEC KFC | «informe técnico (el que se adjunta para baja, garantía o material)» |
| `SIN_AVISO_SAP` | OT INDUSTEC sin aviso SAP | Sin aviso SAP | — | ADM JZ TEC KFC | «Por regularizar»; «regularizarla»; «Sin orden asignada»; «Lo regulariza administración» |

#### Estado de la orden (casos_gestion)

| Clave | Término (singular / plural) | Título | Estado BD | Quién lo ve | Reemplaza |
|---|---|---|---|---|---|
| `SIN_ASIGNAR` | sin asignar | Sin asignar | caso:NUEVO | ADM JZ TEC KFC GER | «sin repartir»; «N sin repartir»; «Ni casos sin repartir»; «Por repartir»; «por repartir»; «Repartir →»; «POR ASIGNAR»; «llegó del correo, sin técnico»; «NUEVO (código crudo en la bitácora)»; «por repartir, … Toca (hoja del piloto del jefe de zona)» |
| `ASIGNADA` | asignada / asignadas | Asignadas | caso:ASIGNADO | ADM JZ TEC KFC GER | «asignado»; «Asignados»; «Pendientes (pestaña del técnico)»; «No tienes órdenes pendientes»; «Tienes N casos asignados»; «N órdenes abiertas (app.js, cuenta las asignadas del técnico)»; «En manos del equipo»; «abiertos (Asignación)»; «Sin nada abierto»; «asignados y todavía abiertos»; «Te asignaron un caso»; «Te quitaron un caso»; «asignado auto» |
| `ASIGNADA_3D` | asignada hace 3+ días, a espera de informe técnico / asignadas hace 3+ días, a espera de informe técnico | Asignadas hace 3+ días | — | ADM JZ | «asignados hace 3 días o más, sin informe»; «asignados 3+ días sin informe»; «sin informe hace 3+ días» |
| `EN_REVISION` | en revisión | En revisión | caso:EN_REVISION | ADM JZ TEC KFC | «enviado a la administración» |
| `RESOLUCION_ADMIN` | resolución de la administración / resoluciones de la administración | Resolución | — | ADM JZ TEC | «Veredicto (acción sobre la orden en revisión)»; «en revisión, esperando tu veredicto»; «Cerrado por la administración» |
| `ESPERA_REPUESTO` | a espera de repuesto | A espera de repuesto | caso:ESPERA_REPUESTO | ADM JZ TEC KFC GER | «espera repuesto»; «Esperando (pestaña del técnico)»; «Esperando un equipo»; «en espera (orden que espera repuesto)»; «esperando repuesto»; «No pude concluir»; «sin concluir»; «PENDIENTES (hoja del tablero de gerencia)» |
| `ATENDIDA` | atendida, por cerrar en SAP / atendidas, por cerrar en SAP | Atendidas, por cerrar en SAP | caso:ATENDIDO | ADM JZ TEC KFC GER | «atendido»; «N atendidos, esperando que los cierres en SAP»; «atendidos por cerrar»; «cierres por confirmar»; «orden emitida; falta cerrarlo en SAP»; «Ya atendidos · N cerrados»; «con orden de cierre (chip)»; «Este caso ya está cerrado»; «cierra los dos casos»; «POR CERRAR EN SAP»; «ya cerrados por INDUSTEC»; «QUITADAS (hoja del STATUS generado)»; «atendidos por técnico» |
| `CERRADA_SAP` | cerrada en SAP / cerradas en SAP | Cerradas en SAP | caso:RESUELTO | ADM JZ TEC KFC GER | «resuelto (orden)»; «cerrado por las dos partes»; «confirmado como cerrado en SAP»; «Ya lo cerré en SAP»; «Nos compete y está resuelto»; «cerrado en SAP»; «cerrado sap»; «Cierre SAP»; «cierre SAP sin estar atendido»; «% cerrado»; «confirmado en SAP» |
| `NO_COMPETE` | no nos compete / no nos competen | No nos compete | caso:NO_COMPETE | ADM JZ TEC KFC GER | — |
| `CERRADA_SIN_ATENCION` | cerrada sin atención / cerradas sin atención | Cerradas sin atención | caso:CERRADO_SIN_ATENCION | ADM JZ TEC KFC GER | «sin atender»; «cerrado por falta de atención»; «cerrado sin atencion»; «cerrar sin atencion»; «Candidatos a cerrarse por falta de atención»; «sin informe de atencion» |
| `SIN_REGULARIZAR` | cerrada sin atención, sin regularizar ante KFC / cerradas sin atención, sin regularizar ante KFC | Sin regularizar | — | ADM JZ TEC | «cerrados sin atención, sin regularizar ante KFC» |
| `REGULARIZADA` | regularizada / regularizadas | Regularizadas | caso:REGULARIZADO | ADM JZ TEC KFC GER | «regularizado» |
| `CERRADA_SIN_OK` | cerrada por falta de OK / cerradas por falta de OK | Cerradas por falta de OK | — | ADM JZ TEC KFC GER | **RESERVADO** (sin estado todavía) |

#### Conjuntos de la tarjeta y del tablero

| Clave | Término (singular / plural) | Título | Estado BD | Quién lo ve | Reemplaza |
|---|---|---|---|---|---|
| `ABIERTA` | abierta / abiertas | ÓRDENES ABIERTAS | — | ADM JZ TEC KFC GER | «con la orden abierta»; «Con la orden abierta»; «en curso (ficha del técnico)»; «con visita pero sin orden de cierre»; «con visita sin cierre»; «atendido, en curso»; «Atendidos, en curso»; «AGREGADAS (hoja del STATUS generado)» |
| `ESPERA_INFORME` | a espera de informe técnico | ÓRDENES A ESPERA DE INFORME TÉCNICO | — | ADM JZ TEC KFC GER | «SIN ORDEN (tablero de gerencia)»; «sin ninguna orden de INDUSTEC»; «sin ninguna orden nuestra»; «sin informe»; «Sin atender (filtro «Atención» del buzón)»; «nadie ha mandado la orden todavía»; «sin orden emitida» |
| `TOTAL_ABIERTAS` | total de órdenes abiertas | TOTAL DE ÓRDENES ABIERTAS | — | ADM JZ TEC KFC GER | «Siguen abiertos»; «Casos abiertos»; «Abiertos ahora»; «Abiertos»; «Antigüedad de lo abierto»; «el caso vuelve a estar abierto»; «Pendientes del STATUS del martes»; «Vivos en 90 días» |
| `TOTAL_GENERAL` | total general de órdenes abiertas | TOTAL GENERAL DE ÓRDENES ABIERTAS | — | ADM KFC GER | — |
| `FUERA_CATALOGO` | con más de 90 días (fuera del catálogo) | Con más de 90 días | — | ADM JZ | **RESERVADO** (la tarjeta no lo pinta: el universo es el catálogo de 90 días) |
| `ORDENES_NUEVAS_7D` | orden nueva en 7 días / órdenes nuevas en 7 días | Órdenes nuevas en 7 días | — | ADM JZ KFC GER | «Llegaron en los últimos 7 días» |
| `FECHA_SAP_HOY` | con fecha SAP hoy | Con fecha SAP hoy | — | ADM JZ TEC | «Comprometidos para hoy»; «Comprometidos hoy» |

#### Equipo y plazo de 48 h

| Clave | Término (singular / plural) | Título | Estado BD | Quién lo ve | Reemplaza |
|---|---|---|---|---|---|
| `EQUIPO_DESHABILITADO` | equipo deshabilitado / equipos deshabilitados | EQUIPOS DESHABILITADOS | estado_equipo:DESHABILITADO | ADM JZ TEC KFC GER | «fuera de servicio»; «Equipo fuera de servicio»; «parado»; «equipos parados»; «trabado»; «el equipo quedó trabado»; «equipo trabado»; «deshabilitadas N» |
| `EQUIPO_OPERATIVO` | equipo operativo / equipos operativos | Operativos | estado_equipo:OPERATIVO | ADM JZ TEC KFC GER | «Sí, quedó operando»; «sigue operando»; «operando con normalidad»; «va como trabado» |
| `SIN_DATO_EQUIPO` | sin dato del equipo | Sin dato del equipo | estado_equipo:(vacío) | ADM JZ TEC KFC GER | — |
| `POR_VALIDAR` | por validar | Por validar | pendiente:SOLICITADO | ADM JZ TEC KFC | «esperando veredicto, con plazo vivo»; «sin veredicto»; «Con el reloj corriendo»; «Reloj corriendo»; «solicitado»; «sin validar»; «Todavía dentro del plazo» |
| `VENCIDO_48H` | vencida (más de 48 h sin validar) / vencidas (más de 48 h sin validar) | Vencidas (48 h) | — | ADM JZ TEC KFC GER | «vencidos 48 h»; «Vencidos ahora»; «vencido hace N»; «Fuera de plazo»; «equipos parados sin veredicto, fuera de plazo»; «Repuestos vencidos»; «Rep. vencidos»; «repuestos fuera de las 48 h»; «Plazo de 48 horas vencido» |
| `VALIDADA` | validada por el jefe de zona / validadas por el jefe de zona | Validadas | — | ADM JZ TEC KFC | «Validados a tiempo»; «Validados tarde»; «Decididos a tiempo»; «Decididos tarde»; «veredicto de tu jefe de zona»; «Decidir ahora»; «validado en N h»; «Validado»; «Reloj ya cerrado» |

#### Solicitud de repuesto o equipo (tabla pendientes)

| Clave | Término (singular / plural) | Título | Estado BD | Quién lo ve | Reemplaza |
|---|---|---|---|---|---|
| `SOLICITUD` | solicitud / solicitudes | Solicitud | — | ADM JZ TEC KFC | «Ver pendiente»; «pendiente de repuestos abierto»; «equipo pendiente»; «Registrado. (mensaje al guardar la solicitud)»; «pendiente (registro de repuesto)»; «Avanzar el pendiente»; «Insistir por este pendiente» |
| `MODULO_REPUESTOS` | repuestos y equipos | Repuestos y equipos | — | ADM JZ TEC KFC | «Pendientes (módulo de repuestos, en mensajes de error)» |
| `SOLICITUD_EN_TRAMITE` | solicitud en trámite / solicitudes en trámite | En trámite | — | ADM JZ TEC | «Todos los vivos»; «pendientes de equipo abiertos»; «cierre SAP con pendiente abierto» |
| `POR_REGISTRAR_SAP` | validada, por registrar en SAP / validadas, por registrar en SAP | Por registrar en SAP | pendiente:VALIDADO_JEFE | ADM JZ TEC KFC | «validado por el jefe»; «repuestos validados, por registrar en SAP» |
| `PENDIENTE_OK_OPS` | pendiente OK de OP´S / pendientes OK de OP´S | PENDIENTE OK OP´S | pendiente:REGISTRADO_SAP, pendiente:ESPERA_KFC | ADM JZ TEC KFC GER | «Esperando a KFC»; «registrado en SAP, esperando a KFC»; «esperando a KFC»; «registrado en SAP»; «registrado hoy»; «Registrados en SAP, sin decisión»; «Se espera a KFC»; «Nada esperando a Grupo KFC» |
| `REPUESTO_DESPACHADO` | repuesto despachado / repuestos despachados | Repuesto despachado | pendiente:REPUESTO_ENVIADO | ADM JZ TEC KFC GER | «KFC envía el repuesto»; «repuesto enviado» |
| `REPUESTO_EN_LOCAL` | repuesto en el local / repuestos en el local | Repuesto en el local | pendiente:ENTREGADO | ADM JZ TEC KFC | «entregado» |
| `EN_TALLER_INDUSTEC` | en taller de INDUSTEC | En taller de INDUSTEC | pendiente:TALLER_INDUSTEC | ADM JZ TEC KFC | «a taller de INDUSTEC (estado de la solicitud)» |
| `DEVUELTO_TALLER` | de vuelta del taller | De vuelta del taller | pendiente:DEVUELTO_TALLER | ADM JZ TEC KFC | «vuelto del taller» |
| `CON_OTRO_PROVEEDOR` | con otro proveedor | Con otro proveedor | pendiente:OTRO_PROVEEDOR | ADM JZ TEC KFC | «a otro proveedor (estado de la solicitud)» |
| `BAJA_APROBADA` | baja aprobada por KFC / bajas aprobadas por KFC | Baja aprobada por KFC | pendiente:BAJA_APROBADA | ADM JZ TEC KFC | «baja aprobada» |
| `SOLICITUD_TERMINADA` | terminada / terminadas | Terminadas | pendiente:RESUELTO | ADM JZ TEC KFC | «resuelto (solicitud de repuesto)»; «Ese pendiente ya está cerrado»; «Ya resueltos»; «Cerrados esta semana»; «Llegó después del cierre»; «Cierre (nota de la solicitud terminada)» |
| `SOLICITUD_CANCELADA` | cancelada (no procedía) / canceladas (no procedía) | Canceladas | pendiente:CANCELADO | ADM JZ TEC | «cancelado (solicitud de repuesto)»; «Cancelar el pendiente» |
| `SOLICITUD_HEREDADA` | trámite anterior a la 009 / trámites anteriores a la 009 | Trámite anterior | pendiente:SIN_VEREDICTO, pendiente:COTIZANDO, pendiente:COMPRADO, pendiente:EN_BODEGA, pendiente:EN_TALLER, pendiente:GARANTIA_RECLAMADA, pendiente:GARANTIA_APROBADA, pendiente:GARANTIA_NEGADA, pendiente:BAJA_PROPUESTA | ADM JZ TEC | «sin validar (estado heredado SIN_VEREDICTO)» |

#### Vía (la propone el técnico, la valida el jefe) y decisión de KFC

| Clave | Término (singular / plural) | Título | Estado BD | Quién lo ve | Reemplaza |
|---|---|---|---|---|---|
| `VIA` | vía propuesta / vías propuestas | Vía | — | ADM JZ TEC | «comprar el repuesto, mandarlo a reparación, reclamar garantía o darlo de baja»; «Vía del equipo trabado» |
| `VIA_REPUESTO` | vía de repuesto / vías de repuesto | Repuesto | via:REPUESTO | ADM JZ TEC KFC | — |
| `VIA_REPARACION` | reparación en taller / reparaciones en taller | Reparación en taller | via:REPARACION | ADM JZ TEC | — |
| `VIA_GARANTIA` | garantía / garantías | Garantía | via:GARANTIA | ADM JZ TEC | — |
| `VIA_BAJA` | baja / bajas | Baja | via:BAJA | ADM JZ TEC | — |
| `DECISION_KFC` | decisión de KFC / decisiones de KFC | Decisión de KFC | — | ADM JZ TEC KFC GER | «veredicto de KFC»; «KFC decide» |
| `KFC_SIN_DECISION` | sin decisión de Grupo KFC todavía | Sin decisión de KFC | decision_kfc:PENDIENTE | ADM JZ TEC KFC | — |
| `KFC_ENVIA_REPUESTO` | envía el repuesto | Envía el repuesto | decision_kfc:REPUESTO_ENVIADO | ADM JZ TEC KFC | — |
| `KFC_A_TALLER` | a taller de INDUSTEC | A taller de INDUSTEC | decision_kfc:TALLER_INDUSTEC | ADM JZ TEC KFC | «Taller INDUSTEC» |
| `KFC_A_OTRO_PROVEEDOR` | a otro proveedor | A otro proveedor | decision_kfc:OTRO_PROVEEDOR | ADM JZ TEC KFC | «otro proveedor» |
| `KFC_DA_DE_BAJA` | da de baja el equipo | Da de baja el equipo | decision_kfc:BAJA | ADM JZ TEC KFC | — |

#### App del técnico y navegación

| Clave | Término (singular / plural) | Título | Estado BD | Quién lo ve | Reemplaza |
|---|---|---|---|---|---|
| `MIS_ORDENES` | mis órdenes | Mis órdenes | — | TEC JZ | «Bandeja» |
| `HISTORIAL` | historial | Historial | — | TEC JZ | «Atendidas (pestaña del técnico)»; «en la pestaña Atendidas» |
| `NOTIFICACION` | notificación / notificaciones | Notificaciones | — | ADM JZ TEC | «Avisos (pestaña del técnico)»; «Tienes N avisos nuevos»; «aviso interno»; «nuevo (chip de la notificación sin leer)» |
| `CONTINUIDAD` | continúa la orden / continúan la orden | Continúa la orden | — | ADM JZ TEC | «continúa el aviso N»; «queda cerrado con la orden X»; «Enlazado»; «Cruce de técnico»; «sin orden propia» |
| `OTRO_TRABAJO` | otro trabajo / otros trabajos | Otros trabajos | — | ADM JZ TEC KFC GER | — |
| `OTRO_TRABAJO_POR_DECIDIR` | fuera del área, por resolver | Fuera del área, por resolver | — | ADM JZ TEC | «con alerta de alcance»; «con alerta (alerta de alcance)»; «por confirmar (alerta de alcance)»; «fuera del área, por decidir» |
| `EQUIPO_PROPUESTO` | equipo nuevo por confirmar / equipos nuevos por confirmar | Equipos nuevos por confirmar | — | ADM JZ TEC | «propuesto (equipo nuevo, en la lista del formulario)»; «Propuestos por otros técnicos (pendientes de aprobar)»; «Propuestos por los técnicos» |
| `DOCUMENTO_POR_APROBAR` | por aprobar | Por aprobar | — | ADM JZ TEC | «en revisión (documento de Aprendizaje)» |

#### Novedades

| Clave | Término (singular / plural) | Título | Estado BD | Quién lo ve | Reemplaza |
|---|---|---|---|---|---|
| `NOVEDAD` | novedad / novedades | Novedades | — | ADM JZ TEC KFC | — |
| `NOVEDAD_REPORTADA` | reportada / reportadas | Reportadas | novedad:REPORTADA | ADM JZ TEC KFC | — |
| `NOVEDAD_EN_ESTUDIO` | en estudio | En estudio | novedad:EN_REVISION | ADM JZ TEC KFC | «en revisión (novedad)»; «La estoy revisando» |
| `NOVEDAD_CON_AVISO` | con aviso SAP | Con aviso SAP | novedad:DERIVADA_SAP | ADM JZ TEC KFC | «derivada»; «Con aviso en SAP»; «Novedades con aviso en SAP»; «Se le pidió el aviso a KFC» |
| `NOVEDAD_ASUMIDA` | la asume INDUSTEC / las asume INDUSTEC | La asume INDUSTEC | novedad:ASUMIDA_INDUSTEC | ADM JZ TEC KFC | «Lo asume INDUSTEC» |
| `NOVEDAD_DESCARTADA` | descartada / descartadas | Descartadas | novedad:DESCARTADA | ADM JZ TEC KFC | «No procede» |
| `NOVEDAD_RESUELTA` | resuelta / resueltas | Resueltas | novedad:RESUELTA | ADM JZ TEC KFC | «Ya se atendió» |
| `NOVEDAD_POR_DECIDIR` | por resolver | Por resolver | — | ADM JZ TEC KFC | «sin decidir»; «esperando decisión»; «por decidir» |
| `NOVEDAD_OTRA_AREA` | de otra área / de otras áreas | De otras áreas | — | ADM JZ TEC KFC | «No es de INDUSTEC (filtro de novedades)»; «No son de INDUSTEC»; «De otras áreas sin decidir» |

#### SAP y KFC

| Clave | Término (singular / plural) | Título | Estado BD | Quién lo ve | Reemplaza |
|---|---|---|---|---|---|
| `ESTATUS_SAP` | estatus SAP | Estatus SAP | — | ADM JZ TEC KFC GER | «abiertos en SAP»; «Abiertos lunes»; «INDUSTEC abiertos en SAP» |
| `ND_SIN_PROVEEDOR` | orden N/D (sin proveedor) / órdenes N/D (sin proveedor) | Órdenes N/D | — | ADM KFC GER | «ND de INDUSTEC (consola de la respuesta del miércoles)» |

#### Preventivo (base EJECUTADO · PENDIENTE · ATRASADO)

| Clave | Término (singular / plural) | Título | Estado BD | Quién lo ve | Reemplaza |
|---|---|---|---|---|---|
| `PREV_EJECUTADO` | ejecutado / ejecutados | Ejecutado | preventivo:CUMPLIDO | ADM JZ TEC KFC GER | «Cumplido»; «Cumplidos»; «cierre (ingreso preventivo)»; «Registrar el cierre (ingreso preventivo)» |
| `PREV_PENDIENTE` | pendiente / pendientes | Pendiente | preventivo:PLANIFICADO | ADM JZ TEC KFC GER | «Planificado»; «Planificados» |
| `PREV_POR_INICIAR` | pendiente, arranca en 3 días o menos / pendientes, arrancan en 3 días o menos | Pendiente · arranca en 3 días o menos | preventivo:PORINICIAR | ADM JZ TEC KFC | «Arranca ya»; «Arrancan ya»; «Por iniciar (≤ 3 días)»; «Arrancan en 3 días o menos»; «Sin kit y arrancan pronto»; «Sin kit, arrancan pronto» |
| `PREV_SIN_AGENDAR` | pendiente, sin agendar / pendientes, sin agendar | Pendiente · sin agendar | preventivo:SINAGENDAR | ADM JZ TEC KFC GER | «Sin agendar» |
| `PREV_EN_EJECUCION` | pendiente, en ejecución / pendientes, en ejecución | Pendiente · en ejecución | preventivo:ENCURSO | ADM JZ TEC KFC GER | «En curso (preventivo)»; «En marcha y por arrancar» |
| `ATRASADO` | atrasado / atrasados | Atrasado | preventivo:VENCIDO | ADM JZ TEC KFC GER | «Vencido (preventivo)»; «Vencidos (preventivo)»; «vencido hace N día(s) (compromiso de fecha)»; «Compromisos vencidos»; «compromiso vencido»; «con atraso»; «pasada (fecha comprometida SAP)» |
| `PREV_SIN_CIERRE` | atrasado, por marcar como ejecutado / atrasados, por marcar como ejecutados | Atrasado · por marcar como ejecutado | preventivo:SINCERRAR | ADM JZ TEC KFC | «Sin cerrar» |
| `PREV_CANCELADO` | cancelado del cronograma / cancelados del cronograma | Cancelado del cronograma | preventivo:CANCELADO | ADM JZ TEC | — |
| `PREV_REAGENDADO` | reagendado / reagendados | Reagendados | — | ADM JZ TEC KFC GER | — |
| `PREV_MOVIMIENTO` | movimiento del ingreso / movimientos del ingreso | Movimientos del ingreso | — | ADM JZ TEC | «Novedades (panel del cronograma preventivo)»; «Novedad CIERRE»; «Novedad AGENDA»; «Sin novedades registradas»; «Novedad registrada. (cronograma preventivo)» |

#### Semáforo del plan de zona

| Clave | Término (singular / plural) | Título | Estado BD | Quién lo ve | Reemplaza |
|---|---|---|---|---|---|
| `LE_TOCA_INDUSTEC` | le toca a INDUSTEC | Responsable: INDUSTEC | semaforo:AMARILLO | ADM JZ KFC | «Pendiente INDUSTEC»; «dentro de «Pendiente INDUSTEC»» |
| `LE_TOCA_KFC` | le toca a KFC | Responsable: KFC | semaforo:NARANJA | ADM JZ KFC | «Pendiente KFC» |
| `LE_TOCA_KFC_REPUESTO` | repuesto en seguimiento | Repuesto en seguimiento | semaforo:VERDE | ADM JZ KFC | «Pendiente repuestos SAP»; «Responsable: KFC (repuesto en SAP)»; «le toca a KFC: repuesto en SAP» |
| `EMERGENTE` | emergente (más de 7 días) / emergentes (más de 7 días) | Emergente | semaforo:ROJO | ADM JZ KFC | «Emergente / vencido» |

#### Zonas

| Clave | Término (singular / plural) | Título | Estado BD | Quién lo ve | Reemplaza |
|---|---|---|---|---|---|
| `ZONA_UIO` | zona UIO | ZONA UIO | zona:UIO | ADM JZ TEC KFC GER | — |
| `ZONA_LARB` | zona LARB | ZONA LARB | zona:LARB | ADM JZ TEC KFC GER | — |
| `ZONA_CNLJ` | zona Cuenca-Loja | ZONA CUENCA-LOJA | zona:CNLJ | ADM JZ TEC KFC GER | «CNLJ (rótulo en pantallas, PDF y correos)» |
| `ZONA_OTRA` | otra zona / otras zonas | OTRA ZONA | zona:OTRA | ADM JZ KFC GER | — |
| `SIN_ZONA` | sin zona | SIN ZONA | zona:(vacío) | ADM KFC GER | — |

---

## 5. Palabras reservadas

| Palabra | Significa SOLO | Deja de usarse para |
|---|---|---|
| **orden** | el trabajo que pide KFC, identificado por el aviso SAP | el documento de INDUSTEC (→ OT INDUSTEC); el número de SAP (→ aviso SAP; el de la orden de trabajo de SAP → n.º de orden SAP) |
| **aviso** | el número de aviso de SAP | la notificación interna (→ notificación) |
| **OT INDUSTEC** | el documento que emite el técnico, de evaluación o de cierre («OT» a secas solo como abreviatura) | — |
| **informe técnico** | en «a espera de informe técnico», la OT INDUSTEC de la visita | el documento aparte para baja, garantía o material (→ **informe técnico detallado**) |
| **abierta** | solo ÓRDENES ABIERTAS: con OT INDUSTEC de evaluación y sin la de cierre, más las que esperan repuesto | el universo completo (→ «total de órdenes abiertas»); SAP (→ «abierta en SAP») |
| **cerrada** | nunca sola: «cerrada en SAP», «cerrada sin atención», «cerrada por falta de OK» | lo que INDUSTEC terminó y falta cerrar en SAP (→ atendida) |
| **atendida** | estado ATENDIDO: INDUSTEC terminó y falta cerrarla en SAP | la novedad que ya quedó hecha (→ resuelta, «Ya se atendió» → «Resuelta») |
| **a espera de** | falta algo para seguir (informe técnico, repuesto) | «en espera», «esperando» |
| **en cola** | la OT INDUSTEC guardada en el celular, que sale sola con señal (`ENVIO_EN_COLA`); con señal la fila dice «enviando» | «en espera» de la cola de envío |
| **rechazada** | a secas, el documento de Aprendizaje (fuera de alcance) | la OT INDUSTEC que el servidor no aceptó (→ «rechazada al enviar», `ENVIO_RECHAZADA`) |
| **nuevo / nueva** | «órdenes nuevas en 7 días» (`ORDENES_NUEVAS_7D`); el código crudo NUEVO se muestra «sin asignar» | la notificación sin abrir (→ «sin leer», `NOTIFICACION`) |
| **de otra área** | la novedad que le toca a otra área de KFC (`NOVEDAD_OTRA_AREA`) | «no es de INDUSTEC», que se confunde con «no nos compete» de la orden |
| **pendiente** | sinónimo en prosa hacia Isabel y KFC; «PENDIENTE OK OP´S»; estado del preventivo | pestaña del técnico, módulo de repuestos, el registro de repuesto (→ solicitud: «Avanzar / Cancelar la solicitud», «Insistir por esta solicitud»), semáforo |
| **solicitud** | el registro que abre el técnico cuando el equipo no quedó operativo (tabla `pendientes`) | «pendiente» como sustantivo |
| **vencida** | solicitud con equipo deshabilitado que lleva más de 48 h sin validar | preventivo, compromiso de fecha y fecha comprometida en SAP (→ atrasado) |
| **atrasado** | pasó una fecha comprometida (ingreso preventivo, compromiso de fecha, fecha comprometida en SAP) | «pasada», «con atraso» |
| **deshabilitado / operativo** | estado del EQUIPO; puede calificar a la orden («orden con equipo deshabilitado») | parado, trabado, fuera de servicio, sigue operando |
| **validar** | el paso del jefe de zona sobre la solicitud | — |
| **decidir / decisión de KFC** | lo que decide KFC sobre una solicitud registrada en SAP | lo que resuelven la administración o el jefe de zona (→ «fuera del área, por resolver», «por resolver») |
| **resolver** | lo que hace la administración con una orden en revisión o fuera del área, y la administración o el jefe de zona con una novedad («por resolver»). Como verbo de un botón no nombra ningún concepto: el botón de una novedad también dice «Resolver» | el título de la resolución (→ «Resolución») |
| **cierre** | la OT INDUSTEC de cierre | el cierre de SAP (→ «cerrada en SAP»); el fin de una solicitud (→ «terminada»); el ingreso preventivo (→ «marcar como ejecutado») |
| **novedad** | lo que el técnico ve en el local (`NOVEDAD`) | lo que pasa con un ingreso preventivo (→ «movimiento del ingreso», `PREV_MOVIMIENTO`) |
| **veredicto** | **se retira** de todo texto visible | jefe (→ validar), KFC (→ decidir), administración (→ resolver) |
| **en revisión** | la orden que el jefe mandó a la administración | documentos (→ por aprobar), novedades (→ en estudio) |
| **regularizar** | explicar ante KFC una orden cerrada sin atención | la OT sin aviso (→ sin aviso SAP) |
| **resuelta** | solo la novedad | la orden (→ cerrada en SAP), la solicitud (→ terminada) |
| **asignada / sin asignar** | la orden tiene o no tiene técnico | repartir, por repartir, POR ASIGNAR |
| **ZONA CUENCA-LOJA** | rótulo de CNLJ en pantallas, PDF y correos | «ZONA C-L» (solo en el STATUS) |

---

## 6. Contratos que no cambian

El detalle de cada texto está en `contratos_externos` del JSON. La prueba de lista negra quita estos textos antes de buscar.

1. **PDF de la OT INDUSTEC** (`nucleo/plantilla_ot.php`): «ORDEN DE TRABAJO INDUSTEC», las secciones (DATOS GENERALES … ESTADO DE LA OT … FIRMA DEL ADMINISTRADOR), «ID-ORDEN-INDUSTEC», «ID-ORDEN-GRUPOKFC», «Técnico Asignado», «Sin aviso de SAP» y «Su requerimiento fue atendido a tiempo». Por qué: los lee `t1_7_extractor_pdf.py` y `t1_6_muestra_verificacion.py`, y es lo que recibe KFC (D-C). Los PDF ya emitidos no se regeneran.
2. **Correo de la OT** (`nucleo/Emision.php`): asunto «ORDEN DE TRABAJO INDUSTEC - OT-NNNN-LOCAL-AVISO-ZONA» y cuerpo con «ORDEN SAP:» (con el valor «sin aviso» cuando la OT nace sin aviso), «Estado de OT: Abierta/Cerrada» y «Estado de Equipo: Operativo/Deshabilitado». Por qué: los lee `t2_11_informes_ot.py`.
3. **Botones del formulario** «Abierta/Cerrada» y «Operativo/Deshabilitado», y el aria-label «Atendido a tiempo» de la pregunta de satisfacción. Por qué: D-C, I-8 y `verificar_formulario.mjs`. Fuera del formulario, las pantallas usan `OT_EVALUACION`/`OT_CIERRE` y `EQUIPO_OPERATIVO`/`EQUIPO_DESHABILITADO`.
4. **Plantilla STATUS de Isabel**: hojas ORDENES y RESUMEN, los `COLS` de `t2_27_status_semanal.py`, «ZONA C-L», «OPERATIVO/DESHABILITADO», «TOTAL ORDENES», «Ordenes Abiertas/Cerradas», el «Cerradas» con que la hoja LEEME explica RESUMEN C15:C17, y el «órdenes cerradas» del correo del martes, que la refleja.
5. **Asunto del hilo del martes** «ORDENES SEMANALES PENDIENTES _ INDUSTEC» y el adjunto «STATUS_PENDIENTES_SEMANA N MES.xlsx».
6. **Libro y asuntos de KFC**:
   - asuntos «REPORTE 2026 SEMANA NN - MANTENIMIENTO CORRECTIVO», con su errata «MANTENIMEINTO», y «… ACTUALIZADO»;
   - hojas FILTRO, TD, #O_ND y ANÁLISIS;
   - columnas ESTATUS A/B/C y Estatus 2;
   - valores ABIERTO/TRATAMIENTO/CERRADO, SIN GESTIÓN/INFORME TÉCNICO/FALLA OPERATIVA, y los códigos MEAB, METR, MECE, etc.
7. **ESTATUS IND de Isabel** (miércoles): CERRADO, INFORME TECNICO, PENDIENTE OK OP´S, IMPORTACION, DESPACHO BOD, GESTION …, SIN CORREO y COTIZACION PENDIENTE; y las hojas que la respuesta le agrega al libro de KFC: RESUMEN, RESUMEN IND y **ND INDUSTEC** (las nombra la semilla 020 y las recibe KFC).
8. **Notificación SIR** «Se ha creado / Se ha eliminado la orden de trabajo».
9. **Semilla `020_automatizacion.sql`**: solo cambia con una migración.
10. **Plan de seguimiento de Isabel**: sus columnas y valores (# OT, #OT INDUSTEC EVALUACION/CIERRE, ESTADO ABIERTA/CERRADA, SIN ASIGNAR, NINGUNO).
11. **Claves de la base y clases CSS**: NUEVO…CERRADO_SIN_ATENCION, `zona-card zona-uio|larb|cnlj` y `est-*`. No se renombran.

## 7. Fuera de alcance, con motivo

Detalle en `fuera_de_alcance` del JSON:
- el KPI «se concluye en una visita»;
- los tipos de novedad («Equipo — necesita correctivo» / «Un equipo que va a fallar»);
- los demás estados del documento de Aprendizaje («aprobada», «rechazada», «retirada») y del equipo propuesto («aprobado», «rechazado», «ya existía» / «Marcado como ya existente», y «decidido» de la columna Decisión);
- la opción «sin alerta» del filtro de alerta de alcance, que es la negación del filtro (las otras dos, «con alerta» y «por confirmar», van a `OTRO_TRABAJO_POR_DECIDIR`);
- el estado del kit del preventivo, incluido el distintivo «kit listo» de `cronograma.js` (confirmado, entregado o disponible);
- los tramos de antigüedad;
- el título «En qué estado están»;
- los mensajes de validación del formulario y de la búsqueda de avisos.

Ninguno nombra un estado de la orden. Se tocan cuando se trabaje su pantalla.

**Scripts de la estación en alcance:** los que producen algo que lee una persona:
- `t2_27_status_semanal.py`, `t2_27_respuesta_kfc.py`, `t2_27_tablero_gerencia.py`, `t2_27_presentacion_gestion.py` y `t2_27_kits_preventivo.py`;
- `t2_28_correos.py`.

Los scripts de consola y de migración histórica (`t1_*`, `t2_historico_*`, `inspectorbot*`) escriben logs técnicos y quedan fuera.

---

## 8. Especificación de la TARJETA POR ZONA

### 8.1 Una sola función y su universo

**`Casos::grupoOrden()`** clasifica cada orden y **`Casos::tarjetasPorZona()`** cuenta. Las usan el panel (tarjetas, línea del total general, cuadro «TOTAL DE ÓRDENES ABIERTAS» y las tareas de «Lo que te toca ahora») y el buzón (`casos.php?grupo=`, con `Casos::clasificar()` y `Casos::enGrupo()`). **Nadie más cuenta estas cifras por su cuenta**: es la lección de `sinAsignar`, donde una cifra con dos cálculos terminó diciendo dos números. Antes el panel contaba en un bucle propio y `vencidos48DeZona()` iba a la base otra vez por cada zona; eso se retiró.

**Universo U: solo el catálogo de 90 días del buzón.** Son los `$casos` que ya pasaron por `Casos::enAlcance()`, el mismo arreglo que lista `casos.php`. Así **cada cifra es igual a las filas de su enlace** (lo afirma `prueba_panel_zona.php`, zona por zona y grupo por grupo).
- **No se suma `Casos::fueraDeCatalogo()`**: no distingue una orden que salió de la ventana de 90 días de una que KFC anuló (el servidor todavía no recibe esa lista), y el enlace no la mostraría. Por eso la sublínea «con más de 90 días» ya no existe y `FUERA_CATALOGO` quedó reservado (§12).
- De U, entran en el TOTAL las órdenes con estado en `casos_gestion` ∈ {NUEVO, ASIGNADO, EN_REVISION, ESPERA_REPUESTO} (`Casos::ABIERTAS_INDUSTEC`). Un aviso del catálogo sin fila en `casos_gestion` cuenta como NUEVO.
- Quedan fuera del TOTAL ATENDIDO, RESUELTO, NO_COMPETE y CERRADO_SIN_ATENCION. ATENDIDO y CERRADO_SIN_ATENCION sin regularizar van al pie.
- Se cuenta **todo el buzón**: Mant. Correctivo, Solicitud Baja de Equipo y Mant. Constructivo.
- El jefe de zona recibe de `enAlcance()` solo su zona; su tarjeta cuenta exactamente lo que ve en su buzón.

**Zona de la orden.** Es la que trae la orden en el buzón: la de `casos_gestion.zona` si la administración la derivó (`enAlcance()` la reescribe una sola vez, para que el filtro, la tarjeta y el enlace digan lo mismo); si no, la del catálogo, que la estación resuelve por el local contra el maestro. Si queda vacía, la orden es **«sin zona»** (`casos.php?zona=SIN`): el local no calza con el maestro. No se busca la zona en la solicitud, en `ot_capturadas` ni en el nombre del archivo: la orden sin zona se corrige en el maestro, no se adivina.

**Continuidad.** Cada cadena `continua_de` cuenta **una sola vez** (`Casos::clasificar()`):
- La representa su orden **más reciente** (por `fecha_creacion`, y a igual fecha por aviso) que siga dentro del TOTAL. Las demás de la cadena quedan fuera del total y de las filas; en el buzón `?grupo=` tampoco salen.
- Para decidir si la cadena «tiene OT» y qué dice del equipo, se miran **todos** sus avisos (`informesPorAviso()` arma las cadenas una sola vez por carga, con `Casos::raiz()`).
- El pie (atendidas, en revisión, sin regularizar) se cuenta por estado, orden por orden, igual que la lista de su enlace (`casos.php?est=`).

**¿Tiene OT INDUSTEC emitida?** Es `Casos::tieneOT(aviso)`, sobre el índice de `informesPorAviso()`: verdadero si algún aviso de la cadena cumple al menos una de estas condiciones:
- tiene al menos una entrada en `atenciones.json → ots[]`, con cualquier `estado_ot`;
- o tiene una fila en `ot_capturadas` en EMITIDA, ENVIADA o PROCESADA con `emitida_en` no vacío;
- o tiene una fila en `ot_archivo`;
- o su `casos_gestion.ot_cierre` no está vacío.

Una captura **NUMERADA o FALLIDA no cuenta**: no salió el PDF ni el correo. Si el aviso solo tiene capturas de ese tipo, la orden va a la fila 2 y cuenta en la sublínea «con OT INDUSTEC no emitida». `informesPorAviso()` es la variante de `Casos::documentos()` sin `Emision::existePdf()`, para no leer disco por cada orden.

### 8.2 `grupoOrden()`: precedencia exacta

```
para cada orden o en U (una por cadena):
  si o.estado == 'ESPERA_REPUESTO'      -> 'ABIERTA'          # D-A: siempre, aunque no tenga OT
  si tieneOT(cadena(o))                 -> 'ABIERTA'
  si no                                 -> 'ESPERA_INFORME'
```

Toda orden de U cae en exactamente un grupo. Una orden con OT de cierre cuyo estado todavía no pasó a ATENDIDO (por ejemplo, EN_REVISION con `ot_cierre`) se cuenta **por su estado**: sigue en el TOTAL, como ÓRDENES ABIERTAS, hasta que la reconciliación la mueva. Las definiciones se escriben por estado porque es lo que se calcula.

### 8.3 Filas, en orden

| # | Rótulo (clave) | Fórmula | Sublíneas (no se suman entre sí) | Enlace |
|---|---|---|---|---|
| 1 | **ÓRDENES ABIERTAS** (`ABIERTA`) | órdenes del TOTAL de la zona con `grupoOrden = ABIERTA` | «de ellas, a espera de repuesto» = fila 1 ∧ ESPERA_REPUESTO · «de ellas, sin asignar (OT INDUSTEC con firma no reconocida)» = fila 1 ∧ sin técnico, solo si > 0 | `casos.php?zona=X&grupo=abiertas`; a espera de repuesto → `…&grupo=abiertas&est=ESPERA_REPUESTO`; sin asignar → `asignacion.php?zona=X#sin-asignar-X` |
| 2 | **ÓRDENES A ESPERA DE INFORME TÉCNICO** (`ESPERA_INFORME`) | órdenes del TOTAL de la zona con `grupoOrden = ESPERA_INFORME` | «sin asignar» = fila 2 ∧ sin técnico · «asignadas hace 3+ días» = fila 2 ∧ ASIGNADO ∧ `asignado_en` hace `Casos::DIAS_ASIGNADA` (3) días o más · «fuera del área, por decidir» = fila 2 ∧ `Casos::otroTrabajoPorDecidir`, solo si > 0 · «con OT INDUSTEC no emitida» = fila 2 ∧ alguna captura NUMERADA/FALLIDA en la cadena, solo si > 0 (sin enlace) | `casos.php?zona=X&grupo=espera_informe`; sin asignar → `asignacion.php?zona=X#sin-asignar-X`; 3+ días → `…&grupo=espera_informe&dias_asignado=3`; fuera del área → `…&grupo=espera_informe&otro=por_decidir` |
| 3 | **EQUIPOS DESHABILITADOS** (`EQUIPO_DESHABILITADO`) | órdenes del TOTAL (una por cadena) cuya **evidencia más reciente** del equipo es Deshabilitado (§8.4). **No se suma.** | «de ellas, vencidas (48 h)» = fila 3 ∧ alguna solicitud de la cadena en trámite, con `deshabilitado = 1` y más de 48 h sin validar, con la misma condición que `Pendientes::cumplimiento48()` y el grupo `vencidos` de `Pendientes::lista()` (la calcula MySQL con su propio `NOW()`) · «con equipo operativo» · «sin dato del equipo», solo si > 0. Se cumple: deshabilitados + operativos + sin dato = TOTAL | `casos.php?zona=X&grupo=deshabilitados`; vencidas → `pendientes.php?g=vencidos&zona=X`; operativos y sin dato, sin enlace |
| 4 | **TOTAL DE ÓRDENES ABIERTAS** (`TOTAL_ABIERTAS`), con una línea arriba | **fila 1 + fila 2** (partición exacta) | ninguna | `casos.php?zona=X&grupo=total` |

«Sin técnico» es NUEVO o EN_REVISION sin `asignado_a`: una orden que el jefe mandó a revisión antes de asignarla sigue sin técnico. Sin técnico y con OT INDUSTEC emitida es una OT cuya firma no cruzó con nadie del padrón: por eso cae en la fila 1 con su propia sublínea. La tarea «N órdenes sin asignar» de «Lo que te toca ahora» suma las dos sublíneas y no depende de ninguna fuente de OT.

Toda sublínea sale **de su fila** (nunca pasa de ella), y sin la cifra de la fila no se pintan sus sublíneas: una sublínea sin su fila no se puede leer como «de ellas». Los enlaces de «sin zona» usan `casos.php?zona=SIN`.

**Pie de la tarjeta** (la tarea diaria de Isabel no desaparece de la vista por zona):

| Rótulo | Fórmula | ¿En el total? | Enlace |
|---|---|---|---|
| «Fuera del total: atendidas, por cerrar en SAP» (`ATENDIDA`) | estado ATENDIDO de la zona, en el catálogo de 90 días | **No** | `casos.php?zona=X&est=ATENDIDO` |
| «Dentro del total: en revisión, por resolver» (`EN_REVISION`) | estado EN_REVISION de la zona (ya contadas en la fila 1 o en la 2) | Sí, ya está dentro | `casos.php?zona=X&est=EN_REVISION` |
| «Fuera del total: cerradas sin atención, sin regularizar ante KFC» (`SIN_REGULARIZAR`) | CERRADO_SIN_ATENCION ∧ `regularizado_en` vacío, de la zona | **No** | `casos.php?zona=X&est=CERRADO_SIN_ATENCION` (el filtro compara `Ui::estadoVista()`: las ya regularizadas son REGULARIZADO y no salen) |

**Ayuda de la tarjeta** (`tarjeta_zona.ayuda_comun`, un ícono ⓘ junto al encabezado) y de cada fila (`Vocabulario::ayuda(clave)`): *«Cifras de B.IA, no de SAP: el servidor no conoce el estado SAP y el correo trae más o menos la mitad de las órdenes abiertas en SAP (no avisa reaperturas ni cierres); se cuenta todo el buzón: correctivos, bajas y constructivos.»*

### 8.4 Evidencia más reciente del equipo (fila 3)

La clave es el **aviso**, que es común a las tres fuentes. Por eso se cuenta una por orden, como el `COUNTIFS(zona; ESTATUS DEL EQUIPO = "DESHABILITADO")` de Isabel. Para cada orden del TOTAL se toman las evidencias de su cadena:

| Fuente | Valor | Fecha |
|---|---|---|
| `atenciones.json → ots[]` | `estado_equipo` (Operativo / Deshabilitado / vacío) | `fecha` de la OT |
| `ot_capturadas` emitidas (EMITIDA, ENVIADA o PROCESADA, con `emitida_en`) | `carga $.equipos[*].estado`: Deshabilitado si algún equipo lo está; Operativo solo si **todos** traen Operativo; si alguno viene sin estado y ninguno está deshabilitado, la OT no dice nada del equipo | `emitida_en` |
| `pendientes` en trámite (`Pendientes::ABIERTOS`) con `deshabilitado = 1` | Deshabilitado | la del reloj de 48 h: `plazo_desde` si se reinició, si no `abierto_en` (la última vez que alguien declaró el equipo deshabilitado) |

Reglas:
- **Manda la evidencia con fecha más reciente**, mirando todos los avisos de la cadena. El correo trae solo el día y la app la hora: si una de las dos fechas es solo día, se comparan por día. Si hay empate, gana Deshabilitado, para no esconder la alarma.
- Una solicitud con `deshabilitado = 0` no aporta nada: **no** es evidencia de Operativo.
- Si ninguna fuente trae valor, la orden cuenta como `SIN_DATO_EQUIPO`, **nunca como Operativo**.
- Un informe posterior que dice Operativo saca la orden de la fila 3, aunque la solicitud siga abierta (el flag `deshabilitado` no baja nunca: GREATEST).
- En ese caso la solicitud vencida **no se pierde**: sigue en el bloque «Equipos deshabilitados · el plazo de 48 horas» y en «Lo que te toca ahora», que cuentan con `Pendientes::cumplimiento48()`.

### 8.5 Línea del total general, OTRA y «sin zona»

- **Dónde va la línea del total general.** Arriba, entre el título «Por zona» y las tarjetas, **solo para la administración**. Refleja la fila 5 del RESUMEN de Isabel:
  `TOTAL GENERAL DE ÓRDENES ABIERTAS (UIO + LARB + CUENCA-LOJA): N · ZONA UIO a · ZONA LARB b · ZONA CUENCA-LOJA c · EQUIPOS DESHABILITADOS d · Operativos o`.
  N = a + b + c: solo las tres zonas (`tarjetasPorZona()['tres_zonas']`). Deshabilitados y operativos también son de las tres zonas, y dicen «no disponible» si falta su fuente.
- **OTRA ZONA** tiene su propia tarjeta, con las mismas filas y el mismo pie, solo si tiene algo: una orden en el TOTAL, atendida, en revisión o sin regularizar (`Casos::zonasVisibles()`, ASG-21). Las tres zonas llevan tarjeta siempre, aunque estén en 0. OTRA no entra en el total general.
- **«sin zona»** no lleva tarjeta. Si OTRA o «sin zona» tienen órdenes, debajo de la línea general sale otra: «Fuera de las tres zonas: OTRA ZONA x · SIN ZONA y (el local no calza con el maestro) → TOTAL DE ÓRDENES ABIERTAS del buzón: N», con enlaces a `casos.php?zona=OTRA&grupo=total` y `casos.php?zona=SIN&grupo=total`.
- El cuadro «TOTAL DE ÓRDENES ABIERTAS» de «Cómo va el buzón» (antes «Siguen abiertos») sale de la **misma función**. Vale total general + OTRA + sin zona; su pie dice «De N órdenes en la ventana de 90 días» y, si OTRA o sin zona son mayores que 0, «· incluye x de OTRA ZONA y y sin zona». Se cumple: **Σ tarjetas (incluida OTRA) + sin zona = cuadro TOTAL**. El cuadro «A espera de repuesto» de al lado es la suma de las sublíneas «de ellas, a espera de repuesto».
- Debajo de las tarjetas va la ayuda común y la frase «Cuenta las órdenes de los últimos 90 días del buzón; no es la cifra de SAP.».
- **Jefe de zona:** ve una sola tarjeta, la de su zona, con los mismos rótulos, las mismas sublíneas y el mismo pie. No ve la línea del total general, la de fuera de las tres zonas ni la tarjeta OTRA. Su cuadro TOTAL es igual a su tarjeta.

### 8.6 Cuando falta un dato (I-7): «no disponible» por fuente

`informesPorAviso()` dice qué fuente respondió. Una fuente que no responde llega como `null`, no como lista vacía, porque «no sé» y «no hay» se pintan distinto. Una cifra que no se puede calcular es `null` y se pinta **«no disponible»**, **nunca 0**. El TOTAL y el pie siempre son números, porque dependen solo del estado de cada orden.

| Fuente | Si no responde |
|---|---|
| `atenciones.json` (las OT INDUSTEC leídas del correo) | Filas 1 y 2 y sus sublíneas, y la fila 3 y las suyas: «no disponible». |
| `ot_capturadas` (migración 008, las OT INDUSTEC emitidas desde la app) | Filas 1 y 2 y la fila 3, con sus sublíneas: «no disponible». |
| `ot_archivo` (migración 009, el archivo de OT INDUSTEC) | Filas 1 y 2 y sus sublíneas: «no disponible». La fila 3 sí se calcula. |
| `pendientes` (migración 007, las solicitudes de repuesto; `Pendientes::disponible()` falso) | Fila 3 y «vencidas (48 h)»: «no disponible». Las filas 1 y 2 sí se calculan. No se llama a `cumplimiento48()`, que sin la 007 devuelve ceros. |
| `casos_sap.json` (el catálogo) | Lo que ya hacía el panel: aviso de que no hay catálogo, sin cifras. |

Es decir: las filas 1 y 2 necesitan las tres fuentes de OT (`ot_disponible`), porque sin cualquiera de ellas una orden con su OT se contaría a espera de informe técnico. La fila 3 necesita correo, app y solicitudes (`equipo_disponible`), porque sin una de ellas «la evidencia más reciente» podría ser otra. La línea del total general no inventa: si una zona no tiene la cifra, sus deshabilitados y operativos también dicen «no disponible».

Debajo de las tarjetas, cada «no disponible» lleva **su motivo con el nombre de la fuente que falta** (por ejemplo, «ÓRDENES ABIERTAS y ÓRDENES A ESPERA DE INFORME TÉCNICO: no disponible, porque no se pudo leer el archivo de OT INDUSTEC (migración 009)»). Si `atenciones.json` tiene más de 24 h, se avisa: «Las OT INDUSTEC leídas del correo son de hace N h: las que llegaron después todavía no cuentan».

En «Lo que te toca ahora», la tarea «asignadas hace 3+ días» usa la sublínea de la fila 2. Sin fuentes de OT, esa sublínea no se puede calcular, así que la tarea cuenta por estado y fecha de asignación y lo dice.

Una orden sin evidencia del equipo cuenta en «sin dato del equipo», nunca en operativos.

### 8.7 Qué pasa con cada fila vieja

| Fila de hoy | Destino | ¿Sigue en «Lo que te toca ahora»? |
|---|---|---|
| N sin repartir | Sublínea «sin asignar» de la fila 2. Si alguna orden sin asignar ya tiene OT con firma no reconocida, aparece en la fila 1. | Sí: «N órdenes sin asignar» → Asignar |
| N vencidos 48 h | Sublínea «de ellas, vencidas (48 h)» de la fila 3. El enlace ahora filtra por zona (`pendientes.php` lee `zona`). | Sí: «N solicitudes vencidas (más de 48 h sin validar)» → Validar |
| N asignados 3+ días sin informe | Sublínea «asignadas hace 3+ días» de la fila 2. Ahora sí excluye las que ya tienen OT. | Sí: «N asignadas hace 3+ días, a espera de informe técnico» |
| N atendidos por cerrar | Pie: «Fuera del total: atendidas, por cerrar en SAP» | Sí: «N atendidas, por cerrar en SAP» |
| N en revisión | Contadas en la fila 1 o en la 2, y además en el pie: «Dentro del total: en revisión, por resolver» | Sí: «N en revisión, por resolver» |
| N sin regularizar | Pie: «Fuera del total: cerradas sin atención, sin regularizar ante KFC» | Sí, igual |

Otras alarmas de «Lo que te toca ahora» que no estaban en la tarjeta y se quedan donde están, con el término nuevo:
- «N por validar, a tiempo»;
- «N validadas, por registrar en SAP»;
- «N fuera del área, por resolver»;
- las candidatas a cerrar sin atención: «N sin OT INDUSTEC hace más de 7 días».

Qué le cambia a Isabel en las cifras que ya conoce: §8.9.

### 8.8 Resto de Inicio, buzón y técnico (textos que salen del diccionario)

- **«Lo que te toca ahora»**: los textos de la tabla anterior. «Decidir ahora» → «Validar».
- **«Equipos deshabilitados · el plazo de 48 horas»**:
  - «Vencidos ahora» → «Vencidas (48 h)»;
  - «Con el reloj corriendo» → «Por validar, a tiempo»;
  - «Decididos a tiempo / tarde» → «Validadas a tiempo / tarde».
  - Las mismas etiquetas salen en `Reportes::calcular()['d48']` y en el Excel, PDF y PPT a KFC.
- **«Cómo va el buzón»**:
  - «Siguen abiertos» → «TOTAL DE ÓRDENES ABIERTAS». **Cambia la cifra**: ya no incluye ATENDIDO. Hay que actualizar `verificar_cifras.py`.
  - «Esperando un equipo» → «A espera de repuesto».
  - «Llegaron en los últimos 7 días» → «Órdenes nuevas en 7 días».
  - «Comprometidos para hoy» → «Con fecha SAP hoy».
- **Buzón** (`casos.php`):
  - el título pasa a «Órdenes»;
  - la columna y el filtro «Atención» pasan a «OT INDUSTEC: sin OT / con OT de evaluación / con OT de cierre», que ahora también mira `ot_capturadas`;
  - se agrega el filtro `grupo=abiertas|espera_informe|deshabilitados|total`, que usa `grupoOrden()` sobre el mismo catálogo de 90 días que la tarjeta (sin `fueraDeCatalogo()`), para que la cifra del panel sea igual a las filas del enlace (hecho en la ola 1);
  - el filtro «Alerta» (con alerta / por confirmar / sin alerta) pasa a «Fuera del área, por resolver» sí / no;
  - la marca «pasada» junto a la fecha comprometida en SAP pasa a `ATRASADO`;
  - en la lista de OT de cada orden, «cierre» pasa a «OT INDUSTEC de cierre», y el chip «informe sin técnico reconocido» pasa a «OT INDUSTEC de cierre con firma no reconocida»;
  - se corrige `casos.php:136`.
- **Repuestos y equipos** (`pendientes.php`): los pasos «Solicitado · Validado · En SAP · KFC decidió · Resuelto» salen de `POR_VALIDAR`, `VALIDADA`, `PENDIENTE_OK_OPS`, `DECISION_KFC` y `SOLICITUD_TERMINADA`. «Cerrados esta semana» pasa a «Terminadas esta semana» y «Nada esperando a Grupo KFC» a «Ninguna solicitud pendiente OK de OP´S». La solicitud en estado TALLER_INDUSTEC u OTRO_PROVEEDOR se rotula «en taller de INDUSTEC» / «con otro proveedor»: «a taller de INDUSTEC» / «a otro proveedor» queda para la decisión de KFC.
- **Cronograma preventivo**: el panel «Novedades» del ingreso pasa a «Movimientos del ingreso» (`PREV_MOVIMIENTO`), y «Novedad CIERRE / AGENDA» a «Cierre / Agenda» dentro de ese panel. Los filtros usan los términos de `PREV_*`; por ejemplo, «Arrancan en 3 días o menos» pasa a «Pendiente · arranca en 3 días o menos».
- **Asignación**:
  - «Por repartir» → «Sin asignar». El ancla pasa a `#sin-asignar-X`, y cambian a la vez `asignacion.php:241`, el «volver» de `asignacion.php:306` y la regex de `casos.php:395`.
  - «En manos del equipo» / «abiertos» → «Asignadas».
  - «Sin nada abierto» → «Sin órdenes asignadas».
  - «Atendidos» → «Atendidas, por cerrar en SAP».
- **Técnico**:
  - barra: Mis órdenes · Historial · Emitir · Repuestos · Preventivos;
  - pestañas: Asignadas · A espera de repuesto · Notificaciones;
  - «N órdenes abiertas» → «N órdenes asignadas»;
  - botón «Emitir OT INDUSTEC»;
  - «No pude concluir: el equipo quedó trabado» → «El equipo no quedó operativo: pedir repuesto»;
  - casilla «El equipo quedó deshabilitado»;
  - `catalogos.php:95` manda la clave del estado, no «POR ASIGNAR».

### 8.9 Qué cambia en las cifras que Isabel ya conoce

Para decírselo **antes** de que compare la tarjeta con su STATUS del martes o con el libro de KFC. Ninguna de estas diferencias es un error de B.IA: cada una sale de una decisión o de un límite conocido.

1. **El TOTAL ya no incluye las atendidas.** El cuadro de antes («Siguen abiertos») contaba también ATENDIDO. Ahora el TOTAL DE ÓRDENES ABIERTAS es solo lo que INDUSTEC aún no termina (sin asignar, asignadas, en revisión y a espera de repuesto). Las atendidas, por cerrar en SAP, van al pie, «Fuera del total». Por eso el TOTAL baja exactamente en el número de atendidas de la zona.
2. **«asignadas hace 3+ días» ya no cuenta las que tienen OT INDUSTEC de evaluación.** Antes, «N asignados 3+ días sin informe» contaba por estado y fecha de asignación, aunque el técnico ya hubiera emitido su OT. Ahora es una sublínea de ÓRDENES A ESPERA DE INFORME TÉCNICO: solo cuenta órdenes sin ninguna OT INDUSTEC emitida. Las que ya tienen la de evaluación están en ÓRDENES ABIERTAS. La cifra baja, y lo que queda es lo que de verdad falta (solo si falta una fuente de OT se vuelve a contar por estado, y la tarea lo dice).
3. **La fila 1 sale más baja que la de su STATUS**, y el TOTAL más bajo que el total de KFC. La tarjeta cuenta el buzón de B.IA y el correo de SAP trae más o menos **la mitad** de las órdenes que SAP tiene abiertas (cotejo del 10 de septiembre de 2026: SAP 62, buzón 31). SAP no avisa por correo las reaperturas ni los cierres, y el servidor no recibe todavía el export del lunes (D-D). Además solo entra el catálogo de 90 días: una orden más vieja que siga abierta en SAP no está. La ayuda ⓘ de cada tarjeta lo dice.
4. **Su RESUMEN POR ZONA tenía rangos fijos.** Las fórmulas del RESUMEN del 22 de septiembre de 2026 solo contaban las filas 2 a 34 de la hoja ORDENES. Dejaron fuera 12 órdenes de C-L: salía 9 cuando eran 21. La tarjeta cuenta todas las órdenes, sin rango. Si su hoja y la tarjeta difieren en una zona, conviene revisar primero el rango de su fórmula.
5. **Un trabajo con varios avisos cuenta una sola vez.** Si KFC abrió un aviso nuevo para el mismo trabajo y se enlazó como continuidad, la tarjeta cuenta la cadena una vez (su aviso más reciente).
6. **EQUIPOS DESHABILITADOS** sigue contando una vez por orden, como su `COUNTIFS`, pero con la **evidencia más reciente** del equipo: si una OT INDUSTEC posterior dice Operativo, la orden sale de la fila aunque la solicitud siga abierta. Una orden que no dice nada del equipo va a «sin dato del equipo», nunca a operativos.

---

## 9. Plan por etapas y criterios de aceptación binarios

Convención de la skill `industec-invariantes` §5: corte vertical, entre 2 y 3 criterios por subtarea, cada uno con su comando exacto y con la salida pegada al cerrar.

Entorno (Git Bash), desde `desarrollo/sistema_ots/app`:

```
export PATH="/d/SOFTWARE/PHP83:$PATH"; export PHP_BIN=D:/SOFTWARE/PHP83/php.exe; export PYTHONUTF8=1
```

**Línea base (24-sep-2026)**, que ninguna etapa puede empeorar:

| Prueba | Resultado |
|---|---|
| `prueba_48h.php` | 120·0 |
| `prueba_continuidad.php` | 42·0 |
| `prueba_destinatarios.php` | 22·0 |
| `prueba_despacho.php` | 20·0 |
| `prueba_casos_prueba.php` | 7·0 |
| `validacion_test.php` | 37/37 |
| `prueba_contratos.mjs` | 57·0 |
| `prueba_graficos.mjs` | 62·0 |
| `prueba_barra_tecnico.mjs` | OK |
| `prueba_vocabulario.php` | 148·0 (desde la ola 1) |
| `prueba_panel_zona.php` | 70·0 (desde la ola 1) |
| `php -l` | 67/67 |

`prueba_offline.mjs` ya fallaba antes: es conocido y no cuenta como regresión. Las pruebas que hoy afirman textos (`prueba_48h.php:129-178`, `verificar_cifras.py`, `verificar_bandeja.py`, `verificar_reportes.py`, `capturar_pantallas.mjs`, las hojas del piloto) se actualizan **en la misma etapa** que cambia el texto. A partir de ahí afirman `Vocabulario::t('…')` y no el literal.

| Etapa | Qué entrega (se entrega sola) | Criterio binario | Comando | Esperado |
|---|---|---|---|---|
| **E1** Diccionario en el código, sin cambiar textos visibles | `nucleo/Vocabulario.php`; `Ui::ESTADOS`, `Pendientes::ESTADOS` / `VIAS`, `Novedades::ESTADOS` construidos desde el JSON; `pruebas/prueba_vocabulario.php` | El JSON es válido y completo | `python -c "import json;json.load(open('publico/vocabulario.json',encoding='utf-8'))" && php pruebas/prueba_vocabulario.php` | sin error; última línea `N·0` |
| | | Una clave inexistente no inventa texto | `php -r "require 'publico/nucleo/Vocabulario.php'; try { Vocabulario::t('NO_EXISTE'); echo 'MAL'; } catch (InvalidArgumentException \$e) { echo 'OK'; }"` | `OK` |
| | | Sin regresión | las 10 pruebas de la línea base | los mismos números |
| **E2** Tarjeta por zona y clasificador | `Casos::grupoOrden()`, `informesPorAviso()`, `equipoDeshabilitado()`; panel.php (tarjeta, pie, total general, jefe de zona); `casos.php?grupo=`; `pendientes.php` lee `zona`; `pruebas/prueba_panel_zona.php` sobre una base **sintética** (ningún dato de la operación) | Los 20 casos de §9.1 | `php pruebas/prueba_panel_zona.php` | `20·0` o más, con 0 fallas (hecho: 70·0) |
| | | Sintaxis | `for f in publico/panel.php publico/casos.php publico/pendientes.php publico/nucleo/Casos.php; do php -l "$f"; done` | `No syntax errors` ×4 |
| | | En el sitio de pruebas: tarjetas, total igual al buzón, jefe con una tarjeta (lo corre Andrés; aquí no se toca el servidor) | `python pruebas/servidor/verificar_http.py` | 3 `zona-card` para ADMIN; 1 `zona-card zona-uio` para `jefe_prueba_uio`; cifra del panel = filas del enlace |
| **E3** Oficina: Inicio, buzón, Asignación, Repuestos, Novedades, bitácora, usuarios, documentos | Literales → `Vocabulario::t()`; mapa de acción → etiqueta en bitacora.php | Ningún término de la lista negra en esas pantallas | `php pruebas/prueba_vocabulario.php --solo=oficina` | `lista negra: 0` |
| | | Sin regresión | línea base + `prueba_panel_zona.php` | los mismos números |
| **E4** App del técnico | `UI.T` en ui.js con el respaldo embebido; app.js, cola.js, offline.js, mis.php, index.html, guia.js, cronograma.js; `sw.js` con `vocabulario.json` en PRECARGA y la versión de caché subida | PHP y JS dicen lo mismo | `node pruebas/prueba_vocabulario_js.mjs` | `OK`: `UI.T(k,1)`, `UI.T(k,2)`, `.titulo`, `.ayuda` iguales a PHP para las 97 claves; la versión del respaldo embebido es igual a la del JSON |
| | | La barra sigue siendo una sola | `node pruebas/prueba_barra_tecnico.mjs` | `OK` |
| | | Se precarga sin señal | `grep -c "'vocabulario.json'" publico/sw.js` | `1` (y la constante de versión de caché cambió) |
| **E5** Reportes a KFC (Excel, PDF, PPT) | Reportes.php (`d48`, «Con la orden abierta» → `grupoOrden`, `estadoVista` en 113/167, preventivo con `PREV_SIN_CIERRE`); reporte_exportar.php; reporte_pdf.php (`$SEM_T` sale del diccionario); la hoja «Casos abiertos» pasa a «Total de órdenes abiertas» | Lista negra en 0 | `php pruebas/prueba_vocabulario.php --solo=reportes` | `lista negra: 0` |
| | | El contrato del PDF y del correo sigue igual | `grep -c "ORDEN DE TRABAJO INDUSTEC" publico/nucleo/plantilla_ot.php publico/nucleo/Emision.php` | ≥1 en cada uno; y `verificar_emision.py` sin cambios en el sitio de pruebas |
| **E6** Estación (Python) | `comun.py` con `termino()`; t2_27_* y t2_28_correos.py; «SIN ORDEN» → «a espera de informe técnico»; hoja PENDIENTES → «A ESPERA DE REPUESTO»; QUITADAS → «ATENDIDAS»; corregir el «{total} órdenes abiertas» de t2_27_status_semanal.py:363 | El Python lee el mismo JSON | `python -c "import sys;sys.path.insert(0,'../../agentes/scripts');import comun;print(comun.termino('ORDEN',2), comun.titulo('TOTAL_ABIERTAS'))"` | `órdenes TOTAL DE ÓRDENES ABIERTAS` |
| | | Clave inexistente → error | `python -c "import sys;sys.path.insert(0,'../../agentes/scripts');import comun;comun.termino('NO_EXISTE')"` | termina con `VocabularioError` |
| | | Lista negra en 0 en los scripts en alcance | `python ../../agentes/scripts/prueba_vocabulario_py.py` | `lista negra: 0` |
| **E7** Piloto | HOJA_ADMINISTRACION, HOJA_JEFE_ZONA, HOJA_TECNICO, QUE_PROBAR y la tabla de estados regenerada desde el JSON; capturas nuevas con `capturar_pantallas.mjs --piloto` | Lista negra en 0 | `php pruebas/prueba_vocabulario.php --solo=piloto` | `lista negra: 0` |

### 9.1 Casos de `prueba_panel_zona.php` (base sintética, versión actual del esquema)

1. NUEVO sin OT → fila 2 y sublínea «sin asignar».
2. ASIGNADO con OT de evaluación en `atenciones.json` → fila 1.
3. ASIGNADO sin OT, asignada hace 4 días → fila 2 y cuenta en «3+ días». Asignada hace 2 días → fila 2, sin sublínea.
4. ASIGNADO con OT de evaluación, asignada hace 5 días → fila 1 y **no** cuenta en «3+ días» (sublínea ≤ fila).
5. **ESPERA_REPUESTO sin ningún documento** → fila 1 (precedencia D-A).
6. Solo capturas NUMERADA o FALLIDA → fila 2 y sublínea «con OT INDUSTEC no emitida».
7. ATENDIDO → pie «atendidas», **no** entra en el TOTAL.
8. EN_REVISION con OT → fila 1, y en el pie «en revisión».
9. CERRADO_SIN_ATENCION sin `regularizado_en` → pie «sin regularizar». Con `regularizado_en` → no aparece en ningún lado.
10. OT con equipo Deshabilitado y después una OT con Operativo → no cuenta en la fila 3; cuenta en «con equipo operativo».
11. Estado del equipo vacío → «sin dato del equipo». En cada zona, deshabilitados + operativos + sin dato = TOTAL.
12. Solicitud SOLICITADO con `deshabilitado = 1` y 60 h, en una orden de la fila 3 → «vencidas» = 1. En toda zona, vencidas ≤ fila 3.
13. `Pendientes::disponible()` falso → la fila 3 y las vencidas son `null` y se pintan «no disponible» (nunca `0`).
14. Falta `atenciones.json` → las filas 1 y 2 son `null`; el TOTAL es numérico.
15. Dos avisos enlazados por `continua_de`, ambos abiertos → cuentan 1.
16. Una orden que salió del catálogo de 90 días no entra en ninguna cifra: el universo es solo el catálogo (§8.1) y `fueraDeCatalogo()` no se suma. Es así por construcción, porque `tarjetasPorZona()` recibe solo lo que devuelve `enAlcance()`. La prueba lo cubre con «filas del enlace = cifra de la tarjeta» en cada zona y cada grupo: el enlace lista ese mismo catálogo. (El caso anterior, un aviso `9999…` fuera de catálogo, ya no aplica: fuera de catálogo no se cuenta nada.)
17. Orden **del catálogo** sin zona resuelta (el local no calza con el maestro) → no lleva tarjeta, cuenta en «sin zona». Σ tarjetas (incluida OTRA) + sin zona = cuadro TOTAL (en la base sintética, 15 + 1 + 1 = 17).
18. Orden de zona OTRA → aparece la tarjeta OTRA; no entra en el total general.
19. El TOTAL es igual a un **conteo independiente por estado** sobre el mismo universo. No es una tautología (fila 1 + fila 2).
20. El jefe de zona UIO recibe una sola tarjeta (UIO) y ninguna línea de total general.

### 9.2 `prueba_vocabulario.php`: qué comprueba

- Estructura: toda clave cumple `^[A-Z][A-Z0-9_]*$`; todo concepto tiene `termino`, `plural`, `titulo`, `ayuda` y `roles`; ningún término se repite entre conceptos.
- **Todo estado del código tiene concepto**, leyendo las constantes vivas: `Ui::ESTADOS` (9), `Pendientes::ESTADOS` (21), `Novedades::ESTADOS` (6), `Pendientes::VIAS` (4), los veredictos de KFC (5) y la `ETIQUETA` de cronograma.js (8). Todo mapa apunta a un concepto existente.
- Un nombre viejo tiene un solo destino (`reemplaza`, `conserva`, `sinonimos_prosa`, `contratos_externos` o `fuera_de_alcance`). Si la misma palabra tuvo dos sentidos, cada destino lleva su contexto entre paréntesis.
- **Lista negra.** Distingue mayúsculas, busca palabra completa y mira **solo texto visible**:
  - PHP: `token_get_all()`, con `T_CONSTANT_ENCAPSED_STRING`, `T_ENCAPSED_AND_WHITESPACE` y `T_INLINE_HTML`, sin comentarios.
  - JS: literales de cadena después de quitar los comentarios.
  - Python: `tokenize` en `prueba_vocabulario_py.py`.
  - piloto: el texto del `.md`.
  - Se ignoran los literales que son identificadores (`^[a-z0-9_]+$` o `^[A-Z0-9_]+$`) y los textos de `contratos_externos`.
  - Se revisa también `lista_negra_contextual` (archivo + literal).
  - Una excepción justificada va a `lista_negra_excepciones`, con archivo, palabra, `maximo` (cuántas apariciones de código cubre) y motivo. No va por línea: las líneas se mueven con cada cambio.
- Un barrido rápido del 24-sep-2026, sin tokenizar, encontró **998 líneas en 76 archivos**. Incluye identificadores y comentarios, así que la cifra real será menor; la medida oficial será la de la prueba.
- `Vocabulario::t()` lanza excepción con una clave inexistente, y `deEstado()` la lanza con un estado o un dominio inexistente.

### 9.3 Qué puede hacer cada quien

| Acción | Quién |
|---|---|
| Programar E1–E7 en la rama, correr las pruebas locales y proponer cambios al JSON | Autónomo (Steven) |
| Cambiar el **significado** de un concepto o de una fila de la tarjeta, o mover un texto de `contratos_externos` | Requiere aprobación de Andrés |
| Desplegar en Hostinger, correr las pruebas del servidor y cambiar la estación | Requiere aprobación de Andrés (I-8: primero UIO y 48 h) |
| Tocar la base de datos, migrar `CERRADO_SIN_OK`, traer el export SAP al servidor, renombrar claves de la base o clases CSS, regenerar PDF ya emitidos, usar datos reales en las pruebas | Prohibido en esta tarea |

---

## 10. API para programar

**Archivo:** `app/publico/vocabulario.json`, en UTF-8 sin BOM. La `version` tiene la forma `AAAA-MM-DD.n` y se sube con cada cambio.

### 10.1 PHP: `app/publico/nucleo/Vocabulario.php`

```php
final class Vocabulario
{
    public const ARCHIVO = __DIR__ . '/../vocabulario.json';

    /** Término: singular si $n === 1; plural en cualquier otro caso (también 0). Solo la palabra, sin el número. */
    public static function t(string $clave, int $n = 1): string;
    /** Rótulo (botón, pestaña, columna, fila de la tarjeta). */
    public static function titulo(string $clave): string;
    /** La frase de ayuda (tooltip), igual para todos los roles. */
    public static function ayuda(string $clave): string;
    /** Forma corta si existe; si no, el término. Solo para la barra del técnico y el chip de zona. */
    public static function corto(string $clave): string;
    /** Clave de concepto de un valor de la base. $dominio: caso | pendiente | novedad | preventivo | via | decision_kfc | estado_ot | estado_equipo | zona | semaforo.
     *  Compara trim + strtoupper del valor. */
    public static function deEstado(string $estadoBd, string $dominio = 'caso'): string;
    /** El JSON completo, ya decodificado (para la tarjeta, las listas y las pruebas). */
    public static function todo(): array;
}
```

- El archivo se lee **una vez por petición** y queda en caché estática.
- Si el archivo no existe o no se puede decodificar, lanza `RuntimeException('Vocabulario: no se pudo leer vocabulario.json: <motivo>')`.
- Si la clave, el estado o el dominio no existen, lanza `InvalidArgumentException('Vocabulario: la clave «X» no existe en vocabulario.json (versión V)')`.
- **Nunca** devuelve la clave en crudo ni un texto de respaldo.
- `Ui::ESTADOS`, `etiquetaEstado`, `ayudaEstado`, `estado()` y `estadoVista()` se conservan como API, construidos desde `Vocabulario::deEstado()` + `t()` / `ayuda()`. Así las llamadas actuales no cambian.

Ejemplo: `$n . ' ' . Vocabulario::t('ORDEN', $n) . ' ' . Vocabulario::t('ESPERA_INFORME', $n)` → «3 órdenes a espera de informe técnico».

### 10.2 JS: `UI.T` en `app/publico/ui.js`

```js
UI.T(clave, n)            // término: n === 1 (o sin n) → singular; si no → plural
UI.T.titulo(clave)
UI.T.ayuda(clave)
UI.T.corto(clave)
UI.T.deEstado(estado, dominio)   // dominio por defecto 'caso'
UI.T.version              // la versión del diccionario en uso
```

- Al cargar, `ui.js` usa el **respaldo embebido**: el bloque `<vocabulario:RESPALDO>` con el JSON recortado (término, plural, título, corto, ayuda y los mapas), generado desde `vocabulario.json`. Nunca se escribe a mano.
- Enseguida pide `vocabulario_publico.json` (la misma forma, escrita por el mismo generador; `sw.js` la precarga) y lo reemplaza si trae versión, conceptos y mapas. El `vocabulario.json` completo **no se sirve** (`.htaccess` niega los `.json`): trae notas internas y solo lo leen `Vocabulario.php` y `comun.py`.
- Si la clave no existe, lanza `Error('Vocabulario: la clave «X» no existe (versión V)')`. No devuelve texto inventado.
- `sw.js`: `'vocabulario_publico.json'` entra en `PRECARGA` y se sube la versión de caché cada vez que cambia `version` **después de un despliegue**. La v19 lleva la ola 1 y la ola 2 juntas porque todavía no salió al servidor (allá está la v18): si la v19 se desplegara sin esta versión, se sube a v20.
- `catalogos.php` manda la **clave** del estado de la base (`estatus_clave`) además del texto (`estatus`, que queda para una app vieja en caché); `app.js` prefiere la clave y la traduce con `UI.T`.

### 10.3 Python: `agentes/scripts/comun.py`

```python
VOCABULARIO_PATH = Path(os.environ.get("INDUSTEC_VOCABULARIO_PATH")
                        or (RAIZ / "desarrollo" / "sistema_ots" / "app" / "publico" / "vocabulario.json"))

class VocabularioError(KeyError): ...

def vocabulario() -> dict: ...                      # lee una vez (lru_cache); falta o JSON inválido → VocabularioError
def termino(clave: str, n: int = 1) -> str: ...     # singular si n == 1; plural si no
def titulo(clave: str) -> str: ...
def ayuda(clave: str) -> str: ...
def de_estado(estado_bd: str, dominio: str = "caso") -> str: ...
```

Los scripts de la estación leen **el mismo archivo** del repositorio desplegado en la estación. No se descarga del servidor.

### 10.4 Cómo se cambia un término

1. Editar `vocabulario.json`: solo `termino`, `plural`, `titulo`, `ayuda`, `corto` o las listas. **Las claves no se tocan.**
2. Subir `version`.
3. Regenerar el respaldo embebido de `ui.js` y subir la versión de caché de `sw.js`.
4. Correr `prueba_vocabulario.php`, `prueba_vocabulario_js.mjs` y la línea base.
5. Desplegar primero en UIO (I-8).

Un cambio de **significado**, como mover un estado de concepto o cambiar una fórmula de la tarjeta, necesita la aprobación de Andrés y actualizar este documento.

---

## 11. Preguntas abiertas para Isabel

Ninguna bloquea la etapa E1. Van en una sola conversación, con sus propias cifras.

1. ¿«PENDIENTE OK OP´S» es lo mismo que «registrado en SAP, esperando la respuesta de KFC»? Hoy REGISTRADO_SAP y ESPERA_KFC apuntan a `PENDIENTE_OK_OPS`. Si no es lo mismo, se separa en otro concepto sin tocar la base.
2. En «a espera de informe técnico», ¿se refiere a la OT INDUSTEC de la visita (lo que decidió Andrés) o al informe técnico detallado?
   - Dato: de su ESTATUS IND «INFORME TECNICO», 19 de 75 filas ya tenían una OT INDUSTEC emitida antes del miércoles. Con D-A, esas órdenes salen en ÓRDENES ABIERTAS.
3. Semáforo: el verde se rotula desde la versión `.6` «Repuesto en seguimiento», porque hoy junta órdenes cuya acción la debe INDUSTEC (solicitud por validar, por registrar en SAP, en taller de INDUSTEC, sin solicitud) con las ya despachadas. ¿Quiere que el verde se parta por quién debe la acción (despachado → KFC; por validar o registrar → amarillo, INDUSTEC)? Es un cambio de lógica en `Reportes::calcular()` y lo decide Andrés con ella. Y el rojo, ¿por días desde que llegó la orden (como se calcula hoy) o desde su último movimiento?
4. ¿Qué significa su «SIN CORREO»?
5. ¿Usa «OPERATIVO CON NOVEDAD»? Los jefes escriben «HABILITADO CON NOVEDAD».

## 12. Tareas posteriores (no son de esta etapa)

- **Traer al servidor el export SAP del lunes** (D-D). Mientras no esté, la tarjeta dice que no es la cifra de SAP.
- **Exportar en `casos_sap.json` la lista de avisos que KFC eliminó.** Hoy `t2_6_imap_avisos.py` solo exporta cuántos son. Con esa lista se podrán contar las órdenes abiertas de más de 90 días sin arrastrar las anuladas, y `FUERA_CATALOGO` (hoy reservado) volverá a la tarjeta como sublínea del TOTAL.
- **Estado `CERRADO_SIN_OK`**: migración del ENUM, transición desde ASIGNADO o ESPERA_REPUESTO con motivo, y cancelación de la solicitud. El concepto `CERRADA_SIN_OK` ya está reservado.
- **Defecto de `Casos::enlazar()`**: toma `ots[0]` como cierre aunque sea una evaluación. Hoy puede llevar a ATENDIDA una orden que solo tiene evaluación.
- **Registrar el pedido** en `T2_28_OBSERVACIONES_INDUSTEC.md`, donde hoy no consta, y las decisiones en `ESTADO.md`.
- **Regenerar los paquetes del piloto** ya entregados en `SALIDAS IA/OTS/paquete_piloto_uio/`.
