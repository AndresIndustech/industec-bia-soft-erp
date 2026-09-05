"""
Historico de correctivos reconstruido desde las ordenes de trabajo.

La fuente de verdad son las OTs recibidas y el export de SAP. De los archivos
de la administracion se toma **el formato y el estilo de llenado** -- las 22
columnas, los estilos, la tabla, el literal NINGUNO, las mayusculas -- y
ademas lo unico que solo existe alli: su seguimiento del caso (OBSERVACIONES,
PRESUPUESTO y, cuando SAP no cubre el mes, como se cerro). Ningun dato de
hecho se copia de esos archivos: llevados a mano, arrastran errores.

De donde sale cada columna
--------------------------
| Columna                        | Fuente                                     |
|--------------------------------|--------------------------------------------|
| # OT                           | aviso de la OT, o del catalogo SAP         |
| TECNICO EVALUACION / CIERRE    | OT                                         |
| LOCAL                          | centro de coste SAP resuelto contra el     |
|                                | maestro; si no, el local de la OT          |
| FECHA DE INICIO                | notificacion SAP; si no, la primera visita |
| EQUIPO                         | denominacion del activo en SAP; si no, el  |
|                                | equipo escrito en la OT                    |
| MARCA / ESTATUS DEL EQUIPO     | OT (ultima visita)                         |
| TRABAJO REALIZADO EVAL/CIERRE  | OT                                         |
| REPUESTO                       | OT                                         |
| ESTATUS SAP                    | SAP, campo "Estatus 2 de la Orden"         |
| PRESUPUESTO                    | seguimiento de la administracion           |
| OBSERVACIONES                  | seguimiento de la administracion, mas las  |
|                                | visitas que no caben en las dos ranuras    |
| #OT INDUSTEC EVAL/CIERRE       | OT                                         |
| FECHA EVALUACION / CIERRE      | OT                                         |
| REQUERIMIENTO A TIEMPO / CALIF | OT                                         |
| ESTADO                         | siempre CERRADA; el motivo va en OBSERVAC. |

Todo caso queda CERRADO, porque asi funciona la operacion: el local que sigue
necesitando algo levanta un requerimiento nuevo, no revive el viejo. Lo que
cambia de caso a caso es CON QUE se sostiene ese cierre, y eso es lo que se
anota: una visita de cierre registrada, el cierre tecnico de SAP, un aviso
cerrado sin orden de trabajo, una intervencion que no hizo falta, el
seguimiento de la administracion, o -- cuando no queda ninguna otra evidencia --
falta de atencion. Los cierres deducidos van rotulados "CIERRE INFERIDO" en la
celda, para que nadie confunda un cierre registrado con uno derivado (I-7).

Lo que la evidencia NO sostiene, y por eso no se implemento: el autocierre de
Grupo KFC a los 7 dias no deja ninguna huella en los datos (el dia 7 tiene 193
cierres contra 187,8 de media de sus vecinos, ratio 1,03x: esta sobre la
tendencia, no sobre un escalon), el 23,1% de los casos tarda mas de una semana
y hay cola hasta 114 dias. Y la hipotesis de que un caso se cierre bajo otro
numero tampoco se separa del ruido: un grupo de control de 613 casos ya
cerrados tiene la misma tasa de "orden posterior en el mismo local" que los
casos sin cierre (exceso entre -3,8 y +1,4 puntos), porque eso mide el trafico
del local y no la migracion del caso.

Cada archivo mensual refleja lo que se sabia al cerrar ese mes: ninguna orden
posterior al fin de mes entra.

Uso:
    .venv/Scripts/python.exe scripts/t2_historico_correctivos.py [--zona UIO] [--desde 2025-09] [--hasta 2026-09]
"""
import argparse
import re
import sys
from collections import defaultdict
from copy import copy
from datetime import date
from pathlib import Path

import openpyxl
from openpyxl.formatting.formatting import ConditionalFormattingList
from openpyxl.utils import get_column_letter

sys.path.insert(0, str(Path(__file__).parent))
from agente2_consolidador import COLS, conectar, id_industec_original, mayus
from t2_seguimiento_admin import cargar_seguimiento

ORIGEN = Path(r"D:\RESPALDOS\_ORIGEN_DRIVE\GESTION DE OTS INDUSTEC\2026\PLANES SEMANALES")
SALIDA = Path(r"D:\INDUSTECH IA\SALIDAS IA\MANTENIMIENTO\MANTENIMIENTOS CORRECTIVOS")

