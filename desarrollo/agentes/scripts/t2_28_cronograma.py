"""
T2.28.16b - El Excel de la administradora contra lo que el sistema ya tiene
cargado en `ingresos_preventivos` (Hostinger, sitio de pruebas), un informe de
diferencias. SOLO LECTURA de las dos fuentes: no reagenda nada, no toca
`plan_original_*`, no escribe en el Excel de la administradora.

POR QUE EXISTE
El sistema se importo el 8 de septiembre; el 22 la administradora reprogramo 87
ingresos en su Excel y nadie volvio a cargarlo (D7, T2_28_OBSERVACIONES_INDUSTEC.md
T2.28.16). Mientras tanto `reportes.php` y el cronograma que ven los tecnicos
siguen diciendo la fecha vieja: doble registro invisible. Este informe lo hace
visible -- proponer la accion, no ejecutarla, porque reagendar en bloque es
T2.28.16c y esta detras de la puerta D7.

REUSA t2_7_cronograma_preventivo.py PARA LEER EL EXCEL. `leer_cronograma()` y
`resolver_fila()` son la MISMA implementacion que usa T2.28.16a (abreviaturas de
mes, dia pegado al mes, cambio de año dentro de la fila): si esa logica cambia,
este informe cambia con ella. Reescribirla aqui habria arriesgado que las dos
divergieran, como ya paso una vez con `empujar()` (ver comun.py).

COMO SE DECIDE LA ACCION, por ingreso (ver `comparar_ingreso()`):
  SIN_CAMBIO     el Excel de hoy y el plan_vigente del sistema dan la misma
                 fecha: no hay nada que avisar.
  CONFLICTO      las fechas difieren Y el sistema ya no puede moverse solo:
                 esta CUMPLIDO o EN_CURSO, o alguien ya lo reagendo aparte
                 (plan_vigente != plan_original) a una fecha que no es la del
                 Excel. Dos fuentes se movieron por separado: a revision
                 humana, nunca a reagendar solo.
  REAGENDAR      las fechas difieren, el sistema sigue en su plan original sin
                 tocar (PLANIFICADO/VENCIDO/CANCELADO): aplicar el Excel es
                 seguro, PERO la carga la hace 16c, detras de D7.
  NO_CONVERTIBLE el Excel no dio una fecha para este ingreso (PENDIENTE,
                 NO_RECONOCIDO o celda vacia): no hay con que comparar.
El kit se compara aparte, con el mismo vocabulario (columnas KIT *), usando el
mapeo EXACTO de `cronograma_importar_cli.php` (si ese mapeo cambia alla, hay
que cambiarlo aqui tambien: no hay forma de leerlo desde PHP sin ejecutarlo).

Uso (desde la estacion; solo lee, no requiere `--ejecutar` porque no escribe
nada mas que el informe):
    .venv/Scripts/python.exe scripts/t2_28_cronograma.py --comparar

Salida: "SALIDAS IA/OTS/CRONOGRAMA - EXCEL CONTRA SISTEMA (generado agente).xlsx"
Codigo de salida: 0 si el informe se genero (con o sin diferencias); 1 si no se
pudo leer el Excel o el servidor.
"""
from __future__ import annotations

import argparse
import sys
from datetime import date
from pathlib import Path

import openpyxl
from openpyxl.styles import Font, PatternFill

sys.path.insert(0, str(Path(__file__).parent))
from comun import SALIDAS  # noqa: E402  (rutas relativas al repositorio, T2.15.1)
import hostinger_ssh as H  # noqa: E402
import t2_7_cronograma_preventivo as CRON  # noqa: E402  (dueña única: T2.28.16a)

# Mismo mapeo que $KIT en cronograma_importar_cli.php:45-46 -- lo que el
# importador escribiria en kit_estado si cargara el Excel de hoy. Vive
# duplicado a proposito (no se puede "importar" un array de PHP desde Python);
# si uno cambia, el otro se desalinea en silencio, asi que preguntas() lo
# imprime en el encabezado del informe para que sea facil notar el desfase.
KIT_A_SISTEMA = {"PENDIENTE": "SIN_KIT", "SOLICITADO": "SOLICITADO",
                  "DISPONIBLE": "CONFIRMADO", "CONFIRMADO": "CONFIRMADO", "ENTREGADO": "ENTREGADO"}

DESTINO = SALIDAS / "CRONOGRAMA - EXCEL CONTRA SISTEMA (generado agente).xlsx"


def _n(v):
    """El TSV de sql_remoto trae NULL como el texto 'NULL' (ver su docstring)."""
    return None if v in (None, "NULL", "") else v


