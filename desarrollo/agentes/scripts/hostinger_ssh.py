"""
hostinger_ssh.py - El unico contrato de acceso por SSH a Hostinger (T2.15.1).

POR QUE EXISTE
`t2_4_sync_hostinger.py` nunca corrio: exigia cinco claves HOSTINGER_* que
config/.env no tiene, mientras `t2_10_desplegar.py` conectaba desde el 9-sep con
otro contrato (SSH_USER del .env, llave en INDUSTEC_LLAVE_SSH o
config/clave_hostinger, host y puerto fijos). Dos contratos para el mismo
servidor: uno funcionaba y el otro no (auditoria H01). Aqui queda UNO, el que
funciona, y sync, purga, volcado, informes y siembra importan de aqui.

CADA EQUIPO CON SU LLAVE. La estacion usa config/clave_hostinger; otro equipo
declara la suya en la variable de entorno INDUSTEC_LLAVE_SSH (el PC de Andres:
C:\\Users\\andre\\.ssh\\industec_hostinger_pc). Asi cada llave se revoca por
separado en hPanel. El usuario sale de SSH_USER en config/.env (o de
INDUSTEC_SSH_USER en el entorno).

LA IP MANDA SOBRE CUALQUIER HOSTNAME: `srv2020.hstgr.io` resuelve a 212.85.3.19,
que tambien acepta SSH pero NO es el servidor de esta cuenta. La IP la da
hPanel > Avanzado > Acceso SSH; si algun dia deja de conectar, se relee de ahi.

DOS DOCROOTS, DOS REGLAS:
  DOCROOT_PRUEBAS      el sitio nuevo (darkviolet): mano libre (Andres, 2026-09-09).
  DOCROOT_PRODUCCION   el sistema viejo (yellow-elephant/ot/produccion): SOLO
                       LECTURA. Regla de Andres del 2026-09-09: fuera del sitio
                       de pruebas, nada. `ssh()` lo vigila por codigo: un comando
                       que nombre produccion y contenga un verbo de escritura
                       (rm, mv, cp, tee, mkdir, chmod, una redireccion...) se
                       rechaza antes de conectar, salvo que quien llama pase
                       `escritura_produccion=True` -- y hoy solo lo pasa la purga,
                       que tiene sus propias compuertas y exige autorizacion del
                       momento. No alcanza con "acordarse".

CREDENCIALES DE LA BASE REMOTA: NUNCA VIAJAN. Para mysqldump y mysql en el
servidor, `guion_con_credenciales()` arma un guion bash que hace que PHP (en el
servidor) lea nucleo/config.php y escriba un .cnf temporal 0600 en ~/respaldos;
mysqldump/mysql lo leen con --defaults-extra-file y un `trap` lo borra al
terminar, pase lo que pase. La contrasena no aparece en la linea de comandos,
ni en `ps`, ni en la salida, ni en el .env de la estacion. `exec()` esta
deshabilitado en el PHP del hosting, asi que quien lanza mysqldump es el shell
de la sesion SSH, no PHP.

Uso directo:
    .venv/Scripts/python.exe scripts/hostinger_ssh.py --probar
"""
from __future__ import annotations

import argparse
import os
import re
import shlex
import subprocess
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import BASE, leer_env  # noqa: E402

HOST = "82.25.73.181"
PUERTO = 65002

SITIO_PRUEBAS = "darkviolet-armadillo-872352"
SITIO_PRODUCCION = "yellow-elephant-166233"

# Rutas relativas al home del usuario (/home/u671729428), como las usa t2_10.
DOCROOT_PRUEBAS = f"domains/{SITIO_PRUEBAS}.hostingersite.com/public_html/ot"
# SOLO LECTURA. Verificado contra desarrollo/sistema_ots/LEEME_ACCESO_HOSTINGER.md
# (carpeta `ot/produccion/`; "no se toca, regla 9") y por `ls -d` el 2026-09-12.
DOCROOT_PRODUCCION = f"domains/{SITIO_PRODUCCION}.hostingersite.com/public_html/ot/produccion"

# El config.php de la app nueva, que hoy vive en el sitio de pruebas. En el
# corte (T2.16) se cambia con `APP_CONFIG_PHP` en config/.env, no aqui.
CONFIG_APP_PRUEBAS = f"{DOCROOT_PRUEBAS}/nucleo/config.php"

