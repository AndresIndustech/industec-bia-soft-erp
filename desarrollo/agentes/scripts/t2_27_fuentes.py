"""
T2.27 — Las fuentes de los reportes para Grupo KFC, en un solo sitio.

POR QUE EXISTE
Los reportes que la administración le manda a KFC cada semana se arman hoy a
mano con cuatro fuentes que no se hablan entre sí: el Excel que KFC manda los
lunes (el export de SAP, ~44.000 filas), las órdenes de INDUSTEC (la base
local), el reporte que ella misma mandó la semana anterior (lo único que guarda
su criterio: presupuesto y responsables) y el maestro de locales. Cada
generador de T2.27 lee de aquí para que todos digan lo mismo.

LO QUE ESTE MÓDULO NO HACE
No escribe en el correo, ni en la base, ni en el Drive. El buzón se abre con
EXAMINE (select readonly) y se lee con BODY.PEEK: ningún mensaje cambia de
estado. Lo que baja se guarda en SALIDAS IA\\REPORTES\\KFC\\_ENTRADAS.
"""
from __future__ import annotations

import collections
import datetime as dt
import email
import email.header
import email.utils
import imaplib
import pickle
import re
import sys
from pathlib import Path

import mysql.connector
import openpyxl

BASE = Path(__file__).resolve().parents[1]
RAIZ = BASE.parents[1]
SALIDAS = RAIZ / "SALIDAS IA" / "REPORTES" / "KFC"
ENTRADAS = SALIDAS / "_ENTRADAS"

# Etiquetas que usa la administración en sus reportes. La zona canónica del
# maestro es CNLJ, pero ella escribe «ZONA C-L» y «CUENCA-LOJA» (y KFC también).
ETIQUETA_ZONA = {"UIO": "ZONA UIO", "LARB": "ZONA LARB", "CNLJ": "ZONA C-L"}
ORDEN_ZONA = {"UIO": 0, "LARB": 1, "CNLJ": 2}

# Lo que un técnico escribe en «repuestos» cuando NO pidió nada. Una orden de
# evaluación sin repuesto no está esperando nada de KFC: no es un pendiente.
SIN_REPUESTO = {"", "NINGUNO", "NINGUNA", "N/A", "NA", "-", "NO", "NO APLICA", "S/N", "0"}


def env() -> dict:
    datos = {}
    for linea in (BASE / "config" / ".env").read_text(encoding="utf-8").splitlines():
        if "=" in linea and not linea.strip().startswith("#"):
            k, v = linea.split("=", 1)
            datos[k.strip()] = v.strip().strip('"').strip("'")
    return datos


def conectar():
    e = env()
    return mysql.connector.connect(host=e["DB_HOST"], port=int(e["DB_PORT"]), user=e["DB_USER"],
                                   password=e["DB_PASSWORD"], database=e["DB_NAME"])


def tiene_repuesto(texto: str | None) -> bool:
    t = (texto or "").strip().upper()
    return t not in SIN_REPUESTO and len(t) > 2


# ---------------------------------------------------------------------------
#  El correo, en solo lectura
# ---------------------------------------------------------------------------

def _decodificar(s: str | None) -> str:
    if not s:
        return ""
    partes = []
    for t, enc in email.header.decode_header(s):
        partes.append(t.decode(enc or "utf-8", "replace") if isinstance(t, bytes) else t)
    return "".join(partes)


