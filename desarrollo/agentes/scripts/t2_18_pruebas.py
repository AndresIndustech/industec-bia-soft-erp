"""
t2_18_pruebas.py - Prueba de punta a punta de t2_18_clasificar_archivo.py,
sobre un árbol canónico sintético en una carpeta temporal (nunca toca
D:\\RESPALDOS ni SALIDAS IA reales).

Cubre exactamente los cinco riesgos que el diseño de la tarea T2.18 tenía que
resolver (ver PLAN_INDUSTEC.md):
  1. La simulación (sin --ejecutar) no escribe nada.
  2. --ejecutar copia y aplana año/módulo, dejando <ZONA>\\<CADENA>\\archivo.pdf.
  3. Ignora las carpetas que empiezan por '_' (T2.15.3: _ORIGEN_BUZON, etc.).
  4. Re-ejecutar es idempotente: nada se vuelve a copiar (0 copiados, todo "al día").
  5. Un nombre igual con contenido/tamaño distinto NUNCA se sobreescribe (I-3):
     se reporta como "divergente".
  6. Un archivo que el canónico ya no tiene se reporta como "huérfano" y
     NUNCA se borra solo (I-2).

Uso:
    .venv/Scripts/python.exe scripts/t2_18_pruebas.py
"""
from __future__ import annotations

import shutil
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
import t2_18_clasificar_archivo as m  # noqa: E402

fallos = 0


def _check(nombre: str, cond: bool, detalle: str = "") -> None:
    global fallos
    if cond:
        print(f"  OK  {nombre}")
    else:
        fallos += 1
        print(f"  MAL {nombre}  {detalle}")


def main() -> int:
    tmp = Path(tempfile.mkdtemp(prefix="t2_18_pruebas_"))
    try:
        raiz_repo = tmp / "REPO"
        canonico = tmp / "RESPALDOS" / "ORDENES DE TRABAJO"
        (canonico / "2026" / "CORRECTIVO" / "UIO" / "KFC").mkdir(parents=True)
        (canonico / "2025" / "PREVENTIVO" / "CNLJ" / "SIN CADENA").mkdir(parents=True)
        (canonico / "2026" / "CORRECTIVO" / "_ORIGEN_BUZON" / "KFC").mkdir(parents=True)
        (raiz_repo / "SALIDAS IA" / "OTS").mkdir(parents=True)

        f_uio = canonico / "2026" / "CORRECTIVO" / "UIO" / "KFC" / "OT-0001-K111-123456-UIO.pdf"
        f_cnlj = canonico / "2025" / "PREVENTIVO" / "CNLJ" / "SIN CADENA" / "OT-0002-X001-D1-CNLJ.pdf"
        f_oculto = canonico / "2026" / "CORRECTIVO" / "_ORIGEN_BUZON" / "KFC" / "OT-9999-K111-999-UIO.pdf"
        f_uio.write_text("contenido A")
        f_cnlj.write_text("contenido B")
        f_oculto.write_text("no debe copiarse nunca")

        m.RAIZ = raiz_repo
        m.CANONICO = canonico
        m.DESTINO = raiz_repo / "SALIDAS IA" / "ARCHIVO OTS INDUSTEC"
        m.MANIFIESTO = raiz_repo / "SALIDAS IA" / "OTS" / "clasificacion_archivo.json"

        print("1) simulacion no escribe nada")
        r = m.clasificar(ejecutar=False, verificar_todo=False, log=lambda *_: None)
        _check("cuenta 2 por copiar", r["copiados"] == 2, r)
        _check("no crea archivos", not list(m.DESTINO.rglob("*.pdf")) if m.DESTINO.exists() else True)

        print("2) --ejecutar copia y aplana zona/cadena")
        r = m.clasificar(ejecutar=True, verificar_todo=False, log=lambda *_: None)
        destino_uio = m.DESTINO / "UIO" / "KFC" / "OT-0001-K111-123456-UIO.pdf"
        destino_cnlj = m.DESTINO / "CNLJ" / "SIN CADENA" / "OT-0002-X001-D1-CNLJ.pdf"
        _check("copio los 2 esperados", r["copiados"] == 2, r)
        _check("UIO/KFC existe con el contenido correcto",
               destino_uio.is_file() and destino_uio.read_text() == "contenido A")
        _check("CNLJ/SIN CADENA existe", destino_cnlj.is_file())

        print("3) ignora carpetas que empiezan por '_'")
        _check("no copio nada de _ORIGEN_BUZON",
               not (m.DESTINO / "UIO" / "KFC" / "OT-9999-K111-999-UIO.pdf").exists())

        print("4) re-ejecutar es idempotente")
        r = m.clasificar(ejecutar=True, verificar_todo=False, log=lambda *_: None)
        _check("0 copiados, 2 al dia", r["copiados"] == 0 and r["al_dia"] == 2, r)

        print("5) divergencia: mismo nombre, tamano distinto -> no se sobreescribe")
        destino_uio.write_text("un contenido bastante mas largo que el original")
        r = m.clasificar(ejecutar=True, verificar_todo=False, log=lambda *_: None)
        _check("detecto exactamente 1 divergente", len(r["divergentes"]) == 1, r["divergentes"])
        _check("NO sobreescribio el divergente",
               destino_uio.read_text().startswith("un contenido bastante"))

        print("6) huerfano: el canonico ya no lo tiene -> se reporta, no se borra")
        f_uio.unlink()
        r = m.clasificar(ejecutar=False, verificar_todo=False, log=lambda *_: None)
        _check("reporto el huerfano", any("OT-0001" in h for h in r["huerfanos"]), r["huerfanos"])
        _check("no lo borro", destino_uio.exists())

    finally:
        shutil.rmtree(tmp, ignore_errors=True)

    print(f"\n{'TODO OK' if fallos == 0 else f'{fallos} FALLO(S)'}")
    return 1 if fallos else 0


if __name__ == "__main__":
    sys.exit(main())
