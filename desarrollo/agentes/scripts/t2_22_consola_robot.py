# -*- coding: utf-8 -*-
"""
T2.22 - Consola del robot: que esta viendo, y desde hace cuanto.

POR QUE EXISTE
El 2026-09-20 se descubrio que el vigilante del buzon llevaba CINCO DIAS
corriendo con el codigo del 15 de septiembre: detectaba correos y empujaba
bien, pero la mitad nueva de su trabajo -bajar de produccion los informes que
el formulario acaba de generar- no se ejecutaba ni una vez, porque un proceso
de Python ya arrancado no recoge los cambios del .py. Nada avisaba. La Tarea
programada marcaba "En ejecucion" y el registro se veia sano.

Esa es la clase de fallo que esta consola existe para hacer imposible: no
muestra "el robot esta corriendo", muestra DESDE HACE CUANTO no pasa cada cosa
que tendria que estar pasando. Un robot que corre sin hacer su trabajo tiene
que verse tan mal como uno caido.

QUE MUESTRA
  1. El robot        - si el vigilante esta vivo, desde cuando, y cuando dio
                       su ultima senal de vida en el registro.
  2. SAP / KFC       - los requerimientos que entran por el buzon: casos
                       vigentes, reparto por zona, cuantos ya se atendieron.
  3. Yellow elephant - los informes que el formulario genera en produccion,
                       por zona, segun el espejo local. Con el nombre de los
                       que aparecen mientras la consola esta abierta.

SOLO LECTURA, SIN EXCEPCIONES. Lee archivos y consulta el listado de procesos.
No escribe, no borra, no toca la base, no toca el buzon y no se conecta a
Hostinger. Se puede abrir y cerrar cuantas veces se quiera sin consecuencias.

USO
    scripts\\consola.bat                       (lo normal: abre su ventana)
    .venv\\Scripts\\python.exe scripts\\t2_22_consola_robot.py
    .venv\\Scripts\\python.exe scripts\\t2_22_consola_robot.py --refresco 5
    .venv\\Scripts\\python.exe scripts\\t2_22_consola_robot.py --una-vez
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import BASE, RESPALDOS, SALIDAS  # noqa: E402

LOGS = BASE / "logs"
CATALOGOS = SALIDAS / "catalogos"
CASOS = CATALOGOS / "casos_sap.json"
ATENCIONES = CATALOGOS / "atenciones.json"
ESPEJO = RESPALDOS / "_ORIGEN_SISTEMA"
ESTADO_VIGILANTE = BASE / "config" / "vigilante_estado.json"

# Los cinco modulos del sistema viejo, con el nombre que entiende la gente.
# `otros` no es una zona: son trabajos fuera del contrato de KFC.
MODULOS = [("uio", "UIO"), ("larb", "LARB"), ("cnlj", "CNLJ"),
           ("mant", "PREVENTIVO"), ("otros", "OTROS CLIENTES")]

# El vigilante renueva su conexion IMAP cada 9 minutos y lo escribe. Si el
# registro lleva mas de eso sin una linea nueva, algo se trabo: no es un umbral
# inventado, es el propio ciclo del robot.
SILENCIO_SOSPECHOSO_MIN = 12
# El espejo de produccion se dispara con cada correo. En un dia habil entran
# varios por hora; medio dia sin espejar significa que ese paso no esta
# corriendo, aunque el robot parezca vivo.
ESPEJO_VIEJO_HORAS = 12

V, A, R, X, G, N = "\033[32m", "\033[33m", "\033[31m", "\033[2m", "\033[36m", "\033[0m"


def color(txt, c):
    return f"{c}{txt}{N}"


def limpiar():
    os.system("cls" if os.name == "nt" else "clear")


def hace_cuanto(cuando: datetime | None) -> str:
    """'hace 3 min'. Lo que importa no es la hora exacta, es la antiguedad."""
    if cuando is None:
        return "nunca"
    seg = (datetime.now() - cuando).total_seconds()
    if seg < 0:
        return "recien"
    if seg < 90:
        return f"hace {int(seg)} s"
    if seg < 5400:
        return f"hace {int(seg // 60)} min"
    if seg < 172800:
        return f"hace {seg / 3600:.1f} h"
    return f"hace {int(seg // 86400)} dias"


def leer_json(ruta: Path):
    """Un catalogo a medio escribir no tumba la consola: se reporta y ya."""
    try:
        return json.loads(ruta.read_text(encoding="utf-8")), None
    except FileNotFoundError:
        return None, "no existe todavia"
    except Exception as e:
        return None, f"ilegible ({type(e).__name__})"


def registro_vigilante() -> Path | None:
    """El registro MAS RECIENTE POR FECHA DE ESCRITURA, no por nombre.

    El nombre lo pone el script al arrancar, asi que un vigilante que lleva
    cinco dias vivo sigue escribiendo en `vigilante-2026-09-15.log`. Buscarlo
    por la fecha de hoy daria "no hay registro" justo cuando si lo hay.
    """
    archivos = sorted(LOGS.glob("vigilante-*.log"), key=lambda p: p.stat().st_mtime,
                      reverse=True)
    return archivos[0] if archivos else None


def ultimas_lineas(ruta: Path, n: int = 6) -> list[str]:
    try:
        with open(ruta, "rb") as f:
            f.seek(0, 2)
            fin = f.tell()
            bloque = min(fin, 16384)
            f.seek(fin - bloque)
            texto = f.read().decode("utf-8", "replace")
        return [x.rstrip() for x in texto.splitlines() if x.strip()][-n:]
    except Exception:
        return []


_cache_proc = {"cuando": 0.0, "datos": None}


def proceso_vigilante():
    """PID y hora de arranque del vigilante, si esta vivo.

    La hora de arranque es el dato clave: dice que version del codigo tiene
    cargada. Un vigilante arrancado antes del ultimo despliegue corre codigo
    viejo por mas sano que se vea.

    Se consulta cada 30 s, no en cada refresco: lanzar PowerShell es lo unico
    caro de esta consola.
    """
    if time.time() - _cache_proc["cuando"] < 30:
        return _cache_proc["datos"]
    datos = None
    try:
        ps = ("Get-CimInstance Win32_Process -Filter \"name like '%python%'\" | "
              "Where-Object { $_.CommandLine -like '*t2_9_buzon_vigilante*' } | "
              "Select-Object ProcessId,CreationDate | ConvertTo-Json -Compress")
        r = subprocess.run(["powershell", "-NoProfile", "-NonInteractive", "-Command", ps],
                           capture_output=True, text=True, timeout=25)
        salida = (r.stdout or "").strip()
        if salida:
            d = json.loads(salida)
            if isinstance(d, dict):
                d = [d]
            vivos = []
            for p in d:
                cd = str(p.get("CreationDate") or "")
                m = re.search(r"(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})", cd)
                if not m:
                    m2 = re.search(r"/Date\((\d+)", cd)
                    inicio = datetime.fromtimestamp(int(m2.group(1)) / 1000) if m2 else None
                else:
                    inicio = datetime(*[int(x) for x in m.groups()])
                vivos.append({"pid": p.get("ProcessId"), "inicio": inicio})
            datos = vivos or None
    except Exception:
        datos = None
    _cache_proc.update(cuando=time.time(), datos=datos)
    return datos


def inventario_espejo() -> dict:
    """Cuantos PDF hay por modulo en el espejo local de produccion."""
    out = {}
    for mod, _ in MODULOS:
        carpeta = ESPEJO / mod
        try:
            out[mod] = {p.name for p in carpeta.glob("*.pdf")}
        except Exception:
            out[mod] = set()
    return out


def fmt(n) -> str:
    return f"{n:,}".replace(",", ".")


def delta(n: int) -> str:
    if n == 0:
        return color("     ", X)
    return color(f" +{n:<3}", V)


def pintar(base_espejo: dict, arrancada: datetime, nuevos_vistos: list) -> None:
    ancho = 78
    limpiar()
    print(color("=" * ancho, G))
    print(color("  B.IA Soft ERP  ·  Consola del robot", G) +
          datetime.now().strftime("%Y-%m-%d %H:%M:%S").rjust(ancho - 38))
    print(color("=" * ancho, G))

    # ---------------------------------------------------------------- el robot
    print()
    print(color(" EL ROBOT", G))
    procs = proceso_vigilante()
    if procs is None:
        print("   Vigilante del buzon     " + color("● CAIDO", R) +
              "   ningun proceso corriendo")
    else:
        inicio = min((p["inicio"] for p in procs if p["inicio"]), default=None)
        pids = ", ".join(str(p["pid"]) for p in procs)
        print("   Vigilante del buzon     " + color("● vivo", V) +
              f"    PID {pids}, arrancado {hace_cuanto(inicio)}" +
              (f" ({inicio:%d/%m %H:%M})" if inicio else ""))

    reg = registro_vigilante()
    if reg is None:
        print("   Ultima senal            " + color("sin registro", R))
    else:
        visto = datetime.fromtimestamp(reg.stat().st_mtime)
        mins = (datetime.now() - visto).total_seconds() / 60
        etiqueta = hace_cuanto(visto)
        print("   Ultima senal            " +
              (color(etiqueta, R) + color("  <-- lleva callado demasiado", R)
               if mins > SILENCIO_SOSPECHOSO_MIN else color(etiqueta, V)) +
              color(f"   ({reg.name})", X))

    # ------------------------------------------------------- SAP / Grupo KFC
    print()
    print(color(" REQUERIMIENTOS DE SAP / GRUPO KFC", G) +
          color("   (lo que entra por el buzon)", X))
    cas, err = leer_json(CASOS)
    if cas is None:
        print("   " + color(f"catalogo de casos {err}", R))
    else:
        r = cas.get("resumen", {})
        gen = cas.get("generado", "?")
        try:
            cuando = datetime.strptime(gen, "%Y-%m-%d %H:%M")
        except Exception:
            cuando = None
        print(f"   Catalogo                {gen}   " + color(hace_cuanto(cuando), X))
        print(f"   Casos vigentes          {color(fmt(r.get('casos_vigentes', 0)), G)}")
        pz = r.get("por_zona", {})
        print("   por zona                " +
              "  ".join(f"{z} {fmt(n)}" for z, n in pz.items()))
        if r.get("ordenes_eliminadas"):
            print(f"   Ordenes eliminadas      {r['ordenes_eliminadas']}" +
                  color("  (KFC las retiro; excluidas)", X))

    at, err = leer_json(ATENCIONES)
    if at is None:
        print("   " + color(f"catalogo de atenciones {err}", R))
    else:
        ra = at.get("resumen", {})
        print(f"   Ya atendidos            {color(fmt(ra.get('con_atencion', 0)), V)}"
              f" de {fmt(ra.get('casos_pendientes', 0))}")
        print(f"     cerrados por INDUSTEC {fmt(ra.get('cerradas_por_industec', 0))}")
        print(f"     atendidos, en curso   {fmt(ra.get('en_curso', 0))}")
        print(f"   Sin atender todavia     {color(fmt(ra.get('sin_atencion', 0)), A)}")
        if ra.get("informes_no_parseados"):
            print("   " + color(f"informes que no se pudieron leer: "
                                 f"{ra['informes_no_parseados']}", R))

    # ---------------------------------------------- informes de yellow elephant
    print()
    print(color(" INFORMES NUEVOS EN YELLOW ELEPHANT", G) +
          color("   (lo que genera el formulario)", X))
    est, _ = leer_json(ESTADO_VIGILANTE)
    ultimo = None
    if est and est.get("ultimo_espejo_utc"):
        try:
            ultimo = datetime.fromisoformat(est["ultimo_espejo_utc"]).astimezone().replace(tzinfo=None)
        except Exception:
            ultimo = None
    if ultimo is None:
        print("   Ultimo espejo           " + color("NUNCA", R) +
              color("   <-- el robot todavia no baja de produccion", R))
    else:
        viejo = (datetime.now() - ultimo).total_seconds() / 3600 > ESPEJO_VIEJO_HORAS
        print("   Ultimo espejo           " +
              color(hace_cuanto(ultimo), R if viejo else V) +
              (color("   <-- hace demasiado", R) if viejo else ""))

    ahora = inventario_espejo()
    total_now = total_base = 0
    for mod, etiqueta in MODULOS:
        n = len(ahora.get(mod, set()))
        nuevos = ahora.get(mod, set()) - base_espejo.get(mod, set())
        total_now += n
        total_base += len(base_espejo.get(mod, set()))
        print(f"   {etiqueta:<22}  {fmt(n):>6} PDF" + delta(len(nuevos)))
        for nom in sorted(nuevos):
            if nom not in nuevos_vistos:
                nuevos_vistos.append(nom)
    print("   " + "-" * 40)
    print(f"   {'TOTAL':<22}  {color(fmt(total_now), G):>6} PDF" +
          delta(total_now - total_base))

    if nuevos_vistos:
        print()
        print(color(" APARECIDOS DESDE QUE ABRIO ESTA CONSOLA", V) +
              color(f"   ({arrancada:%H:%M})", X))
        for nom in nuevos_vistos[-8:]:
            print("   " + color(nom, V))

    # ------------------------------------------------------- ultimas lineas
    if reg is not None:
        print()
        print(color(" ULTIMO QUE DIJO EL ROBOT", G))
        for linea in ultimas_lineas(reg, 6):
            print(color("   " + linea[:ancho - 4], X))

    print()
    print(color(" Ctrl+C para salir.  Esta consola solo mira: no escribe nada.", X))


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Consola de estado del robot. Solo lectura.")
    ap.add_argument("--refresco", type=int, default=10, metavar="SEG",
                    help="cada cuantos segundos se redibuja (por omision 10)")
    ap.add_argument("--una-vez", action="store_true",
                    help="pinta una sola vez y sale (para revisar de pasada)")
    args = ap.parse_args()

    if os.name == "nt":
        os.system("")          # habilita los colores ANSI en la consola de Windows

    base_espejo = inventario_espejo()
    arrancada = datetime.now()
    nuevos_vistos: list = []

    if args.una_vez:
        pintar(base_espejo, arrancada, nuevos_vistos)
        return 0

    try:
        while True:
            pintar(base_espejo, arrancada, nuevos_vistos)
            time.sleep(max(2, args.refresco))
    except KeyboardInterrupt:
        print("\n\n  Consola cerrada. El robot sigue corriendo por su cuenta.\n")
        return 0


if __name__ == "__main__":
    sys.exit(main())
