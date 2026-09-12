# Genera las fotos del sitio a partir de fuentes/fotos/. Se exportan en WebP sin metadatos
# (ni EXIF, ni ICC, ni XMP) y con nombre nuevo cada vez que cambian: el CDN de Hostinger
# guarda las imágenes 7 días, así que un archivo que cambia de contenido no puede conservar su nombre.
#
# Uso (desde web_corporativa/):  python herramientas/generar_imagenes.py
# Requiere Pillow con WebP y numpy.
#
# Portada: foto propia de INDUSTEC (img_6213), publicada hoy en www.industec.me. El original
# que guarda Zyro mide 712 x 536: se sirve a 640 y a 712 (nativa), nunca ampliada.
# Cuando César entregue el IMG_6213 original del teléfono, basta con reemplazar PORTADA y
# volver a correr el script (y entonces sí se pueden agregar tamaños mayores).
import os
import numpy as np
from PIL import Image

AQUI = os.path.dirname(os.path.abspath(__file__))
W = os.path.dirname(AQUI)
F = os.path.join(W, 'fuentes', 'fotos')
IMG = os.path.join(W, 'sitio', 'assets', 'img')

PORTADA = os.path.join(F, 'img_6213-YrD68EbDEzFeV827.jpg')

# (salida, fuente, recorte 4:3 sobre la fuente de 1200 px de ancho)
TRABAJO = [
    ('trabajo/vitrina-caliente-640.webp', 'vitrina-caliente-tablero-abierto.webp', (0, 150, 1200, 1050)),
    ('trabajo/horno-rotativo-640.webp', 'tecnicos-mantenimiento-horno-rotativo.webp', (2, 0, 1197, 896)),
    ('trabajo/freidoras-presion-640.webp', 'freidoras-de-presion-bajo-campana.webp', (0, 60, 1200, 960)),
    ('trabajo/linea-despacho-640.webp', 'mantenedores-de-calor-linea-despacho.webp', (0, 0, 1200, 900)),
]


def _tono(rgb):
    """Tono en grados (0-360) de una imagen RGB en coma flotante 0-1."""
    r, g, b = rgb[..., 0], rgb[..., 1], rgb[..., 2]
    mx, mn = rgb.max(-1), rgb.min(-1)
    d = np.where(mx - mn == 0, 1e-6, mx - mn)
    h = np.where(mx == r, (g - b) / d % 6, np.where(mx == g, (b - r) / d + 2, (r - g) / d + 4))
    return h * 60


def _campana(h, centro, ancho):
    dist = np.abs(((h - centro + 180) % 360) - 180)
    return np.clip(1 - dist / ancho, 0, 1)


def corregir_portada(im):
    """Corrección suave, sin enfoque (el JPEG de 712 px ya trae artefactos):
    1) quita el tinte cian del acero (tonos de 140 a 220 grados, 60 % menos saturados);
    2) baja un 6 % la saturación de los naranjas (baldosa y chaleco) para que convivan con la paleta fría;
    3) comprime las luces por encima de 0,80 para que el casco y la pared no se vean quemados."""
    rgb = np.asarray(im.convert('RGB')).astype(np.float32) / 255
    h = _tono(rgb)
    luma = (rgb * np.array([0.2126, 0.7152, 0.0722], np.float32)).sum(-1, keepdims=True)
    factor = (1 - 0.60 * _campana(h, 180, 40)) * (1 - 0.06 * _campana(h, 22, 20))
    rgb = luma + (rgb - luma) * factor[..., None]
    umbral, k = 0.80, 0.78
    rgb = np.where(rgb > umbral, umbral + (rgb - umbral) * k, rgb)
    # contraste muy leve alrededor del gris medio
    rgb = 0.5 + (rgb - 0.5) * 1.03
    return Image.fromarray((np.clip(rgb, 0, 1) * 255 + 0.5).astype(np.uint8), 'RGB')


def guardar(img, rel, calidad):
    ruta = os.path.join(IMG, *rel.split('/'))
    os.makedirs(os.path.dirname(ruta), exist_ok=True)
    img.save(ruta, 'WEBP', quality=calidad, method=6)  # sin exif= ni icc_profile=: sin metadatos
    chk = Image.open(ruta)
    meta = len(chk.getexif()) + ('icc_profile' in chk.info) + ('xmp' in chk.info)
    print(f'{rel:45} {chk.size[0]}x{chk.size[1]}  {os.path.getsize(ruta) / 1024:5.1f} KB  metadatos: {meta}')
    return os.path.getsize(ruta)


total = 0
base = corregir_portada(Image.open(PORTADA))
assert base.size == (712, 536), base.size
total += guardar(base, 'portada-tecnico-freidoras-712.webp', 82)
total += guardar(base.resize((640, 482), Image.LANCZOS), 'portada-tecnico-freidoras-640.webp', 80)

for rel, fuente, caja in TRABAJO:
    im = Image.open(os.path.join(F, fuente)).convert('RGB').crop(caja)
    total += guardar(im.resize((640, 480), Image.LANCZOS), rel, 74)

print(f'TOTAL {total / 1024:.1f} KB')
