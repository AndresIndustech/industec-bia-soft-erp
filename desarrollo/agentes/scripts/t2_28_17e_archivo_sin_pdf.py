"""
t2_28_17e_archivo_sin_pdf.py - Informe "ARCHIVO - ORDENES SIN PDF" (T2.28.17e).

QUE HACE. De las 132 filas de `ot_archivo` que el 2026-09-22/23 no tenian PDF
(ESTADO.md S1s), T2.28.17c ya subio las 24 que la estacion tenia (verificado:
`SELECT COUNT(*) FROM ot_archivo WHERE origen='HISTORICO' AND en_servidor=0`
da 0). Este script reconcilia las 108 restantes: por cada una, busca si existe
una fila HERMANA -- mismo correlativo + mismo aviso + misma zona, la clave real
del documento, no el nombre -- que SI tenga `en_servidor=1`. Si la hay, el
documento existe, solo esta indexado dos veces por una variante de nombre del
local (`R002` vs `R002EC`, `JV071` vs `V071EC`): es un "duplicado por nombre"
de T2.28.17d, que esta FUERA de esta fase (requiere la puerta D8, prohibido
tocar `duplicado_de`). Si NO la hay, es un caso genuino para investigar y va
al informe con fila propia.

POR QUE CORRELATIVO+AVISO+ZONA Y NO EL LOCAL: el local es justo lo que varia
entre las dos filas de un mismo documento (el correo lo nombra sin "EC", el
maestro con "EC" -- ver Catalogo.php). El aviso SAP es un numero que la propia
KFC asigna una vez por notificacion: que dos ORDENES DE TRABAJO reales
coincidan en correlativo Y aviso Y zona por azar es, en la practica, imposible
(el correlativo es secuencial interno). Emparejar por esa terna es exactamente
la clave canonica que T2.28.17d define para el indexador.

SOLO LECTURA sobre la base remota (sql_remoto siempre hace SELECT). No calcula
ni escribe `duplicado_de`: eso sigue prohibido en esta fase.

USO:
    .venv/Scripts/python.exe scripts/t2_28_17e_archivo_sin_pdf.py
"""
from __future__ import annotations

import re
import sys
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
import hostinger_ssh as H  # noqa: E402
from comun import SALIDAS  # noqa: E402

from openpyxl import Workbook
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.utils import get_column_letter

DESTINO = SALIDAS / "ARCHIVO - ORDENES SIN PDF (generado agente).xlsx"

# OT-{correlativo}-{local}{-aviso}{-Ddia}-{zona}. El local puede traer letras Y
# digitos en cualquier combinacion (K061, CN42, JV071, A010): no se restringe
# el largo para no repetir el bug de "NO CASA PATRON" con JV071 (5 caracteres).
PATRON = re.compile(
    r'^OT-(?P<correlativo>\d{4})-(?P<local>[A-Z0-9]+?)'
    r'(?:-(?P<aviso>\d{8}))?(?:-D(?P<dia>\d+))?-(?P<zona>UIO|LARB|CNLJ|OTRA)$'
)


def partes(id_industec: str) -> dict | None:
    m = PATRON.match(id_industec)
    return m.groupdict() if m else None


