> **Este es el documento maestro del proyecto.** Vive dentro del proyecto (`D:\INDUSTECH IA\PLAN_INDUSTEC.md`)
> justamente para que cualquier conversación nueva pueda retomarlo sin depender del historial de otra.
>
> **Si estás retomando el trabajo, lee primero [`ESTADO.md`](ESTADO.md)**: dice qué está hecho, qué sigue,
> y qué está tocando cada conversación en paralelo. Este archivo dice *qué* hay que construir y con qué
> criterios; `ESTADO.md` dice *dónde vamos*.
>
> El plan cambia poco. `ESTADO.md` cambia en cada sesión.

---

# Plan de Implementación — Estación de IA para INDUSTEC
### Cotización C26-115 · INDUSTECH SOLUTIONS S.A.S. → INDUSTEC · 3 fases de un mes
**Documento ejecutable.** Está escrito para que un agente lo ejecute tarea por tarea sin depender de la conversación que lo originó.

---

## 0. Cómo se ejecuta este plan

**Unidad de trabajo: la tarea.** Cada tarea tiene identificador (`T1.3`), dependencias, entregable y **criterio de aceptación verificable**. Una tarea no se da por terminada si su criterio no se cumple: se corrige o se escala, nunca se marca hecha "a medias".

**Reglas de ejecución:**
1. **No saltar dependencias.** Si `T1.5` depende de `T1.4`, `T1.4` debe haber pasado su criterio de aceptación.
2. **Las Puertas (🚦) son bloqueantes.** Detenerse, verificar, y solo entonces continuar. Una Puerta protege algo irreversible.
3. **Ante ambigüedad, preguntar.** Si un dato real contradice lo que este plan dice, **gana el dato**: se documenta la discrepancia y se consulta. No se improvisa una interpretación.
4. **Todo lo que se ejecute debe poder repetirse.** Correr un script dos veces produce el mismo resultado que correrlo una vez (idempotencia). Se logra con clave natural y *upsert*, nunca *insert* ciego.
5. **Nada se instala sin permiso** — ver Invariante I-1.
6. **Registrar siempre.** Cada corrida deja bitácora con fecha, entradas procesadas, resultados, errores y decisiones tomadas.

**División del trabajo entre código e IA** — el criterio rector, alineado con la práctica actual de IA en producción: **la IA razona, el código decide.**

| Le corresponde al código determinista | Le corresponde a la IA |
|---|---|
| Copiar, verificar, renombrar, mover | Leer texto libre y detectar ambigüedad o vacío de contenido |
| Extraer campos por patrón, cruzar tablas, calcular KPIs | Redactar conclusiones y recomendaciones de un informe |
| Llenar Excel, generar documentos, enviar correo | Interpretar una pregunta en lenguaje natural |
| **Toda decisión de aceptar/rechazar/borrar** | Proponer clasificaciones que luego valida una regla |

Esto no es preferencia estética: reduce el consumo de Claude Pro (riesgo declarado en la cotización), garantiza que los pasos críticos se ejecuten siempre, y hace el sistema auditable.

**Cómo se especifica una tarea para que la ejecute Sonnet a su máximo esfuerzo.** Este plan se ejecuta con Claude Sonnet, no con un modelo insignia — su fiabilidad en juicio implícito no verificable es menor, así que el plan compensa con especificación, no con supervisión constante. Toda tarea de Fase 2 y Fase 3, y cualquier tarea nueva que se añada después, cumple:

1. **Tamaño S/M.** Máximo ~5 archivos o ~2 horas por tarea. Una tarea que hoy suena a "intervenir el sistema" o "construir el bot" es en realidad una épica: se descompone en subtareas (`T2.1.1`, `T2.1.2`...) **antes** de empezar, por descomposición **vertical** — una función completa de punta a punta — nunca horizontal ("toda la capa de datos", luego "toda la capa de vista").
2. **Criterios binarios, nunca "implementar X".** 2-3 criterios de aceptación por subtarea, formulados como Given/When/Then o tabla entrada→salida esperada, cada uno con un **comando de verificación exacto** que se ejecuta y cuya salida se pega como evidencia antes de marcar la tarea hecha (test, script de conteo contra fuente independiente, diff celda a celda, query de validación).
3. **Checkpoint de verificación cruzada, infranqueable, antes de escribir en producción o en un maestro.** Ningún `INSERT`/`UPDATE`/`DELETE` contra la base de producción o un archivo maestro se ejecuta sin comparar antes el resultado contra una fuente independiente (hoja de totales, SAP, respaldo anterior, plantilla real) y **abortar con mensaje explícito** si no cuadra exactamente. Es el patrón que salvó a la Fase 1 de corromper el maestro de datos tres veces (T1.5, T1.6, T1.11) — se generaliza, no se repite por memoria.
4. **`UNIQUE KEY` sobre la clave de negocio real desde la migración inicial** — nunca como corrección posterior (I-9).
5. **Ninguna decisión de diseño con varias opciones válidas queda abierta al criterio del agente.** Qué campo define "cerrado", cómo se resuelve una colisión, qué versión de MariaDB asumir, qué umbral dispara una alerta: se resuelven **en este documento**, con la opción elegida y un ejemplo concreto, antes de asignar la tarea.
6. **LLM + base de datos o LLM + acción real exige guardrail de credencial, no solo de prompt.** Usuario de solo lectura, lista blanca de tablas, límite de reintentos — a nivel de infraestructura.
7. **Límite explícito de reintentos por tarea: 2-3**, y luego se escala, nunca se "gira" buscando que converja solo.
8. **Tabla autónomo / requiere aprobación humana / prohibido, específica de la tarea**, con el umbral de escalamiento más conservador que el que se usaría con un modelo insignia — ver la tabla de cada tarea de Fase 2 y Fase 3 más abajo.

Esto no es burocracia: es la traducción directa de los 16 errores reales encontrados y corregidos en T1.1–T1.11 (regex que devora la etiqueta siguiente, `UNIQUE KEY` ausente, criterio de cierre equivocado, colisión de contenido) a reglas que impiden que se repitan en las fases que faltan.

---

## 1. Invariantes — nunca se violan

| # | Invariante | Por qué |
|---|---|---|
| **I-1** | **Nada se instala sin permiso explícito**, indicando qué es, para qué sirve y su licencia. Ni siquiera librerías menores | Decisión del cliente |
| **I-2** | **Copiar → verificar → borrar.** Nunca borrar ni mover directamente desde una carpeta sincronizada con Drive | Un borrado local borra en la nube. Es la única operación del proyecto capaz de destruir material de la empresa |
| **I-3** | **`G:\Mi unidad` es de solo lectura.** Se estudia y se copia; jamás se altera | Es la operación viva de la empresa |
| **I-4** | **Los archivos de la administración no se sobrescriben.** Los agentes escriben a nombre nuevo; ella promueve | Preserva su control y su confianza |
| **I-5** | **Ningún renombrado sin registro reversible** en el manifiesto | El nombre original es la referencia que ya circuló en correos y planes |
| **I-6** | **Software libre o gratuito**, el mejor de su categoría. Lo de pago se propone con justificación y costo **antes** de adquirir nada | Premisa del cliente |
| **I-7** | **Si no hay dato, se dice.** Ningún agente inventa ni completa por verosimilitud | Lo que responda la Gerencia va a KFC |
| **I-8** | **Los técnicos no cambian su forma de trabajar** | Adopción |
| **I-9** | **Toda tabla con *upsert* declara `UNIQUE KEY` sobre su clave de negocio real desde la migración inicial**, nunca como corrección posterior | Sin ella, cada corrida duplica filas en vez de actualizarlas — pasó tres veces en Fase 1 |
| **I-10** | **Ninguna escritura a producción o a un archivo maestro sin verificación cruzada previa contra una fuente independiente**, y abortar ruidosamente si no cuadra | Detectó 3 bugs reales antes de corromper nada (T1.5, T1.6, T1.11); se generaliza a toda tarea futura |
| **I-11** | **Colisión de clave de negocio entre fuentes: se resuelve por hash de contenido.** Idéntico → duplicado, se conserva uno; distinto → colisión real, ambos a revisión humana. Nunca "el que llega primero gana" | Así se resolvieron los 182 duplicados y 0 colisiones reales de T1.6 |
| **I-12** | **Todo catálogo de referencia declara su cobertura temporal antes de usarse para marcar incumplimientos o hallazgos**; lo que cae fuera de cobertura se excluye y se reporta aparte, no se marca como error | Un catálogo SAP que solo cubría 8 de 12 meses generó ~1.662 falsos positivos en T1.10 |
| **I-13** | **LLM + base de datos o LLM + acción real lleva guardrail de credencial** (usuario de solo lectura, lista blanca de tablas, límite de reintentos), no solo instrucción de prompt | Un prompt no es control de acceso; aplica directo al bot de Telegram (T3.1) |

---

## 2. Contexto

**Quién es quién.** INDUSTECH SOLUTIONS S.A.S. (Andrés Basantes) presta el servicio; INDUSTEC (César Basantes) lo recibe. INDUSTECH **desarrolló el sistema de órdenes de trabajo actual**, lo que permite intervenirlo dentro de este mismo servicio cuando la Fase 2 lo requiera.

**El negocio.** Mantenimiento correctivo, preventivo y predictivo en **~92-95 locales** de tres zonas — UIO (Quito), LARB (Latacunga-Ambato-Riobamba-Baños), CNLJ (Cuenca-Loja) — con **560 a 640 órdenes mensuales**. Grupo KFC es el 95% de los ingresos.

**Dónde se corta la cadena.** El sistema web funciona: el técnico llena el formulario en su teléfono y el informe sale por correo. El problema empieza después — alguien descarga cada informe y lo archiva a mano, copia cada orden a tres archivos de planificación, arma el resumen diario y monta el informe mensual. Y cuando la Gerencia necesita un dato, depende de que alguien se lo prepare.

**Qué se construye.** Una estación dedicada que recoge sola los informes, los archiva, mantiene los planes al día, produce los reportes y responde a la Gerencia desde el celular. Sobre lo que ya existe, de modo que **el único gasto nuevo sea Claude Pro**.

---

## 3. Hallazgos verificados

Datos medidos sobre el material real. Son la línea base contra la que se verifica el trabajo.

### 3.1 El histórico existe y está casi completo
`G:\Mi unidad\GESTION DE OTS INDUSTEC` → **7.333 PDFs, 5,12 GB**

| Rama | PDFs |
|---|---|
| 2025 · Correctivos | 1.639 |
| 2025 · Preventivos | 182 |
| 2026 · Correctivos | 4.579 |
| 2026 · Preventivos | 491 |
| 2026 · Otros trabajos | 26 |
| 2026 · **sin clasificar (carpeta raíz)** | **405** |
| Otros clientes | 9 |

Por zona: **CNLJ 2.749 · LARB 2.470 · UIO 1.916**. Contra los contadores del servidor (~7.201 emitidas): **cobertura ~99,5%**. Hay **204 duplicados** con sufijo `(1)`.

### 3.2 Los PDFs son texto extraíble — no hace falta OCR
Dompdf 3.1.0, fuentes DejaVu subset con tabla `/ToUnicode` presente. Extracción por patrones sobre etiquetas literales estables: `ID-ORDEN-INDUSTEC:`, `ID-ORDEN-GRUPOKFC:`, `Fecha de Atención:`, `Local:`, `Técnico Asignado:`, `Estado del Equipo:`, `Tiempo de Atención:`, `Calificación: N/10`.

### 3.3 Estado de la calidad del dato
Evaluado sobre las seis dimensiones del marco DAMA:

| Dimensión | Estado | Evidencia |
|---|---|---|
| **Validez** | 🔴 Crítico | Código de local es texto libre: **121 variantes para ~95 locales** (`K073`/`k073`/`kh073`, `CN42`/`CN042`, `M44`/`M044`, `K0146`) |
| **Consistencia** | 🔴 Crítico | Zona cruzada: el formulario de CNLJ registró `G025`, `R001`, `R011`, `G005`, que son locales de Quito |
| **Exactitud** | 🟠 Alto | Typos en el aviso SAP, que es la llave del cruce: `1034055`→`10340555`, `10342004`→`10352004`, `20347802`→`10347802`, `1031`, `0` |
| **Completitud** | 🟠 Alto | **969 de 6.569 órdenes (15%) sin una sola foto**; 17 PDFs con campos vacíos (`OT-0023---.pdf`) |
| **Unicidad** | 🟡 Medio | 204 duplicados; contador sin bloqueo → dos envíos simultáneos pueden generar el mismo número |
| **Oportunidad** | 🟡 Medio | El archivo manual acumula retraso: 405 PDFs sin clasificar |

