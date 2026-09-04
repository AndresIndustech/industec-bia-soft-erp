---
name: industec-escritura-mysql
description: Invocala antes de escribir cualquier INSERT, UPDATE, DELETE o cambio de esquema sobre la base del proyecto, y antes de dar por terminado un script que escriba en ella.
---

# Escribir en la base de INDUSTEC

Esquema en `D:\INDUSTECH IA\desarrollo\agentes\sql\001_esquema_inicial.sql`. Tablas centrales: `locales`, `locales_alias`, `tecnicos`, `avisos_sap`, `ots`, `ot_equipos`, `observaciones_calidad`, `manifiesto_saneamiento`.

## 0. El archivo de esquema y la base deben coincidir SIEMPRE

**Estado al 2026-09-04: alineados.** La base se puede reconstruir desde cero con `sql/001_esquema_inicial.sql`.

No siempre fue asi, y por eso existe esta seccion. Durante la sesion del 2026-09-04 se aplicaron dos `ALTER TABLE` en caliente desde scripts y no volvieron al archivo:

| Columna | Que le faltaba al archivo | Para que |
|---|---|---|
| `observaciones_calidad.estado` | `'RESUELTA_AUTOMATICA'` | El auditor lo usa al cerrar un hallazgo que deja de reproducirse |
| `zona` en `locales`, `tecnicos`, `ots`, `observaciones_calidad`, `plan_snapshots`, `correcciones` | `'OTRA'` | Locales atendidos fuera de las tres zonas del contrato |

Una base creada desde el archivo fallaba al primer cierre automatico con `Data truncated for column 'estado'`. El fallo solo aparece al reconstruir el entorno, que es el peor momento posible. Ya esta corregido.

**Regla:** todo `ALTER` aplicado desde un script de negocio se replica en `sql/` en el **mismo commit**, o se crea una migracion numerada `002_...sql`. Al ampliar un ENUM, agrega el valor **al final** para no alterar el orden ni el significado de los existentes, y documenta para que sirve en el `COMMENT`.

**Verificacion, antes de dar por cerrada cualquier tarea que toque el esquema** — contrasta columna por columna contra la base viva:

```sql
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='industec_ots' AND COLUMN_TYPE LIKE 'enum%';
```

Cuidado al automatizar esa comparacion: **dos tablas distintas pueden tener una columna con el mismo nombre** (`estado` existe en `observaciones_calidad` y en `manifiesto_saneamiento`, con valores completamente distintos). Una busqueda por nombre de columna sin acotar la tabla da un falso positivo; paso al escribir esta misma verificacion.

## 1. UNIQUE KEY sobre la clave de negocio, desde el CREATE TABLE (I-9)

Un `ON DUPLICATE KEY UPDATE` sin UNIQUE KEY que lo respalde **no actualiza: duplica todo en cada corrida**. Una PRIMARY KEY autoincremental no sirve: nunca colisiona, la clausula jamas se dispara.

> **Bug real (`58f8efa`):** faltaba `UNIQUE KEY (id_industec, regla)` en `observaciones_calidad`; cada corrida duplicaba **todas** las filas. Corregido como `uq_obs_ot_regla` (`001_esquema_inicial.sql:165`), con el bug documentado en el `COMMENT` de la tabla.

Claves ya resueltas y **no reabribles**:
- `ots` → `id_industec` (PK, clave natural = nombre de archivo sin extension)
- `ots` → `UNIQUE KEY uq_ots_zona_correlativo_modulo (zona, modulo, correlativo, dia_intervencion)` (:113)
- `ot_equipos` → `uq_ot_equipo (id_industec, orden)` (:131)
- `locales_alias` → `uq_alias (alias_texto)` (:32)
- `tecnicos` → `uq_tecnico_cedula (cedula)` (:48)
- `email_queue` → `(tipo_evento, referencia_ot_o_reporte, destinatario, fecha)`
- scores de repotenciacion → `(activo_id, periodo)`

Verificacion: `SHOW CREATE TABLE <tabla>\G` **antes** de dar la subtarea por completa.

## 2. Cuidado al rellenar un identificador que forma parte de la UNIQUE KEY

> **Bug real detectado antes de ejecutar:** usar `correlativo = 0` fijo para los registros incompletos colisionaba entre si, y `ON DUPLICATE KEY UPDATE` habria sobreescrito ordenes distintas en silencio. Solucion: correlativo **sintetico por combinacion (zona, modulo, dia)** en un rango reservado **90000+**, inconfundible con un correlativo real (`t1_7_ingesta.py:246-252`).

