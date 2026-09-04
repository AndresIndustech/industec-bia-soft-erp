---
name: industec-extraccion-pdf
description: Invocala antes de escribir o modificar cualquier parser de campos etiqueta:valor sobre texto de PDF, OCR o documento semiestructurado del proyecto.
---

# Extraccion de campos desde los PDFs de OT

Los PDFs los genera Dompdf 3.1.0 con subset DejaVu y `/ToUnicode` presente: **son texto extraible, no hace falta OCR**. Se leen con `pdfplumber`. El extractor de referencia es `D:\INDUSTECH IA\desarrollo\agentes\scripts\t1_7_extractor_pdf.py`.

## Arquitectura obligatoria: dos niveles

**Nunca corras un particionador etiqueta:valor sobre el texto completo del PDF.**

1. **Nivel 1 — macro-secciones.** Divide por encabezados de seccion (linea entera en mayusculas) con `finditer` de `^...$` y `re.MULTILINE`. Cada bloque termina donde empieza el siguiente corte; el ultimo termina en `len(texto)`.
2. **Nivel 2 — etiquetas.** Aplica el parser de campos **acotado al texto de esa seccion**.

> **Por que:** un particionador etiqueta:valor sobre el documento completo no sabe donde termina la ultima etiqueta de su propia lista y **se traga el resto del PDF** (`t1_7_extractor_pdf.py:7-13`).

Diagnostico: si el ultimo campo de una seccion mide miles de caracteres en vez de decenas, el problema es la delimitacion, no el patron.

```bash
python -c "
import sys; sys.path.insert(0, r'D:\INDUSTECH IA\desarrollo\agentes\scripts')
from t1_7_extractor_pdf import extraer_pdf
r = extraer_pdf(RUTA)
print(len(r['observaciones'] or ''), repr((r['actividades'] or '')[-120:]))"
```

## Las tres trampas del regex (bugs reales, ya pagados en T1.7)

### 1. Los dos puntos son OBLIGATORIOS. Jamas `:?`

Patron correcto: `^(etiqueta1|etiqueta2|...):[ \t]*(.*)$`

> **Bug real:** con `:?`, el texto libre que por coincidencia empieza con el nombre de una etiqueta (`"Equipo operativo"` dentro de un campo Observaciones) se tomaba como la etiqueta real `Equipo:` y **cortaba el bloque anterior antes de tiempo**. Verificado que todas las etiquetas reales del documento llevan `:` inmediatamente despues, sin excepcion (`t1_7_extractor_pdf.py:70-75`).

Test negativo obligatorio: alimenta un bloque de observaciones que empiece con la palabra `Local` sin dos puntos; el campo `local` debe quedar vacio.

### 2. Entre los dos puntos y el valor usa `[ \t]*`, NUNCA `\s*`

> **Bug real:** `\s` incluye `\n`. Con un campo vacio (`Codigo Activo Fijo:` sin nada despues) el `\s*` saltaba el fin de linea y **devoraba la etiqueta siguiente completa** como si fuera su valor (`t1_7_extractor_pdf.py:76-79`). Documentado tambien en la seccion 6.5 del plan y repetido en `t1_6c_corregir_fechas.py:68-70`.

Patron correcto: `r"Fecha de (?:Atenci[oó]n|Intervenci[oó]n):[ \t]*(\S*)"`

Test sintetico obligatorio:
```python
extraer_etiquetas("Codigo Activo Fijo:\nEstado del Equipo: OPERATIVO\n", ETIQUETAS)
# codigo_activo_fijo == ''  y  estado_equipo == 'OPERATIVO'
```

Auditoria: `grep -nE "\\\\s\\*" extractor.py` — cero resultados en patrones etiqueta-valor. Existen 17 PDFs reales con campos vacios (tipo `OT-0023---.pdf`): son el caso de prueba, no una excepcion.

### 3. Una lista de encabezados POR TIPO de documento, nunca una sola compartida

> **Bug real:** `OBSERVACIONES` es una macro-seccion propia en el correctivo, pero en el preventivo `Observaciones:` es solo una **etiqueta de campo dentro de cada bloque EQUIPO N**. Tratarla siempre como corte de macro-seccion se comia ese campo de cada equipo. Solucion: `ENCABEZADOS_SECCION_CORRECTIVO` (`:24-28`) y `ENCABEZADOS_SECCION_PREVENTIVO` (`:29-32`), elegidas tras detectar el tipo (`:162`).

Verificacion: `grep -n 'ENCABEZADOS_SECCION_' t1_7_extractor_pdf.py` debe mostrar 2 listas distintas; sobre un preventivo real, `[e.get('observaciones') for e in extraer_pdf(RUTA)['equipos']]` no debe dar vacios.

## Reglas de construccion

