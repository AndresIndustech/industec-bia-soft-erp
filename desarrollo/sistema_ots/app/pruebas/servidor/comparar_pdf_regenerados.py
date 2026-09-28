"""comparar_pdf_regenerados.py — ¿El PDF regenerado dice lo mismo que el original,
salvo la franja y, donde se corrigió, la fecha de atención? (28-sep-2026)

Andrés pidió regenerar los 14 PDF del piloto sin la franja «DOCUMENTO DE PRUEBA»
como copia interna: mismo número, mismos datos, fotos y firma, y la fecha de
emisión original. Esta comparación es la prueba de «mismos datos»: extrae el
texto de las dos versiones con PyMuPDF, lo parte en palabras (así un salto de
línea o de página distinto no cuenta como diferencia) y falla si algo difiere
fuera de lo permitido:

  - la franja, que solo está en el original;
  - con --fecha OT=ANTES:NUEVA, la fecha de atención de esa OT, que pasa de
    ANTES a NUEVA en la línea «Fecha de Atención:»;
  - con --permitir "OT=TEXTO VIEJO::TEXTO NUEVO", un cambio que Andrés aceptó
    de antemano. El único conocido (simulacro del 28-sep-2026): la OT-9125 se
    emitió el 24-sep a las 10:15, antes de T2.28.2, e imprimía el buzón de zona
    de INDUSTEC como «Correo de Jefe de Operaciones Local»
    (jefezona-uio@industec.me), que D-G corrigió ese mismo día: regenerada dice
    «sin configurar», como toda OT emitida desde entonces.

Todo lo demás —incluida la línea «Documento generado automáticamente el …»,
que debe decir la emisión original— tiene que ser idéntico palabra por palabra.
Las fotos y la firma no son texto: se comparan contando las imágenes de cada
página.

Uso (desde el PC, con las dos carpetas ya bajadas):
    python comparar_pdf_regenerados.py <originales> <regenerados> \
        --fecha OT-9125-A010EC-10355442-UIO=2026-09-24:2026-09-18 ...
Sale con 1 si alguna difiere en algo no permitido o falta de un lado.
"""
import argparse
import difflib
import re
import sys
from pathlib import Path

import fitz  # PyMuPDF

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

# La franja, tal como la pintó plantilla_ot.php: con «OT INDUSTEC» desde el
# vocabulario del 26-sep-2026 y con «orden de trabajo» antes. Entre palabra y
# palabra, cualquier espacio: PyMuPDF corta la línea donde la cortó dompdf
# («…y no se» / «envió a nadie.»), y la primera versión, con espacios
# literales, no la reconoció en ninguno de los 14.
_S = r"\s+"
FRANJA = re.compile(
    _S.join(["DOCUMENTO", "DE", "PRUEBA", "[—–-]", "generado", "por", "el", "sistema", "en", r"pruebas\.",
             "No", "es", "una", r"(?:OT\s+INDUSTEC|orden\s+de\s+trabajo)", "válida", "y", "no", "se", "envió",
             "a", r"nadie\."]),
    re.IGNORECASE,
)


def texto(pdf: Path) -> tuple[str, int]:
    with fitz.open(pdf) as d:
        return "\n".join(p.get_text() for p in d), sum(len(p.get_images(full=True)) for p in d)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("originales")
    ap.add_argument("regenerados")
    ap.add_argument("--fecha", action="append", default=[], help="OT=AAAA-MM-DD:AAAA-MM-DD")
    ap.add_argument("--permitir", action="append", default=[], help="OT=TEXTO VIEJO::TEXTO NUEVO")
    a = ap.parse_args()
    fechas = {}
    for f in a.fecha:
        ot, _, par = f.partition("=")
        antes, _, nueva = par.partition(":")
        fechas[ot] = (antes, nueva)
    permitidos = {}
    for f in a.permitir:
        ot, _, par = f.partition("=")
        viejo, _, nuevo = par.partition("::")
        permitidos.setdefault(ot, []).append((" ".join(viejo.split()), " ".join(nuevo.split())))

    orig = {p.stem: p for p in Path(a.originales).glob("*.pdf")}
    nuev = {p.stem: p for p in Path(a.regenerados).glob("*.pdf")}
    malas = 0
    for ot in sorted(set(orig) | set(nuev)):
        if ot not in orig or ot not in nuev:
            print(f"{ot}: FALTA en {'los originales' if ot not in orig else 'los regenerados'}")
            malas += 1
            continue
        to, io = texto(orig[ot])
        tn, inn = texto(nuev[ot])
        tenia = bool(FRANJA.search(to))
        to_sin = FRANJA.sub(" ", to)
        problemas = []
        if FRANJA.search(tn) or "DOCUMENTO DE PRUEBA" in tn:
            problemas.append("el regenerado TODAVÍA trae la franja")
        po, pn = to_sin.split(), tn.split()
        cambio_fecha = False
        for op, i1, i2, j1, j2 in difflib.SequenceMatcher(a=po, b=pn, autojunk=False).get_opcodes():
            if op == "equal":
                continue
            antes_txt, nuevo_txt = " ".join(po[i1:i2]), " ".join(pn[j1:j2])
            contexto = " ".join(po[max(0, i1 - 4):i1])
            if (antes_txt, nuevo_txt) in permitidos.get(ot, []):
                print(f"{ot}: cambio aceptado de antemano: «{antes_txt}» → «{nuevo_txt}»")
                continue
            if ot in fechas and op == "replace" and antes_txt == fechas[ot][0] and nuevo_txt == fechas[ot][1] \
                    and contexto.endswith("Fecha de Atención:"):
                cambio_fecha = True
                continue
            problemas.append(f"{op}: «…{contexto} [{antes_txt}]» → «[{nuevo_txt}]»")
        if ot in fechas and not cambio_fecha:
            problemas.append(f"la fecha de atención no pasó de {fechas[ot][0]} a {fechas[ot][1]}")
        if io != inn:
            problemas.append(f"imágenes: {io} en el original, {inn} en el regenerado")
        m = re.search(r"generado automáticamente el (\S+ \S+)", tn)
        emision = m.group(1) if m else "—"
        if problemas:
            malas += 1
            print(f"{ot}: DIFIERE (franja en el original: {'sí' if tenia else 'no'}; emisión {emision})")
            for p in problemas:
                print(f"   ✗ {p}")
        else:
            extra = f"; fecha {fechas[ot][0]} → {fechas[ot][1]}" if ot in fechas else ""
            print(f"{ot}: igual salvo la franja{extra} · emisión {emision} · {inn} imágenes"
                  + ("" if tenia else "  (ojo: el original no traía la franja)"))
    print(f"\n{len(orig)} originales · {len(nuev)} regenerados · {malas} con diferencias no permitidas")
    return 1 if malas else 0


if __name__ == "__main__":
    sys.exit(main())
