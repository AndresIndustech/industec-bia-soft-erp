"""
T2.27.3 — Tablero semanal de gerencia: cómo nos ve KFC, y qué hacer antes del jueves.

POR QUÉ
KFC es el 95 % de los ingresos de INDUSTEC y la mide cada semana con UN número:
de los correctivos que el proveedor tenía abiertos en SAP el lunes, qué parte ya
no está abierta el viernes. En la semana 35 INDUSTEC cerró el 18,87 % (IN HOUSE
11,11 %, Megaservicios 58,46 %); en la 37, 47,62 %, «el mayor rezago». Ese número
se discute en la reunión de los jueves y termina en el CONTACT REPORT.

La definición se reprodujo EXACTA con los archivos de KFC de la semana 35, para
los cinco proveedores: abiertos del lunes (por el proveedor del lunes) contra los
avisos del lunes que siguen abiertos el viernes (por el proveedor del viernes: si
KFC le reasigna a INDUSTEC un ND a mitad de semana, le cuenta en contra).

Con eso este tablero le dice a la gerencia, con los datos del lunes:
  - INDICADOR KFC: la serie de INDUSTEC contra los demás proveedores, semana a semana;
  - POR CERRAR EN SAP: los avisos que INDUSTEC ya cerró con su orden y que SAP
    sigue contando abiertos. Cada uno que se cierre en SAP (la transacción IW22, a
    la que INDUSTEC tiene acceso desde el 31-07) sube el indicador de la semana;
  - SIN ORDEN: avisos asignados a INDUSTEC en SAP sin ninguna orden nuestra;
  - TIEMPOS: de la notificación a la primera visita y a la orden de cierre;
  - PENDIENTES: antigüedad y responsable de lo que espera repuesto;
  - REINCIDENCIA: equipos y locales que vuelven a fallar;
  - TÉCNICOS: la carga de las últimas cuatro semanas.

Uso:
    .venv/Scripts/python.exe scripts/t2_27_tablero_gerencia.py                     # con lo último del correo
    .venv/Scripts/python.exe scripts/t2_27_tablero_gerencia.py --sap <lunes>.xlsx --historial <carpeta con los de KFC>
"""
from __future__ import annotations

import argparse
import collections
import datetime as dt
import decimal
import re
import statistics
import sys
from pathlib import Path

import openpyxl
from openpyxl.chart import BarChart, LineChart, Reference
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.utils import get_column_letter

sys.path.insert(0, str(Path(__file__).resolve().parent))
import t2_27_fuentes as F  # noqa: E402

PROVEEDORES = ["INDUSTEC", "IN HOUSE", "MEGASERVICIOS", "SERVICENTURIOSA", "#N/A"]
AZUL, BLANCO = "FF17365D", "FFFFFFFF"


def abiertos(sap: dict, proveedor: str) -> set:
    return {a for a, r in sap.items() if r["proveedor"] == proveedor and r["descripcion"] == "Mant. Correctivo"
            and r["estatus_a"] in ("ABIERTO", "TRATAMIENTO")}


def indicador_kfc(lunes: dict, viernes: dict) -> dict:
    """La métrica de KFC, reproducida exacta en la semana 35 para los cinco proveedores."""
    universo = set(lunes)
    out = {}
    for p in PROVEEDORES:
        antes = abiertos(lunes, p)
        despues = {a for a in abiertos(viernes, p) if a in universo}
        out[p] = (len(antes), len(despues), (1 - len(despues) / len(antes)) if antes else None)
    return out


