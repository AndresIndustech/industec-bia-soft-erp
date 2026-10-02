"""
T2.6 - Lector de avisos SAP desde el buzon de INDUSTEC. SOLO LECTURA DEL BUZON.

QUE HACE:
Grupo KFC notifica cada orden de trabajo por correo, desde sgerente@kfc.com.ec,
con copia a servicioalcliente@industec.me y al buzon de la ZONA. Este script los
lee, extrae el caso y lo publica para que la administracion y el jefe de zona lo
asignen a un tecnico. NO asigna: asignar es una decision humana.

LO UNICO QUE ESCRIBE (T2.28.8), y SOLO en la estacion: las decisiones que la
administracion toma en el buzon sobre las ordenes sin local (`recoger_alias`).
Da de alta el alias en la base de la estacion y marca la decision APLICADO o
RECHAZADO en el servidor. Aplicarlas es cosa de UN equipo -el que tiene
`ROBOT_APLICA_DECISIONES=1` en config/.env-, porque t2_11 y t2_12 piden «corre
antes t2_6» y otro equipo con su propia copia de la base lo correria de verdad:
no tiene los alias de la estacion y marcaria RECHAZADO en falso lo ya aplicado.
Sin esa marca solo lee y lo dice.

LO QUE NUNCA HACE (directiva del cliente, 2026-09-08):
No marca como leido, no mueve, no borra, no responde, no crea carpetas. La
sesion abre el buzon con EXAMINE (readonly=True) y lee con BODY.PEEK[], que es
la unica forma de leer sin encender la bandera \\Seen. Verificacion:

    grep -nE "M\\.(store|copy|expunge|append|create|rename|setacl)\\(|BODY\\[[^]]" \\
         scripts/t2_6_imap_avisos.py

no debe devolver nada. (BODY.PEEK[] si aparece: es la lectura que no marca.)

LO QUE SE MIDIO ANTES DE ESCRIBIRLO (1.031 correos reales en el buzon):

  1. EL HTML ES UTF-8 DE VERDAD, y hay que decodificarlo estricto. Se comprobo
     byte a byte: "creo" viaja como c3 b3, que es UTF-8 correcto, y utf-8
     estricto no falla en ninguno de los correos mirados. Lo contrario --
     forzar cp1252 "por si acaso" -- SI rompe: cp1252 no puede decodificar los
     bytes 0x81 y 0x8d que aparecen en varios mensajes. Por eso el orden es
     utf-8 primero y nunca errors='replace', que convertiria el problema en '?'
     y lo esconderia.
     (Ojo al depurar: la consola de Windows muestra "cre?" aunque el dato este
     bien. Eso es la codepage del terminal, no el correo.)

  2. NO TODO ES UN ALTA. Sobre 400 correos: 395 "Se ha creado la orden de
     trabajo", 3 "Se ha ELIMINADO la orden de trabajo". Un caso se puede anular.
     Ignorar las bajas dejaria al tecnico eligiendo ordenes muertas.

  3. EL CODIGO DE RESTAURANTE NO SIEMPRE ES R###. Aparece CN42W0000xxx. El
     numero de orden es {codigo_local}W{7 digitos}, con el codigo variable.

  4. EL AVISO LLEVA CEROS A LA IZQUIERDA: 000010353373 -> 10353373. Es la misma
     normalizacion de Nivel 1 que ya hizo T1.6b.

  5. LA ZONA TIENE DOS FUENTES INDEPENDIENTES: el buzon de zona que va en copia
     (jefezona-uio / jefezonacuenca-loja / jefetecniconacional) y el local
     resuelto contra el maestro. Se comparan y, si discrepan, se REPORTA en vez
     de elegir una (I-10). Nunca se inventa la zona.

  6. "Restaurante:" Y "Usuario:" NO ESTAN EN LA TABLA. Van en parrafos sueltos,
     como <p><strong>Restaurante:</strong>R002 QUICENTRO NORTE</p>. Buscarlos
     entre las celdas <td>/<th> devuelve None para todos -- pasó en la primera
     corrida, con 924 casos sin local.

Uso:
    .venv/Scripts/python.exe scripts/t2_6_imap_avisos.py            # ultimos 90 dias
    .venv/Scripts/python.exe scripts/t2_6_imap_avisos.py --dias 365
    .venv/Scripts/python.exe scripts/t2_6_imap_avisos.py --todo
"""

import argparse
import email
import html as htmlmod
import imaplib
import json
import os
import re
import sys
import time
import unicodedata
from datetime import date, datetime, timedelta
from email.header import decode_header, make_header
from pathlib import Path

import mysql.connector

sys.path.insert(0, str(Path(__file__).parent))
from comun import CONFIG, ENV_PATH, SALIDAS, escribir_json_atomico  # noqa: E402  (rutas relativas al repositorio, T2.15.1)
ALCANCE_PATH = CONFIG / "alcance_trabajos.json"
SALIDA = SALIDAS

REMITENTE = "sgerente@kfc.com.ec"

# El buzon de zona que va en copia. Es la segunda senal, independiente del local.
ZONA_POR_BUZON = {
    "jefezona-uio@industec.me": "UIO",
    "jefezonacuenca-loja@industec.me": "CNLJ",
    "jefetecniconacional@industec.me": "LARB",
}

# "SIR - Se ha creado|eliminado la orden de trabajo R002W0001136"
RE_ASUNTO = re.compile(
    r"se\s+ha\s+(creado|eliminado)\s+la\s+orden\s+de\s+trabajo\s+([A-Za-z0-9]+W\d+)",
    re.I)

# Las 8 columnas de la tabla, en el orden en que SAP las emite.
COLUMNAS = ["Prioridad", "Aviso SAP", "F.Creacion", "F.Estimada", "Tipo Trabajo",
            "Detalle Trabajo", "Activo Fijo", "Descripcion Breve"]

