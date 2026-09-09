"""
T2.6 - Lector de avisos SAP desde el buzon de INDUSTEC. SOLO LECTURA.

QUE HACE:
Grupo KFC notifica cada orden de trabajo por correo, desde sgerente@kfc.com.ec,
con copia a servicioalcliente@industec.me y al buzon de la ZONA. Este script los
lee, extrae el caso y lo publica para que la administracion y el jefe de zona lo
asignen a un tecnico. NO asigna: asignar es una decision humana.

LO QUE NUNCA HACE (directiva del cliente, 2026-09-08):
No marca como leido, no mueve, no borra, no responde, no crea carpetas. La
sesion abre el buzon con EXAMINE (readonly=True) y lee con BODY.PEEK[], que es
la unica forma de leer sin encender la bandera \\Seen. Verificacion:

    grep -nE "M\\.(store|copy|expunge|append|create|rename|setacl)\\(|BODY\\[[^]]" \\
         scripts/t2_6_imap_avisos.py

no debe devolver nada. (BODY.PEEK[] si aparece: es la lectura que no marca.)

LO QUE SE MIDIO ANTES DE ESCRIBIRLO (1.031 correos reales en el buzon):

  1. EL HTML ES UTF-8 DE VERDAD, y hay que decodificarlo estricto. Se comprobo
     byte a byte: "creo" viaja como c3 b3, que es UTF-8 correcto, y utf-8
     estricto no falla en ninguno de los correos mirados. Lo contrario --
     forzar cp1252 "por si acaso" -- SI rompe: cp1252 no puede decodificar los
     bytes 0x81 y 0x8d que aparecen en varios mensajes. Por eso el orden es
     utf-8 primero y nunca errors='replace', que convertiria el problema en '?'
     y lo esconderia.
     (Ojo al depurar: la consola de Windows muestra "cre?" aunque el dato este
     bien. Eso es la codepage del terminal, no el correo.)

  2. NO TODO ES UN ALTA. Sobre 400 correos: 395 "Se ha creado la orden de
     trabajo", 3 "Se ha ELIMINADO la orden de trabajo". Un caso se puede anular.
     Ignorar las bajas dejaria al tecnico eligiendo ordenes muertas.

  3. EL CODIGO DE RESTAURANTE NO SIEMPRE ES R###. Aparece CN42W0000xxx. El
     numero de orden es {codigo_local}W{7 digitos}, con el codigo variable.

  4. EL AVISO LLEVA CEROS A LA IZQUIERDA: 000010353373 -> 10353373. Es la misma
     normalizacion de Nivel 1 que ya hizo T1.6b.

  5. LA ZONA TIENE DOS FUENTES INDEPENDIENTES: el buzon de zona que va en copia
     (jefezona-uio / jefezonacuenca-loja / jefetecniconacional) y el local
     resuelto contra el maestro. Se comparan y, si discrepan, se REPORTA en vez
     de elegir una (I-10). Nunca se inventa la zona.

  6. "Restaurante:" Y "Usuario:" NO ESTAN EN LA TABLA. Van en parrafos sueltos,
     como <p><strong>Restaurante:</strong>R002 QUICENTRO NORTE</p>. Buscarlos
     entre las celdas <td>/<th> devuelve None para todos -- pasó en la primera
     corrida, con 924 casos sin local.

Uso:
    .venv/Scripts/python.exe scripts/t2_6_imap_avisos.py            # ultimos 90 dias
    .venv/Scripts/python.exe scripts/t2_6_imap_avisos.py --dias 365
    .venv/Scripts/python.exe scripts/t2_6_imap_avisos.py --todo
"""

import argparse
import email
import html as htmlmod
import imaplib
import json
import re
import sys
import unicodedata
from datetime import date, datetime, timedelta
from email.header import decode_header, make_header
from pathlib import Path

import mysql.connector

ENV_PATH = Path(r"D:\INDUSTECH IA\desarrollo\agentes\config\.env")
ALCANCE_PATH = Path(r"D:\INDUSTECH IA\desarrollo\agentes\config\alcance_trabajos.json")
SALIDA = Path(r"D:\INDUSTECH IA\SALIDAS IA\OTS")