Verificacion: `SELECT zona,modulo,correlativo,dia_intervencion,COUNT(*) FROM ots GROUP BY 1,2,3,4 HAVING COUNT(*)>1;` → vacio.

## 3. DELETE completo vs upsert: lo decide quien te referencia

Antes de un `DELETE FROM tabla`:

```sql
SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
 WHERE REFERENCED_TABLE_NAME='<tabla>' AND TABLE_SCHEMA=DATABASE();
```

- **Devuelve filas** → prohibido el DELETE. Usa `INSERT ... ON DUPLICATE KEY UPDATE` sobre la clave de negocio. Caso `locales`, referenciada por `ots` desde T1.7.
- **No devuelve nada** y la tabla es pequena y totalmente reconstruible → `DELETE` + `executemany INSERT` es preferible al upsert, porque evita filas huerfanas cuando cambia la clave canonica (paso real: `BS17EC`→`BR17EC`). Casos: `tecnicos`, `avisos_sap`, `locales_alias`, `manifiesto_saneamiento`.

Deja el motivo de la estrategia en un comentario, con el riesgo concreto.

## 4. Nunca borres lo que desaparecio del origen

Al recargar un maestro con upsert: captura antes las claves existentes (`SELECT local_codigo FROM locales`), calcula el conjunto sobrante e **informalo sin borrarlo**:

> `AVISO: N locales estaban en la base y ya no figuran en el maestro. No se borran automaticamente (pueden tener ordenes asociadas). Requieren decision de la administracion.` (`t1_5_importar_maestro_locales.py:312-317`)

Prueba: inserta a mano un codigo ficticio, corre el script, comprueba que sigue existiendo y que aparecio en el AVISO.

## 5. Idempotencia

- **`cnx.autocommit = True`** en toda ingesta fila-a-fila con recuperacion de errores. Con commits periodicos y `rollback()` por fila, el fallo de UNA fila revierte todas las insertadas desde el ultimo commit (`t1_7_ingesta.py:66-69`).
- **Padre**: upsert por clave natural. **Hijos de cardinalidad variable**: `DELETE` por id padre + reinsert. Es mas simple y seguro que intentar upsert por `(padre, orden)` cuando el numero de hijos puede cambiar entre corridas (`t1_7_ingesta.py:152-155`).
- **Tablas derivadas/materializadas**: `DELETE FROM tabla` + `executemany INSERT` + un unico commit (`t1_6_ejecutar.py:217-237`).
- **`FOUND_ROWS`**: conecta con `client_flags=[ClientFlag.FOUND_ROWS]` si vas a leer `cur.rowcount` tras un UPDATE para saber si la fila existe. Por defecto MySQL cuenta filas **modificadas**: en una segunda corrida idempotente devuelve 0 y el script concluye erroneamente que los registros no existen (`t1_6b_ejecutar_resolucion.py:65-72`).

## 6. Renombrado: marca la fila vieja en la MISMA pasada

Si `id_industec` se deriva del nombre del archivo, renombrar bifurca la identidad y la fila vieja cuenta doble en todo KPI.

> **Bug real (`4a6c33f`):** 336 documentos contados dos veces. **Y su recaida (`6c03842`):** el ejecutor activaba la fila canonica pero dejaba viva la del nombre viejo, y al reejecutarlo **resucitaba las 343 supersedidas**, porque el marcado dependia de correr despues otro script.

Regla: activa la fila canonica y marca la anterior con `en_cuarentena=1` y `motivo_cuarentena='SUPERSEDIDO_POR_NOMBRE_CANONICO:{id_nuevo}'` **en la misma pasada**. Nunca se borra: queda auditable y reversible. Gana la fila cuyo `id_industec` coincide con el nombre del archivo en el arbol canonico (`t1_6b_ejecutar_resolucion.py:254-274`).

Verificacion, en las **dos** corridas:
```sql
SELECT ruta_pdf, COUNT(*) c FROM ots WHERE en_cuarentena=0 GROUP BY ruta_pdf HAVING c>1;
-- 0 filas
```

## 7. Construccion del SQL

**Genera columnas, placeholders y valores desde un unico dict.** Escribir a mano 29 columnas y contar los `%s` produjo el error `Not all parameters were used` por un placeholder de menos.

