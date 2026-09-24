# -*- coding: utf-8 -*-
"""
T2.28.4a - Correos del maestro de la estacion, contra el Excel corregido de la
administradora. SOLO LECTURA: no escribe en `locales` ni en ningun otro lado
de la base. Lo unico que produce es el Excel de propuesta en SALIDAS IA (I-4).

Por que existe: `locales.correo_local` se llenó en T1.5 desde un Excel viejo y
nunca se reconcilio contra lo que de verdad escriben los tecnicos en el
formulario (`ots.correo_local`) ni contra el listado que la administradora
corrigio a mano el 2026-09-22. Este script propone, no decide (I-7): cada fila
sale con su nivel de confianza y una columna DECISION ADMIN vacia para que la
persona resuelva los casos que no cuadran solos.

Dos preguntas por local activo, cada una con su propio nivel A/B/C:

  CORREO DEL LOCAL   Compara el Excel 2026-09-22 contra el correo mas
                      frecuente que los tecnicos escribieron en `ots` (sin
                      contar el generico @industec.me, que es el valor que
                      queda cuando nadie supo el correo real).
  JEFE KFC            No tiene columna en el Excel nuevo. Se estima con las
                      DOS ordenes mas recientes (<=90 dias) que traen un
                      correo @kfc.com.ec, exigiendo que sean de tecnicos
                      DISTINTOS -- son dos personas que lo escribieron por su
                      cuenta, esa es la verificacion independiente. Como
                      ayuda (no como fuente), se anota el nombre del jefe de
                      area del Excel viejo de UIO/LARB/CUENCA.

CODIGO: se resuelve exactamente como el robot del correo (t2_6_imap_avisos.py
:cargar_maestro) -- coincidencia directa contra `locales.local_codigo`, y si
no, contra `locales_alias.alias_texto`. NUNCA se deriva del prefijo de la
marca (error n.º 1 del proyecto: 'BS' no siempre es Baskin). Lo que no
resuelve por ninguna de las dos vias va a la hoja "Codigos por alias", que
tambien sirve de bitacora de que alias se uso en cada fila que si resolvio.

Uso:
    .venv/Scripts/python.exe scripts/t2_28_correos.py --analizar   (por defecto)
    .venv/Scripts/python.exe scripts/t2_28_correos.py --ejecutar   (T2.28.4b,
        bloqueado hasta la puerta D1 de Andres: este script no la implementa)
"""
from __future__ import annotations

import argparse
import collections
import datetime
import re
import shutil
import sys
import tempfile
from pathlib import Path

import mysql.connector
import openpyxl
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.utils import get_column_letter

sys.path.insert(0, str(Path(__file__).parent))
from comun import ENV_PATH, SALIDAS, cargar_env, sha256_de  # noqa: E402

XLSX_NUEVO = Path(r"G:\Mi unidad\INDUSTEC IA\CORREOS LOCALES INDUSTEC.xlsx")
XLSX_VIEJO = Path(r"D:\RESPALDOS\_ORIGEN_DRIVE\Oficina Industec\CORREOS LOCALES UIO.xlsx")
DESTINO = SALIDAS / "CORREOS LOCALES (generado agente).xlsx"

# Hoja -> zona canonica. La hoja se llama 'CUENCA' pero la zona es 'CNLJ'
# (regla 5 de la skill de lectura de Excel: no confiar en el titulo).
HOJA_A_ZONA = {"UIO": "UIO", "LARB": "LARB", "CUENCA": "CNLJ"}
FILA_INICIO = 4          # los datos empiezan en la fila 4 (sondeado 2026-09-23)
COL_CODIGO, COL_UBICACION, COL_CORREO = 1, 2, 3   # B, C, D (0-based)
COL_MINIMA = 4                                     # hasta 'correo' (indice 3)

