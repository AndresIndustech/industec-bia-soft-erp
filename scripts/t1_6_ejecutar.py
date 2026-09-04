"""
T1.6 - Ejecucion del saneamiento: copia fisica desde el manifiesto ya
revisado (MANIFIESTO_SANEAMIENTO.csv, producido por t1_6_saneamiento.py).

- Nivel 1/2 (no duplicado): se copia desde el espejo _ORIGEN_DRIVE hacia su
  ruta canonica bajo D:\\RESPALDOS\\ORDENES DE TRABAJO\\...
- Nivel 3 y duplicados descartados: se copian a D:\\RESPALDOS\\_CUARENTENA\\
  con su nombre original, para revision de la administracion. El espejo
  _ORIGEN_DRIVE nunca se toca (I-2/I-3): sigue siendo la fuente reversible.
- Cada copia se verifica por hash SHA-256 contra el origen.
- El manifiesto final se escribe a Excel (para la administracion) y se
  carga en la tabla manifiesto_saneamiento. Los alias Nivel 1/2 confirmados
  se cargan en locales_alias.
"""
import csv
import hashlib
import shutil
from pathlib import Path
from collections import defaultdict
import mysql.connector
import openpyxl
from openpyxl.styles import Font, PatternFill

ENV_PATH = Path(r"D:\INDUSTECH IA\agentes\config\.env")
env = {}
for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    k, v = line.split("=", 1)
    env[k.strip()] = v.strip()

IN_CSV = Path(r"D:\INDUSTECH IA\SALIDAS IA\CALIDAD\MANIFIESTO_SANEAMIENTO.csv")
CUARENTENA_DIR = Path(r"D:\RESPALDOS\_CUARENTENA")
OUT_XLSX = Path(r"D:\INDUSTECH IA\SALIDAS IA\CALIDAD\MANIFIESTO_SANEAMIENTO.xlsx")


_hash_cache = {}


def sha256_de(path):
    key = str(path)
    if key in _hash_cache:
        return _hash_cache[key]
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    digest = h.hexdigest()
    _hash_cache[key] = digest
    return digest


def copiar_verificado(origen, destino):
    destino.parent.mkdir(parents=True, exist_ok=True)
    if destino.exists():
        # Idempotencia por CONTENIDO (hash), no por tamano: dos archivos
        # distintos pueden coincidir en bytes por casualidad rara vez, pero
        # el tamano solo es una señal debil; el hash es la prueba real.
        if sha256_de(destino) == sha256_de(origen):
            return True, "YA_EXISTIA"
        return False, "DESTINO_YA_OCUPADO_POR_OTRO_CONTENIDO"
    shutil.copy2(origen, destino)
    if sha256_de(origen) != sha256_de(destino):
        return False, "HASH_NO_COINCIDE_TRAS_COPIA"
    return True, "COPIADO"


