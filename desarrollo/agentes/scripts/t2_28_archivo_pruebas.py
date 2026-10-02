"""
T2.28.17f - Pruebas del paso nocturno que mide los PDF del Archivo
(`t2_28_archivo_verificar.py`) y de las alertas que InspectorBot saca de su
resultado. SSH, base y archivos SIMULADOS: no se conecta a nada ni toca el
`estado_archivo.json` real.

Uso:
    .venv/Scripts/python.exe scripts/t2_28_archivo_pruebas.py      # sale con 1 si algo falla
"""
import json
import sys
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).parent))
import t2_28_archivo_verificar as A  # noqa: E402
import inspectorbot_estado as I  # noqa: E402

total = fallos = 0


def afirmar(que, real, esperado):
    global total, fallos
    total += 1
    ok = real == esperado
    if not ok:
        fallos += 1
    print(f"  {'ok   ' if ok else 'FALLA'} {que}" + ("" if ok else f"  -> dio {real!r}, esperaba {esperado!r}"))


TMP = Path(tempfile.mkdtemp(prefix="arc_"))
SANO = "archivo_verificar_cli.php · en_servidor=1 comprobados: 7838\níntegros: 7838/7838\nsin problemas.\n"
CON_PROBLEMAS = ("comprobados: 7838\níntegros: 7836/7838\ncon problema: 2\n"
                 "  OT-1-A\tNO_EXISTE\n  OT-2-B\tNO_ES_PDF\n")


def correr(stdout=SANO, returncode=0, stderr="", por_origen=(("CORREO", "200"),), cae=None, estado_previo=None, ahora=None):
    """Una corrida de main() con todo simulado; devuelve (codigo, estado escrito)."""
    A.ESTADO = TMP / "estado_archivo.json"
    A.ESTADO.unlink(missing_ok=True)
    if estado_previo is not None:
        A.ESTADO.write_text(json.dumps(estado_previo), encoding="utf-8")

    def asegurar():
        if cae:
            raise cae
    A.asegurar_cli = asegurar
    A.correr_verificador = lambda: SimpleNamespace(stdout=stdout, returncode=returncode, stderr=stderr)
    A.H.sql_remoto = lambda sql, **kw: [list(f) for f in por_origen]
    codigo = A.main()
    return codigo, (json.loads(A.ESTADO.read_text(encoding="utf-8")) if A.ESTADO.is_file() else None)


print("=== 1. La cifra de la noche ===")
cod, e = correr()
afirmar("sale con 0 y escribe el estado", (cod, e is not None), (0, True))
afirmar("íntegros, total y problemas", (e["integros"], e["total"], e["con_problema"], e["problemas"]), (7838, 7838, 0, []))
afirmar("sin PDF por origen y el total", (e["sin_pdf"], e["sin_pdf_por_origen"]), (200, {"CORREO": 200}))
afirmar("la primera vez no hay noche anterior", e["anterior"], None)

cod, e = correr(CON_PROBLEMAS)
afirmar("con PDF con problema SIGUE saliendo con 0 (es medición, no compuerta)", cod, 0)
afirmar("y los lista con su motivo", (e["con_problema"], e["problemas"]),
        (2, [{"ot": "OT-1-A", "motivo": "NO_EXISTE"}, {"ot": "OT-2-B", "motivo": "NO_ES_PDF"}]))
afirmar("la lista no está truncada", e["problemas_truncados"], False)

grande = "comprobados: 9000\níntegros: 6000/9000\ncon problema: 3000\n" + "".join(f"  OT-{i}\tNO_EXISTE\n" for i in range(3000))
cod, e = correr(grande)
afirmar("con más de 2.000 problemas guarda 2.000 y DICE que cortó", (len(e["problemas"]), e["problemas_truncados"], e["con_problema"]), (2000, True, 3000))

