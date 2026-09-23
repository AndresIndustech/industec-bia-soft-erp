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
parecía que no había más vía que el navegador. El servidor real sí acepta SSH,
en el puerto 65002. Comprobado el 2026-09-09 y en uso desde entonces.

LA COMPUERTA DE DESTINO NO ES UN VALOR POR OMISION: ABORTA
La cuenta de Hostinger es de INDUSTECH y sostiene además el sistema con el que
INDUSTEC factura hoy. Una cuenta SSH ve TODAS las carpetas del plan, así que un
`scp` con la ruta equivocada llega al sitio en producción. Regla de Andrés del
2026-09-09: dentro del sitio de pruebas, mano libre; fuera de él, nada.
Por eso `RUTA_DESTINO` se verifica carácter por carácter antes de conectar, y
cualquier otra ruta corta la ejecución. No alcanza con "acordarse".

Y LA RUTA DE CADA ARCHIVO TAMBIEN PASA POR LA COMPUERTA. Hasta el 2026-09-10
no se normalizaba: `--borrar ../../../yellow-elephant…/algo` llegaba a
producción, y `./nucleo/config.php` se saltaba la lista de prohibidos.

TAMPOCO SE HACE NADA QUE CUESTE DINERO. Si algún día SSH deja de funcionar
porque el plan no lo incluye, este script reporta y se detiene. Subir de plan lo
decide y lo ejecuta Andrés.

CADA EQUIPO CON SU LLAVE
Las rutas salen de la ubicación de este archivo, así que corre igual en la
estación que en otra copia del proyecto. La estación usa config/clave_hostinger;
otro equipo declara la suya en INDUSTEC_LLAVE_SSH, para que cada llave se pueda
revocar por separado en hPanel.

Uso:
    .venv/Scripts/python.exe scripts/t2_10_desplegar.py --probar
    .venv/Scripts/python.exe scripts/t2_10_desplegar.py casos.php novedades.php
    .venv/Scripts/python.exe scripts/t2_10_desplegar.py --todo
    .venv/Scripts/python.exe scripts/t2_10_desplegar.py --borrar instalar.php
