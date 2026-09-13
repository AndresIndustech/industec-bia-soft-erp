"""verificar_seguridad.py — Lo que cierra el núcleo (T2.14.6) contra el sitio de pruebas.

  - las pantallas con sesión llevan Content-Security-Policy y Cache-Control: no-store;
  - /ot/.gitignore → 403 (dotfiles cerrados);
  - salir.php por GET no cierra la sesión; por POST sin csrf → 403; con csrf, cierra;
  - login.php con otra sesión abierta no vuelve a poner la clave en el HTML, y el segundo
    formulario entra solo con «desplazar» (SEG-10);
  - una petición sin sesión deja SIN_SESION en sesiones_log, una por conexión y minuto (SEG-20);
  - novedades.php no renueva la sesión; las pantallas la renuevan a lo sumo una vez por minuto (SEG-05);
  - a las 12 horas la sesión cae aunque haya actividad (tope diario);
  - clave.php cuenta los fallos de la clave actual y al quinto cierra la sesión y bloquea (SEG-11);
  - las IP de sesiones_log son distintas entre sí (el CDN no las enmascara, SEG-16);
  - purgar_cli.php informa sin borrar; login.php?r=cronograma.html vuelve a la pantalla (H-23).

Se corre desde el PC con la llave del servidor:
    INDUSTEC_LLAVE_SSH=... INDUSTEC_SSH_USER=... PYTHONUTF8=1 python verificar_seguridad.py
"""
import json
import sys
import time
import urllib.error
import urllib.request

import verificar_http as vh

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")


def cabeceras(sesion, ruta):
    req = urllib.request.Request(vh.BASE + ruta, headers={"User-Agent": "verificar_seguridad/1.0"})
    try:
        r = sesion.op.open(req, timeout=60)
        return r.status, {k.lower(): v for k, v in r.headers.items()}, r.read()
    except urllib.error.HTTPError as e:
        return e.code, {k.lower(): v for k, v in e.headers.items()}, e.read()


def ultima(usuario_id):
    return vh.sql("SELECT sesion_ultima, sesion_token IS NOT NULL viva FROM usuarios WHERE usuario_id = ?", [usuario_id])[0]


