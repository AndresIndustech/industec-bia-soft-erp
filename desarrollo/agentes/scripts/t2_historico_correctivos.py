"""
Historico de correctivos en el formato de planificacion de la administracion.

Genera un archivo por zona y por mes con la misma estructura de 22 columnas de
los `PLANES MENSUALES/{MES}.xlsx` que la administracion lleva a mano, cubriendo
todo el rango con datos (sep-2025 a sep-2026). Es la BASE DE PARTIDA: una vez
enlazado el sistema en produccion (Hostinger), los planes del dia se procesan
sobre este historico en lugar de arrancar de cero.

Diferencias deliberadas contra `agente2_consolidador.py`, que solo sabia hacer
el mes en curso de UIO:

1. **Plantilla por zona.** Cada zona tiene su propio archivo real con sus
   estilos; el consolidador usaba el de UIO para todo y ademas asumia que la
   hoja se llama "Hoja1" -- la de CNLJ se llama "PLAN SEMANAL" y habria
   reventado.
2. **Filtro de zona que efectivamente cruza.** SAP guarda el centro de coste
   sin sufijo ('R011') y el maestro con el ('R011EC'): comparados crudos no
   cruzan NI UNA fila, asi que el consolidador se quedaba solo con los avisos
   que ya tenian orden de INDUSTEC y perdia 2.968 de los 6.450.
3. **Arrastre medido, no supuesto.** Un caso anterior al mes se arrastra
   mientras INDUSTEC no lo haya cerrado y ademas siguiera abierto en SAP o
   viniera del mes inmediato anterior. Contrastadas cinco reglas candidatas
   contra los 24 archivos reales, esta da 89% de precision y 85% de cobertura;
   la del consolidador arrastraba el backlog de HOY a cualquier mes pasado.
4. **Solo el alcance de INDUSTEC.** Se listan los avisos `Mant. Correctivo`:
   5.086 de las 5.133 filas reales lo son. Incluir "Menaje (Consumibles)" y
   "Mant. Constructivo", que son de otro proveedor, duplicaba el archivo.
5. **Ordenes sin aviso incluidas.** Son 37 correctivos reales; el consolidador
   los descartaba con `aviso IS NOT NULL` y desaparecian del historico. Entran
   con "# OT" = NINGUNO (I-7: si no hay dato, se dice; no se omite la fila).

El archivo mensual **no es una foto congelada del mes**: la administracion lo
sigue completando despues, y por eso el estado actual del aviso reproduce mejor
su archivo (95,6%) que el estado que el caso tenia al cierre del mes (93,4%).

Limites conocidos y declarados (I-12), detallados en `SALIDAS IA\\MANTENIMIENTO\\
LEEME_HISTORICO.md`: `avisos_sap` solo cubre 2026-01 a 2026-08, y la columna
ESTATUS SAP queda en NINGUNO porque su vocabulario real vive en el campo
"Estatus 2 de la Orden" del export SAP, que todavia no esta importado.

Uso:
    .venv/Scripts/python.exe scripts/t2_historico_correctivos.py [--zona UIO] [--desde 2025-09] [--hasta 2026-09]
"""
import argparse
import sys
from copy import copy
from datetime import date
from pathlib import Path

import openpyxl
from openpyxl.formatting.formatting import ConditionalFormattingList
from openpyxl.utils import get_column_letter

sys.path.insert(0, str(Path(__file__).parent))
from agente2_consolidador import COLS, cargar_equipos, conectar, id_industec_original, mayus

# Convencion del literal NINGUNO, medida sobre las 5.371 filas de los 24
# archivos mensuales reales de las tres zonas (ene-ago 2026): las ocho
# columnas del lado EVALUACION lo llevan cuando no hay dato (68-89% de los
# casos sin dato), y las del lado CIERRE simplemente quedan vacias (<2%).
# El consolidador de T1.11 solo conocia K/L/N porque se calibro contra un
# unico archivo, el de septiembre.
COLS_NINGUNO = {"C", "I", "J", "K", "L", "N", "P", "Q"}

ORIGEN = Path(r"D:\RESPALDOS\_ORIGEN_DRIVE\GESTION DE OTS INDUSTEC\2026\PLANES SEMANALES")
SALIDA = Path(r"D:\INDUSTECH IA\SALIDAS IA\MANTENIMIENTO\MANTENIMIENTOS CORRECTIVOS")