RE_CELDA = re.compile(r"<t[dh][^>]*>(.*?)</t[dh]>", re.S | re.I)


# --------------------------------------------------------------------------
# Entorno y catalogo
# --------------------------------------------------------------------------
def cargar_env():
    env = {}
    for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return env


def clave(s):
    return re.sub(r"[^A-Za-z0-9]", "", s or "").upper()


def normalizar_tipo(t):
    """Mayusculas, sin tildes y sin puntuacion, para comparar contra el alcance.

    'Dano en el sistema de extraccion' llega de SAP con tilde y a veces con el
    mojibake que dejo la importacion del Excel; comparar cadenas crudas fallaba.
    """
    t = unicodedata.normalize("NFD", str(t or ""))
    t = "".join(c for c in t if unicodedata.category(c) != "Mn")
    return re.sub(r"[^A-Z0-9]+", " ", t.upper()).strip()


def cargar_alcance():
    """El alcance del servicio, como dato. Devuelve (dentro, fuera, por_confirmar).

    `fuera` mapea tipo -> motivo, para poder decirle a la administradora POR QUE
    un caso no compete, en vez de solo marcarlo.
    """
    j = json.loads(ALCANCE_PATH.read_text(encoding="utf-8"))
    dentro = {normalizar_tipo(t) for t in j["dentro"]["tipos"]}
    fuera = {}
    for g in j["fuera"]["grupos"]:
        for t in g["tipos"]:
            fuera[normalizar_tipo(t)] = g["motivo"]
    confirmar = {normalizar_tipo(t) for t in j["por_confirmar"]["tipos"]}
    return dentro, fuera, confirmar, j.get("version")


def triage(caso, dentro, fuera, confirmar):
    """Levanta ALERTAS sobre un caso recien llegado. NO decide nada.

    Regla del cliente, 2026-09-08: estos filtros marcan alertas para la
    administradora; **el veredicto sobre cada caso sigue siendo de ella**. El
    sistema nunca cierra, nunca rechaza y nunca da un caso por ajeno: le ahorra
    el trabajo de encontrarlos entre cientos, y le dice por que sospecha. Es el
    mismo criterio del proyecto -- toda decision de aceptar o rechazar es
    humana; el codigo solo prepara la evidencia.

    Por eso lo que se emite es `sugerencia`, no `accion`, y el estado es
    CON_ALERTA / POR_CONFIRMAR / SIN_ALERTA -- nunca "ajeno" ni "rechazado".

    Tres alertas posibles, y ninguna se adivina:
      LOCAL_FUERA_DE_CONTRATO   el local no esta en el maestro de INDUSTEC
      TRABAJO_FUERA_DE_ALCANCE  el tipo de trabajo no figura en el alcance
      TRABAJO_POR_CONFIRMAR     tipo sin criterio definido -> lo resuelve la
                                administracion, no el script (I-7)
    """
    alertas = []
    if not caso["local"]:
        alertas.append({
            "regla": "LOCAL_FUERA_DE_CONTRATO",
            "motivo": f"{caso['restaurante_sap']} no esta en el maestro de locales de INDUSTEC",
            "sugerencia": "Revisar si el local nos corresponde; si no, cerrarlo en SAP",
        })
    t = normalizar_tipo(caso["caso"])
    if not t:
        alertas.append({
            "regla": "TRABAJO_POR_CONFIRMAR",
            "motivo": "el correo no trae tipo de trabajo",
            "sugerencia": "Mirar el caso en SAP para saber de que se trata",
        })
    elif t in fuera:
        alertas.append({
            "regla": "TRABAJO_FUERA_DE_ALCANCE",
            "motivo": fuera[t],
            "sugerencia": "Si confirmas que no nos compete, cerrarlo en SAP indicandolo",
        })
    elif t in confirmar:
        alertas.append({
            "regla": "TRABAJO_POR_CONFIRMAR",
            "motivo": f"'{caso['caso']}' no tiene criterio definido de alcance",
            "sugerencia": "Definir si nos compete y anotarlo en config/alcance_trabajos.json",
        })
    elif t not in dentro:
        alertas.append({
            "regla": "TRABAJO_POR_CONFIRMAR",
            "motivo": f"'{caso['caso']}' es un tipo de trabajo que no estaba en la lista",
            "sugerencia": "Definir si nos compete y anotarlo en config/alcance_trabajos.json",
        })
    if any(a["regla"] != "TRABAJO_POR_CONFIRMAR" for a in alertas):
        estado = "CON_ALERTA"
    elif alertas:
        estado = "POR_CONFIRMAR"
    else:
        estado = "SIN_ALERTA"
    return estado, alertas


def cargar_maestro(env):
    """Locales canonicos y el indice de alias, para resolver 'R002' -> 'R002EC'."""
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])
    try:
        cur = cnx.cursor(dictionary=True)
        cur.execute("SELECT local_codigo, zona, cadena, nombre, correo_jefe_op "
                    "FROM locales WHERE activo = 1")
        locales = {r["local_codigo"]: r for r in cur.fetchall()}
        indice = {clave(c): c for c in locales}
        cur.execute("SELECT alias_texto, local_codigo FROM locales_alias")
        for r in cur.fetchall():
            indice.setdefault(clave(r["alias_texto"]), r["local_codigo"])
    finally:
        cnx.close()
    return locales, indice


