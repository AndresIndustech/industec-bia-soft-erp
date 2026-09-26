"""
T1.5 - Importa el maestro de 95 locales a la base de datos.

Algoritmo de extraccion (verificado contra la hoja GENERAL, que reporta el
conteo autoritativo por cadena; el cuadre es exacto: 95 locales, 13 cadenas):

Las hojas UIO / CUENCA / LARB listan, en las columnas B/C, TODOS los locales
de la zona (esa es la fuente de codigo+zona+ubicacion). A partir de la
columna E, y de nuevo a partir de la H, aparecen BLOQUES apilados
verticalmente: una fila de encabezado con el nombre de la cadena, seguida
de N filas (codigo, ubicacion) de esa cadena, y opcionalmente una fila
"TOTAL". Cuando aparece otro texto que no es ni codigo ni "TOTAL", es el
encabezado del siguiente bloque. Iterando esos bloques se obtiene la cadena
de cada codigo sin ambiguedad.

Los correos por local vienen de la hoja "LOCALES INDUSTEC" (columna F),
que cubre las tres zonas pese a su titulo.
"""
import re
import sys
import openpyxl
import mysql.connector
from pathlib import Path

# Cargar .env manualmente (sin dependencia de dotenv en el PATH del sistema)
ENV_PATH = Path(r"D:\INDUSTECH IA\desarrollo\agentes\config\.env")
env = {}
for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    k, v = line.split("=", 1)
    env[k.strip()] = v.strip()

XLSX_PATH = r"D:\RESPALDOS\_ORIGEN_DRIVE\LOCALES_INDUSTEC_GENERAL\LOCALES_INDUSTEC_GENERAL.xlsx"

CODE_RE = re.compile(r"^[A-Z]{1,2}\d{2,4}EC$", re.IGNORECASE)

# Encabezado de bloque tal como aparece en el Excel -> cadena canonica (plan seccion 6.3)
CADENA_CANONICA = {
    "CADENA GUS": "GUS",
    "CADENA CASA RES_SEMLON": "CASA RES",
    "KFC_INT FOOD": "KFC",
    "AMERICAN_DELI": "AMERICAN DELI",
    "DELI": "AMERICAN DELI",  # abreviatura confirmada por conteo (LARB, A014EC)
    "IL CAPPO_DELI": "IL CAPO",
    "MENESTRAS DEL NEGRO_SEMLON": "MENESTRAS DEL NEGRO",
    "JUAN VALDEZ": "JUAN VALDEZ",
    "TROPI BURGER": "TROPI BURGER",
    "ESPA\u00d1OL": "EL ESPANOL",
    "HELADERIA": "HELADERIA",
    "CAJUN_SHEMLON": "CAJUN",
    "BASKIN ROBBIN": "BASKIN ROBBINS",
    "CINNABON": "CINNABON",
}

# Total esperado por cadena, segun la hoja GENERAL (verificacion cruzada obligatoria)
TOTAL_ESPERADO = {
    "KFC": 22, "GUS": 19, "CASA RES": 8, "TROPI BURGER": 6, "JUAN VALDEZ": 13,
    "MENESTRAS DEL NEGRO": 8, "AMERICAN DELI": 2, "EL ESPANOL": 4, "IL CAPO": 1,
    "BASKIN ROBBINS": 1, "CINNABON": 1, "HELADERIA": 7, "CAJUN": 3,
}
TOTAL_LOCALES_ESPERADO = 95
ZONA_TOTAL_ESPERADO = {"UIO": 31, "LARB": 30, "CUENCA": 34}  # hoja CUENCA = zona CNLJ


def extraer_bloques_cadena(ws, col_izq, col_der):
    """Recorre un par de columnas (p.ej. E/F) apilando bloques de cadena.
    Devuelve dict codigo_canonico -> cadena_cruda_del_excel."""
    resultado = {}
    cadena_actual = None
    # min_row=2: el header de la PRIMERA cadena de cada columna vive en la fila 2
    # (junto a "CODIGO"/"UBICACION" de las columnas B/C). Empezar en 3 se lo salta
    # y deja sin cadena a todo el primer bloque (bug detectado por la verificacion
    # cruzada contra la hoja GENERAL: GUS/CASA RES/KFC daban 0 o casi 0).
    for row in ws.iter_rows(min_row=2, max_row=ws.max_row):
        val_izq = row[col_izq - 1].value
        val_der = row[col_der - 1].value
        if val_izq is None:
            continue
        val_izq = str(val_izq).strip()
        if val_izq.upper() == "TOTAL":
            continue  # fin de bloque; el siguiente header cambia cadena_actual
        if CODE_RE.match(val_izq):
            resultado[val_izq.upper()] = (cadena_actual, val_der)
        else:
            cadena_actual = val_izq  # nueva fila de encabezado de bloque
    return resultado


