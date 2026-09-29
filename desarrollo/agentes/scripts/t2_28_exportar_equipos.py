# -*- coding: utf-8 -*-
"""
T2.28.6 - El maestro de equipos (marca, modelo, serie) a un Excel, para que la
administracion lo tenga "en un archivo" como pidio INDUSTEC (obs. 4). Paso
`equipos` de `saneamiento_nocturno.py`: corre solo, todas las noches, sin
`--ejecutar` porque no escribe nada -es una lectura de `equipos_ficha` /
`equipos_ficha_cambios` (Hostinger, sitio de pruebas) y un volcado a Excel.

QUE LEE
  1. `equipos_ficha` y `equipos_ficha_cambios` del servidor, por SSH
     (`hostinger_ssh.sql_remoto`, la MISMA via de solo-SELECT que usa
     `t2_28_cronograma.py`). Nunca una conexion directa a la base -el sitio de
     pruebas no expone el puerto de MySQL hacia afuera a proposito.
  2. `equipos_por_local.json` y `locales.json` (armados por
     `t2_5_catalogos.py`, en `SALIDAS IA/OTS/catalogos/`): para poner zona,
     cadena, tipo y activo fijo junto a cada ficha -la ficha en si no guarda
     esos datos, solo `equipo_clave` y `local_codigo`. Si el catalogo no esta
     generado, el Excel sale igual pero con esas columnas vacias (I-7: se dice
     que falta, no se inventa).
  3. `equipos_propuestos` del servidor, para los equipos que todavia no tienen
     `equipo_sap` (clave `PROPUESTO:<uuid>`): ahi si esta su tipo y su area,
     porque los propuso el tecnico con el formulario, no vienen de SAP.

QUE ESCRIBE
    D:\\INDUSTECH IA\\SALIDAS IA\\OTS\\MAESTRO DE EQUIPOS (generado agente).xlsx
        hoja "Equipos": local, zona, cadena, equipo SAP, tipo, activo fijo,
        area, marca, modelo, serie, sin placa, fuente, ultima actualizacion,
        por, orden.
        hoja "Cambios de serie": todo el historial de `equipos_ficha_cambios`
        con `campo='serie'` (no solo los ultimos 90 dias: aqui es el archivo
        completo: `equipos.php` es la vista acotada a 90 dias, no este Excel).
No toca la base ni `G:\\`: es una lectura y un volcado a `SALIDAS IA` (I-4, a
nombre nuevo). Aborta con un mensaje claro si el Excel destino esta abierto.

USO
    .venv/Scripts/python.exe scripts/t2_28_exportar_equipos.py
"""
from __future__ import annotations

import sys
from pathlib import Path

import openpyxl
from openpyxl.styles import Font, PatternFill
from openpyxl.utils import get_column_letter

sys.path.insert(0, str(Path(__file__).parent))
from comun import SALIDAS  # noqa: E402
import hostinger_ssh as H  # noqa: E402
import json as _json  # noqa: E402

DESTINO = SALIDAS / "MAESTRO DE EQUIPOS (generado agente).xlsx"
CATALOGOS = SALIDAS / "catalogos"

ENCABEZADO_FILL = PatternFill("solid", fgColor="1F4E78")
ENCABEZADO_FONT = Font(color="FFFFFF", bold=True)


def _n(v):
    """El TSV de sql_remoto trae NULL como el texto 'NULL' (mismo contrato
    documentado en hostinger_ssh.sql_remoto y usado por t2_28_cronograma.py)."""
    return None if v in (None, "NULL") else v


def _leer_json_catalogo(nombre: str):
    p = CATALOGOS / nombre
    if not p.is_file():
        return None
    return _json.loads(p.read_text(encoding="utf-8")).get("datos")


