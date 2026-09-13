"""verificar_http.py — Pruebas por rol contra el sitio de pruebas, entrando de verdad
con las cuentas de prueba (T2.12.3, T2.12.4, T2.12.5, T2.12.6, T2.12.9, T2.13.1 y
S2 · T2.14.2 -- asignación, buzón y panel por zona).

Requiere haber corrido antes ~/respaldos/preparar_prueba.php en el servidor. Las
claves se leen por SSH de ~/respaldos/claves_prueba.json y solo viven en memoria.

Uso:  python verificar_http.py
Llave SSH: INDUSTEC_LLAVE_SSH, o si no la de la estación (desarrollo/agentes/config/clave_hostinger),
o si no la del PC de Andrés (~/.ssh/industec_hostinger_pc). La evidencia va a resultado_http.json en la
carpeta temporal (o en INDUSTEC_PRUEBAS_SALIDA). Sale con 1 si algo falla.
"""
import datetime
import http.cookiejar
import json
import os
import re
import ssl
import subprocess
import time
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

# En Windows la consola es cp1252 y la flecha «→» de los mensajes reventaba la
# prueba antes de la primera comprobación (AUDITORIA_2026-09-12, P-07).
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

AQUI = Path(__file__).resolve().parent
REPO = AQUI.parents[3]
SALIDA = Path(os.environ.get("INDUSTEC_PRUEBAS_SALIDA", tempfile.gettempdir()))
BASE = "https://darkviolet-armadillo-872352.hostingersite.com/ot/"
D = "domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot"
_ESTACION = REPO / "desarrollo" / "agentes" / "config" / "clave_hostinger"
LLAVE = os.environ.get("INDUSTEC_LLAVE_SSH") or str(_ESTACION if _ESTACION.is_file()
                                                    else Path.home() / ".ssh" / "industec_hostinger_pc")
SSH = ["ssh", "-i", LLAVE, "-o", "IdentitiesOnly=yes", "-p", "65002", "-o", "BatchMode=yes",
       "-o", "ConnectTimeout=20", "-o", "StrictHostKeyChecking=accept-new", "u671729428@82.25.73.181"]
ERRORES_PHP = ("Fatal error", "Parse error", "Warning:", "Notice:", "Deprecated:", "Uncaught")

resultados = []


def anotar(clave, que, ok, obtenido, esperado=""):
    resultados.append({"id": clave, "que": que, "ok": bool(ok), "obtenido": str(obtenido)[:160], "esperado": esperado})
    print(f"  {'PASA ' if ok else 'FALLA'} {clave:<9} {que:<66} {str(obtenido)[:70]}")


def ssh(cmd, entrada=None):
    # Con tres baterías a la vez, Hostinger corta alguna conexión SSH sin
    # decir nada (código 255, stderr vacío). Es un tropiezo de red, no un
    # fallo del sistema: se reintenta una vez antes de abandonar la prueba.
    for intento in (1, 2):
        r = subprocess.run(SSH + [cmd], input=entrada, capture_output=True, text=True, timeout=120)
        if r.returncode == 0:
            return r.stdout
        transitorio = r.returncode == 255 or not r.stderr.strip()
        if intento == 1 and transitorio:
            time.sleep(3)
            continue
        raise RuntimeError(f"ssh falló ({r.returncode}): {r.stderr.strip()[:200]}")


def ejecutar(q, p=None):
    """Una escritura en la base del sitio de pruebas (solo sobre datos de prueba)."""
    php = ('require "nucleo/Db.php"; $in = json_decode(stream_get_contents(STDIN), true); '
           'echo Db::ejecutar($in["q"], $in["p"]);')
    return int(ssh(f"cd {D} && php -r '{php}'", json.dumps({"q": q, "p": p or []})))


def sql(q, p=None):
    php = ('require "nucleo/Db.php"; $in = json_decode(stream_get_contents(STDIN), true); '
           'echo json_encode(Db::todos($in["q"], $in["p"]));')
    return json.loads(ssh(f"cd {D} && php -r '{php}'", json.dumps({"q": q, "p": p or []})))