def leer_sistema(anio: int) -> dict:
    """(local, numero) -> fila de ingresos_preventivos. Un SELECT, nada mas."""
    filas = H.sql_remoto(
        "SELECT local_codigo, zona, numero, plan_original_inicio, plan_original_fin, "
        "plan_vigente_inicio, plan_vigente_fin, kit_estado, estado "
        f"FROM ingresos_preventivos WHERE anio = {int(anio)}")
    sistema = {}
    for f in filas:
        if len(f) < 9:
            continue
        local, zona, numero, po_i, po_f, pv_i, pv_f, kit, estado = f[:9]
        sistema[(local, int(numero))] = {
            "zona": _n(zona), "po_i": _n(po_i), "po_f": _n(po_f),
            "pv_i": _n(pv_i), "pv_f": _n(pv_f), "kit": _n(kit), "estado": _n(estado),
        }
    return sistema


def comparar_ingreso(excel_plan: dict | None, s: dict | None) -> tuple[str, str]:
    """(accion, detalle_motivo_tecnico). `excel_plan` es it['plan'] de
    resolver_fila() (o None); `s` es la fila de leer_sistema() (o None)."""
    if s is None:
        return "SIN_FILA_SISTEMA", "el ingreso no existe hoy en ingresos_preventivos"
    if excel_plan is None:
        return "NO_CONVERTIBLE", ""
    ex_i, ex_f = excel_plan["inicio"], excel_plan["fin"]
    if ex_i == s["pv_i"] and ex_f == s["pv_f"]:
        return "SIN_CAMBIO", ""
    if s["estado"] in ("CUMPLIDO", "EN_CURSO"):
        return "CONFLICTO", f"el sistema ya está {s['estado']}: no se reagenda (I-2/permiso de 16b)"
    if s["pv_i"] != s["po_i"] or s["pv_f"] != s["po_f"]:
        return "CONFLICTO", "el sistema ya lo reagendó aparte, a otra fecha distinta de la del Excel"
    return "REAGENDAR", "el Excel trae otra fecha y el sistema sigue en su plan original, sin tocar"


def comparar_kit(kit_excel: str, s: dict | None) -> tuple[str, str]:
    if s is None:
        return "SIN_FILA_SISTEMA", ""
    esperado = KIT_A_SISTEMA.get(kit_excel)
    if esperado is None:
        return "NO_CONVERTIBLE", f"'{kit_excel}' no está en el mapeo de kits del importador"
    if esperado == s["kit"]:
        return "SIN_CAMBIO", ""
    return "REAGENDAR", f"el Excel propone {esperado}, el sistema tiene {s['kit']}"


def comparar():
    filas_excel, formas = CRON.leer_cronograma()
    sistema = leer_sistema(CRON.ANIO)
    salida = []
    for fila in filas_excel:
        local, zona_maestro = fila["local"], None
        for it in CRON.resolver_fila(fila):
            numero = it["numero"]
            s = sistema.get((local, numero))
            accion, motivo_tecnico = comparar_ingreso(it["plan"], s)
            accion_kit, motivo_kit = comparar_kit(fila["kit"], s)
            excel_txt = (f"{it['plan']['inicio']} .. {it['plan']['fin']}" if it["plan"]
                         else f"[{it['forma_origen']}] {it['texto_origen']!r}")
            salida.append({
                "local": local,
                "zona": (s["zona"] if s else None) or zona_maestro,
                "numero": numero,
                "forma_excel": it["forma_origen"],
                "plan_original_sistema": f"{s['po_i']} .. {s['po_f']}" if s and s["po_i"] else "",
                "plan_vigente_sistema": f"{s['pv_i']} .. {s['pv_f']}" if s and s["pv_i"] else "",
                "excel_hoy": excel_txt,
                "estado_sistema": s["estado"] if s else "",
                "accion": accion,
                "motivo": "",
                "motivo_tecnico": motivo_tecnico,
                "anio_motivo": it["anio_motivo"] or "",
                "kit_excel": fila["kit"],
                "kit_texto": fila["observacion"],
                "kit_sistema": s["kit"] if s else "",
                "accion_kit": accion_kit,
                "motivo_kit_tecnico": motivo_kit,
            })
    return salida