# Plantilla real de cada zona (solo lectura: I-3 / I-4, jamas se escribe encima)
PLANTILLAS = {
    "UIO": ORIGEN / "PLAN DE TRABAJO _ ZONA UIO" / "PLAN SEGUIMIENTO OTS UIO _ SEPTIEMBRE.xlsx",
    "LARB": ORIGEN / "PLAN DE TRABAJO _ ZONA LARB" / "PLAN SEGUIMIENTO OTS LARB _ SEPTIEMBRE.xlsx",
    "CNLJ": ORIGEN / "PLAN DE TRABAJO _ ZONA C-L" / "PLAN SEGUIMIENTO OTS CNLJ _ SEPTIEMBRE.xlsx",
}
# La carpeta de salida de CNLJ ya existe con el nombre largo que usa la empresa
CARPETA_ZONA = {"UIO": "ZONA UIO", "LARB": "ZONA LARB", "CNLJ": "ZONA CUENCA LOJA"}

MESES = ["ENERO", "FEBRERO", "MARZO", "ABRIL", "MAYO", "JUNIO", "JULIO",
         "AGOSTO", "SEPTIEMBRE", "OCTUBRE", "NOVIEMBRE", "DICIEMBRE"]


def mes_previo(mes_ini):
    """Primer dia del mes anterior: el arrastre de un solo mes de la regla C6."""
    return date(mes_ini.year - 1, 12, 1) if mes_ini.month == 1 else date(mes_ini.year, mes_ini.month - 1, 1)


def limites_mes(anio, mes):
    ini = date(anio, mes, 1)
    fin = date(anio + (1 if mes == 12 else 0), 1 if mes == 12 else mes + 1, 1)
    return ini, fin


def meses_con_datos(cnx, desde=None, hasta=None):
    cur = cnx.cursor()
    cur.execute("""SELECT MIN(fecha_atencion), MAX(fecha_atencion) FROM ots
                   WHERE en_cuarentena=0 AND modulo='CORRECTIVO' AND fecha_atencion IS NOT NULL""")
    fmin, fmax = cur.fetchone()
    cur.close()
    ini = desde or (fmin.year, fmin.month)
    fin = hasta or (fmax.year, fmax.month)
    meses, y, m = [], ini[0], ini[1]
    while (y, m) <= fin:
        meses.append((y, m))
        y, m = (y + 1, 1) if m == 12 else (y, m + 1)
    return meses


def cobertura_sap(cnx):
    cur = cnx.cursor()
    cur.execute("SELECT MIN(fecha_notificacion), MAX(fecha_notificacion), COUNT(*) FROM avisos_sap")
    r = cur.fetchone()
    cur.close()
    return r


# El tecnico escribe "-", "N/A" o "NINGUNA" para decir que no hubo nada; en el
# plan de la administracion eso es celda sin dato. Sin normalizarlo, 2.959
# celdas quedaban distintas por un guion.
PLACEHOLDERS = {"-", "--", "---", ".", "N/A", "NA", "N/O", "NO", "NINGUNA", "NINGUNO",
                "SIN NOVEDAD", "S/N", "SN", "X"}

# Vocabulario real de la columna ESTATUS SAP, contado sobre los 24 archivos:
# REDE, MEDE, APRO, MSOL, IMPO, AUTO, RINC, AOPE, MFDE. Es el campo
# "Estatus 2 de la Orden" del export SAP, que no esta importado en la base.
VOCABULARIO_ESTATUS_SAP = {"REDE", "MEDE", "APRO", "MSOL", "IMPO", "AUTO", "RINC", "AOPE", "MFDE"}


def limpiar(v):
    """Devuelve None cuando el texto es un relleno sin informacion."""
    if v is None:
        return None
    s = str(v).strip().lstrip("-*• ").strip()   # el tecnico vinetea con "- "
    return None if not s or s.upper() in PLACEHOLDERS else s


