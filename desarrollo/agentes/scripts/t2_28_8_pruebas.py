"""
T2.28.8 - Pruebas de unidad de las ordenes sin local identificadas desde el
buzon: la decision del robot (`decidir_alias`), lo que escribe al aplicarla
(`recoger_alias`, con el servidor y la base SIMULADOS), el reparto del
catalogo por aviso (`repartir`), la carga de alias del maestro que ya no pisa
otras fuentes (t1_5), el cotejo con SAP (t2_12) y la paridad de la clave con
la version PHP del buzon (`Casos::claveLocalSap`). No toca ninguna base ni el
servidor, y el cache de «fuera de alcance» va a una carpeta temporal.

Uso:
    .venv/Scripts/python.exe scripts/t2_28_8_pruebas.py      # sale con 1 si algo falla
"""
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
import t2_6_imap_avisos as T  # noqa: E402
import t1_5_importar_maestro_locales as T15  # noqa: E402
import t2_12_cotejo_sap_abiertas as T12  # noqa: E402

PHP = r"D:\SOFTWARE\PHP83\php.exe"
CASOS_PHP = Path(__file__).resolve().parents[2] / "sistema_ots" / "app" / "publico" / "nucleo" / "Casos.php"

total = fallos = 0


def afirmar(que, real, esperado):
    global total, fallos
    total += 1
    ok = real == esperado
    if not ok:
        fallos += 1
    print(f"  {'ok   ' if ok else 'FALLA'} {que}" + ("" if ok else f"  -> dio {real!r}, esperaba {esperado!r}"))


locales = {"K146EC", "G018EC", "R002EC", "V091EC"}
indice = {"K146EC": "K146EC", "K146": "K146EC", "G018EC": "G018EC", "G018": "G018EC", "R002": "R002EC"}

print("=== 1. La clave: la primera palabra, con forma de codigo de local ===")
for texto, esp in [("V090 SUPER AKI LA JOYA GYE", "V090"), ("K146EC CUENCA", "K146EC"), ("kfc mall", ""),
                   ("r002 algo", "R002"), ("  G018  x", "G018"), ("CN042 ...", "CN042"), ("12345 x", ""), ("", ""), (None, "")]:
    afirmar(f"clave_local_sap({texto!r})", T.clave_local_sap(texto), esp)

print("\n=== 2. decidir_alias ===")
ins, apl, fue, rec = T.decidir_alias([{"clave": "V090", "decision": "LOCAL", "local_codigo": "X999EC", "estado": "PROPUESTO"}], locales, indice)
afirmar("local inexistente en el maestro -> se rechaza con el motivo", ("V090" in rec, "no existe" in rec.get("V090", ""), ins, apl), (True, True, [], []))
ins, apl, fue, rec = T.decidir_alias([{"clave": "R002", "decision": "LOCAL", "local_codigo": "K146EC", "estado": "PROPUESTO"}], locales, indice)
afirmar("la clave ya apunta a OTRO local -> choque, se rechaza (I-11)", ("R002" in rec and "I-11" in rec["R002"], ins), (True, []))
ins, apl, fue, rec = T.decidir_alias([{"clave": "V090", "decision": "LOCAL", "local_codigo": "V091EC", "estado": "PROPUESTO"}], locales, indice)
afirmar("local valido -> alias nuevo y queda aplicada", (ins, apl, rec), ([("V090", "V091EC")], ["V090"], {}))
ins, apl, fue, rec = T.decidir_alias([{"clave": "R002", "decision": "LOCAL", "local_codigo": "R002EC", "estado": "PROPUESTO"}], locales, indice)
afirmar("la clave ya apunta a ESE local -> sin alias nuevo, pero aplicada", (ins, apl, rec), ([], ["R002"], {}))
ins, apl, fue, rec = T.decidir_alias([{"clave": "V090", "decision": "LOCAL", "local_codigo": "V091EC", "estado": "APLICADO"}], locales, indice)
afirmar("APLICADO cuyo alias se quito en la estacion -> NO se recrea: vuelve a la administradora",
        (ins, apl, "se quitó" in rec.get("V090", "")), ([], [], True))