def bajar_ultimo_adjunto(carpeta: str, patron: str, dias: int = 21, antes_de: dt.date | None = None) -> tuple[Path, dt.datetime] | None:
    """Baja el adjunto más reciente de `carpeta` cuyo nombre calce con `patron`.

    Solo lectura: EXAMINE y BODY.PEEK[]. Si el archivo ya está en _ENTRADAS con el
    mismo tamaño no se vuelve a escribir. Devuelve (ruta, fecha del correo) o None.
    """
    e = env()
    rx = re.compile(patron, re.I)
    ENTRADAS.mkdir(parents=True, exist_ok=True)
    M = imaplib.IMAP4_SSL(e["IMAP_HOST"], int(e["IMAP_PORT"]))
    try:
        M.login(e["IMAP_USER"], e["IMAP_PASSWORD"])
        typ, _ = M.select(f'"{carpeta}"', readonly=True)
        if typ != "OK":
            raise SystemExit(f"No se pudo abrir la carpeta {carpeta!r} del correo en modo lectura.")
        desde = (dt.date.today() - dt.timedelta(days=dias)).strftime("%d-%b-%Y")
        typ, ids = M.search(None, f"SINCE {desde}")
        mejor = None
        for i in reversed(ids[0].split()):
            typ, d = M.fetch(i, "(BODY.PEEK[HEADER.FIELDS (DATE)] BODYSTRUCTURE)")
            estructura = b" ".join(p[0] if isinstance(p, tuple) else p for p in d)
            nombres = [_decodificar(n.decode("utf-8", "replace")) for n in re.findall(rb'"(?:NAME|FILENAME)" "([^"]+)"', estructura, re.I)]
            if not any(rx.search(n) for n in nombres):
                continue
            cab = email.message_from_bytes(next(p[1] for p in d if isinstance(p, tuple)))
            fecha = email.utils.parsedate_to_datetime(cab["Date"])
            if antes_de and fecha.date() >= antes_de:
                continue
            if mejor is None or fecha > mejor[1]:
                mejor = (i, fecha)
            break  # los id más altos son los más nuevos: el primero que calza basta
        if mejor is None:
            return None
        typ, d = M.fetch(mejor[0], "(BODY.PEEK[])")
        msg = email.message_from_bytes(next(p[1] for p in d if isinstance(p, tuple)))
        for parte in msg.walk():
            nombre = _decodificar(parte.get_filename())
            if nombre and rx.search(nombre):
                datos = parte.get_payload(decode=True)
                destino = ENTRADAS / f"{mejor[1]:%Y-%m-%d} {carpeta} {nombre}"
                if not (destino.exists() and destino.stat().st_size == len(datos)):
                    destino.write_bytes(datos)
                return destino, mejor[1]
        return None
    finally:
        try:
            M.logout()
        except Exception:
            pass


# ---------------------------------------------------------------------------
#  El Excel semanal de KFC («REPORTE 2026 SEMANA NN - MANTENIMIENTO CORRECTIVO»)
# ---------------------------------------------------------------------------

# Encabezados de la hoja FILTRO que se usan. Se localizan POR NOMBRE: KFC agrega
# o mueve columnas entre semanas (la 38 trajo la pestaña #O_ND nueva).
COLUMNAS_SAP = {
    "AVISO": "aviso", "Fecha notificación": "fecha_notificacion", "Descripción": "clase",
    "DESCRIPCIÓN": "descripcion", "Estatus del Aviso": "estatus_aviso", "ESTATUS A": "estatus_a",
    "Estatus 2 del Aviso": "estatus_aviso_2", "ESTATUS B": "estatus_b", "Orden": "orden",
    "Fecha Creación Orden": "fecha_orden", "Cierre técnico": "cierre_tecnico",
    "Estatus de la Orden": "estatus_orden", "ESTATUS C": "estatus_c", "Estatus 2 de la Orden": "estatus_orden_2",
    "RESPONSABLE": "responsable", "Circunstancia": "circunstancia", "Denominación objeto": "denominacion",
    "ÁREA": "area", "Local": "local", "PROVEEDOR": "proveedor", "REGIÓN": "region",
    "Equipo": "equipo_sap",
}
# Sin estas no hay reporte. Las demás son opcionales: KFC agrega y quita columnas entre
# semanas (las semanas 32, 34 y 36 no traen «ESTATUS B» ni «ÁREA») y eso no debe tumbar nada.
OBLIGATORIAS_SAP = {"aviso", "fecha_notificacion", "descripcion", "estatus_a", "proveedor", "local"}


def _fecha(v):
    if isinstance(v, dt.datetime):
        return v.date()
    return v if isinstance(v, dt.date) else None


