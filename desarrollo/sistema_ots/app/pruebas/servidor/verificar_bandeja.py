"""verificar_bandeja.py — T2.13.2 y T2.13.3 contra el sitio de pruebas, entrando con las
cuentas de prueba: el formulario le ofrece al técnico solo sus casos abiertos, y la bandeja
y el historial salen de la base, no del catálogo del buzón.

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
import uuid

from verificar_http import D, SALIDA, Sesion, anotar, resultados, sql, ssh

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
    anotar("T2.13.3", "historial de A: las órdenes que mandó desde la app",
           n_cap == 0 or "Órdenes que enviaste desde la app" in c, f"{n_cap} en ot_capturadas")
    st, _, c = sa.pedir(f"mis.php?ver={ATENDIDO}")
    anotar("T2.13.3", "ficha del atendido sin orden de cierre: lo dice (I-7)", st == 200 and "No hay PDF de cierre" in c, st)
    anotar("T2.13.3", "ficha del atendido: no ofrece emitir otra orden", "Emitir la orden de este caso" not in c, "")
    st, _, c = sa.pedir(f"mis.php?ver={ABIERTO}")
    anotar("T2.13.3", "ficha del abierto fuera del catálogo: lo dice y ofrece emitir",
           st == 200 and "no está en el listado del buzón" in c and "Emitir la orden de este caso" in c, st)
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
        anotar("T2.13.3", "«No pude concluir» en un caso fuera del catálogo abre el pendiente, en su zona",
               st == 302 and len(p) == 1 and p[0]["zona"] == "UIO", f"{st} · {p}")
        est = sql("SELECT estado FROM casos_gestion WHERE aviso = ?", [ABIERTO])[0]["estado"]
        anotar("T2.13.3", "el caso pasa a ESPERA_REPUESTO y se sigue ofreciendo",
               est == "ESPERA_REPUESTO" and ABIERTO in catalogo(sa)[2], est)
    finally:
        for f in p:
            ejecutar("DELETE FROM pendiente_notas WHERE pendiente_id = ?", [f["pendiente_id"]])
            ejecutar("DELETE FROM pendientes WHERE pendiente_id = ?", [f["pendiente_id"]])
        ejecutar("UPDATE casos_gestion SET estado = 'ASIGNADO' WHERE aviso = ? AND asignado_a = ?", [ABIERTO, a])

    real = next((x for x in av.values() if x.get("local")), {})
    local = real.get("local")
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
    anotar("T2.13.3", "la orden contra ese caso entra sin «no figura entre tus casos vigentes»",
           st == 200 and "no figura entre tus casos" not in c, f"{st} · {c[:100]}")
    st, _, c = sa.pedir("mis.php?t=atendidas")
    anotar("T2.13.3", "y aparece en su historial, diciendo que el PDF aún no sale de la app",
           f"Aviso {ABIERTO}" in c and "todavía no se genera desde la app" in c, st)

    for se in (sa, sb):
        se.pedir("salir.php")
    (SALIDA / "resultado_bandeja.json").write_text(json.dumps(resultados, ensure_ascii=False, indent=1), encoding="utf-8")
    fallas = [r for r in resultados if not r["ok"]]
    print(f"\n{len(resultados) - len(fallas)} de {len(resultados)} comprobaciones pasan; {len(fallas)} fallan")
    return 1 if fallas else 0


if __name__ == "__main__":
    sys.exit(main())