def archivos_kfc(carpeta: Path) -> dict:
    """{semana: {'lunes': ruta, 'viernes': ruta}} de los Excel de KFC en una carpeta.

    El del lunes es «REPORTE ... SEMANA NN - MANT(ENIMIENTO) CORRECTIVO»; el del viernes, el
    «ANALISIS» (su FILTRO es la foto con la que KFC calcula), o si no está, el «ACTUALIZADO».
    """
    sem = collections.defaultdict(dict)
    for p in sorted(carpeta.glob("*.xlsx")):
        n = F.semana_del_reporte_kfc(p)
        if not n or "REPORTE" not in p.name.upper():
            continue
        u = p.name.upper()
        if "ANALISIS" in u:
            sem[n]["viernes"] = p
        elif "ACTUALIZADO" in u:
            sem[n].setdefault("viernes", p)
        elif "CORRECTIVO" in u and "ORDENES ND" not in u and "RESPUESTA" not in u and "ORIGINAL" not in u:
            sem[n].setdefault("lunes", p)
    return sem


def percentil(v: list, q: float):
    if not v:
        return None
    v = sorted(v)
    k = (len(v) - 1) * q
    f, c = int(k), min(int(k) + 1, len(v) - 1)
    return round(v[f] + (v[c] - v[f]) * (k - f), 1)


# ---------------------------------------------------------------------------

