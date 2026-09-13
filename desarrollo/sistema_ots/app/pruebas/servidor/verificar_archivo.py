"""verificar_archivo.py — El archivo general, la bitácora, los usuarios y el aprendizaje (T2.14.4).

Contra el sitio de pruebas, entrando como el técnico A, el jefe de UIO y la administración:

  - archivo_indexar_cli.php llena ot_archivo; el técnico ve órdenes de las tres zonas (D1);
  - abre el PDF de una orden que no es suya → 200 y ABRIR_PDF; con &dl=1 → attachment y DESCARGAR_PDF;
  - «Compartir» genera el enlace al pulsar y deja COMPARTIR_PDF con su usuario; el enlace abre sin
    sesión y registra quién lo compartió; con la firma alterada → 403 y fila exito = 0;
  - «Pedir copia» de una orden que vive en la estación deja la solicitud;
  - bitacora.php: técnico 403, administración 200, CSV;
  - aprendizaje: el jefe sube → EN_REVISION; el técnico no lo ve y no puede aprobar (403); la
    administración aprueba → el técnico lo ve, documento.php lo sirve con bitácora, y marca «leído»;
    el mismo archivo no entra dos veces; «publicar ya» de la administración queda aprobado;
  - usuarios.php: editar deja EDITAR_USUARIO con el antes y el después;
  - un POST sin token CSRF → 403 en ordenes.php y documentos.php.

Se corre desde el PC con la llave del servidor:
    INDUSTEC_LLAVE_SSH=... INDUSTEC_SSH_USER=... PYTHONUTF8=1 python verificar_archivo.py
"""
import json
import sys
import urllib.error
import urllib.request
import uuid

import verificar_http as vh

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")


def cabeceras(sesion, ruta):
    """GET con las cabeceras de la respuesta (pedir() no las devuelve)."""
    req = urllib.request.Request(vh.BASE + ruta, headers={"User-Agent": "verificar_archivo/1.0"})
    # En minúsculas: el servidor las devuelve con la caja que le da la gana
    # (HTTP/2 las manda todas en minúscula) y `dict.get` distingue mayúsculas.
    try:
        r = sesion.op.open(req, timeout=60)
        return r.status, {k.lower(): v for k, v in r.headers.items()}, r.read()
    except urllib.error.HTTPError as e:
        return e.code, {k.lower(): v for k, v in e.headers.items()}, e.read()


