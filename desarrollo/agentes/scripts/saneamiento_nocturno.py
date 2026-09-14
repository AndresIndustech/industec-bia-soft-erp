"""
T2.15.4 - Saneamiento nocturno: el orquestador que corre cada noche en la estacion.

Encadena, en este orden y abortando al primer fallo:

  1. sync        t2_4_sync_hostinger.py         espejo del sistema viejo y de la app (verificado por hash)
  2. volcado     t2_4_volcado_bd.py             volcado de la base de la app, verificado por hash
  3. normalizar  t2_4_normalizar_nuevas.py --ejecutar   del espejo crudo al arbol canonico
  4. ingesta     t1_7_ingesta.py                el arbol canonico a la base de la estacion
  5. informes    t2_11_informes_ot.py --empujar los informes de OT del buzon, al sitio
  6. archivo     t2_15_exportar_archivo.py --empujar  el catalogo historico al indice del archivo (Hostinger)
  7. pdfs        t2_19_subir_pdfs.py --ejecutar  los PDF que faltan en el servidor, verificados por hash

POR QUE UN SOLO ORQUESTADOR: hasta hoy cada paso era una Tarea programada
distinta o se corria a mano, y un paso que fallaba en silencio dejaba a los de
atras trabajando sobre datos viejos. Aqui hay UN candado de instancia unica, UN
registro (`logs/saneamiento-<fecha>.log`), UNA fila en la `bitacora` de la
estacion y UN semaforo (`SALIDAS IA/OTS/estado_nocturno.json`) que la consola
local lee a la manana siguiente. Si un paso falla, los siguientes NO corren:
una ingesta sobre un espejo a medias mete en la base ordenes que no estan.

TAREA PROGRAMADA: `saneamiento_nocturno.bat` la lanza; ver SANEAMIENTO.md para
el `schtasks` (corre aunque nadie haya iniciado sesion, reintenta a los 30 min).

USO:
    .venv/Scripts/python.exe scripts/saneamiento_nocturno.py            # todo
    .venv/Scripts/python.exe scripts/saneamiento_nocturno.py --solo sync volcado
    .venv/Scripts/python.exe scripts/saneamiento_nocturno.py --ensayo   # muestra que correria
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import BASE, LOGS, PYTHON, SALIDAS, abrir_log, escribir_json_atomico, leer_env  # noqa: E402

SCRIPTS = BASE / "scripts"
CANDADO = LOGS / "saneamiento.lock"
ESTADO = SALIDAS / "estado_nocturno.json"

PASOS = [
    ("sync",       ["t2_4_sync_hostinger.py"],                    "espejo verificado del sistema viejo y de la app"),
    ("volcado",    ["t2_4_volcado_bd.py"],                        "volcado de la base de la app"),
    ("normalizar", ["t2_4_normalizar_nuevas.py", "--ejecutar"],   "del espejo crudo al arbol canonico"),
    ("ingesta",    ["t1_7_ingesta.py"],                           "arbol canonico -> base de la estacion"),
    ("informes",   ["t2_11_informes_ot.py", "--empujar"],         "informes de OT del buzon -> sitio"),
    ("archivo",    ["t2_15_exportar_archivo.py", "--desde-mariadb", "--empujar"], "catalogo historico -> indice del archivo"),
    # T2.19 (2026-09-14, revoca D2): los PDF que faltan en el servidor, verificados
    # por hash. Va ULTIMO a proposito: un lote que falle no debe frenar el catalogo,
    # y el propio t2_19 reindexa al terminar (archivo_indexar_cli.php --solo-pdf).
    ("pdfs",       ["t2_19_subir_pdfs.py", "--ejecutar"],          "PDF de orden que faltan en el servidor"),
]


def tomar_candado() -> bool:
    """Un archivo con el PID y la hora. Si existe y su proceso sigue vivo, no se
    arranca otra corrida; si quedo de una corrida muerta (mas de 6 h), se pisa."""
    LOGS.mkdir(parents=True, exist_ok=True)
    if CANDADO.exists():
        try:
            info = json.loads(CANDADO.read_text(encoding="utf-8"))
            edad = time.time() - float(info.get("desde", 0))
            if edad < 6 * 3600:
                print(f"Ya hay una corrida en marcha (pid {info.get('pid')}, hace {edad / 60:.0f} min): no se arranca otra.")
                return False
            print(f"Candado viejo ({edad / 3600:.1f} h): se toma por abandonado.")
        except Exception:
            pass
    CANDADO.write_text(json.dumps({"pid": os.getpid(), "desde": time.time()}), encoding="utf-8")
    return True


def soltar_candado() -> None:
    try:
        CANDADO.unlink()
    except OSError:
        pass


def anotar_bitacora(env: dict, ok: bool, detalle: str) -> None:
    """Una fila en la bitacora de la estacion. Si la base no esta, se dice y se sigue."""
    try:
        import mysql.connector
        cnx = mysql.connector.connect(host=env["DB_HOST"], port=int(env.get("DB_PORT", "3306")),
                                      user=env["DB_USER"], password=env["DB_PASSWORD"], database=env["DB_NAME"],
                                      connection_timeout=10)
        cur = cnx.cursor()
        cur.execute("INSERT INTO bitacora (agente, tarea, accion, detalle, nivel) VALUES (%s, %s, %s, %s, %s)",
                    ("saneamiento", "T2.15", "SANEAMIENTO_NOCTURNO", detalle[:60000], "INFO" if ok else "ERROR"))
        cnx.commit(); cur.close(); cnx.close()
    except Exception as e:
        print(f"  (bitacora de la estacion no disponible: {type(e).__name__})")


def correr_paso(nombre: str, args: list[str], ensayo: bool) -> dict:
    cmd = [str(PYTHON), str(SCRIPTS / args[0]), *args[1:]]
    print(f"\n[{nombre}] {' '.join(args)}", flush=True)
    if ensayo:
        return {"paso": nombre, "ok": True, "ensayo": True, "segundos": 0, "codigo": None}
    t0 = time.time()
    r = subprocess.run(cmd, cwd=str(BASE), capture_output=True, text=True, timeout=4 * 3600)
    salida = (r.stdout or "") + (("\n" + r.stderr) if r.stderr else "")
    for linea in salida.rstrip().splitlines()[-25:]:
        print("   " + linea)
    return {"paso": nombre, "ok": r.returncode == 0, "codigo": r.returncode,
            "segundos": round(time.time() - t0), "cola": salida.rstrip().splitlines()[-3:]}


def main() -> int:
    ap = argparse.ArgumentParser(description="Saneamiento nocturno de la estacion")
    ap.add_argument("--solo", nargs="+", choices=[p[0] for p in PASOS], help="correr solo estos pasos")
    ap.add_argument("--ensayo", action="store_true", help="muestra que correria, sin correr nada")
    ap.add_argument("--log", action="store_true", help="escribe en logs/saneamiento-<fecha>.log")
    a = ap.parse_args()
    if a.log:
        abrir_log("saneamiento")
    if not tomar_candado():
        return 2
    inicio = datetime.now(timezone.utc)
    env = leer_env()
    print(f"Saneamiento nocturno · {inicio.astimezone().strftime('%Y-%m-%d %H:%M')} · {'ENSAYO' if a.ensayo else 'REAL'}")
    resultados, ok = [], True
    try:
        for nombre, args, desc in PASOS:
            if a.solo and nombre not in a.solo:
                continue
            r = correr_paso(nombre, args, a.ensayo)
            r["que"] = desc
            resultados.append(r)
            if not r["ok"]:
                ok = False
                print(f"\nABORTADO en «{nombre}» (codigo {r['codigo']}): los pasos siguientes no corren.")
                break
    finally:
        soltar_candado()
    fin = datetime.now(timezone.utc)
    estado = {
        "ok": ok, "ensayo": a.ensayo,
        "inicio_utc": inicio.isoformat(timespec="seconds"), "fin_utc": fin.isoformat(timespec="seconds"),
        "duracion_min": round((fin - inicio).total_seconds() / 60, 1),
        "pasos": resultados,
    }
    escribir_json_atomico(ESTADO, json.dumps(estado, indent=1, ensure_ascii=False))
    resumen = " · ".join(f"{r['paso']}:{'ok' if r['ok'] else 'FALLO'}({r['segundos']}s)" for r in resultados)
    print(f"\n{'OK' if ok else 'FALLO'} · {estado['duracion_min']} min · {resumen}")
    print(f"semaforo: {ESTADO}")
    if not a.ensayo:
        anotar_bitacora(env, ok, json.dumps(estado, ensure_ascii=False))
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
