"""T1.6b (paso 3/3) - Ejecucion: promueve los casos resueltos de cuarentena al
arbol canonico, actualiza la base y deja el manifiesto para la administracion.

Se ejecuta SOLO despues de que t1_6b_verificar_resolucion.py pase sin fallos.

Reglas de seguridad (invariantes del plan):
  - Copiar -> verificar por hash -> recien entonces mover la copia de trabajo a
    _CUARENTENA\\_RESUELTOS. Nunca se borra nada: el original sigue intacto en
    D:\\RESPALDOS\\_ORIGEN_DRIVE (I-2, I-3).
  - Si el destino ya existe: se compara por hash. Identico -> ya estaba, no se toca.
    Distinto -> NO se sobrescribe, se registra como colision para revision (I-11).
  - Idempotente: correrlo dos veces deja el mismo resultado que correrlo una vez.
  - Todo renombrado queda registrado con nombre y ruta original (I-5).

Nomenclatura aplicada (§6.1 del plan, con la correccion de preventivos):
  Correctivo:            OT-{corr}-{LOCAL}-{AVISO}-{ZONA}.pdf
  Preventivo con aviso:  OT-{corr}-{LOCAL}-{AVISO}-D{n}-{ZONA}.pdf
  Preventivo sin aviso:  OT-{corr}-{LOCAL}-D{n}-{ZONA}.pdf
      El preventivo no nace de un aviso SAP: exigirle uno fue el error de diseño
      que mando 185 documentos correctos a cuarentena.
"""
import collections
import csv
import hashlib
import re
import shutil
import sys
from pathlib import Path

import mysql.connector
import openpyxl
from openpyxl.styles import Font, PatternFill

BASE = Path(r"D:\INDUSTECH IA\agentes")
CALIDAD = Path(r"D:\INDUSTECH IA\SALIDAS IA\CALIDAD")
CUARENTENA = Path(r"D:\RESPALDOS\_CUARENTENA")
RESUELTOS = CUARENTENA / "_RESUELTOS"
ARBOL = Path(r"D:\RESPALDOS\ORDENES DE TRABAJO")
INFORMES = Path(r"D:\RESPALDOS\INFORMES TECNICOS")
OTROS_CLIENTES = Path(r"D:\RESPALDOS\OTROS CLIENTES")
RESOLUCION = CALIDAD / "RESOLUCION_CUARENTENA.csv"
MANIFIESTO_SALIDA = CALIDAD / "MANIFIESTO_CUARENTENA_RESUELTA.xlsx"

_cache_hash = {}


def sha256(path):
    p = str(path)
    if p not in _cache_hash:
        h = hashlib.sha256()
        with open(path, "rb") as fh:
            for bloque in iter(lambda: fh.read(1024 * 1024), b""):
                h.update(bloque)
        _cache_hash[p] = h.hexdigest()
    return _cache_hash[p]


def conectar():
    env = {}
    for line in (BASE / "config/.env").read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    # client_flags con FOUND_ROWS: rowcount debe contar las filas ENCONTRADAS, no solo
    # las modificadas. Sin esto, una segunda corrida idempotente reporta 0 y parece que
    # los documentos no existen en la tabla, cuando ya estaban correctos.
    from mysql.connector.constants import ClientFlag
    return mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"], autocommit=True,
        client_flags=[ClientFlag.FOUND_ROWS])


def anio_de(fila):
    """Año del documento: el de la ruta original, que es como lo archivo la empresa."""
    m = re.search(r"\\(20\d{2})\\", fila["ruta_original"])
    if m:
        return m.group(1)
    m = re.search(r"(20\d{2})", fila["ruta_original"])
    return m.group(1) if m else "2026"


