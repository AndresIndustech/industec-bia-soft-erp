# -*- coding: utf-8 -*-
"""
T2.24 - Promover a mano los documentos que solo el INTERIOR del PDF identifica.

POR QUE EXISTE, Y POR QUE NO ES UNA REGLA AUTOMATICA
De los 41 que la normalizacion del 2026-09-20 no pudo resolver, dos quedaron
sin destino porque el NOMBRE del archivo no alcanza para saber a donde van. Los
dos si se identifican abriendo el PDF, y Andres reviso cada uno:

  OT-1908-MENESTRASDELNEGRO-10342630-LARB.pdf
      El nombre trae la CADENA, no el local. Hay 8 locales de Menestras del
      Negro en el maestro, asi que el nombre por si solo es ambiguo. Pero
      dentro del PDF hay dos senales independientes que coinciden:
          cliente      : M036
          correo_local : m36@menestrasdelnegro.com.ec
      -> M036EC (MENESTRAS 36 RIO SHOPPING, LARB), que ademas coincide con la
         zona LARB del propio nombre.

  OT-1712-RestauranteElvita-0-UIO.pdf
      No es un local de KFC: llenaron el formulario de KFC por equivocacion.
      Lo delata el propio documento -- `correo_jefe_op` es
      gerenciageneral@industec.me y no una cuenta de kfc.com.ec, y el aviso
      viene en 0, o sea que no hay aviso de SAP. `cliente` dice "Terminal de
      Carcelen", que el diccionario del proyecto ya mapea a RESTAURANTE ELVITA.
      -> OTROS CLIENTES, que por convencion NO entra a la tabla `ots`.

NO SE CREA UN ALIAS PARA "MENESTRASDELNEGRO". Seria exactamente el falso
positivo contra el que avisa la skill `industec-archivos-canonicos`: el nombre
de una cadena NO identifica un local, y un alias asi mandaria a M036EC
cualquier documento futuro que diga "Menestras del Negro" sin importar de que
local sea. Este script resuelve ESTOS documentos con SU evidencia, uno por uno,
y no deja una regla suelta.

COPIAR -> VERIFICAR POR HASH -> NO BORRAR (I-2). El origen queda intacto en el
espejo. Cada movimiento deja fila en un manifiesto reversible (I-5).

USO (simula por defecto):
    .venv/Scripts/python.exe scripts/t2_24_promover_por_evidencia.py
    .venv/Scripts/python.exe scripts/t2_24_promover_por_evidencia.py --ejecutar
"""
from __future__ import annotations

import argparse
import csv
import shutil
import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import RESPALDOS, SALIDAS  # noqa: E402
from t1_7_extractor_pdf import extraer_pdf  # noqa: E402
from t2_4_normalizar_nuevas import sha256_de  # noqa: E402

ESPEJO = RESPALDOS / "_ORIGEN_SISTEMA"
CANONICO = RESPALDOS / "ORDENES DE TRABAJO"
OTROS = RESPALDOS / "OTROS CLIENTES"