def escribir_informe(filas: list[dict], destino: Path, hoy: date) -> None:
    contadores = {}
    for f in filas:
        contadores[f["accion"]] = contadores.get(f["accion"], 0) + 1
    cumplidos_en_conflicto = sum(1 for f in filas if f["accion"] == "CONFLICTO" and f["estado_sistema"] == "CUMPLIDO")

    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "DIFERENCIAS"
    ws["A1"] = f"Cronograma de preventivos: el Excel de hoy ({hoy:%d/%m/%Y}) contra el sistema — T2.28.16b"
    ws["A1"].font = Font(bold=True, size=13)
    ws["A2"] = ("Propuesta del agente, no ejecutada: reagendar en bloque es T2.28.16c y espera la puerta D7. "
                "MOTIVO queda vacía para que la llene quien decida (es lo que se le explica a KFC).")
    ws["A2"].font = Font(italic=True, color="FFC00000")

    enc = ["#", "LOCAL", "ZONA", "N°", "FORMA EXCEL", "PLAN ORIGINAL (sistema)",
           "PLAN VIGENTE (sistema)", "EXCEL DE HOY", "ESTADO SISTEMA",
           "ACCIÓN PROPUESTA", "MOTIVO", "por qué (técnico)", "año (motivo)",
           "KIT EXCEL", "KIT TEXTO EXCEL", "KIT SISTEMA", "ACCIÓN KIT", "por qué kit (técnico)"]
    fila_enc = 4
    for j, v in enumerate(enc, start=1):
        c = ws.cell(row=fila_enc, column=j, value=v)
        c.font = Font(bold=True, color="FFFFFFFF")
        c.fill = PatternFill("solid", start_color="FF2F75B5")

    COLOR_ACCION = {"REAGENDAR": "FFFFF2CC", "CONFLICTO": "FFF8CBAD",
                     "NO_CONVERTIBLE": "FFE2E2E2", "SIN_CAMBIO": "FFE2EFDA",
                     "SIN_FILA_SISTEMA": "FFF8CBAD"}
    for i, f in enumerate(filas, start=1):
        valores = [i, f["local"], f["zona"], f["numero"], f["forma_excel"],
                   f["plan_original_sistema"], f["plan_vigente_sistema"], f["excel_hoy"],
                   f["estado_sistema"], f["accion"], f["motivo"], f["motivo_tecnico"],
                   f["anio_motivo"], f["kit_excel"], f["kit_texto"], f["kit_sistema"],
                   f["accion_kit"], f["motivo_kit_tecnico"]]
        r = fila_enc + i
        for j, v in enumerate(valores, start=1):
            ws.cell(row=r, column=j, value=v)
        color = COLOR_ACCION.get(f["accion"])
        if color:
            ws.cell(row=r, column=10).fill = PatternFill("solid", start_color=color)
    anchos = (4, 9, 6, 4, 12, 22, 22, 26, 13, 16, 30, 46, 40, 12, 22, 13, 12, 40)
    for col, w in zip(ws.iter_cols(min_row=1, max_row=1, max_col=len(anchos)), anchos):
        ws.column_dimensions[col[0].column_letter].width = w
    ws.freeze_panes = ws.cell(row=fila_enc + 1, column=1)

    r = wb.create_sheet("RESUMEN")
    r.append(["Generado", f"{hoy:%Y-%m-%d}"])
    r.append(["Total de ingresos comparados", len(filas)])
    r.append([])
    r.append(["Acción", "Cantidad"])
    for a in ("REAGENDAR", "SIN_CAMBIO", "CONFLICTO", "NO_CONVERTIBLE", "SIN_FILA_SISTEMA"):
        r.append([a, contadores.get(a, 0)])
    r.append([])
    r.append(["CONFLICTO donde el sistema está CUMPLIDO", cumplidos_en_conflicto])
    r.append([])
    r.append(["Mapeo de kit usado (Excel -> sistema), copiado de cronograma_importar_cli.php:45-46"])
    for k, v in KIT_A_SISTEMA.items():
        r.append([k, v])
    r.column_dimensions["A"].width, r.column_dimensions["B"].width = 46, 14

    d = wb.create_sheet("CONFLICTO Y NO_CONVERTIBLE")
    d.append(["Local", "N°", "Acción", "Estado sistema", "Plan vigente sistema", "Excel de hoy", "Por qué"])
    for f in filas:
        if f["accion"] in ("CONFLICTO", "NO_CONVERTIBLE", "SIN_FILA_SISTEMA"):
            d.append([f["local"], f["numero"], f["accion"], f["estado_sistema"],
                      f["plan_vigente_sistema"], f["excel_hoy"], f["motivo_tecnico"]])
    for col, w in zip("ABCDEFG", (10, 5, 16, 14, 22, 30, 60)):
        d.column_dimensions[col].width = w

    destino.parent.mkdir(parents=True, exist_ok=True)
    wb.save(destino)


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--comparar", action="store_true",
                     help="lee el Excel y el sistema (solo SELECT) y escribe el informe de diferencias")
    args = ap.parse_args()
    if not args.comparar:
        ap.print_help()
        return

    filas = comparar()
    hoy = date.today()
    escribir_informe(filas, DESTINO, hoy)

    contadores = {}
    for f in filas:
        contadores[f["accion"]] = contadores.get(f["accion"], 0) + 1
    cumplidos_en_conflicto = sum(1 for f in filas if f["accion"] == "CONFLICTO" and f["estado_sistema"] == "CUMPLIDO")
    print(f"ingresos comparados : {len(filas)}")
    for a in ("REAGENDAR", "SIN_CAMBIO", "CONFLICTO", "NO_CONVERTIBLE", "SIN_FILA_SISTEMA"):
        print(f"  {a:<16}: {contadores.get(a, 0)}")
    print(f"CONFLICTO con CUMPLIDO: {cumplidos_en_conflicto}")
    if cumplidos_en_conflicto:
        print("  (no se toca: reagendar un CUMPLIDO está prohibido en esta subtarea)")
    print(f"\n-> {DESTINO}")


if __name__ == "__main__":
    main()