def main():
    with open(IN_CSV, encoding="utf-8-sig") as f:
        filas = list(csv.DictReader(f))
    print(f"Filas en manifiesto: {len(filas)}")

    contadores = defaultdict(int)
    errores = []

    # --- Fase 0: detectar colisiones de ruta_canonica ENTRE ORIGENES DISTINTOS.
    # No es un caso raro: el propio Drive tiene el mismo archivo guardado en
    # mas de una carpeta (p.ej. el backlog sin clasificar de 2026 duplicado
    # con su copia ya archivada). Se resuelve por CONTENIDO, nunca por orden
    # de llegada:
    #   - mismo hash en todos  -> duplicado real: se copia una sola vez, el
    #     resto se registra en el manifiesto sin ocupar espacio nuevo (el
    #     espejo _ORIGEN_DRIVE ya preserva ambas copias intactas).
    #   - hashes distintos     -> colision genuina (posible bug del contador
    #     sin flock() ya documentado): TODOS van a cuarentena para que la
    #     administracion decida cual es la correcta, nunca se sobrescribe.
    por_ruta = defaultdict(list)
    for fila in filas:
        if fila.get("ruta_canonica") and int(fila["nivel"]) < 3 and fila.get("estado_duplicado") != "DESCARTADO_DUPLICADO":
            por_ruta[fila["ruta_canonica"]].append(fila)

    n_colision_identica = 0
    n_colision_real = 0
    for ruta, grupo in por_ruta.items():
        if len(grupo) < 2:
            continue
        hashes = {sha256_de(Path(f["ruta_original"])): f for f in grupo}
        if len(hashes) == 1:
            # Contenido identico: se conserva el primero, el resto se marca
            # como duplicado (no se copia una segunda vez a ningun lado).
            for f in grupo[1:]:
                f["estado_duplicado"] = "DESCARTADO_DUPLICADO_MISMA_RUTA"
                f["regla"] = (f["regla"] + "|DUPLICADO_MISMA_RUTA_CANONICA_HASH_IDENTICO").strip("|")
            n_colision_identica += len(grupo) - 1
        else:
            # Contenido distinto con el mismo nombre canonico: no se puede
            # decidir automaticamente cual es la OT real. Cuarentena para
            # los archivos del grupo salvo el que ya tiene hash unico? No:
            # se manda TODO el grupo a cuarentena, ninguno se promueve solo,
            # para no elegir arbitrariamente.
            for f in grupo:
                f["nivel"] = 3
                f["ruta_canonica"] = ""
                f["regla"] = (f["regla"] + "|COLISION_CONTENIDO_DISTINTO_MISMA_CLAVE").strip("|")
            n_colision_real += len(grupo)

    print(f"Colisiones de ruta canonica: {len(por_ruta)} rutas con mas de 1 origen "
          f"analizadas -> {n_colision_identica} archivos duplicados de contenido identico, "
          f"{n_colision_real} archivos en {sum(1 for g in por_ruta.values() if len(g)>1 and len({sha256_de(Path(f['ruta_original'])) for f in g})>1)} "
          f"colisiones reales de contenido distinto (a cuarentena)")

    for fila in filas:
        origen = Path(fila["ruta_original"])
        estado_dup = fila.get("estado_duplicado", "")
        es_duplicado = estado_dup in ("DESCARTADO_DUPLICADO", "DESCARTADO_DUPLICADO_MISMA_RUTA")
        nivel = int(fila["nivel"])

        if es_duplicado:
            # Duplicado real (mismo contenido en otro lado, ya sea por sufijo
            # "(n)" o por estar guardado en dos carpetas): no se copia a
            # ningun lado nuevo. El espejo _ORIGEN_DRIVE ya preserva ambas
            # rutas de origen; el manifiesto registra la relacion completa.
            fila["ruta_final"] = ""
            fila["estado_copia"] = "NO_COPIADO_DUPLICADO_CONTENIDO_YA_PRESERVADO"
            contadores["DUPLICADO_NO_COPIADO"] += 1
            continue

        if nivel == 3:
            destino = CUARENTENA_DIR / fila["nombre_original"]
            if destino.exists() and sha256_de(destino) != sha256_de(origen):
                sufijo = hashlib.md5(str(origen).encode()).hexdigest()[:8]
                destino = CUARENTENA_DIR / f"{destino.stem}__{sufijo}{destino.suffix}"
            ok, motivo = copiar_verificado(origen, destino)
            fila["ruta_final"] = str(destino)
            fila["estado_copia"] = motivo
            contadores["CUARENTENA"] += 1
            if not ok:
                errores.append((str(origen), motivo))
            continue

        ruta_canonica = fila.get("ruta_canonica", "")
        if not ruta_canonica:
            # nivel <3 pero sin ruta_canonica calculada: tratar como cuarentena
            # por seguridad, nunca se descarta silenciosamente.
            destino = CUARENTENA_DIR / fila["nombre_original"]
            if destino.exists() and sha256_de(destino) != sha256_de(origen):
                sufijo = hashlib.md5(str(origen).encode()).hexdigest()[:8]
                destino = CUARENTENA_DIR / f"{destino.stem}__{sufijo}{destino.suffix}"
            ok, motivo = copiar_verificado(origen, destino)
            fila["ruta_final"] = str(destino)
            fila["estado_copia"] = motivo + "_SIN_RUTA_CANONICA"
            contadores["SIN_RUTA_A_CUARENTENA"] += 1
            if not ok:
                errores.append((str(origen), motivo))
            continue

        destino = Path(ruta_canonica)
        ok, motivo = copiar_verificado(origen, destino)
        fila["ruta_final"] = str(destino)
        fila["estado_copia"] = motivo
        contadores["APLICADO"] += 1
        if not ok:
            errores.append((str(origen), motivo))

    print("\n=== Resultado de la copia ===")
    for k, v in contadores.items():
        print(f"  {k}: {v}")
    print(f"  ERRORES: {len(errores)}")
    for e in errores[:20]:
        print("   ", e)

    total_copiado = sum(contadores.values())
    print(f"\nTotal procesado: {total_copiado} (esperado {len(filas)})")

    # --- Cargar alias nuevos confirmados (Nivel 1/2, no cuarentena) a locales_alias
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"],
    )
    cur = cnx.cursor()

    alias_unicos = {}
    for fila in filas:
        if int(fila["nivel"]) in (1, 2) and fila.get("local_crudo") and fila.get("local_canonico"):
            crudo = fila["local_crudo"].strip().upper()
            canon = fila["local_canonico"]
            if crudo != canon and crudo not in alias_unicos:
                alias_unicos[crudo] = (canon, fila["regla"], int(fila["nivel"]))

    n_alias = 0
    for alias_texto, (local_codigo, regla, nivel) in alias_unicos.items():
        try:
            cur.execute(
                """INSERT INTO locales_alias (alias_texto, local_codigo, regla_aplicada, nivel_confianza)
                   VALUES (%s,%s,%s,%s)
                   ON DUPLICATE KEY UPDATE local_codigo=VALUES(local_codigo), regla_aplicada=VALUES(regla_aplicada)""",
                (alias_texto, local_codigo, regla[:60], nivel),
            )
            n_alias += 1
        except mysql.connector.Error as e:
            print(f"  aviso: alias {alias_texto} no insertado: {e}")
    cnx.commit()
    print(f"\nAlias cargados/actualizados en locales_alias: {n_alias}")

    # --- Cargar el manifiesto completo a la tabla manifiesto_saneamiento
    cur.execute("DELETE FROM manifiesto_saneamiento")
    filas_bd = []
    for fila in filas:
        estado = "APLICADO"
        if fila.get("estado_duplicado") in ("DESCARTADO_DUPLICADO", "DESCARTADO_DUPLICADO_MISMA_RUTA"):
            estado = "DESCARTADO_DUPLICADO"
        elif int(fila["nivel"]) == 3:
            estado = "CUARENTENA"
        filas_bd.append((
            fila["ruta_original"], fila["nombre_original"],
            fila.get("ruta_final") or fila.get("ruta_canonica") or None,
            fila.get("nombre_canonico") or None,
            fila["regla"][:60], int(fila["nivel"]), estado, None, None,
        ))
    cur.executemany(
        """INSERT INTO manifiesto_saneamiento
           (ruta_original, nombre_original, ruta_canonica, nombre_canonico,
            regla_aplicada, nivel_confianza, estado, decision_admin, hash_sha256)
           VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
        filas_bd,
    )
    cnx.commit()
    cur.close()
    cnx.close()
    print(f"Manifiesto cargado en BD: {len(filas_bd)} filas")

    # --- Escribir el manifiesto a Excel para la administracion, con la
    # columna DECISION ADMIN en los casos de cuarentena (Nivel 3).
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Manifiesto"
    encabezados = ["nombre_original", "ruta_original", "nivel", "regla",
                   "tipo", "local_crudo", "local_canonico", "zona_canonica",
                   "cadena", "aviso_crudo", "aviso_resuelto", "ruta_final",
                   "estado_copia", "DECISION ADMIN"]
    ws.append(encabezados)
    for c in ws[1]:
        c.font = Font(bold=True)
    relleno_cuarentena = PatternFill(start_color="FFF2CC", end_color="FFF2CC", fill_type="solid")
    for fila in filas:
        row = [fila.get(h, "") for h in encabezados[:-1]] + [""]
        ws.append(row)
        if int(fila["nivel"]) == 3:
            for cell in ws[ws.max_row]:
                cell.fill = relleno_cuarentena
    for col in ws.columns:
        maxlen = max((len(str(c.value)) for c in col if c.value), default=10)
        ws.column_dimensions[col[0].column_letter].width = min(maxlen + 2, 50)
    wb.save(OUT_XLSX)
    print(f"Manifiesto Excel escrito: {OUT_XLSX}")


if __name__ == "__main__":
    main()