### 3.4 Los formatos ya existen — no hay que inventarlos
| Archivo en Drive | Aporta |
|---|---|
| `LOCALES_INDUSTEC_GENERAL.xlsx` | Maestro de locales: código, zona, cadena, ubicación, correos, *fee* |
| `KPI'S INDUSTEC.xlsx` | 6.451 avisos SAP con fechas de notificación, creación y cierre técnico |
| `STATUS_PENDIENTES_SEMANA N.xlsx` | Reporte semanal ya formateado (RESUMEN + ORDENES) |
| `SEGUIMIENTO PREVENTIVOS _ 2026.xlsx` | 92 locales × 4 ingresos anuales |
| `Hoja de cálculo en Basis.xlsx` | Formato exacto del export nativo de SAP |
| `BASE DE DATOS DE EMPLEADOS INDUSTEC.xlsx` | 19 empleados con tipo de técnico y zona |
| `PLAN SEGUIMIENTO OTS ... .xlsx` | Plan de zona, 22 columnas, con semáforo de antigüedad |

### 3.5 Restricciones del entorno
| Recurso | Estado |
|---|---|
| Google Drive | **10,53 GB usados de 15 — solo 4,47 libres**, creciendo ~5,6 GB/año |
| Disco `D:` | 328 GB libres |
| Correo | **Titan**, no Gmail. `reclutamiento@industec.me` existe en Google solo como identidad de Drive (verificado: Drive responde, Gmail da error de precondición). IMAP: `imap.titan.email:993` |
| Hosting | Hostinger **Premium**: MySQL, cron ilimitados, SSH, Composer. **Sin Node.js ni Python en servidor** |
| Cron Hostinger | Corre en **UTC**; Ecuador es UTC−5 → desplazar +5 h |
| Envío de correo | SMTP Titan: **1.000/día por buzón**. `mail()` de PHP: solo 100/día |
| Estación | Node v24 y Git instalados. **Python NO está** (`python` responde el alias de Microsoft Store). Sin PHP, sin Poppler |

---

## 4. Encuadre del trabajo

Tres fases consecutivas de un mes. Se planifica por **objetivo de cierre de mes**, no por horas: las 40 h mensuales son el marco comercial con el cliente, no la restricción de trabajo interno.

| Fase | Mes | Cierra con |
|---|---|---|
| **1 · Cimientos** | Septiembre (inicio 2/sep) | Estación funcionando, respaldo andando, informes archivándose solos, plan de zona actualizándose sin intervención |
| **2 · Automatización** | Octubre | Tres reportes que hoy toman días, generándose en minutos y enviándose solos |
| **3 · Decisión** | Noviembre | Tablero de decisión, consulta desde el celular, equipo capaz de operarlo por su cuenta |

Cada fase cierra con **capacitación** al personal que usará lo entregado. Al cerrar cada una, INDUSTEC decide si continúa; lo entregado queda funcionando y en su poder.

**Piloto: zona UIO.** Es la de menor volumen (1.916 órdenes frente a 2.749 de CNLJ) y mejor calidad de códigos. Se replica a LARB y CNLJ solo cuando UIO funcione de punta a punta — el error más común en proyectos de datos maestros es intentar abarcar todos los dominios a la vez.

---

## 5. Arquitectura

### 5.1 Almacenamiento en capas
El Drive está a 4,47 GB del tope; copiar 5,12 GB a una carpeta sincronizada es físicamente imposible. El criterio que resuelve esa restricción, definido por el cliente el 2026-09-04, es **separar por antigüedad, no por importancia**: en la nube vive solo lo del año en curso, que es lo que se consulta; el histórico vive en disco local; y todo tiene una segunda copia en el NAS.

| Ubicación | Contenido | ¿Nube? | Horizonte |
|---|---|---|---|
| **Google Drive + OneDrive** | **Solo el año en curso.** Es lo que necesita estar accesible desde cualquier lado | Sí | Permanente, con purga anual al cerrar el año |
| **`D:\RESPALDOS`** | **Repositorio canónico** e histórico de años anteriores, corregido, ordenado y renombrado | No | Permanente |
| **TrueNAS** | **Respaldo secundario de todo** — año en curso e histórico | No | Permanente, se vacía a discos externos cuando se llene |
| **`SALIDAS IA`** | Solo información nueva, mejorada o corregida | Sí | Definitivo |
| **`ENTRADAS IA`** | Buzón donde la administración deposita material base | Sí | Se aligera |
| `G:\Mi unidad` | Estructura actual de la empresa | Sí | **Intocable** (I-3, T1.8 cancelada) |

**Regla de las dos copias.** Todo dato de respaldo existe en **exactamente dos lugares**: el disco local de la estación y el TrueNAS. La copia en la nube del año en curso es un tercer ejemplar de conveniencia, para consulta, no el respaldo. Cuando el NAS se llene, su contenido más antiguo se vuelca a discos externos u otra solución que se decida entonces.

### 5.2 Herramientas (propuesta — cada una se consulta antes de instalar, I-1)
| Herramienta | Para qué | Licencia |
|---|---|---|
| **Python 3.12** | Motor de los agentes | PSF |
| `pdfplumber` | Extracción de PDF | MIT |
| `openpyxl` | **Abre el Excel real como plantilla y rellena filas**, conservando estilos, tabla, formato condicional y validaciones | MIT |
| `pandas` | Cruces y agregaciones | BSD |
| `python-docx` · `python-pptx` · `reportlab` | Word, PowerPoint, PDF | MIT / BSD |
| `python-telegram-bot` | Bot de la Gerencia | GPLv3 |
| **MariaDB 11** | Base de datos, compatible con la MySQL de Hostinger | GPLv2 |
| **Poppler** (`pdftotext`) | Extracción masiva rápida | GPLv2 |
| **PHP 8.2** | Solo si la Fase 2 interviene el sistema | PHP License |

Si se prefiere un stack más liviano, todo salvo Python es negociable.

### 5.3 Los agentes
| # | Agente | Fase | Responsabilidad |
|---|---|---|---|
| 1 | **Gestor de OTs** | 1 | Recoge informes del correo, archiva y audita calidad |
| 2 | **Consolidador** | 1 | Actualiza el plan de cada zona, 07:00 y 14:00 |
| 3 | **Reporteador** | 2 | Reporte diario, indicadores mensuales, reporte KFC |
| 4 | **Consultor** | 3 | Atiende a la Gerencia por Telegram |
| 5 | **Analista de confiabilidad** | 3 | Recurrencia de fallas, repuestos, alertas de repotenciación |
| 6 | **Desarrollador** | Transversal | Arquitectura, código, seguridad, auditoría |

El Agente 6 no corre en horario: construye y sostiene a los demás. Mantiene `SALIDAS IA\CONTEXTO\` con lo que aprende y ningún manual recoge — convenciones, vocabulario propio (`IMPO`, `APRO`, `MSOL`, `MECE ORAS`), quién aprueba qué, correcciones recurrentes. Eso es lo que con el tiempo lo vuelve experto en *esta* empresa.

### 5.4 Canal de la Gerencia: Telegram
La Fase 3 pide consulta desde el celular. La propia cotización declara que llevar reportes a WhatsApp exigiría una plataforma empresarial contratada, como etapa posterior. **Telegram cumple el requisito sin costo**: Bot API gratuita, long polling detrás de NAT sin servidor público ni IP fija, y admite adjuntar PDFs.

---

## 6. Estándar canónico

### 6.1 Nomenclatura de archivos
```
Correctivo:            OT-{correlativo:4}-{LOCAL}-{AVISO:8}-{ZONA}.pdf      → OT-2422-K146EC-10352088-CNLJ.pdf
Correctivo sin aviso:  OT-{correlativo:4}-{LOCAL}-{ZONA}.pdf                → OT-0260-A010EC-UIO.pdf
Preventivo:            OT-{correlativo:4}-{LOCAL}-{AVISO:8}-D{n}-{ZONA}.pdf → OT-0186-K041EC-10340555-D2-CNLJ.pdf
Preventivo sin aviso:  OT-{correlativo:4}-{LOCAL}-D{n}-{ZONA}.pdf           → OT-0005-K061EC-D5-CNLJ.pdf
```

⚠️ **El segmento del AVISO es opcional, y eso no es una excepción menor.** El mantenimiento preventivo no nace de un aviso SAP, y algunos correctivos antiguos se emitieron sin él. La primera versión del estándar lo exigía siempre, y esa sola decisión mandó **185 documentos correctos a cuarentena** (ver T1.6b). El parser de ingesta acepta las cuatro formas.

**El módulo lo manda la carpeta, no el nombre.** El árbol es `{año}/{CORRECTIVO|PREVENTIVO}/{zona}/{cadena}`, y ahí es donde el saneamiento ya decidió. Deducirlo del sufijo `-D{n}` fallaba en los preventivos que no llevan número de día.

**Los documentos sin correlativo no son órdenes de trabajo.** Los informes técnicos sueltos (`INFORME TÉCNICO K124 MÁQUINA DE HIELO.pdf`) no tienen número de orden y no pertenecen a este árbol: viven en `D:\RESPALDOS\INFORMES TECNICOS\{año}\{zona}\{cadena}\`, archivados por local pero fuera de la tabla `ots`.

**Decisión de criterio, documentada:** la práctica de records management recomienda separar campos con guion bajo. Aquí se mantiene el **guion**, porque `OT-2422-K146-10352088-CNLJ` es un **identificador persistente real** — lo genera el sistema, va impreso dentro del PDF, en el asunto del correo y en los planes de la administración. Cambiar el separador rompería la trazabilidad con todo lo que ya circuló, y eso pesa más que la convención. Sí se adoptan las reglas que no rompen nada: **sin espacios** (el preventivo actual usa `Dia 2`, que viola la regla básica → `D2`), ceros a la izquierda, y nombre corto.

### 6.2 Carpetas
```
D:\RESPALDOS\ORDENES DE TRABAJO\{AÑO}\{CORRECTIVO|PREVENTIVO|OTROS}\{UIO|LARB|CNLJ}\{CADENA}\
```
Hoy la estructura es inconsistente: 2025 va por tipo → zona → cadena, y 2026 mezcla carpeta plana, `1. CORRECTIVOS` e `INFORMES MC _ ZONA C-L`.

### 6.3 Vocabulario único
| Concepto | Canónico | Variantes a unificar |
|---|---|---|
| Zona | `UIO` · `LARB` · `CNLJ` | ZONA QUITO, ZONA UIO, ZONA CUENCA LOJA, ZONA C-L |
| Cadena | `KFC` · `GUS` · `CASA RES` · `TROPI BURGER` · `JUAN VALDEZ` · `MENESTRAS DEL NEGRO` · `AMERICAN DELI` · `EL ESPANOL` · `BASKIN ROBBINS` · `CAJUN` · `CINNABON` · `HELADERIA` · `IL CAPO` | MENESTRAS, BASKIN, VALDEZ, TROPI, TROPIBURGER, ESPAÑOL |
| Local | `[A-Z]{1,2}[0-9]{3}EC` | 121 variantes |
| Fechas en nombres | `YYYY-MM-DD` (ISO 8601) | El orden alfabético coincide con el cronológico |

⚠️ **La cadena se resuelve por el maestro, nunca por el prefijo del código.** El prefijo engaña: `J018EC` y `J022EC` son Cajun, mientras otros códigos con `J` son Juan Valdez.

### 6.4 Modelo de datos
```
AVISO SAP (KFC)          ──1:N──►  OT INDUSTEC              ──1:N──►  EQUIPO ──1:N──► FOTO
  aviso        10346666            id_industec  OT-2061-K121EC-10346666-LARB
  centro_coste K121EC              zona, correlativo, fase (EVALUACIÓN | CIERRE)
  ubic_tecnica RINT-...-EK121-...  local_codigo (canónico)
  clase_aviso  L1                  fecha, técnico, horas, estado_ot, estado_equipo
  estatus      MECE / MECE ORAS    actividades, repuestos, obs, satisfacción, atiempo
```
`# OT` del plan = `AVISO` de SAP = `idorden` del formulario. **Es la llave de todo el sistema**, y hoy se escribe a mano.

**Tablas:** `locales` · `locales_alias` · `avisos_sap` · `ots` · `ot_equipos` · `ot_fotos` · `tecnicos` · `observaciones_calidad` · `plan_snapshots` · `correcciones` · `consultas_gerente` · `bitacora`.

**Clave natural de una orden:** `id_industec`. Toda carga es *upsert* sobre esa clave — nunca *insert* ciego.

### 6.4b Órdenes sin aviso SAP — no son un error, son un caso de negocio
**Regla del cliente, 2026-09-04.** Una parte de las órdenes correctivas nace **sin número de aviso SAP**, y eso es normal en la operación: cuando un local tiene una emergencia mientras el técnico ya está en sitio por otro caso, la atiende y emite la orden sin aviso, porque ese aviso todavía no existe.

**La regularización es siempre decisión de la administración**, por una de dos vías:
1. Pedir a Grupo KFC que cree el caso, justificándolo con el informe ya emitido, o
2. Crearlo ella misma en SAP.

**Qué implica para el sistema:**
- **Ningún agente inventa un aviso ni da la orden por cerrada sin él** (I-7). La orden se registra completa, con su aviso en blanco.
- El auditor de calidad levanta la regla **`CORRECTIVO_SIN_AVISO_SAP`** (severidad ALTA, dimensión *completitud*) por cada correctivo en esta situación, y la administración anota el aviso resultante en la columna `VEREDICTO ADMIN`. Es una **cola de trabajo suya**, no un defecto del técnico.
- **Los preventivos quedan fuera de esta regla a propósito**: no nacen de un aviso SAP. Exigírselo fue el error de diseño que mandó 185 documentos correctos a cuarentena (T1.6b).
- En backlog y KPI (T2.2), un correctivo sin aviso **cuenta como trabajo realizado** pero se reporta aparte como *pendiente de regularización*, para que no distorsione el cruce contra SAP.

