"""
T2.5.2 - Los controles del formulario unico, como codigo.

Las reglas de ESPECIFICACION_OT_UNICA.md escritas una sola vez y probables. Las
usan tres piezas distintas, y por eso viven aqui y no dentro de ninguna de ellas:

  - el formulario nuevo (que las replica en PHP: mismas reglas, mismo fixture
    de prueba, para que no se separen con el tiempo);
  - la ingesta, que valida lo que llega del sistema viejo;
  - el auditor de calidad, que las corre sobre lo ya guardado.

CADA REGLA DICE QUE ERROR DEJA DE SER POSIBLE, no cual se vuelve improbable.
La distincion importa: `local` sale de una lista cerrada con clave foranea, asi
que un local inexistente no es improbable -- es irrepresentable. En cambio "el
tecnico eligio la freidora equivocada entre cuatro identicas" no lo resuelve
ninguna regla, y esta anotado como riesgo residual en la especificacion en vez
de fingir que se cubre.

SEVERIDADES, y que significa cada una en el formulario:
  BLOQUEA  el envio no se completa. Se reserva para lo que haria la orden
           inservible o incruzable. Son las que llevan el porcentaje de validez
           estructural al 100%.
  ADVIERTE el envio sigue, pero queda marcado para revision. Es para lo que
           puede ser legitimo y raro a la vez.
  INFORMA  no afecta al envio; alimenta metricas.

USO:
    .venv/Scripts/python.exe scripts/t2_5_validacion.py           # mide el historico
    .venv/Scripts/python.exe scripts/t2_5_validacion.py --pruebas # solo las pruebas
"""
import argparse
import json
import re
import sys
import unicodedata
from collections import Counter
from datetime import datetime
from pathlib import Path

import mysql.connector

# Rutas relativas a la raíz del repositorio: con la ruta absoluta de la estación
# pegada, el fixture no se podía correr en ningún otro equipo (AUDITORIA_2026-09-12, H10).
_BASE = Path(__file__).resolve().parents[1]          # desarrollo/agentes
_RAIZ = _BASE.parents[1]                             # la raíz del proyecto
CATALOGOS = _RAIZ / "SALIDAS IA" / "OTS" / "catalogos"
ENV_PATH = _BASE / "config" / ".env"
INFORME = _RAIZ / "SALIDAS IA" / "OTS"
# Fixture compartido con la implementacion PHP del servidor. Es el arbitro: si
# los dos lados no dan lo mismo sobre estos casos, las reglas se separaron.
FIXTURE = _RAIZ / "desarrollo" / "sistema_ots" / "app" / "pruebas" / "fixture_validacion.json"

BLOQUEA, ADVIERTE, INFORMA = "BLOQUEA", "ADVIERTE", "INFORMA"

# Las ocho ortografias de "no se uso repuesto" que aparecen en los datos reales.
# No es una suposicion: salio de contar los valores del campo. Suman 2.168 de
# las 4.359 filas con repuesto anotado, el 50%.
SIN_REPUESTO = {
    "S/N", "SN", "NINGUNO", "NINGUNA", "SINREPUESTOS", "SINREPUESTO",
    "-", "N/A", "NA", "SINRESPUESTOS", "0", "NINGUN", "SINREPUESTOSUSADOS",
}


def _tokens_nombre(s):
    """Tokens de un nombre, sin tildes y sin particulas cortas.

    Hace falta porque la nomina guarda el nombre legal completo
    (ANTHONY MEDARDO JUMBO ROJANO) y el tecnico se firma como se le conoce
    (Anthony Jumbo). Comparar las cadenas enteras marcaba como desconocidos al
    92% de los tecnicos, que es falso: son la misma persona.
    """
    s = unicodedata.normalize("NFKD", str(s or "")).encode("ascii", "ignore").decode()
    return {t for t in re.split(r"[^A-Za-z]+", s.upper()) if len(t) > 2}


def es_sin_repuesto(v):
    if not v:
        return True
    return re.sub(r"[.\s]", "", str(v)).upper() in SIN_REPUESTO


