"""
Modulo de extraccion de campos de un PDF de OT INDUSTEC (correctivo o
preventivo), basado en las etiquetas reales verificadas sobre PDFs de
muestra (ver _muestra_pdf_correctivo.txt / _muestra_pdf_preventivo.txt).
No inventa campos: si una etiqueta no aparece, el valor queda en None.

Arquitectura en 2 niveles, necesaria porque un particionador de
etiqueta:valor aplicado sobre el documento COMPLETO no sabe donde termina
la ultima etiqueta de su propia lista (se traga el resto del PDF):
  1) Se divide el texto en MACRO-SECCIONES por los encabezados de seccion
     (todo en mayusculas, ej. "DATOS GENERALES", "DETALLE DEL EQUIPO").
  2) Dentro de cada macro-seccion se aplica el parser etiqueta:valor
     correspondiente, ya acotado a esa seccion.
"""
import re
import pdfplumber

# Encabezados de macro-seccion. "OBSERVACIONES" es AMBIGUO entre los dos
# tipos: en el correctivo es una seccion propia de nivel superior, pero en
# el preventivo "Observaciones:" es solo una ETIQUETA DE CAMPO dentro de
# cada bloque EQUIPO N -- tratarla siempre como corte de macro-seccion se
# "comia" ese campo de cada equipo (bug encontrado en pruebas). Por eso hay
# una lista por tipo de documento, nunca una unica lista para ambos.
ENCABEZADOS_SECCION_CORRECTIVO = [
    "DATOS GENERALES", "DETALLE DEL EQUIPO", "DETALLE DE LA INTERVENCION",
    "REPUESTOS", "OBSERVACIONES", "ESTADO DE LA OT", "EVIDENCIA FOTOGRAFICA",
    "SATISFACCION DEL CLIENTE", "FIRMA DEL ADMINISTRADOR",
]
ENCABEZADOS_SECCION_PREVENTIVO = [
    "DATOS GENERALES", "EVIDENCIA FOTOGRAFICA", "OBSERVACIONES GENERALES",
    "SATISFACCION DEL CLIENTE", "FIRMA DEL ADMINISTRADOR",
]


def _sin_tildes(s):
    tabla = str.maketrans("ÁÉÍÓÚáéíóúÑñ", "AEIOUaeiouNn")
    return s.translate(tabla)


def dividir_macro_secciones(texto, encabezados):
    """Devuelve dict NOMBRE_SECCION -> texto de esa seccion (sin incluir el
    encabezado). Los bloques "EQUIPO N" del preventivo se detectan siempre
    (no dependen de `encabezados`) porque son repetibles y propios de ese
    tipo de documento."""
    texto_norm = _sin_tildes(texto).upper()
    posiciones = []
    for nombre in encabezados:
        nombre_norm = _sin_tildes(nombre).upper()
        for m in re.finditer(rf"^{re.escape(nombre_norm)}\s*:?\s*$", texto_norm, re.MULTILINE):
            posiciones.append((m.start(), m.end(), nombre))
    # tambien "EQUIPO N" y "EQUIPO N INICIA" como cortes (preventivo)
    for m in re.finditer(r"^EQUIPO\s+\d+\s*$", texto_norm, re.MULTILINE):
        posiciones.append((m.start(), m.end(), f"__EQUIPO_{m.group(0).split()[-1]}"))

    posiciones.sort(key=lambda x: x[0])
    secciones = {}
    for i, (_s, e, nombre) in enumerate(posiciones):
        fin = posiciones[i + 1][0] if i + 1 < len(posiciones) else len(texto)
        secciones.setdefault(nombre, "")
        secciones[nombre] += texto[e:fin]
    return secciones


