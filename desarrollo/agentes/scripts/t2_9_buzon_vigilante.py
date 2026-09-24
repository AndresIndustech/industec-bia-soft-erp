"""
T2.9 - Vigilante del buzón: mantiene el sistema al día casi en tiempo real.

QUE RESUELVE
Hasta ahora el barrido del correo se corría a mano y el JSON se subía a
Hostinger por el administrador de archivos. Eso significa que un caso urgente
podía estar horas sin que nadie lo viera en el sistema.

COMO LO HACE, Y POR QUE ASI

1. ESCUCHA, NO PREGUNTA. Usa IMAP IDLE: deja una conexión abierta y el servidor
   avisa cuando entra un correo. La reacción es de segundos, y no se golpea el
   buzón cada minuto.

   OJO CON LA DETECCION DE IDLE: `imaplib` guarda las capacidades que el
   servidor anuncia ANTES de autenticar, y ahí Titan NO declara IDLE.
   `"IDLE" in M.capabilities` da False y es MENTIRA. En estado seleccionado sí
   lo declara, y un `IDLE` crudo responde `+ Idling`. Comprobado el 2026-09-09
   contra imap.titan.email. Si se confía en la primera lectura se termina
   programando un sondeo cada minuto sin ninguna necesidad.

2. SIGUE SIENDO DE SOLO LECTURA. La restricción del cliente no se toca: no se
   marca leído, no se mueve, no se borra. Este vigilante solo detecta que algo
   cambió; el análisis lo sigue haciendo `t2_6_imap_avisos.py`, que ya está
   verificado con EXAMINE + BODY.PEEK[]. Se lo invoca como proceso aparte a
   propósito: reescribirlo para meterlo aquí arriesgaría romper un lector que
   funciona y que tiene una restricción del cliente encima.

3. LA ESTACION SIEMPRE SALE, NUNCA RECIBE. La estación está detrás de NAT
   (192.168.0.110, la IP pública la tiene el router). Abrir un puerto exigiría
   redirección y DNS dinámico, y dejaría la laptop expuesta a internet. En vez
   de eso, ella misma empuja el resultado a Hostinger por HTTPS de salida.
   Funciona detrás de cualquier NAT, sobrevive a un cambio de IP y no hay nada
   que atacar desde afuera.

4. LA CLAVE DEL CORREO NO VIAJA. Se queda en `config/.env`, en la estación.
   Hostinger nunca la ve, así que quien comprometa el hosting no se lleva el
   acceso al buzón de la empresa.

6. Y BAJA EL PDF DE PRODUCCION EN EL ACTO (T2.21.7). El correo es la SEÑAL de
   que hay una orden nueva; el archivo bueno esta en el servidor. Como
   `uploads/` del sistema viejo solo conserva ~3 meses, cada aviso dispara
   tambien el espejo a D:/RESPALDOS, que es el unico almacenamiento definitivo.

5. INTENTA EMPUJAR SOLO SI CAMBIO ALGO: compara el hash del archivo. Pero el
   lector sella dentro la hora del barrido, así que en la práctica casi siempre
   empuja (anotado en la auditoría del 2026-09-10; no rompe nada, solo sobra).

CUANTO TARDA DE VERDAD
  correo -> estación   : segundos (IDLE)
  estación -> Hostinger: segundos más (el barrido tarda ~1 min con 90 días)
  Hostinger -> pantalla: hasta 30 s (la pantalla consulta si hay novedades)
Total: normalmente por debajo del minuto y medio. No es instantáneo y no se
promete como tal.

Uso:
    .venv/Scripts/python.exe scripts/t2_9_buzon_vigilante.py --una-vez   # prueba
    .venv/Scripts/python.exe scripts/t2_9_buzon_vigilante.py            # vigila
"""

import argparse
import hashlib
import imaplib
import json
import os
import re
import select
import socket
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