class Hallazgo:
    __slots__ = ("campo", "regla", "severidad", "mensaje")

    def __init__(self, campo, regla, severidad, mensaje):
        self.campo, self.regla = campo, regla
        self.severidad, self.mensaje = severidad, mensaje

    def __repr__(self):
        return f"[{self.severidad}] {self.regla}: {self.mensaje}"


def cargar_catalogos():
    def leer(n):
        p = CATALOGOS / n
        if not p.exists():
            sys.exit(f"Falta {p}. Corre antes: scripts/t2_5_catalogos.py")
        return json.loads(p.read_text(encoding="utf-8"))["datos"]

    locales = {l["codigo"]: l for l in leer("locales.json")}
    equipos = leer("equipos_por_local.json")
    tipos = {t["tipo"] for t in leer("tipos_equipo.json")}
    tecnicos = [_tokens_nombre(t["nombre"]) for t in leer("tecnicos.json")]
    return {"locales": locales, "equipos": equipos, "tipos": tipos, "tecnicos": tecnicos}


# ---------------------------------------------------------------------------
# Las reglas
# ---------------------------------------------------------------------------

def validar(orden, cat, contexto="CAPTURA"):
    """Devuelve la lista de hallazgos de UNA orden. Lista vacia = todo bien.

    `orden` es un dict con las claves del formulario unico. Se aceptan ausentes:
    validar tiene que poder correr sobre el historico, que no tiene todos los
    campos que el formulario nuevo va a pedir.

    `contexto` cambia lo que significa un mismo hecho segun cuando se mira:

      CAPTURA    una orden que se esta enviando ahora. El desplegable solo
                 ofrece tecnicos vigentes, asi que un tecnico desconocido es un
                 error que se corta.
      HISTORICO  una orden ya emitida. INDUSTEC tiene ALTA ROTACION, y una orden
                 de 2025 firmada por alguien que ya se fue es historia correcta,
                 no un dato sucio. Marcarla como problema seria inventarle a la
                 empresa un defecto que no tiene, y de paso ensuciaria el
                 conteo con el que se mide todo lo demas.
    """
    h = []
    tipo = (orden.get("tipo") or "").upper()

    # --- LOCAL. La regla que sola elimina las 158 grafias. -----------------
    local = orden.get("local")
    if not local:
        h.append(Hallazgo("local", "LOCAL_REQUERIDO", BLOQUEA,
                          "sin local no se puede archivar, cruzar ni facturar la orden"))
    elif local not in cat["locales"]:
        h.append(Hallazgo("local", "LOCAL_FUERA_DE_CATALOGO", BLOQUEA,
                          f"'{local}' no esta en el maestro de {len(cat['locales'])} locales"))

    # --- ZONA. No se valida: se deriva. Por eso no puede estar cruzada. ----
    info = cat["locales"].get(local or "")
    if info and orden.get("zona") and orden["zona"] != info["zona"]:
        h.append(Hallazgo("zona", "ZONA_CRUZADA", INFORMA,
                          f"el envio dice {orden['zona']} y el local {local} es de "
                          f"{info['zona']}; manda el maestro"))

    # --- AVISO SAP. Ocho digitos, o declararlo ausente a proposito. --------
    aviso = orden.get("aviso")
    sin_aviso = bool(orden.get("sin_aviso"))
    if aviso in (None, "", 0, "0"):
        if not sin_aviso:
            # Una orden puede nacer sin aviso -- el tecnico ya estaba en sitio y
            # el aviso no existia todavia (regla del cliente, 2026-09-04). Pero
            # tiene que ser una decision declarada, no un campo que quedo vacio.
            h.append(Hallazgo("aviso", "AVISO_VACIO_SIN_DECLARAR", BLOQUEA,
                              "marca 'esta orden nace sin aviso' o escribe el aviso"))
    elif not re.fullmatch(r"\d{8}", str(aviso)):
        h.append(Hallazgo("aviso", "AVISO_MAL_FORMADO", BLOQUEA,
                          f"'{aviso}' no son 8 digitos"))

    # --- TIPO y DIA DE INTERVENCION. --------------------------------------
    if tipo not in ("CORRECTIVO", "PREVENTIVO"):
        h.append(Hallazgo("tipo", "TIPO_INVALIDO", BLOQUEA,
                          f"'{tipo}' no es CORRECTIVO ni PREVENTIVO"))
    dia = orden.get("dia_intervencion")
    if tipo == "PREVENTIVO":
        if dia in (None, "", 0):
            h.append(Hallazgo("dia_intervencion", "DIA_REQUERIDO_EN_PREVENTIVO", BLOQUEA,
                              "un preventivo pertenece a un dia del ingreso"))
        elif not (isinstance(dia, int) or str(dia).isdigit()) or not 1 <= int(dia) <= 5:
            h.append(Hallazgo("dia_intervencion", "DIA_FUERA_DE_RANGO", BLOQUEA,
                              f"'{dia}' no esta entre 1 y 5"))
    elif dia not in (None, "", 0):
        h.append(Hallazgo("dia_intervencion", "DIA_EN_CORRECTIVO", ADVIERTE,
                          "un correctivo no tiene dia de intervencion"))

    # --- EQUIPOS. Bloque repetible, minimo 1, tanto en correctivo como en --
    # --- preventivo. Es la unica diferencia estructural que habia entre ----
    # --- los dos formularios viejos.                                     ---
    equipos = orden.get("equipos") or []
    if not equipos:
        h.append(Hallazgo("equipos", "SIN_EQUIPO", BLOQUEA,
                          "toda orden interviene al menos un equipo"))
    catalogo_local = {e["tipo"] for e in cat["equipos"].get(local or "", [])}
    activos_local = {e["equipo_sap"] for e in cat["equipos"].get(local or "", [])}
    for i, eq in enumerate(equipos, start=1):
        ref = eq.get("equipo_sap")
        tipo_eq = (eq.get("tipo") or "").upper()
        es_nuevo = bool(eq.get("nuevo"))
        if ref:
            if activos_local and ref not in activos_local:
                h.append(Hallazgo(f"equipos[{i}]", "EQUIPO_DE_OTRO_LOCAL", BLOQUEA,
                                  f"el activo {ref} no pertenece a {local}"))
        elif not tipo_eq:
            h.append(Hallazgo(f"equipos[{i}]", "EQUIPO_SIN_IDENTIFICAR", BLOQUEA,
                              "elige el activo del local, o al menos su tipo"))
        elif es_nuevo:
            # "Equipo nuevo / no esta en la lista" (H-10, D8): no bloquea, pero
            # queda marcado para que la administracion lo apruebe.
            h.append(Hallazgo(f"equipos[{i}]", "EQUIPO_NUEVO_PROPUESTO", ADVIERTE,
                              f"'{tipo_eq}' se registra como equipo nuevo de {local}; "
                              "la administracion lo revisa"))
        elif tipo_eq not in cat["tipos"]:
            # No bloquea: los 6 locales sin activos catalogados y los tipos que
            # SAP todavia no registro son un caso real. Se marca para que el
            # catalogo crezca con lo que aparece, no para frenar al tecnico.
            h.append(Hallazgo(f"equipos[{i}]", "TIPO_FUERA_DE_CATALOGO", ADVIERTE,
                              f"'{tipo_eq}' no esta entre los {len(cat['tipos'])} tipos conocidos"))
        if catalogo_local and tipo_eq and not ref and not es_nuevo and tipo_eq in catalogo_local:
            h.append(Hallazgo(f"equipos[{i}]", "EQUIPO_ELEGIBLE_POR_ACTIVO", INFORMA,
                              f"{local} tiene activos de tipo '{tipo_eq}' en el catalogo; "
                              "conviene elegir cual"))

    # --- TRABAJO CON OTRO PROVEEDOR (H-18, D10). ---------------------------
    if orden.get("con_proveedor_marcado") and not str(orden.get("con_proveedor") or "").strip():
        h.append(Hallazgo("con_proveedor", "CON_PROVEEDOR_SIN_NOMBRE", BLOQUEA,
                          "marcaste que el trabajo lo hizo otro proveedor pero falta su nombre"))

    # --- REPUESTOS. La casilla que elimina el 50% del ruido. ---------------
    uso = orden.get("uso_repuesto")
    detalle = orden.get("repuestos")
    if uso is None:
        h.append(Hallazgo("uso_repuesto", "REPUESTO_NO_DECLARADO", BLOQUEA,
                          "responde si se uso repuesto o no"))
    elif uso and es_sin_repuesto(detalle):
        h.append(Hallazgo("repuestos", "REPUESTO_MARCADO_SIN_DETALLE", BLOQUEA,
                          "dice que se uso repuesto pero no dice cual"))
    elif not uso and detalle and not es_sin_repuesto(detalle):
        h.append(Hallazgo("repuestos", "REPUESTO_CONTRADICTORIO", ADVIERTE,
                          f"marca que no hubo repuesto pero anota '{str(detalle)[:40]}'"))

    # --- FECHA. Una orden no se atiende en el futuro. --------------------
    fecha = orden.get("fecha_atencion")
    if fecha:
        # La fecha llega como `date` desde la base y como texto ISO desde el
        # fixture y desde el JSON del formulario. Se compara en ISO, que ordena
        # igual que la fecha. La primera version exigia un objeto con .year y
        # saltaba la regla en silencio para el texto -- el fixture compartido con
        # PHP lo detecto en la primera corrida, que es exactamente para lo que
        # existe ese fixture.
        hoy = orden.get("_hoy") or datetime.now().date()
        if str(fecha)[:10] > str(hoy)[:10]:
            h.append(Hallazgo("fecha_atencion", "FECHA_FUTURA", BLOQUEA,
                              f"{fecha} es posterior a hoy"))

    # --- TIEMPOS. --------------------------------------------------------
    ini, fin = orden.get("inicio"), orden.get("fin")
    if ini and fin:
        if fin <= ini:
            # Con `time` sin fecha esto es indistinguible de una intervencion que
            # cruza medianoche, y por eso el formulario unico captura fecha+hora.
            # Nunca se suman 24 h por verosimilitud: seria inventar (I-6).
            h.append(Hallazgo("fin", "FIN_NO_POSTERIOR_A_INICIO", BLOQUEA,
                              "la hora de fin debe ser posterior a la de inicio"))
        elif (fin - ini).total_seconds() > 12 * 3600:
            h.append(Hallazgo("fin", "DURACION_INVEROSIMIL", ADVIERTE,
                              f"{(fin - ini).total_seconds() / 3600:.1f} horas de atencion"))
    elif not (ini and fin):
        h.append(Hallazgo("inicio", "TIEMPOS_INCOMPLETOS", ADVIERTE,
                          "sin hora de inicio y fin no se puede medir la atencion"))

    # --- TRABAJO REALIZADO. No se puede validar que sea cierto; si que ----
    # --- este y que diga algo.                                          ---
    act = (orden.get("actividades") or "").strip()
    if not act:
        h.append(Hallazgo("actividades", "SIN_TRABAJO_REALIZADO", BLOQUEA,
                          "describe que se hizo"))
    elif len(act) < 15:
        h.append(Hallazgo("actividades", "TRABAJO_DEMASIADO_ESCUETO", ADVIERTE,
                          f"'{act}' no alcanza a describir una intervencion"))

    # --- FIRMA Y EVIDENCIA. ----------------------------------------------
    if not orden.get("firma_presente"):
        h.append(Hallazgo("firma", "SIN_FIRMA", BLOQUEA,
                          "la orden la firma el administrador del local"))
    if not orden.get("fotos_cantidad"):
        h.append(Hallazgo("fotos", "SIN_FOTOS", ADVIERTE,
                          "sin fotos la orden no tiene evidencia de lo hecho"))

    # --- TECNICO. --------------------------------------------------------
    tec = (orden.get("tecnico") or "").strip()
    if not tec:
        h.append(Hallazgo("tecnico", "SIN_TECNICO", BLOQUEA, "sin responsable"))
    else:
        # Varios tecnicos en un mismo trabajo es un caso REAL de la operacion:
        # "Kevin Chimbo, Diego Melendrez" aparece 70 veces en el historico. El
        # campo de texto unico no lo puede expresar, y por eso el formulario
        # unico lleva una lista de tecnicos, no un campo. Aqui se separa por
        # coma para poder medir el historico sin castigar el caso legitimo.
        partes = [x.strip() for x in re.split(r"[,/]| y ", tec) if x.strip()]
        for parte in partes:
            t = _tokens_nombre(parte)
            if t and not any(t <= n for n in cat["tecnicos"]):
                if contexto == "CAPTURA":
                    h.append(Hallazgo("tecnico", "TECNICO_NO_VIGENTE", BLOQUEA,
                                      f"'{parte}' no esta entre los tecnicos vigentes"))
                else:
                    h.append(Hallazgo("tecnico", "TECNICO_YA_NO_VIGENTE", INFORMA,
                                      f"'{parte}' no esta en la nomina actual; "
                                      "esperable por la rotacion de la empresa"))
        if len(partes) > 1:
            h.append(Hallazgo("tecnico", "VARIOS_TECNICOS_EN_UN_CAMPO", INFORMA,
                              f"{len(partes)} tecnicos en un campo de texto; el formato "
                              "unico los lleva como lista"))

    return h


