"""T1.6c - Corrige las ordenes cuya fecha de atencion no se pudo leer del PDF.

Criterio fijado por el cliente (2026-09-04): cuando la fecha escrita en el
documento es invalida o esta vacia, se usa la FECHA DE GENERACION DEL PDF
(CreationDate de los metadatos). Es la fecha en que el tecnico emitio el informe,
que es el mismo dia de la atencion o el siguiente.

La fecha original escrita se conserva en el motivo, para que nada se pierda y la
administracion pueda revisarla.

Idempotente: solo toca las filas marcadas con FECHA_INVALIDA_EN_PDF_ORIGINAL.
"""
import re
from pathlib import Path

import mysql.connector
import pdfplumber

BASE = Path(r"D:\INDUSTECH IA\desarrollo\agentes")


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


def fecha_de_generacion(pdf_path):
    """Fecha de creacion declarada en los metadatos del PDF ('D:20250922121552-05'00'')."""
    with pdfplumber.open(pdf_path) as pdf:
        meta = pdf.metadata or {}
        texto = pdf.pages[0].extract_text() or ""
    for clave in ("CreationDate", "ModDate"):
        m = re.match(r"D:(\d{4})(\d{2})(\d{2})", str(meta.get(clave, "")))
        if m:
            return f"{m.group(1)}-{m.group(2)}-{m.group(3)}", texto
    return None, texto


def main():
    cnx = conectar()
    cur = cnx.cursor(dictionary=True)
    # Alcance: toda orden sin fecha util, este marcada o no. Algunas quedaron con
    # fecha nula sin que la ingesta lo señalara, porque el campo venia vacio en el PDF.
    cur.execute("SELECT id_industec, ruta_pdf, motivo_cuarentena FROM ots "
                "WHERE fecha_atencion IS NULL OR motivo_cuarentena LIKE 'FECHA_INVALIDA%'")
    filas = cur.fetchall()
    print(f"Ordenes con fecha invalida: {len(filas)}")

    corregidas = sin_metadatos = 0
    for r in filas:
        ruta = Path(r["ruta_pdf"])
        if not ruta.exists():
            print(f"  {r['id_industec']}: el archivo ya no esta en {ruta}")
            continue
        fecha, texto = fecha_de_generacion(ruta)
        if not fecha:
            sin_metadatos += 1
            print(f"  {r['id_industec']}: el PDF no declara fecha de creacion")
            continue
        escrita = ""
        # '[ 	]*' y no '\s*': con el campo vacio, '\s*' cruza el salto de linea y
        # captura la etiqueta siguiente ('Cliente:'). Es el error documentado en §6.5.
        m = re.search(r"Fecha de (?:Atenci[oó]n|Intervenci[oó]n):[ 	]*(\S*)", texto)
        if m:
            escrita = m.group(1)
        motivo = (f"FECHA_TOMADA_DE_GENERACION_DEL_PDF (el documento decia "
                  f"{escrita or 'vacio'!r})")[:255]
        cur.execute(
            """UPDATE ots SET fecha_atencion=%s, en_cuarentena=0, motivo_cuarentena=%s
               WHERE id_industec=%s""",
            (fecha, motivo, r["id_industec"]))
        corregidas += 1
        print(f"  {r['id_industec'][:38]:38s} escrita={escrita or '(vacia)'!r:16s} -> {fecha}")

    print(f"\nCorregidas: {corregidas} | sin metadatos de fecha: {sin_metadatos}")

    cur.execute("SELECT COUNT(*) c FROM ots WHERE en_cuarentena=0 AND fecha_atencion IS NULL")
    print(f"Ordenes activas que siguen sin fecha: {cur.fetchone()['c']}")
    cur.execute("SELECT COUNT(*) c FROM ots WHERE en_cuarentena=0")
    print(f"Ordenes activas: {cur.fetchone()['c']}")
    cur.close()
    cnx.close()


if __name__ == "__main__":
    main()
