"""publicar_sitio.py — Publica sitio/ en la raíz de darkviolet y lo verifica.

Uso (desde web_corporativa/):
    python herramientas/publicar_sitio.py          -> ensayo: qué subiría y qué borraría, sin tocar nada
    python herramientas/publicar_sitio.py --si     -> lo hace y lo verifica (disco del servidor y web)
Después, NODE_OPTIONS=--use-system-ca node herramientas/verificar-publicacion.mjs hasta «OK»: es el que
registra sitio.publicado.sha256 (LEEME, sección 8).

Llave SSH: INDUSTEC_LLAVE_SSH; por defecto, ~/.ssh/industec_hostinger_pc, la del PC de Andrés (cada equipo
usa la suya).

Reglas duras:
  - nunca toca ot/ (el sistema) ni sube un .htaccess a la raíz (lo heredaría ot/);
  - solo borra archivos que están dentro de los nombres del sitio (index.html, assets/, nosotros/…)
    y que ya no existen en sitio/;
  - avisa si un recurso cambió de contenido sin cambiar de dirección: el CDN de Hostinger guarda
    css, js e imágenes 7 días y lo serviría viejo.
Nació en la sesión del 11-sep-2026 que publicó la segunda entrega.
"""
import gzip
import hashlib
import io
import os
import re
import shlex
import ssl
import subprocess
import sys
import tarfile
import urllib.error
import urllib.request
from pathlib import Path

W = Path(__file__).resolve().parent.parent
SITIO = W / "sitio"
LLAVE = os.environ.get("INDUSTEC_LLAVE_SSH") or str(Path.home() / ".ssh" / "industec_hostinger_pc")
RAIZ = "domains/darkviolet-armadillo-872352.hostingersite.com/public_html"
BASE = "https://darkviolet-armadillo-872352.hostingersite.com"
SSH = ["ssh", "-i", LLAVE, "-o", "IdentitiesOnly=yes", "-p", "65002", "-o", "BatchMode=yes",
       "-o", "ConnectTimeout=20", "-o", "StrictHostKeyChecking=accept-new", "u671729428@82.25.73.181"]
PROTEGIDOS = {"ot", ".htaccess"}
NOMBRE = re.compile(r"^[A-Za-z0-9._-]+$")
CTX = ssl.create_default_context()
CTX.verify_flags &= ~ssl.VERIFY_X509_STRICT          # Avast inspecciona el HTTPS en el PC de Andrés


def ssh(cmd, entrada=None):
    r = subprocess.run(SSH + [cmd], input=entrada, capture_output=True, timeout=300)
    if r.returncode != 0:
        sys.exit(f"ssh falló: {r.stderr.decode(errors='replace').strip()[:300]}")
    return r.stdout.decode("utf-8", errors="replace")


def sha(b):
    return hashlib.sha256(b).hexdigest()


def locales():
    return {p.relative_to(SITIO).as_posix(): sha(p.read_bytes()) for p in sorted(SITIO.rglob("*")) if p.is_file()}


def del_servidor(entradas):
    lista = " ".join(shlex.quote(e) for e in entradas)
    salida = ssh(f"cd {RAIZ} && for e in {lista}; do [ -e \"$e\" ] && find \"$e\" -type f -print0; done | xargs -0 -r sha256sum")
    out = {}
    for linea in salida.splitlines():
        h, _, ruta = linea.partition("  ")
        if ruta:
            out[ruta.strip()] = h.strip()
    return out


def pedir(url, enc):
    req = urllib.request.Request(url, headers={"Accept-Encoding": enc, "User-Agent": "publicar_sitio (comprobacion)"})
    try:
        with urllib.request.urlopen(req, timeout=60, context=CTX) as r:
            cuerpo = r.read()
            if (r.headers.get("Content-Encoding") or "").lower() == "gzip":
                cuerpo = gzip.decompress(cuerpo)
            return r.status, cuerpo
    except urllib.error.HTTPError as e:
        return e.code, b""


