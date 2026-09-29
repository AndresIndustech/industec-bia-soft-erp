# -*- coding: utf-8 -*-
"""
T2.28.6 - Catalogo de marcas y modelos para las sugerencias de la ficha del
equipo (obs. 4; S-2). NO escribe en `equipos_ficha` ni en ningun otro lado de
la base -eso lo hace `envio.php` con cada orden nueva-: este script solo arma
dos catalogos de referencia para que el ayudante de sugerencias de `app.js`
(`crearSugerencias()`, el mismo de administrador/correo/repuesto, nunca un
<datalist>) tenga con que completar mientras el tecnico escribe.

DOS FUENTES, COMBINADAS
  1. `ot_equipos` (base local de la estacion, `industec_ots`): lo que los
     tecnicos escribieron de verdad en cada orden -la senal mas fuerte, 9.702
     filas con marca y modelo al 2026-09-24.
  2. `Inventario 2023.xlsx`, hoja "Data General" (misma fuente que usa
     `t2_28_repuestos.py` para "donde se usa cada repuesto"): el inventario
     tecnico de KFC, con su propia lista de marca/modelo por linea.
La frecuencia de una marca es la suma de sus apariciones en las dos fuentes
(sin deduplicar entre ellas: son mediciones independientes de la misma
realidad, y sumarlas es mas robusto que quedarse con una sola). Solo entran a
`marcas.json` las que llegan a **frecuencia >= 3** entre ambas: una marca que
aparece una o dos veces es casi siempre una errata del tecnico (p. ej. "Trues"
o un modelo tecleado en la casilla de marca), no una marca real del parque.

NORMALIZACION DE MARCA (`esMarcador()`/`normMarca()`, T2.28.6)
Se reusa la MISMA implementacion que ya valida el formulario
(`t2_5_validacion.py::es_marcador/norm_marca`), importada de alli -no una
copia- para que este catalogo y la regla `EQUIPO_SIN_DATOS_DE_PLACA` nunca
diverjan sobre que cuenta como "sin dato". `normMarca()` aqui NO aplica ningun
sinonimo todavia (I-7: no se adivina que "TRUE REFRIGERATOR" es lo mismo que
"TRUE" sin que una persona lo decida) - eso es exactamente lo que la hoja
"Marcas por unificar" del Excel de salida le pide revisar a Andres o a Cesar.
Cuando ese Excel vuelva con una columna de decision llena, una version futura
de este script podra leerla y aplicar los sinonimos aprobados via el parametro
`sinonimos=` que `norm_marca()` ya acepta.

MODELO no se normaliza a mayusculas (a diferencia de la marca): un modelo como
"IYT0500A" o "T-49" es un codigo, no un texto libre, y forzar mayusculas no
gana nada -- se colapsan solo los espacios y se recorta a 80 caracteres, igual
que hace `envio.php` al guardar la ficha. Para contar frecuencia se agrupa por
la version en mayusculas (dos tecnicos que escriben "iyt0500a" y "IYT0500A"
son el mismo modelo), pero se publica la grafia mas frecuente de cada grupo.

SALIDA
    D:\\INDUSTECH IA\\SALIDAS IA\\OTS\\catalogos\\marcas.json
        {"generado_en": "...", "criterio": "frecuencia >= 3 ...", "datos": ["HENNY PENNY", ...]}
    D:\\INDUSTECH IA\\SALIDAS IA\\OTS\\catalogos\\modelos.json
        {"generado_en": "...", "datos": {"HENNY PENNY": ["500", "600", ...], ...}}   (hasta 60 c/u)
    D:\\INDUSTECH IA\\SALIDAS IA\\OTS\\MARCAS POR UNIFICAR (generado agente).xlsx
        una fila por marca normalizada, con las grafias distintas que se le
        vieron y su frecuencia en cada fuente, para que una persona decida los
        sinonimos (columna DECISION ADMIN vacia, I-5).

USO
    .venv/Scripts/python.exe scripts/t2_28_marcas.py                 # arma los JSON y el Excel
    .venv/Scripts/python.exe scripts/t2_28_marcas.py --subir         # ademas los sube por scp
        a catalogos/ del sitio de pruebas y verifica el sha256 remoto (S:5.4/6.4)

No escribe nada en `G:\\`, en la base ni en ningun maestro: I-4, I-10 no
aplican aqui porque no hay verificacion cruzada que hacer -es un catalogo de
sugerencias, nunca la fuente de verdad (esa es `equipos_ficha`, que solo
escribe `envio.php`).
"""
from __future__ import annotations

