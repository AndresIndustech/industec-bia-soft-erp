"""
Consola local de OTs - interfaz de revision para INDUSTEC.

Corre en la estacion, contra la MariaDB local. Sirve para lo que hoy se hace
abriendo carpetas: buscar una orden, verla completa, abrir su PDF, y sacar a
Excel lo que se acaba de filtrar para mandarlo a KFC.

POR QUE AQUI Y NO EN HOSTINGER:
Python no existe en el hosting compartido -- hace falta root y el compartido no
lo da. Y aunque existiera, esta consola hace consultas que en Hostinger se
cortarian solas: el plan Premium mata cualquier query que pase de 60 segundos.
El analisis va donde estan los datos y la potencia, que es esta maquina.

POR QUE SOLO localhost:
Se enlaza a 127.0.0.1 a proposito. La consola no pide contrasena, asi que no
puede quedar expuesta a la red. Si mas adelante INDUSTEC necesita entrar desde
fuera, se pone autenticacion primero y un tunel despues -- nunca al reves.

POR QUE FastAPI + HTMX y no React:
No hay paso de compilacion. Ni npm, ni node_modules, ni un build que mantener.
El codigo lo sostiene un agente: tiene que caber entero en una lectura y
arrancar con un comando.

USO:
    cd "D:\\INDUSTECH IA\\desarrollo\\sistema_ots\\local"
    ..\\..\\agentes\\.venv\\Scripts\\python.exe app.py
    -> http://127.0.0.1:8010
"""
import os
import subprocess
import sys
from datetime import date, datetime
from pathlib import Path

import mysql.connector
import uvicorn
from fastapi import FastAPI, Query, Request
from fastapi.responses import HTMLResponse, JSONResponse, StreamingResponse

ENV_PATH = Path(r"D:\INDUSTECH IA\desarrollo\agentes\config\.env")
PUERTO = 8010

# Rutas desde donde se permite abrir un PDF. Cualquier ruta que no cuelgue de
# una de estas se rechaza: la ruta llega en la URL, y sin esta comprobacion la
# consola serviria cualquier archivo del disco a quien supiera pedirlo.
RAICES_PERMITIDAS = [
    Path(r"D:\RESPALDOS\ORDENES DE TRABAJO"),
    Path(r"D:\RESPALDOS\INFORMES TECNICOS"),
    Path(r"D:\RESPALDOS\OTROS CLIENTES"),
    Path(r"D:\RESPALDOS\_ORIGEN_SISTEMA"),
]


def cargar_env():
    env = {}
    for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return env


ENV = cargar_env()


def conectar():
    return mysql.connector.connect(
        host=ENV["DB_HOST"], port=int(ENV["DB_PORT"]), user=ENV["DB_USER"],
        password=ENV["DB_PASSWORD"], database=ENV["DB_NAME"])


app = FastAPI(title="Consola de OTs - INDUSTEC", docs_url=None, redoc_url=None)


# ---------------------------------------------------------------------------
# Consultas
# ---------------------------------------------------------------------------

CAMPOS = """
    o.id_industec, o.zona, o.modulo, o.local_codigo, o.aviso, o.fase,
    o.dia_intervencion, o.fecha_atencion, o.tecnico_nombre, o.admin_nombre,
    o.estado_ot, o.atiempo, o.satisfaccion, o.fotos_cantidad,
    o.firma_presente, o.ruta_pdf, o.actividades, o.repuestos, o.observaciones,
    o.hora_inicio, o.hora_fin, o.tiempo_atencion_min, o.correo_local,
    l.nombre AS local_nombre, l.cadena,
    s.estatus_general AS estatus_sap, s.equipo_denominacion AS equipo_sap,
    s.fecha_notificacion, s.descripcion AS descripcion_sap
"""

BASE = f"""
    SELECT {CAMPOS}
    FROM ots o
    LEFT JOIN locales    l ON l.local_codigo = o.local_codigo
    LEFT JOIN avisos_sap s ON s.aviso        = o.aviso
    WHERE o.en_cuarentena = 0
"""