ins, apl, fue, rec = T.decidir_alias([{"clave": "R002", "decision": "LOCAL", "local_codigo": "R002EC", "estado": "APLICADO"}], locales, indice)
afirmar("APLICADO con su alias en su sitio -> nada que hacer", (ins, apl, fue, rec), ([], [], set(), {}))
ins, apl, fue, rec = T.decidir_alias([{"clave": "V090", "decision": "FUERA_ALCANCE", "local_codigo": None, "estado": "PROPUESTO"}], locales, indice)
afirmar("fuera de alcance -> en el conjunto, sin alias, aplicada", (fue, ins, apl, rec), ({"V090"}, [], ["V090"], {}))
ins, apl, fue, rec = T.decidir_alias([{"clave": "V090", "decision": "FUERA_ALCANCE", "local_codigo": None, "estado": "APLICADO"}], locales, indice)
afirmar("fuera de alcance ya APLICADA -> sigue valiendo en cada corrida", (fue, apl), ({"V090"}, []))
ins, apl, fue, rec = T.decidir_alias([{"clave": "K146", "decision": "FUERA_ALCANCE", "local_codigo": None, "estado": "APLICADO"}], locales, indice)
afirmar("fuera de alcance de algo que YA resuelve a un local -> contradiccion, se rechaza", ("K146" in rec, fue), (True, set()))
ins, apl, fue, rec = T.decidir_alias([{"clave": "V090", "decision": "LOCAL", "local_codigo": "X999EC", "estado": "RECHAZADO"}], locales, indice)
afirmar("una RECHAZADA no se vuelve a evaluar (no traba el robot)", (ins, apl, fue, rec), ([], [], set(), {}))
ins, apl, fue, rec = T.decidir_alias([{"clave": "KFC", "decision": "LOCAL", "local_codigo": "K146EC", "estado": "PROPUESTO"}], locales, indice)
afirmar("una clave sin forma de codigo (KFC) se rechaza", "KFC" in rec, True)
_, _, _, rec = T.decidir_alias([{"clave": "V090", "decision": "LOCAL", "local_codigo": "X999EC", "estado": "PROPUESTO"}], locales, indice)
afirmar("el motivo lleva tildes: lo lee una persona", "está" in rec["V090"] and "estación" in rec["V090"], True)

print("\n=== 3. recoger_alias, con el servidor y la base simulados ===")
tmp = Path(tempfile.mkdtemp(prefix="t2_28_8_"))
T.CACHE_FUERA = tmp / "fuera_alcance_cache.json"      # nunca el cache real de la estacion


class Servidor:
    """sql_remoto simulado: devuelve las filas y guarda cada SQL que recibe.
    `falla_en` = numero de llamada (1, 2...) que lanza; `sale` = lanza SystemExit;
    `no_calzan` = claves cuya fila la administradora cambio entre la lectura y la
    escritura: su UPDATE no toca nada (ROW_COUNT() = 0). La segunda llamada
    contesta como mysql --batch: una linea «marca <posicion> <filas>» por UPDATE."""
    def __init__(self, filas, falla_en=None, sale=False, no_calzan=()):
        self.filas, self.falla_en, self.sale, self.llamadas = filas, falla_en, sale, []
        self.no_calzan = set(no_calzan)

    def __call__(self, q):
        self.llamadas.append(q)
        if self.falla_en == len(self.llamadas):
            if self.sale:
                raise SystemExit("Falta la llave SSH")
            raise RuntimeError("ssh fallo")
        if len(self.llamadas) == 1:
            return self.filas
        claves = re.findall(r"WHERE clave = '([^']*)'", q)
        return [["marca", str(i), "0" if k in self.no_calzan else "1"] for i, k in enumerate(claves)]


def maestro(_env):
    return {c: {} for c in locales}, dict(indice)


altas = []
registro = []     # el orden en que ocurrio: ¿el alias se confirmo antes o despues del servidor?


def alta(_env, insertar, confirmar):
    """_alta_alias_estacion simulada: INSERT sin commit, `confirmar()`, y solo
    entonces los alias que el servidor confirmo. Una excepcion no deja nada."""
    registro.append("insert")
    quedan = confirmar()
    registro.append("commit")
    altas.extend([a for a in insertar if a[0] in quedan])


