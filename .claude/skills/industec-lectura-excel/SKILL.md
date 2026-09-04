---
name: industec-lectura-excel
description: Invocala antes de escribir cualquier codigo que lea un Excel o CSV del proyecto (maestro de locales, export de SAP, nomina, planes de la administracion) hacia memoria o hacia la base.
---

# Leer Excel del proyecto INDUSTEC sin perder datos en silencio

Todos los bugs mas caros de la Fase 1 nacieron aqui: no en la logica, sino en leer la columna equivocada o media hoja. Estas reglas son la traduccion directa de esos fallos.

## Procedimiento obligatorio: sondear la hoja ANTES de escribir el importador

Nunca escribas el import de memoria. Corre primero esto y pega la salida:

```bash
python -c "
import openpyxl
from openpyxl.utils import get_column_letter as L
wb = openpyxl.load_workbook(RUTA, data_only=True)
print('hojas:', wb.sheetnames)
ws = wb['NOMBRE_HOJA']
print('max_row', ws.max_row, 'max_column', ws.max_column)
for fila in ws.iter_rows(min_row=1, max_row=4):
    print([(L(i+1), i, c.value) for i, c in enumerate(fila)])
"
```

Con esa salida:
1. Fija el mapeo **letra → indice 0-based** y anotalo en un comentario junto a cada campo del INSERT (patron de `importar_avisos_sap.py:76-84`: `# X centro_coste (Local)`).
2. Localiza en que fila empieza **cada** estructura de la hoja. Pueden ser distintas.
3. Declara `COL_MINIMA` con el indice mas alto que necesitas.
4. Anota si el nombre de la hoja o del encabezado miente sobre su alcance.

## Reglas

### 1. `row[]` de `iter_rows()` es 0-based. Confirmalo, no lo cuentes a ojo

Columna B = `row[1]`, E = `row[4]`, **F = `row[5]`**.

> **Bug real (commit `0777fc1`):** `t1_5_importar_maestro_locales.py` leia `row[4]` creyendo que era la columna F. `row[4]` es la E, que contiene la etiqueta fija `"Equipos"`. Los **100 locales** quedaron con el texto `"Equipos"` como direccion de correo. Corregido en `t1_5_importar_maestro_locales.py:236-239`.

### 2. Lee el ancho real y aborta ruidosamente si no alcanza

Prohibido `max_col=<numero literal>`. Usa `max_col=ws.max_column` y valida:

```python
COL_MINIMA = 25  # hasta 'zona' (indice 24)
if ws.max_column < COL_MINIMA:
    raise SystemExit("Cambio de formato del export de SAP: se esperaban al menos "
                     f"{COL_MINIMA} columnas y hay {ws.max_column}")
```

> **Bug real (`0777fc1`):** `importar_avisos_sap.py` tenia `max_col=13`. Perdio **en silencio** 6.450 centros de coste y 6.413 ubicaciones tecnicas. Las guardas `if len(vals) > N` no protegen: con la fila corta simplemente no se cumplen y el campo queda NULL sin error ni log. Ver `importar_avisos_sap.py:46-55`.

Verificacion: `grep -rn "max_col=" scripts/*.py` — toda aparicion debe ser `ws.max_column` y estar precedida de la validacion.

### 3. Verifica donde empieza REALMENTE el primer encabezado de cada estructura

> **Bug real:** en `t1_5_importar_maestro_locales.py:72-75`, el header de la primera cadena vive en la **fila 2**, junto a `CODIGO`/`UBICACION` de las columnas B/C. Empezar en `min_row=3` se lo saltaba y dejaba sin cadena a todo el primer bloque (GUS, CASA RES, KFC daban 0). Notese que `extraer_zona` (linea 111) si usa `min_row=3`: **estructuras distintas de la misma hoja empiezan en filas distintas.**

### 4. Localiza el encabezado por contenido cuando la planilla es humana

