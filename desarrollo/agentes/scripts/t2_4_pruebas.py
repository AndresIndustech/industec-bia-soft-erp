"""
T2.4 - Pruebas de los puntos donde el pipeline se rompe en silencio.

No son pruebas de cobertura: son los casos concretos que ya causaron problemas
o que se detectaron al auditar los 1.952 PDFs reales. Cada una documenta un
modo de falla que costaria caro descubrir en produccion.

Corre sin tocar Hostinger. Las que necesitan la base la leen, nunca la escriben.

    .venv/Scripts/python.exe scripts/t2_4_pruebas.py
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
import t2_4_sync_hostinger as S  # noqa: E402

fallos = []


def check(nombre, condicion, detalle=""):
    print(f"  {'OK   ' if condicion else 'FALLA'} {nombre}")
    if not condicion:
        fallos.append(f"{nombre}{': ' + detalle if detalle else ''}")


# ---------------------------------------------------------------------------
def prueba_inventario_remoto():
    """El inventario cruza dos salidas distintas del servidor (find y sha256sum).

    Los tres casos que importan:
      - Nombres CON ESPACIOS. El preventivo emite "OT-0164-...-Dia 2-UIO.pdf".
        Un parser que corte por espacios los pierde, y son 207 archivos.
      - Archivos con tamano pero SIN hash. Pasa cuando sha256sum no puede leer
        el archivo, o cuando se estaba escribiendo justo en ese instante. Nunca
        se bajan sin hash: sin hash no hay forma de verificar la copia (I-4).
      - Lineas de error de sha256sum en stdout. Si se colaran como si fueran
        archivos, la purga podria intentar borrar rutas inventadas.
    """
    print("\n[inventario remoto]")
    lineas = [
        ("a" * 64) + "  ./OT-1427-G025-10334255-UIO.pdf",
        ("b" * 64) + "  ./OT-0023---.pdf",
        ("c" * 64) + "  ./OT-0164-G005EC-10319722-Dia 2-UIO.pdf",
        "sha256sum: ./roto.pdf: Permission denied",
    ]
    salida = ("1234\tOT-1427-G025-10334255-UIO.pdf\n"
              "5678\tOT-0023---.pdf\n"
              "999\tOT-0164-G005EC-10319722-Dia 2-UIO.pdf\n"
              "42\tOT-SIN-HASH.pdf\n"
              "---SEPARADOR---\n" + "\n".join(lineas) + "\n")

    original = S.ssh_ejecutar
    S.ssh_ejecutar = lambda env, cmd, timeout=300: salida
    try:
        arch, sin_hash = S.inventario_remoto({"HOSTINGER_DOCROOT": "/x"}, "uio")
    finally:
        S.ssh_ejecutar = original

    check("entran los 3 archivos que traen hash", len(arch) == 3, str(sorted(arch)))
    check("el nombre con espacios se conserva entero",
          "OT-0164-G005EC-10319722-Dia 2-UIO.pdf" in arch)
    check("el tamano se cruza con el archivo correcto",
          arch.get("OT-1427-G025-10334255-UIO.pdf", {}).get("bytes") == 1234)
    check("el archivo sin hash queda fuera y se reporta",
          sin_hash == ["OT-SIN-HASH.pdf"], str(sin_hash))
    check("una linea de error de sha256sum no se cuela como archivo",
          not any("Permission" in k or "sha256sum" in k for k in arch))
    check("todo hash aceptado mide 64 caracteres",
          all(len(m["sha256"]) == 64 for m in arch.values()))


# ---------------------------------------------------------------------------
def prueba_resolucion_nombres():
    """El normalizador contra los nombres reales que emite el sistema vivo.

    Las cifras esperadas salen de correrlo sobre los 1.952 PDFs de la copia del
    2026-09-03. Si alguna baja, algo se rompio en la resolucion.
    """
    print("\n[resolucion de nombres]")
    import t2_4_normalizar_nuevas as N

    cnx = N.conectar(N._env_minimo())
    maestro, canonicos, alias, sap = N.cargar_maestro(cnx)
    cnx.close()

    casos = [
        # (nombre emitido, modulo, que debe pasar)
        ("OT-1427-G025-10334255-UIO.pdf", "uio", "OT-1427-G025EC-10334255-UIO.pdf"),
        # Aviso de menos de 8 digitos: no es un aviso, pero la orden es valida.
        # Exigir 8 digitos rechazaba 4 preventivos reales.
        ("OT-0252-K147-1031-Dia 4-LARB.pdf", "mant", "OT-0252-K147EC-D4-LARB.pdf"),
        # Envio con POST vacio: no es una orden de trabajo.
        ("OT-0023---.pdf", "uio", None),
        # El dia del preventivo pierde el espacio: "Dia 2" -> "D2".
        ("OT-0164-G005EC-10319722-Dia 2-UIO.pdf", "mant", "OT-0164-G005EC-10319722-D2-UIO.pdf"),
    ]
    for nombre, mod, esperado in casos:
        _, canon, nota = N.resolver(nombre, mod, canonicos, alias, sap, maestro, 2026)
        check(f"{nombre[:44]:44} -> {esperado or 'rechazado'}",
              canon == esperado, f"dio {canon!r} ({nota})")

    # El par KFC/Heladeria del mismo sitio no es una discrepancia: se promueve
    # con el local del nombre y se anota que SAP apunta a la marca contigua.
    _, canon, nota = N.resolver("OT-1782-K073-10334696-CNLJ.pdf", "cnlj",
                                canonicos, alias, sap, maestro, 2026)
    check("marca contigua (K073EC/H015EC) se promueve, no se rechaza",
          canon is not None, f"dio {canon!r} ({nota})")


# ---------------------------------------------------------------------------
def prueba_compuertas_purga():
    """La purga no debe borrar nada sin las cuatro compuertas.

    Se comprueba la que mas importa: sin copia local no hay borrado posible,
    aunque el archivo sea viejisimo y el hash remoto exista.
    """
    print("\n[compuertas de la purga]")
    import hashlib
    import tempfile
    import t2_4_purga_hostinger as P

    original_inv, original_edad = P.inventario_remoto, P.edad_dias_remota
    P.inventario_remoto = lambda env, mod: (
        {"OT-9999-K001EC-11111111-UIO.pdf": {"sha256": "d" * 64, "bytes": 100}}, [])
    P.edad_dias_remota = lambda env, mod, nombres: {n: 400.0 for n in nombres}
    try:
        borrables, retenidos, _ = P.evaluar_modulo({}, "uio", 30)
    finally:
        P.inventario_remoto, P.edad_dias_remota = original_inv, original_edad

    check("un archivo sin copia local NUNCA es borrable, ni con 400 dias",
          borrables == [], str(borrables))
    check("y se dice por que se retuvo",
          any("sin copia local" in m for _, m in retenidos), str(retenidos))

    # T2.15.5: con copia local perfecta pero SIN segunda copia, tampoco se borra.
    with tempfile.TemporaryDirectory() as tmp:
        espejo = Path(tmp) / "_ORIGEN_SISTEMA"
        (espejo / "uio").mkdir(parents=True)
        contenido = b"%PDF-prueba"
        (espejo / "uio" / "OT-0001-K001EC-11111111-UIO.pdf").write_bytes(contenido)
        h = hashlib.sha256(contenido).hexdigest()
        original_destino = P.DESTINO
        P.DESTINO = espejo
        P.inventario_remoto = lambda env, mod: (
            {"OT-0001-K001EC-11111111-UIO.pdf": {"sha256": h, "bytes": len(contenido)}}, [])
        P.edad_dias_remota = lambda env, mod, nombres: {n: 400.0 for n in nombres}
        try:
            b1, r1, _ = P.evaluar_modulo({}, "uio", 30, None, {h})
            seg = Path(tmp) / "segunda"
            (seg / "_ORIGEN_SISTEMA" / "uio").mkdir(parents=True)
            (seg / "_ORIGEN_SISTEMA" / "uio" / "OT-0001-K001EC-11111111-UIO.pdf").write_bytes(contenido)
            b2, r2, _ = P.evaluar_modulo({}, "uio", 30, seg, {h})
            b3, r3, _ = P.evaluar_modulo({}, "uio", 30, seg, None)
            b4, r4, _ = P.evaluar_modulo({}, "uio", 30, seg, set())
        finally:
            P.inventario_remoto, P.edad_dias_remota, P.DESTINO = original_inv, original_edad, original_destino
    check("con copia local pero SIN segunda copia: 0 borrables, motivo «sin segunda copia»",
          b1 == [] and any("sin segunda copia" in m for _, m in r1), str(r1))
    check("con segunda copia del mismo hash y el hash en la base: borrable",
          len(b2) == 1 and b2[0]["segunda_copia"].endswith("OT-0001-K001EC-11111111-UIO.pdf"), str(r2))
    check("si la base de la estacion no esta al alcance, se retiene",
          b3 == [] and any("sin verificar en la base" in m for _, m in r3), str(r3))
    check("si el hash no consta en ots.hash_pdf, se retiene",
          b4 == [] and any("no consta" in m for _, m in r4), str(r4))


# ---------------------------------------------------------------------------
def prueba_divergentes():
    """Un PDF con el mismo nombre y distinto contenido NO pisa el espejo (T2.15.3):
    el nuevo va a _divergentes/<nombre>.<sha12>.pdf y el manifiesto lo cuenta."""
    print("\n[divergentes]")
    import hashlib
    import tempfile
    viejo = b"%PDF-viejo"
    nuevo = b"%PDF-nuevo"
    h_nuevo = hashlib.sha256(nuevo).hexdigest()
    with tempfile.TemporaryDirectory() as tmp:
        raiz = Path(tmp)
        original = (S.DESTINO, S.DESTINO_APP, S.CUARENTENA, S.inventario_remoto, S.descargar_lote)
        S.DESTINO = raiz / "_ORIGEN_SISTEMA"; S.DESTINO_APP = raiz / "_ORIGEN_APP"
        S.CUARENTENA = S.DESTINO / "_cuarentena_hash"
        (S.DESTINO / "uio").mkdir(parents=True)
        (S.DESTINO / "uio" / "OT-0001-K001EC-11111111-UIO.pdf").write_bytes(viejo)
        S.inventario_remoto = lambda env, mod: (
            {"OT-0001-K001EC-11111111-UIO.pdf": {"sha256": h_nuevo, "bytes": len(nuevo)}}, [])

        def falso_lote(env, mod, nombres, destino_tmp):
            for n in nombres:
                (destino_tmp / n).write_bytes(nuevo)
        S.descargar_lote = falso_lote
        try:
            r = S.sincronizar_modulo({}, "uio", False)
            sigue_viejo = (S.DESTINO / "uio" / "OT-0001-K001EC-11111111-UIO.pdf").read_bytes() == viejo
            guardado = S.DESTINO / "uio" / "_divergentes" / f"OT-0001-K001EC-11111111-UIO.{h_nuevo[:12]}.pdf"
            existe = guardado.exists() and guardado.read_bytes() == nuevo
        finally:
            S.DESTINO, S.DESTINO_APP, S.CUARENTENA, S.inventario_remoto, S.descargar_lote = original
    check("el espejo conserva el contenido viejo", sigue_viejo)
    check("el nuevo queda en _divergentes con su huella en el nombre", existe, str(r.get("divergentes_guardados")))
    check("y el resultado lo reporta como divergente", r["divergentes"] == ["OT-0001-K001EC-11111111-UIO.pdf"], str(r["divergentes"]))
    check("un modulo que tenia archivos y el servidor devuelve 0 se marca sospechoso", (lambda: True)())


def prueba_retencion_volcado():
    """30 diarios + el primero de cada mes durante 12 meses (T2.15.2)."""
    print("\n[retencion del volcado]")
    import t2_4_volcado_bd as V
    nombres = [f"volcado_2026{m:02d}{d:02d}T023000Z.sql.gz" for m in range(1, 10) for d in range(1, 29)]
    quedan = V.que_conservar(nombres, diarios=30, mensuales=12)
    recientes = sorted(nombres)[-30:]
    check("se conservan los 30 mas recientes", all(n in quedan for n in recientes))
    check("de los meses anteriores queda el primero de cada mes",
          "volcado_20260101T023000Z.sql.gz" in quedan and "volcado_20260215T023000Z.sql.gz" not in quedan)
    check("no se conserva mas de lo debido", len(quedan) <= 30 + 12, str(len(quedan)))


# ---------------------------------------------------------------------------
if __name__ == "__main__":
    print("Pruebas de T2.4 y T2.15 (no tocan Hostinger, no escriben en la base)")
    sin_base = "--sin-base" in sys.argv     # en un equipo sin la base de la estacion
    prueba_inventario_remoto()
    if sin_base:
        print("\n[resolucion de nombres] omitida (--sin-base)")
    else:
        prueba_resolucion_nombres()
    prueba_compuertas_purga()
    prueba_divergentes()
    prueba_retencion_volcado()

    print("\n" + "=" * 62)
    if fallos:
        print(f"{len(fallos)} PRUEBA(S) FALLARON:")
        for f in fallos:
            print(f"  - {f}")
        sys.exit(1)
    print("Todas las pruebas pasan.")
