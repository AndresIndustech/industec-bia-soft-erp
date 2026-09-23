"""
T2.27.1 — «STATUS_PENDIENTES_SEMANA N MES.xlsx», el reporte de los martes a Grupo KFC.

QUÉ ES
Cada martes por la mañana la administración (Isabel Rodríguez) responde el hilo
«ORDENES SEMANALES PENDIENTES _ INDUSTEC» con este Excel: las órdenes de INDUSTEC
que siguen abiertas, zona por zona, y si el equipo está OPERATIVO o
DESHABILITADO. Lo que KFC mira es lo segundo: el 9-sep Miguel Lincango lo
reenvió con «favor revisar cuales son los equipos parados para actuar urgente».

POR QUÉ SE GENERA Y CÓMO
Hoy se arma a mano abriendo el de la semana anterior, y hereda sus defectos:
las fórmulas de RESUMEN tienen rangos fijos (A2:A34) y dejaron fuera órdenes
—la semana 4 le dijo a KFC «Cuenca–Loja: 9 órdenes» cuando eran 21—, el
subtítulo de la semana no se actualiza y las «cerradas» se escriben a mano.

El generador parte del archivo que ELLA mandó la semana anterior —es la
plantilla y es lo único que guarda su criterio: PRESUPUESTO y RESPONSABLES— y
calcula lo que cambió con evidencia:
  - QUITA las que ya tienen orden de cierre de INDUSTEC (acierta 12 de 12 al
    reproducir la semana 4 desde la 3);
  - AGREGA las nuevas: orden correctiva ABIERTA, sin orden de cierre, con
    repuesto pedido (capta 21 de las 28 que ella agregó);
  - refresca ESTATUS SAP con el Excel que KFC mandó el lunes.
Lo que no se puede decidir con datos va al archivo de REVISIÓN, no al reporte:
cuáles de las altas propuestas ella no incluiría, qué presupuesto y
responsable tienen las nuevas, y qué filas SAP ya dio por cerradas.

Uso:
    .venv/Scripts/python.exe scripts/t2_27_status_semanal.py                 # el martes de esta semana
    .venv/Scripts/python.exe scripts/t2_27_status_semanal.py --fecha 2026-09-22 \\
        --anterior "<STATUS de la semana previa>.xlsx" --sap "<REPORTE SEMANA 39>.xlsx" \\
        --comparar "<STATUS real de esa semana>.xlsx"                          # prueba contra lo hecho a mano
"""
from __future__ import annotations

import argparse
import copy
import datetime as dt
import difflib
import re
import sys
from pathlib import Path

import openpyxl

sys.path.insert(0, str(Path(__file__).resolve().parent))
import t2_27_fuentes as F  # noqa: E402

COLS = ["ZONA", "# OT", "LOCAL", "FECHA DE INICIO", "EQUIPO", "MARCA", "TRABAJO REALIZADO / EVALUACIÓN",
        "REPUESTO", "ESTATUS SAP", "PRESUPUESTO", "RESPONSABLES", "ESTATUS DEL EQUIPO", "ANTIGÜEDAD (DÍAS)"]
ETIQUETA_A_ZONA = {v: k for k, v in F.ETIQUETA_ZONA.items()}


def leer_anterior(ruta: Path) -> tuple[list[dict], openpyxl.Workbook]:
    """Las filas del reporte previo, por aviso, y el libro para usarlo de plantilla."""
    wb = openpyxl.load_workbook(ruta, data_only=False)
    valores = openpyxl.load_workbook(ruta, data_only=True)
    for hoja in ("RESUMEN", "ORDENES"):
        if hoja not in wb.sheetnames:
            raise SystemExit(f"{ruta.name}: no tiene la hoja {hoja}; no es un STATUS_PENDIENTES.")
    ws = valores["ORDENES"]
    enc = [str(c.value or "").strip() for c in ws[1]][:13]
    if enc != COLS:
        raise SystemExit(f"{ruta.name}: los encabezados de ORDENES cambiaron.\n esperado {COLS}\n hay      {enc}")
    filas = []
    for r in ws.iter_rows(min_row=2, max_col=13, values_only=True):
        if r[1] in (None, ""):
            continue
        aviso = str(int(r[1])) if isinstance(r[1], (int, float)) else str(r[1]).strip()
        filas.append({"aviso": aviso, **{COLS[i]: r[i] for i in range(13)}})
    return filas, wb