def _filtros(zona, modulo, local, aviso, estatus, desde, hasta, texto):
    """Arma el WHERE con parametros ligados.

    Todo valor del usuario va como %s, nunca concatenado. Es la unica defensa
    real contra inyeccion, y aqui importa igual que en un servidor publico: un
    campo de busqueda mal armado destruye la base local que costo la Fase 1.
    """
    cond, params = [], []
    if zona:
        cond.append("o.zona = %s"); params.append(zona)
    if modulo:
        cond.append("o.modulo = %s"); params.append(modulo)
    if local:
        cond.append("o.local_codigo = %s"); params.append(local)
    if aviso:
        cond.append("o.aviso = %s"); params.append(aviso)
    if estatus == "SIN_AVISO":
        cond.append("o.aviso IS NULL")
    elif estatus == "SIN_CATALOGO":
        cond.append("o.aviso IS NOT NULL AND s.aviso IS NULL")
    elif estatus:
        cond.append("s.estatus_general = %s"); params.append(estatus)
    if desde:
        cond.append("o.fecha_atencion >= %s"); params.append(desde)
    if hasta:
        cond.append("o.fecha_atencion <= %s"); params.append(hasta)
    if texto:
        cond.append("(o.actividades LIKE %s OR o.repuestos LIKE %s "
                    "OR o.observaciones LIKE %s OR o.tecnico_nombre LIKE %s "
                    "OR o.id_industec LIKE %s OR l.nombre LIKE %s)")
        params += [f"%{texto}%"] * 6
    return (" AND " + " AND ".join(cond) if cond else ""), params


def buscar(zona=None, modulo=None, local=None, aviso=None, estatus=None,
           desde=None, hasta=None, texto=None, limite=300):
    where, params = _filtros(zona, modulo, local, aviso, estatus, desde, hasta, texto)
    cnx = conectar()
    cur = cnx.cursor(dictionary=True)
    cur.execute("SELECT COUNT(*) AS n FROM ots o "
                "LEFT JOIN locales l ON l.local_codigo=o.local_codigo "
                "LEFT JOIN avisos_sap s ON s.aviso=o.aviso "
                "WHERE o.en_cuarentena = 0" + where, params)
    total = cur.fetchone()["n"]
    cur.execute(BASE + where + " ORDER BY o.fecha_atencion DESC, o.correlativo DESC LIMIT %s",
                params + [limite])
    filas = cur.fetchall()
    cur.close(); cnx.close()
    return filas, total


def resumen():
    cnx = conectar(); cur = cnx.cursor(dictionary=True)
    r = {}
    cur.execute("SELECT COUNT(*) n FROM ots WHERE en_cuarentena=0")
    r["total"] = cur.fetchone()["n"]
    cur.execute("""SELECT o.zona z, COUNT(*) n FROM ots o
                   WHERE o.en_cuarentena=0 GROUP BY o.zona ORDER BY o.zona""")
    r["por_zona"] = cur.fetchall()
    # El estado se lee de SAP, nunca de la senal interna del sistema (I-10).
    cur.execute("""SELECT COALESCE(s.estatus_general,
                          IF(o.aviso IS NULL,'(sin aviso)','(no consta en SAP)')) e,
                          COUNT(*) n
                   FROM ots o LEFT JOIN avisos_sap s ON s.aviso=o.aviso
                   WHERE o.en_cuarentena=0 GROUP BY e ORDER BY n DESC""")
    r["por_estatus"] = cur.fetchall()
    cur.execute("""SELECT o.fecha_atencion f, COUNT(*) n FROM ots o
                   WHERE o.en_cuarentena=0 AND o.fecha_atencion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                   GROUP BY f ORDER BY f""")
    r["ultimos_30"] = cur.fetchall()
    cur.close(); cnx.close()
    return r


def opciones():
    cnx = conectar(); cur = cnx.cursor(dictionary=True)
    cur.execute("SELECT local_codigo, nombre, zona FROM locales ORDER BY local_codigo")
    loc = cur.fetchall()
    cur.close(); cnx.close()
    return loc