def _armar_fila_hist(aviso_row, ordenes):
    """Una fila = un caso, con dos ranuras: evaluacion y cierre.

    Reparto por FECHA, no por estado. La regla del consolidador de T1.11
    ("evaluacion = la primera orden que no este cerrada") se queda sin ranura
    cuando las dos visitas del caso quedaron cerradas, y descartaba la otra en
    silencio: 8 ordenes solo en abril de UIO. Aca la ultima visita ocupa el
    cierre cuando el caso figura resuelto, la primera restante es la
    evaluacion, y si el caso tuvo mas de dos visitas -- 100 casos en el
    historico -- las sobrantes se nombran en OBSERVACIONES en vez de
    desaparecer (I-7).

    Verificado contra los archivos reales: un caso atendido una sola vez y
    resuelto ocupa la ranura de CIERRE, con NINGUNO en todo el lado evaluacion.
    """
    fila = {c: None for c in COLS}
    fila["# OT"] = aviso_row["aviso"] if aviso_row else (ordenes[0]["aviso"] if ordenes else None)

    ordenadas = sorted(ordenes, key=lambda o: (o["fecha_atencion"] or date.min, o["id_industec"]))
    cerradas = [o for o in ordenadas if o["estado_ot"] == "CERRADA"]
    cierre = cerradas[-1] if cerradas else None
    restantes = [o for o in ordenadas if o is not cierre]
    evaluacion = restantes[0] if restantes else None
    sobrantes = restantes[1:]

    fila["LOCAL"] = (aviso_row["local_maestro"] if aviso_row else None) or         next((o["local_codigo"] for o in ordenadas if o["local_codigo"]), None)

    # Equipo, marca y estado del equipo salen de cualquier visita del caso: el
    # tecnico los anota en la que le toco, no siempre en la que ocupa la ranura.
    for o in reversed(ordenadas):
        for eq in (o.get("_equipos") or []):
            fila["EQUIPO"] = fila["EQUIPO"] or mayus(limpiar(eq.get("equipo")))
            fila["MARCA"] = fila["MARCA"] or mayus(limpiar(eq.get("marca")))
            fila["ESTATUS DEL EQUIPO"] = fila["ESTATUS DEL EQUIPO"] or eq.get("estado_equipo")

    # La fecha de inicio del caso es la notificacion de SAP, no la primera
    # visita: son distintas en 923 filas comparadas.
    if aviso_row:
        fila["FECHA DE INICIO"] = aviso_row["fecha_notificacion"]
    elif ordenadas:
        fila["FECHA DE INICIO"] = ordenadas[0]["fecha_atencion"]

    if evaluacion:
        fila["TECNICO EVALUACION"] = mayus(limpiar(evaluacion["tecnico_nombre"]))
        fila["TRABAJO REALIZADO EVALUACION"] = limpiar(evaluacion["actividades"])
        fila["REPUESTO"] = limpiar(evaluacion["repuestos"])
        fila["#OT INDUSTEC EVALUACION"] = id_industec_original(evaluacion["id_industec"],
                                                              evaluacion["local_codigo"])
        fila["FECHA EVALUACION"] = evaluacion["fecha_atencion"]
    if not fila["EQUIPO"] and aviso_row:
        fila["EQUIPO"] = mayus(limpiar(aviso_row["descripcion"]))

    if cierre:
        fila["TECNICO CIERRE"] = mayus(limpiar(cierre["tecnico_nombre"]))
        fila["TRABAJO REALIZADO CIERRE"] = limpiar(cierre["actividades"]) or limpiar(cierre["observaciones"])
        fila["#OT INDUSTEC CIERRE"] = id_industec_original(cierre["id_industec"], cierre["local_codigo"])
        fila["FECHA CIERRE"] = cierre["fecha_atencion"]
        fila["REQUERIMIENTO A TIEMPO"] = mayus(cierre["atiempo"])
        fila["CALIFICACION SATISFACCIÓN"] = cierre["satisfaccion"]

    # ESTATUS SAP no se inventa: el campo importado (`estatus_aviso`, del tipo
    # "MECE ORAS") pertenece a otro vocabulario y no es lo que va en esta
    # columna. Mientras no se importe "Estatus 2 de la Orden", va NINGUNO.
    estatus = (aviso_row or {}).get("estatus_aviso")
    tokens = {t for t in str(estatus or "").upper().split() if t in VOCABULARIO_ESTATUS_SAP}
    fila["ESTATUS SAP"] = " ".join(sorted(tokens)) if tokens else None

    observaciones = (limpiar(evaluacion["observaciones"]) if evaluacion else None) or \
                    (limpiar(cierre["observaciones"]) if cierre else None)
    if sobrantes:
        extra = "OTRAS VISITAS DEL CASO: " + ", ".join(
            id_industec_original(o["id_industec"], o["local_codigo"]) for o in sobrantes)
        observaciones = f"{observaciones} · {extra}" if observaciones else extra
    fila["OBSERVACIONES"] = observaciones

    # Criterio unico de cierre (T2.1): manda estatus_general de SAP. Solo si el
    # aviso cae fuera de la cobertura del catalogo se usa la propia orden.
    estatus_general = (aviso_row or {}).get("estatus_general")
    if estatus_general == "CERRADO":
        fila["ESTADO"] = "CERRADA"
    elif estatus_general in ("ABIERTO", "TRATAMIENTO"):
        fila["ESTADO"] = "ABIERTA"
    else:
        fila["ESTADO"] = "CERRADA" if cierre else "ABIERTA"
    return fila