# Columnas del Excel viejo (sondeado 2026-09-23): mismas B/C/D + E 'jefe de
# area' + F 'correo' del jefe. Solo se usa E, como ayuda, nunca como fuente.
COL_VIEJO_JEFE_AREA = 4

VENTANA_JEFE_DIAS = 90
DOMINIO_GENERICO = "@industec.me"

# Cifras medidas el 2026-09-23 contra el servidor (T2_28_OBSERVACIONES_INDUSTEC.md
# linea 503). Se usan solo para AVISAR si el analisis de hoy da otra cosa -- no
# se fuerzan: I-7, y la instruccion explicita de esta subtarea es documentar la
# discrepancia y preguntar, no maquillar el numero.
#
# DISCREPANCIA INVESTIGADA (2026-09-23, segunda pasada de T2.28.4a): este script
# da A=94 / B=6 / C=0 de forma repetible (no es un problema de datos moviendose:
# se confirmo que ningun local tiene su primer correo_local no generico fechado
# hoy -- consulta MIN(fecha_atencion) agrupada por local, cero filas). La
# diferencia de exactamente 2 (92->94, y "en excel sin historico" 3->1) se
# explica con la hoja "Codigos por alias" del Excel de salida: los UNICOS DOS
# codigos de todo el Excel que se resuelven por ALIAS -- no por coincidencia
# directa -- son BS17EC->BR17EC y CN42EC->CN042EC (los mismos dos ejemplos del
# error n.o 1 del proyecto, citados en el modulo `clave()` de este archivo y en
# la skill industec-invariantes). Para esos dos locales, `historico_frecuente`
# coincide exacto con el correo del Excel (BR17EC: br17@baskin-cinnabon.com.ec;
# CN042EC: bs42@baskin-cinnabon.com.ec), y ambos pasan a nivel A aqui.
#
# Hipotesis mas probable, NO confirmada al 100% (no hay acceso al script/consulta
# exacta de la medicion original de linea 503): esa medicion cruzo el Excel contra
# `ots.correo_local` por CODIGO DIRECTO (sin pasar por `locales_alias`), como una
# medicion rapida para dimensionar la tarea, no como este script -- que resuelve
# exactamente como el robot (t2_6_imap_avisos.py:cargar_maestro), directo y
# despues alias. Con join directo, BS17EC y CN42EC no calzan contra ningun
# local_codigo real y esos dos locales caen a "sin historico" (nivel B), que es
# justo la cuenta 92/8 de la especificacion.
#
# No se cambian ESPERADO_A/ESPERADO_B_EN_EXCEL_SIN_HISTORICO mas abajo: decidir
# cual cifra es "la buena" (y si hay que corregir la linea 503 del documento) le
# toca a quien revise esto, no a este script de solo lectura. Lo que SI se puede
# afirmar con evidencia: no es un bug de este script tratando mal el correo o el
# nivel -- es, como minimo, la resolucion por alias funcionando como debe (I-7:
# se documenta el hallazgo, no se fuerza el numero esperado de la especificacion).
ESPERADO_A = 92
ESPERADO_C = 0
ESPERADO_B_EN_EXCEL_SIN_HISTORICO = 3
ESPERADO_B_SIN_EXCEL = {"G044EC", "G045EC", "G047EC", "G054EC", "T050EC"}


def clave(s: str) -> str:
    """Identica a la del robot (t2_6_imap_avisos.py:clave): alfanumerico y
    mayusculas. No es la escalera de la skill de Excel -- la especificacion de
    esta subtarea pide resolver 'como hace el robot', que es directo + alias,
    nunca derivar del prefijo."""
    return re.sub(r"[^A-Za-z0-9]", "", s or "").upper()


def normalizar_correo(s) -> str:
    return (str(s or "")).strip().lower()


def normalizar_tecnico(s) -> str:
    return re.sub(r"\s+", " ", (str(s or "")).strip().upper())


def es_generico(correo: str) -> bool:
    return correo.endswith(DOMINIO_GENERICO)