# ---------------------------------------------------------------------------
# Pruebas
# ---------------------------------------------------------------------------

def pruebas(cat):
    fallos = []

    def caso(nombre, orden, reglas_esperadas):
        got = {x.regla for x in validar(orden, cat)}
        esp = set(reglas_esperadas)
        ok = got == esp
        print(f"  {'OK   ' if ok else 'FALLA'} {nombre}")
        if not ok:
            fallos.append(f"{nombre}: sobran {got - esp}, faltan {esp - got}")

    local_ok = next(iter(cat["locales"]))
    activo = None
    for l, eqs in cat["equipos"].items():
        if eqs:
            local_ok, activo = l, eqs[0]
            break

    buena = {
        "tipo": "CORRECTIVO", "local": local_ok, "aviso": "10334255",
        "equipos": [{"equipo_sap": activo["equipo_sap"], "tipo": activo["tipo"]}],
        "uso_repuesto": False, "repuestos": "Ninguno",
        "inicio": datetime(2026, 9, 1, 9, 0), "fin": datetime(2026, 9, 1, 11, 30),
        "actividades": "Se cambio el termostato y se probo el ciclo completo",
        "firma_presente": True, "fotos_cantidad": 4,
        "tecnico": " ".join(sorted(cat["tecnicos"][0])) if cat["tecnicos"] else "X",
    }
    caso("una orden correcta no genera ningun hallazgo", buena, [])

    # Un solo hallazgo, no dos: con un local desconocido no hay catalogo de
    # activos contra el cual decidir si el equipo es de otro sitio, y afirmarlo
    # seria especular. Vale mas un error claro que dos, uno de ellos inventado.
    caso("local fuera del maestro bloquea, y no especula sobre el equipo",
         {**buena, "local": "ZZ999EC"}, ["LOCAL_FUERA_DE_CATALOGO"])

    caso("aviso de 7 digitos bloquea",
         {**buena, "aviso": "1033425"}, ["AVISO_MAL_FORMADO"])
    caso("aviso vacio sin declararlo bloquea",
         {**buena, "aviso": None}, ["AVISO_VACIO_SIN_DECLARAR"])
    caso("aviso vacio DECLARADO es valido",
         {**buena, "aviso": None, "sin_aviso": True}, [])

    caso("preventivo sin dia bloquea",
         {**buena, "tipo": "PREVENTIVO"}, ["DIA_REQUERIDO_EN_PREVENTIVO"])
    caso("preventivo con dia 3 es valido",
         {**buena, "tipo": "PREVENTIVO", "dia_intervencion": 3}, [])
    caso("dia fuera de rango bloquea",
         {**buena, "tipo": "PREVENTIVO", "dia_intervencion": 9}, ["DIA_FUERA_DE_RANGO"])

    caso("sin equipo bloquea", {**buena, "equipos": []}, ["SIN_EQUIPO"])
    caso("marcar repuesto sin decir cual bloquea",
         {**buena, "uso_repuesto": True, "repuestos": "S/N"},
         ["REPUESTO_MARCADO_SIN_DETALLE"])
    caso("no declarar si hubo repuesto bloquea",
         {**buena, "uso_repuesto": None}, ["REPUESTO_NO_DECLARADO"])
    caso("fin anterior al inicio bloquea",
         {**buena, "fin": datetime(2026, 9, 1, 8, 0)}, ["FIN_NO_POSTERIOR_A_INICIO"])
    caso("sin firma bloquea", {**buena, "firma_presente": False}, ["SIN_FIRMA"])
    caso("sin fotos solo advierte", {**buena, "fotos_cantidad": 0}, ["SIN_FOTOS"])

    # La rotacion no es un defecto de datos. El MISMO hecho -- un tecnico que no
    # esta en la nomina vigente -- bloquea una captura de hoy y es normal en una
    # orden de hace un ano.
    desconocido = {**buena, "tecnico": "Fulano De Tal Inexistente"}
    caso("en CAPTURA, un tecnico no vigente bloquea",
         desconocido, ["TECNICO_NO_VIGENTE"])
    got = {x.regla for x in validar(desconocido, cat, contexto="HISTORICO")}
    ok = got == {"TECNICO_YA_NO_VIGENTE"}
    print(f"  {'OK   ' if ok else 'FALLA'} en HISTORICO, el mismo caso solo informa")
    if not ok:
        fallos.append(f"historico: {got}")

    from datetime import date as _d
    caso("una orden fechada en el futuro bloquea",
         {**buena, "fecha_atencion": _d(2099, 1, 1)}, ["FECHA_FUTURA"])

    return fallos