# Carpeta del servidor, FUERA del docroot, donde se dejan volcados y el .cnf
# temporal. Existe desde el 2026-09-11 (0700) con los respaldos manuales.
RESPALDOS_REMOTO = "$HOME/respaldos"

# Verbos que escriben en disco. Si uno de estos aparece en un comando que
# nombra el sitio de produccion, `ssh()` lo rechaza (ver arriba).
_VERBOS_ESCRITURA = re.compile(
    r"(?:^|[;&|(\s])(rm|rmdir|mv|cp|tee|chmod|chown|touch|mkdir|truncate|unlink|dd|ln|shred|rsync)\s")
_REDIRECCIONES_INOCUAS = re.compile(r"(?:\d?>>?|&>)\s*/dev/null|\d>&\d")


class ErrorSsh(RuntimeError):
    pass


# --- Identidad --------------------------------------------------------------------
def llave() -> Path:
    """La llave privada: INDUSTEC_LLAVE_SSH del entorno o config/clave_hostinger.
    Aborta si no existe: sin llave no hay nada que probar."""
    ruta = Path(os.environ.get("INDUSTEC_LLAVE_SSH") or (BASE / "config" / "clave_hostinger"))
    if not ruta.is_file():
        sys.exit(f"Falta la llave SSH {ruta}.\n"
                 f"  En la estacion: config/clave_hostinger. En otro equipo: declara la tuya en\n"
                 f"  la variable de entorno INDUSTEC_LLAVE_SSH y autoriza la publica en hPanel.")
    avisar_permisos(ruta)
    return ruta


_permisos_avisados: set[str] = set()


def avisar_permisos(ruta: Path) -> None:
    """Avisa ANTES de conectar si el ssh de Windows va a rechazar la llave.

    El 2026-09-20 el espejo de produccion del vigilante fallo cinco veces
    seguidas con un escueto `ssh fallo (codigo 255)`, y a mano no se reproducia:
    Git Bash tolera los permisos del archivo y el ssh de Windows no. La llave
    tenia acceso para "Usuarios autenticados", asi que la Tarea programada
    -que usa el de Windows- la ignoraba. Diagnosticarlo costo varias vueltas
    por un error que no decia su causa; este aviso existe para que la proxima
    vez la diga sola, y de paso para que nadie deje una llave privada legible
    por medio mundo.

    Solo avisa: no cambia permisos por su cuenta ni aborta. Puede haber equipos
    con otro esquema de cuentas y no es este script quien decide eso.
    """
    if os.name != "nt" or str(ruta) in _permisos_avisados:
        return
    _permisos_avisados.add(str(ruta))
    try:
        r = subprocess.run(["icacls", str(ruta)], capture_output=True, text=True, timeout=20)
        salida = (r.stdout or "")
        riesgosos = [g for g in ("Authenticated Users", "Usuarios autenticados",
                                 "BUILTIN\\Usuarios", "BUILTIN\\Users", "Todos", "Everyone")
                     if g in salida]
        if riesgosos:
            print(f"AVISO: la llave {ruta.name} es accesible por {', '.join(riesgosos)}.\n"
                  f"  El ssh de Windows la RECHAZA por eso (sale con 255) aunque desde Git Bash\n"
                  f"  funcione. Si una Tarea programada falla con 255, es esto. Se corrige con:\n"
                  f'    icacls "{ruta}" /inheritance:r /grant:r "%USERNAME%:(F)"',
                  file=sys.stderr)
    except Exception:
        pass          # el aviso es una cortesia: si falla, no frena nada


def usuario(env: dict | None = None) -> str:
    env = leer_env() if env is None else env
    u = (os.environ.get("INDUSTEC_SSH_USER") or env.get("SSH_USER") or "").strip()
    if not u:
        sys.exit("Falta SSH_USER en config/.env (lo da hPanel > Avanzado > Acceso SSH)")
    return u


def destino(env: dict | None = None) -> str:
    return f"{usuario(env)}@{HOST}"


def config_app(env: dict | None = None) -> str:
    """Ruta remota del config.php de la app nueva (APP_CONFIG_PHP del .env o el
    del sitio de pruebas)."""
    env = leer_env() if env is None else env
    return (env.get("APP_CONFIG_PHP") or "").strip() or CONFIG_APP_PRUEBAS