def cargar_maestro(env: dict) -> tuple[dict, dict]:
    """Locales activos y el indice codigo/alias -> local_codigo, igual que
    `t2_6_imap_avisos.py:cargar_maestro` pero sin filtrar por activo en el
    indice (un alias puede apuntar a un local que hoy esta inactivo y aun asi
    hay que reconocerlo para no mandarlo a 'no resuelve' por error)."""
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])
    try:
        cur = cnx.cursor(dictionary=True)
        cur.execute("SELECT local_codigo, zona, cadena, nombre, correo_local, activo "
                    "FROM locales")
        todos = {r["local_codigo"]: r for r in cur.fetchall()}
        indice = {clave(c): c for c in todos}
        cur.execute("SELECT alias_texto, local_codigo FROM locales_alias")
        for r in cur.fetchall():
            indice.setdefault(clave(r["alias_texto"]), r["local_codigo"])
    finally:
        cnx.close()
    activos = {k: v for k, v in todos.items() if v["activo"]}
    return activos, indice, todos


def cargar_historico(env: dict) -> tuple[dict, dict]:
    """Todo lo que `ots` sabe de correo_local y de correo_jefe_op, en dos
    consultas (no 100 por local): una lectura, agrupado despues en memoria."""
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])
    try:
        cur = cnx.cursor(dictionary=True)
        cur.execute(
            "SELECT local_codigo, correo_local, fecha_atencion FROM ots "
            "WHERE local_codigo IS NOT NULL AND correo_local IS NOT NULL "
            "AND correo_local <> ''")
        por_local_correo = collections.defaultdict(list)
        for r in cur.fetchall():
            por_local_correo[r["local_codigo"]].append(
                (normalizar_correo(r["correo_local"]), r["fecha_atencion"]))

        limite = datetime.date.today() - datetime.timedelta(days=VENTANA_JEFE_DIAS)
        cur.execute(
            "SELECT local_codigo, tecnico_nombre, correo_jefe_op, fecha_atencion "
            "FROM ots WHERE local_codigo IS NOT NULL AND correo_jefe_op LIKE '%@kfc.com.ec' "
            "AND fecha_atencion >= %s ORDER BY local_codigo, fecha_atencion DESC",
            (limite,))
        por_local_jefe = collections.defaultdict(list)
        for r in cur.fetchall():
            por_local_jefe[r["local_codigo"]].append(
                (normalizar_correo(r["correo_jefe_op"]),
                 normalizar_tecnico(r["tecnico_nombre"]), r["fecha_atencion"]))
    finally:
        cnx.close()
    return por_local_correo, por_local_jefe


def copiar_temporal(ruta: Path, nombre: str) -> Path:
    """Copia a un temporal antes de leer: el original suele estar abierto en
    Excel (la administradora lo tiene a mano) y no hay que competir por el
    archivo de Drive, que es de solo lectura (I-3)."""
    if not ruta.exists():
        sys.exit(f"ABORTADO: no existe {ruta}")
    tmp = Path(tempfile.gettempdir()) / nombre
    try:
        shutil.copy2(ruta, tmp)
    except PermissionError:
        sys.exit(f"ABORTADO: no se pudo leer {ruta} (en uso de forma exclusiva).")
    return tmp


