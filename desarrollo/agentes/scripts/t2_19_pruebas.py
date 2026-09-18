"""
t2_19_pruebas.py - Pruebas sin red de t2_19_subir_pdfs.py: qué se sube y qué no.

Corren en cualquier equipo (no tocan Hostinger ni D:\\RESPALDOS): arman un árbol
sintético en una carpeta temporal y comprueban las reglas que deciden qué se
sube, qué se aparta y qué no se pisa. La prueba de punta a punta contra el
servidor (--destino-prueba) quedó en ESTADO.md con su salida literal.

Uso:  .venv/Scripts/python.exe scripts/t2_19_pruebas.py      (sale con 1 si algo falla)
"""
import os
import sys
import tempfile
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
sys.path.insert(0, str(Path(__file__).parent))
import t2_19_subir_pdfs as T  # noqa: E402

resultados: list[bool] = []


def anotar(que: str, ok: bool, obtenido="") -> None:
    resultados.append(bool(ok))
    print(f"  {'PASA ' if ok else 'FALLA'} {que:<72} {str(obtenido)[:60]}")


def escribir(base: Path, rel: str, datos: bytes) -> None:
    p = base / rel
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_bytes(datos)


with tempfile.TemporaryDirectory() as tmp:
    b = Path(tmp) / "ORDENES DE TRABAJO"
    A = b"%PDF A" + os.urandom(3000)
    C = b"%PDF C" + os.urandom(3000)
    escribir(b, "2026/CORRECTIVO/CNLJ/KFC/OT-2488-K061EC-10351229-CNLJ.pdf", A)
    escribir(b, "_DEL_BUZON/OT-2488-K061-10351229-CNLJ.pdf", A)           # el caso real de las capturas
    escribir(b, "2026/CORRECTIVO/UIO/KFC/OT-9902-K001EC-UIO.pdf", b"%PDF B1" + os.urandom(3000))
    escribir(b, "_DEL_BUZON/OT-9902-K001EC-UIO.pdf", b"%PDF B2" + os.urandom(3000))
    escribir(b, "2026/PREVENTIVO/LARB/KFC/OT-9903-K002EC-D2-LARB.pdf", C)
    escribir(b, "2026/PREVENTIVO/LARB/GUS/OT-9903-K002EC-D2-LARB.pdf", C)  # mismo contenido, otra carpeta
    escribir(b, "2026/CORRECTIVO/CNLJ/KFC/ot-9904-k003ec-cnlj.pdf", b"%PDF D" + os.urandom(3000))
    escribir(b, "2025/OTROS/CNLJ/CAJUN/OT-Cajun-10280653-CNLJ-023.pdf", b"x")
    escribir(b, "_cuarentena_hash/OT-9905-K001EC-UIO.pdf", b"x")
    escribir(b, "2026/CORRECTIVO/UIO/KFC/notas.txt", b"x")

    print("== inventario de la estación ==")
    subir, ap = T.inventario_local(b)
    fuera = ap["fuera"]
    anotar("el mismo informe con dos nombres (K061 y K061EC) sube los dos",
           {"OT-2488-K061EC-10351229-CNLJ.pdf", "OT-2488-K061-10351229-CNLJ.pdf"} <= set(subir))
    anotar("mismo nombre con otro contenido: colisión y no sube ninguno (I-11)",
           "OT-9902-K001EC-UIO.pdf" in ap["colisiones"] and "OT-9902-K001EC-UIO.pdf" not in subir)
    anotar("mismo nombre y mismo contenido en dos carpetas: sube una vez",
           "OT-9903-K002EC-D2-LARB.pdf" in subir and "OT-9903-K002EC-D2-LARB.pdf" not in ap["colisiones"])
    anotar("un nombre en minúsculas se sube en MAYÚSCULAS (lo que busca pdf.php)",
           "OT-9904-K003EC-CNLJ.pdf" in subir)
    anotar("el patrón con el correlativo al final no sube: se cuenta aparte",
           len(fuera.get("nombre que pdf.php no sirve", [])) == 1, fuera.get("nombre que pdf.php no sirve"))
    anotar("una carpeta apartada (_cuarentena_hash) no sube",
           len(fuera.get("carpeta apartada", [])) == 1)
    anotar("solo PDF: el .txt ni se sube ni se cuenta",
           all(n.endswith(".pdf") for n in subir) and sum(len(v) for v in fuera.values()) == 2)
    anotar("el total a subir es exactamente 4", len(subir) == 4, sorted(subir))

    print("== plan contra el servidor ==")
    n1, n2 = "OT-2488-K061EC-10351229-CNLJ.pdf", "OT-2488-K061-10351229-CNLJ.pdf"
    remoto = {
        n1: {"bytes": subir[n1]["bytes"], "sha": subir[n1]["sha"]},                       # igual por huella
        "OT-9903-K002EC-D2-LARB.pdf": {"bytes": 1, "sha": None},                           # otro tamaño, sin huella
        "OT-9904-K003EC-CNLJ.pdf": {"bytes": subir["OT-9904-K003EC-CNLJ.pdf"]["bytes"], "sha": "0" * 64},
    }
    faltan, iguales, div = T.planificar(subir, remoto)
    anotar("igual por huella: no se vuelve a subir", iguales == 1 and n1 not in faltan)
    anotar("otro tamaño y sin huella en el índice: divergente, no se pisa", "OT-9903-K002EC-D2-LARB.pdf" in div)
    anotar("otra huella en el índice: divergente, no se pisa", "OT-9904-K003EC-CNLJ.pdf" in div)
    anotar("lo que no está en el servidor es lo que falta", faltan == [n2], faltan)

    print("== lotes ==")
    grupos = list(T.lotes(sorted(subir), subir, 7000))
    anotar("ningún lote pasa del tamaño, salvo un archivo solo",
           all(sum(subir[n]["bytes"] for n in g) <= 7000 or len(g) == 1 for g in grupos), [len(g) for g in grupos])
    anotar("los lotes cubren todo, sin repetir",
           sorted(n for g in grupos for n in g) == sorted(subir))

    print("== verificación del servidor ==")
    salida = ("OT-1.pdf: FAILED\nOT-2.pdf: FAILED open or read\n"
              "sha256sum: WARNING: 2 computed checksums did NOT match\n")
    anotar("lee los FAILED de `sha256sum -c`", T.fallidos_de(salida) == ["OT-1.pdf", "OT-2.pdf"], T.fallidos_de(salida))
    anotar("una salida limpia no da fallidos", T.fallidos_de("") == [])

print(f"\n{sum(resultados)} de {len(resultados)} en verde")
sys.exit(0 if all(resultados) else 1)
