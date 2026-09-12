"""verificar_http.py — Pruebas por rol contra el sitio de pruebas, entrando de verdad
con las cuentas de prueba (T2.12.3, T2.12.4, T2.12.5, T2.12.6, T2.12.9 y T2.13.1).

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
import ssl
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

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
    r = subprocess.run(SSH + [cmd], input=entrada, capture_output=True, text=True, timeout=120)
    if r.returncode != 0:
        raise RuntimeError(f"ssh falló: {r.stderr.strip()[:200]}")
    return r.stdout


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
        if cuerpo_json is not None:
            datos = json.dumps(cuerpo_json).encode("utf-8")
            cab["Content-Type"] = "application/json"
        elif form is not None:
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

    for se in s.values():
        se.pedir("salir.php")

    (SALIDA / "resultado_http.json").write_text(json.dumps(resultados, ensure_ascii=False, indent=1), encoding="utf-8")
    fallas = [r for r in resultados if not r["ok"]]
    print(f"\n{len(resultados) - len(fallas)} de {len(resultados)} comprobaciones pasan; {len(fallas)} fallan")
    return 1 if fallas else 0


if __name__ == "__main__":
    sys.exit(main())