def normalizar_codigo(codigo):
    """Nivel 1: mayusculas + relleno de ceros a 3 digitos antes del sufijo EC.
    K99EC->K099EC, CN42EC->CN042EC. No toca casos de 2 letras+2 digitos como
    BS17EC (ahi la ambiguedad es de letra, no de ceros; se resuelve aparte)."""
    codigo = codigo.strip().upper()
    m = re.match(r"^([A-Z]{1,2})(\d+)(EC)$", codigo)
    if not m:
        return codigo
    letras, digitos, suf = m.groups()
    return f"{letras}{digitos.zfill(3)}{suf}"


def normalizar_ubicacion(u):
    return re.sub(r"\s+", " ", str(u or "")).strip().upper()


def extraer_zona(ws, nombre_zona):
    """Columnas B/C: listado maestro general de la zona (todas las cadenas)."""
    locales = {}
    total_declarado = None
    for row in ws.iter_rows(min_row=3, max_row=ws.max_row):
        codigo = row[1].value  # columna B
        ubicacion = row[2].value  # columna C
        if codigo is None:
            continue
        codigo = str(codigo).strip()
        if codigo.upper() == "TOTAL":
            total_declarado = ubicacion
            continue
        if CODE_RE.match(codigo):
            locales[codigo.upper()] = str(ubicacion).strip() if ubicacion else None
    return locales, total_declarado