Situación al 2026-09-04: **29 correctivos** en esta condición (21 de 2025, 8 de 2026; UIO 17 · CNLJ 8 · LARB 4) y 188 preventivos sin aviso, que es lo esperado.

### 6.5 Reglas de extracción de texto — lecciones de Fase 1
Toda extracción nueva de campos desde PDF o Excel en Fase 2/3 (T2.1, T2.2) hereda estas reglas, ya pagadas en errores reales durante T1.7:
- **El separador entre etiqueta y valor nunca es `\s*` sin anclar.** Cruza saltos de línea y devora la etiqueta siguiente cuando el campo queda vacío. Usar `[ \t]*` y probar explícitamente con campos vacíos.
- **El delimitador estructural (`:`) es obligatorio en el patrón**, nunca opcional — si no, texto libre que empieza con una palabra-etiqueta se confunde con un campo real.
- **Una misma palabra puede ser encabezado de sección en un tipo de documento y etiqueta de campo en otro** (p. ej. "OBSERVACIONES"): mantener listas de encabezados separadas por tipo de documento, nunca una sola lista compartida.

---

# FASE 1 · CIMIENTOS — Septiembre

> **Cierra con:** estación funcionando, respaldo andando, informes archivándose solos, plan de zona actualizándose sin intervención.

### T1.1 · Inventario de origen
**Depende de:** nada.
Recorrer `G:\Mi unidad` y `ENTRADAS IA`. Producir `SALIDAS IA\CALIDAD\INVENTARIO_ORIGEN.csv` con ruta, nombre, tamaño, fecha y **hash SHA-256** de cada archivo.
**Aceptación:** el conteo total coincide con el medido (7.333 PDFs en `GESTION DE OTS INDUSTEC`); ningún archivo sin hash.

### T1.2 · Copia a `D:\RESPALDOS`
**Depende de:** T1.1.
Copiar preservando estructura de origen. `robocopy` con reintentos y bitácora.
**Aceptación:** conteo y tamaño coinciden con T1.1.

### 🚦 T1.3 · PUERTA DE VERIFICACIÓN — antes de tocar nada en Drive
**Depende de:** T1.2. **Bloqueante (I-2).**
1. Recalcular SHA-256 en destino y comparar **archivo por archivo** contra T1.1. Robocopy **no verifica lo que copia** (`/v` existe en `copy` y `xcopy`, no en robocopy), así que este paso es obligatorio.
2. Simulacro `robocopy /MIR /L` origen→destino: **un plan vacío demuestra que convergieron.**
3. Emitir `VERIFICACION_COPIA.csv` con el resultado por archivo.

**Aceptación: 100% de hashes coinciden y el simulacro no lista pendientes.** Con un solo archivo discrepante, no se continúa: se investiga y se recopia.

### T1.4 · Instalación del stack
**Depende de:** nada. **Sujeta a I-1** — se presenta cada pieza y se instala solo lo autorizado.
Python 3.12 + entorno virtual + `requirements.txt`; MariaDB 11; Poppler. Repositorio git con `.gitignore` que excluya `config\.env`, la base y los PDFs.
**Aceptación:** `python --version` responde 3.12; la base acepta conexión; `pdftotext` extrae texto de un PDF de prueba.

### T1.5 · Esquema y maestro de locales
**Depende de:** T1.4.
Crear el esquema. Importar los 95 locales desde `LOCALES_INDUSTEC_GENERAL.xlsx` y construir `locales_alias` con las 121 variantes → canónico.

Cuando dos fuentes discrepen sobre un local, la regla de supervivencia es, en orden: **(1)** el maestro `LOCALES_INDUSTEC_GENERAL`, **(2)** el valor más frecuente en el histórico, **(3)** el más reciente. Toda resolución queda registrada.

**Aceptación:** cada una de las 121 variantes mapea a un canónico o queda marcada para decisión; ningún canónico sin zona ni cadena.

### T1.6 · Saneamiento del repositorio canónico
**Depende de:** T1.3 y T1.5.

Se trabaja **solo sobre `D:\RESPALDOS`**, nunca sobre Drive.

**Nivel 1 — automático, sin ambigüedad:**
- Local a canónico: mayúsculas (`k073`→`K073EC`), ceros faltantes (`CN42`→`CN042EC`, `M44`→`M044EC`, `K99`→`K099EC`), ceros sobrantes (`K0146`→`K146EC`), sufijo `EC`.
- Eliminar los 204 duplicados `(1)` conservando la mejor copia (más páginas, luego mayor tamaño).
- Correlativo a 4 dígitos, aviso a 8. **Espacios fuera:** `Dia 2` → `D2`.
- Unificar nombres de zona y cadena al vocabulario de §6.3.

**Nivel 2 — automático con verificación cruzada.** Solo si el cruce lo confirma:
- **Typo de aviso SAP**: se corrige si el aviso propuesto existe en `avisos_sap`, el original no existe, y la distancia de edición es 1.
- **Zona cruzada**: se reubica si el local pertenece inequívocamente a otra zona según el maestro.
- **Cadena**: por maestro, nunca por prefijo (§6.3).

**Nivel 3 — cuarentena.** No se toca; se aísla en `D:\RESPALDOS\_CUARENTENA\` y se lista para decisión:
- Códigos fuera del maestro: `RestauranteElvita`, `GusCotocollao`, `GusSanBartolo`, `KFC167`, `G003prensa`, `G006america`, `K1111`.
- Códigos compuestos con sufijo `H` (`K174H063`, `K073H015`, `K197H071`, `K099H032`) — parecen locales con dos marcas en el mismo sitio.
- Los 17 PDFs de campos vacíos y los que llevan aviso `0`.
- Typos con más de una corrección posible.

**Trazabilidad (I-5).** `SALIDAS IA\CALIDAD\MANIFIESTO_SANEAMIENTO.xlsx`: nombre y ruta original, nombre y ruta canónica, regla aplicada, nivel, estado, y columna **`DECISIÓN ADMIN`** para los de Nivel 3. Permite deshacer cualquier cambio y sirve de evidencia ante auditoría.

**Aceptación:**
- **No se pierde nada**: `archivos finales + duplicados eliminados + cuarentena = 7.333`.
- Todo código de local del repositorio existe en el maestro, o está en cuarentena.
- Ninguna corrección de aviso sin respaldo en `avisos_sap`.
- Muestra de 30 renombrados cotejada contra el contenido del PDF: el nombre canónico dice la verdad.

### T1.6b · Resolución de la cuarentena contra los planes de la administración
**Depende de:** T1.6 y T1.7. **Añadida el 2026-09-04 a pedido del cliente.**

T1.6 dejó **364 documentos en cuarentena** (Nivel 3) y **161 más sin ruta canónica** porque su nombre usaba otro patrón. La instrucción del cliente fue no dejarlos ahí: analizarlos a fondo, cruzarlos contra **cómo la administración terminó registrando esas órdenes en sus planes de trabajo**, corregirlos y guardarlos.

**Fuente nueva que habilita la tarea:** los planes de zona (2025 semanales y mensuales, 2026 mensuales) traen columnas `#OT INDUSTEC EVALUACIÓN` / `CIERRE` que citan el correlativo de cada orden junto al `LOCAL` y al `# OT` que la administración asignó a mano. `t1_6b_indice_planes.py` extrae ese índice: **7.777 referencias** de 59 libros.

**Criterio de decisión — cuatro señales independientes, por mayoría:**

| Señal | Qué aporta | Por qué es independiente |
|---|---|---|
| **P · Interior del PDF** | `Local:` y `Cliente:` impresos en el documento | Es lo que el técnico declaró en sitio; no depende del nombre del archivo |
| **S · SAP** | `avisos_sap.centro_coste` del aviso | Es el sistema de KFC: dice a qué local se factura |
| **A · Plan de la administración** | El `LOCAL` que ella asignó a mano | Es la decisión humana que el cliente pidió respetar |
| **N · Nombre del local** | Coincidencia del texto libre contra el nombre del maestro | Resuelve cuando el código está mal escrito pero la ubicación no |

Dos o más señales coincidentes → confianza ALTA. Una sola → MEDIA, marcada. Sin mayoría → **no se decide**: va a revisión humana (I-10). El PDF manda cuando el aviso resultó poco confiable — hay avisos reutilizados por el técnico en documentos de locales distintos, y entonces SAP y el plan apuntan al local equivocado.

**Casos particulares resueltos de forma explícita:**
- **Local compuesto `Kxxx-Hyyy`** (p. ej. `K073-H015`): no es un error de escritura, son **dos marcas en el mismo sitio** — KFC Miraflores y Heladería Miraflores. Manda el criterio de la administración; el centro de coste de SAP se conserva aparte para no perder el dato contable.
- **Preventivo sin aviso**: no es un defecto. El mantenimiento preventivo no nace de un aviso SAP, y el patrón canónico le exigía uno que nunca tiene. **Ese solo error de diseño explicaba 185 de las 364 cuarentenas.** El nombre canónico del preventivo sin aviso omite ese segmento: `OT-{corr}-{LOCAL}-D{n}-{ZONA}.pdf`.
- **Letra de marca en vez de la del maestro** (`J054` con cliente Juan Valdez → `V054EC`): se resuelve por cadena + número, solo si da un único local.
- **Aviso con ceros a la izquierda** (`000010279736` → `10279736`): normalización de Nivel 1.

**Aceptación (cumplida):** las cinco comprobaciones de `t1_6b_verificar_resolucion.py` pasan antes de mover un solo archivo — conservación del total, todo local resuelto existe en el maestro, coherencia con lo que T1.6 dedujo del nombre, **muestra aleatoria contrastada contra el texto crudo del PDF**, y ausencia de colisiones de contenido. La ejecución copia → verifica por hash → recién entonces retira la copia de trabajo; correrla dos veces no cambia nada.

**Lo que no se decide solo:** los casos sin mayoría quedan en `MANIFIESTO_CUARENTENA_RESUELTA.xlsx`, hoja *PENDIENTES DE DECISIÓN*, separados en dos causas que no son errores sino decisiones de negocio: **locales reales que faltan en el maestro de 95** y **trabajos para clientes fuera del Grupo KFC**.

### T1.7 · Ingesta a la base de datos
**Depende de:** T1.6.
Extraer campos de cada PDF por patrón (§3.2) y cargar por *upsert* sobre `id_industec`. Un PDF que falla no detiene el lote: va a cuarentena con el motivo. Importar los 6.451 avisos SAP y los 19 empleados.
**Aceptación:** ~7.200 órdenes en base; los conteos por zona cuadran con §3.1; la cuarentena está justificada archivo por archivo; **volver a correr la carga no crea duplicados**.

> **Es la primera vez que la operación de un año queda consultable en un solo lugar, y con el dato limpio.**

### ❌ T1.8 · CANCELADA — liberación del Drive
**Estado (2026-09-04): cancelada por directiva explícita del cliente**, no solo pospuesta. Al llegar a esta Puerta se pidió confirmación antes de ejecutar el borrado, y la respuesta fue no proceder; acto seguido se reforzó como política permanente: *"la información de google drive de industec actual no se toca ni modifica, solamente usa el espacio que tienes disponible en D:\"* y *"nunca podrás borrar información actual de industec sin que te lo pida o autorice"*.

**Lo que esto significa en la práctica:** `G:\Mi unidad` queda de solo lectura de forma indefinida — nunca se borra nada ahí, ni siquiera lo ya copiado y verificado por hash en `D:\RESPALDOS`. La secuencia del invariante I-2 se queda en "copiar → verificar", sin el paso de "borrar → vaciar papelera". El riesgo de que el Drive se llene (documentado en la sección de riesgos) se sigue vigilando, pero se resuelve por otra vía si llega a materializarse — nunca borrando del origen sin una autorización explícita y puntual en ese momento.

**Consecuencia arquitectónica:** `D:\RESPALDOS` deja de ser "temporal hasta el TrueNAS" y pasa a ser el almacenamiento definitivo del proyecto. Hay 328 GB libres en `D:`, más que suficientes.

### T1.9 · Respaldo en TrueNAS
**Depende de:** acceso físico al equipo, otorgado por INDUSTEC.

**Condición de desbloqueo:** César Basantes autoriza el acceso y fija fecha. Si no llega antes del cierre de Fase 1, se escala explícitamente en el cierre de mes en vez de quedar como pendiente silencioso (§10).

**Hardware definido por el cliente (2026-09-04):** el TrueNAS es un **equipo portátil dedicado**, con **dos discos en RAID 1** para los datos y una **memoria flash desde la que arranca el sistema** — el arranque separado de los datos permite reinstalar TrueNAS sin tocar el arreglo. Es el **respaldo secundario de todo**: tanto la información del año en curso como el histórico.

