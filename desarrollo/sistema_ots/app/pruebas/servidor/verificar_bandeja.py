"""verificar_bandeja.py — T2.13.2 a T2.13.5 contra el sitio de pruebas, entrando
con las cuentas de prueba: el formulario le ofrece al técnico solo sus casos abiertos, la
bandeja y el historial salen de la base, no del catálogo del buzón, y el buzón de avisos le
cuenta lo suyo (le asignan o le quitan un caso, le responden, le resuelven una novedad).

En T2.13.5 el jefe de prueba le pasa al técnico B uno de los casos reales de prueba de A y se
lo devuelve, la administración responde en el hilo del pendiente de prueba y resuelve una
novedad de prueba de A. Todo eso lo borra deshacer_prueba.php.

Requiere haber corrido ~/respaldos/preparar_prueba.php, que le crea al técnico A los avisos
sintéticos 99990011 (ASIGNADO) y 99990012 (ATENDIDO sin orden de cierre). Deja lo que
encontró como estaba: el pendiente que abre lo borra y el caso vuelve a ASIGNADO; la orden de
prueba que manda la borra deshacer_prueba.php, con las demás.

Uso:  python verificar_bandeja.py        (la misma llave SSH que verificar_http.py)
Sale con 1 si algo falla.
"""
import datetime
import json
import sys
import time
import uuid

from verificar_http import D, SALIDA, Sesion, anotar, resultados, sql, ssh

# En Windows la consola es cp1252 y la flecha «→» de los mensajes reventaba la
# prueba antes de la primera comprobación (AUDITORIA_2026-09-12, P-07).
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")


ABIERTO, ATENDIDO = "99990011", "99990012"


def ejecutar(q, p=None):
    """Una sentencia que escribe: sql() de verificar_http solo lee."""
    php = ('require "nucleo/Db.php"; $in = json_decode(stream_get_contents(STDIN), true); '
           'echo Db::ejecutar($in["q"], $in["p"]);')
    return int(ssh(f"cd {D} && php -r '{php}'", json.dumps({"q": q, "p": p or []})))


def catalogo(sesion):
    st, _, c = sesion.pedir("catalogos.php")
    j = json.loads(c) if st == 200 else {}
    return st, j, {a["aviso"]: a for a in j.get("avisos", {}).get("datos", [])}