# Plantilla real de cada zona: se abre SOLO para heredar formato (I-3 / I-4)
PLANTILLAS = {
    "UIO": ORIGEN / "PLAN DE TRABAJO _ ZONA UIO" / "PLAN SEGUIMIENTO OTS UIO _ SEPTIEMBRE.xlsx",
    "LARB": ORIGEN / "PLAN DE TRABAJO _ ZONA LARB" / "PLAN SEGUIMIENTO OTS LARB _ SEPTIEMBRE.xlsx",
    "CNLJ": ORIGEN / "PLAN DE TRABAJO _ ZONA C-L" / "PLAN SEGUIMIENTO OTS CNLJ _ SEPTIEMBRE.xlsx",
}
CARPETA_ZONA = {"UIO": "ZONA UIO", "LARB": "ZONA LARB", "CNLJ": "ZONA CUENCA LOJA"}

MESES = ["ENERO", "FEBRERO", "MARZO", "ABRIL", "MAYO", "JUNIO", "JULIO",
         "AGOSTO", "SEPTIEMBRE", "OCTUBRE", "NOVIEMBRE", "DICIEMBRE"]

# Estilo de llenado copiado del archivo real: el literal NINGUNO va en las ocho
# columnas del lado evaluacion (medido sobre 5.371 filas), y el lado cierre
# queda vacio cuando esa fase no ocurrio.
COLS_NINGUNO = {"C", "I", "J", "K", "L", "N", "P", "Q"}

# El tecnico escribe "-", "N/A" o "NINGUNA" para decir que no hubo nada.
PLACEHOLDERS = {"-", "--", "---", ".", "N/A", "NA", "N/O", "NO", "NINGUNA", "NINGUNO",
                "SIN NOVEDAD", "S/N", "SN", "X"}


def limpiar(v):
    if v is None:
        return None
    s = str(v).strip().lstrip("-*\u2022 ").strip()
    return None if not s or s.upper() in PLACEHOLDERS else s


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


def cargar_todo(cnx):
    """Una sola lectura de la base; despues se corta por zona y mes en memoria."""
    cur = cnx.cursor(dictionary=True)
    cur.execute("""SELECT * FROM ots
                   WHERE en_cuarentena=0 AND modulo='CORRECTIVO'
                     AND fecha_atencion IS NOT NULL
                   ORDER BY fecha_atencion, id_industec""")
    ots = cur.fetchall()

    cur.execute("""SELECT id_industec, equipo, marca, estado_equipo, orden
                   FROM ot_equipos ORDER BY id_industec, orden""")
    equipos = defaultdict(list)
    for r in cur.fetchall():
        equipos[r["id_industec"]].append(r)
    for o in ots:
        o["_equipos"] = equipos.get(o["id_industec"], [])

    # El centro de coste llega de SAP sin sufijo ('R011') y el maestro lo lleva
    # con el ('R011EC'): comparados crudos no cruza ni una fila. Los dos codigos
    # que aun asi no cruzan (BS17, CN42) salen por locales_alias.
    cur.execute("""SELECT a.*, COALESCE(al.local_codigo, CONCAT(a.centro_coste,'EC')) AS local_maestro,
                          l.zona AS zona_local
                   FROM avisos_sap a
                   LEFT JOIN locales_alias al ON al.alias_texto = CONCAT(a.centro_coste,'EC')
                   LEFT JOIN locales l ON l.local_codigo = COALESCE(al.local_codigo,
                                                                    CONCAT(a.centro_coste,'EC'))""")
    avisos = {r["aviso"]: r for r in cur.fetchall()}
    cur.execute("""SELECT MIN(fecha_notificacion) AS ini, MAX(fecha_notificacion) AS fin
                   FROM avisos_sap""")
    global COBERTURA_SAP
    rango = cur.fetchone()          # cursor de diccionario: desempaquetar por clave
    COBERTURA_SAP = (rango["ini"], rango["fin"])
    cur.close()
    return ots, avisos


def _sin_tildes(texto):
    tabla = str.maketrans("ÁÉÍÓÚÜÑ", "AEIOUUN")
    return str(texto or "").upper().translate(tabla)