def opciones_base() -> list[str]:
    """Opciones comunes a ssh, scp y sftp.

    BatchMode=yes es deliberado: si la llave no sirve, queremos que falle en el
    acto con un error legible, no que se quede colgado pidiendo contrasena en
    una tarea nocturna donde nadie la va a escribir (I-5: abortar ruidosamente).

    OJO CON LOS PERMISOS DE LA LLAVE EN WINDOWS (2026-09-20). Hay dos ssh.exe
    en esta estacion y NO se comportan igual:

        el de Git Bash            tolera los permisos del archivo
        C:\\Windows\\System32\\OpenSSH  los exige, y si no rechaza la llave

    Una llave sobre la que el grupo "Usuarios autenticados" tenga acceso hace
    que el ssh de Windows la ignore ("UNPROTECTED PRIVATE KEY FILE") y salga
    con 255. Eso significa que todo script que se pruebe a mano desde Git Bash
    puede pasar y fallar igual desde la Tarea programada, que usa el de
    Windows: paso exactamente asi con el espejo de produccion del vigilante.

    Si aparece un `ssh fallo (codigo 255)` que no se reproduce a mano:
        icacls config\\clave_hostinger /inheritance:r /grant:r "%USERNAME%:(F)"
    """
    return [
        "-i", str(llave()),
        "-o", "BatchMode=yes",
        "-o", "StrictHostKeyChecking=accept-new",
        "-o", "ConnectTimeout=20",
        "-o", "ServerAliveInterval=15",
    ]


# --- La compuerta de solo lectura -----------------------------------------------
def nombra_produccion(comando: str) -> bool:
    return SITIO_PRODUCCION in comando


def parece_escritura(comando: str) -> bool:
    """True si el comando contiene un verbo que escribe o una redireccion a un
    archivo. Las redirecciones a /dev/null y entre descriptores (2>&1) no cuentan."""
    if _VERBOS_ESCRITURA.search(comando):
        return True
    sin_inocuas = _REDIRECCIONES_INOCUAS.sub(" ", comando)
    return ">" in sin_inocuas


def afirmar_solo_lectura(comando: str, escritura_produccion: bool = False) -> None:
    if escritura_produccion:
        return
    if nombra_produccion(comando) and parece_escritura(comando):
        raise ErrorSsh(
            "RECHAZADO ANTES DE CONECTAR: el comando nombra el sitio de PRODUCCION y "
            "parece escribir en el (regla de Andres del 2026-09-09: fuera del sitio de "
            "pruebas, nada). Solo la purga, con sus compuertas, puede pasar "
            "escritura_produccion=True.\n  comando: " + comando[:200])


# --- Transporte -------------------------------------------------------------------
def ssh_crudo(comando: str, timeout: int = 300, escritura_produccion: bool = False,
              env: dict | None = None) -> subprocess.CompletedProcess:
    afirmar_solo_lectura(comando, escritura_produccion)
    cmd = ["ssh", "-p", str(PUERTO)] + opciones_base() + [destino(env), comando]
    return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)


def ssh(comando: str, timeout: int = 300, escritura_produccion: bool = False,
        env: dict | None = None) -> str:
    """Corre un comando en el servidor y devuelve stdout. Levanta ErrorSsh si falla."""
    r = ssh_crudo(comando, timeout, escritura_produccion, env)
    if r.returncode != 0:
        raise ErrorSsh(
            f"ssh fallo (codigo {r.returncode})\n"
            f"  comando: {comando[:200]}\n"
            f"  stderr : {(r.stderr or '').strip()[:500]}")
    return r.stdout


def scp_bajar(remoto: str, local: Path, timeout: int = 900, env: dict | None = None) -> None:
    """Baja UN archivo del servidor. Para muchos archivos usar sftp_bajar: scp
    abre una sesion por archivo y sobre el techo de I/O del plan compite con los
    tecnicos que estan enviando ordenes."""
    local = Path(local)
    local.parent.mkdir(parents=True, exist_ok=True)
    cmd = (["scp", "-P", str(PUERTO)] + opciones_base() +
           [f"{destino(env)}:{remoto}", local.as_posix()])
    r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    if r.returncode != 0 or not local.is_file():
        raise ErrorSsh(f"scp fallo (codigo {r.returncode}) bajando {remoto}\n"
                       f"  stderr: {(r.stderr or '').strip()[:400]}")