def main():
    wb = openpyxl.load_workbook(XLSX_PATH, data_only=True)

    zonas_hojas = {"UIO": "UIO", "LARB": "LARB", "CNLJ": "CUENCA"}
    maestro = {}  # codigo_canonico -> dict(zona, ubicacion, cadena)
    alias_detectados = []  # variantes encontradas DENTRO del propio maestro (Nivel 2)

    for zona, hoja in zonas_hojas.items():
        ws = wb[hoja]
        locales_zona, total_decl = extraer_zona(ws, zona)
        if total_decl is not None and int(total_decl) != len(locales_zona):
            print(f"AVISO: {hoja} declara TOTAL={total_decl} pero se listaron {len(locales_zona)}")
        esperado = ZONA_TOTAL_ESPERADO.get(hoja)
        if esperado is not None and len(locales_zona) != esperado:
            print(f"ERROR: {hoja} esperaba {esperado} locales, se encontraron {len(locales_zona)}")
            sys.exit(1)

        # Bloques de cadena: par E/F (columnas 5/6) y par H/I (columnas 8/9)
        bloques_e = extraer_bloques_cadena(ws, 5, 6)
        bloques_h = extraer_bloques_cadena(ws, 8, 9)
        # Indices auxiliares para los dos niveles de resolucion de discrepancias
        # dentro del propio archivo (listado general B/C vs bloques E/H):
        todos_bloques = {**bloques_e, **bloques_h}  # codigo_bloque -> (cadena, ubicacion)
        por_codigo_normalizado = {normalizar_codigo(c): v for c, v in todos_bloques.items()}
        por_ubicacion = {}
        for c, (cad, ubic) in todos_bloques.items():
            por_ubicacion.setdefault(normalizar_ubicacion(ubic), []).append((c, cad))

        for codigo, ubicacion in locales_zona.items():
            cadena_cruda = None
            origen_match = None
            codigo_canonico = codigo  # por defecto, el propio codigo del listado general
            if codigo in todos_bloques:
                cadena_cruda = todos_bloques[codigo][0]
                origen_match = "EXACTO"
            elif normalizar_codigo(codigo) in por_codigo_normalizado:
                cadena_cruda = por_codigo_normalizado[normalizar_codigo(codigo)][0]
                origen_match = "NORMALIZADO_CEROS"
                # El canonico es la forma con ceros completos (regla Nivel 1),
                # no la clave cruda del listado general.
                codigo_canonico = normalizar_codigo(codigo)
            else:
                candidatos = por_ubicacion.get(normalizar_ubicacion(ubicacion), [])
                if len(candidatos) == 1:
                    cadena_cruda = candidatos[0][1]
                    codigo_bloque = candidatos[0][0]
                    origen_match = f"POR_UBICACION (bloque usa codigo {codigo_bloque!r})"
                    # El bloque de cadena es la fuente mas confiable del codigo
                    # real (esta ligado a la marca por su propio encabezado).
                    codigo_canonico = codigo_bloque

            if origen_match and origen_match != "EXACTO":
                print(f"NIVEL 2: {codigo} ({zona}) -> canonico {codigo_canonico} identificado por {origen_match} -> {cadena_cruda!r}")

            if cadena_cruda is None:
                print(f"CUARENTENA: {codigo} ({zona}) sin bloque de cadena reconocido")
                cadena = "SIN_CLASIFICAR"
            else:
                cadena = CADENA_CANONICA.get(cadena_cruda.strip())
                if cadena is None:
                    print(f"CUARENTENA: {codigo} ({zona}) cadena cruda desconocida: {cadena_cruda!r}")
                    cadena = "SIN_CLASIFICAR"

            maestro[codigo_canonico] = {"zona": zona, "ubicacion": ubicacion, "cadena": cadena}
            if codigo_canonico != codigo:
                alias_detectados.append({
                    "alias": codigo, "canonico": codigo_canonico,
                    "regla": origen_match, "zona": zona,
                })

    # Caso especial conocido: H043EC (LARB) queda justo despues del TOTAL del
    # bloque ESPAÑOL y antes del siguiente header de bloque -- como el codigo
    # no resetea cadena_actual tras un TOTAL (no hay marca de "fin de bloque"
    # distinta de "aparece texto nuevo"), hereda incorrectamente "ESPAÑOL" por
    # posicion. Su ubicacion es literalmente "HELADERIA MALTERIA": la evidencia
    # textual directa pesa mas que la herencia posicional, asi que se fuerza
    # sin condicion (no solo cuando quede SIN_CLASIFICAR).
    if "H043EC" in maestro and maestro["H043EC"]["cadena"] != "HELADERIA":
        print(f"NOTA: H043EC reclasificado de {maestro['H043EC']['cadena']!r} a 'HELADERIA' "
              f"por el texto de su ubicacion ({maestro['H043EC']['ubicacion']!r}); "
              "el Excel no marca el fin del bloque anterior antes de esta fila.")
        maestro["H043EC"]["cadena"] = "HELADERIA"

    # Verificacion cruzada obligatoria contra la hoja GENERAL
    conteo_cadena = {}
    for c in maestro.values():
        conteo_cadena[c["cadena"]] = conteo_cadena.get(c["cadena"], 0) + 1

    print(f"\nTotal de locales extraidos: {len(maestro)} (esperado {TOTAL_LOCALES_ESPERADO})")
    ok = len(maestro) == TOTAL_LOCALES_ESPERADO
    for cadena, esperado in TOTAL_ESPERADO.items():
        real = conteo_cadena.get(cadena, 0)
        marca = "OK" if real == esperado else "DIFERENCIA"
        if real != esperado:
            ok = False
        print(f"  {cadena:20s} esperado={esperado:3d}  real={real:3d}  {marca}")
    sin_clasificar = conteo_cadena.get("SIN_CLASIFICAR", 0)
    if sin_clasificar:
        print(f"  SIN_CLASIFICAR: {sin_clasificar} (deberia ser 0)")
        ok = False

    if not ok:
        print("\nABORTADO: la verificacion cruzada contra GENERAL no cuadra. No se escribe nada en la BD.")
        sys.exit(1)
    print("\nVerificacion cruzada: OK - coincide exactamente con la hoja GENERAL.")

    # Correos por local, de la hoja "LOCALES INDUSTEC" (cubre las 3 zonas pese a su titulo)
    ws_correos = wb["LOCALES INDUSTEC"]
    correos = {}
    for row in ws_correos.iter_rows(min_row=3, max_row=ws_correos.max_row):
        codigo = row[1].value  # B
        # row[] es 0-based: la columna F ('CORREOS DESTINATARIOS') es row[5], no row[4].
        # row[4] es la columna E, que contiene la etiqueta fija 'Equipos' y por eso
        # los 95 locales quedaron con ese texto en vez de una direccion de correo.
        correo_raw = row[5].value  # F
        if codigo is None:
            continue
        codigo = str(codigo).strip().upper()
        if CODE_RE.match(codigo) and correo_raw:
            partes = [p.strip() for p in str(correo_raw).split(";") if p.strip()]
            correos[codigo] = partes

    alias_a_canonico = {a["alias"]: a["canonico"] for a in alias_detectados}
    def buscar_correo(codigo_canonico):
        if codigo_canonico in correos:
            return correos[codigo_canonico]
        # La hoja de correos puede usar la forma cruda del listado general
        for alias, canon in alias_a_canonico.items():
            if canon == codigo_canonico and alias in correos:
                return correos[alias]
        return []

    con_correo = sum(1 for c in maestro if buscar_correo(c))
    print(f"Locales con correo encontrado en 'LOCALES INDUSTEC': {con_correo}/{len(maestro)}")

    # Conexion e insercion (upsert idempotente por local_codigo)
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"],
    )
    cur = cnx.cursor()
    # Reemplazo completo, no upsert incremental: el maestro es pequeno (95 filas)
    # y totalmente reconstruible desde el Excel en cada corrida. Un upsert por
    # clave dejaria filas huerfanas si la clave canonica de un codigo cambia
    # entre corridas (como paso con BS17EC->BR17EC al corregir este script).
    # locales_alias se reconstruye entero (no lo referencia nadie).
    cur.execute("DELETE FROM locales_alias")
    # locales NO se borra: desde T1.7 la tabla ots lo referencia por clave foranea.
    # La carga es upsert sobre local_codigo (ON DUPLICATE KEY UPDATE mas abajo), y al
    # final se reporta cualquier local que exista en la base y ya no este en el maestro,
    # para decidirlo a mano en vez de borrarlo en silencio.
    cur.execute("SELECT local_codigo FROM locales")
    locales_previos = {r[0] for r in cur.fetchall()}
    insertados = 0
    for codigo, datos in sorted(maestro.items()):
        lista_correos = buscar_correo(codigo)
        correo_local = lista_correos[0] if len(lista_correos) >= 1 else None
        correo_jefe_op = lista_correos[1] if len(lista_correos) >= 2 else None
        cur.execute(
            """
            INSERT INTO locales (local_codigo, zona, cadena, nombre, correo_local, correo_jefe_op, activo)
            VALUES (%s, %s, %s, %s, %s, %s, 1)
            ON DUPLICATE KEY UPDATE
                zona=VALUES(zona), cadena=VALUES(cadena), nombre=VALUES(nombre),
                correo_local=VALUES(correo_local), correo_jefe_op=VALUES(correo_jefe_op)
            """,
            (codigo, datos["zona"], datos["cadena"], datos["ubicacion"], correo_local, correo_jefe_op),
        )
        insertados += 1

    # Alias detectados DENTRO del propio maestro (discrepancias B/C vs bloques
    # de cadena, resueltas por Nivel 2). Se cargan ya en esta fase porque
    # surgen aqui; T1.6 los complementara con las variantes observadas en los
    # nombres reales de los 7333 archivos historicos.
    alias_insertados = 0
    for a in alias_detectados:
        cur.execute(
            """
            INSERT INTO locales_alias (alias_texto, local_codigo, regla_aplicada, nivel_confianza)
            VALUES (%s, %s, %s, 2)
            ON DUPLICATE KEY UPDATE local_codigo=VALUES(local_codigo), regla_aplicada=VALUES(regla_aplicada)
            """,
            (a["alias"], a["canonico"], a["regla"]),
        )
        alias_insertados += 1
    cnx.commit()

    sobrantes = sorted(locales_previos - set(maestro))
    if sobrantes:
        print(f"\nAVISO: {len(sobrantes)} locales estaban en la base y ya no figuran "
              f"en el maestro: {sobrantes}")
        print("   No se borran automaticamente (pueden tener ordenes asociadas). "
              "Requieren decision de la administracion.")

    cur.execute("SELECT COUNT(*) FROM locales")
    total_bd = cur.fetchone()[0]
    cur.execute("SELECT zona, COUNT(*) FROM locales GROUP BY zona")
    por_zona = cur.fetchall()
    cur.close()
    cnx.close()

    print(f"\n{insertados} locales insertados/actualizados. Total en tabla 'locales': {total_bd}")
    print("Por zona:", por_zona)
    print(f"Alias del propio maestro cargados en 'locales_alias': {alias_insertados}")
    for a in alias_detectados:
        print(f"  {a['alias']} -> {a['canonico']}  ({a['regla']})")


if __name__ == "__main__":
    main()
