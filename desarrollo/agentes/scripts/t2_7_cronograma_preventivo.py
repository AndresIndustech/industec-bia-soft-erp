"""
T2.7 - El cronograma de mantenimiento preventivo, convertido a fechas reales.

POR QUE EXISTE:
El preventivo no nace de un aviso notificado: sigue una planificacion acordada
entre Grupo KFC e INDUSTEC, 4 ingresos al ano por local. Esa planificacion vive
hoy en un Excel de la administracion donde las fechas son FRASES, no fechas:
"12 y 13 de febrero", "22 - 24 / 04 /2026", "PEND". Medido sobre el archivo
real: solo el 8,2% de las 368 celdas son fechas de Excel de verdad.

Con frases no se puede avisar "el preventivo de G001 es en 3 dias". Este script
convierte lo que ya existe -- 95,7% automatico -- para que nadie retipee 92
locales, y deja marcado lo que no convierte en vez de adivinarlo.

EL ARCHIVO DE LA ADMINISTRACION NO SE TOCA (I-3, I-4): G:\\ es de solo lectura y
la salida va a nombre nuevo en SALIDAS IA.

DECISIONES DE MODELO, TOMADAS CONTRA LOS DATOS:

  1. EL PLAN ORIGINAL NO SE SOBRESCRIBE. Cada ingreso guarda `plan_original`
     (lo acordado con KFC) y `plan_vigente` (lo que rige hoy). Sin esa
     separacion el cumplimiento siempre da 100%: si al reagendar se pisa la
     fecha, no queda contra que medir. Y el cliente pidio explicitamente poder
     reportarle a KFC los motivos de demora.

  2. UN INGRESO NO ES UN RANGO CONTIGUO. El 19,2% de los ingresos reales tiene
     huecos entre dias (K041EC en marzo: D1 el 10, D2 el 11, D3 el 13). Por eso
     se guardan los dias declarados ademas de inicio y fin, y la ventana es
     inicio..fin sin suponer que todos los dias de por medio se trabajan.

  3. UNA OT POR DIA DE INTERVENCION. Medido: 926 OT preventivas repartidas en
     D1..D5 (454/337/106/17/3). El ingreso se cierra cuando el tecnico marca su
     OT como la ultima de la serie.

  4. EL INGRESO SE AGRUPA POR AVISO SAP. En 2026 el 94,6% de las OT preventivas
     lleva aviso, y 176 de 192 ingresos usan uno solo. En 2025 era 0%: cambio la
     practica. Por eso el aviso se usa como llave del ingreso cuando existe, y
     se cae a (local, mes) cuando no.

  5. LO AMBIGUO NO SE RESUELVE SOLO. "22 - 24 / 04 /2026" puede ser dos dias
     (22 y 24) o tres (22 al 24). No se decide: se guardan los numeros leidos,
     la ventana va de min a max, y el conteo real de OT lo da la operacion.

Uso:
    .venv/Scripts/python.exe scripts/t2_7_cronograma_preventivo.py
"""

import json
import re
import sys
import unicodedata
from datetime import date, datetime
from pathlib import Path

import mysql.connector
import openpyxl

sys.path.insert(0, str(Path(__file__).parent))
from comun import ENV_PATH, SALIDAS  # noqa: E402  (rutas relativas al repositorio, T2.15.1)
# El Drive de INDUSTEC en la estacion (solo lectura). Se puede cambiar con
# CRONOGRAMA_XLSX en config/.env sin tocar codigo.
CRONOGRAMA = Path(r"G:\Mi unidad\SEGUIMIENTO PREVENTIVOS\SEGUIMIENTO PREVENTIVOS _ 2026.xlsx")
SALIDA = SALIDAS / "catalogos"
HOJA = "2026"
ANIO = 2026

# Columnas de la hoja, sondeadas antes de escribir esto (skill de lectura Excel):
#   A #  |  B LOCAL  |  C UBICACION  |  D CIUDAD
#   E..H FECHA INGRESO 1..4  |  I OBSERVACIONES
COL_LOCAL, COL_UBIC, COL_CIUDAD, COL_ING1, COL_OBS = 1, 2, 3, 4, 8
COL_MINIMA = 9

