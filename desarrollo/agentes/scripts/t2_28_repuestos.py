"""
T2.28.10a — Catálogo de repuestos: análisis de las tres fuentes (Fase 1, solo lectura).

QUÉ ES
Grupo KFC tiene tres papeles sueltos sobre repuestos, ninguno cruzado con los
otros: el catálogo fotográfico de bodega (PDF), el stock real de esa bodega en
enero de 2026 (Excel) y el inventario de 2023 con los modelos donde se usa cada
pieza (Excel). Este script solo LEE las tres, arma un catálogo unificado en
memoria y lo vuelca a un Excel de propuesta para que alguien lo revise. No
escribe en la base ni en el servidor — eso es T2.28.10b/10c/10d, detrás de la
puerta D2 (decisión de Andrés), y este script no las toca.

FUENTES, EN ORDEN DE AUTORIDAD (ver T2_28_OBSERVACIONES_INDUSTEC.md líneas 657-668)
1. G:\\Mi unidad\\INDUSTEC IA\\Catalogo de Repuestos en Bodega.pdf — catálogo de
   bodega de KFC. Tabla por página: Código real | Imagen referencial | Nombre
   final | Número de parte | Equipo (la columna se llama «Equipo» en el PDF,
   pero el contenido es la MARCA — Henny Penny, Manitowoc, etc.; el documento
   la describe como «Marca» y el PDF real dice «Equipo»: gana el código, se usa
   como marca y se anota la discrepancia aquí, no se repite en cada línea).
   El número de parte trae el prefijo de Parts Town (Hen22455); S/N = sin
   número. Vigencia declarada: enero de 2025 (fecha del PDF, confirmada por su
   metadato de creación — ver hoja Vigencia).
2. D:\\RESPALDOS\\_ORIGEN_DRIVE\\DOC COMPU CB\\CATALOGO REPUESTOS BAJA 2026.xlsx,
   hoja «CATALOGO REPUESTOS COMPLETO» — cruza por código SAP con la fuente 1:
   da el stock en bodega. La especificación dice «130 repuestos»; la hoja real
   tiene 129 filas de datos con 129 códigos SAP distintos (fila 2 a 130, sin
   fila de encabezado repetida ni fila de totales). Gana el código: se reporta
   129, no 130, y queda anotado aquí y en el informe de salida.
3. D:\\RESPALDOS\\_ORIGEN_DRIVE\\Oficina Industec\\ISA 2.0\\Inventario 2023.xlsx,
   hoja «Data General» — solo para dónde se usa cada repuesto (modelos
   compatibles), cruzado por (marca, número de parte sin el prefijo de Parts
   Town). Vigencia declarada 2023; el archivo se modificó por última vez el
   2025-01-31 (ver hoja Vigencia): la etiqueta «2023» es del negocio, no del
   archivo.

NORMALIZACIÓN DE MARCA
`normMarca()` aquí es la versión mínima (mayúsculas, sin espacios dobles, sin
tildes/ñ perdidas por el PDF): mayúsculas + colapsar espacios. La tabla de
sinónimos (`marcas.json`, TRUE REFRIGERATOR → TRUE, etc.) es de T2.28.6, que
todavía no existe en este repositorio al correr este script — por eso la hoja
«Marcas por unificar» solo señala coincidencias exactas y variantes de grafía
mecánicas (mayúsculas/espacios), nunca coincidencias por parecido (I-7: no se
adivina un alias sin decisión humana, mismo principio que T2.28.8).

LÍMITE DE CODIFICACIÓN DEL PDF (anótalo antes de creer un nombre con tilde)
El PDF usa un subset de fuente Segoe UI sin mapa completo a Unicode para
vocales con tilde y la «ñ»: pdfplumber devuelve el carácter de reemplazo «�»
en esas posiciones («Alimentaci�n», «ARA�A»). No es un bug de este script: se
comprobó con `page.chars` que el propio PDF entrega ese glifo. No se intenta
adivinar la tilde correcta (I-7): el nombre queda tal como lo entrega el PDF,
con «�» donde corresponda, para que quien revise el Excel lo corrija a mano si
hace falta.

USO
    .venv/Scripts/python.exe scripts/t2_28_repuestos.py --analizar
    .venv/Scripts/python.exe scripts/t2_28_repuestos.py --analizar --sin-imagenes   # más rápido, sin volcar JPEGs

SALIDA
    D:\\INDUSTECH IA\\SALIDAS IA\\OTS\\CATALOGO DE REPUESTOS - PROPUESTA (generado agente).xlsx
    D:\\INDUSTECH IA\\SALIDAS IA\\OTS\\repuestos_img\\<codigo_sap>.jpg   (una por código SAP con imagen)
"""
from __future__ import annotations