import argparse
import collections
import datetime as dt
import hashlib
import json
import re
import sys
from pathlib import Path

import mysql.connector
import openpyxl
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.utils import get_column_letter

sys.path.insert(0, str(Path(__file__).parent))
from comun import SALIDAS, cargar_env, escribir_json_atomico  # noqa: E402
from t2_5_validacion import es_marcador, norm_marca  # noqa: E402 (misma funcion que valida el formulario)

RUTA_INVENTARIO_2023 = Path(r"D:\RESPALDOS\_ORIGEN_DRIVE\Oficina Industec\ISA 2.0\Inventario 2023.xlsx")
HOJA_INVENTARIO_2023 = "Data General"

DIR_CATALOGOS = SALIDAS / "catalogos"
RUTA_MARCAS_JSON = DIR_CATALOGOS / "marcas.json"
RUTA_MODELOS_JSON = DIR_CATALOGOS / "modelos.json"
RUTA_XLSX_SINONIMOS = SALIDAS / "MARCAS POR UNIFICAR (generado agente).xlsx"

FRECUENCIA_MINIMA_MARCA = 3
MAX_MODELOS_POR_MARCA = 60


# ---------------------------------------------------------------------------
# Fuente 1: ot_equipos (base local de la estacion)
# ---------------------------------------------------------------------------

def leer_ot_equipos(env: dict) -> list[tuple[str, str]]:
    """[(marca_cruda, modelo_crudo), ...] de cada fila con marca o modelo no
    vacios. `es_marcador()` filtra los "S/N"/"XXX"/etc, igual que hace
    `envio.php` antes de guardar la ficha."""
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])
    try:
        cur = cnx.cursor()
        cur.execute("SELECT marca, modelo FROM ot_equipos")
        filas = cur.fetchall()
    finally:
        cnx.close()
    return [(m or "", mo or "") for m, mo in filas]


# ---------------------------------------------------------------------------
# Fuente 2: Inventario 2023.xlsx, hoja "Data General"
# ---------------------------------------------------------------------------

def leer_inventario_2023(ruta: Path) -> list[tuple[str, str]]:
    """Mismas columnas que usa `t2_28_repuestos.py::cargar_fuente3()`:
    equipo, marca, modelo, item_ax, nombre_parte, num_parte. Aqui solo
    interesan marca y modelo; se lee una fila por linea del inventario (no se
    deduplica: cada linea es una entrada real del inventario tecnico de KFC)."""
    if not ruta.is_file():
        return []
    wb = openpyxl.load_workbook(ruta, data_only=True, read_only=True)
    if HOJA_INVENTARIO_2023 not in wb.sheetnames:
        print(f"  aviso: la hoja '{HOJA_INVENTARIO_2023}' no existe en {ruta} "
              f"(hojas: {wb.sheetnames}); se sigue solo con ot_equipos")
        return []
    ws = wb[HOJA_INVENTARIO_2023]
    filas = []
    for row in ws.iter_rows(min_row=2, values_only=True):
        _equipo, marca, modelo = (list(row) + [None] * 3)[:3]
        filas.append((str(marca or ""), str(modelo or "")))
    return filas


# ---------------------------------------------------------------------------
# Frecuencias
# ---------------------------------------------------------------------------