def main() -> int:
    print("Consultando ot_archivo (solo lectura) ...")
    filas = H.sql_remoto(
        "SELECT id_industec, origen, zona, local_codigo, aviso, en_servidor "
        "FROM ot_archivo WHERE en_servidor = 0 ORDER BY id_industec"
    )
    print(f"  filas con en_servidor=0 hoy: {len(filas)}")

    pendientes = []      # (id_industec, origen, zona, local, aviso) sin parsear
    parseadas = []        # (id_industec, origen, zona, local, aviso, correlativo)
    avisos = set()
    for id_industec, origen, zona, local_codigo, aviso, _en_servidor in filas:
        p = partes(id_industec)
        if p is None or not p["aviso"]:
            pendientes.append((id_industec, origen, zona, local_codigo, aviso))
            continue
        parseadas.append((id_industec, origen, zona, local_codigo, aviso, p["correlativo"]))
        avisos.add(p["aviso"])

    # Un solo viaje de vuelta: todo lo que exista con esos avisos, sea cual sea
    # su origen o su en_servidor (la hermana puede ser CORREO, APP o HISTORICO).
    hermanas_por_aviso: dict[str, list] = {}
    lote = sorted(avisos)
    CHUNK = 60
    for i in range(0, len(lote), CHUNK):
        trozo = lote[i:i + CHUNK]
        lista_sql = ",".join("'" + a + "'" for a in trozo)
        filas2 = H.sql_remoto(
            f"SELECT id_industec, origen, zona, aviso, local_codigo, en_servidor "
            f"FROM ot_archivo WHERE aviso IN ({lista_sql})"
        )
        for row in filas2:
            hermanas_por_aviso.setdefault(row[3], []).append(row)

    duplicadas_con_pdf = []   # ya resueltas por T2.28.17d (fuera de alcance): no se tocan
    genuinas = []              # sin ninguna hermana con PDF: van al informe

    for id_industec, origen, zona, local_codigo, aviso, correlativo in parseadas:
        hermanas = [
            h for h in hermanas_por_aviso.get(aviso, [])
            if h[0] != id_industec and h[2] == zona and h[0].split('-')[1] == correlativo
        ]
        con_pdf = [h for h in hermanas if h[5] in ('1', 1)]
        if con_pdf:
            duplicadas_con_pdf.append((id_industec, origen, zona, local_codigo, aviso, con_pdf[0][0]))
        else:
            genuinas.append((id_industec, origen, zona, local_codigo, aviso, hermanas))

    # Lo que no tenia forma de OT reconocible tambien es "genuino": no hay
    # aviso con que buscar hermana, asi que no se puede descartar como duplicado.
    for id_industec, origen, zona, local_codigo, aviso in pendientes:
        genuinas.append((id_industec, origen, zona, local_codigo, aviso, []))

    print(f"  con hermana que SI tiene PDF (duplicado por nombre, T2.28.17d, no se toca): {len(duplicadas_con_pdf)}")
    print(f"  sin ninguna hermana con PDF (genuinas, van al informe): {len(genuinas)}")

    generar_excel(filas, duplicadas_con_pdf, genuinas)
    print(f"\nEscrito: {DESTINO}")
    return 0