En los libros que lleva la administracion el formato cambia mes a mes. Escanea las primeras 12 filas y exige **dos anclas simultaneas** (`#OT` u `OT` **+** `LOCAL`); si no aparecen, salta la hoja. Empareja los encabezados por **prefijo normalizado** (mayusculas, sin acentos, sin separadores), no por igualdad: `#OT INDUSTEC`, `#OT INDUST`, `#OT INDUST EVALUACION`, `#OT INDUSTEC CIERRE` son la misma columna. Deriva la fase del sufijo (`CIERRE` / `EVALUACION` / `UNICA`). Ver `t1_6b_indice_planes.py:35-42` y `:106-113`.

### 5. No confies en el titulo de la hoja ni de la columna

La hoja `LOCALES INDUSTEC` cubre las tres zonas pese a su titulo. La hoja `CUENCA` es la zona `CNLJ`. Documenta cada desajuste en un comentario y usa un mapeo explicito nombre-de-hoja → codigo canonico (`t1_5:128`).

Verificacion: `SELECT DISTINCT zona FROM locales;` debe devolver `UIO/LARB/CNLJ`, nunca `CUENCA`. `SELECT zona, COUNT(*) FROM locales GROUP BY zona;` → 31/30/34.

### 6. Convierte los identificadores numericos a entero antes de pasarlos a texto

openpyxl devuelve numeros como `float`: `str(valor)` produce `'12345.0'` y rompe la clave.

```python
aviso = str(int(raw)) if isinstance(raw, float) else str(raw).strip()
if not aviso.isdigit():
    continue
```

Mismo problema en los avisos con ceros a la izquierda (`'000010279736'`) y con sufijo `.0`: normaliza a solo digitos sin ceros iniciales antes de cualquier join. Es Nivel 1 (correccion segura), no una ambiguedad.

Verificacion: `SELECT COUNT(*) FROM avisos_sap WHERE aviso LIKE '%.%' OR aviso REGEXP '[^0-9]';` → 0.

### 7. Acepta solo fechas tipadas por Excel

Trata como fecha unicamente `date`/`datetime` (o lo que expone `.date()`). Lo demas va a NULL con el motivo en comentario. Coercionar cadenas de formato desconocido genera fechas silenciosamente equivocadas (`importar_avisos_sap.py:32-38`).

Verificacion: `SELECT COUNT(*) FROM avisos_sap WHERE fecha_notificacion IS NULL;` — si el porcentaje es alto, la hoja trae fechas como texto y hay que decidir el formato explicitamente, no descartarlas.

### 8. Ante un valor no reconocido: `None`. Nunca un valor por defecto

Todo mapeo de texto libre a valor controlado lleva rama explicita de "no reconocido" que produce NULL, y los campos de estado se validan contra lista blanca **antes** de insertar:

```python
if estatus_a not in ("CERRADO", "TRATAMIENTO", "ABIERTO"):
    estatus_a = None
```

Y `limpio()` convierte `''`, `'None'`, `'none'`, `'NULL'` textuales en `None` (`importar_avisos_sap.py:23-29`). `mapear_zona` termina en `return None` (`importar_tecnicos.py:23-31`).

### 9. Deduplica por clave de negocio en memoria y reporta cuantos traia el origen

No delegues los duplicados a la restriccion de la base. Manten un `set`, conserva la primera aparicion, cuenta las descartadas e imprime el numero: es informacion sobre la calidad del export (`importar_avisos_sap.py:47,64-67,88`).

### 10. Carga el `.env` a mano y abre con `data_only=True`

Parsea el `.env` con un bucle propio (`split('=',1)`, saltando vacios y `#`) para no depender de python-dotenv en el PATH — el mismo bloque esta identico en los tres importadores. Abre siempre con `data_only=True` (valores calculados, no formulas) y anade `read_only=True` en libros grandes.

Verificacion: `python -c "import openpyxl;print(openpyxl.load_workbook(RUTA,data_only=True).active['B3'].value)"` no debe empezar por `=`.

### 11. Escribe los caracteres no ASCII como escapes `\uXXXX`