# Las rutas salen de la ubicación de este archivo: en la estación dan lo mismo
# que antes (D:\INDUSTECH IA\...) y en otra copia del proyecto no apuntan a una
# unidad que no existe.
sys.path.insert(0, str(Path(__file__).parent))
from comun import empujar as empujar_firmado  # noqa: E402

BASE = Path(__file__).resolve().parents[1]
ENV_PATH = BASE / "config" / ".env"
LECTOR = BASE / "scripts" / "t2_6_imap_avisos.py"
INFORMES = BASE / "scripts" / "t2_11_informes_ot.py"
ESPEJO = BASE / "scripts" / "t2_4_sync_hostinger.py"
PYTHON = BASE / ".venv" / "Scripts" / "python.exe"
SALIDA = BASE.parents[1] / "SALIDAS IA" / "OTS" / "catalogos" / "casos_sap.json"
ESTADO = BASE / "config" / "vigilante_estado.json"

# RFC 2177 permite hasta 29 minutos, pero Titan corta el IDLE a los ~20: en los
# logs del 2026-09-10 se ve el corte a los 20 min y, renovando a los 24, un
# SSLEOFError en cada ciclo y el vigilante sordo en el medio. Se renueva a los
# 9, con margen de sobra por debajo del corte.
IDLE_MINUTOS = 9
# Si el barrido se dispara, no se vuelve a disparar hasta pasado esto. Sin
# freno, una tanda de 20 correos seguidos lanzaria 20 barridos encimados.
ESPERA_MINIMA_SEG = 45
# Ventana que se le pide a produccion, en minutos. El piso evita pedir "los
# ultimos 2 minutos" cuando llegan dos correos seguidos y perder un PDF que el
# sistema escribio un momento antes del aviso. El techo (3 dias) evita que, tras
# una parada larga, la primera corrida mande a hashear el directorio entero.
ESPEJO_VENTANA_MIN, ESPEJO_VENTANA_MAX = 60, 3 * 24 * 60
REINTENTO_INICIAL, REINTENTO_MAXIMO = 10, 600
# T2.28.18c, afinado el 2026-09-24: un intento de reconexión que falla y el
# siguiente prende no es un error. Los dos «ERROR» de la madrugada del 24 eran
# eso: la red de la estación se cayó unos segundos (se cortó la sesión y
# enseguida falló el DNS, `getaddrinfo failed`) y a los 21 s ya estaba
# reconectado y barriendo lo que llegó. Solo se anota ERROR si el buzón lleva
# más que esto sin poder reconectarse: ahí sí hay algo que mirar.
UMBRAL_CAIDA_ERROR_SEG = 300
# Candado de instancia única. El 2026-09-23 llegó a haber dos vigilantes a la
# vez (uno lanzado a mano y otro por la Tarea programada), escribiendo el mismo
# registro y el mismo vigilante_estado.json. Es un bloqueo del sistema operativo
# sobre el archivo, no un archivo que haya que borrar: si el proceso muere, el
# sistema lo suelta solo y nunca queda un candado huérfano.
CANDADO = BASE / "logs" / "vigilante.lock"
_candado_fd = None


RECHAZOS = BASE / "logs" / "candado_vigilante.log"   # no casa con 'vigilante-*.log' a propósito


def tomar_candado_unico() -> str:
    """'tomado' si este es el único vigilante, 'ocupado' si ya hay otro, o
    'sin candado: <motivo>' si el archivo no se pudo abrir (p. ej. quedó de
    solo lectura). En ese último caso el robot SIGUE, avisando: un robot caído
    por no poder abrir su candado es peor que el riesgo de un duplicado, que
    InspectorBot igual detecta."""
    global _candado_fd
    try:
        import msvcrt
    except ImportError:                       # fuera de Windows: no se bloquea
        return "tomado"
    try:
        CANDADO.parent.mkdir(parents=True, exist_ok=True)
        fd = os.open(str(CANDADO), os.O_RDWR | os.O_CREAT)
    except OSError as e:
        return f"sin candado: {type(e).__name__}: {e}"
    try:
        os.lseek(fd, 0, os.SEEK_SET)
        msvcrt.locking(fd, msvcrt.LK_NBLCK, 1)
    except OSError:
        os.close(fd)
        return "ocupado"
    _candado_fd = fd                          # abierto hasta que el proceso termine
    return "tomado"


