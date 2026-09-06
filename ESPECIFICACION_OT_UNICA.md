# Especificación de la orden de trabajo única

**Fecha:** 2026-09-06 · **Decide:** Andrés Basantes (INDUSTECH) · **Estado:** vigente
**Reemplaza:** los 3 formatos que hoy conviven en producción.

---

## 1. La regla

**Un solo formato de orden de trabajo.** No tres sistemas, no tres nombres de
archivo, no tres estructuras de datos. Una orden, un registro, un nombre.

Lo que hoy son sistemas distintos pasa a ser **un campo**:

| Hoy | En el formato único |
|---|---|
| `ot_normal_v3` — correctivo, 3 copias por zona | `tipo = CORRECTIVO` |
| `ot_mantenimiento` — preventivo | `tipo = PREVENTIVO` + `dia_intervencion` |
| `ot_normal_otros` — clientes no KFC | `tipo = CORRECTIVO` + `cliente ≠ KFC` |

La diferencia estructural real entre ellos es **una sola**: el preventivo repite
el bloque de equipo, el correctivo lo lleva una vez. Se resuelve haciendo que el
bloque de equipo sea **siempre repetible, con mínimo 1**. Todo lo demás eran
copias divergidas del mismo formulario.

---

## 2. Qué se gana y qué se pierde al unificar

Inventario real de los tres formularios (`name=` de cada campo):

| | Correctivo | Preventivo | Otros |
|---|---|---|---|
| Campos | 25 | 16 + 8 por equipo (hasta 7) | 18 |
| Aviso SAP | ✅ | ✅ | ❌ **no lo captura** |
| Zona | ✅ | ✅ | ❌ **no la captura** |
| Actividades realizadas | ✅ | ❌ solo por equipo | ✅ |
| Repuestos | ✅ | ❌ | ✅ |
| Hora inicio / fin | ✅ | ❌ | ✅ |
| Estado de la OT y del equipo | ✅ | ❌ | ❌ |
| A tiempo | ✅ | ❌ | ❌ |
| Día de intervención | ❌ | ✅ | ❌ |
| Varios equipos | ❌ | ✅ hasta 7 | ❌ |

**El formato único es la unión, no la intersección.** El preventivo gana horas,
actividades, repuestos, estado y puntualidad —que hoy simplemente no registra—.
El módulo de otros clientes gana zona y aviso. Nadie pierde nada.

> Los 37 correlativos de `ot_normal_otros` no se pueden ligar a `avisos_sap` ni
> asignar a una zona. Entran como `cliente ≠ KFC`, `aviso = NULL`, y la zona se
> resuelve por el local. **Esto requiere que Andrés confirme** si esos clientes
> (Gus, TropiBurger) deben entrar a la misma base o llevarse aparte.

---

## 3. El nombre del archivo: una sola regla

```
OT-{correlativo:4}-{LOCAL}-{AVISO:8}-{ZONA}.pdf          correctivo con aviso
OT-{correlativo:4}-{LOCAL}-{ZONA}.pdf                    correctivo sin aviso
OT-{correlativo:4}-{LOCAL}-{AVISO:8}-D{n}-{ZONA}.pdf     preventivo con aviso
OT-{correlativo:4}-{LOCAL}-D{n}-{ZONA}.pdf               preventivo sin aviso
```

**Ninguno de esos segmentos lo escribe una persona.** Los construye el servidor
desde los campos ya validados. Hoy el preventivo emite `Dia 2` con espacio y el
módulo de otros emite `OT-{local}-{corr:3}` sin correlativo delante: eso deja de
poder ocurrir porque nadie teclea el nombre.

**El aviso es opcional y eso no es una excepción menor.** El preventivo no nace
de un aviso, y hay correctivos emitidos sin él —cuando el técnico ya estaba en
sitio y el aviso todavía no existía—. Exigirlo mandó 185 documentos correctos a
cuarentena en la Fase 1.

---

## 4. Los controles, campo por campo

Cada fila dice **qué error deja de ser posible**, no qué error se vuelve menos
probable. Las cifras del desorden actual están medidas sobre los datos reales.

### Identificación — aquí no queda nada al criterio del técnico

