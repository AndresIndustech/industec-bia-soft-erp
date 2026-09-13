"""
T2.14.7 / T2.16 - Siembra los correlativos de la app con los contadores del sistema viejo.

Al corte, la primera orden del sistema nuevo tiene que continuar la numeracion
del viejo, no volver a empezar: KFC recibe `OT-2467-...` despues de `OT-2466-...`.
La app numera con la tabla `correlativos` (serie `MODULO:ZONA`, p. ej.
`CORRECTIVO:UIO`); este script le carga el ultimo numero de cada serie.

DE DONDE SALE CADA NUMERO (se toma el MAYOR de las tres fuentes, mas un margen):
  1. el contador del sistema viejo, leido DOS VECES con 60 s de diferencia: si
     cambio entre lecturas es que todavia se estan emitiendo ordenes en el
     viejo, y no es momento de sembrar (se aborta);
  2. el mayor correlativo en la base de la estacion (`ots.correlativo` por
     modulo y zona), por si el contador se reinicio alguna vez;
  3. el mayor numero que ya hubiera en `correlativos` de la app por debajo de
     la serie de pruebas (9000): nunca se baja un contador.

SOLO LECTURA sobre produccion (`hostinger_ssh` lo garantiza); ESCRIBE en la
base de la app por SSH (`sql_remoto`), solo con --ejecutar, y aborta si una
serie ya esta sembrada por encima de la de pruebas (correr dos veces no
duplica ni pisa). Deja acta en `SALIDAS IA/OTS/correlativos_sembrados_<sello>.json`.

USO:
    .venv/Scripts/python.exe scripts/t2_14_sembrar_correlativos.py              # ensayo
    .venv/Scripts/python.exe scripts/t2_14_sembrar_correlativos.py --ejecutar   # siembra
"""
from __future__ import annotations

import argparse
import json
import shlex
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import SALIDAS, leer_env, sello_utc  # noqa: E402
import hostinger_ssh as H  # noqa: E402

DOCROOT_VIEJO = f"domains/{H.SITIO_PRODUCCION}.hostingersite.com/public_html/ot/produccion"
MARGEN = 5
SERIE_PRUEBA = 9000

# serie de la app -> archivos de contador del sistema viejo (se toma el mayor)
CONTADORES = {
    "CORRECTIVO:UIO":  ["ot_normal_v3/uio/contadores/counter_UIO.txt"],
    "CORRECTIVO:LARB": ["ot_normal_v3/larb/contadores/counter_LARB.txt"],
    "CORRECTIVO:CNLJ": ["ot_normal_v3/cnlj/contadores/counter_CNLJ.txt"],
    "PREVENTIVO:UIO":  ["ot_mantenimiento/contadores/counter_UIO.txt"],
    "PREVENTIVO:LARB": ["ot_mantenimiento/contadores/counter_LARB.txt"],
    "PREVENTIVO:CNLJ": ["ot_mantenimiento/contadores/counter_CNLJ.txt"],
    "CORRECTIVO:OTRA": ["ot_normal_otros/contadores/counter.txt"],
    "PREVENTIVO:OTRA": ["ot_normal_otros/contadores/counter.txt"],
}


def leer_contadores(env: dict) -> dict[str, int | None]:
    """Todos los contadores en una sola conexion, de solo lectura."""
    rutas = sorted({r for lista in CONTADORES.values() for r in lista})
    guion = "; ".join(f"printf '%s\\t' {shlex.quote(r)}; cat {shlex.quote(DOCROOT_VIEJO + '/' + r)} 2>/dev/null | tr -d '\\r\\n '; echo" for r in rutas)
    salida = H.ssh(guion, timeout=60, env=env)
    valores: dict[str, int | None] = {}
    for linea in salida.splitlines():
        if "\t" not in linea:
            continue
        r, v = linea.split("\t", 1)
        valores[r] = int(v) if v.strip().isdigit() else None
    return {serie: max((valores.get(r) or 0) for r in lista) or None for serie, lista in CONTADORES.items()}


def maximos_estacion(env: dict) -> dict[str, int]:
    """El mayor correlativo por modulo y zona en la base de la estacion; {} si no esta."""
    try:
        import mysql.connector
        cnx = mysql.connector.connect(host=env["DB_HOST"], port=int(env.get("DB_PORT", "3306")),
                                      user=env["DB_USER"], password=env["DB_PASSWORD"], database=env["DB_NAME"],
                                      connection_timeout=10)
        cur = cnx.cursor()
        cur.execute("SELECT modulo, zona, MAX(correlativo) FROM ots WHERE en_cuarentena = 0 GROUP BY modulo, zona")
        out = {f"{m}:{z}": int(n or 0) for m, z, n in cur.fetchall()}
        cur.close(); cnx.close()
        return out
    except Exception as e:
        print(f"  (base de la estacion no disponible: {type(e).__name__}; se siembra sin esa fuente)")
        return {}