def ahora() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def log(msg: str) -> None:
    print(f"[{ahora()}] {msg}", flush=True)


def cargar_env() -> dict:
    env = {}
    for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return env


# ---------------------------------------------------------------------------
# Empuje a Hostinger
# ---------------------------------------------------------------------------
def empujar(env: dict, contenido: bytes) -> bool:
    """Manda el catalogo al endpoint de Hostinger, firmado.

    Es un envoltorio sobre `comun.empujar`, que es la copia CANONICA. Antes
    habia aqui una copia entera del empuje que ya habia divergido de la de
    t2_11 (le faltaba la cabecera X-Industec-Tipo) y que, sobre todo, no
    reintentaba: el 2026-09-18 trece empujes se perdieron por cortes de TLS y
    el vigilante los dio por buenos. Una sola implementacion, un solo arreglo.
    """
    return empujar_firmado(env, contenido, tipo="casos",
                           agente="industec-vigilante", log=log, timeout=45)


def procesar_informes(dias: int) -> bool:
    """Actualiza qué casos ya se atendieron, leyendo los informes de OT.

    AL BUZON LLEGAN DOS COSAS DISTINTAS, y hasta ahora solo se atendía una:

      de sgerente@kfc.com.ec        KFC pide algo -> caso nuevo
      de reclutamiento@industec.me  un técnico cerró una orden -> caso atendido

    Con solo lo primero, un técnico terminaba un trabajo y el sistema seguía
    mostrando el caso sin atender hasta la corrida de las 3 horas. La
    administradora lo veía pendiente y podía volver a repartirlo.

    Es barato repetirlo: `t2_11` guarda en caché el técnico de cada orden, así
    que solo baja el PDF de las que no ha visto.
    """
    log("revisando los informes de OT...")
    r = subprocess.run([str(PYTHON), str(INFORMES), "--empujar", "--dias", str(dias)],
                       cwd=str(BASE), capture_output=True, text=True, timeout=900)
    if r.returncode != 0:
        log(f"ERROR: los informes salieron con {r.returncode}")
        for linea in (r.stderr or "").strip().splitlines()[-4:]:
            log(f"   {linea}")
        return False
    for linea in (r.stdout or "").splitlines():
        # Los avisos de error del informe también: filtrados, un empuje fallido
        # quedaba sin rastro en el log del vigilante.
        if linea.strip().startswith(("casos pendientes con atencion", "empujado",
                                     "ERROR", "AVISO", "SIN tecnico")):
            log(f"   {linea.strip()}")
    return True