def correr(srv, aplica=True, proceso=None, **kw):
    """recoger_alias con la salida capturada: (fuera, texto impreso)."""
    import contextlib
    import io
    antes = os.environ.get("INDUSTEC_PROCESO")
    if proceso is None:
        os.environ.pop("INDUSTEC_PROCESO", None)
    else:
        os.environ["INDUSTEC_PROCESO"] = proceso
    salida = io.StringIO()
    try:
        with contextlib.redirect_stdout(salida):
            fuera = T.recoger_alias({}, sql=srv, maestro=maestro, alta_alias=alta, aplica=aplica, **kw)
    finally:
        if antes is None:
            os.environ.pop("INDUSTEC_PROCESO", None)
        else:
            os.environ["INDUSTEC_PROCESO"] = antes
    return fuera, salida.getvalue()


def notas_por_clave(sql):
    """{clave: nota_robot decodificada} de cada UPDATE del script de marcas."""
    out = {}
    for s in sql.split(";\n"):
        k = re.search(r"WHERE clave = '([^']*)'", s)
        n = re.search(r"nota_robot = CONVERT\(X'([0-9a-f]*)' USING utf8mb4\)", s)
        if k and n:
            out[k.group(1)] = bytes.fromhex(n.group(1)).decode("utf-8")
    return out


filas = [["V090", "LOCAL", "V091EC", "PROPUESTO"], ["G099", "FUERA_ALCANCE", "NULL", "PROPUESTO"],
         ["X001", "LOCAL", "X999EC", "PROPUESTO"], ["kfc", "LOCAL", "K146EC", "PROPUESTO"]]
srv = Servidor(filas)
altas.clear()
registro.clear()
fuera, impreso = correr(srv)
afirmar("una decision imposible NO corta el barrido (antes: sys.exit(1))", fuera, {"G099"})
afirmar("el alias valido se da de alta en la estacion, solo ese", altas, [("V090", "V091EC")])
marca = srv.llamadas[1] if len(srv.llamadas) > 1 else ""
afirmar("el resultado se anota en UNA sola llamada, con la hora de Ecuador primero",
        (len(srv.llamadas), marca.startswith("SET time_zone = '-05:00';")), (2, True))
afirmar("las marcas van en UNA transaccion del servidor: todas o ninguna",
        (marca.count("START TRANSACTION"), marca.rstrip().endswith("COMMIT;"), marca.count("ROW_COUNT()")), (1, True, 4))
afirmar("el alias se confirma DESPUES de que el servidor contesto, no antes (el orden era el defecto)",
        registro, ["insert", "commit"])
afirmar("lo aplicado y lo rechazado se imprimen, de lo que el servidor confirmo",
        ("decisiones aplicadas: V090 -> V091EC, G099 fuera de alcance" in impreso,
         "AVISO: decision RECHAZADA X001" in impreso, "alias nuevos 1" in impreso), (True, True, True))
afirmar("APLICADO solo si la fila sigue como se leyo (clave, decision, local y estado)",
        "WHERE clave = 'V090' AND decision = 'LOCAL' AND local_codigo <=> 'V091EC' AND estado = 'PROPUESTO'" in marca, True)
afirmar("un local NULL se compara con <=> NULL, no con ''", "clave = 'G099' AND decision = 'FUERA_ALCANCE' AND local_codigo <=> NULL" in marca, True)
afirmar("la clave sin forma de codigo tambien se marca RECHAZADO (antes quedaba PROPUESTO y trababa el robot)",
        bool(re.search(r"SET estado = 'RECHAZADO'.*WHERE clave = 'kfc'", marca)), True)
notas = notas_por_clave(marca)
afirmar("cada nota viaja en hexadecimal y vuelve con sus tildes, en la sentencia de SU clave",
        (notas.get("V090"), notas.get("G099"), notas.get("X001"), notas.get("kfc")),
        ("aplicada por el robot de la estación", "aplicada por el robot de la estación",
         "el local X999EC no existe o no está activo en el maestro de la estación",
         "no tiene forma de código de local"))
afirmar("nada de texto con tildes suelto en el SQL (solo ASCII viaja por ssh)", marca.isascii(), True)
afirmar("el cache de fuera de alcance queda escrito", json.loads(T.CACHE_FUERA.read_text(encoding="utf-8")), ["G099"])

print("\n  -- si el servidor no contesta al anotar --")
srv = Servidor([["V090", "LOCAL", "V091EC", "PROPUESTO"], ["G099", "FUERA_ALCANCE", "NULL", "PROPUESTO"]], falla_en=2)
altas.clear()
registro.clear()
fuera, impreso = correr(srv)
afirmar("falla ANOTAR: el barrido sigue y el alias NO queda (antes se confirmaba primero y quedaba aplicado a medias)",
        (altas, registro), ([], ["insert"]))