def sap_i(sap: dict | None) -> str:
    v = (sap or {}).get("estatus_orden_2") or ""
    return v if v else "NINGUNO"


def ultima_evaluacion(ots: list[dict]) -> dict:
    return ots[-1]


# Un repuesto que SAP ya marca REDE o MEDE está despachado o entregado: lo que falta es
# instalarlo y cerrar, no esperar a KFC. En la semana 4, de las 21 altas propuestas que la
# administración NO incluyó, 11 eran REDE/MEDE; de las que sí incluyó, ninguna.
YA_ENTREGADO = ("REDE", "MEDE")


def limpiar_texto(t: str | None) -> str:
    """Quita el guion o la viñeta con que empieza el texto del técnico: ella los borra al pegar."""
    return re.sub(r"^\s*[-•·]\s*", "", (t or "").strip())


def canonizador_de_marcas(previas: list[dict], cnx):
    """Corrige la marca contra las que ya se usan: «HENNH PENNY» -> «HENNY PENNY».

    Las canónicas son las del reporte anterior (escritas por la administración) y las
    marcas que se repiten 20 veces o más en las órdenes. Solo se corrige si hay una
    muy parecida; si no, queda lo que escribió el técnico, en mayúsculas.
    """
    # Primero manda la ortografía de ella; las frecuentes de las órdenes van después porque
    # las erratas también se repiten: «HENNH PENNY» y «TURBOAIR» pasan de 20 apariciones.
    admin = {str(f["MARCA"]).upper().strip() for f in previas if f.get("MARCA")}
    cur = cnx.cursor()
    cur.execute("SELECT UPPER(TRIM(marca)), COUNT(*) FROM ot_equipos WHERE marca IS NOT NULL GROUP BY 1 HAVING COUNT(*) >= 20")
    frecuentes = {m for m, _ in cur.fetchall() if m}
    clave = lambda x: re.sub(r"[^A-Z0-9]", "", x)
    por_clave_admin = {clave(c): c for c in admin}
    por_clave_frec = {clave(c): c for c in frecuentes}

    def canon_de(m: str | None) -> str:
        m = (m or "").upper().strip()
        if not m or m in admin:
            return m
        k = clave(m)
        for tabla in (por_clave_admin, por_clave_frec):
            if k in tabla:
                return tabla[k]
            cerca = difflib.get_close_matches(k, list(tabla), n=1, cutoff=0.8)
            if cerca:
                return tabla[cerca[0]]
        return m
    return canon_de


