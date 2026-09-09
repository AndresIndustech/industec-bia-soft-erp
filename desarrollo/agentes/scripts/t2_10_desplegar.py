"""
T2.10 - Despliega al sitio de pruebas por SSH, sin pasar por el navegador.

POR QUE EXISTE
Subir por el administrador de archivos de Hostinger es el paso más frágil que
tiene este proyecto, y ya falló dos veces: **el extractor no sobreescribe**.
Crea los que faltan, salta los que ya están —aunque se marque "Overwrite
existing files"— y deja el sitio con un archivo nuevo junto a uno viejo. Así
salió el HTTP 500 de `usuarios.php` y el panel que seguía mostrando el módulo
apagado. Por SSH el archivo se reemplaza y punto.

COMO SE LLEGO AQUI
El dominio del sitio está detrás de un proxy que solo expone el 443, así que
parecía que no había más vía que el navegador. El servidor real sí acepta SSH:
`srv2020.hstgr.io` (212.85.3.19) tiene abierto el 65002. Comprobado el
2026-09-09.

LA COMPUERTA DE DESTINO NO ES UN VALOR POR OMISION: ABORTA
La cuenta de Hostinger es de INDUSTECH y sostiene además el sistema con el que
INDUSTEC factura hoy. Una cuenta SSH ve TODAS las carpetas del plan, así que un
`scp` con la ruta equivocada llega al sitio en producción. Regla de Andrés del
2026-09-09: dentro del sitio de pruebas, mano libre; fuera de él, nada.
Por eso `RUTA_DESTINO` se verifica carácter por carácter antes de conectar, y
cualquier otra ruta corta la ejecución. No alcanza con "acordarse".

TAMPOCO SE HACE NADA QUE CUESTE DINERO. Si algún día SSH deja de funcionar
porque el plan no lo incluye, este script reporta y se detiene. Subir de plan lo
decide y lo ejecuta Andrés.

Uso:
    .venv/Scripts/python.exe scripts/t2_10_desplegar.py --probar
    .venv/Scripts/python.exe scripts/t2_10_desplegar.py casos.php novedades.php
    .venv/Scripts/python.exe scripts/t2_10_desplegar.py --todo
    .venv/Scripts/python.exe scripts/t2_10_desplegar.py --borrar instalar.php
"""

import argparse
import hashlib
import subprocess
import sys
from pathlib import Path

BASE = Path(r"D:\INDUSTECH IA\desarrollo\agentes")
ORIGEN = Path(r"D:\INDUSTECH IA\desarrollo\sistema_ots\app\publico")
LLAVE = BASE / "config" / "clave_hostinger"
ENV_PATH = BASE / "config" / ".env"

# --- La compuerta. Cambiar esto a mano es cambiar de sitio de destino. -------
SITIO_PERMITIDO = "darkviolet-armadillo-872352"
RUTA_DESTINO = "domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot"
SSH_HOST, SSH_PUERTO = "srv2020.hstgr.io", 65002

# Lo que se despliega con --todo. Es una lista blanca a propósito: así un
# archivo suelto de pruebas no se sube por descuido.
ARCHIVOS = [
    "index.html", "app.js", "reglas.js", "estilo.css", "offline.js", "sw.js",
    "manifest.json", "login.php", "panel.php", "salir.php", "clave.php",
    "usuarios.php", "casos.php", "novedades.php", "sync_casos.php",
    "catalogos.php", "cronograma.html", "cronograma.css", "cronograma.js",
    "cronograma.php", "nucleo/Auth.php", "nucleo/Db.php", "nucleo/Validacion.php",
    "nucleo/.htaccess", "catalogos/.htaccess", "iconos/icono-192.png",
    "iconos/icono-512.png",
]

# Nunca se suben, ni con --todo ni nombrándolos: llevan credenciales o datos
# personales que no tienen por qué salir de la estación por esta vía.
PROHIBIDOS = {"nucleo/config.php", "nucleo/padron.json", "catalogos/casos_sap.json",
              "catalogos/casos_resumen.json", "instalar.php", "diagnostico.php"}


def compuerta() -> None:
    """Se corre antes de abrir la conexión, no después."""
    if SITIO_PERMITIDO not in RUTA_DESTINO:
        sys.exit(f"ABORTADO: la ruta de destino no es la del sitio de pruebas.\n"
                 f"  destino    : {RUTA_DESTINO}\n"
                 f"  debe llevar: {SITIO_PERMITIDO}")
    if not RUTA_DESTINO.endswith("/public_html/ot"):
        sys.exit(f"ABORTADO: el destino debe terminar en /public_html/ot, y es {RUTA_DESTINO!r}")
    if not LLAVE.is_file():
        sys.exit(f"Falta la llave {LLAVE}.\n"
                 f"Genérala y autoriza la pública en hPanel > Avanzado > Acceso SSH.")