# Formulas con las que el tecnico deja constancia de que no hubo que intervenir.
# Deliberadamente estrechas: "SIN NOVEDAD" y "EQUIPO OPERATIVO" quedan FUERA
# porque aparecen en 2.171 ordenes y casi siempre cierran un trabajo que si se
# hizo ("el equipo queda operativo luego de la intervencion"). Tomarlas como
# "no se requirio atencion" habria etiquetado mal un tercio del corpus.
SIN_INTERVENCION = re.compile(
    r"NO (SE )?(REQUIRIO|REQUIERE|FUE NECESARI|AMERITA|ES NECESARI)"
    r"|FALSA ALARMA"
    r"|NO SE ENCONTRO (NINGUNA )?(NOVEDAD|FALLA)"
    r"|SOPORTE (VIA )?(TELEFONIC|LLAMADA)|VIA TELEFONIC")

# Como la administracion redacta ella misma un cierre sin trabajo, en sus planes
SEGUIMIENTO_EXPLICA_CIERRE = re.compile(
    r"FALTA DE OK|NO AUTORIZAD|SIN AUTORIZACION"
    r"|NO SE CONSIGUE EL REPUESTO|SE CIERRA POR|CERRADO POR")

# Motivos posibles, del mas sostenido por evidencia al menos sostenido
MOTIVO_VISITA_CIERRE = "visita de cierre registrada"
MOTIVO_CIERRE_SAP = "cierre tecnico en SAP"
MOTIVO_SAP_SIN_ORDEN = "cerrado en SAP sin orden de trabajo"
MOTIVO_SIN_INTERVENCION = "no se requirio intervencion tecnica"
MOTIVO_SEGUIMIENTO = "cierre explicado en el seguimiento de la administracion"
MOTIVO_SIN_COBERTURA = "sin constancia de cierre: el catalogo SAP no cubre esa fecha"
MOTIVO_SAP_LO_DA_ABIERTO = "falta de atencion (SAP lo reportaba abierto al corte)"
MOTIVO_FALTA_ATENCION = "cerrado por falta de atencion"

# Rango real que cubre el catalogo de avisos; lo fija cargar_todo() al leer la
# base. Sin declararlo, los 609 casos de 2025 que el catalogo no alcanza se
# etiquetarian como "falta de atencion", afirmando sobre la operacion algo que
# en realidad solo dice que no hay catalogo para esa fecha (I-12).
COBERTURA_SAP = (None, None)


def motivo_cierre(aviso_row, ordenes, seguimiento_admin):
    """Por que se dio por cerrado este caso, y con que evidencia.

    Todo caso del historico queda CERRADO -- es la realidad operativa: el local
    que sigue necesitando algo levanta un requerimiento nuevo, no revive el
    viejo. Lo que cambia de caso a caso es CON QUE se sostiene ese cierre, y eso
    es lo que se anota. Devuelve (motivo, texto_para_observaciones); el texto es
    None cuando el cierre consta por si solo y no hace falta explicarlo.

    Las dos primeras ramas son constancia documental. Las demas son inferencia y
    van rotuladas como tal en la celda: quien lea el archivo tiene que poder
    distinguir un cierre registrado de uno deducido (I-7).
    """
    if any(o["estado_ot"] == "CERRADA" for o in ordenes):
        return MOTIVO_VISITA_CIERRE, None

    cierre_sap = (aviso_row or {}).get("fecha_cierre_tecnico")
    if cierre_sap:
        return MOTIVO_CIERRE_SAP, f"CIERRE INFERIDO: cierre tecnico en SAP el {cierre_sap:%d/%m/%Y}"

    if aviso_row and aviso_row.get("estatus_general") == "CERRADO":
        # 729 avisos cerraron sin haber generado nunca orden SAP: es el cierre
        # administrativo, el caso que se resolvio o se descarto sin trabajo.
        if not aviso_row.get("orden_sap"):
            return MOTIVO_SAP_SIN_ORDEN, "CIERRE INFERIDO: cerrado en SAP sin orden de trabajo"
        return MOTIVO_CIERRE_SAP, "CIERRE INFERIDO: aviso cerrado en SAP, sin fecha de cierre tecnico"

    texto = _sin_tildes(" ".join(str(o.get("actividades") or "") + " " + str(o.get("observaciones") or "")
                                for o in ordenes))
    if SIN_INTERVENCION.search(texto):
        return MOTIVO_SIN_INTERVENCION, "CIERRE INFERIDO: no se requirio intervencion tecnica"

    anotado = _sin_tildes((seguimiento_admin or {}).get("observaciones"))
    if anotado and SEGUIMIENTO_EXPLICA_CIERRE.search(anotado):
        return MOTIVO_SEGUIMIENTO, None       # su propio texto ya explica el cierre

    if aviso_row and aviso_row.get("estatus_general") in ("ABIERTO", "TRATAMIENTO"):
        corte = COBERTURA_SAP[1]
        return MOTIVO_SAP_LO_DA_ABIERTO, (
            "CIERRE INFERIDO: cerrado por falta de atencion; SAP lo reportaba abierto"
            + (f" al {corte:%d/%m/%Y}" if corte else ""))

    # Fuera de la ventana del catalogo no hay con que ver el cierre: decirlo es
    # distinto -- y mas honesto -- que atribuirlo a falta de atencion.
    inicio, fin = COBERTURA_SAP
    fecha = ordenes[0]["fecha_atencion"] if ordenes else None
    if aviso_row is None and inicio and fecha and not (inicio <= fecha <= fin):
        return MOTIVO_SIN_COBERTURA, (
            f"CIERRE INFERIDO: sin constancia de cierre; el catalogo SAP solo cubre "
            f"{inicio:%d/%m/%Y} a {fin:%d/%m/%Y}")

    return MOTIVO_FALTA_ATENCION, "CIERRE INFERIDO: cerrado por falta de atencion"


