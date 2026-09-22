"""verificar_continuidad.py — Un trabajo, varios avisos, contra el sitio de pruebas (T2.25).

Lo que la prueba local (`pruebas/prueba_continuidad.php`, 35 comprobaciones sin
base) NO puede comprobar: que el enlace se ESCRIBE bien, que la orden nueva
cierra el caso viejo, y que el pendiente que quedó trabado en el aviso que SAP
cerró a las 48 horas se resuelve de verdad. Todo eso necesita MySQL.

El recorrido es el caso real que dio origen a la tarea:

  el técnico atiende un caso y el equipo queda trabado (pendiente, reloj corriendo)
  -> SAP cierra ese aviso solo; KFC abre otro por el MISMO equipo del MISMO local
  -> el técnico ve la propuesta en la ficha del caso nuevo y la confirma
  -> el caso nuevo queda enlazado contra la raíz de la cadena
  -> vuelve con el repuesto y emite la orden sobre el caso NUEVO
  -> esa orden cierra los dos casos Y el pendiente del viejo (T2.25.3)

Y las pruebas negativas, que aquí importan tanto como las positivas:
  - enlazar al revés se rechaza (no se puede crear un ciclo)
  - un caso de otra zona no se puede enlazar ni consultar
  - `avisos_sap.estatus_general` NO cambia por nada de esto
  - sin enlace, el pendiente del otro aviso sigue abierto (es la rama de control)

Se corre desde el PC o la estación con la llave del servidor:
    INDUSTEC_LLAVE_SSH=... INDUSTEC_SSH_USER=... PYTHONUTF8=1 python verificar_continuidad.py

Requiere `preparar_prueba.php` corrido en el servidor y la migración 012
aplicada. Si falta la 012, el script lo dice y sale: es un dato, no un fallo.
"""
import base64
import datetime
import json
import sys
import uuid
import zlib

import verificar_http as vh

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")


def _png(ancho, alto, color):
    """Un PNG valido, sin dependencias. La orden no se acepta sin firma."""
    firma = bytes([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A])

    def trozo(tipo, datos):
        c = tipo + datos
        return len(datos).to_bytes(4, "big") + c + zlib.crc32(c).to_bytes(4, "big")

    cab = ancho.to_bytes(4, "big") + alto.to_bytes(4, "big") + bytes([8, 2, 0, 0, 0])
    fila = bytes([0]) + bytes(color) * ancho
    return (firma + trozo(b"IHDR", cab)
            + trozo(b"IDAT", zlib.compress(fila * alto)) + trozo(b"IEND", b""))


PNG = _png(300, 100, (250, 250, 250))


def gestion(aviso, campos="estado, ot_cierre, continua_de, continua_ot"):
    f = vh.sql(f"SELECT {campos} FROM casos_gestion WHERE aviso = ?", [aviso])
    return f[0] if f else {}


def hay_012():
    n = vh.sql("SELECT COUNT(*) n FROM information_schema.COLUMNS "
               "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'casos_gestion' "
               "AND COLUMN_NAME = 'continua_de'")
    return int(n[0]["n"]) > 0


