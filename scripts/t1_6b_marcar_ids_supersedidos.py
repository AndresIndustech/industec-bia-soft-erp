"""T1.6b (cierre) - Marca las filas de 'ots' que quedaron duplicadas por el renombrado.

Al promover un documento de cuarentena, su nombre canonico cambia y por lo tanto
cambia tambien su 'id_industec' (que se deriva del nombre). La ingesta posterior
creo la fila nueva, pero la vieja - con el id del nombre original - siguio ahi.
Resultado: el mismo documento fisico contado dos veces en cualquier KPI.

NO SE BORRA NADA. La fila vieja se marca con en_cuarentena=1 y un motivo explicito
que apunta al id que la reemplaza, de modo que:
  - queda fuera de toda consulta normal (los agentes filtran en_cuarentena=0),
  - se puede auditar y revertir,
  - y la administracion decide si алguna vez conviene borrarlas.

Criterio para elegir cual sobrevive: gana la fila cuyo id_industec coincide con el
nombre del archivo en el arbol canonico. Es la unica que respeta el estandar de §6.1.
"""
import collections
from pathlib import Path

import mysql.connector

BASE = Path(r"D:\INDUSTECH IA\agentes")


def conectar():
    env = {}
    for line in (BASE / "config/.env").read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"], autocommit=True)


def main():
    cnx = conectar()
    cur = cnx.cursor(dictionary=True)
    cur.execute("SELECT id_industec, ruta_pdf, en_cuarentena FROM ots WHERE ruta_pdf IS NOT NULL")
    filas = cur.fetchall()

    por_ruta = collections.defaultdict(list)
    for r in filas:
        por_ruta[str(r["ruta_pdf"])].append(r)

    a_marcar = []
    sin_canonica = []
    for ruta, grupo in por_ruta.items():
        if len(grupo) < 2:
            continue
        esperado = Path(ruta).stem
        canonicas = [r for r in grupo if r["id_industec"] == esperado]
        if len(canonicas) != 1:
            sin_canonica.append((ruta, [r["id_industec"] for r in grupo]))
            continue
        for r in grupo:
            if r["id_industec"] != esperado:
                a_marcar.append((r["id_industec"], esperado))

    print(f"Documentos con mas de una fila: {sum(1 for g in por_ruta.values() if len(g) > 1)}")
    print(f"Filas viejas a marcar como supersedidas: {len(a_marcar)}")
    if sin_canonica:
        print(f"\nATENCION: {len(sin_canonica)} documentos sin una fila que coincida con su "
              f"nombre canonico. No se tocan; requieren revision:")
        for ruta, ids in sin_canonica[:8]:
            print(f"   {Path(ruta).name}  ids={ids}")

    for viejo, nuevo in a_marcar:
        cur.execute(
            """UPDATE ots SET en_cuarentena=1,
                   motivo_cuarentena=CONCAT('SUPERSEDIDO_POR_NOMBRE_CANONICO:', %s)
               WHERE id_industec=%s""",
            (nuevo, viejo))

    cur.execute("SELECT COUNT(*) c FROM ots WHERE en_cuarentena=0")
    activas = cur.fetchone()["c"]
    cur.execute("SELECT COUNT(*) c FROM ots WHERE motivo_cuarentena LIKE 'SUPERSEDIDO%'")
    sup = cur.fetchone()["c"]
    cur.execute("SELECT COUNT(*) c FROM ots WHERE en_cuarentena=1 AND "
                "(motivo_cuarentena IS NULL OR motivo_cuarentena NOT LIKE 'SUPERSEDIDO%')")
    pend = cur.fetchone()["c"]
    print(f"\nOrdenes activas (en_cuarentena=0): {activas}")
    print(f"Filas marcadas como supersedidas:  {sup}")
    print(f"Pendientes reales de decision:     {pend}")

    # verificacion: ninguna orden activa comparte archivo con otra orden activa
    cur.execute("""SELECT ruta_pdf, COUNT(*) c FROM ots WHERE en_cuarentena=0
                   GROUP BY ruta_pdf HAVING c > 1""")
    resto = cur.fetchall()
    print(f"Documentos con mas de una orden activa tras la limpieza: {len(resto)}")
    for r in resto[:5]:
        print(f"   {Path(str(r['ruta_pdf'])).name}  ({r['c']})")
    cur.close()
    cnx.close()


if __name__ == "__main__":
    main()
