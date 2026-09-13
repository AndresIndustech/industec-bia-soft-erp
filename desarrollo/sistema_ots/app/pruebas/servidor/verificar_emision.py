"""verificar_emision.py — La 008 contra el sitio de pruebas: la orden que manda la app sale
con su número, su PDF y su correo en la cola, retenido, porque en pruebas no sale nada.

Entra como el técnico de prueba A, sube dos fotos por foto.php, manda la orden con la firma
y comprueba el número (serie de pruebas, 9000 en adelante), el PDF (lo abren él y su jefe de
zona; no el otro técnico ni el jefe de otra zona), la cola de correo retenida, que el
reintento no saque otro número, y que diez reservas simultáneas den diez números distintos
(T2.1.5).

Requiere preparar_prueba.php. Lo que crea lo borra deshacer_prueba.php.
Uso:  python verificar_emision.py        (la misma llave SSH que verificar_http.py)
Sale con 1 si algo falla.
"""
import base64
import datetime
import json
import re
import struct
import sys
import urllib.error
import urllib.request
import uuid
import zlib

from verificar_http import BASE, D, SALIDA, Sesion, anotar, resultados, sql, ssh

# En Windows la consola es cp1252 y la flecha «→» de los mensajes reventaba la
# prueba antes de la primera comprobación (AUDITORIA_2026-09-12, P-07).
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")



def png(ancho, alto, rgb):
    """Un PNG de un color, sin librerías: foto.php acepta cualquier imagen y la vuelve JPEG."""
    crudo = zlib.compress((b"\x00" + bytes(rgb) * ancho) * alto)

    def trozo(tipo, datos):
        return struct.pack(">I", len(datos)) + tipo + datos + struct.pack(">I", zlib.crc32(tipo + datos) & 0xFFFFFFFF)

    return (b"\x89PNG\r\n\x1a\n" + trozo(b"IHDR", struct.pack(">IIBBBBB", ancho, alto, 8, 2, 0, 0, 0))
            + trozo(b"IDAT", crudo) + trozo(b"IEND", b""))


def ejecutar(q, p=None):
    php = ('require "nucleo/Db.php"; $in = json_decode(stream_get_contents(STDIN), true); '
           'echo Db::ejecutar($in["q"], $in["p"]);')
    return int(ssh(f"cd {D} && php -r '{php}'", json.dumps({"q": q, "p": p or []})))


def abrir(sesion, req):
    try:
        r = sesion.op.open(req, timeout=90)
        return r.status, r.headers.get("Content-Type", ""), r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get("Content-Type", ""), e.read()


def subir(sesion, envio, foto, n, datos, nombre="foto.png", tipo="image/png"):
    """POST multipart a foto.php, como lo manda cola.js."""
    limite = "----industec" + uuid.uuid4().hex
    partes = [f'--{limite}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
              for k, v in (("envio_uuid", envio), ("foto_uuid", foto), ("n", str(n)))]
    partes.append(f'--{limite}\r\nContent-Disposition: form-data; name="foto"; filename="{nombre}"\r\n'
                  f'Content-Type: {tipo}\r\n\r\n'.encode() + datos + b"\r\n")
    partes.append(f"--{limite}--\r\n".encode())
    st, _, cuerpo = abrir(sesion, urllib.request.Request(BASE + "foto.php", data=b"".join(partes), headers={
        "Content-Type": f"multipart/form-data; boundary={limite}", "User-Agent": "verificar_emision/1.0"}))
    try:
        return st, json.loads(cuerpo.decode("utf-8") or "{}")
    except ValueError:
        return st, {"crudo": cuerpo[:120]}


def binario(sesion, ruta):
    return abrir(sesion, urllib.request.Request(BASE + ruta, headers={"User-Agent": "verificar_emision/1.0"}))


