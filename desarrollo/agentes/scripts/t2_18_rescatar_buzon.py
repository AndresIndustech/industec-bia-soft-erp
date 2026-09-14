"""
t2_18_rescatar_buzon.py - Los 164 PDF de `_DEL_BUZON` que ninguna ingesta veía.

POR QUE
Al correr T2.18 (2026-09-13) apareció `D:\\RESPALDOS\\ORDENES DE TRABAJO\\_DEL_BUZON\\`,
una carpeta de 164 PDF que no está documentada en ningún script (`comun.py` apunta a
`_ORIGEN_BUZON`, que no existe) y que ni `t1_7_ingesta.py` ni `t2_4_normalizar_nuevas.py`
recogen -- ambos ignoran toda carpeta que empiece por "_". Verificado uno por uno contra
la base (`ots`) por hash y por (correlativo, aviso):
  - 107 son duplicados exactos de un PDF que YA está en el árbol canónico y en la base.
  - 53 no existen en la base bajo ningún correlativo: nunca se clasificaron ni se
    ingestaron. Cuatro días sin entrar al sistema (fecha de archivo: 2026-09-09).
  - 4 tienen el mismo numero de aviso que un registro YA existente en la base, pero con
    OTRO correlativo -- conflicto real de numeracion, no se autorresuelve (I-2 nivel 3).

QUE HACE
Reutiliza EXACTAMENTE el criterio de resolucion de `t2_4_normalizar_nuevas.py`
(`resolver()`, que a su vez usa `normalizar_local_extendido` de T1.6b): local contra el
maestro y sus alias, zona del maestro (nunca del nombre), cruce con el centro de coste
del aviso SAP. No se duplica ese criterio aqui.

Ademas, antes de promover cualquier archivo, cruza el aviso contra `ots.correlativo`
(I-10): si el aviso ya existe en la base con un correlativo DISTINTO al del nombre del
archivo, NO se promueve -- se reporta como CONFLICTO_CORRELATIVO para que una persona
decida cual de los dos correlativos es el correcto.

NO BORRA NADA de `_DEL_BUZON` (I-2): ese es un paso aparte, manual, para cuando Andres
confirme que ya no hace falta la copia de respaldo. Solo promueve al arbol canonico lo
que resuelve sin ambiguedad y no colisiona con nada existente.

Uso (simula por defecto):
    .venv/Scripts/python.exe scripts/t2_18_rescatar_buzon.py
    .venv/Scripts/python.exe scripts/t2_18_rescatar_buzon.py --ejecutar

Despues de `--ejecutar`, correr `t1_7_ingesta.py` para que lo promovido entre a la base
(este script no toca la base: solo el arbol de archivos, igual que `t2_4_normalizar_nuevas.py`).
"""
from __future__ import annotations

import argparse
import csv
import re
import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from t2_4_normalizar_nuevas import resolver, cargar_maestro, conectar, sha256_de  # noqa: E402
from comun import RESPALDOS, SALIDAS, cargar_env  # noqa: E402

CANONICO = RESPALDOS / "ORDENES DE TRABAJO"
BUZON = CANONICO / "_DEL_BUZON"
INFORMES = SALIDAS

RE_ZONA = re.compile(r"-([A-Za-z]+)\.pdf$")


def modulo_dir_de(nombre: str) -> str | None:
    """El modulo_dir que espera resolver() (uio/larb/cnlj): lo saca de la zona
    que trae el propio nombre del archivo. Aqui NO hay carpeta de origen por
    zona (a diferencia del espejo de Hostinger), asi que es lo unico que hay."""
    m = RE_ZONA.search(nombre)
    if not m:
        return None
    zona = m.group(1).upper()
    return {"UIO": "uio", "LARB": "larb", "CNLJ": "cnlj"}.get(zona)