def leer_excel_nuevo() -> tuple[dict, list]:
    """Lee CORREOS LOCALES INDUSTEC.xlsx. Devuelve:
      - correo_por_hoja: {(hoja, codigo_crudo): (correo, ubicacion, fila)}
      - filas: bitacora de cada fila leida, para la hoja 'Codigos por alias'
    """
    tmp = copiar_temporal(XLSX_NUEVO, "_t2_28_correos_nuevo.xlsx")
    try:
        wb = openpyxl.load_workbook(tmp, data_only=True, read_only=True)
        if set(wb.sheetnames) - set(HOJA_A_ZONA):
            print(f"AVISO: hojas inesperadas en el Excel: "
                  f"{set(wb.sheetnames) - set(HOJA_A_ZONA)}")
        filas = []
        for hoja, zona in HOJA_A_ZONA.items():
            if hoja not in wb.sheetnames:
                sys.exit(f"ABORTADO: falta la hoja '{hoja}' en {XLSX_NUEVO.name}")
            ws = wb[hoja]
            if ws.max_column < COL_MINIMA:
                sys.exit(f"ABORTADO: cambio el formato de la hoja '{hoja}': se esperaban "
                         f"al menos {COL_MINIMA} columnas y hay {ws.max_column}")
            for fila_num, celdas in enumerate(
                    ws.iter_rows(min_row=FILA_INICIO, max_row=ws.max_row,
                                 max_col=ws.max_column),
                    start=FILA_INICIO):
                v = [c.value for c in celdas]
                crudo = (v[COL_CODIGO] or "").strip() if isinstance(v[COL_CODIGO], str) \
                    else (str(v[COL_CODIGO]).strip() if v[COL_CODIGO] is not None else "")
                if not crudo:
                    continue
                ubicacion = (v[COL_UBICACION] or "").strip() if v[COL_UBICACION] else ""
                correo = normalizar_correo(v[COL_CORREO])
                filas.append({"hoja": hoja, "zona_hoja": zona, "fila": fila_num,
                              "crudo": crudo, "ubicacion": ubicacion, "correo": correo})
        wb.close()   # read_only=True deja el zip abierto hasta close(): sin esto,
                     # el unlink() de abajo falla en Windows con WinError 32.
    finally:
        try:
            tmp.unlink(missing_ok=True)
        except OSError:
            pass   # limpieza best-effort; no debe tapar un sys.exit real de arriba
    return filas


def leer_excel_viejo() -> dict:
    """Solo el nombre del jefe de area, como ayuda (nunca como fuente). Si el
    espejo no tiene el archivo, sigue sin el (I-7: se dice que falta, no se
    inventa)."""
    if not XLSX_VIEJO.exists():
        print(f"AVISO: no esta {XLSX_VIEJO} (espejo de Drive); la columna de "
              f"ayuda 'jefe de area (Excel viejo)' queda vacia.")
        return {}
    tmp = copiar_temporal(XLSX_VIEJO, "_t2_28_correos_viejo.xlsx")
    jefe_area = {}
    try:
        wb = openpyxl.load_workbook(tmp, data_only=True, read_only=True)
        for hoja, zona in HOJA_A_ZONA.items():
            nombre_hoja = "CUENCA" if hoja == "CUENCA" else hoja
            if nombre_hoja not in wb.sheetnames:
                continue
            ws = wb[nombre_hoja]
            if ws.max_column <= COL_VIEJO_JEFE_AREA:
                continue
            for celdas in ws.iter_rows(min_row=FILA_INICIO, max_row=ws.max_row,
                                        max_col=ws.max_column):
                v = [c.value for c in celdas]
                crudo = v[COL_CODIGO]
                if not crudo:
                    continue
                crudo = str(crudo).strip()
                jefe = v[COL_VIEJO_JEFE_AREA]
                if jefe:
                    texto = re.sub(r"\s*\n\s*", "; ", str(jefe).strip())
                    jefe_area[clave(crudo)] = texto
        wb.close()
    finally:
        try:
            tmp.unlink(missing_ok=True)
        except OSError:
            pass
    return jefe_area


def resolver(crudo: str, indice: dict) -> tuple[str | None, str]:
    """Devuelve (local_codigo o None, regla). Exactamente el criterio del
    robot: directo contra `locales`, si no contra `locales_alias`. Nada de
    ceros, sufijos ni prefijos adivinados aqui (esos casos YA estan resueltos
    en `locales_alias`, que es donde T1.6 los dejo con su evidencia)."""
    k = clave(crudo)
    codigo = indice.get(k)
    if codigo is None:
        return None, "NO_RESUELVE"
    return codigo, ("DIRECTO" if k == clave(codigo) else "ALIAS")