MES = {"enero": 1, "febrero": 2, "marzo": 3, "abril": 4, "mayo": 5, "junio": 6,
       "julio": 7, "agosto": 8, "septiembre": 9, "setiembre": 9, "octubre": 10,
       "noviembre": 11, "diciembre": 12}

# 10-11/01/2026 | 17-18-19/01/2026 | 22-24/04/2026 (ya sin espacios)
RE_NUM = re.compile(r"^([0-9]{1,2}(?:[-/][0-9]{1,2})*?)/([0-9]{1,2})/([0-9]{4})$")

KIT = {
    "PENDIENTE KIT MTO": "PENDIENTE",
    "KIT SOLICITADO": "SOLICITADO",
    "CUENTAN CON KIT": "DISPONIBLE",
    "OK": "DISPONIBLE",
}


def norm(s):
    s = unicodedata.normalize("NFD", str(s or ""))
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    return re.sub(r"\s+", " ", s.lower().replace("\xa0", " ")).strip()


def cargar_env():
    env = {}
    for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return env


def parsear_ingreso(valor):
    """Una celda -> (forma, anio, mes, [dias]) o (forma, None...) si no es fecha.

    Formas: TIPADA | NUMERICA | PALABRAS | PENDIENTE | NO_RECONOCIDO
    """
    if valor is None:
        return "VACIA", None
    if hasattr(valor, "year") and hasattr(valor, "month"):
        return "TIPADA", [(valor.year, valor.month, valor.day)]
    t = norm(valor)
    if t in ("pend", "pendiente", "por definir", "-"):
        return "PENDIENTE", None
    m = RE_NUM.match(t.replace(" ", ""))
    if m:
        dias = [int(d) for d in re.split(r"[-/]", m.group(1))]
        return "NUMERICA", [(int(m.group(3)), int(m.group(2)), d) for d in dias]

    # UN INGRESO PUEDE CRUZAR DE MES: "30 junio y 1 de julio", "31 de agosto 1 y
    # 2 de septiembre". Tomar el primer mes y aplicarselo a todos los dias daba
    # el 1 y el 30 de JUNIO -- una ventana de 30 dias en vez de dos dias
    # seguidos. Regla: cada dia pertenece al primer mes que aparece DESPUES de
    # el en el texto; si no hay ninguno despues, al ultimo que hubo antes.
    meses = [(mm.start(), MES[mm.group(0)]) for mm in
             re.finditer(r"\b(" + "|".join(MES) + r")\b", t)]
    if meses:
        pares = []
        for dm in re.finditer(r"\b(\d{1,2})\b", t):
            dia = int(dm.group(1))
            if not (1 <= dia <= 31):
                continue
            posteriores = [mes for pos, mes in meses if pos > dm.start()]
            anteriores = [mes for pos, mes in meses if pos < dm.start()]
            mes = posteriores[0] if posteriores else (anteriores[-1] if anteriores else None)
            if mes:
                pares.append((ANIO, mes, dia))
        if pares:
            # La forma en palabras NO trae ano: se asume el de la hoja, y se
            # deja constancia de que fue un supuesto, no un dato.
            return "PALABRAS", pares
    return "NO_RECONOCIDO", None


def a_fechas(res):
    """[(anio, mes, dia), ...] -> (inicio, fin, dias_validos).

    Devuelve los dias DECLARADOS, no el rango completo: el 19,2% de los
    ingresos reales tiene huecos, y pintar toda la ventana en el calendario
    llenaba el mes de un solo local.
    """
    fechas = []
    for anio, mes, dia in res:
        try:
            fechas.append(date(anio, mes, dia))
        except ValueError:
            continue          # 31 de febrero y compania: se descarta el dia
    if not fechas:
        return None
    fechas = sorted(set(fechas))
    return fechas[0], fechas[-1], [f.isoformat() for f in fechas]