def generar(fecha: dt.date, anterior: Path, sap_ruta: Path, cerradas_manual: list[int] | None, salida: Path) -> dict:
    corte = fecha - dt.timedelta(days=1)
    previas, wb = leer_anterior(anterior)
    prev_avisos = {f["aviso"] for f in previas}
    if len(prev_avisos) != len(previas):
        print(f"  AVISO: {anterior.name} trae avisos repetidos; se conserva la primera fila de cada uno.")
    sap = F.leer_sap_semanal(sap_ruta)
    cnx = F.conectar()
    zonas = F.zonas_de_locales(cnx)
    ots = F.ordenes_por_aviso(cnx, corte)
    marca = canonizador_de_marcas(previas, cnx)
    # La fecha del envío anterior se deduce del nombre de la semana cuando no se da:
    # las altas se buscan desde entonces. Si no se puede, una semana atrás.
    desde = corte - dt.timedelta(days=7)

    quitadas, conservadas, cambios_i, por_confirmar = [], [], [], []
    vistos = set()
    for f in previas:
        if f["aviso"] in vistos:
            continue
        vistos.add(f["aviso"])
        mias = ots.get(f["aviso"], [])
        cierre = [o for o in mias if o["estado_ot"] == "CERRADA"]
        if cierre:
            f["_motivo"] = f"orden de cierre {cierre[-1]['id_industec']} del {cierre[-1]['fecha_atencion']:%d/%m}"
            quitadas.append(f)
            continue
        s = sap.get(f["aviso"])
        nuevo_i = sap_i(s)
        antes_i = str(f["ESTATUS SAP"] or "").strip()
        if s is not None and nuevo_i != antes_i:
            cambios_i.append((f["aviso"], antes_i, nuevo_i))
            f["ESTATUS SAP"] = nuevo_i
        # El estado del equipo lo cambia una orden NUEVA; si no hubo, manda lo que ella anotó
        # (puede saber por teléfono que el equipo quedó parado).
        nuevas = [o for o in mias if o["fecha_atencion"] >= desde and o["estado_equipo"]]
        if nuevas and nuevas[-1]["estado_equipo"].upper() != str(f["ESTATUS DEL EQUIPO"] or "").upper():
            por_confirmar.append((f, f"orden nueva {nuevas[-1]['id_industec']} dice {nuevas[-1]['estado_equipo'].upper()}; "
                                     f"el reporte anterior decía {f['ESTATUS DEL EQUIPO']}"))
            f["ESTATUS DEL EQUIPO"] = nuevas[-1]["estado_equipo"].upper()
        if s and s.get("estatus_a") == "CERRADO" and any(x in nuevo_i for x in ("REDE", "MEDE")):
            por_confirmar.append((f, f"SAP ya cerró el aviso y el repuesto figura {nuevo_i}: confirmar si sigue pendiente"))
        if not mias:
            por_confirmar.append((f, "no hay ninguna orden de INDUSTEC con este aviso en la base"))
        conservadas.append(f)

    agregadas, otras_abiertas = [], []
    hace30 = corte - dt.timedelta(days=30)
    for aviso, lista in ots.items():
        if aviso in prev_avisos:
            continue
        if any(o["modulo"] != "CORRECTIVO" for o in lista):
            continue
        if any(o["estado_ot"] == "CERRADA" for o in lista):
            continue
        ult = ultima_evaluacion(lista)
        s = sap.get(aviso)
        # Lo que no entra pero sigue abierto se le muestra, con el motivo: la regla capta
        # tres de cada cuatro altas, y la cuarta es justo la que ella tiene que ver.
        motivo_fuera = None
        if not (desde <= lista[0]["fecha_atencion"] <= corte):
            motivo_fuera = f"la primera orden es del {lista[0]['fecha_atencion']:%d/%m}, antes de esta semana"
        elif not F.tiene_repuesto(ult["repuestos"]):
            motivo_fuera = "la orden no pide repuesto"
        elif any(x in sap_i(s) for x in YA_ENTREGADO):
            motivo_fuera = f"SAP marca el repuesto como {sap_i(s)} (ya despachado o entregado)"
        if motivo_fuera:
            if lista[0]["fecha_atencion"] >= hace30:
                otras_abiertas.append((aviso, ult, s, motivo_fuera))
            continue
        zona = F.zona_de(ult["local_codigo"], zonas) or ult["zona"]
        fila = {
            "aviso": aviso,
            "ZONA": F.ETIQUETA_ZONA.get(zona, ""),
            "# OT": int(aviso) if aviso.isdigit() else aviso,
            "LOCAL": ult["local_codigo"],
            # FECHA DE INICIO = fecha de notificación en SAP: así la llena ella (44 de 45 en la semana 4).
            "FECHA DE INICIO": dt.datetime.combine((s or {}).get("fecha_notificacion") or lista[0]["fecha_atencion"], dt.time()),
            # EQUIPO = la denominación del objeto en SAP; si SAP no la trae, la de la orden.
            "EQUIPO": ((s or {}).get("denominacion") or (ult["equipo"] or "")).upper().strip(),
            "MARCA": marca(ult["marca"]),
            "TRABAJO REALIZADO / EVALUACIÓN": limpiar_texto(ult["actividades"]),
            "REPUESTO": limpiar_texto(ult["repuestos"]),
            "ESTATUS SAP": sap_i(s),
            "PRESUPUESTO": None,       # criterio de la administración: no se inventa
            "RESPONSABLES": None,      # ídem
            "ESTATUS DEL EQUIPO": (ult["estado_equipo"] or "").upper().strip() or None,
            "_motivo": f"{ult['id_industec']} del {ult['fecha_atencion']:%d/%m}: orden abierta con repuesto pedido y sin orden de cierre",
            "_sap_a": (s or {}).get("estatus_a"),
        }
        if not fila["ZONA"]:
            por_confirmar.append((fila, f"el local {ult['local_codigo']} no está en el maestro: sin zona"))
        if not fila["ESTATUS DEL EQUIPO"]:
            por_confirmar.append((fila, "la orden no dice si el equipo quedó operativo o deshabilitado"))
        agregadas.append(fila)

    filas = conservadas + agregadas
    filas.sort(key=lambda f: (F.ORDEN_ZONA.get(ETIQUETA_A_ZONA.get(f["ZONA"], ""), 9),
                              f["FECHA DE INICIO"] or dt.datetime.max, str(f["# OT"])))

    # «Cerradas en la semana»: órdenes de cierre de INDUSTEC emitidas del martes anterior al lunes,
    # por zona. Ella las escribe a mano y no se pudieron reproducir desde ninguna fuente, así que
    # aquí se usa una definición explícita (y se puede imponer la suya con --cerradas).
    cur = cnx.cursor()
    cur.execute("""SELECT zona, COUNT(DISTINCT aviso) FROM ots
                    WHERE en_cuarentena = 0 AND correlativo < 90000 AND modulo = 'CORRECTIVO'
                      AND estado_ot = 'CERRADA' AND fecha_atencion BETWEEN %s AND %s GROUP BY zona""", (desde, corte))
    cerradas_calc = dict(cur.fetchall())
    cerradas = cerradas_manual or [int(cerradas_calc.get(z, 0)) for z in ("UIO", "LARB", "CNLJ")]
    cur.execute("""SELECT id_industec, zona, aviso, local_codigo, fecha_atencion FROM ots
                    WHERE en_cuarentena = 0 AND correlativo < 90000 AND modulo = 'CORRECTIVO'
                      AND estado_ot = 'CERRADA' AND fecha_atencion BETWEEN %s AND %s ORDER BY zona, fecha_atencion""", (desde, corte))
    detalle_cerradas = cur.fetchall()
    cnx.close()

    # ---------------------------------------------------------------- ORDENES
    ws = wb["ORDENES"]
    estilos = [copy.copy(ws.cell(row=2, column=c)._style) for c in range(1, 14)]
    alto = ws.row_dimensions[2].height or 45
    reglas = []
    for cf in ws.conditional_formatting:
        for regla in cf.rules:
            reglas.append(regla)
    ws.conditional_formatting = type(ws.conditional_formatting)()
    if ws.max_row >= 2:
        ws.delete_rows(2, ws.max_row - 1)
    for i, f in enumerate(filas, start=2):
        for c, nombre in enumerate(COLS, start=1):
            celda = ws.cell(row=i, column=c)
            celda._style = copy.copy(estilos[c - 1])
            if nombre == "ANTIGÜEDAD (DÍAS)":
                celda.value = f'=IF(D{i}="","",TODAY()-D{i})'
            else:
                celda.value = f[nombre]
        ws.row_dimensions[i].height = alto
    fin = len(filas) + 1
    # El semáforo de la columna # OT se conserva tal cual venía en la plantilla,
    # solo con el rango hasta la última fila real.
    for regla in reglas:
        ws.conditional_formatting.add(f"B2:B{fin}", regla)
    ws.auto_filter.ref = f"A1:M{fin}"

    # ---------------------------------------------------------------- RESUMEN
    r = wb["RESUMEN"]
    n = F.ordinal_en_el_mes(fecha)
    r["A2"] = f"SEMANA {n} ( {fecha.day} {F.MESES[fecha.month - 1].capitalize()})"
    r["A5"] = f"=COUNTA(ORDENES!A2:A{fin})"
    for celda, zona in (("B5", "ZONA UIO"), ("C5", "ZONA LARB"), ("D5", "ZONA C-L")):
        r[celda] = f'=COUNTIF(ORDENES!A2:A{fin},"{zona}")'
    r["E5"] = f'=COUNTIF(ORDENES!L2:L{fin},"DESHABILITADO")'
    r["F5"] = f'=COUNTIF(ORDENES!L2:L{fin},"OPERATIVO")'
    for fila in (9, 10, 11):
        r[f"B{fila}"] = f"=COUNTIF(ORDENES!A2:A{fin},A{fila})"
        r[f"C{fila}"] = f'=COUNTIFS(ORDENES!A2:A{fin},A{fila},ORDENES!L2:L{fin},"OPERATIVO")'
        r[f"D{fila}"] = f'=COUNTIFS(ORDENES!A2:A{fin},A{fila},ORDENES!L2:L{fin},"DESHABILITADO")'
        r[f"E{fila}"] = f"=IF(B{fila}=0,0,D{fila}/B{fila})"
    anterior_martes = fecha - dt.timedelta(days=7)
    mes = F.MESES[fecha.month - 1]
    r["A13"] = (f"Semana del {anterior_martes.day} al {fecha.day} de {mes}" if anterior_martes.month == fecha.month
                else f"Semana del {anterior_martes.day} de {F.MESES[anterior_martes.month - 1]} al {fecha.day} de {mes}")
    for celda, v in zip(("C15", "C16", "C17"), cerradas):
        r[celda] = v
    wb.calculation.fullCalcOnLoad = True
    # ZONA se escribe como valor: la fórmula original buscaba en un libro del Drive
    # ('[1]LOCALES INDUSTEC') que KFC no puede abrir, y el vínculo le pedía «actualizar».
    wb._external_links = []

    # -------------------------------------------- verificación cruzada (I-10)
    cuenta = {"ZONA UIO": [0, 0, 0], "ZONA LARB": [0, 0, 0], "ZONA C-L": [0, 0, 0]}
    for f in filas:
        if f["ZONA"] in cuenta:
            c = cuenta[f["ZONA"]]
            c[0] += 1
            c[1] += f["ESTATUS DEL EQUIPO"] == "OPERATIVO"
            c[2] += f["ESTATUS DEL EQUIPO"] == "DESHABILITADO"
    esperado = len(vistos) - len(quitadas) + len(agregadas)
    if len(filas) != esperado:
        raise SystemExit(f"ABORTADO: {len(filas)} filas, y conservadas+agregadas da {esperado}. No se guarda nada.")
    salida.mkdir(parents=True, exist_ok=True)
    nombre = f"STATUS_PENDIENTES_SEMANA {n} {F.MES3[fecha.month - 1]} (generado agente).xlsx"
    destino = salida / nombre
    wb.save(destino)
    # Los valores de las fórmulas, calculados aquí, para que el RESUMEN no llegue en ceros a
    # quien lo abra en la vista previa del correo (que no recalcula).
    oper_t = sum(c[1] for c in cuenta.values())
    desh_t = sum(c[2] for c in cuenta.values())
    zonas_orden = ("ZONA UIO", "ZONA LARB", "ZONA C-L")
    v_res = {"A5": len(filas), "B5": cuenta["ZONA UIO"][0], "C5": cuenta["ZONA LARB"][0], "D5": cuenta["ZONA C-L"][0],
             "E5": desh_t, "F5": oper_t, "C20": len(filas), "C21": sum(cerradas)}
    for k, (fila, z) in enumerate(zip((9, 10, 11), zonas_orden)):
        tot, op, de = cuenta[z]
        v_res.update({f"B{fila}": tot, f"C{fila}": op, f"D{fila}": de, f"E{fila}": (de / tot if tot else 0),
                      f"B{15 + k}": tot})
    v_ord = {}
    for i, f in enumerate(filas, start=2):
        d = f["FECHA DE INICIO"]
        v_ord[f"M{i}"] = (fecha - d.date()).days if isinstance(d, dt.datetime) else ""
    fijadas = F.fijar_valores_de_formulas(destino, {"RESUMEN": v_res, "ORDENES": v_ord})
    print(f"  valores fijados en {fijadas} fórmulas")
    # Se relee lo guardado y se recuenta por zona: si el archivo no dice lo mismo que el cálculo, aborta.
    ws2 = openpyxl.load_workbook(destino, data_only=False)["ORDENES"]
    releido = {}
    for row in ws2.iter_rows(min_row=2, max_col=12, values_only=True):
        if row[1] is not None:
            releido[row[0]] = releido.get(row[0], 0) + 1
    if any(releido.get(z, 0) != c[0] for z, c in cuenta.items()):
        destino.unlink()
        raise SystemExit(f"ABORTADO: el archivo guardado cuenta {releido} y el cálculo {cuenta}. Se borró el archivo generado.")
    # Y lo que verá quien lo abra sin recalcular: cada valor del RESUMEN contra el recuento.
    res_leido = openpyxl.load_workbook(destino, data_only=True)["RESUMEN"]
    malas = {k: (res_leido[k].value, v) for k, v in v_res.items()
             if not (res_leido[k].value == v or (isinstance(v, float) and abs((res_leido[k].value or 0) - v) < 1e-9))}
    if malas:
        destino.unlink()
        raise SystemExit(f"ABORTADO: el RESUMEN guardado no dice lo que se calculó (celda: (leído, esperado)): {malas}")

    total = len(filas)
    oper = sum(c[1] for c in cuenta.values())
    desh = sum(c[2] for c in cuenta.values())
    return dict(destino=destino, filas=filas, quitadas=quitadas, agregadas=agregadas, cambios_i=cambios_i,
                otras_abiertas=otras_abiertas,
                por_confirmar=por_confirmar, cuenta=cuenta, total=total, oper=oper, desh=desh,
                cerradas=cerradas, cerradas_calc=cerradas_calc, detalle_cerradas=detalle_cerradas,
                n=n, fecha=fecha, desde=desde, corte=corte, anterior=anterior, sap=sap_ruta)