def usuario() -> str:
    env = {}
    if ENV_PATH.is_file():
        for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                env[k.strip()] = v.strip()
    u = env.get("SSH_USER", "").strip()
    if not u:
        sys.exit("Falta SSH_USER en config/.env (lo da hPanel > Avanzado > Acceso SSH)")
    return u


def ssh(u: str, comando: str, *, texto: bool = True):
    return subprocess.run(
        ["ssh", "-i", str(LLAVE), "-p", str(SSH_PUERTO),
         "-o", "StrictHostKeyChecking=accept-new",
         "-o", "BatchMode=yes", "-o", "ConnectTimeout=20",
         f"{u}@{SSH_HOST}", comando],
        capture_output=True, text=texto, timeout=120)


def subir(u: str, rel: str) -> bool:
    """Sube un archivo y comprueba por hash que llegó igual."""
    local = ORIGEN / rel
    if not local.is_file():
        print(f"  {rel:<28} NO EXISTE en {ORIGEN}")
        return False
    if rel.replace("\\", "/") in PROHIBIDOS:
        print(f"  {rel:<28} PROHIBIDO subir por esta vía")
        return False

    remoto = f"{RUTA_DESTINO}/{rel}"
    carpeta = remoto.rsplit("/", 1)[0]
    ssh(u, f"mkdir -p {carpeta!r}")
    r = subprocess.run(
        ["scp", "-i", str(LLAVE), "-P", str(SSH_PUERTO),
         "-o", "StrictHostKeyChecking=accept-new", "-o", "BatchMode=yes",
         str(local), f"{u}@{SSH_HOST}:{remoto}"],
        capture_output=True, text=True, timeout=180)
    if r.returncode != 0:
        print(f"  {rel:<28} ERROR: {(r.stderr or '').strip()[:120]}")
        return False

    # Verificar, no confiar: el scp puede devolver 0 y dejar el archivo corto.
    esperado = hashlib.sha256(local.read_bytes()).hexdigest()
    v = ssh(u, f"sha256sum {remoto!r} 2>/dev/null | cut -d' ' -f1")
    obtenido = (v.stdout or "").strip()
    if obtenido != esperado:
        print(f"  {rel:<28} NO COINCIDE el hash tras subirlo")
        return False
    print(f"  {rel:<28} ok  {len(local.read_bytes()):>7} bytes")
    return True


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("archivos", nargs="*", help="rutas relativas a publico/")
    ap.add_argument("--todo", action="store_true", help="sube la lista blanca completa")
    ap.add_argument("--probar", action="store_true", help="solo comprueba la conexión")
    ap.add_argument("--borrar", nargs="+", metavar="ARCHIVO",
                    help="borra archivos del sitio de pruebas")
    args = ap.parse_args()

    compuerta()
    u = usuario()
    print(f"destino: {u}@{SSH_HOST}:{SSH_PUERTO}  {RUTA_DESTINO}\n")

    r = ssh(u, f"pwd && ls -d {RUTA_DESTINO!r} 2>/dev/null && echo CARPETA_OK")
    if r.returncode != 0:
        sys.exit("No se pudo conectar por SSH.\n"
                 f"  {(r.stderr or '').strip()[:300]}\n\n"
                 "Revisa en hPanel > Avanzado > Acceso SSH que esté activo y que la\n"
                 f"llave pública {LLAVE.with_suffix('.pub').name} esté autorizada.")
    if "CARPETA_OK" not in (r.stdout or ""):
        sys.exit(f"Conecta, pero no encuentro {RUTA_DESTINO}.\n"
                 f"  ls dio: {(r.stdout or '').strip()[:300]}")
    print("conexión y carpeta de destino: OK\n")
    if args.probar:
        return

    if args.borrar:
        for rel in args.borrar:
            if rel.replace("\\", "/") in PROHIBIDOS or "/" not in RUTA_DESTINO:
                print(f"  {rel:<28} no se borra por esta vía")
                continue
            d = ssh(u, f"rm -f {RUTA_DESTINO + '/' + rel!r}")
            print(f"  {rel:<28} {'borrado' if d.returncode == 0 else 'ERROR'}")
        return

    lista = ARCHIVOS if args.todo else args.archivos
    if not lista:
        sys.exit("Dime qué archivos subir, o usa --todo.")

    ok = sum(subir(u, rel.replace("\\", "/")) for rel in lista)
    print(f"\n{ok} de {len(lista)} archivos en el sitio de pruebas")
    sys.exit(0 if ok == len(lista) else 1)


if __name__ == "__main__":
    main()
