"""
T2.11 - Qué casos ya se atendieron, y quién los atendió.

EL PROBLEMA
El buzón muestra 917 casos pendientes porque el correo de SAP avisa cuando KFC
**crea** o **elimina** un caso, pero **nunca cuando lo cierra**. Así que ahí
dentro hay trabajo ya hecho. Un ejemplo real: el caso 10334240 figura pendiente
y su orden se cerró el 12 de junio.

LA FUENTE
Al mismo buzón llegan los informes que emite el sistema actual de OTs, desde
`reclutamiento@industec.me`, con un cuerpo estructurado:

    Se ha generado una nueva OT: OT-1851-G005-10353660-UIO
    Zona: UIO
    Local: G005
    ORDEN SAP: 10353660
    Tipo de Trabajo: Correctivo
    Estado de OT: Cerrada

`ORDEN SAP` es el aviso, y es la llave que cruza con el caso. El nombre del
técnico NO está en el cuerpo: está dentro del PDF adjunto.

POR QUE NO SE BAJAN LOS 584 PDFS
Son ~300 MB por IMAP. Solo interesan los informes cuyo aviso figura hoy como
pendiente —123 de 584—, así que se filtra primero por el cuerpo, que es
liviano, y se baja el PDF únicamente de esos. El resto se cuenta y se reporta,
pero no se descarga.

DISTINCION QUE NO SE PUEDE PERDER
Que exista una OT significa que **INDUSTEC atendió**, no que **SAP cerró**.
Son dos cosas distintas y el sistema no puede confundirlas:

    Estado de OT "Cerrada"  -> INDUSTEC dio el trabajo por terminado
    Estado de OT "Abierta"  -> se atendió, sigue en curso (falta repuesto, etc.)
    sin OT                  -> nadie lo ha atendido todavía

El estado que manda frente a KFC sigue siendo el del export de SAP. Esto es
evidencia de lo nuestro, y por eso la salida se llama `atenciones`, no
`cerrados`.

ES TEMPORAL. Cuando la emisión de órdenes pase al sistema nuevo, esto sobra:
el estado se sabrá sin leer correos. Mientras tanto permite probar con datos
reales.

SOLO LECTURA. EXAMINE + BODY.PEEK[], igual que t2_6. No marca, no mueve, no
borra.

Uso:
    .venv/Scripts/python.exe scripts/t2_11_informes_ot.py            # con PDFs
    .venv/Scripts/python.exe scripts/t2_11_informes_ot.py --sin-pdf  # solo cuerpos
"""

import argparse
import collections
import email
import imaplib
import json
import re
import sys
import hashlib
import hmac
import ssl
import time
import unicodedata
import urllib.error
import urllib.request
from difflib import SequenceMatcher
from datetime import date, datetime, timedelta
import pathlib
from pathlib import Path

import mysql.connector

sys.path.insert(0, str(Path(__file__).parent))
from t1_7_extractor_pdf import extraer_pdf          # el extractor ya verificado
# Rutas, .env, empuje firmado y registro: una sola copia en comun.py (T2.15.1).
from comun import BASE, ENV_PATH, RESPALDOS, SALIDAS, abrir_log, cargar_env, empujar  # noqa: F401

SALIDA = SALIDAS / "catalogos"
CASOS = SALIDA / "casos_sap.json"
# Antes hardcodeado aqui y otra vez en t2_12_cotejo_sap_abiertas.py, con el
# mismo valor escrito a mano dos veces (hallazgo de la auditoria T2.21 sobre
# t2_12). Una sola fuente: config/.env.
EMISOR = cargar_env().get("EMISOR_OT", "reclutamiento@industec.me")

# Cada PDF pesa entre 300 KB y 1 MB, y el tecnico de una orden ya emitida no
# cambia nunca. Sin esta cache, cada corrida vuelve a bajar los mismos ~60 MB
# para llegar al mismo resultado, y programarla cada pocas horas seria absurdo.
CACHE = BASE / "config" / "cache_tecnicos.json"

