"""
Importa los avisos SAP desde KPI'S INDUSTEC.xlsx (hoja FILTRO) a avisos_sap.
Se adelanta antes de T1.6 para maximizar la resolucion de Nivel 2 (typos de
aviso corregidos por distancia de edicion) al sanear los nombres de archivo.
"""
import datetime
from pathlib import Path
import openpyxl
import mysql.connector

ENV_PATH = Path(r"D:\INDUSTECH IA\agentes\config\.env")
env = {}
for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    k, v = line.split("=", 1)
    env[k.strip()] = v.strip()

XLSX_PATH = Path("D:/RESPALDOS/_ORIGEN_DRIVE") / "KPI\u00b4S - INDUSTEC" / "KPI\u00b4S INDUSTEC.xlsx"


def limpio(v):
    if v is None:
        return None
    s = str(v).strip()
    if s in ("", "None", "none", "NULL"):
        return None
    return s


def a_fecha(v):
    if v is None:
        return None
    if isinstance(v, (datetime.date, datetime.datetime)):
        return v.date() if isinstance(v, datetime.datetime) else v
    s = limpio(v)
    return None if s is None else None  # cadenas de fecha no numericas: se descartan, raras en este export


def main():
    wb = openpyxl.load_workbook(XLSX_PATH, data_only=True, read_only=True)
    ws = wb["FILTRO"]

    filas = []
    vistos = set()
    duplicados_aviso = 0
    for i, row in enumerate(ws.iter_rows(min_row=2, max_row=ws.max_row, max_col=13)):
        vals = [c.value for c in row]
        aviso_raw = vals[0]
        if aviso_raw is None:
            continue
        aviso = str(int(aviso_raw)) if isinstance(aviso_raw, float) else str(aviso_raw).strip()
        if not aviso.isdigit():
            continue
        if aviso in vistos:
            duplicados_aviso += 1
            continue
        vistos.add(aviso)

        filas.append((
            aviso,
            a_fecha(vals[1]),                 # B fecha_notificacion
            limpio(vals[5]),                  # F descripcion
            None,                              # clase_aviso: no viene en este export
            limpio(vals[23]) if len(vals) > 23 else None,  # X centro_coste (Local)
            limpio(vals[20]) if len(vals) > 20 else None,  # U ubicacion_tecnica
            int(vals[10]) if len(vals) > 10 and str(vals[10]).strip().isdigit() else None,  # K orden_sap
            a_fecha(vals[11]) if len(vals) > 11 else None,  # L fecha_creacion_orden
            a_fecha(vals[12]) if len(vals) > 12 else None,  # M cierre_tecnico
            limpio(vals[6]) if len(vals) > 6 else None,     # G estatus_aviso
            limpio(vals[16]) if len(vals) > 16 else None,   # Q modificado_por/responsable
        ))

    print(f"Filas leidas con AVISO valido: {len(filas)} (esperado ~6451)")
    print(f"AVISOS duplicados dentro del propio export (se conserva el primero): {duplicados_aviso}")

    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"],
    )
    cur = cnx.cursor()
    cur.execute("DELETE FROM avisos_sap")  # reemplazo completo, igual criterio que T1.5
    cur.executemany(
        """
        INSERT INTO avisos_sap (aviso, fecha_notificacion, descripcion, clase_aviso,
            centro_coste, ubicacion_tecnica, orden_sap, fecha_creacion_orden,
            fecha_cierre_tecnico, estatus_aviso, modificado_por)
        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
        """,
        filas,
    )
    cnx.commit()
    cur.execute("SELECT COUNT(*) FROM avisos_sap")
    total = cur.fetchone()[0]
    cur.close()
    cnx.close()
    print(f"Total en avisos_sap: {total}")


if __name__ == "__main__":
    main()