def calcular(sap_ruta: Path, historial: Path | None, status_ruta: Path | None, corte: dt.date) -> dict:
    sap = F.leer_sap_semanal(sap_ruta)
    semana = F.semana_del_reporte_kfc(sap_ruta)
    cnx = F.conectar()
    zonas = F.zonas_de_locales(cnx)
    ots = F.ordenes_por_aviso(cnx, corte)
    zona = lambda loc: F.zona_de(loc, zonas) or "SIN ZONA"

    # ---- Indicador KFC: serie por semana ------------------------------------
    serie = []
    if historial:
        for n, par in sorted(archivos_kfc(historial).items()):
            if "lunes" not in par:
                continue
            lun = F.leer_sap_semanal(par["lunes"])
            fila = {"semana": n, "lunes": {p: len(abiertos(lun, p)) for p in PROVEEDORES}, "kfc": None}
            if "viernes" in par:
                fila["kfc"] = indicador_kfc(lun, F.leer_sap_semanal(par["viernes"]))
            serie.append(fila)

    # ---- La semana actual: qué hay abierto de INDUSTEC en SAP y qué dice nuestra base ----
    mias = sorted(abiertos(sap, "INDUSTEC"))
    por_cerrar, sin_orden, con_visita = [], [], []
    for a in mias:
        r = sap[a]
        o = ots.get(a, [])
        cierre = [x for x in o if x["estado_ot"] == "CERRADA"]
        base = [a, r["local"], zona(r["local"]), r["fecha_notificacion"], r["estatus_a"], r.get("estatus_b"), r.get("responsable"),
                (r.get("denominacion") or "")[:40]]
        if cierre:
            c = cierre[-1]
            por_cerrar.append(base + [c["id_industec"], c["fecha_atencion"], (corte - c["fecha_atencion"]).days])
        elif not o:
            dias = (corte - r["fecha_notificacion"]).days if r["fecha_notificacion"] else None
            sin_orden.append(base + [dias, (r.get("circunstancia") or "")[:160]])
        else:
            con_visita.append(a)
    proyeccion = len(por_cerrar) / len(mias) if mias else None

    # ---- Tiempos: notificación -> primera orden y -> orden de cierre (2026, correctivos de INDUSTEC) ----
    tiempos = collections.defaultdict(lambda: {"n": 0, "resp": [], "sol": [], "sap": []})
    for a, r in sap.items():
        if r["proveedor"] != "INDUSTEC" or r["descripcion"] != "Mant. Correctivo" or not r["fecha_notificacion"]:
            continue
        clave = (zona(r["local"]), r["fecha_notificacion"].strftime("%Y-%m"))
        t = tiempos[clave]
        t["n"] += 1
        o = ots.get(a, [])
        if o:
            t["resp"].append((o[0]["fecha_atencion"] - r["fecha_notificacion"]).days)
            cierre = [x for x in o if x["estado_ot"] == "CERRADA"]
            if cierre:
                t["sol"].append((cierre[0]["fecha_atencion"] - r["fecha_notificacion"]).days)
        if r.get("cierre_tecnico"):
            t["sap"].append((r["cierre_tecnico"] - r["fecha_notificacion"]).days)

    # ---- Reincidencia: 90 días, por equipo (denominación de SAP) y por local ----
    hace90 = corte - dt.timedelta(days=90)
    hace180 = corte - dt.timedelta(days=180)
    por_equipo, por_local, por_local_prev = collections.defaultdict(list), collections.Counter(), collections.Counter()
    for a, r in sap.items():
        if r["proveedor"] != "INDUSTEC" or r["descripcion"] != "Mant. Correctivo" or not r["fecha_notificacion"]:
            continue
        f = r["fecha_notificacion"]
        if f >= hace90:
            por_local[r["local"]] += 1
            # Por el número de equipo de SAP, no por la denominación: en un local con tres
            # freidoras las tres se llaman «FREIDORA» y agruparlas inventaba reincidencias.
            eq = str(r.get("equipo_sap") or "").strip()
            if eq and eq not in ("0", "None"):
                por_equipo[(r["local"], eq, (r.get("denominacion") or "").strip())].append(f)
        elif f >= hace180:
            por_local_prev[r["local"]] += 1
    reincidentes = sorted(((loc, f"{den} ({eq})", len(fs), min(fs), max(fs)) for (loc, eq, den), fs in por_equipo.items() if len(fs) >= 3),
                          key=lambda x: (-x[2], x[0]))

    # ---- Pendientes (STATUS del martes) ----
    pendientes = []
    if status_ruta:
        ws = openpyxl.load_workbook(status_ruta, data_only=True)["ORDENES"]
        for r in ws.iter_rows(min_row=2, max_col=13, values_only=True):
            if r[1] in (None, ""):
                continue
            ini = r[3].date() if isinstance(r[3], dt.datetime) else r[3]
            pendientes.append({"zona": r[0], "aviso": r[1], "local": r[2], "equipo": r[4], "resp": str(r[10] or "SIN RESPONSABLE").strip(),
                               "estado": r[11], "dias": (corte - ini).days if isinstance(ini, dt.date) else None})

    # ---- Técnicos: últimas 4 semanas ----
    cur = cnx.cursor()
    cur.execute("""SELECT tecnico_nombre, zona, COUNT(*), SUM(modulo='CORRECTIVO' AND estado_ot='ABIERTA'),
                          SUM(modulo='CORRECTIVO' AND estado_ot='CERRADA'), SUM(modulo='PREVENTIVO'),
                          COUNT(DISTINCT fecha_atencion), COUNT(DISTINCT local_codigo)
                     FROM ots WHERE en_cuarentena = 0 AND correlativo < 90000 AND fecha_atencion BETWEEN %s AND %s
                    GROUP BY tecnico_nombre, zona ORDER BY zona, COUNT(*) DESC""", (corte - dt.timedelta(days=28), corte))
    tecnicos = [list(x) for x in cur.fetchall()]
    cnx.close()
    return dict(semana=semana, sap_ruta=sap_ruta, corte=corte, serie=serie, mias=mias, por_cerrar=por_cerrar,
                sin_orden=sin_orden, con_visita=con_visita, proyeccion=proyeccion, tiempos=tiempos,
                reincidentes=reincidentes, por_local=por_local, por_local_prev=por_local_prev,
                pendientes=pendientes, tecnicos=tecnicos, status_ruta=status_ruta)


# ---------------------------------------------------------------------------

def _encabezado(ws, fila: int, valores: list, anchos: list | None = None):
    for j, v in enumerate(valores, start=1):
        c = ws.cell(row=fila, column=j, value=v)
        c.font = Font(bold=True, color=BLANCO)
        c.fill = PatternFill("solid", start_color="FF2F75B5")
        c.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
    if anchos:
        for j, w in enumerate(anchos, start=1):
            ws.column_dimensions[get_column_letter(j)].width = w


