"""t2_9_pruebas.py — Pruebas sin red ni buzón del vigilante (t2_9_buzon_vigilante.py).

Cubre lo que se cambió el 2026-09-24: reconexión (AVISO si prende enseguida,
ERROR si pasa de 5 min), latido en noches tranquilas, puesta al día si el
arranque falló, y el candado de instancia única con procesos de verdad.
No toca el robot vivo ni su candado real: el candado y los registros van a una
carpeta temporal, y un proceso que no debiera pasar del candado se corta con
código 99 antes de leer credenciales o conectarse.

Uso:  .venv/Scripts/python.exe scripts/t2_9_pruebas.py
"""
import socket
import subprocess
import sys
import tempfile
import time
from pathlib import Path

SCRIPTS = Path(__file__).resolve().parent   # funciona igual en la estación y en el PC de Andrés
sys.path.insert(0, str(SCRIPTS))
import t2_9_buzon_vigilante as V  # noqa: E402
CANDADO_REAL = V.tomar_candado_unico

TMP = Path(tempfile.mkdtemp(prefix="vig_"))
pasa = falla = 0


def afirmar(que, ok, detalle=""):
    global pasa, falla
    pasa, falla = (pasa + 1, falla) if ok else (pasa, falla + 1)
    print(f"  {'PASA ' if ok else 'FALLA'} {que:<66} {detalle}")


def correr(fallos_dns, avance, inicio_ok=True, novedades_vacias=0):
    lineas, reloj, n = [], [1000.0], {"abrir": 0, "esperar": 0, "barridos": 0}

    def abrir(env):
        n["abrir"] += 1
        if n["abrir"] <= fallos_dns:
            raise socket.gaierror(11001, "getaddrinfo failed")
        return object()

    def esperar(M, s):
        n["esperar"] += 1
        if n["esperar"] <= novedades_vacias:
            return False
        raise KeyboardInterrupt()

    def barrer(env, dias):
        n["barridos"] += 1
        return inicio_ok if n["barridos"] == 1 else True

    def mono():
        reloj[0] += avance
        return reloj[0]

    V.log = lambda m: lineas.append(m)
    V.abrir, V.contar, V.esperar_novedad = abrir, (lambda M: 0), esperar
    V.barrer_y_empujar, V.procesar_informes, V.espejar_produccion = barrer, (lambda d: None), (lambda: True)
    V.cargar_env = lambda: {"IMAP_USER": "prueba", "IMAP_HOST": "prueba"}
    V.tomar_candado_unico = lambda: "tomado"
    V.time.sleep, V.time.monotonic = (lambda s: None), mono
    sys.argv = ["t2_9_buzon_vigilante.py"]
    V.main()
    return lineas, n


print("== corte breve de DNS ==")
l, _ = correr(2, 5)
afirmar("dos intentos fallidos -> AVISO", sum(x.startswith("AVISO: no se pudo reconectar") for x in l) == 2)
afirmar("ninguna linea ERROR", not [x for x in l if "ERROR" in x])
afirmar("dice con cuantos intentos reconecto", any(x.startswith("reconectado tras 2 intento") for x in l))

print("\n== caida larga ==")
l, _ = correr(6, 70)
afirmar("pasados 5 min sin reconectar -> ERROR", any(x.startswith("ERROR: el buzón lleva") for x in l))

print("\n== latido en una noche tranquila ==")
l, _ = correr(0, 1, novedades_vacias=3)
afirmar("una linea 'escuchando (sin novedades' por renovacion",
        sum(x.startswith("escuchando (sin novedades") for x in l) == 3, f"{sum(x.startswith('escuchando (sin novedades') for x in l)}")

print("\n== arranque que fallo: la primera conexion se pone al dia ==")
l, n = correr(0, 1, inicio_ok=False)
afirmar("hace el barrido de puesta al dia al conectar", n["barridos"] == 2, f"barridos={n['barridos']}")
l, n = correr(0, 1, inicio_ok=True)
afirmar("si el arranque salio bien, no repite el barrido", n["barridos"] == 1, f"barridos={n['barridos']}")

print("\n== candado de instancia unica, con procesos de verdad ==")
PRE = ("import sys; from pathlib import Path; sys.path.insert(0, r'%s'); import t2_9_buzon_vigilante as V;"
       "V.CANDADO = Path(r'%s'); V.RECHAZOS = Path(r'%s');" % (SCRIPTS, TMP / "v.lock", TMP / "candado_vigilante.log"))
dueno = subprocess.Popen([sys.executable, "-c", PRE + "print(V.tomar_candado_unico(), flush=True); import time; time.sleep(8)"],
                         stdout=subprocess.PIPE, text=True)
primero = dueno.stdout.readline().strip()
afirmar("el primero toma el candado", primero == "tomado", primero)
if primero == "tomado":
    # Un segundo vigilante completo: si pasara del candado, cargar_env lo corta con 99.
    segundo = subprocess.run([sys.executable, "-c", PRE +
        "V.cargar_env = lambda: sys.exit(99); V.abrir_log = lambda n: (_ for _ in ()).throw(SystemExit(98));"
        "sys.argv = ['t2_9', '--log']; V.main()"], capture_output=True, text=True)
    afirmar("el segundo sale con 0 sin conectarse", segundo.returncode == 0, f"codigo {segundo.returncode}")
    rech = (TMP / "candado_vigilante.log").read_text(encoding="utf-8") if (TMP / "candado_vigilante.log").exists() else ""
    afirmar("deja su nota en candado_vigilante.log", "este (PID" in rech, rech.strip()[:80])
    afirmar("y NO abrio vigilante-<hoy>.log (salio antes de abrir_log)", segundo.returncode != 98)
dueno.wait()
libre = subprocess.run([sys.executable, "-c", PRE + "print(V.tomar_candado_unico())"], capture_output=True, text=True).stdout.strip()
afirmar("muerto el dueno, el sistema lo suelta solo", libre == "tomado", libre)

print("\n== candado que no se puede abrir: el robot sigue, avisando ==")
V.CANDADO = TMP / "no_existe_esta_carpeta" / "x" / "v.lock"
V.CANDADO.parent.parent.mkdir(parents=True)
V.CANDADO.parent.write_text("soy un archivo, no una carpeta")     # mkdir/open fallan
r = CANDADO_REAL()
afirmar("devuelve 'sin candado' en vez de reventar", r.startswith("sin candado"), r[:70])

print(f"\n{pasa} comprobaciones · {falla} fallos")
sys.exit(1 if falla else 0)
