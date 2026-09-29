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
import time
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


def subir(sesion, envio, foto, n, datos, nombre="foto.png", tipo="image/png", extra=None):
    """POST multipart a foto.php, como lo manda cola.js.

    `extra` (T2.28.7): equipo_n/momento/tomada_ms, opcionales -exactamente
    como los manda cola.js cuando la foto viene de un bloque de equipo.
    """
    limite = "----industec" + uuid.uuid4().hex
    campos = [("envio_uuid", envio), ("foto_uuid", foto), ("n", str(n))] + list((extra or {}).items())
    partes = [f'--{limite}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
              for k, v in campos]
    partes.append(f'--{limite}\r\nContent-Disposition: form-data; name="foto"; filename="{nombre}"\r\n'
                  f'Content-Type: {tipo}\r\n\r\n'.encode() + datos + b"\r\n")
    partes.append(f"--{limite}--\r\n".encode())
    cab = {"Content-Type": f"multipart/form-data; boundary={limite}", "User-Agent": "verificar_emision/1.0"}
    # Desde la 009 foto.php exige el token CSRF en la cabecera X-Csrf, igual
    # que cola.js; el arnés lo trae de yo.php al entrar.
    if getattr(sesion, "csrf", ""):
        cab["X-Csrf"] = sesion.csrf
    st, _, cuerpo = abrir(sesion, urllib.request.Request(BASE + "foto.php", data=b"".join(partes), headers=cab))
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
    # T2.28.1: esta batería es la que EMITE DE VERDAD, y usa su propio aviso
    # sintético (99990021), limpio -sin pendiente- para que concluirlo lo
    # cierre sin más. verificar_http.py usa el otro (99990022, que
    # preparar_prueba.php deja en ESPERA_REPUESTO): así ninguna de las dos deja
    # a la otra sin ningún caso con local (error nro 36).
    AVISO_EMISION = "99990021"
    CORREO_PRUEBA = "admin.prueba@local-prueba.ec"
    st, _, c = sa.pedir("catalogos.php")
    cat = json.loads(c)
    caso = next((x for x in cat["avisos"]["datos"] if x.get("aviso") == AVISO_EMISION), None)
    if caso is None:
        print(f"  AVISO: el tecnico de prueba no tiene el aviso sintetico {AVISO_EMISION} con local. "
              "Corre ~/respaldos/preparar_prueba.php antes de creerle a lo que sigue.")
        anotar("008", f"el técnico A tiene el aviso {AVISO_EMISION} para emitir de verdad", False,
               sorted(x.get("aviso") for x in cat["avisos"]["datos"]))
        (SALIDA / "resultado_emision.json").write_text(json.dumps(resultados, ensure_ascii=False, indent=1), encoding="utf-8")
        return 1
    local = caso["local"]
    equipos = (cat.get("equipos") or {}).get(local) or []
    # T2.28.6: envio.php ahora escribe una ficha (marca/modelo/serie) por cada
    # equipo de la orden. Elegir a ciegas equipos[0] podía caer en un activo
    # SAP REAL del local -y esta prueba manda marca="MarcaPrueba", que
    # quedaría pisando el dato real de un equipo que nadie tocó (prohibido:
    # "nunca escribir fichas de equipos reales desde las baterías", detectado
    # corriendo esta misma batería antes de dar la subtarea por cerrada). Se
    # prefiere el equipo PROPUESTO que preparar_prueba.php ya sembró para este
    # aviso (uuid 99990000-…), que es el que limpiar_pruebas.php sabe borrar.
    eq_prop = next((e for e in equipos if e.get("propuesto")), None)
    eq_elegido = eq_prop or (equipos[0] if equipos else None)
    eq = ({"equipo_sap": str(eq_elegido["equipo_sap"]), "tipo": eq_elegido.get("tipo", "")} if eq_elegido
          else {"tipo": (cat.get("tipos") or ["FREIDORA"])[0]})
    eq.update({"estado": "Operativo", "obs": "PRUEBA: observación del equipo", "marca": "MarcaPrueba"})
    hoy = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=-5))).date().isoformat()
    orden = {"local": local, "aviso": caso["aviso"], "tipo": "CORRECTIVO", "equipos": [eq], "uso_repuesto": False,
             "repuestos": "", "fecha_atencion": hoy, "inicio": f"{hoy}T08:00", "fin": f"{hoy}T09:45",
             "actividades": "PRUEBA automatizada de la emisión (008): no es una intervención real.",
             "admin": "Administrador de Prueba", "observaciones": "PRUEBA: sin novedades", "estado_ot": "Cerrada",
             # Reporte de INDUSTEC del 2026-09-23: el correo que escribe el técnico
             # tiene que llegar a la cola y al PDF, no solo verse en el formulario.
             "correo_local": CORREO_PRUEBA,
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
    # La coletilla «Es el sistema en pruebas: el correo no se envió a nadie.»
    # se retiró el 28-sep-2026: toda orden del sitio de pruebas «es del
    # piloto» y ahora lo dice con esas palabras (envio.php:758-764).
    anotar("008", "el recibo dice que es del piloto y no llegó a Grupo KFC ni al local",
           "es del piloto" in (rec.get("que_sigue") or "") and "NO llegó a Grupo KFC" in (rec.get("que_sigue") or ""),
           (rec.get("que_sigue") or "")[:90])
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
    para = sql("SELECT para FROM email_queue WHERE id_industec = ?", [ot])
    anotar("correo", "la cola va al correo del local que escribió el técnico",
           bool(para) and CORREO_PRUEBA in (para[0]["para"] or ""), (para[0]["para"] or "")[:120] if para else "sin fila")
    anotar("correo", "y no al buzón genérico de INDUSTEC como correo del local",
           bool(para) and "servicioalcliente@industec.me" not in (para[0]["para"] or ""),
           (para[0]["para"] or "")[:120] if para else "sin fila")
    aprendido = sql("SELECT correo, correo_veces FROM locales_admin WHERE local_codigo = ? AND nombre = 'Administrador de Prueba'", [local])
    anotar("correo", "el correo queda aprendido para la próxima orden de ese local",
           bool(aprendido) and aprendido[0]["correo"] == CORREO_PRUEBA, aprendido)
    # T2.28.3: preparar_prueba.php/limpiar_pruebas.php dejan 'Administrador de
    # Prueba' sin fila, así que esta es su primera orden del ciclo -correo_veces
    # tiene que quedar en 1, no arrastrar de una corrida anterior.
    anotar("correo", "correo_veces queda en 1 (primera orden del ciclo con ese correo)",
           bool(aprendido) and int(aprendido[0]["correo_veces"] or 0) == 1, aprendido)

    # T2.28.2: a quién más va la orden (Destinatarios::resolver()) y la
    # propuesta que deja para que la administración apruebe el correo del
    # local. El jefe de zona sale del maestro aunque la 013 todavía no esté
    # sembrada (Destinatarios::resolver() cae a "lo de hoy" sin la tabla), así
    # que esto no depende de correos_sembrar_cli.php --ejecutar.
    cc = sql("SELECT cc FROM email_queue WHERE id_industec = ?", [ot])
    anotar("correo", "el jefe de zona de UIO va en copia, como JSON en email_queue.cc",
           bool(cc) and cc[0]["cc"] and "jefezona-uio@industec.me" in (cc[0]["cc"] or ""),
           (cc[0]["cc"] or "")[:120] if cc else "sin fila")
    propuesto = sql("SELECT estado, veces, correo_anterior FROM locales_correo_propuesto WHERE local_codigo = ? AND correo = ?",
                     [local, CORREO_PRUEBA])
    anotar("correo", "el correo del local queda PROPUESTO en locales_correo_propuesto para que la administración lo apruebe",
           bool(propuesto) and propuesto[0]["estado"] == "PROPUESTO", propuesto)

    print("\n== el PDF: qué lleva y quién lo abre ==")
    php = ('require "nucleo/Emision.php"; $in = json_decode(stream_get_contents(STDIN), true); '
           '$c = Db::uno("SELECT * FROM ot_capturadas WHERE id_industec = ?", [$in["ot"]]); '
           '$h = Emision::html($c, json_decode($c["carga"], true), $c["id_industec"]); '
           'echo json_encode(["prueba" => str_contains($h, "DOCUMENTO DE PRUEBA"), '
           '"emitida_en" => $c["emitida_en"], '
           '"emision" => str_contains($h, "generado automáticamente el " . Emision::fechaEmision($c["emitida_en"])), '
           '"admin" => str_contains($h, "Administrador de Prueba"), '
           '"correo" => str_contains($h, "' + CORREO_PRUEBA + '"), '
           '"fotos" => substr_count($h, "data:image/jpeg;base64,"), "firma" => str_contains($h, "alt=\\"Firma\\""), '
           '"estado" => str_contains($h, "Operativo"), "marca" => str_contains($h, "MarcaPrueba"), '
           '"satisf" => str_contains($h, "9/10"), "tiempo" => str_contains($h, "1h 45m")]);')
    h = json.loads(ssh(f"cd {D} && php -r '{php}'", json.dumps({"ot": ot})))
    # Hasta el 28-sep-2026 aquí se exigía la franja. Decisión de Andrés de ese
    # día: «de ahora en adelante ninguna orden salga con esa franja», tampoco en
    # modo PRUEBA (Emision::html() pasa prueba = false). Ahora se exige que NO
    # esté, y que la fecha de emisión sea la de emitida_en: regenerar el PDF no
    # la mueve.
    anotar("008", "NO lleva la franja «DOCUMENTO DE PRUEBA» (decisión del 28-sep-2026)", h.get("prueba") is False, h)
    anotar("008", "«Documento generado automáticamente el …» es emitida_en, no la hora de hoy",
           h.get("emision") is True, h.get("emitida_en"))
    anotar("008", "las dos fotos, la firma y quién firmó", h.get("fotos") == 2 and h.get("firma") and h.get("admin"), h)
    anotar("correo", "el PDF imprime el correo del local que escribió el técnico", h.get("correo") is True, h)
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

    print("\n== T2.28.6: la ficha del equipo (marca, modelo, serie) ==")
    # Equipo propio de esta batería (no el de la emisión de arriba): "nuevo"
    # con un uuid 99990000-... para que limpiar_pruebas.php lo reconozca por
    # su patrón `PROPUESTO:99990000-%` y lo borre junto con lo demás del
    # arnés. Cada orden va SIN aviso (sin_aviso=True): lo único que importa
    # aquí es el equipo, y encadenar varias órdenes reales al mismo aviso
    # 99990021 ya cerrado arriba no aporta nada.
    FICHA_UUID = "99990000-0000-4000-8000-0000000000f1"
    CLAVE_FICHA = f"PROPUESTO:{FICHA_UUID}"

    def orden_ficha(datos_equipo):
        o = dict(orden)
        o.pop("aviso", None)
        o["sin_aviso"] = True
        o["formulario_v"] = 2
        o["fotos"], o["fotos_cantidad"] = [], 0          # SIN_FOTOS solo ADVIERTE
        # T2.28.7: con formulario_v >= 2 y equipo elegido, FOTOS_ANTES_DESPUES
        # BLOQUEA si el equipo no declara 1+1 -- esta batería prueba la ficha,
        # no las fotos, así que solo se declara el conteo (igual que hacía
        # antes fotos_cantidad arriba): no hace falta subir ninguna de verdad.
        eq2 = {"nuevo": True, "equipo_uuid": FICHA_UUID, "tipo": "FREIDORA",
               "area": "Cocina caliente", "estado": "Operativo",
               "obs": "PRUEBA T2.28.6: ficha del equipo",
               "fotos_antes": 1, "fotos_despues": 1}
        eq2.update(datos_equipo)
        o["equipos"] = [eq2]
        return o

    def emitir_ficha(datos_equipo):
        env = str(uuid.uuid4())
        cuerpo_f = {"envio_uuid": env, "usuario_captura": a,
                    "capturada_en": datetime.datetime.now(datetime.timezone.utc).isoformat(),
                    "orden": orden_ficha(datos_equipo)}
        st, _, c = sa.pedir("envio.php", cuerpo_json=cuerpo_f)
        try:
            return st, json.loads(c)
        except ValueError:
            return st, {"crudo": c[:200]}

    st, j = emitir_ficha({})   # sin marca, sin modelo, sin "sin placa"
    anotar("T2.28.6", "sin marca, sin modelo y sin 'sin placa' -> 400, EQUIPO_SIN_DATOS_DE_PLACA",
           st == 400 and "marca" in (j.get("motivo") or "").lower(), f"{st} · {j.get('motivo')}")

    st, j = emitir_ficha({"marca": "MANITOWOC", "modelo": "IYT0500A", "serie": "SN-PRUEBA-1"})
    anotar("T2.28.6", "con marca, modelo y serie -> emitida", st == 200 and (j.get("recibo") or {}).get("estado") == "EMITIDA",
           f"{st} · {j}")
    ficha = sql("SELECT marca, modelo, serie, sin_placa FROM equipos_ficha WHERE equipo_clave = ?", [CLAVE_FICHA])
    anotar("T2.28.6", "equipos_ficha guarda esa marca, modelo y serie",
           bool(ficha) and ficha[0]["marca"] == "MANITOWOC" and ficha[0]["modelo"] == "IYT0500A"
           and ficha[0]["serie"] == "SN-PRUEBA-1", ficha)

    st, _, c = sa.pedir("catalogos.php")
    cat2 = json.loads(c)
    f = (cat2.get("fichas") or {}).get(CLAVE_FICHA)
    anotar("T2.28.6", "catalogos.php prellena la ficha para el próximo formulario del mismo equipo",
           bool(f) and f.get("marca") == "MANITOWOC" and f.get("modelo") == "IYT0500A"
           and f.get("serie") == "SN-PRUEBA-1", f)

    st, j = emitir_ficha({"marca": "MANITOWOC", "modelo": "IYT0500A", "serie": "SN-PRUEBA-2"})
    anotar("T2.28.6", "un segundo envío con otra serie -> emitida", st == 200 and (j.get("recibo") or {}).get("estado") == "EMITIDA",
           f"{st} · {j}")
    cambio = sql("SELECT antes, despues FROM equipos_ficha_cambios WHERE equipo_clave = ? AND campo = 'serie' "
                 "ORDER BY cambio_id DESC LIMIT 1", [CLAVE_FICHA])
    anotar("T2.28.6", "equipos_ficha_cambios deja 1 fila: SN-PRUEBA-1 -> SN-PRUEBA-2",
           bool(cambio) and cambio[0]["antes"] == "SN-PRUEBA-1" and cambio[0]["despues"] == "SN-PRUEBA-2", cambio)
    nbit = sql("SELECT COUNT(*) n FROM bitacora WHERE accion = 'EQUIPO_SERIE_CAMBIO' AND referencia = ?",
               [CLAVE_FICHA])[0]["n"]
    anotar("T2.28.6", "y queda en la bitácora como posible reemplazo del equipo", int(nbit) >= 1, nbit)

    print("\n== T2.28.7: fotos del antes y del después, por equipo ==")
    # Igual patrón que la ficha de arriba: órdenes propias de esta batería, sin
    # aviso, con equipos "nuevo" de uuid 99990000-... que limpiar_pruebas.php
    # reconoce y borra. `sin_placa: true` aísla del hallazgo de T2.28.6 -lo que
    # se prueba aquí es la foto, no la placa.
    def orden_fotos(equipos):
        o = dict(orden)
        o.pop("aviso", None)
        o["sin_aviso"] = True
        o["formulario_v"] = 2
        o["fotos"] = []
        o["fotos_cantidad"] = sum((e.get("fotos_antes") or 0) + (e.get("fotos_despues") or 0) for e in equipos)
        o["equipos"] = equipos
        return o

    def emitir_fotos(envio_uuid, equipos):
        cuerpo_f = {"envio_uuid": envio_uuid, "usuario_captura": a,
                    "capturada_en": datetime.datetime.now(datetime.timezone.utc).isoformat(),
                    "orden": orden_fotos(equipos)}
        st, _, c = sa.pedir("envio.php", cuerpo_json=cuerpo_f)
        try:
            return st, json.loads(c)
        except ValueError:
            return st, {"crudo": c[:200]}

    ahora_ms = int(datetime.datetime.now(datetime.timezone.utc).timestamp() * 1000)

    print("-- dos equipos, una foto de cada momento --")
    ENV_2EQ = str(uuid.uuid4())
    for n_eq, rgb in ((0, (10, 200, 10)), (1, (10, 10, 200))):
        st, j = subir(sa, ENV_2EQ, str(uuid.uuid4()), n_eq * 2, png(80, 60, rgb),
                      extra={"equipo_n": str(n_eq), "momento": "ANTES", "tomada_ms": str(ahora_ms - 60000)})
        anotar("T2.28.7", f"foto ANTES del equipo {n_eq} -> 200", st == 200 and j.get("ok") is True, f"{st} · {j}")
        st, j = subir(sa, ENV_2EQ, str(uuid.uuid4()), n_eq * 2 + 1, png(80, 60, rgb),
                      extra={"equipo_n": str(n_eq), "momento": "DESPUES", "tomada_ms": str(ahora_ms)})
        anotar("T2.28.7", f"foto DESPUES del equipo {n_eq} -> 200", st == 200 and j.get("ok") is True, f"{st} · {j}")
    n_fotos = sql("SELECT COUNT(*) n FROM ot_fotos WHERE envio_uuid = ? AND momento IS NOT NULL", [ENV_2EQ])[0]["n"]
    anotar("T2.28.7", "las 4 fotos quedan con su equipo_n y momento", int(n_fotos) == 4, n_fotos)

    equipos_2 = [
        {"nuevo": True, "equipo_uuid": "99990000-0000-4000-8000-0000000000f2", "tipo": "FREIDORA",
         "area": "Cocina caliente", "estado": "Operativo", "sin_placa": True, "fotos_antes": 1, "fotos_despues": 1},
        {"nuevo": True, "equipo_uuid": "99990000-0000-4000-8000-0000000000f3", "tipo": "MAQUINA DE HIELO",
         "area": "Bodega", "estado": "Operativo", "sin_placa": True, "fotos_antes": 1, "fotos_despues": 1},
    ]
    st, j = emitir_fotos(ENV_2EQ, equipos_2)
    ot2 = (j.get("recibo") or {}).get("id_industec") or ""
    anotar("T2.28.7", "2 equipos con 1 foto de cada momento -> emitida",
           st == 200 and (j.get("recibo") or {}).get("estado") == "EMITIDA", f"{st} · {j}")

    st, tipo, pdf2 = binario(sa, f"pdf.php?ot={ot2}")
    anotar("T2.28.7", "el PDF de esa orden se descarga", st == 200 and pdf2[:5] == b"%PDF-", f"{st} · {len(pdf2)} B")
    texto_pdf = ""
    try:
        from pypdf import PdfReader
        import io
        texto_pdf = "".join((p.extract_text() or "") for p in PdfReader(io.BytesIO(pdf2)).pages)
    except Exception as ex:
        anotar("T2.28.7", "pypdf pudo leer el PDF emitido", False, f"{type(ex).__name__}: {ex}")
    anotar("T2.28.7", "el texto del PDF (pypdf) dice «Antes» dos veces, una por equipo",
           texto_pdf.count("Antes") >= 2, f'"Antes" x{texto_pdf.count("Antes")}')
    anotar("T2.28.7", "el texto del PDF (pypdf) dice «Después» dos veces, una por equipo",
           texto_pdf.count("Después") >= 2, f'"Después" x{texto_pdf.count("Después")}')

    print("-- el tope de 5 fotos por equipo --")
    ENV_TOPE = str(uuid.uuid4())
    for k in range(5):
        st, j = subir(sa, ENV_TOPE, str(uuid.uuid4()), k, png(40, 30, (5, 5, 5)),
                      extra={"equipo_n": "2", "momento": "ANTES", "tomada_ms": str(ahora_ms)})
        anotar("T2.28.7", f"foto {k + 1}/5 del equipo -> 200", st == 200 and j.get("ok") is True, f"{st} · {j}")
    st, j = subir(sa, ENV_TOPE, str(uuid.uuid4()), 5, png(40, 30, (5, 5, 5)),
                  extra={"equipo_n": "2", "momento": "ANTES", "tomada_ms": str(ahora_ms)})
    anotar("T2.28.7", "la 6.a foto del mismo equipo -> 400 con 'máximo'",
           st == 400 and "máximo" in (j.get("motivo") or "").lower(), f"{st} · {j.get('motivo')}")

    print("-- 7 equipos x 5 fotos, emitida en menos de 30 s y sin error nuevo en el log de la web --")
    ARCHIVO_LOG = "~/.logs/error_log_darkviolet-armadillo-872352_hostingersite_com"
    lineas_antes = int((ssh(f"wc -l < {ARCHIVO_LOG} 2>/dev/null || echo 0") or "0").strip() or "0")

    ENV_CARGA = str(uuid.uuid4())
    equipos_carga = []
    ok_subida = True
    for n_eq in range(7):
        uid_eq = f"99990000-0000-4000-8000-0000000010{n_eq:02x}"
        for k in range(5):
            momento = "ANTES" if k == 0 else "DESPUES"
            st, j = subir(sa, ENV_CARGA, str(uuid.uuid4()), n_eq * 5 + k,
                          png(1600, 1200, ((n_eq * 30) % 256, (k * 40) % 256, 80)),
                          extra={"equipo_n": str(n_eq), "momento": momento,
                                 "tomada_ms": str(ahora_ms - (5 - k) * 1000)})
            ok_subida = ok_subida and st == 200 and j.get("ok") is True
        equipos_carga.append({"nuevo": True, "equipo_uuid": uid_eq, "tipo": "FREIDORA",
                              "area": "Cocina caliente", "estado": "Operativo",
                              "sin_placa": True, "fotos_antes": 1, "fotos_despues": 4})
    anotar("T2.28.7", "las 35 fotos (7 equipos x 5) se subieron", ok_subida, ok_subida)
    n_carga = sql("SELECT COUNT(*) n FROM ot_fotos WHERE envio_uuid = ?", [ENV_CARGA])[0]["n"]
    anotar("T2.28.7", "y quedaron las 35 filas en ot_fotos", int(n_carga) == 35, n_carga)

    t0 = time.time()
    st, j = emitir_fotos(ENV_CARGA, equipos_carga)
    transcurrido = time.time() - t0
    anotar("T2.28.7", "orden con 7 equipos x 5 fotos -> emitida en menos de 30 s",
           st == 200 and (j.get("recibo") or {}).get("estado") == "EMITIDA" and transcurrido < 30,
           f"{transcurrido:.1f} s · {st} · {(j.get('recibo') or {}).get('estado')}")

    lineas_despues = int((ssh(f"wc -l < {ARCHIVO_LOG} 2>/dev/null || echo 0") or "0").strip() or "0")
    nuevas = ssh(f"tail -n +{lineas_antes + 1} {ARCHIVO_LOG}") if lineas_despues > lineas_antes else ""
    anotar("T2.28.7", "sin error nuevo en el log de la web durante la emisión",
           lineas_despues == lineas_antes, nuevas[:200] if nuevas else f"{lineas_antes} -> {lineas_despues}")

    print("\n== diez reservas a la vez, diez números distintos (T2.1.5) ==")
    # Cada proceso escribe en su propio archivo: con la salida compartida dos
    # números se pegaban en una sola línea («90069005») y la prueba fallaba sin
    # que la reserva estuviera mal (flaqueza medida el 2026-09-13).
    salida = ssh(f"cd {D} && rm -f /tmp/conc_uio_* && for i in $(seq 10); do php -r 'require \"nucleo/Emision.php\"; "
                 f"echo Emision::reservar(\"CONCURRENCIA:UIO\"), PHP_EOL;' > /tmp/conc_uio_$i & done; wait; "
                 f"cat /tmp/conc_uio_*; echo; rm -f /tmp/conc_uio_*")
    nums = sorted(int(x) for x in salida.split())
    anotar("008", "10 procesos a la vez: 10 números, sin repetir y seguidos",
           len(nums) == 10 and len(set(nums)) == 10 and nums[-1] - nums[0] == 9, nums)
    ejecutar("DELETE FROM correlativos WHERE serie = 'CONCURRENCIA:UIO'")

    print("\n== el historial del técnico ==")
    st, _, c = sa.pedir("mis.php?t=atendidas")
    anotar("008", "mis.php muestra el número y el enlace a su PDF", st == 200 and ot in c and f"pdf.php?ot={ot}" in c, st)
    # Desde el 28-sep-2026 la marca es la del vocabulario (OT_PILOTO, «corto»):
    # «del piloto · no enviada a KFC». El texto viejo «es de prueba: no se envió
    # a nadie» ya no existe (lo retiró la versión 2026-09-28.1).
    anotar("008", "y dice que es del piloto, no enviada a KFC", "del piloto · no enviada a KFC" in c, "")

    for se in s.values():
        se.pedir("salir.php")
    (SALIDA / "resultado_emision.json").write_text(json.dumps(resultados, ensure_ascii=False, indent=1), encoding="utf-8")
    fallas = [r for r in resultados if not r["ok"]]
    print(f"\n{len(resultados) - len(fallas)} de {len(resultados)} comprobaciones pasan; {len(fallas)} fallan")
    return 1 if fallas else 0


if __name__ == "__main__":
    sys.exit(main())