# ---------------------------------------------------------------------------
# Medicion sobre el historico
# ---------------------------------------------------------------------------

def medir_historico(cat):
    """Corre las reglas sobre las 7.069 ordenes ya guardadas.

    No es una auditoria del pasado: el historico se lleno con lo que el sistema
    viejo permitia, asi que es normal que falle. Es la MEDIDA de cuanta
    ambiguedad eliminan los controles -- cada BLOQUEA que aparece aqui es un
    caso que con el formulario unico no habria podido enviarse.
    """
    env = {}
    for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])
    cur = cnx.cursor(dictionary=True)
    cur.execute("""SELECT o.id_industec, o.modulo, o.zona, o.local_codigo, o.aviso,
                          o.dia_intervencion, o.hora_inicio, o.hora_fin,
                          o.actividades, o.repuestos, o.firma_presente,
                          o.fotos_cantidad, o.tecnico_nombre, o.fecha_atencion
                   FROM ots o WHERE o.en_cuarentena = 0""")
    filas = cur.fetchall()
    cur.execute("SELECT id_industec, equipo FROM ot_equipos")
    eq_por_ot = {}
    for r in cur.fetchall():
        eq_por_ot.setdefault(r["id_industec"], []).append({"tipo": (r["equipo"] or "").upper()})
    cur.close(); cnx.close()

    reglas = Counter()
    severidad = Counter()
    limpias = 0
    for f in filas:
        ini = fin = None
        if f["fecha_atencion"] and f["hora_inicio"] and f["hora_fin"]:
            base = datetime.combine(f["fecha_atencion"], datetime.min.time())
            ini = base + f["hora_inicio"]
            fin = base + f["hora_fin"]
        orden = {
            "tipo": f["modulo"], "zona": f["zona"], "local": f["local_codigo"],
            "aviso": str(f["aviso"]) if f["aviso"] else None,
            "dia_intervencion": f["dia_intervencion"],
            "equipos": eq_por_ot.get(f["id_industec"], []),
            # El historico no tiene la casilla: se deduce del texto, que es
            # justo lo que el formulario unico viene a dejar de hacer.
            "uso_repuesto": not es_sin_repuesto(f["repuestos"]),
            "repuestos": f["repuestos"],
            "inicio": ini, "fin": fin,
            "actividades": f["actividades"], "firma_presente": f["firma_presente"],
            "fotos_cantidad": f["fotos_cantidad"], "tecnico": f["tecnico_nombre"],
        }
        orden["fecha_atencion"] = f["fecha_atencion"]
        hs = validar(orden, cat, contexto="HISTORICO")
        if not hs:
            limpias += 1
        for x in hs:
            reglas[x.regla] += 1
            severidad[x.severidad] += 1
    return filas, reglas, severidad, limpias