**Qué se respalda y desde dónde** (ver §5.1):
- La nube (Drive + OneDrive) guarda **solo el año en curso**, para consulta desde cualquier lado.
- `D:\RESPALDOS` guarda el **repositorio canónico completo**, incluido el histórico de años anteriores.
- El agente **replica ambos al TrueNAS**. Así todo dato existe en dos lugares independientes.

**Puesta en marcha, en dos tiempos** — como pidió el cliente:
1. **Primera carga manual, acompañada.** El cliente ejecuta la migración inicial con acompañamiento paso a paso. Sirve para que el equipo entienda el mecanismo antes de automatizarlo, no solo para mover los datos.
2. **Automatización posterior.** Recién con la primera carga verificada se programa la réplica periódica, con **Veeam Agent Community** (gratuito) para el respaldo continuo y volcado nocturno de la base con rotación 7 diarios / 4 semanales / 12 mensuales.

**Horizonte:** cuando el NAS se acerque a su capacidad, su contenido más antiguo se vuelca a discos externos o a la solución que se decida en ese momento. Esa decisión no se toma ahora.

**Aceptación:**
1. Mientras bloqueada: el respaldo cifrado de contingencia existe, está fechado, y una restauración de prueba de una carpeta al azar abre correctamente.
2. Una vez con acceso: verificación **por hash de contenido** (nunca por nombre o fecha) entre el origen y lo replicado en TrueNAS — mismo patrón que resolvió las colisiones de T1.6 (I-11) — reportando cualquier archivo con hash distinto o ausente. Una restauración real de un volcado a una base vacía reproduce los mismos conteos.
3. **Regla de las dos copias comprobada:** para una muestra aleatoria, cada archivo existe con el mismo hash en el disco local y en el NAS. Si un archivo vive en un solo lugar, la tarea no está cumplida.

### T1.10 · Agente 1 · Gestor de OTs
**Depende de:** T1.7.

**Archivo automático:** recoge informes del correo por IMAP y los deposita ordenados. Idempotente por `id_industec`; los errores de red reintentan con espera creciente.

**Auditor de calidad:** mantiene `SALIDAS IA\CALIDAD\OBSERVACIONES_OTS.xlsx` — una fila por hallazgo con orden, zona, técnico, regla, severidad, evidencia, estado y columna **`VEREDICTO ADMIN`** que la administración edita; en la corrida siguiente el agente la lee y aprende qué no es novedad.

Reglas iniciales, mapeadas a las dimensiones de §3.3: local fuera del maestro *(validez)* · zona cruzada *(consistencia)* · aviso inexistente o mal formado *(exactitud)* · orden sin fotos *(completitud)* · hora de fin anterior a la de inicio *(validez)* · correlativo duplicado *(unicidad)* · campos clave vacíos *(completitud)* · orden abierta más de 3 días *(oportunidad)*.

**Capa de IA:** una llamada por lote, nunca por orden. Revisa `actividades` y `repuestos` buscando descripciones sin contenido ("NINGUNO", "se revisó"), repuestos sin número de parte, diagnóstico que no explica la causa. Devuelve hallazgos **citando el texto**.

De ahí sale `GUIA_LLENADO_OTS.md`, insumo de la capacitación de cierre.

**Aceptación:** corre sobre las 1.916 órdenes de UIO; la administración revisa y marca falsos positivos — **objetivo <10%**.

### T1.11 · Agente 2 · Consolidador
**Depende de:** T1.7.

Actualiza el plan de cada zona **a las 07:00 y 14:00**. **No recrea el archivo: abre una copia del plan real como plantilla y rellena filas**, de modo que estilos, tabla, formato condicional y anchos quedan idénticos por construcción.

Las 22 columnas están mapeadas una a una. Lógica a replicar:
- Cada caso SAP tiene **dos fases** — evaluación (diagnóstico y solicitud de repuesto) y cierre (instalación) — que son dos órdenes distintas consolidadas en **una sola fila**.
- Convención literal `NINGUNO` en celdas sin dato.
- Semáforo por antigüedad, con las tres reglas exactas del original: abierta y ≥3 días, = 2 días, < 2 días.
- Vocabulario: `ESTATUS SAP` ∈ {IMPO, APRO, MSOL, NINGUNO} · `PRESUPUESTO` ∈ {PENDIENTE, APROBADO, NINGUNO} · `ESTATUS DEL EQUIPO` ∈ {OPERATIVO, DESHABILITADO}.

**Aprendizaje:** guarda snapshot, compara contra la versión que la administración corrigió, registra cada celda cambiada en `correcciones` y emite resumen semanal de patrones. Las reglas estables se promueven a código **tras revisión humana** — el generador nunca se automodifica.

**Aceptación (I-4):** genera a nombre nuevo, jamás sobre el archivo de la administración. Comparación celda a celda contra el plan llevado a mano: **≥95% idénticas** antes de que ella deje de hacerlo.

### T1.12 · Capacitación de cierre
Sesión sobre lo que quedó funcionando, dirigida a administración, jefatura técnica nacional y jefes de zona. Se apoya en `GUIA_LLENADO_OTS.md`.

**Aceptación (verificable, no "el personal entendió"):** checklist/quiz de 5-8 escenarios concretos tomados de las lecciones reales de Fase 1, con respuesta correcta documentada de antemano — p. ej. *"¿qué campo determina que una OT está cerrada?"* (`estatus_general` de SAP, nunca una señal interna) o *"¿qué hacer si el mismo documento aparece en dos carpetas?"* (no decidir solo: se compara por contenido y se documenta). Umbral mínimo de aciertos fijado antes de la sesión para considerarla completa.

---

# FASE 2 · AUTOMATIZACIÓN — Octubre

> **Cierra con:** tres reportes que hoy toman días, generándose en minutos y enviándose solos.

### T2.1 · Intervención al sistema de OTs
Aquí sí se toca: está contemplado en la cotización y el sistema es de desarrollo propio de INDUSTECH. Con la base ya operativa como red de seguridad, se descompone verticalmente en subtareas S/M — cada una una función completa de punta a punta, nunca "toda la capa de datos" seguida de "toda la capa de vista":

| # | Subtarea | Ataca | Verificación exacta |
|---|---|---|---|
| **T2.1.1** | Persistencia en base de datos en cada envío del formulario | Que el único registro sea un PDF que se purga a 90 días | Enviar una orden de prueba; consultar que la fila existe en `ots` con los mismos valores del PDF generado |
| **T2.1.2** | Selector de local desde el maestro, en vez de texto libre | Las 121 variantes y la zona cruzada, de raíz | Enviar con cada código de `locales`; ninguno debe poder escribirse fuera del maestro |
| **T2.1.3** | Validación del aviso (8 dígitos, contra avisos conocidos) | Los typos que rompen el cruce | Enviar un aviso de 7 y uno de 9 dígitos: ambos rechazados con mensaje claro al técnico |
| **T2.1.4** | Correos del local autocompletados desde el maestro | Rebotes por direcciones mal escritas | El correo de destino de una orden de prueba coincide con `locales.correo`, sin campo editable |
| **T2.1.5** | Bloqueo del contador (transacción con `SELECT ... FOR UPDATE` o `UNIQUE KEY` sobre el correlativo) | Números duplicados por envíos simultáneos | Disparar 10 envíos concurrentes de prueba; 10 correlativos distintos, cero colisiones |
| **T2.1.6** | Cola de reintento de correo (se integra con T2.3 en el punto de envío) | 82 fallos en que la orden no llegó a nadie | Un envío con SMTP caído a propósito: el job queda en `email_queue`, no se pierde |

**Regla no negociable de esta tarea — criterio único de cierre:** el campo canónico de estado de una OT es **`estatus_general`** (SAP, `CERRADO`/`TRATAMIENTO`/`ABIERTO`), el mismo que ya rige en la base de Fase 1. Cualquier señal interna del sistema (p. ej. existe una segunda orden de cierre) puede mostrarse como información complementaria, pero **nunca** como criterio de backlog, SLA o notificación — es el error real que sobre-contó el backlog 8× en T1.11 (I-10, I-12). Prueba de aceptación: para una muestra de OTs con estado SAP conocido, el sistema en producción reproduce ese estado exactamente.

**`UNIQUE KEY` obligatoria (I-9):** toda tabla nueva o modificada que reciba escritura automatizada (estado sincronizado con SAP, notificaciones) declara `UNIQUE KEY` sobre la clave de negocio real (número de OT + tipo de evento, nunca solo el id autoincremental), verificado con `SHOW CREATE TABLE` antes de dar la subtarea por completa.

**Protocolo de despliegue (I-8):** cada subtarea se despliega **primero en UIO**, se verifica 48 h, y solo entonces al resto. El formulario debe seguir viéndose y usándose igual para el técnico.

| Autónomo | Requiere aprobación humana | Prohibido |
|---|---|---|
| Escribir y probar cada subtarea en un entorno de prueba / zona piloto | Aplicar cualquier cambio de esquema sobre la base de **producción** real; desplegar una subtarea verificada de UIO al resto de zonas | Modificar el criterio de cierre para que dependa de algo distinto de `estatus_general`; desplegar sin las 48 h de verificación en UIO |

**Aceptación global de T2.1:** una orden de prueba genera el mismo PDF y los mismos correos que antes, y además queda en base con el criterio de cierre correcto.

### T2.2 · Agente 3 · Reporteador
Se descompone igual que T2.1 — cada bloque es una subtarea S/M verificable por separado:

- **T2.2.1 · Reporte diario:** órdenes abiertas, pendientes de atención, equipos deshabilitados y casos que superan las 48 horas, con la semaforización que INDUSTEC ya usa. Sale por correo cada mañana a los jefes de zona.
- **T2.2.2 · Indicadores mensuales** por zona, franquicia, local y tipo de equipo, con conclusiones y recomendaciones redactadas *(aquí sí interviene la IA)*.
- **T2.2.3 · Reporte para el Grupo KFC** en su formato de entrega.

**Definiciones de KPI resueltas en este documento — no a criterio del agente:**
- **SLA, dos relojes independientes**, con timestamps explícitos (`fecha_notificacion`/creación, `fecha_atencion`, `fecha_cierre_tecnico`): **Response Time SLA** (tiempo hasta la primera atención) y **Resolution/Completion SLA** (tiempo hasta el cierre), cada uno con metas por prioridad P1-P4 distinguiendo horario hábil de fuera de horario. Las metas concretas por prioridad quedan pendientes del anexo de niveles de servicio del contrato (§10); mientras no llegue, se usan como valor provisional los estándares del sector (Vixxo, GetMaintainX) y se marca explícitamente como provisional en el reporte.
- **Backlog** = horas-hombre estimadas de OTs aprobadas/programadas/en curso ÷ capacidad semanal real de técnicos de la zona, expresado en **semanas de trabajo pendiente**, nunca como conteo simple de tickets abiertos.
- **Schedule/PM Compliance** (cumplimiento del preventivo) es una métrica separada, con tolerancia del 10% sobre la periodicidad programada antes de contar como incumplimiento.
- MTTR, MTBF por familia de equipo, First-Time-Fix Rate, % correctivo/preventivo, disponibilidad por equipo crítico y satisfacción se mantienen como en la versión original de este plan.

**Checkpoint de verificación cruzada, obligatorio (I-10):** antes de insertar o publicar cualquier KPI calculado, comparar el conteo total de OTs/horas usado contra una fuente independiente (extracto SAP o el maestro de Fase 1); si no cuadra exactamente, abortar y dejar log del desfase para revisión humana — es el mismo patrón que detectó el bug de indexación de T1.5 antes de escribir nada.

**Cobertura de catálogo (I-12):** antes de que cualquier regla marque incumplimiento de SLA/backlog contra un catálogo de referencia (metas de SLA, calendario de turnos), declarar y loguear su rango de fechas/sedes real; lo que cae fuera se excluye del cálculo y se reporta aparte como "fuera de cobertura", nunca como incumplimiento.

**Protocolo openpyxl, obligatorio antes de tocar la plantilla real (`STATUS_PENDIENTES_SEMANA N.xlsx` y similares):**
1. Prueba de *round-trip*: abrir con openpyxl, guardar sin cambios, confirmar en Excel que **no** pide reparación. Es criterio de aceptación previo a escribir un solo dato.
2. Cargar con `load_workbook(ruta, keep_vba=True, keep_links=True, data_only=False)` en una sola llamada.
3. Escribir solo dentro de celdas/rangos de tablas (`ListObject`) existentes; nunca borrar y reconstruir una tabla con nombre ni el formato condicional.
4. Fijar `wb.calculation.fullCalcOnLoad = True` antes de guardar — openpyxl nunca calcula fórmulas por sí solo.
5. Detectar archivo bloqueado (abierto en Excel por la administración) y **abortar con mensaje claro**, nunca forzar la escritura.
6. Copia de respaldo de la plantilla antes de cada corrida.

| Autónomo | Requiere aprobación humana | Prohibido |
|---|---|---|
| Calcular y publicar los KPIs cuyo checkpoint de verificación cruzada cuadra | Fijar las metas de SLA definitivas por prioridad una vez llegue el anexo de KFC | Escribir sobre la plantilla real sin la prueba de *round-trip* previa; marcar incumplimiento fuera de la cobertura declarada del catálogo |