# Donde se guardan los PDFs que se bajan. Van a `D:\RESPALDOS`, que es el
# almacenamiento definitivo del proyecto, no a una carpeta temporal: son los
# informes originales de ordenes que el sistema tiene que poder mostrar, y
# volver a bajarlos del correo cada vez seria absurdo.
# `_ORIGEN_BUZON`, hermano de `_ORIGEN_DRIVE` y `_ORIGEN_SISTEMA` (T2.15.3): un
# origen crudo mas, que la ingesta ignora (toda carpeta que empieza por `_`).
DIR_PDF = RESPALDOS / "_ORIGEN_BUZON"

# El cuerpo del informe, campo por campo. Si el sistema actual cambia el
# formato, lo que falla es el parseo y se reporta -- no se rellena por parecido.
def campo(texto, etiqueta):
    m = re.search(rf"^{etiqueta}:\s*(.+?)\s*$", texto, re.M | re.I)
    return m.group(1).strip() if m else None


def num_aviso(s):
    """La forma canonica de un numero de aviso: solo digitos, sin los ceros de
    delante. El informe de OT escribe 'ORDEN SAP: 10353660' y el buzon a veces
    trae '000010353660' -- es el mismo aviso. Esto NO es emparejar por parecido:
    es llevar las dos formas de escribir el mismo numero a una sola. Un numero
    con un digito de mas o de menos sigue sin cruzar, y eso se reporta."""
    d = re.sub(r"\D", "", str(s or ""))
    return d.lstrip("0") or ("0" if d else "")


def _norma(s):
    s = unicodedata.normalize("NFD", str(s or ""))
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    return re.sub(r"\s+", " ", s.upper()).strip()


def _tokens(s):
    return {p for p in re.split(r"[^A-Z]+", _norma(s)) if len(p) > 2}


# El formulario de hoy tiene UN campo de texto libre para el tecnico, y cuando
# van dos o tres a la misma visita escriben todos ahi: "Sergio Torres, Vinicio
# Campos", "Pablo Ortiz / Vinicio Campos", "Luis Erazo- Henry Melendrez".
# Tomarlo como un solo nombre deja 11 firmas sin identificar y, peor, hace
# desaparecer el trabajo del segundo tecnico. Se parte por los separadores que
# realmente usan. En el sistema nuevo esto no pasa: los tecnicos son bloques
# repetibles, no un campo de texto.
SEPARADORES = re.compile(r"\s*(?:,|/|;|\+|\sy\s|\se\s|-)\s*(?=[A-Za-zÁÉÍÓÚÑáéíóúñ])")


def separar_tecnicos(texto):
    """'Sergio Torres, Vinicio Campos' -> ['Sergio Torres', 'Vinicio Campos']."""
    if not texto:
        return []
    partes = [x.strip(" .-") for x in SEPARADORES.split(str(texto))]
    return [x for x in partes if len(_norma(x)) >= 4]


def cargar_padron():
    """Quien es quien, para no reportar nombres sueltos sin dueno."""
    env = cargar_env()
    cn = mysql.connector.connect(host=env["DB_HOST"], port=int(env["DB_PORT"]),
                                 user=env["DB_USER"], password=env["DB_PASSWORD"],
                                 database=env["DB_NAME"])
    try:
        c = cn.cursor(dictionary=True)
        c.execute("SELECT nombres, apellidos, usuario, activo FROM tecnicos")
        salida = []
        for r in c.fetchall():
            completo = (str(r["nombres"]) + " " + str(r["apellidos"])).strip()
            salida.append({"nombre": completo, "usuario": r["usuario"],
                           "activo": bool(r["activo"]), "toks": _tokens(completo)})
        return salida
    finally:
        cn.close()


def _por_tokens(parte, padron):
    t = _tokens(parte)
    if not t:
        return []
    return [x for x in padron if x["toks"] and (t <= x["toks"] or x["toks"] <= t)]