def pruebas_fixture():
    """Corre el fixture compartido con PHP. Mismo archivo, mismos casos.

    El catalogo del fixture es fijo y de juguete a proposito: con el catalogo
    real los resultados cambiarian cada vez que se ingesta un aviso nuevo, y
    dejaria de ser una prueba.
    """
    if not FIXTURE.exists():
        sys.exit(f"Falta el fixture compartido: {FIXTURE}")
    fx = json.loads(FIXTURE.read_text(encoding="utf-8"))
    cat = {
        "locales": {l["codigo"]: l for l in fx["catalogo"]["locales"]},
        "equipos": fx["catalogo"]["equipos"],
        "tipos": {t.upper() for t in fx["catalogo"]["tipos"]},
        "tecnicos": [_tokens_nombre(t["nombre"]) for t in fx["catalogo"]["tecnicos"]],
    }
    base = fx["orden_base"]
    fallos = []
    for caso in fx["casos"]:
        orden = dict(base)
        orden.update(caso["cambios"])
        # Las fechas del fixture son texto ISO; validar() compara datetimes.
        for k in ("inicio", "fin"):
            if isinstance(orden.get(k), str):
                orden[k] = datetime.fromisoformat(orden[k])
        got = sorted({x.regla for x in validar(orden, cat, caso.get("contexto", "CAPTURA"))})
        esp = sorted(caso["espera"])
        ok = got == esp
        print(f"  {'OK   ' if ok else 'FALLA'} {caso['nombre']}")
        if not ok:
            fallos.append(f"{caso['nombre']}\n"
                          f"       esperaba: {esp}\n"
                          f"       dio     : {got}")
    return fallos, len(fx["casos"])