def construir_filas(cnx, zona, anio, mes):
    """Filas del mes, con el corte historico: nada posterior al fin de mes."""
    mes_ini, mes_fin = limites_mes(anio, mes)
    cur = cnx.cursor(dictionary=True)

    # Que casos pertenecen al mes: los notificados en el mes, mas los que ya
    # venian abiertos cuando el mes empezo. La apertura es PUNTUAL, leida de
    # `fecha_cierre_tecnico` (SAP), no del estado de hoy: medido sobre los 24
    # archivos reales, 1.355 de las 1.356 filas de arrastre corresponden a
    # casos que hoy figuran cerrados pero seguian abiertos ese mes.
    #
    # El filtro de zona compara CONCAT(centro_coste,'EC') porque SAP guarda el
    # centro sin el sufijo ('R011') y el maestro con el ('R011EC'). Comparados
    # crudos no cruza NI UNA fila: el consolidador de T1.11 se quedaba solo con
    # los avisos que tenian orden de INDUSTEC (3.482 de 6.450) y perdia los
    # otros 2.968. Los dos centros que aun asi no cruzan (BS17, CN42) salen por
    # `locales_alias`.
    cur.execute("""SELECT a.aviso, a.fecha_notificacion, a.descripcion, a.centro_coste,
                          a.estatus_aviso, a.estatus_general, a.fecha_cierre_tecnico,
                          COALESCE(al.local_codigo, CONCAT(a.centro_coste, 'EC')) AS local_maestro
                   FROM avisos_sap a
                   LEFT JOIN locales_alias al ON al.alias_texto = CONCAT(a.centro_coste, 'EC')
                   LEFT JOIN locales l ON l.local_codigo = COALESCE(al.local_codigo,
                                                                    CONCAT(a.centro_coste, 'EC'))
                   LEFT JOIN (SELECT aviso, MIN(CASE WHEN estado_ot='CERRADA' THEN fecha_atencion END) AS cierre_ind,
                                     COUNT(*) AS visitas
                              FROM ots
                              WHERE en_cuarentena=0 AND modulo='CORRECTIVO' AND aviso IS NOT NULL
                              GROUP BY aviso) v ON v.aviso = a.aviso
                   WHERE (l.zona = %s
                          OR a.aviso IN (SELECT aviso FROM ots WHERE zona=%s AND aviso IS NOT NULL))
                     -- Solo lo que es alcance de INDUSTEC. El plan de la
                     -- administracion lista Mant. Correctivo: 5.086 de sus
                     -- 5.133 filas. Los avisos de "Menaje (Consumibles)" o
                     -- "Mant. Constructivo" son de otro proveedor y meterlos
                     -- inflaba el archivo al doble.
                     AND (UPPER(TRIM(a.descripcion)) = 'MANT. CORRECTIVO' OR v.visitas IS NOT NULL)
                     AND ((a.fecha_notificacion >= %s AND a.fecha_notificacion < %s)
                          -- Arrastre: el caso sigue en el plan mientras INDUSTEC
                          -- no lo haya cerrado, y ademas o seguia abierto en SAP
                          -- o viene del mes inmediato anterior. Medido contra los
                          -- 24 archivos: 89% de precision y 85% de cobertura.
                          OR (a.fecha_notificacion < %s
                              AND (v.cierre_ind IS NULL OR v.cierre_ind >= %s)
                              AND (a.fecha_cierre_tecnico IS NULL
                                   OR a.fecha_cierre_tecnico >= %s
                                   OR a.fecha_notificacion >= %s)))
                   ORDER BY a.fecha_notificacion""",
                (zona, zona, mes_ini, mes_fin, mes_ini, mes_ini, mes_ini, mes_previo(mes_ini)))
    avisos = cur.fetchall()

    # Sin corte por fin de mes: el archivo mensual de la administracion es un
    # documento vivo que se sigue completando despues (el estado actual acierta
    # 95,6% contra el archivo real de agosto; el estado al cierre del mes, 93,4%).
    cur.execute("""SELECT * FROM ots
                   WHERE zona=%s AND en_cuarentena=0 AND modulo='CORRECTIVO'
                     AND fecha_atencion IS NOT NULL
                   ORDER BY fecha_atencion""", (zona,))
    ots_rows = cur.fetchall()
    cur.close()

    equipos = cargar_equipos(cnx, [o["id_industec"] for o in ots_rows])
    for o in ots_rows:
        o["_equipos"] = equipos.get(o["id_industec"], [])

    por_aviso, sin_aviso = {}, []
    for o in ots_rows:
        if o["aviso"]:
            por_aviso.setdefault(o["aviso"], []).append(o)
        else:
            sin_aviso.append(o)

    filas, cubiertos = [], set()

    for a in avisos:
        cubiertos.add(a["aviso"])
        filas.append(_armar_fila_hist(a, por_aviso.get(a["aviso"], [])))

    # Casos sin catalogo SAP (fuera de la ventana ene-ago 2026): el unico
    # criterio disponible es la fecha de atencion de la propia orden.
    for aviso, ordenes in por_aviso.items():
        if aviso in cubiertos:
            continue
        if any(mes_ini <= o["fecha_atencion"] < mes_fin for o in ordenes):
            filas.append(_armar_fila_hist(None, ordenes))

    for o in sin_aviso:
        if mes_ini <= o["fecha_atencion"] < mes_fin:
            fila = _armar_fila_hist(None, [o])
            fila["# OT"] = "NINGUNO"   # I-7: la fila existe aunque el aviso no
            filas.append(fila)

    filas.sort(key=lambda f: (f.get("FECHA DE INICIO") or date.min, str(f.get("# OT"))))
    return filas, len(avisos)


