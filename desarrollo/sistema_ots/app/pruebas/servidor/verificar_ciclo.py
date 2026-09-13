"""verificar_ciclo.py — El ciclo completo de un repuesto contra el sitio de pruebas (T2.12.10 y T2.14.3).

Desde el 2026-09-13 recorre la cadena real (D3, migración 009):

  técnico deja el equipo trabado desde la app (SOLICITADO, reloj corriendo)
  -> el jefe pide un dato en el hilo (responder, P-12)
  -> la administración intenta registrar en SAP antes de tiempo (rechazado)
  -> el jefe valida (VALIDADO_JEFE, bitácora PENDIENTE_VALIDA)
  -> registrar sin número (rechazado) / con número (REGISTRADO_SAP)
  -> «KFC decidió: taller de INDUSTEC» (TALLER_INDUSTEC, tercero INDUSTEC)
  -> avanzar hasta RESUELTO; el caso vuelve a ASIGNADO
  -> el mismo equipo vuelve a fallar: episodio nuevo (SOLICITADO)
  -> la administración lo valida sin jefe (bitácora VALIDADO_SIN_JEFE), lo registra,
     KFC envía el repuesto (REPUESTO_ENVIADO), se entrega (ENTREGADO)
  -> la orden concluida del técnico lo resuelve sola (resolverPorOrden, P-06)
  -> un POST sin token CSRF -> 403; la reconciliación en seco no pisa nada.

Se corre desde el PC con la llave del servidor:
    INDUSTEC_LLAVE_SSH=... INDUSTEC_SSH_USER=... PYTHONUTF8=1 python verificar_ciclo.py
"""
import datetime
import json
import sys
import uuid

import verificar_http as vh

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")


def estado(pid):
    return vh.sql("SELECT estado, via, veredicto_kfc, tercero, requerimiento_sap, cerrado_en IS NOT NULL cerrado "
                  "FROM pendientes WHERE pendiente_id = ?", [pid])[0]