# ---------------------------------------------------------------------------
# Vistas
# ---------------------------------------------------------------------------

def _v(x):
    if x is None or x == "":
        return '<span class="nulo">sin dato</span>'
    return str(x).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def _fecha(d):
    if isinstance(d, (date, datetime)):
        return d.strftime("%Y-%m-%d")
    return _v(d)


CSS = """
:root{--bg:#f6f7f9;--card:#fff;--tinta:#111827;--suave:#6b7280;--linea:#e5e7eb;
--azul:#1f4e79;--verde:#0f7b3f;--ambar:#b45309;--rojo:#b91c1c}
*{box-sizing:border-box}
body{margin:0;font:14px/1.5 system-ui,Segoe UI,sans-serif;background:var(--bg);color:var(--tinta)}
header{background:var(--azul);color:#fff;padding:12px 20px;display:flex;gap:20px;align-items:baseline}
header h1{margin:0;font-size:17px;font-weight:600}
header .sub{opacity:.75;font-size:12px}
main{padding:18px 20px;max-width:1700px}
.tarjetas{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.t{background:var(--card);border:1px solid var(--linea);border-radius:10px;padding:12px 16px;min-width:120px}
.t .n{font-size:22px;font-weight:700}
.t .e{color:var(--suave);font-size:12px;text-transform:uppercase;letter-spacing:.4px}
form.filtros{background:var(--card);border:1px solid var(--linea);border-radius:10px;
padding:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:14px}
label{display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--suave)}
input,select{padding:6px 8px;border:1px solid var(--linea);border-radius:6px;font:inherit;background:#fff}
button{padding:7px 14px;border:0;border-radius:6px;background:var(--azul);color:#fff;
font:inherit;cursor:pointer}
button.sec{background:#fff;color:var(--azul);border:1px solid var(--azul)}
table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--linea);
border-radius:10px;overflow:hidden}
th{background:var(--azul);color:#fff;text-align:left;padding:8px;font-size:12px;
text-transform:uppercase;letter-spacing:.3px;position:sticky;top:0}
td{padding:7px 8px;border-top:1px solid var(--linea);vertical-align:top}
tr:hover td{background:#f9fafb}
.nulo{color:#9ca3af;font-style:italic}
.pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600}
.CERRADO{background:#dcfce7;color:var(--verde)}
.TRATAMIENTO{background:#fef3c7;color:var(--ambar)}
.ABIERTO{background:#fee2e2;color:var(--rojo)}
.otro{background:#f3f4f6;color:var(--suave)}
a{color:var(--azul)}
.aviso{background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 12px;
margin-bottom:14px;font-size:13px}
.cuenta{color:var(--suave);font-size:12px;margin:8px 0}
dl{display:grid;grid-template-columns:210px 1fr;gap:6px 14px;margin:0}
dt{color:var(--suave);font-size:12px;text-transform:uppercase;letter-spacing:.3px}
dd{margin:0}
.bloque{background:var(--card);border:1px solid var(--linea);border-radius:10px;
padding:16px;margin-bottom:14px}
.bloque h2{margin:0 0 12px;font-size:14px;text-transform:uppercase;letter-spacing:.4px;color:var(--suave)}
pre{white-space:pre-wrap;margin:0;font:inherit}
@media print{header,form.filtros,.noimp{display:none}body{background:#fff}
.bloque{border:0;padding:0}}
"""


def pagina(titulo, cuerpo):
    return HTMLResponse(f"""<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{titulo}</title><style>{CSS}</style></head><body>
<header><h1>Consola de OTs &middot; INDUSTEC</h1>
<span class="sub">MariaDB local &middot; solo lectura &middot; 127.0.0.1</span>
<span class="sub" style="margin-left:auto"><a href="/" style="color:#fff">Buscar</a>
&nbsp;&middot;&nbsp;<a href="/tablero" style="color:#fff">Tablero</a></span></header>
<main>{cuerpo}</main></body></html>""")


def _estatus_pill(e):
    clase = e if e in ("CERRADO", "TRATAMIENTO", "ABIERTO") else "otro"
    return f'<span class="pill {clase}">{_v(e or "sin dato")}</span>'