# --------------------------------------------------------------------------
# T2.28.8 - Las ordenes sin local, identificadas por la administracion
# --------------------------------------------------------------------------
# La clave con que se resuelve el local: la PRIMERA palabra del texto de SAP
# («V090 SUPER AKI LA JOYA GYE» -> «V090»), igual que en parsear(). Solo con
# forma de codigo de local: una primera palabra como «KFC» mandaria a un solo
# local todas las ordenes que empiecen asi. Gemela de Casos::claveLocalSap()
# (casos.php): si divergen, un alias aprobado alla nunca calzaria aqui.
PATRON_CLAVE_LOCAL = re.compile(r"^[A-Z]{1,3}[0-9]{2,4}(EC)?$")
REGLA_ADMIN_BUZON = "ADMIN_BUZON"
CACHE_FUERA = SALIDA / "catalogos" / "fuera_alcance_cache.json"


def clave_local_sap(texto):
    palabras = (texto or "").split()
    k = clave(palabras[0]) if palabras else ""
    return k if PATRON_CLAVE_LOCAL.match(k) else ""


def decidir_alias(propuestas, locales_activos, indice):
    """Que hacer con lo que decidio la administracion en el buzon. PURA.

    propuestas     filas de locales_alias_propuestos: {clave, decision,
                   local_codigo, estado}
    locales_activos codigos activos del maestro de la estacion
    indice         {clave: local} con los codigos y los alias que ya resuelven

    Devuelve (insertar, aplicar, fuera, rechazar):
      insertar  [(clave, local)] -> alias nuevos en locales_alias (ADMIN_BUZON)
      aplicar   [clave]          -> PROPUESTO que quedan aplicadas en esta corrida
      fuera     {clave}          -> ordenes que no entran al buzon
      rechazar  {clave: motivo}  -> no se pueden aplicar: se marcan RECHAZADO con
                                    el motivo, que la administradora lee en el
                                    buzon, y no se escribe nada de ellas (I-10)
    Una decision nunca pisa un alias que ya apunta a OTRO local (I-11), y un
    local que no esta activo en el maestro no se acepta: no se adivina nada.
    Los motivos llevan tildes: los lee una persona en la pantalla.
    """
    insertar, aplicar, fuera, rechazar = [], [], set(), {}
    for p in propuestas:
        k = p.get("clave") or ""
        estado = p.get("estado")
        if estado == "RECHAZADO":
            continue
        if not PATRON_CLAVE_LOCAL.match(k):
            rechazar[k] = "no tiene forma de código de local"
            continue
        ya = indice.get(k)
        if p.get("decision") == "FUERA_ALCANCE":
            if ya is not None:
                rechazar[k] = (f"está marcado fuera de alcance, pero {k} ya corresponde al local {ya} "
                               "en la estación: avisa a Andrés")
                continue
            fuera.add(k)
            if estado == "PROPUESTO":
                aplicar.append(k)
            continue
        loc = p.get("local_codigo") or ""
        if loc not in locales_activos:
            rechazar[k] = f"el local {loc or '(vacío)'} no existe o no está activo en el maestro de la estación"
            continue
        if ya is not None and ya != loc:
            rechazar[k] = (f"{k} ya corresponde al local {ya} en la estación y aquí se eligió {loc}: "
                           "no se pisa (I-11), avisa a Andrés")
            continue
        if ya is None:
            if estado == "APLICADO":
                # Revision del 2026-09-30: quitar el alias en la estacion es la
                # forma de deshacer una decision ya aplicada (la pantalla dice
                # «avisa a Andres»). Recrearlo en silencio deshacia la correccion.
                # Vuelve a la administradora, editable, en vez de reinsertarse.
                rechazar[k] = "se quitó en la estación: vuelve a decidir a qué local corresponde"
                continue
            insertar.append((k, loc))
        if estado == "PROPUESTO":
            aplicar.append(k)
    return insertar, aplicar, fuera, rechazar


def es_fuera_de_alcance(caso, fuera):
    """La orden es de un local que la administracion marco como no nuestro."""
    return not caso.get("local") and clave_local_sap(caso.get("restaurante_sap")) in fuera


def _sql_texto(s):
    return "'" + str(s).replace("\\", "\\\\").replace("'", "''") + "'"


def _sql_utf8(s):
    """Un texto con tildes, como literal hexadecimal. La nota del robot la lee
    la administradora en el buzon y lleva tildes; en hexadecimal no depende de
    como codifique la linea de comandos de ssh en Windows (no hay una sola
    llamada con tildes por sql_remoto en todo el proyecto que lo haya probado)."""
    return "CONVERT(X'" + str(s).encode("utf-8").hex() + "' USING utf8mb4)"


def _misma_fila(p):
    """WHERE que solo calza si la fila sigue como se leyo. Sin esto, un UPDATE
    del robot pisaba lo que la administradora cambio entre la lectura y la
    escritura (revision del 2026-09-30): marcaba APLICADO un local que ya no
    era el que se aplico, o RECHAZADO una correccion recien hecha."""
    loc = p.get("local_codigo")
    return (f"clave = {_sql_texto(p.get('clave') or '')} AND decision = {_sql_texto(p.get('decision') or '')} "
            f"AND local_codigo <=> {_sql_texto(loc) if loc else 'NULL'} "
            f"AND estado = {_sql_texto(p.get('estado') or '')}")


