"""
t2_18_clasificar_archivo.py - El archivo de OT, otra vez visible por zona y franquicia.

POR QUE
Antes de automatizar, el archivo histórico se llevaba organizado a mano en
Google Drive, por zona y por cadena (KFC y las demás que atiende INDUSTEC).
Con el árbol canónico (T1.6/T2.4) esa organización sigue existiendo, pero
enterrada dentro de `D:\\RESPALDOS\\ORDENES DE TRABAJO\\<año>\\<módulo>\\<zona>\\
<cadena>\\`, que NO está sincronizado con Drive (I-2: el histórico completo no
cabe en el Drive de INDUSTEC, decisión del cliente del 2026-09-04) y que nadie
de administración abre a diario.

Pedido de Andrés del 2026-09-13: recuperar esa vista, sin volver a mantenerla a
mano, en `SALIDAS IA\\ARCHIVO OTS INDUSTEC\\<ZONA>\\<CADENA>\\`, que SÍ está
dentro de `SALIDAS IA` -- la carpeta que ya se sincroniza con Drive para la
administración (ver `SALIDAS IA\\LEEME.md`). Así el archivo clasificado queda
visible en Drive otra vez, como antes, pero generado por el mismo saneamiento
que ya corre en la estación en vez de a mano.

QUÉ HACE
Recorre `D:\\RESPALDOS\\ORDENES DE TRABAJO\\*\\*\\<ZONA>\\<CADENA>\\*.pdf` (el
árbol que ya clasificó `t2_4_normalizar_nuevas.py`) y espeja cada PDF a
`SALIDAS IA\\ARCHIVO OTS INDUSTEC\\<ZONA>\\<CADENA>\\<mismo nombre>.pdf`,
aplanando año y módulo -- Andrés pidió "por zona y por franquicia", dos
niveles, no cuatro. Si hacen falta también por año o por módulo, es un cambio
de una línea (`NIVELES` más abajo).

Es la MISMA operación tanto para "reclasificar el histórico ya reorganizado"
como para "los actuales que van llegando": no hay dos modos. Cada corrida
recorre todo el árbol canónico y copia lo que falte o cambió; lo que ya estaba
bien no se vuelve a tocar. Así que puede llamarse a mano una vez para el
histórico completo, y después en cada ronda del saneamiento nocturno (T2.15)
para lo que `t1_7_ingesta.py` + `t2_4_normalizar_nuevas.py` fueron sumando al
árbol canónico ese día -- sin distinguir "viejo" de "nuevo": el árbol canónico
ya es la fuente de verdad para los dos casos.

CÓMO DECIDE SI COPIAR (idempotente, I-4 adaptado: el origen es de solo lectura,
así que aquí no hay "mover", solo "copiar y no repetir el trabajo)
  - no existe en el destino               -> copia y verifica por hash
  - existe con el mismo tamaño            -> no se toca (hash solo con --verificar-todo)
  - existe con tamaño DISTINTO            -> NO se sobreescribe (I-3): va al manifiesto
                                              como "divergente" para que alguien mire
  - un nombre ya no está en ningún módulo/año de su zona/cadena en el árbol
    canónico (la reclasificó el maestro hacia otra cadena, por ejemplo)
                                           -> se reporta como "huérfano" en el
                                              manifiesto; NUNCA se borra sola (I-3)

Ignora cualquier carpeta que empiece por "_" (mismo criterio que T2.15.3:
`_ORIGEN_DRIVE`, `_ORIGEN_SISTEMA`, `_ORIGEN_BUZON`, `_divergentes`, etc.).

QUÉ PRODUCE
  SALIDAS IA\\ARCHIVO OTS INDUSTEC\\<ZONA>\\<CADENA>\\*.pdf   -- la vista clasificada
  SALIDAS IA\\OTS\\clasificacion_archivo.json                 -- manifiesto: copiados,
                                                                  ya al día, divergentes,
                                                                  huérfanos, por zona/cadena
  logs\\clasificar_archivo-<fecha>.log                        -- con --log

Uso (en la estación; simula por defecto, igual que la purga):
    .venv/Scripts/python.exe scripts/t2_18_clasificar_archivo.py
    .venv/Scripts/python.exe scripts/t2_18_clasificar_archivo.py --ejecutar
    .venv/Scripts/python.exe scripts/t2_18_clasificar_archivo.py --ejecutar --verificar-todo
    .venv/Scripts/python.exe scripts/t2_18_clasificar_archivo.py --ejecutar --log

NO TOCA LA BASE NI LA RED. Es una operación de archivos, punto: puede correr
en la estación o en cualquier copia que tenga `RESPALDOS_DIR` apuntando al
árbol canónico real. El enlace con el Archivo publicado en Hostinger (que las
tres zonas puedan VER estos PDF, no solo la estación) es el otro script de esta
misma tarea: `t2_18_atender_pedidos_copia.py`.
"""
from __future__ import annotations