def contar(pares_por_fuente: dict[str, list[tuple[str, str]]]):
    """Cuenta marca (normalizada) y (marca, modelo) por fuente y combinado.

    Devuelve:
      frecuencia_marca: {marca_norm: total combinado}
      por_fuente_marca: {marca_norm: {fuente: cuenta}}          (para el Excel)
      variantes_marca:  {marca_norm: {grafia_cruda: cuenta}}    (para el Excel)
      modelos_por_marca: {marca_norm: {modelo_norm: (cuenta, grafia_mas_frecuente)}}
    """
    frecuencia_marca = collections.Counter()
    por_fuente_marca = collections.defaultdict(lambda: collections.Counter())
    variantes_marca = collections.defaultdict(lambda: collections.Counter())
    modelos_por_marca = collections.defaultdict(lambda: collections.Counter())
    modelos_grafia = collections.defaultdict(lambda: collections.Counter())

    for fuente, pares in pares_por_fuente.items():
        for marca_cruda, modelo_crudo in pares:
            if es_marcador(marca_cruda):
                continue
            marca_n = norm_marca(marca_cruda)
            # "----", "..." y similares no son marcadores de "sin dato" en el
            # contrato compartido (esMarcador solo reconoce grafias exactas),
            # pero una marca real SIEMPRE tiene alguna letra -- filtrarlas aqui
            # no es adivinar un sinonimo (I-7), es descartar lo que no puede
            # ser un nombre de marca bajo ninguna lectura.
            if not marca_n or not re.search(r"[A-ZÀ-ÿ]", marca_n):
                continue
            frecuencia_marca[marca_n] += 1
            por_fuente_marca[marca_n][fuente] += 1
            variantes_marca[marca_n][marca_cruda.strip()] += 1

            if es_marcador(modelo_crudo):
                continue
            modelo_txt = " ".join(str(modelo_crudo).split())[:80]
            if not modelo_txt:
                continue
            modelo_n = modelo_txt.upper()
            modelos_por_marca[marca_n][modelo_n] += 1
            modelos_grafia[(marca_n, modelo_n)][modelo_txt] += 1

    return frecuencia_marca, por_fuente_marca, variantes_marca, modelos_por_marca, modelos_grafia


# ---------------------------------------------------------------------------
# Salidas
# ---------------------------------------------------------------------------

def escribir_json_catalogos(frecuencia_marca, modelos_por_marca, modelos_grafia):
    marcas_validas = sorted(m for m, c in frecuencia_marca.items() if c >= FRECUENCIA_MINIMA_MARCA)

    ahora = dt.datetime.now().isoformat(timespec="seconds")
    escribir_json_atomico(RUTA_MARCAS_JSON, json.dumps({
        "generado_en": ahora,
        "criterio": f"frecuencia >= {FRECUENCIA_MINIMA_MARCA} entre ot_equipos e Inventario 2023",
        "datos": marcas_validas,
    }, ensure_ascii=False, indent=2))

    modelos_json = {}
    for marca_n in marcas_validas:
        top = modelos_por_marca.get(marca_n, collections.Counter()).most_common(MAX_MODELOS_POR_MARCA)
        modelos_json[marca_n] = [
            modelos_grafia[(marca_n, modelo_n)].most_common(1)[0][0]
            for modelo_n, _cuenta in top
        ]
    escribir_json_atomico(RUTA_MODELOS_JSON, json.dumps({
        "generado_en": ahora,
        "datos": modelos_json,
    }, ensure_ascii=False, indent=2))

    return marcas_validas, modelos_json


ENCABEZADO_FILL = PatternFill("solid", fgColor="1F4E78")
ENCABEZADO_FONT = Font(color="FFFFFF", bold=True)


def escribir_excel_sinonimos(frecuencia_marca, por_fuente_marca, variantes_marca):
    RUTA_XLSX_SINONIMOS.parent.mkdir(parents=True, exist_ok=True)
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Marcas por unificar"
    encabezados = ["marca_normalizada", "frecuencia_total", "en_ot_equipos", "en_inventario_2023",
                   "grafias_vistas", "entra_al_catalogo (>= 3)", "DECISION ADMIN (sinonimo canonico, si aplica)"]
    for j, h in enumerate(encabezados, start=1):
        c = ws.cell(row=1, column=j, value=h)
        c.font = ENCABEZADO_FONT
        c.fill = ENCABEZADO_FILL
        c.alignment = Alignment(wrap_text=True)
    fila = 2
    for marca_n, total in sorted(frecuencia_marca.items(), key=lambda kv: (-kv[1], kv[0])):
        variantes = variantes_marca[marca_n]
        grafias = " | ".join(f"{g} ({n})" for g, n in variantes.most_common()) if len(variantes) > 1 else ""
        ws.cell(row=fila, column=1, value=marca_n)
        ws.cell(row=fila, column=2, value=total)
        ws.cell(row=fila, column=3, value=por_fuente_marca[marca_n].get("ot_equipos", 0))
        ws.cell(row=fila, column=4, value=por_fuente_marca[marca_n].get("inventario_2023", 0))
        ws.cell(row=fila, column=5, value=grafias)
        ws.cell(row=fila, column=6, value="Si" if total >= FRECUENCIA_MINIMA_MARCA else "No")
        fila += 1
    ws.freeze_panes = "A2"
    anchos = [22, 14, 12, 16, 50, 16, 40]
    for j, ancho in enumerate(anchos, start=1):
        ws.column_dimensions[get_column_letter(j)].width = ancho
    try:
        wb.save(str(RUTA_XLSX_SINONIMOS))
    except PermissionError:
        sys.exit(f"ABORTADO: '{RUTA_XLSX_SINONIMOS.name}' esta abierto en Excel. Cierralo y repite (I-4).")


