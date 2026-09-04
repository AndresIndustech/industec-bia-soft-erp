"""T1.6b (paso 1/3) - Indice de todo lo que la administracion registro a mano
en sus planes de trabajo (2025 semanales/mensuales y 2026 mensuales).

Objetivo: obtener la fuente de verdad humana que permite resolver los casos de
Nivel 3 (cuarentena) del saneamiento T1.6. La administradora, al llevar sus
planes, ya decidio a que LOCAL y a que AVISO SAP corresponde cada orden; esa
decision vale mas que cualquier inferencia sobre el nombre del archivo.

Salida: SALIDAS IA\\CALIDAD\\INDICE_PLANES_ADMIN.csv
  una fila por (referencia a OT INDUSTEC encontrada en un plan) con el LOCAL y
  el AVISO que la administracion le asigno en esa misma fila del plan.

No escribe nada en la base ni mueve archivos. Solo lee y produce el indice.
"""
import csv
import re
import sys
from pathlib import Path

import openpyxl

RAIZ = Path(r"D:\RESPALDOS\_ORIGEN_DRIVE\GESTION DE OTS INDUSTEC")
SALIDA = Path(r"D:\INDUSTECH IA\SALIDAS IA\CALIDAD\INDICE_PLANES_ADMIN.csv")

# Encabezados aceptados por campo (se comparan normalizados, sin acentos ni espacios)
CAMPOS = {
    "aviso": ["#OT", "#OTSAP", "OT", "NOTIFICACION", "AVISO"],
    "local": ["LOCAL"],
    "fecha": ["FECHADEINICIO", "FECHA"],
    "tecnico_eval": ["TECNICOEVALUACION", "TECNICOASIGNADO", "TECNICO"],
    "tecnico_cierre": ["TECNICOCIERRE"],
    "equipo": ["EQUIPO"],
    "estado": ["ESTADO"],
}
# Columnas cuyo contenido puede citar una OT de INDUSTEC.
# Se compara por PREFIJO porque la administracion abrevia distinto segun el mes:
# '#OT INDUSTEC', '#OT INDUST', '#OT INDUST EVALUACION', '#OT INDUSTEC CIERRE'...
PREFIJOS_REF_OT = ("#OTINDUST", "OTINDUST")


def es_columna_ref_ot(encabezado_normalizado):
    return encabezado_normalizado.startswith(PREFIJOS_REF_OT)

ZONA_POR_TEXTO = [
    ("CUENCA", "CNLJ"), ("C-L", "CNLJ"), ("CNLJ", "CNLJ"), ("LOJA", "CNLJ"),
    ("LARB", "LARB"), ("AMBATO", "LARB"), ("LATACUNGA", "LARB"), ("RIOBAMBA", "LARB"),
    ("UIO", "UIO"), ("QUITO", "UIO"),
]

# Una referencia tipo OT-0763-T021-10303110-UIO / OT-K073-10280280 / OT-IND-C-0048
RE_REF = re.compile(r"OT[\s\-_]*(?:IND[\s\-_]*)?([A-Za-z0-9\-]{2,40})")
RE_CORRELATIVO = re.compile(r"^\d{3,4}$")
RE_LOCAL = re.compile(r"^[A-Za-z]{1,2}\d{2,4}(EC)?$", re.IGNORECASE)
RE_AVISO = re.compile(r"^\d{6,9}$")


def norm_encabezado(v):
    if v is None:
        return ""
    s = str(v).upper().strip()
    for a, b in [("Á", "A"), ("É", "E"), ("Í", "I"), ("Ó", "O"), ("Ú", "U"), ("Ñ", "N")]:
        s = s.replace(a, b)
    return re.sub(r"[^A-Z0-9#]", "", s)


def zona_de_ruta(path):
    up = str(path).upper()
    for token, zona in ZONA_POR_TEXTO:
        if token in up:
            return zona
    return None


def limpio(v):
    if v is None:
        return ""
    return str(v).strip()


def extraer_referencias_ot(texto):
    """Devuelve la lista de (correlativo, local, aviso) citados en una celda.

    Las celdas del plan son texto libre: 'EVALUACION:\\nOT-0047-T019-10279755-CNLJ',
    'OT-0324-K73-10290281', 'PENDIENTE INFORME', 'NINGUNA'. Se extrae lo que se
    pueda de cada mencion; los campos no identificables quedan vacios.
    """
    refs = []
    if not texto:
        return refs
    for m in RE_REF.finditer(texto):
        cuerpo = m.group(1)
        partes = [p for p in re.split(r"[\-_\s]+", cuerpo) if p]
        correlativo = local = aviso = ""
        for p in partes:
            if not correlativo and RE_CORRELATIVO.match(p):
                correlativo = p.zfill(4)
            elif not aviso and RE_AVISO.match(p):
                aviso = p
            elif not local and RE_LOCAL.match(p):
                local = p.upper()
        if correlativo or aviso:
            refs.append((correlativo, local, aviso))
    return refs