def scp_subir(local: Path, remoto: str, timeout: int = 900, env: dict | None = None) -> None:
    """Sube UN archivo al servidor. Simétrico a `scp_bajar`: una sesión por
    archivo, pensado para lotes chicos (p. ej. los PDF que alguien pidió por
    "pedir copia" en el Archivo, no un volcado masivo). `remoto` es relativo al
    home del usuario, igual que en el resto de este módulo."""
    local = Path(local)
    if not local.is_file():
        raise ErrorSsh(f"no existe el archivo local a subir: {local}")
    cmd = (["scp", "-P", str(PUERTO)] + opciones_base() +
           [local.as_posix(), f"{destino(env)}:{remoto}"])
    r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    if r.returncode != 0:
        raise ErrorSsh(f"scp fallo (codigo {r.returncode}) subiendo {local.name}\n"
                       f"  stderr: {(r.stderr or '').strip()[:400]}")


def lineas_get(pares) -> list[str]:
    """Las lineas `-get -p` de un lote sftp.

    EL DESTINO LOCAL VA EN POSIX (D:/RESPALDOS/...). `str(Path)` en Windows da
    barras invertidas, y dentro de las comillas simples que shlex pone a los
    nombres con espacio ("OT-0164-...-Dia 2-UIO.pdf") el parser de sftp no
    garantiza como las interpreta (auditoria H11). El sftp de OpenSSH en Windows
    acepta D:/... sin problema. El `-` delante del get hace que un archivo que
    desaparecio entre el inventario y la descarga no aborte el lote entero.
    """
    return [f"-get -p {shlex.quote(remoto)} {shlex.quote(Path(local).as_posix())}"
            for remoto, local in pares]


def sftp_lote(lineas: list[str], timeout: int = 7200,
              env: dict | None = None) -> subprocess.CompletedProcess:
    """Ejecuta un lote sftp (-b): una sola conexion para todas las lineas.

    No se mira el returncode como verdad: con `-get` sftp devuelve 0 aunque
    falten archivos. La verdad la da la verificacion por hash que hace quien
    llama. Se devuelve el CompletedProcess para poder registrar stderr.
    """
    with tempfile.NamedTemporaryFile("w", suffix=".sftp", delete=False,
                                     encoding="utf-8", newline="\n") as f:
        f.write("\n".join(list(lineas) + ["quit"]) + "\n")
        batch = f.name
    try:
        cmd = (["sftp", "-P", str(PUERTO)] + opciones_base() + ["-b", batch, destino(env)])
        return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    finally:
        os.unlink(batch)


def sftp_bajar(pares, timeout: int = 7200, env: dict | None = None) -> subprocess.CompletedProcess:
    """Baja varios (remoto, local) en una sola sesion sftp."""
    for _, local in pares:
        Path(local).parent.mkdir(parents=True, exist_ok=True)
    return sftp_lote(lineas_get(pares), timeout, env)


# --- Credenciales de la base remota, sin que viajen ----------------------------------
_PHP_ESCRIBE_CNF = r'''<?php
$c = require getenv('CONFIG_PHP');
$f = getenv('CNF');
if (!is_array($c) || empty($c['db_name']) || !isset($c['db_pass'])) { fwrite(STDERR, "config.php sin claves de base\n"); exit(3); }
$pass = str_replace(array("\\", "\""), array("\\\\", "\\\""), (string) $c['db_pass']);
$ini = "[client]\nhost=" . $c['db_host'] . "\nport=" . (int) $c['db_port'] . "\nuser=" . $c['db_user'] . "\npassword=\"" . $pass . "\"\n";
if (file_put_contents($f, $ini) === false) { fwrite(STDERR, "no se pudo escribir el .cnf\n"); exit(3); }
chmod($f, 0600);
echo $c['db_name'];
'''


