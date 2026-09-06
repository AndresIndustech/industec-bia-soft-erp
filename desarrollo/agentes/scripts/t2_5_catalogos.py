"""
T2.5.1 - Catalogos que alimentan el formulario unico.

Sin estos catalogos, "validar" es teatro: se puede rechazar lo que esta mal
escrito, pero no se le puede ofrecer al tecnico lo correcto, y entonces el
tecnico escribe libre igual. Estos archivos son lo que convierte cada campo de
texto libre en una lista cerrada.

DE DONDE SALE CADA UNO Y CON QUE COBERTURA REAL (medido, no estimado):

  locales    100 de 100. Maestro de la Fase 1, ya verificado contra la hoja
             GENERAL. Incluye correos, para que el tecnico no los teclee.

  equipos    1.213 activos de SAP, cada uno atado a UN local, cubriendo 94 de
             los 100 locales. 780 traen codigo de activo fijo, tipo y area
             porque su denominacion sigue el patron
             {codigo:6}_{I}_{CLASE}_{tipo}; los otros 433 solo traen el nombre
             del tipo. Los 6 locales sin catalogo caen al catalogo de tipos.

  tipos      222 tipos normalizados, extraidos de las denominaciones de SAP.
             Es la red de seguridad: un local sin activos catalogados igual
             elige de una lista cerrada en vez de teclear.

  tecnicos   Los activos, con su zona.

POR QUE ESTO IMPORTA, con las cifras que lo motivan:
  - El campo `equipo` escrito a mano acumulo 807 grafias distintas para lo que
    SAP nombra con 222 tipos. Casi cuatro veces mas variantes que conceptos.
  - El campo `repuestos` tiene 1.953 valores distintos en 4.359 filas, y los 8
    mas frecuentes son todos formas de decir "ninguno" (S/N, Sn, Ninguno,
    Ninguna, Sin repuestos, -, N/A, Sin respuestos): 2.147 filas, el 49%.
    Por eso el formulario unico pregunta primero SI hubo repuesto, con una
    casilla, y solo entonces pide cual. Esa sola casilla elimina la mitad del
    ruido por construccion, sin necesidad de catalogo.

NO INVENTA NADA. Un local sin equipos catalogados sale listado como tal en
COBERTURA.md, no se le rellena con equipos de otro local parecido (I-6).

USO:
    .venv/Scripts/python.exe scripts/t2_5_catalogos.py
"""
import json
import re
import sys
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

import mysql.connector

sys.path.insert(0, str(Path(__file__).parent))
from t1_6b_resolver_cuarentena import normalizar_local_extendido  # noqa: E402

ENV_PATH = Path(r"D:\INDUSTECH IA\desarrollo\agentes\config\.env")
SALIDA = Path(r"D:\INDUSTECH IA\SALIDAS IA\OTS\catalogos")

# La denominacion de SAP viene como 007760_I_MAQYEQ_MESA MIXTA: codigo de
# activo fijo, una letra de clasificacion, la clase (MAQYEQ = maquinaria y
# equipo, ACTCON = activos en construccion) y el tipo legible. El 64,3% de los
# activos la sigue; el resto trae solo el nombre del tipo.
RE_DENOM = re.compile(r"^(\d{6})_([A-Z])_([A-Z]+)_(.+)$")

# Ultimo segmento de la ubicacion tecnica RINT-E020-2001-KFC-EK146-CALIE.
# Sirve para agrupar el desplegable por area y que el tecnico encuentre el
# equipo donde esta parado, en vez de recorrer 35 items.
AREAS = {
    "CALIE": "Cocina caliente",
    "COYRE": "Conservacion y refrigeracion",
    "CONGE": "Congelacion",
    "CLYVE": "Climatizacion y ventilacion",
    "OTROS": "Otros",
}


def cargar_env():
    env = {}
    for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return env


def conectar(env):
    return mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])


