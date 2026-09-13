"""
t2_15_exportar_archivo.py - El catalogo historico de OT, al indice del archivo de Hostinger.

POR QUE
Andres decidio el 2026-09-12 que el archivo general de ordenes de las tres zonas
(las 7.069 del historico mas lo que va llegando) lo consulte todo el personal
desde la app, en solo lectura. El indice vive en la tabla `ot_archivo` del
servidor (migracion 009) y lo llena `archivo_indexar_cli.php`; lo que ese CLI no
puede ver es lo que esta solo en la estacion: el catalogo historico y la ruta del
PDF de cada orden en D:\\RESPALDOS. Este script lo exporta a un JSON con las
columnas que el CLI entiende y, con --empujar, lo sube y corre el indexado.

DE DONDE SALE
  --desde-xlsx (por defecto)  `SALIDAS IA/OTS/CATALOGO OTS (generado agente).xlsx`,
                              lo que publica t2_4_publicar_ots.py (una hoja por zona).
  --desde-mariadb             la tabla `ots` de la estacion, con el maestro de
                              locales, usando la misma consulta que t2_4.

QUE PRODUCE
  SALIDAS IA/OTS/archivo_ot.json  {generado, fuente, filas:[{id_industec, zona,
  local, local_nombre, cadena, aviso, modulo, dia, fecha_atencion, tecnico, ruta}]}

USO (en la estacion):
    .venv/Scripts/python.exe scripts/t2_15_exportar_archivo.py
    .venv/Scripts/python.exe scripts/t2_15_exportar_archivo.py --empujar
    .venv/Scripts/python.exe scripts/t2_15_exportar_archivo.py --desde-mariadb --empujar

--empujar usa la misma llave que t2_10_desplegar.py (INDUSTEC_LLAVE_SSH,
INDUSTEC_SSH_USER en el entorno o en config/.env) y deja el JSON en
~/respaldos/archivo_ot.json del servidor, fuera de la carpeta web; despues corre
`php archivo_indexar_cli.php --catalogo ~/respaldos/archivo_ot.json` por SSH.
NO sube ningun PDF: eso es la decision D2, pendiente de Andres.
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from datetime import date, datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import SALIDAS, leer_env  # noqa: E402

XLSX = SALIDAS / "CATALOGO OTS (generado agente).xlsx"
SALIDA_JSON = SALIDAS / "archivo_ot.json"

# El servidor de pruebas, como en t2_10_desplegar.py. Se pueden cambiar por
# entorno o por config/.env sin tocar el codigo.
SSH_HOST = "82.25.73.181"
SSH_PUERTO = "65002"
REMOTO_APP = "domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot"
REMOTO_JSON = "respaldos/archivo_ot.json"

COLUMNAS_XLSX = {
    "OT INDUSTEC": "id_industec", "ZONA": "zona", "TIPO": "modulo", "LOCAL": "local",
    "NOMBRE DEL LOCAL": "local_nombre", "CADENA": "cadena", "# OT (AVISO SAP)": "aviso",
    "FECHA DE ATENCION": "fecha_atencion", "TECNICO": "tecnico", "DIA": "dia", "ARCHIVO": "ruta",
}


def _fecha(v) -> str | None:
    if v is None or v == "":
        return None
    if isinstance(v, (datetime, date)):
        return v.strftime("%Y-%m-%d")
    s = str(v).strip()
    return s[:10] if len(s) >= 10 and s[4] == "-" else None


def _fila(d: dict) -> dict | None:
    ot = str(d.get("id_industec") or "").strip().upper()
    if not ot.startswith("OT-"):
        return None
    aviso = d.get("aviso")
    dia = d.get("dia")
    return {
        "id_industec": ot,
        "zona": str(d.get("zona") or "").strip().upper() or None,
        "local": str(d.get("local") or "").strip().upper() or None,
        "local_nombre": (str(d.get("local_nombre") or "").strip() or None),
        "cadena": (str(d.get("cadena") or "").strip() or None),
        "aviso": str(int(aviso)) if isinstance(aviso, (int, float)) and aviso else (str(aviso).strip() or None),
        "modulo": str(d.get("modulo") or "").strip().upper() or None,
        "dia": int(dia) if isinstance(dia, (int, float)) and dia else None,
        "fecha_atencion": _fecha(d.get("fecha_atencion")),
        "tecnico": (str(d.get("tecnico") or "").strip() or None),
        "ruta": (str(d.get("ruta") or "").strip() or None),
    }


def desde_xlsx() -> list[dict]:
    import openpyxl
    if not XLSX.is_file():
        sys.exit(f"No esta {XLSX}. Corre antes t2_4_publicar_ots.py, o usa --desde-mariadb.")
    wb = openpyxl.load_workbook(XLSX, read_only=True, data_only=True)
    filas: dict[str, dict] = {}
    for ws in wb.worksheets:
        encabezado = None
        for fila in ws.iter_rows(values_only=True):
            if encabezado is None:
                encabezado = [str(c).strip() if c is not None else "" for c in fila]
                if "OT INDUSTEC" not in encabezado:
                    break                     # la hoja de resumen no tiene ordenes
                continue
            d = {COLUMNAS_XLSX[h]: v for h, v in zip(encabezado, fila) if h in COLUMNAS_XLSX}
            f = _fila(d)
            if f:
                filas[f["id_industec"]] = f   # la misma OT en dos hojas: vale la ultima
    return list(filas.values())


def desde_mariadb() -> list[dict]:
    import mysql.connector
    import t2_4_publicar_ots as pub
    env = pub.cargar_env()
    cnx = mysql.connector.connect(host=env.get("DB_HOST", "127.0.0.1"), port=int(env.get("DB_PORT", "3306")),
                                  user=env["DB_USER"], password=env["DB_PASSWORD"], database=env["DB_NAME"])
    try:
        crudas = pub.leer_ots(cnx)
    finally:
        cnx.close()
    filas = []
    for r in crudas:
        f = _fila({"id_industec": r.get("id_industec"), "zona": r.get("zona"), "modulo": r.get("modulo"),
                   "local": r.get("local_codigo"), "local_nombre": r.get("_local_nombre"), "cadena": r.get("_cadena"),
                   "aviso": r.get("aviso"), "fecha_atencion": r.get("fecha_atencion"), "tecnico": r.get("tecnico_nombre"),
                   "dia": r.get("dia_intervencion"), "ruta": r.get("ruta_pdf")})
        if f:
            filas.append(f)
    return filas


def empujar(json_local: Path) -> int:
    env = leer_env()
    llave = os.environ.get("INDUSTEC_LLAVE_SSH") or env.get("INDUSTEC_LLAVE_SSH") or str(Path.home() / ".ssh" / "industec_hostinger_pc")
    usuario = os.environ.get("INDUSTEC_SSH_USER") or env.get("INDUSTEC_SSH_USER") or "u671729428"
    host = os.environ.get("INDUSTEC_SSH_HOST") or env.get("INDUSTEC_SSH_HOST") or SSH_HOST
    remoto_app = os.environ.get("INDUSTEC_REMOTO_APP") or env.get("INDUSTEC_REMOTO_APP") or REMOTO_APP
    base = ["-i", llave, "-o", "IdentitiesOnly=yes", "-o", "BatchMode=yes", "-o", "ConnectTimeout=20",
            "-o", "StrictHostKeyChecking=accept-new"]
    destino = f"{usuario}@{host}"
    print(f"subiendo {json_local.name} a {destino}:{REMOTO_JSON} ...")
    r = subprocess.run(["scp", "-P", SSH_PUERTO, *base, str(json_local), f"{destino}:{REMOTO_JSON}"],
                       capture_output=True, text=True, timeout=600)
    if r.returncode != 0:
        print(r.stderr.strip() or "scp fallo", file=sys.stderr)
        return r.returncode
    print("indexando en el servidor ...")
    r = subprocess.run(["ssh", "-p", SSH_PUERTO, *base, destino,
                        f"cd {remoto_app} && php archivo_indexar_cli.php --catalogo ~/{REMOTO_JSON}"],
                       capture_output=True, text=True, timeout=1800)
    print((r.stdout or "").strip())
    if r.returncode != 0:
        print(r.stderr.strip() or f"el indexado termino con codigo {r.returncode}", file=sys.stderr)
    return r.returncode


def main() -> int:
    ap = argparse.ArgumentParser(description="Exporta el catalogo historico de OT al indice del archivo de Hostinger.")
    ap.add_argument("--desde-mariadb", action="store_true", help="leer la tabla ots de la estacion en vez del xlsx")
    ap.add_argument("--empujar", action="store_true", help="subir el JSON a ~/respaldos y correr archivo_indexar_cli.php")
    ap.add_argument("--salida", default=str(SALIDA_JSON), help="ruta del JSON (por defecto SALIDAS IA/OTS/archivo_ot.json)")
    a = ap.parse_args()

    filas = desde_mariadb() if a.desde_mariadb else desde_xlsx()
    if not filas:
        sys.exit("No salio ninguna orden: revisa la fuente.")
    salida = Path(a.salida)
    salida.parent.mkdir(parents=True, exist_ok=True)
    salida.write_text(json.dumps({
        "generado": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "fuente": "mariadb" if a.desde_mariadb else XLSX.name,
        "filas": filas,
    }, ensure_ascii=False, indent=0), encoding="utf-8")
    zonas: dict[str, int] = {}
    for f in filas:
        zonas[f["zona"] or "?"] = zonas.get(f["zona"] or "?", 0) + 1
    print(f"{len(filas)} ordenes en {salida} · " + " · ".join(f"{z} {n}" for z, n in sorted(zonas.items())))
    if a.empujar:
        return empujar(salida)
    return 0


if __name__ == "__main__":
    sys.exit(main())