def dia_preventivo(nombre):
    """Extrae el numero de dia de un preventivo: 'Dia 2' / 'D2' / 'Dia Lunes' -> '2'."""
    m = re.search(r"D[ií]a[\s_]*(\d+)", nombre, re.IGNORECASE)
    if m:
        return m.group(1)
    m = re.search(r"-D(\d+)-", nombre, re.IGNORECASE)
    if m:
        return m.group(1)
    m = re.search(r"D[ií]a[\s_]*([A-Za-zÁÉÍÓÚáéíóú]+)", nombre, re.IGNORECASE)
    if m:
        dias = {"LUNES": "1", "MARTES": "2", "MIERCOLES": "3", "JUEVES": "4",
                "VIERNES": "5", "SABADO": "6", "DOMINGO": "7"}
        clave = m.group(1).upper().replace("É", "E").replace("Á", "A")
        return dias.get(clave, "")
    return ""


def correlativo_de(fila):
    """El correlativo del manifiesto, y si no lo trae, el del propio nombre original.

    Sin este respaldo los documentos cuyo patron T1.6 no reconocio se nombraban todos
    'OT-0000-...' y colisionaban entre si en el destino.
    """
    if fila["correlativo"]:
        return fila["correlativo"].zfill(4)
    nombre = fila["nombre_original"]
    m = re.match(r"^OT[\-_\s]*(\d{1,4})(?:\D|$)", nombre, re.IGNORECASE)
    if m:
        return m.group(1).zfill(4)
    # segundo patron de la empresa, con el correlativo AL FINAL:
    # 'OT-Cajun-10280653-CNLJ-023.pdf' / 'OT-IND-CNLJ-014.pdf'
    m = re.search(r"-(\d{1,4})\.pdf$", nombre, re.IGNORECASE)
    return m.group(1).zfill(4) if m else ""


def es_preventivo(fila):
    """El tipo del manifiesto, corregido por la evidencia del nombre: un documento con
    'Dia N' es un preventivo aunque T1.6 no haya reconocido su patron."""
    if fila["tipo"] == "PREVENTIVO":
        return True
    return bool(re.search(r"D[ií]a[\s_]*\w+|-D\d+-", fila["nombre_original"], re.IGNORECASE))


def nombre_canonico(fila, modulo):
    corr = correlativo_de(fila)
    local = fila["local_resuelto"]
    aviso = fila["aviso"]
    zona = fila["zona_resuelta"] or fila["zona_nombre"]
    partes = ["OT", corr, local] if corr else ["OT", local]
    if aviso:
        partes.append(aviso.zfill(8) if len(aviso) <= 8 else aviso)
    if modulo == "PREVENTIVO":
        dia = dia_preventivo(fila["nombre_original"])
        if dia:
            partes.append(f"D{dia}")
    partes.append(zona)
    return "-".join(partes) + ".pdf"