def _alta_alias_estacion(env, insertar, confirmar):
    """Los alias nuevos en la base de la estacion, en UNA transaccion que se
    cierra DESPUES de anotarlos en el servidor.

    `confirmar()` hace esa anotacion y devuelve las claves cuya fila del
    servidor quedo marcada. Solo esos alias se confirman; el resto se deshace,
    igual que todo si `confirmar()` falla (revision del 2026-10-01). Antes se
    confirmaban primero: si la administradora corregia la decision entre la
    lectura y la escritura, el UPDATE del servidor no calzaba, la fila quedaba
    como ella la dejo y el alias viejo se quedaba en la estacion; al barrido
    siguiente su decision real se rechazaba por I-11 contra el alias que habia
    puesto el propio robot, y las ordenes se iban al local que ella descarto.

    Sin commit, el alias no existe para nadie mas: cerrar la conexion sin
    confirmar lo deshace.
    """
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])
    try:
        cur = cnx.cursor()
        for k, loc in insertar:
            cur.execute("INSERT INTO locales_alias (alias_texto, local_codigo, regla_aplicada, nivel_confianza) "
                        "VALUES (%s, %s, %s, 3)", (k, loc, REGLA_ADMIN_BUZON))
        quedan = confirmar()
        for k, loc in insertar:
            if k not in quedan:
                cur.execute("DELETE FROM locales_alias WHERE alias_texto = %s AND local_codigo = %s "
                            "AND regla_aplicada = %s", (k, loc, REGLA_ADMIN_BUZON))
        cnx.commit()
    finally:
        cnx.close()


def aplica_decisiones(env):
    """¿Este equipo aplica las decisiones de la administracion? Solo la estacion,
    y se sabe por una marca explicita (config/.env, fuera de git), no por
    adivinanza.

    Revision del 2026-10-01: t2_11 y t2_12 piden «corre antes t2_6», asi que otro
    equipo con su propia MariaDB del proyecto (o la estacion con una base
    restaurada de un volcado viejo) lo corre de verdad. Esa base no tiene los
    alias que puso la estacion: cada decision APLICADO caia en «se quito en la
    estacion» y se marcaba RECHAZADO en falso, y cada PROPUESTO quedaba APLICADO
    con el alias en una base que no es la que resuelve las ordenes.
    """
    marca = env.get("ROBOT_APLICA_DECISIONES") or os.environ.get("ROBOT_APLICA_DECISIONES") or ""
    return marca.strip() == "1"


def _causa(e):
    """Tipo y mensaje en UNA linea: t2_9 registra linea por linea, y el tipo solo
    («ErrorSsh») no dice que fallo."""
    texto = " ".join(str(e).split())
    return f"{type(e).__name__}: {texto[:200]}" if texto else type(e).__name__


def _script_de_marcas(marcas):
    """Las marcas del resultado, en UNA transaccion del servidor: quedan todas o
    ninguna. Tras cada UPDATE, `SELECT ROW_COUNT()` dice si la fila seguia como se
    leyo: el estado cambia, asi que da 1 cuando calza y 0 cuando la
    administradora la toco entre la lectura y ahora. `marcas` es [(clave, UPDATE)];
    se identifica por posicion, no por clave, que puede traer cualquier texto."""
    partes = ["SET time_zone = '-05:00'", "START TRANSACTION"]
    for i, (_, sentencia) in enumerate(marcas):
        partes += [sentencia, f"SELECT 'marca', {i}, ROW_COUNT()"]
    partes.append("COMMIT")
    return ";\n".join(partes) + ";"


def _sql_del_servidor(env):
    """La consulta al servidor con tiempos que caben en el barrido: el
    vigilante mata al lector a los 600 s (t2_9), asi que ni 600 s esperando
    cupo del semaforo ni 300 s de consulta. Con 30 + 60 s, si el servidor no
    contesta queda tiempo para leer el correo con la lista del cache."""
    import hostinger_ssh as H

    def sql(q):
        return H.sql_remoto(q, timeout=60, env=env, espera_semaforo=30)
    return sql