def main():
    ap = argparse.ArgumentParser(description="Controles del formulario unico")
    ap.add_argument("--pruebas", action="store_true", help="solo corre las pruebas")
    ap.add_argument("--fixture", action="store_true",
                    help="corre el fixture compartido con PHP y nada mas")
    args = ap.parse_args()

    if args.fixture:
        print("[fixture compartido con PHP]")
        fallos, n = pruebas_fixture()
        print("\n" + "=" * 62)
        if fallos:
            print(f"{len(fallos)} de {n} casos FALLAN:\n")
            for f in fallos:
                print(f"  - {f}\n")
            sys.exit(1)
        print(f"Los {n} casos del fixture pasan en Python.")
        return

    cat = cargar_catalogos()
    print(f"Catalogos: {len(cat['locales'])} locales | "
          f"{sum(len(v) for v in cat['equipos'].values())} activos | "
          f"{len(cat['tipos'])} tipos | {len(cat['tecnicos'])} tecnicos\n")

    print("[fixture compartido con PHP]")
    f_fix, n_fix = pruebas_fixture()

    print("\n[pruebas contra el catalogo real]")
    fallos = pruebas(cat) + f_fix
    if fallos:
        print("\nFALLAN:")
        for f in fallos:
            print(f"  - {f}")
        sys.exit(1)
    if args.pruebas:
        print("\nTodas las pruebas pasan.")
        return

    print("\n[medicion sobre el historico]")
    filas, reglas, severidad, limpias = medir_historico(cat)
    n = len(filas)
    print(f"  ordenes evaluadas : {n:,}".replace(",", "."))
    print(f"  sin ningun hallazgo: {limpias:,} ({100 * limpias / n:.1f}%)".replace(",", "."))
    print(f"  con al menos un BLOQUEA: {n - limpias:,}".replace(",", "."))
    print("\n  Hallazgos por regla (cada BLOQUEA es un caso que el formulario")
    print("  unico no habria dejado enviar):\n")
    for regla, c in reglas.most_common():
        sev = next((x for x in (BLOQUEA, ADVIERTE, INFORMA) if _sev_de(regla) == x), "")
        print(f"    {c:>6}  [{sev:8}] {regla}")

    INFORME.mkdir(parents=True, exist_ok=True)
    p = INFORME / "CONTROLES_MEDIDOS.md"
    p.write_text("\n".join([
        "# Cuánta ambigüedad eliminan los controles",
        "",
        f"Generado el **{datetime.now():%Y-%m-%d %H:%M}** corriendo las reglas de",
        "`ESPECIFICACION_OT_UNICA.md` sobre las órdenes ya guardadas.",
        "",
        "**No es una auditoría del pasado.** El histórico se llenó con lo que el",
        "sistema viejo permitía, así que es normal que falle. Es la **medida** de",
        "cuánto deja de ser posible: cada `BLOQUEA` de esta lista es un caso que",
        "con el formulario único no se habría podido enviar.",
        "",
        f"| | |", "|---|---|",
        f"| Órdenes evaluadas | {n:,} |".replace(",", "."),
        f"| Pasarían sin un solo hallazgo | {limpias:,} ({100 * limpias / n:.1f}%) |".replace(",", "."),
        f"| Tendrían al menos un hallazgo | {n - limpias:,} ({100 * (n - limpias) / n:.1f}%) |".replace(",", "."),
        "",
        "## Por regla",
        "",
        "| Casos | Severidad | Regla |",
        "|---|---|---|",
    ] + [f"| {c:,} | `{_sev_de(r)}` | `{r}` |".replace(",", ".")
         for r, c in reglas.most_common()] + [
        "",
        "## Cómo leerlo",
        "",
        "Un `BLOQUEA` alto no es una mala noticia: es exactamente la ambigüedad que",
        "hoy hay que reconciliar a mano contra SAP, y que con el formulario único",
        "deja de generarse. Un `ADVIERTE` alto señala dónde el catálogo tiene que",
        "crecer o dónde hace falta una decisión de INDUSTEC.",
    ]), encoding="utf-8")
    print(f"\n  Informe: {p}")