def guion_con_credenciales(cuerpo: str, config_php: str | None = None,
                           env: dict | None = None) -> str:
    """Guion bash para el servidor: deja $CNF (0600, en ~/respaldos) y $BD listos,
    corre `cuerpo` y borra el .cnf al salir, pase lo que pase (trap EXIT).

    `set -o pipefail` importa: sin el, `mysqldump ... | gzip` sale con el codigo
    de gzip (0) aunque mysqldump haya fallado, y un volcado vacio pasaria por
    bueno. Comprobado que el shell de la cuenta es bash 5.1 (2026-09-12).
    """
    config_php = config_php or config_app(env)
    return (
        "set -eu\nset -o pipefail\numask 077\n"
        f"export CONFIG_PHP={shlex.quote(config_php)}\n"
        f"[ -f \"$CONFIG_PHP\" ] || {{ echo \"no existe $CONFIG_PHP\" >&2; exit 5; }}\n"
        f"D={RESPALDOS_REMOTO}\nmkdir -p \"$D\"\n"
        "export CNF=\"$(mktemp \"$D/.cred_XXXXXXXX\")\"\n"
        "trap 'rm -f \"$CNF\"' EXIT\n"
        "export BD=\"$(php <<'PHPFIN'\n" + _PHP_ESCRIBE_CNF + "PHPFIN\n)\"\n"
        "[ -n \"$BD\" ] || { echo 'config.php no dio db_name' >&2; exit 4; }\n"
        + cuerpo.rstrip("\n") + "\n")


def sql_remoto(sql: str, config_php: str | None = None, timeout: int = 300,
               env: dict | None = None) -> list[list[str]]:
    """Corre SQL en la base de la app nueva desde el servidor y devuelve las
    filas como listas de texto (TSV, sin cabecera; NULL llega como 'NULL').

    El SQL va por heredoc con delimitador entre comillas: no se interpola nada
    del shell, asi que no hay que escapar comillas del SQL. El guion nombra el
    config.php de la app (sitio de pruebas hoy), nunca el sistema viejo.
    """
    if "\nSQLFIN" in sql:
        raise ValueError("el SQL no puede contener una linea 'SQLFIN'")
    cuerpo = ("mysql --defaults-extra-file=\"$CNF\" --batch --raw --skip-column-names "
              "\"$BD\" <<'SQLFIN'\n" + sql.rstrip("\n") + "\nSQLFIN\n")
    salida = ssh(guion_con_credenciales(cuerpo, config_php, env), timeout=timeout, env=env)
    return [linea.split("\t") for linea in salida.splitlines() if linea != ""]


# --- Prueba de conexion --------------------------------------------------------------
def probar(env: dict | None = None) -> dict:
    """Conecta, y comprueba lo que los scripts nocturnos dan por sentado: que
    el home es el esperado, que existen los dos docroots, y que estan las
    herramientas con las que se verifican hashes y se vuelca la base."""
    salida = ssh(
        "pwd; echo ---; sha256sum --version | head -1; mysqldump --version; php -v | head -1; "
        f"echo ---; ls -d {shlex.quote(DOCROOT_PRUEBAS)} {shlex.quote(DOCROOT_PRODUCCION)} 2>&1",
        timeout=60, env=env)
    bloques = salida.split("---")
    resultado = {
        "destino": f"{destino(env)}:{PUERTO}",
        "llave": str(llave()),
        "home": bloques[0].strip(),
        "herramientas": [l.strip() for l in bloques[1].strip().splitlines()] if len(bloques) > 1 else [],
        "docroots": [l.strip() for l in bloques[2].strip().splitlines()] if len(bloques) > 2 else [],
    }
    resultado["ok"] = (
        DOCROOT_PRUEBAS in resultado["docroots"] and DOCROOT_PRODUCCION in resultado["docroots"]
        and any("sha256sum" in h for h in resultado["herramientas"]))
    return resultado


def main() -> None:
    ap = argparse.ArgumentParser(description="Contrato SSH a Hostinger")
    ap.add_argument("--probar", action="store_true", help="conecta y verifica docroots y herramientas")
    args = ap.parse_args()
    if not args.probar:
        ap.print_help()
        return
    try:
        r = probar()
    except ErrorSsh as e:
        sys.exit(f"NO CONECTA: {e}\nRevisa en hPanel > Avanzado > Acceso SSH que este activo "
                 f"y que la llave publica este autorizada.")
    print(f"destino     : {r['destino']}")
    print(f"llave       : {r['llave']}")
    print(f"home        : {r['home']}")
    for h in r["herramientas"]:
        print(f"herramienta : {h}")
    for d in r["docroots"]:
        marca = "SOLO LECTURA" if SITIO_PRODUCCION in d else "pruebas"
        print(f"docroot     : {d}  [{marca}]")
    print("OK: conexion, docroots y herramientas" if r["ok"] else "FALLA: falta un docroot o una herramienta")
    sys.exit(0 if r["ok"] else 1)


if __name__ == "__main__":
    main()