def leer_sap_semanal(ruta: Path) -> dict:
    """{aviso: {campo: valor}} de la hoja FILTRO. Una fila por aviso (la primera).

    FILTRO repite el aviso una vez por línea de material: 44.259 filas para
    ~36.000 avisos en la semana 39. Se guarda en caché junto al archivo porque
    leerlo toma unos segundos y cada generador lo necesita.
    """
    cache = ruta.with_suffix(ruta.suffix + ".cache.pkl")
    firma = (ruta.stat().st_size, ruta.stat().st_mtime)
    if cache.exists():
        try:
            guardado = pickle.loads(cache.read_bytes())
            if guardado.get("firma") == firma:
                return guardado["datos"]
        except Exception:
            pass
    wb = openpyxl.load_workbook(ruta, read_only=True, data_only=True)
    if "FILTRO" not in wb.sheetnames:
        raise SystemExit(f"{ruta.name}: no tiene la hoja FILTRO; ¿cambió el formato del reporte de KFC?")
    filas = wb["FILTRO"].iter_rows(values_only=True)
    enc = [str(h).strip() if h is not None else "" for h in next(filas)]
    # Sinónimos que usa KFC según el archivo: el «ACTUALIZADO» del viernes llama «Notificación»
    # al aviso, y algunas tablas dicen «DESCRIPCIÓN2». Solo se aplican si falta el nombre de siempre.
    for sinonimo, nombre in (("Notificación", "AVISO"), ("DESCRIPCIÓN2", "DESCRIPCIÓN")):
        if nombre not in enc and sinonimo in enc:
            enc[enc.index(sinonimo)] = nombre
    idx, faltan = {}, []
    for nombre, campo in COLUMNAS_SAP.items():
        if nombre in enc:
            idx[campo] = enc.index(nombre)
        elif campo in OBLIGATORIAS_SAP:
            raise SystemExit(f"{ruta.name}: falta la columna {nombre!r} en FILTRO. Columnas: {enc}")
        else:
            faltan.append(nombre)
    datos, repetidas = {}, 0
    for r in filas:
        if not r or r[idx["aviso"]] in (None, ""):
            continue
        aviso = str(int(r[idx["aviso"]])) if isinstance(r[idx["aviso"]], float) else str(r[idx["aviso"]]).strip()
        if aviso in datos:
            repetidas += 1
            continue
        fila = {campo: (r[i] if i < len(r) else None) for campo, i in idx.items()}
        for nombre in faltan:
            fila[COLUMNAS_SAP[nombre]] = None
        for f in ("fecha_notificacion", "fecha_orden", "cierre_tecnico"):
            fila[f] = _fecha(fila[f])
        for k, v in fila.items():
            if isinstance(v, str):
                fila[k] = v.strip()
        fila["aviso"] = aviso
        datos[aviso] = fila
    wb.close()
    cache.write_bytes(pickle.dumps({"firma": firma, "datos": datos}))
    print(f"  SAP semanal {ruta.name}: {len(datos)} avisos ({repetidas} filas repetidas por material)")
    return datos


def semana_del_reporte_kfc(ruta: Path) -> int | None:
    m = re.search(r"SEMANA\s+(\d{1,2})", ruta.name, re.I)
    return int(m.group(1)) if m else None


# ---------------------------------------------------------------------------
#  La base local
# ---------------------------------------------------------------------------

def zonas_de_locales(cnx) -> dict:
    cur = cnx.cursor()
    cur.execute("SELECT local_codigo, zona FROM locales")
    z = {k.upper(): zona for k, zona in cur.fetchall()}
    cur.execute("SELECT a.alias_texto, l.zona FROM locales_alias a JOIN locales l ON l.local_codigo = a.local_codigo")
    for alias, zona in cur.fetchall():
        z.setdefault(str(alias).upper(), zona)
    return z


def zona_de(local: str | None, zonas: dict) -> str | None:
    loc = (local or "").upper().strip()
    return zonas.get(loc) or zonas.get(loc + "EC")


def ordenes_por_aviso(cnx, hasta: dt.date) -> dict:
    """{aviso: [orden, ...]} con el primer equipo de cada orden, hasta la fecha de corte.

    Solo órdenes activas (en_cuarentena = 0) y reales (correlativo < 90000): las
    sintéticas de prueba no son trabajo. Ordenadas por fecha de atención.
    """
    cur = cnx.cursor(dictionary=True)
    cur.execute("""
        SELECT o.id_industec, o.aviso, o.modulo, o.fase, o.zona, o.local_codigo, o.fecha_atencion,
               o.estado_ot, o.tecnico_nombre, o.actividades, o.repuestos, o.observaciones,
               e.equipo, e.marca, e.estado_equipo
          FROM ots o
          LEFT JOIN ot_equipos e ON e.id_industec = o.id_industec AND e.orden = 0
         WHERE o.en_cuarentena = 0 AND o.correlativo < 90000
           AND o.aviso IS NOT NULL AND o.aviso <> ''
           AND o.fecha_atencion IS NOT NULL AND o.fecha_atencion <= %s
         ORDER BY o.fecha_atencion, o.id_industec""", (hasta,))
    por = collections.defaultdict(list)
    for r in cur.fetchall():
        por[str(r["aviso"]).strip()].append(r)
    return por