**Aceptación:** regenerar el `STATUS_PENDIENTES` de una semana real y cotejar los totales contra el hecho a mano.

### T2.3 · Envío automático
Rediseñado como cola persistente, no envío directo — PHPMailer no reintenta solo, y el hosting compartido no da webhooks de rebote:

- **T2.3.1 · Tabla `email_queue`** (`status`, `priority`, `attempts`, `next_attempt_at`, `locked_until`, más la `UNIQUE KEY` de abajo). El flujo web **solo inserta filas** dentro de la misma transacción que genera el reporte/orden — nunca llama a PHPMailer directamente.
- **T2.3.2 · Worker por cron cada 5-10 min** que reclama jobs de forma atómica: `UPDATE email_queue SET status='processing', locked_until=NOW()+INTERVAL 5 MINUTE WHERE status='pending' AND next_attempt_at<=NOW() LIMIT n`. **Verificar primero la versión de MariaDB del hosting** antes de asumir `SKIP LOCKED` (requiere 10.6+); si es menor, el `UPDATE` atómico de arriba basta sin él.
- **T2.3.3 · Clasificación de error SMTP y backoff:** leer `$mail->ErrorInfo` y clasificar 4xx (temporal → reintentar) vs 5xx (permanente → no reintentar, pasar a `dead_letter` directo). Backoff exponencial con techo: 5 min, 15 min, 1 h, 4 h, 24 h, máximo 6 intentos (~48 h) antes de `dead_letter`. Un job en `dead_letter` dispara alerta por un canal **distinto del correo** (Telegram/webhook).
- **T2.3.4 · Detección de rebotes:** buzón dedicado (`rebotes@industec.me` o similar) configurado como Return-Path; cron cada 15-30 min hace polling IMAP, parsea los DSN (RFC 1894) y alimenta `email_suppression_list`. Confirmar que SPF incluye a Titan, que DKIM firma los envíos y que existe registro DMARC (aunque sea `p=none` inicialmente).

**`UNIQUE KEY` obligatoria (I-9):** sobre (`tipo_evento`, `referencia_ot_o_reporte`, `destinatario`, `fecha`) en `email_queue`, con `INSERT ... ON DUPLICATE KEY UPDATE` — un reintento del proceso que genera el correo nunca debe crear un job duplicado.

**Cuidado con el tope de Titan:** 1.000/día por buzón; las alertas se agrupan en vez de emitir una por evento.

| Autónomo | Requiere aprobación humana | Prohibido |
|---|---|---|
| Reintentar 4xx con backoff; mover 5xx a `dead_letter` | Cambiar el buzón de envío o el de rebotes; ajustar el techo de reintentos | Reintentar un rebote 5xx (daña la reputación del dominio); silenciar una alerta de `dead_letter` sin registrarla |

**Aceptación:** un fallo de envío reintenta según su clasificación y queda registrado; ningún correo se pierde en silencio; un rebote real termina en `email_suppression_list` sin intervención manual.

### T2.4 · Capacitación de cierre

---

### T2.5 – T2.11 · Ejecutadas — el detalle NO se repite aquí

Entre el 2026-09-06 y el 2026-09-10 se ejecutaron siete tareas que este plan no
tenía numeradas cuando se escribió: catálogos del formulario, lector del buzón
SAP, cronograma de preventivos, padrón de técnicos, vigilante IMAP en vivo,
despliegue por SSH y cruce de informes de OT.

**Están documentadas, con sus cifras verificadas, en [`ESTADO.md`](ESTADO.md)
§1b.** No se copian aquí a propósito: dos versiones del mismo hecho se separan,
y la que se queda atrás es la que alguien lee. El plan dice qué construir y con
qué criterio; `ESTADO.md` dice qué se construyó y qué cifra dio.

---

### T2.12 · Puesta en marcha de las interfaces por rol

**Lo construido está listo y sin desplegar.** El 2026-09-09/10 se rediseñaron
las trece pantallas del sistema nuevo, se construyó la aplicación móvil del
técnico con captura sin señal, el control de las 48 horas de los equipos
deshabilitados, las novedades del preventivo y el tablero gráfico. Reseña
completa: [`SALIDAS IA\OTS\REDISENO_INTERFACES.md`](SALIDAS%20IA/OTS/REDISENO_INTERFACES.md).

Esta tarea es **solo la puesta en marcha**: aplicar, desplegar y verificar.
Nada de escribir código nuevo — si al verificar aparece un defecto, se corrige,
pero el alcance no es agregar funciones.

**Por qué se descompone así:** cada subtarea es una compuerta. La siguiente no
empieza hasta que la anterior pegó su evidencia. El orden no es una preferencia:
sin la migración no hay tablas, sin tablas la verificación de alcance no puede
correr, y sin verificar el alcance no se despliega algo que muestra datos de un
cliente.