def recoger_alias(env, sql=None, maestro=None, alta_alias=None, aplica=None):
    """Trae del servidor las decisiones de la administracion y las aplica.

    Antes de cargar_maestro(): el alias nuevo tiene que estar en el indice de
    ESTA corrida. Si el servidor no responde, se sigue con lo ultimo que se supo
    de «fuera de alcance» (cache local): sin eso, esas ordenes volverian al
    buzon cada vez que falle una conexion. Devuelve el conjunto de claves fuera
    de alcance.

    UNA DECISION QUE NO SE PUEDE APLICAR NO CORTA EL BARRIDO (revision del
    2026-09-30). Antes abortaba con sys.exit(1): el vigilante no reintenta, asi
    que la orden nueva que habia disparado ese barrido se quedaba fuera del
    buzon hasta el siguiente correo de KFC, que de noche tarda horas. Ahora se
    marca RECHAZADO con el motivo (la administradora lo ve donde decidio), no
    se escribe nada de ella y las demas siguen su curso.

    SOLO LA ESTACION APLICA (`aplica_decisiones`). En cualquier otro equipo esto
    solo lee: calcula `fuera` y no escribe en ninguna de las dos bases.

    LO QUE SE ANOTA SE CONFIRMA CONTRA EL SERVIDOR. Los alias se dan de alta en
    una transaccion de la estacion que solo se cierra cuando el servidor dijo
    cuales filas quedaron marcadas (`_alta_alias_estacion`); lo que la
    administradora cambio entre la lectura y la escritura no se aplica ni se
    cuenta como aplicado, y el barrido siguiente lo lee de nuevo. Queda una
    ventana: si el servidor marca y la respuesta no llega (timeout), la estacion
    deshace el alias y el barrido siguiente marca esa fila RECHAZADO con «se quito
    en la estacion»; la administradora lo ve y vuelve a decidir. Es visible y
    recuperable, no un dato torcido en silencio.

    `sql`, `maestro`, `alta_alias` y `aplica` se pueden reemplazar en las pruebas
    (t2_28_8_pruebas.py): por defecto son el servidor, cargar_maestro, la base de
    la estacion y la marca de config/.env.
    """
    sql = sql or _sql_del_servidor(env)
    maestro = maestro or cargar_maestro
    alta_alias = alta_alias or _alta_alias_estacion
    aplica = aplica_decisiones(env) if aplica is None else aplica
    try:
        filas = sql("SELECT clave, decision, local_codigo, estado FROM locales_alias_propuestos")
    except (Exception, SystemExit) as e:
        # SystemExit tambien: hostinger_ssh sale asi si falta la llave o el
        # usuario, y el lector del correo no dependia de SSH antes de T2.28.8.
        cache = json.loads(CACHE_FUERA.read_text(encoding="utf-8")) if CACHE_FUERA.is_file() else []
        # «AVISO:» al principio: t2_9 registra esas líneas e InspectorBot las
        # cuenta. Sin el prefijo, un SSH caído pasaba en silencio barrido tras
        # barrido mientras la pantalla seguía prometiendo «la próxima corrida».
        print(f"AVISO: decisiones de la administracion: no se pudieron leer ({_causa(e)}); "
              f"se usa la ultima lista de fuera de alcance ({len(cache)})")
        return set(cache)
    # sql_remoto devuelve NULL como el texto 'NULL' (TSV de mysql --batch).
    propuestas = [{"clave": f[0], "decision": f[1], "local_codigo": None if f[2] == "NULL" else f[2],
                   "estado": f[3]} for f in filas if len(f) >= 4]

    if not aplica:
        fuera = {p["clave"] for p in propuestas
                 if p["decision"] == "FUERA_ALCANCE" and p["estado"] in ("PROPUESTO", "APLICADO")
                 and PATRON_CLAVE_LOCAL.match(p["clave"] or "")}
        escribir_json_atomico(CACHE_FUERA, json.dumps(sorted(fuera)))
        linea = (f"decisiones de la administracion: {len(propuestas)} · SOLO LECTURA en este equipo, "
                 f"no se escribe nada (falta ROBOT_APLICA_DECISIONES=1 en config/.env) · "
                 f"fuera de alcance {len(fuera)}")
        # En el vigilante, que corre solo en la estacion, esa marca perdida es un
        # defecto: las decisiones nuevas de la administradora no se aplicarian
        # nunca y nadie lo vería. Con «AVISO:» queda en el registro y en InspectorBot.
        print(("AVISO: " + linea) if os.environ.get("INDUSTEC_PROCESO") == "vigilante" else linea)
        return fuera

    locales, indice = maestro(env)
    insertar, aplicar, fuera, rechazar = decidir_alias(propuestas, set(locales), indice)

    por_clave = {p["clave"]: p for p in propuestas}
    marcas = [
        (k, "UPDATE locales_alias_propuestos SET estado = 'APLICADO', aplicado_en = NOW(), "
            f"nota_robot = {_sql_utf8('aplicada por el robot de la estación')} WHERE {_misma_fila(por_clave[k])}")
        for k in aplicar
    ] + [
        (k, "UPDATE locales_alias_propuestos SET estado = 'RECHAZADO', aplicado_en = NULL, "
            f"nota_robot = {_sql_utf8(motivo[:300])} WHERE {_misma_fila(por_clave[k])}")
        for k, motivo in rechazar.items()
    ]
    confirmadas = set()      # claves cuya fila del servidor quedo marcada
    donde = ["la estación"]  # quien falla si algo falla: se anota antes de hablar con el servidor

    def anotar():
        # La hora de Ecuador, como la web (Db.php fija '-05:00'): la sesion de
        # mysql por SSH queda en UTC, y la misma fila tendria propuesto_en en
        # una zona y aplicado_en en otra. Con desfase y no con nombre de zona:
        # Hostinger no tiene cargadas las zonas con nombre (error 1298).
        donde[0] = "el servidor"
        resultado = sql(_script_de_marcas(marcas))
        donde[0] = "la estación"
        for fila in resultado:
            if len(fila) >= 3 and fila[0] == "marca" and fila[2] == "1":
                confirmadas.add(marcas[int(fila[1])][0])
        return confirmadas

    respondio = False
    try:
        if insertar:
            alta_alias(env, insertar, anotar)
        elif marcas:
            anotar()
        respondio = bool(marcas)
    except (Exception, SystemExit) as e:
        # Los alias de esta corrida se deshicieron junto con la transaccion y el
        # servidor no marco nada: el barrido siguiente repite todo.
        confirmadas.clear()
        print(f"AVISO: decisiones de la administracion: no se pudo anotar el resultado en {donde[0]} "
              f"({_causa(e)}); no se aplicó nada y se repite en el próximo barrido")

    alias_ok = [(k, loc) for k, loc in insertar if k in confirmadas]
    aplicadas_ok = [k for k in aplicar if k in confirmadas]
    rechazadas_ok = {k: m for k, m in rechazar.items() if k in confirmadas}
    # Una decision de «fuera de alcance» que el servidor NO marco porque la fila
    # cambio ya no es la que ella dejo: se saca de este barrido (la orden se ve
    # en el buzon y no desaparece por un dato viejo). Si el servidor no
    # respondio, la decision que se leyo sigue siendo la ultima que se conoce.
    if respondio:
        fuera -= {k for k in aplicar if k not in confirmadas}
    sin_marcar = len(marcas) - len(confirmadas)
    escribir_json_atomico(CACHE_FUERA, json.dumps(sorted(fuera)))
    print(f"decisiones de la administracion: {len(propuestas)} · alias nuevos {len(alias_ok)} · "
          f"aplicadas ahora {len(aplicadas_ok)} · fuera de alcance {len(fuera)} · rechazadas {len(rechazadas_ok)}"
          + (f" · sin marcar {sin_marcar} (la fila cambió o no se pudo anotar)" if sin_marcar else ""))
    # Estas dos familias las registra t2_9 (empiezan así a propósito); el
    # resumen de arriba no, para no llenar el registro en cada barrido. Solo de
    # lo que el servidor confirmó: «aplicadas» de algo que no quedó marcado era
    # una mentira en el registro.
    if aplicadas_ok:
        print("decisiones aplicadas: " + ", ".join(
            f"{k} -> {por_clave[k].get('local_codigo')}" if por_clave[k].get("decision") == "LOCAL"
            else f"{k} fuera de alcance" for k in aplicadas_ok))
    for k, motivo in rechazadas_ok.items():
        print(f"AVISO: decision RECHAZADA {k}: {motivo}")
    return fuera