@app.get("/", response_class=HTMLResponse)
def inicio(zona: str = "", modulo: str = "", local: str = "", aviso: str = "",
           estatus: str = "", desde: str = "", hasta: str = "", texto: str = "",
           limite: int = Query(300, le=5000)):
    filas, total = buscar(zona or None, modulo or None, local or None,
                          aviso or None, estatus or None, desde or None,
                          hasta or None, texto or None, limite)
    locales = opciones()

    def sel(nombre, actual, items, vacio="Todas"):
        op = f'<option value="">{vacio}</option>'
        for val, txt in items:
            s = " selected" if str(val) == str(actual) else ""
            op += f'<option value="{val}"{s}>{txt}</option>'
        return f'<select name="{nombre}">{op}</select>'

    filtros = f"""<form class="filtros" method="get">
<label>Zona{sel("zona", zona, [(z, z) for z in ("UIO","LARB","CNLJ","OTRA")])}</label>
<label>Tipo{sel("modulo", modulo, [(m, m) for m in ("CORRECTIVO","PREVENTIVO","OTROS")], "Todos")}</label>
<label>Local{sel("local", local, [(l["local_codigo"], f'{l["local_codigo"]} - {l["nombre"]}') for l in locales], "Todos")}</label>
<label>Estado segun SAP{sel("estatus", estatus, [("CERRADO","CERRADO"),("TRATAMIENTO","TRATAMIENTO"),("ABIERTO","ABIERTO"),("SIN_AVISO","(sin aviso)"),("SIN_CATALOGO","(no consta en SAP)")], "Todos")}</label>
<label># OT / aviso<input name="aviso" value="{_v(aviso) if aviso else ''}" placeholder="10334255" size="10"></label>
<label>Desde<input type="date" name="desde" value="{desde}"></label>
<label>Hasta<input type="date" name="hasta" value="{hasta}"></label>
<label>Texto libre<input name="texto" value="{_v(texto) if texto else ''}" placeholder="tecnico, actividad, repuesto..." size="24"></label>
<button type="submit">Buscar</button>
<button type="button" class="sec" onclick="location='/'">Limpiar</button>
</form>"""

    qs = "&".join(f"{k}={v}" for k, v in
                  (("zona", zona), ("modulo", modulo), ("local", local), ("aviso", aviso),
                   ("estatus", estatus), ("desde", desde), ("hasta", hasta), ("texto", texto))
                  if v)

    cuenta = (f'<div class="cuenta">{total:,} orden(es) coinciden. '
              f'{"Se muestran las " + format(len(filas), ",") + " mas recientes. " if total > len(filas) else ""}'
              f'<a href="/exportar?{qs}">Exportar a Excel</a></div>')

    cab = ("<tr><th>OT INDUSTEC</th><th>Zona</th><th>Local</th><th># OT (aviso)</th>"
           "<th>Estado SAP</th><th>Fecha</th><th>Tecnico</th><th>Equipo (SAP)</th>"
           "<th>Fotos</th><th></th></tr>")
    cuerpo_tabla = ""
    for f in filas:
        est = f["estatus_sap"] or ("(sin aviso)" if not f["aviso"] else "(no consta)")
        cuerpo_tabla += (
            f'<tr><td><a href="/ot/{f["id_industec"]}">{_v(f["id_industec"])}</a></td>'
            f'<td>{_v(f["zona"])}</td>'
            f'<td>{_v(f["local_codigo"])}<br><span class="nulo">{_v(f["local_nombre"])}</span></td>'
            f'<td>{_v(f["aviso"])}</td>'
            f'<td>{_estatus_pill(est)}</td>'
            f'<td>{_fecha(f["fecha_atencion"])}</td>'
            f'<td>{_v(f["tecnico_nombre"])}</td>'
            f'<td>{_v(f["equipo_sap"])}</td>'
            f'<td>{_v(f["fotos_cantidad"])}</td>'
            f'<td class="noimp">{"<a href=/pdf/" + f["id_industec"] + ">PDF</a>" if f["ruta_pdf"] else ""}</td></tr>')

    if not filas:
        cuerpo_tabla = '<tr><td colspan="10" class="nulo">Ninguna orden coincide con esos filtros.</td></tr>'

    return pagina("Buscar OTs", filtros + cuenta +
                  f"<table>{cab}{cuerpo_tabla}</table>")