def main():
    claves = json.loads(vh.ssh("cat ~/respaldos/claves_prueba.json"))
    ids = claves["ids"]
    a, b = ids["tec_prueba_uio_a"], ids["tec_prueba_uio_b"]
    adm = vh.Sesion("admin_prueba"); adm.entrar(claves["claves"]["admin_prueba"])
    tec = vh.Sesion("tec_prueba_uio_a"); tec.entrar(claves["claves"]["tec_prueba_uio_a"])
    anon = vh.Sesion("nadie")

    print("\n== 1. cabeceras y archivos cerrados ==")
    st, cab, _ = cabeceras(adm, "panel.php")
    vh.anotar("T2.14.6", "panel.php con sesión lleva Content-Security-Policy", st == 200 and "default-src 'self'" in cab.get("content-security-policy", ""), cab.get("content-security-policy", "")[:60])
    vh.anotar("T2.14.6", "y Cache-Control: no-store", "no-store" in cab.get("cache-control", ""), cab.get("cache-control"))
    vh.anotar("T2.14.6", "y Permissions-Policy sin cámara para terceros", "camera=(self)" in cab.get("permissions-policy", ""), cab.get("permissions-policy", "")[:40])
    st, _, _ = cabeceras(anon, ".htaccess")
    vh.anotar("T2.14.6", "/ot/.htaccess → 403 (los dotfiles no se sirven)", st == 403, st)
    st, _, _ = cabeceras(anon, ".gitignore")
    vh.anotar("T2.14.6", "/ot/.gitignore no se sirve (403 o 404)", st in (403, 404), st)
    st, _, _ = cabeceras(anon, "nucleo/config.php")
    vh.anotar("T2.14.6", "/ot/nucleo/config.php → 403", st == 403, st)
    st, _, _ = cabeceras(anon, "purgar_cli.php")
    vh.anotar("T2.14.6", "un CLI por web → 404", st == 404, st)

    print("\n== 2. salir: GET no cierra, POST sin token no cierra, POST con token sí ==")
    st, _, c = tec.pedir("salir.php")
    st2, _, _ = tec.pedir("yo.php")
    vh.anotar("T2.14.6", "salir.php por GET muestra el botón y la sesión sigue", st == 200 and "salir" in c.lower() and st2 == 200, f"{st} · {st2}")
    st, _, _ = tec.pedir("salir.php", form={"csrf": None})
    st2, _, _ = tec.pedir("yo.php")
    vh.anotar("T2.14.6", "POST a salir.php sin csrf → 403 y la sesión sigue", st == 403 and st2 == 200, f"{st} · {st2}")
    st, _, _ = tec.pedir("salir.php", form={})
    st2, _, _ = tec.pedir("yo.php")
    vh.anotar("T2.14.6", "POST con csrf → cierra (yo.php 401)", st in (302, 200) and st2 == 401, f"{st} · {st2}")
    tec.entrar(claves["claves"]["tec_prueba_uio_a"])

    print("\n== 3. la segunda sesión no ve la clave (SEG-10) ==")
    s1 = vh.Sesion("tec_prueba_uio_b"); s1.entrar(claves["claves"]["tec_prueba_uio_b"])
    s2 = vh.Sesion("tec_prueba_uio_b")
    clave_b = claves["claves"]["tec_prueba_uio_b"]
    st, _, c = s2.pedir("login.php", form={"usuario": "tec_prueba_uio_b", "clave": clave_b})
    vh.anotar("T2.14.6", "con otra sesión abierta, la página lo dice y NO contiene la clave", st == 200 and "sesión abierta" in c and clave_b not in c, st)
    st, loc, _ = s2.pedir("login.php", form={"usuario": "tec_prueba_uio_b", "desplazar": "1"})
    st2, _, _ = s2.pedir("yo.php")
    st1, _, _ = s1.pedir("yo.php")
    vh.anotar("T2.14.6", "el segundo formulario entra solo con «desplazar» (marcador de 2 min) y la otra sesión cae", st == 302 and st2 == 200 and st1 == 401, f"{st} · nueva={st2} · vieja={st1}")
    s3 = vh.Sesion("tec_prueba_uio_b")
    st, _, c = s3.pedir("login.php", form={"usuario": "tec_prueba_uio_b", "desplazar": "1"})
    vh.anotar("T2.14.6", "«desplazar» sin marcador ni clave no entra", st == 200 and "caducó" in c, st)
    s2.csrf = ""
    try:
        st, _, cy = s2.pedir("yo.php"); s2.csrf = (json.loads(cy) or {}).get("csrf", "")
    except Exception:
        pass

    print("\n== 4. sin sesión queda rastro (SEG-20) ==")
    n0 = int(vh.sql("SELECT COUNT(*) n FROM sesiones_log WHERE evento = 'SIN_SESION'")[0]["n"])
    st, _, _ = anon.pedir("catalogos.php")
    n1 = int(vh.sql("SELECT COUNT(*) n FROM sesiones_log WHERE evento = 'SIN_SESION'")[0]["n"])
    vh.anotar("T2.14.6", "catalogos.php sin sesión → 401 y fila SIN_SESION", st == 401 and n1 >= n0 + 1, f"{st} · {n0} → {n1}")
    st, _, _ = anon.pedir("panel.php")
    n2 = int(vh.sql("SELECT COUNT(*) n FROM sesiones_log WHERE evento = 'SIN_SESION'")[0]["n"])
    vh.anotar("T2.14.6", "otra petición en el mismo minuto no suma otra fila (tope por conexión)", n2 == n1, f"{n1} → {n2}")
    fila = vh.sql("SELECT usuario, motivo, ip FROM sesiones_log WHERE evento = 'SIN_SESION' ORDER BY id DESC LIMIT 1")
    vh.anotar("T2.14.6", "la fila dice qué se pidió y desde dónde", fila and fila[0]["usuario"] == "-" and fila[0]["motivo"] in ("catalogos.php", "panel.php") and fila[0]["ip"], fila)

    print("\n== 5. el sondeo no renueva la sesión; las pantallas, una vez por minuto (SEG-05) ==")
    vh.ejecutar("UPDATE usuarios SET sesion_ultima = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE usuario_id = ?", [a])
    for _ in range(3):
        tec.pedir("novedades.php")
    d = vh.sql("SELECT TIMESTAMPDIFF(SECOND, sesion_ultima, NOW()) s FROM usuarios WHERE usuario_id = ?", [a])[0]
    vh.anotar("T2.14.6", "novedades.php tres veces no mueve sesion_ultima", int(d["s"]) >= 240, f"{d['s']} s")
    tec.pedir("mis.php")
    u1 = ultima(a)["sesion_ultima"]
    d = vh.sql("SELECT TIMESTAMPDIFF(SECOND, sesion_ultima, NOW()) s FROM usuarios WHERE usuario_id = ?", [a])[0]
    vh.anotar("T2.14.6", "una pantalla sí la renueva", int(d["s"]) < 60, f"{d['s']} s")
    tec.pedir("mis.php")
    vh.anotar("T2.14.6", "y la segunda en el mismo minuto no la vuelve a escribir", ultima(a)["sesion_ultima"] == u1, ultima(a)["sesion_ultima"])

    print("\n== 6. tope absoluto de 12 horas ==")
    vh.ejecutar("UPDATE usuarios SET sesion_desde = DATE_SUB(NOW(), INTERVAL 13 HOUR) WHERE usuario_id = ?", [b])
    st, _, _ = s2.pedir("yo.php")
    ev = vh.sql("SELECT evento, motivo FROM sesiones_log WHERE usuario_id = ? ORDER BY id DESC LIMIT 1", [b])
    vh.anotar("T2.14.6", "con 13 h de sesión, yo.php → 401 y EXPIRADO «tope diario»", st == 401 and ev and ev[0]["evento"] == "EXPIRADO" and ev[0]["motivo"] == "tope diario", f"{st} · {ev}")

    print("\n== 7. la clave actual se cuenta y al quinto fallo bloquea (SEG-11) ==")
    vh.ejecutar("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE usuario_id = ?", [a])
    f0 = int(vh.sql("SELECT COUNT(*) n FROM bitacora WHERE accion = 'CAMBIO_CLAVE' AND exito = 0 AND usuario_id = ?", [a])[0]["n"])
    st, _, _ = tec.pedir("clave.php", form={"actual": "mala", "nueva": "abcdefghijk", "repite": "abcdefghijk", "csrf": None})
    vh.anotar("T2.14.6", "clave.php sin csrf → 403", st == 403, st)
    codigos = []
    for k in range(4):
        st, _, c = tec.pedir("clave.php", form={"actual": "mala-" + str(k), "nueva": "abcdefghijk", "repite": "abcdefghijk"})
        codigos.append(st)
    it = int(vh.sql("SELECT intentos_fallidos FROM usuarios WHERE usuario_id = ?", [a])[0]["intentos_fallidos"])
    vh.anotar("T2.14.6", "cuatro claves actuales malas → 200 con error, contador en 4 y bitácora con exito = 0", codigos == [200] * 4 and it == 4 and int(vh.sql("SELECT COUNT(*) n FROM bitacora WHERE accion = 'CAMBIO_CLAVE' AND exito = 0 AND usuario_id = ?", [a])[0]["n"]) == f0 + 4, f"{codigos} · intentos={it}")
    st, cab, _ = tec.pedir("clave.php", form={"actual": "mala-5", "nueva": "abcdefghijk", "repite": "abcdefghijk"})
    loc = str(cab.get("Location") or cab.get("location") or "") if isinstance(cab, dict) else str(cab)
    bl = vh.sql("SELECT bloqueado_hasta IS NOT NULL b, sesion_token IS NULL cerrada FROM usuarios WHERE usuario_id = ?", [a])[0]
    st2, _, _ = tec.pedir("yo.php")
    vh.anotar("T2.14.6", "el quinto → la cuenta se bloquea, la sesión se cierra y manda al ingreso", st == 302 and "bloqueado=1" in loc and int(bl["b"]) == 1 and int(bl["cerrada"]) == 1 and st2 == 401, f"{st} → {loc} · {bl}")
    vh.ejecutar("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE usuario_id = ?", [a])
    st, loc = tec.entrar(claves["claves"]["tec_prueba_uio_a"])
    vh.anotar("T2.14.6", "desbloqueado por SQL, vuelve a entrar", st == 302 and "login.php" not in loc, f"{st} → {loc}")

    print("\n== 8. IP real, purga en ensayo y vuelta a la pantalla .html ==")
    ips = vh.sql("SELECT COUNT(DISTINCT ip) n FROM sesiones_log")[0]["n"]
    vh.anotar("T2.14.6", "sesiones_log guarda direcciones distintas (el CDN no las enmascara, SEG-16)", int(ips) > 1, f"{ips} IP distintas")
    salida = vh.ssh(f"cd {vh.D} && php purgar_cli.php")
    vh.anotar("T2.14.6", "purgar_cli.php sin --ejecutar solo informa", "ENSAYO" in salida and "nada se borró" in salida, salida.strip().splitlines()[-1][:90])
    vh.ejecutar("UPDATE usuarios SET sesion_desde = NOW() WHERE usuario_id = ?", [b])
    s4 = vh.Sesion("tec_prueba_uio_b")
    st, cab, _ = s4.pedir("login.php?r=cronograma.html", form={"usuario": "tec_prueba_uio_b", "clave": clave_b, "desplazar": "1"})
    loc = str(cab.get("Location") or cab.get("location") or "") if isinstance(cab, dict) else str(cab)
    vh.anotar("T2.14.6", "login.php?r=cronograma.html devuelve a la pantalla .html (H-23)", st == 302 and loc.endswith("cronograma.html"), f"{st} → {loc}")
    s5 = vh.Sesion("tec_prueba_uio_b")
    st, cab, _ = s5.pedir("login.php?r=//otro.sitio/x.php", form={"usuario": "tec_prueba_uio_b", "clave": clave_b, "desplazar": "1"})
    loc = str(cab.get("Location") or cab.get("location") or "") if isinstance(cab, dict) else str(cab)
    vh.anotar("T2.14.6", "un destino a otro dominio se ignora (open redirect)", st == 302 and loc.endswith("panel.php"), f"{st} → {loc}")

    for se in (adm, tec, s5):
        se.pedir("salir.php", form={})
    fallas = [r for r in vh.resultados if not r["ok"]]
    print(f"\n{len(vh.resultados) - len(fallas)} de {len(vh.resultados)} comprobaciones pasan; {len(fallas)} fallan")
    return 1 if fallas else 0


if __name__ == "__main__":
    sys.exit(main())