def main():
    filas = list(csv.DictReader(RESOLUCION.read_text(encoding="utf-8").splitlines()))
    resueltos = [f for f in filas if f["local_resuelto"]]
    pendientes = [f for f in filas if not f["local_resuelto"]]
    print(f"Casos resueltos a promover: {len(resueltos)} | sin resolver: {len(pendientes)}")

    cnx = conectar()
    cur = cnx.cursor(dictionary=True)
    cur.execute("SELECT local_codigo, cadena, zona FROM locales")
    maestro = {r["local_codigo"]: r for r in cur.fetchall()}

    # --- de-duplicacion por contenido antes de copiar (I-11) ---
    por_nombre = collections.defaultdict(list)
    for f in resueltos:
        por_nombre[f["nombre_original"]].append(f)
    descartados = set()
    for nombre, grupo in por_nombre.items():
        if len(grupo) < 2:
            continue
        hashes = {}
        for f in grupo:
            rp = Path(f["ruta_original"])
            if rp.exists():
                hashes.setdefault(sha256(rp), []).append(f)
        if len(hashes) == 1:
            for f in grupo[1:]:
                descartados.add(id(f))
        else:
            for f in grupo:
                descartados.add(id(f))
            print(f"  COLISION REAL (contenido distinto) en {nombre}: todo el grupo queda pendiente")
    print(f"Duplicados de contenido identico descartados (se conserva una copia): {len(descartados)}")

    RESUELTOS.mkdir(parents=True, exist_ok=True)
    registro = []
    copiados = ya_estaban = colisiones = sin_origen = 0

    for f in resueltos:
        if id(f) in descartados:
            registro.append({**f, "resultado": "DESCARTADO_DUPLICADO_CONTENIDO_IDENTICO",
                             "ruta_canonica": "", "nombre_canonico": ""})
            continue
        # Orden de busqueda: la bandeja de cuarentena, luego lo ya promovido, y por
        # ultimo la ruta original en _ORIGEN_DRIVE, que nunca se mueve. Los documentos
        # de nivel 1 sin ruta canonica no pasaron por la bandeja y solo existen alli.
        origen = None
        for candidata in (CUARENTENA / f["nombre_original"],
                          RESUELTOS / f["nombre_original"],
                          Path(f["ruta_original"])):
            if candidata.exists():
                origen = candidata
                break
        if origen is None:
            sin_origen += 1
            registro.append({**f, "resultado": "ORIGEN_NO_ENCONTRADO",
                             "ruta_canonica": "", "nombre_canonico": ""})
            continue

        modulo = "PREVENTIVO" if es_preventivo(f) else "CORRECTIVO"
        local = f["local_resuelto"]
        cadena = maestro[local]["cadena"]
        zona = maestro[local]["zona"]
        nombre_nuevo = nombre_canonico({**f, "zona_resuelta": zona}, modulo)
        # Un documento sin correlativo no es una orden de trabajo: son los informes
        # tecnicos sueltos ('INFORME TECNICO K124 MAQUINA DE HIELO.pdf'). Se archivan
        # por local, pero en su propia rama y fuera de la tabla ots.
        if not correlativo_de(f):
            destino_dir = INFORMES / anio_de(f) / zona / cadena
        else:
            destino_dir = ARBOL / anio_de(f) / modulo / zona / cadena
        destino = destino_dir / nombre_nuevo

        if destino.exists():
            if sha256(destino) == sha256(origen):
                ya_estaban += 1
                resultado = "YA_ESTABA_EN_DESTINO"
            else:
                colisiones += 1
                resultado = "COLISION_DESTINO_OCUPADO_OTRO_CONTENIDO"
                registro.append({**f, "resultado": resultado,
                                 "ruta_canonica": str(destino), "nombre_canonico": nombre_nuevo})
                continue
        else:
            destino_dir.mkdir(parents=True, exist_ok=True)
            shutil.copy2(origen, destino)
            if sha256(destino) != sha256(origen):
                destino.unlink()
                registro.append({**f, "resultado": "FALLO_VERIFICACION_HASH",
                                 "ruta_canonica": "", "nombre_canonico": ""})
                print(f"  ERROR de hash al copiar {f['nombre_original']}: se elimino la copia")
                continue
            copiados += 1
            resultado = "COPIADO_Y_VERIFICADO"

        # la copia de trabajo sale de la bandeja de cuarentena (el original sigue en _ORIGEN_DRIVE)
        if origen.parent == CUARENTENA:
            shutil.move(str(origen), str(RESUELTOS / origen.name))
        registro.append({**f, "resultado": resultado,
                         "ruta_canonica": str(destino), "nombre_canonico": nombre_nuevo})

    print(f"\nCopiados y verificados por hash: {copiados}")
    print(f"Ya estaban en destino (idempotencia): {ya_estaban}")
    print(f"Colisiones de destino no resueltas: {colisiones}")
    print(f"Sin archivo de origen: {sin_origen}")

    # ---------- actualizacion de la base ----------
    print("\nActualizando la base de datos...")
    actualizados = no_encontrados = supersedidas = 0
    for r in registro:
        if r["resultado"] not in ("COPIADO_Y_VERIFICADO", "YA_ESTABA_EN_DESTINO"):
            continue
        # El id_industec se deriva del NOMBRE, asi que al renombrar el documento nace
        # un id nuevo y el viejo queda como fila duplicada del mismo PDF. Se activa la
        # fila canonica y, en la misma pasada, se marca la vieja como supersedida. Antes
        # esto dependia de correr despues otro script, y volver a ejecutar aqui resucitaba
        # las 338 filas viejas.
        id_viejo = Path(r["nombre_original"]).stem
        id_canonico = Path(r["nombre_canonico"]).stem if r["nombre_canonico"] else id_viejo

        cur.execute(
            """UPDATE ots
               SET local_codigo=%s, zona=%s, en_cuarentena=0, ruta_pdf=%s,
                   motivo_cuarentena=CONCAT('RESUELTO_T1.6b:', %s)
               WHERE id_industec=%s""",
            (r["local_resuelto"], r["zona_resuelta"] or r["zona_nombre"],
             r["ruta_canonica"], r["via_resolucion"][:60], id_canonico))
        toco_canonica = cur.rowcount

        if id_viejo != id_canonico:
            cur.execute(
                """UPDATE ots SET en_cuarentena=1,
                       motivo_cuarentena=CONCAT('SUPERSEDIDO_POR_NOMBRE_CANONICO:', %s)
                   WHERE id_industec=%s""",
                (id_canonico, id_viejo))
            supersedidas += cur.rowcount

        if toco_canonica:
            actualizados += toco_canonica
        else:
            # la fila canonica aun no existe: la creara la ingesta al leer el arbol
            no_encontrados += 1
    print(f"  filas de 'ots' actualizadas: {actualizados}")
    print(f"  filas viejas marcadas como supersedidas: {supersedidas}")
    print(f"  documentos sin fila canonica todavia (los creara la ingesta): {no_encontrados}")

    cur.execute("SELECT COUNT(*) c FROM ots WHERE en_cuarentena=1")
    quedan = cur.fetchone()["c"]
    cur.execute("SELECT COUNT(*) c FROM ots WHERE en_cuarentena=0")
    limpias = cur.fetchone()["c"]
    print(f"  ots en cuarentena tras la corrida: {quedan} | fuera de cuarentena: {limpias}")

    # ---------- manifiesto para la administracion ----------
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "RESUELTAS"
    cols = ["nombre_original", "nombre_canonico", "local_resuelto", "cadena_resuelta",
            "zona_resuelta", "tipo", "aviso", "via_resolucion", "confianza",
            "senal_pdf", "senal_sap", "senal_plan", "nota", "resultado",
            "ruta_canonica", "ruta_original"]
    ws.append([c.replace("_", " ").upper() for c in cols])
    for r in registro:
        ws.append([r.get(c, "") for c in cols])

    ws2 = wb.create_sheet("PENDIENTES DE DECISION")
    cols2 = ["nombre_original", "categoria", "tipo", "pdf_local_texto", "pdf_cliente",
             "aviso", "nota", "ruta_original"]
    ws2.append([c.replace("_", " ").upper() for c in cols2] + ["DECISION ADMIN"])
    for r in pendientes:
        ws2.append([r.get(c, "") for c in cols2] + [""])

    for hoja in (ws, ws2):
        hoja.freeze_panes = "A2"
        for celda in hoja[1]:
            celda.font = Font(bold=True, color="FFFFFF")
            celda.fill = PatternFill("solid", start_color="1F4E78")
        for col in hoja.columns:
            ancho = max((len(str(c.value)) for c in col if c.value), default=10)
            hoja.column_dimensions[col[0].column_letter].width = min(ancho + 2, 52)

    MANIFIESTO_SALIDA.parent.mkdir(parents=True, exist_ok=True)
    wb.save(MANIFIESTO_SALIDA)
    print(f"\nManifiesto para la administracion: {MANIFIESTO_SALIDA}")

    # ---------- cuadre final ----------
    total = len(registro) + len(pendientes)
    print("\n" + "=" * 70)
    print(f"CUADRE: registro({len(registro)}) + pendientes({len(pendientes)}) = {total}  "
          f"(esperado {len(filas)})")
    if total != len(filas):
        print("  ATENCION: el cuadre no da. Revisar antes de dar por cerrada la tarea.")
        sys.exit(1)
    for res, c in collections.Counter(r["resultado"] for r in registro).most_common():
        print(f"  {c:4d}  {res}")
    cur.close()
    cnx.close()


if __name__ == "__main__":
    main()