@app.get("/tablero", response_class=HTMLResponse)
def tablero():
    r = resumen()
    tarjetas = f'<div class="t"><div class="n">{r["total"]:,}</div><div class="e">Ordenes</div></div>'
    for z in r["por_zona"]:
        tarjetas += f'<div class="t"><div class="n">{z["n"]:,}</div><div class="e">{z["z"]}</div></div>'

    fs = "".join(f'<tr><td>{_estatus_pill(e["e"])}</td><td style="text-align:right">{e["n"]:,}</td></tr>'
                 for e in r["por_estatus"])

    ult = r["ultimos_30"]
    if ult:
        mx = max(d["n"] for d in ult) or 1
        barras = "".join(
            f'<div title="{_fecha(d["f"])}: {d["n"]}" style="flex:1;display:flex;'
            f'flex-direction:column;justify-content:flex-end;height:110px">'
            f'<div style="background:#1f4e79;height:{max(3, int(100*d["n"]/mx))}%;border-radius:2px 2px 0 0"></div>'
            f'</div>' for d in ult)
        graf = (f'<div style="display:flex;gap:2px;align-items:flex-end">{barras}</div>'
                f'<div class="cuenta">{_fecha(ult[0]["f"])} &rarr; {_fecha(ult[-1]["f"])} '
                f'&middot; maximo en un dia: {mx}</div>')
    else:
        graf = ('<p class="nulo">Ninguna orden con fecha en los ultimos 30 dias. '
                'Es lo esperado mientras no corra la sincronizacion con Hostinger.</p>')

    return pagina("Tablero", f"""
<div class="tarjetas">{tarjetas}</div>
<div class="bloque"><h2>Estado segun SAP</h2>
<p class="cuenta">SAP es el unico criterio de cierre del proyecto. Lo que el tecnico
marco en el formulario se muestra en el detalle de cada orden, y no se usa como criterio.</p>
<table style="max-width:460px">{fs}</table></div>
<div class="bloque"><h2>Ordenes por dia, ultimos 30 dias</h2>{graf}</div>""")


