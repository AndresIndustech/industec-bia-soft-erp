"""verificar_ciclo.py — T2.12.10: el ciclo completo de un caso por los caminos reales.

1. El técnico A manda desde la app una orden de su segundo caso con el equipo trabado
   (envio.php con `pendiente`): se abre el pendiente y el caso pasa a ESPERA_REPUESTO.
2. El jefe de UIO da el veredicto: vía REPUESTO.
3. La administración avanza la vía: COTIZANDO → COMPRADO → EN_BODEGA → ENTREGADO → RESUELTO.
4. El caso vuelve a ASIGNADO (tiene técnico y no tiene orden de cierre).
5. reconciliar_cli.php en modo ensayo, y la consulta 5 de la 007 da 0 filas.

Reusa verificar_http.py (sesiones, SSH y SQL). Sale con 1 si algo falla.
"""
import datetime
import json
import sys
import uuid

import verificar_http as vh

# En Windows la consola es cp1252 y la flecha «→» de los mensajes reventaba la
# prueba antes de la primera comprobación (AUDITORIA_2026-09-12, P-07).
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")



def main():
    claves = json.loads(vh.ssh("cat ~/respaldos/claves_prueba.json"))
    ids = claves["ids"]
    reg = json.loads(vh.ssh("cat ~/respaldos/prueba_deshacer.json"))
    # Un caso sin otros equipos esperando: si tiene otro pendiente vivo, es
    # CORRECTO que siga en ESPERA_REPUESTO al resolver este, y la prueba no
    # distinguiría el comportamiento bueno del malo.
    vivos = {r["aviso"] for r in vh.sql("SELECT DISTINCT aviso FROM pendientes WHERE estado NOT IN ('RESUELTO','CANCELADO')")}
    libres = [a for a in reg["elegidos"] if a not in vivos]
    if not libres:
        vh.anotar("T2.12.10", "hay un caso del técnico A sin equipos pendientes", False, reg["elegidos"])
        return 1
    aviso = libres[0]
    print(f"caso de la prueba: {aviso} (sin otros equipos pendientes)")
    s = {u: vh.Sesion(u) for u in ("tec_prueba_uio_a", "jefe_prueba_uio", "admin_prueba")}
    for u, se in s.items():
        st, loc = se.entrar(claves["claves"][u])
        vh.anotar("ingreso", f"{u} entra", st == 302 and "login.php" not in loc, f"{st} → {loc}")

    print("\n== 1. el técnico deja el equipo trabado, desde la app ==")
    st, _, c = s["tec_prueba_uio_a"].pedir("catalogos.php")
    cat = json.loads(c)
    caso = next(a for a in cat["avisos"]["datos"] if a["aviso"] == aviso)
    local = caso["local"]
    equipos = (cat.get("equipos") or {}).get(local) or []
    activo = str(equipos[0]["equipo_sap"]) if equipos else ""
    eq = {"equipo_sap": activo, "tipo": equipos[0].get("tipo", "")} if equipos else {"tipo": (cat.get("tipos") or ["FREIDORA"])[0]}
    hoy = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=-5))).date().isoformat()
    orden = {"local": local, "aviso": aviso, "tipo": "CORRECTIVO", "equipos": [eq], "uso_repuesto": False,
             "repuestos": "", "fecha_atencion": hoy, "inicio": f"{hoy} 10:00", "fin": f"{hoy} 11:00",
             "actividades": "PRUEBA T2.12.10: diagnóstico hecho, el equipo queda sin concluir por falta de pieza.",
             "firma_presente": True, "fotos_cantidad": 1, "concluida": False,
             "pendiente": {"diagnostico": "PRUEBA T2.12.10: falta la pieza; no es un equipo real.", "deshabilitado": True,
                           "activo_fijo": activo, "equipo_desc": "Equipo de prueba", "parte": "Pieza de prueba"}}
    cuerpo = {"envio_uuid": str(uuid.uuid4()), "usuario_captura": ids["tec_prueba_uio_a"],
              "capturada_en": datetime.datetime.now(datetime.timezone.utc).isoformat(), "orden": orden}
    st, _, c = s["tec_prueba_uio_a"].pedir("envio.php", cuerpo_json=cuerpo)
    vh.anotar("T2.12.10", "orden con equipo trabado → 200 y el recibo lo registra", st == 200 and "48 horas" in c, f"{st} · {c[:120]}")
    p = vh.sql("SELECT pendiente_id, via, estado, deshabilitado, abierto_por FROM pendientes WHERE aviso = ? AND activo_fijo = ?", [aviso, activo])
    vh.anotar("T2.12.10", "se abrió UN pendiente, parado, a nombre del técnico", len(p) == 1 and int(p[0]["deshabilitado"]) == 1 and int(p[0]["abierto_por"]) == ids["tec_prueba_uio_a"], p)
    pid = int(p[0]["pendiente_id"]) if p else 0
    g = vh.sql("SELECT estado FROM casos_gestion WHERE aviso = ?", [aviso])[0]
    vh.anotar("T2.12.10", "el caso pasa a ESPERA_REPUESTO", g["estado"] == "ESPERA_REPUESTO", g["estado"])

    print("\n== 2. el jefe de zona da el veredicto ==")
    st, _, c = s["jefe_prueba_uio"].pedir("pendientes.php", form={"accion": "veredicto", "pendiente_id": pid, "via": "REPUESTO", "nota": "Prueba T2.12.10: se compra la pieza"})
    vh.anotar("T2.12.10", "el POST del veredicto responde 302, sin error del servidor", st == 302, st)
    p = vh.sql("SELECT via, estado, veredicto_por, TIMESTAMPDIFF(MINUTE, abierto_en, veredicto_en) m FROM pendientes WHERE pendiente_id = ?", [pid])[0]
    vh.anotar("T2.12.10", "veredicto REPUESTO del jefe, dentro de las 48 h", p["via"] == "REPUESTO" and int(p["veredicto_por"]) == ids["jefe_prueba_uio"] and int(p["m"]) <= 48 * 60, p)

    print("\n== 3. la administración avanza la vía hasta resolver ==")
    actual = p["estado"]
    for paso in ["COMPRADO", "EN_BODEGA", "ENTREGADO", "RESUELTO"]:
        if actual == paso:
            continue
        st, _, c = s["admin_prueba"].pedir("pendientes.php", form={"accion": "mover", "pendiente_id": pid, "estado": paso, "nota": f"Prueba T2.12.10: {paso}"})
        vh.anotar("T2.12.10", f"el POST de mover a {paso} responde 302, sin error del servidor", st == 302, st)
        actual = vh.sql("SELECT estado FROM pendientes WHERE pendiente_id = ?", [pid])[0]["estado"]
        vh.anotar("T2.12.10", f"mover a {paso}", actual == paso, actual)
    p = vh.sql("SELECT estado, cerrado_en IS NOT NULL cerrado FROM pendientes WHERE pendiente_id = ?", [pid])[0]
    vh.anotar("T2.12.10", "el pendiente queda RESUELTO y cerrado", p["estado"] == "RESUELTO" and int(p["cerrado"]) == 1, p)

    print("\n== 4. el caso vuelve a la corriente ==")
    g = vh.sql("SELECT estado, asignado_a, ot_cierre FROM casos_gestion WHERE aviso = ?", [aviso])[0]
    vh.anotar("T2.12.10", "sin orden de cierre y con técnico → ASIGNADO", g["estado"] == "ASIGNADO", g)
    notas = vh.sql("SELECT tipo, COUNT(*) n FROM pendiente_notas WHERE pendiente_id = ? GROUP BY tipo", [pid])
    vh.anotar("T2.12.10", "el hilo registra el veredicto y cada cambio de estado", sum(int(n["n"]) for n in notas) >= 5, notas)

    print("\n== 5. la reconciliación, en ensayo, no pisa nada ==")
    salida = vh.ssh(f"cd {vh.D} && php reconciliar_cli.php")
    print("   " + "\n   ".join(salida.strip().splitlines()[-6:]))
    g2 = vh.sql("SELECT estado FROM casos_gestion WHERE aviso = ?", [aviso])[0]
    vh.anotar("T2.12.10", "después de reconciliar, el caso sigue ASIGNADO", g2["estado"] == "ASIGNADO", g2["estado"])
    q5 = vh.sql("SELECT COUNT(*) n FROM casos_gestion WHERE estado IN ('CERRADO_SIN_ATENCION','ATENDIDO') "
                "AND aviso IN (SELECT aviso FROM pendientes WHERE estado NOT IN ('RESUELTO','CANCELADO'))")[0]
    vh.anotar("T2.12.10", "consulta 5 de la 007: ningún caso cerrado con pendiente vivo", int(q5["n"]) == 0, f"{q5['n']} filas")

    for se in s.values():
        se.pedir("salir.php")
    fallas = [r for r in vh.resultados if not r["ok"]]
    print(f"\n{len(vh.resultados) - len(fallas)} de {len(vh.resultados)} comprobaciones pasan; {len(fallas)} fallan")
    return 1 if fallas else 0


if __name__ == "__main__":
    sys.exit(main())