# ---------------------------------------------------------------------------
def subir(env: dict) -> bool:
    """Sube marcas.json y modelos.json a catalogos/ del sitio de pruebas y
    verifica el sha256 remoto contra el local (S:6.4). No toca nada mas."""
    import hostinger_ssh as H
    ok = True
    for ruta in (RUTA_MARCAS_JSON, RUTA_MODELOS_JSON):
        remoto = f"{H.DOCROOT_PRUEBAS}/catalogos/{ruta.name}"
        print(f"  subiendo {ruta.name} -> {remoto} ...")
        H.scp_subir(ruta, remoto)
        esperado = hashlib.sha256(ruta.read_bytes()).hexdigest()
        obtenido = H.ssh(f"sha256sum {remoto} 2>/dev/null | cut -d' ' -f1", env=env).strip()
        if obtenido != esperado:
            print(f"    NO COINCIDE el hash de {ruta.name}: local={esperado} remoto={obtenido}")
            ok = False
        else:
            print(f"    ok, sha256 {esperado[:12]}...")
    return ok


def main() -> int:
    sys.stdout.reconfigure(encoding="utf-8")
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    ap.add_argument("--subir", action="store_true",
                     help="ademas de generar los JSON, subirlos por scp al sitio de pruebas")
    a = ap.parse_args()

    env = cargar_env(("DB_HOST", "DB_PORT", "DB_USER", "DB_PASSWORD", "DB_NAME"))

    print("=== Fuente 1: ot_equipos (base local) ===")
    pares_ot = leer_ot_equipos(env)
    print(f"  {len(pares_ot)} filas")

    print("=== Fuente 2: Inventario 2023.xlsx, hoja 'Data General' ===")
    pares_inv = leer_inventario_2023(RUTA_INVENTARIO_2023)
    print(f"  {len(pares_inv)} filas" if pares_inv else "  no disponible (se sigue solo con ot_equipos)")

    frecuencia_marca, por_fuente_marca, variantes_marca, modelos_por_marca, modelos_grafia = contar(
        {"ot_equipos": pares_ot, "inventario_2023": pares_inv})

    marcas_validas, modelos_json = escribir_json_catalogos(frecuencia_marca, modelos_por_marca, modelos_grafia)
    escribir_excel_sinonimos(frecuencia_marca, por_fuente_marca, variantes_marca)

    total_modelos = sum(len(v) for v in modelos_json.values())
    print(f"\n{len(frecuencia_marca)} marcas distintas vistas; {len(marcas_validas)} con "
          f"frecuencia >= {FRECUENCIA_MINIMA_MARCA} entran al catalogo.")
    print(f"{total_modelos} modelos publicados (hasta {MAX_MODELOS_POR_MARCA} por marca).")
    print(f"Escrito {RUTA_MARCAS_JSON}")
    print(f"Escrito {RUTA_MODELOS_JSON}")
    print(f"Escrito {RUTA_XLSX_SINONIMOS} (para que se revisen los sinonimos)")

    if a.subir:
        print("\n=== Subiendo a catalogos/ del sitio de pruebas ===")
        if not subir(env):
            print("ABORTADO: el hash remoto no coincidio en al menos un archivo.")
            return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
