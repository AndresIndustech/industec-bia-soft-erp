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
| **T2.1.5** | ✅ **Hecho el 2026-09-11 en la app nueva** (la 008, sitio de pruebas): reserva con `UPDATE ... LAST_INSERT_ID(ultimo + 1)`, `FOR UPDATE` sobre la orden y `UNIQUE KEY` en `id_industec`; 10 reservas simultáneas dieron 10 números seguidos · Bloqueo del contador (transacción con `SELECT ... FOR UPDATE` o `UNIQUE KEY` sobre el correlativo) | Números duplicados por envíos simultáneos | Disparar 10 envíos concurrentes de prueba; 10 correlativos distintos, cero colisiones |
| **T2.1.6** | 🟡 **La cola existe desde el 2026-09-11** (`email_queue`, la 008): la orden queda guardada y su correo espera ahí; en el sitio de pruebas, RETENIDO. Falta el despachador con PHPMailer, que se prueba en el corte con el SMTP real · Cola de reintento de correo (se integra con T2.3 en el punto de envío) | 82 fallos en que la orden no llegó a nadie | Un envío con SMTP caído a propósito: el job queda en `email_queue`, no se pierde |

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
| **T2.12.1** | ✅ **Hecho el 2026-09-11** · Aplicar `sql/007_pendientes_y_captura.sql` | Sin las tablas, el control de 48 h, las novedades y la recepción de órdenes no existen | Correr **los dos bloques de verificación** del propio archivo y **pegar su salida literal**: las 7 consultas numeradas del pie, y el bloque aparte «VERIFICACION de las novedades» que está antes (fácil de saltar, porque no está al final). Las tres que no se negocian: `SHOW CREATE TABLE pendientes\G` muestra `UNIQUE KEY uq_pendiente (aviso, activo_fijo)`; `SHOW CREATE TABLE ot_capturadas\G` muestra `UNIQUE KEY uq_captura_envio (envio_uuid)`; `SHOW COLUMNS FROM casos_gestion LIKE 'estado'` termina en `,'ESPERA_REPUESTO')` |
| **T2.12.2** | ✅ **Hecho el 2026-09-11** · Idempotencia de la migración | Una segunda corrida que duplique deja el tablero contando doble | Correr la 007 **dos veces seguidas**. `SELECT COUNT(*) FROM permisos WHERE codigo LIKE 'repuestos%' OR codigo LIKE 'novedades%'` → 7 en las dos corridas. Y la prueba de inserción doble del pie del archivo → `COUNT(*) = 1` |
| **T2.12.3** | ✅ **Desplegado entero el 2026-09-11**: nadie usa el sitio de pruebas, así que el piloto por zona queda para cuando empiece el uso real · Desplegar a **UIO solamente** (I-8) | Un defecto en tres zonas a la vez | `t2_10_desplegar.py`, que verifica por hash. Abrir las 13 pantallas con un usuario de cada rol y **que ninguna dé error de PHP ni quede en blanco**. ⚠️ **Corregido el 2026-09-10:** hay **un solo sitio para las tres zonas**, así que «desplegar solo a UIO» no existe: el piloto se hace dejando activas solo las cuentas de UIO (baja temporal de las de LARB y CNLJ desde `usuarios.php`, con bitácora) o con una compuerta `zonas_habilitadas` en `nucleo/config.php`. **La vía la elige Andrés** antes de este paso |
| **T2.12.4** | ✅ **Hecho el 2026-09-11** · 🚦 Verificar el alcance por zona **con los tres roles** | Que un jefe de zona vea datos de otra zona. Es la comprobación más importante de toda la tarea | Entrar como administradora, jefe de UIO y jefe de CNLJ y **contar filas** en `casos.php`, `pendientes.php`, `novedades_visita.php`, `ordenes.php`, `reportes.php` y `cronograma.html`. Las cifras de los dos jefes deben sumar sin solaparse y ser menores que la de la administradora. Pedir otra zona por la URL (`?zona=CNLJ` siendo jefe de UIO) → **0 filas y ninguna fuga** |
| **T2.12.5** | ✅ **Hecho el 2026-09-11** · Verificar el alcance con un **POST fabricado a mano** | Que esconder un botón se confunda con proteger | `curl` un POST a `pendientes.php` con `pendiente_id` de otra zona y a `novedades_visita.php` con `novedad_id` de otra zona, con la cookie de un jefe de zona → **rechazado en el servidor**, y la fila queda en `bitacora` con `exito = 0` |
| **T2.12.6** | ✅ **Hecho el 2026-09-11** · Verificar los dos extremos que se cerraron el 10-sep | Que un despliegue los reabra por descuido | `curl` sin cookie a `catalogos.php` y a `cronograma.php` → **401 en JSON**, no un catálogo (**comprobado el 2026-09-10 tras subirlos**). Con cookie de jefe de zona a `cronograma.php` → solo su zona. **Con cookie de un técnico** a `catalogos.php` → `avisos` = exactamente sus casos asignados; ninguna prueba de T2.12 entraba como técnico |
| **T2.12.7** | ✅ **Hecho el 2026-09-11** con un navegador de verdad contra el servidor (`prueba_cola_vivo.mjs`): el servidor «caído» se simula desviando el dominio, porque Hostinger no se puede apagar · 🚦 Prueba de captura **sin señal**, de punta a punta | Que una orden se pierda, o que llegue dos veces | **Apagar el servidor de verdad** — no cortar la red con el depurador, que no reproduce el caso: el trabajador de servicio tiene su propio contexto de red. Abrir el formulario, llenar una orden completa, comprobar que la cola dice «guardada, esperando señal». Encender el servidor. La orden sale sola y **`SELECT COUNT(*) FROM ot_capturadas WHERE envio_uuid = '<el uuid>'` da 1**, no 2 |
| **T2.12.8** | ✅ **Hecho el 2026-09-11** (`prueba_cola_vivo.mjs`) · Reintento con sesión caducada | Que un 401 marque como inválida una orden que está perfecta | Con órdenes en la cola, cerrar la sesión desde otro navegador (sesión única). El siguiente intento debe dejarlas en «falta entrar», **no** en «rechazada». Volver a entrar → salen solas |
| **T2.12.9** | ✅ **Hecho el 2026-09-11** · Verificar el reloj de 48 h contra datos reales | Que la cifra del globo de navegación y la de la pantalla discrepen | Abrir un pendiente con equipo deshabilitado. Comprobar que el globo de «Repuestos y equipos», la tarjeta «Fuera de plazo» y la lista filtrada con `?g=vencidos` **dan el mismo número**. Contrastar con la consulta 6 del pie de la 007 |
| **T2.12.10** | ✅ **Hecho el 2026-09-11** · Verificar el ciclo completo de un caso | Que la reconciliación pise un estado que puso una persona | Recorrer un caso de prueba: asignar → el técnico lo deja trabado → veredicto → avanzar la vía → resolver. Después correr `reconciliar_cli.php` y comprobar con la consulta 5 del pie de la 007 que **da 0 filas** |
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
(62) y `pruebas/prueba_contratos.mjs` (54), **212 en total, 0 fallos**. Antes de
tocar nada, correrlas: si alguna falla, algo se movió.