import argparse
import datetime as dt
import re
import sys
from pathlib import Path

import openpyxl
import pdfplumber
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.utils import get_column_letter

RUTA_PDF = Path(r"G:\Mi unidad\INDUSTEC IA\Catalogo de Repuestos en Bodega.pdf")
RUTA_EXCEL_STOCK = Path(r"D:\RESPALDOS\_ORIGEN_DRIVE\DOC COMPU CB\CATALOGO REPUESTOS BAJA 2026.xlsx")
RUTA_EXCEL_MODELOS = Path(r"D:\RESPALDOS\_ORIGEN_DRIVE\Oficina Industec\ISA 2.0\Inventario 2023.xlsx")
HOJA_STOCK = "CATALOGO REPUESTOS COMPLETO"
HOJA_MODELOS = "Data General"

DIR_SALIDA = Path(r"D:\INDUSTECH IA\SALIDAS IA\OTS")
DIR_IMAGENES = DIR_SALIDA / "repuestos_img"
RUTA_XLSX_SALIDA = DIR_SALIDA / "CATALOGO DE REPUESTOS - PROPUESTA (generado agente).xlsx"

# Criterios de aceptación de T2.28.10a (documento, líneas 665-668): se comparan
# al final y se imprimen, pero NO abortan el proceso — esto es un informe hacia
# SALIDAS IA, no una escritura a la base ni a un maestro (la compuerta I-10 de
# "aborta con exit 1" es para eso, no para esto).
ESPERADO_CODIGOS_SAP_FUENTE1 = 1611
ESPERADO_MIN_IMAGENES = 1500
CODIGOS_MUESTRA_PAGINAS_1_2 = {"Hen22455": None, "Hen29898": None, "Man000007926": None}

# Marcadores de "no hay número/dato" — mismo criterio que esMarcador() de
# T2.28.6 (línea 558 de la especificación). T2.28.6 no existe todavía en este
# repositorio; cuando exista, sustituir esta lista por su función compartida.
MARCADORES_SIN_DATO = {"S/N", "SN", "S/M", "N/A", "NA", "XXX", "-", "\u2014", "NO TIENE", "SIN SERIE", "SIN PLACA", "0", ""}


# --------------------------------------------------------------------------
# Normalización — funciones compartidas por las tres fuentes
# --------------------------------------------------------------------------

def norm_txt(v) -> str:
    """Colapsa espacios (incluido \xa0) y saltos de línea. No toca tildes: el
    original se conserva tal como lo entrega la fuente (I-7)."""
    if v is None:
        return ""
    s = str(v).replace("\xa0", " ").replace("\n", " ")
    return re.sub(r"\s+", " ", s).strip()


def norm_marca(v) -> str:
    """Versión mínima de normMarca() (T2.28.6 trae la tabla de sinónimos, que
    todavía no existe): mayúsculas + espacios colapsados, sin más."""
    return norm_txt(v).upper()


def clave_numero(v) -> str:
    """mayúsculas, sin espacios/guiones/puntos — la 'clave' que pide la
    especificación para cruzar números de parte."""
    s = norm_txt(v).upper()
    return re.sub(r"[\s\-.]", "", s)


def es_marcador_sin_dato(v) -> bool:
    return norm_txt(v).upper() in MARCADORES_SIN_DATO


def quitar_prefijo_partstown(clave: str) -> str:
    """HEN51279 -> 51279. Solo si tras quitar el prefijo alfabético queda un
    resto que empieza con dígito (si no, el prefijo probablemente ES el
    número, como pasa con algunas claves de Inventario 2023)."""
    m = re.match(r"^([A-Z]+)(\d.*)$", clave)
    return m.group(2) if m else clave


