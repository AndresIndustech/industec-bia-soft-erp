"""
T2.5.3 - Los avisos SAP que siguen abiertos, para la pantalla "mis ordenes".

POR QUE EXISTE ESTE ARCHIVO:
El tecnico no debe teclear el numero de aviso. El campo ID-ORDEN-GRUPOKFC del
formulario de hoy ES el aviso SAP -- se comprobo en submit.php, que arma el
nombre canonico como OT-{correlativo}-{local}-{idorden}-{zona} -- y hoy se
digita a mano. De ahi salen los avisos '0', '1031' y '102832q6' que rompen el
cruce. La correccion no es validar mejor el tecleo: es que el tecnico ELIJA de
sus ordenes asignadas.

Este script produce esa lista. NO decide asignaciones: solo publica los avisos
realmente abiertos, resueltos a local canonico y zona.

COBERTURA, DECLARADA (I-12):
El catalogo SAP en la base es un EXPORT, no un feed. Cubre del 2026-01-01 al
2026-08-31, asi que "abierto" significa "abierto al corte del export", no
"abierto hoy". El JSON lo dice explicitamente y la interfaz lo muestra, porque
presentarlo como si fuera de hoy seria inventar (I-7). La fuente en vivo
decidida es el buzon Titan por IMAP, todavia bloqueado por credenciales.

CRITERIO DE ABIERTO:
estatus_general IN ('ABIERTO','TRATAMIENTO'). Es la columna ESTATUS A del
export, el unico criterio de cierre del proyecto -- nunca una senal interna del
sistema de OTs (decision 5).

Uso:
    .venv/Scripts/python.exe scripts/t2_5_avisos_abiertos.py
"""

import json
import re
import sys
from datetime import date, datetime
from pathlib import Path

import mysql.connector

ENV_PATH = Path(r"D:\INDUSTECH IA\desarrollo\agentes\config\.env")
SALIDA = Path(r"D:\INDUSTECH IA\SALIDAS IA\OTS\catalogos")

ABIERTOS = ("ABIERTO", "TRATAMIENTO")


def cargar_env():
    env = {}
    for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return env


def conectar(env):
    return mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])


def clave(s):
    """Normaliza un codigo para cruzar contra locales_alias.

    centro_coste llega crudo de SAP: 'K121', 'BS17', 'G012' -- sin el sufijo EC
    del canonico. Por eso el JOIN directo contra locales da CERO coincidencias
    y hay que pasar por locales_alias, que es exactamente para lo que se
    construyo en T1.5.
    """
    return re.sub(r"[^A-Za-z0-9]", "", s or "").upper()


def iso(v):
    return v.isoformat() if isinstance(v, (date, datetime)) else v


def construir(cnx):
    cur = cnx.cursor(dictionary=True)

    cur.execute("""SELECT local_codigo, zona, cadena, nombre
                   FROM locales WHERE activo = 1""")
    locales = {l["local_codigo"]: l for l in cur.fetchall()}
    por_clave = {clave(c): c for c in locales}

    cur.execute("SELECT alias_texto, local_codigo FROM locales_alias")
    for r in cur.fetchall():
        por_clave.setdefault(clave(r["alias_texto"]), r["local_codigo"])

    # Cobertura real del catalogo, medida -- no asumida.
    cur.execute("""SELECT MIN(fecha_notificacion) desde, MAX(fecha_notificacion) hasta,
                          COUNT(*) total FROM avisos_sap""")
    cob = cur.fetchone()

    cur.execute("""SELECT aviso, fecha_notificacion, descripcion, centro_coste,
                          equipo_sap, equipo_denominacion, ubicacion_tecnica,
                          estatus_general, estatus_orden_2
                   FROM avisos_sap
                   WHERE estatus_general IN (%s, %s)
                   ORDER BY fecha_notificacion DESC, aviso DESC""", ABIERTOS)
    crudos = cur.fetchall()

    datos, sin_resolver = [], {}
    for a in crudos:
        cod = por_clave.get(clave(a["centro_coste"]))
        loc = locales.get(cod) if cod else None
        if not loc:
            sin_resolver.setdefault(a["centro_coste"], 0)
            sin_resolver[a["centro_coste"]] += 1
        datos.append({
            "aviso": str(a["aviso"]),
            "fecha_notificacion": iso(a["fecha_notificacion"]),
            "caso": (a["descripcion"] or "").strip() or None,
            "local": cod,
            "local_nombre": loc["nombre"] if loc else None,
            "zona": loc["zona"] if loc else None,
            "cadena": loc["cadena"] if loc else None,
            "centro_coste_sap": a["centro_coste"],
            "equipo_sap": a["equipo_sap"] or None,
            "equipo_denominacion": (a["equipo_denominacion"] or "").strip() or None,
            "estatus": a["estatus_general"],
            "estatus_sap": a["estatus_orden_2"] or None,
        })

    salida = {
        "generado": datetime.now().strftime("%Y-%m-%d %H:%M"),
        "criterio": "estatus_general IN ('ABIERTO','TRATAMIENTO')",
        "cobertura": {
            "desde": iso(cob["desde"]),
            "hasta": iso(cob["hasta"]),
            "avisos_en_catalogo": cob["total"],
            "advertencia": (
                "El catalogo SAP es un export, no un feed en vivo. "
                f"'Abierta en SAP' significa abierta en SAP al corte del {iso(cob['hasta'])}, "
                "no hoy. La fuente en vivo (buzon Titan por IMAP) "
                "todavia esta bloqueada por credenciales."
            ),
        },
        "sin_resolver": sin_resolver,
        "datos": datos,
    }
    SALIDA.mkdir(parents=True, exist_ok=True)
    (SALIDA / "avisos_abiertos.json").write_text(
        json.dumps(salida, ensure_ascii=False, indent=1), encoding="utf-8")

    por_zona = {}
    for d in datos:
        por_zona[d["zona"] or "(sin resolver)"] = por_zona.get(d["zona"] or "(sin resolver)", 0) + 1
    return salida, por_zona


def main():
    env = cargar_env()
    cnx = conectar(env)
    try:
        salida, por_zona = construir(cnx)
    finally:
        cnx.close()

    n = len(salida["datos"])
    print(f"abiertas en SAP      : {n}")
    for z in sorted(por_zona):
        print(f"  {z:<16}: {por_zona[z]}")
    print(f"cobertura del export : {salida['cobertura']['desde']} a {salida['cobertura']['hasta']}")
    if salida["sin_resolver"]:
        print("centros de coste SIN alias en el maestro (no se inventa la zona):")
        for c, k in sorted(salida["sin_resolver"].items()):
            print(f"  {c:<10} {k} aviso(s)")
    print(f"-> {SALIDA / 'avisos_abiertos.json'}")

    # Compuerta: si la resolucion cae por debajo del 95%, algo se rompio en el
    # maestro de alias y la pantalla del tecnico mostraria avisos sin zona.
    resueltos = sum(1 for d in salida["datos"] if d["zona"])
    if n and resueltos / n < 0.95:
        print(f"\nABORTA: solo {resueltos}/{n} avisos resuelven a zona (<95%).", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