REMITENTE = "sgerente@kfc.com.ec"

# El buzon de zona que va en copia. Es la segunda senal, independiente del local.
ZONA_POR_BUZON = {
    "jefezona-uio@industec.me": "UIO",
    "jefezonacuenca-loja@industec.me": "CNLJ",
    "jefetecniconacional@industec.me": "LARB",
}

# "SIR - Se ha creado|eliminado la orden de trabajo R002W0001136"
RE_ASUNTO = re.compile(
    r"se\s+ha\s+(creado|eliminado)\s+la\s+orden\s+de\s+trabajo\s+([A-Za-z0-9]+W\d+)",
    re.I)

# Las 8 columnas de la tabla, en el orden en que SAP las emite.
COLUMNAS = ["Prioridad", "Aviso SAP", "F.Creacion", "F.Estimada", "Tipo Trabajo",
            "Detalle Trabajo", "Activo Fijo", "Descripcion Breve"]

RE_CELDA = re.compile(r"<t[dh][^>]*>(.*?)</t[dh]>", re.S | re.I)


# --------------------------------------------------------------------------
# Entorno y catalogo
# --------------------------------------------------------------------------
def cargar_env():
    env = {}
    for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return env


def clave(s):
    return re.sub(r"[^A-Za-z0-9]", "", s or "").upper()


def normalizar_tipo(t):
    """Mayusculas, sin tildes y sin puntuacion, para comparar contra el alcance.

    'Dano en el sistema de extraccion' llega de SAP con tilde y a veces con el
    mojibake que dejo la importacion del Excel; comparar cadenas crudas fallaba.
    """
    t = unicodedata.normalize("NFD", str(t or ""))
    t = "".join(c for c in t if unicodedata.category(c) != "Mn")
    return re.sub(r"[^A-Z0-9]+", " ", t.upper()).strip()


def cargar_alcance():
    """El alcance del servicio, como dato. Devuelve (dentro, fuera, por_confirmar).

    `fuera` mapea tipo -> motivo, para poder decirle a la administradora POR QUE
    un caso no compete, en vez de solo marcarlo.
    """
    j = json.loads(ALCANCE_PATH.read_text(encoding="utf-8"))
    dentro = {normalizar_tipo(t) for t in j["dentro"]["tipos"]}
    fuera = {}
    for g in j["fuera"]["grupos"]:
        for t in g["tipos"]:
            fuera[normalizar_tipo(t)] = g["motivo"]
    confirmar = {normalizar_tipo(t) for t in j["por_confirmar"]["tipos"]}
    return dentro, fuera, confirmar, j.get("version")