def escribir_plan(zona, anio, mes, filas):
    plantilla = PLANTILLAS[zona]
    if not plantilla.exists():
        sys.exit(f"ABORTA: no existe la plantilla real de {zona}: {plantilla}")

    wb = openpyxl.load_workbook(plantilla)
    ws = wb.worksheets[0]           # CNLJ la llama "PLAN SEMANAL", no "Hoja1"
    filas_plantilla = ws.max_row - 1
    ultima = 1 + max(len(filas), 1)

    # Estilos modelo de la primera fila de datos, para extender hacia abajo
    modelo = [copy(ws.cell(2, j)._style) for j in range(1, len(COLS) + 1)]

    for i, fila in enumerate(filas):
        r = 2 + i
        fila["#"] = i + 1
        for j, nombre_col in enumerate(COLS, start=1):
            valor = fila.get(nombre_col)
            if valor in (None, "") and get_column_letter(j) in COLS_NINGUNO:
                valor = "NINGUNO"
            celda = ws.cell(r, j)
            celda.value = valor
            if r > filas_plantilla + 1:
                celda._style = copy(modelo[j - 1])

    # Sobran filas de la plantilla cuando el mes trae menos casos que septiembre
    for r in range(2 + len(filas), filas_plantilla + 2):
        for j in range(1, len(COLS) + 1):
            ws.cell(r, j).value = None

    # La tabla y el formato condicional deben cubrir exactamente las filas
    # escritas, o Excel abre el archivo como danado
    for nombre in list(ws.tables):
        ws.tables[nombre].ref = f"A1:{get_column_letter(len(COLS))}{ultima}"
    reglas = list(ws.conditional_formatting)
    ws.conditional_formatting = ConditionalFormattingList()
    for cf in reglas:
        columnas = sorted({str(rango).split(":")[0].rstrip("0123456789") for rango in cf.sqref.ranges})
        nuevo = " ".join(f"{col}2:{col}{ultima}" for col in columnas)
        for regla in cf.rules:
            ws.conditional_formatting.add(nuevo, regla)

    destino = SALIDA / CARPETA_ZONA[zona] / "PLANES MENSUALES"
    destino.mkdir(parents=True, exist_ok=True)
    ruta = destino / f"{anio}-{mes:02d} {MESES[mes-1]} (generado agente).xlsx"
    wb.save(ruta)
    return ruta