# --------------------------------------------------------------------------
# Decodificacion y parseo
# --------------------------------------------------------------------------
def texto_html(parte):
    """Decodifica la parte HTML. utf-8 estricto primero; el fallo es la senal.

    Medido sobre el buzon real: el HTML es UTF-8 legitimo y utf-8 estricto no
    falla. El fallback existe por si algun dia llega otra cosa, no porque haga
    falta hoy. Nunca errors='replace': eso esconderia el problema en vez de
    delatarlo.
    """
    crudo = parte.get_payload(decode=True) or b""
    for enc in ("utf-8", "cp1252", "latin-1"):
        try:
            return crudo.decode(enc)
        except UnicodeDecodeError:
            continue
    return crudo.decode("latin-1", errors="replace")


def limpiar(celda):
    t = re.sub(r"<[^>]+>", " ", celda or "")
    t = htmlmod.unescape(t)
    return re.sub(r"\s+", " ", t).strip()


def etiqueta_parrafo(html, etiqueta):
    """Valor de <strong>Etiqueta:</strong>VALOR, que va fuera de la tabla.

    El delimitador ':' es obligatorio en el patron, nunca opcional: sin el,
    cualquier texto libre que empiece con la palabra-etiqueta se confunde con
    un campo real (leccion de T1.7).
    """
    m = re.search(r"<strong>\s*" + re.escape(etiqueta) + r"\s*:\s*</strong>([^<]*)",
                  html, re.I)
    return limpiar(m.group(1)) if m else None


def cuerpo_html(msg):
    for p in msg.walk():
        if p.get_content_type() == "text/html":
            return texto_html(p)
    for p in msg.walk():
        if p.get_content_type() == "text/plain":
            return texto_html(p)
    return ""


def fecha_ec(s):
    """dd/mm/aaaa -> aaaa-mm-dd. Devuelve None si no cuadra: no se adivina."""
    m = re.match(r"^\s*(\d{1,2})/(\d{1,2})/(\d{4})\s*$", s or "")
    if not m:
        return None
    d, mo, a = m.groups()
    return f"{a}-{int(mo):02d}-{int(d):02d}"


def parsear(msg, locales, indice):
    """Un correo -> un caso, o None si no es un aviso de SAP."""
    asunto = str(make_header(decode_header(msg.get("Subject") or "")))
    m = RE_ASUNTO.search(asunto)
    if not m:
        return None
    accion = "ELIMINADA" if m.group(1).lower() == "eliminado" else "CREADA"
    orden_trabajo = m.group(2).upper()

    html = cuerpo_html(msg)
    celdas = [limpiar(c) for c in RE_CELDA.findall(html)]
    celdas = [c for c in celdas if c]

    restaurante = etiqueta_parrafo(html, "Restaurante") or ""
    usuario = etiqueta_parrafo(html, "Usuario")

    # La fila de datos: arranca justo despues de la ultima cabecera conocida.
    fin = None
    for i, c in enumerate(celdas):
        if c.lower().startswith("descripcion breve"):
            fin = i
    valores = celdas[fin + 1: fin + 1 + 8] if fin is not None else []
    v = dict(zip(COLUMNAS, valores)) if len(valores) >= 7 else {}

    # 000010353373 -> 10353373. Solo se acepta si quedan 8 digitos exactos.
    aviso_crudo = (v.get("Aviso SAP") or "").strip()
    aviso = re.sub(r"^0+", "", re.sub(r"\D", "", aviso_crudo))
    if not re.fullmatch(r"\d{8}", aviso or ""):
        aviso = None

    # Local: el codigo va antes del nombre -> "R002 QUICENTRO NORTE"
    cod_sap = restaurante.split()[0] if restaurante else ""
    canonico = indice.get(clave(cod_sap))
    info = locales.get(canonico) if canonico else None

    # Zona por el buzon en copia: segunda senal, independiente del maestro.
    cc = (msg.get("Cc") or "") + "," + (msg.get("To") or "")
    zona_buzon = None
    for buzon, z in ZONA_POR_BUZON.items():
        if buzon in cc.lower():
            zona_buzon = z
            break

    zona_local = info["zona"] if info else None
    discrepancia = bool(zona_buzon and zona_local and zona_buzon != zona_local)

    fecha_correo = None
    try:
        fecha_correo = email.utils.parsedate_to_datetime(msg.get("Date")).date().isoformat()
    except Exception:
        pass

    return {
        "aviso": aviso,
        "aviso_crudo": aviso_crudo or None,
        "orden_trabajo": orden_trabajo,
        "accion": accion,
        "prioridad": (v.get("Prioridad") or "").upper() or None,
        "fecha_creacion": fecha_ec(v.get("F.Creacion")),
        "fecha_estimada": fecha_ec(v.get("F.Estimada")),
        "caso": v.get("Tipo Trabajo") or None,
        "detalle": v.get("Detalle Trabajo") or None,
        "activo_fijo": v.get("Activo Fijo") or None,
        "descripcion_trabajo": v.get("Descripcion Breve") or None,
        "restaurante_sap": restaurante or None,
        "centro_coste_sap": cod_sap or None,
        "usuario_kfc": usuario,
        "local": canonico,
        "local_nombre": info["nombre"] if info else None,
        "cadena": info["cadena"] if info else None,
        "zona": zona_local or zona_buzon,
        "zona_por_buzon": zona_buzon,
        "zona_por_local": zona_local,
        "zona_discrepa": discrepancia,
        "buzon_zona": info["correo_jefe_op"] if info else None,
        "recibido": fecha_correo,
    }


