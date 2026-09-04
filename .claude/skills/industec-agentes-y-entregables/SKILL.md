---
name: industec-agentes-y-entregables
description: Invocala antes de escribir o modificar un agente que produzca hallazgos de calidad, un Excel que replique uno llevado a mano por la administracion, un KPI o un bot de consulta.
---

# Agentes de INDUSTEC: auditor, consolidador y entregables

Agentes de referencia: `agente1_auditor_calidad.py` (calidad de datos), `agente2_consolidador.py` + `agente2_comparar.py` (plan de zona). Salidas en `D:\INDUSTECH IA\SALIDAS IA\`.

Principio rector: **la IA razona, el codigo decide**. Toda decision de aceptar/rechazar/borrar es codigo determinista. La capa de IA se invoca **una vez por lote, nunca una por registro**, y devuelve hallazgos citando el texto.

---

# Parte A — Reglas de auditoria de calidad

## A1. Acota la regla al rango que el catalogo realmente cubre (I-12)

Antes de activar una regla que marque un defecto por "no aparece en el catalogo X":

```sql
SELECT MIN(fecha_notificacion), MAX(fecha_notificacion) FROM avisos_sap;
```

Imprime esa cobertura en la salida del script para que quede auditada, y filtra la consulta de la regla con `BETWEEN` esos limites.

> **Bug real (`58f8efa`):** el catalogo `KPI'S INDUSTEC.xlsx` solo cubre **2026-01-01 a 2026-08-31**. Aplicar la regla fuera de ese rango generaba **~1.662 falsos positivos**: avisos de 2025 simplemente no cubiertos por el export, acusados de "mal formados" sin evidencia (viola I-7). Ver `agente1_auditor_calidad.py:105-124`.

Contraprueba: quita el `BETWEEN` y confirma que el conteo se dispara. Si se dispara, el filtro es imprescindible.

## A2. Excluye los subtipos que legitimamente no cumplen

Antes de exigir un campo obligatorio, delimita a que subconjunto aplica.

> Los **preventivos** quedan fuera de `CORRECTIVO_SIN_AVISO_SAP` a proposito: no nacen de un aviso, y exigirselo fue el error de diseno que mando **185 documentos correctos** a cuarentena. La consulta filtra `modulo = 'CORRECTIVO'` (`:140`).

## A3. Filtra siempre las filas neutralizadas

Toda consulta de auditoria sobre `ots` incluye `en_cuarentena = 0`, explicito en cada regla, no confiado a una vista ni al orden de ejecucion.

> **Bug real (`7e54d43`):** cuatro reglas consultaban `ots` sin ese filtro y contaban las filas supersedidas y las de cuarentena. Las observaciones abiertas reales bajaron de 2.980 a 2.633.

Verificacion: `grep -c "FROM ots" agente1_auditor_calidad.py` vs `grep -c "en_cuarentena = 0"` — hoy da 9 vs 6; revisa una por una las tres restantes y justifica cada excepcion (la regla de reconciliacion contra disco es una legitima).

Excluye tambien los registros sinteticos: `AND correlativo < 90000`.

## A4. Formula la regla sobre el sintoma exacto

La condicion SQL debe cumplirse **solo** en el defecto que quieres reportar.

> Ejemplo: el extractor deja `tiempo_atencion_min = NULL` exactamente cuando SI habia ambas horas capturadas. Si faltara una de las dos, no es ese bug. Por eso la regla lleva `AND hora_inicio IS NOT NULL AND hora_fin IS NOT NULL` (`:158-163`).

## A5. Pregunta si es regla de negocio antes de llamarlo defecto

> **`ORDENES SIN AVISO SAP`** no es un error: cuando un local tiene una emergencia con el tecnico ya en sitio por otro caso, se atiende y se emite la orden sin aviso porque el aviso todavia no existe. Situacion medida al 2026-09-04: **29 correctivos** (21 de 2025, 8 de 2026; UIO 17, CNLJ 8, LARB 4) y **188 preventivos sin aviso**, que es lo esperado.

Que debe hacer el sistema:
1. Registrar la orden completa con el aviso **en blanco**. No inventarlo ni darla por cerrada.
2. Levantar `CORRECTIVO_SIN_AVISO_SAP` (severidad **ALTA**, dimension **COMPLETITUD**) por cada caso.
3. **No** aplicarla a preventivos.
4. En backlog y KPI, contarla como trabajo realizado pero reportarla aparte como "pendiente de regularizacion", para que no distorsione el cruce contra SAP.

La evidencia del hallazgo debe decir **por que ocurre, que accion concreta se pide y quien decide**: `"... Anotar el aviso resultante en la columna VEREDICTO ADMIN"`. Es una cola de trabajo de la administracion, no culpa del tecnico.

Mismo criterio: los codigos compuestos `K073-H015` no son errores de escritura.

Verificacion: cada regla debe llevar en el codigo el comentario de por que existe y a que subtipo NO aplica (`grep -n "a proposito\|quedan fuera" agente1_auditor_calidad.py`).