def main():
    ap = argparse.ArgumentParser(
        description="Rescata los PDF de _DEL_BUZON que nunca se clasificaron. Por defecto simula.")
    ap.add_argument("--ejecutar", action="store_true", help="copia de verdad")
    args = ap.parse_args()

    if not BUZON.is_dir():
        sys.exit(f"No existe {BUZON}")

    env = cargar_env(("DB_HOST", "DB_PORT", "DB_USER", "DB_PASSWORD", "DB_NAME"))
    cnx = conectar(env)
    maestro, canonicos, alias, sap = cargar_maestro(cnx)
    print(f"Maestro: {len(maestro)} locales | {len(alias)} alias | {len(sap)} avisos SAP")

    cur = cnx.cursor()
    cur.execute("SELECT aviso, correlativo FROM ots WHERE aviso IS NOT NULL")
    por_aviso: dict[str, set[int]] = {}
    for aviso, corr in cur.fetchall():
        por_aviso.setdefault(str(aviso), set()).add(corr)
    cur.close()

    archivos = sorted(p for p in BUZON.glob("*.pdf") if p.is_file())
    print(f"_DEL_BUZON: {len(archivos)} PDF\n")

    promovidos, ya_estaban, colisiones, conflictos, no_resueltos = [], [], [], [], []

    for p in archivos:
        modulo_dir = modulo_dir_de(p.name)
        if not modulo_dir:
            no_resueltos.append({"origen": p.name, "motivo": "no se pudo leer la zona del nombre"})
            continue

        anio = datetime.fromtimestamp(p.stat().st_mtime).year
        destino_rel, canon, nota = resolver(p.name, modulo_dir, canonicos, alias, sap, maestro, anio)
        if destino_rel is None:
            no_resueltos.append({"origen": p.name, "motivo": nota})
            continue

        m = re.match(r"^OT-(\d{4})-[^-]+-(\d{8})-", canon)
        corr_canon, aviso_canon = (int(m.group(1)), m.group(2)) if m else (None, None)

        if aviso_canon and aviso_canon in por_aviso and corr_canon not in por_aviso[aviso_canon]:
            conflictos.append({"origen": p.name, "canonico": canon,
                                "motivo": f"el aviso {aviso_canon} ya existe en la base con "
                                          f"correlativo {sorted(por_aviso[aviso_canon])}, "
                                          f"no {corr_canon}"})
            continue

        destino = CANONICO / destino_rel
        h_origen = sha256_de(p)

        if destino.exists():
            if sha256_de(destino) == h_origen:
                ya_estaban.append({"origen": p.name, "canonico": canon})
            else:
                colisiones.append({"origen": p.name, "canonico": canon,
                                    "motivo": "ya existe en el canonico con contenido distinto"})
            continue

        promovidos.append({"origen": p.name, "canonico": canon, "destino": str(destino_rel),
                            "regla": nota, "sha256": h_origen})
        if args.ejecutar:
            destino.parent.mkdir(parents=True, exist_ok=True)
            tmp = destino.with_suffix(".pdf.parcial")
            tmp.write_bytes(p.read_bytes())
            if sha256_de(tmp) != h_origen:
                tmp.unlink()
                promovidos.pop()
                no_resueltos.append({"origen": p.name, "motivo": "la copia no verifico por hash"})
                continue
            tmp.replace(destino)

    print(f"promovidos:            {len(promovidos)}")
    print(f"ya estaban (duplicado): {len(ya_estaban)}")
    print(f"colisiones:             {len(colisiones)}")
    print(f"conflicto correlativo:  {len(conflictos)}")
    print(f"no resueltos:           {len(no_resueltos)}")

    if conflictos:
        print("\nCONFLICTO_CORRELATIVO (requieren decision humana, NO promovidos):")
        for r in conflictos:
            print(f"  {r['origen']}: {r['motivo']}")

    if colisiones:
        print("\nCOLISION (requieren decision humana, NO sobreescritos):")
        for r in colisiones:
            print(f"  {r['origen']}: {r['motivo']}")

    if no_resueltos:
        print("\nNO_RESUELTO:")
        for r in no_resueltos:
            print(f"  {r['origen']}: {r['motivo']}")

    INFORMES.mkdir(parents=True, exist_ok=True)
    sello = datetime.now().strftime("%Y%m%dT%H%M%S")
    inf = INFORMES / f"RESCATE_DEL_BUZON_{sello}.csv"
    with open(inf, "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["estado", "archivo_origen", "nombre_canonico", "destino", "detalle"])
        for r in promovidos:
            w.writerow(["PROMOVIDO", r["origen"], r["canonico"], r["destino"], r["regla"]])
        for r in ya_estaban:
            w.writerow(["YA_ESTABA", r["origen"], r["canonico"], "", ""])
        for r in colisiones:
            w.writerow(["COLISION", r["origen"], r["canonico"], "", r["motivo"]])
        for r in conflictos:
            w.writerow(["CONFLICTO_CORRELATIVO", r["origen"], r["canonico"], "", r["motivo"]])
        for r in no_resueltos:
            w.writerow(["NO_RESUELTO", r["origen"], "", "", r["motivo"]])
    print(f"\nManifiesto: {inf}")

    if not args.ejecutar and promovidos:
        print("\nSimulacion: nada se copio. Repite con --ejecutar para escribir de verdad, "
              "y despues corre t1_7_ingesta.py para que entren a la base.")


if __name__ == "__main__":
    main()