def espejar_produccion() -> bool:
    """Baja de produccion los PDFs nuevos, en el mismo instante en que el correo
    avisa que existen (T2.21.7).

    POR QUE PRODUCCION Y NO EL CORREO. Los dos traen el mismo documento, pero no
    son igual de buenos como fuente:

      el correo      es la SEÑAL: llega en segundos y dice que hay algo nuevo
      produccion     es la FUENTE: el archivo tal como el sistema lo emitio,
                     sin pasar por codificacion de adjunto, y esta completo
                     aunque el correo se haya quedado en el camino

    Y ninguno de los dos es almacenamiento: `uploads/` del sistema viejo se
    conserva ~3 meses y despues se limpia solo. El unico definitivo es
    D:\\RESPALDOS. De ahi que esto corra pegado al aviso y no una vez al dia:
    cada hora sin espejo es material que solo existe en un servidor que lo va a
    borrar.

    LA VENTANA SE CALCULA, NO SE FIJA. Se pide al servidor lo modificado desde
    el ultimo espejo exitoso, con media hora de margen. Si el vigilante estuvo
    caido cuatro dias, la primera corrida al volver pide cuatro dias; una
    ventana fija de una hora se habria saltado todo lo demas en silencio.

    NO ES CRITICA. Si falla, se anota y se sigue: la pantalla de la
    administracion no depende de esto, y el proximo aviso lo reintenta con una
    ventana mas ancha (el sello solo se mueve cuando la corrida sale bien).
    """
    desde = None
    if ESTADO.is_file():
        try:
            desde = datetime.fromisoformat(
                json.loads(ESTADO.read_text(encoding="utf-8"))["ultimo_espejo_utc"])
        except Exception:
            desde = None
    if desde is None:
        minutos = ESPEJO_VENTANA_MAX
    else:
        transcurridos = (datetime.now(timezone.utc) - desde).total_seconds() / 60
        minutos = int(min(max(transcurridos + 30, ESPEJO_VENTANA_MIN), ESPEJO_VENTANA_MAX))

    log(f"espejando lo que produccion emitio en los ultimos {minutos} min...")
    r = subprocess.run([str(PYTHON), str(ESPEJO), "--sin-app", "--sin-auxiliares",
                        "--recientes", str(minutos)],
                       cwd=str(BASE), capture_output=True, text=True, timeout=3600)
    # Solo lo que cambio algo. El filtro mira el NUMERO, no el final de la
    # linea: "Divergentes : 0  (mismo nombre...)" no termina en ": 0" y se
    # colaba en el registro cada corrida, ensuciando justo lo que hay que mirar.
    for linea in (r.stdout or "").splitlines():
        m = re.match(r"(Bajados|Fallidos|Divergentes)\s*:\s*(\d+)", linea.strip())
        if m and m.group(2) != "0":
            log(f"   {linea.strip()}")
    if r.returncode != 0:
        log(f"AVISO: el espejo de produccion salio con {r.returncode} "
            f"(no frena el ciclo; se reintenta con el proximo aviso)")
        # La CAUSA, no el final del texto. Antes se registraban las tres ultimas
        # lineas y eran siempre las mismas tres del resumen: el "ERROR:" del
        # modulo que fallo quedaba fuera, y un fallo sin causa no se puede
        # arreglar (paso el 2026-09-20 en la primera corrida real).
        causas = [x.strip() for x in (r.stdout or "").splitlines()
                  if re.search(r"ERROR|FALLIDOS|\s+- |bajaron a 0", x)]
        for linea in (causas or (r.stdout or "").strip().splitlines())[-8:]:
            log(f"   {linea}")
        if r.stderr and r.stderr.strip():
            log(f"   stderr: {r.stderr.strip()[:300]}")
        return False
    # t2_4 sale con 0 aunque se salte la corrida porque otra (el nocturno) tiene
    # su candado. No bajó nada: mover el sello haría que la próxima ventana
    # empezara después del hueco (revisión del 2026-09-24).
    if "SALTADO_POR_CANDADO" in (r.stdout or ""):
        log("AVISO: el espejo de produccion no corrio: el nocturno lo tiene tomado. "
            "No se mueve el sello; lo recoge el proximo aviso")
        return False
    ESTADO.parent.mkdir(parents=True, exist_ok=True)
    ESTADO.write_text(json.dumps(
        {"ultimo_espejo_utc": datetime.now(timezone.utc).isoformat()},
        indent=1), encoding="utf-8")
    return True


