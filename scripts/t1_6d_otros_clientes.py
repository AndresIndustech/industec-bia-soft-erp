"""T1.6d - Traslada a su propia rama los trabajos que no son del Grupo KFC.

Decision del cliente (2026-09-04): los documentos cuyo cliente esta fuera del Grupo
KFC no pertenecen al arbol de ordenes de trabajo del contrato. Se archivan en
D:\\RESPALDOS\\OTROS CLIENTES\\{año}\\{CLIENTE}\\ , por cliente y año.

No es un descarte: son trabajos reales de INDUSTEC, solo que fuera del alcance del
contrato con KFC. Se conservan igual de ordenados y quedan en el manifiesto.

Copiar -> verificar por hash -> recien entonces retirar la copia de trabajo de la
bandeja de cuarentena. El original nunca se toca (I-2, I-3). Idempotente.
"""
import collections
import csv
import hashlib
import re
import shutil
import unicodedata
from pathlib import Path

BASE = Path(r"D:\INDUSTECH IA\agentes")
CALIDAD = Path(r"D:\INDUSTECH IA\SALIDAS IA\CALIDAD")
RESOLUCION = CALIDAD / "RESOLUCION_CUARENTENA.csv"
CUARENTENA = Path(r"D:\RESPALDOS\_CUARENTENA")
DESTINO = Path(r"D:\RESPALDOS\OTROS CLIENTES")

# Un mismo cliente aparece escrito de varias formas. La unificacion es explicita
# aqui, no se deduce por parecido: cada variante observada apunta a un nombre unico.
UNIFICAR = {
    "EL REY DE LAS MENESTRAS": "EL REY DE LAS MENESTRAS",
    "REY DE LAS MENESTRAS": "EL REY DE LAS MENESTRAS",
    "TABLITA DEL TARTARO": "TABLITA DEL TARTARO",
    "TABLITA DEL TARTARO ISLA FLOREANA": "TABLITA DEL TARTARO",
    "POLLO DE ALEX CENTRO HISTORICO": "POLLO DE ALEX",
    "TERMINAL DE CARCELEN": "RESTAURANTE ELVITA",
    "CESAR BASANTES": "HARRYS",
    "CAFE MARIA": "CAFE MARIA",
    "METALZA": "METALZA",
    "PABLO CADENA": "PABLO CADENA",
    "NOVO EVENTOS": "NOVO EVENTOS",
    "DORITANS": "DORITANS",
    "CASA RES": "CASA RES (SIN CODIGO)",
}


def sin_acentos(s):
    return "".join(c for c in unicodedata.normalize("NFD", s or "")
                   if unicodedata.category(c) != "Mn").upper().strip()


def nombre_cliente(texto):
    # Los apostrofos se ELIMINAN, no se cambian por espacio: "DORITAN’S" es un solo
    # nombre y sustituirlo daba la carpeta "DORITAN S", que parecia otro cliente.
    limpio = re.sub(r"['‘’ʼ`]", "", sin_acentos(texto))
    base = re.sub(r"[^A-Z0-9 ]", " ", limpio)
    base = re.sub(r"\s+", " ", base).strip()
    return UNIFICAR.get(base, base or "SIN CLIENTE")


def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for b in iter(lambda: fh.read(1 << 20), b""):
            h.update(b)
    return h.hexdigest()


def anio_de(ruta):
    m = re.search(r"[\\/](20\d{2})[\\/]", str(ruta))
    return m.group(1) if m else "SIN ANIO"


def main():
    filas = list(csv.DictReader(RESOLUCION.read_text(encoding="utf-8").splitlines()))
    otros = [f for f in filas if f["categoria"] == "OTRO_CLIENTE"]
    print(f"Documentos de clientes fuera del Grupo KFC: {len(otros)}")

    registro = []
    copiados = ya = errores = 0
    for f in otros:
        origen = None
        for cand in (CUARENTENA / f["nombre_original"],
                     CUARENTENA / "_RESUELTOS" / f["nombre_original"],
                     Path(f["ruta_original"])):
            if cand.exists():
                origen = cand
                break
        if origen is None:
            errores += 1
            registro.append({**f, "resultado": "ORIGEN_NO_ENCONTRADO", "ruta_destino": ""})
            continue

        cliente = nombre_cliente(f["pdf_cliente"] or f["pdf_local_texto"])
        carpeta = DESTINO / anio_de(f["ruta_original"]) / cliente
        destino = carpeta / f["nombre_original"]

        if destino.exists():
            if sha256(destino) == sha256(origen):
                ya += 1
                resultado = "YA_ESTABA"
            else:
                errores += 1
                registro.append({**f, "resultado": "COLISION_CONTENIDO_DISTINTO",
                                 "ruta_destino": str(destino)})
                continue
        else:
            carpeta.mkdir(parents=True, exist_ok=True)
            shutil.copy2(origen, destino)
            if sha256(destino) != sha256(origen):
                destino.unlink()
                errores += 1
                registro.append({**f, "resultado": "FALLO_HASH", "ruta_destino": ""})
                continue
            copiados += 1
            resultado = "COPIADO_Y_VERIFICADO"

        if origen.parent == CUARENTENA:
            movidos = CUARENTENA / "_OTROS_CLIENTES"
            movidos.mkdir(parents=True, exist_ok=True)
            shutil.move(str(origen), str(movidos / origen.name))
        registro.append({**f, "resultado": resultado, "ruta_destino": str(destino)})

    print(f"  copiados y verificados: {copiados}")
    print(f"  ya estaban (idempotencia): {ya}")
    print(f"  con problema: {errores}")
    print(f"\nPor cliente:")
    for cli, n in collections.Counter(
            nombre_cliente(r["pdf_cliente"] or r["pdf_local_texto"]) for r in registro).most_common():
        print(f"  {n:3d}  {cli}")
    print(f"\nPDFs en OTROS CLIENTES: {len(list(DESTINO.rglob('*.pdf')))}")

    salida = CALIDAD / "OTROS_CLIENTES.csv"
    campos = ["nombre_original", "pdf_cliente", "pdf_local_texto", "resultado",
              "ruta_destino", "ruta_original"]
    with salida.open("w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=campos, extrasaction="ignore")
        w.writeheader()
        w.writerows(registro)
    print(f"Detalle: {salida}")


if __name__ == "__main__":
    main()
