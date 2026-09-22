"""t2_24_2_consultar_sin_cerrar.py — Solo lectura, contra ingresos_preventivos del sitio de pruebas.

Identifica los preventivos EN_CURSO cuyo plan_vigente_fin ya pasó — el estado que
cronograma.js llama "sin cerrar" desde T2.23. Es el primer paso de T2.24.2 (cierre
masivo a pedido de Andrés, 2026-09-21): esto solo LEE y vuelca la lista; el cruce
contra la fecha real de cada orden lo hace después, en la estación,
scripts/t2_24_2_consultar_fechas_reales.py, y quien escribe de verdad es
publico/t2_24_2_cerrar_masivo_cli.php (dry-run por omisión, --ejecutar para escribir).

Uso:
  INDUSTEC_LLAVE_SSH=... python t2_24_2_consultar_sin_cerrar.py                  # resumen en pantalla
  INDUSTEC_LLAVE_SSH=... python t2_24_2_consultar_sin_cerrar.py --volcar RUTA.json
"""
import json
import sys
import verificar_http as vh

sc = vh.sql(
    "SELECT ingreso_id, local_codigo, zona, anio, numero, plan_original_fin, "
    "plan_vigente_fin, ot_ids, actualizado_por "
    "FROM ingresos_preventivos WHERE estado = 'EN_CURSO' AND plan_vigente_fin < CURDATE() "
    "ORDER BY plan_vigente_fin ASC"
)

if "--volcar" in sys.argv:
    ruta = sys.argv[sys.argv.index("--volcar") + 1]
    with open(ruta, "w", encoding="utf-8") as fh:
        json.dump(sc, fh, ensure_ascii=False, indent=1)
    print(f"{len(sc)} filas volcadas a {ruta}")
else:
    print("total sin cerrar:", len(sc))
    for f in sc[:8]:
        print(f)
    sin_ot = [f for f in sc if not f.get("ot_ids")]
    print("sin ot_ids:", len(sin_ot), "de", len(sc))
    tocadas = [f for f in sc if f.get("actualizado_por") is not None]
    print("tocadas por un usuario:", len(tocadas), "de", len(sc))
