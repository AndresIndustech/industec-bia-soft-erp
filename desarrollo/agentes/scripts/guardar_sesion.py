# -*- coding: utf-8 -*-
"""
Red de seguridad del trabajo en curso: copia lo que git ve como modificado o sin
seguimiento a una carpeta fechada, y escribe un punto de retomada.

POR QUE EXISTE
Una conversación se puede cortar en seco -se agota el crédito, se cierra la
ventana, se cae el equipo- y lo que no está en git deja de existir para la
siguiente. Este script no decide nada ni commitea nada: solo deja una copia
fechada y un resumen de dónde se quedó todo, para que retomar no dependa de la
memoria de nadie.

NO TOCA GIT. No commitea, no hace stash, no cambia de rama, no borra. Solo lee
`git status` y copia archivos a `.respaldo_sesion\\`, que está fuera de git.

USO
    .venv\\Scripts\\python.exe scripts\\guardar_sesion.py
    .venv\\Scripts\\python.exe scripts\\guardar_sesion.py --nota "voy por la GUI"
"""
from __future__ import annotations

import argparse
import shutil
import subprocess
import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import RAIZ  # noqa: E402

DESTINO = RAIZ / ".respaldo_sesion"
# Más de esto y ya no es trabajo en curso: es un volcado de datos que no debe
# ir a una carpeta de respaldo que crece en cada turno.
MAX_BYTES = 8 * 1024 * 1024
SALTAR = {".respaldo_sesion", ".venv", "node_modules", "__pycache__"}
# Solo lo tocado hace poco. `git status` arrastra cosas que llevan días sin
# commitear a propósito -`desarrollo/sitio_web/` son 215 archivos y 22 MB-, y
# copiarlas en cada turno convierte la red de seguridad en un estorbo. Lo que
# hay que salvar es lo que se escribió en esta sesión.
HORAS_POR_DEFECTO = 12


def git(*args: str) -> str:
    """Devuelve stdout SIN recortar.

    Recortarlo aquí parecía inofensivo y se comía el espacio de la primera
    columna de `status --porcelain` en la primera línea: ` M ESTADO.md` se leía
    como `M ESTADO.md` y el archivo salía respaldado como `STADO.md`. En un
    script cuyo trabajo es no perder nada, un recorte silencioso es justo lo que
    no puede pasar.
    """
    r = subprocess.run(["git", *args], cwd=RAIZ, capture_output=True, text=True,
                       encoding="utf-8", errors="replace")
    return r.stdout or ""


def archivos_en_juego() -> list[tuple[str, str]]:
    """[(estado, ruta)] según git. `-uall` no: en repos grandes se come la RAM."""
    salida = []
    for linea in git("status", "--porcelain").splitlines():
        if len(linea) < 4:
            continue
        estado, ruta = linea[:2].strip() or "??", linea[3:].strip().strip('"')
        if " -> " in ruta:                      # renombrado: interesa el destino
            ruta = ruta.split(" -> ", 1)[1]
        if any(p in SALTAR for p in Path(ruta).parts):
            continue
        salida.append((estado, ruta))
    return salida


def main() -> int:
    ap = argparse.ArgumentParser(description="Guarda el trabajo en curso. No toca git.")
    ap.add_argument("--nota", default="", help="en qué se estaba trabajando")
    ap.add_argument("--horas", type=float, default=HORAS_POR_DEFECTO,
                    help="solo lo modificado en las últimas N horas (0 = todo)")
    ap.add_argument("--silencioso", action="store_true")
    args = ap.parse_args()

    if not (RAIZ / ".git").exists():
        print("No es un repositorio git; no hay nada que respaldar.")
        return 0

    sello = datetime.now().strftime("%Y%m%d-%H%M%S")
    carpeta = DESTINO / sello
    corte = datetime.now().timestamp() - args.horas * 3600 if args.horas else 0
    copiados, omitidos, viejos = [], [], 0

    def copiar(origen: Path) -> None:
        nonlocal viejos
        rel = origen.relative_to(RAIZ)
        try:
            st = origen.stat()
        except OSError as e:
            omitidos.append(f"{rel} ({type(e).__name__})")
            return
        if st.st_mtime < corte:
            viejos += 1
            return
        if st.st_size > MAX_BYTES:
            omitidos.append(f"{rel} (pesa demasiado)")
            return
        try:
            (carpeta / rel).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(origen, carpeta / rel)
            copiados.append(str(rel))
        except OSError as e:
            omitidos.append(f"{rel} ({type(e).__name__})")

    for _estado, ruta in archivos_en_juego():
        origen = RAIZ / ruta
        if origen.is_file():
            copiar(origen)
        elif origen.is_dir():                   # carpeta entera sin seguimiento
            try:
                for hijo in origen.rglob("*"):
                    if hijo.is_file():
                        copiar(hijo)
            except OSError as e:
                omitidos.append(f"{ruta} ({type(e).__name__})")

    rama = git("rev-parse", "--abbrev-ref", "HEAD").strip()
    ultimo = git("log", "-1", "--format=%h %s").strip()
    resumen = [
        f"# Punto de retomada · {datetime.now():%Y-%m-%d %H:%M:%S}",
        "",
        f"- Rama: `{rama}`",
        f"- Último commit: `{ultimo}`",
        f"- Archivos copiados: {len(copiados)}",
    ]
    if viejos:
        resumen.append(f"- Sin copiar por antigüedad (> {args.horas:g} h): {viejos}")
    if args.nota:
        resumen += ["", "## En qué se estaba", "", args.nota]
    if copiados:
        resumen += ["", "## Copiado aquí", ""] + [f"- `{x}`" for x in sorted(copiados)]
    if omitidos:
        resumen += ["", "## No se copió", ""] + [f"- {x}" for x in omitidos]
    resumen += ["", "## git status", "", "```",
                git("status", "--short").rstrip() or "(limpio)", "```"]

    if copiados or omitidos or args.nota:
        carpeta.mkdir(parents=True, exist_ok=True)
        (carpeta / "RETOMAR.md").write_text("\n".join(resumen) + "\n", encoding="utf-8")
        DESTINO.mkdir(parents=True, exist_ok=True)
        (DESTINO / "ULTIMO.md").write_text(
            "\n".join(resumen) + f"\n\nLa copia está en `.respaldo_sesion/{sello}/`.\n",
            encoding="utf-8")

    if not args.silencioso:
        print(f"Guardados {len(copiados)} archivos en .respaldo_sesion/{sello}/")
        if omitidos:
            print(f"  ({len(omitidos)} omitidos)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