class SinRedireccion(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Sesion:
    def __init__(self, usuario):
        self.usuario = usuario
        # En el PC de Andrés el antivirus (Avast) inspecciona el HTTPS con una raíz
        # propia sin «Basic Constraints» crítico, que Python 3.13+ rechaza por
        # VERIFY_X509_STRICT. Se sigue verificando cadena y nombre; solo se quita
        # esa rigidez adicional.
        ctx = ssl.create_default_context()
        ctx.verify_flags &= ~ssl.VERIFY_X509_STRICT
        self.op = urllib.request.build_opener(
            urllib.request.HTTPSHandler(context=ctx),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), SinRedireccion)

    def pedir(self, ruta, form=None, cuerpo_json=None):
        cab = {"User-Agent": "verificar_http/1.0 (pruebas T2.12 desde el PC)"}
        datos = None
        # Desde la 009 todo POST lleva el token CSRF (formulario: campo `csrf`;
        # JSON del celular: cabecera X-Csrf, la que manda cola.js). El arnés lo
        # pone solo, salvo que la prueba lo mande a propósito (o lo omita para
        # comprobar el 403: entonces pasa form con "csrf": None).
        if cuerpo_json is not None:
            datos = json.dumps(cuerpo_json).encode("utf-8")
            cab["Content-Type"] = "application/json"
            if getattr(self, "csrf", ""):
                cab["X-Csrf"] = self.csrf
        elif form is not None:
            form = dict(form)
            if "csrf" not in form and getattr(self, "csrf", ""):
                form["csrf"] = self.csrf
            form = {k: v for k, v in form.items() if v is not None}
            datos = urllib.parse.urlencode(form).encode("utf-8")
            cab["Content-Type"] = "application/x-www-form-urlencoded"
        req = urllib.request.Request(BASE + ruta, data=datos, headers=cab)
        try:
            r = self.op.open(req, timeout=60)
            return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode("utf-8", "replace")

    def entrar(self, clave):
        st, cab, _ = self.pedir("login.php", form={"usuario": self.usuario, "clave": clave, "desplazar": "1"})
        # El token de esta sesión sale de yo.php, como en el celular.
        self.csrf = ""
        try:
            sty, _, cy = self.pedir("yo.php")
            if sty == 200:
                self.csrf = (json.loads(cy) or {}).get("csrf", "") or ""
        except Exception:
            self.csrf = ""
        return st, cab.get("Location", "")