| # | Subtarea | Qué ataca | Verificación exacta |
|---|---|---|---|
| **T2.12.1** | 🚦 Aplicar `sql/007_pendientes_y_captura.sql` | Sin las tablas, el control de 48 h, las novedades y la recepción de órdenes no existen | Correr **los dos bloques de verificación** del propio archivo y **pegar su salida literal**: las 7 consultas numeradas del pie, y el bloque aparte «VERIFICACION de las novedades» que está antes (fácil de saltar, porque no está al final). Las tres que no se negocian: `SHOW CREATE TABLE pendientes\G` muestra `UNIQUE KEY uq_pendiente (aviso, activo_fijo)`; `SHOW CREATE TABLE ot_capturadas\G` muestra `UNIQUE KEY uq_captura_envio (envio_uuid)`; `SHOW COLUMNS FROM casos_gestion LIKE 'estado'` termina en `,'ESPERA_REPUESTO')` |
| **T2.12.2** | Idempotencia de la migración | Una segunda corrida que duplique deja el tablero contando doble | Correr la 007 **dos veces seguidas**. `SELECT COUNT(*) FROM permisos WHERE codigo LIKE 'repuestos%' OR codigo LIKE 'novedades%'` → 7 en las dos corridas. Y la prueba de inserción doble del pie del archivo → `COUNT(*) = 1` |
| **T2.12.3** | Desplegar a **UIO solamente** (I-8) | Un defecto en tres zonas a la vez | `t2_10_desplegar.py`, que verifica por hash. Abrir las 13 pantallas con un usuario de cada rol y **que ninguna dé error de PHP ni quede en blanco**. ⚠️ **Corregido el 2026-09-10:** hay **un solo sitio para las tres zonas**, así que «desplegar solo a UIO» no existe: el piloto se hace dejando activas solo las cuentas de UIO (baja temporal de las de LARB y CNLJ desde `usuarios.php`, con bitácora) o con una compuerta `zonas_habilitadas` en `nucleo/config.php`. **La vía la elige Andrés** antes de este paso |
| **T2.12.4** | 🚦 Verificar el alcance por zona **con los tres roles** | Que un jefe de zona vea datos de otra zona. Es la comprobación más importante de toda la tarea | Entrar como administradora, jefe de UIO y jefe de CNLJ y **contar filas** en `casos.php`, `pendientes.php`, `novedades_visita.php`, `ordenes.php`, `reportes.php` y `cronograma.html`. Las cifras de los dos jefes deben sumar sin solaparse y ser menores que la de la administradora. Pedir otra zona por la URL (`?zona=CNLJ` siendo jefe de UIO) → **0 filas y ninguna fuga** |
| **T2.12.5** | Verificar el alcance con un **POST fabricado a mano** | Que esconder un botón se confunda con proteger | `curl` un POST a `pendientes.php` con `pendiente_id` de otra zona y a `novedades_visita.php` con `novedad_id` de otra zona, con la cookie de un jefe de zona → **rechazado en el servidor**, y la fila queda en `bitacora` con `exito = 0` |
| **T2.12.6** | Verificar los dos extremos que se cerraron el 10-sep | Que un despliegue los reabra por descuido | `curl` sin cookie a `catalogos.php` y a `cronograma.php` → **401 en JSON**, no un catálogo (**comprobado el 2026-09-10 tras subirlos**). Con cookie de jefe de zona a `cronograma.php` → solo su zona. **Con cookie de un técnico** a `catalogos.php` → `avisos` = exactamente sus casos asignados; ninguna prueba de T2.12 entraba como técnico |
| **T2.12.7** | 🚦 Prueba de captura **sin señal**, de punta a punta | Que una orden se pierda, o que llegue dos veces | **Apagar el servidor de verdad** — no cortar la red con el depurador, que no reproduce el caso: el trabajador de servicio tiene su propio contexto de red. Abrir el formulario, llenar una orden completa, comprobar que la cola dice «guardada, esperando señal». Encender el servidor. La orden sale sola y **`SELECT COUNT(*) FROM ot_capturadas WHERE envio_uuid = '<el uuid>'` da 1**, no 2 |
| **T2.12.8** | Reintento con sesión caducada | Que un 401 marque como inválida una orden que está perfecta | Con órdenes en la cola, cerrar la sesión desde otro navegador (sesión única). El siguiente intento debe dejarlas en «falta entrar», **no** en «rechazada». Volver a entrar → salen solas |
| **T2.12.9** | Verificar el reloj de 48 h contra datos reales | Que la cifra del globo de navegación y la de la pantalla discrepen | Abrir un pendiente con equipo deshabilitado. Comprobar que el globo de «Repuestos y equipos», la tarjeta «Fuera de plazo» y la lista filtrada con `?g=vencidos` **dan el mismo número**. Contrastar con la consulta 6 del pie de la 007 |
| **T2.12.10** | Verificar el ciclo completo de un caso | Que la reconciliación pise un estado que puso una persona | Recorrer un caso de prueba: asignar → el técnico lo deja trabado → veredicto → avanzar la vía → resolver. Después correr `reconciliar_cli.php` y comprobar con la consulta 5 del pie de la 007 que **da 0 filas** |
| **T2.12.11** | Esperar **48 horas** y extender a LARB y CNLJ | Desplegar a las tres zonas un defecto que se ve al segundo día | Que en 48 h no haya entrado ninguna incidencia del personal de UIO. Recién entonces `t2_10_desplegar.py` al resto |
| **T2.12.12** | Capacitación mínima, **una hoja por rol** | Que la interfaz nueva se aprenda por prueba y error delante del cliente | Tres hojas en `SALIDAS IA\OTS\`: técnico (bandeja, cómo se llena sin señal, cómo se insiste), jefe de zona (repartir, los cuatro veredictos, el reloj), administración (confirmar cierre en SAP, novedades, reportes). Cada una con capturas del sistema ya desplegado |

**La regla de negocio que esta tarea pone a funcionar**, y contra la que se mide
todo lo de arriba: *una intervención concluye el trabajo; el único motivo válido
para no concluir es que el equipo dependa de una pieza o de un tercero; y si un
equipo queda deshabilitado hay **48 horas** para dar uno de cuatro veredictos —
repuesto, reparación, garantía o baja.* El plazo mide **la decisión**, no la
reparación completa: una garantía puede tardar semanas sin que sea
incumplimiento. Lo que no puede pasar es que a las 72 horas nadie haya decidido.

**Lo que ya está verificado y no hay que repetir** (evidencia en
`REDISENO_INTERFACES.md` §13): `php -l` 38/38, `node --check` 9/9, balance de
etiquetas 13/13, y tres suites de prueba que se corren solas —
`pruebas/prueba_48h.php` (96 comprobaciones), `pruebas/prueba_graficos.mjs`
(62) y `pruebas/prueba_contratos.mjs` (48), **206 en total, 0 fallos**. Antes de
tocar nada, correrlas: si alguna falla, algo se movió.

```bash
cd "D:\INDUSTECH IA\desarrollo\sistema_ots\app\pruebas"
D:/SOFTWARE/PHP83/php.exe prueba_48h.php     # 96 · 0
node prueba_graficos.mjs                      # 62 · 0
node prueba_contratos.mjs                     # 48 · 0
```

**Lo que NO cubre ninguna de esas pruebas, y por eso existe esta tarea:** nada
se abrió nunca contra la base real. Todo lo verificado es sintaxis, estructura y
lógica pura. Ninguna prueba demuestra que una consulta devuelva lo que se
espera, ni que el filtro de zona filtre.

| Autónomo | Requiere aprobación humana | Prohibido |
|---|---|---|
| Correr las tres suites de prueba; leer el código; preparar el paquete de despliegue; escribir las hojas de capacitación; corregir un defecto que aparezca al verificar | **Aplicar la 007** (cambia el esquema); **desplegar a UIO**; **extender a LARB y CNLJ** después de las 48 h | Desplegar a las tres zonas de una vez; extender antes de las 48 h; dar por buena T2.12.4 sin haber contado filas con los tres roles; borrar o vaciar cualquier tabla del cliente; tocar `nucleo/config.php` en el servidor |

**Aceptación global de T2.12:** las trece pantallas abren con los cuatro roles
sin error; los dos jefes de zona no ven ni una fila de la otra zona, ni por
pantalla ni por URL ni por POST; una orden llenada con el servidor apagado llega
una sola vez al encenderlo; y las tres cifras del reloj de 48 h coinciden entre
sí y con la consulta de la migración.

**Si algo de esto no cuadra, se detiene y se documenta la discrepancia.** No se
sigue con una advertencia (I-10). Y si un dato real contradice lo que dice este
plan, **gana el dato**: se anota y se consulta, no se improvisa una
interpretación.

---

### T2.13 · La app del técnico que pidió Andrés (2026-09-10)

Pedido literal en `entrada/desarrollador/INDUSTEC/REQUERIMIENTO.md` del repo de agentes:
que el técnico no pueda equivocarse en lo que el sistema ya sabe. Estado de cada
requisito después de la auditoría del 2026-09-10 (código local, sin desplegar salvo
lo que se dice):

| # | Requisito | Estado | Qué falta |
|---|---|---|---|
| 1 | El aviso SAP se pone solo, porque el caso ya fue asignado | ✅ en código | Desplegar. «Emitir la orden de este caso» precarga y bloquea el aviso; el combo trae solo lo asignado |
| 2 | El nombre del técnico se pone solo | ✅ en código | Desplegar. El servidor pone primero a quien tiene la sesión, diga lo que diga el celular |
| 3 | Solo ve sus casos | ✅ en el servidor desde el 10-sep (`catalogos.php`, `cronograma.php`) y en código (`mis.php`, pendientes) | T2.13.2: que el combo no ofrezca casos ya atendidos o cerrados |
| 4 | Historial de lo que atendió | ⚠️ parcial: «Atendidas» depende de la ventana de 90 días del buzón | T2.13.3 |
| 5 | Cronogramas | ⚠️ parcial: ve el de su zona, con la barra de escritorio | T2.13.4. «Mis preventivos» exige registrar quién va a cada ingreso (decisión 5) |
| 6 | Buzón de alertas y notificaciones internas | ❌ | T2.13.5. Comunicados escritos por personas, solo si Andrés los quiere (decisión 3) |

**Lo que decide si esto se usa (decisión 1 de Andrés).** Hoy la app nueva guarda la
orden, pero no genera el PDF ni manda el correo, y no lleva fotos ni la imagen de la
firma. Mientras sea así, el técnico sigue en el formulario de producción y los
requisitos 1 y 2 no le llegan. Dos caminos: **(a)** terminar la emisión en la app
nueva — migración nueva `app/sql/008` con correlativos y cola de correo, y fotos y
firma como dato —; **(b)** un puente: que «Emitir» abra el formulario de producción
con el aviso y el técnico precargados. **Andrés eligió (a) el 2026-09-10**: la emisión
se termina en la app nueva y producción no se toca.

| # | Subtarea | Verificación exacta |
|---|---|---|
| **T2.13.0** | 🚦 Personas y casos de prueba (**requiere aprobación**) | Crear `tec_prueba_uio_a`, `tec_prueba_uio_b` y un jefe de prueba, y 3 avisos sintéticos `9999xxxx` con su limpieza al final. `SELECT COUNT(*) FROM usuarios WHERE usuario LIKE 'tec_prueba%'` = 2. Sin esto ningún criterio de abajo se puede correr: asignar o cerrar casos reales para probar está prohibido |
| **T2.13.1** | Probar en el servidor lo corregido el 10-sep (tras T2.12.1 y el despliegue) | Con la cookie de `tec_prueba_uio_a`: `catalogos.php` → sus avisos y ninguno más; POST a `envio.php` con una orden válida → 200 y `SELECT COUNT(*) FROM ot_capturadas WHERE envio_uuid='<uuid>'` = 1; el mismo POST otra vez → sigue en 1; el mismo cuerpo con `usuario_captura` de B → 409 |
| **T2.13.2** | El combo no ofrece casos ya atendidos o cerrados | `catalogos.php`, solo para TECNICO: casos en ASIGNADO o ESPERA_REPUESTO. Given un aviso sintético asignado a A, When pasa a ATENDIDO, Then desaparece del `curl` de `catalogos.php` de A. Coordinar con quien tenga `catalogos.php` en curso |
| **T2.13.3** | Historial desde la base, no desde la ventana de 90 días | «Atendidas» = `casos_gestion` (asignado a mí, ATENDIDO/RESUELTO/NO_COMPETE) ∪ `ot_capturadas` (mías); el PDF sale de `casos_gestion.ot_cierre` y, si falta, se dice (I-7). Enlace «Historial» en la barra del técnico. Criterio: un aviso sintético ATENDIDO que no está en `casos_sap.json` aparece con «sin dato en el catálogo» |
| **T2.13.4** | Cronograma móvil del técnico | `cronograma.php` deja de mandar `tecnicos` (la pantalla no lo usa) y `locales` al técnico; `cronograma.html` le pinta la barra móvil. Criterio: `curl -b a.txt $B/cronograma.php` → sin clave `tecnicos` y `locales` vacío; la barra se prueba con una función pura en `prueba_contratos.mjs` |
| **T2.13.5** | Buzón de avisos del técnico, **sin tabla nueva** | `novedades.php` (rama TECNICO) devuelve total y versión propios desde lo que ya se registra: asignaciones (`casos_gestion.asignado_en`), respuestas y veredictos del hilo (`pendiente_notas`), veredictos de sus novedades y «te quitaron el caso» (bitácora). «Visto hasta» = su último `CONSULTAR bandeja` en la bitácora. Pestaña «Avisos» en `mis.php` con globo. Criterio: el jefe de prueba asigna un aviso sintético a A → total de A = 1 y de B = 0; A abre la bandeja → total 0 |
| **T2.13.6** | Comunicados de zona (**solo si Andrés los aprueba**) | Tabla `comunicados` con `UNIQUE (comunicado_uuid)`, zona forzada en el servidor y «visto por X de Y». Condicionado a que el piloto muestre uso real: un comunicado que nadie abre le hace creer al jefe que avisó |

| Autónomo | Requiere aprobación humana | Prohibido |
|---|---|---|
| Código local, pruebas locales, `php -l` por SSH, `SELECT` de verificación en el sitio de pruebas | T2.13.0; aplicar migraciones; desplegar; T2.13.6 | Tocar producción; asignar o cerrar casos reales para probar; servir datos sin sesión; WhatsApp o servicios de pago; editar `casos.php`, `ordenes.php`, `Ui.php`, `estilo.css` o `busqueda.js` sin coordinar con su conversación |

**Decisiones de Andrés que esta tarea necesita:** (1) ~~cómo salen el PDF y el correo~~
→ **(a), decidida el 2026-09-10**; (2) ~~aprobar la 007~~ → **la aplica la estación
después de fusionar la rama** (T2.12.1); (3) notificaciones: solo automáticas, o también
comunicados escritos; (4) el arranque con personas en UIO — 14 de 16 técnicos y 2 de
los 3 jefes nunca entraron (medido la noche del 2026-09-10), y la bandeja solo se llena si el jefe asigna en el sistema;
(5) cronograma por técnico: `t2_7` no genera quién va a cada ingreso.

---

# FASE 3 · DECISIÓN — Noviembre

> **Cierra con:** tablero de decisión y equipo capaz de operarlo por su cuenta.

### T3.1 · Agente 4 · Consultor de Gerencia
Bot de Telegram con long polling desde la estación. Para alguien sin conocimientos informáticos, desde el celular:
- Botones y menús además de texto libre: `/pendientes`, `/local K121`, `/caso 10346666`, `/kpis`, `/zona UIO`.
- Preguntas en lenguaje natural traducidas a consultas **de solo lectura** sobre un esquema acotado, con plantillas verificadas para lo frecuente.
- Respuestas cortas, con el PDF adjunto cuando aplica.
- **Cada respuesta cita las órdenes y avisos en que se basa (I-7).**
- Guarda pregunta, respuesta y corrección de la Gerencia; las correcciones afinan plantillas y estilo.
- Alertas proactivas: órdenes que cruzan 3 días, equipos deshabilitados más de 48 h, preventivos vencidos.
- Acceso restringido por lista blanca de identificadores de chat.

**Guardrails de credencial, no negociables (I-13) — un bot de lenguaje natural a SQL sobre la base de producción, operado por alguien sin criterio técnico para detectar una consulta peligrosa:**
1. Usuario MariaDB dedicado, con privilegio **únicamente `SELECT`** (nunca reutilizar el usuario de la aplicación principal), y `max_statement_time` de 2-5 s.
2. `LIMIT` razonable aplicado siempre a cualquier consulta generada.
3. Validar la consulta SQL generada por el LLM antes de ejecutarla: rechazar cualquier texto que no empiece con `SELECT` o que referencie una tabla fuera de una lista blanca explícita.
4. Exponer al LLM solo el esquema de las tablas relevantes (*schema contract*), nunca la base completa.

**Consistencia con T2.1:** toda respuesta sobre el estado de una OT lee el mismo campo canónico (`estatus_general` de SAP), nunca una señal interna alternativa. Caso de prueba: para una muestra de OTs conocidas, la respuesta del bot coincide con el estado SAP.

**Disponibilidad 24/7:**
- Librería madura (`python-telegram-bot` o `aiogram`) con `timeout=30` en long polling; el manejo de *offset* lo hace la librería, no código propio.
- **Una única instancia corriendo** — dos instancias con el mismo token causan `409 Conflict` y pérdida o duplicación de mensajes. Detener la instancia de producción antes de cualquier prueba manual.
- Ante `429`, respetar literalmente `retry_after` con backoff exponencial hasta 60 s.
- Error-handler global con `RotatingFileHandler` que capture toda excepción sin tumbar el proceso.
- **Instalado como Servicio de Windows con NSSM** (no Tarea Programada — es un proceso de larga duración, no una corrida periódica), con reinicio automático ante fallo (`AppExit=Restart`) y `AppRestartDelay` de 5-15 s. `powercfg` sin suspensión ni hibernación en el PC dedicado.

| Autónomo | Requiere aprobación humana | Prohibido |
|---|---|---|
| Responder preguntas de solo lectura dentro del *schema contract*; enviar alertas proactivas ya definidas | Ampliar la lista blanca de tablas o chats autorizados | Ejecutar cualquier SQL que no sea `SELECT`; que el bot escriba en la base o dispare una acción real sobre el sistema de OTs |

**Aceptación:** 20 preguntas reales de la Gerencia. Prueba de humo: *"¿qué pasó con el caso 10346666?"* devuelve equipo, técnico, repuesto pedido y estado. Ante un dato inexistente, responde que no lo tiene.

### T3.2 · Agente 5 · Analista de confiabilidad
- **Recurrencia de fallas** por local y tipo de equipo: qué se repite, dónde y cada cuánto.
- **Consumo y costo de repuestos**, aprovechando que el campo ya trae números de parte.
- **Alertas de equipos candidatos a repotenciación.**

**Score de repotenciación — función explícita de 4 variables, resuelta en este documento, no a criterio del agente:**
1. **Frecuencia (filtro *bad-actor*):** ≥3 fallas en 24 meses, o ≥2 fallas en 6 meses.
2. **Umbral financiero:** costo de una reparación ≥50% del costo de reemplazo, **o** costo acumulado en 12 meses ≥75% del costo de reemplazo, **o** ratio costo de mantenimiento anual / RAV (valor de reemplazo del activo) >5% (alarma crítica >20%).
3. **Score de criticidad ponderado**, calibrado a contexto QSR: impacto en ventas/operación 35-40%, seguridad alimentaria 25-30%, costo de mantenimiento 15-20%.
4. **Persistencia obligatoria:** el costo debe sostenerse en al menos **2 periodos consecutivos** (p. ej. trimestres) antes de marcar "candidato" — evita marcar por una racha aislada.

Un equipo es candidato solo si cumple (1) y (2), ponderado por (3), y confirmado por (4).

**Checkpoint de verificación cruzada (I-10) y cobertura (I-12), antes de escribir cualquier resultado:** declarar y loguear el rango de fechas real cubierto por el historial de fallas usado por equipo; comparar el conteo de fallas por activo calculado contra un conteo manual de una muestra de 10 activos extraído directamente del sistema de OTs, y abortar si no cuadra exactamente.

**`UNIQUE KEY` obligatoria (I-9):** sobre (`activo_id`, `periodo`) en la tabla de scores, con upsert explícito — verificado corriendo el cálculo dos veces sobre el mismo periodo y confirmando que el número de filas no cambia.

**Mejora de segunda iteración, no bloqueante para el MVP:** proyección lineal simple de la pendiente de costo de reparación trimestral por activo hacia el punto de cruce con el costo de reemplazo, expuesta como "mes de cruce proyectado" junto al score estático.

| Autónomo | Requiere aprobación humana | Prohibido |
|---|---|---|
| Calcular el score y generar la lista de candidatos verificada | Recomendar formalmente a KFC el reemplazo de un equipo específico | Marcar "candidato" sin que se cumpla la persistencia de 2 periodos; escribir el score sin el checkpoint de cobertura |

### T3.3 · Entrega y continuidad
- **Manual de operación**: qué corre, cuándo, qué produce, dónde queda, qué hacer si falla.
- **Plan de continuidad**: cómo reconstruir la estación en otro equipo desde el repositorio y un volcado, y a quién acudir.
- **Capacitación final.**

**Sección obligatoria — "Errores conocidos y su patrón de prevención".** Convierte las 16 lecciones reales de T1.1-T1.11 en checklist verificable para quien mantenga el sistema después, entre otras:
- ¿El regex de extracción usa `[ \t]*` (no `\s*`) y `:` obligatorio entre etiqueta y valor? → si no, riesgo de fuga entre campos; probar con casos de campo vacío.
- ¿La tabla con *upsert* tiene `UNIQUE KEY` sobre su clave de negocio real? → verificar con `SHOW CREATE TABLE` antes de cerrar la tarea.
- ¿El criterio de "cerrado" usa `estatus_general` de SAP, o se coló una señal interna? → revisar antes de publicar cualquier KPI o backlog.
- ¿Una colisión de clave de negocio se resolvió por hash de contenido, o por "el primero que llegó"? → repetir el patrón de T1.6.
- ¿El catálogo de referencia declaró su cobertura temporal antes de marcar incumplimientos? → si no, revisar falsos positivos.

**Confiabilidad 24/7 del Programador de tareas — criterio de aceptación de T3.3, no solo recomendación:**
1. Cada tarea configurada con *"If the task fails, restart every 1-5 min"* (hasta 3 intentos) y *"Do not start a new instance"* si ya está corriendo, más un `FileLock` a nivel de script como segunda barrera.
2. Heartbeat externo (Healthchecks.io, plan gratuito): ping HTTP al final de cada ejecución exitosa, ping de fallo inmediato ante excepción capturada.
3. `powercfg` sin suspensión/hibernación/apagado de disco; *Active Hours* de Windows Update cubriendo la ventana operativa; BIOS con *Restore on AC Power Loss = Power On*.
4. `RotatingFileHandler` en todo script, y código de salida distinto de 0 ante fallo no capturado.

**Patrón estándar de verificación, documentado para reuso:** comparación **celda a celda por clave de negocio** (nunca por posición de fila) contra el artefacto llevado a mano, distinguiendo explícitamente diferencia de estilo (corrección fácil) de limitación real de datos (requiere acceso a un sistema en vivo) — así se validó T1.11.

**Aceptación:** una persona de INDUSTEC ejecuta una corrida completa siguiendo solo el manual, sin ayuda, y responde correctamente el checklist de errores conocidos.

---

## 7. Fuera del alcance contratado

Dos frentes de valor real que **no están en la C26-115**:

| Frente | Qué sería | Sustento |
|---|---|---|
| **Rediseño web + portal de técnicos** | Sitio propio en código con la identidad de INDUSTEC, y acceso por rol para técnicos, jefes de zona, administración y KFC | `industec.me` está en el Website Builder, sin PHP ni base de datos, sin tocar desde febrero. `/ordenes-de-trabajo` está publicada y **vacía**. Hoy los PDFs son descargables sin autenticación, con nombres predecibles |
| **Gestión documental** | Correlativos automáticos, generación desde plantilla y repositorio con búsqueda para actas, proformas, guías, cartas y certificados | El control de proformas es un Excel de 412 filas con correlativos reservados pero casi todos vacíos; las actas se llevan a mano en `.docx` y `.pdf` duplicados; `Oficina Industec` es un cajón de 151 archivos sueltos |

**Es decisión del cliente**: la cotización prevé que las horas no consumidas se acumulan y pueden destinarse a otros requerimientos. Pueden salir de esa bolsa, cotizarse aparte, o quedar como fase 4. Mientras tanto el Agente 6 avanza lo transversal.

---

## 8. Seguridad

Inventario de **32 hallazgos, 10 críticos**: contraseña SMTP en texto plano en 5 archivos; `phpinfo.php` y una firma manuscrita de cliente (`debug_firma.txt`) públicos; **1.952 PDFs con firmas, correos y fotos de interiores descargables sin autenticación** con nombres predecibles.

**Decisión del cliente: se difieren.** El sistema lleva un año así y unas semanas más no cambian el cuadro; se cierran en bloque en el corte al dominio definitivo. Se respeta, con una salvedad por escrito: **la contraseña SMTP viajó sin cifrar a esta estación** al descargar el sistema, así que su rotación conviene que sea el primer paso de ese corte, no el último. El inventario queda como lista de verificación.

---

## 9. Riesgos

| Riesgo | Mitigación |
|---|---|
| **Borrado accidental de material de la empresa** | El más serio del proyecto. Invariante I-2 (copiar → verificar, **nunca borrar** el origen en Drive — T1.8 quedó cancelada, no solo pospuesta) y la Puerta T1.3. El Drive es de solo lectura indefinida; `D:\RESPALDOS` es el almacenamiento definitivo |
| **Límites de Claude Pro** | Riesgo declarado en la cotización. Lo repetitivo es código determinista, no IA — razón de fondo de §0 |
| **Calidad de la información base** | La Fase 1 la sanea y el auditor la vigila; la capacitación refuerza el criterio de llenado |
| **Drive se llena** | Consolidación a `D:\RESPALDOS` y luego TrueNAS |
| Tocar producción rompe la operación | No se toca en Fase 1. En Fase 2, zona por zona con 48 h de verificación |
| La administración pierde control de sus archivos | I-4: nunca se sobrescriben los suyos |
| Estación única como punto de falla | TrueNAS + Veeam, repositorio versionado, plan de continuidad en T3.3 |
| Cambia el formato del export de SAP | El parser valida encabezados y **falla ruidosamente** en vez de importar basura |
| Disponibilidad del personal para capacitación | Se agenda con anticipación al cierre de cada fase |

---

## 10. Pendientes del cliente

| Pendiente | Bloquea |
|---|---|
| Contraseña del buzón Titan + "acceso de aplicaciones de terceros" | T1.10 (ingesta por IMAP). El resto de la Fase 1 avanza sin esto |
| Acceso al TrueNAS y sus discos | T1.9 |
| Punto de red y ubicación permanente de la estación | Operación continua |
| **Anexo de niveles de servicio del contrato con KFC** | Define los KPIs reales de T2.2. Mientras no llegue, se usan los estándares del sector |
| 10-20 correos de muestra de avisos SAP | Parser de ingesta |
| **Decisiones sobre los casos de Nivel 3** | Se entregan en el manifiesto; no bloquean el resto |
| Confirmación del stack a instalar (I-1) | T1.4 |

---

## 11. Orden de arranque

1. **T1.1** Inventario con hash.
2. **T1.2** Copia a `D:\RESPALDOS`.
3. **🚦 T1.3** Puerta de verificación.
4. **T1.4** Stack — previa autorización pieza por pieza.
5. **T1.5** Esquema y maestro de locales.
6. **T1.6** Saneamiento canónico.
7. **T1.7** Ingesta a base de datos.
8. ~~**🚦 T1.8** Puerta: liberación del Drive.~~ **Cancelada** — ver arriba. El Drive queda intocable de forma indefinida.

Las tareas T1.9 a T1.11 avanzan en paralelo conforme se desbloqueen sus dependencias. T1.9 sigue bloqueada por falta de acceso al TrueNAS; T1.10 y T1.11 avanzan sin depender de Drive ni del TrueNAS.

---

## 11b. Arranque para una conversación nueva — al 2026-09-10

> Esta sección existe para que quien abra una conversación nueva pueda **empezar
> a ejecutar sin preguntar nada**. Se actualiza cada vez que cambia lo que sigue.

### Qué leer, y en qué orden

| Orden | Documento | Para qué |
|---|---|---|
| 1 | [`ESTADO.md`](ESTADO.md) §1 y §1b | Qué funciona hoy, con su cifra verificada |
| 2 | [`ESTADO.md`](ESTADO.md) §5.1 | **Anótate ahí antes de tocar nada.** Si la tabla tiene filas, hay otra conversación trabajando |
| 3 | Este plan, **T2.12** | La tarea que sigue, con su criterio de aceptación y su tabla de permisos |
| 4 | [`SALIDAS IA\OTS\REDISENO_INTERFACES.md`](SALIDAS%20IA/OTS/REDISENO_INTERFACES.md) | Qué se construyó y **qué se rompió al construirlo** (§9b: cuatro defectos y por qué fallaban en silencio) |

No hace falta leer el resto del repositorio. Y la skill **`industec-invariantes`**
se invoca siempre al empezar, antes de la primera línea.

### Dónde está parado el proyecto, en un párrafo

La Fase 1 está cerrada: 7.069 órdenes en el árbol canónico y en la base. La
Fase 2 está avanzada: el buzón de casos opera de verdad (asignar, derivar,
veredicto, cierre de dos manos), el vigilante IMAP trae los casos en segundos,
el despliegue va por SSH con verificación por hash, y el 2026-09-10 se terminó
el rediseño de las trece pantallas más la aplicación móvil del técnico con
captura sin señal, el control de las 48 horas y el tablero gráfico. **Todo eso
está escrito, probado en local y sin desplegar.**

### La siguiente acción, concreta

**0. Fusionar en la estación la rama `pc/auditoria-2026-09-10`** del remoto privado
(`git fetch origin && git merge origin/pc/auditoria-2026-09-10`; si `git merge` se niega
por cambios locales en `ESTADO.md` u otro archivo, se apartan antes con
`git stash push -- <archivo>` y se devuelven con `git stash pop`) y **reiniciar la tarea
programada del vigilante**, que así toma el IDLE de 9 minutos. Esa rama trae las
correcciones de la auditoría del 2026-09-10 ([`AUDITORIA_2026-09-10.md`](AUDITORIA_2026-09-10.md)):
desplegar desde la estación sin fusionarla pisa en el servidor lo que ya se corrigió: desde
el 2026-09-10 el sitio de pruebas tiene `sw.js` v4, `pdf.php`, `nucleo/Reconciliar.php`,
`nucleo/Auth.php` y los dos `.htaccess` de esa rama.

**T2.12.1 — aplicar la migración 007.** Requiere aprobación de Andrés porque
cambia el esquema: pídesela antes de correr nada. Desde el 2026-09-10 la 007 trae
además `pendientes.plazo_desde` y la nota `DIAGNOSTICO`, y `verificar_esquema.php`
ya sabe comprobarla: después de aplicarla tiene que decir «permisos (con la 007)»
26/25/17/9 y terminar en «TODO OK».

El archivo y el comando exactos, que es lo que faltaba escribir:

```
Archivo:  D:\INDUSTECH IA\desarrollo\sistema_ots\app\sql\007_pendientes_y_captura.sql
```

Se aplica **en el servidor de Hostinger, por SSH y desde la línea de órdenes** —
`aplicar_sql.php` devuelve 404 por web a propósito: es una herramienta de
mantenimiento, no un endpoint que ejecute SQL:

```bash
# 0. La carpeta de destino puede no existir todavía: con scp eso falla con un
#    'No such file or directory' que no dice cuál de las dos rutas es la mala.
ssh -p 65002 -i "D:\INDUSTECH IA\desarrollo\agentes\config\clave_hostinger" \
    u671729428@82.25.73.181 \
    "mkdir -p domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot/sql"