def resuelto_al(aviso_row, ordenes, corte):
    """Estaba el caso cerrado a esa fecha? Dos evidencias independientes y
    fechadas: el cierre tecnico de SAP y la visita de cierre de INDUSTEC."""
    cierre_sap = (aviso_row or {}).get("fecha_cierre_tecnico")
    if cierre_sap and cierre_sap < corte:
        return True
    return any(o["estado_ot"] == "CERRADA" and o["fecha_atencion"] < corte for o in ordenes)


def seguia_abierto(aviso_row, ordenes, corte):
    """Hay evidencia POSITIVA de que el caso seguia abierto a esa fecha?

    No basta con que no conste el cierre. 840 avisos figuran CERRADO en SAP sin
    fecha de cierre tecnico: no se sabe cuando se cerraron, pero si que
    terminaron cerrados, y arrastrarlos por un ano de planes fabricaria un
    backlog que nunca existio. Se arrastra solo lo que se puede sostener:
      - el aviso sigue ABIERTO o en TRATAMIENTO en SAP, o
      - el caso esta fuera del catalogo y tiene una orden abierta sin cierre
        posterior (unico rastro disponible en los meses de 2025).
    Lo demas aparece en los meses en que tuvo actividad y no se arrastra.
    """
    if resuelto_al(aviso_row, ordenes, corte):
        return False
    if aviso_row and aviso_row.get("estatus_general"):
        return aviso_row["estatus_general"] in ("ABIERTO", "TRATAMIENTO")
    return any(o["estado_ot"] == "ABIERTA" and o["fecha_atencion"] < corte for o in ordenes)


def repartir_visitas(ordenes):
    """Reparte las visitas del caso en las dos ranuras del formato.

    `estado_ot` NO dice si el caso sigue abierto: dice como quedo ESA visita.
    Medido sobre el corpus, 'ABIERTA' es la visita de evaluacion que diagnostico
    y pidio repuestos -- el 95,9% de ellas declara repuestos, contra el 51,8% de
    las 'CERRADA' -- y el patron evaluacion->cierre cubre 1.084 de los 1.334
    casos de dos visitas. Usarla como estado del caso daba 98,1% de falsos
    positivos: de 1.964 ordenes 'ABIERTA', 1.648 quedan desmentidas por un
    cierre posterior o por SAP, y el backlog real son 38.

    De ahi el reparto: con dos o mas visitas, la primera evalua y la ULTIMA
    cierra, sin mirar su estado. Con una sola, decide su estado; y si ese campo
    no existia todavia (536 ordenes de sep-oct 2025, antes de que el formulario
    lo trajera) decide si pidio repuestos, que es el rasgo de la evaluacion.
    """
    if not ordenes:
        return None, None, []
    if len(ordenes) >= 2:
        return ordenes[0], ordenes[-1], ordenes[1:-1]

    unica = ordenes[0]
    if unica["estado_ot"] == "CERRADA":
        return None, unica, []
    if unica["estado_ot"] == "ABIERTA":
        return unica, None, []
    return (unica, None, []) if limpiar(unica.get("repuestos")) else (None, unica, [])