def main():
    if not hay_012():
        print("La migración 012 no está aplicada en este servidor.")
        print("  php aplicar_sql.php sql/012_continuidad_casos.sql")
        print("No es un fallo de la prueba: es que todavía no hay qué comprobar.")
        return 2

    claves = json.loads(vh.ssh("cat ~/respaldos/claves_prueba.json"))
    ids = claves["ids"]
    reg = json.loads(vh.ssh("cat ~/respaldos/prueba_deshacer.json"))

    # Hacen falta DOS casos del mismo técnico y sin pendientes vivos: uno hará
    # de trabajo viejo (el que SAP cerró) y otro de aviso nuevo.
    vivos = {r["aviso"] for r in vh.sql(
        "SELECT DISTINCT aviso FROM pendientes WHERE estado NOT IN ('RESUELTO','CANCELADO')")}
    enlazados = {r["aviso"] for r in vh.sql(
        "SELECT aviso FROM casos_gestion WHERE continua_de IS NOT NULL")}
    libres = [a for a in reg["elegidos"] if a not in vivos and a not in enlazados]
    if len(libres) < 2:
        vh.anotar("T2.25", "hacen falta dos casos de prueba sin pendientes vivos", False, libres)
        return 1
    viejo, nuevo = libres[0], libres[1]
    print(f"trabajo viejo: {viejo}   ·   aviso nuevo de KFC: {nuevo}")

    tec_id = ids["tec_prueba_uio_a"]
    # Punto de partida fijo: los dos casos ASIGNADOS al técnico A, sin cierre y
    # sin enlace. Estos casos acumulan estado entre corridas.
    for a in (viejo, nuevo):
        vh.ejecutar("UPDATE casos_gestion SET estado = 'ASIGNADO', ot_cierre = NULL, atendido_en = NULL, "
                    "continua_de = NULL, continua_ot = NULL, continua_por = NULL, continua_en = NULL, "
                    "continua_nota = NULL, asignado_a = ? WHERE aviso = ?", [tec_id, a])

    # El estado de SAP, ANTES de tocar nada. Es lo que no puede moverse.
    sap_antes = vh.sql("SELECT aviso, estatus_general FROM avisos_sap WHERE aviso IN (?, ?)", [viejo, nuevo])

    s = {u: vh.Sesion(u) for u in ("tec_prueba_uio_a", "tec_prueba_larb", "jefe_prueba_uio")}
    for u, se in s.items():
        st, loc = se.entrar(claves["claves"][u])
        vh.anotar("ingreso", f"{u} entra", st == 302 and "login.php" not in loc, f"{st} → {loc}")
    tec, otro = s["tec_prueba_uio_a"], s["tec_prueba_larb"]
    st, _, c = tec.pedir("catalogos.php")
    cat = json.loads(c)

    print("\n== 1. el equipo queda trabado en el aviso viejo ==")
    st, _, _ = tec.pedir(f"mis.php?ver={viejo}", form={
        "accion": "no_concluye", "aviso": viejo, "activo_fijo": "EQUIPO-PRUEBA-T225",
        "equipo_desc": "horno de la prueba",
        "diagnostico": "quemador tapado; hace falta el inyector. Prueba T2.25.",
        "parte": "inyector", "deshabilitado": "1"})
    vh.anotar("T2.25.3", "el trabado se registra", st in (302, 200), st)
    pen = vh.sql("SELECT pendiente_id, estado FROM pendientes WHERE aviso = ? "
                 "ORDER BY pendiente_id DESC LIMIT 1", [viejo])
    vh.anotar("T2.25.3", "el pendiente existe y está abierto",
              bool(pen) and pen[0]["estado"] not in ("RESUELTO", "CANCELADO"),
              pen[0]["estado"] if pen else "no hay pendiente")
    pid = int(pen[0]["pendiente_id"]) if pen else 0
    # Se lo lleva hasta ENTREGADO: el repuesto ya está en manos del técnico, que
    # es el paso en que emitir la orden lo cierra.
    vh.ejecutar("UPDATE pendientes SET via = 'REPUESTO', estado = 'ENTREGADO' WHERE pendiente_id = ?", [pid])

    print("\n== 2. la rama de control: SIN enlace, la orden del aviso nuevo NO lo toca ==")
    # Es la mitad que demuestra que el arrastre viene del enlace y no de otra cosa.
    vh.ejecutar("UPDATE casos_gestion SET estado = 'ASIGNADO' WHERE aviso = ?", [viejo])
    st, _, cuerpo = tec.pedir("envio.php", cuerpo_json=orden_de(cat, nuevo, tec_id))
    vh.anotar("T2.25.3", "la orden sobre el aviso nuevo se acepta", st == 200, f"{st} {cuerpo[:120]}")
    e = vh.sql("SELECT estado FROM pendientes WHERE pendiente_id = ?", [pid])[0]["estado"]
    vh.anotar("T2.25.3", "SIN enlace, el pendiente del aviso viejo sigue abierto",
              e == "ENTREGADO", e)
    g = gestion(viejo)
    vh.anotar("T2.25.3", "SIN enlace, el caso viejo sigue sin orden de cierre",
              not g.get("ot_cierre"), g.get("ot_cierre"))

    print("\n== 3. el técnico declara que es el mismo trabajo ==")
    # La rama de control dejó el caso nuevo atendido con SU orden: se vuelve al
    # punto de partida para que lo que se mida después sea el enlace y no el
    # arrastre de esa corrida.
    vh.ejecutar("UPDATE casos_gestion SET estado = 'ASIGNADO', ot_cierre = NULL, atendido_en = NULL "
                "WHERE aviso = ?", [nuevo])
    st, _, cuerpo = tec.pedir(f"mis.php?ver={nuevo}")
    vh.anotar("T2.25.2", "la ficha del caso nuevo abre", st == 200, st)
    st, _, _ = tec.pedir(f"mis.php?ver={nuevo}", form={
        "accion": "continua", "aviso": nuevo, "origen": viejo,
        "nota": "mismo equipo: fui por el aviso viejo y hoy instalé el inyector"})
    g = gestion(nuevo)
    vh.anotar("T2.25.2", "el caso nuevo queda enlazado contra el viejo",
              g.get("continua_de") == viejo, g.get("continua_de"))
    n = vh.sql("SELECT COUNT(*) n FROM bitacora WHERE entidad = 'caso' AND referencia = ? "
               "AND accion = 'CASO_CONTINUA'", [nuevo])[0]["n"]
    vh.anotar("T2.25.2", "queda en bitácora a nombre de quien lo declaró", int(n) >= 1, n)

    print("\n== 4. las pruebas negativas ==")
    st, _, _ = tec.pedir(f"mis.php?ver={viejo}", form={
        "accion": "continua", "aviso": viejo, "origen": nuevo, "nota": "al revés"})
    g = gestion(viejo)
    vh.anotar("T2.25.1", "enlazar al revés se rechaza: no se crea el ciclo",
              not g.get("continua_de"), g.get("continua_de"))
    # Un técnico de otra zona no puede enlazar un caso que no es suyo.
    st, _, _ = otro.pedir(f"mis.php?ver={nuevo}", form={
        "accion": "continua", "aviso": nuevo, "origen": viejo, "nota": "ajeno"})
    n = vh.sql("SELECT COUNT(*) n FROM bitacora WHERE entidad = 'caso' AND referencia = ? "
               "AND accion = 'DENEGADO'", [nuevo])[0]["n"]
    vh.anotar("T2.25.2", "un técnico de otra zona no enlaza el caso ajeno", int(n) >= 1, n)

    print("\n== 5. la orden nueva arrastra la cadena y cierra el pendiente viejo ==")
    vh.ejecutar("UPDATE pendientes SET estado = 'ENTREGADO', cerrado_en = NULL, "
                "nota_cierre = NULL WHERE pendiente_id = ?", [pid])
    vh.ejecutar("UPDATE casos_gestion SET estado = 'ASIGNADO', ot_cierre = NULL, atendido_en = NULL "
                "WHERE aviso IN (?, ?)", [viejo, nuevo])
    st, _, cuerpo = tec.pedir("envio.php", cuerpo_json=orden_de(cat, nuevo, tec_id))
    vh.anotar("T2.25.3", "la orden se emite", st == 200, f"{st} {cuerpo[:120]}")
    recibo = json.loads(cuerpo).get("recibo", {}) if st == 200 else {}
    ot = recibo.get("id_industec")

    gv, gn = gestion(viejo), gestion(nuevo)
    vh.anotar("T2.25.3", "el caso NUEVO queda atendido con la orden",
              gn.get("ot_cierre") == ot, f"{gn.get('ot_cierre')} vs {ot}")
    vh.anotar("T2.25.3", "el caso VIEJO también, con la MISMA orden",
              gv.get("ot_cierre") == ot, f"{gv.get('ot_cierre')} vs {ot}")
    p = vh.sql("SELECT estado, nota_cierre FROM pendientes WHERE pendiente_id = ?", [pid])[0]
    vh.anotar("T2.25.3", "el pendiente del aviso viejo queda RESUELTO",
              p["estado"] == "RESUELTO", p["estado"])
    vh.anotar("T2.25.3", "  y la nota dice con qué orden se cerró",
              str(ot or "") in str(p["nota_cierre"] or ""), p["nota_cierre"])
    vh.anotar("T2.25.3", "el recibo le dice al técnico que cerró el caso anterior",
              any(str(viejo) in str(a) for a in recibo.get("anexos", [])), recibo.get("anexos"))

    print("\n== 6. lo que NO puede haberse movido ==")
    sap_desp = {r["aviso"]: r["estatus_general"] for r in
                vh.sql("SELECT aviso, estatus_general FROM avisos_sap WHERE aviso IN (?, ?)", [viejo, nuevo])}
    igual = all(sap_desp.get(r["aviso"]) == r["estatus_general"] for r in sap_antes)
    vh.anotar("T2.25", "avisos_sap.estatus_general NO cambió (regla 5)", igual,
              f"{[(r['aviso'], r['estatus_general']) for r in sap_antes]} → {sap_desp}")
    q5 = vh.sql("SELECT COUNT(*) n FROM casos_gestion WHERE estado IN ('CERRADO_SIN_ATENCION','ATENDIDO') "
                "AND aviso IN (SELECT aviso FROM pendientes WHERE estado NOT IN ('RESUELTO','CANCELADO'))")[0]
    vh.anotar("T2.25", "ningún caso cerrado con un pendiente vivo", int(q5["n"]) == 0, f"{q5['n']} filas")
    hu = vh.sql("SELECT COUNT(*) n FROM casos_gestion WHERE continua_de = aviso")[0]
    vh.anotar("T2.25.1", "ningún caso se continúa a sí mismo", int(hu["n"]) == 0, hu["n"])

    print("\n== 7. deshacer el enlace ==")
    st, _, _ = tec.pedir(f"mis.php?ver={nuevo}", form={
        "accion": "descontinua", "aviso": nuevo, "nota": "prueba"})
    g = gestion(nuevo)
    vh.anotar("T2.25.2", "el enlace se deshace", not g.get("continua_de"), g.get("continua_de"))

    for se in s.values():
        se.pedir("salir.php", form={})
    fallas = [r for r in vh.resultados if not r["ok"]]
    print(f"\n{len(vh.resultados) - len(fallas)} de {len(vh.resultados)} comprobaciones pasan; {len(fallas)} fallan")
    return 1 if fallas else 0