afirmar("la decision de fuera de alcance que se leyo sigue valiendo: la orden no vuelve al buzon por un SSH caido",
        fuera, {"G099"})
afirmar("el AVISO dice DONDE fallo y la causa, y que se repite",
        ("AVISO: decisiones de la administracion: no se pudo anotar el resultado en el servidor (RuntimeError: ssh fallo)" in impreso,
         "se repite en el próximo barrido" in impreso, "decisiones aplicadas" in impreso), (True, True, False))

print("\n  -- lo que la administradora cambio entre la lectura y la escritura --")
srv = Servidor([["V090", "LOCAL", "V091EC", "PROPUESTO"], ["G099", "FUERA_ALCANCE", "NULL", "PROPUESTO"],
                ["V095", "LOCAL", "V091EC", "PROPUESTO"]], no_calzan={"V090"})
altas.clear()
fuera, impreso = correr(srv)
afirmar("la fila de V090 cambio: su alias se deshace y no se cuenta como aplicado (el defecto: quedaba y despues se rechazaba por I-11)",
        altas, [("V095", "V091EC")])
afirmar("lo impreso no miente: V090 no figura como aplicado, y se dice cuantas no se marcaron",
        ("decisiones aplicadas: G099 fuera de alcance, V095 -> V091EC" in impreso, "V090 ->" in impreso,
         "sin marcar 1" in impreso), (True, False, True))
srv = Servidor([["G099", "FUERA_ALCANCE", "NULL", "PROPUESTO"]], no_calzan={"G099"})
fuera, impreso = correr(srv)
afirmar("una decision de fuera de alcance que ya no es la que ella dejo no esconde la orden en este barrido", fuera, set())

print("\n  -- solo la estacion aplica --")
srv = Servidor([["V090", "LOCAL", "V091EC", "PROPUESTO"], ["V091", "LOCAL", "V091EC", "APLICADO"],
                ["G099", "FUERA_ALCANCE", "NULL", "PROPUESTO"], ["G098", "FUERA_ALCANCE", "NULL", "APLICADO"],
                ["G097", "FUERA_ALCANCE", "NULL", "RECHAZADO"], ["kfc", "FUERA_ALCANCE", "NULL", "PROPUESTO"]])
altas.clear()
registro.clear()
fuera, impreso = correr(srv, aplica=False)
afirmar("en otro equipo: UNA sola llamada (la lectura), ningun alias y ninguna marca",
        (len(srv.llamadas), altas, registro), (1, [], []))
afirmar("en otro equipo calcula fuera de alcance (PROPUESTO y APLICADO con forma de codigo, no RECHAZADO)", fuera, {"G099", "G098"})
afirmar("y lo dice, sin «AVISO» porque no es un defecto fuera de la estacion",
        ("SOLO LECTURA en este equipo" in impreso, impreso.startswith("AVISO")), (True, False))
fuera, impreso = correr(Servidor(srv.filas), aplica=False, proceso="vigilante")
afirmar("pero si lo corre el vigilante (la estacion) y falta la marca, es un AVISO: las decisiones no se aplicarian nunca",
        impreso.startswith("AVISO: decisiones de la administracion"), True)
afirmar("el cache de fuera de alcance tambien se escribe en ese caso", json.loads(T.CACHE_FUERA.read_text(encoding="utf-8")), ["G098", "G099"])

salvo = os.environ.pop("ROBOT_APLICA_DECISIONES", None)
try:
    afirmar("aplica_decisiones: sin la marca no aplica", T.aplica_decisiones({}), False)
    afirmar("aplica_decisiones: con 1 en config/.env aplica", T.aplica_decisiones({"ROBOT_APLICA_DECISIONES": "1"}), True)
    afirmar("aplica_decisiones: tolera espacios", T.aplica_decisiones({"ROBOT_APLICA_DECISIONES": " 1 "}), True)
    afirmar("aplica_decisiones: 0, si o vacio no cuentan",
            [T.aplica_decisiones({"ROBOT_APLICA_DECISIONES": v}) for v in ("0", "si", "")], [False, False, False])
    os.environ["ROBOT_APLICA_DECISIONES"] = "1"
    afirmar("aplica_decisiones: tambien vale la variable del proceso (para las pruebas y el e2e)", T.aplica_decisiones({}), True)