Las rutas y literales del origen traen tildes, `ñ` y acentos agudos tipograficos:
`Path("D:/RESPALDOS/_ORIGEN_DRIVE") / "KPI\u00b4S - INDUSTEC" / "KPI\u00b4S INDUSTEC.xlsx"` (U+00B4, **no** apostrofo). `"ESPA\u00d1OL": "EL ESPANOL"`. Verificacion: `python -c "from pathlib import Path;print(Path(RUTA).exists())"` → `True`.

### 12. Comparte el mismo vocabulario entre todos los importadores

Los codigos canonicos (zonas, cadenas, estados) deben ser identicos en todos los scripts que cargan tablas que despues se cruzan.

Verificacion: `SELECT DISTINCT zona_asignada FROM tecnicos WHERE zona_asignada NOT IN (SELECT DISTINCT zona FROM locales);` → vacio.

## Compuerta de verificacion cruzada (I-10) — obligatoria antes de escribir

Los conteos esperados **no se imprimen: se comparan y abortan**. Un `print("esperado N")` junto a un valor distinto pasa desapercibido en una corrida desatendida.

1. Codifica los totales esperados como constantes al inicio, citando de donde salen (`TOTAL_ESPERADO` por cadena, `TOTAL_LOCALES_ESPERADO = 95`, `ZONA_TOTAL_ESPERADO`).
2. Ejecuta **toda** la extraccion en memoria, sin abrir conexion a la base.
3. Compara cada total acumulando un flag `ok`; imprime el desglose `esperado/real/OK-DIFERENCIA` linea por linea.
4. Cuenta los registros en cuarentena (`SIN_CLASIFICAR`) y sumalos al fallo si son > 0.
5. Si `ok` es falso: `print("ABORTADO: ... No se escribe nada en la BD")` y `sys.exit(1)`. Solo si es verdadero, conecta y carga.

> Asi se detecto el `min_row` equivocado de T1.5 y las 2 discrepancias internas del propio archivo (`BS17EC`/`BR17EC`, `CN42EC`/`CN042EC`), resueltas con evidencia y registradas en `locales_alias` en vez de corregirse a mano.

Verificacion: `python script.py; echo $?` → imprime `Verificacion cruzada: OK` y sale 0. Cambiar un esperado a proposito debe dar exit 1 y `COUNT(*)` sin cambios.

Auditoria del habito: `grep -n "esperado" script.py` — cada literal debe ir acompanado de un `sys.exit`/`raise` en las lineas siguientes, no solo de un `print`. Contraejemplo vigente: `importar_tecnicos.py:49` y `importar_avisos_sap.py:87` solo imprimen y continuan con el DELETE+INSERT pase lo que pase.

## Normalizacion en escalera de un codigo escrito a mano

Aplica en orden y **acepta solo si el resultado es unico** en el maestro:

1. Limpiar a alfanumerico y mayusculas.
2. Coincidencia directa contra canonicos, luego contra la tabla de alias.
3. **Relleno de ceros en las dos direcciones**: `str(int(digitos)).zfill(n)` — `int()` quita ceros sobrantes (`K0174`→`174`), `zfill` rellena los faltantes (`17`→`017`). Un `zfill` sin `int()` cubre solo la mitad de los casos.
4. **No asumas ancho fijo**: el maestro real mezcla longitudes (`BR17EC` 2 digitos, `CN042EC` 3). Genera candidatos 2/3/4 con y sin sufijo `EC` y deja que la existencia unica decida.
5. Prefijo de marca pegado: `Jv045`→`V045EC`, `Cj028`→`J028EC`. Quita la primera letra y reintenta; acepta solo si resuelve unico. Regla `PREFIJO_MARCA_REMOVIDO`.
6. Digito tecleado de mas: `K1167`→`K167EC`. Borra cada digito por turno; acepta solo si queda exactamente un candidato. Regla `DIGITO_SOBRANTE_REMOVIDO`.
7. Devuelve siempre `(valor, regla_aplicada)` y `None` cuando cualquier paso quede ambiguo.

Cuidado con la regex del sufijo: `^[A-Za-z]{1,2}\d{2,4}(EC)?$` — agrupa el sufijo entero. `E` + `C?` no es lo mismo y solo se nota en el conteo final. Test: `K066` y `K066EC` calzan, `K066E` no.