def main():
    claves = json.loads(ssh("cat ~/respaldos/claves_prueba.json"))
    a = claves["ids"]["tec_prueba_uio_a"]
    sa, sb = Sesion("tec_prueba_uio_a"), Sesion("tec_prueba_uio_b")
    sa.entrar(claves["claves"]["tec_prueba_uio_a"])
    sb.entrar(claves["claves"]["tec_prueba_uio_b"])
    sint = {r["aviso"]: r["estado"] for r in sql(
        "SELECT aviso, estado FROM casos_gestion WHERE aviso IN (?, ?) AND asignado_a = ?", [ABIERTO, ATENDIDO, a])}
    if sint != {ABIERTO: "ASIGNADO", ATENDIDO: "ATENDIDO"}:
        sys.exit(f"Los avisos sintéticos no están como los deja preparar_prueba.php: {sint}")

    print("== T2.13.2 · el formulario ofrece solo los casos abiertos del técnico ==")
    abiertos = sorted(r["aviso"] for r in sql(
        "SELECT aviso FROM casos_gestion WHERE asignado_a = ? AND estado IN ('ASIGNADO','ESPERA_REPUESTO')", [a]))
    st, cat_a, av = catalogo(sa)
    anotar("T2.13.2", "técnico A: catalogos.php = sus casos ASIGNADO o ESPERA_REPUESTO",
           st == 200 and sorted(av) == abiertos, sorted(av), abiertos)
    anotar("T2.13.2", "el sintético abierto se ofrece aunque no esté en el catálogo",
           av.get(ABIERTO, {}).get("sin_catalogo") is True, av.get(ABIERTO))
    anotar("T2.13.2", "el sintético ATENDIDO no se ofrece", ATENDIDO not in av, sorted(av))
    ejecutar("UPDATE casos_gestion SET estado = 'ATENDIDO' WHERE aviso = ? AND asignado_a = ?", [ABIERTO, a])
    try:
        st, _, av2 = catalogo(sa)
        anotar("T2.13.2", "asignado a A y pasa a ATENDIDO → sale de su catalogos.php",
               st == 200 and ABIERTO not in av2, sorted(av2))
    finally:
        ejecutar("UPDATE casos_gestion SET estado = 'ASIGNADO' WHERE aviso = ? AND asignado_a = ?", [ABIERTO, a])
    st, _, avb = catalogo(sb)
    anotar("T2.13.2", "técnico B no recibe ninguno de los casos de A", st == 200 and not set(avb) & set(av), sorted(avb))

    print("\n== T2.13.3 · bandeja e historial desde la base ==")
    st, _, c = sa.pedir("mis.php")
    anotar("T2.13.3", "bandeja de A: el sintético abierto, con «sin dato en el catálogo»",
           st == 200 and f"?ver={ABIERTO}" in c and "sin dato en el catálogo" in c, st)
    anotar("T2.13.3", "bandeja de A: el sintético atendido no está entre los pendientes", f"?ver={ATENDIDO}" not in c, "")
    anotar("T2.13.3", "la barra del técnico tiene «Historial»", "mis.php?t=atendidas" in c and "Historial" in c, "")
    st, _, c = sa.pedir("mis.php?t=atendidas")
    anotar("T2.13.3", "historial de A: el sintético atendido aparece", st == 200 and f"?ver={ATENDIDO}" in c, st)
    n_cap = int(sql("SELECT COUNT(*) n FROM ot_capturadas WHERE usuario_id = ?", [a])[0]["n"])
    # Vocabulario único (24-sep-2026): lo que manda el técnico es la «OT INDUSTEC».
    anotar("T2.13.3", "historial de A: las OT INDUSTEC que mandó desde la app",
           n_cap == 0 or "OT INDUSTEC que enviaste desde la app" in c, f"{n_cap} en ot_capturadas")
    st, _, c = sa.pedir(f"mis.php?ver={ATENDIDO}")
    anotar("T2.13.3", "ficha del atendido sin OT INDUSTEC de cierre: lo dice (I-7)",
           st == 200 and "No hay OT INDUSTEC de cierre" in c, st)
    anotar("T2.13.3", "ficha del atendido: no ofrece emitir otra OT INDUSTEC", "Emitir OT INDUSTEC" not in c, "")
    st, _, c = sa.pedir(f"mis.php?ver={ABIERTO}")
    anotar("T2.13.3", "ficha del abierto fuera del catálogo: lo dice y ofrece emitir",
           st == 200 and "no está en el listado del buzón" in c and "Emitir OT INDUSTEC" in c, st)
    st, cab, _ = sb.pedir(f"mis.php?ver={ABIERTO}")
    anotar("T2.13.3", "técnico B pidiendo la ficha del caso de A → vuelve a su bandeja",
           st == 302 and "ver=" not in cab.get("Location", ""), f"{st} → {cab.get('Location', '')}")
    st, _, c = sb.pedir("mis.php?t=atendidas")
    anotar("T2.13.3", "historial de B: nada de A", st == 200 and ABIERTO not in c and ATENDIDO not in c, st)

    print("\n== el caso fuera del catálogo se usa de punta a punta ==")
    st, _, _ = sa.pedir("mis.php", form={"accion": "no_concluye", "aviso": ABIERTO, "activo_fijo": "PRUEBA-SINCAT",
                                          "equipo_desc": "Equipo de prueba",
                                          "diagnostico": "PRUEBA T2.13.3: caso fuera del catálogo; no es un equipo real"})
    p = sql("SELECT pendiente_id, zona FROM pendientes WHERE aviso = ? AND activo_fijo = 'PRUEBA-SINCAT'", [ABIERTO])
    try:
        anotar("T2.13.3", "«El equipo no quedó operativo» en una orden fuera del catálogo abre la solicitud, en su zona",
               st == 302 and len(p) == 1 and p[0]["zona"] == "UIO", f"{st} · {p}")
        est = sql("SELECT estado FROM casos_gestion WHERE aviso = ?", [ABIERTO])[0]["estado"]
        anotar("T2.13.3", "el caso pasa a ESPERA_REPUESTO y se sigue ofreciendo",
               est == "ESPERA_REPUESTO" and ABIERTO in catalogo(sa)[2], est)
    finally:
        for f in p:
            ejecutar("DELETE FROM pendiente_notas WHERE pendiente_id = ?", [f["pendiente_id"]])
            ejecutar("DELETE FROM pendientes WHERE pendiente_id = ?", [f["pendiente_id"]])
        ejecutar("UPDATE casos_gestion SET estado = 'ASIGNADO' WHERE aviso = ? AND asignado_a = ?", [ABIERTO, a])

    # T2.28.1: el local sale de cualquier aviso sintético con local que el
    # técnico tenga abierto (99990021 o 99990022, nunca un caso real). Si por
    # el orden de las baterías los dos ya están cerrados, se cae a G007EC -el
    # local de 99990021- para que esta comprobación no dependa de que otra
    # bateria haya corrido antes (error nro 36): no arma una orden con local
    # vacío, que envio.php rechazaría por un motivo ajeno a lo que se prueba.
    real = next((x for x in av.values() if x.get("local")), {})
    local = real.get("local") or "G007EC"
    equipos = (cat_a.get("equipos") or {}).get(local) or []
    eq = ({"equipo_sap": str(equipos[0].get("equipo_sap")), "tipo": equipos[0].get("tipo", "")} if equipos
          else {"tipo": (cat_a.get("tipos") or ["FREIDORA"])[0]})
    hoy = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=-5))).date().isoformat()
    orden = {"local": local, "aviso": ABIERTO, "tipo": "CORRECTIVO", "equipos": [eq], "uso_repuesto": False,
             "repuestos": "", "fecha_atencion": hoy, "inicio": f"{hoy} 08:00", "fin": f"{hoy} 09:30",
             "actividades": "PRUEBA automatizada (T2.13.3): orden contra un caso fuera del catálogo; no es una intervención real.",
             "firma_presente": True, "fotos_cantidad": 1, "tecnico": "Cualquier nombre que mande el celular",
             "concluida": True}
    st, _, c = sa.pedir("envio.php", cuerpo_json={
        "envio_uuid": str(uuid.uuid4()), "usuario_captura": a,
        "capturada_en": datetime.datetime.now(datetime.timezone.utc).isoformat(), "orden": orden})
    anotar("T2.13.3", "la OT INDUSTEC contra esa orden entra sin «esa orden no figura entre las tuyas»",
           st == 200 and "no figura entre las tuyas" not in c, f"{st} · {c[:100]}")
    st, _, c = sa.pedir("mis.php?t=atendidas")
    anotar("T2.13.3", "y aparece en su historial, con su número de orden y su PDF (la 008)",
           f"Aviso {ABIERTO}" in c and f"-{ABIERTO}-UIO" in c and "Ver PDF" in c, st)

    print("\n== T2.13.5 · el buzón de avisos del técnico ==")
    reg = json.loads(ssh("cat ~/respaldos/prueba_deshacer.json"))
    # Un caso ASIGNADO de los de prueba; si las otras baterías los dejaron
    # todos ATENDIDOS (la orden concluida ahora mueve el caso en el acto), se
    # devuelve uno a ASIGNADO: son casos de prueba del sitio de pruebas.
    estados = {x: sql("SELECT estado FROM casos_gestion WHERE aviso = ?", [x])[0]["estado"] for x in reg["elegidos"]}
    caso = next((x for x, e_ in estados.items() if e_ == "ASIGNADO"), None)
    if caso is None:
        caso = next((x for x, e_ in estados.items() if e_ == "ATENDIDO"), reg["elegidos"][0])
        ejecutar("UPDATE casos_gestion SET estado = 'ASIGNADO', ot_cierre = NULL, atendido_en = NULL WHERE aviso = ?", [caso])
    b = claves["ids"]["tec_prueba_uio_b"]
    sj, sad = Sesion("jefe_prueba_uio"), Sesion("admin_prueba")
    sj.entrar(claves["claves"]["jefe_prueba_uio"])
    sad.entrar(claves["claves"]["admin_prueba"])

    def avisos_de(se):
        st_, _, c_ = se.pedir("novedades.php")
        return (json.loads(c_) if st_ == 200 else {}).get("avisos")

    # «Te quitaron» sale de la asignación anterior en la bitácora: la de preparar_prueba
    # fue por SQL, así que primero el jefe se lo asigna a A por la pantalla.
    sj.pedir("casos.php", form={"accion": "asignar", "aviso": caso, "tecnico": a})
    # H-12 (009): el «visto» lo deja solo la pestaña Notificaciones (t=avisos, `VER_AVISOS`), no
    # cualquier GET a mis.php: antes, abrir una ficha ya contaba como leído.
    sa.pedir("mis.php?t=avisos")
    sb.pedir("mis.php?t=avisos")        # los dos abren sus avisos: ahí queda su «visto»
    time.sleep(1.2)                     # la bitácora guarda segundos: lo nuevo, después
    na, nb = avisos_de(sa), avisos_de(sb)
    anotar("T2.13.5", "recién abierta la bandeja, A y B no tienen avisos", na == 0 and nb == 0, f"A={na} B={nb}")
    st, _, _ = sj.pedir("casos.php", form={"accion": "asignar", "aviso": caso, "tecnico": b})
    na, nb = avisos_de(sa), avisos_de(sb)
    anotar("T2.13.5", "el jefe le pasa a B un caso de A → B 1 aviso y A 1 («te quitaron»)",
           st == 302 and na == 1 and nb == 1, f"{st} · A={na} B={nb}")
    st, _, c = sb.pedir("mis.php?t=avisos")
    anotar("T2.13.5", "B lo ve en su pestaña Notificaciones: «Te asignaron una orden», con enlace",
           "Te asignaron una orden" in c and f"?ver={caso}" in c, st)
    st, _, c = sa.pedir("mis.php?t=avisos")
    anotar("T2.13.5", "A ve «Te quitaron una orden», sin enlace a una ficha que ya no es suya",
           "Te quitaron una orden" in c and f"?ver={caso}" not in c, st)
    na, nb = avisos_de(sa), avisos_de(sb)
    anotar("T2.13.5", "A y B abrieron su bandeja → los dos en 0", na == 0 and nb == 0, f"A={na} B={nb}")
    time.sleep(1.2)
    st, _, _ = sj.pedir("casos.php", form={"accion": "asignar", "aviso": caso, "tecnico": a})
    na, nb = avisos_de(sa), avisos_de(sb)
    anotar("T2.13.5", "el jefe se lo devuelve a A → A 1 aviso y B 1", st == 302 and na == 1 and nb == 1,
           f"{st} · A={na} B={nb}")
    sa.pedir("mis.php?t=avisos")
    sb.pedir("mis.php?t=avisos")
    time.sleep(1.2)
    st, _, _ = sad.pedir("pendientes.php", form={"accion": "responder", "pendiente_id": reg["pendientes"]["uio"],
                                                  "nota": "PRUEBA T2.13.5: respuesta de la administración"})
    na, nb = avisos_de(sa), avisos_de(sb)
    anotar("T2.13.5", "la administración responde sobre el equipo trabado de A → A 1 aviso, B 0",
           na == 1 and nb == 0, f"{st} · A={na} B={nb}")
    u_nov = "99990000-0000-4000-8000-000000000002"
    ejecutar("INSERT INTO novedades (novedad_uuid, zona, tipo, descripcion, riesgo, responsable_prop, reportada_por) "
             "VALUES (?, 'UIO', 'OTRO', 'PRUEBA T2.13.5: novedad de prueba, no es real', 'BAJO', 'INDUSTEC', ?) "
             "ON DUPLICATE KEY UPDATE estado = 'REPORTADA', veredicto_por = NULL, veredicto_en = NULL", [u_nov, a])
    nov = sql("SELECT novedad_id FROM novedades WHERE novedad_uuid = ?", [u_nov])[0]["novedad_id"]
    st, _, _ = sad.pedir("novedades_visita.php", form={"accion": "resolver", "novedad_id": nov, "estado": "DESCARTADA",
                                                        "nota": "PRUEBA T2.13.5", "aviso_sap": ""})
    na = avisos_de(sa)
    anotar("T2.13.5", "la administración resuelve una novedad de A → A 2 avisos", na == 2, f"{st} · A={na}")
    st, _, c = sa.pedir("mis.php?t=avisos")
    anotar("T2.13.5", "A ve «Te respondieron» y «Resolvieron una novedad que reportaste»",
           "Te respondieron" in c and "Resolvieron una novedad" in c, st)
    anotar("T2.13.5", "y al abrirlos vuelve a 0", avisos_de(sa) == 0, "")

    print("\n== T2.13.4 · el cronograma del técnico ==")
    st, _, c = sa.pedir("cronograma.php")
    j = json.loads(c) if st == 200 else {}
    anotar("T2.13.4", "técnico A: cronograma.php sin el padrón (sin clave «tecnicos»)", st == 200 and "tecnicos" not in j,
           sorted(j))
    anotar("T2.13.4", "técnico A: y con «locales» vacío", st == 200 and j.get("locales") == [], len(j.get("locales") or []))
    st, _, c = sj.pedir("cronograma.php")
    j = json.loads(c) if st == 200 else {}
    anotar("T2.13.4", "el jefe de zona sí los recibe: los usa para agendar",
           st == 200 and "tecnicos" in j and len(j.get("locales") or []) > 0, f"{st} · locales={len(j.get('locales') or [])}")

    print("\n== S1 · T2.14.1 · lo nuevo del formulario del técnico ==")
    # (a) El técnico abre el PDF de una OT «en curso» de su propio caso.
    # «En curso» = no es el ot_cierre del caso: es la orden que se mandó más
    # arriba (T2.13.3) contra el aviso ABIERTO, que sigue ASIGNADO/ESPERA_REPUESTO
    # y por tanto sin cerrar. pdf.php lo resuelve S4 (D1: cualquier sesión con
    # `ots.pdf` abre cualquier OT); aquí solo se comprueba que de verdad pasa.
    fila_ot = sql("SELECT id_industec FROM ot_capturadas WHERE aviso = ? AND usuario_id = ? "
                  "ORDER BY captura_id DESC LIMIT 1", [ABIERTO, a])
    if fila_ot and fila_ot[0].get("id_industec"):
        ot_en_curso = fila_ot[0]["id_industec"]
        st, _, c = sa.pedir(f"pdf.php?ot={ot_en_curso}")
        anotar("T2.14.1", "el técnico abre el PDF de una OT «en curso» de su caso (H-01, S4)",
               st == 200, f"{st} · {ot_en_curso}")
    else:
        anotar("T2.14.1", "el técnico abre el PDF de una OT «en curso» de su caso (H-01, S4)",
               False, "no se encontró la orden de T2.13.3 en ot_capturadas: revisa que esa prueba haya corrido antes")

    # (c) catalogos.php trae los prellenados nuevos (H-08, H-11, D8, D9). Las
    # llaves tienen que existir SIEMPRE -- vacías si la 009 no está aplicada,
    # nunca ausentes-- porque de eso depende que app.js no reviente al leerlas.
    st, _, c = sa.pedir("catalogos.php")
    catn = json.loads(c) if st == 200 else {}
    anotar("T2.14.1", "catalogos.php con cookie de técnico trae admins/familias/diagnosticos",
           st == 200 and isinstance(catn.get("admins"), dict) and isinstance(catn.get("familias"), list)
           and isinstance(catn.get("diagnosticos"), list),
           f"{st} · admins={type(catn.get('admins')).__name__} familias={len(catn.get('familias') or [])} "
           f"diagnosticos={len(catn.get('diagnosticos') or [])}")

    # (b) Un equipo "nuevo / no está en la lista" deja fila en equipos_propuestos
    # (H-10, D8), visible para todas las zonas desde que se propone.
    tipo_prop = (catn.get("tipos") or ["FREIDORA ABIERTA"])[0]
    eq_uuid = str(uuid.uuid4())
    orden_nuevo = dict(orden)
    orden_nuevo["equipos"] = [{"nuevo": True, "tipo": tipo_prop, "equipo_uuid": eq_uuid,
                                "marca": "PRUEBA", "codigo_activo": None}]
    st, _, c = sa.pedir("envio.php", cuerpo_json={
        "envio_uuid": str(uuid.uuid4()), "usuario_captura": a,
        "capturada_en": datetime.datetime.now(datetime.timezone.utc).isoformat(), "orden": orden_nuevo})
    try:
        fila_prop = sql("SELECT local_codigo, estado FROM equipos_propuestos WHERE equipo_uuid = ?", [eq_uuid])
        anotar("T2.14.1", "un equipo marcado 'nuevo' deja fila en equipos_propuestos (H-10, D8)",
               st == 200 and len(fila_prop) == 1 and fila_prop[0]["estado"] == "PROPUESTO",
               f"{st} · {fila_prop}")
    finally:
        ejecutar("DELETE FROM equipos_propuestos WHERE equipo_uuid = ?", [eq_uuid])

    # (d) `pendiente` con `concluida = 1` es una contradicción del propio
    # formato -- una visita concluida no puede además dejar un equipo
    # trabado-- y envio.php la rechaza con 400 antes de guardar nada.
    orden_contradice = dict(orden)
    orden_contradice["concluida"] = True
    orden_contradice["pendiente"] = {"activo_fijo": "PRUEBA-CONTRADICE",
                                      "diagnostico": "PRUEBA T2.14.1: no debería aceptarse con concluida=1"}
    st, _, c = sa.pedir("envio.php", cuerpo_json={
        "envio_uuid": str(uuid.uuid4()), "usuario_captura": a,
        "capturada_en": datetime.datetime.now(datetime.timezone.utc).isoformat(), "orden": orden_contradice})
    anotar("T2.14.1", "envio.php rechaza (400) 'pendiente' junto con concluida=1",
           st == 400, f"{st} · {c[:140]}")

    # (e) Todo POST del celular exige el token CSRF (`X-Csrf`, punto 10).
    # `Sesion.pedir` lo manda solo desde que hay `self.csrf`; se lo quita
    # momentáneamente para comprobar que el servidor de verdad lo exige.
    csrf_guardado, sa.csrf = sa.csrf, ""
    try:
        st, _, c = sa.pedir("envio.php", cuerpo_json={
            "envio_uuid": str(uuid.uuid4()), "usuario_captura": a,
            "capturada_en": datetime.datetime.now(datetime.timezone.utc).isoformat(), "orden": orden})
    finally:
        sa.csrf = csrf_guardado
    anotar("T2.14.1", "envio.php sin X-Csrf → 403", st == 403, f"{st} · {c[:140]}")

    for se in (sa, sb, sj, sad):
        se.pedir("salir.php")
    (SALIDA / "resultado_bandeja.json").write_text(json.dumps(resultados, ensure_ascii=False, indent=1), encoding="utf-8")
    fallas = [r for r in resultados if not r["ok"]]
    print(f"\n{len(resultados) - len(fallas)} de {len(resultados)} comprobaciones pasan; {len(fallas)} fallan")
    return 1 if fallas else 0


if __name__ == "__main__":
    sys.exit(main())
