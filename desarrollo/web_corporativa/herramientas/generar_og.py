# Genera la imagen para redes (1200 x 630) a partir de fuentes/og/og.html.
# Captura la página con Edge sin ventana y la guarda como JPEG sin metadatos en
# sitio/assets/img/og-industec-cadenas-v4.jpg. La v4 (13-sep-2026, T2.17) lleva en la tarjeta la ilustración
# de la portada en el estilo In Tune (ilus/portada-cocina-cadena.svg); la v3 llevaba la ilustración anterior y la
# v2, la foto de las freidoras.
# JPEG y no PNG: WhatsApp no muestra las vistas previas muy pesadas. Con la ilustración, en calidad 88 y sin
# submuestreo de color (4:4:4, para que el rojo y el azul no se corran en los filos del dibujo), el JPEG pesa
# unos 140 KB y el PNG, unos 159 KB (11-sep-2026).
#
# Uso (desde web_corporativa/):  python herramientas/generar_og.py
# Si cambia el contenido de la imagen (también si cambia la ilustración), CAMBIAR EL NOMBRE del archivo (el CDN
# de Hostinger lo guarda 7 días y WhatsApp también la guarda) y actualizar og:image en las cinco páginas y en
# los datos estructurados de la portada.
import os
import shutil
import subprocess
import tempfile
import time
from pathlib import Path
from PIL import Image

W = Path(__file__).resolve().parent.parent
FUENTE = W / 'fuentes' / 'og' / 'og.html'
DESTINO = W / 'sitio' / 'assets' / 'img' / 'og-industec-cadenas-v4.jpg'
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
    im.save(DESTINO, 'JPEG', quality=88, subsampling=0, optimize=True, progressive=True)  # sin exif= ni icc_profile=
    chk = Image.open(DESTINO)
    print(f'{DESTINO.name}: {chk.size[0]}x{chk.size[1]}, {os.path.getsize(DESTINO) / 1024:.1f} KB, '
          f'metadatos: {len(chk.getexif()) + ("icc_profile" in chk.info)} '
          f'(la captura en PNG pesa {os.path.getsize(captura) / 1024:.1f} KB)')
finally:
    shutil.rmtree(perfil, ignore_errors=True)