def triage(caso, dentro, fuera, confirmar):
    """Levanta ALERTAS sobre un caso recien llegado. NO decide nada.

    Regla del cliente, 2026-09-08: estos filtros marcan alertas para la
    administradora; **el veredicto sobre cada caso sigue siendo de ella**. El
    sistema nunca cierra, nunca rechaza y nunca da un caso por ajeno: le ahorra
    el trabajo de encontrarlos entre cientos, y le dice por que sospecha. Es el
    mismo criterio del proyecto -- toda decision de aceptar o rechazar es
    humana; el codigo solo prepara la evidencia.

    Por eso lo que se emite es `sugerencia`, no `accion`, y el estado es
    CON_ALERTA / POR_CONFIRMAR / SIN_ALERTA -- nunca "ajeno" ni "rechazado".

    Tres alertas posibles, y ninguna se adivina:
      LOCAL_FUERA_DE_CONTRATO   el local no esta en el maestro de INDUSTEC
      TRABAJO_FUERA_DE_ALCANCE  el tipo de trabajo no figura en el alcance
      TRABAJO_POR_CONFIRMAR     tipo sin criterio definido -> lo resuelve la
                                administracion, no el script (I-7)
    """
    alertas = []
    if not caso["local"]:
        alertas.append({
            "regla": "LOCAL_FUERA_DE_CONTRATO",
            "motivo": f"{caso['restaurante_sap']} no esta en el maestro de locales de INDUSTEC",
            "sugerencia": "Revisar si el local nos corresponde; si no, cerrarlo en SAP",
        })
    t = normalizar_tipo(caso["caso"])
    if not t:
        alertas.append({
            "regla": "TRABAJO_POR_CONFIRMAR",
            "motivo": "el correo no trae tipo de trabajo",
            "sugerencia": "Mirar el caso en SAP para saber de que se trata",
        })
    elif t in fuera:
        alertas.append({
            "regla": "TRABAJO_FUERA_DE_ALCANCE",
            "motivo": fuera[t],
            "sugerencia": "Si confirmas que no nos compete, cerrarlo en SAP indicandolo",
        })
    elif t in confirmar:
        alertas.append({
            "regla": "TRABAJO_POR_CONFIRMAR",
            "motivo": f"'{caso['caso']}' no tiene criterio definido de alcance",
            "sugerencia": "Definir si nos compete y anotarlo en config/alcance_trabajos.json",
        })
    elif t not in dentro:
        alertas.append({
            "regla": "TRABAJO_POR_CONFIRMAR",
            "motivo": f"'{caso['caso']}' es un tipo de trabajo que no estaba en la lista",
            "sugerencia": "Definir si nos compete y anotarlo en config/alcance_trabajos.json",
        })
    if any(a["regla"] != "TRABAJO_POR_CONFIRMAR" for a in alertas):
        estado = "CON_ALERTA"
    elif alertas:
        estado = "POR_CONFIRMAR"
    else:
        estado = "SIN_ALERTA"
    return estado, alertas


def cargar_maestro(env):
    """Locales canonicos y el indice de alias, para resolver 'R002' -> 'R002EC'."""
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])
    try:
        cur = cnx.cursor(dictionary=True)
        cur.execute("SELECT local_codigo, zona, cadena, nombre, correo_jefe_op "
                    "FROM locales WHERE activo = 1")
        locales = {r["local_codigo"]: r for r in cur.fetchall()}
        indice = {clave(c): c for c in locales}
        cur.execute("SELECT alias_texto, local_codigo FROM locales_alias")
        for r in cur.fetchall():
            indice.setdefault(clave(r["alias_texto"]), r["local_codigo"])
    finally:
        cnx.close()
    return locales, indice


# --------------------------------------------------------------------------
# Decodificacion y parseo
# --------------------------------------------------------------------------
def texto_html(parte):
    """Decodifica la parte HTML. utf-8 estricto primero; el fallo es la senal.

    Medido sobre el buzon real: el HTML es UTF-8 legitimo y utf-8 estricto no
    falla. El fallback existe por si algun dia llega otra cosa, no porque haga
    falta hoy. Nunca errors='replace': eso esconderia el problema en vez de
    delatarlo.
    """
    crudo = parte.get_payload(decode=True) or b""
    for enc in ("utf-8", "cp1252", "latin-1"):
        try:
            return crudo.decode(enc)
        except UnicodeDecodeError:
            continue
    return crudo.decode("latin-1", errors="replace")


def limpiar(celda):
    t = re.sub(r"<[^>]+>", " ", celda or "")
    t = htmlmod.unescape(t)
    return re.sub(r"\s+", " ", t).strip()


def etiqueta_parrafo(html, etiqueta):
    """Valor de <strong>Etiqueta:</strong>VALOR, que va fuera de la tabla.

    El delimitador ':' es obligatorio en el patron, nunca opcional: sin el,
    cualquier texto libre que empiece con la palabra-etiqueta se confunde con
    un campo real (leccion de T1.7).
    """
    m = re.search(r"<strong>\s*" + re.escape(etiqueta) + r"\s*:\s*</strong>([^<]*)",
                  html, re.I)
    return limpiar(m.group(1)) if m else None


def cuerpo_html(msg):
    for p in msg.walk():
        if p.get_content_type() == "text/html":
            return texto_html(p)
    for p in msg.walk():
        if p.get_content_type() == "text/plain":
            return texto_html(p)
    return ""


