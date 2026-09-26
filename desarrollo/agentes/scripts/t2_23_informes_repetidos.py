# -*- coding: utf-8 -*-
"""
T2.23 - Informes enviados dos veces: el Excel con el que la administracion decide.

DE DONDE SALE
Andres, 2026-09-20: "los tecnicos a veces se les envia dos veces el informe o
enviaban dos veces a proposito". Cuando eso pasa, el sistema viejo le da al
segundo envio OTRO numero de OT, asi que quedan dos ordenes distintas para el
mismo aviso de SAP y para la MISMA visita.

Medido ese dia en `_ORIGEN_BUZON`: 5 pares. Los cinco son el mismo tecnico, el
mismo dia y la misma hora de inicio y fin -- o sea, una sola visita facturable
documentada dos veces. Pero no son copias identicas: en tres de ellos el
tecnico CORRIGIO algo al reenviar (las actividades, los equipos o los
repuestos), y en los otros dos solo cambio el numero de fotos.

LA DECISION ES DE LA ADMINISTRACION, NO DEL SISTEMA (I-7, I-11). Quedarse con
el primero archivaria la version que el tecnico quiso corregir; quedarse con el
ultimo asumiria que todo reenvio es una correccion, y eso tampoco consta. Por
eso Andres decidio el 2026-09-20 archivar LOS DOS y que decida una persona con
los dos documentos a la vista. Este Excel es esa vista.

QUE HACE
Busca en el arbol canonico las ordenes que comparten numero de aviso, abre cada
PDF y las subagrupa por VISITA (mismo dia, mismo tecnico, misma hora de inicio
y fin). Dos ordenes de la misma visita son el mismo trabajo documentado dos
veces, y eso es lo que hay que decidir. Dos ordenes de visitas distintas son el
tecnico que volvio, que es un caso normal y no hay que tocar.

SE SUBAGRUPA POR VISITA, NO SE MIRA EL AVISO ENTERO. Las dos cosas son
independientes: el aviso 10347141 tiene CUATRO ordenes porque el tecnico
volvio varias veces, y dentro de esas cuatro hay un par -la 2507 y la 2508-
que es el mismo trabajo enviado dos veces. La primera version preguntaba si
TODO el aviso era una sola visita, y por eso ese par y el del aviso 10354784
quedaban invisibles justo en los avisos con mas movimiento.

SOLO LECTURA. No mueve, no borra y no escribe en la base. Lo unico que produce
es un Excel a nombre nuevo en SALIDAS IA (I-4).

USO
    .venv/Scripts/python.exe scripts/t2_23_informes_repetidos.py
"""
from __future__ import annotations

import sys
from collections import defaultdict
from datetime import date, datetime
from pathlib import Path

import openpyxl
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.utils import get_column_letter

sys.path.insert(0, str(Path(__file__).parent))
from comun import RESPALDOS, SALIDAS  # noqa: E402
from t1_7_extractor_pdf import extraer_pdf  # noqa: E402

CANONICO = RESPALDOS / "ORDENES DE TRABAJO"
SALIDA = SALIDAS / "CALIDAD"

# Los campos que definen UNA VISITA. Si los cuatro coinciden es el mismo
# trabajo: el tecnico no puede estar en dos sitios el mismo dia a la misma hora.
# El aviso y el local NO entran aqui: son la clave por la que se agrupa.
VISITA = ("fecha_atencion", "tecnico_nombre", "hora_inicio", "hora_fin")
# Lo que puede haber cambiado entre un envio y el otro. Es lo que la persona
# tiene que mirar para decidir cual vale.
CONTENIDO = ("actividades", "repuestos", "equipos", "observaciones",
             "estado_equipo", "estado_ot", "fotos_cantidad")


def texto(v) -> str:
    return "" if v is None else str(v)