def armar_fila(aviso_row, ordenes, seguimiento_admin):
    """Un caso = una fila, con dos ranuras: evaluacion y cierre.

    Las visitas que no caben en las dos ranuras se nombran en OBSERVACIONES:
    ninguna orden se pierde.
    """
    fila = {c: None for c in COLS}
    fila["# OT"] = (aviso_row or {}).get("aviso") or (ordenes[0]["aviso"] if ordenes else None)

    evaluacion, cierre, sobrantes = repartir_visitas(ordenes)

    fila["LOCAL"] = (aviso_row or {}).get("local_maestro") or \
        next((o["local_codigo"] for o in ordenes if o["local_codigo"]), None)

    # La denominacion del activo en SAP es la que usa el plan
    # ('005170_I_MAQYEQ_FREIDORA DE PAPAS'); lo que el tecnico escribio en la
    # orden ('FREIDORA') solo se usa si SAP no trae el equipo.
    fila["EQUIPO"] = mayus(limpiar((aviso_row or {}).get("equipo_denominacion")))
    for o in reversed(ordenes):
        for eq in o["_equipos"]:
            fila["EQUIPO"] = fila["EQUIPO"] or mayus(limpiar(eq.get("equipo")))
            fila["MARCA"] = fila["MARCA"] or mayus(limpiar(eq.get("marca")))
            fila["ESTATUS DEL EQUIPO"] = fila["ESTATUS DEL EQUIPO"] or eq.get("estado_equipo")

    if aviso_row:
        fila["FECHA DE INICIO"] = aviso_row.get("fecha_notificacion")
    if not fila["FECHA DE INICIO"] and ordenes:
        fila["FECHA DE INICIO"] = ordenes[0]["fecha_atencion"]

    if evaluacion:
        fila["TECNICO EVALUACION"] = mayus(limpiar(evaluacion["tecnico_nombre"]))
        fila["TRABAJO REALIZADO EVALUACION"] = limpiar(evaluacion["actividades"])
        fila["REPUESTO"] = limpiar(evaluacion["repuestos"])
        fila["#OT INDUSTEC EVALUACION"] = id_industec_original(evaluacion["id_industec"],
                                                              evaluacion["local_codigo"])
        fila["FECHA EVALUACION"] = evaluacion["fecha_atencion"]

    if cierre:
        fila["TECNICO CIERRE"] = mayus(limpiar(cierre["tecnico_nombre"]))
        fila["TRABAJO REALIZADO CIERRE"] = limpiar(cierre["actividades"]) or limpiar(cierre["observaciones"])
        fila["#OT INDUSTEC CIERRE"] = id_industec_original(cierre["id_industec"], cierre["local_codigo"])
        fila["FECHA CIERRE"] = cierre["fecha_atencion"]
        fila["REQUERIMIENTO A TIEMPO"] = mayus(cierre["atiempo"])
        fila["CALIFICACION SATISFACCIÓN"] = cierre["satisfaccion"]

    # ESTATUS SAP: campo "Estatus 2 de la Orden" (REDE, MEDE, APRO, MSOL...).
    # Es el vocabulario que usa el plan; el que ya estaba importado
    # (estatus_aviso, "MECE ORAS") es otro campo y no va en esta columna.
    estatus = " ".join(filter(None, [(aviso_row or {}).get("estatus_orden_2"),
                                     (aviso_row or {}).get("estatus_aviso_2")]))
    tokens = sorted(set(estatus.upper().split()))
    fila["ESTATUS SAP"] = " ".join(tokens) if tokens else None

    # Todo caso del historico queda CERRADO. Un caso no se queda abierto para
    # siempre: el local que sigue necesitando algo levanta un requerimiento
    # nuevo. Lo que cambia es con que se sostiene el cierre, y eso se anota.
    motivo, nota = motivo_cierre(aviso_row, ordenes, seguimiento_admin)
    fila["ESTADO"] = "CERRADA"
    fila["_motivo_cierre"] = motivo

    # Lo unico que se toma de los archivos de la administracion: su seguimiento.
    fila["PRESUPUESTO"] = (seguimiento_admin or {}).get("presupuesto")
    partes = [(seguimiento_admin or {}).get("observaciones"), nota]
    if sobrantes:
        partes.append("OTRAS VISITAS DEL CASO: " + ", ".join(
            id_industec_original(o["id_industec"], o["local_codigo"]) for o in sobrantes))
    fila["OBSERVACIONES"] = " · ".join(p for p in partes if p) or None
    return fila