def barrer_y_empujar(env: dict, dias: int) -> bool:
    """Corre el lector verificado y, si el resultado cambió, lo manda."""
    antes = hashlib.sha256(SALIDA.read_bytes()).hexdigest() if SALIDA.is_file() else ""
    log(f"barriendo el buzón ({dias} días)...")
    r = subprocess.run([str(PYTHON), str(LECTOR), "--dias", str(dias)],
                       cwd=str(BASE), capture_output=True, text=True, timeout=600)
    if r.returncode != 0:
        log(f"ERROR: el lector salió con {r.returncode}")
        for linea in (r.stderr or "").strip().splitlines()[-6:]:
            log(f"   {linea}")
        return False

    for linea in (r.stdout or "").splitlines():
        if linea.startswith(("casos vigentes", "ordenes eliminadas")):
            log(f"   {linea.strip()}")

    if not SALIDA.is_file():
        log("ERROR: el lector no dejó el archivo de casos")
        return False
    contenido = SALIDA.read_bytes()
    if hashlib.sha256(contenido).hexdigest() == antes:
        log("sin cambios respecto al barrido anterior: no se empuja")
        return True
    return empujar(env, contenido)


# ---------------------------------------------------------------------------
# IMAP IDLE, de solo lectura
# ---------------------------------------------------------------------------
def abrir(env: dict) -> imaplib.IMAP4_SSL:
    # Con tiempo límite: sin él, una conexión que el servidor deja colgada al
    # entrar bloquea al vigilante para siempre.
    M = imaplib.IMAP4_SSL(env["IMAP_HOST"], int(env["IMAP_PORT"]), timeout=60)
    M.login(env["IMAP_USER"], env["IMAP_PASSWORD"])
    # readonly=True -> EXAMINE. El servidor no puede cambiar banderas ni aunque
    # este proceso se equivoque: es la restricción del cliente, en el protocolo.
    ok, _ = M.select("INBOX", readonly=True)
    if ok != "OK":
        raise RuntimeError("no se pudo abrir INBOX en modo solo lectura")
    return M


def contar(M: imaplib.IMAP4_SSL) -> int:
    """Cuántos mensajes hay en INBOX. Se vuelve a EXAMINAR (solo lectura) tras
    cada IDLE: lo que entra entre el DONE y el siguiente IDLE no llega como
    aviso, y sin esta cuenta se perdía hasta el correo siguiente."""
    # esperar_novedad deja el socket sin tiempo límite; aquí se le pone uno
    # para que un EXAMINE sin respuesta no cuelgue al vigilante para siempre.
    M.sock.settimeout(60)
    ok, data = M.select("INBOX", readonly=True)
    if ok != "OK":
        raise RuntimeError("no se pudo volver a examinar INBOX")
    return int(data[0])