# 1. Subir el .sql. NO sirve `t2_10_desplegar.py`: toma rutas posicionales (no
#    `--archivo`) y su ORIGEN está fijado a `app/publico/`, así que un archivo
#    que vive en `app/sql/` no puede subirse con él de ninguna forma. Va por
#    scp, con la misma llave del despliegue.
cd "D:\INDUSTECH IA\desarrollo\sistema_ots\app\sql"
scp -P 65002 -i "D:\INDUSTECH IA\desarrollo\agentes\config\clave_hostinger" \
    007_pendientes_y_captura.sql \
    u671729428@82.25.73.181:domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot/sql/

# 2. Correrlo por SSH, desde la línea de órdenes. `aplicar_sql.php` devuelve 404
#    por web a propósito: es mantenimiento, no un endpoint que ejecute SQL.
#    Y NO se parte el archivo por ';' a mano: el script quita primero los
#    comentarios y comprueba cada sentencia — un punto y coma dentro de un
#    comentario partiría el archivo y dejaría el esquema a medias.
ssh -p 65002 -i "D:\INDUSTECH IA\desarrollo\agentes\config\clave_hostinger" \
    u671729428@82.25.73.181
cd domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot
php aplicar_sql.php sql/007_pendientes_y_captura.sql

# 3. Verificar. Los DOS bloques del pie del .sql, y pegar la salida literal.
php verificar_esquema.php
```

**Y antes de T2.12.3, comprobar la lista blanca del desplegador.** El 2026-09-10
estaba **20 archivos atrás**, y `--todo` habría subido el sitio de antes del
rediseño: sin `mis.php`, sin `pendientes.php`, sin `reportes.php`, sin `ui.js`,
sin `cola.js` y sin ninguna clase nueva de `nucleo/`. Eso no da un error — da un
sitio a medias, con la navegación apuntando a 404 y el formulario sin su cola de
envíos. Se corrigió a 45 archivos, y el comentario de `ARCHIVOS` en
`t2_10_desplegar.py` trae el comando que vuelve a comprobarlo.
**Mantenerla al día es parte de agregar un archivo.**

Mientras la aprobación llega, lo que **sí** se puede hacer sin pedir permiso:

```bash
# 1. Que nada se haya movido: 206 comprobaciones, 0 fallos
cd "D:\INDUSTECH IA\desarrollo\sistema_ots\app\pruebas"
D:/SOFTWARE/PHP83/php.exe prueba_48h.php     # 96 · 0
node prueba_graficos.mjs                      # 62 · 0
node prueba_contratos.mjs                     # 48 · 0

