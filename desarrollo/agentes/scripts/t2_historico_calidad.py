"""
Calidad del historico reconstruido, en dos mediciones distintas.

La fidelidad ya no se mide contra los planes de la administracion: esos se
llevan a mano y arrastran errores, asi que igualarlos seria copiar el error.
Se mide otra cosa:

1. **Cobertura de llenado.** De cada columna, que porcentaje de filas quedo con
   dato real y no con NINGUNO o vacio. Es lo que dice cuanto alcanza a
   reconstruir la evidencia disponible, columna por columna.

2. **Discrepancias contra los planes llevados a mano.** Donde su archivo dice
   algo distinto de lo que dicen la orden firmada y SAP. No se corrigen aqui ni
   se copian: se listan en un Excel para que la administracion las revise. Solo
   se contrastan las columnas donde la evidencia es concluyente:

   | Columna          | Evidencia contra la que se contrasta          |
   |------------------|-----------------------------------------------|
   | LOCAL            | centro de coste del aviso en SAP              |
   | FECHA DE INICIO  | fecha de notificacion del aviso en SAP        |
   | #OT INDUSTEC ... | numero de orden real en el arbol canonico     |
   | FECHA CIERRE     | fecha de atencion de la orden de cierre       |
   | ESTATUS SAP      | campo "Estatus 2 de la Orden" del export SAP  |

   Quedan fuera OBSERVACIONES y PRESUPUESTO (son de ella, no hay contra que
   contrastarlas) y los textos libres del tecnico, donde diferir no es error.

Uso:
    .venv/Scripts/python.exe scripts/t2_historico_calidad.py
"""
import sys
from datetime import date, datetime
from pathlib import Path

import openpyxl
from openpyxl.utils import get_column_letter

sys.path.insert(0, str(Path(__file__).parent))
from t2_historico_correctivos import COLS, COLS_NINGUNO, CARPETA_ZONA, MESES, PLANTILLAS, SALIDA

ORIGEN = Path(r"D:\RESPALDOS\_ORIGEN_DRIVE\GESTION DE OTS INDUSTEC\2026\PLANES SEMANALES")
CARPETA_REAL = {"UIO": "PLAN DE TRABAJO _ ZONA UIO", "LARB": "PLAN DE TRABAJO _ ZONA LARB",
                "CNLJ": "PLAN DE TRABAJO _ ZONA C-L"}
REPORTE = Path(r"D:\INDUSTECH IA\SALIDAS IA\MANTENIMIENTO"
               r"\DISCREPANCIAS PLANES ADMINISTRACION (generado agente).xlsx")

# Columnas con evidencia concluyente detras. El texto libre del tecnico no entra:
# que ella lo resuma distinto no es un error.
CONTRASTABLES = ["LOCAL", "FECHA DE INICIO", "#OT INDUSTEC EVALUACION",
                 "#OT INDUSTEC CIERRE", "FECHA CIERRE", "ESTATUS SAP"]
# Una diferencia no siempre es un error de ella. El export de SAP es una foto
# de HOY: si el caso paso de MSOL a MEDE despues de que ella cerro el mes, su
# celda registra el estado de entonces y ninguno de los dos esta equivocado.
# Las fechas, los codigos de local y los numeros de orden si son estables.
TIPO_DIFERENCIA = {
    "ESTATUS SAP": "posible desfase temporal: el export SAP refleja el estado de hoy",
}
TIPO_POR_DEFECTO = "contradice evidencia estable (revisar el archivo)"

EVIDENCIA = {
    "LOCAL": "centro de coste del aviso en SAP",
    "FECHA DE INICIO": "fecha de notificacion del aviso en SAP",
    "#OT INDUSTEC EVALUACION": "orden de evaluacion en el arbol canonico",
    "#OT INDUSTEC CIERRE": "orden de cierre en el arbol canonico",
    "FECHA CIERRE": "fecha de atencion de la orden de cierre",
    "ESTATUS SAP": "campo 'Estatus 2 de la Orden' del export SAP",
}


def norm(v):
    if v is None:
        return ""
    if isinstance(v, (datetime, date)):
        return v.strftime("%Y-%m-%d")
    if isinstance(v, float) and v.is_integer():
        v = int(v)
    return " ".join(str(v).split()).strip().upper()


def leer(path):
    wb = openpyxl.load_workbook(path, data_only=True)
    ws = wb.worksheets[0]
    encabezado = [norm(c.value) for c in ws[1]]
    salto = 6 if "COLUMNA1" in encabezado else None
    filas = {}
    for r in range(2, ws.max_row + 1):
        aviso = norm(ws.cell(r, 2).value)
        if not aviso.isdigit():
            continue
        filas[aviso] = {nombre: norm(ws.cell(r, j if (salto is None or j < salto) else j + 1).value)
                        for j, nombre in enumerate(COLS, start=1)}
    return filas


