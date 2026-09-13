"""
comun.py - Lo que todos los scripts de la estacion compartian a mano (T2.15.1).

POR QUE EXISTE
Hasta el 2026-09-12 `cargar_env`, `abrir_log`, `empujar` y las rutas del
proyecto estaban copiadas en seis scripts y ya divergian (auditoria H22: la
version de `empujar` de t2_9 no mandaba la cabecera X-Industec-Tipo que la de
t2_11 si manda; cinco scripts llevaban pegada la ruta absoluta D:\\INDUSTECH IA
y por eso no arrancaban fuera de la estacion, H10). Un cambio en la firma HMAC
o en el dominio productivo habia que replicarlo en seis sitios y el que se
olvidara fallaba en silencio contra sync_casos.php.

Aqui vive UNA copia de cada cosa. Los scripts importan de aqui:

    sys.path.insert(0, str(Path(__file__).parent))
    from comun import BASE, RAIZ, SALIDAS, RESPALDOS, cargar_env, abrir_log, empujar, fecha_ec

RUTAS: todas salen de la ubicacion de este archivo. `BASE` es
desarrollo/agentes (parents[1] del script), `RAIZ` es la raiz del repositorio
(dos niveles mas arriba) y `SALIDAS` es RAIZ/'SALIDAS IA'/'OTS'. En la estacion
dan exactamente lo mismo que las rutas absolutas de antes; en otra copia del
proyecto apuntan a la copia y no a una unidad que no existe.

`RESPALDOS` (el archivo definitivo de PDFs, hoy D:\\RESPALDOS) NO esta dentro del
repositorio: se lee de `RESPALDOS_DIR` en config/.env y, si no esta, se usa el
valor historico D:\\RESPALDOS. Asi otra maquina (o el dia que llegue el TrueNAS)
lo cambia en el .env sin tocar codigo.

NADA DE AQUI TOCA LA RED NI LA BASE al importarse: leer el .env es lo unico que
hace, y si el .env no existe, `RESPALDOS` cae al valor por defecto y `cargar_env`
es quien avisa cuando se lo llama de verdad.
"""
from __future__ import annotations

import hashlib
import hmac
import os
import re
import ssl
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

# --- Rutas --------------------------------------------------------------------
BASE = Path(__file__).resolve().parents[1]            # desarrollo/agentes
RAIZ = BASE.parents[1]                                # raiz del repositorio
ENV_PATH = BASE / "config" / ".env"
CONFIG = BASE / "config"
LOGS = BASE / "logs"
SALIDAS = RAIZ / "SALIDAS IA" / "OTS"
# El interprete del proyecto. En la estacion es el .venv; si no existe (otra
# copia del proyecto sin venv) se usa el que este corriendo este script, que
# es lo que el orquestador necesita para lanzar a los demas.
PYTHON = BASE / ".venv" / "Scripts" / "python.exe"
if not PYTHON.is_file():
    PYTHON = Path(sys.executable)

RESPALDOS_DEFECTO = Path(r"D:\RESPALDOS")


def leer_env(ruta: Path = ENV_PATH) -> dict:
    """Lee un .env de lineas CLAVE=valor. Devuelve {} si el archivo no existe.

    Es la version silenciosa: sirve para calcular constantes al importar sin que
    un .env ausente rompa `import comun`. Quien necesite claves de verdad usa
    `cargar_env`, que si aborta con un mensaje legible.
    """
    env = {}
    if not ruta.is_file():
        return env
    for line in ruta.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        env[k.strip()] = v.strip()
    return env


def cargar_env(obligatorias: tuple[str, ...] = (), ruta: Path = ENV_PATH) -> dict:
    """Carga config/.env y aborta (I-5, ruidosamente) si falta el archivo o
    alguna de las claves que el script que llama declara obligatorias.

    Se aborta con `sys.exit` y no con una excepcion porque el mensaje es para la
    persona que lanza el script: dice que falta y donde se consigue.
    """
    if not ruta.is_file():
        sys.exit(f"Falta {ruta}. Es el .env de la estacion (fuera de git); "
                 f"ver desarrollo/sistema_ots/LEEME_ACCESO_HOSTINGER.md")
    env = leer_env(ruta)
    faltan = [k for k in obligatorias if not env.get(k)]
    if faltan:
        sys.exit(f"FALTAN CLAVES en {ruta.name}: {', '.join(faltan)}")
    return env


def respaldos_dir(env: dict | None = None) -> Path:
    """La carpeta de respaldos definitiva: RESPALDOS_DIR del entorno (otro
    equipo, una prueba), si no la del .env, si no D:\\RESPALDOS."""
    env = leer_env() if env is None else env
    valor = (os.environ.get("RESPALDOS_DIR") or env.get("RESPALDOS_DIR") or "").strip().strip('"')
    return Path(valor) if valor else RESPALDOS_DEFECTO


RESPALDOS = respaldos_dir()