@app.get("/ot/{id_industec}", response_class=HTMLResponse)
def detalle(id_industec: str):
    cnx = conectar(); cur = cnx.cursor(dictionary=True)
    cur.execute(BASE + " AND o.id_industec = %s", [id_industec])
    f = cur.fetchone()
    equipos = []
    if f:
        cur.execute("SELECT * FROM ot_equipos WHERE id_industec = %s", [id_industec])
        equipos = cur.fetchall()
    cur.close(); cnx.close()
    if not f:
        return pagina("No encontrada",
                      f'<div class="bloque"><p>No existe una orden con el identificador '
                      f'<strong>{_v(id_industec)}</strong>.</p><a href="/">Volver</a></div>')

    est = f["estatus_sap"] or ("(sin aviso)" if not f["aviso"] else "(no consta en SAP)")
    pdf = (f'<a href="/pdf/{f["id_industec"]}">Abrir el PDF</a>' if f["ruta_pdf"]
           else '<span class="nulo">sin PDF localizado</span>')

    def dl(pares):
        return "<dl>" + "".join(f"<dt>{k}</dt><dd>{v}</dd>" for k, v in pares) + "</dl>"

    ident = dl([
        ("OT INDUSTEC", f'<strong>{_v(f["id_industec"])}</strong>'),
        ("Zona / Tipo", f'{_v(f["zona"])} &middot; {_v(f["modulo"])}'
                        + (f' &middot; dia {f["dia_intervencion"]}' if f["dia_intervencion"] else "")),
        ("Local", f'{_v(f["local_codigo"])} &mdash; {_v(f["local_nombre"])} ({_v(f["cadena"])})'),
        ("# OT (aviso SAP)", _v(f["aviso"])),
        ("Estado segun SAP", _estatus_pill(est)),
        ("Marco el tecnico", _v(f["estado_ot"]) +
            ' <span class="nulo">(informacion complementaria, no es criterio de cierre)</span>'),
        ("Archivo", pdf),
    ])
    atencion = dl([
        ("Fecha de atencion", _fecha(f["fecha_atencion"])),
        ("Horario", f'{_v(f["hora_inicio"])} &rarr; {_v(f["hora_fin"])}'),
        ("Tiempo de atencion", (f'{f["tiempo_atencion_min"]} min' if f["tiempo_atencion_min"]
                                else '<span class="nulo">no calculable: la hora de fin no es '
                                     'posterior a la de inicio</span>')),
        ("Tecnico", _v(f["tecnico_nombre"])),
        ("Administrador del local", _v(f["admin_nombre"])),
        ("Correo del local", _v(f["correo_local"])),
        ("A tiempo", _v(f["atiempo"])),
        ("Calificacion", f'{f["satisfaccion"]}/10' if f["satisfaccion"] is not None
                         else '<span class="nulo">sin dato</span>'),
        ("Fotos / firma", f'{_v(f["fotos_cantidad"])} foto(s) &middot; '
                          f'{"con firma" if f["firma_presente"] else "sin firma"}'),
    ])
    sap = dl([
        ("Equipo (SAP)", _v(f["equipo_sap"])),
        ("Notificado en SAP", _fecha(f["fecha_notificacion"])),
        ("Descripcion del aviso", _v(f["descripcion_sap"])),
    ])

    eq = ""
    if equipos:
        filas_eq = "".join(
            "<tr>" + "".join(f"<td>{_v(v)}</td>" for k, v in e.items()
                             if k not in ("id", "id_industec")) + "</tr>"
            for e in equipos)
        cols = "".join(f"<th>{k}</th>" for k in equipos[0] if k not in ("id", "id_industec"))
        eq = (f'<div class="bloque"><h2>Equipos ({len(equipos)})</h2>'
              f'<table><tr>{cols}</tr>{filas_eq}</table></div>')

    def txt(t, v):
        return (f'<div class="bloque"><h2>{t}</h2><pre>{_v(v)}</pre></div>'
                if v else "")

    return pagina(f["id_industec"], f"""
<div class="noimp" style="margin-bottom:12px">
  <a href="/">&larr; Volver</a> &nbsp;&middot;&nbsp;
  <button class="sec" onclick="window.print()">Imprimir</button>
</div>
<div class="bloque"><h2>Identificacion</h2>{ident}</div>
<div class="bloque"><h2>Atencion</h2>{atencion}</div>
<div class="bloque"><h2>Lo que dice SAP</h2>{sap}</div>
{eq}
{txt("Actividades realizadas", f["actividades"])}
{txt("Repuestos", f["repuestos"])}
{txt("Observaciones", f["observaciones"])}""")


@app.get("/pdf/{id_industec}")
def abrir_pdf(id_industec: str):
    """Abre el PDF con el visor del sistema.

    La ruta NO viene de la URL sino de la base, y aun asi se comprueba contra la
    lista de raices permitidas: si un dia alguien escribe una ruta arbitraria en
    la columna ruta_pdf, esto no la abre.
    """
    cnx = conectar(); cur = cnx.cursor(dictionary=True)
    cur.execute("SELECT ruta_pdf FROM ots WHERE id_industec = %s", [id_industec])
    r = cur.fetchone()
    cur.close(); cnx.close()
    if not r or not r["ruta_pdf"]:
        return JSONResponse({"error": "esa orden no tiene PDF registrado"}, status_code=404)

    p = Path(r["ruta_pdf"]).resolve()
    if not any(p.is_relative_to(raiz) for raiz in RAICES_PERMITIDAS):
        return JSONResponse({"error": "la ruta del archivo esta fuera de las carpetas permitidas"},
                            status_code=403)
    if not p.is_file():
        return JSONResponse({"error": f"el archivo no esta en el disco: {p}"}, status_code=404)

    if sys.platform == "win32":
        os.startfile(str(p))  # noqa: S606
    else:
        subprocess.Popen(["xdg-open", str(p)])
    return HTMLResponse('<p>Abriendo el PDF en el visor del sistema. '
                        '<a href="javascript:history.back()">Volver</a></p>')


