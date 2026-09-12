# Genera las fotos del sitio a partir de fuentes/fotos/. Se exportan en WebP sin metadatos
# (ni EXIF, ni ICC, ni XMP) y con nombre nuevo cada vez que cambian: el CDN de Hostinger
# guarda las imágenes 7 días, así que un archivo que cambia de contenido no puede conservar su nombre.
#
# Uso (desde web_corporativa/):  python herramientas/generar_imagenes.py
# Requiere Pillow con WebP, numpy y OpenCV (cv2, para la gradación de la foto de las freidoras).
#
# La portada ya no lleva foto: lleva la ilustración portada-cocina-cadena-frontal.svg, que genera
# herramientas/gen_ilustracion.py. La foto propia de INDUSTEC que iba en la portada (img_6213, el original de
# 712 x 536 que guarda Zyro) sigue en la galería «Nuestro trabajo» de servicios, con la gradación «clave baja»:
# portada-cine-2-640.webp (640 px, sin ampliar). Por eso este script ya no escribe portada-tecnico-freidoras-640
# ni -712.webp, retiradas del sitio.
# Cuando César entregue el IMG_6213 original del teléfono, basta con reemplazar FOTO_FREIDORAS y volver a correr
# el script (y entonces se puede exportar a mayor tamaño, con nombre nuevo).
import os
import numpy as np
from PIL import Image

AQUI = os.path.dirname(os.path.abspath(__file__))
W = os.path.dirname(AQUI)
F = os.path.join(W, 'fuentes', 'fotos')
IMG = os.path.join(W, 'sitio', 'assets', 'img')

FOTO_FREIDORAS = os.path.join(F, 'img_6213-YrD68EbDEzFeV827.jpg')

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


def guardar(img, rel, calidad):
    ruta = os.path.join(IMG, *rel.split('/'))
    os.makedirs(os.path.dirname(ruta), exist_ok=True)
    img.save(ruta, 'WEBP', quality=calidad, method=6)  # sin exif= ni icc_profile=: sin metadatos
    chk = Image.open(ruta)
    meta = len(chk.getexif()) + ('icc_profile' in chk.info) + ('xmp' in chk.info)
    print(f'{rel:45} {chk.size[0]}x{chk.size[1]}  {os.path.getsize(ruta) / 1024:5.1f} KB  metadatos: {meta}')
    return os.path.getsize(ruta)


# ---------------------------------------------------------------------------------------------------------
# Foto de las freidoras (galería de servicios): gradación «clave baja», la ganadora entre las tres gradaciones
# «cinematográficas» que se probaron para la portada el 11-sep-2026. Aquí va solo cine_2_clave_baja y lo mínimo
# que necesita; da el mismo archivo, byte a byte, que la función original. Pasos: fuera el ruido de color del
# JPEG y el tinte verde-cian que el fluorescente deja en el acero (_cine_base); la pared y el fondo se apagan con
# degradados; queda un pozo de luz sobre los tableros de las freidoras y otro sobre el técnico; claridad
# (contraste local sin halos) y grano fino. Es la misma toma, sin reencuadre ni ampliación (712 -> 640 px).
LUMA = np.array([0.2126, 0.7152, 0.0722], np.float32)


def _luma(a):
    return (a * LUMA).sum(-1)


def _suave(x, a, b):
    t = np.clip((x - a) / (b - a), 0, 1)
    return t * t * (3 - 2 * t)


def _sat(a):
    mx = a.max(-1)
    return (mx - a.min(-1)) / (mx + 1e-6)


def _lineal(a):
    a = np.clip(a, 0, 1)
    return np.where(a <= 0.04045, a / 12.92, ((a + 0.055) / 1.055) ** 2.4).astype(np.float32)


def _srgb(l):
    l = np.clip(l, 0, 1)
    return np.where(l <= 0.0031308, l * 12.92, 1.055 * l ** (1 / 2.4) - 0.055).astype(np.float32)


def _guiado(guia, p, r, eps):
    """Filtro guiado (He et al.): suaviza p respetando los bordes de la guía."""
    import cv2
    k = (2 * r + 1, 2 * r + 1)
    f = lambda x: cv2.boxFilter(x, -1, k, borderType=cv2.BORDER_REFLECT)
    mg, mp = f(guia), f(p)
    a = (f(guia * p) - mg * mp) / (f(guia * guia) - mg * mg + eps)
    return f(a) * guia + f(mp - a * mg)


def _curva(xs, ys, n=4096):
    """Tabla de una curva cúbica monótona (Fritsch-Carlson) por puntos de control: sin rebotes ni inversiones."""
    xs, ys = np.asarray(xs, np.float64), np.asarray(ys, np.float64)
    h = np.diff(xs)
    d = np.diff(ys) / h
    m = np.empty_like(xs)
    m[0], m[-1] = d[0], d[-1]
    for k in range(1, len(xs) - 1):
        w1, w2 = 2 * h[k] + h[k - 1], h[k] + 2 * h[k - 1]
        m[k] = 0 if d[k - 1] * d[k] <= 0 else (w1 + w2) / (w1 / d[k - 1] + w2 / d[k])
    x = np.linspace(0, 1, n)
    i = np.clip(np.searchsorted(xs, x, side='right') - 1, 0, len(xs) - 2)
    t = (x - xs[i]) / h[i]
    t2, t3 = t * t, t * t * t
    y = ((2 * t3 - 3 * t2 + 1) * ys[i] + (t3 - 2 * t2 + t) * h[i] * m[i]
         + (-2 * t3 + 3 * t2) * ys[i + 1] + (t3 - t2) * h[i] * m[i + 1])
    return y.astype(np.float32)