def esperar_novedad(M: imaplib.IMAP4_SSL, segundos: int) -> bool:
    """
    Bloquea hasta que el servidor avise algo, o hasta agotar `segundos`.
    Devuelve True si hubo novedad que amerite barrer.

    `imaplib` no trae IDLE, así que se habla el protocolo a mano: se manda
    IDLE, el servidor contesta `+`, y a partir de ahí empuja líneas sin que se
    le pregunte. `DONE` cierra. Es importante mandar DONE SIEMPRE, incluso al
    salir por timeout: si no, la conexión queda a medias y el siguiente comando
    recibe la respuesta equivocada.
    """
    tag = M._new_tag().decode()
    M.send(f"{tag} IDLE\r\n".encode())
    M.sock.settimeout(20)

    # El servidor NO siempre contesta `+ Idling` en la primera línea.
    #
    # Si entra un correo justo en ese instante, lo primero que manda es la
    # notificación -- `* 8068 EXISTS` -- y la continuación viene detrás. La
    # primera versión leía una sola línea y, al no ver el `+`, daba la conexión
    # por rota: se reconectaba cada vez que llegaba un correo en el peor
    # momento. Salió en el log del 2026-09-09 a las 17:11.
    #
    # Peor que el churn: esa notificación ES la novedad que se estaba
    # esperando, y se perdía. Aquí se lee hasta la continuación y lo que venga
    # antes se toma por lo que es.
    novedad = False
    try:
        for _ in range(20):
            r = M.readline().decode(errors="replace")
            if not r:
                raise RuntimeError("el servidor cerró la conexión al pedir IDLE")
            if r.startswith("+"):
                break
            if " EXISTS" in r or " EXPUNGE" in r:
                log(f"aviso del servidor (antes del IDLE): {r.strip()}")
                novedad = True
            # Cualquier otra línea sin etiquetar se ignora: son avisos de estado
            # del buzón que no cambian lo que hay que hacer.
        else:
            raise RuntimeError("el servidor no aceptó IDLE tras 20 líneas")
    except Exception:
        M.sock.settimeout(None)
        raise

    if novedad:
        # Ya hay algo que barrer: se cierra el IDLE y se sale, en vez de
        # quedarse esperando una segunda notificación que quizá no llegue.
        try:
            M.send(b"DONE\r\n")
            M.sock.settimeout(20)
            for _ in range(12):
                if M.readline().decode(errors="replace").startswith(tag):
                    break
        except Exception:
            pass
        try:
            M.sock.settimeout(None)
        except Exception:
            pass
        return True

    # El socket vuelve a bloqueante: quien mide el tiempo ahora es select(),
    # y un timeout puesto aqui es justo lo que rompe el buffer de readline().
    M.sock.settimeout(None)

    limite = time.monotonic() + segundos
    try:
        while time.monotonic() < limite:
            # select() ANTES de leer, en vez de leer con un timeout corto.
            #
            # `imaplib.readline()` lee de un fichero con buffer montado sobre el
            # socket. Si el socket vence a mitad de una lectura, ese fichero
            # queda inservible y todo lo que se lea despues revienta con
            # «cannot read from timed out object». La primera version ponia
            # timeout de 60 s y leia: cada minuto saltaba esa excepcion, se daba
            # la conexion por perdida y se volvia a entrar al buzon. Son 1.440
            # conexiones al correo por dia para no hacer nada -- lo contrario de
            # lo que el IDLE viene a resolver, y una buena forma de que el
            # proveedor acabe limitando la cuenta.
            #
            # Con select() se espera sobre el descriptor sin tocar el socket, y
            # solo se lee cuando de verdad hay algo. Se vio en el log del
            # 2026-09-09: «escuchando» a las 17:51:24 y «conexion perdida» a las
            # 17:52:24, exactamente los 60 segundos del timeout.
            espera = max(1.0, min(60.0, limite - time.monotonic()))
            try:
                listos, _, _ = select.select([M.sock], [], [], espera)
            except (OSError, ValueError):
                raise RuntimeError("el socket dejo de ser valido")
            if not listos:
                continue                       # nada que leer todavia

            linea = M.readline().decode(errors="replace").strip()
            if not linea:
                raise RuntimeError("el servidor cerró la conexión")
            # EXISTS = llegó correo. EXPUNGE = KFC eliminó una orden, que
            # también cambia el buzón y hay que reflejarlo.
            if " EXISTS" in linea or " EXPUNGE" in linea:
                log(f"aviso del servidor: {linea}")
                novedad = True
                break
    finally:
        try:
            M.send(b"DONE\r\n")
            M.sock.settimeout(20)
            for _ in range(12):
                if M.readline().decode(errors="replace").startswith(tag):
                    break
        except Exception:
            pass
        try:
            M.sock.settimeout(None)
        except Exception:
            pass
    return novedad