| Campo | Hoy | Control | Qué se vuelve imposible |
|---|---|---|---|
| **Local** | `<input type="text">`. **158 grafías** en 3 meses, solo 26 con sufijo `EC` | `<select>` de los 100 del maestro, con clave foránea a `locales` | Escribir un local que no existe. `BR17`/`Br017`/`BS17` deja de ocurrir |
| **Zona** | Campo del formulario, sin sanear | **Derivada del local.** No se muestra ni se envía | Zona cruzada. Y el `path traversal` que hoy permite escribir fuera del directorio |
| **Cadena** | Deducida del prefijo del código | Del maestro | Que `J018EC` se clasifique como Juan Valdez cuando es Cajun |
| **Aviso SAP** | Texto libre. Llegan `0`, `1031`, `102832q6` | 8 dígitos, validado contra `avisos_sap`. Si no está: casilla **"esta orden nace sin aviso"** con motivo | Un aviso inventado o a medias que rompe el cruce |
| **Correlativo** | `.txt` sin bloqueo. **9 pares** de órdenes distintas lo comparten | `INSERT … ON DUPLICATE KEY UPDATE valor = LAST_INSERT_ID(valor+1)`, atómico | Dos técnicos con el mismo número |
| **Correos** | Los teclea el técnico | Del maestro, no editables | Rebotes por dirección mal escrita |
| **Técnico** | Texto libre | `<select>` de los 19 activos, o la sesión iniciada | "Alexis y Mario" como si fuera una persona |

### Equipo — el catálogo ya está construido y medido

| Campo | Hoy | Control |
|---|---|---|
| **Equipo** | Texto libre. **807 grafías** para 222 tipos reales | `<select>` de los activos **de ese local**, agrupados por área. 1.173 activos, 94 de 100 locales |
| **Código de activo fijo** | Se teclea, y el módulo de otros ni lo captura aunque su plantilla lo imprima | Viene con el equipo elegido. 780 de 1.173 activos lo traen |
| **Marca / modelo / serie** | Texto libre | Se precargan de la última OT de ese mismo activo; el técnico corrige si cambió |

Los **6 locales sin activos catalogados** en SAP (`G044EC`, `G045EC`, `G047EC`,
`G054EC`, `K197EC`, `T050EC`) eligen del catálogo de 222 tipos. No se les
inventa un catálogo: quedan listados en `catalogos/COBERTURA.md` para pedirle a
Grupo KFC el registro de activos de esos locales.

### Repuestos — el hallazgo que cambia el diseño

De 4.359 órdenes con repuesto anotado hay **1.953 valores distintos**. Los ocho
más frecuentes son todos formas de decir lo mismo:

> `S/N` (874) · `Sn` (417) · `Ninguno` (303) · `Ninguna` (248) ·
> `Sin repuestos` (161) · `-` (119) · `N/A` (14) · `Sin respuestos` (11)

**2.168 filas, el 50%, solo dicen "no se usó repuesto"** en distintas ortografías.

El arreglo no es un catálogo de repuestos: es **una casilla**. *¿Se usó
repuesto?* Si no, el campo no existe. Si sí, se pide cuál. **Ese solo control
elimina la mitad del ruido por construcción**, y de paso hace medible por
primera vez cuántas intervenciones consumen material.

### Tiempos y evaluación

| Campo | Hoy | Control |
|---|---|---|
| **Inicio / fin** | `<input type="time">`, sin fecha. Toda intervención que cruza medianoche deja el tiempo vacío | `datetime-local`. Fin posterior a inicio, validado |
| **Fecha de atención** | `date()` del servidor al enviar, no cuándo se atendió | Se guardan **las dos**: `fecha_atencion` declarada y `creado_en` del servidor |
| **Estado de la OT** | Lo marca el técnico y se ha usado como criterio de cierre | Se guarda como **información complementaria**. El criterio de cierre es `estatus_general` de SAP, siempre |
| **Calificación** | 0–10 libre | Escala fija, obligatoria |

### Lo que hoy pasa y deja de pasar por sí solo

| Hoy | Con el formato único |
|---|---|
| Un GET a `submit.php` genera una OT en blanco y la envía a KFC. **87 veces** | Solo POST autenticado; el número no se reserva hasta que la orden valida |
| El POST del preventivo con 35 fotos puede superar `post_max_size`; PHP descarta `$_POST` y sale un PDF vacío | Las fotos suben por separado y el formulario avisa antes de enviar |
| El preventivo no recomprime fotos: PDFs de hasta **7,4 MB** | Misma compresión en todos los tipos |

---

## 5. Sobre el 99,99999%

Vale la pena decirlo con precisión, porque el número tomado literal —**un error
por cada diez millones**— no es alcanzable ni es la meta correcta.