# 2. Escribir las tres hojas de capacitación (T2.12.12) en SALIDAS IA\OTS\
# 3. Armar el paquete de despliegue de T2.12.3. La convención del proyecto es
#    una carpeta por entrega en SALIDAS IA\OTS\ — mira `paquete_buzon` y
#    `paquete_alta_padron`, que son los dos precedentes.
```

### Vocabulario operativo — las ocho palabras que hay que entender antes

§6.3 define el vocabulario de **datos** (zonas, cadenas, códigos de local). Esto
es otra cosa: son las palabras con que se habla del **trabajo**, y aparecen en
cada pantalla y en cada tarea. Sin ellas, T2.12 no se entiende.

| Palabra | Qué es |
|---|---|
| **Aviso** | El número de 8 dígitos con que Grupo KFC abre un caso en **su** SAP. Es la clave con que se cruza todo. INDUSTEC no lo crea: llega por correo. Un preventivo puede no tener aviso, y eso **no** es un error (§6.4b) |
| **Caso** | El pedido de KFC, identificado por su aviso. Vive en `casos_gestion` y tiene ocho estados |
| **Orden (OT)** | El documento que INDUSTEC emite al atender. Un caso puede tener varias; el correlativo lo reserva el servidor, el técnico no lo teclea |
| **Alcance** | Sobre qué filas puede actuar una persona. **No** es lo mismo que el permiso: el permiso dice «puede asignar», el alcance dice «sobre qué casos». Técnico → los suyos. Jefe de zona → su zona. Administración → las tres. **Se filtra en la cláusula `WHERE`, en el servidor**, nunca escondiendo filas al dibujar |
| **Cierre de dos manos** | Un caso se cierra con dos hechos distintos: el sistema lo marca **ATENDIDO** al ver el informe de la orden, y la administración confirma aparte que **además lo cerró en SAP**. El correo de SAP avisa cuando KFC crea o elimina un caso, y **nunca cuando lo cierra** — de ahí la segunda mano |
| **Regularizar** | Lo que hace la administración con un caso que se cerró por falta de atención: explicárselo a KFC. Deja de contar como pendiente suyo, pero **el caso sigue constando como no atendido**: eso no se borra por haberlo explicado |
| **Veredicto** | Dos cosas distintas según el contexto. En un **caso**: si nos compete o no. En un **equipo deshabilitado**: por cuál de las cuatro vías va (repuesto, reparación, garantía, baja), y es lo que el plazo de 48 horas mide |
| **Pendiente** | Un equipo que quedó sin concluir. Vive en la tabla `pendientes` y es lo que enciende el reloj de 48 horas si el equipo quedó fuera de servicio |
| **Novedad** | Lo que el técnico ve en la visita y **no era su orden**: un correctivo que se viene, o algo de otra área (eléctrico, ventilación, desagüe) que hace fallar los equipos. Ojo: `novedades.php` es otra cosa —el extremo que consulta el buzón cada 30 s—; la pantalla es `novedades_visita.php` |

Y la regla que manda sobre todas: **`avisos_sap.estatus_general` es el único
criterio de «cerrado»**, nunca una señal interna del sistema. Confundirlos
sobre-contó el backlog 8× en T1.11.

### Lo que está bloqueado, y por quién

| Bloqueado | Lo desbloquea |
|---|---|
| Aplicar la 007 y la 003 | **Andrés** — cambian el esquema |
| Desplegar a UIO, y 48 h después a LARB y CNLJ | **Andrés** (I-8) |
| Que el PDF y el correo salgan del sistema nuevo | La migración `003` (`correlativos` con reserva atómica y `email_queue`) |
| El 36% del correctivo que el buzón no trae | Confirmar la causa — ver `SALIDAS IA\OTS\HALLAZGO_BUZON_VS_SAP.md` |
| Respaldo TrueNAS (T1.9) | Acceso físico al equipo |
| Metas reales de SLA (T2.2) | El anexo de niveles de servicio del contrato con KFC |
| ~~Sacar el proyecto del único disco~~ | ✅ **Hecho el 2026-09-10:** remoto privado `AndresIndustech/industec-bia-soft-erp`. La base y el árbol canónico siguen en un solo disco hasta el TrueNAS |
| ~~Desplegar los arreglos que ya afectan al sitio en uso y corregir las 2 filas «ASIGNADO» sin técnico~~ | ✅ **Hecho el 2026-09-10** con aprobación de Andrés: `sw.js` v4, `pdf.php`, `nucleo/Reconciliar.php`, `nucleo/Auth.php` y los dos `.htaccess`; huérfanas a NUEVO. `login.php` y `usuarios.php` suben con T2.12.3 porque necesitan el `Ui.php` y el `estilo.css` del rediseño. Ver [`AUDITORIA_2026-09-10.md`](AUDITORIA_2026-09-10.md) §3 |
| Delegado de protección de datos ante la SPDP | Trámite: gratis, en línea, guía en `TRAMITE_DELEGADO_DATOS.md`. **El plazo venció hace más de 8 meses** |

### Entorno

```bash
cd "D:\INDUSTECH IA\desarrollo\agentes"
.venv/Scripts/python.exe scripts/<script>.py     # siempre el venv, no el python del sistema

D:/SOFTWARE/PHP83/php.exe                        # el PHP local, para -l y para las pruebas
node                                             # v24, para node --check y las pruebas .mjs
```

- **Base del archivo histórico:** MariaDB local, esquema `industec_ots`
- **Base operativa:** MySQL en Hostinger, `u671729428_ots` (usuarios, casos, gestión)
- **Credenciales:** `desarrollo/agentes/config/.env` y `app/publico/nucleo/config.php`, los dos fuera de git
- **Sitio de pruebas:** `darkviolet-armadillo-872352.hostingersite.com/ot/`
- **SSH:** `u671729428@82.25.73.181:65002`, llave ed25519

### Errores de este proyecto que ya se pagaron — no repetirlos

Cada uno costó horas o datos. Están aquí porque son fáciles de repetir.

1. **Derivar la cadena o la zona del prefijo del código de local.** `J018EC` es
   Cajun y otros códigos con `J` son Juan Valdez. Se resuelve **siempre** por el
   maestro. Ese atajo cruzó cuatro locales de Quito a Cuenca.
2. **Exigir el aviso SAP en el nombre canónico.** Mandó 185 documentos correctos
   a cuarentena: los preventivos no nacen de un aviso.
3. **`ON DUPLICATE KEY UPDATE` sin la `UNIQUE KEY` que lo respalde.** No
   actualiza: duplica todo en cada corrida. Pasó con `observaciones_calidad`.
4. **Escribir un archivo con un nombre que ya existe.** El 2026-09-10 la
   pantalla de novedades sobrescribió `novedades.php`, que era el extremo del
   vigilante del buzón: `ui.js` recibía HTML donde esperaba JSON y la barra de
   «el buzón se actualizó» dejó de aparecer, **sin un solo error visible**.
   Antes de escribir, comprobar: `git cat-file -e HEAD:<ruta>`.
5. **Confundir un 401 con un rechazo de contenido.** En la cola de envíos del
   técnico, tratar la sesión caducada como «orden inválida» perdía veinte
   minutos de su trabajo. Los 4xx no son todos iguales.
6. **Medir con un catálogo sin declarar su cobertura temporal.** Un export de
   SAP que solo cubría 8 de 12 meses generó ~1.662 falsos positivos.
7. **Creer que esconder un botón protege un endpoint.** Un POST se fabrica a
   mano. La validación va en el servidor, en la cláusula `WHERE`.
8. **Poner la protección en el `.json` y olvidar el `.php` que lo sirve.** Pasó
   dos veces: `catalogos.php` y `cronograma.php` entregaban locales, correos del
   cliente y nombres del personal sin ninguna sesión.
9. **Dar por cerrado lo que solo está cerrado en el código.** Esos mismos dos
   extremos figuraron como «CERRADOS» mientras seguían entregando 909 casos de KFC
   sin sesión: el arreglo nunca se había desplegado. «Cerrado» se afirma con un
   `curl` contra el servidor, no con un diff.
10. **Leer un archivo con otras claves que las que escribe su generador.**
    `envio.php` buscaba `locales.json['locales']` y `equipos.json`; `t2_5` escribe
    `{'datos': …}` y `equipos_por_local.json`. Habría rechazado el 100 % de las
    órdenes. Un solo lector para todos: `nucleo/Catalogo.php`.
11. **Restar fechas en PHP que guardó MySQL.** La base corre en UTC y el PHP de la
    web no: el reloj de 48 h y el bloqueo por intentos se calculan en SQL.

### Lo que no se toca, nunca

1. **`G:\Mi unidad`** (Drive de INDUSTEC) es de solo lectura, **indefinidamente**.
2. **`D:\RESPALDOS\_ORIGEN_DRIVE`** y **`_ORIGEN_SISTEMA`**: espejos intactos. La
   red de seguridad.
3. **Nada del cliente se borra** sin que él lo pida **en el momento**. Aprobar un
   plan no autoriza ejecutar un borrado.
4. **`nucleo/config.php`** en el servidor: lo único intocable del sitio de pruebas.
5. **En Hostinger, solo el sitio de pruebas.** Los demás sitios del panel
   —incluido el sistema en producción— no se modifican. Y **ningún trámite que
   genere un cobro** lo hace un agente.

---

## Referencias

- [Las seis dimensiones de calidad de datos — DAMA](http://dama-nl.org/wp-content/uploads/2020/09/DDQ-Dimensions-of-Data-Quality-Research-Paper-version-1.2-d.d.-3-Sept-2020.pdf) · [Guía de dimensiones](https://soda.io/blog/guide-to-data-quality-dimensions)
- [Patrones de pipeline: idempotencia, DLQ y cuarentena](https://dataskew.io/blog/data-pipeline-design-patterns/) · [Estrategias para datos malos](https://www.bigeye.com/blog/strategies-for-handling-bad-data-in-data-pipelines)
- [Verificación de integridad en copias de archivos](https://datadobi.com/wp-content/uploads/2025/09/Data-Integrity-During-a-File-Copy.pdf) · [Migración de archivos: robocopy y cómo probar que los datos llegaron](https://www.wuctechnologies.com/resources/field-guides/file-share-migration/)
- [Golden record y reglas de supervivencia en MDM](https://profisee.com/blog/mdm-survivorship/) · [Guía de supervivencia de datos](https://dataladder.com/guide-to-data-survivorship-how-to-build-the-golden-record/)
- [Convenciones de nombres de archivo — Harvard](https://datamanagement.hms.harvard.edu/plan-design/file-naming-conventions) · [Princeton Records Management](https://records.princeton.edu/records-management-manual/file-naming-conventions-version-control)
- [Código determinista en el bucle de la IA](https://ctoadvisor.substack.com/p/deterministic-code-in-the-loop) · [Human-in-the-loop para agentes](https://www.permit.io/blog/human-in-the-loop-for-ai-agents-best-practices-frameworks-use-cases-and-demo)
- [SLA de equipos de food service por franja horaria — Vixxo](https://www.vixxo.com/facilities-management-news/food-and-beverage-equipment-slas-why-timing-matters) · [KPIs de mantenimiento](https://www.getmaintainx.com/blog/beginners-guide-maintenance-kpis)

### Investigación adicional — re-análisis para Fase 2/3 (2026-09-04)
Fuentes consultadas en la segunda pasada de mejores prácticas, citadas por nombre porque la URL exacta del artículo no quedó fijada en el hallazgo original — sirven de punto de partida para profundizar antes de ejecutar cada tarea:
- **Cola de correo y reintentos:** discusiones de PHPMailer sobre reintentos (`PHPMailer/PHPMailer` issue #2493), loops.so y warmy.io sobre clasificación de errores SMTP 4xx/5xx.
- **Bot de Telegram 24/7:** wiki de `python-telegram-bot`, documentación de grammY sobre rate limits y `retry_after`, guías de NSSM como servicio de Windows.
- **Seguridad de LLM + SQL:** rietta.com y la documentación de privilegios de MariaDB sobre el riesgo de un "God User" al conectar un LLM a una base de datos.
- **KPIs y backlog de mantenimiento (CMMS):** Fiix, UpKeep, MaintainX, Limble, Xenia y las guías de SMRP sobre backlog en horas-hombre y SLA en dos relojes.
- **Repotenciación de equipos:** Reliabilityweb, maintainly.com, reliamag.com y fabrico.io sobre umbrales de reemplazo vs. reparación y proyección de tendencia de costo.
- **Confiabilidad del Programador de tareas de Windows:** Microsoft Learn (parámetro *Restart on failure*), healthchecks.io (heartbeat externo), rednafi.com (patrón `FileLock`).
- **Riesgo de corrupción con openpyxl:** reportes de pérdida de formato/tablas al editar plantillas reales con librerías de Excel programático.
