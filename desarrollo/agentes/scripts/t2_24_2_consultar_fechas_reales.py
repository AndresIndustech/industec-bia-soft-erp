"""t2_24_2_consultar_fechas_reales.py — Solo lectura, contra la base local industec_ots.

Para el cierre en bloque de los 174 preventivos "sin cerrar" (T2.24.2, decisión de
Andrés del 2026-09-21): busca, para cada orden que ya está en ot_ids del ingreso,
su fecha_atencion real (extraída del PDF, columna independiente de lo que dice el
cronograma). Es la verificación cruzada que pide I-10: no se inventa una fecha de
cierre, se usa la que ya quedó registrada al procesar el corpus histórico.

Entrada: el JSON que vuelca pruebas/servidor/_consulta_preventivos.py --volcar.
No escribe nada.

Uso:
    .venv/Scripts/python.exe scripts/t2_24_2_consultar_fechas_reales.py RUTA.json
"""
import datetime
import json
import os
import re
import sys
from pathlib import Path

BASE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE))

from dotenv import load_dotenv
import mysql.connector

load_dotenv(BASE / "config" / ".env")

ruta_entrada = sys.argv[1] if len(sys.argv) > 1 else None
if not ruta_entrada or not Path(ruta_entrada).is_file():
    print("uso: t2_24_2_consultar_fechas_reales.py RUTA.json (el volcado de _consulta_preventivos.py --volcar)",
          file=sys.stderr)
    sys.exit(2)

filas = json.load(open(ruta_entrada, encoding="utf-8"))
print(f"ingresos sin cerrar recibidos: {len(filas)}")

cnx = mysql.connector.connect(
    host=os.environ["DB_HOST"], port=int(os.environ.get("DB_PORT", 3306)),
    user=os.environ["DB_USER"], password=os.environ["DB_PASSWORD"],
    database=os.environ["DB_NAME"],
)
cur = cnx.cursor(dictionary=True)

def ids_de(campo):
    """ot_ids es varchar(400): con un local de muchas órdenes duplicadas el
    JSON queda cortado a la mitad (visto en K041EC, ingreso_id 187: 12
    variantes de nombre para un preventivo de 4 días agotan los 400
    caracteres). json.loads() falla ahí, y descartar la fila entera tiraría
    las órdenes completas que sí vinieron enteras. Se recuperan con un patrón
    sobre el texto crudo — nunca menos preciso que lo que ya se perdió al
    truncar en el servidor, y nunca se inventa una que no esté escrita."""
    if not campo:
        return []
    try:
        return json.loads(campo)
    except json.JSONDecodeError:
        return re.findall(r'OT-[A-Za-z0-9._-]+(?=")', campo)


truncados = []
resultado = []
sin_ninguna_fecha = []
disparidad = []

for f in filas:
    crudo = f["ot_ids"]
    ids = ids_de(crudo)
    if crudo and len(crudo) >= 400:
        truncados.append((f["ingreso_id"], f["local_codigo"], len(ids)))
    fechas = []
    if ids:
        ph = ",".join(["%s"] * len(ids))
        # en_cuarentena=1 son duplicados ya superados (I-11): no cuentan para
        # la fecha real, o un correlativo sintético del rango 90000+ (mismo
        # motivo) podría colarse como si fuera la fecha de una orden real.
        cur.execute(
            f"SELECT id_industec, fecha_atencion FROM ots WHERE id_industec IN ({ph}) AND en_cuarentena = 0",
            ids,
        )
        fechas = [r["fecha_atencion"] for r in cur.fetchall() if r["fecha_atencion"] is not None]
    plan_fin = f["plan_vigente_fin"]
    if isinstance(plan_fin, str):
        plan_fin = datetime.date.fromisoformat(plan_fin)
    plan_ini = f.get("plan_original_fin")

    if not fechas:
        sin_ninguna_fecha.append(f)
        # Sin fecha real independiente: NO se inventa (I-7). Se usa la fecha
        # planificada como única alternativa, y queda marcado como tal para
        # que la novedad lo diga sin disfrazarlo de "verificado".
        resultado.append({**f, "real_inicio": str(plan_fin), "real_fin": str(plan_fin),
                           "fuente_fecha": "SIN_OT_CON_FECHA:se_usa_plan_vigente_fin"})
        continue

    real_fin = max(fechas)
    real_inicio = min(fechas)
    dias = abs((real_fin - plan_fin).days)
    if dias > 30:
        disparidad.append((f["local_codigo"], f["numero"], str(real_fin), str(plan_fin), dias))
    resultado.append({**f, "real_inicio": str(real_inicio), "real_fin": str(real_fin),
                       "fuente_fecha": "OTS.fecha_atencion"})

if truncados:
    print(f"AVISO: {len(truncados)} fila(s) con ot_ids en el límite de 400 caracteres (posible truncado por el servidor):")
    for t in truncados:
        print("  ", t)
print(f"con al menos 1 fecha real en 'ots': {len(filas) - len(sin_ninguna_fecha)} de {len(filas)}")
print(f"sin ninguna fecha real (ningun id_industec calzo, o ninguna orden tiene fecha_atencion): {len(sin_ninguna_fecha)}")
if sin_ninguna_fecha:
    for f in sin_ninguna_fecha:
        print("  ", f["local_codigo"], f["numero"], f["ot_ids"][:100])
print(f"disparidad > 30 dias entre la fecha real hallada y plan_vigente_fin: {len(disparidad)}")
for d in disparidad:
    print("  ", d)

salida = Path(ruta_entrada).with_name(Path(ruta_entrada).stem + "_con_fechas.json")
json.dump(resultado, open(salida, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
print(f"\nvolcado con fechas reales: {salida}")

cur.close()
cnx.close()
