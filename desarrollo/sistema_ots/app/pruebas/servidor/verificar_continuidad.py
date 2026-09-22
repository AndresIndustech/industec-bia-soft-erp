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
  - declarar continuidad sobre el caso de OTRO técnico sigue prohibido: el
    caso actual tiene que ser suyo, eso no cambió
  - pero el ORIGEN (el trabajo anterior) ya no tiene que ser suyo (T2.25.4,
    2026-09-22): las urgencias reasignan, y exigir que fuera "suyo" tapaba el
    caso más común -- basta con que sea de su misma zona. Lo que sigue
    cortando es la zona (`Auth::alcanzaZona`), pero este arnés no tiene una
    cuenta de OTRA zona para ejercitar esa rama — queda sin probar aquí,
    dicho y no fingido (I-7)
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

    # Los DOS avisos sintéticos del arnés (99990011 y 99990012): no existen en
    # SAP, así que la prueba no mueve ni un caso del cliente. Uno hace de
    # trabajo viejo —el que SAP cerró a las 48 h— y el otro del aviso nuevo.
    # Al no estar en el catálogo, la propuesta automática no los ofrece: lo que
    # esta prueba ejercita es el ENLACE y su arrastre, que es lo que necesita
    # base. La propuesta ya la cubre `prueba_continuidad.php` contra el
    # catálogo real, sin base y con el caso del horno de G006EC fijado.
    viejo, nuevo = "99990011", "99990012"
    faltan = [a for a in (viejo, nuevo)
              if not vh.sql("SELECT aviso FROM casos_gestion WHERE aviso = ?", [a])]
    if faltan:
        vh.anotar("T2.25", "los avisos sintéticos del arnés existen", False,
                  f"faltan {faltan}; corre preparar_prueba.php")
        return 1
    print(f"trabajo viejo: {viejo}   ·   aviso nuevo de KFC: {nuevo}   (sintéticos del arnés)")

    tec_id = ids["tec_prueba_uio_a"]
    # Punto de partida fijo: los dos casos ASIGNADOS al técnico A, sin cierre y
    # sin enlace. Estos casos acumulan estado entre corridas.
    for a in (viejo, nuevo):
        vh.ejecutar("UPDATE casos_gestion SET estado = 'ASIGNADO', ot_cierre = NULL, atendido_en = NULL, "
                    "continua_de = NULL, continua_ot = NULL, continua_por = NULL, continua_en = NULL, "
                    "continua_nota = NULL, asignado_a = ? WHERE aviso = ?", [tec_id, a])

    # El estado de SAP, ANTES de tocar nada: es lo que no puede moverse.
    # En el servidor no existe la tabla `avisos_sap` (esa vive en la base de la
    # estación): aquí lo que dice SAP es el catálogo, un archivo que la estación
    # REESCRIBE entero en cada barrido y que nada de la app puede tocar. Se mide
    # por su huella, que es la forma que tiene esa regla en este lado.
    sap_antes = vh.ssh("cd " + vh.D + " && sha256sum catalogos/casos_sap.json").split()[0]

    s = {u: vh.Sesion(u) for u in ("tec_prueba_uio_a", "tec_prueba_uio_b", "jefe_prueba_uio")}
    for u, se in s.items():
        st, loc = se.entrar(claves["claves"][u])
        vh.anotar("ingreso", f"{u} entra", st == 302 and "login.php" not in loc, f"{st} → {loc}")
    tec, otro = s["tec_prueba_uio_a"], s["tec_prueba_uio_b"]
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

    # El candado que NO se tocó en T2.25.4: el caso ACTUAL (avisoN) tiene que
    # seguir siendo del técnico que lo tiene asignado. `otro` (tec_prueba_uio_b)
    # no tiene 99990012 asignado, así que declarar sobre él sigue prohibido,
    # sin llegar siquiera a mirar el origen. En este punto el paso 3 ya dejó
    # `nuevo` enlazado con `viejo`: lo que importa es que el intento de `otro`
    # NO lo cambie, no que quede vacío.
    antes = gestion(nuevo).get("continua_de")
    st, _, _ = otro.pedir(f"mis.php?ver={nuevo}", form={
        "accion": "continua", "aviso": nuevo, "origen": viejo, "nota": "caso ajeno"})
    g = gestion(nuevo)
    vh.anotar("T2.25", "un técnico no declara continuidad sobre el caso de otro",
              g.get("continua_de") == antes, g.get("continua_de"))

    # T2.25.4 (2026-09-22): lo que SÍ cambió es el ORIGEN. Las urgencias
    # reasignan, así que ya no hace falta que el trabajo anterior sea del
    # mismo técnico -- alcanza con que sea de su zona. Se desenlaza lo que
    # dejó el paso 3 y el aviso viejo pasa a `otro`, para que el origen sea
    # de verdad de otro técnico y no del mismo `tec`.
    otro_id = ids["tec_prueba_uio_b"]
    st, _, _ = tec.pedir(f"mis.php?ver={nuevo}", form={
        "accion": "descontinua", "aviso": nuevo, "nota": "prueba: se limpia para T2.25.4"})
    g = gestion(nuevo)
    vh.anotar("T2.25.4", "se desenlaza para dejar la prueba siguiente limpia",
              not g.get("continua_de"), g.get("continua_de"))
    vh.ejecutar("UPDATE casos_gestion SET asignado_a = ? WHERE aviso = ?", [otro_id, viejo])

    st, _, _ = tec.pedir(f"mis.php?ver={nuevo}", form={
        "accion": "continua", "aviso": nuevo, "origen": viejo,
        "nota": "el trabajo anterior lo atendió otro técnico de la zona"})
    g = gestion(nuevo)
    vh.anotar("T2.25.4", "el dueño del caso nuevo SÍ enlaza contra un origen que no es suyo",
              g.get("continua_de") == viejo, g.get("continua_de"))
    ultimo = vh.sql("SELECT usuario_id FROM bitacora WHERE entidad = 'caso' AND referencia = ? "
                     "AND accion = 'CASO_CONTINUA' ORDER BY id DESC LIMIT 1", [nuevo])
    vh.anotar("T2.25.4", "  y queda en bitácora a nombre del técnico que declaró",
              bool(ultimo) and int(ultimo[0]["usuario_id"]) == int(tec_id),
              ultimo[0]["usuario_id"] if ultimo else "sin fila")

    # T2.25.5: el buzón del jefe tiene que poder decir quién empezó el trabajo
    # anterior. `Casos::quienAtendio()` lo saca de la FIRMA de la orden
    # archivada, y solo si no hay orden cae al asignado. Ese orden de
    # preferencia es el punto entero: en el primer caso real de continuidad el
    # aviso viejo estaba CERRADO_SIN_ATENCION y con `asignado_a` en NULL —
    # mirar el asignado habría dicho «sin dato» justo ahí, mientras la orden
    # OT-1561 tenía la firma de Marco Taipe.
    #
    # Aquí se ejercita esa preferencia con el terreno al revés a propósito: el
    # aviso viejo está asignado a `otro` (lo acaba de reasignar la prueba) pero
    # sus órdenes del arnés las firmó el técnico A. Tiene que ganar la firma.
    quien = json.loads(vh.ssh(
        "cd " + vh.D + " && php -r \"require 'nucleo/Db.php'; require 'nucleo/Casos.php'; "
        "echo json_encode(Casos::quienAtendio(['" + viejo + "','99999999']));\""))
    firma = vh.sql("SELECT tecnico FROM ot_archivo WHERE aviso = ? AND tecnico <> '' "
                   "ORDER BY fecha_atencion DESC LIMIT 1", [viejo])
    if firma:
        vh.anotar("T2.25.5", "manda la FIRMA de la orden, no a quién está asignado hoy",
                  (quien.get(viejo) or {}).get("nombre") == firma[0]["tecnico"]
                  and (quien.get(viejo) or {}).get("fuente") == "la orden",
                  f"{(quien.get(viejo) or {}).get('nombre')} por {(quien.get(viejo) or {}).get('fuente')}")
    else:
        # Sin orden archivada, el respaldo es el asignado: la otra rama.
        nombre_otro = vh.sql("SELECT nombre FROM usuarios WHERE usuario_id = ?", [otro_id])[0]["nombre"]
        vh.anotar("T2.25.5", "sin orden archivada, el respaldo es a quién está asignado",
                  (quien.get(viejo) or {}).get("nombre") == nombre_otro
                  and (quien.get(viejo) or {}).get("fuente") == "asignado",
                  f"{(quien.get(viejo) or {}).get('nombre')} por {(quien.get(viejo) or {}).get('fuente')}")
    vh.anotar("T2.25.5", "  y de un aviso que no existe NO inventa un nombre",
              "99999999" not in quien, list(quien.keys()))

    vh.ejecutar("UPDATE casos_gestion SET asignado_a = ? WHERE aviso = ?", [tec_id, viejo])

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
    sap_desp = vh.ssh("cd " + vh.D + " && sha256sum catalogos/casos_sap.json").split()[0]
    vh.anotar("T2.25", "el catálogo de SAP no se tocó: misma huella (regla 5)",
              sap_desp == sap_antes, f"{sap_antes[:12]}… → {sap_desp[:12]}…")
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

    print("\n== 8. el terreno se devuelve como estaba ==")
    # `verificar_bandeja.py` comprueba que 99990011 esté ASIGNADO y 99990012
    # ATENDIDO sin orden de cierre: es lo que deja `preparar_prueba.php` y lo
    # que esa batería necesita para distinguir la bandeja del historial. Esta
    # prueba los mueve a los dos, así que los devuelve. Sin esto, correr esta
    # batería rompía la siguiente -- y el fallo aparecía lejos de su causa.
    vh.ejecutar("UPDATE casos_gestion SET estado = 'ASIGNADO', ot_cierre = NULL, atendido_en = NULL, "
                "continua_de = NULL, continua_ot = NULL, continua_por = NULL, continua_en = NULL, "
                "continua_nota = NULL WHERE aviso = ?", [viejo])
    vh.ejecutar("UPDATE casos_gestion SET estado = 'ATENDIDO', ot_cierre = NULL, atendido_en = NULL, "
                "continua_de = NULL, continua_ot = NULL, continua_por = NULL, continua_en = NULL, "
                "continua_nota = NULL WHERE aviso = ?", [nuevo])
    # El pendiente que abrió esta prueba: se cancela, no se borra. La bitácora
    # y el hilo quedan; borrar filas de `pendientes` es justo lo que no se hace.
    if pid:
        vh.ejecutar("UPDATE pendientes SET estado = 'CANCELADO', cerrado_en = NOW(), "
                    "nota_cierre = 'Cerrado al terminar verificar_continuidad.py' "
                    "WHERE pendiente_id = ? AND estado NOT IN ('RESUELTO','CANCELADO')", [pid])
    quedo = {r["aviso"]: r["estado"] for r in
             vh.sql("SELECT aviso, estado FROM casos_gestion WHERE aviso IN (?, ?)", [viejo, nuevo])}
    vh.anotar("T2.25", "los avisos sintéticos quedan como los deja preparar_prueba.php",
              quedo == {viejo: "ASIGNADO", nuevo: "ATENDIDO"}, quedo)
    vivos = vh.sql("SELECT COUNT(*) n FROM pendientes WHERE aviso IN (?, ?) "
                   "AND estado NOT IN ('RESUELTO','CANCELADO')", [viejo, nuevo])[0]["n"]
    vh.anotar("T2.25", "no queda ningún pendiente vivo de esta prueba", int(vivos) == 0, vivos)

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
    # Los avisos sintéticos del arnés SÍ salen en el catálogo del técnico —los
    # tiene asignados— pero SIN local: no existen en SAP. Sin local la orden se
    # rechaza con 400 antes de llegar a lo que esta prueba mide, así que se le
    # pone un local real de UIO. `envio.php` la marca con la observación
    # LOCAL_DISTINTO_AL_DEL_CASO y la acepta igual, que es lo previsto para una
    # orden firmada cuyo caso no cuadra con el catálogo.
    caso = next((x for x in catalogo["avisos"]["datos"]
                 if x["aviso"] == aviso and x.get("local")), None)
    if caso is None:
        caso = next(x for x in catalogo["avisos"]["datos"] if x.get("local"))
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