def cuerpo_correo(res: dict) -> str:
    c, f = res["cuenta"], res["fecha"]
    lin = lambda z, et: f"  *   {et}: {c[z][0]} órdenes, {c[z][1]} operativas y {c[z][2]} deshabilitadas."
    cer = sum(res["cerradas"])
    return "\n".join([
        "Estimados,",
        f"Adjunto el archivo con el estado de las órdenes pendientes correspondiente a la Semana {res['n']} ({f.day} de {F.MESES[f.month - 1]}).",
        f"Actualmente se registran {res['total']} órdenes pendientes, distribuidas de la siguiente manera:",
        lin("ZONA UIO", "UIO"), lin("ZONA LARB", "LARB"), lin("ZONA C-L", "Cuenca–Loja"),
        f"En total, {res['oper']} equipos se encuentran operativos y {res['desh']} deshabilitados. "
        f"Durante la semana se registraron {res['total']} órdenes abiertas y {cer} órdenes cerradas.",
        "Se adjunta el detalle de cada orden para su revisión y seguimiento correspondiente.",
        "Saludos cordiales,",
    ])


def escribir_revision(res: dict) -> Path:
    """El archivo para la administración: lo que el generador decidió, con su evidencia, y lo que no puede decidir."""
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "LEEME"
    lineas = [
        f"Revisión del STATUS_PENDIENTES de la semana {res['n']} ({res['fecha']:%d/%m/%Y}) — generado por el agente",
        "",
        f"Punto de partida: {res['anterior'].name} (el reporte que se mandó la semana anterior).",
        f"Estado de SAP: {res['sap'].name} (el Excel que KFC mandó el lunes).",
        f"Órdenes de INDUSTEC: base local, hasta el {res['corte']:%d/%m/%Y}.",
        "",
        f"Se QUITARON {len(res['quitadas'])} órdenes: ya tienen orden de cierre de INDUSTEC (hoja QUITADAS).",
        f"Se AGREGARON {len(res['agregadas'])}: orden correctiva abierta desde el {res['desde']:%d/%m}, con repuesto pedido y sin cierre (hoja AGREGADAS).",
        "  Las agregadas llegan SIN presupuesto ni responsable: esas dos columnas son criterio de la administración y no se inventan.",
        "  Al reproducir la semana 4 desde la 3, esta regla captó 21 de las 28 que se agregaron a mano y propuso 21 más.",
        "  Revise la hoja AGREGADAS y borre del reporte las que no correspondan.",
        f"Se actualizó ESTATUS SAP en {len(res['cambios_i'])} filas con el Excel de KFC (hoja ESTATUS SAP).",
        f"Hay {len(res['por_confirmar'])} filas para confirmar (hoja POR CONFIRMAR).",
        f"Otras {len(res['otras_abiertas'])} órdenes siguen abiertas (últimos 30 días) y NO entraron, cada una con su motivo (hoja OTRAS ABIERTAS):",
        "  sin repuesto pedido, repuesto ya despachado según SAP (REDE/MEDE), o anteriores a esta semana. Si alguna debe ir, cópiela al reporte.",
        "",
        "«Cerradas» de la semana (RESUMEN C15:C17): órdenes de cierre de INDUSTEC emitidas del "
        f"{res['desde']:%d/%m} al {res['corte']:%d/%m}, por zona: UIO {res['cerradas'][0]}, LARB {res['cerradas'][1]}, C-L {res['cerradas'][2]}.",
        "  Si se lleva otra cuenta, se puede imponer con --cerradas UIO,LARB,CL.",
        "",
        "Diferencias con el archivo hecho a mano, a propósito:",
        "  - Las fórmulas de RESUMEN cuentan hasta la última fila real (antes tenían rangos fijos que dejaban órdenes fuera).",
        "  - ZONA va como valor, no como búsqueda en un libro del Drive que KFC no puede abrir.",
    ]
    for i, t in enumerate(lineas, start=1):
        ws.cell(row=i, column=1, value=t)
    ws.column_dimensions["A"].width = 140

    def hoja(nombre, encabezados, datos):
        h = wb.create_sheet(nombre)
        h.append(encabezados)
        for d in datos:
            h.append(d)
        for c in range(1, len(encabezados) + 1):
            h.cell(row=1, column=c).font = openpyxl.styles.Font(bold=True)
        h.freeze_panes = "A2"
        for col, ancho in zip("ABCDEFGH", (12, 12, 10, 28, 70, 14, 14, 14)):
            h.column_dimensions[col].width = ancho

    hoja("QUITADAS", ["# OT", "ZONA", "LOCAL", "EQUIPO", "Por qué sale"],
         [[f["# OT"], f["ZONA"], f["LOCAL"], f["EQUIPO"], f["_motivo"]] for f in res["quitadas"]])
    hoja("AGREGADAS", ["# OT", "ZONA", "LOCAL", "EQUIPO", "Por qué entra", "ESTATUS SAP", "SAP (aviso)", "Equipo"],
         [[f["# OT"], f["ZONA"], f["LOCAL"], f["EQUIPO"], f["_motivo"], f["ESTATUS SAP"], f["_sap_a"], f["ESTATUS DEL EQUIPO"]] for f in res["agregadas"]])
    hoja("ESTATUS SAP", ["# OT", "Antes (reporte anterior)", "Ahora (SAP del lunes)"],
         [[a, b, c] for a, b, c in res["cambios_i"]])
    hoja("POR CONFIRMAR", ["# OT", "ZONA", "LOCAL", "Qué confirmar"],
         [[f["# OT"], f["ZONA"], f["LOCAL"], m] for f, m in res["por_confirmar"]])
    hoja("OTRAS ABIERTAS", ["# OT", "LOCAL", "Primera orden", "Equipo", "Por qué NO entró", "ESTATUS SAP", "SAP (aviso)"],
         [[a, u["local_codigo"], f"{u['id_industec']} ({u['fecha_atencion']:%d/%m})", (u["equipo"] or "").upper(), m,
           sap_i(s), (s or {}).get("estatus_a")] for a, u, s, m in sorted(res["otras_abiertas"], key=lambda x: x[0])])
    hoja("CERRADAS SEMANA", ["Orden de cierre", "Zona", "Aviso", "Local", "Fecha"],
         [list(x) for x in res["detalle_cerradas"]])
    destino = res["destino"].with_name(f"REVISION STATUS_PENDIENTES SEMANA {res['n']} {F.MES3[res['fecha'].month - 1]} (generado agente).xlsx")
    wb.save(destino)
    return destino