def _mil(n):
    """Separador de miles en espanol: 1.953, no 1,953. El informe lo lee la
    administracion ecuatoriana y el formato ingles se lee como decimal."""
    return f"{n:,}".replace(",", ".")


def normalizar_tipo(t):
    """Un tipo de equipo, en mayusculas y sin espacios de sobra.

    No se hace mas que esto a proposito. Unificar 'REFRIGERADORA VERTICAL' con
    'REFRIGERADOR VERTICAL' seria decidir que son lo mismo, y eso lo decide
    INDUSTEC, no un script (I-6). Se listan juntos en COBERTURA.md para que
    alguien resuelva si son uno o dos.
    """
    return re.sub(r"\s+", " ", (t or "").strip()).upper()


def construir(cnx):
    cur = cnx.cursor(dictionary=True)

    cur.execute("""SELECT local_codigo, zona, cadena, nombre, correo_local, correo_jefe_op
                   FROM locales WHERE activo = 1 ORDER BY local_codigo""")
    locales = cur.fetchall()
    canonicos = {l["local_codigo"] for l in locales}

    cur.execute("SELECT alias_texto, local_codigo FROM locales_alias")
    alias = {re.sub(r"[^A-Za-z0-9]", "", r["alias_texto"]).upper(): r["local_codigo"]
             for r in cur.fetchall()}

    cur.execute("""SELECT DISTINCT equipo_sap, equipo_denominacion, ubicacion_tecnica,
                          centro_coste
                   FROM avisos_sap
                   WHERE equipo_sap IS NOT NULL AND centro_coste IS NOT NULL""")
    crudos = cur.fetchall()

    cur.execute("""SELECT tecnico_id, nombres, apellidos, tipo_tecnico, zona_asignada
                   FROM tecnicos WHERE activo = 1 ORDER BY apellidos, nombres""")
    tecnicos = cur.fetchall()

    # El desorden actual, medido en el momento. Es el argumento del catalogo:
    # si estas cifras bajan solas algun dia, el catalogo dejo de hacer falta.
    cur.execute("""SELECT COUNT(DISTINCT equipo) n FROM ot_equipos
                   WHERE equipo IS NOT NULL AND equipo <> ''""")
    desorden = {"grafias_equipo": cur.fetchone()["n"]}
    cur.execute("""SELECT COUNT(*) filas, COUNT(DISTINCT repuestos) distintos
                   FROM ots WHERE repuestos IS NOT NULL AND repuestos <> ''""")
    r = cur.fetchone()
    desorden["repuestos_filas"], desorden["repuestos_distintos"] = r["filas"], r["distintos"]
    # Las formas de decir "no se uso repuesto". La lista es la observada, no una
    # suposicion: salio de mirar los valores mas frecuentes del campo.
    cur.execute("""SELECT COUNT(*) n FROM ots WHERE repuestos IS NOT NULL
                   AND UPPER(REPLACE(REPLACE(repuestos,'.',''),' ','')) IN
                   ('S/N','SN','NINGUNO','NINGUNA','SINREPUESTOS','SINREPUESTO',
                    '-','N/A','NA','SINRESPUESTOS','0','NINGUN','SINREPUESTOSUSADOS')""")
    desorden["repuestos_vacios"] = cur.fetchone()["n"]
    cur.close()

    # El centro de coste de SAP viene sin el sufijo EC (A010, K146) mientras el
    # maestro usa A010EC. Se resuelve con el mismo criterio de T1.6b.
    equipos = defaultdict(list)
    sin_resolver, tipos = [], Counter()
    for e in crudos:
        local, _ = normalizar_local_extendido(e["centro_coste"], canonicos, alias)
        if not local:
            sin_resolver.append(e["centro_coste"])
            continue
        denom = e["equipo_denominacion"] or ""
        m = RE_DENOM.match(denom)
        if m:
            codigo, _, clase, tipo = m.groups()
        else:
            codigo, clase, tipo = None, None, denom
        tipo = normalizar_tipo(tipo)
        if not tipo:
            continue
        tipos[tipo] += 1
        area_cod = (e["ubicacion_tecnica"] or "").split("-")[-1]
        equipos[local].append({
            "equipo_sap": str(e["equipo_sap"]),
            "codigo_activo": codigo,
            "tipo": tipo,
            "clase": clase,
            "area": AREAS.get(area_cod, "Otros"),
            "ubicacion_tecnica": e["ubicacion_tecnica"],
        })

    for lst in equipos.values():
        lst.sort(key=lambda x: (x["area"], x["tipo"], x["codigo_activo"] or ""))

    return locales, dict(equipos), tipos, tecnicos, sin_resolver, desorden