# --------------------------------------------------------------------------
# Fuente 1 — PDF de bodega
# --------------------------------------------------------------------------

def extraer_fuente1(ruta_pdf: Path, dir_imagenes: Path | None):
    """Devuelve (filas_crudas, resumen). Cada fila cruda es un dict con una
    fila de la tabla del PDF, sin agrupar todavía por código SAP. Si
    dir_imagenes no es None, guarda un JPEG por código SAP (la primera
    aparición con imagen gana; los duplicados posteriores no reescriben)."""
    filas = []
    guardadas: set[str] = set()
    if dir_imagenes is not None:
        dir_imagenes.mkdir(parents=True, exist_ok=True)

    with pdfplumber.open(ruta_pdf) as pdf:
        for pi, page in enumerate(pdf.pages):
            imagenes_pagina = page.images
            for t in page.find_tables():
                filas_tabla = t.extract()
                filas_obj = t.rows
                inicio = 1 if pi == 0 else 0  # el encabezado solo está en la página 1
                for ridx in range(inicio, len(filas_tabla)):
                    row_txt = filas_tabla[ridx]
                    row_obj = filas_obj[ridx]
                    codigo = norm_txt(row_txt[0])
                    nombre = norm_txt(row_txt[2])
                    numero = norm_txt(row_txt[3])
                    marca = norm_txt(row_txt[4]) if len(row_txt) > 4 else ""

                    # celda "Imagen Referencial" = columna índice 1
                    x0, top, x1, bottom = row_obj.cells[1]
                    imagen_encontrada = None
                    for im in imagenes_pagina:
                        cx = (im["x0"] + im["x1"]) / 2
                        cy = (im["top"] + im["bottom"]) / 2
                        if x0 <= cx <= x1 and top <= cy <= bottom:
                            imagen_encontrada = im
                            break

                    tiene_imagen = imagen_encontrada is not None
                    if tiene_imagen and dir_imagenes is not None and codigo and codigo not in guardadas:
                        try:
                            crop = page.crop((x0, top, x1, bottom))
                            im_pil = crop.to_image(resolution=110)
                            destino = dir_imagenes / f"{codigo}.jpg"
                            im_pil.save(str(destino), quality=75)
                            guardadas.add(codigo)
                        except Exception as e:  # el lote nunca aborta por una imagen rota
                            print(f"  aviso: no se pudo guardar la imagen de {codigo} (pág. {pi + 1}): {e}")

                    filas.append({
                        "pagina": pi + 1,
                        "codigo_sap": codigo,
                        "nombre_final": nombre,
                        "numero_parte": numero,
                        "marca": marca,
                        "tiene_imagen": tiene_imagen,
                    })

    leidas = len(filas)
    for f in filas:
        f["_descartar"] = (not f["nombre_final"]) and es_marcador_sin_dato(f["numero_parte"])
    descartadas = [f for f in filas if f["_descartar"]]
    cargables = [f for f in filas if not f["_descartar"]]
    for f in filas:
        del f["_descartar"]
    codigos_distintos = {f["codigo_sap"] for f in cargables if f["codigo_sap"]}
    con_imagen = sum(1 for f in cargables if f["tiene_imagen"])

    resumen = {
        "fuente": "1. Catálogo de bodega (PDF)",
        "ruta": str(ruta_pdf),
        "leidas": leidas,
        "descartadas": len(descartadas),
        "motivo_descarte": "sin nombre final y sin número de parte" if descartadas else "—",
        "cargables": len(cargables),
        "unicas": len(codigos_distintos),
        "con_imagen": len(guardadas) if dir_imagenes is not None else con_imagen,
        "imagenes_guardadas": len(guardadas) if dir_imagenes is not None else None,
    }
    return cargables, resumen