finally:
    os.environ.pop("ROBOT_APLICA_DECISIONES", None)
    if salvo is not None:
        os.environ["ROBOT_APLICA_DECISIONES"] = salvo

print("\n  -- si no se puede leer --")
T.CACHE_FUERA.write_text(json.dumps(["V090"]), encoding="utf-8")
srv = Servidor([], falla_en=1, sale=True)
fuera, impreso = correr(srv)
afirmar("sin llave SSH (SystemExit) se sigue con el cache, no se cae el lector", fuera, {"V090"})
afirmar("el AVISO lleva la causa, no solo el tipo", "SystemExit: Falta la llave SSH" in impreso, True)
srv = Servidor([], falla_en=1)
fuera, impreso = correr(srv)
afirmar("servidor caido -> el cache, con su causa en el AVISO", (fuera, "RuntimeError: ssh fallo" in impreso), ({"V090"}, True))
afirmar("la causa va en UNA linea y recortada (t2_9 registra por linea)",
        (T._causa(RuntimeError("a\nb\n   c")), len(T._causa(RuntimeError("x" * 900)))), ("RuntimeError: a b c", len("RuntimeError: ") + 200))

print("\n=== 4. repartir: un caso por aviso ===")


def caso(aviso, accion="CREADA", local=None, restaurante="V090 SUPER AKI", discrepa=False, ot="W1"):
    return {"aviso": aviso, "accion": accion, "local": local, "restaurante_sap": restaurante,
            "zona_discrepa": discrepa, "orden_trabajo": ot, "recibido": "2026-09-30"}


parseados = [caso("10342779", ot="W1"), caso("10342779", ot="W2"),              # dos correos, un aviso
             caso("10347456", restaurante="G099 OTRO"), caso("10347456", accion="ELIMINADA"),
             caso("10000001", local="K146EC", restaurante="K146 X", discrepa=True),
             caso("10000001", local="K146EC", restaurante="K146 X", discrepa=True, ot="W9"),
             caso("10000002", restaurante="V090 SUPER AKI")]
casos_, sin_aviso, sin_local, fuera_al, discrepan, elim = T.repartir(
    parseados, {"V090"}, lambda c: ("SIN_ALERTA", []))
afirmar("fuera de alcance: una fila por aviso aunque lleguen varios correos",
        sorted(c["aviso"] for c in fuera_al), ["10000002", "10342779"])
afirmar("un aviso que KFC elimina sale de sin_local", [c["aviso"] for c in sin_local], [])
afirmar("zona discrepante: una fila por aviso", [c["aviso"] for c in discrepan], ["10000001"])
afirmar("en el buzon quedan solo los que tienen local", sorted(casos_), ["10000001"])
parseados = [caso("10342779"), caso("10342779", accion="ELIMINADA")]
_, _, _, fuera_al, _, elim = T.repartir(parseados, {"V090"}, lambda c: ("SIN_ALERTA", []))
afirmar("un aviso fuera de alcance que KFC elimina sale de la lista", (fuera_al, elim), ([], {"10342779"}))

print("\n=== 5. t1_5: los alias del maestro ya no pisan los de otra fuente ===")


class Cursor:
    def __init__(self, tabla):
        self.tabla, self.ultimo, self.insertados = dict(tabla), None, []

    def execute(self, q, p):
        if q.startswith("SELECT"):
            self.ultimo = self.tabla.get(p[0])
        else:
            self.tabla[p[0]] = (p[1], p[2])
            self.insertados.append(p[0])

    def fetchone(self):
        return self.ultimo


cur = Cursor({"A018EC": ("A014EC", "T1.6e_CORREO_PDF"), "BR17EC": ("BR17EC", "CONFIRMADO_ANDRES")})
n, ya, choques = T15.cargar_alias_maestro(cur, [
    {"alias": "A018EC", "canonico": "X001EC", "regla": "POR_UBICACION (typo)"},     # choque
    {"alias": "BR17EC", "canonico": "BR17EC", "regla": "NORMALIZADO_CEROS"},        # mismo local
    {"alias": "K0146EC", "canonico": "K146EC", "regla": "NORMALIZADO_CEROS"}])     # nuevo
