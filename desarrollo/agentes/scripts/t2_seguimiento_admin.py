"""
Indice de lo que SOLO existe en los archivos de la administracion.

El historico se reconstruye desde las ordenes de trabajo y el export de SAP,
que son la fuente de verdad. Pero hay dos columnas del plan que no viven en
ninguna orden ni en SAP porque son gestion propia de la administracion:

  OBSERVACIONES  "PENDIENTE OK OP'S", "GESTION ANDRES RIGOLI", "REPUESTOS EN
                 IMPORTACION LLEGAN EL JUEVES 23"
  PRESUPUESTO    APROBADO / PENDIENTE / GESTIONAR REPUESTOS

De sus archivos se toma eso -- el seguimiento del caso y como se cerro -- y
nada mas. Ni el local, ni el tecnico, ni las fechas, ni el equipo: todo eso se
reconstruye desde las OTs, porque los archivos llevados a mano tienen errores.

Las columnas se localizan POR NOMBRE DE ENCABEZADO, nunca por posicion: los
archivos de 2025 tienen 17 columnas y otro orden, y algunos meses de 2026
traen una columna "Columna1" intercalada que corre todo un lugar.

Uso como modulo:
    from t2_seguimiento_admin import cargar_seguimiento
    seg = cargar_seguimiento()
    dato = seg.buscar(aviso, anio, mes)     # -> dict o None
"""
from pathlib import Path

import openpyxl

ORIGEN = Path(r"D:\RESPALDOS\_ORIGEN_DRIVE\GESTION DE OTS INDUSTEC")

MESES = {"ENERO": 1, "FEBRERO": 2, "MARZO": 3, "ABRIL": 4, "MAYO": 5, "JUNIO": 6,
         "JULIO": 7, "AGOSTO": 8, "SEPTIEMBRE": 9, "OCTUBRE": 10, "NOVIEMBRE": 11,
         "DICIEMBRE": 12}

# Lo unico que se lee de estos archivos, por nombre de encabezado normalizado
CAMPOS = {
    "OBSERVACIONES": "observaciones",
    "PRESUPUESTO": "presupuesto",
    "ESTADO": "estado",
}


def _norm(v):
    return " ".join(str(v).split()).strip().upper() if v is not None else ""


def _leer_hoja(ws):
    """{aviso: {observaciones, presupuesto, estado}} de una hoja cualquiera."""
    encabezado = {_norm(c.value): c.column for c in ws[1] if c.value}
    fila_datos = 2
    if "# OT" not in encabezado:                     # algunos dejan la fila 1 vacia
        encabezado = {_norm(c.value): c.column for c in ws[2] if c.value}
        fila_datos = 3
    col_aviso = encabezado.get("# OT")
    if not col_aviso:
        return {}

    columnas = {destino: encabezado[etiqueta]
                for etiqueta, destino in CAMPOS.items() if etiqueta in encabezado}
    out = {}
    for r in range(fila_datos, ws.max_row + 1):
        aviso = _norm(ws.cell(r, col_aviso).value)
        if not aviso.isdigit():
            continue
        registro = {destino: _norm(ws.cell(r, col).value) or None
                    for destino, col in columnas.items()}
        if any(registro.values()):
            out[aviso] = registro
    return out


class Seguimiento:
    """Lo anotado por la administracion, indexado por aviso y por mes."""

    def __init__(self, por_mes, archivos):
        self.por_mes = por_mes              # (anio, mes) -> {aviso: registro}
        self.archivos = archivos
        self.meses = sorted(por_mes)

    def buscar(self, aviso, anio, mes):
        """Primero el archivo del mismo mes; si no hay, el mes mas cercano.

        Preferir el mismo mes mantiene la coherencia temporal: la observacion
        que acompana a marzo es la que ella escribio en marzo, no la que
        agrego medio ano despues.
        """
        clave = str(aviso)
        exacto = self.por_mes.get((anio, mes), {}).get(clave)
        if exacto:
            return exacto
        for a, m in sorted(self.meses, key=lambda x: abs((x[0] - anio) * 12 + x[1] - mes)):
            encontrado = self.por_mes.get((a, m), {}).get(clave)
            if encontrado:
                return encontrado
        return None

    def resumen(self):
        avisos = {a for reg in self.por_mes.values() for a in reg}
        return (f"{len(self.archivos)} archivos de la administracion leidos · "
                f"{len(avisos)} avisos con seguimiento anotado")


def _fuentes():
    """Archivos de la administracion que aportan seguimiento, con su mes."""
    fuentes = []
    for carpeta in (ORIGEN / "2026" / "PLANES SEMANALES").glob("PLAN DE TRABAJO _ *"):
        for f in (carpeta / "PLANES MENSUALES").glob("*.xlsx"):
            mes = MESES.get(f.stem.upper())
            if mes:
                fuentes.append((2026, mes, f))
        for f in carpeta.glob("PLAN SEGUIMIENTO OTS *.xlsx"):
            mes = next((m for nombre, m in MESES.items() if nombre in f.stem.upper()), None)
            if mes:
                fuentes.append((2026, mes, f))
    # El consolidado de 2025 trae otro layout (17 columnas) y una hoja por zona
    for f in (ORIGEN / "2025" / "PLANES SEMANALES" / "CONSOLIDADO MENSUAL PLANES").glob("*.xlsx"):
        mes = next((m for nombre, m in MESES.items() if nombre in f.stem.upper()), None)
        if mes:
            fuentes.append((2025, mes, f))
    return fuentes


def cargar_seguimiento():
    por_mes, archivos = {}, []
    for anio, mes, ruta in _fuentes():
        try:
            wb = openpyxl.load_workbook(ruta, data_only=True)
        except Exception as e:                      # un archivo ilegible no puede tumbar la corrida
            print(f"  aviso: no se pudo leer {ruta.name}: {e}")
            continue
        acumulado = por_mes.setdefault((anio, mes), {})
        for ws in wb.worksheets:
            acumulado.update(_leer_hoja(ws))
        archivos.append(ruta)
    return Seguimiento(por_mes, archivos)


if __name__ == "__main__":
    seg = cargar_seguimiento()
    print(seg.resumen())
    for (anio, mes), registros in sorted(seg.por_mes.items()):
        con_obs = sum(1 for r in registros.values() if r.get("observaciones"))
        con_pres = sum(1 for r in registros.values() if r.get("presupuesto"))
        print(f"  {anio}-{mes:02d}: {len(registros):4d} avisos · {con_obs:4d} con observacion "
              f"· {con_pres:4d} con presupuesto")