def agrupar_fuente1(filas_cargables):
    """Agrupa por código SAP (lo que pide la especificación). Cada grupo
    conserva TODAS las variantes de nombre/número/marca vistas: el catálogo
    de bodega repite un mismo código SAP en varias páginas cuando KFC ofrece
    un empaque genérico como alternativa al original (visto en 6 códigos con
    marca "Eco")."""
    grupos: dict[str, dict] = {}
    for f in filas_cargables:
        codigo = f["codigo_sap"]
        if not codigo:
            continue
        g = grupos.setdefault(codigo, {
            "codigo_sap": codigo, "nombres": [], "numeros": [], "marcas": [], "paginas": [], "tiene_imagen": False,
        })
        if f["nombre_final"] and f["nombre_final"] not in g["nombres"]:
            g["nombres"].append(f["nombre_final"])
        if f["numero_parte"] and f["numero_parte"] not in g["numeros"]:
            g["numeros"].append(f["numero_parte"])
        if f["marca"] and f["marca"] not in g["marcas"]:
            g["marcas"].append(f["marca"])
        if f["pagina"] not in g["paginas"]:
            g["paginas"].append(f["pagina"])
        g["tiene_imagen"] = g["tiene_imagen"] or f["tiene_imagen"]
    return grupos


# --------------------------------------------------------------------------
# Fuente 2 — Excel de stock (enero 2026)
# --------------------------------------------------------------------------

def cargar_fuente2(ruta: Path):
    wb = openpyxl.load_workbook(ruta, data_only=True)
    if HOJA_STOCK not in wb.sheetnames:
        raise SystemExit(f"ABORTADO: la hoja '{HOJA_STOCK}' no existe en {ruta}. Hojas: {wb.sheetnames}")
    ws = wb[HOJA_STOCK]
    COL_MINIMA = 9  # hasta 'Valor Total' (índice 8)
    if ws.max_column < COL_MINIMA:
        raise SystemExit(f"ABORTADO: se esperaban al menos {COL_MINIMA} columnas en '{HOJA_STOCK}' y hay {ws.max_column}")

    filas = []
    for r in ws.iter_rows(min_row=2, max_row=ws.max_row, values_only=True):
        codigo_raw, rotacion, detalle, _imagen, fecha_mov, compra, stock, valor_u, valor_t, marca, cadena, tipo, obs = (
            list(r) + [None] * (13 - len(r))
        )[:13]
        codigo = str(int(codigo_raw)) if isinstance(codigo_raw, float) else norm_txt(codigo_raw)
        filas.append({
            "codigo_sap": codigo,
            "rotacion": norm_txt(rotacion),
            "detalle": norm_txt(detalle),
            "fecha_movimiento": fecha_mov if isinstance(fecha_mov, (dt.date, dt.datetime)) else None,
            "compra": norm_txt(compra),
            "stock": stock,
            "valor_unidad": valor_u,
            "valor_total": valor_t,
            "marca": norm_txt(marca),
            "cadena": norm_txt(cadena),
        })

    leidas = len(filas)
    # Esta fuente no tiene un campo propio de "número de parte": se cruza
    # entera por código SAP contra la fuente 1. La regla "sin nombre y sin
    # número" no tiene equivalente aquí (siempre hay código SAP + detalle,
    # comprobado: 0 filas con cualquiera de los dos vacío) — se deja constancia
    # en vez de aplicar la regla en silencio.
    descartadas = [f for f in filas if not f["codigo_sap"] or not f["detalle"]]
    cargables = [f for f in filas if f["codigo_sap"] and f["detalle"]]
    unicos = {f["codigo_sap"] for f in cargables}

    resumen = {
        "fuente": "2. Stock de bodega ene-2026 (Excel)",
        "ruta": str(ruta),
        "leidas": leidas,
        "descartadas": len(descartadas),
        "motivo_descarte": "sin código SAP o sin detalle" if descartadas else "no aplica: esta fuente no tiene número de parte propio, se cruza entera por código SAP",
        "cargables": len(cargables),
        "unicas": len(unicos),
    }
    indice = {f["codigo_sap"]: f for f in cargables}
    return indice, resumen


# --------------------------------------------------------------------------
# Fuente 3 — Inventario 2023 (modelos compatibles)
# --------------------------------------------------------------------------