def _titulo(ws, texto: str, sub: str = ""):
    ws["A1"] = texto
    ws["A1"].font = Font(bold=True, size=14, color=AZUL)
    if sub:
        ws["A2"] = sub
        ws["A2"].font = Font(italic=True, color="FF595959")


def escribir(res: dict, destino: Path) -> Path:
    wb = openpyxl.Workbook()
    fecha = "%d/%m/%Y"

    # ---------------- PORTADA
    ws = wb.active
    ws.title = "PORTADA"
    _titulo(ws, f"Tablero de gerencia · semana {res['semana']} de KFC",
            f"Excel de KFC: {res['sap_ruta'].name} · órdenes de INDUSTEC hasta el {res['corte']:{fecha}} · generado por el agente")
    ult = next((s for s in reversed(res["serie"]) if s["kfc"]), None)
    lineas = [
        ("Correctivos de INDUSTEC abiertos en SAP (lunes)", len(res["mias"])),
        ("   ya cerrados por INDUSTEC con su orden, abiertos en SAP", len(res["por_cerrar"])),
        ("   con visita pero sin orden de cierre", len(res["con_visita"])),
        ("   sin ninguna orden de INDUSTEC", len(res["sin_orden"])),
        ("Indicador KFC si esta semana se cierran en SAP los que ya tienen orden de cierre",
         f"{res['proyeccion'] * 100:.1f} %" if res["proyeccion"] is not None else "sin dato"),
    ]
    if ult:
        k = ult["kfc"]
        lineas.append((f"Indicador KFC de la semana {ult['semana']} (reproducido de sus archivos): INDUSTEC",
                       f"{k['INDUSTEC'][2] * 100:.2f} %" if k["INDUSTEC"][2] is not None else "sin dato"))
        for p in PROVEEDORES[1:]:
            if k[p][2] is not None:
                lineas.append((f"      {p}", f"{k[p][2] * 100:.2f} %"))
    if res["pendientes"]:
        desh = [p for p in res["pendientes"] if str(p["estado"]).upper() == "DESHABILITADO"]
        lineas += [("Pendientes del STATUS del martes", len(res["pendientes"])),
                   ("   equipos DESHABILITADOS", len(desh)),
                   ("   deshabilitados hace más de 7 días", sum(1 for p in desh if (p["dias"] or 0) > 7))]
    lineas.append(("Equipos con 3 o más correctivos en 90 días", len(res["reincidentes"])))
    for i, (t, v) in enumerate(lineas, start=4):
        ws.cell(row=i, column=1, value=t)
        c = ws.cell(row=i, column=2, value=v)
        c.font = Font(bold=True, size=12)
        c.alignment = Alignment(horizontal="right")
    n = 4 + len(lineas) + 1
    ws.cell(row=n, column=1, value="Qué hacer antes de la reunión del jueves").font = Font(bold=True, color=AZUL, size=12)
    acciones = [
        f"1. Cerrar en SAP (IW22) los {len(res['por_cerrar'])} avisos de la hoja POR CERRAR EN SAP: INDUSTEC ya emitió la orden de cierre.",
        f"2. Revisar los {len(res['sin_orden'])} avisos SIN ORDEN: o falta la visita, o no son de INDUSTEC y hay que pedir la reasignación.",
        "3. Mover los deshabilitados más antiguos (hoja PENDIENTES): es lo que KFC mira primero.",
    ]
    for i, t in enumerate(acciones, start=n + 1):
        ws.cell(row=i, column=1, value=t)
    ws.column_dimensions["A"].width = 92
    ws.column_dimensions["B"].width = 16

    # ---------------- INDICADOR KFC
    ws = wb.create_sheet("INDICADOR KFC")
    _titulo(ws, "El indicador con que KFC mide a cada proveedor",
            "% cerrado = 1 − (avisos del lunes que siguen abiertos el viernes ÷ correctivos abiertos del lunes). Reproducido exacto en la semana 35.")
    _encabezado(ws, 4, ["Semana"] + [f"Abiertos lunes {p}" for p in PROVEEDORES] + [f"% cerrado {p}" for p in PROVEEDORES],
                [10] + [14] * 10)
    for i, s in enumerate(res["serie"], start=5):
        ws.cell(row=i, column=1, value=s["semana"])
        for j, p in enumerate(PROVEEDORES):
            ws.cell(row=i, column=2 + j, value=s["lunes"][p])
            v = s["kfc"][p][2] if s["kfc"] else None
            c = ws.cell(row=i, column=7 + j, value=v)
            c.number_format = "0.0%"
    if len(res["serie"]) >= 2:
        fin = 4 + len(res["serie"])
        g = LineChart()
        g.title = "Correctivos abiertos el lunes, por proveedor"
        g.y_axis.title = "avisos"
        g.x_axis.title = "semana"
        g.add_data(Reference(ws, min_col=2, max_col=6, min_row=4, max_row=fin), titles_from_data=True)
        g.set_categories(Reference(ws, min_col=1, min_row=5, max_row=fin))
        g.height, g.width = 8, 18
        ws.add_chart(g, f"A{fin + 3}")

    # ---------------- POR CERRAR EN SAP
    ws = wb.create_sheet("POR CERRAR EN SAP")
    _titulo(ws, f"{len(res['por_cerrar'])} avisos que INDUSTEC ya cerró y SAP sigue contando abiertos",
            "Cada uno que se cierre en SAP antes del viernes sube el indicador de la semana.")
    _encabezado(ws, 4, ["Aviso", "Local", "Zona", "Notificación", "ESTATUS A", "ESTATUS B", "Responsable SAP", "Equipo",
                        "Orden de cierre", "Fecha cierre", "Días desde el cierre"], [11, 8, 8, 12, 13, 18, 15, 34, 30, 12, 10])
    for i, f in enumerate(sorted(res["por_cerrar"], key=lambda x: -x[-1]), start=5):
        for j, v in enumerate(f, start=1):
            c = ws.cell(row=i, column=j, value=v)
            if isinstance(v, dt.date):
                c.number_format = "dd/mm/yyyy"
    ws.freeze_panes = "A5"

    # ---------------- SIN ORDEN
    ws = wb.create_sheet("SIN ORDEN")
    _titulo(ws, f"{len(res['sin_orden'])} avisos de INDUSTEC en SAP sin ninguna orden nuestra",
            "Si no llegó el correo del aviso, no hay visita. Si no es de INDUSTEC, pedir la reasignación antes de que cuente en contra.")
    _encabezado(ws, 4, ["Aviso", "Local", "Zona", "Notificación", "ESTATUS A", "ESTATUS B", "Responsable SAP", "Equipo",
                        "Días", "Lo que pidió el local"], [11, 8, 8, 12, 13, 18, 15, 34, 7, 90])
    for i, f in enumerate(sorted(res["sin_orden"], key=lambda x: -(x[-2] or 0)), start=5):
        for j, v in enumerate(f, start=1):
            c = ws.cell(row=i, column=j, value=v)
            if isinstance(v, dt.date):
                c.number_format = "dd/mm/yyyy"
    ws.freeze_panes = "A5"

    # ---------------- TIEMPOS
    ws = wb.create_sheet("TIEMPOS")
    _titulo(ws, "Tiempos de atención de los correctivos de INDUSTEC (2026)",
            "Días calendario desde la notificación en SAP. «1.ª visita»: la primera orden de INDUSTEC. «Cierre»: la primera orden CERRADA.")
    _encabezado(ws, 4, ["Zona", "Mes", "Avisos", "Con visita", "1.ª visita: mediana", "1.ª visita: p90", "% visita ≤1 día",
                        "% visita ≤2 días", "Cierre INDUSTEC: mediana", "Cierre SAP: mediana"], [9, 9, 8, 10, 12, 11, 11, 11, 13, 12])
    i = 5
    for (z, m), t in sorted(res["tiempos"].items()):
        resp = t["resp"]
        valores = [z, m, t["n"], len(resp), statistics.median(resp) if resp else None, percentil(resp, 0.9),
                   (sum(1 for d in resp if d <= 1) / len(resp)) if resp else None,
                   (sum(1 for d in resp if d <= 2) / len(resp)) if resp else None,
                   statistics.median(t["sol"]) if t["sol"] else None, statistics.median(t["sap"]) if t["sap"] else None]
        for j, v in enumerate(valores, start=1):
            c = ws.cell(row=i, column=j, value=v)
            if j in (7, 8):
                c.number_format = "0%"
        i += 1
    ws.freeze_panes = "A5"

    # ---------------- PENDIENTES
    ws = wb.create_sheet("PENDIENTES")
    _titulo(ws, "Lo que espera repuesto: antigüedad y responsable",
            f"Del STATUS del martes: {res['status_ruta'].name if res['status_ruta'] else 'no se dio'}")
    tramos = [("0–7 días", 0, 7), ("8–15", 8, 15), ("16–30", 16, 30), ("más de 30", 31, 10 ** 6)]
    _encabezado(ws, 4, ["Responsable"] + [t[0] for t in tramos] + ["Total", "Deshabilitados"], [44, 10, 10, 10, 12, 8, 14])
    por_resp = collections.defaultdict(list)
    for p in res["pendientes"]:
        clave = re.split(r"[/(]", p["resp"])[0].strip().upper() or "SIN RESPONSABLE"
        por_resp[clave].append(p)
    i = 5
    for resp, lista in sorted(por_resp.items(), key=lambda x: -len(x[1])):
        ws.cell(row=i, column=1, value=resp)
        for j, (_, a, b) in enumerate(tramos, start=2):
            ws.cell(row=i, column=j, value=sum(1 for p in lista if p["dias"] is not None and a <= p["dias"] <= b))
        ws.cell(row=i, column=6, value=len(lista))
        ws.cell(row=i, column=7, value=sum(1 for p in lista if str(p["estado"]).upper() == "DESHABILITADO"))
        i += 1
    i += 2
    ws.cell(row=i, column=1, value="Equipos DESHABILITADOS, del más antiguo al más nuevo").font = Font(bold=True, color=AZUL)
    _encabezado(ws, i + 1, ["Aviso", "Zona", "Local", "Equipo", "Responsable", "Días"])
    for k, p in enumerate(sorted((p for p in res["pendientes"] if str(p["estado"]).upper() == "DESHABILITADO"),
                                 key=lambda p: -(p["dias"] or 0)), start=i + 2):
        for j, v in enumerate([p["aviso"], p["zona"], p["local"], p["equipo"], p["resp"], p["dias"]], start=1):
            ws.cell(row=k, column=j, value=v)

    # ---------------- REINCIDENCIA
    ws = wb.create_sheet("REINCIDENCIA")
    _titulo(ws, "Equipos que vuelven a fallar (3 o más correctivos en 90 días)",
            "Por la denominación del equipo en SAP. Un equipo que repite casi nunca es mala suerte: es causa raíz, repuesto de mala calidad o uso.")
    _encabezado(ws, 4, ["Local", "Equipo (SAP)", "Correctivos 90 días", "Primero", "Último"], [9, 46, 12, 12, 12])
    for i, f in enumerate(res["reincidentes"], start=5):
        for j, v in enumerate(f, start=1):
            c = ws.cell(row=i, column=j, value=v)
            if isinstance(v, dt.date):
                c.number_format = "dd/mm/yyyy"
    base = 6 + len(res["reincidentes"])
    ws.cell(row=base, column=1, value="Locales con más correctivos (90 días) contra los 90 días anteriores").font = Font(bold=True, color=AZUL)
    _encabezado(ws, base + 1, ["Local", "Zona", "Últimos 90 días", "90 días anteriores", "Cambio"])
    for i, (loc, n) in enumerate(res["por_local"].most_common(20), start=base + 2):
        prev = res["por_local_prev"].get(loc, 0)
        for j, v in enumerate([loc, "", n, prev, n - prev], start=1):
            ws.cell(row=i, column=j, value=v)

    # ---------------- TÉCNICOS
    ws = wb.create_sheet("TECNICOS")
    _titulo(ws, "Carga por técnico, últimas 4 semanas",
            "Órdenes emitidas por técnico (según el nombre escrito en la orden). No mide calidad: una emergencia compleja cuenta igual que una visita simple.")
    _encabezado(ws, 4, ["Técnico", "Zona", "Órdenes", "Correctivo (evaluación abierta)", "Correctivo cerrado", "Preventivo",
                        "Días con órdenes", "Locales distintos"], [30, 8, 9, 16, 12, 11, 11, 11])
    for i, f in enumerate(res["tecnicos"], start=5):
        for j, v in enumerate(f, start=1):
            ws.cell(row=i, column=j, value=int(v) if isinstance(v, (int, decimal.Decimal)) else v)
    ws.freeze_panes = "A5"

    destino.parent.mkdir(parents=True, exist_ok=True)
    wb.save(destino)
    return destino


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    ap.add_argument("--sap", help="Excel del lunes de KFC; por omisión, el último de Recibidos")
    ap.add_argument("--historial", help="carpeta con los Excel de KFC de semanas anteriores (lunes y ANALISIS/ACTUALIZADO)")
    ap.add_argument("--status", help="STATUS_PENDIENTES más reciente; por omisión, el último de Enviados")
    ap.add_argument("--corte", help="órdenes hasta esta fecha (AAAA-MM-DD); por omisión, hoy")
    a = ap.parse_args()
    corte = dt.date.fromisoformat(a.corte) if a.corte else dt.date.today()
    sap = Path(a.sap) if a.sap else (F.bajar_ultimo_adjunto("INBOX", r"REPORTE.*SEMANA.*CORRECTIVO.*\.xlsx$", dias=10, antes_de=corte + dt.timedelta(days=1)) or [None])[0]
    if not sap:
        raise SystemExit("No encontré el Excel de KFC de esta semana. Pásalo con --sap.")
    status = Path(a.status) if a.status else (F.bajar_ultimo_adjunto("Sent", r"STATUS_PENDIENTES.*\.xlsx$", dias=10, antes_de=corte + dt.timedelta(days=1)) or [None])[0]
    historial = Path(a.historial) if a.historial else F.ENTRADAS
    res = calcular(sap, historial, status, corte)
    destino = F.SALIDAS / f"{corte:%Y-%m-%d}" / f"TABLERO GERENCIA SEMANA {res['semana']} (generado agente).xlsx"
    escribir(res, destino)
    print(f"Tablero de la semana {res['semana']}: {destino}")
    print(f"  INDUSTEC abiertos en SAP: {len(res['mias'])} · ya cerrados por INDUSTEC: {len(res['por_cerrar'])} · "
          f"con visita sin cierre: {len(res['con_visita'])} · sin orden: {len(res['sin_orden'])}")
    print(f"  Proyección del indicador si se cierran en SAP: "
          f"{res['proyeccion'] * 100:.1f} %" if res["proyeccion"] is not None else "  sin proyección")
    for s in res["serie"]:
        k = s["kfc"]
        print(f"  semana {s['semana']}: abiertos lunes INDUSTEC {s['lunes']['INDUSTEC']}"
              + (f" · indicador KFC INDUSTEC {k['INDUSTEC'][2] * 100:.2f} %" if k and k['INDUSTEC'][2] is not None else ""))
    print(f"  reincidentes: {len(res['reincidentes'])} · técnicos: {len(res['tecnicos'])} · pendientes: {len(res['pendientes'])}")


if __name__ == "__main__":
    main()