def verificar(cnx, zona, anio, mes, filas):
    """I-10: ninguna orden correctiva del mes puede quedar fuera del archivo."""
    mes_ini, mes_fin = limites_mes(anio, mes)
    cur = cnx.cursor()
    cur.execute("""SELECT id_industec FROM ots
                   WHERE zona=%s AND en_cuarentena=0 AND modulo='CORRECTIVO'
                     AND fecha_atencion >= %s AND fecha_atencion < %s""", (zona, mes_ini, mes_fin))
    esperadas = {r[0] for r in cur.fetchall()}
    cur.close()

    # el archivo lleva el id sin el sufijo EC del local; se compara normalizado
    def clave(x):
        return str(x).replace("EC-", "-")

    escritas = set()
    for f in filas:
        for col in ("#OT INDUSTEC EVALUACION", "#OT INDUSTEC CIERRE"):
            if f.get(col):
                escritas.add(clave(f[col]))
    # Las visitas que no caben en las dos ranuras del formato quedan nombradas
    # en OBSERVACIONES; siguen siendo trazables y cuentan como presentes.
    texto_observaciones = " ".join(str(f.get("OBSERVACIONES") or "") for f in filas)
    faltan = {e for e in esperadas
              if clave(e) not in escritas and clave(e) not in clave(texto_observaciones)}
    return esperadas, faltan


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--zona", action="append", choices=list(PLANTILLAS))
    ap.add_argument("--desde", help="AAAA-MM")
    ap.add_argument("--hasta", help="AAAA-MM")
    args = ap.parse_args()

    zonas = args.zona or list(PLANTILLAS)

    def par(s):
        return tuple(int(x) for x in s.split("-")) if s else None

    cnx = conectar()
    meses = meses_con_datos(cnx, par(args.desde), par(args.hasta))
    smin, smax, stotal = cobertura_sap(cnx)
    print(f"Cobertura real del catalogo SAP (I-12): {smin} a {smax} ({stotal} avisos)")
    print(f"Meses a generar: {meses[0][0]}-{meses[0][1]:02d} a {meses[-1][0]}-{meses[-1][1]:02d} "
          f"({len(meses)}) x zonas {zonas}\n")

    resumen, huerfanas_total = [], 0
    for zona in zonas:
        for anio, mes in meses:
            filas, n_avisos = construir_filas(cnx, zona, anio, mes)
            esperadas, faltan = verificar(cnx, zona, anio, mes, filas)
            if faltan:
                huerfanas_total += len(faltan)
                print(f"  !! {zona} {anio}-{mes:02d}: {len(faltan)} ordenes del mes no quedaron "
                      f"en ninguna fila: {sorted(faltan)[:5]}")
            ruta = escribir_plan(zona, anio, mes, filas)
            resumen.append((zona, anio, mes, len(filas), len(esperadas), len(faltan)))
            print(f"  {zona} {anio}-{mes:02d}: {len(filas):4d} filas · {len(esperadas):4d} ordenes "
                  f"del mes · {ruta.name}")
    cnx.close()

    print("\n=== Resumen ===")
    print(f"Archivos generados: {len(resumen)}")
    print(f"Filas totales: {sum(r[3] for r in resumen)}")
    print(f"Ordenes correctivas cubiertas: {sum(r[4] for r in resumen)}")
    if huerfanas_total:
        print(f"ABORTA (I-10): {huerfanas_total} ordenes del mes sin fila en su archivo")
        sys.exit(1)
    print("Verificacion I-10: toda orden correctiva del mes aparece en el archivo de su mes")


if __name__ == "__main__":
    main()