print("\n=== 2. «anterior» es de OTRO día (el nocturno corre dos veces por noche) ===")
ahora = datetime.now(timezone.utc)
ayer = {"fecha_utc": (ahora - timedelta(hours=30)).isoformat(), "sin_pdf": 173, "con_problema": 0, "anterior": {"sin_pdf": 99}}
cod, e = correr(estado_previo=ayer)
afirmar("la medición de ayer pasa a ser «anterior»", (e["anterior"]["sin_pdf"], e["anterior"]["con_problema"]), (173, 0))
hoy_temprano = {"fecha_utc": (ahora - timedelta(minutes=20)).isoformat(), "sin_pdf": 190, "con_problema": 0,
                "anterior": {"fecha_utc": "2026-09-30T16:29:20+00:00", "sin_pdf": 173, "con_problema": 0}}
cod, e = correr(estado_previo=hoy_temprano)
afirmar("la segunda corrida del MISMO día conserva el «anterior» de otro día (no lo pisa con la de hace 20 min)",
        e["anterior"]["sin_pdf"], 173)

print("\n=== 3. Si no se puede medir: no se rompe el nocturno ni se pierde la última cifra buena ===")
buena = {"fecha_utc": "2026-10-01T08:52:24+00:00", "total": 7838, "integros": 7838, "con_problema": 0, "sin_pdf": 200,
         "sin_pdf_por_origen": {"CORREO": 200}, "anterior": {"sin_pdf": 173}}
cod, e = correr(cae=TimeoutError("ssh colgado"), estado_previo=buena)
afirmar("SSH colgado -> sale con 0", cod, 0)
afirmar("conserva la última cifra buena", (e["integros"], e["sin_pdf"], e["fecha_utc"]), (7838, 200, "2026-10-01T08:52:24+00:00"))
afirmar("anota el error con su fecha", ("ssh colgado" in e["ultimo_error"]["error"], "fecha_utc" in e["ultimo_error"]), (True, True))

cod, e = correr(cae=SystemExit("Falta SSH_USER en config/.env"), estado_previo=buena)
afirmar("hostinger_ssh sale con SystemExit si falta la llave: tampoco rompe", (cod, "SSH_USER" in e["ultimo_error"]["error"]), (0, True))

cod, e = correr(stdout="PHP Fatal error: no hay conexión", returncode=255, stderr="client_loop: send disconnect", estado_previo=buena)
afirmar("salida irreconocible -> error con el código y el stderr", cod, 0)
afirmar("el mensaje dice por qué (código y stderr)", ("codigo 255" in e["ultimo_error"]["error"], "send disconnect" in e["ultimo_error"]["error"]), (True, True))
afirmar("y la cifra buena sigue ahí", e["sin_pdf"], 200)

cod, e = correr(cae=TimeoutError("x"))
afirmar("sin cifra previa: queda solo el error, sin inventar cifras", (cod, "sin_pdf" in e, "ultimo_error" in e), (0, False, True))

print("\n=== 4. Las alertas de InspectorBot ===")


def alertas(arc_json, noc_ok=True, noc_fin_h=2):
    I.ARCHIVO = TMP / "para_alertas.json"
    I.ARCHIVO.write_text(json.dumps(arc_json), encoding="utf-8")
    e = {"robot": {"consulta_error": None, "vivo": True, "codigo_congelado": [], "robots": 1, "silencioso": False},
         "registro": {"conexiones_perdidas": 0, "novedades": 1, "arranques": 1, "errores": 0},
         "espejo": {"ultimo_espejo": datetime.now(), "espejo_viejo": False,
                    "resumen": {"sospechosos": [], "errores": [], "fallidos": 0, "divergentes": 0}, "divergentes": 0, "cuarentena": 0},
         "nocturno": {"nunca_corrio": False, "ensayo": False, "ok": noc_ok, "fin": datetime.now() - timedelta(hours=noc_fin_h),
                      "pasos": [], "candado_huerfano": None},
         "buzon": {"error": "x"}, "atenciones": {"error": "x"}, "disco": {"libre_gb": 500}, "archivo": I.bloque_archivo()}
    return [x for x in I.calcular_alertas(e) if "Archivo" in x["titulo"] or "PDF" in x["titulo"] or "OT emitidas" in x["titulo"]]


reciente = (datetime.now(timezone.utc) - timedelta(hours=1)).isoformat()
sano = {"fecha_utc": reciente, "total": 7838, "integros": 7838, "con_problema": 0, "sin_pdf": 200,
        "sin_pdf_por_origen": {"CORREO": 200}, "anterior": {"sin_pdf": 173}}