def main():
    hacer = "--si" in sys.argv
    if (SITIO / ".htaccess").exists() or (SITIO / "ot").exists():
        sys.exit("ABORTO: sitio/ trae un .htaccess o una carpeta ot/ en la raíz")
    entradas = sorted(p.name for p in SITIO.iterdir())
    malos = [e for e in entradas if not NOMBRE.match(e) or e in PROTEGIDOS]
    if malos:
        sys.exit(f"ABORTO: nombres no permitidos en la raíz de sitio/: {malos}")

    loc = locales()
    antes = del_servidor(entradas)
    subir = [r for r in loc if antes.get(r) != loc[r]]
    borrar = [r for r in antes if r not in loc and r.split("/")[0] in entradas and r.split("/")[0] not in PROTEGIDOS]

    # Recursos que cambian de contenido y conservan la dirección: el CDN los serviría viejos.
    refs = set()
    for html in (p for p in SITIO.rglob("*.html")):
        texto = html.read_text(encoding="utf-8")
        refs |= set(re.findall(r'(?:href|src)="(/assets/[^"\s]+)"', texto))
        # srcset trae varias direcciones separadas por comas, cada una con su ancho.
        for valor in re.findall(r'srcset="([^"]+)"', texto):
            refs |= {parte.strip().split()[0] for parte in valor.split(",") if parte.strip().startswith("/assets/")}
    sin_version = [r for r in subir if r.startswith("assets/") and r in antes
                   and not any(x.split("?")[0] == "/" + r and "?" in x for x in refs)]

    print(f"sitio/: {len(loc)} archivos · en el servidor: {len(antes)}")
    print(f"subir ({len(subir)}):  " + (", ".join(subir) if subir else "nada"))
    print(f"borrar ({len(borrar)}): " + (", ".join(borrar) if borrar else "nada"))
    if sin_version:
        print("AVISO — cambian de contenido con la misma dirección (el CDN los tendría viejos hasta 7 días): " + ", ".join(sin_version))
    if not hacer:
        print("\nENSAYO. Agrega --si para publicar.")
        return 0

    if subir:
        buf = io.BytesIO()
        with tarfile.open(fileobj=buf, mode="w") as t:
            for r in subir:
                datos = (SITIO / r).read_bytes()
                info = tarfile.TarInfo(r)
                info.size, info.mode = len(datos), 0o644
                t.addfile(info, io.BytesIO(datos))
        lista = " ".join(shlex.quote(e) for e in entradas)
        ssh(f"cd {RAIZ} && tar -xf - && for e in {lista}; do [ -d \"$e\" ] && find \"$e\" -type d -exec chmod 755 {{}} + ; done; "
            f"for e in {lista}; do [ -e \"$e\" ] && find \"$e\" -type f -exec chmod 644 {{}} + ; done; stat -c '%a' . ot",
            entrada=buf.getvalue())
    if borrar:
        ssh(f"cd {RAIZ} && rm -f -- " + " ".join(shlex.quote(r) for r in borrar)
            + " && find assets -mindepth 1 -type d -empty -delete")

    despues = del_servidor(entradas)
    distintos = [r for r in loc if despues.get(r) != loc[r]]
    extras = [r for r in despues if r not in loc]
    print(f"\ndisco del servidor: {len(loc) - len(distintos)} de {len(loc)} cuadran por hash"
          + (f" · NO cuadran: {distintos}" if distintos else "") + (f" · sobran: {extras}" if extras else ""))
    permisos = ssh(f"cd {RAIZ} && stat -c '%a %n' . ot && test ! -e .htaccess && echo 'sin .htaccess en la raíz'")
    print(permisos.strip())

    # La web, como la ve un navegador: páginas sin parámetros y cada recurso tal como lo pide el HTML.
    fallas, avisos = [], []
    paginas = sorted(r for r in loc if r.endswith("index.html"))
    for r in paginas:
        url = BASE + "/" + r[: -len("index.html")]
        for enc in ("identity", "gzip"):
            st, cuerpo = pedir(url, enc)
            if st != 200 or sha(cuerpo) != loc[r]:
                fallas.append(f"{url} ({enc}): HTTP {st}{'' if st != 200 else ', el contenido no es el subido'}")
    for ref in sorted(refs):
        r = ref.split("?")[0].lstrip("/")
        if r not in loc:
            fallas.append(f"{ref}: el HTML lo pide y no está en sitio/")
            continue
        for enc in ("identity", "gzip"):
            st, cuerpo = pedir(BASE + ref, enc)
            if st != 200:
                fallas.append(f"{ref} ({enc}): HTTP {st}")
            elif sha(cuerpo) != loc[r]:
                (avisos if r.endswith((".png", ".jpg", ".jpeg")) else fallas).append(
                    f"{ref} ({enc}): {'el CDN recomprime las imágenes' if r.endswith(('.png', '.jpg', '.jpeg')) else 'la web entrega otra versión'}")
    st, _ = pedir(BASE + "/ot/login.php", "identity")
    if st != 200:
        fallas.append(f"/ot/login.php: HTTP {st} (la publicación no debió tocar ot/)")
    print(f"web: {len(paginas)} páginas y {len(refs)} recursos comprobados en dos codificaciones · /ot/login.php {st}")
    for a in sorted(set(avisos)):
        print("  aviso:", a)
    for f in fallas:
        print("  FALLA:", f)
    print("OK: publicado y verificado." if not fallas and not distintos and not extras else "HAY QUE REVISAR.")
    return 1 if (fallas or distintos or extras) else 0


if __name__ == "__main__":
    sys.exit(main())