def extraer_etiquetas(texto_seccion, etiquetas):
    """Parser etiqueta:valor acotado a UNA macro-seccion ya delimitada."""
    if not texto_seccion:
        return {}
    patrones = [p for _, p in etiquetas]
    combinado = "|".join(f"(?:{p})" for p in patrones)
    # Dos puntos OBLIGATORIOS (no ":?"): sin esto, texto libre que por
    # coincidencia empieza con el nombre de una etiqueta ("Equipo operativo"
    # dentro de un campo Observaciones) se confundia con la etiqueta real
    # "Equipo:", cortando el bloque anterior antes de tiempo. Verificado que
    # TODAS las etiquetas reales del documento llevan ":" inmediatamente
    # despues, sin excepcion -- bug real encontrado y corregido en T1.7.
    # [ \t] (NO \s) entre los dos puntos y el valor: \s incluye \n, y con un
    # campo vacio ("Codigo Activo Fijo:" sin nada despues) el \s* "saltaba"
    # el salto de linea y devoraba la ETIQUETA SIGUIENTE completa como si
    # fuera su valor (otro bug real, tambien corregido).
    regex = re.compile(rf"^({combinado}):[ \t]*(.*)$", re.MULTILINE)
    matches = list(regex.finditer(texto_seccion))
    resultado = {}
    for i, m in enumerate(matches):
        etiqueta_cruda = m.group(1)
        resto_misma_linea = m.group(2).strip()
        campo = None
        for nombre, patron in etiquetas:
            if re.match(patron, etiqueta_cruda, re.IGNORECASE):
                campo = nombre
                break
        inicio_bloque = m.end()
        fin_bloque = matches[i + 1].start() if i + 1 < len(matches) else len(texto_seccion)
        texto_bloque = texto_seccion[inicio_bloque:fin_bloque].strip()
        valor = (resto_misma_linea + ("\n" + texto_bloque if texto_bloque else "")).strip()
        if campo and (campo not in resultado or not resultado[campo]):
            resultado[campo] = valor
    return resultado


ETIQUETAS_GENERALES = [
    ("id_industec", r"ID-ORDEN-INDUSTEC"),
    ("aviso", r"ID-ORDEN-GRUPOKFC"),
    ("fecha_atencion", r"Fecha de Atenci[oó]n"),
    ("fecha_atencion", r"Fecha de Intervenci[oó]n"),
    ("dia_intervencion", r"D[ií]a de Intervenci[oó]n"),
    ("cliente", r"Cliente"),
    ("local_texto", r"Local"),
    ("tecnico_nombre", r"T[eé]cnico Asignado"),
    ("admin_nombre", r"Administrador del local"),
    ("correo_local", r"Correo del Local"),
    ("correo_jefe_op", r"Correo de Jefe de Operaciones Local"),
    ("correo_jefe_op", r"Correo Jefe de Operaciones"),
]
ETIQUETAS_EQUIPO_CORRECTIVO = [
    ("equipo", r"Equipo"), ("marca", r"Marca"), ("modelo", r"Modelo"),
    ("serie", r"Serie"), ("codigo_activo_fijo", r"C[oó]digo Activo Fijo"),
    ("estado_equipo", r"Estado del Equipo"),
]
ETIQUETAS_INTERVENCION = [
    ("hora_inicio", r"Hora Inicio"), ("hora_fin", r"Hora Fin"),
    ("tiempo_atencion", r"Tiempo de Atenci[oó]n"), ("actividades", r"Actividades"),
]
ETIQUETAS_EQUIPO_PREVENTIVO = [
    ("equipo", r"Equipo"), ("marca", r"Marca"), ("modelo", r"Modelo"),
    ("serie", r"Serie"), ("codigo_activo_fijo", r"C[oó]digo Activo Fijo"),
    ("actividades", r"Actividades realizadas"), ("observaciones", r"Observaciones"),
]

RE_ATIEMPO = re.compile(
    r"(?:Su requerimiento fue atendido a tiempo|Sus equipos quedaron operando con normalidad)\s*:?\s*(SI|NO)",
    re.IGNORECASE,
)
RE_CALIFICACION = re.compile(r"Calificaci[oó]n\s*:?\s*(\d{1,2})\s*/\s*10")
RE_ADMIN_FIRMA = re.compile(r"Administrador\s*:\s*(.+)")


def calcular_tiempo_atencion_min(hora_inicio, hora_fin):
    try:
        h1, m1 = map(int, hora_inicio.split(":"))
        h2, m2 = map(int, hora_fin.split(":"))
        minutos = (h2 * 60 + m2) - (h1 * 60 + m1)
        return minutos if minutos > 0 else None
    except Exception:
        return None