# --------------------------------------------------------------------------
# Lectura del buzon
# --------------------------------------------------------------------------
def leer(env, dias):
    M = imaplib.IMAP4_SSL(env["IMAP_HOST"], int(env["IMAP_PORT"]))
    M.login(env["IMAP_USER"], env["IMAP_PASSWORD"])
    try:
        # readonly=True -> EXAMINE. El servidor no puede cambiar banderas.
        ok, _ = M.select("INBOX", readonly=True)
        if ok != "OK":
            raise RuntimeError("no se pudo abrir INBOX")

        criterio = ["FROM", REMITENTE]
        if dias:
            desde = (date.today() - timedelta(days=dias)).strftime("%d-%b-%Y")
            criterio += ["SINCE", desde]
        ok, data = M.search(None, *criterio)
        ids = data[0].split()

        mensajes = []
        for i in range(0, len(ids), 50):
            lote = b",".join(ids[i:i + 50])
            # BODY.PEEK[] es lo unico que lee sin encender \Seen.
            ok, d = M.fetch(lote, "(BODY.PEEK[])")
            # Un NO del servidor no lanza excepción en imaplib: sin esta línea
            # un lote de 50 correos se perdía en silencio y el catálogo salía corto.
            if ok != "OK":
                raise RuntimeError(f"FETCH respondió {ok} en el lote que empieza en {i}")
            for item in d:
                if isinstance(item, tuple):
                    mensajes.append(email.message_from_bytes(item[1]))
        return mensajes
    finally:
        try:
            M.close()
        except Exception:
            pass
        M.logout()