def orden_de(catalogo, aviso, tecnico_id):
    """Una orden válida y concluida para ese aviso, con la forma que manda el celular.

    Mismos campos que `verificar_emision.py`: la validación corre entera en el
    servidor y una orden a medias se rechaza con 400 antes de llegar a lo que
    esta prueba quiere medir.
    """
    caso = next(x for x in catalogo["avisos"]["datos"] if x["aviso"] == aviso)
    local = caso["local"]
    equipos = (catalogo.get("equipos") or {}).get(local) or []
    eq = ({"equipo_sap": str(equipos[0]["equipo_sap"]), "tipo": equipos[0].get("tipo", "")} if equipos
          else {"tipo": (catalogo.get("tipos") or ["HORNO"])[0]})
    eq.update({"estado": "Operativo", "obs": "PRUEBA T2.25", "marca": "MarcaPrueba"})
    hoy = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=-5))).date().isoformat()
    orden = {
        "local": local, "aviso": aviso, "tipo": "CORRECTIVO", "equipos": [eq],
        "uso_repuesto": False, "repuestos": "", "fecha_atencion": hoy,
        "inicio": f"{hoy}T08:00", "fin": f"{hoy}T09:45",
        "actividades": "PRUEBA T2.25: se instaló el inyector y el horno quedó operativo.",
        "admin": "Administrador de Prueba", "observaciones": "PRUEBA: sin novedades",
        "estado_ot": "Cerrada", "atiempo": "Si", "satisfaccion": 9,
        "firma_presente": True,
        "firma_png": "data:image/png;base64," + base64.b64encode(PNG).decode(),
        "fotos": [], "fotos_cantidad": 0, "concluida": True,
    }
    return {"envio_uuid": str(uuid.uuid4()), "usuario_captura": tecnico_id,
            "capturada_en": datetime.datetime.now(datetime.timezone.utc).isoformat(),
            "orden": orden}


if __name__ == "__main__":
    sys.exit(main())