# Cada caso trae la evidencia que lo justifica y los campos del PDF que hay que
# comprobar ANTES de copiar. Si el PDF no dice lo que aqui se afirma, no se
# mueve: el documento pudo cambiar, o alguien copio mal un nombre.
CASOS = [
    {
        "origen": ESPEJO / "larb" / "OT-1908-MENESTRASDELNEGRO-10342630-LARB.pdf",
        "destino": CANONICO / "2026" / "CORRECTIVO" / "LARB" / "MENESTRAS DEL NEGRO"
                   / "OT-1908-M036EC-10342630-LARB.pdf",
        "comprobar": {"cliente": "M036", "correo_local": "m36@menestrasdelnegro.com.ec"},
        "regla": "POR_EVIDENCIA_DEL_PDF: cliente=M036 y correo m36@ apuntan los dos a M036EC",
    },
    {
        "origen": ESPEJO / "uio" / "OT-1712-RestauranteElvita-0-UIO.pdf",
        "destino": OTROS / "2026" / "RESTAURANTE ELVITA"
                   / "OT-1712-RestauranteElvita-2026-08-18.pdf",
        "comprobar": {"cliente": "Terminal de Carcelén",
                      "correo_jefe_op": "gerenciageneral@industec.me"},
        "regla": "NO_ES_DE_KFC: el jefe de operacion es de INDUSTEC y no hay aviso SAP; "
                 "llenaron el formulario de KFC por equivocacion",
    },
]


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Promueve por evidencia del PDF los casos que el nombre no resuelve. "
                    "Por defecto simula.")
    ap.add_argument("--ejecutar", action="store_true", help="copia de verdad")
    args = ap.parse_args()

    hechos, frenados = [], []
    for c in CASOS:
        origen, destino = c["origen"], c["destino"]
        print(f"--- {origen.name}")
        if not origen.is_file():
            frenados.append((origen.name, "el origen ya no esta en el espejo"))
            print("    FRENADO: el origen ya no esta en el espejo")
            continue

        # La evidencia se vuelve a leer del documento, no se da por buena.
        d = extraer_pdf(str(origen))
        malas = {k: (d.get(k), v) for k, v in c["comprobar"].items()
                 if str(d.get(k) or "").strip().lower() != v.strip().lower()}
        if malas:
            frenados.append((origen.name, f"el PDF no dice lo esperado: {malas}"))
            print(f"    FRENADO: el PDF no dice lo esperado -> {malas}")
            continue
        print(f"    evidencia OK: {c['comprobar']}")

        h = sha256_de(origen)
        if destino.exists():
            if sha256_de(destino) == h:
                print(f"    ya estaba en {destino.relative_to(RESPALDOS)}")
                continue
            frenados.append((origen.name, f"el destino ya existe con OTRO contenido: {destino}"))
            print("    FRENADO: el destino ya existe con otro contenido")
            continue

        # Y el MISMO CONTENIDO bajo OTRO NOMBRE en la carpeta destino. Preguntar
        # solo por la ruta exacta no alcanza, y se vio en la primera corrida: el
        # Restaurante Elvita ya estaba archivado como
        # `OT-1712-RestauranteElvita-0-UIO.pdf` (venia del historico de Drive) y
        # esta promocion le agrego al lado una segunda copia identica con el
        # nombre que yo habia elegido. Mismo hash, mismo tamano, dos archivos.
        # El nombre de destino lo decide este script, asi que la unica forma de
        # no duplicar es preguntar por el CONTENIDO (I-11).
        if destino.parent.is_dir():
            gemelo = next((q for q in destino.parent.glob("*.pdf")
                           if q != destino and sha256_de(q) == h), None)
            if gemelo is not None:
                print(f"    ya estaba con otro nombre: {gemelo.name}  (no se duplica)")
                continue

        print(f"    -> {destino.relative_to(RESPALDOS)}")
        if args.ejecutar:
            destino.parent.mkdir(parents=True, exist_ok=True)
            tmp = destino.with_suffix(".pdf.parcial")
            shutil.copy2(origen, tmp)
            if sha256_de(tmp) != h:
                tmp.unlink()
                frenados.append((origen.name, "la copia no verifico por hash"))
                print("    FRENADO: la copia no verifico por hash")
                continue
            tmp.replace(destino)
            print("    copiado y verificado por hash")
        hechos.append({"origen": str(origen), "destino": str(destino),
                       "sha256": h, "regla": c["regla"]})

    SALIDAS.mkdir(parents=True, exist_ok=True)
    sello = datetime.now().strftime("%Y%m%dT%H%M%S")
    inf = SALIDAS / f"PROMOCION_POR_EVIDENCIA_{sello}.csv"
    with open(inf, "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["estado", "ruta_original", "ruta_final", "sha256", "regla_aplicada"])
        for r in hechos:
            w.writerow(["PROMOVIDO" if args.ejecutar else "SIMULADO",
                        r["origen"], r["destino"], r["sha256"], r["regla"]])
        for nombre, motivo in frenados:
            w.writerow(["FRENADO", nombre, "", "", motivo])

    print(f"\n{'promovidos' if args.ejecutar else 'promovibles'}: {len(hechos)} · frenados: {len(frenados)}")
    print(f"Manifiesto: {inf}")
    if not args.ejecutar and hechos:
        print("\nSimulacion: nada se copio. Repite con --ejecutar.")
    return 1 if frenados else 0


if __name__ == "__main__":
    sys.exit(main())