def sha256_de(path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def sello_utc(momento: datetime | None = None) -> str:
    """Sello compacto en UTC, el mismo que usan los manifiestos del espejo
    (20260912T213000Z). Todo lo que se fecha en el saneamiento va en UTC para
    que un archivo de la noche del 12 no se llame como el dia 13 segun el reloj
    local, y para que ordene bien por nombre."""
    momento = momento or datetime.now(timezone.utc)
    return momento.astimezone(timezone.utc).strftime("%Y%m%dT%H%M%SZ")


def fecha_ec(s) -> str | None:
    """dd/mm/aaaa -> aaaa-mm-dd. Devuelve None si no cuadra: no se adivina.

    Es la fecha como la escriben SAP y el sistema viejo de OTs. Vivia en
    t2_6_imap_avisos.py; t2_11 la necesita para ordenar las OTs de un aviso
    (H21: ordenarlas como texto dd/mm/aaaa daba mal la ultima fecha).
    """
    m = re.match(r"^\s*(\d{1,2})/(\d{1,2})/(\d{4})\s*$", s or "")
    if not m:
        return None
    d, mo, a = m.groups()
    return f"{a}-{int(mo):02d}-{int(d):02d}"


# --- Registro -------------------------------------------------------------------
def abrir_log(nombre: str) -> Path:
    """Manda stdout y stderr a `logs/<nombre>-<fecha>.log`.

    Lo hace el script y no el .bat a proposito. Nombrar el archivo desde cmd
    obliga a sacar la fecha de `%DATE:~n,m%` -- que depende del formato regional
    y aqui producia "vigilante-2026 0mi.log" -- o de un `for /f` contra
    PowerShell, que funcionaba a mano y fallaba dentro de la Tarea programada,
    dejandola salir con codigo 1 sin escribir una sola linea. Aqui la fecha es
    un `strftime` y no hay nada que se rompa segun quien lo lance.
    """
    LOGS.mkdir(parents=True, exist_ok=True)
    ruta = LOGS / f"{nombre}-{datetime.now():%Y-%m-%d}.log"
    f = open(ruta, "a", encoding="utf-8", buffering=1)
    sys.stdout = f
    sys.stderr = f
    return ruta


# --- Empuje firmado a Hostinger ------------------------------------------------
def empujar(env: dict, contenido: bytes, tipo: str = "casos",
            agente: str = "industec-estacion", log=print, timeout: int = 60) -> bool:
    """Manda un JSON al endpoint de Hostinger (sync_casos.php), firmado.

    Es la copia CANONICA: la de t2_9 (sin X-Industec-Tipo) y la de t2_11 (con
    ella) divergian. sync_casos.php toma `casos` como tipo por omision, asi que
    mandarlo siempre no cambia nada para el catalogo y hace explicito el de
    `atenciones`.

    LA FIRMA ES LO QUE PROTEGE EL ENDPOINT. No hay sesion de por medio -- esto
    es maquina a maquina -- asi que la unica prueba de que el envio viene de la
    estacion es un HMAC-SHA256 sobre `timestamp.cuerpo` con un secreto que solo
    conocen los dos lados. Va la marca de tiempo DENTRO de lo firmado para que
    un envio capturado no se pueda reenviar manana: el servidor rechaza todo lo
    que se aparte mas de 5 minutos de su reloj. Sin el timestamp firmado,
    cualquiera que grabe un POST valido podria repetirlo para revertir el buzon
    a un estado viejo.

    `log` es la funcion con la que se informa (print, o el log() con hora del
    vigilante). Devuelve True solo con HTTP 200.
    """
    url = (env.get("SYNC_URL") or "").strip()
    secreto = (env.get("SYNC_SECRETO") or "").strip()
    if not url or not secreto:
        log("SYNC_URL o SYNC_SECRETO no estan en config/.env: no se empuja")
        return False

    ts = str(int(time.time()))
    firma = hmac.new(secreto.encode(), ts.encode() + b"." + contenido,
                     hashlib.sha256).hexdigest()
    pedido = urllib.request.Request(
        url, data=contenido, method="POST",
        headers={"Content-Type": "application/json",
                 "X-Industec-Ts": ts,
                 "X-Industec-Firma": firma,
                 "X-Industec-Tipo": tipo,
                 "User-Agent": f"{agente}/1.0"})
    try:
        ctx = ssl.create_default_context()          # certificado verificado
        with urllib.request.urlopen(pedido, timeout=timeout, context=ctx) as r:
            cuerpo = r.read(500).decode("utf-8", "replace")
            log(f"empujado ({tipo}): HTTP {r.status} {cuerpo.strip()[:160]}")
            return r.status == 200
    except urllib.error.HTTPError as e:
        log(f"ERROR del servidor al empujar {tipo}: HTTP {e.code} "
            f"{e.read(300).decode('utf-8', 'replace').strip()[:160]}")
    except Exception as e:
        log(f"ERROR al empujar {tipo}: {type(e).__name__}: {e}")
    return False


def escribir_json_atomico(destino: Path, texto: str, intentos: int = 10) -> None:
    """Escribe a un temporal y reemplaza: quien lea a mitad de la escritura
    nunca ve un JSON cortado. En Windows el reemplazo falla si otro proceso
    tiene el archivo abierto en ese instante (el empuje lo lee); son lecturas
    de milisegundos, asi que se reintenta un momento antes de rendirse."""
    destino.parent.mkdir(parents=True, exist_ok=True)
    temporal = destino.with_name(destino.name + ".tmp")
    temporal.write_text(texto, encoding="utf-8")
    for intento in range(intentos):
        try:
            temporal.replace(destino)
            return
        except PermissionError:
            if intento == intentos - 1:
                raise
            time.sleep(0.5)