def _por_parecido(parte, padron, minimo=0.86):
    """Rescata los errores de tecleo: 'Diego Melendez' por 'Diego Melendrez'.

    Es el mismo problema que ya aparecio al conciliar el padron -- CAMPOS CHAVES
    contra CHAVEZ, GUARDILLA contra GUARQUILA. Solo se acepta si UNA sola
    persona pasa el umbral: con dos parecidas, no se elige.
    """
    a = _norma(parte)
    cerca = [(SequenceMatcher(None, a, _norma(x["nombre"])).ratio(), x) for x in padron]
    buenos = [x for r, x in cerca if r >= minimo]
    return buenos


def _por_tokens_parecidos(parte, padron, minimo=0.88):
    """Cada palabra de la firma tiene que parecerse a alguna del nombre real.

    Hace falta porque comparar cadenas enteras es demasiado estricto cuando el
    padron guarda cuatro palabras y el tecnico firma con dos: "Diego Melendez"
    contra "Diego Fernando Melendrez Sinchiguano" da un parecido bajo, aunque
    para una persona sea obvio. Palabra por palabra, DIEGO calza exacto y
    MELENDEZ contra MELENDREZ da 0,94.

    Sigue mandando la unicidad: hay dos Melendrez en el padron, y solo uno se
    llama Diego. Si el nombre fuera ambiguo, esto devuelve dos y no se elige.
    """
    t = _tokens(parte)
    if not t:
        return []
    salida = []
    for x in padron:
        if not x["toks"]:
            continue
        if all(any(SequenceMatcher(None, a, b).ratio() >= minimo for b in x["toks"]) for a in t):
            salida.append(x)
    return salida


def resolver(parte, padron):
    """Empareja una firma con una persona del padron.

    Tres pasadas, de la mas segura a la mas permisiva, y en todas rige la misma
    regla: se acepta solo si el resultado es UNICO. Con dos candidatos no se
    elige por corazonada -- se reporta sin resolver (I-7).
    """
    cand = _por_tokens(parte, padron)
    if len(cand) == 1:
        return cand[0]
    if not cand:
        cand = _por_parecido(parte, padron)
        if len(cand) == 1:
            return cand[0]
    if not cand:
        cand = _por_tokens_parecidos(parte, padron)
        if len(cand) == 1:
            return cand[0]
    return None


def resolver_varios(parte, padron):
    """Devuelve la lista de personas que hay en una firma.

    Existe porque a veces escriben dos nombres sin ningun separador:
    'Vinicio Campos Sergio Torres'. Se prueba a partir cada dos palabras, y se
    acepta SOLO si todos los pedazos resuelven: si uno queda suelto, se prefiere
    devolver la firma entera sin identificar antes que media atribucion.
    """
    uno = resolver(parte, padron)
    if uno:
        return [uno]
    palabras = parte.split()
    if len(palabras) >= 4 and len(palabras) % 2 == 0:
        pares = [" ".join(palabras[i:i + 2]) for i in range(0, len(palabras), 2)]
        hallados = [resolver(x, padron) for x in pares]
        if all(hallados):
            # Dos veces la misma persona no es dos personas: es un nombre largo.
            unicos = {h["nombre"]: h for h in hallados}
            if len(unicos) == len(hallados):
                return list(unicos.values())
    return []



