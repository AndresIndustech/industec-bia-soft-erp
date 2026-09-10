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

5. SOLO EMPUJA SI CAMBIO ALGO. Compara el hash del contenido; si el barrido da
   lo mismo, no manda nada.

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
import hmac
import imaplib
import json
import select
import socket
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

BASE = Path(r"D:\INDUSTECH IA\desarrollo\agentes")
ENV_PATH = BASE / "config" / ".env"
LECTOR = BASE / "scripts" / "t2_6_imap_avisos.py"
INFORMES = BASE / "scripts" / "t2_11_informes_ot.py"
PYTHON = BASE / ".venv" / "Scripts" / "python.exe"
SALIDA = Path(r"D:\INDUSTECH IA\SALIDAS IA\OTS\catalogos\casos_sap.json")
ESTADO = BASE / "config" / "vigilante_estado.json"

# RFC 2177 manda renovar el IDLE antes de los 29 minutos. Se usa 24 para tener
# margen: si el servidor corta primero, se pierde el aviso hasta la renovacion.
IDLE_MINUTOS = 24
# Si el barrido se dispara, no se vuelve a disparar hasta pasado esto. Sin
# freno, una tanda de 20 correos seguidos lanzaria 20 barridos encimados.
ESPERA_MINIMA_SEG = 45
REINTENTO_INICIAL, REINTENTO_MAXIMO = 10, 600


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
    """
    Manda el catálogo al endpoint de Hostinger, firmado.

    LA FIRMA ES LO QUE PROTEGE EL ENDPOINT. No hay sesión de por medio -- esto
    es máquina a máquina-- así que la única prueba de que el envío viene de la
    estación es un HMAC-SHA256 sobre `timestamp.cuerpo` con un secreto que solo
    conocen los dos lados. Va la marca de tiempo DENTRO de lo firmado para que
    un envío capturado no se pueda reenviar mañana: el servidor rechaza todo lo
    que se aparte más de 5 minutos de su reloj.

    Sin el timestamp firmado, cualquiera que grabe un POST válido podría
    repetirlo para revertir el buzón a un estado viejo.
    """
    url = env.get("SYNC_URL", "").strip()
    secreto = env.get("SYNC_SECRETO", "").strip()
    if not url or not secreto:
        log("SYNC_URL o SYNC_SECRETO no están en config/.env: no se empuja")
        return False

    ts = str(int(time.time()))
    firma = hmac.new(secreto.encode(), ts.encode() + b"." + contenido,
                     hashlib.sha256).hexdigest()
    pedido = urllib.request.Request(
        url, data=contenido, method="POST",
        headers={"Content-Type": "application/json",
                 "X-Industec-Ts": ts,
                 "X-Industec-Firma": firma,
                 "User-Agent": "industec-vigilante/1.0"})
    try:
        ctx = ssl.create_default_context()          # certificado verificado
        with urllib.request.urlopen(pedido, timeout=45, context=ctx) as r:
            cuerpo = r.read(500).decode("utf-8", "replace")
            log(f"empujado: HTTP {r.status} {cuerpo.strip()[:160]}")
            return r.status == 200
    except urllib.error.HTTPError as e:
        log(f"ERROR del servidor: HTTP {e.code} {e.read(300).decode('utf-8','replace').strip()[:160]}")
    except Exception as e:
        log(f"ERROR al empujar: {type(e).__name__}: {e}")
    return False


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
        if linea.strip().startswith(("casos pendientes con atencion", "empujado")):
            log(f"   {linea.strip()}")
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
    M = imaplib.IMAP4_SSL(env["IMAP_HOST"], int(env["IMAP_PORT"]))
    M.login(env["IMAP_USER"], env["IMAP_PASSWORD"])
    # readonly=True -> EXAMINE. El servidor no puede cambiar banderas ni aunque
    # este proceso se equivoque: es la restricción del cliente, en el protocolo.
    ok, _ = M.select("INBOX", readonly=True)
    if ok != "OK":
        raise RuntimeError("no se pudo abrir INBOX en modo solo lectura")
    return M


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
    if args.log:
        abrir_log("vigilante")
    env = cargar_env()

    if args.una_vez:
        sys.exit(0 if barrer_y_empujar(env, args.dias) else 1)

    log("vigilante en marcha. Ctrl+C para parar.")
    log(f"buzón {env['IMAP_USER']} en {env['IMAP_HOST']} — SOLO LECTURA")
    # Al arrancar se ponen al día las dos cosas: si el vigilante estuvo caído,
    # ahí dentro hay casos nuevos Y órdenes cerradas que nadie ha procesado.
    barrer_y_empujar(env, args.dias)
    procesar_informes(args.dias)

    espera = REINTENTO_INICIAL
    ultimo = 0.0
    while True:
        M = None
        try:
            M = abrir(env)
            espera = REINTENTO_INICIAL          # conectó: se reinicia el castigo
            log(f"escuchando (renovación cada {IDLE_MINUTOS} min)")
            while True:
                if esperar_novedad(M, IDLE_MINUTOS * 60):
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
                    ultimo = time.monotonic()
        except KeyboardInterrupt:
            log("detenido a mano")
            break
        except Exception as e:
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