def cargar_fuente3(ruta: Path):
    wb = openpyxl.load_workbook(ruta, data_only=True, read_only=True)
    if HOJA_MODELOS not in wb.sheetnames:
        raise SystemExit(f"ABORTADO: la hoja '{HOJA_MODELOS}' no existe en {ruta}. Hojas: {wb.sheetnames}")
    ws = wb[HOJA_MODELOS]
    COL_MINIMA = 6
    if ws.max_column < COL_MINIMA:
        raise SystemExit(f"ABORTADO: se esperaban al menos {COL_MINIMA} columnas en '{HOJA_MODELOS}' y hay {ws.max_column}")

    leidas = 0
    descartadas = 0
    indice: dict[tuple[str, str], list] = {}
    marcas_vistas: set[str] = set()
    for row in ws.iter_rows(min_row=2, values_only=True):
        equipo, marca, modelo, item_ax, nombre_parte, num_parte = (list(row) + [None] * 6)[:6]
        leidas += 1
        nombre_txt = norm_txt(nombre_parte)
        numero_txt = norm_txt(num_parte)
        if not nombre_txt and (not numero_txt or es_marcador_sin_dato(numero_txt)):
            descartadas += 1
            continue
        if not numero_txt or es_marcador_sin_dato(numero_txt):
            continue  # cargable, pero sin número de parte no se puede cruzar (no es un descarte)
        marca_n = norm_marca(marca)
        if marca_n:
            marcas_vistas.add(marca_n)
        clave_completa = clave_numero(num_parte)
        clave_sin_prefijo = quitar_prefijo_partstown(clave_completa)
        for clave in {clave_completa, clave_sin_prefijo}:
            indice.setdefault((marca_n, clave), []).append({
                "equipo": norm_txt(equipo), "modelo": norm_txt(modelo), "item_ax": norm_txt(item_ax),
            })

    cargables = leidas - descartadas
    resumen = {
        "fuente": "3. Inventario 2023 — dónde se usa (Excel)",
        "ruta": str(ruta),
        "leidas": leidas,
        "descartadas": descartadas,
        "motivo_descarte": "sin nombre de parte y sin número de parte",
        "cargables": cargables,
        "unicas": len({k for k in indice if k[1]}),
        "marcas_distintas": len(marcas_vistas),
    }
    return indice, marcas_vistas, resumen


# --------------------------------------------------------------------------
# Cruces
# --------------------------------------------------------------------------

def cruzar(grupos1: dict, indice2: dict, indice3: dict):
    """Para cada grupo de la fuente 1, busca su fila de stock (fuente 2, por
    código SAP) y sus modelos compatibles (fuente 3, por marca + clave del
    número de parte, con y sin el prefijo de Parts Town)."""
    filas_catalogo = []
    con_stock = 0
    con_modelos = 0
    for codigo, g in sorted(grupos1.items()):
        stock = indice2.get(codigo)
        if stock:
            con_stock += 1

        modelos: list[dict] = []
        vistos = set()
        for numero in g["numeros"]:
            if es_marcador_sin_dato(numero):
                continue
            c_completa = clave_numero(numero)
            c_sin_prefijo = quitar_prefijo_partstown(c_completa)
            for marca in (g["marcas"] or [""]):
                marca_n = norm_marca(marca)
                for clave in {c_completa, c_sin_prefijo}:
                    for m in indice3.get((marca_n, clave), []):
                        llave = (m["equipo"], m["modelo"])
                        if llave not in vistos:
                            vistos.add(llave)
                            modelos.append(m)
        if modelos:
            con_modelos += 1

        filas_catalogo.append({
            "codigo_sap": codigo,
            "nombre_final": " / ".join(g["nombres"]),
            "numero_parte": " / ".join(g["numeros"]) if g["numeros"] else "S/N",
            "marca": " / ".join(g["marcas"]),
            "paginas_pdf": ", ".join(str(p) for p in g["paginas"]),
            "imagen": f"{codigo}.jpg" if g["tiene_imagen"] else "",
            "stock_bodega": stock["stock"] if stock else None,
            "stock_fecha": stock["fecha_movimiento"] if stock else None,
            "stock_valor_unidad": stock["valor_unidad"] if stock else None,
            "modelos_compatibles": "; ".join(f"{m['equipo']} {m['modelo']}".strip() for m in modelos[:6]),
            "n_modelos_compatibles": len(modelos),
        })
    return filas_catalogo, con_stock, con_modelos