def abrir_log(nombre):
    """Manda la salida a `logs/<nombre>-<fecha>.log`.

    Lo hace el script y no el .bat a proposito. Nombrar el archivo desde cmd
    obliga a sacar la fecha de `%DATE:~n,m%` -- que depende del formato regional
    y aqui producia "vigilante-2026 0mi.log" -- o de un `for /f` contra
    PowerShell, que funcionaba a mano y fallaba dentro de la Tarea programada,
    dejandola salir con codigo 1 sin escribir una sola linea. Aqui la fecha es
    un `strftime` y no hay nada que se rompa segun quien lo lance.
    """
    d = BASE / "logs"
    d.mkdir(parents=True, exist_ok=True)
    ruta = d / f"{nombre}-{datetime.now():%Y-%m-%d}.log"
    f = open(ruta, "a", encoding="utf-8", buffering=1)
    sys.stdout = f
    sys.stderr = f
    return ruta


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dias", type=int, default=90, help="ventana del barrido")
    ap.add_argument("--una-vez", action="store_true",
                    help="barre y empuja una sola vez; no se queda vigilando")
    ap.add_argument("--log", action="store_true",
                    help="escribe en logs/ en vez de por pantalla")
    args = ap.parse_args()
    # El candado ANTES de abrir el registro (revisión del 2026-09-24): si una
    # instancia rechazada escribiera en vigilante-<hoy>.log cada 10 min,
    # InspectorBot vería «señal» ahí y nunca avisaría de un robot trabado.
    candado = "tomado" if args.una_vez else tomar_candado_unico()
    if candado == "ocupado":
        # Sale con 0: no es una falla, es la protección funcionando.
        with open(RECHAZOS, "a", encoding="utf-8") as f:
            f.write(f"[{ahora()}] ya hay un vigilante corriendo ({CANDADO.name} tomado): "
                    f"este (PID {os.getpid()}) se cierra sin hacer nada\n")
        sys.exit(0)
    if args.log:
        abrir_log("vigilante")
    if candado.startswith("sin candado"):
        log(f"ERROR: no se pudo abrir {CANDADO.name} ({candado[13:]}); sigo trabajando sin "
            f"candado de instancia única -- revisa ese archivo")
    # T2.28.18a: identifica este proceso en logs/ssh_llamadas.csv de
    # hostinger_ssh.py. Va como variable de entorno (no como parametro) porque
    # este proceso llama a t2_11_informes_ot.py y t2_4_sync_hostinger.py como
    # SUBPROCESOS: la heredan solos, sin que cada script intermedio tenga que
    # reenviarla a mano.
    os.environ["INDUSTEC_PROCESO"] = "vigilante"
    env = cargar_env()

    if args.una_vez:
        sys.exit(0 if barrer_y_empujar(env, args.dias) else 1)

    log("vigilante en marcha. Ctrl+C para parar.")
    log(f"buzón {env['IMAP_USER']} en {env['IMAP_HOST']} — SOLO LECTURA")
    # Al arrancar se ponen al día las dos cosas: si el vigilante estuvo caído,
    # ahí dentro hay casos nuevos Y órdenes cerradas que nadie ha procesado.
    ok_inicio = barrer_y_empujar(env, args.dias)
    procesar_informes(args.dias)
    espejar_produccion()

    espera = REINTENTO_INICIAL
    ultimo = 0.0
    # Si el barrido del arranque falló (p. ej. la estación arrancó sin red), la
    # primera conexión hace la puesta al día; antes se la saltaba y los casos
    # quedaban viejos hasta el siguiente correo (revisión del 2026-09-24).
    primera = bool(ok_inicio)
    caido_desde = None      # desde cuándo está sin conexión (time.monotonic)
    intentos = 0            # intentos de reconexión fallidos en esta racha
    while True:
        M = None
        try:
            try:
                M = abrir(env)
            except Exception as e:
                intentos += 1
                if caido_desde is None:
                    caido_desde = time.monotonic()
                caido = time.monotonic() - caido_desde
                detalle = f"{type(e).__name__}: {e}"
                if caido < UMBRAL_CAIDA_ERROR_SEG:
                    # Un corte breve de red: se reintenta y casi siempre prende
                    # al siguiente. AVISO, para que InspectorBot no lo cuente
                    # como error (clasificar() mira el prefijo).
                    log(f"AVISO: no se pudo reconectar al buzón (intento {intentos}, "
                        f"{detalle}); reintento en {espera}s")
                else:
                    log(f"ERROR: el buzón lleva {caido / 60:.0f} min sin poder reconectarse "
                        f"({intentos} intentos; el último: {detalle}); reintento en {espera}s")
                time.sleep(espera)
                espera = min(espera * 2, REINTENTO_MAXIMO)
                continue
            if intentos:
                log(f"reconectado tras {intentos} intento(s) fallido(s) y "
                    f"{time.monotonic() - caido_desde:.0f} s sin conexión")
            caido_desde, intentos = None, 0
            espera = REINTENTO_INICIAL          # conectó: se reinicia el castigo
            if not primera:
                # Lo que llegó con la conexión caída no avisa por IDLE: se barre
                # al reconectar. Sin esto el vigilante quedaba sordo hasta el
                # correo siguiente (logs del 2026-09-10).
                log("reconectado: se barre lo que pudo llegar mientras tanto")
                barrer_y_empujar(env, args.dias)
                procesar_informes(args.dias)
                espejar_produccion()
                ultimo = time.monotonic()
            primera = False
            conteo = contar(M)
            log(f"escuchando (renovación cada {IDLE_MINUTOS} min)")
            while True:
                hubo = esperar_novedad(M, IDLE_MINUTOS * 60)
                ahora_n = contar(M)
                if hubo or ahora_n != conteo:
                    if not hubo:
                        log(f"el buzón cambió sin aviso ({conteo} -> {ahora_n})")
                    falta = ESPERA_MINIMA_SEG - (time.monotonic() - ultimo)
                    if falta > 0:
                        log(f"esperando {falta:.0f}s para agrupar correos seguidos")
                        time.sleep(falta)
                    # Los dos, en orden: primero el catálogo de casos, que es
                    # lo que la pantalla lee, y después los informes, que se
                    # cruzan CONTRA ese catálogo. Al revés, el informe de un
                    # caso recién llegado no encontraría el caso.
                    barrer_y_empujar(env, args.dias)
                    procesar_informes(args.dias)
                    # Al final de los tres: es lo unico que no mira nadie en
                    # pantalla, y es lo mas lento. Primero se actualiza lo que
                    # la administracion esta viendo.
                    espejar_produccion()
                    ultimo = time.monotonic()
                    conteo = contar(M)
                else:
                    conteo = ahora_n
                    # Latido: sin esta línea, una noche tranquila dejaba el
                    # registro horas callado y InspectorBot marcaba GRAVE «el robot
                    # lleva callado demasiado» con el robot sano (revisión del
                    # 2026-09-24: de 22:10 a 00:39 y de 02:07 a 04:26 del 23-sep).
                    log(f"escuchando (sin novedades; renovación cada {IDLE_MINUTOS} min)")
        except KeyboardInterrupt:
            log("detenido a mano")
            break
        except Exception as e:
            # Sesión que SI estaba viva y se cortó (IDLE que Titan cierra solo,
            # "socket error: EOF" al examinar, etc.): esperable, se reconecta.
            # AVISO, no error (T2.28.18c) -- clasificar() ya lo pone en la
            # familia "aviso" con solo buscar "conexión perdida" en el texto.
            # Si la reconexión de verdad falla, lo dice el bloque de arriba.
            if caido_desde is None:
                caido_desde = time.monotonic()     # la racha empieza con el corte
            log(f"conexión perdida ({type(e).__name__}: {e}); reintento en {espera}s")
            time.sleep(espera)
            # Retroceso exponencial: si el correo o la red están caídos, no se
            # insiste cada 10 segundos durante horas.
            espera = min(espera * 2, REINTENTO_MAXIMO)
        finally:
            if M is not None:
                try:
                    M.logout()
                except Exception:
                    pass


if __name__ == "__main__":
    main()
