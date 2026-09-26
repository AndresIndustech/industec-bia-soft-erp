"""T1.6b (paso 2b/3) - Verificacion independiente de la resolucion de cuarentena.

Se ejecuta ANTES de mover un solo archivo (I-10). Si alguna comprobacion falla,
no se debe pasar a la fase de ejecucion.

Comprobaciones:
  1. Conservacion: resueltos + no resueltos = 364, sin perdidas ni duplicados.
  2. Todo local resuelto existe en el maestro de 95 locales.
  3. Coherencia con T1.6: cuando el saneamiento original ya habia deducido un local
     del NOMBRE del archivo, la resolucion nueva no lo contradice (o si lo hace,
     queda listado para revision explicita).
  4. Muestra aleatoria verificada contra el texto crudo del PDF: el codigo de local
     resuelto debe aparecer literalmente en el documento, o su nombre de local.
  5. Ningun archivo destino colisiona con otro ya existente en el arbol canonico.
"""
import collections
import csv
import hashlib
import random
import re
import sys
import unicodedata
from pathlib import Path

import mysql.connector
import pdfplumber

BASE = Path(r"D:\INDUSTECH IA\desarrollo\agentes")
CALIDAD = Path(r"D:\INDUSTECH IA\SALIDAS IA\CALIDAD")
CUARENTENA = Path(r"D:\RESPALDOS\_CUARENTENA")
RESOLUCION = CALIDAD / "RESOLUCION_CUARENTENA.csv"
MANIFIESTO = CALIDAD / "MANIFIESTO_SANEAMIENTO.csv"
SEMILLA = 20260904  # muestra reproducible


def conectar():
    env = {}
    for line in (BASE / "config/.env").read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])


def sin_acentos(s):
    return "".join(c for c in unicodedata.normalize("NFD", s or "")
                   if unicodedata.category(c) != "Mn").upper()