# --------------------------------------------------------------------------
# Escritura del Excel de propuesta
# --------------------------------------------------------------------------

ENCABEZADO_FILL = PatternFill("solid", fgColor="1F4E78")
ENCABEZADO_FONT = Font(color="FFFFFF", bold=True)


def _hoja_tabla(wb, nombre, encabezados, filas):
    ws = wb.create_sheet(nombre)
    for j, h in enumerate(encabezados, start=1):
        c = ws.cell(row=1, column=j, value=h)
        c.font = ENCABEZADO_FONT
        c.fill = ENCABEZADO_FILL
    for i, fila in enumerate(filas, start=2):
        for j, h in enumerate(encabezados, start=1):
            valor = fila.get(h, "")
            if isinstance(valor, (dt.date, dt.datetime)):
                valor = valor.strftime("%Y-%m-%d") if valor else ""
            ws.cell(row=i, column=j, value=valor)
    ws.freeze_panes = "A2"
    for j, h in enumerate(encabezados, start=1):
        ws.column_dimensions[get_column_letter(j)].width = min(max(12, len(h) + 2), 45)
    return ws


def escribir_excel(resumen1, resumen2, resumen3, filas_catalogo, con_stock, con_modelos,
                    grupos1, marcas3_vistas, ruta_salida: Path):
    ruta_salida.parent.mkdir(parents=True, exist_ok=True)
    wb = openpyxl.Workbook()
    wb.remove(wb.active)

    # --- Resumen ---
    filas_resumen = []
    for r in (resumen1, resumen2, resumen3):
        cuadra = (r["cargables"] + r["descartadas"] == r["leidas"])
        filas_resumen.append({
            "Fuente": r["fuente"], "Ruta": r["ruta"], "Leídas": r["leidas"],
            "Descartadas": r["descartadas"], "Motivo del descarte": r["motivo_descarte"],
            "Cargables": r["cargables"], "Únicas (agrupadas)": r["unicas"],
            "leídas = cargables + descartadas": "OK" if cuadra else "DESCUADRE",
        })
    ws_resumen = _hoja_tabla(wb, "Resumen", list(filas_resumen[0].keys()), filas_resumen)
    fila_nota = len(filas_resumen) + 3
    notas = [
        f"Criterio T2.28.10a — fuente 1: se esperaban 1.611 códigos SAP distintos; salieron {resumen1['unicas']}.",
        f"Criterio T2.28.10a — fuente 1 con imagen: se esperaban ≥ 1.500; salieron {resumen1.get('imagenes_guardadas') or resumen1['con_imagen']}.",
        f"Contradicción con el documento (gana el código, T2.28.10a): la especificación dice 130 repuestos en la fuente 2; la hoja real tiene {resumen2['unicas']} códigos SAP distintos.",
        f"Cruce fuente 1 ↔ fuente 2 (stock) por código SAP: {con_stock} de {resumen1['unicas']} códigos de la fuente 1 tienen fila de stock.",
        f"Cruce fuente 1 ↔ fuente 3 (modelos) por marca+número: {con_modelos} de {resumen1['unicas']} códigos de la fuente 1 encontraron dónde se usan.",
        "El PDF de bodega usa una fuente sin mapa Unicode completo para tildes/ñ: los nombres con '\ufffd' vienen así del documento, no es un error de este script (ver docstring).",
    ]
    for i, nota in enumerate(notas):
        ws_resumen.cell(row=fila_nota + i, column=1, value=nota)

    # --- Catálogo ---
    encabezados_cat = ["codigo_sap", "nombre_final", "numero_parte", "marca", "paginas_pdf", "imagen",
                        "stock_bodega", "stock_fecha", "stock_valor_unidad",
                        "modelos_compatibles", "n_modelos_compatibles"]
    _hoja_tabla(wb, "Catalogo", encabezados_cat, filas_catalogo)

    # --- Sin número de parte ---
    sin_numero = [
        {"codigo_sap": c, "nombre_final": " / ".join(g["nombres"]), "marca": " / ".join(g["marcas"]),
         "paginas_pdf": ", ".join(str(p) for p in g["paginas"])}
        for c, g in sorted(grupos1.items())
        if not g["numeros"] or all(es_marcador_sin_dato(n) for n in g["numeros"])
    ]
    _hoja_tabla(wb, "Sin numero de parte", ["codigo_sap", "nombre_final", "marca", "paginas_pdf"], sin_numero)

    # --- Marcas por unificar ---
    marcas1_info: dict[str, dict] = {}
    for g in grupos1.values():
        for marca in g["marcas"]:
            m = norm_marca(marca)
            info = marcas1_info.setdefault(m, {"variantes": set(), "n_repuestos": 0})
            info["variantes"].add(marca)
            info["n_repuestos"] += 1
    filas_marcas = []
    for m, info in sorted(marcas1_info.items()):
        filas_marcas.append({
            "marca_normalizada": m,
            "variantes_de_grafia_fuente1": " | ".join(sorted(info["variantes"])) if len(info["variantes"]) > 1 else "",
            "n_repuestos_fuente1": info["n_repuestos"],
            "existe_igual_en_fuente3": "Sí" if m in marcas3_vistas else "No — revisar a mano (T2.28.6)",
        })
    _hoja_tabla(wb, "Marcas por unificar", ["marca_normalizada", "variantes_de_grafia_fuente1", "n_repuestos_fuente1", "existe_igual_en_fuente3"], filas_marcas)

    # --- Cruces ---
    filas_cruces = [{
        "cruce": "Fuente 1 (bodega) -> Fuente 2 (stock ene-2026), por código SAP",
        "total_fuente_1": resumen1["unicas"], "con_cruce": con_stock,
        "sin_cruce": resumen1["unicas"] - con_stock,
    }, {
        "cruce": "Fuente 1 (bodega) -> Fuente 3 (modelos 2023), por marca + número de parte",
        "total_fuente_1": resumen1["unicas"], "con_cruce": con_modelos,
        "sin_cruce": resumen1["unicas"] - con_modelos,
    }, {
        "cruce": "Fuente 2 (stock ene-2026) -> Fuente 1 (bodega), por código SAP",
        "total_fuente_1": resumen2["unicas"], "con_cruce": sum(1 for f in filas_catalogo if f["stock_bodega"] is not None),
        "sin_cruce": resumen2["unicas"] - sum(1 for f in filas_catalogo if f["stock_bodega"] is not None),
    }]
    _hoja_tabla(wb, "Cruces", ["cruce", "total_fuente_1", "con_cruce", "sin_cruce"], filas_cruces)

    # --- Vigencia ---
    def _mtime(p: Path):
        try:
            return dt.datetime.fromtimestamp(p.stat().st_mtime)
        except OSError:
            return None

    filas_vigencia = [{
        "fuente": "1. Catálogo de bodega (PDF)", "ruta": str(RUTA_PDF),
        "vigencia_declarada": "enero de 2025 (I-12, fecha del documento)",
        "modificado_en_disco": _mtime(RUTA_PDF),
        "nota": "confirmado por sha256 idéntico entre G:\\ y el espejo D:\\RESPALDOS\\_ORIGEN_DRIVE",
    }, {
        "fuente": "2. Stock de bodega (Excel)", "ruta": str(RUTA_EXCEL_STOCK),
        "vigencia_declarada": "enero de 2026 (según la especificación)",
        "modificado_en_disco": _mtime(RUTA_EXCEL_STOCK),
        "nota": "el mtime del archivo (16-ene-2026) respalda la vigencia declarada",
    }, {
        "fuente": "3. Inventario 2023 — modelos (Excel)", "ruta": str(RUTA_EXCEL_MODELOS),
        "vigencia_declarada": "2023 (etiqueta del negocio, no verificada campo a campo)",
        "modificado_en_disco": _mtime(RUTA_EXCEL_MODELOS),
        "nota": "el archivo se modificó por última vez el 31-ene-2025: la etiqueta '2023' es del nombre, no del mtime — usar solo para 'dónde se usa', nunca para marcar incumplimientos recientes (I-12)",
    }]
    _hoja_tabla(wb, "Vigencia", ["fuente", "ruta", "vigencia_declarada", "modificado_en_disco", "nota"], filas_vigencia)

    wb.save(str(ruta_salida))