def comparar(generado: Path, real: Path, anterior: Path | None = None) -> bool:
    """Celda a celda por aviso (skill de entregables B5), separando lo que se compara.

    - Objetivas (A–H y L): lo que sale de SAP y de las órdenes. Criterio: ≥95 % idénticas.
    - ESTATUS SAP (I): el generador la refresca con el SAP del lunes a propósito; ella la arrastra.
    - PRESUPUESTO y RESPONSABLES (J, K): criterio de la administración. En las filas nuevas
      quedan vacías por diseño; en las arrastradas, se muestran las que ella cambió esa semana.
    La antigüedad (M) no se compara: es TODAY()-D y depende del día en que se abre.
    """
    def filas(p):
        ws = openpyxl.load_workbook(p, data_only=True)["ORDENES"]
        return {str(int(r[1])) if isinstance(r[1], (int, float)) else str(r[1]).strip(): r
                for r in ws.iter_rows(min_row=2, max_col=13, values_only=True) if r[1] not in (None, "")}
    g, r = filas(generado), filas(real)
    prev = filas(anterior) if anterior else {}
    comunes = sorted(set(g) & set(r))
    norm = lambda v: " ".join((v.date().isoformat() if isinstance(v, dt.datetime) else str(v if v is not None else "")).upper().split())
    OBJ = [0, 1, 2, 3, 4, 5, 6, 7, 11]
    ig = tot = 0
    por_col = {}
    for a in comunes:
        for i in OBJ:
            tot += 1
            if norm(g[a][i]) == norm(r[a][i]):
                ig += 1
            else:
                por_col[COLS[i]] = por_col.get(COLS[i], 0) + 1
    pct = ig / tot * 100 if tot else 0
    dif_i = sum(norm(g[a][8]) != norm(r[a][8]) for a in comunes)
    arr = [a for a in comunes if a in prev]
    nuevas = [a for a in comunes if a not in prev]
    jk_cambiadas = [a for a in arr if norm(prev[a][9]) != norm(r[a][9]) or norm(prev[a][10]) != norm(r[a][10])]
    print(f"\n== Comparación contra {real.name}")
    print(f"  Filas: generado {len(g)} · real {len(r)} · en ambos {len(comunes)} ({len(arr)} arrastradas, {len(nuevas)} nuevas)")
    print(f"  OBJETIVAS (A–H, L), en ambos: {ig} de {tot} idénticas ({pct:.1f} %). Diferencias: {dict(sorted(por_col.items(), key=lambda x: -x[1]))}")
    print(f"  ESTATUS SAP distinto en {dif_i} de {len(comunes)}: el generador usa el SAP del lunes; el archivo real arrastra el valor anterior.")
    print(f"  PRESUPUESTO/RESPONSABLES: {len(nuevas)} filas nuevas quedan en blanco por diseño; "
          f"de las {len(arr)} arrastradas, ella cambió {len(jk_cambiadas)} esa semana: {jk_cambiadas}")
    solo_r, solo_g = sorted(set(r) - set(g)), sorted(set(g) - set(r))
    print(f"  Avisos solo en el REAL ({len(solo_r)}): {solo_r}")
    print(f"  Avisos solo en lo GENERADO ({len(solo_g)}): {solo_g}")
    return pct >= 95


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    ap.add_argument("--fecha", help="martes de envío (AAAA-MM-DD); por omisión, el de esta semana")
    ap.add_argument("--anterior", help="STATUS_PENDIENTES que se mandó la semana previa; por omisión se baja del correo (Enviados)")
    ap.add_argument("--sap", help="Excel semanal de KFC; por omisión se baja del correo (Recibidos)")
    ap.add_argument("--cerradas", help="impone las cerradas de la semana: UIO,LARB,CL")
    ap.add_argument("--comparar", help="STATUS real de la misma semana, para medir el generador")
    a = ap.parse_args()
    fecha = dt.date.fromisoformat(a.fecha) if a.fecha else F.martes_de_envio(dt.date.today())
    if fecha.weekday() != 1:
        print(f"  Aviso: {fecha} no es martes; el reporte se nombra por el martes de envío.")
    if a.anterior:
        anterior = Path(a.anterior)
    else:
        b = F.bajar_ultimo_adjunto("Sent", r"STATUS_PENDIENTES.*\.xlsx$", dias=21, antes_de=fecha)
        if not b:
            raise SystemExit("No encontré en Enviados un STATUS_PENDIENTES de las últimas 3 semanas. Pásalo con --anterior.")
        anterior = b[0]
    if a.sap:
        sap = Path(a.sap)
    else:
        b = F.bajar_ultimo_adjunto("INBOX", r"REPORTE.*SEMANA.*CORRECTIVO.*\.xlsx$", dias=10, antes_de=fecha + dt.timedelta(days=1))
        if not b:
            raise SystemExit("No encontré en Recibidos el REPORTE ... SEMANA NN ... CORRECTIVO de KFC. Pásalo con --sap.")
        sap = b[0]
    cerradas = [int(x) for x in a.cerradas.split(",")] if a.cerradas else None
    print(f"STATUS_PENDIENTES del martes {fecha}\n  anterior: {anterior}\n  SAP: {sap}")
    res = generar(fecha, anterior, sap, cerradas, F.SALIDAS / f"{fecha:%Y-%m-%d}")
    rev = escribir_revision(res)
    correo = res["destino"].with_name(f"CORREO STATUS_PENDIENTES SEMANA {res['n']} (generado agente).txt")
    correo.write_text(cuerpo_correo(res), encoding="utf-8")
    c = res["cuenta"]
    print(f"\n  {res['total']} órdenes: UIO {c['ZONA UIO'][0]} · LARB {c['ZONA LARB'][0]} · C-L {c['ZONA C-L'][0]} "
          f"| operativas {res['oper']} · deshabilitadas {res['desh']}")
    print(f"  quitadas {len(res['quitadas'])} · agregadas {len(res['agregadas'])} · ESTATUS SAP actualizado en {len(res['cambios_i'])} · por confirmar {len(res['por_confirmar'])}")
    print(f"  cerradas de la semana (UIO, LARB, C-L): {res['cerradas']}")
    print(f"  Reporte:  {res['destino']}\n  Revisión: {rev}\n  Correo:   {correo}")
    if a.comparar:
        ok = comparar(res["destino"], Path(a.comparar), anterior)
        print("  Criterio ≥95 % de celdas objetivas idénticas:", "CUMPLE" if ok else "NO CUMPLE")


if __name__ == "__main__":
    main()