def leer_informes(M, dias):
    """Cuerpos de todos los informes de la ventana. Un FETCH por lote."""
    criterio = ["FROM", EMISOR]
    if dias:
        criterio += ["SINCE", (date.today() - timedelta(days=dias)).strftime("%d-%b-%Y")]
    ok, d = M.search(None, *criterio)
    ids = d[0].split()

    informes, sin_parsear = [], []
    vistos = set()
    for i in range(0, len(ids), 60):
        lote = ids[i:i + 60]
        # BODY.PEEK[1] = solo la primera parte (el texto). El PDF no se toca.
        ok, dd = M.fetch(b",".join(lote), "(BODY.PEEK[1])")
        # El numero de mensaje se lee de la RESPUESTA, no se empareja por orden.
        # Emparejar `lote` con las respuestas usando zip() es una perdida callada:
        # si el servidor devuelve menos tuplas de las pedidas, zip trunca y esos
        # correos desaparecen sin un solo error. Aqui, lo que no vuelva se
        # detecta al final comparando contra `ids`.
        for it in dd:
            if not isinstance(it, tuple):
                continue
            m_id = re.match(rb"\s*(\d+)\s+\(", it[0] or b"")
            if not m_id:
                sin_parsear.append({"id_imap": "?", "inicio": "respuesta IMAP sin numero de mensaje"})
                continue
            n = m_id.group(1)
            vistos.add(n)
            cuerpo = it[1]
            t = (cuerpo or b"").decode("utf-8", "replace")
            m = re.search(r"nueva OT:\s*(\S+)", t)
            if not m:
                sin_parsear.append({"id_imap": n.decode(), "inicio": t.strip()[:90]})
                continue
            informes.append({
                "id_imap": n.decode(),
                "ot": m.group(1),
                "aviso": campo(t, "ORDEN SAP"),
                "estado_ot": campo(t, "Estado de OT"),
                "zona": campo(t, "Zona"),
                "local": campo(t, "Local"),
                "fecha": campo(t, "Fecha"),
                "tipo": campo(t, r"Tipo de Trabajo"),
                "equipo": campo(t, "Equipo"),
                "estado_equipo": campo(t, "Estado de Equipo"),
            })

    # Compuerta: si el servidor no devolvio todos los que dijo tener, se dice.
    faltantes = [x.decode() for x in ids if x not in vistos]
    if faltantes:
        sin_parsear.append({"id_imap": ",".join(faltantes[:20]),
                            "inicio": f"{len(faltantes)} correos que el SEARCH listo y el FETCH no devolvio"})
    return informes, sin_parsear


def cargar_cache():
    if CACHE.is_file():
        try:
            return json.loads(CACHE.read_text(encoding="utf-8"))
        except Exception:
            pass          # una cache ilegible se rehace, no rompe la corrida
    return {}


def guardar_cache(c):
    CACHE.parent.mkdir(parents=True, exist_ok=True)
    CACHE.write_text(json.dumps(c, ensure_ascii=False, indent=1), encoding="utf-8")



def tecnico_del_pdf(M, id_imap, ot=None):
    """Baja el PDF, lo GUARDA y saca el «Técnico Asignado».

    Guardarlo no es un extra: el sistema tiene que poder mostrarle al técnico el
    informe de su orden, y bajarlo del correo cada vez que alguien lo abra seria
    una conexion IMAP por clic. Se guarda con el nombre canonico de la orden,
    que es como lo pide `pdf.php`.
    """
    ok, dd = M.fetch(id_imap.encode(), "(BODY.PEEK[])")
    if ok != "OK":
        return None, "no se pudo traer el correo"
    msg = email.message_from_bytes(dd[0][1])
    for p in msg.walk():
        nombre = p.get_filename() or ""
        if not nombre.lower().endswith(".pdf"):
            continue
        datos = p.get_payload(decode=True) or b""
        if not datos:
            return None, "adjunto vacio"

        # El nombre sale del adjunto, no del asunto: es el que genero el sistema
        # que emitio la orden. Se limpia por si trae rutas.
        limpio = re.sub(r"[^A-Za-z0-9._-]", "_", nombre.rsplit("/", 1)[-1])
        DIR_PDF.mkdir(parents=True, exist_ok=True)
        destino = DIR_PDF / limpio
        if not destino.is_file():
            destino.write_bytes(datos)

        try:
            r = extraer_pdf(str(destino))
            if r.get("error"):
                return None, r["error"]
            return (r.get("tecnico_nombre") or None), None
        except Exception as e:
            return None, f"NO_LEGIBLE:{e}"
    return None, "sin PDF adjunto"