@app.get("/exportar")
def exportar(zona: str = "", modulo: str = "", local: str = "", aviso: str = "",
             estatus: str = "", desde: str = "", hasta: str = "", texto: str = ""):
    """Excel de exactamente lo que se acaba de filtrar.

    Es lo que se manda a KFC: se filtra en pantalla, se comprueba a ojo, y se
    exporta lo mismo que se vio. Sin limite de filas, a diferencia de la tabla.
    """
    import io
    import openpyxl
    from openpyxl.styles import Font, PatternFill

    filas, _ = buscar(zona or None, modulo or None, local or None, aviso or None,
                      estatus or None, desde or None, hasta or None, texto or None,
                      limite=100000)
    cols = ["id_industec", "zona", "modulo", "local_codigo", "local_nombre", "cadena",
            "aviso", "estatus_sap", "fecha_atencion", "tecnico_nombre", "equipo_sap",
            "fase", "estado_ot", "atiempo", "satisfaccion", "fotos_cantidad", "ruta_pdf"]
    titulos = ["OT INDUSTEC", "ZONA", "TIPO", "LOCAL", "NOMBRE DEL LOCAL", "CADENA",
               "# OT (AVISO SAP)", "ESTATUS SAP", "FECHA DE ATENCION", "TECNICO",
               "EQUIPO (SAP)", "FASE", "MARCO EL TECNICO", "A TIEMPO", "CALIFICACION",
               "FOTOS", "ARCHIVO"]

    wb = openpyxl.Workbook(); ws = wb.active; ws.title = "OTS"
    ws.append(titulos)
    col_fecha = cols.index("fecha_atencion") + 1
    for i in range(1, len(titulos) + 1):
        c = ws.cell(1, i)
        c.font = Font(bold=True, color="FFFFFF")
        c.fill = PatternFill(start_color="1F4E79", end_color="1F4E79", fill_type="solid")
    for f in filas:
        # Mismas etiquetas que el catalogo de SALIDAS IA\OTS. Que dos entregables
        # que lee la misma persona digan cosas distintas del mismo dato es
        # peor que no decir nada: una celda vacia se lee como "no averiguado".
        if not f.get("estatus_sap"):
            f["estatus_sap"] = "(sin aviso en SAP)" if not f.get("aviso") else "(no consta)"
        ws.append([f.get(c) for c in cols])
        ws.cell(ws.max_row, col_fecha).number_format = "YYYY-MM-DD"
    ws.freeze_panes = "A2"
    for col in ws.columns:
        m = max((len(str(c.value)) for c in col if c.value), default=10)
        ws.column_dimensions[col[0].column_letter].width = min(m + 2, 60)

    buf = io.BytesIO(); wb.save(buf); buf.seek(0)
    nombre = f"OTS {datetime.now():%Y-%m-%d %H%M} (generado agente).xlsx"
    return StreamingResponse(
        buf, media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": f'attachment; filename="{nombre}"'})


@app.get("/salud")
def salud():
    """Para el bot de Telegram y para comprobar de un vistazo que la base responde."""
    try:
        r = resumen()
        return {"ok": True, "ordenes": r["total"],
                "por_zona": {z["z"]: z["n"] for z in r["por_zona"]}}
    except Exception as e:
        return JSONResponse({"ok": False, "error": str(e)}, status_code=500)


if __name__ == "__main__":
    print(f"Consola de OTs -> http://127.0.0.1:{PUERTO}")
    print("Solo escucha en 127.0.0.1: no pide contrasena, asi que no puede quedar expuesta.")
    uvicorn.run(app, host="127.0.0.1", port=PUERTO, log_level="warning")