def construir_filas(zona, anio, mes, ots, avisos, seguimiento):
    """Filas del mes, con corte historico: nada posterior al fin de mes."""
    mes_ini, mes_fin = limites_mes(anio, mes)

    # Se agrupa por (aviso, zona), no solo por aviso: hay numeros de aviso que
    # aparecen en dos zonas porque el tecnico transcribio mal el numero. Son
    # casos distintos, y agrupando por aviso a secas la orden de la segunda
    # zona desaparecia de su archivo (2 ordenes reales: LARB nov-25 y ene-26).
    por_caso, sin_aviso = defaultdict(list), []
    for o in ots:
        if o["fecha_atencion"] >= mes_fin:      # lo que se supo despues no entra
            continue
        (por_caso[(o["aviso"], o["zona"])].append(o) if o["aviso"] else sin_aviso.append(o))

    filas, cubiertos = [], set()
    for (aviso, zona_ot), ordenes in por_caso.items():
        aviso_row = avisos.get(aviso)
        if zona_ot != zona:
            continue
        cubiertos.add(aviso)
        visita_en_el_mes = any(mes_ini <= o["fecha_atencion"] < mes_fin for o in ordenes)
        notificado_en_el_mes = bool(aviso_row and aviso_row.get("fecha_notificacion")
                                    and mes_ini <= aviso_row["fecha_notificacion"] < mes_fin)
        # Un caso sigue en el plan mientras conste que seguia abierto; eso lo
        # dicen SAP y las propias ordenes, no el archivo de nadie.
        abierto = seguia_abierto(aviso_row, ordenes, mes_fin)
        if not (visita_en_el_mes or notificado_en_el_mes or abierto):
            continue
        filas.append(armar_fila(aviso_row, ordenes, seguimiento.buscar(aviso, anio, mes)))

    # Avisos que aun no tienen ninguna visita de INDUSTEC: son casos reales del
    # mes, pendientes de atender. Solo entran los del alcance del contrato.
    for aviso, aviso_row in avisos.items():
        if aviso in cubiertos or aviso_row.get("zona_local") != zona:
            continue
        f_notif = aviso_row.get("fecha_notificacion")
        if not f_notif or f_notif >= mes_fin:
            continue
        if str(aviso_row.get("descripcion") or "").strip().upper() != "MANT. CORRECTIVO":
            continue
        if f_notif < mes_ini and not seguia_abierto(aviso_row, [], mes_fin):
            continue
        filas.append(armar_fila(aviso_row, [], seguimiento.buscar(aviso, anio, mes)))

    # Ordenes sin aviso: 37 correctivos reales que no pueden desaparecer (I-7)
    for o in sin_aviso:
        if o["zona"] == zona and mes_ini <= o["fecha_atencion"] < mes_fin:
            fila = armar_fila(None, [o], None)
            fila["# OT"] = "NINGUNO"
            filas.append(fila)

    filas.sort(key=lambda f: (f.get("FECHA DE INICIO") or date.min, str(f.get("# OT"))))
    return filas


def escribir_plan(zona, anio, mes, filas):
    plantilla = PLANTILLAS[zona]
    if not plantilla.exists():
        sys.exit(f"ABORTA: no existe la plantilla real de {zona}: {plantilla}")

    wb = openpyxl.load_workbook(plantilla)
    ws = wb.worksheets[0]           # CNLJ la llama "PLAN SEMANAL", no "Hoja1"
    filas_plantilla = ws.max_row - 1
    ultima = 1 + max(len(filas), 1)
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

    for r in range(2 + len(filas), filas_plantilla + 2):
        for j in range(1, len(COLS) + 1):
            ws.cell(r, j).value = None

    # La tabla y el formato condicional deben cubrir exactamente lo escrito, o
    # Excel abre el archivo como danado
    for nombre in list(ws.tables):
        ws.tables[nombre].ref = f"A1:{get_column_letter(len(COLS))}{ultima}"
    reglas = list(ws.conditional_formatting)
    ws.conditional_formatting = ConditionalFormattingList()
    for cf in reglas:
        columnas = sorted({str(rango).split(":")[0].rstrip("0123456789") for rango in cf.sqref.ranges})
        for regla in cf.rules:
            ws.conditional_formatting.add(" ".join(f"{c}2:{c}{ultima}" for c in columnas), regla)

    destino = SALIDA / CARPETA_ZONA[zona] / "PLANES MENSUALES"
    destino.mkdir(parents=True, exist_ok=True)
    ruta = destino / f"{anio}-{mes:02d} {MESES[mes-1]} (generado agente).xlsx"
    wb.save(ruta)
    return ruta