El ritmo real, medido sobre los 12 meses completos que hay en la base, es de
**622 órdenes al mes — unas 7.500 al año**. Con ~30 campos cada una son ~225.000
datos anuales, así que un error por cada diez millones significaría **un error
cada cuarenta y cuatro años**. Ningún sistema que dependa del criterio de una
persona sostiene eso, y prometerlo sería exactamente lo que este proyecto no hace.

**Lo que sí se consigue es más fuerte que un porcentaje, porque no es una
probabilidad: es una imposibilidad.**

Los campos **identificadores** —local, zona, cadena, aviso, correlativo,
identidad del equipo, técnico, correos— llegan al **100% de validez estructural**,
porque un valor inválido **no se puede enviar**. No es que sea improbable: la
lista cerrada y la clave foránea no lo admiten. Esos campos son los que hoy
producen toda la ambigüedad y todo el trabajo de reconciliación.

Lo que **no** se puede garantizar por construcción, y hay que decirlo:

| Riesgo residual | Por qué no se elimina | Cómo se controla |
|---|---|---|
| El técnico elige **la freidora equivocada** entre 4 idénticas del mismo local | Ambas opciones son válidas; el sistema no puede saber cuál se reparó | Precargar la del aviso SAP cuando exista; contrastar con la recurrencia de fallas de ese activo |
| Describe mal el trabajo realizado | Es texto libre por necesidad | Obligatorio, con mínimo de contenido, y auditable por el Agente 1 |
| Declara una hora que no corresponde | Solo lo sabría una fuente externa | Se guarda también la hora del servidor; una diferencia grande se marca |
| El aviso es válido pero **no es el de esta falla** | Los dos números existen | Contraste con `centro_coste` y con la fecha de notificación |

**La medida honesta, y la que hay que reportarle a KFC**, es la del cruce con
SAP: qué porcentaje de las órdenes casa con su aviso en local, equipo y fecha.
Hoy ese cruce es lo que resuelve la ambigüedad *después*; con el formato único
la resuelve *antes*, y el cruce pasa a ser verificación en vez de reparación.

> Y hay un caso que ninguna validación debe "corregir": `K073EC`/`H015EC`,
> `K099EC`/`H032EC`, `K146EC`/`H052EC` y `K069EC`/`H014EC` son **el KFC y la
> Heladería del mismo sitio físico**. El técnico nombra el sitio, SAP nombra la
> marca cuyo equipo falló, y los dos tienen razón. Un sistema que "unifique" eso
> destruye información real.

---

## 6. Migración: cómo se llega sin romper la operación

El orden importa. Cada paso deja algo funcionando.

| # | Paso | Verificación | Depende de |
|---|---|---|---|
| 1 | Catálogos generados y revisados | `catalogos/COBERTURA.md` sin sorpresas; los 6 locales sin activos, aceptados o resueltos | ✅ **hecho** |
| 2 | Migración `003` aplicada | `correlativos` sembrada desde el contador vivo del servidor, nunca desde `MAX()` | Aprobación |
| 3 | Formulario único en el staging | Enviar una orden de cada tipo; las 3 producen el mismo formato de nombre y la misma estructura | Staging sin WordPress |
| 4 | **Piloto en UIO, 48 h** | Mismo PDF y mismos correos que hoy, y además fila en `ots` | Paso 3 |
| 5 | LARB y CNLJ | Igual | 48 h limpias en UIO |
| 6 | Preventivo | El bloque repetible funciona con 1 y con 7 equipos | Paso 5 |
| 7 | Retiro de los formularios viejos | Ningún envío nuevo por las URLs antiguas durante 15 días | Paso 6 |

**Los formularios viejos no se borran el mismo día.** Quedan sirviendo un aviso
con el enlace nuevo, hasta confirmar que ningún técnico tiene la URL vieja
guardada en el celular.

---

## 7. Lo que hace falta decidir

| Pregunta | Por qué bloquea | Quién decide |
|---|---|---|
| ¿Los clientes no-KFC (Gus, TropiBurger) entran a la misma base? | Define si `cliente` es un campo o una base aparte | Andrés |
| Los **21 pares de tipos** parecidos (`REFRIGERADORA VERTICAL` / `REFRIGERADOR VERTICAL`) — ¿son el mismo equipo? | No se unifican solos: es criterio de negocio | INDUSTEC |
| Los **6 locales sin activos** en SAP — ¿se pide el registro a KFC? | Definen si esos locales tienen catálogo real o lista genérica | INDUSTEC / KFC |
| El local sin correo en el maestro | Impide autocompletar su destinatario | INDUSTEC |
| ¿El técnico se autentica con usuario propio o con un código de zona? | Cambia el diseño de la sesión | Andrés |