```bash
cd "D:\INDUSTECH IA\desarrollo\sistema_ots\app\pruebas"
D:/SOFTWARE/PHP83/php.exe prueba_48h.php     # 96 · 0
node prueba_graficos.mjs                      # 62 · 0
node prueba_contratos.mjs                     # 54 · 0
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
se termina en la app nueva y producción no se toca. **Hecho el 2026-09-11 en el sitio de pruebas** con la
migración `008_emision.sql`: número, PDF y correo en cola, con las fotos y la firma como dato (ver
ESTADO §1). Para producción falta el despachador de la cola y cargar los contadores reales.

| # | Subtarea | Verificación exacta |
|---|---|---|
| **T2.13.0** | ✅ **Hecho el 2026-09-11** con dos casos reales de UIO (reversibles con `deshacer_prueba.php`), porque los sintéticos no se ven hasta T2.13.3 · 🚦 Personas y casos de prueba (**requiere aprobación**) | Crear `tec_prueba_uio_a`, `tec_prueba_uio_b` y un jefe de prueba, y 3 avisos sintéticos `9999xxxx` con su limpieza al final. `SELECT COUNT(*) FROM usuarios WHERE usuario LIKE 'tec_prueba%'` = 2. Sin esto ningún criterio de abajo se puede correr: asignar o cerrar casos reales para probar está prohibido |
| **T2.13.1** | ✅ **Hecho el 2026-09-11** · Probar en el servidor lo corregido el 10-sep (tras T2.12.1 y el despliegue) | Con la cookie de `tec_prueba_uio_a`: `catalogos.php` → sus avisos y ninguno más; POST a `envio.php` con una orden válida → 200 y `SELECT COUNT(*) FROM ot_capturadas WHERE envio_uuid='<uuid>'` = 1; el mismo POST otra vez → sigue en 1; el mismo cuerpo con `usuario_captura` de B → 409 |
| **T2.13.2** | ✅ **Hecho el 2026-09-11**: `catalogos.php` le da al técnico sus casos ASIGNADO o ESPERA_REPUESTO desde la base (`Casos::delTecnico`); probado con un aviso sintético que, al pasar a ATENDIDO, sale de su lista (`verificar_bandeja.py`) · El combo no ofrece casos ya atendidos o cerrados | `catalogos.php`, solo para TECNICO: casos en ASIGNADO o ESPERA_REPUESTO. Given un aviso sintético asignado a A, When pasa a ATENDIDO, Then desaparece del `curl` de `catalogos.php` de A. Coordinar con quien tenga `catalogos.php` en curso |
| **T2.13.3** | ✅ **Hecho el 2026-09-11**: `mis.php` arma bandeja e historial desde `casos_gestion` y suma las órdenes enviadas desde la app; un caso fuera del catálogo sale con «sin dato en el catálogo» y se puede reportar trabado y emitir (`Casos::alcanzaAviso` se lo reconoce al técnico); la ficha de un caso cerrado muestra su orden de cierre o dice que no hay; «Historial» en la barra. 19 comprobaciones con avisos sintéticos · Historial **y bandeja** desde la base, no desde la ventana de 90 días | «Mis casos» = `casos_gestion` asignado a mí en ASIGNADO o ESPERA_REPUESTO, esté o no en el catálogo: el 2026-09-10 había 4 casos ASIGNADO fuera del catálogo que sus 3 técnicos no veían. «Atendidas» = `casos_gestion` (asignado a mí, ATENDIDO/RESUELTO/NO_COMPETE) ∪ `ot_capturadas` (mías); el PDF sale de `casos_gestion.ot_cierre` y, si falta, se dice (I-7). Enlace «Historial» en la barra del técnico. Criterio: un aviso sintético ATENDIDO que no está en `casos_sap.json` aparece con «sin dato en el catálogo» |
| **T2.13.4** | ✅ **Hecho el 2026-09-11**: `cronograma.php` no le manda al técnico `tecnicos` y le da `locales` vacío; `cronograma.html` le pinta la barra de abajo con `UI.barraTecnico()` y le esconde «agendar un local». La barra se prueba como función pura en `pruebas/prueba_barra_tecnico.mjs` —aparte de `prueba_contratos.mjs`, que tiene trabajo en curso de otra conversación— y se compara con la de `mis.php` · Cronograma móvil del técnico | `cronograma.php` deja de mandar `tecnicos` (la pantalla no lo usa) y `locales` al técnico; `cronograma.html` le pinta la barra móvil. Criterio: `curl -b a.txt $B/cronograma.php` → sin clave `tecnicos` y `locales` vacío; la barra se prueba con una función pura en `prueba_contratos.mjs` |
| **T2.13.5** | ✅ **Hecho el 2026-09-11**: `nucleo/Avisos.php` arma los avisos desde `casos_gestion`, `pendiente_notas`, `novedades` y la bitácora; `novedades.php` le cuenta al técnico los suyos; pestaña «Avisos» con globo y barra en `mis.php`. Probado con el jefe de prueba pasándole un caso de A a B y devolviéndoselo, una respuesta en el hilo y una novedad resuelta (`verificar_bandeja.py`). Límite: «te quitaron» solo se ve si la asignación anterior pasó por `casos.php` · Buzón de avisos del técnico, **sin tabla nueva** | `novedades.php` (rama TECNICO) devuelve total y versión propios desde lo que ya se registra: asignaciones (`casos_gestion.asignado_en`), respuestas y veredictos del hilo (`pendiente_notas`), veredictos de sus novedades y «te quitaron el caso» (bitácora). «Visto hasta» = su último `CONSULTAR bandeja` en la bitácora. Pestaña «Avisos» en `mis.php` con globo. Criterio: el jefe de prueba asigna un aviso sintético a A → total de A = 1 y de B = 0; A abre la bandeja → total 0 |
| **T2.13.6** | Comunicados de zona (**solo si Andrés los aprueba**) | Tabla `comunicados` con `UNIQUE (comunicado_uuid)`, zona forzada en el servidor y «visto por X de Y». Condicionado a que el piloto muestre uso real: un comunicado que nadie abre le hace creer al jefe que avisó |
| **T2.13.7** | ✅ **Hecho el 2026-09-11**: `Db.php` fija las dos zonas (`time_zone = '-05:00'` por desfase, porque Hostinger no tiene cargadas las zonas con nombre —error 1298—, y `date_default_timezone_set` al cargarse, que es por donde pasa todo lo que toca la base) y `hora_ecuador.php` corrió −5 h las `DATETIME` del reloj del servidor, salvo `atendido_en`, que llega del informe en hora local. Verificado en el servidor: `NOW()` y `date()` dan la misma hora de Ecuador, ninguna fecha queda en el futuro, el recibo de la orden sale con la hora local y las 87 comprobaciones por rol siguen pasando · La hora de Ecuador en todo el sistema | Hoy la base y el PHP corren en UTC y el negocio en UTC−5 (medido el 2026-09-10; ver AUDITORIA §6). **Opción elegida: una sola zona para todo.** `Db::conn()` ejecuta `SET time_zone = '-05:00'` (Ecuador no cambia de hora) y un arranque común fija `date_default_timezone_set('America/Guayaquil')`; las filas ya guardadas se corren −5 h una sola vez, en el sitio de pruebas (en `casos_gestion.tocado_en`, que lleva `ON UPDATE`, el `UPDATE` tiene que fijarla explícita o se pisa con `NOW()`). Descartada: guardar en UTC y convertir al mostrar, porque obliga a tocar cada pantalla y cada comparación con las fechas del catálogo de SAP, que llegan en hora local. Criterio: a las 20:00 de Ecuador, `SELECT NOW()` y `date('Y-m-d H:i')` dan la hora de Ecuador; un caso con `fecha_estimada` = hoy **no** sale vencido; la hora de `recibida_en` que ve el técnico en su recibo es la de su reloj |

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

### T2.14 · Pulido para las pruebas del cliente (pedido de Andrés del 2026-09-12)

**De dónde sale.** El 2026-09-12 Andrés pidió, literal: tener la app web **totalmente funcional
para las pruebas de técnicos, jefes y administradora**, «de entrada un sistema ya completamente
pulido, mejorado en base a las buenas prácticas», y detalló lo que cada rol necesita (abajo). Ese
mismo día, probando el sitio de pruebas, reportó dos fallos: **el técnico no ve los PDF de sus
casos** y **la pantalla de asignación mezcla a los técnicos sin división por zona**. Antes de
construir se auditó todo el sistema por diez frentes con verificación escéptica
([`AUDITORIA_2026-09-12.md`](AUDITORIA_2026-09-12.md)): 205 hallazgos, de los que salen las
subtareas de abajo. La línea base del sitio de pruebas antes de tocar nada: **148 de 148
comprobaciones por rol en verde** (`verificar_http` 66, `verificar_bandeja` 32,
`verificar_ciclo` 21, `verificar_emision` 29).

**Premisas que no se negocian en esta tarea** (Andrés, 2026-09-12): no alterar la operación
actual (producción `yellow-elephant` no se toca), todo en el sitio de pruebas, **cero costos
nuevos**, aprovechar al máximo lo que ya se paga (Hostinger Premium: PHP 8.2 con gd/imagick/intl/zip
y composer; las suscripciones de Claude; la estación; a futuro el equipo de almacenamiento con
Veeam). Software libre.

#### Lo que pidió cada rol, en sus palabras

| Rol | Lo que debe poder hacer |
|---|---|
| **Administradora** | Identificar fácilmente las novedades; pedir seguimiento a los técnicos y, si hace falta, asignarles tareas directamente; un buzón con el **resumen de casos atendidos y pendientes** para decidir; al final, una **lista de casos a registrar en SAP** (cerrados) y de **repuestos a solicitar**; ver el estatus de la operación en tiempo real; **vistas consolidadas por zona**, bien diferenciadas, con las estadísticas de cada zona agrupadas y separadas; generar los **informes y reportes que pide KFC en Excel, PDF y PowerPoint**, profesionales, que demuestren una gestión controlada y de calidad |
| **Jefe técnico** | Delegar fácilmente los casos de su zona; seguir de forma proactiva los requerimientos de repuestos y el cumplimiento del cronograma preventivo; paneles de control y **rendimiento de sus técnicos**; **validar que el repuesto solicitado y el diagnóstico son correctos** — con ese OK la administradora registra el requerimiento en SAP y el caso queda abierto hasta que KFC haga llegar el repuesto o dé su veredicto (reparación en talleres de INDUSTEC, otro proveedor, o baja del equipo) |
| **Técnico** | Algo sencillo: un buzón con los casos nuevos que le asignaron; un **formulario interactivo con campos prellenados** (su nombre, y lo que trae el caso de SAP: local, equipo, tipo de requerimiento); poder **elegir información ya ingresada**, como el administrador de ese local; que un **equipo nuevo quede guardado en la base para todas las zonas**; **diagnósticos pre-redactados** por los daños más comunes; identificar claramente los **repuestos a solicitar**; guía para completar todos los datos; lo mismo en **preventivos y trabajos con otros proveedores**; **siempre** poder ver los PDF de los casos |
| **Todos** | Acceso al **archivo general de órdenes de trabajo de todas las zonas** —como hoy en Google Drive— solo para consultar, compartir o descargar, sin borrar ni editar; y un **apartado de aprendizaje** con manuales y guías de los equipos que INDUSTEC irá alimentando desde la propia aplicación, con las **aprobaciones** de los estratos necesarios y **trazabilidad de todas las acciones** de todos los usuarios |

#### Decisiones tomadas por defecto (corregir si Andrés dice otra cosa)

Andrés no estaba disponible durante la construcción, así que donde el diseño exigía una decisión
se tomó la más conservadora y se dejó escrita aquí. **Ninguna de estas es irreversible.**

| # | Decisión por defecto | Motivo |
|---|---|---|
| **D1** | **La regla de alcance se parte en dos.** Las *acciones* (asignar, veredicto, cerrar, gestionar) siguen filtradas por zona y por técnico **en el WHERE del servidor**. La *consulta* del archivo de órdenes emitidas es de **solo lectura para los cuatro roles, en las tres zonas**, incluido el TÉCNICO desde el celular. Cada apertura, descarga y compartición queda en la bitácora con quién, cuándo y qué. Compartir sigue produciendo un enlace firmado que caduca a 24 h | Decisión de Andrés del 2026-09-12; la bitácora es el control compensatorio |
| **D2** | **El histórico de 7.069 órdenes entra como índice** (`ot_archivo`, cargado desde el catálogo de la estación) y los PDF se sirven cuando existen en Hostinger; los que solo están en `D:\RESPALDOS` muestran «pedir copia» y la petición queda registrada para que la estación la suba. ~~Subir los 5 GB de PDF históricos a Hostinger es decisión de Andrés~~ **Revocada por Andrés el 2026-09-14: se sube TODO el histórico y lo nuevo cada noche, solo desde la estación (T2.19). «Pedir copia» queda de respaldo** | No se sube nada del cliente a la nube sin que él lo pida — y lo pidió: «Quiero que no sea necesario pedir copia a la estación» |
| **D3** | **Flujo de repuestos:** `SOLICITADO` (técnico) → `VALIDADO_JEFE` (jefe de zona; la administración puede validar por excepción y queda en bitácora) → `REGISTRADO_SAP` (administración, **con el número del requerimiento**) → `ESPERA_KFC` → veredicto de KFC: `REPUESTO_ENVIADO` / `TALLER_INDUSTEC` / `OTRO_PROVEEDOR` / `BAJA` → `RESUELTO` o `CANCELADO`. El reloj de 48 h mide la validación del jefe (la decisión de INDUSTEC), no la respuesta de KFC. La orden **concluida** del técnico tras instalar el repuesto resuelve el pendiente sola. Los estados viejos (`COTIZANDO`, `COMPRADO`…) se conservan en el ENUM para las filas existentes | Es el flujo que Andrés describió; lo de KFC se mide aparte porque no depende de INDUSTEC |
| **D4** | Asignar a un técnico de **otra zona** exige marcar «confirmo que es de otra zona» y queda en bitácora; no se prohíbe | Hay técnicos que cubren locales vecinos |
| **D5** | El **cierre por falta de atención a los 7 días no es automático**: el panel muestra «N casos con más de 7 días sin informe» y un botón de la administración lo ejecuta con confirmación. Los textos que decían «se cierran solos» se corrigen | Es una decisión sobre casos del cliente; la toma una persona |
| **D6** | «Asignar» sobre un caso `CERRADO_SIN_ATENCION` lo **reabre** como `ASIGNADO` con bitácora `REABRIR`; sobre `EN_REVISION` se permite, conservando el motivo de revisión a la vista | La administradora que reparte un caso ya decidió regularizarlo |
| **D7** | Los locales de zona **OTRA** emiten con serie propia (`CORRECTIVO:OTRA`) y sufijo `-OTRA` en el nombre canónico | Hoy no reciben número ni PDF; qué contador usa producción para ellos lo confirma Andrés |
| **D8** | Un **equipo nuevo** registrado desde el formulario entra como `PROPUESTO`, visible de inmediato en la lista de ese local (marcado) y para todas las zonas; la administración lo **aprueba** y la estación lo incorpora al maestro. Nunca se pierde | Lo pidió Andrés; la aprobación mantiene limpio el catálogo |
| **D9** | El **catálogo de diagnósticos y repuestos frecuentes** se siembra desde el histórico real (7.863 filas de los planes de zona) por familia de equipo, y lo editan la administración y los jefes desde la aplicación; el técnico elige uno y completa el texto | Texto pre-redactado de verdad, no inventado |
| **D10** | Un **trabajo con otro proveedor** es un atributo de la orden (`con_proveedor`: nombre y objeto), no un módulo con serie propia | Cambio mínimo; si KFC exige serie aparte se separa después |
| **D11** | **Manuales y guías:** los suben jefes y administración (el técnico propone), los **aprueba la administración** (`SUPERADMIN` también), con versiones y bitácora; los comunicados llevan acuse de lectura opcional | Es el «apartado de aprendizaje» con las aprobaciones que pidió Andrés |
| **D12** | Los PDF **siguen dentro de `public_html/ot/` protegidos por `.htaccess`** hasta el corte; moverlos fuera del docroot va en T2.16 | Cambia rutas del servidor: se hace con el corte, no en medio del piloto |
| **D13** | Todos los formularios llevan **token CSRF** además de `SameSite=Lax`; la cola del celular lo manda en la cabecera `X-Csrf` | Buenas prácticas; costo cero |
| **D14** | Los reportes se exportan a **Excel, PDF y PowerPoint con formato propio de INDUSTEC** (marca, semáforo que ya usa la administración, por zona y consolidado). Las plantillas exactas de KFC se mapean cuando Andrés las entregue (`ENTRADAS IA\REPORTES` y `PRESENTACIONES` están vacías en el PC) | No se inventa el formato del cliente |
| **D15** | El **cronograma escribe**: kit, reagenda con motivo y cierre de cada ingreso van a tablas propias (migración 009); el cumplimiento se mide contra el **plan original** | Sin esto no se puede reportar cumplimiento del preventivo |
| **D16** | La **reemisión** de un PDF fallido y el **despachador de correo** son scripts de línea de órdenes para el cron de hPanel (lo programa Andrés) y, mientras tanto, se disparan de forma oportunista al recibir una orden. En el sitio de pruebas el correo sigue **RETENIDO** | Ningún correo sale de darkviolet |

#### Subtareas — cada una es un corte vertical, con su verificación

| # | Subtarea | Qué ataca (hallazgos de la auditoría) | Verificación exacta |
|---|---|---|---|
| **T2.14.0** | **Migración `009_pulido_piloto.sql` y núcleo compartido.** Tabla `migraciones` (libro de lo aplicado); `pendientes`: estados nuevos al final del ENUM, `validado_por/en`, `requerimiento_sap`, `registrado_sap_por/en`, `veredicto_kfc`, `plazo_desde` ya existe; `email_queue`: `tomado_en`, `proximo_intento_en`, `error_ultimo`, estado `ENVIANDO`; `ot_capturadas`: estados `NUMERADA/EMITIDA/ENVIADA/FALLIDA`, índices por aviso/zona/local/emisión, `ultimo_reintento_en`; `ot_archivo`; `equipos_propuestos`; `diagnosticos` y `repuestos_frecuentes` (con semilla); `locales_admin`; `documentos` y `documento_versiones` y `documento_acuses`; `ingresos_preventivos` y `cronograma_novedades`; `casos_seguimientos`; FKs que faltan en `casos_gestion`; permisos nuevos (`ots.archivo`, `ots.compartir`, `repuestos.validar`, `repuestos.registrar_sap`, `repuestos.responder`, `catalogos.editar`, `documentos.subir`, `documentos.aprobar`, `cronograma.editar`, `bitacora.ver` ya existe) repartidos por rol; triggers que impiden `DELETE`/`UPDATE` en `bitacora`. Núcleo: `Auth::csrfToken()/exigirCsrf()`, manejador global de excepciones en `Db.php`, `Cache-Control: no-store` en pantallas con sesión, `Emision::existePdf()`, `Casos::sinAsignar()/puedeTransitar()/fueraDeCatalogo()`, `verificar_esquema.php` con los bloques 008 y 009 | E-03, E-05..E-09, E-12, E-14, E-18, E-19, E-22, P-02, P-12, SEG-07, SEG-09, SEG-15, SEG-18, ASG-04, ASG-12, ASG-18 | `php aplicar_sql.php sql/009_pulido_piloto.sql` **dos veces** sin error; `php verificar_esquema.php` termina en TODO OK y dice «con la 009»; `SELECT COUNT(*) FROM diagnosticos` ≥ 120; `DELETE FROM bitacora LIMIT 1` falla con SQLSTATE 45000 |
| **T2.14.1** | **La app del técnico.** `pdf.php` abre a todo usuario con sesión (D1) y registra; pestaña **Archivo** en la barra del técnico; el botón «Ver PDF» solo si el archivo existe (si no, dice por qué); el recibo del envío muestra el **número real** y el enlace al PDF; se retiran todos los textos «todavía no genera el PDF»; con el catálogo caído el botón avisa en vez de morir; la guía por pasos conserva ✓ y resumen al plegar; una orden RECHAZADA se puede **corregir y reenviar** conservando fotos y firma; **administrador del local** elegible de los ya ingresados (`locales_admin` + últimos de `ot_capturadas`); el **equipo del aviso viene preseleccionado**; opción **«Equipo nuevo / no está en la lista»** → `equipos_propuestos`; **diagnóstico pre-redactado** por familia de equipo con texto editable y **repuestos estructurados** (pieza, cantidad, número de parte si se sabe, equipo); preventivos llegan prellenados desde el cronograma (`?tipo=PREVENTIVO&dia=&local=&equipos=`); casilla **«trabajo con otro proveedor»** (D10); el técnico fuera del padrón no puede enviar (bloquea antes); los avisos no se marcan vistos por navegar; borrador restaurado completo; el reintento del celular **no pisa** una orden ya emitida; foto demasiado grande → 413 y la orden sale sin ella con nota; purga de la cola local a 30 días | H-01..H-25, E-04, E-17, E-20, E-23, SEG-19, DOC-04 | `node reglas.fixture.mjs` y `php validacion_test.php` con los casos nuevos (equipo nuevo, diagnóstico con código, repuesto estructurado, con_proveedor) en verde en los tres lados; `verificar_bandeja.py` amplía: el técnico de prueba abre el PDF de una OT «en curso» de su caso → **200**; `curl` sin sesión a `pdf.php?ot=…` → 302; con cookie de técnico a una OT de otra zona → 200 y fila `ABRIR_PDF` en bitácora; una orden con `equipo.nuevo=true` deja fila en `equipos_propuestos` |
| **T2.14.2** | **Asignación, buzón y panel por zona.** `asignacion.php`: un **bloque por zona** (para la administración las tres, cada una con sus cuatro cifras, su equipo ordenado por carga y su tabla «por repartir» debajo); la cifra «por repartir» es un enlace que **filtra la lista** (`?zona=UIO#por-repartir`); el desplegable ofrece los técnicos **de esa zona** (otra zona solo con confirmación, D4); vuelve a `asignacion.php` tras asignar; la carga cuenta `ESPERA_REPUESTO`. `casos.php`: veredicto `RESUELTO` solo desde `ATENDIDO` y nunca con un pendiente vivo (crítico); «Asignar» reabre lo cerrado sin atención (D6) y pone `tecnico_auto = 0`; `revision`/`derivar` validan el estado de origen; una tabla de transiciones única en `Casos.php`; el aviso sale de `onclick` a `data-*`; los casos `ATENDIDO` **fuera de la ventana de 90 días** siguen visibles para la administración («sin dato en el catálogo»); filtro `zona=SIN`; acción **«pedir seguimiento»** (nota al técnico, aparece en sus Avisos, `casos_seguimientos`). `panel.php`: para la administración, **una columna por zona** con sus cifras enlazadas; tareas nuevas: «asignados sin informe hace 3+ días», «repuestos validados por registrar en SAP», «casos con más de 7 días sin informe» con el botón de cierre por falta de atención (D5). `Reconciliar.php`: no regresa `ATENDIDO` a `ASIGNADO` por una OT abierta; los casos con alerta de alcance no se cierran por falta de atención | ASG-01..ASG-21, TR-09, DOC-03, SEG-21 | `verificar_http.py` amplía: administración → `asignacion.php` tiene tres `section.zona-bloque` y cada `<select>` de UIO solo lista técnicos de UIO; POST `veredicto=RESUELTO` sobre un caso `ESPERA_REPUESTO` → rechazado y fila `DENEGADO`; POST `asignar` de un caso de UIO a un técnico de CNLJ sin confirmación → rechazado; `panel.php` de la administración muestra tres columnas con `Ui::zona()` |
| **T2.14.3** | **Repuestos y novedades con el flujo real.** `Pendientes.php`/`pendientes.php`: la máquina de estados de D3 con sus etiquetas nuevas; diálogo «Validar la solicitud» (jefe), «Registrar en SAP» con número obligatorio (administración), «KFC decidió» con las cuatro salidas; el jefe puede **responder** en el hilo; los recordatorios de oficina no cuentan como insistencias del técnico; el reloj arranca cuando un equipo pasa a deshabilitado; cumplimiento medido en minutos; `mover` exige nota al retroceder; tiles y filtros «por validar» (jefe), «por registrar en SAP» (administración), «esperando a KFC», «compromisos vencidos»; **la orden concluida resuelve el pendiente sola**; un equipo parado en una orden sin aviso entra a «por regularizar». Novedades: conserva la zona del local; `DERIVADA_SAP`/`ASUMIDA` pueden pasar a `RESUELTA` o corregir el aviso; `reportar` exige permiso y valida local en el servidor; se puede registrar una novedad desde la oficina | P-01..P-23, E-06, SEG-12 | `verificar_ciclo.py` amplía al ciclo D3 completo: técnico abre → jefe valida (200; administración sin excepción → 403) → administración registra con número (sin número → 400) → «KFC decidió: TALLER_INDUSTEC» → orden concluida del técnico → pendiente `RESUELTO` y caso `ASIGNADO`; el hilo del pendiente muestra los cinco pasos |
| **T2.14.4** | **Archivo general, bitácora, usuarios y aprendizaje.** `ordenes.php` pasa a ser el **Archivo**: lee `ot_archivo` (índice del histórico + lo emitido por la app + los PDF del servidor), sin filtro por rol, con buscador, filtros por zona/año/local/técnico/fecha, paginación de 50, «Ver» y «Descargar» (registrados distinto), **«Compartir» como POST con traza** y firma que incluye quién compartió; `archivo_indexar_cli.php` (CLI) que carga el catálogo de 7.069 órdenes que publica la estación y reindexa `ordenes_pdf/`; los PDF que no están en Hostinger muestran «pedir copia» (D2). `bitacora.php` (permiso `bitacora.ver`): filtros por usuario, acción, entidad y fechas, paginación y CSV; enlace «Ver actividad» por usuario. `usuarios.php`: agrupado por zona, **editar** nombre/correo/zona/rol con bitácora, patrón POST-redirect-GET. **Aprendizaje** (`documentos.php` + `documento.php`): manuales y guías por familia de equipo y por zona, subir (jefe/administración; el técnico propone), **aprobar** (administración), versiones con sha256, comunicados con acuse; archivos en `documentos/` fuera del alcance web directo, servidos con sesión y bitácora | TR-07, TR-08, TR-10, TR-16..TR-19, TR-23, SEG-02, SEG-03, SEG-06, SEG-13, E-05, E-09 | Técnico de prueba con cookie: `ordenes.php` lista órdenes de las tres zonas; `pdf.php?ot=<OT de CNLJ>&dl=1` → 200 y fila `DESCARGAR_PDF`; POST `compartir` → enlace que abre sin sesión y fila `COMPARTIR_PDF` con `usuario_id`; `bitacora.php` sin permiso → 403; subir un manual como jefe → estado `EN_REVISION`; aprobar como administración → visible para el técnico; el técnico no puede aprobar (403) |
| **T2.14.5** | **Reportes por zona con exportación, y cronograma que escribe.** `reportes.php`: filtro de zona para la administración (las tres · UIO · LARB · CNLJ), **selector de periodo** (mes), sección «Rendimiento por técnico» (asignados, con informe, concluidos en una visita, días a la primera atención, pendientes vencidos abiertos por él, novedades), sección «Cumplimiento del preventivo» (a tiempo / tarde / vencidos / sin agendar, por zona, motivos de reagenda, kits confirmados); `reporte_exportar.php?formato=xlsx|pdf|pptx` con PhpSpreadsheet, dompdf y PhpPresentation (libres; `composer` en `~/lib/ot`), formato propio de INDUSTEC con el semáforo de la administración, por zona y consolidado (D14). Cronograma: tablas `ingresos_preventivos` y `cronograma_novedades` (009) cargadas desde el JSON con `cronograma_importar_cli.php`; `cronograma_accion.php` (POST: kit, reagendar con motivo, cerrar ingreso, agendar); tiles «cumplidos» y «% a tiempo»; navegación real en `cronograma.html`; marca de zona en la vista de las tres; accesible con teclado; cada visita enlaza al formulario prellenado (`?tipo=PREVENTIVO…`) | TR-01..TR-06, TR-11..TR-15, TR-21, TR-22, TR-24, TR-25 | `reportes.php?zona=UIO` con cookie de administración → solo casos de UIO (cifra = la del jefe de UIO); `reporte_exportar.php?formato=xlsx&zona=UIO` → 200, `Content-Type` de Excel y el archivo abre con una hoja por sección; `pptx` → 200 con al menos 6 diapositivas; `pdf` → 200; POST `cronograma_accion.php` (reagendar con motivo) como jefe de UIO sobre un ingreso de CNLJ → 403; sobre uno de UIO → 200 y fila en `cronograma_novedades` |
| **T2.14.6** | **Emisión, correo y seguridad del núcleo.** `emitir_pendientes_cli.php` (CLI, candado de instancia única) reintenta las capturas con `emision_error` o sin PDF; `envio.php` lo dispara de forma oportunista tras recibir (máximo 3 por llamada); `despachar_correo_cli.php` con PHPMailer (composer, `~/lib/ot`), reclamo atómico, reintentos 5 min → 15 min → 1 h → 4 h → 24 h, `FALLIDO` al sexto; en modo PRUEBA no envía nunca; la orden sin destinatarios queda `FALLIDO` con motivo; un correo `FALLIDO` vuelve a `PENDIENTE` al reemitir; regenerar un PDF conserva `emitida_en` y la huella original; la etapa del PDF con candado; la cabecera de `config.hostinger.php` declara `emision_modo`, `correo_fijos`, `correo_por_zona`, `dompdf_autoload`, `enlace_secreto`. `Auth.php`: la sesión **caduca de verdad** por inactividad (el sondeo no la renueva) y a las 12 h; `salir.php` por POST y cookie invalidada; `session.use_strict_mode`; la clave no vuelve al HTML al desplazar una sesión; `clave.php` cuenta y bloquea intentos; IP real detrás del CDN solo si se comprueba en vivo; denegaciones sin sesión registradas con tope. `.htaccess`: CSP, `Permissions-Policy`, dotfiles y copias bloqueadas. `Ui.php`: `json_encode` con `JSON_HEX_*`, módulos con lista de permisos, entradas de navegación nuevas (Archivo, Aprendizaje, Bitácora). `instalar.php` fuera del despliegue y borrado del servidor | H-03, E-02, E-10, E-13, E-15, E-16, E-21, SEG-05, SEG-08, SEG-10, SEG-11, SEG-14, SEG-16, SEG-20, SEG-22, SEG-24, SEG-25, TR-20 | `php emitir_pendientes_cli.php` sobre una captura con `emision_error` forzado → PDF generado y `emision_error` en NULL; `php despachar_correo_cli.php` en modo PRUEBA → «0 enviados, N retenidos» y ninguna conexión SMTP; `curl -I` a `panel.php` con sesión → `Content-Security-Policy` y `Cache-Control: no-store`; `GET /ot/.gitignore` → 403; `verificar_http.py` amplía: 121 minutos sin más peticiones que `novedades.php` → sesión vencida (302) |
| **T2.14.7** | **Las pruebas.** `prueba_48h.php` con rutas relativas y sus 4 comprobaciones reescritas contra lo que hoy calcula el sistema (o retiradas con motivo escrito); `prueba_offline.mjs` toma PHP y navegador de `PHP_BIN`/`CHROME_BIN` con Edge de reserva; todos los `verificar_*.py` con `sys.stdout.reconfigure(encoding='utf-8')`; **cruce automático de clases CSS usadas contra definidas** en `prueba_contratos.mjs`; comprobaciones nuevas: CSRF en cada POST, alcance de `asignacion.php` por zona, PDF del técnico, cola de correo en PRUEBA, emisión con dompdf ausente | DOC-09, DOC-10, TR-16, frente «pruebas» de la auditoría | Desde el PC de Andrés, con el PHP 8.2 portable: `php prueba_48h.php` → **96 · 0**; `node prueba_contratos.mjs` → todas en verde incluido el cruce de clases; `node prueba_offline.mjs` arranca y termina en verde con `PHP_BIN` apuntando al portable |
| **T2.14.8** | **El paquete del piloto** en `SALIDAS IA\OTS\paquete_piloto_uio\`: `HOJA_TECNICO.md`, `HOJA_JEFE_ZONA.md`, `HOJA_ADMINISTRACION.md` (con capturas del sitio de pruebas y los flujos completos), `CUENTAS.md` (URL, cómo se entregan las claves iniciales, cambio obligatorio), `QUE_PROBAR.md` (guion por rol, día 1 a día 5), `COMO_REPORTAR_FALLOS.md` (canal y qué datos enviar), `ANTES_DE_EMPEZAR.md` (deshacer los datos de prueba, verificar las cuentas). Además la documentación técnica al día: `publico/LEEME.md`, `app/LEEME.md`, `LEEME_ACCESO_HOSTINGER.md` en dos partes (pruebas vigente / corte diferido), cabecera de `PLAN_APP_GESTION.md`, cabeceras de la 007 y la 003 de agentes | DOC-01, DOC-11, DOC-12, DOC-13, DOC-17, DOC-18, DOC-22, DOC-23, T2.12.12 | Las tres hojas existen, cada una con al menos 6 capturas reales del sitio de pruebas y ningún dato de una persona real; `CUENTAS.md` no contiene ninguna clave |
| **T2.14.9** | **Integración y despliegue.** Un solo `estilo.css` con las secciones nuevas; `Ui::MODULOS`/`LISTOS` con las pantallas nuevas; `sw.js` sube `VERSION`; `t2_10_desplegar.py` con los archivos nuevos en `ARCHIVOS`; `composer.json` de `app/lib` con PhpSpreadsheet, PhpPresentation y PHPMailer; la 009 aplicada en el sitio de pruebas con respaldo previo; despliegue verificado contra la web; las cinco baterías del servidor en verde; capturas de las pantallas por rol (Edge sin ventana) revisadas a 400 y 1400 px; `ESTADO.md` y §11b al día; commit y push de la rama `pc/pulido-2026-09-12` | — | `t2_10 --todo` sube sin faltantes; `verificar_http/bandeja/ciclo/emision` ≥ 148 en verde más las nuevas; `git status` limpio y `origin/pc/pulido-2026-09-12` igual al local |

| Autónomo | Requiere aprobación de Andrés | Prohibido |
|---|---|---|
| Todo el código, las pruebas locales, aplicar la **009 en el sitio de pruebas** (decisión de Andrés del 2026-09-10: darkviolet se cambia sin pedir permiso cada vez), desplegar a darkviolet, crear cuentas y datos de prueba, correr las verificaciones, escribir el paquete del piloto | Subir los PDF históricos a Hostinger (D2); programar los cron en hPanel; crear el segundo usuario MySQL; borrar las órdenes de prueba de la serie 9000; cualquier decisión por defecto que quiera cambiar | Tocar producción; enviar un correo desde darkviolet; borrar datos del cliente; instalar nada de pago; leer o pegar credenciales |

**Aceptación global de T2.14:** un técnico, un jefe de zona y la administradora de prueba recorren
las tres hojas del paquete del piloto de punta a punta en el sitio de pruebas sin encontrar una
pantalla en blanco, un botón que no hace lo que dice ni un dato que se pierda; los dos fallos que
Andrés reportó el 2026-09-12 están cerrados y verificados contra el servidor; y la línea base de
148 comprobaciones sigue en verde con las nuevas encima.

---

### T2.15 · Saneamiento continuo de lo que produce el sistema viejo (desde ya, en la estación)

**Pedido de Andrés del 2026-09-12:** sanear la información que el sistema antiguo sigue generando
hasta el cambio. Hoy nada de esto corre: `t2_4_sync_hostinger.py` **nunca ha corrido** porque exige
claves `HOSTINGER_*` que `config/.env` no tiene (SSH funciona desde el 9-sep con otro contrato de
llave y usuario); no hay espejo de `ordenes_pdf/` ni volcado de la base operativa; los PDF que baja
`t2_11` caen dentro del árbol canónico y la ingesta los toma como órdenes con nombre crudo; la purga
no exige segunda copia; y no existe orquestador nocturno.

| # | Subtarea | Verificación exacta |
|---|---|---|
| **T2.15.1** | `scripts/comun.py` y `scripts/hostinger_ssh.py`: una sola forma de cargar `.env`, resolver la llave (`INDUSTEC_LLAVE_SSH` o `config/clave_hostinger`), host, puerto y usuario, con el docroot de producción **solo para leer**; `t2_4_sync`, `t2_4_purga` y `t2_11` importan de ahí; rutas relativas a la raíz del repositorio en los cinco scripts que las tenían pegadas | `python -c "import scripts.hostinger_ssh"` y `t2_4_sync_hostinger.py --probar` conectan desde la estación y desde el PC con la llave de cada uno |
| **T2.15.2** | El espejo cubre **también el sistema nuevo**: `ordenes_pdf/` y `ordenes_fotos/` del sitio de pruebas a `D:\RESPALDOS\_ORIGEN_APP\`, y `t2_4_volcado_bd.py` baja cada noche el volcado de `u671729428_ots` (mysqldump por SSH con `--defaults-extra-file` temporal, sha256 remoto y local, retención 30 diarios + 12 mensuales) | Segunda corrida seguida: `bajados = 0`; el volcado del día abre con `gzip -t` y su sha256 coincide con el remoto |
| **T2.15.3** | Un PDF divergente **no sobreescribe** el espejo: va a `_divergentes/<nombre>.<sha12>.pdf` y al manifiesto; el script sale con 1 si un módulo entero falla o si baja a 0 un módulo que tenía archivos; `t2_11` guarda en `_ORIGEN_BUZON\` y la ingesta ignora toda carpeta que empiece por `_` | Prueba con dos archivos del mismo nombre y distinto contenido en `t2_4_pruebas.py`; `SELECT COUNT(*) FROM ots WHERE ruta_pdf LIKE '%\_ORIGEN%'` → 0 |
| **T2.15.4** | `saneamiento_nocturno.py`: orquestador con candado de instancia única que corre sync → volcado → normalización → ingesta → informes, aborta al primer fallo, escribe `logs/saneamiento-<fecha>.log`, una fila en `bitacora` y `SALIDAS IA\OTS\estado_nocturno.json`; Tarea programada «INDUSTEC - Saneamiento nocturno» (aunque nadie haya iniciado sesión; reintento a los 30 min); la consola local muestra el semáforo de anoche | `schtasks /query /tn "INDUSTEC - Saneamiento nocturno"`; el JSON de estado de anoche tiene `ok: true` y las cifras de cada paso |
| **T2.15.5** | La purga exige **dos copias**: hash en `ots.hash_pdf` **y** el archivo con el mismo hash en la ruta de la segunda copia (`SEGUNDA_COPIA` en `.env`, el equipo Veeam/TrueNAS); mientras no exista, solo informa. Retención en `.env` (`PURGA_RETENCION_DIAS`, 90 hasta D+30 del corte) | `t2_4_purga_hostinger.py --ejecutar` sin `SEGUNDA_COPIA` → «0 borrables, motivo: sin segunda copia» |

Requiere aprobación de Andrés: crear la Tarea programada en la estación y fijar la ruta de la segunda
copia. Prohibido: escribir en producción, borrar en el servidor sin las dos compuertas.

---

### T2.16 · El corte y la unificación (después del piloto)

**Pedido de Andrés del 2026-09-12:** superada la fase piloto, limpiar y unificar el funcionamiento
del sistema del modo que dé más velocidad y seguridad. Hoy no existe procedimiento de corte.

**Precondiciones (todas verificables):** piloto en UIO con ≥ 50 correctivas y 5 preventivas sin una
orden perdida ni duplicada; KFC vio el PDF nuevo; T2.14 cerrada; T2.15 corriendo cada noche con
`ok: true` 7 noches seguidas; segunda copia existente y verificada; dominio definitivo decidido
(`ot.industec.me` es del cliente); titularidad y renovación del hosting resueltas (vence el
2026-12-18); contrato de encargo LOPDP firmado.

| Paso | Qué | Camino de vuelta |
|---|---|---|
| 1 | **Congelar**: aviso a técnicos; el formulario viejo pasa a mostrar un aviso con el enlace nuevo **pero sigue aceptando envíos 7 días** | Quitar el aviso |
| 2 | **Contadores reales**: `t2_14_sembrar_correlativos.py` lee dos veces (60 s aparte) `counter_{zona}.txt` de cada módulo, toma `max(contador, MAX en MariaDB < 90000, MAX en Hostinger) + margen`, escribe `correlativos`, deja acta en `SALIDAS IA\OTS` y aborta si ya hay una serie sembrada | Restaurar el volcado previo de `correlativos` |
| 3 | **Destinatarios y modo**: `config.php` con `correo_fijos`, `correo_por_zona`, `emision_modo = PRODUCCION`, `enlace_secreto` propio; un envío de prueba a un buzón interno antes de habilitar a los locales | `emision_modo = PRUEBA` |
| 4 | **Cron de hPanel** (los programa Andrés): reemisión cada 10 min, despachador de correo cada 5 min, purga diaria de huérfanos | Borrar el cron |
| 5 | **Seguridad de producción, en bloque** (diferida el 2026-09-10): `produccion.htaccess`, `guardas.php` en los tres módulos, borrar `phpinfo.php` y `debug_firma.txt`, `display_errors=0`, **rotar la contraseña SMTP primero**, WordPress fuera del sitio o actualizado; PDF y fotos **fuera del docroot** (D12); segundo usuario MySQL sin `DROP/ALTER` para la web | Respaldo del sitio y de la base antes de cada paso |
| 6 | **Dominio definitivo** y **retiro de los formularios viejos** al día 8 sin envíos nuevos; `t2_11` se apaga; el espejo del sistema viejo pasa a solo histórico | El formulario viejo se conserva 30 días en el servidor, sin enlace |
| 7 | **Unificación**: una sola fuente de la verdad por dato (la orden nace en Hostinger; la estación ingiere filas, no solo PDF); `t2_10` con perfil `--destino produccion` que exige `--confirmo-produccion` y la ruta positiva de producción; purga a 30 días con las dos copias; `saneamiento_nocturno` cubriendo el sitio definitivo | — |

Criterio de aborto escrito de antemano: **una orden perdida o dos duplicadas en el piloto → se
vuelve atrás** y se investiga antes de reintentar. Todo paso de T2.16 requiere autorización de
Andrés en el momento (regla 9 del `CLAUDE.md`).

---

### T2.17 · La web corporativa: rediseño (2026-09-12)

**Pedido de Andrés:** rediseño de imagen y navegabilidad, moderna e intuitiva, que exhiba y venda
mejor el servicio **con la información que ya tiene** (los textos pasaron las aprobaciones de
César: no se cambian los hechos, sí la forma). **Dirección de arte fijada por Andrés el mismo día**
con tres referencias (portadas de *In Tune*, Scott Foresman): ilustración de trazo negro limpio y
color plano, figuras estilizadas en acción, frases manuscritas flotando —**en el contexto industrial
y de servicio técnico**: cocinas de cadena, freidoras, campanas, cámaras frías, técnicos con overol.
Guía completa en [`desarrollo/web_corporativa/DIRECCION_ARTE.md`](desarrollo/web_corporativa/DIRECCION_ARTE.md).

Lo que la auditoría encontró y el rediseño resuelve: la oferta (los cinco servicios) enterrada bajo
38 logos; el único botón destacado del menú era el acceso del personal; el rojo servía a la vez de
botón principal y de urgencia; tipografía solo `system-ui`; cabeceras todas centradas y 18 tarjetas
iguales; la consultoría gratuita como enlace subrayado; sin fotos en «Nosotros».

| # | Subtarea | Verificación exacta |
|---|---|---|
| **T2.17.1** | **Ilustraciones** SVG a mano en el estilo fijado: portada (cocina de un local de cadena con técnicos en acción y frases flotantes), cinco viñetas de servicios, una escena monocroma para Nosotros/Contacto, imagen para redes 1200 × 630 | Cada SVG < 60 KB, sin degradados ni filtros, con `role="img"` y `aria-label`; revisadas en captura a 390 y 1440 px |
| **T2.17.2** | **Sistema visual**: tipografía de titulares autoalojada (Barlow, OFL, subconjunto latino en woff2, ≤ 60 KB en total; `system-ui` en el cuerpo); escala derivada del azul del logo (#48537E) para fondos y botones; rojo #CC504B solo como acento y urgencia; verde solo para WhatsApp; tres registros de sección (venta a dos columnas, prueba en banda, apoyo en rejilla compacta) | `verificar-sitio.mjs` en verde; peso total < 1,5 MB; contraste AA en `capturar-sitio.mjs` |
| **T2.17.3** | **Portada y navegación**: primera pantalla con la ilustración y dos botones (consultoría gratuita por WhatsApp y emergencias 24/7 · llamar); WhatsApp como botón primario del menú, «Acceso del personal» al pie; servicios **antes** que logos; clientes y marcas en una sola franja compacta (gris, color al pasar); franja de confianza reformulada sin cambiar los hechos; misma jerarquía en las cinco páginas; formulario de contacto con enlace real de WhatsApp (sin clic sintético) | Las cinco páginas pasan `verificar-sitio.mjs --sellar --manifiesto`; capturas a 390/1024/1440 px revisadas; `/acceso/` intacta en función |
| **T2.17.4** | **Publicación** en la raíz de darkviolet con `publicar_sitio.py --si` y `verificar-publicacion.mjs` hasta OK; `/ot/login.php` sigue en 200 y `/ot/catalogos/locales.json` en 403 | Salida literal de las dos herramientas en `LEEME.md` §10 |

Requiere aprobación de Andrés: las cinco URL propias por servicio (SEO) y cualquier foto o dato
nuevo (NOTAS_PARA_CESAR). Prohibido: recursos externos, `.htaccess` en la raíz, tocar `ot/`.

---

### T2.18 · Archivo clasificado por zona y franquicia, y el enlace con «pedir copia»

**Pedido de Andrés del 2026-09-13:** que el archivo de órdenes se organice y se mantenga al día
en `SALIDAS IA\ARCHIVO OTS INDUSTEC\<ZONA>\<CADENA>\` —por zona y por franquicia (KFC y las
demás), **como se llevaba antes a mano en Google Drive**—, tanto para el histórico ya reorganizado
como para lo que se va reclasificando, y que «el sistema publicado de archivos» (el Archivo de
`ordenes.php` / `ot_archivo`, T2.14.4) lea y cargue esos PDF.

**Lo que ya existía y no se repitió:** el árbol canónico (`D:\RESPALDOS\ORDENES DE
TRABAJO\<año>\<módulo>\<zona>\<cadena>\`, T1.6/T2.4) **ya** clasifica por zona y cadena — es la
misma información, solo que no está donde la administración la ve a diario (`RESPALDOS` no está
sincronizado con Drive, decisión del cliente del 2026-09-04: el histórico no cabe). Y el Archivo
publicado (T2.14.4) **ya** tiene el mecanismo de «pedir copia» (`ot_archivo_solicitudes`) para
cuando alguien necesita un PDF que solo vive en la estación — lo único que faltaba era quién
atendiera esas solicitudes. **No se tocó nada de T2.14/T2.15**, ambas ya cerradas y con sus
baterías en verde: esta tarea es dos scripts nuevos que leen de lo que ya existe.

| # | Subtarea | Qué hace | Verificación exacta |
|---|---|---|---|
| **T2.18.1** | `t2_18_clasificar_archivo.py` | Espeja `D:\RESPALDOS\ORDENES DE TRABAJO\*\*\<ZONA>\<CADENA>\*.pdf` a `SALIDAS IA\ARCHIVO OTS INDUSTEC\<ZONA>\<CADENA>\`, aplanando año y módulo (Andrés pidió dos niveles). Simula por defecto; `--ejecutar` copia de verdad, con copiar→verificar por hash→recién renombrar (I-4). Es la MISMA corrida para el histórico completo y para lo que `t1_7_ingesta.py`/`t2_4_normalizar_nuevas.py` van sumando al árbol canónico: no distingue viejo de nuevo, solo lo que falta o cambió. Un nombre con tamaño distinto al del canónico **nunca se sobreescribe** (divergente, al manifiesto); un PDF que el canónico ya no tiene se reporta como huérfano y **nunca se borra solo** (I-2/I-3). Ignora toda carpeta que empiece por `_` (T2.15.3) | `t2_18_pruebas.py` sobre un árbol sintético (no toca datos reales): simulación no escribe nada; ejecución real copia y aplana; ignora `_ORIGEN_BUZON`; segunda corrida da 0 copiados/100% al día; una divergencia de tamaño se reporta y NO se sobreescribe; un huérfano se reporta y NO se borra. **Corrido el 2026-09-13: 6/6 en verde** |
| **T2.18.2** | `t2_18_atender_pedidos_copia.py` + `hostinger_ssh.scp_subir()` (nuevo, simétrico a `scp_bajar`) | Lee por SSH (mismo contrato de `hostinger_ssh.py`, nunca toca producción) las solicitudes de `ot_archivo_solicitudes` sin atender; ubica el PDF (1º en el clasificado de T2.18.1, 2º buscando por nombre ahí mismo, 3º en `ot_archivo.fuente_ruta`); si lo encuentra, lo sube por `scp` a `ordenes_pdf/` de **darkviolet únicamente** y corre `archivo_indexar_cli.php --solo-pdf`; recién entonces marca `atendido_en`. Si no lo encuentra en ningún lado, la deja pendiente y lo dice — nunca marca atendida a ciegas. Simula por defecto; `--limite` (20 por omisión) tapa un lote inesperado. **No es la decisión D2** (subir los 5 GB completos sigue pendiente de Andrés): esto solo sube, una por una, las copias que alguien pidió de verdad con el botón | Simulación (sin `--ejecutar`) corrida en vivo el 2026-09-13 desde el PC contra darkviolet (solo lectura: ninguna fila se tocó): **1 solicitud real sin atender** (`OT-2503-K146-10354374-CNLJ`, origen CORREO, sin `fuente_ruta` — nunca se descargó del buzón, así que hoy no se puede completar ni con este script; queda para `t2_11_informes_ot.py` o pedirlo al local). El guardián de escritura de `hostinger_ssh.py` (ya probado en T2.15) impide que este script toque producción aunque se intentara |

**Qué NO hace esta tarea, a propósito:** no decide D2 (subir el histórico completo a Hostinger),
no cambia `saneamiento_nocturno.py` (T2.15, ya cerrada y verificada — añadir un paso ahí es una
línea, pero se deja para cuando la estación confirme que el orden no le estorba a su ventana
nocturna), y no borra nada: divergentes y huérfanos se reportan para que una persona decida.

| Autónomo | Requiere aprobación de Andrés | Prohibido |
|---|---|---|
| Correr `t2_18_clasificar_archivo.py --ejecutar` sobre el árbol canónico real; correr `t2_18_atender_pedidos_copia.py --ejecutar` (sube solo lo que alguien ya pidió con el botón, a darkviolet) | Subir el histórico completo a Hostinger sin que medie «pedir copia» (sigue siendo D2); agregar el clasificador a `saneamiento_nocturno.py` | Tocar producción (`yellow-elephant`); borrar un divergente o un huérfano sin que Andrés lo pida en el momento (I-2/I-3) |

**Pendiente de la estación** (esto no se pudo ejecutar desde el PC: `D:\RESPALDOS` y
`D:\INDUSTECH IA` no existen aquí — ver §11b):
1. `git merge` de la rama de esta tarea (ver el commit de cierre) y confirmar que `t2_18_pruebas.py` sigue en verde ahí. ✅ **Hecho el 2026-09-13**: fusionadas `pc/pulido-2026-09-12` y `pc/archivo-zona-franquicia-2026-09-13` en `master`, las dos por fast-forward limpio. `t2_18_pruebas.py`: 6/6 en verde.
2. `python scripts/t2_18_clasificar_archivo.py` (simulación) para ver el conteo real contra los 7.070 del corpus, y recién entonces `--ejecutar`. Con ~5 GB por copiar, revisar antes que el Drive de INDUSTEC tenga espacio o que la sincronización de `SALIDAS IA` esté pausada mientras corre la primera carga completa (I-2: nunca hay que dejar a medias un archivo dentro de una carpeta sincronizada — este script sí hace copiar→verificar→recién-entonces-mover, pero un RAR de 1,5 GB ya mostró que el enlace a Drive de esa carpeta es real). **Simulación corrida el 2026-09-13: 7.070 por copiar, 0 divergentes, 0 huérfanos — cuadra con la base.** `--ejecutar` **NO se corrió todavía**: `G:\Mi unidad` (el cupo real de Google Drive) mostró solo 4,3 GB libres frente a los ~5 GB a copiar, y esto se desvió hacia el punto 5 (abajo) antes de decidir cómo seguir con la copia.
3. Con eso corrido, `python scripts/t2_18_atender_pedidos_copia.py --ejecutar` para la única solicitud real pendiente y las que se acumulen; considerar si conviene una Tarea programada aparte (no la del saneamiento) para que corra cada hora, como el propio `archivo_indexar_cli.php` sugiere para el cron de hPanel. **Pendiente**, depende del punto 2.
4. Decidir si conviene sumar el paso 2 al final de `saneamiento_nocturno.py` (una llamada, sin tocar lo demás) para que el clasificado quede al día solo, o si se prefiere seguir corriéndolo aparte. **Pendiente.**
5. **Hallazgo nuevo, resuelto el mismo 2026-09-13:** al simular el punto 2 apareció `D:\RESPALDOS\ORDENES DE TRABAJO\_DEL_BUZON\`, una carpeta de 164 PDF **no documentada en ningún script** (`comun.py` apunta a `_ORIGEN_BUZON`, que no existe) y que ni `t1_7_ingesta.py` ni `t2_4_normalizar_nuevas.py` recogían — los dos ignoran toda carpeta que empiece por `_`. Verificados uno por uno contra la base por hash y por (correlativo, aviso):
   - **107** eran duplicados exactos de un PDF que ya estaba en el árbol canónico y en la base — inofensivos.
   - **53** no existían en la base bajo ningún correlativo: llevaban desde el 2026-09-09 (4 días) sin clasificarse ni ingestarse. **Rescatados con el nuevo `t2_18_rescatar_buzon.py`** (reutiliza el `resolver()` de `t2_4_normalizar_nuevas.py`, sin duplicar el criterio), promovidos al árbol canónico y cargados con `t1_7_ingesta.py`: los 53 entraron activos, ninguno en cuarentena. **Base: 7.069 → 7.118 órdenes activas.**
   - **4** tienen el mismo número de aviso que un registro YA existente en la base, pero con **otro correlativo** — conflicto real de numeración que el script detecta y **no autorresuelve** (I-2 nivel 3): `OT-1687-G021-10347027-UIO` (la base tiene correlativo 1674), `OT-1903-K066-10341493-LARB` (base: 1902), `OT-2241-K147-10348578-LARB` (base: 2103 y 90051), `OT-2472-CN42-10347141-CNLJ` (base: 2180). **Pendiente de decisión de Andrés**: cuál correlativo es el correcto en cada caso.
   `_DEL_BUZON` **no se tocó**: quedan sus 164 PDF originales, sin borrar (I-2) — los 111 sobrantes (107 duplicados + 4 en conflicto) son candidatos a limpieza una vez resueltos los 4 conflictos, pero eso no lo decide un script.

### T2.19 · Todos los PDF de orden en el servidor, sin «pedir copia» (2026-09-14)

**Pedido de Andrés del 2026-09-14**, con capturas del buzón: «Quiero que no sea necesario pedir
copia a la estación y se muestren todos los pdfs de todos los casos y órdenes atendidas y en
curso». **Decidió, con las opciones delante:** subir **todo** el histórico más lo nuevo cada noche,
y **solo desde la estación** — no se copia de producción a darkviolet dentro del servidor, ni en
solo lectura. **Revoca D2.** El mismo informe llega con dos nombres (`OT-2488-K061-…` en
`_DEL_BUZON`, `OT-2488-K061EC-…` en el árbol canónico): suben los dos y el buzón los junta por aviso.

| # | Subtarea | Qué hace | Verificación exacta |
|---|---|---|---|
| **T2.19.1** | `t2_19_subir_pdfs.py` | Inventario del árbol canónico y de `_DEL_BUZON` con sha256; inventario del servidor (`find` de `ordenes_pdf/` y `ot_archivo.sha256`, sin re-hashear el hosting); lo que falta va en lotes .tar con manifiesto a `~/respaldos/subida_pdf/`, se verifica con `sha256sum -c`, se mueve con `mv -n` a `ordenes_pdf/`, se verifica otra vez, se borra el temporal y se corre `archivo_indexar_cli.php --solo-pdf`. Colisión en la estación y divergente en el servidor: se reportan, ni se suben ni se pisan (I-11). Nombres que `pdf.php` no sirve y carpetas `_…` apartadas: se cuentan. Simula por defecto | `t2_19_pruebas.py`: **16/16** (2026-09-14, PC). Punta a punta con `--destino-prueba` contra el servidor: 7 en 4 lotes verificados, segunda corrida 0, divergente no pisado, huellas iguales, 0 temporales (salida en `ESTADO.md` §1b) |
| **T2.19.2** | Paso `pdfs` en `saneamiento_nocturno.py` | Último de la noche, a propósito: un lote que falle no frena el catálogo del archivo | `saneamiento_nocturno.py --ensayo` en la estación lo muestra como 7.º paso |
| **T2.19.3** | **Primera carga, en la estación** | Simulación (cifra real de lo que falta, colisiones y nombres fuera de patrón) → `--ejecutar --limite 500` para medir el tiempo → `--ejecutar` | En el buzón, 10354415, 10354383 y 10351229 abren su PDF; en el Archivo, «Solo en la estación» cerca de 0 |

| Autónomo | Requiere aprobación de Andrés | Prohibido |
|---|---|---|
| Correr `t2_19_subir_pdfs.py --ejecutar` en la estación y dejarlo en el saneamiento | Servir el patrón con el correlativo al final (tocar `Emision::PATRON_OT` y `pdf.php`, 161 documentos); resolver una colisión o un divergente | Copiar desde producción; pisar un PDF del servidor; borrar un original |

### T2.20 · «Otros trabajos»: los extras que INDUSTEC hace para KFC fuera de su área (2026-09-14)

**Pedido de Andrés del 2026-09-14:** hay casos excepcionales en que, por un acuerdo interno con
KFC, INDUSTEC hace un trabajo fuera de su área y emite el informe — puede llegar un informe
constructivo de una OT que no nos llegó o no se nos pidió. La OT es válida, el trabajo se hizo, y
**se contabiliza y se le reporta a KFC como extra**. Cuando KFC manda un caso fuera del área, **la
administradora decide**: lo acepta (hubo acuerdo) o lo cierra y le pide a KFC que lo derive al área
o proveedor que corresponde. **Siempre decide ella.**

**Decisiones de Andrés, con las opciones delante:** es una **marca aparte del estado** (el caso sigue
su flujo y la marca dice si cuenta como extra); el **módulo OTROS** del sistema viejo son extras de
KFC dentro del mismo trato y van con los otros trabajos; **«otros clientes»** —locales o cadenas
fuera de KFC— es otra evaluación y un reporte interno aparte, secundario: no entra aquí.

| # | Subtarea | Qué hace | Verificación exacta |
|---|---|---|---|
| **T2.20.1** | Migración `011_otros_trabajos.sql` | `casos_gestion.otro_trabajo` (AUTORIZADO / NO_AUTORIZADO; NULL = sin decidir), el acuerdo, quién y cuándo | Aplicada el 2026-09-14; `verificar_esquema.php` → bloque «migracion 011» y TODO OK |
| **T2.20.2** | Núcleo y buzón | `Casos::fueraDeArea()` y `Casos::otroTrabajoPorDecidir()`, la única definición; acción `otro_trabajo` en `casos.php` (solo `casos.veredicto`, acuerdo obligatorio, bitácora `OTRO_TRABAJO`); botón en los casos con alerta o ya decididos; marca visible; filtro `?otro=`; el veredicto «no nos compete» dice que se pide a KFC derivarlo | 7 casos por decidir en darkviolet el 2026-09-14 |
| **T2.20.3** | Panel y reportes | La tarea «fuera del área, por decidir» reemplaza a «con alerta de alcance», que nunca bajaba; la alerta vieja ya no cuenta los decididos; «Otros trabajos para Grupo KFC» en pantalla, hoja de Excel, diapositiva de PowerPoint y sección del PDF, con el conteo del módulo OTROS del Archivo | `Reportes::calcular()` bajo superadmin en memoria: 927 casos, 7 por decidir, 0 autorizados; el HTML del PDF trae la sección |
| **T2.20.4** | **Lo que falta** | (a) probar la acción y las descargas con una sesión real, empezando por el 10351229; (b) proponer también los informes de órdenes **sin aviso** o de avisos que nunca llegaron al buzón (hoy solo entran los que tienen fila de gestión); (c) el conteo del módulo OTROS espera el catálogo histórico de la estación (hoy `ot_archivo.modulo` está vacío en las 164 filas); (d) el reporte interno de otros clientes | — |

| Autónomo | Requiere aprobación de Andrés | Prohibido |
|---|---|---|
| Ajustar textos, filtros y reportes de la marca | Cambiar quién decide (hoy administración y superadmin) o que algo se marque solo | Que el sistema autorice o cierre un caso por su cuenta |

---

### T2.21 · Cerrar la cadena del correo: del informe al árbol, a la base y al caso (2026-09-18)

**De dónde sale:** Andrés pidió el 2026-09-18 validar si el robot del correo
identifica los informes nuevos, los clasifica en el respaldo, los enlaza al caso
abierto y los muestra en el buzón del técnico. La auditoría (26 agentes, 21
completados; el detalle y las cifras en `ESTADO.md` §1c) encontró la cadena
**cortada en dos sitios**, y el corte no es intermitente: es estructural.

**El cuadro medido, para no volver a levantarlo:** `t2_11_informes_ot.py` sí lee
el correo y sí empuja `atenciones.json` (6 corridas el 2026-09-18, 129 informes
sobre 121 casos), y el enlace por número de aviso funciona — la distinción
«INDUSTEC atendió» ≠ «SAP cerró» se respeta en el código y en la pantalla, así
que **ahí no hay riesgo frente a KFC**. Lo que está roto es el resto: **104
informes en `_ORIGEN_BUZON`, 0 clasificados, 0 en `ots`, 0 en el servidor**, y
**76 casos cerrados invisibles porque su informe está en `Trash`**.

**Decisiones ya cerradas, para que no las tome el agente en ejecución:**

- El promotor **no se escribe de cero**: se parametriza el origen de
  `t2_18_rescatar_buzon.py` (hoy `BUZON = CANONICO / "_DEL_BUZON"`, línea 52) y
  se vuelve un paso del nocturno. Ya reutiliza `resolver()` y por hash, y ya
  rescató 53 OTs: el criterio está probado.
- **Se renombra a canónico ANTES de ingestar.** Los nombres del correo vienen sin
  el sufijo `EC` del local (`K167`, no `K167EC`) y **0 de 49 códigos existen en
  `locales`**: apuntar la ingesta a la carpeta sin renombrar haría fallar las 104
  filas por clave ajena.
- **Los conflictos no se autorresuelven** (I-10/I-11). Dentro del propio
  `_ORIGEN_BUZON` hay 4 avisos con dos correlativos distintos y contenido
  distinto (10354785, 10353502, 10355047, 10354784): esos 8 archivos van a
  revisión humana el primer día, y eso **no es un fallo del promotor**.
- **`avisos_sap` está fuera de cobertura** (I-12): `MAX(aviso)=10351627`, y solo
  4 de los 100 avisos del buzón están en la tabla. El cruce por centro de coste
  no está disponible para 96 de 100, así que el promotor **resuelve por el
  maestro de locales y declara «fuera de cobertura SAP»**, nunca manda los 96 a
  cuarentena por eso.
- **Las carpetas del buzón se declaran explícitamente.** Entran `INBOX`,
  `Trash` e `INFORMES OT`; `Spam`, `Sent`, `Drafts`, `Scheduled`, `Archive`,
  `Borrador` e `INBOX/SIR (1) (1)` quedan fuera, y la lista va en el código con
  su motivo. Leer `Trash` es solo lectura igual que el resto (EXAMINE + PEEK):
  **no se restaura ni se mueve nada** (I-2, I-3).

| # | Subtarea | Qué hace | Criterio de aceptación exacto |
|---|---|---|---|
| **T2.21.1** | Leer las carpetas que faltan | Lista blanca de carpetas en `t2_6`, `t2_11` y `t2_9`, con contador por carpeta en el resumen. EXAMINE + `BODY.PEEK[]` en todas | `t2_11_informes_ot.py --sin-pdf` imprime una línea por carpeta leída, y los **76 casos** hoy pendientes cuyo informe de cierre está en `Trash` pasan a tener atención. Prueba negativa: `grep -nE "M\.(store\|copy\|move\|expunge)" scripts/t2_*.py` no devuelve nada |
| **T2.21.2** | Promover `_ORIGEN_BUZON` al árbol canónico | `--origen` en `t2_18_rescatar_buzon.py`, renombrado a canónico por maestro antes de copiar, manifiesto reversible (I-5), copiar→verificar por hash→**no borrar** (I-2) | Simulación: de los 104, dice cuántos promueve, cuántos van a revisión y por qué, con los 8 del conflicto de correlativo identificados por nombre. Tras `--ejecutar` + `t1_7_ingesta.py`: `SELECT COUNT(*) FROM ots WHERE fuente='IMAP_EN_VIVO'` > 0 — **hoy da 0 de 7.469** |
| **T2.21.3** | Que el PDF llegue al servidor | El promotor deja el archivo dentro de `ORDENES DE TRABAJO`, que es lo que `t2_19_subir_pdfs.py` ya recorre. No se toca `t2_19` | `t2_19_subir_pdfs.py` en simulación pasa de «faltan: 0» a «faltan: 104», y tras `--ejecutar` los 3 informes de cierre sin PDF (avisos 10355449, 10355399, 10355488) abren desde el buzón. Verificado por SSH, no por el log del script |
| **T2.21.4** | Que un fallo de empuje se note | `return empujar(...)` en `t2_11:546` y `sys.exit(1)`; reintento con espera en `comun.empujar()`; el `.bat` revisa el código de salida | Con el endpoint apagado a propósito, `t2_11 --empujar` sale con **1** y la Tarea programada registra un resultado distinto de 0. Hoy: 13 fallos de TLS el 2026-09-18 y `LastTaskResult 0` en todos |
| **T2.21.5** | «Las mías» del Archivo | `t2_15_exportar_archivo.py` exporta también la **persona resuelta** contra `tecnicos`, junto a la firma cruda; `ordenes.php` filtra por esa columna, no por `LIKE '%nombre%'` | El filtro pasa de **1 fila de 7.118** a las órdenes reales de cada técnico (Ortiz 359, Perdomo 586, Meléndrez 1.063 repartidas entre los dos hermanos) |
| **T2.21.6** | Lo que quedó sin verificar | Cerrar contra el servidor lo que esta auditoría solo pudo leer en el código: `casos_gestion`, `ot_archivo`, y las pantallas con una sesión real. Y refutar los 4 hallazgos de captación que quedaron sin refutador | Cada uno con su salida pegada. Lo que siga sin comprobarse **se dice** (I-7) |
| **T2.21.7** | Producción como fuente de informes, además del correo | Confirmado el 2026-09-19/20: producción (`yellow-elephant`) tiene los mismos 2.323 PDF del sistema viejo, con documentos que el correo nunca trajo (246 nuevos de verdad, ver más abajo). `t2_9_buzon_vigilante.py` ya dispara `t2_4_sync_hostinger.py --recientes N` al detectar un correo — el correo es la SEÑAL, producción la FUENTE, porque `uploads/` rota a ~3 meses y tampoco es permanente | `_ORIGEN_SISTEMA` con el espejo completo (verificado: 2.323/2.323, 0 fallidos, 0 divergentes); el vigilante deja `manifiesto_parcial_*` sin autorizar purga (`autoriza_purga: false` en el resumen) |

**T2.21.4 verificado el 2026-09-20**, con el criterio exacto del plan: endpoint apagado a propósito
(`INDUSTEC_ENV_PATH` a un `.env` con `SYNC_URL` inválida), `t2_11 --empujar` reintenta 3 veces (15 s,
30 s) y sale con **código 1** — antes salía con 0 y el Programador registraba éxito con datos sin
llegar al sitio.

**Solape medido por hash entre producción y lo que ya teníamos** (2026-09-20, cuadre exacto:
1.970+107+246 = 2.323): de los 2.323 PDF de producción, **1.970 ya estaban en el árbol canónico**,
**107 ya estaban en `_ORIGEN_BUZON`** (llegados por correo) y **246 son documentos nuevos de
verdad** que ni el árbol ni el correo tenían. La fuente nueva sí aporta.

### T2.21 — cinco hallazgos verificados por refutación adversarial (2026-09-20), que cambian T2.21.2/T2.21.3

Un workflow de 5 lentes + refutadores independientes (14 de 16 agentes completados; el
`journal.jsonl` del run `wf_0987ea74-def` tiene el detalle completo con comandos y salidas). Estos
**cinco sobrevivieron** a su refutador — **no se descartan, hay que resolverlos antes de correr
`--ejecutar` sobre T2.21.2/T2.21.3**:

1. **El criterio de aceptación de T2.21.2 es hoy inalcanzable.** `t1_7_ingesta.py` tiene el literal
   `'DRIVE_HISTORICO'` incrustado en sus cuatro `INSERT INTO ots` (líneas 106, 126, 186, 303) — no es
   parámetro, no hay `argparse`, y ningún `ON DUPLICATE KEY UPDATE` toca `fuente`. El criterio ya
   escrito arriba (`SELECT COUNT(*) FROM ots WHERE fuente='IMAP_EN_VIVO'` > 0) no puede pasar con el
   código actual, aunque toda la cadena funcione perfecto. **Arreglo, ya con las correcciones del
   refutador incorporadas:** los manifiestos que ya escriben `t2_18_rescatar_buzon.py` (columnas
   `origen`, `canonico`, `destino`, `sha256`) y `t2_4_normalizar_nuevas.py` (`estado, modulo,
   archivo_origen, nombre_canonico, destino, detalle`) ya identifican el origen — no hace falta
   columna nueva. `t1_7_ingesta.py` debe leer esos CSV y usar `fuente=IF(VALUES(fuente)=
   'DRIVE_HISTORICO', fuente, VALUES(fuente))` en los cuatro `ON DUPLICATE` (nunca degradar
   `IMAP_EN_VIVO`/`SISTEMA_DESCARGADO` de vuelta a `DRIVE_HISTORICO` en una re-corrida).
2. **El promotor del buzón (`t2_18_rescatar_buzon.py --origen`) nunca se encadenó a nada.** Ni al
   saneamiento nocturno (`PASOS` en `saneamiento_nocturno.py:47-58` no lo menciona), ni a ninguna
   Tarea programada (`schtasks` solo devuelve «Informes de OT» y «Vigilante del buzon»), ni lo llama
   `t2_11`. Por eso el árbol no recibe un archivo nuevo desde el 2026-09-13: no es que algo falle, es
   que nada lo dispara. **Arreglo, con los tres defectos que el refutador encontró en la propuesta
   original:** agregarlo a `PASOS` **entre `normalizar` e `ingesta`** (no después de `ingesta`, o lo
   promovido se queda un día entero fuera de la base) con la entrada exacta `["t2_18_rescatar_buzon.py",
   "--origen", "D:\\RESPALDOS\\_ORIGEN_BUZON", "--ejecutar"]` (sin `--origen` trabajaría sobre
   `_DEL_BUZON`, la carpeta vieja); y antes de encadenarlo, hacer que salga con código distinto de 0
   cuando haya `CONFLICTO_CORRELATIVO` o `COLISION` (hoy `main()` no hace `sys.exit`, así que el
   nocturno nunca se entera de un conflicto sin resolver).
3. **`t2_19_subir_pdfs.py` no ve `_ORIGEN_BUZON` ni `_ORIGEN_SISTEMA`: su «faltan: 0» es una
   tautología**, no una prueba de que todo está subido. Solo recorre `D:\RESPALDOS\ORDENES DE
   TRABAJO` (línea 77) y excluye toda carpeta que empiece por `_` salvo `_DEL_BUZON` (línea 102) —
   las otras dos cuelgan de `D:\RESPALDOS` directamente, nunca entran al `rglob`. Medido el
   2026-09-20: son **270 PDF invisibles para t2_19** (107 en `_ORIGEN_BUZON` + 163 que ya había en
   `_ORIGEN_SISTEMA` de una corrida previa). **El arreglo NO es contar esas carpetas como
   "pendientes"** —por I-2 el origen nunca se borra tras promover, así que seguirían contando para
   siempre, como ya pasa con los 164 de `_DEL_BUZON`—: hay que cruzar por **hash** contra lo ya
   promovido al árbol, nunca por nombre (el nombre cambia al normalizar).
4. **La deduplicación de `t2_18`/`t2_4` es por RUTA canónica, no por contenido** (`t2_18:157-166`,
   `t2_4:232-242`): si `resolver()` calcula un nombre distinto al que ya tiene ese mismo contenido en
   el árbol bajo otra ruta, se archiva una segunda copia. Medido contra los 2.323 de producción:
   **13 casos reales** (0 entre los 107 del correo — coinciden byte a byte con producción). La causa
   **no es** el correlativo ni el marcador de día, como se pensó al principio: es **local ambiguo**
   (`K073H015` resuelve a `H015EC` cuando el árbol ya lo tiene como `K073EC`) y **aviso corto
   descartado** (`1031` sin ceros a la izquierda vs `00001031` en el árbol). **El arreglo no es
   marcar `YA_ESTABA` en silencio si el hash calza en otra ruta** — esos 13 casos delatan un defecto
   real de `resolver()` (`OT-2341-K197H071` resuelve a `V058EC`, que huele a error): van a
   **revisión humana con las dos rutas pegadas**, igual que cualquier otra colisión (I-11).
5. **El saneamiento nocturno nunca corrió encadenado**: existe completo en código
   (`saneamiento_nocturno.py`, 7 pasos, aborta al primer fallo) pero la Tarea programada
   correspondiente **no existe** en Windows — confirmado con `schtasks` de primera mano.

**Cuatro hallazgos de la misma corrida que el refutador SÍ tumbó — no los repitas:**
- *"La FK de `locales` aborta el lote si llega un local sin EC"*: el escenario que lo dispara es
  imposible hoy — `t1_7_ingesta.py` no tiene `argparse`, su `RAIZ` es una ruta fija, y `_ORIGEN_BUZON`
  ni siquiera cuelga de ella.
- *"La UNIQUE KEY no protege contra la fuente nueva porque `dia_intervencion` es NULL"*: falso —
  `resolver()` ya normaliza el local y el día contra el maestro **antes** de nombrar el archivo, así
  que el correo y producción producen el mismo `id_industec` byte a byte para el mismo documento.
- *"Hay 342 filas de `ots` duplicando el mismo PDF, la cifra de 7.118 activas está inflada"*: falso
  — las 342 están **en cuarentena** (`SUPERSEDIDO_POR_NOMBRE_CANONICO`), es el mecanismo reversible
  de I-5 funcionando a propósito. Las 7.118 activas tienen 7.118 hashes distintos.
- *"7 archivos con el mismo aviso y otro local chocan contra `uq_ots_zona_correlativo_modulo`"*:
  falso — son correctivos con `dia_intervencion NULL`, y MariaDB no bloquea con NULL (ya hay 9 pares
  así conviviendo hoy sin problema).

**Sin verificar, por límite de sesión (I-7):** el refutador de *"aviso corto descartado"* murió antes
de correr — el hallazgo aparece mencionado dentro del punto 4 de arriba (`1031` vs `00001031`) pero
esa mención puntual no pasó por un refutador dedicado.

| Autónomo | Requiere aprobación de Andrés | Prohibido |
|---|---|---|
| Escribir y correr en **simulación** todo lo de arriba; leer cualquier carpeta del buzón con EXAMINE; espejar producción a `_ORIGEN_SISTEMA` (T2.21.7, es copia de solo lectura hacia `D:\RESPALDOS`, nunca escribe en el árbol ni en la base); commitear el código | El `--ejecutar` de T2.21.2 (mueve archivos al árbol y escribe en `ots`); el `--ejecutar` de T2.21.3 (sube PDF al servidor); encadenar `t2_18_rescatar_buzon.py` al nocturno; resolver los conflictos de correlativo y las 13 colisiones de contenido en otra ruta | Restaurar, mover o borrar un correo del buzón; borrar nada de `_ORIGEN_BUZON`, `_DEL_BUZON` ni `_ORIGEN_SISTEMA` aunque ya esté promovido; autorresolver un conflicto de correlativo o una colisión de hash; tocar `G:\Mi unidad`; escribir en producción |

---

### T2.22 · 203 casos con la orden ya archivada que `casos_gestion` no refleja (hallazgo del 2026-09-21)

**Por qué existe.** Andrés reportó el aviso 10353660: el buzón lo mostraba `ASIGNADO`
(sin orden emitida, sin cierre) pero la orden real ya estaba archivada y verificada
en `ot_archivo` desde el saneamiento del corpus histórico (T2.21), con técnico y
fecha de atención. Se regularizó ese caso puntual en dos pasos, replicando la
máquina de estados de `casos.php` en vez de forzar el estado a mano:
`ASIGNADO → ATENDIDO` (con `ot_cierre` y `atendido_en` tomados del propio
`ot_archivo`, que es la evidencia independiente — I-10) y después
`ATENDIDO → RESUELTO` ('cerrado_sap', a pedido explícito de Andrés para ese caso,
sin verificar contra SAP — mismo patrón que la regularización masiva de
`ESTADO.md` §1h). Bitácora: acciones `ATENDIDO_DESDE_ARCHIVO` y
`CERRADO_SAP_PUNTUAL`, aviso 10353660.

**El hallazgo es más grande que un caso.** Al dimensionarlo:

```sql
SELECT COUNT(*) FROM ot_archivo a JOIN casos_gestion g ON g.aviso = a.aviso
 WHERE a.origen = 'HISTORICO' AND g.estado IN ('NUEVO','ASIGNADO','EN_REVISION')
   AND g.ot_cierre IS NULL;