def _aplicar(a, tabla):
    return np.interp(np.clip(a, 0, 1), np.linspace(0, 1, len(tabla)), tabla).astype(np.float32)


def _mezcla_sat(a, f):
    """Saturación por píxel: f = 1 la deja, < 1 la baja, > 1 la sube (luminancia intacta)."""
    L = _luma(a)[..., None]
    return L + (a - L) * np.asarray(f, np.float32)[..., None]


def _claridad(a, r, eps, k):
    """Contraste local sin halos: se realza el detalle que el filtro guiado separa de la base (los bordes
    fuertes quedan en la base y no se exageran); menos efecto en las sombras y luces extremas."""
    L = _luma(a)
    detalle = L - _guiado(L, L, r, eps)
    return a + (k * detalle * (0.25 + 0.75 * 4 * L * (1 - L)))[..., None]


def _cine_base(im):
    """1) quita el ruido de color del JPEG sin tocar casi la luminancia; 2) neutraliza el tinte verde-cian
    del fluorescente en el acero (tonos de 125 a 205 grados poco saturados: las pantallas verdes de las
    freidoras y los guantes conservan su color)."""
    import cv2
    a = cv2.fastNlMeansDenoisingColored(np.asarray(im.convert('RGB')), None, 2, 4, 7, 21).astype(np.float32) / 255
    f = 1 - 0.55 * _campana(_tono(a), 165, 40) * (1 - _suave(_sat(a), 0.25, 0.40))
    return _mezcla_sat(a, f)


def cine_2_clave_baja(im):
    """Clave baja: pared y fondo apagados con degradados, luz dirigida al técnico y a los tableros de las
    freidoras, claridad y (en el acabado) grano fino. Devuelve RGB en coma flotante 0-1."""
    a = _cine_base(im)
    H, W = a.shape[:2]
    yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
    elipse = lambda cx, cy, rx, ry: np.sqrt(((xx - cx) / rx) ** 2 + ((yy - cy) / ry) ** 2)
    L, s = _luma(a), _sat(a)
    # a) la pared: arriba, entre el gabinete y el brazo, está quemada (clave de luces); a la derecha, detrás
    #    de las piernas, en sombra clara (clave de medios). Las ventanas no alcanzan el casco, la espalda ni
    #    los guantes, y la piel, el jean y el chaleco quedan fuera por saturación.
    neutro = 1 - _suave(s, 0.10, 0.22)
    arriba = _suave(L, 0.62, 0.85) * _suave(xx, 430, 470) * (1 - _suave(xx, 575, 605)) * (1 - _suave(yy, 95, 135))
    derecha = _suave(L, 0.40, 0.65) * _suave(xx, 612, 650) * _suave(yy, 150, 180) * (1 - _suave(yy, 300, 330))
    m = np.clip(_guiado(L - 0.5 * s, neutro * np.maximum(arriba, derecha), 3, 2e-3), 0, 1)
    lin = _lineal(a) * (1 - 0.50 * m)[..., None]
    # b) luz dirigida: un pozo de luz sobre los tableros y otro sobre el técnico; la mesa, la estantería de
    #    arriba y el piso del primer plano caen hasta -1,3 EV
    pozo = np.maximum(1 - _suave(elipse(340, 262, 300, 200), 0.45, 1.40),
                      1 - _suave(elipse(612, 205, 140, 250), 0.45, 1.40))
    a = _srgb(lin * (0.40 + 0.60 * pozo)[..., None])
    # c) curva de clave baja: negros firmes sin aplastar, medios algo más bajos, hombro en las luces
    a = _aplicar(a, _curva([0, .05, .25, .5, .75, .92, 1], [.014, .03, .19, .455, .75, .905, .96]))
    # d) claridad: textura del acero, de los tableros y de los guantes
    a = _claridad(a, 12, 0.015, 0.55)
    # e) color: sombras apenas frías, luces apenas cálidas, saturación -8 %
    L = _luma(np.clip(a, 0, 1))
    a = (a + (1 - _suave(L, 0.05, 0.5))[..., None] * np.array([-0.012, 0.002, 0.012], np.float32)
           + _suave(L, 0.55, 1.0)[..., None] * np.array([0.014, 0.005, -0.012], np.float32))
    return np.clip(_mezcla_sat(a, np.full(a.shape[:2], 0.92)), 0, 1)


def _redimensionar(a, ancho):
    alto = round(a.shape[0] * ancho / a.shape[1])
    return np.dstack([np.asarray(Image.fromarray(np.ascontiguousarray(a[..., c]), 'F').resize((ancho, alto), Image.LANCZOS))
                      for c in range(3)])


def _grano(a, amp, semilla=6213):
    """Grano de luminancia fino (ruido gaussiano suavizado a 0,6 px), más en los medios tonos."""
    import cv2
    g = cv2.GaussianBlur(np.random.default_rng(semilla).standard_normal(a.shape[:2]).astype(np.float32), (0, 0), 0.6)
    L = _luma(np.clip(a, 0, 1))
    return a + (amp * g / g.std() * (0.3 + 0.7 * 4 * L * (1 - L)))[..., None]


total = 0
for rel, fuente, caja in TRABAJO:
    im = Image.open(os.path.join(F, fuente)).convert('RGB').crop(caja)
    total += guardar(im.resize((640, 480), Image.LANCZOS), rel, 74)

original = Image.open(FOTO_FREIDORAS)
assert original.size == (712, 536), original.size
b = _grano(_redimensionar(cine_2_clave_baja(original), 640), 0.011)
total += guardar(Image.fromarray((np.clip(b, 0, 1) * 255 + 0.5).astype(np.uint8), 'RGB'), 'portada-cine-2-640.webp', 80)

print(f'TOTAL {total / 1024:.1f} KB')