# --------------------------------------------------------------------------
def main():
    sys.stdout.reconfigure(encoding="utf-8")
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    ap.add_argument("--analizar", action="store_true", help="corre el análisis de solo lectura (Fase 1, T2.28.10a)")
    ap.add_argument("--sin-imagenes", action="store_true", help="no guarda los JPEG en repuestos_img/ (más rápido)")
    a = ap.parse_args()

    if not a.analizar:
        ap.print_help()
        print("\nNota: --exportar y --cargar (T2.28.10b/10c) no están implementados aquí: "
              "requieren la puerta D2 (decisión de Andrés sobre la fuente elegida y el N a cargar) "
              "y son de otra fase.")
        return 1

    for ruta in (RUTA_PDF, RUTA_EXCEL_STOCK, RUTA_EXCEL_MODELOS):
        if not ruta.exists():
            print(f"ABORTADO: no existe {ruta}")
            return 1

    print("=== Fuente 1: catálogo de bodega (PDF) ===")
    dir_img = None if a.sin_imagenes else DIR_IMAGENES
    filas1, resumen1 = extraer_fuente1(RUTA_PDF, dir_img)
    grupos1 = agrupar_fuente1(filas1)
    print(f"  leídas={resumen1['leidas']} descartadas={resumen1['descartadas']} "
          f"cargables={resumen1['cargables']} códigos SAP distintos={resumen1['unicas']}")
    if a.sin_imagenes:
        print(f"  con imagen (celda con imagen encontrada, no guardada)={resumen1['con_imagen']}")
    else:
        print(f"  imágenes guardadas en {DIR_IMAGENES} = {resumen1['imagenes_guardadas']}")

    ok_codigos = resumen1["unicas"] == ESPERADO_CODIGOS_SAP_FUENTE1
    n_img = resumen1["imagenes_guardadas"] if resumen1["imagenes_guardadas"] is not None else resumen1["con_imagen"]
    ok_imagenes = n_img >= ESPERADO_MIN_IMAGENES
    print(f"  criterio 1.611 códigos SAP distintos: {'OK' if ok_codigos else 'DIFERENCIA'} (real: {resumen1['unicas']})")
    print(f"  criterio ≥1.500 con imagen: {'OK' if ok_imagenes else 'DIFERENCIA'} (real: {n_img})")

    for muestra in ("Hen22455", "Hen29898", "Man000007926"):
        encontrado = [c for c, g in grupos1.items() if muestra in g["numeros"]]
        print(f"  muestra {muestra}: {'código SAP ' + ', '.join(encontrado) if encontrado else 'NO ENCONTRADO'}")

    print("\n=== Fuente 2: stock de bodega ene-2026 (Excel) ===")
    indice2, resumen2 = cargar_fuente2(RUTA_EXCEL_STOCK)
    print(f"  leídas={resumen2['leidas']} descartadas={resumen2['descartadas']} "
          f"cargables={resumen2['cargables']} códigos SAP distintos={resumen2['unicas']}")

    print("\n=== Fuente 3: Inventario 2023 — modelos compatibles (Excel) ===")
    indice3, marcas3, resumen3 = cargar_fuente3(RUTA_EXCEL_MODELOS)
    print(f"  leídas={resumen3['leidas']} descartadas={resumen3['descartadas']} "
          f"cargables={resumen3['cargables']} combos (marca,número) distintos={resumen3['unicas']}")

    print("\n=== Cruces ===")
    filas_catalogo, con_stock, con_modelos = cruzar(grupos1, indice2, indice3)
    print(f"  fuente1 -> fuente2 (stock) por código SAP: {con_stock} de {resumen1['unicas']}")
    print(f"  fuente1 -> fuente3 (modelos) por marca+número: {con_modelos} de {resumen1['unicas']}")

    print(f"\nEscribiendo {RUTA_XLSX_SALIDA} ...")
    escribir_excel(resumen1, resumen2, resumen3, filas_catalogo, con_stock, con_modelos, grupos1, marcas3, RUTA_XLSX_SALIDA)
    print("Listo.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