def fecha_ec(s):
    """dd/mm/aaaa -> aaaa-mm-dd. Devuelve None si no cuadra: no se adivina."""
    m = re.match(r"^\s*(\d{1,2})/(\d{1,2})/(\d{4})\s*$", s or "")
    if not m:
        return None
    d, mo, a = m.groups()
    return f"{a}-{int(mo):02d}-{int(d):02d}"


def parsear(msg, locales, indice):
    """Un correo -> un caso, o None si no es un aviso de SAP."""
    asunto = str(make_header(decode_header(msg.get("Subject") or "")))
    m = RE_ASUNTO.search(asunto)
    if not m:
        return None
    accion = "ELIMINADA" if m.group(1).lower() == "eliminado" else "CREADA"
    orden_trabajo = m.group(2).upper()

    html = cuerpo_html(msg)
    celdas = [limpiar(c) for c in RE_CELDA.findall(html)]
    celdas = [c for c in celdas if c]

    restaurante = etiqueta_parrafo(html, "Restaurante") or ""
    usuario = etiqueta_parrafo(html, "Usuario")

    # La fila de datos: arranca justo despues de la ultima cabecera conocida.
    fin = None
    for i, c in enumerate(celdas):
        if c.lower().startswith("descripcion breve"):
            fin = i
    valores = celdas[fin + 1: fin + 1 + 8] if fin is not None else []
    v = dict(zip(COLUMNAS, valores)) if len(valores) >= 7 else {}

    # 000010353373 -> 10353373. Solo se acepta si quedan 8 digitos exactos.
    aviso_crudo = (v.get("Aviso SAP") or "").strip()
    aviso = re.sub(r"^0+", "", re.sub(r"\D", "", aviso_crudo))
    if not re.fullmatch(r"\d{8}", aviso or ""):
        aviso = None

    # Local: el codigo va antes del nombre -> "R002 QUICENTRO NORTE"
    cod_sap = restaurante.split()[0] if restaurante else ""
    canonico = indice.get(clave(cod_sap))
    info = locales.get(canonico) if canonico else None

    # Zona por el buzon en copia: segunda senal, independiente del maestro.
    cc = (msg.get("Cc") or "") + "," + (msg.get("To") or "")
    zona_buzon = None
    for buzon, z in ZONA_POR_BUZON.items():
        if buzon in cc.lower():
            zona_buzon = z
            break

    zona_local = info["zona"] if info else None
    discrepancia = bool(zona_buzon and zona_local and zona_buzon != zona_local)

    fecha_correo = None
    try:
        fecha_correo = email.utils.parsedate_to_datetime(msg.get("Date")).date().isoformat()
    except Exception:
        pass

    return {
        "aviso": aviso,
        "aviso_crudo": aviso_crudo or None,
        "orden_trabajo": orden_trabajo,
        "accion": accion,
        "prioridad": (v.get("Prioridad") or "").upper() or None,
        "fecha_creacion": fecha_ec(v.get("F.Creacion")),
        "fecha_estimada": fecha_ec(v.get("F.Estimada")),
        "caso": v.get("Tipo Trabajo") or None,
        "detalle": v.get("Detalle Trabajo") or None,
        "activo_fijo": v.get("Activo Fijo") or None,
        "descripcion_trabajo": v.get("Descripcion Breve") or None,
        "restaurante_sap": restaurante or None,
        "centro_coste_sap": cod_sap or None,
        "usuario_kfc": usuario,
        "local": canonico,
        "local_nombre": info["nombre"] if info else None,
        "cadena": info["cadena"] if info else None,
        "zona": zona_local or zona_buzon,
        "zona_por_buzon": zona_buzon,
        "zona_por_local": zona_local,
        "zona_discrepa": discrepancia,
        "buzon_zona": info["correo_jefe_op"] if info else None,
        "recibido": fecha_correo,
    }