import argparse
import json
import shutil
import sys
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import RAIZ, RESPALDOS, abrir_log, escribir_json_atomico, sha256_de  # noqa: E402

CANONICO = RESPALDOS / "ORDENES DE TRABAJO"
DESTINO = RAIZ / "SALIDAS IA" / "ARCHIVO OTS INDUSTEC"
MANIFIESTO = RAIZ / "SALIDAS IA" / "OTS" / "clasificacion_archivo.json"

# Cuántos niveles del nombre de carpeta usar en el destino, en orden, tal como
# aparecen en el árbol canónico (año, módulo, zona, cadena). Andrés pidió
# explícitamente "por zona y por franquicia" -- dos niveles. Si algún día hace
# falta separar también por año o por módulo, se agrega aquí sin tocar el resto
# del script.
NIVELES = ("zona", "cadena")


def _es_oculto(nombre: str) -> bool:
    """Las carpetas de cuarentena y los espejos de origen empiezan por '_'
    (T2.15.3); nunca son parte del archivo publicable."""
    return nombre.startswith("_")


def _hojas_pdf(raiz_canonico: Path):
    """Recorre <raiz>/<año>/<módulo>/<zona>/<cadena>/*.pdf y entrega
    (zona, cadena, ruta_pdf) para cada archivo. Cualquier nivel que empiece
    por '_' se salta completo (T2.15.3)."""
    if not raiz_canonico.is_dir():
        sys.exit(f"No existe {raiz_canonico}. ¿RESPALDOS_DIR apunta al lugar correcto?")
    for anio_dir in sorted(p for p in raiz_canonico.iterdir() if p.is_dir() and not _es_oculto(p.name)):
        for modulo_dir in sorted(p for p in anio_dir.iterdir() if p.is_dir() and not _es_oculto(p.name)):
            for zona_dir in sorted(p for p in modulo_dir.iterdir() if p.is_dir() and not _es_oculto(p.name)):
                for cadena_dir in sorted(p for p in zona_dir.iterdir() if p.is_dir() and not _es_oculto(p.name)):
                    for pdf in sorted(cadena_dir.glob("*.pdf")):
                        yield zona_dir.name, cadena_dir.name, pdf


def _copiar_verificado(origen: Path, destino: Path) -> None:
    """Copia a un temporal, verifica por hash contra el origen y recién
    entonces renombra al nombre final (I-4). Si algo falla a mitad de camino,
    el temporal queda huérfano y el archivo final nunca aparece a medias."""
    destino.parent.mkdir(parents=True, exist_ok=True)
    temporal = destino.with_name(destino.name + ".copiando")
    shutil.copy2(origen, temporal)
    if sha256_de(temporal) != sha256_de(origen):
        temporal.unlink(missing_ok=True)
        raise IOError(f"la copia de {origen.name} no coincide por hash con el origen")
    temporal.replace(destino)