def fijar_valores_de_formulas(ruta: Path, valores: dict[str, dict[str, object]]) -> int:
    """Escribe el valor calculado dentro de cada fórmula: {nombre de hoja: {"A5": 45, ...}}.

    openpyxl guarda las fórmulas con el valor vacío (<v></v>) y confía en que Excel
    recalcule al abrir. La vista previa del correo y los visores del celular NO
    recalculan: el RESUMEN que llega a KFC se vería en ceros. Aquí se reescribe el
    XML de la hoja para dejar el valor junto a la fórmula, como lo guarda Excel.
    Devuelve cuántas celdas se fijaron; aborta si alguna no se encontró.
    """
    import zipfile
    from xml.sax.saxutils import escape
    with zipfile.ZipFile(ruta) as z:
        contenido = {n: z.read(n) for n in z.namelist()}
    libro = contenido["xl/workbook.xml"].decode("utf-8")
    rels = contenido["xl/_rels/workbook.xml.rels"].decode("utf-8")
    destino_de = dict(re.findall(r'<Relationship[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"', rels))
    destino_de.update({i: t for t, i in re.findall(r'<Relationship[^>]*Target="([^"]+)"[^>]*Id="([^"]+)"', rels)})
    fijadas = 0
    for nombre, rid in re.findall(r'<sheet[^>]*name="([^"]+)"[^>]*r:id="([^"]+)"', libro):
        if nombre not in valores:
            continue
        parte = "xl/" + destino_de[rid].lstrip("/").replace("xl/", "", 1)
        xml = contenido[parte].decode("utf-8")
        for celda, v in valores[nombre].items():
            patron = re.compile(rf'(<c r="{celda}"[^>]*?)(\s+t="[^"]*")?(>)(<f>.*?</f>)<v\s*/?>(?:</v>)?')
            if isinstance(v, str):
                reemplazo = rf'\1 t="str"\3\4<v>{escape(v)}</v>'
            else:
                reemplazo = rf"\1\3\4<v>{v}</v>"
            xml, n = patron.subn(reemplazo, xml, count=1)
            if n != 1:
                raise SystemExit(f"ABORTADO: no encontré la fórmula de {nombre}!{celda} en {ruta.name} para fijar su valor.")
            fijadas += 1
        contenido[parte] = xml.encode("utf-8")
    tmp = ruta.with_suffix(".tmp")
    with zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED) as z:
        for n, datos in contenido.items():
            z.writestr(n, datos)
    tmp.replace(ruta)
    return fijadas


def consultar_servidor(sql: str, parametros: list | None = None) -> list[dict]:
    """Un SELECT contra la base del sitio de pruebas, por SSH. Solo lectura: rechaza lo demás.

    Se usa el mismo camino que las baterías de pruebas (php -r con Db::todos en el sitio),
    con la llave de la estación (config/clave_hostinger) o la de INDUSTEC_LLAVE_SSH.
    """
    import json
    import os
    import subprocess
    if not re.match(r"^\s*SELECT\b", sql, re.I) or ";" in sql.strip().rstrip(";"):
        raise SystemExit("consultar_servidor solo acepta un SELECT.")
    llave = os.environ.get("INDUSTEC_LLAVE_SSH") or str(BASE / "config" / "clave_hostinger")
    sitio = "domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot"
    php = ('require "nucleo/Db.php"; $in = json_decode(stream_get_contents(STDIN), true); '
           'echo json_encode(Db::todos($in["q"], $in["p"]));')
    r = subprocess.run(["ssh", "-i", llave, "-o", "IdentitiesOnly=yes", "-p", "65002", "-o", "BatchMode=yes",
                        "-o", "ConnectTimeout=20", "u671729428@82.25.73.181", f"cd {sitio} && php -r '{php}'"],
                       input=json.dumps({"q": sql, "p": parametros or []}), capture_output=True, text=True,
                       encoding="utf-8", timeout=120)
    if r.returncode != 0:
        raise SystemExit(f"No se pudo leer el servidor ({r.returncode}): {r.stderr.strip()[:200]}")
    return json.loads(r.stdout)


def martes_de_envio(hoy: dt.date) -> dt.date:
    """El martes de la semana de `hoy` (el reporte de pendientes sale los martes)."""
    return hoy - dt.timedelta(days=(hoy.weekday() - 1) % 7)


MESES = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto",
         "septiembre", "octubre", "noviembre", "diciembre"]
MES3 = ["ENE", "FEB", "MAR", "ABR", "MAY", "JUN", "JUL", "AGO", "SEPT", "OCT", "NOV", "DIC"]


def ordinal_en_el_mes(d: dt.date) -> int:
    """1-sep → 1, 8-sep → 2, 15-sep → 3, 22-sep → 4: la regla del nombre del archivo."""
    return (d.day - 1) // 7 + 1


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    print("Módulo de fuentes de T2.27; se usa desde los generadores t2_27_*.py")