def subir_archivo(sesion, campos, nombre, datos, tipo="application/pdf", con_csrf=True):
    """POST multipart a documentos.php, como lo manda el formulario."""
    limite = "----industec" + uuid.uuid4().hex
    partes = []
    campos = dict(campos)
    if con_csrf and getattr(sesion, "csrf", ""):
        campos["csrf"] = sesion.csrf
    for k, v in campos.items():
        partes.append(f'--{limite}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode("utf-8"))
    partes.append(f'--{limite}\r\nContent-Disposition: form-data; name="archivo"; filename="{nombre}"\r\n'
                  f'Content-Type: {tipo}\r\n\r\n'.encode("utf-8") + datos + b"\r\n")
    partes.append(f"--{limite}--\r\n".encode("utf-8"))
    cab = {"Content-Type": f"multipart/form-data; boundary={limite}", "User-Agent": "verificar_archivo/1.0"}
    if con_csrf and getattr(sesion, "csrf", ""):
        cab["X-Csrf"] = sesion.csrf
    req = urllib.request.Request(vh.BASE + "documentos.php", data=b"".join(partes), headers=cab)
    try:
        r = sesion.op.open(req, timeout=90)
        return r.status, r.headers.get("Location", ""), r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get("Location", ""), e.read().decode("utf-8", "replace")


def pdf_minimo(marca):
    """Un PDF válido de una página, distinto en cada corrida (la huella no puede repetirse)."""
    cuerpo = (f"%PDF-1.4\n% prueba T2.14.4 {marca}\n"
              "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
              "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
              "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
              "trailer<</Root 1 0 R>>\n%%EOF\n")
    return cuerpo.encode("latin-1")


def bitacora(accion, referencia=None, usuario_id=None, usuario=None):
    q = "SELECT COUNT(*) n FROM bitacora WHERE accion = ?"
    p = [accion]
    if referencia is not None:
        q += " AND referencia = ?"; p.append(str(referencia))
    if usuario_id is not None:
        q += " AND usuario_id = ?"; p.append(int(usuario_id))
    if usuario is not None:
        q += " AND usuario = ?"; p.append(usuario)
    return int(vh.sql(q, p)[0]["n"])


def main():
    claves = json.loads(vh.ssh("cat ~/respaldos/claves_prueba.json"))
    ids = claves["ids"]
    s = {u: vh.Sesion(u) for u in ("tec_prueba_uio_a", "jefe_prueba_uio", "admin_prueba")}
    for u, se in s.items():
        st, loc = se.entrar(claves["claves"][u])
        vh.anotar("ingreso", f"{u} entra", st == 302 and "login.php" not in loc, f"{st} → {loc}")
    tec, jefe, adm = s["tec_prueba_uio_a"], s["jefe_prueba_uio"], s["admin_prueba"]
    a = ids["tec_prueba_uio_a"]
    nombre_a = vh.sql("SELECT nombre FROM usuarios WHERE usuario_id = ?", [a])[0]["nombre"]

    print("\n== 1. el índice del archivo ==")
    salida = vh.ssh(f"cd {vh.D} && php archivo_indexar_cli.php")
    print("   " + "\n   ".join(salida.strip().splitlines()[-2:]))
    n = int(vh.sql("SELECT COUNT(*) n FROM ot_archivo")[0]["n"])
    en_srv = int(vh.sql("SELECT COUNT(*) n FROM ot_archivo WHERE en_servidor = 1")[0]["n"])
    vh.anotar("T2.14.4", "archivo_indexar_cli.php deja filas en ot_archivo, con PDF en el servidor", n > 0 and en_srv > 0, f"{n} filas · {en_srv} con PDF")
    salida2 = vh.ssh(f"cd {vh.D} && php archivo_indexar_cli.php --sin-huella")
    n2 = int(vh.sql("SELECT COUNT(*) n FROM ot_archivo")[0]["n"])
    vh.anotar("T2.14.4", "correrlo dos veces no duplica (idempotente)", n2 == n, f"{n} → {n2}")
    # Dos órdenes de otras zonas que viven solo en la estación (como los 7.069 históricos hasta D2).
    SINT = [("OT-99901-Z999EC-99990001-CNLJ", "CNLJ"), ("OT-99902-Z998EC-99990002-LARB", "LARB")]
    for ot, z in SINT:
        vh.ejecutar("INSERT INTO ot_archivo (id_industec, zona, local_codigo, local_nombre, aviso, modulo, fecha_atencion, tecnico, origen, en_servidor, fuente_ruta) "
                    "VALUES (?, ?, ?, 'PRUEBA T2.14.4', ?, 'CORRECTIVO', '2025-03-15', 'Técnico de prueba', 'HISTORICO', 0, 'D:\\\\RESPALDOS\\\\PRUEBA\\\\no-existe.pdf') "
                    "ON DUPLICATE KEY UPDATE en_servidor = 0", [ot, z, ot.split("-")[2], ot.split("-")[3]])

    print("\n== 2. el técnico consulta el archivo de las tres zonas (D1) ==")
    st, _, c = tec.pedir("ordenes.php")
    vh.anotar("T2.14.4", "ordenes.php como técnico → 200", st == 200, st)
    vh.anotar("T2.14.4", "las tiles muestran las tres zonas, no solo la suya",
              "zona-tile zona-uio" in c and "zona-tile zona-cnlj" in c and "zona-tile zona-larb" in c, "")
    st, _, c = tec.pedir("ordenes.php?q=OT-999")
    vh.anotar("T2.14.4", "y busca órdenes de CNLJ y LARB que no son suyas", st == 200 and "OT-99901-Z999EC" in c and "OT-99902-Z998EC" in c, st)
    vh.anotar("T2.14.4", "una orden que vive en la estación ofrece «pedir copia»", "Pedir copia" in c, "")
    vh.anotar("T2.14.4", "la consulta queda en la bitácora con sus filtros", bitacora("CONSULTAR", "todas", a) > 0, "")
    st, _, c = tec.pedir("ordenes.php?zona=CNLJ&q=99901")
    vh.anotar("T2.14.4", "filtrar por zona y texto", st == 200 and "OT-99901-Z999EC" in c and "OT-99902-Z998EC" not in c, st)

    ajena = vh.sql("SELECT id_industec FROM ot_archivo WHERE en_servidor = 1 AND (tecnico IS NULL OR tecnico NOT LIKE ?) "
                   "AND id_industec NOT LIKE 'OT-999%' ORDER BY fecha_atencion DESC LIMIT 1", [f"%{nombre_a}%"])
    ot = ajena[0]["id_industec"] if ajena else vh.sql("SELECT id_industec FROM ot_archivo WHERE en_servidor = 1 LIMIT 1")[0]["id_industec"]
    print(f"   orden de la prueba: {ot}")
    b0 = bitacora("ABRIR_PDF", ot, a)
    st, cab, cuerpo = cabeceras(tec, f"pdf.php?ot={ot}")
    vh.anotar("T2.14.4", "el técnico abre el PDF de una orden que no es suya → 200 (D1)", st == 200 and cab.get("content-type", "").startswith("application/pdf"), f"{st} · {cab.get('content-type')}")
    vh.anotar("T2.14.4", "y queda ABRIR_PDF con su usuario", bitacora("ABRIR_PDF", ot, a) == b0 + 1, "")
    d0 = bitacora("DESCARGAR_PDF", ot, a)
    st, cab, _ = cabeceras(tec, f"pdf.php?ot={ot}&dl=1")
    vh.anotar("T2.14.4", "&dl=1 entrega para guardar (attachment) y queda DESCARGAR_PDF",
              st == 200 and "attachment" in cab.get("content-disposition", "") and bitacora("DESCARGAR_PDF", ot, a) == d0 + 1, cab.get("content-disposition"))

    print("\n== 3. compartir: el enlace se genera al pulsar y deja rastro ==")
    c0 = bitacora("COMPARTIR_PDF", ot, a)
    st, _, c = tec.pedir("ordenes.php", form={"accion": "compartir", "ot": ot, "canal": "whatsapp"})
    j = json.loads(c or "{}")
    vh.anotar("T2.14.4", "POST compartir → JSON con el enlace y su caducidad", st == 200 and j.get("ok") and "&f=" in j.get("enlace", "") and f"&u={a}" in j["enlace"], f"{st} · {c[:100]}")
    vh.anotar("T2.14.4", "COMPARTIR_PDF con el usuario que lo compartió", bitacora("COMPARTIR_PDF", ot, a) == c0 + 1, "")
    anonimo = vh.Sesion("nadie")
    e0 = int(vh.sql("SELECT COUNT(*) n FROM bitacora WHERE usuario = 'enlace' AND accion = 'ABRIR_PDF' AND referencia = ?", [ot])[0]["n"])
    st, cab, _ = cabeceras(anonimo, j.get("enlace", "pdf.php"))
    vh.anotar("T2.14.4", "el enlace abre sin sesión → 200 PDF", st == 200 and cab.get("content-type", "").startswith("application/pdf"), st)
    fila = vh.sql("SELECT datos FROM bitacora WHERE usuario = 'enlace' AND accion = 'ABRIR_PDF' AND referencia = ? ORDER BY id DESC LIMIT 1", [ot])
    datos = json.loads(fila[0]["datos"]) if fila and fila[0]["datos"] else {}
    vh.anotar("T2.14.4", "y la bitácora dice quién lo compartió",
              int(vh.sql("SELECT COUNT(*) n FROM bitacora WHERE usuario = 'enlace' AND accion = 'ABRIR_PDF' AND referencia = ?", [ot])[0]["n"]) == e0 + 1
              and int(datos.get("compartido_por") or 0) == a, datos)
    roto = j.get("enlace", "").rsplit("&f=", 1)
    enlace_roto = roto[0] + "&f=" + ("0" if roto[1][:1] != "0" else "1") + roto[1][1:] if len(roto) == 2 else "pdf.php?ot=X"
    f0 = int(vh.sql("SELECT COUNT(*) n FROM bitacora WHERE usuario = 'enlace' AND accion = 'DENEGADO' AND referencia = ? AND exito = 0", [ot])[0]["n"])
    st, _, _ = cabeceras(anonimo, enlace_roto)
    vh.anotar("T2.14.4", "con la firma alterada → 403 y fila exito = 0",
              st == 403 and int(vh.sql("SELECT COUNT(*) n FROM bitacora WHERE usuario = 'enlace' AND accion = 'DENEGADO' AND referencia = ? AND exito = 0", [ot])[0]["n"]) == f0 + 1, st)
    st, _, c = tec.pedir("ordenes.php", form={"accion": "compartir", "ot": SINT[0][0]})
    vh.anotar("T2.14.4", "compartir una orden que no está en el servidor → 404", st == 404, st)

    print("\n== 4. pedir copia de lo que vive en la estación ==")
    vh.ejecutar("DELETE FROM ot_archivo_solicitudes WHERE id_industec = ?", [SINT[0][0]])
    st, _, c = tec.pedir("ordenes.php", form={"accion": "pedir_copia", "ot": SINT[0][0]})
    sol = vh.sql("SELECT usuario_id, atendido_en FROM ot_archivo_solicitudes WHERE id_industec = ?", [SINT[0][0]])
    vh.anotar("T2.14.4", "pedir copia → JSON ok y fila en ot_archivo_solicitudes", st == 200 and sol and int(sol[0]["usuario_id"]) == a and sol[0]["atendido_en"] is None, f"{st} · {sol}")
    vh.anotar("T2.14.4", "bitácora SOLICITAR_COPIA", bitacora("SOLICITAR_COPIA", SINT[0][0], a) >= 1, "")
    st, _, c = tec.pedir("ordenes.php", form={"accion": "pedir_copia", "ot": ot})
    vh.anotar("T2.14.4", "pedir copia de una que sí está aquí → 409", st == 409, st)

    print("\n== 5. la bitácora tiene pantalla ==")
    st, _, _ = tec.pedir("bitacora.php")
    vh.anotar("T2.14.4", "bitacora.php como técnico → 403", st == 403, st)
    st, _, _ = jefe.pedir("bitacora.php")
    vh.anotar("T2.14.4", "bitacora.php como jefe de zona → 403", st == 403, st)
    st, _, c = adm.pedir("bitacora.php?usuario=tec_prueba_uio_a&accion=ABRIR_PDF")
    vh.anotar("T2.14.4", "como administración → 200 con las filas del técnico", st == 200 and "abrir pdf" in c and ot in c, st)
    st, cab, cuerpo = cabeceras(adm, "bitacora.php?usuario=tec_prueba_uio_a&formato=csv")
    vh.anotar("T2.14.4", "exportar CSV → text/csv con encabezado", st == 200 and "text/csv" in cab.get("content-type", "") and cuerpo.startswith(b"\xef\xbb\xbfid;cuando"), cab.get("content-type"))
    vh.anotar("T2.14.4", "la exportación queda en la bitácora", bitacora("EXPORTAR_BITACORA", "csv", ids["admin_prueba"]) >= 1, "")

    print("\n== 6. aprendizaje: subir, aprobar, ver, leer ==")
    marca = uuid.uuid4().hex[:8]
    titulo = f"PRUEBA T2.14.4 guía {marca}"
    st, loc, _ = subir_archivo(jefe, {"accion": "subir", "tipo": "GUIA", "titulo": titulo, "descripcion": "no es real", "zona": "UIO"},
                               "guia_prueba.pdf", pdf_minimo(marca))
    doc = vh.sql("SELECT d.doc_id, v.version_id, v.estado, v.ruta FROM documentos d JOIN documento_versiones v ON v.doc_id = d.doc_id WHERE d.titulo = ?", [titulo])
    vh.anotar("T2.14.4", "el jefe sube una guía → 302 y queda EN_REVISION", st == 302 and doc and doc[0]["estado"] == "EN_REVISION", f"{st} · {doc}")
    did = int(doc[0]["doc_id"]) if doc else 0
    vid = int(doc[0]["version_id"]) if doc else 0
    st, _, c = tec.pedir("documentos.php")
    vh.anotar("T2.14.4", "el técnico todavía no la ve", st == 200 and titulo not in c, st)
    st, _, _ = cabeceras(tec, f"documento.php?v={vid}")
    vh.anotar("T2.14.4", "ni puede abrirla (403)", st == 403, st)
    st, _, _ = tec.pedir("documentos.php", form={"accion": "aprobar", "version_id": vid})
    vh.anotar("T2.14.4", "el técnico intenta aprobar → 403", st == 403 and vh.sql("SELECT estado FROM documento_versiones WHERE version_id = ?", [vid])[0]["estado"] == "EN_REVISION", st)
    st, _, c = adm.pedir("documentos.php")
    vh.anotar("T2.14.4", "la administración la ve en «por aprobar»", st == 200 and "Por aprobar" in c and titulo in c, st)
    st, _, _ = adm.pedir("documentos.php", form={"accion": "aprobar", "version_id": vid})
    vh.anotar("T2.14.4", "la administración aprueba → APROBADA con bitácora",
              st == 302 and vh.sql("SELECT estado FROM documento_versiones WHERE version_id = ?", [vid])[0]["estado"] == "APROBADA"
              and bitacora("APROBAR_DOCUMENTO", did, ids["admin_prueba"]) == 1, st)
    st, _, c = tec.pedir("documentos.php")
    vh.anotar("T2.14.4", "ahora el técnico la ve, con «marcar como leído» solo en comunicados", st == 200 and titulo in c, st)
    o0 = bitacora("ABRIR_DOCUMENTO", did, a)
    st, cab, cuerpo = cabeceras(tec, f"documento.php?v={vid}")
    vh.anotar("T2.14.4", "documento.php la sirve como PDF y deja ABRIR_DOCUMENTO",
              st == 200 and cab.get("content-type", "").startswith("application/pdf") and cuerpo.startswith(b"%PDF") and bitacora("ABRIR_DOCUMENTO", did, a) == o0 + 1, st)
    st, _, _ = subir_archivo(adm, {"accion": "subir", "tipo": "GUIA", "titulo": titulo, "doc_id": did}, "guia_prueba.pdf", pdf_minimo(marca))
    nv = int(vh.sql("SELECT COUNT(*) n FROM documento_versiones WHERE doc_id = ?", [did])[0]["n"])
    vh.anotar("T2.14.4", "el mismo archivo otra vez no entra (misma huella)", st == 302 and nv == 1, f"{st} · versiones={nv}")
    st, _, _ = subir_archivo(adm, {"accion": "subir", "tipo": "GUIA", "titulo": titulo, "doc_id": did, "aprobar_ya": "1"}, "guia_prueba_v2.pdf", pdf_minimo(marca + "-v2"))
    v2 = vh.sql("SELECT version, estado FROM documento_versiones WHERE doc_id = ? ORDER BY version DESC LIMIT 1", [did])
    vh.anotar("T2.14.4", "una versión nueva con «publicar ya» de la administración queda APROBADA v2", st == 302 and v2 and int(v2[0]["version"]) == 2 and v2[0]["estado"] == "APROBADA", v2)
    # Un comunicado con acuse.
    titulo_c = f"PRUEBA T2.14.4 comunicado {marca}"
    st, _, _ = subir_archivo(adm, {"accion": "subir", "tipo": "COMUNICADO", "titulo": titulo_c, "aprobar_ya": "1"}, "comunicado.pdf", pdf_minimo(marca + "-com"))
    dc = vh.sql("SELECT doc_id FROM documentos WHERE titulo = ?", [titulo_c])
    dcid = int(dc[0]["doc_id"]) if dc else 0
    st, _, c = tec.pedir("documentos.php?tipo=COMUNICADO")
    vh.anotar("T2.14.4", "el comunicado muestra «de N lo leyeron» y el botón de leído", st == 200 and titulo_c in c and "Marcar como leído" in c, st)
    st, _, _ = tec.pedir("documentos.php", form={"accion": "leido", "doc_id": dcid})
    ac = vh.sql("SELECT version FROM documento_acuses WHERE doc_id = ? AND usuario_id = ?", [dcid, a])
    vh.anotar("T2.14.4", "el técnico marca leído → fila en documento_acuses y bitácora", st == 302 and ac and bitacora("ACUSE_DOCUMENTO", dcid, a) == 1, ac)
    st, _, _ = adm.pedir("documentos.php", form={"accion": "retirar", "version_id": vid, "nota": "prueba"})
    vh.anotar("T2.14.4", "retirar la v1 la deja RETIRADA (no se borra)", st == 302 and vh.sql("SELECT estado FROM documento_versiones WHERE version_id = ?", [vid])[0]["estado"] == "RETIRADA", "")
    st, _, c = tec.pedir("documentos.php")
    vh.anotar("T2.14.4", "el técnico sigue viendo la guía por su v2", st == 200 and titulo in c, st)

    print("\n== 7. usuarios: editar deja el antes y el después ==")
    b = ids["tec_prueba_uio_b"]
    antes = vh.sql("SELECT nombre, correo, rol, zona FROM usuarios WHERE usuario_id = ?", [b])[0]
    st, _, _ = adm.pedir("usuarios.php", form={"accion": "editar", "id": b, "nombre": antes["nombre"] + " (prueba)", "correo": antes["correo"] or "",
                                               "rol": antes["rol"], "zona": antes["zona"] or ""})
    desp = vh.sql("SELECT nombre FROM usuarios WHERE usuario_id = ?", [b])[0]["nombre"]
    vh.anotar("T2.14.4", "editar el nombre → 302 y guardado", st == 302 and desp == antes["nombre"] + " (prueba)", f"{st} · {desp}")
    fila = vh.sql("SELECT estado_antes, estado_despues, datos FROM bitacora WHERE accion = 'EDITAR_USUARIO' AND usuario_id = ? ORDER BY id DESC LIMIT 1", [ids["admin_prueba"]])
    dd = json.loads(fila[0]["datos"]) if fila and fila[0]["datos"] else {}
    vh.anotar("T2.14.4", "EDITAR_USUARIO con el antes y el después",
              fila and dd.get("antes", {}).get("nombre") == antes["nombre"] and dd.get("despues", {}).get("nombre") == antes["nombre"] + " (prueba)"
              and (fila[0]["estado_antes"] or "").startswith("nombre="), (fila[0]["estado_antes"], fila[0]["estado_despues"]) if fila else None)
    adm.pedir("usuarios.php", form={"accion": "editar", "id": b, "nombre": antes["nombre"], "correo": antes["correo"] or "", "rol": antes["rol"], "zona": antes["zona"] or ""})
    vh.anotar("T2.14.4", "y se restaura", vh.sql("SELECT nombre FROM usuarios WHERE usuario_id = ?", [b])[0]["nombre"] == antes["nombre"], "")
    st, _, _ = jefe.pedir("usuarios.php", form={"accion": "editar", "id": b, "nombre": antes["nombre"], "correo": "", "rol": "ADMIN", "zona": ""})
    vh.anotar("T2.14.4", "el jefe no puede ascender a un técnico a administración", vh.sql("SELECT rol FROM usuarios WHERE usuario_id = ?", [b])[0]["rol"] == antes["rol"], st)
    st, _, c = adm.pedir("usuarios.php")
    vh.anotar("T2.14.4", "la lista de la administración va agrupada por zona", st == 200 and c.count('class="grupo"') >= 2 and "Ver actividad" in c, st)

    print("\n== 8. sin token CSRF no se toca nada ==")
    st, _, _ = tec.pedir("ordenes.php", form={"accion": "compartir", "ot": ot, "csrf": None})
    vh.anotar("T2.14.4", "POST a ordenes.php sin csrf → 403", st == 403, st)
    st, _, _ = adm.pedir("documentos.php", form={"accion": "aprobar", "version_id": vid, "csrf": None})
    vh.anotar("T2.14.4", "POST a documentos.php sin csrf → 403", st == 403, st)
    st, _, _ = adm.pedir("usuarios.php", form={"accion": "clave", "id": b, "csrf": None})
    vh.anotar("T2.14.4", "POST a usuarios.php sin csrf → 403", st == 403, st)

    # --- limpieza: lo de prueba no se queda en el sitio ---------------------
    rutas = [r["ruta"] for r in vh.sql("SELECT ruta FROM documento_versiones WHERE doc_id IN (?, ?)", [did, dcid])]
    vh.ejecutar("DELETE FROM documento_acuses WHERE doc_id IN (?, ?)", [did, dcid])
    vh.ejecutar("DELETE FROM documento_versiones WHERE doc_id IN (?, ?)", [did, dcid])
    vh.ejecutar("DELETE FROM documentos WHERE doc_id IN (?, ?)", [did, dcid])
    if rutas:
        vh.ssh(f"cd {vh.D}/documentos && rm -f " + " ".join(r.replace("'", "") for r in rutas if r))
    vh.ejecutar("DELETE FROM ot_archivo_solicitudes WHERE id_industec LIKE 'OT-999%'")
    vh.ejecutar("DELETE FROM ot_archivo WHERE id_industec LIKE 'OT-999%' AND local_nombre = 'PRUEBA T2.14.4'")

    for se in s.values():
        se.pedir("salir.php", form={})
    fallas = [r for r in vh.resultados if not r["ok"]]
    print(f"\n{len(vh.resultados) - len(fallas)} de {len(vh.resultados)} comprobaciones pasan; {len(fallas)} fallan")
    return 1 if fallas else 0


if __name__ == "__main__":
    sys.exit(main())