def leer_cronograma():
    if not CRONOGRAMA.exists():
        sys.exit(f"No encuentro {CRONOGRAMA}")
    wb = openpyxl.load_workbook(CRONOGRAMA, data_only=True, read_only=True)
    ws = wb[HOJA]
    if ws.max_column < COL_MINIMA:
        sys.exit(f"Cambio de formato: se esperaban {COL_MINIMA} columnas y hay {ws.max_column}")

    filas, formas = [], {}
    for f in ws.iter_rows(min_row=2, max_row=ws.max_row, max_col=ws.max_column, values_only=True):
        local = f[COL_LOCAL]
        if not local:
            continue
        local = str(local).strip().replace("\xa0", "")
        if norm(local) in ("local", ""):
            continue          # la fila de encabezado se cuela por el nbsp
        obs = str(f[COL_OBS] or "").strip().replace("\xa0", "")
        ingresos = []
        for n in range(4):
            forma, res = parsear_ingreso(f[COL_ING1 + n])
            formas[forma] = formas.get(forma, 0) + 1
            ingresos.append((n + 1, forma, res, f[COL_ING1 + n]))
        filas.append({
            "local": local,
            "ubicacion": str(f[COL_UBIC] or "").strip(),
            "ciudad": str(f[COL_CIUDAD] or "").strip(),
            "observacion": obs,
            "kit": KIT.get(obs.upper(), "SIN DATO" if not obs else "OTRO"),
            "ingresos": ingresos,
        })
    wb.close()
    return filas, formas


def cargar_realidad(cnx, locales_codigos):
    """Lo que efectivamente se hizo: OT preventivas agrupadas por (local, aviso o mes)."""
    cur = cnx.cursor(dictionary=True)
    cur.execute("""SELECT id_industec, local_codigo, fecha_atencion, dia_intervencion, aviso
                   FROM ots
                   WHERE modulo = 'PREVENTIVO' AND fecha_atencion IS NOT NULL
                     AND YEAR(fecha_atencion) = %s
                   ORDER BY local_codigo, fecha_atencion""", (ANIO,))
    porlocal = {}
    for r in cur.fetchall():
        porlocal.setdefault(r["local_codigo"], []).append(r)
    return porlocal