def extraer_pdf(path):
    try:
        with pdfplumber.open(path) as pdf:
            paginas = [pg.extract_text() or "" for pg in pdf.pages]
            n_fotos = sum(len(pg.images) for pg in pdf.pages)
    except Exception as e:
        return {"error": f"NO_LEGIBLE:{e}"}

    texto = "\n".join(paginas)
    if not texto.strip():
        return {"error": "SIN_TEXTO_EXTRAIBLE"}

    es_preventivo = "MANTENIMIENTO PREVENTIVO" in _sin_tildes(texto).upper()
    resultado = {"error": None, "modulo": "PREVENTIVO" if es_preventivo else "CORRECTIVO"}

    encabezados = ENCABEZADOS_SECCION_PREVENTIVO if es_preventivo else ENCABEZADOS_SECCION_CORRECTIVO
    secciones = dividir_macro_secciones(texto, encabezados)

    generales = extraer_etiquetas(secciones.get("DATOS GENERALES", ""), ETIQUETAS_GENERALES)
    resultado.update(generales)

    seccion_satisf = secciones.get("SATISFACCION DEL CLIENTE", "")
    m_atiempo = RE_ATIEMPO.search(seccion_satisf)
    resultado["atiempo"] = m_atiempo.group(1).capitalize() if m_atiempo else None
    m_calif = RE_CALIFICACION.search(seccion_satisf)
    resultado["satisfaccion"] = int(m_calif.group(1)) if m_calif else None

    seccion_firma = secciones.get("FIRMA DEL ADMINISTRADOR", "")
    m_admin = RE_ADMIN_FIRMA.search(seccion_firma)
    resultado["admin_firma"] = m_admin.group(1).strip() if m_admin else None
    resultado["firma_presente"] = 1 if "FIRMA DEL ADMINISTRADOR" in _sin_tildes(texto).upper() else 0
    resultado["fotos_cantidad"] = n_fotos

    if es_preventivo:
        equipos = []
        i = 1
        while f"__EQUIPO_{i}" in secciones:
            campos_eq = extraer_etiquetas(secciones[f"__EQUIPO_{i}"], ETIQUETAS_EQUIPO_PREVENTIVO)
            campos_eq["orden"] = i - 1
            equipos.append(campos_eq)
            i += 1
        resultado["equipos"] = equipos
        resultado["estado_ot"] = None
        resultado["hora_inicio"] = None
        resultado["hora_fin"] = None
        resultado["tiempo_atencion_min"] = None
        resultado["repuestos"] = None
        resultado["actividades"] = None
        resultado["observaciones"] = secciones.get("OBSERVACIONES GENERALES", "").strip() or None
    else:
        eq = extraer_etiquetas(secciones.get("DETALLE DEL EQUIPO", ""), ETIQUETAS_EQUIPO_CORRECTIVO)
        eq["orden"] = 0
        resultado["equipos"] = [eq]
        interv = extraer_etiquetas(secciones.get("DETALLE DE LA INTERVENCION", ""), ETIQUETAS_INTERVENCION)
        resultado["hora_inicio"] = interv.get("hora_inicio") or None
        resultado["hora_fin"] = interv.get("hora_fin") or None
        resultado["actividades"] = interv.get("actividades") or None
        resultado["repuestos"] = secciones.get("REPUESTOS", "").strip() or None
        resultado["observaciones"] = secciones.get("OBSERVACIONES", "").strip() or None
        estado_ot_txt = _sin_tildes(secciones.get("ESTADO DE LA OT", "")).strip().upper()
        resultado["estado_ot"] = "CERRADA" if "CERRAD" in estado_ot_txt else ("ABIERTA" if "ABIERT" in estado_ot_txt else None)
        if resultado.get("hora_inicio") and resultado.get("hora_fin"):
            resultado["tiempo_atencion_min"] = calcular_tiempo_atencion_min(resultado["hora_inicio"], resultado["hora_fin"])
        else:
            resultado["tiempo_atencion_min"] = None

    return resultado