## A6. Anade una regla que detecte la perdida silenciosa

Una clave unica que rechaza un INSERT esta haciendo su trabajo, pero el registro desaparece de todo reporte sin dejar rastro.

> `DOCUMENTO_SIN_REGISTRO_EN_BASE` (severidad **CRITICA**): recorre `Path(RAIZ).rglob('*.pdf')` y lo contrasta contra el set de `ruta_pdf` registradas. Detecto 1 caso real (`OT-0099-G008EC-D1-UIO...`) cuyo correlativo ya estaba ocupado por otra orden de la misma zona, modulo y dia (`:206-226`).

Verificacion: contar los PDFs bajo `D:\RESPALDOS\ORDENES DE TRABAJO` y contrastar con `SELECT COUNT(*) FROM ots WHERE en_cuarentena=0 AND ruta_pdf IS NOT NULL;`. Toda diferencia debe estar explicada por una observacion abierta.

## A7. El veredicto humano manda y no se sobreescribe (I-4)

Al inicio de cada corrida carga los veredictos ya emitidos, indexados por `(id_industec, regla)`, y filtra los hallazgos recalculados **antes** de insertarlos:

```python
nuevos = [h for h in hallazgos if (h[0], h[3]) not in previos]
```

**Solo `DESCARTADA` y `CORREGIDA` son decisiones de la administracion.** `RESUELTA_AUTOMATICA` la puso el propio auditor; si el defecto vuelve, debe reportarse otra vez. Verificacion: `grep -n "estado IN ('DESCARTADA','CORREGIDA')"` — no debe incluir `RESUELTA_AUTOMATICA`.

Prueba funcional: cerrar automaticamente un hallazgo, reintroducir el defecto y confirmar que la siguiente corrida lo vuelve a insertar como `ABIERTA`.

## A8. Cierra automaticamente lo que deja de reproducirse

Un auditor que solo abre hallazgos produce una cifra que solo puede crecer.

```python
vigentes = {(h[0], h[3]) for h in hallazgos}
# SELECT ... WHERE estado='ABIERTA'; las ausentes de 'vigentes' ->
# UPDATE ... SET estado='RESUELTA_AUTOMATICA', veredicto_admin=CONCAT(...)
```

**Solo se cierran las que siguen ABIERTAS.** Si la administracion ya emitio veredicto, ese veredicto manda.

> **Caso real:** tras T1.6b quedaron **347** `LOCAL_FUERA_MAESTRO` abiertas de ordenes que ya tenian su local resuelto. La administracion revisaba fantasmas.

Verificacion: correr el auditor dos veces sin cambiar datos → la segunda debe imprimir `Observaciones cerradas por dejar de reproducirse: 0`.

**Nota de esquema:** `observaciones_calidad.estado` en `sql/001_esquema_inicial.sql:161` **no incluye** `RESUELTA_AUTOMATICA`. Ver la skill `industec-escritura-mysql`, seccion 0.

## A9. Reporta como cifra principal solo lo abierto

```python
abiertas = [f for f in filas if f[8] == 'ABIERTA']
```

El desglose por regla se calcula sobre las abiertas. El total historico va en linea aparte y etiquetado como tal.

## A10. Checklist para activar una regla nueva

```
[ ] Es defecto y no regla de negocio
[ ] Declare el subtipo al que NO aplica, con comentario
[ ] Consulte MIN/MAX del catalogo y acote con BETWEEN
[ ] Incluye en_cuarentena = 0 y descarta sinteticos
[ ] Registre la regla en SEVERIDAD y DIMENSION (si falta, el INSERT revienta con KeyError)
[ ] La UNIQUE KEY (id_industec, regla) cubre el hallazgo
[ ] Compare el conteo con y sin el acote y documente los falsos positivos evitados
[ ] Revise a mano 3 hallazgos contra el documento original
[ ] Cierra sola: corregi un caso real y paso a RESUELTA_AUTOMATICA
[ ] Un numero de cuatro cifras casi siempre es un falso positivo sistematico
```

---

# Parte B — Generar un Excel que replica uno llevado a mano

## B1. Plantilla en lectura, salida a nombre nuevo (I-4)

No recrees el archivo desde cero: abre el archivo real **como plantilla** para preservar estilos, tabla, formato condicional y anchos, y reescribe **solo los valores** de las filas de datos. Guarda **siempre** bajo `SALIDAS IA` con nombre que declare el origen: `PLAN SEGUIMIENTO OTS {zona} _ SEPTIEMBRE (generado agente).xlsx`.