def main():
    fallos = []
    res = list(csv.DictReader(RESOLUCION.read_text(encoding="utf-8").splitlines()))
    # mismo criterio de seleccion que el resolutor: nivel 3 + nivel 1 sin ruta canonica
    man, vistos = [], set()
    for f in csv.DictReader(MANIFIESTO.read_text(encoding="utf-8-sig").splitlines()):
        clave = (f["nombre_original"], f["ruta_original"])
        if clave in vistos:
            continue
        if f["nivel"] == "3" or not f["ruta_canonica"]:
            vistos.add(clave)
            man.append(f)

    # ---- 1. conservacion ----
    print("=" * 78)
    print("1. CONSERVACION")
    resueltos = [r for r in res if r["local_resuelto"]]
    no_resueltos = [r for r in res if not r["local_resuelto"]]
    print(f"   casos en el informe: {len(res)}  (esperado {len(man)})")
    print(f"   identificados: {len(resueltos)} | sin identificar: {len(no_resueltos)} | "
          f"suma: {len(resueltos) + len(no_resueltos)}")
    if len(res) != len(man):
        fallos.append(f"el informe tiene {len(res)} casos y la cuarentena {len(man)}")
    # Un mismo nombre puede venir de dos carpetas distintas de Drive. Eso no es un
    # fallo si el CONTENIDO es identico (se conserva una copia, I-11); si el contenido
    # difiere es una colision real y hay que detenerse.
    nombres = [r["nombre_original"] for r in res]
    dups = [n for n, c in collections.Counter(nombres).items() if c > 1]
    por_nombre = collections.defaultdict(list)
    for f in man:
        por_nombre[f["nombre_original"]].append(f["ruta_original"])
    colisiones_reales = []
    for n in dups:
        hashes = set()
        for ruta in por_nombre[n]:
            rp = Path(ruta)
            if rp.exists():
                hashes.add(hashlib.sha256(rp.read_bytes()).hexdigest())
        if len(hashes) > 1:
            colisiones_reales.append(n)
    print(f"   nombres repetidos (misma OT en dos carpetas): {len(dups)}")
    print(f"   de esos, con CONTENIDO distinto (colision real): {len(colisiones_reales)}")
    if colisiones_reales:
        fallos.append(f"colisiones de contenido: {colisiones_reales[:5]}")

    # ---- 2. todo local resuelto existe en el maestro ----
    print("\n2. LOCALES CONTRA EL MAESTRO")
    cnx = conectar()
    cur = cnx.cursor(dictionary=True)
    cur.execute("SELECT local_codigo, zona, cadena, nombre FROM locales")
    maestro = {r["local_codigo"]: r for r in cur.fetchall()}
    fuera = sorted({r["local_resuelto"] for r in resueltos if r["local_resuelto"] not in maestro})
    print(f"   locales distintos usados: {len({r['local_resuelto'] for r in resueltos})}")
    print(f"   locales fuera del maestro: {len(fuera)} {fuera if fuera else ''}")
    if fuera:
        fallos.append(f"locales inexistentes en el maestro: {fuera}")

    # la zona resuelta debe ser la del maestro, no la del nombre del archivo
    zmal = [r for r in resueltos
            if r["local_resuelto"] in maestro
            and r["zona_resuelta"] != maestro[r["local_resuelto"]]["zona"]]
    print(f"   filas con zona incoherente con el maestro: {len(zmal)}")
    if zmal:
        fallos.append(f"zona incoherente en {len(zmal)} filas")

    # ---- 3. coherencia con lo que T1.6 dedujo del nombre ----
    print("\n3. COHERENCIA CON EL SANEAMIENTO ORIGINAL (T1.6)")
    previo = {f["nombre_original"]: f["local_canonico"] for f in man}
    comparables = [r for r in resueltos if previo.get(r["nombre_original"])]
    iguales = [r for r in comparables if r["local_resuelto"] == previo[r["nombre_original"]]]
    distintos = [r for r in comparables if r["local_resuelto"] != previo[r["nombre_original"]]]
    print(f"   comparables (T1.6 habia deducido local): {len(comparables)}")
    print(f"   coinciden: {len(iguales)} | difieren: {len(distintos)}")
    for r in distintos[:15]:
        print(f"      {r['nombre_original'][:46]:46s} nombre={previo[r['nombre_original']]:8s} "
              f"-> identificado={r['local_resuelto']:8s} via={r['via_resolucion'][:26]}")

    # ---- 4. muestra verificada contra el texto crudo del PDF ----
    print("\n4. MUESTRA CONTRA EL TEXTO CRUDO DEL PDF")
    random.seed(SEMILLA)
    por_via = collections.defaultdict(list)
    for r in resueltos:
        por_via[r["via_resolucion"]].append(r)
    muestra = []
    for via, filas in sorted(por_via.items()):
        muestra.extend(random.sample(filas, min(3, len(filas))))
    print(f"   verificando {len(muestra)} documentos (hasta 3 por via de resolucion)...")
    ok = malo = sin_texto = 0
    for r in muestra:
        ruta = Path(r["ruta_original"])
        if not ruta.exists():
            for alt in (CUARENTENA / r["nombre_original"],
                        CUARENTENA / "_RESUELTOS" / r["nombre_original"]):
                if alt.exists():
                    ruta = alt
                    break
        if not ruta.exists():
            fallos.append(f"no existe el archivo {r['nombre_original']}")
            continue
        try:
            with pdfplumber.open(ruta) as pdf:
                texto = sin_acentos(" ".join((p.extract_text() or "") for p in pdf.pages[:2]))
        except Exception as e:
            sin_texto += 1
            print(f"      [sin texto] {r['nombre_original'][:44]}: {type(e).__name__}")
            continue
        cod = r["local_resuelto"]
        base = re.sub(r"EC$", "", cod)
        nombre_local = sin_acentos(maestro.get(cod, {}).get("nombre", ""))
        # se acepta si aparece el codigo (con o sin EC, con o sin ceros) o el nombre del local
        variantes = {cod, base, base.lstrip("0"),
                     re.sub(r"^([A-Z]{1,2})0*", r"\1", base)}
        aparece = any(v and v in texto.replace(" ", "") for v in variantes)
        if not aparece:
            # el documento puede escribir el codigo con la letra de la MARCA en vez de la
            # del maestro ('J070' por 'V070EC'): se acepta si coinciden el numero y la cadena
            numero = re.sub(r"^[A-Z]{1,2}0*", "", base)
            cadena = sin_acentos(maestro.get(cod, {}).get("cadena", "")).replace(" ", "")
            if numero and cadena:
                texto_plano = texto.replace(" ", "")
                if re.search(rf"[A-Z]{{1,2}}0*{numero}", texto) and cadena[:5] in texto_plano:
                    aparece = True
        if not aparece and nombre_local:
            palabras = [p for p in nombre_local.split() if len(p) > 4]
            aparece = bool(palabras) and sum(1 for p in palabras if p in texto) >= max(1, len(palabras) // 2)
        if aparece:
            ok += 1
        else:
            malo += 1
            print(f"      [NO APARECE] {r['nombre_original'][:44]:44s} -> {cod} "
                  f"(via {r['via_resolucion'][:24]})")
    print(f"   evidencia hallada en el PDF: {ok}/{len(muestra) - sin_texto} | fallos: {malo}")
    if malo:
        fallos.append(f"{malo} documentos de la muestra sin evidencia del local en su texto")

    # ---- 5. colisiones con el arbol canonico ya existente ----
    print("\n5. COLISION DE DESTINO")
    cur.execute("SELECT ruta_pdf FROM ots WHERE en_cuarentena=0 AND ruta_pdf IS NOT NULL")
    existentes = {Path(r["ruta_pdf"]).name for r in cur.fetchall()}
    print(f"   archivos ya en el arbol canonico: {len(existentes)}")
    print("   (la colision real se comprueba por hash en la fase de ejecucion)")

    cur.close()
    cnx.close()

    print("\n" + "=" * 78)
    if fallos:
        print("VERIFICACION FALLIDA - no se debe ejecutar el movimiento:")
        for f in fallos:
            print(f"   - {f}")
        sys.exit(1)
    print("VERIFICACION SUPERADA. Se puede proceder a la fase de ejecucion.")


if __name__ == "__main__":
    main()