def main():
    claves = json.loads(ssh("cat ~/respaldos/claves_prueba.json"))
    a = claves["ids"]["tec_prueba_uio_a"]
    s = {u: Sesion(u) for u in ("tec_prueba_uio_a", "tec_prueba_uio_b", "jefe_prueba_uio", "jefe_prueba_cnlj")}
    for u, se in s.items():
        se.entrar(claves["claves"][u])
    sa, sb = s["tec_prueba_uio_a"], s["tec_prueba_uio_b"]

    print("== la 008 está aplicada ==")
    tablas = {r["t"] for r in sql("SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() "
                                  "AND TABLE_NAME IN ('correlativos','ot_fotos','email_queue')")}
    anotar("008", "tablas correlativos, ot_fotos y email_queue", tablas == {"correlativos", "ot_fotos", "email_queue"},
           sorted(tablas))
    uq = sql("SELECT NON_UNIQUE n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
             "AND TABLE_NAME = 'ot_capturadas' AND INDEX_NAME = 'uq_cap_industec'")
    anotar("008", "id_industec es único en ot_capturadas (I-9)", len(uq) == 1 and int(uq[0]["n"]) == 0, uq)

    print("\n== foto.php ==")
    envio, f1, f2 = str(uuid.uuid4()), str(uuid.uuid4()), str(uuid.uuid4())
    st, _, _ = sa.pedir("foto.php")
    anotar("008", "GET a foto.php → 405", st == 405, st)
    st, j = subir(sa, envio, "no-es-uuid", 0, png(8, 8, (200, 0, 0)))
    anotar("008", "identificador mal formado → 400", st == 400, f"{st} · {j.get('motivo')}")
    st, j = subir(sa, envio, str(uuid.uuid4()), 0, b"esto no es una imagen", "x.jpg", "image/jpeg")
    anotar("008", "un archivo que no es imagen → 400", st == 400, f"{st} · {j.get('motivo')}")
    st, j = subir(sa, envio, f1, 0, png(1600, 900, (30, 90, 160)))
    anotar("008", "foto de 1600×900 → 200, reducida a 1200 px como producción", st == 200 and j.get("ancho") == 1200, j)
    st, j = subir(sa, envio, f2, 1, png(400, 300, (160, 90, 30)))
    anotar("008", "segunda foto → 200", st == 200 and j.get("ok") is True, j)
    st, j = subir(sa, envio, f1, 0, png(1600, 900, (30, 90, 160)))
    anotar("008", "la misma foto otra vez → 200 «ya estaba», sin duplicarla", st == 200 and j.get("ya_estaba") is True, j)
    st, j = subir(sb, envio, f1, 0, png(8, 8, (0, 0, 0)))
    anotar("008", "el técnico B con la foto de A → 409", st == 409, f"{st} · {j.get('motivo')}")
    n = sql("SELECT COUNT(*) n FROM ot_fotos WHERE envio_uuid = ?", [envio])[0]["n"]
    anotar("008", "dos filas en ot_fotos para esa orden", int(n) == 2, n)

    print("\n== la orden, emitida ==")
    st, _, c = sa.pedir("catalogos.php")
    cat = json.loads(c)
    caso = next(x for x in cat["avisos"]["datos"] if x.get("local"))
    local = caso["local"]
    equipos = (cat.get("equipos") or {}).get(local) or []
    eq = ({"equipo_sap": str(equipos[0]["equipo_sap"]), "tipo": equipos[0].get("tipo", "")} if equipos
          else {"tipo": (cat.get("tipos") or ["FREIDORA"])[0]})
    eq.update({"estado": "Operativo", "obs": "PRUEBA: observación del equipo", "marca": "MarcaPrueba"})
    hoy = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=-5))).date().isoformat()
    orden = {"local": local, "aviso": caso["aviso"], "tipo": "CORRECTIVO", "equipos": [eq], "uso_repuesto": False,
             "repuestos": "", "fecha_atencion": hoy, "inicio": f"{hoy}T08:00", "fin": f"{hoy}T09:45",
             "actividades": "PRUEBA automatizada de la emisión (008): no es una intervención real.",
             "admin": "Administrador de Prueba", "observaciones": "PRUEBA: sin novedades", "estado_ot": "Cerrada",
             "atiempo": "Si", "satisfaccion": 9, "firma_presente": True,
             "firma_png": "data:image/png;base64," + base64.b64encode(png(300, 100, (250, 250, 250))).decode(),
             "fotos": [f1, f2], "fotos_cantidad": 2, "tecnico": "Cualquier nombre que mande el celular",
             "concluida": True}
    serie = "CORRECTIVO:UIO"
    antes = sql("SELECT ultimo FROM correlativos WHERE serie = ?", [serie])
    cuerpo = {"envio_uuid": envio, "usuario_captura": a,
              "capturada_en": datetime.datetime.now(datetime.timezone.utc).isoformat(), "orden": orden}
    st, _, c = sa.pedir("envio.php", cuerpo_json=cuerpo)
    rec = (json.loads(c) if st == 200 else {}).get("recibo", {})
    ot = rec.get("id_industec") or ""
    anotar("008", "la orden sale EMITIDA, con número de la serie de pruebas",
           st == 200 and rec.get("estado") == "EMITIDA"
           and re.fullmatch(rf"OT-9\d{{3}}-{re.escape(local)}-{caso['aviso']}-UIO", ot) is not None, f"{st} · {ot or c[:100]}")
    anotar("008", "el recibo dice que el correo no salió (sistema en pruebas)",
           "no se envió a nadie" in (rec.get("que_sigue") or ""), (rec.get("que_sigue") or "")[:90])
    fila = sql("SELECT estado, emitida_en, pdf_sha256, emision_error FROM ot_capturadas WHERE envio_uuid = ?", [envio])[0]
    # Desde la 009 el estado real es EMITIDA (PROCESADA era el nombre anterior a los
    # estados NUMERADA/EMITIDA/ENVIADA/FALLIDA, E-12).
    anotar("008", "ot_capturadas: EMITIDA, con fecha de emisión y huella del PDF",
           fila["estado"] == "EMITIDA" and fila["emitida_en"] and fila["pdf_sha256"] and not fila["emision_error"], fila)
    despues = int(sql("SELECT ultimo FROM correlativos WHERE serie = ?", [serie])[0]["ultimo"])
    num = int(ot.split("-")[1]) if ot else -1
    anotar("008", "el correlativo quedó en ese número, uno más que antes",
           despues == num and (not antes or int(antes[0]["ultimo"]) == num - 1),
           f"antes={antes[0]['ultimo'] if antes else '—'} · después={despues} · orden={num}")
    cola = sql("SELECT estado, motivo, adjunto FROM email_queue WHERE id_industec = ?", [ot])
    anotar("008", "un correo en la cola, RETENIDO y con el PDF adjunto",
           len(cola) == 1 and cola[0]["estado"] == "RETENIDO" and cola[0]["adjunto"] == ot + ".pdf",
           cola[0]["estado"] if cola else "sin fila")
    anotar("008", "y dice por qué no sale", bool(cola) and "sitio de pruebas" in (cola[0]["motivo"] or ""),
           (cola[0]["motivo"] or "")[:70] if cola else "")

    print("\n== el PDF: qué lleva y quién lo abre ==")
    php = ('require "nucleo/Emision.php"; $in = json_decode(stream_get_contents(STDIN), true); '
           '$c = Db::uno("SELECT * FROM ot_capturadas WHERE id_industec = ?", [$in["ot"]]); '
           '$h = Emision::html($c, json_decode($c["carga"], true), $c["id_industec"]); '
           'echo json_encode(["prueba" => str_contains($h, "DOCUMENTO DE PRUEBA"), '
           '"admin" => str_contains($h, "Administrador de Prueba"), '
           '"fotos" => substr_count($h, "data:image/jpeg;base64,"), "firma" => str_contains($h, "alt=\\"Firma\\""), '
           '"estado" => str_contains($h, "Operativo"), "marca" => str_contains($h, "MarcaPrueba"), '
           '"satisf" => str_contains($h, "9/10"), "tiempo" => str_contains($h, "1h 45m")]);')
    h = json.loads(ssh(f"cd {D} && php -r '{php}'", json.dumps({"ot": ot})))
    anotar("008", "lleva la franja «DOCUMENTO DE PRUEBA»", h.get("prueba") is True, h)
    anotar("008", "las dos fotos, la firma y quién firmó", h.get("fotos") == 2 and h.get("firma") and h.get("admin"), h)
    anotar("008", "el estado y la marca del equipo, la satisfacción y el tiempo de atención",
           h.get("estado") and h.get("marca") and h.get("satisf") and h.get("tiempo"), h)
    st, tipo, pdf = binario(sa, f"pdf.php?ot={ot}")
    anotar("008", "el técnico A abre su PDF", st == 200 and pdf[:5] == b"%PDF-" and "pdf" in tipo,
           f"{st} · {tipo} · {len(pdf)} B")
    # Desde el 2026-09-12 (D1) el archivo de órdenes es de lectura para todos los
    # roles y todas las zonas; hasta entonces aquí se esperaba 403 para el técnico
    # B y el jefe de CNLJ. Lo que sí tiene que quedar es la fila de la bitácora.
    for u in ("tec_prueba_uio_b", "jefe_prueba_uio", "jefe_prueba_cnlj"):
        st, _, _ = binario(s[u], f"pdf.php?ot={ot}")
        anotar("D1", f"{u} abre el PDF de otro (archivo general) → 200", st == 200, st)
    n = sql("SELECT COUNT(*) n FROM bitacora WHERE accion = 'ABRIR_PDF' AND referencia = ? "
            "AND usuario = 'tec_prueba_uio_b' AND exito = 1", [ot])[0]["n"]
    anotar("D1", "la apertura del técnico B quedó en la bitácora", int(n) >= 1, n)
    st, tipo, pdf = binario(sa, f"pdf.php?ot={ot}&dl=1")
    anotar("D1", "?dl=1 entrega el PDF para guardar", st == 200 and pdf[:5] == b"%PDF-", st)
    n = sql("SELECT COUNT(*) n FROM bitacora WHERE accion = 'DESCARGAR_PDF' AND referencia = ?", [ot])[0]["n"]
    anotar("D1", "y se registra como descarga, no como apertura", int(n) >= 1, n)
    st, _, _ = binario(sa, f"pdf.php?ot={ot}&exp=1&f=abc")
    anotar("D1", "un enlace con firma inválida → 403", st == 403, st)
    n = sql("SELECT COUNT(*) n FROM bitacora WHERE accion = 'DENEGADO' AND usuario = 'enlace' AND referencia = ? "
            "AND exito = 0", [ot])[0]["n"]
    anotar("D1", "y el intento queda en la bitácora", int(n) >= 1, n)

    print("\n== el reintento no saca otro número ==")
    st, _, c = sa.pedir("envio.php", cuerpo_json=cuerpo)
    rec2 = (json.loads(c) if st == 200 else {}).get("recibo", {})
    anotar("008", "el mismo envío otra vez → el mismo número", st == 200 and rec2.get("id_industec") == ot,
           rec2.get("id_industec"))
    anotar("008", "y el correlativo no se movió",
           int(sql("SELECT ultimo FROM correlativos WHERE serie = ?", [serie])[0]["ultimo"]) == num, "")
    n = sql("SELECT COUNT(*) n FROM email_queue WHERE id_industec = ?", [ot])[0]["n"]
    anotar("008", "ni se encoló un segundo correo", int(n) == 1, n)

    print("\n== diez reservas a la vez, diez números distintos (T2.1.5) ==")
    # Cada proceso escribe en su propio archivo: con la salida compartida dos
    # números se pegaban en una sola línea («90069005») y la prueba fallaba sin
    # que la reserva estuviera mal (flaqueza medida el 2026-09-13).
    salida = ssh(f"cd {D} && rm -f /tmp/conc_uio_* && for i in $(seq 10); do php -r 'require \"nucleo/Emision.php\"; "
                 f"echo Emision::reservar(\"CONCURRENCIA:UIO\");' > /tmp/conc_uio_$i & done; wait; "
                 f"cat /tmp/conc_uio_*; echo; rm -f /tmp/conc_uio_*")
    nums = sorted(int(x) for x in salida.split())
    anotar("008", "10 procesos a la vez: 10 números, sin repetir y seguidos",
           len(nums) == 10 and len(set(nums)) == 10 and nums[-1] - nums[0] == 9, nums)
    ejecutar("DELETE FROM correlativos WHERE serie = 'CONCURRENCIA:UIO'")

    print("\n== el historial del técnico ==")
    st, _, c = sa.pedir("mis.php?t=atendidas")
    anotar("008", "mis.php muestra el número y el enlace a su PDF", st == 200 and ot in c and f"pdf.php?ot={ot}" in c, st)
    anotar("008", "y dice que es de prueba", "es de prueba: no se envió a nadie" in c, "")

    for se in s.values():
        se.pedir("salir.php")
    (SALIDA / "resultado_emision.json").write_text(json.dumps(resultados, ensure_ascii=False, indent=1), encoding="utf-8")
    fallas = [r for r in resultados if not r["ok"]]
    print(f"\n{len(resultados) - len(fallas)} de {len(resultados)} comprobaciones pasan; {len(fallas)} fallan")
    return 1 if fallas else 0


if __name__ == "__main__":
    sys.exit(main())