afirmar("alias de otra fuente que apunta a OTRO local -> choque (y no se toca)",
        (len(choques), cur.tabla["A018EC"]), (1, ("A014EC", "T1.6e_CORREO_PDF")))
afirmar("alias de otra fuente al MISMO local -> se deja como estaba, con su regla",
        (ya, cur.tabla["BR17EC"]), (1, ("BR17EC", "CONFIRMADO_ANDRES")))
afirmar("alias nuevo -> se inserta", (n, cur.insertados), (1, ["K0146EC"]))

print("\n=== 6. t2_12: el cotejo con SAP respeta la decision de fuera de alcance ===")
base = {"ot_cierre": "", "historico": "", "estado_gestion": "", "en_buzon": "NO"}
afirmar("fuera de alcance -> amarillo, «cerrarla en SAP», no rojo", T12.accion_de({**base, "fuera_alcance": True})[1], "amarillo")
afirmar("sin buzon y sin decision -> sigue en rojo", T12.accion_de({**base, "fuera_alcance": False})[1], "rojo")
cat = tmp / "casos_sap.json"
cat.write_text(json.dumps({"generado": "x", "datos": [], "revisar": {"fuera_alcance": [{"aviso": "10342779"}]}}), encoding="utf-8")
T12.CATALOGOS = tmp
afirmar("el cotejo lee los avisos fuera de alcance del catalogo", T12.cargar_catalogo_buzon()[2], {"10342779"})

print("\n=== 7. Lo que esta fuera de alcance no entra al buzon ===")
afirmar("caso sin local de V090, con V090 fuera -> fuera", T.es_fuera_de_alcance({"local": None, "restaurante_sap": "V090 SUPER AKI"}, {"V090"}), True)
afirmar("caso sin local de otro texto -> no", T.es_fuera_de_alcance({"local": None, "restaurante_sap": "V095 OTRO"}, {"V090"}), False)
afirmar("caso CON local nunca se saca (lo resolvio el maestro)", T.es_fuera_de_alcance({"local": "V091EC", "restaurante_sap": "V090 X"}, {"V090"}), False)

print("\n=== 8. Paridad con Casos::claveLocalSap (PHP) ===")
muestras = ["V090 SUPER AKI LA JOYA GYE", "K146EC CUENCA", "kfc mall", "r002 algo", "  G018  x", "CN042 ...",
            "12345 x", "", "AB1 x", "ABCD12 x", "K1234EC y", "K12345 z", "Ñ12 x", "V-090 x", "V090EC\tGYE"]
if Path(PHP).is_file() and CASOS_PHP.is_file():
    codigo = ("require " + json.dumps(str(CASOS_PHP)) + "; $m = json_decode(stream_get_contents(STDIN), true); "
              "echo json_encode(array_map(fn($t) => Casos::claveLocalSap($t), $m));")
    r = subprocess.run([PHP, "-r", codigo], input=json.dumps(muestras), capture_output=True, text=True, encoding="utf-8")
    php = json.loads(r.stdout) if r.returncode == 0 and r.stdout.strip() else None
    py = [T.clave_local_sap(m) for m in muestras]
    afirmar(f"PHP y Python dan la misma clave en {len(muestras)} textos", php, py)
else:
    afirmar("PHP disponible para comparar", False, True)

print("\n=== 9. InspectorBot cuenta lo que el lector avisa de las decisiones ===")
import inspectorbot_estado as I  # noqa: E402
from datetime import datetime, timedelta  # noqa: E402


def registro_con(nombre, barridos, hace_h=0.0):
    """Un registro del vigilante de mentira: cada barrido es la lista de lineas
    que el lector imprime, mas su ultima linea («ordenes en el buzon»). t2_9 las
    registra con tres espacios de sangria; InspectorBot tiene que ignorarlos."""
    t = datetime.now() - timedelta(hours=hace_h)
    lineas = []
    for b in barridos:
        t += timedelta(seconds=10)
        lineas.append(f"[{t:%Y-%m-%d %H:%M:%S}] barriendo el buzón (90 días)...")
        for l in b:
            lineas.append(f"[{t:%Y-%m-%d %H:%M:%S}]    {l}")
        lineas.append(f"[{t:%Y-%m-%d %H:%M:%S}]    ordenes en el buzon  : 919")
    ruta = tmp / f"vigilante_{nombre}.log"
    ruta.write_text("\n".join(lineas) + "\n", encoding="utf-8")
    return I.bloque_registro(ruta)