# --------------------------------------------------------------------------
def repartir(parseados, fuera_locales, clasificar):
    """Los correos ya parseados, en orden cronologico -> las listas del
    catalogo. PURA (la prueba la llama sin correo ni base).

    Devuelve (casos, sin_aviso, sin_local, fuera_alcance, discrepan, eliminadas):
    `casos` es {aviso: caso}; sin_local, fuera_alcance y discrepan son listas
    de un caso POR AVISO (revision del 2026-09-30): un aviso trae varios
    correos «creado» -una OT por visita- y contaba una vez por correo, y una
    orden que KFC elimina despues seguia en esas listas.
    `clasificar(caso)` da (estado_alerta, alertas): es triage() con el alcance.
    """
    casos, sin_aviso, eliminadas = {}, [], set()
    sin_local, fuera_alcance, discrepan = {}, {}, {}
    for c in parseados:
        if not c["aviso"]:
            sin_aviso.append(c)
            continue
        if c["accion"] == "ELIMINADA":
            eliminadas.add(c["aviso"])
            for por_aviso in (casos, sin_local, fuera_alcance, discrepan):
                por_aviso.pop(c["aviso"], None)
            continue
        # T2.28.8: la administracion dijo que ese local no es de nuestras
        # zonas. No entra al buzon; queda en su propia lista, a la vista.
        if es_fuera_de_alcance(c, fuera_locales):
            fuera_alcance[c["aviso"]] = c
            continue
        if not c["local"]:
            sin_local[c["aviso"]] = c
        if c["zona_discrepa"]:
            discrepan[c["aviso"]] = c

        c["estado_alerta"], c["alertas"] = clasificar(c)

        # El par (alerta que dio el sistema, veredicto que dio ella) es lo que
        # permitira, mas adelante, medir la tasa de falso positivo por regla y
        # automatizar filtros nuevos con evidencia. Nace vacio a proposito: lo
        # llena una persona, nunca el script.
        c["veredicto_admin"] = None
        c["veredicto_fecha"] = None
        c["veredicto_por"] = None
        c["estado_gestion"] = "NUEVO"    # NUEVO | EN_REVISION | RESUELTO

        casos[c["aviso"]] = c            # upsert por aviso: idempotente
    return (casos, sin_aviso, list(sin_local.values()), list(fuera_alcance.values()),
            list(discrepan.values()), eliminadas)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dias", type=int, default=90,
                    help="ventana hacia atras (por defecto 90)")
    ap.add_argument("--todo", action="store_true", help="sin limite de fecha")
    args = ap.parse_args()
    dias = None if args.todo else args.dias

    env = cargar_env()
    dentro, fuera, confirmar, ver_alcance = cargar_alcance()
    # T2.28.8: lo que decidio la administracion en el buzon, ANTES de cargar el
    # maestro, para que un alias nuevo resuelva ya en esta corrida.
    fuera_locales = recoger_alias(env)
    locales, indice = cargar_maestro(env)
    print(f"maestro: {len(locales)} locales, {len(indice)} claves de resolucion")
    print(f"alcance: {len(dentro)} tipos dentro, {len(fuera)} fuera, "
          f"{len(confirmar)} por confirmar (version {ver_alcance})")

    mensajes = leer(env, dias)
    print(f"correos de {REMITENTE}: {len(mensajes)}"
          + (f" (ultimos {dias} dias)" if dias else " (todos)"))

    # Orden cronologico: la ultima palabra sobre un aviso es la que vale.
    parseados = [p for p in (parsear(m, locales, indice) for m in mensajes) if p]
    ignorados = len(mensajes) - len(parseados)
    parseados.sort(key=lambda c: (c["recibido"] or "", c["orden_trabajo"]))

    casos, sin_aviso, sin_local, fuera_alcance, discrepan, eliminadas = repartir(
        parseados, fuera_locales, lambda c: triage(c, dentro, fuera, confirmar))
    datos = sorted(casos.values(), key=lambda c: (c["recibido"] or ""), reverse=True)

    por_zona, por_prio, por_alerta, por_regla = {}, {}, {}, {}
    for c in datos:
        por_zona[c["zona"] or "(sin zona)"] = por_zona.get(c["zona"] or "(sin zona)", 0) + 1
        por_prio[c["prioridad"] or "(sin dato)"] = por_prio.get(c["prioridad"] or "(sin dato)", 0) + 1
        por_alerta[c["estado_alerta"]] = por_alerta.get(c["estado_alerta"], 0) + 1
        for a in c["alertas"]:
            por_regla[a["regla"]] = por_regla.get(a["regla"], 0) + 1

    salida = {
        "generado": datetime.now().strftime("%Y-%m-%d %H:%M"),
        "fuente": f"buzon {env['IMAP_USER']} (solo lectura), remitente {REMITENTE}",
        "ventana_dias": dias,
        "correos_leidos": len(mensajes),
        "alcance_version": ver_alcance,
        "las_alertas_no_deciden": (
            "Estas alertas solo marcan ordenes sospechosas para que la administradora "
            "las encuentre rapido. Cada orden la resuelve ella: el sistema no cierra, "
            "no rechaza y no da ninguna orden por ajena."
        ),
        "resumen": {
            "casos_vigentes": len(datos),
            "ordenes_eliminadas": len(eliminadas),
            "sin_aviso_legible": len(sin_aviso),
            "sin_local_resuelto": len(sin_local),
            "fuera_alcance": len(fuera_alcance),
            "zona_discrepante": len(discrepan),
            "correos_no_reconocidos": ignorados,
            "por_zona": por_zona,
            "por_prioridad": por_prio,
            "por_estado_alerta": por_alerta,
            "por_regla": por_regla,
        },
        "revisar": {
            "sin_local": [{"orden_trabajo": c["orden_trabajo"],
                           "restaurante_sap": c["restaurante_sap"],
                           "aviso": c["aviso"]} for c in sin_local],
            "fuera_alcance": [{"orden_trabajo": c["orden_trabajo"],
                               "restaurante_sap": c["restaurante_sap"],
                               "aviso": c["aviso"]} for c in fuera_alcance],
            "zona_discrepante": [{"aviso": c["aviso"], "local": c["local"],
                                  "por_local": c["zona_por_local"],
                                  "por_buzon": c["zona_por_buzon"]} for c in discrepan],
            "sin_aviso_legible": [{"orden_trabajo": c["orden_trabajo"],
                                   "aviso_crudo": c["aviso_crudo"]} for c in sin_aviso],
        },
        "datos": datos,
    }

    # Compuerta: si menos del 95% resuelve local, el maestro de alias tiene un
    # hueco y la bandeja mostraria casos sin zona ni destinatario. Va ANTES de
    # escribir: evaluada despues, el archivo malo ya habia reemplazado al bueno
    # y el vigilante lo empujaba igual.
    if datos:
        ok = sum(1 for c in datos if c["local"])
        if ok / len(datos) < 0.95:
            print(f"\nABORTA: solo {ok}/{len(datos)} ordenes resuelven local (<95%). "
                  "No se toca el catalogo anterior.", file=sys.stderr)
            sys.exit(1)

    SALIDA.mkdir(parents=True, exist_ok=True)
    destino = SALIDA / "catalogos" / "casos_sap.json"
    destino.parent.mkdir(parents=True, exist_ok=True)
    # A un temporal y despues se reemplaza: quien lea a mitad de la escritura
    # (el vigilante, el empuje) nunca ve un JSON cortado.
    temporal = destino.with_name(destino.name + ".tmp")
    temporal.write_text(json.dumps(salida, ensure_ascii=False, indent=1), encoding="utf-8")
    # En Windows el reemplazo falla si en ese instante otro proceso tiene el
    # archivo abierto (el empuje lo lee). Son lecturas de milisegundos: se
    # reintenta un momento antes de rendirse, en vez de perder el barrido.
    for intento in range(10):
        try:
            temporal.replace(destino)
            break
        except PermissionError:
            if intento == 9:
                raise
            time.sleep(0.5)

    # Sin tildes: t2_9_buzon_vigilante.py lee estas dos lineas por su comienzo a traves de
    # una tuberia. Si cambian aqui, se cambian alla.
    print(f"\nordenes en el buzon  : {len(datos)}")
    print(f"ordenes eliminadas   : {len(eliminadas)} (excluidas)")
    for z in sorted(por_zona):
        print(f"  {z:<14}: {por_zona[z]}")
    print("prioridad            :", ", ".join(f"{k}={v}" for k, v in sorted(por_prio.items())))
    print("\nALERTAS para la administradora (marcan, no deciden: las resuelve ella):")
    for e in ("CON_ALERTA", "POR_CONFIRMAR", "SIN_ALERTA"):
        print(f"  {e:<16}: {por_alerta.get(e, 0)}")
    for r, n in sorted(por_regla.items(), key=lambda x: -x[1]):
        print(f"     {r:<26} {n}")
    if fuera_alcance:
        print(f"\nFUERA DE ALCANCE     : {len(fuera_alcance)} (decision de la administracion: no entran al buzon)")
    if sin_local:
        print(f"\nSIN LOCAL RESUELTO   : {len(sin_local)} -> no se inventa la zona")
        for c in sin_local[:8]:
            print(f"   {c['orden_trabajo']:<16} {c['restaurante_sap']}")
    if discrepan:
        print(f"\nZONA DISCREPANTE     : {len(discrepan)} (buzon vs maestro) -> revisar")
        for c in discrepan[:8]:
            print(f"   aviso {c['aviso']} {c['local']}: maestro={c['zona_por_local']} buzon={c['zona_por_buzon']}")
    if sin_aviso:
        print(f"\nSIN AVISO LEGIBLE    : {len(sin_aviso)}")
    print(f"\n-> {destino}")


if __name__ == "__main__":
    main()