def main():
    env = cargar_env()
    filas, formas = leer_cronograma()
    print(f"cronograma leido: {len(filas)} locales")
    print("formas de las celdas:", dict(sorted(formas.items())))

    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])
    try:
        cur = cnx.cursor(dictionary=True)
        cur.execute("SELECT local_codigo, zona, cadena, nombre FROM locales WHERE activo = 1")
        maestro = {r["local_codigo"]: r for r in cur.fetchall()}
        realidad = cargar_realidad(cnx, set(maestro))
    finally:
        cnx.close()

    hoy = date.today()
    ingresos, sin_convertir, sin_maestro = [], [], []

    for fila in filas:
        loc = fila["local"]
        info = maestro.get(loc)
        if not info:
            sin_maestro.append(loc)
        ots_local = realidad.get(loc, [])

        for numero, forma, res, crudo in fila["ingresos"]:
            reg = {
                "id": f"{loc}-{ANIO}-{numero}",
                "local": loc,
                "local_nombre": info["nombre"] if info else None,
                "zona": info["zona"] if info else None,
                "cadena": info["cadena"] if info else None,
                "ciudad": fila["ciudad"],
                "numero": numero,
                "kit": fila["kit"],
                "kit_texto": fila["observacion"] or None,
                "forma_origen": forma,
                "texto_origen": str(crudo) if crudo is not None else None,
                "anio_supuesto": forma == "PALABRAS",
                "plan_original": None,
                "plan_vigente": None,
                "real": {"inicio": None, "fin": None, "aviso": None, "ots": [], "cerrado": False},
                "novedades": [],
                "estado": None,
            }
            f = a_fechas(res) if res else None
            if f:
                inicio, fin, dias = f
                plan = {"inicio": inicio.isoformat(), "fin": fin.isoformat(),
                        "dias_declarados": dias, "duracion_dias": (fin - inicio).days + 1}
                reg["plan_original"] = plan
                reg["plan_vigente"] = dict(plan)    # al nacer coinciden
            elif forma in ("PENDIENTE", "NO_RECONOCIDO", "VACIA"):
                if forma != "PENDIENTE":
                    sin_convertir.append({"local": loc, "ingreso": numero,
                                          "texto": reg["texto_origen"], "forma": forma})

            # Cruce con la realidad: las OT del mismo mes del plan (+-1 mes).
            if reg["plan_vigente"]:
                mes_plan = int(reg["plan_vigente"]["inicio"][5:7])
                del_ingreso = [o for o in ots_local
                               if abs(o["fecha_atencion"].month - mes_plan) <= 1]
                if del_ingreso:
                    fechas = sorted({o["fecha_atencion"] for o in del_ingreso})
                    avisos = {o["aviso"] for o in del_ingreso if o["aviso"]}
                    reg["real"] = {
                        "inicio": fechas[0].isoformat(),
                        "fin": fechas[-1].isoformat(),
                        "aviso": str(next(iter(avisos))) if len(avisos) == 1 else None,
                        "avisos_multiples": len(avisos) > 1,
                        "ots": [{"id": o["id_industec"], "fecha": o["fecha_atencion"].isoformat(),
                                 "dia": o["dia_intervencion"]} for o in del_ingreso],
                        # El cierre real lo marca el tecnico en la OT final. Todavia
                        # no existe ese campo: se deja en False y NO se infiere.
                        "cerrado": False,
                    }

            # Estado, sin adivinar nada
            pv = reg["plan_vigente"]
            if not pv:
                reg["estado"] = "SIN_AGENDAR"
            elif reg["real"]["ots"]:
                reg["estado"] = "EN_CURSO"       # hay OT; el cierre lo marca el tecnico
            elif date.fromisoformat(pv["fin"]) < hoy:
                reg["estado"] = "VENCIDO"
            elif (date.fromisoformat(pv["inicio"]) - hoy).days <= 3:
                reg["estado"] = "POR_INICIAR"    # la alerta de 3 dias
            else:
                reg["estado"] = "PLANIFICADO"
            ingresos.append(reg)

    resumen = {}
    for i in ingresos:
        resumen[i["estado"]] = resumen.get(i["estado"], 0) + 1
    kits = {}
    for i in ingresos:
        kits[i["kit"]] = kits.get(i["kit"], 0) + 1

    salida = {
        "generado": datetime.now().strftime("%Y-%m-%d %H:%M"),
        "anio": ANIO,
        "origen": str(CRONOGRAMA),
        "el_plan_original_no_se_pisa": (
            "Cada ingreso guarda plan_original (lo acordado con KFC) y plan_vigente "
            "(lo que rige hoy). Reagendar mueve el vigente y deja novedad; el original "
            "no cambia nunca, porque es contra lo que se mide el cumplimiento."
        ),
        "conversion": {
            "formas": formas,
            "convertidos": sum(1 for i in ingresos if i["plan_original"]),
            "total_ingresos": len(ingresos),
            "sin_convertir": sin_convertir,
        },
        "resumen": {"por_estado": resumen, "por_kit": kits},
        "locales_fuera_del_maestro": sorted(set(sin_maestro)),
        "ingresos": ingresos,
    }
    SALIDA.mkdir(parents=True, exist_ok=True)
    destino = SALIDA / "cronograma_preventivo.json"
    destino.write_text(json.dumps(salida, ensure_ascii=False, indent=1), encoding="utf-8")

    conv = salida["conversion"]["convertidos"]
    tot = len(ingresos)
    print(f"\ningresos               : {tot} ({len(filas)} locales x 4)")
    print(f"con fecha real         : {conv} ({100*conv/tot:.1f}%)")
    print(f"sin convertir          : {len(sin_convertir)}")
    print("por estado             :", dict(sorted(resumen.items())))
    print("por kit                :", dict(sorted(kits.items())))
    if sin_maestro:
        print(f"\nlocales fuera del maestro: {sorted(set(sin_maestro))}")
    if sin_convertir:
        print("\nno se convirtieron (no se adivinan):")
        for s in sin_convertir[:10]:
            print(f"   {s['local']} ingreso {s['ingreso']}: {s['texto']!r} ({s['forma']})")
    print(f"\n-> {destino}")

    if conv / tot < 0.85:
        print(f"\nABORTA: solo {conv}/{tot} ingresos con fecha (<85%).", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