def nivel_correo_local(excel: str | None, frecuente: str | None) -> tuple[str, str | None]:
    if excel and frecuente:
        return ("A", excel) if excel == frecuente else ("C", None)
    if excel or frecuente:
        return "B", (excel or frecuente)
    return "B", None   # sin ninguna fuente; no se espera que ocurra (ver cifras)


def historico_correo_local(filas: list) -> tuple[str | None, tuple[str, object] | None]:
    """(mas_frecuente, (reciente, fecha)). Ambas excluyen el generico
    @industec.me: es el valor por defecto cuando nadie supo el correo real,
    no una senal de cual es el correo del local."""
    utiles = [(c, f) for c, f in filas if c and not es_generico(c)]
    if not utiles:
        return None, None
    conteo = collections.Counter(c for c, _ in utiles)
    # Empate: se prefiere el visto mas recientemente, y a igualdad el orden
    # alfabetico, para que la corrida sea reproducible.
    ultimo_de = {}
    for c, f in utiles:
        if f and (c not in ultimo_de or f > ultimo_de[c]):
            ultimo_de[c] = f
    frecuente = max(conteo.items(),
                     key=lambda kv: (kv[1], ultimo_de.get(kv[0]) or datetime.date.min, kv[0]))[0]
    reciente = max(utiles, key=lambda cf: cf[1] or datetime.date.min)
    return frecuente, reciente


def nivel_jefe_kfc(ordenes: list) -> dict:
    """`ordenes` ya viene ordenada fecha DESC (de la consulta). Busca la
    primera y la siguiente de tecnico DISTINTO -- 'dos personas que lo
    escribieron', la verificacion independiente que pide la especificacion."""
    if not ordenes:
        return {"nivel": "SIN_DATO", "propuesta": None, "orden1": None, "orden2": None}
    correo1, tec1, fecha1 = ordenes[0]
    orden2 = next((o for o in ordenes[1:] if o[1] != tec1), None)
    if orden2 is None:
        return {"nivel": "B", "propuesta": correo1,
                "orden1": (correo1, tec1, fecha1), "orden2": None}
    correo2, tec2, fecha2 = orden2
    if correo1 == correo2:
        return {"nivel": "A", "propuesta": correo1,
                "orden1": (correo1, tec1, fecha1), "orden2": (correo2, tec2, fecha2)}
    return {"nivel": "C", "propuesta": None,
            "orden1": (correo1, tec1, fecha1), "orden2": (correo2, tec2, fecha2)}