def main():
    claves = json.loads(ssh("cat ~/respaldos/claves_prueba.json"))
    ids = claves["ids"]
    reg = json.loads(ssh("cat ~/respaldos/prueba_deshacer.json"))
    elegidos = reg["elegidos"]
    pen_uio, pen_cnlj = reg["pendientes"]["uio"], reg["pendientes"]["cnlj"]
    nov_cnlj = reg["novedades"]["cnlj"]
    cnlj_muestra = [r["aviso"] for r in sql(
        "SELECT aviso FROM casos_gestion WHERE zona = 'CNLJ' ORDER BY tocado_en DESC LIMIT 5")]
    inicio_prueba = sql("SELECT NOW() n")[0]["n"]

    s = {u: Sesion(u) for u in claves["claves"]}
    print("== ingreso ==")
    for u, se in s.items():
        st, loc = se.entrar(claves["claves"][u])
        anotar("ingreso", f"{u} entra (302 al panel o a su pantalla)", st == 302 and "login.php" not in loc, f"{st} → {loc}")

    print("\n== T2.12.3 · pantallas por rol: sin errores de PHP ni en blanco ==")
    pantallas = ["panel.php", "casos.php", "asignacion.php", "pendientes.php", "novedades_visita.php",
                 "ordenes.php", "reportes.php", "usuarios.php", "mis.php", "clave.php",
                 "cronograma.html", "index.html"]
    for u in ["admin_prueba", "jefe_prueba_uio", "tec_prueba_uio_a"]:
        for p in pantallas:
            st, cab, cuerpo = s[u].pedir(p)
            errores = [e for e in ERRORES_PHP if e in cuerpo]
            if st == 200:
                ok = not errores and len(cuerpo) > 300
            else:
                ok = st in (302, 403) and not errores
            anotar("T2.12.3", f"{u}: {p}", ok, f"{st} · {len(cuerpo)} B" + (f" · {errores}" if errores else "")
                   + (f" → {cab.get('Location', '')}" if st == 302 else ""))

    print("\n== T2.12.6 · extremos de datos con sesión ==")
    st, _, c = s["tec_prueba_uio_a"].pedir("catalogos.php")
    cat_a = json.loads(c) if st == 200 else {}
    avisos_a = sorted(a["aviso"] for a in cat_a.get("avisos", {}).get("datos", []))
    # Desde T2.13.2 el formulario ofrece los casos ABIERTOS del técnico según la base,
    # incluidos los sintéticos que no están en el catálogo (verificar_bandeja.py).
    abiertos_a = sorted(r["aviso"] for r in sql(
        "SELECT aviso FROM casos_gestion WHERE asignado_a = ? AND estado IN ('ASIGNADO','ESPERA_REPUESTO')",
        [ids["tec_prueba_uio_a"]]))
    anotar("T2.12.6", "técnico A: catalogos.php → exactamente sus casos abiertos", avisos_a == abiertos_a, avisos_a, abiertos_a)
    st, _, c = s["tec_prueba_uio_b"].pedir("catalogos.php")
    avisos_b = [a["aviso"] for a in (json.loads(c) if st == 200 else {}).get("avisos", {}).get("datos", [])]
    anotar("T2.12.6", "técnico B (sin casos): catalogos.php → ningún aviso", st == 200 and avisos_b == [], f"{st} · {avisos_b}")
    for u, z in [("jefe_prueba_uio", "UIO"), ("jefe_prueba_cnlj", "CNLJ")]:
        st, _, c = s[u].pedir("cronograma.php")
        j = json.loads(c) if st == 200 else {}
        zonas = sorted({x.get("zona") for x in j.get("correctivos", [])})
        anotar("T2.12.6", f"{u}: cronograma.php → alcance {z} y correctivos solo de {z}",
               st == 200 and j.get("alcance", {}).get("zona") == z and zonas in ([z], []), f"{st} · alcance={j.get('alcance', {}).get('zona')} · zonas={zonas}")
    st, _, c = s["tec_prueba_uio_a"].pedir("yo.php")
    yo = json.loads(c) if st == 200 else {}
    anotar("T2.13.1", "técnico A: yo.php trae su nombre de la sesión", yo.get("nombre") == "Prueba Tecnico Uio A", yo.get("nombre"))

    print("\n== T2.12.4 · alcance en las pantallas (HTML) ==")
    st, _, c = s["jefe_prueba_uio"].pedir("casos.php?zona=CNLJ")
    fuga = [a for a in cnlj_muestra if a in c]
    anotar("T2.12.4", "jefe UIO pidiendo ?zona=CNLJ en casos.php → ningún caso de CNLJ", st == 200 and not fuga, f"{st} · fuga={fuga}")
    for u, debe in [("jefe_prueba_uio", False), ("jefe_prueba_cnlj", True), ("admin_prueba", True)]:
        st, _, c = s[u].pedir("pendientes.php")
        anotar("T2.12.4", f"{u}: pendientes.php {'muestra' if debe else 'NO muestra'} el pendiente de CNLJ", ("99990001" in c) == debe, f"{st} · {'lo muestra' if '99990001' in c else 'no lo muestra'}")
        st, _, c = s[u].pedir("novedades_visita.php")
        anotar("T2.12.4", f"{u}: novedades_visita.php {'muestra' if debe else 'NO muestra'} la novedad de CNLJ", ("PRUEBA de alcance" in c) == debe, f"{st} · {'la muestra' if 'PRUEBA de alcance' in c else 'no la muestra'}")

    print("\n== T2.12.9 · el reloj de 48 h: la lista de vencidos ==")
    for u, debe in [("admin_prueba", True), ("jefe_prueba_cnlj", True), ("jefe_prueba_uio", False)]:
        st, _, c = s[u].pedir("pendientes.php?g=vencidos")
        anotar("T2.12.9", f"{u}: ?g=vencidos {'incluye' if debe else 'no incluye'} el vencido de CNLJ", ("99990001" in c) == debe, f"{st}")

    print("\n== T2.12.5 · POST fabricado a mano contra otra zona ==")
    st, _, c = s["jefe_prueba_uio"].pedir("pendientes.php", form={"accion": "veredicto", "pendiente_id": pen_cnlj, "via": "REPUESTO", "nota": "POST fabricado (prueba T2.12.5)"})
    fila = sql("SELECT via, estado FROM pendientes WHERE pendiente_id = ?", [pen_cnlj])[0]
    anotar("T2.12.5", "jefe UIO da veredicto a un pendiente de CNLJ → no cambia", fila["via"] == "SIN_VEREDICTO", f"{st} · via={fila['via']}")
    st, _, c = s["jefe_prueba_uio"].pedir("novedades_visita.php", form={"accion": "resolver", "novedad_id": nov_cnlj, "estado": "DESCARTADA", "nota": "POST fabricado (prueba T2.12.5)", "aviso_sap": ""})
    fila = sql("SELECT estado FROM novedades WHERE novedad_id = ?", [nov_cnlj])[0]
    anotar("T2.12.5", "jefe UIO resuelve una novedad de CNLJ → no cambia", fila["estado"] == "REPORTADA", f"{st} · estado={fila['estado']}")
    rech = sql("SELECT accion, detalle FROM bitacora WHERE usuario_id = ? AND exito = 0 AND cuando >= ?", [ids["jefe_prueba_uio"], inicio_prueba])
    anotar("T2.12.5", "los dos rechazos quedan en la bitácora con exito = 0", len(rech) >= 2, f"{len(rech)} filas: {[r['accion'] for r in rech]}")

    print("\n== T2.13.1 · la orden que manda la app ==")
    aviso = elegidos[1]
    caso = next((a for a in cat_a.get("avisos", {}).get("datos", []) if a["aviso"] == aviso), {})
    local = caso.get("local")
    equipos = (cat_a.get("equipos") or {}).get(local) or []
    eq = ({"equipo_sap": str(equipos[0].get("equipo_sap")), "tipo": equipos[0].get("tipo", "")} if equipos
          else {"tipo": (cat_a.get("tipos") or ["FREIDORA"])[0]})
    hoy = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=-5))).date().isoformat()
    orden = {"local": local, "aviso": aviso, "tipo": "CORRECTIVO", "equipos": [eq], "uso_repuesto": False,
             "repuestos": "", "fecha_atencion": hoy, "inicio": f"{hoy} 08:00", "fin": f"{hoy} 09:30",
             "actividades": "PRUEBA automatizada de recepción de órdenes (T2.13.1): no es una intervención real.",
             "firma_presente": True, "fotos_cantidad": 1, "tecnico": "Cualquier nombre que mande el celular", "concluida": True}
    u_envio = str(uuid.uuid4())
    cuerpo = {"envio_uuid": u_envio, "usuario_captura": ids["tec_prueba_uio_a"],
              "capturada_en": datetime.datetime.now(datetime.timezone.utc).isoformat(), "orden": orden}
    st, _, c = s["tec_prueba_uio_a"].pedir("envio.php", cuerpo_json=cuerpo)
    anotar("T2.13.1", "técnico A envía una orden válida → 200 con recibo", st == 200 and '"ok":true' in c.replace(" ", ""), f"{st} · {c[:110]}")
    st2, _, c2 = s["tec_prueba_uio_a"].pedir("envio.php", cuerpo_json=cuerpo)
    anotar("T2.13.1", "el mismo envío otra vez → 200 y «ya se había recibido»", st2 == 200 and "ya se hab" in c2, f"{st2} · {c2[:110]}")
    n = sql("SELECT COUNT(*) n, MAX(JSON_UNQUOTE(JSON_EXTRACT(carga, '$.tecnico'))) t FROM ot_capturadas WHERE envio_uuid = ?", [u_envio])[0]
    anotar("T2.13.1", "una sola fila en ot_capturadas para ese envío", int(n["n"]) == 1, f"COUNT = {n['n']}")
    anotar("T2.13.1", "la orden queda firmada con el nombre de la SESIÓN, no con el del celular", (n["t"] or "").startswith("Prueba Tecnico Uio A"), n["t"])
    st, _, c = s["tec_prueba_uio_b"].pedir("envio.php", cuerpo_json=cuerpo)
    anotar("T2.13.1", "el mismo cuerpo desde la sesión del técnico B → 409", st == 409, f"{st} · {c[:90]}")
    otro = dict(cuerpo, usuario_captura=ids["tec_prueba_uio_b"])
    st, _, c = s["tec_prueba_uio_b"].pedir("envio.php", cuerpo_json=otro)
    anotar("T2.13.1", "el técnico B reusando el uuid de A → 409, no se reasigna", st == 409, f"{st} · {c[:90]}")
    st, _, c = s["tec_prueba_uio_a"].pedir("envio.php")
    anotar("T2.13.1", "GET a envio.php → 405", st == 405, st)

    print("\n== S2 · T2.14.2 · asignación y buzón por zona ==")
    # yo.php trae el csrf de cada sesión (cacheado por el celular; aquí se pide
    # una vez por cuenta, igual que haría cola.js).
    csrf = {}
    for u in ["admin_prueba", "jefe_prueba_uio"]:
        _, _, cy = s[u].pedir("yo.php")
        csrf[u] = (json.loads(cy) if cy else {}).get("csrf", "")

    st, _, c = s["admin_prueba"].pedir("asignacion.php")
    bloques = len(re.findall(r'zona-bloque zona-(?:uio|larb|cnlj)', c))
    anotar("S2.ASG07", "admin: asignacion.php tiene un bloque por zona (UIO/LARB/CNLJ)",
           st == 200 and bloques == 3, f"{st} · bloques={bloques}")

    tecs_uio = {int(r["usuario_id"]) for r in sql(
        "SELECT usuario_id FROM usuarios WHERE zona = 'UIO' AND rol IN ('TECNICO','JEFE_ZONA') AND activo = 1")}
    m = re.search(r'id="zona-UIO".*?(?=id="zona-|$)', c, re.S)
    m2 = re.search(r'<select name="tecnico"[^>]*>(.*?)</select>', m.group(0), re.S) if m else None
    antes_opt = m2.group(1).split('<optgroup')[0] if m2 else ""
    ids_antes = {int(x) for x in re.findall(r'<option value="(\d+)"', antes_opt)}
    anotar("S2.ASG03", "UIO: el <select> de «Asignar a» de una fila solo trae técnicos de UIO antes del optgroup",
           bool(ids_antes) and ids_antes.issubset(tecs_uio), f"antes_del_optgroup={sorted(ids_antes)}")

    st, _, c = s["admin_prueba"].pedir("panel.php")
    tarjetas = len(re.findall(r'zona-card zona-(?:uio|larb|cnlj)', c))
    anotar("S2.ASG09", "admin: panel.php tiene una tarjeta «Por zona» por cada zona",
           st == 200 and tarjetas == 3, f"{st} · tarjetas={tarjetas}")

    # preparar_prueba.php deja elegidos[0] en ESPERA_REPUESTO, pero verificar_ciclo.py
    # lo devuelve a ASIGNADO al resolver su pendiente: el estado se fija aquí para
    # que la comprobación no dependa del orden en que se corran las baterías.
    caso_espera = elegidos[0]
    estado_previo = sql("SELECT estado FROM casos_gestion WHERE aviso = ?", [caso_espera])[0]["estado"]
    ejecutar("UPDATE casos_gestion SET estado = 'ESPERA_REPUESTO' WHERE aviso = ?", [caso_espera])
    inicio_asg = sql("SELECT NOW() n")[0]["n"]
    s["admin_prueba"].pedir("casos.php", form={
        "accion": "veredicto", "aviso": caso_espera, "veredicto": "RESUELTO",
        "motivo": "", "csrf": csrf["admin_prueba"]})
    fila = sql("SELECT estado FROM casos_gestion WHERE aviso = ?", [caso_espera])[0]
    anotar("S2.ASG01", "veredicto RESUELTO sobre un caso en ESPERA_REPUESTO → no cambia de estado",
           fila["estado"] == "ESPERA_REPUESTO", f"estado={fila['estado']}")
    den = sql("SELECT accion FROM bitacora WHERE entidad = 'caso' AND referencia = ? AND exito = 0 AND cuando >= ?",
              [caso_espera, inicio_asg])
    anotar("S2.ASG01", "queda una fila DENEGADO en la bitácora", any(r["accion"] == "DENEGADO" for r in den), f"{len(den)} filas")

    st, _, c = s["admin_prueba"].pedir(f"casos.php?est=ESPERA_REPUESTO")
    m = re.search(r'data-aviso="' + re.escape(str(caso_espera)) + r'".*?</tr>', c, re.S)
    fila_html = m.group(0) if m else ""
    anotar("S2.ASG01", "en el buzón, ese caso no ofrece «Veredicto» y sí un enlace a Pendientes",
           bool(fila_html) and 'data-accion="veredicto"' not in fila_html and 'pendientes.php?q=' in fila_html,
           f"encontrada={bool(fila_html)}")

    st, _, c = s["admin_prueba"].pedir("casos.php", form={
        "accion": "revision", "aviso": caso_espera, "motivo": "prueba sin csrf (S2)", "csrf": None})
    anotar("S2.CSRF", "POST a casos.php sin csrf → 403", st == 403, st)
    ejecutar("UPDATE casos_gestion SET estado = ? WHERE aviso = ?", [estado_previo, caso_espera])

    # La asignación entre zonas (ASG-03, D4) se prueba sobre un aviso sintético
    # 9999xxxx (regla 8 de CONVENCIONES_T2_14.md): preparar_prueba.php lo deja
    # ASIGNADO a tec_prueba_uio_a en UIO en cada corrida, así que reasignarlo
    # aquí no deja basura para la siguiente vez.
    aviso_sint = "99990011"
    # Punto de partida conocido, corra o no preparar_prueba.php antes: el sintético
    # asignado al técnico A en UIO (así la corrida anterior no lo deja en CNLJ).
    ejecutar("UPDATE casos_gestion SET asignado_a = ?, zona = 'UIO', estado = 'ASIGNADO' WHERE aviso = ?",
             [ids["tec_prueba_uio_a"], aviso_sint])
    st, _, c = s["admin_prueba"].pedir("casos.php", form={
        "accion": "asignar", "aviso": aviso_sint, "tecnico": str(ids["jefe_prueba_cnlj"]),
        "csrf": csrf["admin_prueba"]})
    fila = sql("SELECT asignado_a FROM casos_gestion WHERE aviso = ?", [aviso_sint])[0]
    anotar("S2.ASG03", "asignar un caso de UIO a un técnico de CNLJ sin confirmo_zona → rechazado",
           fila["asignado_a"] == ids["tec_prueba_uio_a"], f"asignado_a={fila['asignado_a']}")

    inicio_conf = sql("SELECT NOW() n")[0]["n"]
    st, _, c = s["admin_prueba"].pedir("casos.php", form={
        "accion": "asignar", "aviso": aviso_sint, "tecnico": str(ids["jefe_prueba_cnlj"]),
        "confirmo_zona": "1", "csrf": csrf["admin_prueba"]})
    fila = sql("SELECT asignado_a FROM casos_gestion WHERE aviso = ?", [aviso_sint])[0]
    anotar("S2.ASG03", "con confirmo_zona=1 → sí asigna a un técnico de otra zona",
           fila["asignado_a"] == ids["jefe_prueba_cnlj"], f"asignado_a={fila['asignado_a']}")
    bit = sql("SELECT datos FROM bitacora WHERE entidad = 'caso' AND referencia = ? AND accion = 'ASIGNAR' AND cuando >= ?",
              [aviso_sint, inicio_conf])
    anotar("S2.ASG03", "la asignación entre zonas queda marcada `confirmo_zona` en la bitácora",
           any('"confirmo_zona":true' in (r["datos"] or "") for r in bit), f"{len(bit)} filas")
    # Se devuelve al técnico A: verificar_bandeja.py lo espera así.
    ejecutar("UPDATE casos_gestion SET asignado_a = ?, zona = 'UIO', estado = 'ASIGNADO' WHERE aviso = ?",
             [ids["tec_prueba_uio_a"], aviso_sint])

    # «Pedir seguimiento» (ASG-15), sobre el otro aviso sintético (ATENDIDO).
    st, _, c = s["admin_prueba"].pedir("casos.php", form={
        "accion": "seguimiento", "aviso": "99990012", "tecnico": str(ids["tec_prueba_uio_a"]),
        "texto": "PRUEBA automatizada (S2): cuéntame cómo va este caso.", "csrf": csrf["admin_prueba"]})
    fila = sql("SELECT texto FROM casos_seguimientos WHERE aviso = '99990012' ORDER BY seguimiento_id DESC LIMIT 1")
    anotar("S2.ASG15", "pedir seguimiento sobre un caso ATENDIDO → fila en casos_seguimientos",
           bool(fila) and "PRUEBA automatizada" in (fila[0]["texto"] or ""), f"{fila}")

    # «Cerrar por falta de atención» (ASG-05) NO se ejecuta de verdad aquí: es
    # una mutación masiva sobre el catálogo real de KFC, y automatizarla en una
    # prueba que se repite en cada corrida es justo el riesgo que el 15% de
    # guarda quiere evitar. Se comprueba en seco que panel.php muestra el mismo
    # conteo que calcula `Reconciliar::cerrarSinAtencion(..., false)`.
    php = ('require "nucleo/Casos.php"; require "nucleo/Reconciliar.php"; '
           '$cat = Casos::catalogo()["datos"] ?? []; $aten = Casos::atenciones(); '
           'echo json_encode(Reconciliar::cerrarSinAtencion($cat, $aten, 7, false));')
    r = json.loads(ssh(f"cd {D} && php -r '{php}'"))
    st, _, c = s["admin_prueba"].pedir("panel.php")
    if r["candidatos"] > 0:
        anotar("S2.ASG05", "panel.php muestra el mismo conteo de «7+ días sin informe» que el cálculo en seco",
               str(r["candidatos"]) in c, f"dry-run candidatos={r['candidatos']}")
    else:
        anotar("S2.ASG05", "sin candidatos a cerrar por falta de atención ahora mismo", True, "candidatos=0")

    for se in s.values():
        se.pedir("salir.php")

    (SALIDA / "resultado_http.json").write_text(json.dumps(resultados, ensure_ascii=False, indent=1), encoding="utf-8")
    fallas = [r for r in resultados if not r["ok"]]
    print(f"\n{len(resultados) - len(fallas)} de {len(resultados)} comprobaciones pasan; {len(fallas)} fallan")
    return 1 if fallas else 0


if __name__ == "__main__":
    sys.exit(main())