def bitacora(pid, accion):
    return int(vh.sql("SELECT COUNT(*) n FROM bitacora WHERE entidad = 'pendiente' AND referencia = ? AND accion = ?",
                      [str(pid), accion])[0]["n"])


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
    # El caso de prueba acumula estado entre corridas (la orden concluida del
    # final lo deja ATENDIDO con su orden de cierre): se parte siempre del
    # mismo punto, ASIGNADO al técnico A y sin cierre. Es un caso de prueba del
    # sitio de pruebas, no un dato del cliente.
    vh.ejecutar("UPDATE casos_gestion SET estado = 'ASIGNADO', ot_cierre = NULL, atendido_en = NULL, "
                "asignado_a = COALESCE(asignado_a, ?) WHERE aviso = ?", [ids["tec_prueba_uio_a"], aviso])
    s = {u: vh.Sesion(u) for u in ("tec_prueba_uio_a", "jefe_prueba_uio", "admin_prueba")}
    for u, se in s.items():
        st, loc = se.entrar(claves["claves"][u])
        vh.anotar("ingreso", f"{u} entra", st == 302 and "login.php" not in loc, f"{st} → {loc}")
    tec, jefe, adm = s["tec_prueba_uio_a"], s["jefe_prueba_uio"], s["admin_prueba"]

    st, _, c = tec.pedir("catalogos.php")
    cat = json.loads(c)
    caso = next(a for a in cat["avisos"]["datos"] if a["aviso"] == aviso)
    local = caso["local"]
    equipos = (cat.get("equipos") or {}).get(local) or []
    activo = str(equipos[0]["equipo_sap"]) if equipos else ""
    eq = {"equipo_sap": activo, "tipo": equipos[0].get("tipo", "")} if equipos else {"tipo": (cat.get("tipos") or ["FREIDORA"])[0]}
    hoy = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=-5))).date().isoformat()

    def orden_base(concluida, pendiente=None):
        o = {"local": local, "aviso": aviso, "tipo": "CORRECTIVO", "equipos": [eq], "uso_repuesto": False,
             "repuestos": "", "fecha_atencion": hoy, "inicio": f"{hoy} 10:00", "fin": f"{hoy} 11:00",
             "actividades": "PRUEBA T2.14.3: " + ("equipo reparado, orden concluida." if concluida
                                                   else "diagnóstico hecho, el equipo queda sin concluir por falta de pieza."),
             "firma_presente": True, "fotos_cantidad": 1, "concluida": concluida}
        if pendiente is not None:
            o["pendiente"] = pendiente
        return o

    def enviar(orden):
        cuerpo = {"envio_uuid": str(uuid.uuid4()), "usuario_captura": ids["tec_prueba_uio_a"],
                  "capturada_en": datetime.datetime.now(datetime.timezone.utc).isoformat(), "orden": orden}
        return tec.pedir("envio.php", cuerpo_json=cuerpo)

    print("\n== 1. el técnico deja el equipo trabado, desde la app ==")
    pend = {"diagnostico": "PRUEBA T2.14.3: falta la pieza; no es un equipo real.", "deshabilitado": True,
            "activo_fijo": activo, "equipo_desc": "Equipo de prueba", "parte": "Pieza de prueba",
            "diagnostico_codigo": "FRE-01",
            "partes": [{"descripcion": "Termostato de prueba", "cantidad": 2, "numero_parte": "TP-01"}]}
    st, _, c = enviar(orden_base(False, pend))
    vh.anotar("T2.12.10", "orden con equipo trabado → 200 y el recibo lo registra", st == 200 and "48 horas" in c, f"{st} · {c[:120]}")
    p = vh.sql("SELECT pendiente_id, via, estado, deshabilitado, abierto_por, diagnostico_codigo, partes "
               "FROM pendientes WHERE aviso = ? AND activo_fijo = ?", [aviso, activo])
    vh.anotar("T2.12.10", "se abrió UN pendiente, parado, a nombre del técnico",
              len(p) == 1 and int(p[0]["deshabilitado"]) == 1 and int(p[0]["abierto_por"]) == ids["tec_prueba_uio_a"], p)
    pid = int(p[0]["pendiente_id"]) if p else 0
    vh.anotar("T2.14.3", "nace SOLICITADO, sin vía", p and p[0]["estado"] == "SOLICITADO" and p[0]["via"] == "SIN_VEREDICTO",
              (p[0]["estado"], p[0]["via"]) if p else None)
    partes = json.loads(p[0]["partes"] or "null") if p else None
    vh.anotar("T2.14.3", "guarda el diagnóstico pre-redactado y las partes en lista (D9)",
              p and p[0]["diagnostico_codigo"] == "FRE-01" and isinstance(partes, list) and partes and partes[0].get("cantidad") == 2,
              (p[0]["diagnostico_codigo"], partes) if p else None)
    g = vh.sql("SELECT estado FROM casos_gestion WHERE aviso = ?", [aviso])[0]
    vh.anotar("T2.12.10", "el caso pasa a ESPERA_REPUESTO", g["estado"] == "ESPERA_REPUESTO", g["estado"])
    n0 = int(vh.sql("SELECT insistencias FROM pendientes WHERE pendiente_id = ?", [pid])[0]["insistencias"])
    vh.anotar("T2.14.3", "abrir no cuenta como insistencia (P-21)", n0 == 0, n0)
    ACCIONES = ("PENDIENTE_VALIDA", "VALIDADO_SIN_JEFE", "PENDIENTE_VEREDICTO_KFC", "PENDIENTE_RESUELTO_POR_ORDEN")
    b0 = {a: bitacora(pid, a) for a in ACCIONES}   # lo que ya había de episodios anteriores

    print("\n== 2. el jefe pide un dato, la administración se adelanta, el jefe valida ==")
    # El hilo conserva las notas de episodios anteriores del mismo equipo (es
    # su historia): se cuenta lo que esta prueba añade, no el total.
    def respuestas():
        return int(vh.sql("SELECT COUNT(*) n FROM pendiente_notas WHERE pendiente_id = ? AND tipo = 'RESPUESTA'", [pid])[0]["n"])
    antes = respuestas()
    st, _, _ = jefe.pedir("pendientes.php", form={"accion": "responder", "pendiente_id": pid, "nota": "PRUEBA T2.14.3: ¿el termostato es el de 300 °C?"})
    vh.anotar("T2.14.3", "el jefe responde en el hilo sin validar (P-12)", st == 302 and respuestas() == antes + 1, f"{st} · {antes} → {respuestas()}")
    st, _, _ = adm.pedir("pendientes.php", form={"accion": "insistir", "pendiente_id": pid, "nota": "PRUEBA T2.14.3: aviso interno de la oficina"})
    n1 = int(vh.sql("SELECT insistencias FROM pendientes WHERE pendiente_id = ?", [pid])[0]["insistencias"])
    tipos = {n["tipo"] for n in vh.sql("SELECT tipo FROM pendiente_notas WHERE pendiente_id = ?", [pid])}
    vh.anotar("T2.14.3", "el recordatorio de la oficina va como AVISO_INTERNO y no suma insistencias (P-13)",
              st == 302 and "AVISO_INTERNO" in tipos and n1 == 0, f"{st} · insistencias={n1} · {sorted(tipos)}")
    st, _, _ = tec.pedir("pendientes.php", form={"accion": "insistir", "pendiente_id": pid, "nota": "PRUEBA T2.14.3: el equipo sigue parado", "urgente": "1"})
    n2 = int(vh.sql("SELECT insistencias FROM pendientes WHERE pendiente_id = ?", [pid])[0]["insistencias"])
    vh.anotar("T2.14.3", "la insistencia del técnico sí cuenta", st == 302 and n2 == 1, f"{st} · insistencias={n2}")
    st, _, _ = adm.pedir("pendientes.php", form={"accion": "registrar_sap", "pendiente_id": pid, "requerimiento_sap": "RQ-PRUEBA-0", "nota": ""})
    e = estado(pid)
    vh.anotar("T2.14.3", "registrar en SAP antes de validar → rechazado, sigue SOLICITADO",
              st == 302 and e["estado"] == "SOLICITADO" and not e["requerimiento_sap"], e)
    st, _, _ = jefe.pedir("pendientes.php", form={"accion": "validar", "pendiente_id": pid, "via": "REPARACION", "nota": "PRUEBA T2.14.3: sale a reparación"})
    p = vh.sql("SELECT via, estado, veredicto_por, validado_por, TIMESTAMPDIFF(MINUTE, abierto_en, validado_en) m FROM pendientes WHERE pendiente_id = ?", [pid])[0]
    vh.anotar("T2.12.10", "el POST de validar responde 302, sin error del servidor", st == 302, st)
    vh.anotar("T2.14.3", "validado por el jefe: VALIDADO_JEFE, vía REPARACION, dentro de las 48 h",
              p["estado"] == "VALIDADO_JEFE" and p["via"] == "REPARACION"
              and int(p["validado_por"]) == ids["jefe_prueba_uio"] and int(p["veredicto_por"]) == ids["jefe_prueba_uio"]
              and int(p["m"]) <= 48 * 60, p)
    vh.anotar("T2.14.3", "bitácora PENDIENTE_VALIDA (no VALIDADO_SIN_JEFE) cuando valida el jefe",
              bitacora(pid, "PENDIENTE_VALIDA") == b0["PENDIENTE_VALIDA"] + 1
              and bitacora(pid, "VALIDADO_SIN_JEFE") == b0["VALIDADO_SIN_JEFE"], "")
    st, _, _ = jefe.pedir("pendientes.php", form={"accion": "validar", "pendiente_id": pid, "via": "REPUESTO", "nota": "segunda vez"})
    vh.anotar("T2.14.3", "validar dos veces no cambia la vía", estado(pid)["via"] == "REPARACION", estado(pid)["via"])

    print("\n== 3. la administración registra en SAP y anota lo que decidió Grupo KFC ==")
    st, _, _ = adm.pedir("pendientes.php", form={"accion": "registrar_sap", "pendiente_id": pid, "requerimiento_sap": "   ", "nota": ""})
    vh.anotar("T2.14.3", "registrar sin número → rechazado (P-02)", st == 302 and estado(pid)["estado"] == "VALIDADO_JEFE", estado(pid))
    st, _, _ = adm.pedir("pendientes.php", form={"accion": "registrar_sap", "pendiente_id": pid, "requerimiento_sap": "RQ-PRUEBA-1", "nota": "PRUEBA T2.14.3"})
    e = estado(pid)
    vh.anotar("T2.14.3", "con número → REGISTRADO_SAP con el requerimiento guardado",
              st == 302 and e["estado"] == "REGISTRADO_SAP" and e["requerimiento_sap"] == "RQ-PRUEBA-1", e)
    st, _, _ = jefe.pedir("pendientes.php", form={"accion": "kfc", "pendiente_id": pid, "veredicto_kfc": "TALLER_INDUSTEC", "nota": ""})
    vh.anotar("T2.14.3", "el jefe no puede anotar la decisión de KFC (repuestos.gestionar)",
              st == 302 and estado(pid)["estado"] == "REGISTRADO_SAP", estado(pid)["estado"])
    st, _, _ = adm.pedir("pendientes.php", form={"accion": "kfc", "pendiente_id": pid, "veredicto_kfc": "OTRO_PROVEEDOR", "tercero": "", "nota": ""})
    vh.anotar("T2.14.3", "«otro proveedor» sin decir cuál → rechazado (P-04)", st == 302 and estado(pid)["estado"] == "REGISTRADO_SAP", estado(pid)["estado"])
    st, _, _ = adm.pedir("pendientes.php", form={"accion": "kfc", "pendiente_id": pid, "veredicto_kfc": "TALLER_INDUSTEC", "referencia": "KFC-PRUEBA-1", "nota": "PRUEBA T2.14.3"})
    e = estado(pid)
    vh.anotar("T2.14.3", "«KFC decidió: taller de INDUSTEC» → TALLER_INDUSTEC, tercero INDUSTEC",
              st == 302 and e["estado"] == "TALLER_INDUSTEC" and e["veredicto_kfc"] == "TALLER_INDUSTEC" and e["tercero"] == "INDUSTEC", e)
    vh.anotar("T2.14.3", "bitácora PENDIENTE_VEREDICTO_KFC", bitacora(pid, "PENDIENTE_VEREDICTO_KFC") == b0["PENDIENTE_VEREDICTO_KFC"] + 1, "")

    print("\n== 4. la administración avanza el camino hasta resolver ==")
    st, _, _ = adm.pedir("pendientes.php", form={"accion": "mover", "pendiente_id": pid, "estado": "COTIZANDO", "nota": "no debería"})
    vh.anotar("T2.14.3", "un paso de la compra vieja no pertenece al camino de KFC", estado(pid)["estado"] == "TALLER_INDUSTEC", estado(pid)["estado"])
    st, _, _ = adm.pedir("pendientes.php", form={"accion": "mover", "pendiente_id": pid, "estado": "RESUELTO", "nota": ""})
    vh.anotar("T2.14.3", "saltar un paso sin nota → rechazado (P-17)", estado(pid)["estado"] == "TALLER_INDUSTEC", estado(pid)["estado"])
    for paso in ["DEVUELTO_TALLER", "RESUELTO"]:
        st, _, _ = adm.pedir("pendientes.php", form={"accion": "mover", "pendiente_id": pid, "estado": paso, "nota": f"PRUEBA T2.14.3: {paso}"})
        vh.anotar("T2.12.10", f"mover a {paso}", st == 302 and estado(pid)["estado"] == paso, f"{st} · {estado(pid)['estado']}")
    e = estado(pid)
    vh.anotar("T2.12.10", "el pendiente queda RESUELTO y cerrado", e["estado"] == "RESUELTO" and int(e["cerrado"]) == 1, e)
    g = vh.sql("SELECT estado, asignado_a, ot_cierre FROM casos_gestion WHERE aviso = ?", [aviso])[0]
    vh.anotar("T2.12.10", "el caso vuelve a la corriente: sin orden de cierre y con técnico → ASIGNADO", g["estado"] == "ASIGNADO", g)
    notas = vh.sql("SELECT tipo, COUNT(*) n FROM pendiente_notas WHERE pendiente_id = ? GROUP BY tipo", [pid])
    tipos = {n["tipo"] for n in notas}
    vh.anotar("T2.14.3", "el hilo guarda la validación, el registro en SAP, la decisión de KFC y cada paso",
              {"VALIDACION", "REGISTRO_SAP", "VEREDICTO_KFC", "CAMBIO_ESTADO"} <= tipos, sorted(tipos))

    print("\n== 5. el mismo equipo vuelve a fallar: la administración valida sin jefe y la orden lo cierra ==")
    st, _, c = enviar(orden_base(False, dict(pend, diagnostico="PRUEBA T2.14.3: volvió a fallar; segundo episodio.")))
    e = estado(pid)
    vh.anotar("T2.14.3", "el reporte posterior al cierre reabre el episodio: SOLICITADO otra vez",
              st == 200 and e["estado"] == "SOLICITADO" and e["via"] == "SIN_VEREDICTO" and e["veredicto_kfc"] == "PENDIENTE", e)
    st, _, _ = adm.pedir("pendientes.php", form={"accion": "validar", "pendiente_id": pid, "via": "REPUESTO", "nota": "PRUEBA T2.14.3: el jefe está de vacaciones"})
    vh.anotar("T2.14.3", "la administración puede validar, y queda marcado VALIDADO_SIN_JEFE (P-08)",
              st == 302 and estado(pid)["estado"] == "VALIDADO_JEFE" and bitacora(pid, "VALIDADO_SIN_JEFE") == b0["VALIDADO_SIN_JEFE"] + 1, estado(pid)["estado"])
    adm.pedir("pendientes.php", form={"accion": "registrar_sap", "pendiente_id": pid, "requerimiento_sap": "RQ-PRUEBA-2", "nota": ""})
    st, _, _ = adm.pedir("pendientes.php", form={"accion": "kfc", "pendiente_id": pid, "veredicto_kfc": "REPUESTO_ENVIADO", "referencia": "GUIA-PRUEBA", "nota": ""})
    vh.anotar("T2.14.3", "«KFC envía el repuesto» → REPUESTO_ENVIADO", st == 302 and estado(pid)["estado"] == "REPUESTO_ENVIADO", estado(pid)["estado"])
    st, _, _ = adm.pedir("pendientes.php", form={"accion": "mover", "pendiente_id": pid, "estado": "ENTREGADO", "nota": "", "prometido": hoy})
    vh.anotar("T2.14.3", "llegó al local: ENTREGADO, con fecha comprometida", st == 302 and estado(pid)["estado"] == "ENTREGADO", estado(pid)["estado"])
    st, _, c = enviar(orden_base(True))
    e = estado(pid)
    vh.anotar("T2.14.3", "la orden concluida del técnico resuelve sola el pendiente ENTREGADO (P-06)",
              st == 200 and e["estado"] == "RESUELTO" and int(e["cerrado"]) == 1, f"{st} · {e}")
    recibo = json.loads(c or "{}").get("recibo") or {}
    g = vh.sql("SELECT ot_cierre FROM casos_gestion WHERE aviso = ?", [aviso])[0]
    vh.anotar("T2.14.3", "la orden concluida deja su número como orden de cierre del caso, en el acto",
              bool(g["ot_cierre"]) and g["ot_cierre"] == recibo.get("id_industec", g["ot_cierre"]), g)
    vh.anotar("T2.14.3", "bitácora PENDIENTE_RESUELTO_POR_ORDEN", bitacora(pid, "PENDIENTE_RESUELTO_POR_ORDEN") == b0["PENDIENTE_RESUELTO_POR_ORDEN"] + 1, "")
    g = vh.sql("SELECT estado, ot_cierre FROM casos_gestion WHERE aviso = ?", [aviso])[0]
    vh.anotar("T2.14.3", "con orden de cierre, el caso queda ATENDIDO", g["estado"] == "ATENDIDO", g)

    print("\n== 6. sin token CSRF no se toca nada (D13) ==")
    st, _, _ = jefe.pedir("pendientes.php", form={"accion": "responder", "pendiente_id": pid, "nota": "sin token", "csrf": None})
    vh.anotar("T2.14.3", "POST a pendientes.php sin csrf → 403", st == 403, st)
    st, _, _ = adm.pedir("novedades_visita.php", form={"accion": "reportar", "descripcion": "sin token", "local": local, "csrf": None})
    vh.anotar("T2.14.3", "POST a novedades_visita.php sin csrf → 403", st == 403, st)

    print("\n== 7. novedades: la zona es la del local y las transiciones se respetan (P-14, P-15, SEG-12) ==")
    u_nov = "99990000-0000-4000-8000-000000000003"
    vh.ejecutar("DELETE FROM novedades WHERE novedad_uuid = ?", [u_nov])
    st, _, _ = adm.pedir("novedades_visita.php", form={"accion": "reportar", "descripcion": "PRUEBA T2.14.3: novedad desde la oficina, no es real",
                                                        "tipo": "ELECTRICO", "riesgo": "BAJO", "responsable": "CLIENTE", "local": local,
                                                        "zona": "CNLJ"})
    nov = vh.sql("SELECT novedad_id, zona, estado, local_codigo FROM novedades WHERE descripcion LIKE 'PRUEBA T2.14.3: novedad desde la oficina%' "
                 "ORDER BY novedad_id DESC LIMIT 1")
    zona_local = vh.sql("SELECT zona FROM casos_gestion WHERE aviso = ?", [aviso])[0]["zona"]
    vh.anotar("T2.14.3", "la oficina registra una novedad y la zona sale del maestro del local, no del POST",
              st == 302 and nov and nov[0]["zona"] == zona_local and nov[0]["estado"] == "REPORTADA", f"{st} · {nov}")
    nid = int(nov[0]["novedad_id"]) if nov else 0
    st, _, _ = adm.pedir("novedades_visita.php", form={"accion": "reportar", "descripcion": "PRUEBA T2.14.3: local inexistente", "tipo": "OTRO", "local": "ZZZ999"})
    fantasma = vh.sql("SELECT COUNT(*) n FROM novedades WHERE local_codigo = 'ZZZ999'")[0]["n"]
    vh.anotar("T2.14.3", "un local que no está en el catálogo se rechaza (SEG-12)", st == 302 and int(fantasma) == 0, f"{st} · {fantasma}")
    st, _, _ = adm.pedir("novedades_visita.php", form={"accion": "resolver", "novedad_id": nid, "estado": "DERIVADA_SAP", "aviso_sap": "10399999", "nota": ""})
    e = vh.sql("SELECT estado, aviso_sap FROM novedades WHERE novedad_id = ?", [nid])[0]
    vh.anotar("T2.14.3", "derivada con aviso", e["estado"] == "DERIVADA_SAP" and e["aviso_sap"] == "10399999", e)
    st, _, _ = adm.pedir("novedades_visita.php", form={"accion": "resolver", "novedad_id": nid, "estado": "REPORTADA", "aviso_sap": "", "nota": "atrás"})
    vh.anotar("T2.14.3", "una derivada no vuelve a REPORTADA (P-15)", vh.sql("SELECT estado FROM novedades WHERE novedad_id = ?", [nid])[0]["estado"] == "DERIVADA_SAP", "")
    st, _, _ = adm.pedir("novedades_visita.php", form={"accion": "resolver", "novedad_id": nid, "estado": "RESUELTA", "aviso_sap": "", "nota": "PRUEBA T2.14.3: atendida"})
    e = vh.sql("SELECT estado, aviso_sap FROM novedades WHERE novedad_id = ?", [nid])[0]
    vh.anotar("T2.14.3", "y sí pasa a RESUELTA conservando el aviso", e["estado"] == "RESUELTA" and e["aviso_sap"] == "10399999", e)
    vh.ejecutar("DELETE FROM novedades WHERE novedad_id = ?", [nid])

    print("\n== 8. la reconciliación, en ensayo, no pisa nada ==")
    salida = vh.ssh(f"cd {vh.D} && php reconciliar_cli.php")
    print("   " + "\n   ".join(salida.strip().splitlines()[-6:]))
    g2 = vh.sql("SELECT estado FROM casos_gestion WHERE aviso = ?", [aviso])[0]
    vh.anotar("T2.12.10", "después de reconciliar, el caso sigue ATENDIDO", g2["estado"] == "ATENDIDO", g2["estado"])
    q5 = vh.sql("SELECT COUNT(*) n FROM casos_gestion WHERE estado IN ('CERRADO_SIN_ATENCION','ATENDIDO') "
                "AND aviso IN (SELECT aviso FROM pendientes WHERE estado NOT IN ('RESUELTO','CANCELADO'))")[0]
    vh.anotar("T2.12.10", "consulta 5 de la 007: ningún caso cerrado con pendiente vivo", int(q5["n"]) == 0, f"{q5['n']} filas")

    for se in s.values():
        se.pedir("salir.php", form={})
    fallas = [r for r in vh.resultados if not r["ok"]]
    print(f"\n{len(vh.resultados) - len(fallas)} de {len(vh.resultados)} comprobaciones pasan; {len(fallas)} fallan")
    return 1 if fallas else 0


if __name__ == "__main__":
    sys.exit(main())