def clasificar(ejecutar: bool, verificar_todo: bool, log=print) -> dict:
    resumen = {"copiados": 0, "al_dia": 0, "divergentes": [], "huerfanos": [], "por_zona_cadena": {}}
    vistos_en_destino: set[Path] = set()

    for zona, cadena, pdf in _hojas_pdf(CANONICO):
        carpeta = DESTINO
        for nivel, valor in zip(NIVELES, (zona, cadena)):
            carpeta = carpeta / valor
        objetivo = carpeta / pdf.name
        vistos_en_destino.add(objetivo)
        clave = f"{zona}/{cadena}"
        resumen["por_zona_cadena"].setdefault(clave, {"copiados": 0, "al_dia": 0})

        if objetivo.is_file():
            mismo_tamano = objetivo.stat().st_size == pdf.stat().st_size
            if mismo_tamano and not verificar_todo:
                resumen["al_dia"] += 1
                resumen["por_zona_cadena"][clave]["al_dia"] += 1
                continue
            if mismo_tamano and verificar_todo and sha256_de(objetivo) == sha256_de(pdf):
                resumen["al_dia"] += 1
                resumen["por_zona_cadena"][clave]["al_dia"] += 1
                continue
            # Mismo nombre, contenido distinto: NUNCA se sobreescribe (I-3).
            resumen["divergentes"].append(str(objetivo.relative_to(RAIZ)))
            log(f"DIVERGENTE, no se toca: {objetivo} (el canónico y el clasificado difieren)")
            continue

        if ejecutar:
            try:
                _copiar_verificado(pdf, objetivo)
            except (IOError, OSError) as e:
                log(f"ERROR copiando {pdf} -> {objetivo}: {e}")
                continue
        resumen["copiados"] += 1
        resumen["por_zona_cadena"][clave]["copiados"] += 1

    # Huerfanos: lo que hoy vive en el clasificado y ya no corresponde a ningun
    # PDF del arbol canonico (p. ej. un local que cambio de cadena en el
    # maestro). Se reporta, nunca se borra solo (I-2/I-3).
    if DESTINO.is_dir():
        for existente in DESTINO.rglob("*.pdf"):
            if existente not in vistos_en_destino:
                resumen["huerfanos"].append(str(existente.relative_to(RAIZ)))

    return resumen


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Espeja el árbol canónico de OT a SALIDAS IA/ARCHIVO OTS INDUSTEC, por zona y franquicia. Simula por defecto.")
    ap.add_argument("--ejecutar", action="store_true", help="copia de verdad; sin esto solo cuenta qué haría")
    ap.add_argument("--verificar-todo", action="store_true",
                     help="recalcula el hash incluso de lo que ya coincide en tamaño (más lento; úsalo si se sospecha corrupción)")
    ap.add_argument("--log", action="store_true", help="manda la salida a logs/ en vez de la consola")
    args = ap.parse_args()

    if args.log:
        ruta_log = abrir_log("clasificar_archivo")
        print(f"[{datetime.now(timezone.utc).isoformat(timespec='seconds')}] "
              f"clasificar_archivo --ejecutar={args.ejecutar} --verificar-todo={args.verificar_todo}")

    resumen = clasificar(args.ejecutar, args.verificar_todo, log=print)

    MANIFIESTO.parent.mkdir(parents=True, exist_ok=True)
    escribir_json_atomico(MANIFIESTO, json.dumps({
        "generado": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "modo": "ejecutado" if args.ejecutar else "simulacion",
        "origen": str(CANONICO),
        "destino": str(DESTINO),
        **resumen,
    }, ensure_ascii=False, indent=2))

    verbo = "copiados" if args.ejecutar else "por copiar"
    print(f"{resumen['copiados']} {verbo} · {resumen['al_dia']} ya al día · "
          f"{len(resumen['divergentes'])} divergentes · {len(resumen['huerfanos'])} huérfanos")
    for clave, c in sorted(resumen["por_zona_cadena"].items()):
        print(f"  {clave}: {c['copiados']} {verbo} · {c['al_dia']} al día")
    if resumen["divergentes"]:
        print("DIVERGENTES (revisar a mano, no se tocaron):")
        for d in resumen["divergentes"]:
            print(f"  - {d}")
    if resumen["huerfanos"]:
        print("HUÉRFANOS en el clasificado, sin PDF correspondiente hoy en el canónico (no se borraron):")
        for h in resumen["huerfanos"]:
            print(f"  - {h}")
    if not args.ejecutar:
        print("Simulación: nada se copió. Repite con --ejecutar para escribir de verdad.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