def main():
    env = cargar_env()
    cnx = conectar(env)
    locales, equipos, tipos, tecnicos, sin_resolver, desorden = construir(cnx)
    cnx.close()

    SALIDA.mkdir(parents=True, exist_ok=True)
    sello = datetime.now().strftime("%Y-%m-%d %H:%M")

    def guardar(nombre, datos):
        p = SALIDA / nombre
        p.write_text(json.dumps(
            {"generado": sello, "datos": datos}, indent=1, ensure_ascii=False),
            encoding="utf-8")
        return p

    guardar("locales.json", [{
        "codigo": l["local_codigo"], "nombre": l["nombre"], "cadena": l["cadena"],
        "zona": l["zona"], "correo_local": l["correo_local"],
        "correo_jefe_op": l["correo_jefe_op"],
    } for l in locales])

    guardar("equipos_por_local.json", equipos)
    guardar("tipos_equipo.json", [{"tipo": t, "activos": n}
                                  for t, n in sorted(tipos.items())])
    guardar("tecnicos.json", [{
        "id": t["tecnico_id"],
        "nombre": f'{t["nombres"]} {t["apellidos"]}'.strip(),
        "tipo": t["tipo_tecnico"], "zona": t["zona_asignada"],
    } for t in tecnicos])

    # -------- informe de cobertura, honesto --------
    con_equipos = [l for l in locales if equipos.get(l["local_codigo"])]
    sin_equipos = [l for l in locales if not equipos.get(l["local_codigo"])]
    total_eq = sum(len(v) for v in equipos.values())
    con_codigo = sum(1 for v in equipos.values() for e in v if e["codigo_activo"])
    sin_correo = [l for l in locales if not l["correo_local"]]

    # Tipos que se parecen entre si. No se unifican: se listan para que una
    # persona decida si son el mismo equipo o dos distintos.
    def raiz(t):
        return re.sub(r"[AO]S?$", "", t.replace(" ", ""))[:14]
    parecidos = defaultdict(list)
    for t in tipos:
        parecidos[raiz(t)].append(t)
    sospechosos = {k: sorted(v) for k, v in parecidos.items() if len(v) > 1}

    lineas = [
        "# Cobertura de los catalogos del formulario unico",
        "",
        f"Generado el **{sello}**. Estos archivos son los que el formulario nuevo",
        "consume para que ningun campo identificador se escriba a mano.",
        "",
        "## Qué cubre cada catálogo",
        "",
        "| Catálogo | Cobertura | Qué pasa con lo que no cubre |",
        "|---|---|---|",
        f"| `locales.json` | **{len(locales)} de {len(locales)}** locales activos | — |",
        f"| `equipos_por_local.json` | **{len(con_equipos)} de {len(locales)}** locales, "
        f"{total_eq} activos | Los {len(sin_equipos)} restantes usan `tipos_equipo.json` |",
        f"| `tipos_equipo.json` | **{len(tipos)}** tipos distintos | Red de seguridad: "
        "lista cerrada aunque el local no tenga activos catalogados |",
        f"| `tecnicos.json` | **{len(tecnicos)}** técnicos activos | — |",
        "",
        f"De los {total_eq} activos, **{con_codigo} traen código de activo fijo** "
        f"({100 * con_codigo / total_eq:.1f}%) porque su denominación en SAP sigue el patrón "
        f"`{{código:6}}_{{I}}_{{CLASE}}_{{tipo}}`. Los otros {total_eq - con_codigo} solo traen "
        "el nombre del tipo: se pueden elegir igual, pero no aportan código de activo.",
        "",
        "## Por qué esto no es cosmético",
        "",
        "| Campo | Hoy, escrito a mano | Con catálogo |",
        "|---|---|---|",
        f"| Equipo | **{desorden['grafias_equipo']} grafías distintas** para {len(tipos)} "
        f"tipos reales | Lista cerrada por local |",
        f"| Local | **158 grafías** en los 3 meses del sistema vivo, solo 26 con sufijo `EC` "
        f"| Los {len(locales)} del maestro |",
        f"| Repuestos | {_mil(desorden['repuestos_distintos'])} valores distintos en "
        f"{_mil(desorden['repuestos_filas'])} filas; **{_mil(desorden['repuestos_vacios'])} "
        f"({100 * desorden['repuestos_vacios'] / max(desorden['repuestos_filas'], 1):.0f}%) "
        f"solo dicen \"ninguno\"** de distintas formas | Casilla *¿hubo repuesto?* primero |",
        "",
    ]

    if sin_equipos:
        lineas += [
            f"## Los {len(sin_equipos)} locales sin activos catalogados en SAP",
            "",
            "No se les inventa un catálogo. En el formulario eligen de `tipos_equipo.json`,",
            "y quedan marcados para pedirle a Grupo KFC el registro de activos de esos locales.",
            "",
            "| Local | Nombre | Cadena | Zona |",
            "|---|---|---|---|",
        ] + [f'| `{l["local_codigo"]}` | {l["nombre"]} | {l["cadena"]} | {l["zona"]} |'
             for l in sin_equipos] + [""]

    if sin_correo:
        lineas += [
            f"## {len(sin_correo)} local{'es' if len(sin_correo) != 1 else ''} sin correo en el maestro",
            "",
            "El formulario no puede autocompletar su destinatario. Hay que conseguirlos",
            "antes de que T2.1.4 pueda quitar el campo editable.",
            "",
        ] + [f'- `{l["local_codigo"]}` — {l["nombre"]}' for l in sin_correo] + [""]

    if sospechosos:
        lineas += [
            f"## {len(sospechosos)} pares de tipos que podrían ser el mismo equipo",
            "",
            "**No se unificaron.** Decidir que `REFRIGERADORA VERTICAL` y `REFRIGERADOR",
            "VERTICAL` son lo mismo es una decisión de INDUSTEC, no de un script. Mientras",
            "no se resuelva, ambos siguen en la lista y el técnico puede elegir cualquiera.",
            "",
        ] + [f"- {' · '.join(v)}" for v in sorted(sospechosos.values())][:40] + [""]

    if sin_resolver:
        lineas += [
            f"## {len(set(sin_resolver))} centros de coste de SAP que no resuelven contra el maestro",
            "",
            "Sus equipos no entraron a ningún catálogo:",
            "",
        ] + [f"- `{c}`" for c in sorted(set(sin_resolver))] + [""]

    (SALIDA / "COBERTURA.md").write_text("\n".join(lineas), encoding="utf-8")

    print(f"Catalogos en {SALIDA}")
    print(f"  locales            : {len(locales)}")
    print(f"  locales con equipos: {len(con_equipos)} de {len(locales)}")
    print(f"  activos            : {total_eq} ({con_codigo} con codigo de activo fijo)")
    print(f"  tipos de equipo    : {len(tipos)}")
    print(f"  tecnicos activos   : {len(tecnicos)}")
    if sin_equipos:
        print(f"  SIN equipos        : {len(sin_equipos)} locales -> ver COBERTURA.md")
    if sin_correo:
        print(f"  SIN correo         : {len(sin_correo)} locales -> ver COBERTURA.md")
    if sospechosos:
        print(f"  tipos parecidos    : {len(sospechosos)} pares por decidir")


if __name__ == "__main__":
    main()