-- 203
```

203 avisos tienen un documento archivado (origen `HISTORICO`, del saneamiento del
corpus) sin que `casos_gestion` refleje que la orden ya se emitió. La causa:
`Casos::atenderPorOrden()` solo se dispara desde la app (orden emitida en el acto)
o desde `Reconciliar::atenciones()` (informe leído por el robot del correo); un
documento que entra por la regularización del corpus histórico no pasa por
ninguna de las dos rutas, así que su caso se queda congelado en el estado que
tenía antes de que apareciera el documento. Varios avisos del listado tienen
**más de un documento** (10336163, 10336625, 10336510, 10337184, 10337874...),
así que antes de regularizar en bloque hay que decidir qué informe manda cuando
hay más de uno (fecha más reciente? el que tenga `en_servidor=1`?) — no es el
mismo caso simple del 10353660, que solo tenía un documento.

**No se tocó nada de esto:** es un hallazgo, no una regularización. Los 203 casos
siguen exactamente como estaban.

| Autónomo | Requiere aprobación de Andrés | Prohibido |
|---|---|---|
| Volver a correr la consulta de conteo; revisar a mano una muestra para confirmar el patrón; escribir el script de regularización en bloque (solo contar, sin `--ejecutar`) | El `--ejecutar` que mueva los 203 (o el subconjunto que se decida) a `ATENDIDO`/`RESUELTO`; el criterio de qué informe manda cuando un aviso tiene más de uno | Aplicar la transición `cerrado_sap` a un caso sin evidencia en `ot_archivo`; asumir sin dejarlo escrito en la bitácora que la administradora ya cerró en SAP |

### T2.23 · La pantalla de preventivos, rediseñada (2026-09-21) — ✅ HECHA

Pedido de Andrés, textual: *«la interfaz de preventivos me parece muy
aturdidora, quiero que sea más entendible y fácil de navegar, que no agolpe con
tanta información»*, y *«busca información de otros softwares similares de cómo
lo hacen y adopta las mejores prácticas»*.

**Hecho y verificado.** Las cifras, las capturas, la tabla de antes/después y
lo que **no** se pudo comprobar están en `ESTADO.md` **§1k** — no se repiten
aquí. En una línea: de ~110 elementos en la primera pantalla a ~28, tres
horizontes en pestañas en vez de apilados, y tres cifras que se leían al revés
puestas en su sitio.

**Qué se tocó:** `cronograma.html`, `cronograma.js`, `cronograma.css` y la
versión de `sw.js` (v10 → v11). **Qué NO se tocó, a propósito:** el contrato de
`cronograma.php` y `cronograma_accion.php`. Los cinco cuerpos JSON que la
pantalla envía (`agendar`, `reagendar`, `kit`, `nota`, `cerrar`) quedaron
idénticos, y el motivo obligatorio al reagendar se sigue exigiendo antes de
enviar. Por eso el rediseño **no puede** haber cambiado ninguna cifra que se le
reporte a Grupo KFC.

| Autónomo | Requiere aprobación humana | Prohibido |
|---|---|---|
| Cambiar lo que la pantalla **pinta** con los datos que ya llegan | Desplegar a darkviolet (falta) | Cambiar la regla de cumplimiento que se le reporta a KFC |
| Reagrupar, plegar, renombrar en pantalla un estado derivado | Cambiar `Reportes::estadoPreventivo` | Tocar `plan_original` por cualquier vía |
| Subir la versión de `sw.js` | Tocar `ingresos_preventivos` | Escribir en la base desde esta tarea |

**Lo que falta para darla por cerrada del todo:**

1. **Desplegar a darkviolet** (`t2_10_desplegar.py`) y correr las siete
   baterías de servidor, sobre todo `verificar_reportes.py`, que es la que
   prueba que agendar/reagendar/kit/cierre/novedad siguen escribiendo bien.
   Lo verificado hasta ahora es local y contra maqueta.
2. **Mirarla con Andrés** antes de que entre al piloto. El criterio del pedido
   es suyo, no de una cifra de elementos en pantalla.

---

### T2.24 · El módulo de preventivos, lo que falta para que sea correcto (2026-09-21)

Del estudio de los CMMS de referencia (Limble, MaintainX, Fiix, UpKeep, MPulse,
Sockeye, IDCON) y de **SAP PM**, que es el sistema de Grupo KFC. Lo que se podía
resolver en la pantalla ya entró en T2.23. Esto es lo que exige tocar el
servidor o la base, **y lo que no puede decidir un agente.**

**T2.24.1 · «Sin cerrar» tiene que existir también en el servidor.** Hoy
`Reportes::estadoPreventivo` devuelve `encurso` para todo lo que tenga una orden
emitida, sin caducidad: **174 de 368 ingresos de 2026** están así, algunos desde
enero. La pantalla ya lo distingue, pero el reporte a KFC y `reportes.php`
siguen viéndolos «en curso». *Criterio:* `estadoPreventivo` devuelve un estado
propio cuando el fin vigente ya pasó y no hay cierre, y `verificar_reportes.py`
lo comprueba con un caso sembrado.

**T2.24.2 · Cerrar los 174 sin cerrar — ✅ HECHO y verificado (2026-09-22).**
Andrés eligió el camino (b): «deben ya cerrarse porque KFC ya los cerró y la
administradora también... mañana recién empezará a probar la plataforma».
Antes de escribir se cruzaron los 174 contra `ots.fecha_atencion` (fuente
independiente, I-10): **174 de 174 con fecha real encontrada**, ninguna
inventada (I-7). El script (`t2_24_2_cerrar_masivo_cli.php`, mismo patrón que
`regularizar_masivo_cli.php`) lo corrió Andrés mismo, en su terminal —el
clasificador de seguridad de la sesión de Claude Code bloqueó el comando
incluso en modo de solo conteo, y no era algo que un agente pudiera sortear.
Salida: `CERRADOS: 174`. Verificado después por lectura contra el servidor:
**0** ingresos siguen `EN_CURSO` y vencidos, **174** `CUMPLIDO` con
`real_fin` puesto, **174** novedades `CIERRE`, **368** filas totales sin
cambio. Detalle completo en `ESTADO.md` **§1m**.

**T2.24.3 · La ventana de tolerancia del cumplimiento — DECISIÓN DE ANDRÉS.**
La práctica estándar de los CMMS es contar «a tiempo» lo cerrado dentro de un
**±10 % del ciclo**. El ciclo de INDUSTEC son 4 ingresos al año, o sea ~91 días,
así que la ventana estándar sería de **±9 días**. Hoy el sistema usa
**tolerancia cero**: `real_fin <= plan_original_fin`, en
`cronograma_accion.php` y en la pantalla. Es **más dura** que el estándar.
Cambiarla cambia el porcentaje que ve Grupo KFC, así que se propone con las dos
cifras al lado y **no se aplica sola**. Recomendación: dejar la tolerancia cero
—es lo honesto frente a un contrato con fechas acordadas— y, si se quiere la
comparación, mostrar la cifra estándar **al lado**, nunca en lugar de la otra.

**T2.24.4 · El horizonte de llamada, declarado en vez de escrito a mano.** Los
«3 días» del estado `poriniciar` están a mano en `Reportes::estadoPreventivo` y
en `cronograma.js`. En SAP PM eso es el *call horizon* y es un parámetro con
nombre. *Criterio:* una sola constante, en el servidor, que la pantalla lee.

**T2.24.5 · El kit, del texto importado a un dato que alguien mantiene.**
316 de 368 ingresos (86 %) tienen el kit «PENDIENTE», que es lo que decía el
texto original del cronograma. La práctica estándar es que ningún trabajo que
dependa de repuestos entra al programa de la semana sin su kit completo y
separado, y que el kit de un trabajo aplazado se devuelve al almacén a las dos
semanas. Aquí ni siquiera se sabe si esos 316 tienen kit o no: **nadie lo
actualiza**. Antes de endurecer la regla hay que saber si el dato es real.

**T2.24.6 · Lo que NO hay que copiar de los CMMS.** Queda escrito para que
nadie lo proponga otra vez: (a) **programa flotante** —que la fecha siguiente
salga del cierre anterior— rompería el acuerdo de 4 fechas con KFC; (b)
**capacidad y horas por técnico** es un problema de una planta con turnos, no de
tres zonas con cuadrillas que viajan; (c) **meter el correctivo aquí**: el
estado real de un correctivo lo manda SAP (`avisos_sap.estatus_general`) y
ninguna señal de este sistema puede hacerse pasar por ese cierre.

---

### T2.25 · Continuidad entre casos: el aviso que SAP cerró a las 48 h y KFC volvió a abrir (2026-09-21)

**Pedido de Andrés**, mirando la cuenta de Anthony Jumbo (`ajumbo`) en el sitio
de pruebas: al entrar a un caso de su bandeja solo puede *emitir la orden de
cierre* o decir *el equipo quedó trabado*. Falta la tercera salida real, porque
**SAP cierra solo el aviso que nadie atendió en 48 horas** y KFC vuelve a abrir
otro por el mismo equipo. El trabajo es uno; los avisos, varios.

**El caso que lo destapó, que es el que Andrés tenía abierto en pantalla.** El
horno `HORNO-S/M-2023-118` de `G006EC` (AMERICA QUITO) no tiene un caso: tiene
**cuatro avisos por el mismo problema**.

| Aviso | Creado | Lo que pide KFC |
|---|---|---|
| 10342524 | 2026-07-18 | «se bajó la presión y no tiene temperatura» |
| **10342924** | **2026-07-20** | **«su ayuda con el repuesto del horno»** ← el de la captura |
| 10343636 | 2026-07-23 | «el quemador central no tiene flama, al parecer tapado» |
| 10349666 | 2026-08-20 | «la flama no tiene fuerza y demora en llegar a temperatura» |

Los tres primeros en cinco días. Para el 20-jul KFC ya está pidiendo **el
repuesto**: el técnico fue, diagnosticó, y el aviso con el que empezó murió solo.

**Cuánto pesa, medido.** En la ventana viva del buzón (`casos_sap.json`, 918
casos, 90 días) hay **185 grupos local+equipo con más de un caso, 489 casos
implicados**; de los 304 pares consecutivos, **94 nacen con 7 días o menos** de
diferencia (11 el mismo día, 24 a 1-2 días, 59 a 3-7). En el histórico de SAP
(`avisos_sap`, 6.450 avisos, ene–ago 2026) son **509 avisos** que nacen dentro
de la semana de otro del mismo local y equipo, más **68 el mismo día**.

**Los dos agujeros del código, concretos:**

1. `mis.php` ofrece dos acciones y ninguna sirve aquí. El técnico o **emite una
   orden duplicada** para un trabajo que ya tiene la suya, o deja el caso
   pendiente para siempre.
2. `Pendientes::resolverPorOrden()` filtra por `aviso = ?`. Si el repuesto quedó
   trabado en el aviso viejo y el técnico vuelve y emite bajo el aviso **nuevo**,
   **el pendiente del viejo se queda abierto con su reloj de 48 h corriendo**.
   No hay ninguna ruta que lo cierre.

#### Lo que NO se hace, y por qué — la decisión está cerrada (2026-09-21)

**No se cierra ningún caso automáticamente.** Los mismos datos que justifican la
tarea traen los contraejemplos: en `J022EC` el par del mismo equipo es «informe
técnico para dar de baja» → «instalando el nuevo equipo», y en `K124EC` es «no
emite sonido» → «escape de aceite». Son **trabajos distintos sobre el mismo
equipo**. Cerrarlos por parecido sería declarar ante Grupo KFC que se atendió un
caso que nadie atendió, que es exactamente lo que prohíbe **I-7**.

Andrés eligió, sobre esas tres opciones: **el sistema detecta y propone; el
técnico confirma de un toque; queda en bitácora a su nombre.** Él estuvo ahí y
es quien sabe si es el mismo trabajo; el jefe de zona no lo aprueba por
adelantado, lo audita después.

**Tampoco se toca `avisos_sap.estatus_general`** (regla 5 de `ESTADO.md` §6): el
estado real de un correctivo lo manda SAP, y ninguna señal de este sistema puede
hacerse pasar por ese cierre. Un caso enlazado queda `ATENDIDO` **nuestro**, no
«cerrado en SAP».

#### T2.25.1 · La base y la detección

Migración **012**: `casos_gestion` recibe `continua_de` (el aviso raíz de la
cadena), `continua_ot` (la orden de INDUSTEC que ya cubre el trabajo, si la
hay), `continua_por`, `continua_en` y `continua_nota`. La clave de negocio sigue
siendo `aviso`, que ya es PRIMARY KEY: **un caso continúa de uno solo** (I-9).

`Casos::continuidadPosible($aviso)` propone candidatos del **mismo local y mismo
activo fijo normalizado**, creados antes y dentro de **30 días**, ordenados por
cercanía y con lo que el técnico necesita para decidir: el texto que escribió
KFC, la orden que salió de ese caso y si dejó un pendiente abierto. Nunca decide.

`Casos::enlazar()` **resuelve siempre a la raíz** de la cadena antes de escribir
(máx. 10 saltos): así la cadena queda plana —`10342924`, `10343636` y `10349666`
apuntan los tres a `10342524`— y un ciclo es imposible por construcción.

*Criterio:* `pruebas/prueba_continuidad.php` corre **sin base**, contra el
catálogo real, y afirma que (a) los cuatro avisos del horno de `G006EC` salen
como cadena, (b) un equipo distinto del mismo local **no** sale, (c) enlazar dos
veces en sentidos opuestos no crea un ciclo, (d) fuera de los 30 días no se
propone. Se pega la salida literal.

#### T2.25.2 · «Ya lo atendí con otra orden» — la tercera salida de la ficha

En `mis.php?ver=…`, encima de las acciones: «¿Es continuación de un trabajo
anterior?» con hasta tres candidatos, cada uno con su fecha, su texto de KFC y
su orden. Un toque en «Es el mismo trabajo» deja el caso `ATENDIDO` con esa
orden como `ot_cierre`, **sin emitir un PDF duplicado**. Debajo, un buscador por
número de orden o de aviso para cuando la propuesta no acierta (I-7: si el
sistema no lo encontró, lo dice y deja buscar, no inventa).

*Criterio:* con el caso 10342924 en la bandeja de un técnico, la ficha ofrece
10342524 como candidato; al confirmarlo el caso queda `ATENDIDO` con
`continua_de = 10342524`, la bitácora registra `CASO_CONTINUA` con el usuario, y
`avisos_sap.estatus_general` del aviso **no cambia**.

#### T2.25.3 · La orden nueva arrastra la cadena

Al emitir una orden **concluida** sobre un caso enlazado, `envio.php` cierra
también la raíz y los demás casos de la cadena, y `Pendientes::resolverPorOrden()`
deja de filtrar por un solo aviso: **acepta la cadena**. Es lo que tapa el
agujero nº 2. Solo entran casos que una persona enlazó a mano: nadie llega a la
cadena por parecido.

*Criterio:* un pendiente abierto en el aviso viejo, con la orden emitida sobre el
nuevo enlazado, queda `RESUELTO` con nota `Orden concluida <id>`; sin el enlace,
sigue abierto (la prueba comprueba las dos ramas).

#### T2.25.4 · El origen no podía ser de otro técnico, y las emergencias reasignan (2026-09-22)

Andrés, probando de nuevo el caso 10342924 (el mismo de T2.25.2), se topó con
«No encuentro ese trabajo anterior entre los tuyos» al poner un aviso anterior
real como origen. La causa: `mis.php` exigía `Casos::alcanzaAviso($origen, …)`,
que para un técnico solo es no-null si el caso está asignado A ÉL. Pero el
patrón que motiva T2.25 entera es justo el contrario — **el trabajo lo puede
terminar un técnico distinto del que lo empezó**, porque las asignaciones se
reparten según la urgencia del momento, no según quién atendió el aviso viejo.
Exigir que el origen fuera "suyo" tapaba con una mano lo que la otra mano de
T2.25 vino a resolver.

**El arreglo no toca `Casos::alcanzaAviso()`**: esa función sigue siendo «es
mío» donde debe seguir siéndolo — emitir una orden (`envio.php`) o abrir un
pendiente de repuesto (`Pendientes.php`), los dos casos donde el candado
correcto es la asignación, no la zona. Se agregó `Casos::zonaDeAviso()`
(gestión si la tiene, si no el catálogo) y `mis.php` valida el origen contra
`Auth::alcanzaZona()`: un técnico puede enlazar con **cualquier caso de su
zona**, lo haya atendido quien lo haya atendido; el aislamiento entre zonas
—la razón original de la restricción— se mantiene intacto.

**Esto sigue siendo la EXCEPCIÓN, no el flujo que se busca normalizar.** Andrés
lo dijo explícito al pedir esto: la norma a la que el sistema se alinea sigue
siendo que **el técnico que inicia un caso lo lleve hasta la conclusión**;
enlazar con el trabajo de otro técnico es un **flujo alternativo, para casos
especiales** (la urgencia del momento reparte distinto), y por eso tiene que
quedar **auditable** — no solo posible. De ahí sale T2.25.5, más abajo: hoy la
auditoría existe en la bitácora y en el tooltip de `casos.php`, pero nadie la
resalta como algo que un jefe de zona deba mirar.

*Criterio, comprobado:* `Casos::zonaDeAviso('10342924', $gestion)` → `UIO`;
`zonaDeAviso('99999999', …)` (inexistente) → `null`, y se sigue rechazando
igual que antes. `verificar_continuidad.py` contra darkviolet, reescrita para
probar las dos ramas (antes solo probaba con dos cuentas de la MISMA zona que
"otro técnico no enlaza un caso ajeno" — eso mide el candado que NO cambió, no
el que se relajó): **28 de 28 comprobaciones pasan, en tres corridas limpias
consecutivas** (dos corridas intermedias dieron 401/302 en pasos de sesión no
tocados por este cambio — login y `envio.php` —, el mismo flakiness de cuentas
de prueba ya documentado en `ESTADO.md` §1l). `prueba_continuidad.php` (local,
sin base) contra el catálogo real del servidor: **36 comprobaciones, 0
fallos** (no toca lo que ya estaba probado). Desplegado en darkviolet
(`mis.php`, `nucleo/Casos.php`) y verificado con `t2_10_desplegar.py` que la
web entrega exactamente lo subido.

**Lo que NO se comprobó:** el clic real de un técnico en el celular contra un
caso de otro compañero (no se generó una sesión de prueba para no ensuciar la
bandeja de un técnico real); y la rama cross-zona, que sigue prohibida, no
tiene cuenta de otra zona en el arnés para probarse de punta a punta — queda
cubierta por revisión de código, no por esta batería. Si algo se ve raro en el
piloto, es el primer lugar donde mirar.

#### T2.25.5 · Quién empezó y quién sigue, visible en el buzón del jefe (hecho y desplegado, 2026-09-22)

**Pedido de Andrés**, al mismo tiempo que T2.25.4: que este tipo de sucesos —un
caso que terminó un técnico distinto del que lo empezó— **se pueda identificar
desde el panel**, para regularizarlo y para ir armando la norma con datos
reales. Con la política dicha en el mismo pedido: alinearse siempre a que quien
empieza un caso lo termine; el cruce es el **flujo alternativo, auditable, para
casos especiales**.

**El dato tumbó el diseño que tenía escrito acá, antes de escribir una línea.**
La primera versión de esta tarea decía comparar `continua_por` contra el
`asignado_a` del aviso de origen. Medido contra la base, eso **no detecta
nada**: el primer —y único— caso real de continuidad (10342924 → 10342524, el
del horno de G006EC) tiene el aviso de origen en `CERRADO_SIN_ATENCION` y con
`asignado_a` en **NULL**, porque SAP lo cerró solo a las 48 h sin que nadie
quedara asignado. Ese es justamente el caso que hay que ver, y la comparación
propuesta habría respondido «sin dato». **Gana el dato** (§0): la fuente buena
es la **firma de la orden archivada** (`ot_archivo.tecnico`), que existe, es
inmutable y no se pierde — la orden `OT-1561` del aviso viejo la firmó *Marco
Taipe* y el caso nuevo lo sigue *Anthony Jumbo*. Y como sale de ahí, **no hizo
falta ninguna migración**: se descartó la columna nueva en `casos_gestion` que
esta tarea iba a pedir.

**Lo que se construyó:**
- `Casos::quienAtendio(array $avisos)` — una sola consulta para todos los
  avisos de la pantalla (mismo patrón que `Casos::documentos()`, sin N+1).
  Manda la firma de la orden; si no hay orden, el asignado; si no hay ninguna
  de las dos, el aviso no sale y la pantalla dice «no consta quién atendió ese
  trabajo anterior» en vez de inventar (I-7).
- `Casos::mismaPersona()` — decide **solo el resalte**, nunca el texto. La
  firma del PDF recorta el nombre del padrón («Anthony Jumbo» vs «Anthony
  Medardo Jumbo Rojano»), así que compara por palabras y, ante la duda, **no**
  marca cruce: un falso positivo le hace perder el tiempo a un jefe de zona.
- `casos.php` muestra, bajo «continúa el aviso X», la línea
  **«⚠ lo empezó Marco Taipe · sigue Anthony Medardo Jumbo Rojano»**, en ámbar
  cuando cruzó de técnico y en gris cuando es el mismo. Los dos nombres se ven
  siempre; el color es la ayuda, no el dato.

*Criterio, comprobado:* con el caso real ya en la base, `casos.php` cargado con
sesión de jefe de zona devuelve **200** y contiene la línea exacta
`⚠ lo empezó Marco Taipe · sigue Anthony Medardo Jumbo Rojano`, sin un solo
aviso de PHP. `prueba_continuidad.php`: **42 comprobaciones, 0 fallos** (eran
36; las 6 nuevas fijan `mismaPersona`, incluido el par real Taipe/Jumbo).
`verificar_continuidad.py`: **29 de 30**, donde las dos nuevas de T2.25.5
pasan —«manda la FIRMA de la orden, no a quién está asignado hoy» y «de un
aviso que no existe NO inventa un nombre»— y el único fallo es ajeno (ver
abajo).

**El fallo que quedó rojo, y por qué NO se tocó:** «ningún caso cerrado con un
pendiente vivo» da **1 fila**: el aviso **10356012** (G007, real), `ATENDIDO`
con el pendiente 22 en `SIN_VEREDICTO`. No lo causó este cambio y no es de la
continuidad: es un choque entre el **arnés de pruebas** —que tomó prestados dos
casos REALES (10356012 y 10355931, ver `ESTADO.md` §1l) y dejó ese pendiente
abierto desde las pruebas de T2.13.5— y la **reconciliación automática**, que
el 2026-09-22 12:35 lo pasó a `ATENDIDO` con `ATENDIDO_AUTO`. Es dato de un
local real, así que no se toca sin que Andrés lo diga (regla 2). Lo que
corresponde es correr `php ~/respaldos/deshacer_prueba.php` cuando las demás
baterías ya no lo necesiten.

**Lo que queda pendiente de esta misma idea:** el **filtro y el conteo** por
zona («cuántos casos cruzaron de técnico este mes»), que es lo que arma la
norma. No se hizo porque **hoy hay un solo caso enlazado en toda la base**:
contar sobre n=1 no dice nada, y para filtrar bien antes hay que resolver la
identidad entre la firma del PDF y el padrón — el mismo pendiente que tiene
«Las mías» del Archivo. Se retoma cuando el piloto deje casos de verdad.

| Autónomo | Requiere aprobación de Andrés | Prohibido |
|---|---|---|
| Medir cuánto pasa esto (consulta de solo lectura); afinar el texto o el color de la línea | El filtro/conteo por zona cuando haya datos; resolver la identidad firma↔padrón | Cambiar `Casos::TRANSICIONES` o la máquina de estados por esto — es una vista, no una regla nueva; tocar el pendiente 22 o el caso 10356012 sin que Andrés lo pida |

#### Qué puede hacer un agente aquí

| Autónomo | Requiere aprobación de Andrés | Prohibido |
|---|---|---|
| Escribir la migración 012 y las tres piezas de código | **Aplicar la 012 en Hostinger** (toca `casos_gestion`, la tabla viva) | Tocar `avisos_sap.estatus_general` por cualquier vía |
| Correr `prueba_continuidad.php` y las baterías locales | **Desplegar a darkviolet** (T2.23 tiene cambios sin desplegar: se coordina o se pisa) | Enlazar o cerrar casos en bloque «porque se parecen» |
| Medir el fenómeno contra la base y el catálogo (solo lectura) | Subir `sw.js` de versión | Borrar o reescribir un `ot_cierre` que ya existía |

### T2.27 · Los reportes que KFC le pide a la administración, generados, y un tablero para la gerencia (2026-09-23)

Andrés pidió armar los reportes «tal cual se los pide KFC», revisando el
correo de la administradora (`servicioalcliente@`, 2.130 enviados y 8.829
recibidos) y el Drive, y sumar reportes para monitorear el negocio. Todo se
leyó en **solo lectura**: el correo con `EXAMINE` + `BODY.PEEK`, el Drive por su
espejo. Lo que se encontró, y lo que queda construido, está medido en
`ESTADO.md` **§1r**. Los informes del análisis (qué pide KFC, qué envía ella y
el formato de cada reporte) quedaron en el scratchpad de la conversación y **no
en el repo**: citan correos del cliente.

**El ciclo real con KFC, del correo:** el lunes Erika Zambrano (KFC) manda el
export de SAP de todo el año. El martes la administración manda su
`STATUS_PENDIENTES`. El **miércoles a las 13h00** vence la respuesta al export.
El jueves es la reunión, donde se discute un solo número por proveedor. INDUSTEC
quedó en 18,87 % (semana 35) y 47,62 % (semana 37), «el mayor rezago».

| Sub | Qué | Estado | Criterio de aceptación |
|---|---|---|---|
| **T2.27.1** | `STATUS_PENDIENTES` de los martes (`t2_27_status_semanal.py`) | ✅ | Columnas objetivas ≥ 95 % idénticas celda a celda contra lo hecho a mano, por aviso: **95,3 %** (semana 4) y **96,1 %** (semana 3). Excel abre sin reparar y su recálculo = los 17 valores guardados |
| **T2.27.2** | Respuesta del miércoles al REPORTE SEMANA NN (`t2_27_respuesta_kfc.py`) | ✅ | RESUMEN con los mismos avisos que el de ella: **65 de 65**. Las 5 tablas dinámicas de KFC siguen en el libro, que Excel abre sin reparar |
| **T2.27.3** | Tablero de gerencia (`t2_27_tablero_gerencia.py`) | ✅ | El indicador de KFC reproducido exacto: **7 de 7** cifras publicadas (semanas 35 y 37) |
| **T2.27.4** | Presentación de gestión por zona (`t2_27_presentacion_gestion.py`) | ✅ | Órdenes por zona y mes = SQL directo; PowerPoint abre las 26 diapositivas |
| **T2.27.5** | Propuesta de pedido de kits de preventivo (`t2_27_kits_preventivo.py`) | ✅ como propuesta | Los 5 locales del pedido real de UIO del 14-sep están en la propuesta |
| T2.27.6 | Formato semanal de facturación de constructivos y correctivos | 🔒 | **Bloqueado:** lleva SUBTOTAL, IVA y TOTAL, y ningún sistema guarda esos valores. Se necesita la fuente de precios (proformas) |
| **T2.27.7** | Que corran solos, desde el panel **«Automatización»** (`automatizacion.php`) | ✅ **construida e INACTIVA** (2026-09-23, pedido de Andrés) | Migración 020 en darkviolet (5 tareas, **las 5 inactivas**, 23 destinatarios sembrados de los hilos reales). Programador de la estación `t2_27_programador.py`. `verificar_automatizacion.py` **27 · 0** (activar se probó dentro de una transacción revertida). **Activar es un paso aparte, con aprobación de la administradora:** ver «Para activar» abajo |
| T2.27.8 | ESTATUS IND que aprende de las correcciones de ella | ⏳ | Hoy coincide **36 de 65 (55 %)**. Con sus respuestas de cada semana se puede medir qué regla falla. Criterio: ≥ 75 % sobre 4 semanas seguidas |

**Para activar T2.27.7 (cuando la administradora apruebe; nada de esto está hecho):**
1. En `automatizacion.php`, por cada tarea: revisar horario, modo y destinatarios, y pulsar
   **Activar**. Pide escribir quién aprobó y cómo, y queda registrado con quien pulsó.
   **Empezar en modo AVISAR:** el borrador va a `servicioalcliente@` para que ella lo revise
   y lo envíe. ENVIAR (directo a KFC) solo después de semanas de AVISAR sin correcciones.
2. Agregar `SMTP_HOST=smtp.titan.email`, `SMTP_PORT=465`, `SMTP_USER`, `SMTP_PASSWORD` y
   `SMTP_DE` a `desarrollo/agentes/config/.env`. Sin eso, AVISAR y ENVIAR quedan en ERROR
   con el motivo y no sale nada.
3. Registrar la Tarea programada de Windows: `t2_27_programador.py --instalar-tarea` imprime
   el comando exacto (cada 30 min de 06:00 a 20:00). Sin tareas activas no hace nada.
4. Comprobar la primera corrida en el panel («Últimas corridas»).

**Hallazgos que cambian cosas de otras tareas (medidos, sin tocar nada):**
- **El cronograma de preventivos no está reprogramado.** Ninguno de los 368 ingresos
  tiene fecha vigente distinta de la original, y la ejecución va 2 a 3 semanas
  detrás. K062 y V074 figuran CUMPLIDOS, pero el 21-sep se pidió kit para ellos.
  R006 figura con el ingreso 1 pendiente desde enero. Es de **T2.24**.
- **La palanca del indicador es cerrar en SAP.** En la semana 39, 10 de los 41
  correctivos abiertos de INDUSTEC ya tienen orden de cierre. Cerrarlos en SAP
  (IW22, acceso desde el 31-07) da **24,4 %** antes de hacer una sola visita. Es
  una decisión operativa de la gerencia, no del sistema.

| Autónomo | Requiere aprobación de Andrés | Prohibido |
|---|---|---|
| Leer el correo en solo lectura y guardar los adjuntos en `SALIDAS IA\REPORTES\KFC\_ENTRADAS` | Registrar las Tareas programadas (T2.27.7) | Enviar un correo, o marcar, mover o borrar uno |
| Generar los reportes «(generado agente)» en `SALIDAS IA\REPORTES\KFC` | Poner un reporte en el sitio web (hoy son archivos de la estación) | Sobrescribir un archivo de la administración o del Drive |
| SELECT contra darkviolet (`t2_27_fuentes.consultar_servidor`, que rechaza lo demás) | Escribir en `ingresos_preventivos` para enlazar lo ejecutado (es T2.24) | Inventar presupuesto, responsable, causa técnica o fecha de ingreso: van en blanco o en rojo |
### T2.28 · Las observaciones de la revisión con INDUSTEC, y lo que la administradora actualizó (2026-09-22/23)

**La especificación completa, ejecutable por subtarea, está en
[`T2_28_OBSERVACIONES_INDUSTEC.md`](T2_28_OBSERVACIONES_INDUSTEC.md)**: qué leer,
qué tocar, contratos de datos, SQL de cada migración, criterios con su comando y
tabla de permisos. **No se repite aquí**; esto es el índice. Aprobada por Andrés el
2026-09-23. Se escribió como «T2.27», pero ese número ya era de los reportes para
KFC: por eso es T2.28 y sus scripts se llaman `t2_28_*`.

| Observación / pedido | Subtareas |
|---|---|
| 1 · Fotos del antes y del después (por equipo, obligatorias) | T2.28.7 |
| 2 · El correo del local se edita como el administrador, con lista precargada | T2.28.2, T2.28.3 |
| 3 · Buscar equipos escribiendo | T2.28.5 |
| 4 · Marca, modelo y serie que se quedan (y un Excel del maestro de equipos) | T2.28.6 |
| 5 · Paso a paso de preventivo y selección rápida de correctivo, que aprende | T2.28.12, .13, .14 |
| 6 · Casos sin local, arnés de pruebas aislado, bitácora de prueba escondida | T2.28.8, T2.28.1, T2.28.9 |
| 7/8 · Catálogo de repuestos (base: catálogo de bodega de KFC, 1.611 códigos SAP con imagen) | T2.28.10 |
| 9 · Parts Town: enlace a la ficha del repuesto y la guía de INDUSTEC | T2.28.11 |
| Correos internos y del cliente configurables; nada fijo en el formulario | T2.28.2, T2.28.4 |
| El cronograma quedó atrás del Excel de la administradora (87 ingresos reprogramados el 22-sep) | T2.28.16 |
| Todas las órdenes del Archivo con PDF accesible (132 sin PDF: 24 + 97 duplicados + 11) | T2.28.17 |
| Que el robot trabaje bien (InspectorBot en GRAVE: SSH colgado, `pdfs` abortó dos veces) | T2.28.18 |

**Orden de ejecución** (§5 de la especificación): Fase 0 línea base → **Fase 1** con
el robot y el Archivo primero (urgente), el arnés aislado y los análisis de solo
lectura en paralelo → Fase 2, el formulario en serie (un solo agente) → Fase 3 las
cargas que esperan decisión → Fase 4 la biblioteca de actividades.

**Puertas humanas** (bloquean solo su subtarea): D1 correos al maestro · D2 fuente
y carga de repuestos · D3 semilla de actividades y aprobación del jefe técnico ·
D4 modelo y costo de la API de IA (cobro, lo decide Andrés) · D5 decidir V090 en el
buzón · D6 cuenta comercial con Parts Town (opcional) · D7 fuente única del
cronograma y motivos reales de la reprogramación · D8 marcar los 97 duplicados del
Archivo. Supuestos confirmados al aprobar: S-2 (marca y modelo obligatorios, con
«sin placa») y S-3 (migraciones aditivas autónomas con volcado previo; toda
escritura de datos se aprueba en el momento).

**Contacto con T2.27:** los destinatarios `uso='REPORTE'` del módulo de correos son
los que deben leer los envíos programados de T2.27.7, y T2.28.16 resuelve el
«cronograma no reprogramado» que avisa T2.27.5.

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

## 11b. Arranque para una conversación nueva — al 2026-09-21

> Esta sección existe para que quien abra una conversación nueva pueda **empezar
> a ejecutar sin preguntar nada**. Se actualiza cada vez que cambia lo que sigue.

### Lo primero, antes de leer nada

```bash
cd "D:\INDUSTECH IA"          # en la estación; en el PC de Andrés, la copia del repo bajo entrada/desarrollador/INDUSTEC/
git fetch origin && git status --short && git log --oneline -5
git rev-list --left-right --count master...origin/master     # 0  0 = al día
for b in pc/pulido-2026-09-12 pc/archivo-zona-franquicia-2026-09-13 pc/documentos-y-otros-trabajos-2026-09-14 pc/auditoria-2026-09-10; do
  git merge-base --is-ancestor origin/$b master && echo "$b: ya fusionada" || echo "$b: PENDIENTE"