**Normaliza solo para LOCALIZAR; corta siempre sobre el texto original.** Construye una copia sin tildes/mayusculas con `str.maketrans` (**1:1 en longitud**, no `unicodedata` + filtrado), busca posiciones con `finditer` sobre ella, y recorta con esos offsets aplicados al texto original para conservar acentos y capitalizacion (`:35-37`, `:45-60`). Verificacion: `len(_sin_tildes(t)) == len(t)` → `True`.

**Nunca inventes un campo.** Deriva la lista de etiquetas de muestras reales guardadas junto al codigo (`_muestra_pdf_correctivo.txt`, `_muestra_pdf_preventivo.txt`) y deja `None` cuando no hay coincidencia. Prohibido rellenar por defecto, adivinar por posicion o mapear semanticamente sin respaldo (I-7). Ejemplo: mapear dia-de-semana a numero de visita seria una suposicion no respaldada, se deja NULL (`t1_7_ingesta.py:270-274`).

**Permite varios patrones apuntando al mismo campo.** Modela las etiquetas como **lista de tuplas** `(campo, patron)`, no como dict, para que dos redacciones alimenten el mismo destino: `("fecha_atencion", r"Fecha de Atenci[oó]n")` y `("fecha_atencion", r"Fecha de Intervenci[oó]n")`. Gana la primera coincidencia y no se sobreescribe un valor ya no vacio: `if campo and (campo not in resultado or not resultado[campo])`.

**Detecta los bloques repetibles con su propio regex**, independiente de la lista de encabezados. `EQUIPO N` se guarda con clave sintetica (`__EQUIPO_1`, ...) acumulando con `setdefault` + concatenacion, y se recorre con `while f"__EQUIPO_{i}" in secciones` (`:52-53`, `:181-188`).

**Decide el tipo por contenido y anula explicitamente lo que no aplica.** `es_preventivo = "MANTENIMIENTO PREVENTIVO" in _sin_tildes(texto).upper()`. En la rama preventivo, asigna `None` **explicito** a `estado_ot`, `hora_inicio`, `hora_fin`, `tiempo_atencion_min`, `repuestos`, `actividades` — asi el consumidor recibe siempre el mismo contrato de claves.

**Un PDF ilegible devuelve error como DATO, no como excepcion.** `{"error": f"NO_LEGIBLE:{e}"}` si falla la apertura; `{"error": "SIN_TEXTO_EXTRAIBLE"}` si el texto sale vacio (senal de escaneado). El llamador decide; el lote nunca aborta (`:147-157`).

**Los campos calculados van en `try/except` amplio y descartan lo imposible.** `calcular_tiempo_atencion_min` devuelve `minutos if minutos > 0 else None` y `except Exception: return None`. Verificacion: `SELECT COUNT(*) FROM ots WHERE tiempo_atencion_min <= 0;` → 0.

**Valida los dominios cerrados antes de entregar.** `if atiempo_val not in ('Si','No'): atiempo_val = None`. Normaliza `estado_ot` a `CERRADA`/`ABIERTA`/`None`.

## Etiquetas literales estables del documento

`ID-ORDEN-INDUSTEC:`, `ID-ORDEN-GRUPOKFC:`, `Fecha de Atencion:`, `Local:`, `Cliente:`, `Tecnico Asignado:`, `Estado del Equipo:`, `Codigo Activo Fijo:`, `Tiempo de Atencion:`, `Calificacion: N/10`.

## Cuando la fecha escrita es invalida o vacia

Criterio fijado por el cliente (2026-09-04): usar la **fecha de generacion del PDF** (`CreationDate`/`ModDate` de los metadatos), que es el mismo dia de la atencion o el siguiente. Conserva el valor original en el motivo: `FECHA_TOMADA_DE_GENERACION_DEL_PDF (el documento decia X)`. Nada se pierde. Ver `t1_6c_corregir_fechas.py:3-12`.

Alcance de la correccion: **por la condicion real, no por la marca del proceso anterior** — `WHERE fecha_atencion IS NULL OR motivo_cuarentena LIKE 'FECHA_INVALIDA%'`, porque algunas quedaron con fecha nula sin que la ingesta lo senalara.

## Diagnostico de un parser que se traga campos

1. Imprime el valor sospechoso con `repr()` y mide su longitud. Si arrastra el resto del documento: es delimitacion.
2. Comprueba si el parser corre sobre el documento completo. Si si, introduce el nivel de macro-secciones.
3. Revisa si el literal que falta es tambien encabezado en otro tipo de documento.
4. Verifica `:` obligatorio y `[ \t]*` en lugar de `\s*`.
5. Reproduce con un texto sintetico minimo y fijalo como caso de prueba permanente.
6. Confirma que la busqueda es sobre texto normalizado pero el recorte sobre el original, y que la normalizacion no cambia longitudes.