def verificar(zona, anio, mes, ots, filas):
    """I-10: ninguna orden correctiva del mes puede quedar fuera del archivo."""
    mes_ini, mes_fin = limites_mes(anio, mes)
    esperadas = {o["id_industec"] for o in ots
                 if o["zona"] == zona and mes_ini <= o["fecha_atencion"] < mes_fin}

    def clave(x):
        return str(x).replace("EC-", "-")

    escritas = {clave(f[c]) for f in filas
                for c in ("#OT INDUSTEC EVALUACION", "#OT INDUSTEC CIERRE") if f.get(c)}
    texto = clave(" ".join(str(f.get("OBSERVACIONES") or "") for f in filas))
    faltan = {e for e in esperadas if clave(e) not in escritas and clave(e) not in texto}
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

    seguimiento = cargar_seguimiento()
    print(seguimiento.resumen())

    cnx = conectar()
    meses = meses_con_datos(cnx, par(args.desde), par(args.hasta))
    ots, avisos = cargar_todo(cnx)
    cur = cnx.cursor()
    cur.execute("""SELECT MIN(fecha_notificacion), MAX(fecha_notificacion), COUNT(*),
                          COUNT(estatus_orden_2), COUNT(equipo_denominacion) FROM avisos_sap""")
    smin, smax, stotal, s_est, s_eq = cur.fetchone()
    cur.close()
    cnx.close()

    print(f"Cobertura del catalogo SAP (I-12): {smin} a {smax} · {stotal} avisos · "
          f"{s_est} con ESTATUS SAP · {s_eq} con denominacion de equipo")
    print(f"Ordenes correctivas en la base: {len(ots)}")
    print(f"Meses: {meses[0][0]}-{meses[0][1]:02d} a {meses[-1][0]}-{meses[-1][1]:02d} "
          f"({len(meses)}) x {zonas}\n")

    resumen, huerfanas = [], 0
    motivos = defaultdict(int)
    for zona in zonas:
        for anio, mes in meses:
            filas = construir_filas(zona, anio, mes, ots, avisos, seguimiento)
            esperadas, faltan = verificar(zona, anio, mes, ots, filas)
            if faltan:
                huerfanas += len(faltan)
                print(f"  !! {zona} {anio}-{mes:02d}: {len(faltan)} ordenes sin fila: "
                      f"{sorted(faltan)[:5]}")
            for f in filas:
                motivos[f.get("_motivo_cierre") or "(sin clasificar)"] += 1
            ruta = escribir_plan(zona, anio, mes, filas)
            resumen.append((zona, anio, mes, len(filas), len(esperadas)))
            print(f"  {zona} {anio}-{mes:02d}: {len(filas):4d} filas · {len(esperadas):4d} "
                  f"ordenes del mes · {ruta.name}")

    print("\n=== Resumen ===")
    print(f"Archivos generados: {len(resumen)}")
    print(f"Filas totales: {sum(r[3] for r in resumen)}")
    print(f"Ordenes correctivas cubiertas: {sum(r[4] for r in resumen)} de {len(ots)}")
    print("\nCon que se sostiene el cierre de cada fila:")
    for motivo, cuantas in sorted(motivos.items(), key=lambda x: -x[1]):
        print(f"  {cuantas:>6}  {motivo}")
    if huerfanas:
        sys.exit(f"ABORTA (I-10): {huerfanas} ordenes del mes sin fila en su archivo")
    print("Verificacion I-10: toda orden correctiva del mes aparece en el archivo de su mes")


if __name__ == "__main__":
    main()