```python
columnas_sql = ', '.join(campos.keys())
placeholders_sql = ', '.join(['%s'] * len(campos))
cur.execute(f"INSERT INTO ots ({columnas_sql}) VALUES ({placeholders_sql}) ...", tuple(campos.values()))
```

**Trunca los textos con marca visible antes del INSERT.** Un campo largo por fuga de seccion no debe abortar la fila ni el lote: `_trunc(valor, largo_exacto_de_la_columna)` que deja `...[TRUNCADO]`. Las reglas concatenadas con `|` crecen sin limite: `regla[:60]`.

Verificacion: `SELECT COUNT(*) FROM ots WHERE cliente LIKE '%[TRUNCADO]%';` identifica los PDFs con fuga de seccion a revisar.

**Ante `DataError` por un campo corrupto, reintenta la fila con ese campo en NULL.** Captura `mysql.connector.errors.DataError` aparte del `Error` generico, identifica el campo por el mensaje, reintenta el INSERT completo con ese campo en `NULL`, marca `en_cuarentena=1` con motivo `FECHA_INVALIDA_EN_PDF_ORIGINAL` y registra `RECUPERADO_CON_FECHA_NULL`. Anida un `except` para el fallo del reintento. Typo real observado: `'20026-07-30'`. No pierdas 28 columnas buenas por 1 corrupta.

**Registra la fila aunque falle la extraccion**, con lo que se sabe por el nombre/ruta, `en_cuarentena=1` y motivo truncado a 200. Perder la fila equivale a perder la evidencia de que el documento existe.

## 8. Checkpoint de verificacion cruzada antes de produccion (I-10)

1. Declara y loguea la cobertura real (rango de fechas y sedes) del catalogo que vas a usar (I-12).
2. Calcula el resultado **sin escribirlo**.
3. Obten el mismo total desde una fuente **independiente**: extracto SAP, hoja de totales, maestro de Fase 1, respaldo anterior, conteo manual de una muestra.
4. Compara exactamente. Si no cuadra: aborta con mensaje explicito, deja log del desfase y escala. **No continues con advertencia.**
5. Si cuadra: escribe mediante upsert sobre la clave de negocio.

Prueba del propio checkpoint: introduce deliberadamente un desfase de 1 y confirma que aborta con exit != 0.

## 9. Certificar idempotencia antes de cerrar la tarea

```
[ ] SHOW CREATE TABLE confirma la UNIQUE KEY sobre la clave de negocio
[ ] Corri el script 2-3 veces: mismos COUNT(*) exactos por tabla
[ ] Ninguna fila supersedida o en cuarentena volvio a estado activo
[ ] El resultado no depende del orden en que corren los scripts vecinos
    (si depende, esas dos operaciones se fusionan en una sola pasada)
[ ] Ninguna fila apunta a un archivo inexistente, ningun archivo del arbol quedo sin fila
```

El proyecto exigio 3 corridas identicas consecutivas en T1.7 y 2 en T1.10.

## 10. Orden canonico del pipeline (cada paso consume lo que produce el anterior)

1. `sql/001_esquema_inicial.sql` — verificando antes la deriva de la seccion 0.
2. `t1_5_importar_maestro_locales.py` — maestro de 100 locales, con compuerta contra la hoja GENERAL.
3. `importar_avisos_sap.py` e `importar_tecnicos.py` — **adelantados a proposito**: sin el catalogo de avisos cargado, la correccion Nivel 2 por distancia de edicion no tiene contra que comparar.
4. `t1_6_saneamiento.py` (analisis, no mueve nada) → `t1_6_ejecutar.py` → `t1_6_muestra_verificacion.py`.
5. `t1_6b_indice_planes.py` → `t1_6b_resolver_cuarentena.py` → `t1_6b_verificar_resolucion.py` → `t1_6b_ejecutar_resolucion.py`.
6. `t1_7_extractor_pdf.py` + `t1_7_ingesta.py`.
7. `t1_6c_corregir_fechas.py`, `t1_6d_otros_clientes.py`, `t1_6e_altas_maestro.py`.
8. `agente1_auditor_calidad.py` — **despues de todo lo anterior**; correrlo antes produce los hallazgos fantasma que hubo que cerrar a mano.
9. `agente2_consolidador.py` + `agente2_comparar.py`.
10. Cuadre final contra el conteo original de PDFs.