afirmar("sano, aunque «sin PDF» suba de 173 a 200 (atraso normal): SIN alerta", alertas(sano), [])
afirmar("2 PDF con problema -> una alerta MEDIO",
        [(a["gravedad"], a["titulo"]) for a in alertas({**sano, "integros": 7836, "con_problema": 2,
                                                         "problemas": [{"ot": "OT-1", "motivo": "NO_EXISTE"}]})],
        [("medio", "2 PDF del Archivo con problema en el servidor")])
con_app = alertas({**sano, "sin_pdf_por_origen": {"CORREO": 199, "APP": 1}})
afirmar("una OT de la APP sin su PDF -> alerta MEDIO", [(a["gravedad"], a["titulo"]) for a in con_app],
        [("medio", "1 OT emitidas por la app no tienen su PDF en el servidor")])
afirmar("la lista truncada se dice «las primeras N»",
        "Las primeras 1 están" in alertas({**sano, "integros": 7837, "con_problema": 5000, "problemas": [{"ot": "x", "motivo": "y"}],
                                           "problemas_truncados": True})[0]["que_hacer"], True)
posterior = (datetime.now(timezone.utc) - timedelta(minutes=10)).isoformat()
afirmar("un error de medición POSTERIOR a la última cifra -> MEDIO, con la edad de la cifra buena",
        [a["titulo"] for a in alertas({**sano, "ultimo_error": {"fecha_utc": posterior, "error": "TimeoutError: ssh"}})],
        ["No se pudo medir el Archivo en la última corrida"])
afirmar("un error ANTERIOR a la última cifra buena ya no cuenta",
        alertas({**sano, "ultimo_error": {"fecha_utc": (datetime.now(timezone.utc) - timedelta(hours=5)).isoformat(), "error": "viejo"}}), [])
viejo = (datetime.now(timezone.utc) - timedelta(hours=40)).isoformat()
afirmar("sin medir hace 40 h aunque el nocturno corrió -> MEDIO",
        [a["titulo"] for a in alertas({**sano, "fecha_utc": viejo})], ["El Archivo lleva más de un día sin verificarse"])
afirmar("sin medir hace 40 h pero el nocturno FALLÓ -> no se repite la alerta",
        alertas({**sano, "fecha_utc": viejo}, noc_ok=False), [])
afirmar("la medicion de la ultima corrida FALLO y la cifra buena tiene 40 h: UNA sola alerta, no la que culpa a un --solo que no existio",
        [a["titulo"] for a in alertas({**sano, "fecha_utc": viejo, "ultimo_error": {"fecha_utc": posterior, "error": "TimeoutError: ssh"}})],
        ["No se pudo medir el Archivo en la última corrida"])
afirmar("estado_archivo.json sin cifras (solo un error) no revienta",
        [a["titulo"] for a in alertas({"ultimo_error": {"fecha_utc": posterior, "error": "TimeoutError"}})],
        ["No se pudo medir el Archivo en la última corrida"])
afirmar("un archivo ilegible se dice", [a["titulo"] for a in (lambda: (I.ARCHIVO.write_text("{no es json", encoding="utf-8"), [
    x for x in I.calcular_alertas({"robot": {"consulta_error": None, "vivo": True, "codigo_congelado": [], "robots": 1, "silencioso": False},
                                   "registro": {"conexiones_perdidas": 0, "novedades": 1, "arranques": 1, "errores": 0},
                                   "espejo": {"ultimo_espejo": datetime.now(), "espejo_viejo": False,
                                              "resumen": {"sospechosos": [], "errores": [], "fallidos": 0, "divergentes": 0},
                                              "divergentes": 0, "cuarentena": 0},
                                   "nocturno": {"nunca_corrio": False, "ensayo": False, "ok": True, "fin": datetime.now(),
                                                "pasos": [], "candado_huerfano": None},
                                   "buzon": {"error": "x"}, "atenciones": {"error": "x"}, "disco": {"libre_gb": 500},
                                   "archivo": I.bloque_archivo()}) if "Archivo" in x["titulo"]])[1])()],
        ["No se puede leer la verificación del Archivo"])

print(f"\n{total} comprobaciones · {fallos} fallos")
sys.exit(1 if fallos else 0)