def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dias", type=int, default=90, help="ventana hacia atras")
    ap.add_argument("--sin-pdf", action="store_true",
                    help="no baja PDFs; queda sin el nombre del tecnico")
    ap.add_argument("--empujar", action="store_true",
                    help="manda el resultado al sitio, firmado")
    ap.add_argument("--log", action="store_true",
                    help="escribe en logs/ en vez de por pantalla")
    args = ap.parse_args()
    if args.log:
        abrir_log("informes")

    if not CASOS.is_file():
        sys.exit(f"Falta {CASOS}. Corre antes t2_6_imap_avisos.py")
    casos = json.loads(CASOS.read_text(encoding="utf-8"))
    pendientes = {c["aviso"]: c for c in casos["datos"] if c.get("aviso")}
    # Indice por la forma canonica del numero, para cruzar aunque una fuente
    # traiga los ceros de delante y la otra no.
    pend_por_num = {}
    for c in casos["datos"]:
        n = num_aviso(c.get("aviso"))
        if n:
            pend_por_num.setdefault(n, c["aviso"])
    print(f"casos pendientes segun el buzon: {len(pendientes)}")

    padron = cargar_padron()
    print(f"padron de tecnicos             : {len(padron)} personas")

    env = cargar_env()
    M = imaplib.IMAP4_SSL(env["IMAP_HOST"], int(env["IMAP_PORT"]))
    M.login(env["IMAP_USER"], env["IMAP_PASSWORD"])
    try:
        # readonly=True -> EXAMINE. El servidor no puede cambiar banderas.
        ok, _ = M.select("INBOX", readonly=True)
        if ok != "OK":
            sys.exit("no se pudo abrir INBOX en solo lectura")

        informes, sin_parsear = leer_informes(M, args.dias)
        print(f"informes de OT leidos          : {len(informes)}")
        if sin_parsear:
            print(f"  AVISO: {len(sin_parsear)} correos no se pudieron parsear:")
            for s in sin_parsear[:5]:
                print(f"     imap#{s['id_imap']}  {s['inicio']!r}")

        # Solo los que tocan un caso que hoy figura pendiente. Se cruza por la
        # forma canonica del numero (sin ceros de delante); si cruza asi pero no
        # literalmente, se deja el aviso tal como lo tiene el buzon para que
        # todo lo demas siga usando una sola clave.
        relevantes, casi = [], []
        for i in informes:
            if i["aviso"] in pendientes:
                relevantes.append(i)
                continue
            equiv = pend_por_num.get(num_aviso(i["aviso"]))
            if equiv:
                casi.append((i["aviso"], equiv))
                i["aviso"] = equiv
                relevantes.append(i)
        print(f"informes sobre casos pendientes: {len(relevantes)}")
        if casi:
            print(f"  {len(casi)} cruzaron por el numero sin los ceros de delante:")
            for crudo, norm in casi[:8]:
                print(f"     informe {crudo!r} -> caso {norm!r}")

        tecnicos_ok = fallos = bajados = 0
        if not args.sin_pdf:
            cache = cargar_cache()
            faltan = [i for i in relevantes if i["ot"] not in cache]
            print(f"tecnicos ya en cache           : {len(relevantes) - len(faltan)}")
            if faltan:
                print(f"bajando {len(faltan)} PDFs nuevos...")
            # La cache se guarda por OT, no por numero de mensaje IMAP: los
            # numeros de secuencia cambian cuando alguien borra un correo, y
            # entonces la cache apuntaria a otra orden.
            for n, inf in enumerate(faltan, 1):
                tec, err = tecnico_del_pdf(M, inf["id_imap"], inf["ot"])
                cache[inf["ot"]] = {"tecnico": tec, "error": err}
                bajados += 1
                if n % 25 == 0 or n == len(faltan):
                    print(f"   {n}/{len(faltan)}")
            if faltan:
                guardar_cache(cache)
            for inf in relevantes:
                d = cache.get(inf["ot"], {})
                inf["tecnico"] = d.get("tecnico")
                inf["pdf_error"] = d.get("error")
                if inf["tecnico"]:
                    tecnicos_ok += 1
                else:
                    fallos += 1
        else:
            for inf in relevantes:
                inf["tecnico"] = None
                inf["pdf_error"] = "no se pidio el PDF"
    finally:
        try:
            M.close()
        except Exception:
            pass
        M.logout()

    # --- Agrupar por aviso. Un caso puede tener varias OTs: los preventivos
    #     entran varios dias, y un correctivo puede reingresar. -------------
    por_aviso = collections.defaultdict(list)
    for i in relevantes:
        por_aviso[i["aviso"]].append(i)

    atenciones = {}
    for aviso, lista in por_aviso.items():
        lista.sort(key=lambda x: x["fecha"] or "")
        estados = [x["estado_ot"] for x in lista]
        # Basta UNA orden cerrada para que el trabajo este terminado: es la
        # orden de cierre. Mientras no exista, sigue en curso.
        estado = "CERRADA" if "Cerrada" in estados else "EN_CURSO"

        ots, quienes, sin_resolver = [], {}, set()
        for x in lista:
            personas = []
            for parte in separar_tecnicos(x.get("tecnico")):
                hallados = resolver_varios(parte, padron)
                if hallados:
                    for m in hallados:
                        personas.append({"nombre": m["nombre"], "usuario": m["usuario"],
                                         "activo": m["activo"]})
                        quienes[m["nombre"]] = m
                else:
                    # No se descarta: se muestra tal como lo escribio el tecnico
                    # y se marca que no se pudo identificar.
                    personas.append({"nombre": parte, "usuario": None, "activo": None})
                    sin_resolver.add(parte)
            ots.append({"ot": x["ot"], "fecha": x["fecha"], "estado_ot": x["estado_ot"],
                        "tecnico_texto": x.get("tecnico"), "personas": personas,
                        "equipo": x["equipo"], "estado_equipo": x["estado_equipo"]})

        atenciones[aviso] = {
            "estado_industec": estado,
            "ots": ots,
            "ultima_fecha": lista[-1]["fecha"],
            "tecnicos": sorted(quienes),
            "usuarios": sorted({v["usuario"] for v in quienes.values() if v["usuario"]}),
            "sin_identificar": sorted(sin_resolver),
        }

    cerradas = sum(1 for a in atenciones.values() if a["estado_industec"] == "CERRADA")
    firmas_raras = sorted({s for a in atenciones.values() for s in a["sin_identificar"]})
    salida = {
        "generado": datetime.now().strftime("%Y-%m-%d %H:%M"),
        "fuente": f"buzon de servicio al cliente, informes de {EMISOR}",
        "ventana_dias": args.dias,
        "que_significa": (
            "Que exista una OT significa que INDUSTEC atendio el caso, NO que KFC "
            "lo haya cerrado en SAP. El correo no avisa los cierres de SAP. El "
            "estado frente al cliente sigue siendo el del export de SAP."
        ),
        "resumen": {
            "casos_pendientes": len(pendientes),
            "con_atencion": len(atenciones),
            "sin_atencion": len(pendientes) - len(atenciones),
            "cerradas_por_industec": cerradas,
            "en_curso": len(atenciones) - cerradas,
            "informes_leidos": len(informes),
            "informes_no_parseados": len(sin_parsear),
            "tecnico_identificado": tecnicos_ok,
            "tecnico_no_identificado": fallos,
            "firmas_sin_identificar": len(firmas_raras),
        },
        "firmas_sin_identificar": firmas_raras,
        "atenciones": atenciones,
    }
    SALIDA.mkdir(parents=True, exist_ok=True)
    destino = SALIDA / "atenciones.json"
    destino.write_text(json.dumps(salida, ensure_ascii=False, indent=1), encoding="utf-8")

    print()
    print(f"casos pendientes con atencion : {len(atenciones)} de {len(pendientes)}")
    print(f"  ya cerradas por INDUSTEC    : {cerradas}")
    print(f"  atendidas, en curso         : {len(atenciones) - cerradas}")
    print(f"  con tecnico identificado    : {tecnicos_ok}")
    if fallos:
        print(f"  SIN tecnico identificado    : {fallos}  (se reportan vacios, no se inventan)")
    if firmas_raras:
        print(f"  firmas que no calzan con el padron: {len(firmas_raras)}")
        for f in firmas_raras[:10]:
            print(f"     {f!r}")
    print(f"\n{destino}")
    if args.empujar:
        empujar(cargar_env(), destino.read_bytes(), "atenciones")


if __name__ == "__main__":
    main()