# --------------------------------------------------------------------------
# Lectura del buzon
# --------------------------------------------------------------------------
def leer(env, dias):
    M = imaplib.IMAP4_SSL(env["IMAP_HOST"], int(env["IMAP_PORT"]))
    M.login(env["IMAP_USER"], env["IMAP_PASSWORD"])
    try:
        # readonly=True -> EXAMINE. El servidor no puede cambiar banderas.
        ok, _ = M.select("INBOX", readonly=True)
        if ok != "OK":
            raise RuntimeError("no se pudo abrir INBOX")

        criterio = ["FROM", REMITENTE]
        if dias:
            desde = (date.today() - timedelta(days=dias)).strftime("%d-%b-%Y")
            criterio += ["SINCE", desde]
        ok, data = M.search(None, *criterio)
        ids = data[0].split()

        mensajes = []
        for i in range(0, len(ids), 50):
            lote = b",".join(ids[i:i + 50])
            # BODY.PEEK[] es lo unico que lee sin encender \Seen.
            ok, d = M.fetch(lote, "(BODY.PEEK[])")
            for item in d:
                if isinstance(item, tuple):
                    mensajes.append(email.message_from_bytes(item[1]))
        return mensajes
    finally:
        try:
            M.close()
        except Exception:
            pass
        M.logout()