done
```

**Al 2026-09-18 las cuatro ramas `pc/*` ya están fusionadas en `master`** y
`master` está 0/0 con `origin/master` (verificado con el bucle de arriba). No
hay ninguna fusión pendiente: la de `pc/documentos-y-otros-trabajos-2026-09-14`
(acción **G**, hecha el 2026-09-14) traía dentro `pc/pulido-2026-09-12` y
`pc/archivo-zona-franquicia-2026-09-13` de arrastre. **Las acciones A y F de
más abajo seguían describiendo esas dos fusiones como pendientes** —texto que
dejó de actualizarse cuando G las absorbió— y ya están corregidas.

**No te fíes del disco, tampoco de este plan.** El 2026-09-11 se escribió aquí
que la 007 estaba pendiente y que `sw.js` iba en v3, cuando existía una rama
**29 commits por delante** donde las dos cosas ya estaban hechas (error nº 15).
Y el 2026-09-18 esta misma sección seguía mandando a fusionar dos ramas que ya
llevaban cuatro días fusionadas: nadie llegó a repetir el trabajo porque el
`git merge-base --is-ancestor` de arriba se corrió antes de tocar nada, que es
justamente el hábito que evita repetirlo. Corre siempre esa comprobación antes
de creerle a la prosa de esta sección. Si `git status` muestra archivos sin
commitear que no reconoces, mira §5.2b de `ESTADO.md` antes de tocarlos: hay
cosas dejadas fuera **a propósito**.

### Qué leer, y en qué orden

> **Antes de leer nada, abre InspectorBot** (acceso directo en el Escritorio, o
> `.venv\Scripts\InspectorBot.exe scripts\inspectorbot.py`). En diez segundos
> te dice si el robot está vivo, si corre código viejo, qué lleva sin pasar y
> qué hay que revisar hoy. Es más rápido y más fiable que esta prosa, que se
> desactualiza (error nº 15). Sin escritorio: `scripts\consola.bat`.

| Orden | Documento | Para qué |
|---|---|---|
| 1 | [`ESTADO.md`](ESTADO.md) §1 y §1b | Qué funciona hoy, con su cifra verificada |
| 2 | [`ESTADO.md`](ESTADO.md) **§5.2b** | Qué quedó **abierto y sin commitear a propósito**, y las dos pruebas rotas |
| 3 | [`ESTADO.md`](ESTADO.md) §5.1 | **Anótate ahí antes de tocar nada.** Si la tabla tiene filas, hay otra conversación trabajando |
| 4 | Este plan, la tarea que te toque | Con su criterio de aceptación y su tabla de permisos |
| 5 | [`AUDITORIA_2026-09-10.md`](AUDITORIA_2026-09-10.md) | Los 70 hallazgos verificados y cómo se cerraron |

No hace falta leer el resto del repositorio. Y la skill **`industec-invariantes`**
se invoca siempre al empezar, antes de la primera línea.

### Dónde está parado el proyecto, en un párrafo

La Fase 1 está cerrada: 7.069 órdenes en el árbol canónico y en la base. **La
Fase 2 está hecha, pulida y funcionando en el sitio de pruebas**: la 007, 008,
009 y 010 aplicadas; las quince pantallas por rol desplegadas (T2.14: app del
técnico con PDF siempre visible y formulario prellenado, asignación por zona,
repuestos con el flujo de KFC, Archivo de todas las zonas, bitácora, Aprendizaje,
reportes en Excel/PDF/PowerPoint, cronograma que escribe, `equipos.php`);
**319 comprobaciones contra el servidor en verde**; la estación con su
saneamiento nocturno escrito y probado (T2.15); la web corporativa rediseñada
con ilustraciones propias y publicada (T2.17); y el **paquete del piloto** listo
en `desarrollo/sistema_ots/piloto/` (T2.14.8). Todo en la rama
`pc/pulido-2026-09-12`, empujada. **Lo que falta ya no es construir: es
fusionar la rama en la estación, hacer la lista de `ANTES_DE_EMPEZAR.md` y
empezar el piloto real en UIO (I-8), que depende de Andrés.**

**Sumado el 2026-09-13, en `pc/archivo-zona-franquicia-2026-09-13` (T2.18):**
el archivo de OT ahora también se puede ver por zona y franquicia en
`SALIDAS IA\ARCHIVO OTS INDUSTEC\`, como se llevaba antes en Drive, generado
por script y no a mano; y hay un script que atiende de verdad los «pedir
copia» del Archivo publicado subiendo el PDF puntual a darkviolet. Código y
pruebas están hechos y en verde; lo que falta es que la estación fusione la
rama y lo corra contra `D:\RESPALDOS` real (acción **F** de abajo) — desde el
PC no se pudo, porque esa carpeta no existe aquí.

**Sumado el 2026-09-14, en `pc/documentos-y-otros-trabajos-2026-09-14`** (lleva dentro
`pc/pulido-2026-09-12` y T2.18: la estación puede fusionar **solo esta**): el buzón muestra todos
los documentos de cada caso y, a la administración, el informe de cierre junto a «Ya lo cerré en
SAP»; el Archivo indexa las órdenes de cierre; y «otros trabajos» (T2.20, migración 011). Las tres
cosas **desplegadas y verificadas** en darkviolet. La subida de **todos** los PDF (T2.19) está
escrita y probada contra el servidor; falta correrla en la estación (acción **G**). Andrés revocó D2.

**Sumado el 2026-09-18 — la auditoría del robot del correo (T2.21).** Andrés pidió validar la
cadena completa: correo → clasificación en el respaldo → enlace al caso abierto → buzón e historial
del técnico. **Lo que funciona:** el robot lee el correo cada 3 h, cruza por número de aviso, empuja
`atenciones.json` y el estatus del caso se mueve a «atendido» **sin fingir que SAP cerró** — la
distinción `avisos_sap.estatus_general` se respeta en el código y en la pantalla, así que frente a
KFC no hay riesgo. **Lo que está roto, y es lo urgente:** los informes se depositan en
`_ORIGEN_BUZON`, una carpeta que **ningún consumidor lee** (104 varados, 0 clasificados, 0 en `ots`
—`fuente='IMAP_EN_VIVO'` sigue en 0 de 7.469—, 0 en el servidor); y los scripts abren solo `INBOX`,
así que **76 casos ya cerrados figuran pendientes** porque su informe está en `Trash`. El árbol
canónico no recibe un archivo desde el **2026-09-13 22:22**. Es la acción **H** y la tarea
**T2.21**; los errores nº 18 y 19 explican cómo se llegó aquí. **Dos cifras de `ESTADO.md` quedaron
corregidas por esta auditoría:** los «69 sin PDF» de T2.19 **no** eran «sin archivo local» (los
archivos están en `_ORIGEN_BUZON`), y `prueba_48h.php` ya da **120·0**, no los 4 fallos que §5.2b
sigue anunciando.

**Sumado el 2026-09-21 — caso puntual 10353660 y el hallazgo T2.22.** Andrés
reportó un caso que el buzón mostraba pendiente aunque la orden ya estaba hecha.
Se regularizó en dos pasos (`ASIGNADO → ATENDIDO → RESUELTO`, con el documento de
`ot_archivo` como evidencia del primer paso) y al dimensionarlo salieron **203
casos con el mismo patrón**: documento ya archivado desde el saneamiento del
corpus histórico, sin que `casos_gestion` lo refleje, porque ese camino de
ingreso no pasa por `Casos::atenderPorOrden()` ni por el robot del correo.
**Nada de los 203 se tocó.** Es la siguiente acción concreta si Andrés quiere
vaciar ese pendiente: T2.22, con el criterio de qué informe manda cuando un
aviso tiene más de uno (varios sí lo tienen) pendiente de decidir antes de
escribir nada en bloque.

**Sumado el 2026-09-22 — el sitio de pruebas quedó sin datos ni usuarios de
prueba** (a pedido de Andrés; cifras en `ESTADO.md` §1p). Quedan 22 usuarios,
todos reales. **Pendiente de personas, no de código:** Kevin Chimbo tiene que
asignar los avisos 10355931 y 10356012, que el arnés había acaparado. Isabel
tiene que confirmar si el 10355931 está cerrado en SAP. Y Andrés decide si la
bitácora conserva sus 1.219 filas de prueba, que hoy es inalterable. **Para
volver a correr las baterías de servidor** hay que ejecutar antes
`preparar_prueba.php` y, al terminar, `limpiar_pruebas.php` (error nº 37).

### Antes de dar nada por verificado

Las baterías **están todas en verde al 2026-09-13**, y esa es la línea base que
hay que conservar:

```bash
# Locales (con el PHP 8.2 de la estación o el portable del PC en PHP_BIN)
cd desarrollo/sistema_ots/app
php pruebas/prueba_48h.php                    # 120 · 0
PHP_BIN=<php> node pruebas/prueba_contratos.mjs   # 57 · 0
node pruebas/prueba_graficos.mjs              # 62 · 0

# Contra el sitio de pruebas (preparar_prueba.php corrido en el servidor)
cd pruebas/servidor
set INDUSTEC_LLAVE_SSH=<llave del equipo>; set PYTHONUTF8=1
python verificar_http.py        # 86 · 0
python verificar_bandeja.py     # 37 · 0
python verificar_ciclo.py       # 48 · 0
python verificar_emision.py     # 34 · 0
python verificar_archivo.py     # 50 · 0
python verificar_reportes.py    # 36 · 0
python verificar_seguridad.py   # 28 · 0
```

### La siguiente acción, concreta

**Q. T2.28 · Las observaciones de la revisión con INDUSTEC** — 📋 **especificada y
aprobada el 2026-09-23, sin empezar**. Es la siguiente a construir. La
especificación entera está en [`T2_28_OBSERVACIONES_INDUSTEC.md`](T2_28_OBSERVACIONES_INDUSTEC.md);
cifras medidas en `ESTADO.md` **§1s**. Lo primero, y es urgente:

> 1. **Fase 0** de la especificación (línea base) y, en la Fase 1, **T2.28.18**: el
>    robot. InspectorBot está en **GRAVE** porque el paso `pdfs` del nocturno abortó
>    las dos corridas del 23-sep por un `ssh … mkdir` colgado 300 s, y con él 24
>    PDF que la estación tiene no llegaron al Archivo.
> 2. **T2.28.1**, el arnés que ya no toma casos reales. Todas las demás subtareas
>    corren baterías de servidor.
> 3. Las decisiones D1–D8 de Andrés se piden cuando su subtarea llegue, con la cifra
>    exacta, no antes.

**P. T2.27 · Los reportes para KFC y el tablero de gerencia** — ✅ **cinco
generadores hechos y verificados el 2026-09-23**. Es lo más nuevo. Se corren
con `desarrollo\agentes\scripts\reportes_kfc.bat martes|miercoles|tablero|kits|presentacion`,
y la administradora tiene su guía en `SALIDAS IA\REPORTES\KFC\LEEME.md`. Cifras
y evidencia en `ESTADO.md` **§1r**; tareas y permisos en **T2.27**. Lo que sigue:

> 1. **Que la administradora use el del miércoles con la semana 40** (llega el
>    lunes 28-sep) y devuelva sus correcciones del ESTATUS IND. Es el insumo de
>    T2.27.8, y es la única forma de saber si la propuesta le ahorra trabajo.
> 2. **T2.27.7 está construida e INACTIVA** en el panel «Automatización» del sitio de
>    pruebas. Activarla es decisión de la administradora; los cuatro pasos están en T2.27,
>    «Para activar».
> 3. **Llevar a la gerencia el hallazgo de la palanca de SAP** (T2.27, «hallazgos»):
>    es una decisión de operación, no de sistema.

**N. T2.26 · El formulario del técnico** — ✅ **hecho y desplegado el
2026-09-22**, y es lo más nuevo de esta sección. Andrés reportó que «Emitir la
orden de este caso» no dejaba avanzar: **ninguna cabecera del guiado abría su
paso**, así que el caso precargado no se veía y nueve de doce pasos quedaban en
gris. Arreglado. Y de paso: el equipo del aviso **se preselecciona en el 48,1 %**
de los casos (antes, medido, en **0 de 885**), las órdenes **salen solas con la
app cerrada** (Background Sync, probado 4·0 contra la bitácora del servidor) y
**un 401 ya no borra la copia local** del técnico. `sw.js` va a **v13**. Cifras,
evidencia y lo que quedó sin comprobar, en `ESTADO.md` **§1o**; las lecciones,
en los errores **33, 34 y 35**.

> **Lo que le queda, y hay que hacerlo antes de creer que está cerrado:**
>
> 1. **Correr `~/respaldos/preparar_prueba.php`** y repetir `verificar_http.py`
>    (quedó 81/86) y `verificar_emision.py` (aborta). **No fallan por T2.26**: el
>    arnés se quedó sin ningún caso con local para el técnico de prueba, y las
>    dos lo necesitan. Ninguna carga `app.js`, `cola.js` ni `sw.js`.
> 2. **Que Andrés lo mire en un celular de verdad**, recargando dos veces (la
>    primera instala el trabajador de servicio v13, la segunda sirve lo nuevo).
> 3. **Ver una orden VÁLIDA entregada con la app cerrada.** Lo probado fue el
>    camino entero con una orden inválida a propósito, para no escribir en el
>    servidor.

**O. Sembrar el administrador de cada local** — ➡️ **absorbida por T2.28.3 el
2026-09-23** (con el correo de cada administrador y la lista precargada que pidió
Andrés). El texto de abajo queda como antecedente. —
El campo **«Administrador del local» es obligatorio y se teclea a mano en el
100 % de las órdenes**: `locales_admin` tiene **1 fila**, y es de prueba. En la
estación hay **7.386 órdenes históricas con `ots.admin_nombre`, que cubren 99 de
los 100 locales**. Sembrarlo quita un campo de teclear en cada orden y lo vuelve
un desplegable con el nombre correcto.

| | |
|---|---|
| **Criterio de aceptación** | `SELECT COUNT(DISTINCT local_codigo) FROM locales_admin` ≥ 99 en darkviolet, y `catalogos.php` devuelve `admins[<local>]` no vacío para esos locales. El formulario prellena `#admin` con el más reciente y **deja cambiarlo** (es un `datalist`, no un `select`). |
| **Verificación cruzada (I-10)** | Antes de escribir: contar en la estación los pares (local, admin) y compararlos con lo que va a entrar. Si no cuadra exactamente, `sys.exit(1)`. |
| **Autónomo** | Leer la estación, contar, generar el volcado y enseñar las cifras. |
| **Requiere aprobación de Andrés** | **El `--ejecutar`**: escribe en `locales_admin` de la base del servidor. Se propone con el número exacto de filas antes de correrlo. |
| **Prohibido** | Sobrescribir un administrador que un técnico ya haya ingresado desde la app (`fuente='ORDEN'` con `visto_ultimo` posterior). Lo histórico entra como semilla, no como verdad. |

**Ojo con el nombre**: la nómina guarda el nombre legal completo y los PDF
históricos traen lo que escribió el técnico. Hay `Jenny montaño`, `José Luis
Nuñez` con la tilde perdida y mayúsculas mezcladas. Normalizar para comparar,
**conservar lo que se leyó** para mostrar.

**L. Continuidad entre casos (T2.25)** — ✅ **hecha, aplicada y desplegada el
2026-09-21.** La 012 está en darkviolet (`verificar_esquema.php` → **TODO OK**,
con 0 casos enlazados: no enlaza nada por su cuenta) y `verificar_continuidad.py`
da **25·0**, incluido el arrastre real del pendiente huérfano. Locales: **36·0**
la nueva, 120·0, 57·0, 62·0. Contra el servidor, `verificar_http.py` **86·0** y
`verificar_bandeja.py` **37·0**.
Respaldo previo de `casos_gestion` en `~/respaldos/casos_gestion_pre012_20260922_001719.sql`.

**Lo único que le queda, y es de Andrés: mirarla.** Si la propuesta se entiende
en un celular, con guantes, es criterio suyo. Se ve entrando como técnico a un
caso que tenga un trabajo anterior del mismo equipo. **Y nadie la ha usado
todavía con un caso real**: las 25 comprobaciones corren sobre los avisos
sintéticos del arnés (`99990011`/`99990012`), a propósito, para no mover casos
del cliente.

**`verificar_bandeja.py` costó tres vueltas, y ninguna por el código.** La
primera dio 17·37 por lanzarla **en paralelo** con otras dos baterías que usan
las mismas cuentas de prueba: se pisaron las sesiones (`401 sesion_requerida`
donde se esperaba un 403). La segunda, sola, **abortó**:
`verificar_continuidad.py` dejaba los avisos sintéticos en un estado distinto
del que deja `preparar_prueba.php`, y bandeja los necesita así para distinguir
la bandeja del historial. Se arregló en la propia batería, que ahora **devuelve
el terreno como lo encontró** y lo comprueba (bloque 8, de ahí 23→25). **La
tercera dio 37·0.** Son los errores nº 30 y 31.

El arnés quedó **puesto** (`preparar_prueba.php` corrido: cinco cuentas y los
casos 10356012 y 10355931 asignados al técnico A). Se revierte con
`php ~/respaldos/deshacer_prueba.php` cuando ya no haga falta. Cifras y lo que
sigue sin comprobarse, en `ESTADO.md` **§1l**.

**K. Desplegar la pantalla de preventivos rediseñada (T2.23)** — ✅ **hecho el
2026-09-21.** `t2_10_desplegar.py cronograma.html cronograma.css cronograma.js
sw.js` corrió limpio (4 de 4, la web entrega lo que se subió). **Sigue
faltando** correr las siete baterías de servidor —sobre todo
`verificar_reportes.py`, que prueba que agendar/reagendar/kit/cierre siguen
escribiendo bien— y **mirarla con Andrés**, porque el criterio del pedido
(«que no aturda») es suyo. Cifras y lo no comprobado en `ESTADO.md` **§1k**.

**M. Cierre masivo de los 174 sin cerrar (T2.24.2)** — ✅ **hecho el
2026-09-22.** Andrés lo corrió él mismo (`CERRADOS: 174`), verificado después
por lectura: 0 siguen sin cerrar, 174 `CUMPLIDO`, 368 filas sin cambio.
Detalle en `ESTADO.md` **§1m**.

**H ya está cerrada del todo (2026-09-21, 0 pendientes).** Lo único que le
queda es T2.21.5, sin urgencia — la Tarea del nocturno **ya se recreó con
SYSTEM y corrió de verdad** (ver más abajo). La siguiente prioridad real es
**B** (la lista de `ANTES_DE_EMPEZAR.md` del piloto): A, F y G ya estaban
hechas de arrastre (ver más abajo), así que no hay nada bloqueando el piloto
de UIO salvo lo que depende de Andrés.

**J. Regularización masiva del buzón** — ✅ **hecha el 2026-09-21, sin
subtarea del plan porque fue un pedido puntual, no una construcción.** El
buzón de la administradora tenía 124 `ATENDIDO` y 773 `CERRADO_SIN_ATENCION`
sin regularizar; Andrés pidió vaciarlo en bloque asumiendo que ella ya reportó
o cerró esos casos en SAP. Ejecutado con `regularizar_masivo_cli.php` (nuevo,
en `sistema_ots/app/publico/`), mismas reglas que los botones «Ya lo cerré en
SAP» / «Regularizar» de `casos.php`. **Pendiente de regularizar: 0.** No toca
`avisos_sap.estatus_general` — sigue siendo el único estado real de SAP (regla
5 de `ESTADO.md` §6). Detalle, evidencia y la nota sobre los «656» que dio
Andrés contra los 773 reales, en `ESTADO.md` **§1h**. Si el buzón vuelve a
acumularse, la herramienta ya existe: correr sin `--ejecutar` primero para
contar, avisar el número a Andrés antes del `--ejecutar`.

**I. T2.22b · InspectorBot** — ✅ **terminada el 2026-09-21.** La consola del
robot pasó de ventana de CMD a aplicación con ventana, icono propio, nombre
**InspectorBot** en el Administrador de tareas y arranque con el equipo. Vigila
**12** fuentes de estado donde la consola de texto usaba 5, y la primera vez
que corrió levantó una alerta grave real que llevaba días escondida: **el
saneamiento nocturno no ha corrido nunca** (`LastTaskResult 267011`). Evidencia
y método en `ESTADO.md` **§1f**; no lo repitas aquí. Trajo de paso la red de
seguridad del trabajo en curso (`scripts/guardar_sesion.py` + hook `Stop`),
en **§1g**.

> **Lo que InspectorBot dejó a la vista — ✅ resuelto el mismo día.** El
> nocturno nunca había corrido; con acceso de administrador se recreó con
> SYSTEM, se corrigió un comando roto que la tarea traía de fábrica, se le dio
> a SYSTEM su propia llave SSH (el ssh de Windows rechaza una llave con dos
> cuentas en el ACL), y se corrió de verdad por primera vez: 213 min, los 8
> pasos en `ok`, verificado contra la base en vivo. Detalle en `ESTADO.md`
> **§1j**. Ya no bloquea nada de la acción **B**.

**H. T2.21 · Cerrar la cadena del correo** — ✅ **CERRADA DEL TODO el 2026-09-21,
0 pendientes.** Corrió el `--ejecutar` en dos rondas (el masivo del 20-sep y el
cierre caso por caso del 20/21-sep) y cuadró en las tres dimensiones las dos
veces: árbol 7.123 → **7.457** PDF, `ots` 7.469 → **7.803** filas, activas
7.118 → **7.452**, todas +334 exacto, con 7.452 hashes distintos entre las
activas (cero duplicados) y 334 PDF subidos y verificados en el servidor. La
evidencia completa está en `ESTADO.md` **§1d**; no la repitas aquí.

Quedaron hechas T2.21.1 a T2.21.4, T2.21.6, T2.21.7 y el encadenamiento al
nocturno (ver más abajo). **T2.21.5 sigue abierta**, es la única que queda de
toda la tarea.

**Tres decisiones de Andrés del 2026-09-20 que cerraron hallazgos abiertos, y
que mandan sobre lo que decía la sección de los cinco hallazgos:**
1. **No hace falta distinguir el origen en la base.** Todo lo genera el sistema
   antiguo igual y esa parte desaparece cuando entre el sistema nuevo, así que
   `t1_7_ingesta.py` no se toca. El criterio viejo de T2.21.2
   (`fuente='IMAP_EN_VIVO' > 0`) queda **ANULADO**: no se persigue más.
2. **El correo dejó de ser la fuente de los informes** — lo es producción. Y
   está medido: tras promover producción, el promotor del buzón sobre sus 117
   PDF dio «promovidos 0, ya estaban 103».
3. **Todo se archiva aunque se repita**, y cuando el mismo documento llega con
   otro correlativo gana el que ya está archivado (implementado por hash). Los
   informes enviados dos veces se archivan **los dos** y decide la
   administración, con el Excel de `t2_23_informes_repetidos.py` (108 avisos,
   227 documentos, cinco casos verificados a mano entre ellos).

**Cierre caso por caso de los 41 no resueltos, el 2026-09-20/21 — los 12 que
quedaban también están resueltos, 0 pendientes:**
- **28 formularios vacíos, descartados por SHA-256** en
  `config/descartados_sha256.txt` (siguen en producción, solo no se vuelven a
  espejar).
- **1 (el aviso con espacio tecleado) archivado**, con el patrón corregido para
  aceptar espacios y SAP confirmando el aviso de forma independiente.
- **2 (Menestras, Elvita) resueltos por evidencia del interior del PDF** con
  `t2_24_promover_por_evidencia.py` — los dos ya estaban archivados por otra
  vía, sin crear un alias de cadena que habría sido el falso positivo que
  avisa `industec-archivos-canonicos`.
- **4 con local mal escrito, resueltos con alias confirmado**
  (`locales_alias` 145→149): `kh073`→K073EC, `H071k197`→K197EC,
  `G015Michelena`→G015EC, `G021Colonial`→G021EC.
- **6 de «otros clientes», verificados uno por uno — la primera lectura estaba
  mal para 1 de los 6.** `G016` no era de otros clientes: resuelve limpio a
  GUS/UIO (`G016EC`) y se promovió al árbol canónico. Los otros 5 (TropiBurger
  ×4 y el evento de Baños) sí son de clientes fuera de contrato, confirmado
  porque `METALZA` y `NOVO EVENTOS` ya eran carpetas de cliente existentes con
  historial de 2025 — no una clasificación inventada.

**El promotor del buzón, encadenado al nocturno.** `saneamiento_nocturno.py`
tiene ahora el paso `buzon` entre `normalizar` e `ingesta`, y
`t2_18_rescatar_buzon.py` sale con código distinto de 0 solo ante una colisión
real de contenido (antes siempre salía 0). Se creó la Tarea programada
**«INDUSTEC - Saneamiento nocturno»** — no existía —, diaria a las 02:30 con
reintento cada 30 min durante 2 h.

**Lo único que sigue abierto de T2.21:**
- **T2.21.5**, el filtro «Las mías» del Archivo (`ESTADO.md` §1c-bis: hoy son
  239 filas de 7.356, no «1 de 7.118» como decía antes de verificarse).

**La Tarea del nocturno, recreada con SYSTEM** — ✅ **hecha y corrida de verdad
el 2026-09-21**, con acceso de administrador. Ya no está en modo «solo
interactivo»: `LogonType ServiceAccount`, cuenta `NT AUTHORITY\SYSTEM`, corre
sin que nadie haya iniciado sesión. Traía además un comando roto (la ruta
partida en el espacio de «INDUSTECH IA») que no tenía nada que ver con la
cuenta, y una llave SSH que hubo que duplicar con ACL exclusivo para SYSTEM
porque el ssh de Windows rechaza una llave con dos cuentas distintas en su
lista de permisos. Primera corrida real: 213 min, los 8 pasos en `ok`, 7.622
archivos bajados en la primera reconciliación completa, 7.467 documentos
ingresados, verificado contra la base en vivo (no solo contra el JSON del
propio script). Evidencia completa en `ESTADO.md` **§1j**; no la repitas aquí.

**A. Fusionar `pc/pulido-2026-09-12` en `master`** — ✅ **ya está, sin que esta
sección lo dijera.** La fusión de `pc/documentos-y-otros-trabajos-2026-09-14`
del 2026-09-14 (acción **G**) traía dentro `pc/pulido-2026-09-12`, así que
llegó de arrastre. **Verificado el 2026-09-18** con
`git merge-base --is-ancestor origin/pc/pulido-2026-09-12 master` (sale 0, es
decir sí). Esta fila se dejó de actualizar cuando G absorbió su trabajo — el
mismo tipo de plan-mintiendo-por-desactualizado que el error nº 15 de más
abajo, solo que sin costo esta vez porque nadie llegó a repetir la fusión.

**B. La lista de `desarrollo/sistema_ots/piloto/ANTES_DE_EMPEZAR.md`** — *diez
pasos con su comprobación; quedan los 4 y 9, de Andrés.* Los pasos 2 (retirar
los datos de prueba) y 3 (decisión sobre las órdenes 90xx: se borran) **ya están
hechos, el 2026-09-13** — cifra verificada en `ESTADO.md` §1b. **La Tarea
programada del saneamiento ya existe y ya corre con SYSTEM**, sin depender de
que nadie inicie sesión (recreada y corrida de verdad el 2026-09-21, ver
`ESTADO.md` §1j). Faltan las cuentas de UIO y sus claves (`CUENTAS.md`) y
`SEGUNDA_COPIA` en `.env`.

**C. El piloto real en UIO** — *requiere a Andrés, y es I-8.* Con las tres hojas
del paquete (técnico, jefe de zona, administración) y el guion de cinco días de
`QUE_PROBAR.md`. Criterio: ≥ 50 correctivas y 5 preventivas sin una orden perdida
ni duplicada. Los fallos llegan por `COMO_REPORTAR_FALLOS.md` y se corrigen en el
sitio de pruebas el mismo día.

**D. Las decisiones que solo Andrés puede tomar**, y que no bloquean el piloto:
los cron de hPanel (D2 ya no: el 2026-09-14 Andrés decidió subir todo, acción G), el segundo usuario
MySQL, las cinco URL por servicio de la web (SEO) y lo que César responda de
`NOTAS_PARA_CESAR.md`.

**E. El corte (T2.16)** — *solo cuando el piloto cierre.* Siete pasos con camino
de vuelta, y la parte B de `LEEME_ACCESO_HOSTINGER.md` con lo que se hace en
producción **en bloque y una sola vez**. `t2_14_sembrar_correlativos.py
--ejecutar` va ahí, con el sistema viejo detenido; en ensayo ya dio los números.

**F. Correr T2.18 en la estación** — *autónomo, no depende del piloto.* **La
fusión ya está** (`pc/archivo-zona-franquicia-2026-09-13` también llegó de
arrastre con G, verificado igual que en A: `git merge-base --is-ancestor`
confirma que es ancestro de `master`). Lo que falta es correr los scripts, no
fusionar nada: `t2_18_clasificar_archivo.py` (simulación primero, bloqueada
hasta ahora por espacio en `G:\Mi unidad`) y `t2_18_atender_pedidos_copia.py`
para la solicitud real que ya está pendiente. El detalle completo, con lo que
no se pudo probar desde el PC por no tener acceso a `D:\RESPALDOS`, está en la
tarea T2.18 más arriba, sección «Pendiente de la estación».

**G. Fusionar la rama del 2026-09-14 y cargar todos los PDF (T2.19)** — ✅ **hecho el
2026-09-18.** Fusionada `pc/documentos-y-otros-trabajos-2026-09-14` en `master` (trae también el
pulido y T2.18), fast-forward limpio. `t2_19_pruebas.py`: 16/16. `t2_19_subir_pdfs.py`: simulación
7.172 por subir, 0 colisiones, 0 fuera de patrón; `--ejecutar --limite 500` (500/500) y `--ejecutar`
para el resto (6.672/6.672, 0 fallidos): **7.287 de 7.287 PDF de la estación ahora en el servidor.**
`t2_15_exportar_archivo.py --desde-mariadb --empujar`: 7.118 órdenes exportadas, índice en 7.356
filas, `en_servidor=1` en 7.287 (69 sin PDF, de origen CORREO/GESTIÓN sin archivo local — no es la
carga de T2.19, es lo que ya faltaba en la estación). **Bug encontrado y corregido en el camino:**
`t2_15_exportar_archivo.py` traía el fallback de la llave SSH apuntando a la ruta del PC en vez de
`config/clave_hostinger` de la estación (inconsistente con `t2_10_desplegar.py`); primer intento de
`--empujar` falló por esto, corregido y reintentado con éxito. **Criterio verificado:** los avisos
10354415, 10354383 y 10351229 tienen su PDF en `ordenes_pdf/` del servidor y su fila en `ot_archivo`
con `en_servidor=1` y `ruta` válida (verificado por SSH, no solo por el log del script). El
saneamiento nocturno ya trae el paso `pdfs` para lo nuevo. **Sigue sin hacer** (no era parte de esta
acción): el `--ejecutar` de `t2_18_clasificar_archivo.py` (la vista por zona/franquicia en
`SALIDAS IA\ARCHIVO OTS INDUSTEC`, bloqueada por espacio en `G:\Mi unidad`) y los 4 conflictos de
correlativo en `_DEL_BUZON` — ver T2.18 más arriba.

*Lo que en la versión anterior de esta sección eran las acciones A a D (las 4
comprobaciones rotas de `prueba_48h.php`, `prueba_offline.mjs`, la copia
`sitio_web/` y las hojas de capacitación) está hecho o resuelto: la batería está
en 120·0 con rutas relativas, `prueba_offline.mjs` toma PHP y navegador de
`PHP_BIN`/`CHROME_BIN`, y las hojas son el paquete del piloto (T2.14.8).*

Y si vas a **desplegar** algo, el método que hizo seguro el del 2026-09-12:

```bash
# 1. ¿Tocó alguien más este archivo en otra rama? Si devuelve algo, NO subas.
git fetch origin
git diff master origin/<otra-rama> -- desarrollo/sistema_ots/app/publico/<archivo>

# 2. Subir. `t2_10` ya compara lo que entrega la web, no solo el disco.
cd "D:\INDUSTECH IA\desarrollo\agentes"
.venv/Scripts/python.exe scripts/t2_10_desplegar.py <archivo> [...]

# 3. Si tocaste algo de la lista PRECARGA de `sw.js` (estilo.css, ui.js, app.js,
#    cola.js, guia.js, index.html), SUBE `VERSION` en sw.js y despliégalo: se
#    sirve cache-first y el navegador que ya instaló la app no vería el cambio.
#    Hoy va en v10, y el repositorio y el servidor coinciden.
```

---

> **Lo que sigue es el registro de lo ya hecho**, no tareas pendientes. El paso
> 0 (fusionar la rama del PC) **se cumplió el 2026-09-12**: `master` la contiene
> y está empujado. Respaldo del `master` previo en la rama local
> `respaldo/pre-fusion-2026-09-12`. Método en §5.2b de [`ESTADO.md`](ESTADO.md).

**0. Fusionar en la estación la rama `pc/auditoria-2026-09-10`** del remoto privado
(`git fetch origin && git merge origin/pc/auditoria-2026-09-10`; si `git merge` se niega
por cambios locales en `ESTADO.md` u otro archivo, se apartan antes con
`git stash push -- <archivo>` y se devuelven con `git stash pop`) y **reiniciar la tarea
programada del vigilante**, que así toma el IDLE de 9 minutos. Esa rama trae las
correcciones de la auditoría del 2026-09-10 ([`AUDITORIA_2026-09-10.md`](AUDITORIA_2026-09-10.md)):
desplegar desde la estación sin fusionarla pisa en el servidor lo que ya se corrigió: desde
el 2026-09-10 el sitio de pruebas tiene `sw.js` v4, `pdf.php`, `nucleo/Reconciliar.php`,
`nucleo/Auth.php` y los dos `.htaccess` de esa rama.

**Actualización del 2026-09-11 (desde el PC, por decisión de Andrés: el sitio de pruebas no lo
usa nadie de INDUSTEC).** T2.12.1, T2.12.2 y T2.12.3 **están hechos**: la 007 se aplicó dos
veces sin duplicar (`verificar_esquema.php` «permisos (con la 007)» y TODO OK; pasan las 20
comprobaciones de los dos bloques del pie) y los 48 archivos confirmados del rediseño están en el
servidor, verificados por hash. Herramientas y respaldo previo en `~/respaldos/` del servidor
(`verificar_007.php`, `revertir_007.php`). **No se vuelve a aplicar ni a desplegar desde la
estación sin fusionar antes la rama.** Lo que sigue: T2.12.4 a T2.12.6 (alcance por rol, con
cuentas de prueba) y después T2.13 por el camino (a).

**Y la verificación por rol, el mismo 2026-09-11:** T2.12.3 a T2.12.6, T2.12.9, T2.12.10, T2.13.0 y
T2.13.1 pasan (87 comprobaciones con ingreso real; herramientas en `desarrollo/sistema_ots/app/pruebas/servidor/`,
con su LEEME). T2.12.7 y T2.12.8 también pasan, con un navegador de verdad (`prueba_cola_vivo.mjs`).
Falta T2.12.12 (hojas de capacitación).

**T2.13.7, la hora de Ecuador, también el 2026-09-11:** `Db.php` fija la zona de la base y la de PHP,
y las fechas que el servidor había guardado en UTC se corrieron una sola vez (`hora_ecuador.php`, con
respaldo previo en `~/respaldos/`). T2.13.2 y T2.13.3, el mismo día:
la bandeja, el historial y el formulario del técnico salen de la base (`verificar_bandeja.py`). T2.13.5
(el buzón de avisos del técnico) y T2.13.4 (su cronograma), también. Y la 008, la emisión: la orden sale de la
app con su número, su PDF y su correo en cola (`verificar_emision.py`). Con eso T2.13 queda hecha en el
sitio de pruebas; lo que sigue es el piloto en UIO cuando empiece el uso real (I-8) y, para el
corte, el despachador de la cola y los contadores reales.

**La web corporativa de INDUSTEC** quedó publicada ese mismo día en la raíz del sitio de pruebas, con
`/acceso/` como puerta de entrada al sistema (`desarrollo/web_corporativa/LEEME.md`, §8).

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

**Y en el mismo despliegue, subir `VERSION` en `sw.js`.** `estilo.css` va en la
lista de precarga y se sirve **cache-first**: sin subir la versión, quien ya
instaló la aplicación se queda con la hoja vieja y no ve nada, sin un solo
error. La prueba de contratos no lo detecta: solo exige v3 o más.

> **Corrección del 2026-09-12.** Este párrafo decía que `sw.js` «sigue en v3».
> Era falso: `master` está **29 commits por detrás** de
> `origin/pc/auditoria-2026-09-10`, donde ya iba en **v5** — y la causa de que
> el rediseño no se viera no era solo la versión, sino que **el CDN de Hostinger
> guardaba el código 7 días** (`eec80d2`, ya corregido con `no-cache`). Hoy el
> servidor va en **v6**. Antes de escribir en el plan algo sobre el estado del
> sistema desplegado, **mira la rama del PC, no solo este árbol** — ver §5.2b de
> [`ESTADO.md`](ESTADO.md).

Mientras la aprobación llega, lo que **sí** se puede hacer sin pedir permiso:

```bash
# 1. Que nada se haya movido: 212 comprobaciones, 0 fallos
cd "D:\INDUSTECH IA\desarrollo\sistema_ots\app\pruebas"
D:/SOFTWARE/PHP83/php.exe prueba_48h.php     # 96 · 0
node prueba_graficos.mjs                      # 62 · 0
node prueba_contratos.mjs                     # 54 · 0

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
| ~~Aplicar la 007~~ | ✅ **Aplicada el 2026-09-11** en el sitio de pruebas, dos veces sin duplicar; la 008, 009 y 010 también. La **003** de agentes quedó superada en parte por la 008 (Hostinger); lo que resta es de la estación y va con el corte |
| ~~Las 4 comprobaciones rotas de `prueba_48h.php` y `prueba_offline.mjs`~~ | ✅ **Hecho el 2026-09-13** (T2.14.7): 120·0 y rutas por `PHP_BIN`/`CHROME_BIN` |
| Desplegar a UIO, y 48 h después a LARB y CNLJ | **Andrés** (I-8). El 2026-09-11 el rediseño se subió entero al sitio de pruebas porque nadie lo usa; el piloto por zona se decide cuando empiece el uso real |
| ~~Que el PDF y el correo salgan del sistema nuevo~~ | ✅ **El PDF, desde el 2026-09-11** (la 008, en el sitio de pruebas). El correo queda en `email_queue`; falta el despachador y los datos reales del corte: contadores de `counter_{zona}.txt` y destinatarios en `config.php` |
| El 36% del correctivo que el buzón no trae | Confirmar la causa — ver `SALIDAS IA\OTS\HALLAZGO_BUZON_VS_SAP.md` |
| Respaldo TrueNAS (T1.9) | Acceso físico al equipo |
| Metas reales de SLA (T2.2) | El anexo de niveles de servicio del contrato con KFC |
| ~~Sacar el proyecto del único disco~~ | ✅ **Hecho el 2026-09-10:** remoto privado `AndresIndustech/industec-bia-soft-erp`. La base y el árbol canónico siguen en un solo disco hasta el TrueNAS |
| ~~Desplegar los arreglos que ya afectan al sitio en uso y corregir las 2 filas «ASIGNADO» sin técnico~~ | ✅ **Hecho el 2026-09-10** con aprobación de Andrés: `sw.js` v4, `pdf.php`, `nucleo/Reconciliar.php`, `nucleo/Auth.php` y los dos `.htaccess`; huérfanas a NUEVO. `login.php` y `usuarios.php` suben con T2.12.3 porque necesitan el `Ui.php` y el `estilo.css` del rediseño. Ver [`AUDITORIA_2026-09-10.md`](AUDITORIA_2026-09-10.md) §3 |
| Delegado de protección de datos ante la SPDP | Trámite: gratis, en línea, guía en `TRAMITE_DELEGADO_DATOS.md`. **El plazo venció hace más de 8 meses** |
| **Recuperar el PDF de `OT-2488-K061-10351229-CNLJ`** — **lo resuelve T2.19 (acción G):** el 2026-09-14 Andrés mostró que la estación lo tiene dos veces, en `_DEL_BUZON` y en el árbol canónico como `OT-2488-K061EC-10351229-CNLJ`; los dos suben y el buzón los junta por aviso. Lo que sigue es el detalle anterior (aviso `10351229` / crudo `000010351229`, local `K061EC` Mall del Río Cuenca, zona CNLJ, cadena KFC; cerrada por informe el 2026-09-11, reportada por Andrés el 2026-09-13 — el clic caía en el 404 de `pdf.php`, causa ya corregida en `casos.php`, ver §11b «errores que ya se pagaron» #16) | **La estación, con acceso a `D:\RESPALDOS`** (no accesible desde este PC ni desde el PC de Andrés). Buscar el archivo en la carpeta canónica `D:\RESPALDOS\ORDENES DE TRABAJO\2026\CORRECTIVO\CNLJ\KFC\` (o por el aviso `10351229`/`000010351229` dentro del texto del PDF, con `industec-extraccion-pdf`) y subirlo por scp como `ordenes_pdf/OT-2488-K061-10351229-CNLJ.pdf` en el sitio de pruebas (mismo nombre exacto, sensible a mayúsculas); después `php archivo_indexar_cli.php --solo-pdf` por SSH para que el Archivo lo indexe. Si no aparece en la carpeta canónica, el informe nunca llegó a bajarse del buzón — mismo cuadro que `OT-2503-K146-10354374-CNLJ`, hallado el 2026-09-13 sin `fuente_ruta` al simular `t2_18_atender_pedidos_copia.py` contra darkviolet — y hace falta revisarlo con `t2_11_informes_ot.py` o pedir una copia nueva al local. La rama `pc/archivo-zona-franquicia-2026-09-13` (T2.18, del propio Andrés, **sin fusionar con `pc/pulido-2026-09-12` ni con `master`**) ya trae construido y probado en simulación justo este mecanismo (`t2_18_clasificar_archivo.py` + `t2_18_atender_pedidos_copia.py`, que atiende la cola `ot_archivo_solicitudes` del botón «Pedir copia»): fusionarla y correrla contra el `D:\RESPALDOS` real es más rápido que resolver este caso a mano |

### Entorno

```bash
cd "D:\INDUSTECH IA\desarrollo\agentes"
.venv/Scripts/python.exe scripts/<script>.py     # siempre el venv, no el python del sistema

D:/SOFTWARE/PHP83/php.exe                        # el PHP local de la estación, para -l y las pruebas
                                                 # (el servidor corre 8.2.33; en el PC de Andrés se usa un
                                                 #  PHP 8.2 portable y las pruebas toman PHP_BIN)
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
11. **Restar fechas en PHP que guardó MySQL.** Que coincidan lo decide la configuración del
    hosting, no el código (el 2026-09-10 la base y el PHP de la web corren los dos en UTC): el
    reloj de 48 h y el bloqueo por intentos se calculan en SQL. La hora del negocio, la de
    Ecuador, es otro asunto: T2.13.7, hecha el 2026-09-11 (`Db.php` fija las dos zonas).
12. **Probar solo contra la base de la estación.** En Hostinger la intercalación del servidor
    (`utf8mb4_unicode_ci`) chocaba con la que MariaDB les da a los parámetros de las preparadas
    nativas, y `NULLIF(?, '')` tumbaba con error 500 el veredicto, los cierres y las novedades; en la
    estación no se veía. Lo que se despliega se prueba contra el servidor, con cuentas de prueba.
13. **Dar por desplegado lo que quedó en el disco del servidor.** El CDN de Hostinger guarda una
    copia por dirección y por compresión, y marcaba el js y el css para 7 días: horas después de subir
    el `sw.js` v4 y el `estilo.css` nuevo, los navegadores —que piden comprimido— seguían recibiendo
    los viejos, mientras el hash del disco cuadraba y un `curl` sin compresión daba lo nuevo. El
    `.htaccess` pide revalidar el código, una purga limpió lo guardado, y `t2_10` compara al final lo
    que entrega la web en sus dos variantes. Las imágenes no se comparan: el CDN las recomprime.
14. **Llevar los estilos a la hoja común y olvidar las clases de una pantalla.**
    El rediseño del 2026-09-10 recogió en `estilo.css` lo que cada página tenía
    en su `<style>`, y se quedaron fuera `.equipo`, `.persona`, `.cifras` y
    `.asignar` (toda la pantalla de asignación) y `.clave` (la contraseña
    temporal de `usuarios.php`). **Eso no da un error:** da el HTML intacto, las
    pruebas en verde y la pantalla en texto plano. Se descubre mirando, no
    ejecutando. La comprobación es cruzar las clases usadas contra las definidas
    — y **hazla completa**: la primera pasada se hizo mal y concluyó «era la
    única», cuando eran dos. La segunda cayó justo en la cadena que una persona
    copia a mano y que solo se muestra una vez: la contraseña temporal.
15. **Dar por cierto el estado del proyecto mirando solo tu árbol de trabajo.**
    Se escribió en este plan que `sw.js` «sigue en v3» y que aplicar la 007 era
    la siguiente acción. Las dos cosas eran falsas: `master` estaba **29 commits
    por detrás** de `pc/auditoria-2026-09-10`, donde la 007 ya estaba aplicada,
    el rediseño desplegado y `sw.js` en v5. Un `git log --oneline
    master..origin/<rama>` lo habría dicho en un segundo, y un
    `git merge-base --is-ancestor` habría dicho además que todavía se podía
    avanzar sin fusionar. **El disco local no es el estado del proyecto**, y
    escribir el plan sin mirar las ramas manda a la siguiente conversación a
    repetir trabajo hecho.
16. **Corregir un hallazgo en una sola pantalla que lo repite, y no buscar las demás.**
    `Emision::existePdf()` se creó para H-02 (`mis.php` pintaba «Ver PDF» sin
    comprobar que el archivo existiera) y se aplicó ahí y en `ordenes.php`. Nadie
    volvió a revisar el resto de las pantallas que arman `pdf.php?ot=…`, y
    `casos.php` —el buzón, la pantalla que más gente abre— se quedó con el mismo
    hueco: el número de la orden de cierre enlazaba siempre a `pdf.php`, con o sin
    archivo, y caía en el 404 «El PDF de esa orden no está en el servidor.»
    Reportado por Andrés el 2026-09-13 (caso `OT-2488-K061-10351229-CNLJ`),
    corregido el mismo día en `casos.php` (commit `00870b4`,
    `pc/pulido-2026-09-12`). **La corrección de un patrón no está completa hasta
    que se verifica en todos los sitios donde el patrón se repite**: `grep -rn
    "pdf.php?ot="` sobre `app/publico` es el comando, y da seis sitios; verificar
    los seis, no solo el que reportaron.
17. **Leer un hecho de una sola de sus fuentes.** El buzón decidía si un caso
    estaba atendido mirando solo `atenciones.json`, que es una ventana del
    correo, mientras la orden de cierre que dejan la app y la reconciliación
    vivía en `casos_gestion`: 36 casos atendidos se veían «sin atender» en la
    misma fila que decía «atendido · técnico (del informe)» (10354415,
    10354383), y 19 órdenes de cierre no estaban en el índice del Archivo. Y el
    mismo informe llega con dos nombres (`K061` por correo, `K061EC` en el
    árbol), así que se relaciona por aviso. **Cuando un hecho tiene más de una
    fuente, se junta en un solo sitio (`Casos::documentos()`) y todas las
    pantallas leen de ahí.** De paso: el `casos.php` vivo era el de `014b529`;
    el arreglo `00870b4` estaba confirmado y empujado, pero **nunca se había
    desplegado**. El `sha256sum` del archivo vivo contra `git show
    <commit>:<ruta>` de cada versión dice cuál está arriba.
18. **Cambiar la carpeta donde un script deposita, sin mover a sus
    consumidores.** El 2026-09-13, T2.15.3 mandó a `t2_11_informes_ot.py` a
    guardar los informes del correo en `_ORIGEN_BUZON` en vez de
    `ORDENES DE TRABAJO\_DEL_BUZON`, para que la ingesta dejara de tomarlos con
    nombre crudo. El cambio era correcto y su criterio de aceptación —
    `SELECT COUNT(*) FROM ots WHERE ruta_pdf LIKE '%_ORIGEN%'` → 0 — **pasa
    hoy**. Lo que nunca se escribió es la puerta que reemplazaba a la que se
    cerró: ningún script promueve `_ORIGEN_BUZON` al árbol canónico, y los dos
    consumidores que existían (`t2_18_rescatar_buzon.py:52` y
    `t2_19_subir_pdfs.py:77-78`) quedaron mirando la carpeta vieja. Resultado
    medido el 2026-09-18: **104 informes varados, 0 clasificados, 0 en `ots`, 0
    en el servidor**, creciendo ~20 al día. Y es la **segunda vez con la misma
    forma**: el 2026-09-13 el proyecto ya pagó esto con `_DEL_BUZON` (53 OTs
    cuatro días sin entrar) y lo cerró con un rescatador de un solo uso. **Un
    criterio de aceptación que comprueba que algo NO entra no comprueba que
    entre por otro lado.** Al mover un destino: `grep -rn "<carpeta vieja>"` y
    reapuntar a cada consumidor en el mismo commit, y el criterio se escribe
    sobre el destino final del dato, no sobre la carpeta intermedia.
19. **Leer un solo buzón IMAP y creer que es «el correo».** Los tres scripts
    abren `INBOX` y nada más. La cuenta tiene 10 carpetas: el 2026-09-18 había
    **157 informes en `Trash`, 123 con «Estado de OT: Cerrada», y 76 de ellos
    de casos que el sitio seguía mostrando pendientes** — trabajo hecho que el
    sistema daba por no hecho, porque alguien archiva el correo a diario. Y ya
    existe una carpeta **`INFORMES OT`** vacía que nadie lee: el día que la
    administración empiece a usarla, lo que muevan ahí desaparece. Antes de
    afirmar «el robot lee el correo», listar las carpetas (`M.list()`) y decidir
    explícitamente cuáles entran y cuáles no.
20. **Dos `ssh.exe` en la misma estación, y no se comportan igual.** El de Git
    Bash tolera los permisos del archivo de llave privada; el de
    `C:\Windows\System32\OpenSSH` los exige, y si `config\clave_hostinger`
    queda accesible para «Usuarios autenticados» (herencia normal de carpeta
    en Windows), lo rechaza con `UNPROTECTED PRIVATE KEY FILE` y sale 255.
    Probado a mano desde Git Bash, todo pasaba. Como la Tarea programada corre
    por `cmd.exe`, usa el de Windows: **T2.21.7 nunca habría funcionado desde
    el Programador**, y nadie lo habría notado porque el error no decía la
    causa. Costó una tanda entera diagnosticarlo el 2026-09-20. Antes de dar
    por bueno un mecanismo que se dispara desde una Tarea programada:
    probarlo con el `ssh.exe` que la Tarea realmente usa
    (`C:\Windows\System32\OpenSSH\ssh.exe`), no con el del PATH de la consola
    de desarrollo. `hostinger_ssh.py` ahora avisa solo si detecta el permiso
    abierto.
21. **Un entregable que reporta «0» sin refutar el propio «0».** El Excel para
    la administración de informes repetidos falló dos veces seguidas, y las
    dos veces el número publicado era el equivocado, no una excepción visible:
    (a) reportó 0 repetidos porque `extraer_pdf()` siempre devuelve la clave
    `error` con `None` cuando todo sale bien, y el chequeo `"error" not in d`
    daba falso siempre; (b) corregido eso, agrupaba por aviso ENTERO en vez de
    por (aviso, visita), así que un aviso con varias visitas —el caso con más
    movimiento, el que más importa revisar— escondía el par duplicado dentro
    de él. Los dos bugs se encontraron por lo mismo: comparar la salida del
    script contra 5 casos que ya se habían verificado a mano abriendo los PDF.
    Sin esa comparación, el entregable le habría dicho a la administración
    «no hay nada que decidir» cuando había 227 documentos por decidir.
    **Un `0` en un entregable de calidad se verifica contra un caso positivo
    conocido antes de publicarlo, igual que cualquier otro número.**
22. **Recortar la salida de un comando antes de parsearla por posición.**
    `guardar_sesion.py` hacía `.strip()` sobre el stdout de
    `git status --porcelain`, que se come el espacio de la primera columna **de
    la primera línea**: ` M ESTADO.md` se leía como `M ESTADO.md` y el archivo
    se respaldaba como **`STADO.md`**. Las demás líneas salían bien, así que el
    fallo era invisible salvo mirando exactamente la primera. En un script cuyo
    trabajo es no perder nada, ese recorte era el bug. **Si vas a leer un
    formato por posición de columna, no toques el texto antes**: recorta al
    final y solo lo que necesites.
23. **El `pythonw.exe` de un venv no es el intérprete: es un lanzador.** Lee
    `pyvenv.cfg` y arranca el intérprete real **como proceso hijo**. Por eso el
    vigilante aparece hoy como **dos** `python.exe` en el Administrador de
    tareas siendo uno solo, y por eso copiarlo con otro nombre dejaba dos
    procesos donde el que tenía la ventana se seguía llamando «Python». Lo que
    hay que copiar es el intérprete **base** (`sys.base_prefix`), dejándolo
    dentro de `.venv\Scripts\` para que encuentre el `pyvenv.cfg`. Y **contar
    procesos de un script Python por su línea de comandos da siempre el doble**:
    el robot de verdad es la raíz del árbol, el que no tiene padre en la lista.
24. **Armar rutas de carpetas de Windows desde `%USERPROFILE%`.** En esta
    estación el Escritorio está en `C:\Users\indus\OneDrive\Desktop` —
    redirigido por OneDrive y llamado «Desktop» con el sistema en español—, así
    que tanto `%USERPROFILE%\Desktop` como `%USERPROFILE%\OneDrive\Escritorio`
    fallan. Se pregunta con `SHGetKnownFolderPath` y se acabó el problema. Vale
    igual para Inicio, Documentos y Descargas.
25. **Un umbral de alarma que no conoce los trabajos largos del proceso que
    vigila.** InspectorBot marcaba «el robot lleva callado demasiado» a los 12
    minutos, sacados del ciclo IMAP de 9. Pero el espejo de producción tiene
    **una hora** de tiempo límite y no escribe una línea hasta terminar: la
    primera corrida ya dio rojo con el robot trabajando perfectamente. **Una
    alarma que grita cuando todo va bien enseña a ignorarla**, y entonces no
    sirve el día que tiene razón. Antes de fijar un umbral de silencio: mirar
    cuánto puede tardar legítimamente la operación más lenta.
26. **El ssh de Windows rechaza una llave privada con DOS cuentas en el ACL**,
    aunque las dos sean de confianza. Al dar a `NT AUTHORITY\SYSTEM` acceso de
    lectura sobre `config\clave_hostinger` (sumándolo al del usuario, sin
    quitar nada) el ssh de Windows la rechazó igual que si fuera legible por
    cualquiera: «UNPROTECTED PRIVATE KEY FILE». No es que desconfíe de SYSTEM
    en particular — desconfía de que el ACL tenga **más de un titular**, sea
    quien sea el segundo. La solución no es abrir el archivo: es que cada
    cuenta tenga su **propia copia** de la misma llave, cada una con ACL
    exclusivo. Mismo patrón que «cada equipo con su llave» (error nº 20), un
    nivel más abajo: «cada cuenta del sistema operativo, con su llave».
27. **Los pasos de una tarea nocturna pueden tardar horas la primera vez, y
    eso no es un cuelgue.** El paso `sync` del saneamiento nocturno tardó 213
    minutos en su primera corrida real: corre sin los límites (`--recientes`)
    que usa el vigilante, así que hizo la primera reconciliación **completa**
    contra el servidor (9.970 archivos remotos, 7.622 bajados de una vez, cada
    uno con su propia sesión SSH). Antes de decidir que algo está atascado:
    mirar si hay trabajo legítimo detrás (conexiones nuevas abriéndose,
    archivos llegando) y cuál es el timeout real del paso — aquí, 4 horas.
28. **Dar por hecho que el aviso de SAP identifica el trabajo.** No lo
    identifica: SAP cierra solo el aviso que nadie atendió en 48 horas y KFC
    abre otro por el mismo equipo, así que **un trabajo puede tener varios
    avisos** —el horno de `G006EC` llegó a cuatro—. Toda consulta que cierre,
    cuente o mida por `aviso = ?` está contando episodios, no trabajos.
    `Pendientes::resolverPorOrden()` arrastraba ese supuesto y dejaba el
    pendiente del aviso viejo abierto **para siempre**, con su reloj de 48 h
    corriendo y sin ninguna ruta que lo cerrara (T2.25.3). Antes de escribir
    un `WHERE aviso = ?`, preguntarse si lo que se busca es el episodio o el
    trabajo.
29. **Cerrar un caso por parecido.** «Mismo local, mismo equipo, pocos días» no
    basta: en `J022EC` ese par es «informe técnico para dar de baja» →
    «instalando el nuevo equipo», y en `K124EC` «no emite sonido» → «escape de
    aceite». Son trabajos distintos, y cerrarlos por similitud es declararle a
    Grupo KFC que se atendió un caso que nadie atendió (I-7). El patrón que
    sirve —y la decisión de Andrés del 2026-09-21— es **detectar y proponer, y
    que confirme quien estuvo ahí**, con la traza a su nombre.

30. **Una batería que deja el terreno cambiado rompe a la siguiente, y el fallo
    aparece lejos de su causa.** `verificar_continuidad.py` movía los avisos
    sintéticos `99990011`/`99990012`, y `verificar_bandeja.py` —que los necesita
    tal como los deja `preparar_prueba.php`, uno ASIGNADO y otro ATENDIDO, para
    distinguir la bandeja del historial— abortaba sin más explicación que «no
    están como los deja preparar_prueba.php». Una batería de servidor
    **devuelve el terreno como lo encontró**, y lo comprueba al final con su
    propia afirmación, no «con cuidado».
31. **Correr en paralelo dos baterías de servidor que usan las mismas cuentas de
    prueba.** Se pisan las sesiones y el resultado no significa nada:
    `verificar_bandeja.py` dio 17·37 con un `401 sesion_requerida` donde
    esperaba un 403, y `verificar_emision.py` reventó con `KeyError: 'avisos'`
    porque `catalogos.php` le respondió sin sesión. Ninguna de las dos cosas
    tenía que ver con el código que se estaba probando. **Se corren de a una**,
    aunque tarden.
32. **Buscar el error de una petición web en el `error_log` de la carpeta.** Ahí
    van los de CLI. Los de la web están en `~/.logs/error_log_<dominio>`, y no
    mirarlos cuesta caro: el TypeError de `Casos::cadena()` (error nº 28) se
    tragaba en un `catch`, la orden salía «bien» y el recibo decía que sí,
    mientras ningún caso de la cadena se cerraba. El log de la web lo decía en
    una línea.
33. **319 comprobaciones en verde y el formulario inutilizable.** El 2026-09-22
    ninguna cabecera del guiado abría su paso —todas abrían el último, por un
    cierre sobre una variable compartida— así que el técnico no podía ver el
    caso que traía precargado ni avanzar. **Las cuatro baterías locales y las
    siete del servidor estaban todas en verde**, porque ninguna pulsa un botón:
    comprueban códigos HTTP, contratos de archivos y datos. Una pantalla puede
    estar muerta con toda la instrumentación en verde. Lo que se le entrega a
    una persona se prueba **con gestos**, en un navegador de verdad
    (`verificar_formulario.mjs`, `verificar_sync_cerrada.mjs`).
34. **Una medida de seguridad que deja al técnico sin herramienta en el local.**
    El trabajador de servicio borraba TODA la copia guardada ante cualquier 401,
    para que lo de una sesión no se le sirviera a otra. Pero la sesión vence a
    las 12 h y se desplaza al entrar desde otro teléfono: con eso se le borraban
    catálogos, bandeja e identidad, y entraba al local sin señal con la
    aplicación **vacía**. Sesión vencida y cambio de persona **no son lo mismo**:
    ahora se borra cuando `yo.php` contesta con otro usuario. Antes de proteger
    un dato, preguntarse qué pasa con quien lo necesita para trabajar.
35. **Medir antes de creerle a una regla que "ya está hecha".** El formulario
    tenía desde H-09 la preselección del equipo del aviso. Nunca acertó: **0 de
    885**, porque comparaba la denominación entera del aviso
    (`MAQUINA DE HIELO-WM-IM100-000000000010176992`) con un código de seis
    dígitos del catálogo (`003769`) — dos numeraciones distintas. Estuvo así
    desde que se escribió, sin que nada fallara: simplemente no preseleccionaba
    nunca y el técnico buscaba a mano. Una regla de cruce sin su cifra medida
    contra los datos reales es una intención, no una función.

36. **`verificar_emision.py` deja a `verificar_http.py` sin terreno.** La de
    emisión **emite una orden de verdad**, así que el caso del técnico de prueba
    pasa a ATENDIDO y deja de estar abierto. La de HTTP necesita un caso abierto
    **con local** para sus cinco envíos de T2.13.1, y sin él falla con «sin
    local» cinco veces seguidas, que no tiene nada que ver con lo que prueba.
    **El orden correcto es `preparar_prueba.php` → `verificar_http.py` →
    `verificar_emision.py`**, y si hay que repetir la de HTTP, volver a preparar
    el arnés antes. Desde el 2026-09-22 la batería **lo dice en su salida** en
    vez de dejar cinco fallos crípticos. Es el error nº 30 aplicado a dos
    baterías concretas.

37. **El arnés de pruebas toma casos REALES y abiertos, y nadie lo deshace.**
    `preparar_prueba.php` asigna al técnico de prueba dos avisos reales de UIO
    sin técnico. El 2026-09-21 tomó el 10355931 y el 10356012, de prioridad ALTA
    y abiertos en SAP. Las baterías los «cerraron» con órdenes de prueba y
    ningún técnico real los vio durante un día. Isabel llegó a marcar uno como
    cerrado en SAP. Además, `deshacer_prueba.php` solo **desactiva** las
    cuentas y deja fuera seguimientos, sesiones y `locales_admin`. **Regla:**
    después de correr baterías de servidor, siempre `php
    ~/respaldos/limpiar_pruebas.php` (simulacro → `--ejecutar` con sus
    cifras). Y la bitácora **no se limpia**: la 009 la hizo inalterable.
38. **Un indicador en verde no prueba nada si su fuente está vacía.** «Se
    concluye en una visita» dio **100 %** durante semanas: salía de
    `pendientes`, que solo llena la app nueva y tenía 4 filas de prueba. «Sin
    pendiente» se leía como «en una visita», y en realidad 80 de los 110 casos
    tenían la orden **abierta**. **Regla:** antes de publicar un porcentaje,
    contar cuántas filas reales tiene la tabla de la que sale. Si son pocas o
    de prueba, el indicador sale «—», no 100.
39. **PHP por la entrada estándar no define `STDERR`, y `Ui::pie()` termina con
    `exit`.** Para leer el servidor sin dejar archivos se hace `ssh … "cd <sitio>
    && php" < script.php`. En ese modo `fwrite(STDERR, …)` es un error fatal
    **mudo** (sale 255 sin salida); usa `file_put_contents('php://stderr', …)`.
    Y una pantalla incluida con `include` corta el proceso en `Ui::pie()`: dibuja
    una por proceso. `pruebas/servidor/verificar_cifras.py` ya lo hace así.

40. **Un campo editable no sirve si el que envía lee otra fuente.** El correo del
    local del formulario estaba en solo lectura; volverlo editable no habría
    cambiado nada: `reunirOrden()` ni siquiera lo mandaba, y `Emision::encolar()`
    y el PDF lo tomaban **siempre del maestro** (`Emision.php:251, 371`). Antes
    de dar por arreglado un campo, seguirlo hasta quien lo consume: el envío, el
    PDF y la cola. Es el error nº 17 visto desde la pantalla.
41. **Dos conversaciones el mismo día tomaron el mismo número de tarea.** El
    2026-09-23 la de los reportes para KFC y la de las observaciones de INDUSTEC
    escribieron las dos «T2.27»; la segunda tuvo que renumerarse a T2.28 antes de
    publicar. **Antes de nombrar una tarea:** `grep -n "^### T2\." PLAN_INDUSTEC.md`,
    mirar §5.1 de `ESTADO.md` y `ls desarrollo/agentes/scripts | grep t2_NN`.

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