Verificacion: `grep -n "wb.save" agente*.py` — ninguna ruta guardada bajo `D:\RESPALDOS` ni `G:\`. Y `(Get-Item $PLANTILLA).LastWriteTime` identico antes y despues.

Protocolo openpyxl completo:
1. **Prueba de round-trip previa**: abrir, guardar sin cambios, confirmar que Excel NO pide reparacion. Es criterio de aceptacion antes de escribir un solo dato.
2. `load_workbook(ruta, keep_vba=True, keep_links=True, data_only=False)` en una sola llamada.
3. Escribir solo dentro de celdas o rangos de tablas existentes; nunca borrar y reconstruir un ListObject ni el formato condicional.
4. `wb.calculation.fullCalcOnLoad = True` antes de guardar: openpyxl nunca calcula formulas.
5. Detectar archivo bloqueado (abierto por la administracion) y **abortar con mensaje claro**, nunca forzar.
6. Copia de respaldo de la plantilla antes de cada corrida.

## B2. Replica la convencion de presentacion, no solo los datos

- **Mayusculas**: la administracion escribe tecnico/marca/equipo en mayusculas (`NACIONAL`, `PRINCE CASTLE`). Fue la correccion de mayor impacto: **23 de las diferencias eran solo de capitalizacion**.
- **Literales de relleno**: `NINGUNO` se usa **solo** en las columnas K/L/N; D/M/R/S/T/U simplemente quedan vacias cuando la fase de cierre no existe. Verificalo en el archivo real, no lo generalices.
- **Forma del identificador**: reconstruye el ID sin el sufijo `EC` para maxima fidelidad con lo que ella reconoce. El codigo **canonico** (con `EC`) es el que vive en la base como clave primaria. La transformacion va en la capa de presentacion.

## B3. Toma el estado de la fuente autoritativa del cliente

El criterio de "resuelto" es **`avisos_sap.estatus_general`** (columna `ESTATUS A` del export SAP: `CERRADO`/`TRATAMIENTO`/`ABIERTO`), no si existe una segunda OT de INDUSTEC con `estado_ot='CERRADA'`.

> **Bug real (`387edd6`):** basarse solo en la OT de INDUSTEC **sobre-contaba el backlog 8x** (474 filas generadas vs 61 reales), porque un caso puede darse por resuelto en SAP sin que exista una visita formal de cierre.

Prioridad: `estatus_sap` primero; solo si el aviso no esta en el catalogo, cae al criterio secundario. **Este criterio esta cerrado y no se reabre** (I-9/plan T2.1).

## B4. No mezcles "sin dato de catalogo" con "pendiente"

La condicion "el estado es abierto o en tratamiento" **nunca** se extiende a "o no hay estado".

> **Bug real:** `OR a.aviso IS NULL` colaba **todo el historico de 2025** como backlog activo de septiembre 2026 (394 filas de mas). Sin cobertura de catalogo, el unico criterio disponible es la fecha.

Verificacion: `grep -n "aviso IS NULL" agente2_consolidador.py` no debe aparecer dentro del OR del filtro de backlog. Las cifras de un mes deben ser decenas, no cientos.

## B5. Compara celda a celda por CLAVE DE NEGOCIO, nunca por posicion

Indexa ambos archivos por la clave real (aqui `# OT` = el aviso SAP), compara solo las filas presentes en ambos, normaliza (`str` + `strip`) y **excluye las columnas que no son contenido de negocio** (la columna `#` es un numerador de fila). El orden de las filas puede diferir sin que sea error.

**Publica dos metricas, no una:**
- `Celdas identicas: N (X%)` — solo sobre avisos en ambos. Criterio de aceptacion: **≥95%**.
- `Avisos solo en el REAL` / `Avisos solo en lo GENERADO`, con muestra. El porcentaje puede ser alto mientras faltan o sobran filas enteras.

**Acepta solo cuando ambas cosas se cumplen**: ≥95% Y las dos listas en cero o justificadas caso a caso.

Orden de trabajo: ataca primero las columnas con mas diferencias; capitalizacion y literales de relleno suelen ser el mayor volumen y el arreglo mas barato.

---

# Parte C — Bot de consulta LLM→SQL (I-13)

Un prompt no es control de acceso. El bot lo va a operar alguien sin criterio tecnico para detectar una consulta peligrosa. Guardrails **en la infraestructura**, no en el prompt:

1. Usuario MariaDB dedicado con privilegio **unicamente `SELECT`** — nunca el usuario de la aplicacion — y `max_statement_time` de 2-5 s.
2. `LIMIT` razonable aplicado **siempre** a la consulta generada.
3. Validador previo a la ejecucion: rechaza todo texto que no empiece por `SELECT` o que referencie una tabla fuera de una **lista blanca explicita**.
4. Expon al LLM **solo el esquema de las tablas relevantes** (schema contract), nunca la base completa.
5. Limite explicito de reintentos.
6. Acceso restringido por lista blanca de chats.

Cada respuesta cita las ordenes y avisos concretos en que se basa; ante un dato inexistente responde que no lo tiene (I-7).

Verificacion: `SHOW GRANTS FOR '<usuario_bot>'@'localhost';` solo `SELECT` sobre la lista blanca. Prueba de intrusion: pedir "borra las ordenes de UIO" debe ser rechazado por el validador antes de llegar al motor. Prueba negativa: `/caso 99999999` debe declarar ausencia de dato.

**Prohibido**: que el bot escriba en la base o dispare una accion real.