def main():
    ap = argparse.ArgumentParser()
    modo = ap.add_mutually_exclusive_group()
    modo.add_argument("--analizar", action="store_true", help="Por defecto. Solo lectura.")
    modo.add_argument("--ejecutar", action="store_true",
                       help="T2.28.4b -- bloqueado hasta la puerta D1 de Andres")
    args = ap.parse_args()

    if args.ejecutar:
        sys.exit("T2.28.4b (--ejecutar) no esta implementado en este script todavia: "
                 "requiere la puerta D1 (aprobacion de Andres sobre las cifras de "
                 "--analizar) y la migracion 013 de T2.28.2/.3 sembrada en el "
                 "servidor. Corre --analizar y lleva el Excel a revision.")

    env = cargar_env(("DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASSWORD"))
    sha_nuevo = sha256_de(XLSX_NUEVO) if XLSX_NUEVO.exists() else None
    if sha_nuevo is None:
        sys.exit(f"ABORTADO: no existe {XLSX_NUEVO}")

    activos, indice, _todos = cargar_maestro(env)
    filas_excel = leer_excel_nuevo()
    jefe_area_ayuda = leer_excel_viejo()
    hist_correo, hist_jefe = cargar_historico(env)

    # --- resolver cada fila del Excel nuevo contra el maestro ---------------
    bitacora_alias = []
    correo_excel_por_local: dict[str, dict] = {}
    for f in filas_excel:
        codigo, regla = resolver(f["crudo"], indice)
        activo = bool(activos.get(codigo)) if codigo else False
        bitacora_alias.append({**f, "resuelto": codigo, "regla": regla, "activo": activo})
        if codigo and activo:
            if codigo in correo_excel_por_local:
                print(f"AVISO: {codigo} aparece mas de una vez en el Excel nuevo "
                      f"(hoja {f['hoja']} fila {f['fila']} pisa una fila anterior)")
            correo_excel_por_local[codigo] = f

    # --- una fila por local activo del maestro -------------------------------
    filas_salida = []
    conteo_nivel = collections.Counter()
    b_en_excel_sin_historico, b_sin_excel = [], []
    for codigo, local in sorted(activos.items()):
        excel_row = correo_excel_por_local.get(codigo)
        excel_correo = excel_row["correo"] if excel_row else None
        frecuente, reciente = historico_correo_local(hist_correo.get(codigo, []))
        nivel, propuesta = nivel_correo_local(excel_correo, frecuente)
        conteo_nivel[nivel] += 1
        if nivel == "B":
            if excel_correo and not frecuente:
                b_en_excel_sin_historico.append(codigo)
            elif frecuente and not excel_correo:
                b_sin_excel.append(codigo)

        jefe = nivel_jefe_kfc(hist_jefe.get(codigo, []))

        filas_salida.append({
            "local": codigo, "zona": local["zona"], "cadena": local["cadena"],
            "maestro_actual": local["correo_local"] or "",
            "excel_2026_09_22": excel_correo or "",
            "historico_frecuente": frecuente or "",
            "historico_reciente": reciente,
            "propuesta": propuesta or "",
            "nivel": nivel,
            "jefe_nivel": jefe["nivel"], "jefe_propuesta": jefe["propuesta"] or "",
            "jefe_orden1": jefe["orden1"], "jefe_orden2": jefe["orden2"],
            "jefe_area_ayuda": jefe_area_ayuda.get(clave(codigo), ""),
        })

    no_en_excel = sorted(c for c in activos if c not in correo_excel_por_local)

    # --- reporte por consola -------------------------------------------------
    print("=" * 78)
    print("T2.28.4a - CORREOS LOCALES: analisis (solo lectura)")
    print("=" * 78)
    print(f"Excel nuevo   : {XLSX_NUEVO}")
    print(f"  sha256      : {sha_nuevo}")
    print(f"  filas leidas: {len(filas_excel)} (desde la fila {FILA_INICIO}, hojas "
          f"{list(HOJA_A_ZONA)})")
    resueltas = sum(1 for b in bitacora_alias if b["resuelto"])
    print(f"  resueltas   : {resueltas}  no resueltas: {len(bitacora_alias) - resueltas}")
    print(f"Locales activos en el maestro: {len(activos)}")
    print()
    print("CORREO DEL LOCAL -- nivel:")
    for n in ("A", "B", "C"):
        print(f"  {n}: {conteo_nivel[n]}")
    print(f"    de los B: {len(b_en_excel_sin_historico)} en el Excel sin historico, "
          f"{len(b_sin_excel)} sin fila en el Excel")
    print(f"    codigos sin fila en el Excel: {', '.join(no_en_excel)}")

    # Comparacion contra lo medido el 2026-09-23 (linea 503 de la especificacion).
    # Se AVISA, no se fuerza (I-7): si algo no cuadra, lo dice esta corrida y lo
    # decide una persona, no el script.
    esperado_b = ESPERADO_B_EN_EXCEL_SIN_HISTORICO + len(ESPERADO_B_SIN_EXCEL)
    ok = (conteo_nivel["A"] == ESPERADO_A and conteo_nivel["C"] == ESPERADO_C
          and conteo_nivel["B"] == esperado_b
          and set(no_en_excel) == ESPERADO_B_SIN_EXCEL)
    print()
    if ok:
        print(f"Cifras: COINCIDEN con lo esperado (A={ESPERADO_A} C={ESPERADO_C} "
              f"B={esperado_b}).")
    else:
        print("*** OJO: las cifras de HOY no coinciden con las medidas el 2026-09-23. ***")
        print(f"    esperado: A={ESPERADO_A} C={ESPERADO_C} B={esperado_b} "
              f"(sin excel={sorted(ESPERADO_B_SIN_EXCEL)})")
        print(f"    real    : A={conteo_nivel['A']} C={conteo_nivel['C']} "
              f"B={conteo_nivel['B']} (sin excel={no_en_excel})")
        print("    No se fuerza el numero esperado. Documentar la discrepancia y")
        print("    preguntar antes de dar esto por bueno.")
        if conteo_nivel["A"] - ESPERADO_A == 2:
            print("    Pista (no confirmada al 100%, ver comentario junto a ESPERADO_A):")
            print("    los +2 en A coinciden exacto con BR17EC y CN042EC, los dos unicos")
            print("    codigos del Excel que resuelven por ALIAS (BS17EC/CN42EC, error n.o 1).")
            print("    Hipotesis: la medicion de la especificacion cruzo por codigo directo,")
            print("    sin pasar por locales_alias, y esos dos cayeron a 'sin historico'.")

    conteo_jefe = collections.Counter(f["jefe_nivel"] for f in filas_salida)
    print()
    print("JEFE KFC -- nivel (sin cifra esperada en la especificacion, informativo):")
    for n in ("A", "B", "C", "SIN_DATO"):
        print(f"  {n}: {conteo_jefe[n]}")

    escribir_excel(filas_salida, bitacora_alias, no_en_excel, sha_nuevo, len(filas_excel))
    return 0 if ok else 1