_SEVERIDADES = {}


def _sev_de(regla):
    return _SEVERIDADES.get(regla, "")


# Se rellena una vez, corriendo las reglas sobre ordenes sinteticas que las
# disparen todas. Mas simple que duplicar la tabla y arriesgar que se separe.
def _mapear_severidades():
    for nombre, sev in [
        ("LOCAL_REQUERIDO", BLOQUEA), ("LOCAL_FUERA_DE_CATALOGO", BLOQUEA),
        ("ZONA_CRUZADA", INFORMA), ("AVISO_VACIO_SIN_DECLARAR", BLOQUEA),
        ("AVISO_MAL_FORMADO", BLOQUEA), ("TIPO_INVALIDO", BLOQUEA),
        ("DIA_REQUERIDO_EN_PREVENTIVO", BLOQUEA), ("DIA_FUERA_DE_RANGO", BLOQUEA),
        ("DIA_EN_CORRECTIVO", ADVIERTE), ("SIN_EQUIPO", BLOQUEA),
        ("EQUIPO_DE_OTRO_LOCAL", BLOQUEA), ("EQUIPO_SIN_IDENTIFICAR", BLOQUEA),
        ("TIPO_FUERA_DE_CATALOGO", ADVIERTE), ("EQUIPO_ELEGIBLE_POR_ACTIVO", INFORMA),
        ("REPUESTO_NO_DECLARADO", BLOQUEA), ("REPUESTO_MARCADO_SIN_DETALLE", BLOQUEA),
        ("REPUESTO_CONTRADICTORIO", ADVIERTE), ("FIN_NO_POSTERIOR_A_INICIO", BLOQUEA),
        ("DURACION_INVEROSIMIL", ADVIERTE), ("TIEMPOS_INCOMPLETOS", ADVIERTE),
        ("SIN_TRABAJO_REALIZADO", BLOQUEA), ("TRABAJO_DEMASIADO_ESCUETO", ADVIERTE),
        ("SIN_FIRMA", BLOQUEA), ("SIN_FOTOS", ADVIERTE),
        ("SIN_TECNICO", BLOQUEA), ("TECNICO_NO_VIGENTE", BLOQUEA),
        ("TECNICO_YA_NO_VIGENTE", INFORMA), ("FECHA_FUTURA", BLOQUEA),
        ("VARIOS_TECNICOS_EN_UN_CAMPO", INFORMA),
        ("EQUIPO_NUEVO_PROPUESTO", ADVIERTE), ("CON_PROVEEDOR_SIN_NOMBRE", BLOQUEA),
    ]:
        _SEVERIDADES[nombre] = sev


_mapear_severidades()


if __name__ == "__main__":
    main()