def alertas_de(reg):
    e = {"robot": {"consulta_error": None, "vivo": True, "codigo_congelado": [], "robots": 1, "silencioso": False},
         "registro": reg,
         "espejo": {"ultimo_espejo": datetime.now(), "espejo_viejo": False,
                    "resumen": {"sospechosos": [], "errores": [], "fallidos": 0, "divergentes": 0}, "divergentes": 0, "cuarentena": 0},
         "nocturno": {"nunca_corrio": False, "ensayo": False, "ok": True, "fin": datetime.now(), "pasos": [], "candado_huerfano": None},
         "buzon": {"error": "x"}, "atenciones": {"error": "x"}, "disco": {"libre_gb": 500}, "archivo": I.bloque_archivo()}
    return [(a["gravedad"], a["titulo"]) for a in I.calcular_alertas(e) if "decisiones" in a["titulo"] or "decisión" in a["titulo"]]


I.ARCHIVO = tmp / "no_hay_estado_archivo.json"
FALLO = "AVISO: decisiones de la administracion: no se pudieron leer (ErrorSsh: ssh fallo (codigo 255)); se usa la ultima lista de fuera de alcance (1)"
RECHAZO = "AVISO: decision RECHAZADA V090: V090 ya corresponde al local V091EC en la estación y aquí se eligió V095EC: no se pisa (I-11), avisa a Andrés"
SIN_MARCA = ("AVISO: decisiones de la administracion: 4 · SOLO LECTURA en este equipo, no se escribe nada "
             "(falta ROBOT_APLICA_DECISIONES=1 en config/.env) · fuera de alcance 1")

r = registro_con("rechazo", [[RECHAZO]])
afirmar("una decision RECHAZADA se cuenta y se alerta (antes: 0 errores y ninguna alerta)",
        (r["rechazos_decision"], r["errores"], alertas_de(r)), (1, 0, [("medio", "1 decisión de la administración rechazada por el robot (24 h)")]))
r = registro_con("rechazos", [[RECHAZO], [RECHAZO.replace("V090", "V091")]])
afirmar("varias: plural", alertas_de(r), [("medio", "2 decisiones de la administración rechazadas por el robot (24 h)")])
r = registro_con("fallo2", [[FALLO], [FALLO]])
afirmar("el servidor sin contestar en 2 barridos seguidos: todavia es el SSH intermitente, sin alerta",
        (r["racha_lectura_decisiones"], alertas_de(r)), (2, []))
r = registro_con("fallo3", [[FALLO], [FALLO], [FALLO]])
afirmar("en 3 seguidos si alerta, con la causa",
        (r["racha_lectura_decisiones"], alertas_de(r), "ErrorSsh: ssh fallo (codigo 255)" in r["ultimo_fallo_decisiones"]),
        (3, [("medio", "El lector no pudo leer las decisiones de la administración en 3 barridos seguidos")], True))
r = registro_con("fallo_y_sano", [[FALLO], [FALLO], [FALLO], []])
afirmar("un barrido sano corta la racha", (r["racha_lectura_decisiones"], alertas_de(r)), (0, []))
r = registro_con("sin_marca", [[SIN_MARCA]])
afirmar("falta la marca de la estacion: alerta MEDIO", alertas_de(r), [("medio", "El robot no aplica las decisiones de la administración")])
r = registro_con("sin_marca_arreglada", [[SIN_MARCA], []])
afirmar("agregada la marca, la alerta se va en el barrido siguiente (no a las 24 h)", alertas_de(r), [])
r = registro_con("viejo", [[RECHAZO], [FALLO], [FALLO], [FALLO], [SIN_MARCA]], hace_h=40)
afirmar("lo de hace mas de 24 h no cuenta", (r["rechazos_decision"], alertas_de(r)), (0, []))
afirmar("un registro anterior (sin estas claves) no revienta calcular_alertas",
        alertas_de({"conexiones_perdidas": 0, "novedades": 1, "arranques": 1, "errores": 0}), [])
sin_cambio = registro_con("normal", [[], []])
afirmar("lo normal no alerta", (sin_cambio["rechazos_decision"], alertas_de(sin_cambio)), (0, []))

print(f"\n{total} comprobaciones · {fallos} fallos")
sys.exit(1 if fallos else 0)
