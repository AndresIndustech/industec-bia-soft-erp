# Genera la imagen para redes (1200 x 630) a partir de fuentes/og/og.html.
# Captura la página con Edge sin ventana y la guarda como JPEG sin metadatos en
# sitio/assets/img/og-industec-cadenas-v2.jpg. JPEG y no PNG: con la foto, el PNG pasa de 300 KB
# y WhatsApp no muestra las vistas previas muy pesadas. La v2 (11-sep-2026) cambia el ícono de la
# freidora, que se leía como una caja de regalo.
#
# Uso (desde web_corporativa/):  python herramientas/generar_og.py
# Si cambia el contenido de la imagen, CAMBIAR EL NOMBRE del archivo (el CDN de Hostinger lo guarda
# 7 días y WhatsApp también la guarda) y actualizar og:image en las cinco páginas y en los datos estructurados.
import os
import shutil
import subprocess
import tempfile
import time
from pathlib import Path
from PIL import Image

W = Path(__file__).resolve().parent.parent
FUENTE = W / 'fuentes' / 'og' / 'og.html'
DESTINO = W / 'sitio' / 'assets' / 'img' / 'og-industec-cadenas-v2.jpg'
EDGE = r'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'

perfil = Path(tempfile.mkdtemp(prefix='edge_og_'))
captura = perfil / 'og.png'
try:
    subprocess.run([EDGE, '--headless=new', f'--user-data-dir={perfil / "perfil"}', '--hide-scrollbars',
                    '--force-device-scale-factor=1', '--no-first-run', '--no-default-browser-check',
                    '--window-size=1200,630', '--virtual-time-budget=4000', f'--screenshot={captura}',
                    FUENTE.as_uri()], capture_output=True, check=False)
    for _ in range(40):
        if captura.exists() and captura.stat().st_size > 0:
            break
        time.sleep(0.25)
    im = Image.open(captura).convert('RGB')
    assert im.size == (1200, 630), f'la captura mide {im.size}, no 1200 x 630'
    im.save(DESTINO, 'JPEG', quality=86, optimize=True, progressive=True)  # sin exif= ni icc_profile=
    chk = Image.open(DESTINO)
    print(f'{DESTINO.name}: {chk.size[0]}x{chk.size[1]}, {os.path.getsize(DESTINO) / 1024:.1f} KB, '
          f'metadatos: {len(chk.getexif()) + ("icc_profile" in chk.info)}')
finally:
    shutil.rmtree(perfil, ignore_errors=True)