def actuales_app(env: dict) -> dict[str, int]:
    filas = H.sql_remoto("SELECT serie, ultimo FROM correlativos", env=env)
    return {f[0]: int(f[1]) for f in filas if len(f) >= 2 and f[1].isdigit()}


def main() -> int:
    ap = argparse.ArgumentParser(description="Siembra `correlativos` de la app con los contadores del sistema viejo")
    ap.add_argument("--ejecutar", action="store_true", help="escribe en la base de la app (por defecto, ensayo)")
    ap.add_argument("--sin-espera", action="store_true", help="no espera 60 s entre las dos lecturas (solo pruebas)")
    ap.add_argument("--margen", type=int, default=MARGEN)
    a = ap.parse_args()
    env = leer_env()
    inicio = datetime.now(timezone.utc)
    print(f"Siembra de correlativos · {'EJECUCION' if a.ejecutar else 'ENSAYO'} · {H.destino(env)}")

    print("\n1. contadores del sistema viejo (primera lectura)")
    c1 = leer_contadores(env)
    if not a.sin_espera:
        print("   esperando 60 s para la segunda lectura...")
        time.sleep(60)
    c2 = leer_contadores(env)
    movidos = [s for s in CONTADORES if c1.get(s) != c2.get(s)]
    for s in CONTADORES:
        print(f"   {s:<17} {c2.get(s) if c2.get(s) is not None else '(sin contador)'}")
    if movidos:
        print(f"\nABORTADO: los contadores de {', '.join(movidos)} cambiaron entre lecturas: el sistema viejo sigue emitiendo. "
              "La siembra se hace con el viejo detenido (T2.16, paso 1).")
        return 3

    print("\n2. maximos en la base de la estacion")
    est = maximos_estacion(env)
    for s in CONTADORES:
        if s in est:
            print(f"   {s:<17} {est[s]}")

    print("\n3. lo que ya tiene la app")
    app = actuales_app(env)
    for s, v in sorted(app.items()):
        print(f"   {s:<17} {v}{'  (serie de pruebas)' if v >= SERIE_PRUEBA else ''}")

    print("\n4. la siembra")
    plan = []
    for serie in CONTADORES:
        fuentes = {"contador": c2.get(serie) or 0, "estacion": est.get(serie, 0),
                   "app": app.get(serie, 0) if app.get(serie, 0) < SERIE_PRUEBA else 0}
        base = max(fuentes.values())
        if base == 0:
            print(f"   {serie:<17} sin ninguna fuente: no se siembra")
            continue
        nuevo = base + a.margen
        ya = app.get(serie)
        if ya is not None and SERIE_PRUEBA > ya >= nuevo:
            print(f"   {serie:<17} ya sembrada en {ya} (>= {nuevo}): no se toca")
            continue
        plan.append({"serie": serie, "ultimo": nuevo, "fuentes": fuentes, "antes": ya})
        print(f"   {serie:<17} {ya if ya is not None else '-':>6} -> {nuevo}   (contador {fuentes['contador']}, estacion {fuentes['estacion']}, margen {a.margen})")

    acta = {"cuando_utc": inicio.isoformat(timespec="seconds"), "ejecutado": a.ejecutar, "margen": a.margen,
            "contadores": c2, "estacion": est, "app_antes": app, "plan": plan}
    SALIDAS.mkdir(parents=True, exist_ok=True)
    ruta = SALIDAS / f"correlativos_sembrados_{sello_utc(inicio)}.json"

    if a.ejecutar and plan:
        sql = "\n".join(
            "INSERT INTO correlativos (serie, ultimo, nota) VALUES ('{s}', {n}, 'sembrado del sistema viejo el {d}') "
            "ON DUPLICATE KEY UPDATE ultimo = GREATEST(ultimo, VALUES(ultimo)), nota = VALUES(nota);".format(
                s=p["serie"], n=p["ultimo"], d=inicio.strftime("%Y-%m-%d"))
            for p in plan)
        H.sql_remoto(sql, env=env)
        acta["app_despues"] = actuales_app(env)
        print("\nSEMBRADO. Lo que quedo en la app:")
        for s, v in sorted(acta["app_despues"].items()):
            print(f"   {s:<17} {v}")
    elif a.ejecutar:
        print("\nNada que sembrar.")
    else:
        print("\nENSAYO: no se escribio nada. Con --ejecutar se siembra lo de arriba.")
    ruta.write_text(json.dumps(acta, indent=1, ensure_ascii=False), encoding="utf-8")
    print(f"acta: {ruta}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