def cargar_enriquecimiento():
    """(locales_por_codigo, equipos_sap_por_clave, tipos_por_local). Devuelve
    diccionarios vacios -no aborta- si el catalogo local no esta generado: el
    Excel sale igual, solo que sin zona/cadena/tipo (I-7)."""
    locales_json = _leer_json_catalogo("locales.json") or []
    locales_por_codigo = {l["codigo"]: l for l in locales_json}

    equipos_json = _leer_json_catalogo("equipos_por_local.json") or {}
    equipos_sap_por_clave = {}
    for local_codigo, lista in equipos_json.items():
        for e in lista:
            if e.get("equipo_sap"):
                equipos_sap_por_clave[str(e["equipo_sap"])] = {**e, "local_codigo": local_codigo}

    return locales_por_codigo, equipos_sap_por_clave


def leer_fichas():
    """equipos_ficha + su cruce con ot_capturadas (por envio_uuid) para tener
    una referencia legible de la orden ('aviso') en vez de solo el UUID."""
    filas = H.sql_remoto(
        "SELECT ef.equipo_clave, ef.local_codigo, ef.marca, ef.modelo, ef.serie, "
        "ef.sin_placa, ef.fuente, ef.actualizado_en, u.nombre, ef.envio_uuid, c.aviso "
        "FROM equipos_ficha ef "
        "LEFT JOIN usuarios u ON u.usuario_id = ef.actualizado_por "
        "LEFT JOIN ot_capturadas c ON c.envio_uuid = ef.envio_uuid "
        "ORDER BY ef.local_codigo, ef.equipo_clave")
    out = []
    for f in filas:
        if len(f) < 11:
            continue
        clave, local, marca, modelo, serie, sin_placa, fuente, act_en, por, envio_uuid, aviso = f[:11]
        out.append({
            "equipo_clave": clave, "local_codigo": _n(local), "marca": _n(marca),
            "modelo": _n(modelo), "serie": _n(serie), "sin_placa": _n(sin_placa) == "1",
            "fuente": _n(fuente), "actualizado_en": _n(act_en), "por": _n(por),
            "envio_uuid": _n(envio_uuid), "aviso": _n(aviso),
        })
    return out


def leer_propuestos():
    """equipo_uuid -> {tipo, area, activo_fijo, zona} de equipos_propuestos,
    para los equipos que la ficha conoce por clave PROPUESTO:<uuid> y que
    equipos_por_local.json no tiene (todavia no son SAP)."""
    filas = H.sql_remoto(
        "SELECT equipo_uuid, tipo, area, activo_fijo, zona FROM equipos_propuestos")
    out = {}
    for f in filas:
        if len(f) < 5:
            continue
        uuid, tipo, area, activo_fijo, zona = f[:5]
        out[uuid.lower()] = {"tipo": _n(tipo), "area": _n(area),
                              "activo_fijo": _n(activo_fijo), "zona": _n(zona)}
    return out


def leer_cambios_serie():
    filas = H.sql_remoto(
        "SELECT c.equipo_clave, ef.local_codigo, c.antes, c.despues, u.nombre, c.en, cap.aviso "
        "FROM equipos_ficha_cambios c "
        "LEFT JOIN equipos_ficha ef ON ef.equipo_clave = c.equipo_clave "
        "LEFT JOIN usuarios u ON u.usuario_id = c.por "
        "LEFT JOIN ot_capturadas cap ON cap.envio_uuid = c.envio_uuid "
        "WHERE c.campo = 'serie' "
        "ORDER BY c.en DESC")
    out = []
    for f in filas:
        if len(f) < 7:
            continue
        clave, local, antes, despues, por, en, aviso = f[:7]
        out.append({"equipo_clave": clave, "local_codigo": _n(local), "antes": _n(antes),
                     "despues": _n(despues), "por": _n(por), "en": _n(en), "aviso": _n(aviso)})
    return out