"""

import argparse
import gzip
import hashlib
import os
import posixpath
import shlex
import ssl
import subprocess
import sys
import urllib.request
from pathlib import Path

BASE = Path(__file__).resolve().parents[1]
ORIGEN = BASE.parent / "sistema_ots" / "app" / "publico"
LLAVE = Path(os.environ.get("INDUSTEC_LLAVE_SSH") or (BASE / "config" / "clave_hostinger"))
ENV_PATH = BASE / "config" / ".env"

# --- La compuerta. Cambiar esto a mano es cambiar de sitio de destino. -------
SITIO_PERMITIDO = "darkviolet-armadillo-872352"
RUTA_DESTINO = "domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot"
# La IP la da hPanel > Avanzado > Acceso SSH, y manda sobre cualquier hostname:
# `srv2020.hstgr.io` resuelve a 212.85.3.19, que tambien acepta SSH pero NO es
# el servidor de esta cuenta. Si algun dia deja de conectar, se relee del panel.
SSH_HOST, SSH_PUERTO = "82.25.73.181", 65002

# Lo que se despliega con --todo. Es una lista blanca a propósito: así un
# archivo suelto de pruebas no se sube por descuido.
#
# OJO: MANTENERLA AL DIA ES PARTE DE AGREGAR UN ARCHIVO.
# El 2026-09-10 se descubrió que estaba **20 archivos atrás**. `--todo` habría
# subido el sitio de antes del rediseño —sin `mis.php`, sin `pendientes.php`,
# sin `reportes.php`, sin `ui.js`, sin `cola.js`, sin ninguna clase nueva de
# `nucleo/`— y eso no da un error: da un sitio a medias, con la navegación
# apuntando a 404 y el formulario sin su cola de envíos. Cinco de esos veinte
# llevaban semanas en uso: se habían subido nombrándolos a mano.
#
# Comprobación, antes de dar por buena una entrega:
#   python - <<'PY'
#   import io,re,os
#   s=io.open('scripts/t2_10_desplegar.py',encoding='utf-8').read()
#   b=set(re.findall(r'"([^"]+)"', re.search(r'ARCHIVOS = \[(.*?)\]',s,re.S).group(1)))
#   os.chdir(r'..\sistema_ots\app\publico')
#   r={os.path.relpath(os.path.join(d,f),'.').replace(os.sep,'/')
#      for d,_,fs in os.walk('.') for f in fs if f.endswith(('.php','.js','.html','.css','.json'))}
#   print(sorted(r-b))     # lo que existe y --todo no subiria
#   PY
ARCHIVOS = [
    # --- Armazón compartido: de aquí sale la barra y los avisos de todas -----
    "estilo.css", "ui.js", "graficos.js",
    # `busqueda.js` lo cargan casos.php y ordenes.php desde el 2026-09-10.
    "busqueda.js",
    "nucleo/Auth.php", "nucleo/Db.php", "nucleo/Validacion.php",
    "nucleo/Ui.php", "nucleo/Casos.php", "nucleo/Pendientes.php",
    "nucleo/Novedades.php", "nucleo/Reconciliar.php", "nucleo/Catalogo.php",
    # `Avisos.php` arma el buzón del técnico (T2.13.5): lo cargan mis.php y novedades.php.
    "nucleo/Avisos.php",
    # La emisión (T2.13, la 008): número, PDF y cola de correo. El logo va dentro del PDF.
    "nucleo/Emision.php", "nucleo/plantilla_ot.php", "nucleo/logo-industec.png",

    # --- La app del técnico. `cola.js` es lo que evita perder una orden
    #     llenada sin señal: si falta, el botón de enviar no guarda nada. -----
    "index.html", "app.js", "reglas.js", "offline.js", "cola.js", "guia.js",
    "sw.js", "manifest.json", "mis.php", "envio.php", "yo.php",
    # `foto.php` recibe las fotos de una en una, antes que la orden (T2.13, la 008).
    "foto.php",

    # --- La mesa de servicio -------------------------------------------------
    "login.php", "salir.php", "clave.php", "panel.php", "usuarios.php",
    "casos.php", "asignacion.php", "pendientes.php", "novedades_visita.php",
    "ordenes.php", "pdf.php", "reportes.php",
    # T2.14.4: la bitácora con pantalla y el aprendizaje (manuales, guías y
    # comunicados con aprobación). `documento.php` es el único que sirve los
    # archivos de `documentos/`; su .htaccess niega el resto.
    "bitacora.php", "documentos.php", "documento.php", "documentos/.htaccess",
    # T2.14.8: la confirmación de los equipos que los técnicos registran como nuevos (D8).
    "equipos.php",
    # T2.27.7: el panel de automatización y la puerta CLI de la estación.
    "automatizacion.php", "automatizacion_cli.php", "nucleo/Automatizacion.php",
    # T2.14.5: los reportes por zona con exportación (Excel, PDF, PowerPoint) y
    # el cronograma que escribe.
    "reporte_exportar.php", "nucleo/Reportes.php", "nucleo/reporte_pdf.php", "cronograma_accion.php",

    # --- Extremos que consultan las pantallas --------------------------------
    # `novedades.php` es el que el buzón consulta cada 30 s para saber si
    # llegaron casos nuevos. NO confundir con `novedades_visita.php`, que es la
    # pantalla: el 2026-09-10 una escribió sobre el otro y la barra de «el buzón
    # se actualizó» dejó de aparecer sin ningún error visible.
    "novedades.php", "sync_casos.php", "catalogos.php", "cronograma.php",

    # --- Cronograma de preventivos -------------------------------------------
    "cronograma.html", "cronograma.css", "cronograma.js",

    # --- Lo que cierra el acceso directo a los datos -------------------------
    # El .htaccess de la raíz y el de ordenes_pdf/ vivían solo en el servidor
    # hasta el 2026-09-10: un sitio subido desde cero quedaba sin ellos.
    ".htaccess", "nucleo/.htaccess", "catalogos/.htaccess", "ordenes_pdf/.htaccess",
    "ordenes_fotos/.htaccess",
    "iconos/icono-192.png", "iconos/icono-512.png",
]

# Lo que existe en `publico/` y NO entra en --todo, con su motivo. Está escrito
# para que la próxima comprobación de la lista no lo reporte como olvido.
#
#   alta_padron.php      se corrió una vez, dio de alta a los 19 y ya no hace
#                        falta en el servidor. Se sube a mano si se repite.
#   nucleo/padron.json   son NOMBRES DEL PERSONAL. Está fuera de git a propósito
#                        y no debería quedarse en el servidor una vez hecha el
#                        alta: subirlo en cada despliegue sería dejarlo ahí para
#                        siempre. Es dato personal (LOPDP).
#   instalar.php         de instalación. Ya se borró del servidor una vez.
#   diagnostico.php      de diagnóstico. Igual.
#   nucleo/config.php    ver PROHIBIDOS, abajo.
#   nucleo/config.ejemplo.php, nucleo/config.hostinger.php
#                        plantillas de configuración; no son código que corra.
#   nucleo/crear_usuario.php, aplicar_sql.php, verificar_esquema.php,
#   minar.php, reconciliar_cli.php, alta_padron_cli.php,
#   emitir_pendientes_cli.php, despachar_correo_cli.php (T2.14.6),
#   archivo_indexar_cli.php (T2.14.4: llena ot_archivo; lo corre el cron o
#   t2_15_exportar_archivo.py --empujar),
#   cronograma_importar_cli.php (T2.14.5: carga cronograma_preventivo.json en
#   ingresos_preventivos; se corre tras cada publicación del cronograma),
#   purgar_cli.php (T2.14.6: fotos huérfanas a 30 días y .tmp a 24 h; solo
#   informa sin --ejecutar; cron semanal),
#   regularizar_masivo_cli.php (2026-09-21: vacía en bloque el pendiente de la
#   administradora — ATENDIDO -> cerrado SAP, CERRADO_SIN_ATENCION -> regularizado
#   — asumiendo que ya lo hizo en SAP; a mano, no en el cron. Ver ESTADO.md §1h)
#   t2_24_2_cerrar_masivo_cli.php (2026-09-21: cierra en bloque los preventivos
#   "sin cerrar" que destapó T2.23 — a mano, una sola vez. Lee
#   catalogos/t2_24_2_cierres_masivo.json, que se sube junto con él y NO entra
#   en --todo por ser dato, no código. Ver ESTADO.md §1k y PLAN T2.24.2)
#                        herramientas de línea de órdenes. Las que ya están en
#                        el servidor cortan con 404 por web; el resto no tienen
#                        por qué llegar. Los dos CLI de T2.14.6 se suben con
#                        scp cuando cambian (los llama el cron de hPanel:
#                        reemisor cada 10 min, despachador cada 5).

# Lo unico que NO se toca del sitio de pruebas.
#
# `nucleo/config.php` lleva las credenciales de la base de Hostinger y el
# secreto de sincronizacion. Se configuro una vez a mano; pisarlo obligaria a
# volver a cargarlo cada vez y dejaria el sitio caido mientras tanto. Regla de
# Andres del 2026-09-09: en el sitio de pruebas, todo lo demas es manipulable.
#
# Los otros dos nunca deberian existir en el servidor: son de instalacion y
# diagnostico, y ya se borraron una vez. Si vuelven, es por descuido.
PROHIBIDOS = {"nucleo/config.php", "instalar.php", "diagnostico.php"}


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


def normalizar(rel: str) -> str:
    """La ruta de un archivo, dentro del sitio de pruebas o nada."""
    r = posixpath.normpath(rel.replace("\\", "/"))
    if r in ("", ".", "..") or r.startswith("/") or r.startswith("../") or ":" in r:
        sys.exit(f"ABORTADO: {rel!r} sale del sitio de pruebas")
    if not (ORIGEN / r).resolve().is_relative_to(ORIGEN.resolve()):
        sys.exit(f"ABORTADO: {rel!r} sale de {ORIGEN}")
    return r


def usuario() -> str:
    env = {}
    if ENV_PATH.is_file():
        for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                env[k.strip()] = v.strip()
    u = (os.environ.get("INDUSTEC_SSH_USER") or env.get("SSH_USER", "")).strip()
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


def subir(u: str, crudo: str) -> bool:
    """Sube un archivo y comprueba por hash que llegó igual."""
    rel = normalizar(crudo)
    local = ORIGEN / rel
    if rel in PROHIBIDOS:
        print(f"  {rel:<28} PROHIBIDO subir por esta vía")
        return False
    if not local.is_file():
        print(f"  {rel:<28} NO EXISTE en {ORIGEN}")
        return False

    remoto = f"{RUTA_DESTINO}/{rel}"
    carpeta = remoto.rsplit("/", 1)[0]
    ssh(u, f"mkdir -p {shlex.quote(carpeta)}")
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
    v = ssh(u, f"sha256sum {shlex.quote(remoto)} 2>/dev/null | cut -d' ' -f1")
    obtenido = (v.stdout or "").strip()
    if obtenido != esperado:
        print(f"  {rel:<28} NO COINCIDE el hash tras subirlo")
        return False
    print(f"  {rel:<28} ok  {len(local.read_bytes()):>7} bytes")
    return True


def borrar(u: str, crudo: str) -> bool:
    """Borra un archivo del sitio de pruebas y comprueba que ya no está.
    `rm -f` sale con 0 aunque el archivo no exista: no sirve como prueba."""
    rel = normalizar(crudo)
    if rel in PROHIBIDOS:
        print(f"  {rel:<28} no se borra por esta vía")
        return False
    q = shlex.quote(f"{RUTA_DESTINO}/{rel}")
    d = ssh(u, f"if [ -e {q} ]; then rm -- {q} && [ ! -e {q} ] && echo BORRADO; "
               f"else echo NO_EXISTIA; fi")
    salida = (d.stdout or "").strip()
    print(f"  {rel:<28} {salida or 'ERROR ' + (d.stderr or '').strip()[:100]}")
    return salida in ("BORRADO", "NO_EXISTIA")


def verificar_web(lista: list[str]) -> list[str]:
    """Lo que entrega la WEB, no solo lo que quedó en el disco del servidor.

    El 2026-09-11 el CDN de Hostinger siguió sirviendo el sw.js v2 horas después
    de subir el v4: el hash en el disco cuadraba y los celulares recibían el
    viejo. Aquí se pide cada archivo público tal como lo pide un navegador, sin
    esquivar el CDN, y se compara con lo que se subió.
    """
    host = RUTA_DESTINO.split("/")[1]
    ctx = ssl.create_default_context()
    # Un antivirus que inspecciona HTTPS (Avast en el PC de Andrés) presenta una
    # raíz propia que Python 3.13+ rechaza por VERIFY_X509_STRICT. Se sigue
    # verificando la cadena y el nombre; solo se quita esa rigidez.
    ctx.verify_flags &= ~ssl.VERIFY_X509_STRICT
    viejos = []
    for crudo in lista:
        rel = normalizar(crudo)
        # Solo el código. Las imágenes las recomprime el CDN al vuelo (nodos
        # «imm-edge»): el ícono de 1149 B llega de 1341 B aunque se esquive la
        # caché, así que su hash nunca cuadra y no dice nada (medido el 2026-09-11).
        if not (rel.endswith((".js", ".css", ".html")) or rel == "manifest.json"):
            continue
        local = hashlib.sha256((ORIGEN / rel).read_bytes()).hexdigest()
        # El CDN guarda una copia por cada forma de pedirlo: sin comprimir (curl)
        # y comprimida (todo navegador). El 2026-09-11 la comprimida del sw.js
        # siguió vieja cuando la otra ya se había renovado: hay que mirar las dos.
        for enc in ("identity", "gzip"):
            pedido = urllib.request.Request(f"https://{host}/ot/{rel}", headers={
                "Accept-Encoding": enc, "User-Agent": "t2_10_desplegar (comprobacion)"})
            try:
                with urllib.request.urlopen(pedido, timeout=30, context=ctx) as r:
                    cuerpo = r.read()
                    if (r.headers.get("Content-Encoding") or "").lower() == "gzip":
                        cuerpo = gzip.decompress(cuerpo)
            except Exception as e:
                print(f"  {rel:<28} no se pudo leer por la web ({enc}): {e}")
                viejos.append(f"{rel} ({enc})")
                continue
            if hashlib.sha256(cuerpo).hexdigest() != local:
                viejos.append(f"{rel} ({'comprimida' if enc == 'gzip' else 'sin comprimir'})")
    if viejos:
        print("\nATENCIÓN: la web todavía entrega una copia VIEJA de: " + ", ".join(viejos))
        print("Es el CDN de Hostinger. Purga su caché en hPanel (sitio → Rendimiento → CDN)")
        print("y vuelve a comprobar con --comprobar-web.")
    else:
        print("la web entrega exactamente lo que se subió")
    return viejos


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("archivos", nargs="*", help="rutas relativas a publico/")
    ap.add_argument("--todo", action="store_true", help="sube la lista blanca completa")
    ap.add_argument("--probar", action="store_true", help="solo comprueba la conexión")
    ap.add_argument("--comprobar-web", nargs="+", metavar="ARCHIVO",
                    help="solo compara lo que entrega la web (con su CDN) con lo local")
    ap.add_argument("--borrar", nargs="+", metavar="ARCHIVO",
                    help="borra archivos del sitio de pruebas")
    args = ap.parse_args()

    compuerta()
    u = usuario()
    print(f"destino: {u}@{SSH_HOST}:{SSH_PUERTO}  {RUTA_DESTINO}\n")

    r = ssh(u, f"pwd && ls -d {shlex.quote(RUTA_DESTINO)} 2>/dev/null && echo CARPETA_OK")
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

    if args.comprobar_web:
        sys.exit(1 if verificar_web(args.comprobar_web) else 0)

    if args.borrar:
        ok = sum(borrar(u, rel) for rel in args.borrar)
        sys.exit(0 if ok == len(args.borrar) else 1)

    lista = ARCHIVOS if args.todo else args.archivos
    if not lista:
        sys.exit("Dime qué archivos subir, o usa --todo.")

    ok = sum(subir(u, rel) for rel in lista)
    print(f"\n{ok} de {len(lista)} archivos en el sitio de pruebas")
    viejos = verificar_web(lista)
    sys.exit(0 if ok == len(lista) and not viejos else 1)


if __name__ == "__main__":
    main()