def main() -> int:
    print("Leyendo el arbol canonico...")
    por_aviso = defaultdict(list)
    for p in CANONICO.rglob("*.pdf"):
        rel = p.relative_to(CANONICO)
        if any(parte.startswith("_") for parte in rel.parts[:-1]):
            continue
        partes = p.stem.split("-")
        # OT-{corr}-{local}-{aviso}-...  El aviso es el unico segmento de 8
        # digitos; buscarlo por posicion falla con los preventivos, que llevan
        # el -D{n} de por medio.
        aviso = next((x for x in partes if x.isdigit() and len(x) == 8), None)
        if aviso:
            por_aviso[aviso].append(p)

    grupos = {a: ps for a, ps in por_aviso.items() if len(ps) > 1}
    print(f"  {len(por_aviso)} avisos distintos, {len(grupos)} con mas de una orden\n")
    if not grupos:
        print("No hay ningun aviso con dos ordenes. Nada que decidir.")
        return 0

    filas, una_visita, dos_visitas = [], 0, 0
    for aviso, rutas in sorted(grupos.items()):
        datos = []
        for p in sorted(rutas):
            try:
                datos.append((p, extraer_pdf(str(p))))
            except Exception as e:
                datos.append((p, {"error": f"NO_LEGIBLE: {e}"}))

        # SE AGRUPA POR (AVISO, VISITA), NO SOLO POR AVISO.
        #
        # La primera version preguntaba si TODOS los documentos del aviso eran
        # la misma visita, y con eso escondia los casos reales: el aviso
        # 10347141 tiene CUATRO ordenes (2180, 2472, 2507 y 2508) porque el
        # tecnico volvio varias veces, y dentro de esas cuatro, la 2507 y la
        # 2508 son el mismo trabajo enviado dos veces. Como el grupo entero no
        # era "una sola visita", el par quedaba invisible. Igual el 10354784,
        # con tres ordenes. Que un aviso tenga varias visitas y que una de esas
        # visitas se haya documentado dos veces son cosas independientes.
        por_visita = defaultdict(list)
        for p, d in datos:
            if d.get("error"):
                por_visita[("ILEGIBLE", p.name)].append((p, d))
            else:
                por_visita[tuple(texto(d.get(k)) for k in VISITA)].append((p, d))

        hubo_repetida = False
        for clave, docs in por_visita.items():
            if len(docs) > 1 and clave[0] != "ILEGIBLE":
                hubo_repetida = True
                veredicto = f"UNA SOLA VISITA documentada {len(docs)} veces"
                cambios = sorted({k for k in CONTENIDO
                                  if len({texto(d.get(k)) for _, d in docs}) > 1})
                detalle = ("cambio: " + ", ".join(cambios)) if cambios else "identicas"
            else:
                veredicto = "VISITA UNICA de este aviso"
                detalle = ("el tecnico volvio al mismo aviso otro dia: caso normal"
                           if len(por_visita) > 1 else "sin repeticion")
            for p, d in docs:
                filas.append({
                    "aviso": aviso, "veredicto": veredicto, "detalle": detalle,
                    "orden": p.stem, "ruta": str(p.relative_to(CANONICO)),
                    "fecha": texto(d.get("fecha_atencion")),
                    "tecnico": texto(d.get("tecnico_nombre")),
                    "horario": f"{texto(d.get('hora_inicio'))}-{texto(d.get('hora_fin'))}".strip("-"),
                    "actividades": texto(d.get("actividades"))[:300],
                    "repuestos": texto(d.get("repuestos"))[:200],
                    "fotos": texto(d.get("fotos_cantidad")),
                    "estado_ot": texto(d.get("estado_ot")),
                })
        if hubo_repetida:
            una_visita += 1
        else:
            dos_visitas += 1

    # --- Compuerta de cuadre (I-10) -------------------------------------------
    esperado = sum(len(v) for v in grupos.values())
    if len(filas) != esperado:
        sys.exit(f"ABORTADO: el cuadre no da ({len(filas)} filas para {esperado} documentos)")

    SALIDA.mkdir(parents=True, exist_ok=True)
    destino = SALIDA / "INFORMES ENVIADOS DOS VECES (generado agente).xlsx"
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "A DECIDIR"
    enc = ["AVISO SAP", "QUE ES", "QUE CAMBIO", "ORDEN", "FECHA", "TECNICO", "HORARIO",
           "ACTIVIDADES", "REPUESTOS", "FOTOS", "ESTADO OT", "CUAL VALE (decide admin)"]
    ws.append(enc)
    for i in range(1, len(enc) + 1):
        c = ws.cell(row=1, column=i)
        c.font = Font(bold=True, color="FFFFFF")
        c.fill = PatternFill("solid", fgColor="1F4E79")
        c.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)

    amarillo = PatternFill("solid", fgColor="FFF2CC")   # hay que decidir
    verde = PatternFill("solid", fgColor="E2EFDA")      # no hay nada que hacer
    for f in filas:
        decide = f["veredicto"].startswith("UNA SOLA")
        ws.append([f["aviso"], f["veredicto"], f["detalle"], f["orden"], f["fecha"],
                   f["tecnico"], f["horario"], f["actividades"], f["repuestos"],
                   f["fotos"], f["estado_ot"], ""])
        for col in range(1, len(enc) + 1):
            ws.cell(row=ws.max_row, column=col).fill = amarillo if decide else verde

    for i, ancho in enumerate([12, 34, 30, 30, 12, 18, 13, 52, 34, 7, 11, 24], 1):
        ws.column_dimensions[get_column_letter(i)].width = ancho
    ws.freeze_panes = "A2"
    ws.auto_filter.ref = ws.dimensions

    ws2 = wb.create_sheet("DE DONDE SALE")
    for fila in [
        ["Generado por", "t2_23_informes_repetidos.py"],
        ["Fecha", str(date.today())],
        ["Que muestra", "Avisos de SAP con mas de una orden de trabajo archivada."],
        ["Amarillo", "UNA SOLA VISITA documentada dos veces: el informe se envio dos "
                     "veces y el sistema le dio otro numero de OT. Hay que decidir cual vale."],
        ["Verde", "DOS VISITAS en fechas distintas: el tecnico volvio al mismo aviso. "
                  "Es un caso normal y no hay que tocar nada."],
        ["Por que no lo decide el sistema", "En varios casos el tecnico CORRIGIO el informe al "
                                            "reenviarlo. Quedarse con el primero archivaria la "
                                            "version que el quiso corregir, y quedarse con el "
                                            "ultimo supondria que todo reenvio es una correccion. "
                                            "Ninguna de las dos cosas consta en el documento."],
        ["Los dos estan archivados", "Decision de Andres del 2026-09-20. No se borro nada: los dos "
                                     "PDF estan en el arbol canonico y en la base."],
    ]:
        ws2.append(fila)
    ws2.column_dimensions["A"].width = 26
    ws2.column_dimensions["B"].width = 95
    for i in range(1, ws2.max_row + 1):
        ws2.cell(row=i, column=1).font = Font(bold=True)
        ws2.cell(row=i, column=2).alignment = Alignment(wrap_text=True, vertical="top")

    try:
        wb.save(destino)
    except PermissionError:
        sys.exit(f"ABORTADO: {destino.name} esta en uso en Excel. Cierralo y repite.")

    a_decidir = sum(1 for f in filas if f["veredicto"].startswith("UNA SOLA"))
    print(f"Avisos con algun informe enviado dos veces  : {una_visita}"
          "   <- la administracion decide cual vale")
    print(f"  documentos involucrados en esas repeticiones: {a_decidir}")
    print(f"Avisos sin ninguna repeticion              : {dos_visitas}"
          "   <- caso normal, no hay que tocar nada")
    print(f"CUADRE: {len(filas)} documentos en {len(grupos)} grupos (esperado {esperado})")
    print(f"\nEscrito: {destino}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