def _hoja_tabla(wb, nombre, encabezados, filas):
    ws = wb.create_sheet(nombre)
    for j, h in enumerate(encabezados, start=1):
        c = ws.cell(row=1, column=j, value=h)
        c.font = ENCABEZADO_FONT
        c.fill = ENCABEZADO_FILL
    for i, fila in enumerate(filas, start=2):
        for j, h in enumerate(encabezados, start=1):
            ws.cell(row=i, column=j, value=fila.get(h, ""))
    ws.freeze_panes = "A2"
    for j, h in enumerate(encabezados, start=1):
        ws.column_dimensions[get_column_letter(j)].width = min(max(12, len(h) + 2), 40)
    return ws


def construir_filas_equipos(fichas, locales_por_codigo, equipos_sap_por_clave, propuestos):
    filas = []
    for f in fichas:
        clave = f["equipo_clave"]
        local_info = locales_por_codigo.get(f["local_codigo"] or "", {})
        if clave.startswith("PROPUESTO:"):
            prop = propuestos.get(clave.split(":", 1)[1].lower(), {})
            equipo_sap, tipo, activo_fijo, area = "", prop.get("tipo"), prop.get("activo_fijo"), prop.get("area")
        else:
            sap = equipos_sap_por_clave.get(clave, {})
            equipo_sap, tipo, activo_fijo, area = clave, sap.get("tipo"), sap.get("codigo_activo"), sap.get("area")
        filas.append({
            "local": f["local_codigo"] or "", "zona": local_info.get("zona") or "",
            "cadena": local_info.get("cadena") or "", "equipo_sap": equipo_sap or "",
            "tipo": tipo or "", "activo_fijo": activo_fijo or "", "area": area or "",
            "marca": f["marca"] or "", "modelo": f["modelo"] or "", "serie": f["serie"] or "",
            "sin_placa": "Si" if f["sin_placa"] else "No", "fuente": f["fuente"] or "",
            "ultima_actualizacion": f["actualizado_en"] or "", "por": f["por"] or "",
            "orden": f["aviso"] or f["envio_uuid"] or "",
        })
    return filas


def main() -> int:
    sys.stdout.reconfigure(encoding="utf-8")

    print("=== Leyendo equipos_ficha del servidor ===")
    fichas = leer_fichas()
    print(f"  {len(fichas)} fichas")

    print("=== Leyendo equipos_propuestos del servidor ===")
    propuestos = leer_propuestos()
    print(f"  {len(propuestos)} propuestos")

    print("=== Leyendo equipos_ficha_cambios (serie) del servidor ===")
    cambios = leer_cambios_serie()
    print(f"  {len(cambios)} cambios de serie")

    print("=== Catalogos locales (zona/cadena/tipo) ===")
    locales_por_codigo, equipos_sap_por_clave = cargar_enriquecimiento()
    if not locales_por_codigo:
        print("  aviso: no esta 'locales.json' en SALIDAS IA/OTS/catalogos -- "
              "corre t2_5_catalogos.py antes si quieres zona/cadena en el Excel")
    print(f"  {len(locales_por_codigo)} locales, {len(equipos_sap_por_clave)} equipos SAP conocidos")

    filas_equipos = construir_filas_equipos(fichas, locales_por_codigo, equipos_sap_por_clave, propuestos)

    wb = openpyxl.Workbook()
    wb.remove(wb.active)
    encabezados_eq = ["local", "zona", "cadena", "equipo_sap", "tipo", "activo_fijo", "area",
                       "marca", "modelo", "serie", "sin_placa", "fuente",
                       "ultima_actualizacion", "por", "orden"]
    _hoja_tabla(wb, "Equipos", encabezados_eq, filas_equipos)

    encabezados_cambios = ["equipo_clave", "local_codigo", "antes", "despues", "por", "en", "aviso"]
    _hoja_tabla(wb, "Cambios de serie", encabezados_cambios, cambios)

    DESTINO.parent.mkdir(parents=True, exist_ok=True)
    try:
        wb.save(str(DESTINO))
    except PermissionError:
        sys.exit(f"ABORTADO: '{DESTINO.name}' esta abierto en Excel. Cierralo y repite (I-4).")

    print(f"\nEscrito {DESTINO} -- {len(filas_equipos)} equipos, {len(cambios)} cambios de serie.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