def generar_excel(filas_totales, duplicadas_con_pdf, genuinas) -> None:
    DESTINO.parent.mkdir(parents=True, exist_ok=True)
    wb = Workbook()

    # --- Hoja 1: resumen -----------------------------------------------------
    r = wb.active
    r.title = "Resumen"
    negrita = Font(bold=True)
    titulo = Font(bold=True, size=14)
    r["A1"] = "ARCHIVO — Órdenes sin PDF"
    r["A1"].font = titulo
    r["A2"] = f"Generado {datetime.now(timezone.utc).astimezone().strftime('%Y-%m-%d %H:%M')} · T2.28.17e"
    filas_resumen = [
        ("", ""),
        ("Filas de ot_archivo con en_servidor=0 al correr este informe", len(filas_totales)),
        ("— de ellas, con una hermana (mismo correlativo+aviso+zona) que SÍ tiene PDF", len(duplicadas_con_pdf)),
        ("  (son duplicados por nombre del local: R002/R002EC, JV071/V071EC. El", ""),
        ("  documento SÍ existe, indexado con el nombre canónico. Resolverlos del", ""),
        ("  todo —duplicado_de, esconderlos de ordenes.php— es T2.28.17d, fuera", ""),
        ("  de esta fase: requiere la puerta D8 y no se tocó una sola fila.)", ""),
        ("— sin ninguna hermana con PDF: genuinamente sin documento hoy", len(genuinas)),
        ("", ""),
        ("Dónde se buscó la hermana antes de declarar un caso genuino:", ""),
        ("  ot_archivo completa (cualquier origen: APP, CORREO o HISTORICO),", ""),
        ("  por la clave correlativo + aviso SAP + zona — no por el nombre del", ""),
        ("  local, que es justo lo que varía entre el correo (sin «EC») y el", ""),
        ("  maestro (con «EC»). Es una consulta SQL directa a la base real del", ""),
        ("  servidor (sql_remoto), no una búsqueda en disco: cualquier fila que", ""),
        ("  ya esté indexada con su PDF, sea cual sea su origen, aparece aquí.", ""),
        ("", ""),
        ("Nota sobre la cifra planificada: ESTADO.md §1s (medido 2026-09-22/23)", ""),
        ("hablaba de «97 duplicados + 11 por investigar». Hoy (2026-09-24), tras", ""),
        ("T2.28.17c (24 subidos) y el trabajo del robot entre ambas fechas, el", ""),
        ("reparto real es el de arriba: manda el dato de hoy sobre el plan de", ""),
        ("hace dos días (I-7 / criterio del plan: si un dato real contradice el", ""),
        ("plan, gana el dato).", ""),
    ]
    fila = 4
    for etiqueta, valor in filas_resumen:
        r.cell(row=fila, column=1, value=etiqueta)
        if valor != "":
            c = r.cell(row=fila, column=2, value=valor)
            c.font = negrita
        fila += 1
    r.column_dimensions["A"].width = 92
    r.column_dimensions["B"].width = 12

    # --- Hoja 2: las genuinas (una fila por orden, criterio del informe) -----
    g = wb.create_sheet("Sin documento")
    cab = ["id_industec", "origen", "zona", "local_codigo", "aviso",
           "Dónde se buscó", "Qué se encontró", "Qué se propone"]
    g.append(cab)
    for c in g[1]:
        c.font = negrita
        c.fill = PatternFill("solid", fgColor="DDDDDD")
    for id_industec, origen, zona, local_codigo, aviso, hermanas in genuinas:
        if hermanas:
            encontrado = ("Hay fila(s) hermana(s) por correlativo+aviso+zona, pero NINGUNA "
                          "tiene PDF en el servidor: " + "; ".join(h[0] for h in hermanas))
        elif not aviso:
            encontrado = "El id_industec no trae aviso SAP reconocible: no hay clave con qué buscar hermana."
        else:
            encontrado = "Sin ninguna fila hermana en ot_archivo (ningún origen) con ese aviso y zona."
        g.append([
            id_industec, origen, zona, local_codigo or "", aviso or "",
            "ot_archivo completa por correlativo+aviso+zona (SQL directo al servidor); "
            "no se buscó en disco (árbol canónico / _ORIGEN_BUZON / espejo / correo) en esta "
            "corrida porque la reconciliación en base ya resuelve el caso o lo deja genuino.",
            encontrado,
            "Confirmar contra el correo (INBOX/Trash/INFORMES OT) por el aviso, y contra el "
            "árbol canónico y el espejo de producción, si Andrés quiere seguir esta fila puntual.",
        ])
    anchos = [30, 10, 8, 14, 12, 60, 60, 60]
    for i, w in enumerate(anchos, start=1):
        g.column_dimensions[get_column_letter(i)].width = w
    for row in g.iter_rows(min_row=2):
        for c in row:
            c.alignment = Alignment(wrap_text=True, vertical="top")

    # --- Hoja 3: duplicadas por nombre, para trazabilidad (no se tocan) ------
    d = wb.create_sheet("Duplicadas (no tocadas)")
    d.append(["id_industec (sin PDF)", "origen", "zona", "local_codigo", "aviso",
              "hermana con el PDF (en_servidor=1)"])
    for c in d[1]:
        c.font = negrita
        c.fill = PatternFill("solid", fgColor="DDDDDD")
    for id_industec, origen, zona, local_codigo, aviso, hermana in duplicadas_con_pdf:
        d.append([id_industec, origen, zona, local_codigo or "", aviso or "", hermana])
    anchos2 = [30, 10, 8, 14, 12, 32]
    for i, w in enumerate(anchos2, start=1):
        d.column_dimensions[get_column_letter(i)].width = w

    wb.calculation.fullCalcOnLoad = True
    wb.save(DESTINO)


if __name__ == "__main__":
    sys.exit(main())
