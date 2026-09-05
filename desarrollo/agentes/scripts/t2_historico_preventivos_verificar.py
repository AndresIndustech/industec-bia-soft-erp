"""
Verificacion cruzada del historico de preventivos (I-10).

Fuente independiente: `SEGUIMIENTO PREVENTIVOS _ INDUSTEC.xlsx`, el archivo que
la administracion lleva a mano. Su hoja 2026 escribe los ingresos en texto
("12 y 13 de febrero"); la reconstruccion del agente sale de las fechas de las
ordenes preventivas de la base. Que coincidan es la prueba de que la
agrupacion de ordenes en ingresos reproduce las visitas reales.

No se exige igualdad exacta de las cuatro fechas: el archivo de la
administracion mezcla lo PLANIFICADO del ano con lo EJECUTADO, y hay ingresos
todavia por venir. El criterio es que cada local que ella sigue tenga al menos
un ingreso ejecutado en la fecha que ella anoto.

Uso:
    .venv/Scripts/python.exe scripts/t2_historico_preventivos_verificar.py
"""
import datetime
import re
import sys
from pathlib import Path

import openpyxl

PLANTILLA_DIR = Path(r"D:\RESPALDOS\_ORIGEN_DRIVE\Oficina Industec\MTTO PREVENTIVO UIO  2026")
GENERADO = Path(r"D:\INDUSTECH IA\SALIDAS IA\MANTENIMIENTO\MANTENIMIENTOS PREVENTIVOS"
                r"\SEGUIMIENTO PREVENTIVOS (generado agente).xlsx")

MESES = {"ENERO": 1, "FEBRERO": 2, "MARZO": 3, "ABRIL": 4, "MAYO": 5, "JUNIO": 6,
         "JULIO": 7, "AGOSTO": 8, "SEPTIEMBRE": 9, "SEP": 9, "OCTUBRE": 10,
         "NOVIEMBRE": 11, "DICIEMBRE": 12}
COL_INGRESOS = range(5, 9)
TOLERANCIA_DIAS = 3


def fechas(valor):
    """(mes, dia) de una celda, venga como fecha o como '12 y 13 de febrero'."""
    if isinstance(valor, (datetime.datetime, datetime.date)):
        return {(valor.month, valor.day)}
    if not valor:
        return set()
    texto = str(valor).upper()
    nombre = next((k for k in MESES if k in texto), None)
    if not nombre:
        return set()
    dias = re.findall(r"\b(\d{1,2})\b", texto.split(nombre)[0])
    return {(MESES[nombre], int(d)) for d in dias}


def leer(ruta, hoja):
    wb = openpyxl.load_workbook(ruta, data_only=True)
    if hoja not in wb.sheetnames:
        sys.exit(f"ABORTA: {ruta.name} no tiene la hoja {hoja}")
    ws = wb[hoja]
    out = {}
    for r in range(3, ws.max_row + 1):
        local = ws.cell(r, 2).value
        if local:
            out[str(local).strip().upper()] = {d for c in COL_INGRESOS
                                               for d in fechas(ws.cell(r, c).value)}
    return out


def cobertura(ruta):
    """Primera fecha con datos en el corpus, leida de la hoja DETALLE OTS.

    Sin esto la hoja 2025 parece un desastre (3,2% de coincidencia) cuando en
    realidad las vueltas de enero y mayo de 2025 son ANTERIORES al corpus, que
    arranca el 18-sep-2025. Declarar la cobertura antes de juzgar es I-12: lo
    que cae fuera se reporta como fuera de cobertura, nunca como error.
    """
    ws = openpyxl.load_workbook(ruta, data_only=True)["DETALLE OTS"]
    fechas_det = [ws.cell(r, 6).value for r in range(2, ws.max_row + 1)]
    fechas_det = [f.date() if isinstance(f, datetime.datetime) else f
                  for f in fechas_det if f]
    return min(fechas_det)


def main():
    candidatos = sorted(PLANTILLA_DIR.glob("SEGUIMIENTO PREVENTIVOS*.xlsx"))
    if not candidatos:
        sys.exit(f"ABORTA: no aparece el archivo real en {PLANTILLA_DIR}")
    if not GENERADO.exists():
        sys.exit("ABORTA: falta el archivo generado; corre t2_historico_preventivos.py primero")

    desde = cobertura(GENERADO)
    print(f"Cobertura real del corpus de preventivos (I-12): desde {desde}")

    for hoja in ("2025", "2026"):
        reales, generadas = leer(candidatos[0], hoja), leer(GENERADO, hoja)
        anio = int(hoja)
        # Solo se juzgan los ingresos que caen dentro de la cobertura
        fuera = 0
        for loc, dias in list(reales.items()):
            dentro = {(m, d) for m, d in dias if datetime.date(anio, m, d) >= desde}
            fuera += len(dias) - len(dentro)
            reales[loc] = dentro
        print(f"Hoja {hoja}: {fuera} ingresos anotados por la administracion quedan "
              f"fuera de cobertura y no se juzgan")
        # Se compara a nivel de VISITA, con tolerancia de 3 dias: la
        # administracion anota el dia planificado del ingreso y la base tiene el
        # de la primera orden firmada, que cae un dia o dos despues. Exigir el
        # dia exacto marcaba como error 17 visitas que son la misma.
        def coincide(loc):
            return any(abs((datetime.date(anio, *r) - datetime.date(anio, *g)).days) <= TOLERANCIA_DIAS
                       for r in reales[loc] for g in generadas[loc])

        comunes = [loc for loc in set(reales) & set(generadas) if reales[loc]]
        coinciden = [loc for loc in comunes if coincide(loc)]
        otras = [loc for loc in comunes if generadas[loc] and not coincide(loc)]
        sin_dato = [loc for loc in comunes if not generadas[loc]]
        pct = len(coinciden) / len(comunes) * 100 if comunes else 0
        print(f"Hoja {hoja}: {len(comunes)} locales que la administracion sigue con fechas")
        print(f"  con al menos un ingreso coincidente: {len(coinciden)} ({pct:.1f}%)")
        print(f"  ejecutados en otras fechas: {len(otras)}")
        print(f"  sin ninguna orden preventiva en la base: {len(sin_dato)} {sorted(sin_dato)[:6]}")


if __name__ == "__main__":
    main()