def cobertura_llenado():
    """Porcentaje de filas con dato real por columna, sobre todo el historico."""
    con_dato = {c: 0 for c in COLS}
    total = 0
    for zona, carpeta in CARPETA_ZONA.items():
        for f in sorted((SALIDA / carpeta / "PLANES MENSUALES").glob("*.xlsx")):
            ws = openpyxl.load_workbook(f, data_only=True).worksheets[0]
            for r in range(2, ws.max_row + 1):
                if ws.cell(r, 2).value is None:
                    continue
                total += 1
                for j, nombre in enumerate(COLS, start=1):
                    v = norm(ws.cell(r, j).value)
                    if v and v != "NINGUNO":
                        con_dato[nombre] += 1
    return total, con_dato


def discrepancias():
    hallazgos = []
    for zona, carpeta_real in CARPETA_REAL.items():
        for archivo in sorted((ORIGEN / carpeta_real / "PLANES MENSUALES").glob("*.xlsx")):
            mes = MESES.index(archivo.stem.upper()) + 1 if archivo.stem.upper() in MESES else None
            if not mes:
                continue
            gen = (SALIDA / CARPETA_ZONA[zona] / "PLANES MENSUALES" /
                   f"2026-{mes:02d} {MESES[mes-1]} (generado agente).xlsx")
            if not gen.exists():
                continue
            reales, generadas = leer(archivo), leer(gen)
            for aviso in set(reales) & set(generadas):
                for col in CONTRASTABLES:
                    vr, vg = reales[aviso][col], generadas[aviso][col]
                    # Solo se reporta cuando AMBOS tienen dato y difieren: que
                    # ella no haya llenado una celda no es un error suyo.
                    if not vr or not vg or vr == "NINGUNO" or vg == "NINGUNO":
                        continue
                    if vr != vg:
                        hallazgos.append([zona, MESES[mes - 1], aviso, col, vr, vg,
                                          EVIDENCIA[col],
                                          TIPO_DIFERENCIA.get(col, TIPO_POR_DEFECTO)])
    return hallazgos


def main():
    total, con_dato = cobertura_llenado()
    print(f"=== Cobertura de llenado · {total} filas del historico ===")
    print(f"{'COLUMNA':<32}{'con dato':>10}{'%':>8}")
    for j, nombre in enumerate(COLS, start=1):
        marca = " (NINGUNO)" if get_column_letter(j) in COLS_NINGUNO else ""
        print(f"{nombre + marca:<32}{con_dato[nombre]:>10}{con_dato[nombre]/total*100:>7.1f}%")

    hallazgos = discrepancias()
    print(f"\n=== Discrepancias contra los planes llevados a mano ===")
    print(f"Filas contrastadas contra los 24 archivos reales de ene-ago 2026")
    por_columna = {}
    for h in hallazgos:
        por_columna.setdefault(h[3], []).append(h)
    revisar = sum(1 for h in hallazgos if h[7] == TIPO_POR_DEFECTO)
    for col, lista in sorted(por_columna.items(), key=lambda x: -len(x[1])):
        etiqueta = "" if col not in TIPO_DIFERENCIA else "  [desfase temporal, no error]"
        print(f"  {col}: {len(lista)} casos donde su archivo difiere de "
              f"{EVIDENCIA[col]}{etiqueta}")
        for h in lista[:2]:
            print(f"      aviso {h[2]} ({h[0]} {h[1]}): archivo={h[4]!r}  evidencia={h[5]!r}")
    print(f"  TOTAL: {len(hallazgos)} · a revisar por contradecir evidencia estable: {revisar}")

    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "DISCREPANCIAS"
    ws.append(["ZONA", "MES", "AVISO", "COLUMNA", "DICE EL ARCHIVO",
               "DICE LA EVIDENCIA", "FUENTE DE LA EVIDENCIA", "COMO LEER LA DIFERENCIA"])
    for h in sorted(hallazgos, key=lambda x: (x[3], x[0], x[1])):
        ws.append(h)
    for col, ancho in zip("ABCDEFGH", (8, 12, 12, 26, 34, 34, 42, 46)):
        ws.column_dimensions[col].width = ancho
    ws.freeze_panes = "A2"
    REPORTE.parent.mkdir(parents=True, exist_ok=True)
    wb.save(REPORTE)
    print(f"\nReporte para la administracion: {REPORTE}")


if __name__ == "__main__":
    main()