# --------------------------------------------------------------------------
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dias", type=int, default=90,
                    help="ventana hacia atras (por defecto 90)")
    ap.add_argument("--todo", action="store_true", help="sin limite de fecha")
    args = ap.parse_args()
    dias = None if args.todo else args.dias

    env = cargar_env()
    dentro, fuera, confirmar, ver_alcance = cargar_alcance()
    locales, indice = cargar_maestro(env)
    print(f"maestro: {len(locales)} locales, {len(indice)} claves de resolucion")
    print(f"alcance: {len(dentro)} tipos dentro, {len(fuera)} fuera, "
          f"{len(confirmar)} por confirmar (version {ver_alcance})")

    mensajes = leer(env, dias)
    print(f"correos de {REMITENTE}: {len(mensajes)}"
          + (f" (ultimos {dias} dias)" if dias else " (todos)"))

    casos, ignorados, sin_aviso, sin_local, discrepan = {}, 0, [], [], []
    eliminadas = set()

    # Orden cronologico: la ultima palabra sobre un aviso es la que vale.
    parseados = [p for p in (parsear(m, locales, indice) for m in mensajes) if p]
    ignorados = len(mensajes) - len(parseados)
    parseados.sort(key=lambda c: (c["recibido"] or "", c["orden_trabajo"]))

    for c in parseados:
        if not c["aviso"]:
            sin_aviso.append(c)
            continue
        if c["accion"] == "ELIMINADA":
            eliminadas.add(c["aviso"])
            casos.pop(c["aviso"], None)
            continue
        if not c["local"]:
            sin_local.append(c)
        if c["zona_discrepa"]:
            discrepan.append(c)

        c["estado_alerta"], c["alertas"] = triage(c, dentro, fuera, confirmar)

        # El par (alerta que dio el sistema, veredicto que dio ella) es lo que
        # permitira, mas adelante, medir la tasa de falso positivo por regla y
        # automatizar filtros nuevos con evidencia. Nace vacio a proposito: lo
        # llena una persona, nunca el script.
        c["veredicto_admin"] = None
        c["veredicto_fecha"] = None
        c["veredicto_por"] = None
        c["estado_gestion"] = "NUEVO"    # NUEVO | EN_REVISION | RESUELTO

        casos[c["aviso"]] = c            # upsert por aviso: idempotente

    datos = sorted(casos.values(), key=lambda c: (c["recibido"] or ""), reverse=True)

    por_zona, por_prio, por_alerta, por_regla = {}, {}, {}, {}
    for c in datos:
        por_zona[c["zona"] or "(sin zona)"] = por_zona.get(c["zona"] or "(sin zona)", 0) + 1
        por_prio[c["prioridad"] or "(sin dato)"] = por_prio.get(c["prioridad"] or "(sin dato)", 0) + 1
        por_alerta[c["estado_alerta"]] = por_alerta.get(c["estado_alerta"], 0) + 1
        for a in c["alertas"]:
            por_regla[a["regla"]] = por_regla.get(a["regla"], 0) + 1

    salida = {
        "generado": datetime.now().strftime("%Y-%m-%d %H:%M"),
        "fuente": f"buzon {env['IMAP_USER']} (solo lectura), remitente {REMITENTE}",
        "ventana_dias": dias,
        "correos_leidos": len(mensajes),
        "alcance_version": ver_alcance,
        "las_alertas_no_deciden": (
            "Estas alertas solo marcan casos sospechosos para que la administradora "
            "los encuentre rapido. El veredicto sobre cada caso es suyo: el sistema "
            "no cierra, no rechaza y no da ningun caso por ajeno."
        ),
        "resumen": {
            "casos_vigentes": len(datos),
            "ordenes_eliminadas": len(eliminadas),
            "sin_aviso_legible": len(sin_aviso),
            "sin_local_resuelto": len(sin_local),
            "zona_discrepante": len(discrepan),
            "correos_no_reconocidos": ignorados,
            "por_zona": por_zona,
            "por_prioridad": por_prio,
            "por_estado_alerta": por_alerta,
            "por_regla": por_regla,
        },
        "revisar": {
            "sin_local": [{"orden_trabajo": c["orden_trabajo"],
                           "restaurante_sap": c["restaurante_sap"],
                           "aviso": c["aviso"]} for c in sin_local],
            "zona_discrepante": [{"aviso": c["aviso"], "local": c["local"],
                                  "por_local": c["zona_por_local"],
                                  "por_buzon": c["zona_por_buzon"]} for c in discrepan],
            "sin_aviso_legible": [{"orden_trabajo": c["orden_trabajo"],
                                   "aviso_crudo": c["aviso_crudo"]} for c in sin_aviso],
        },
        "datos": datos,
    }

    SALIDA.mkdir(parents=True, exist_ok=True)
    destino = SALIDA / "catalogos" / "casos_sap.json"
    destino.parent.mkdir(parents=True, exist_ok=True)
    destino.write_text(json.dumps(salida, ensure_ascii=False, indent=1), encoding="utf-8")

    print(f"\ncasos vigentes       : {len(datos)}")
    print(f"ordenes eliminadas   : {len(eliminadas)} (excluidas)")
    for z in sorted(por_zona):
        print(f"  {z:<14}: {por_zona[z]}")
    print("prioridad            :", ", ".join(f"{k}={v}" for k, v in sorted(por_prio.items())))
    print("\nALERTAS para la administradora (no son veredictos):")
    for e in ("CON_ALERTA", "POR_CONFIRMAR", "SIN_ALERTA"):
        print(f"  {e:<16}: {por_alerta.get(e, 0)}")
    for r, n in sorted(por_regla.items(), key=lambda x: -x[1]):
        print(f"     {r:<26} {n}")
    if sin_local:
        print(f"\nSIN LOCAL RESUELTO   : {len(sin_local)} -> no se inventa la zona")
        for c in sin_local[:8]:
            print(f"   {c['orden_trabajo']:<16} {c['restaurante_sap']}")
    if discrepan:
        print(f"\nZONA DISCREPANTE     : {len(discrepan)} (buzon vs maestro) -> revisar")
        for c in discrepan[:8]:
            print(f"   aviso {c['aviso']} {c['local']}: maestro={c['zona_por_local']} buzon={c['zona_por_buzon']}")
    if sin_aviso:
        print(f"\nSIN AVISO LEGIBLE    : {len(sin_aviso)}")
    print(f"\n-> {destino}")

    # Compuerta: si menos del 95% resuelve local, el maestro de alias tiene un
    # hueco y la bandeja mostraria casos sin zona ni destinatario.
    if datos:
        ok = sum(1 for c in datos if c["local"])
        if ok / len(datos) < 0.95:
            print(f"\nABORTA: solo {ok}/{len(datos)} casos resuelven local (<95%).", file=sys.stderr)
            sys.exit(1)


if __name__ == "__main__":
    main()
