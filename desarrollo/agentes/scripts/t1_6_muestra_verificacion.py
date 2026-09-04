"""
T1.6 - Verificacion final: muestra de 30 archivos renombrados cotejada
contra el contenido real del PDF (el nombre canonico debe decir la verdad).
"""
import random
import re
from pathlib import Path
import pdfplumber

random.seed(20260904)  # reproducible

RAIZ = Path(r"D:\RESPALDOS\ORDENES DE TRABAJO")

RE_NOMBRE = re.compile(r"^OT-(\d+)-([A-Z0-9]+)-(\d+)(?:-D(\d+))?-([A-Z]+)\.pdf$")


def main():
    pdfs = list(RAIZ.rglob("*.pdf"))
    print(f"Universo: {len(pdfs)} PDFs")
    muestra = random.sample(pdfs, 30)

    ok, mal, sin_texto = 0, 0, 0
    for p in muestra:
        m = RE_NOMBRE.match(p.name)
        if not m:
            print(f"NOMBRE NO PARSEABLE: {p.name}")
            mal += 1
            continue
        correlativo, local, aviso, dia, zona = m.groups()

        try:
            with pdfplumber.open(p) as pdf:
                texto = "\n".join((pg.extract_text() or "") for pg in pdf.pages)
        except Exception as e:
            print(f"ERROR AL LEER {p.name}: {e}")
            sin_texto += 1
            continue

        if not texto.strip():
            print(f"SIN TEXTO EXTRAIBLE: {p.name}")
            sin_texto += 1
            continue

        # El aviso SAP dentro del PDF aparece como "ID-ORDEN-GRUPOKFC: NNNNNNNN"
        m_aviso = re.search(r"ID-ORDEN-GRUPOKFC[:\s]*([0-9]+)", texto, re.IGNORECASE)
        aviso_en_pdf = m_aviso.group(1).lstrip("0") if m_aviso else None
        aviso_esperado = aviso.lstrip("0")

        coincide_aviso = (aviso_en_pdf == aviso_esperado) if aviso_en_pdf else None

        # El local no siempre aparece como codigo literal en el PDF (a veces
        # solo el nombre saneado de la carpeta local), asi que se reporta
        # como informativo, no como criterio de fallo.
        estado = "OK" if coincide_aviso else ("SIN_ETIQUETA_AVISO" if coincide_aviso is None else "DISCREPANCIA")
        print(f"[{estado}] {p.name}  aviso_pdf={aviso_en_pdf}  aviso_nombre={aviso_esperado}  zona_nombre={zona}")
        if estado == "OK":
            ok += 1
        elif estado == "DISCREPANCIA":
            mal += 1
        else:
            sin_texto += 1

    print(f"\n=== RESUMEN: {ok} OK / {mal} discrepancias / {sin_texto} sin etiqueta o sin texto / {len(muestra)} total ===")


if __name__ == "__main__":
    main()