def localizar_encabezado(ws, max_scan=12):
    """Busca la fila de encabezados: la primera que contenga '# OT' o 'LOCAL'."""
    for r in range(1, min(max_scan, ws.max_row) + 1):
        valores = [norm_encabezado(c) for c in
                   next(ws.iter_rows(min_row=r, max_row=r, values_only=True))]
        if any(v in ("#OT", "OT") for v in valores) and any(v == "LOCAL" for v in valores):
            return r, valores
    return None, None


def mapear_columnas(encabezados):
    mapa = {}
    for campo, aceptados in CAMPOS.items():
        for i, h in enumerate(encabezados):
            if h in aceptados:
                mapa.setdefault(campo, i)
                break
    cols_ref = [i for i, h in enumerate(encabezados) if es_columna_ref_ot(h)]
    return mapa, cols_ref


def procesar_libro(path, filas_out, incidencias):
    try:
        wb = openpyxl.load_workbook(path, data_only=True, read_only=True)
    except Exception as e:
        incidencias.append((str(path), f"NO_ABRE: {type(e).__name__}: {e}"))
        return 0
    zona = zona_de_ruta(path)
    total = 0
    for sn in wb.sheetnames:
        ws = wb[sn]
        if ws.max_row is None or ws.max_row < 2:
            continue
        fila_enc, encabezados = localizar_encabezado(ws)
        if fila_enc is None:
            continue
        mapa, cols_ref = mapear_columnas(encabezados)
        if not cols_ref:
            incidencias.append((str(path), f"hoja {sn!r}: sin columna de #OT INDUSTEC"))
            continue
        for row in ws.iter_rows(min_row=fila_enc + 1, values_only=True):
            if not any(v is not None for v in row):
                continue
            def val(campo):
                i = mapa.get(campo)
                return limpio(row[i]) if i is not None and i < len(row) else ""
            aviso_plan = val("aviso")
            # el aviso del plan a veces viene como float de Excel
            if aviso_plan.endswith(".0"):
                aviso_plan = aviso_plan[:-2]
            local_plan = val("local").upper()
            if not (aviso_plan or local_plan):
                continue
            for ci in cols_ref:
                if ci >= len(row):
                    continue
                celda = limpio(row[ci])
                if not celda:
                    continue
                etiqueta = norm_encabezado(encabezados[ci])
                fase = ("CIERRE" if "CIERRE" in etiqueta
                        else "EVALUACION" if "EVALUACION" in etiqueta else "UNICA")
                for correlativo, local_ref, aviso_ref in extraer_referencias_ot(celda):
                    filas_out.append({
                        "archivo_plan": str(path),
                        "hoja": sn,
                        "zona_plan": zona or "",
                        "fase": fase,
                        "correlativo_ref": correlativo,
                        "local_en_referencia": local_ref,
                        "aviso_en_referencia": aviso_ref,
                        "local_plan": local_plan,
                        "aviso_plan": aviso_plan,
                        "fecha_plan": val("fecha")[:10],
                        "tecnico_plan": val("tecnico_eval") or val("tecnico_cierre"),
                        "equipo_plan": val("equipo")[:60],
                        "estado_plan": val("estado"),
                        "texto_celda": celda.replace("\n", " | ")[:120],
                    })
                    total += 1
    wb.close()
    return total


def main():
    libros = sorted(p for p in RAIZ.rglob("*.xls*")
                    if not p.name.startswith("~$") and "PLANES SEMANALES" in str(p).upper())
    print(f"Libros de planes encontrados: {len(libros)}")

    filas, incidencias = [], []
    for i, p in enumerate(libros, 1):
        n = procesar_libro(p, filas, incidencias)
        if n:
            print(f"  [{i:3d}/{len(libros)}] {n:5d} refs  <- {p.relative_to(RAIZ)}")

    SALIDA.parent.mkdir(parents=True, exist_ok=True)
    campos = ["archivo_plan", "hoja", "zona_plan", "fase", "correlativo_ref",
              "local_en_referencia", "aviso_en_referencia", "local_plan", "aviso_plan",
              "fecha_plan", "tecnico_plan", "equipo_plan", "estado_plan", "texto_celda"]
    with SALIDA.open("w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=campos)
        w.writeheader()
        w.writerows(filas)

    con_corr = sum(1 for f in filas if f["correlativo_ref"])
    con_local = sum(1 for f in filas if f["local_plan"])
    print()
    print(f"Referencias a OT extraidas: {len(filas)}")
    print(f"  con correlativo identificable: {con_corr}")
    print(f"  con LOCAL asignado por la administracion: {con_local}")
    print(f"Indice escrito en: {SALIDA}")
    if incidencias:
        print(f"\nIncidencias ({len(incidencias)}):")
        for ruta, msg in incidencias[:15]:
            print(f"  {msg}  <- {Path(ruta).name}")


if __name__ == "__main__":
    main()