def _fmt_reciente(par) -> str:
    if not par:
        return ""
    correo, fecha = par
    return f"{correo} ({fecha})" if fecha else correo


def _fmt_orden(o) -> str:
    if not o:
        return ""
    correo, tec, fecha = o
    return f"{correo} -- {tec or '(sin tecnico)'} ({fecha})"


def escribir_excel(filas: list, bitacora_alias: list, no_en_excel: list,
                    sha_nuevo: str, filas_excel_leidas: int):
    wb = openpyxl.Workbook()

    ws = wb.active
    ws.title = "Resumen"
    enc = ["local", "zona", "cadena", "maestro actual", "Excel 2026-09-22",
           "historico frecuente", "historico reciente (fecha)", "propuesta", "nivel",
           "DECISION ADMIN",
           "jefe KFC - nivel", "jefe KFC - propuesta",
           "jefe KFC - orden reciente 1 (correo -- tecnico (fecha))",
           "jefe KFC - orden reciente 2 (correo -- tecnico (fecha))",
           "jefe KFC - jefe de area (Excel viejo, ayuda)"]
    ws.append(enc)
    for i, _ in enumerate(enc, 1):
        c = ws.cell(row=1, column=i)
        c.font = Font(bold=True, color="FFFFFF")
        c.fill = PatternFill("solid", fgColor="1F4E79")
        c.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)

    rojo = PatternFill("solid", fgColor="FCE4E4")
    amarillo = PatternFill("solid", fgColor="FFF2CC")
    verde = PatternFill("solid", fgColor="E2EFDA")
    relleno_nivel = {"A": verde, "B": amarillo, "C": rojo}
    for f in filas:
        ws.append([
            f["local"], f["zona"], f["cadena"], f["maestro_actual"], f["excel_2026_09_22"],
            f["historico_frecuente"], _fmt_reciente(f["historico_reciente"]),
            f["propuesta"], f["nivel"], "",
            f["jefe_nivel"], f["jefe_propuesta"],
            _fmt_orden(f["jefe_orden1"]), _fmt_orden(f["jefe_orden2"]), f["jefe_area_ayuda"],
        ])
        ws.cell(row=ws.max_row, column=9).fill = relleno_nivel.get(f["nivel"], rojo)
    for i, a in enumerate([9, 6, 16, 26, 26, 26, 34, 26, 8, 18, 20, 22, 38, 38, 30], 1):
        ws.column_dimensions[get_column_letter(i)].width = a
    ws.freeze_panes = "A2"
    ws.auto_filter.ref = ws.dimensions

    ws2 = wb.create_sheet("Codigos por alias")
    enc2 = ["hoja", "fila", "codigo (Excel)", "ubicacion (Excel)", "correo (Excel)",
            "resuelto a", "regla", "local activo?"]
    ws2.append(enc2)
    for i, _ in enumerate(enc2, 1):
        ws2.cell(row=1, column=i).font = Font(bold=True)
    for b in bitacora_alias:
        ws2.append([b["hoja"], b["fila"], b["crudo"], b["ubicacion"], b["correo"],
                    b["resuelto"] or "", b["regla"], "SI" if b["activo"] else "NO"])
        if b["regla"] == "NO_RESUELVE":
            for col in range(1, len(enc2) + 1):
                ws2.cell(row=ws2.max_row, column=col).fill = PatternFill("solid", fgColor="FCE4E4")
    for i, a in enumerate([10, 6, 16, 30, 26, 14, 14, 12], 1):
        ws2.column_dimensions[get_column_letter(i)].width = a
    ws2.freeze_panes = "A2"
    ws2.auto_filter.ref = ws2.dimensions

    ws3 = wb.create_sheet("No estan en el Excel")
    ws3.append(["local activo del maestro sin fila resuelta en CORREOS LOCALES INDUSTEC.xlsx"])
    ws3.cell(row=1, column=1).font = Font(bold=True)
    for codigo in no_en_excel:
        ws3.append([codigo])
    ws3.column_dimensions["A"].width = 60

    ws4 = wb.create_sheet("De donde sale")
    for fila in [
        ["Generado por", "t2_28_correos.py --analizar"],
        ["Fecha de la corrida", str(datetime.date.today())],
        ["Fuente principal", str(XLSX_NUEVO)],
        ["  sha256", sha_nuevo],
        ["  filas leidas (desde la fila 4, hojas UIO/LARB/CUENCA)", str(filas_excel_leidas)],
        ["Fuente de ayuda (jefe de area, nunca fuente de correo)", str(XLSX_VIEJO)],
        ["Base de datos", "ots.correo_local y ots.correo_jefe_op, industec_ots"],
        ["Ventana para 'jefe KFC'", f"{VENTANA_JEFE_DIAS} dias, correo_jefe_op LIKE "
                                    "'%@kfc.com.ec', de tecnicos distintos"],
        ["El Drive", "Solo se leyo (copia temporal). No se modifico nada (I-3)."],
        ["Que NO hace este script", "No escribe en 'locales' ni en ningun maestro. "
                                    "T2.28.4b (--ejecutar) esta bloqueado hasta la "
                                    "puerta D1 de Andres."],
    ]:
        ws4.append(fila)
    ws4.column_dimensions["A"].width = 46
    ws4.column_dimensions["B"].width = 90
    for i in range(1, ws4.max_row + 1):
        ws4.cell(row=i, column=1).font = Font(bold=True)
        ws4.cell(row=i, column=2).alignment = Alignment(wrap_text=True)

    SALIDAS.mkdir(parents=True, exist_ok=True)
    try:
        wb.save(DESTINO)
    except PermissionError:
        sys.exit(f"ABORTADO: {DESTINO.name} esta abierto en Excel. Cierralo y repite (I-4).")
    print(f"\nEscrito: {DESTINO}")


if __name__ == "__main__":
    sys.exit(main())
