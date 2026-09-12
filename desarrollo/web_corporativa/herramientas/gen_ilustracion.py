# -*- coding: utf-8 -*-
"""Genera la ilustración de la portada: alzado frontal de la línea de cocina de un local de cadena.

Salida: sitio/assets/img/portada-cocina-cadena-frontal.svg (712 x 536, 4:3). La piden la portada
(sitio/index.html) y la tarjeta de la imagen para redes (fuentes/og/og.html).

Uso (desde web_corporativa/):
    python herramientas/gen_ilustracion.py                        -> escribe el SVG del sitio
    python herramientas/gen_ilustracion.py otra/ruta.svg          -> lo escribe en otra ruta (para comparar)
    python herramientas/gen_ilustracion.py --sin-insignia r.svg   -> variante SIN la insignia redonda de la
                                                                    llave (arriba a la izquierda). Es una
                                                                    variante de prueba: exige la ruta de
                                                                    salida, para no pisar el SVG del sitio.
Para verla renderizada (a 2x, en el recorte del celular y a su tamaño real): sh herramientas/render.sh

Es determinista: dos corridas dan el mismo archivo, byte a byte (el script imprime su sha256).
Si cambia el dibujo después de publicado, CAMBIAR EL NOMBRE del SVG (el CDN de Hostinger guarda las
imágenes 7 días) y actualizar la portada, og.html y este script.

Escena, de izquierda a derecha: línea fría (refrigerador vertical comercial de dos cuerpos con 2 x 2 medias
puertas y mesa refrigerada de preparación) -> campana de extracción sobre la línea caliente, con revestimiento
de acero en la pared: tres freidoras de piso con aceite y canastillas, y la tercera abierta, con un técnico de
INDUSTEC arrodillado que la diagnostica con el multímetro -> mesa de acero con el mantenedor de calor.
Unidades de escena: 150 = 1 m, piso en y = 432. Toda la escena se dibuja a escala 1,1 sobre el lienzo
(piso en y = 456) y el técnico, además, a 1,15 respecto de los equipos."""
import hashlib
import math
import os
import sys

AQUI = os.path.dirname(os.path.abspath(__file__))
WEB = os.path.dirname(AQUI)
SVG_SITIO = os.path.join(WEB, 'sitio', 'assets', 'img', 'portada-cocina-cadena-frontal.svg')

rutas = [x for x in sys.argv[1:] if not x.startswith('--')]
banderas = [x for x in sys.argv[1:] if x.startswith('--')]
if [b for b in banderas if b != '--sin-insignia'] or len(rutas) > 1:
    sys.exit('Uso: python herramientas/gen_ilustracion.py [salida.svg] [--sin-insignia]')
INSIGNIA = '--sin-insignia' not in banderas
if not INSIGNIA and not rutas:
    sys.exit('--sin-insignia genera una variante de prueba: indicar la ruta de salida (así no se pisa el SVG del sitio)')
SALIDA = os.path.abspath(rutas[0]) if rutas else SVG_SITIO

S = "#48537E"          # trazo (azul del logo)
L1, L2, L3, L4 = "#F4F6FA", "#E7EBF2", "#DCE1EA", "#CDD4E0"   # aceros, de claro a oscuro
R = "#CC504B"          # acento rojo
FRIO = "#9CC0E6"       # celeste de las pantallas de la línea fría
ACEITE, ACEITE_LUZ = "#C99A3E", "#E6C274"
PANTALLA = "#2A3150"
PISO = 432             # piso, en unidades de escena
ESC, TX, TY = 1.1, -26.4, -19.2   # escena -> lienzo: x' = 1,1 x - 26,4 ; y' = 1,1 y - 19,2 (el piso queda en 456)

P = []


def a(*s):
    P.extend(s)


def n(v):
    s = f"{v:.1f}"
    s = s[:-2] if s.endswith(".0") else s
    return "0" if s == "-0" else s


# ---------------------------------------------------------------- cabecera
a('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 712 536" width="712" height="536">',
  '<defs>'
  '<linearGradient id="f" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ECEFF6"/><stop offset="1" stop-color="#DFE4EE"/></linearGradient>'
  '<pattern id="a" width="34" height="34" patternUnits="userSpaceOnUse"><path d="M0 .75h34M.75 0v34" fill="none" stroke="#D2D9E5" stroke-width="1.5"/></pattern>'
  # revestimiento de acero de la pared de la línea caliente, más oscuro que los equipos
  '<linearGradient id="rv" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#C9D0DC"/><stop offset="1" stop-color="#B8C1D2"/></linearGradient>'
  # acero cepillado: líneas verticales finas, más oscuras y más claras que la base
  '<pattern id="c" width="12" height="12" patternUnits="userSpaceOnUse"><rect width="12" height="12" fill="#E8ECF2"/>'
  '<path d="M1 0v12M3.5 0v12M8 0v12" stroke="#D3D9E4" stroke-width=".9"/><path d="M5.5 0v12M10.5 0v12" stroke="#F7F9FC" stroke-width=".9"/></pattern>'
  # reflejo vertical suave del acero (no el brillo diagonal de un vidrio)
  '<linearGradient id="b" x1="0" y1="0" x2="1" y2="0"><stop offset=".1" stop-color="#fff" stop-opacity="0"/>'
  '<stop offset=".28" stop-color="#fff" stop-opacity=".55"/><stop offset=".46" stop-color="#fff" stop-opacity="0"/></linearGradient>'
  '<clipPath id="t"><rect x="-20" y="-38" width="40" height="76" rx="15"/></clipPath>'
  '</defs>')

a(f'<g transform="matrix({ESC} 0 0 {ESC} {n(TX)} {n(TY)})">')

# ---------------------------------------------------------------- pared, revestimiento y piso
a(f'<rect x="-30" y="-30" width="760" height="{PISO + 30}" fill="url(#f)"/>',
  f'<rect x="-30" y="-30" width="760" height="{PISO + 30}" fill="url(#a)"/>',
  f'<circle cx="{n((60 - TX) / ESC)}" cy="{n((50 - TY) / ESC)}" r="{n(150 / ESC)}" fill="{R}" opacity=".045"/>')
COSTURAS = (374, 470, 566, 662)
a(f'<rect x="278" y="128" width="460" height="{PISO - 128}" fill="url(#rv)"/>',
  '<path d="' + "".join(f"M{x} 136V{PISO}" for x in (278,) + COSTURAS) + '" stroke="#A7B1C4" stroke-width="2" fill="none"/>',
  '<path d="' + "".join(f"M{x + 2.5} 136V{PISO}" for x in COSTURAS) + '" stroke="#D3D9E3" stroke-width="1.2" fill="none"/>',
  f'<rect x="-30" y="{PISO}" width="760" height="110" fill="#CDD5E2"/>')

VX, VY = 356, 200  # punto de fuga del piso


def fuga(x, y1, y2):
    """x de la recta que pasa por el punto de fuga y (x, y1), evaluada en y2."""
    return VX + (x - VX) * (y2 - VY) / (y1 - VY)


d = "".join(f"M-30 {y}h760" for y in (447, 468, 497, 533))
d += "".join(f"M{x} {PISO}L{n(fuga(x, PISO, 540))} 540" for x in range(-76, 800, 72))
a(f'<path d="{d}" fill="none" stroke="#BAC3D3" stroke-width="1.5"/>',
  f'<path d="M-30 {PISO}h760" stroke="#AAB4C7" stroke-width="2"/>')

# rejilla de desagüe frente a las freidoras (en el recorte del celular queda fuera)
gt, gb, xl, xr = 444, 460, 300, 444
a(f'<path d="M{xl} {gt}H{xr}L{n(fuga(xr, gt, gb))} {gb}H{n(fuga(xl, gt, gb))}z" fill="#AEB7C9" stroke="{S}" stroke-width="2" stroke-linejoin="round"/>')
d = "".join(f"M{x} {gt + 2}L{n(fuga(x, gt, gb - 2))} {gb - 2}" for x in range(308, 440, 8))
a(f'<path d="{d}" stroke="#7F8AA6" stroke-width="2" fill="none"/>')

# sombras
a('<g fill="#BCC5D4" opacity=".8">'
  '<ellipse cx="98" cy="434" rx="70" ry="4.5"/><ellipse cx="221" cy="434" rx="58" ry="4.5"/>'
  '<ellipse cx="372" cy="434" rx="96" ry="4.5"/><ellipse cx="556" cy="435" rx="88" ry="5"/>'
  '<ellipse cx="637" cy="434" rx="66" ry="4.5"/></g>')

# ---------------------------------------------------------------- grupo con el trazo del sitio
a(f'<g stroke="{S}" stroke-width="3" stroke-linejoin="round" stroke-linecap="round">')

# ---- ventilación: ducto, campana, filtros de lamas, sistema contra incendios
a(f'<rect x="452" y="-30" width="84" height="80" fill="{L3}" stroke="none"/>',
  '<path d="M452 -30v78M536 -30v78" fill="none"/>',
  '<path d="M452 18h84" stroke="#BCC5D5" stroke-width="2" fill="none"/>',
  f'<rect x="276" y="48" width="452" height="80" rx="6" fill="{L2}"/>',
  '<rect x="286" y="56" width="432" height="5" rx="2.5" fill="#fff" stroke="none" opacity=".75"/>',
  '<path d="M420 64v18M564 64v18" stroke="#C5CDDB" stroke-width="2" fill="none"/>',
  f'<rect x="282" y="89" width="446" height="35" rx="3" fill="{L3}" stroke-width="2"/>')
for i in range(9):
    x = 286 + 48 * i
    a(f'<rect x="{x}" y="93" width="44" height="27" rx="2" fill="{L1}" stroke-width="2"/>')
    a('<path d="' + "".join(f"M{n(x + 6 + 7.5 * k)} 116l5-19" for k in range(5)) + '" stroke="#9AA5BC" stroke-width="2" fill="none"/>')
a(f'<rect x="268" y="124" width="462" height="12" rx="4" fill="{L4}"/>')
d = "M290 136v12H712" + "".join(f"M{x} 148v6M{x - 3} 156h6" for x in (312, 372, 432, 624))
a(f'<path d="{d}" stroke="#8E99B3" stroke-width="3" fill="none"/>')

# vapor de las dos freidoras en uso (claro, para leerse sobre el revestimiento)
d = "".join(f"M{x} {y}c-5-5 5-10 0-15s5-10 0-15s5-10 0-15" for x, y in ((302, 228), (322, 220), (362, 228), (382, 220)))
a(f'<path d="{d}" stroke="#F7F9FC" fill="none"/>')

# ---- pantalla de pedidos de cocina (KDS), rasgo de cadena: tarjetas sin texto
a(f'<rect x="210" y="141" width="20" height="10" rx="2" fill="{L4}"/>',
  '<rect x="172" y="148" width="96" height="60" rx="6" fill="#363F63"/>',
  f'<rect x="178" y="154" width="84" height="48" rx="2" fill="{PANTALLA}" stroke="none"/>')
for i, tope in enumerate((R, FRIO, "#AEB7C9")):
    x = 182 + 27 * i
    a(f'<rect x="{x}" y="158" width="22" height="40" rx="2" fill="{L1}" stroke="none"/>',
      f'<path d="M{x} 161.5h22" stroke="{tope}" stroke-width="7" stroke-linecap="butt"/>',
      f'<path d="M{x + 4} 172h14M{x + 4} 179h9M{x + 4} 186h12" stroke="#AEB7C9" stroke-width="2.5"/>')

# ---- línea fría 1: refrigerador vertical comercial, dos cuerpos con 2 x 2 medias puertas de acero cepillado,
#      rejilla del compresor arriba y pantalla de temperatura
a(f'<rect x="34" y="116" width="128" height="304" rx="6" fill="{L3}"/>',
  '<rect x="40" y="122" width="116" height="26" rx="3" fill="#C9D0DC" stroke-width="2.5"/>',
  '<path d="M46 128h62M46 133h62M46 138h62M46 143h62" stroke="#8792AB" stroke-width="2.5" fill="none"/>',
  f'<rect x="115" y="126" width="36" height="18" rx="3" fill="{PANTALLA}" stroke="none"/>',
  f'<path d="M123 130.5v9M119.1 132.8l7.8 4.5M119.1 137.2l7.8-4.5" stroke="{FRIO}" stroke-width="1.5" fill="none"/>',
  f'<path d="M132 131h6v9h-6M132.6 135.5h5.4" stroke="{FRIO}" stroke-width="1.8" fill="none" stroke-linecap="butt"/>',
  f'<circle cx="142.5" cy="132" r="1.4" stroke="{FRIO}" stroke-width="1.1" fill="none"/>')
for x, y in ((40, 152), (99, 152), (40, 283), (99, 283)):
    a(f'<rect x="{x}" y="{y}" width="57" height="129" rx="3" fill="url(#c)" stroke-width="2.5"/>',
      f'<rect x="{x + 2}" y="{y + 2}" width="53" height="125" rx="2" fill="url(#b)" stroke="none"/>')
MANIJAS = ((89, 226, 270), (107, 226, 270), (89, 294, 338), (107, 294, 338))
a('<path d="' + "".join(f"M{x} {y1}V{y2}" for x, y1, y2 in MANIJAS) + '" stroke-width="5" fill="none"/>',
  '<path d="' + "".join(f"M{x - 3} {y1}h6M{x - 3} {y2}h6" for x, y1, y2 in MANIJAS) + '" stroke-width="2.5" fill="none"/>',
  '<path d="' + "".join(f"M{x} {y}v12" for x in (37, 159) for y in (162, 256, 293, 387)) + '" stroke-width="3" fill="none"/>',
  '<path d="M44 416.5h108" stroke="#9AA5BC" stroke-width="2" fill="none"/>',
  '<path d="M44 421v11M152 421v11" stroke-width="6"/>')

# ---- línea fría 2: mesa refrigerada de preparación con riel de insumos (tonos de la paleta) y su pantalla
for x, c in ((176, "#D27570"), (200, FRIO), (224, "#AEB7C9"), (248, "#F3EFE6")):
    a(f'<path d="M{x} 280q0-11 10-11t10 11z" fill="{c}" stroke-width="2.5"/>')
a(f'<rect x="170" y="276" width="102" height="18" rx="3" fill="{L3}"/>',
  f'<rect x="250" y="280.5" width="17" height="9" rx="1.5" fill="{PANTALLA}" stroke="none"/>',
  f'<path d="M253.5 285h6" stroke="{FRIO}" stroke-width="2"/>',
  f'<circle cx="263" cy="285" r="1.3" fill="{FRIO}" stroke="none"/>',
  f'<rect x="166" y="292" width="110" height="10" rx="3" fill="{L4}"/>',
  f'<rect x="168" y="302" width="106" height="116" fill="{L2}"/>')
for x in (173, 223):
    a(f'<rect x="{x}" y="308" width="46" height="84" rx="3" fill="url(#c)" stroke-width="2.5"/>',
      f'<rect x="{x + 2}" y="310" width="42" height="80" rx="2" fill="url(#b)" stroke="none"/>')
a('<path d="M212 318v28M230 318v28" stroke-width="4.5" fill="none"/>',
  '<path d="M180 401h82M180 409h82" stroke="#9AA5BC" stroke-width="2.5" fill="none"/>',
  '<path d="M180 418v3M262 418v3" fill="none"/>',
  f'<circle cx="180" cy="426" r="5" fill="{S}" stroke="none"/><circle cx="262" cy="426" r="5" fill="{S}" stroke="none"/>')

# ---- mesa de acero inoxidable con mantenedor de calor (línea caliente) y la caja de herramientas en el entrepaño
a(f'<rect x="578" y="318" width="7" height="106" fill="{L3}"/>',
  f'<rect x="688" y="318" width="7" height="106" fill="{L3}"/>',
  f'<rect x="574" y="378" width="130" height="7" rx="2" fill="{L3}"/>',
  f'<rect x="575" y="424" width="13" height="8" rx="2" fill="{L4}"/>',
  f'<rect x="685" y="424" width="13" height="8" rx="2" fill="{L4}"/>',
  f'<rect x="574" y="308" width="130" height="11" fill="{L2}"/>',
  f'<rect x="570" y="300" width="134" height="10" rx="3" fill="{L4}"/>')
a('<rect x="584" y="206" width="80" height="90" rx="6" fill="url(#c)"/>',
  '<path d="M584 222h80" stroke-width="2" fill="none"/>',
  f'<rect x="591" y="210" width="22" height="8" rx="2" fill="{S}" stroke="none"/>',
  f'<circle cx="655" cy="214" r="3" fill="{R}" stroke="none"/>',
  '<path d="M592 296v4M656 296v4" stroke-width="4"/>')
for y in (227, 251, 275):
    a(f'<rect x="589" y="{y}" width="42" height="19" rx="3" fill="#F6E7DA" stroke-width="2"/>',
      f'<path d="M594 {y + 13}h32" stroke="#C99F84" stroke-width="2" fill="none"/>',
      f'<rect x="636" y="{y + 3}" width="23" height="13" rx="2" fill="{S}" stroke="none"/>',
      f'<path d="M640 {y + 9.5}h5M648 {y + 9.5}h5" stroke="#F08079" stroke-width="3"/>')
a('<path d="M614 356v-8h20v8" fill="none" stroke-width="3.5"/>',
  f'<rect x="598" y="355" width="52" height="23" rx="4" fill="{R}"/>',
  '<path d="M598 363h52" stroke-width="2.5" fill="none"/>',
  '<rect x="619" y="359" width="10" height="8" rx="2" fill="#fff" stroke-width="2"/>')

# ---- línea caliente: batería de tres freidoras de piso. Desde arriba se ve el aceite dorado de cada tina;
#      las canastillas son siluetas de malla con mango, como las del ícono de freidora que tuvo el sitio.
MODULO = (8, 366, 14, 26)          # módulo de control dentro del gabinete abierto: dx, y, ancho, alto
BORNES = (372, 380, 388)           # bornes del módulo (y), en x = X + 15


def freidora(X, abierta=False):
    # respaldo con la rejilla del ducto de humos y la barra de la que cuelgan las canastillas
    a(f'<rect x="{X + 3}" y="236" width="54" height="50" rx="3" fill="{L3}"/>',
      f'<path d="M{X + 16} 242h28" stroke="#9AA5BC" stroke-width="3" fill="none"/>',
      f'<path d="M{X + 5} 257h50" stroke="#7F8AA6" stroke-width="3" fill="none"/>')
    for bx in (X + 8, X + 32):
        a(f'<path d="M{bx + 12} 257l5-9h3" fill="none" stroke-width="2.6"/>',
          f'<path d="M{bx + 18} 248h6" stroke="#2A3150" stroke-width="5"/>',
          f'<rect x="{bx}" y="257" width="20" height="21" rx="2" fill="#8E99B3" stroke-width="2.5"/>',
          f'<path d="M{n(bx + 6.7)} 259.5v16M{n(bx + 13.3)} 259.5v16M{bx + 2} 264.5h16M{bx + 2} 271h16" stroke="#B3BDCF" stroke-width="1.3" fill="none"/>')
    luz = "#8E99B3" if abierta else R
    a(f'<path d="M{X + 4} 283H{X + 56}L{X + 60} 299H{X}z" fill="{L1}"/>',
      f'<path d="M{X + 8.5} 285.5H{X + 51.5}L{X + 54.5} 296.5H{X + 5.5}z" fill="{ACEITE}" stroke-width="2"/>',
      f'<path d="M{X + 12} 289h18" stroke="{ACEITE_LUZ}" stroke-width="2.2" fill="none"/>',
      f'<rect x="{X + 2}" y="299" width="56" height="119" fill="{L2}"/>',
      f'<rect x="{X + 6}" y="304" width="48" height="15" rx="2" fill="#363F63" stroke="none"/>',
      f'<rect x="{X + 9}" y="307" width="18" height="9" rx="1.5" fill="#232A45" stroke="none"/>',
      f'<path d="M{X + 12} 311.5h7" stroke="{luz}" stroke-width="2.6"/>',
      f'<circle cx="{X + 23}" cy="311.5" r="1.6" fill="{luz}" stroke="none"/>',
      f'<path d="M{X + 32} 311.5h1.5M{X + 39.5} 311.5h1.5M{X + 47} 311.5h1.5" stroke="#AEB7C9" stroke-width="4.5"/>',
      f'<path d="M{X + 2} 323h56" stroke-width="2" fill="none"/>',
      f'<path d="M{X + 10} 418v3M{X + 50} 418v3" fill="none"/>',
      f'<circle cx="{X + 10}" cy="426" r="5" fill="{S}" stroke="none"/><circle cx="{X + 50}" cy="426" r="5" fill="{S}" stroke="none"/>')
    if not abierta:
        a(f'<rect x="{X + 7}" y="327" width="46" height="85" rx="3" fill="url(#c)" stroke-width="2"/>',
          f'<rect x="{X + 9}" y="329" width="42" height="81" rx="2" fill="url(#b)" stroke="none"/>',
          f'<path d="M{X + 18} 335h24" stroke-width="4" fill="none"/>')
        return
    # interior a la vista: fondo de la olla, quemador, línea de gas y módulo de control con sus bornes
    mx, my, mw, mh = MODULO
    a(f'<rect x="{X + 7}" y="327" width="46" height="85" rx="3" fill="#4C5679" stroke-width="2"/>',
      f'<path d="M{X + 11} 329h38v6a7 7 0 0 1-7 7H{X + 18}a7 7 0 0 1-7-7z" fill="#6C7697" stroke="none"/>',
      f'<path d="M{X + 12} 348h36" stroke="#8E98B6" stroke-width="3" fill="none"/>',
      f'<path d="M{X + 38} 348v52" stroke="{L4}" stroke-width="5" fill="none"/>',
      f'<path d="M{X + 15} {my}V352M{X + 19} {my}c0-6 8-6 8-14" stroke="{FRIO}" stroke-width="2" fill="none"/>',
      f'<rect x="{X + mx}" y="{my}" width="{mw}" height="{mh}" rx="2" fill="#AEB7C9" stroke-width="2"/>')
    for y in BORNES:
        a(f'<circle cx="{X + 15}" cy="{y}" r="2" fill="{S}" stroke="none"/>')
    # puerta abierta hacia la izquierda (bisagra a la izquierda)
    a(f'<path d="M{X + 7} 327L{X - 10} 319V420L{X + 7} 412z" fill="{L2}"/>',
      f'<path d="M{X - 4} 335v22" stroke-width="4" fill="none"/>')


FREIDORAS = (282, 342, 402)
for i, X in enumerate(FREIDORAS):
    freidora(X, abierta=(i == 2))

# ---- técnico de INDUSTEC: casco blanco, chaleco naranja reflectivo, guantes, uniforme azul.
#      Se dibuja en sus coordenadas (las del primer diseño) y se lleva a 1,15 anclado en el piso, bajo las manos.
TE, ANCLA_LOCAL, ANCLA = 1.15, 452, 436
TEC = (TE, ANCLA - TE * ANCLA_LOCAL, PISO - TE * PISO)
SW_TEC = 2.6                      # 2,6 x 1,15 = 3: el mismo trazo que los equipos


def a_escena(x, y):
    return ANCLA + TE * (x - ANCLA_LOCAL), PISO + TE * (y - PISO)


def unitario(p, q):
    dx, dy = q[0] - p[0], q[1] - p[1]
    m = math.hypot(dx, dy)
    return dx / m, dy / m


# brazos: hombro, codo y fin de la manga; el guante empieza 6 unidades más allá, en la misma dirección
BRAZO_CERCA = ((526, 306), (490, 336), (462, 360))
BRAZO_LEJOS = ((534, 302), (506, 346), (464, 385))


def mano(brazo):
    """(muñeca, dirección, puño en coordenadas de escena) del brazo."""
    u = unitario(brazo[1], brazo[2])
    muneca = (brazo[2][0] + 6 * u[0], brazo[2][1] + 6 * u[1])
    puno = a_escena(muneca[0] + 10 * u[0], muneca[1] + 10 * u[1])
    return muneca, u, puno


# ---- multímetro en el piso, frente a la segunda freidora: sus cables rojo y negro entran al gabinete abierto
#      y terminan en las puntas de prueba, que el técnico apoya en los bornes del módulo de control
MX, MY = 358, 402
X3 = FREIDORAS[2]
puntas = ((mano(BRAZO_CERCA)[2], (X3 + 16, BORNES[0]), R, (MX + 8, MY + 1)),
          (mano(BRAZO_LEJOS)[2], (X3 + 16, BORNES[2]), "#1E2438", (MX + 22, MY + 1)))
for (px, py), _, color, (jx, jy) in puntas:
    d = f"M{n(jx)} {n(jy)}C{n(jx + 4)} {n(jy - 28)} {n(px - 40)} {n(py + 22)} {n(px)} {n(py)}"
    a(f'<path d="{d}" stroke="{L1}" stroke-width="7" fill="none"/>',
      f'<path d="{d}" stroke="{color}" stroke-width="3.5" fill="none"/>')
for (px, py), (tx, ty), color, _ in puntas:
    cx, cy = px + 0.5 * (tx - px), py + 0.5 * (ty - py)
    a(f'<path d="M{n(px)} {n(py)}L{n(cx)} {n(cy)}" stroke="{L1}" stroke-width="6.5" fill="none"/>',
      f'<path d="M{n(px)} {n(py)}L{n(cx)} {n(cy)}" stroke="{color}" stroke-width="4" fill="none"/>',
      f'<path d="M{n(cx)} {n(cy)}L{n(tx)} {n(ty)}" stroke="#E4E8EF" stroke-width="1.8" fill="none"/>')
a(f'<rect x="{MX}" y="{MY}" width="30" height="32" rx="5" fill="#363F63"/>',
  f'<rect x="{MX + 5}" y="{MY + 6}" width="20" height="10" rx="1.5" fill="#DCE6F0" stroke="none"/>',
  f'<path d="M{MX + 8} {MY + 11}h9" stroke="{S}" stroke-width="2"/>',
  f'<circle cx="{MX + 15}" cy="{MY + 25}" r="4.5" fill="#fff" stroke-width="2"/>',
  f'<circle cx="{MX + 8}" cy="{MY + 1}" r="2.4" fill="{R}" stroke="none"/>',
  f'<circle cx="{MX + 22}" cy="{MY + 1}" r="2.4" fill="#1E2438" stroke="none"/>')

UN, UF = "#5C76BA", "#4D66A8"   # uniforme (lado cercano / lejano)
VEST, CINTA = "#F08A2E", "#EEF1F6"
PIEL, GUANTE, PUNO_GUANTE, BOTA = "#D9A583", "#A9CAEC", "#86A9D8", "#3B4467"


def miembro(d, w, color):
    a(f'<path d="{d}" fill="none" stroke="{S}" stroke-width="{n(w + 2 * SW_TEC)}"/>',
      f'<path d="{d}" fill="none" stroke="{color}" stroke-width="{w}"/>')


def brazo(pts, color):
    miembro("M" + "L".join(f"{x} {y}" for x, y in pts), 15, color)
    muneca, u, _ = mano(pts)
    ang = math.degrees(math.atan2(u[1], u[0]))
    # guante con forma de mano, ~70 % del primer diseño: puño del guante, mano cerrada sobre la punta de
    # prueba, pulgar arriba y la línea de los nudillos
    a(f'<g transform="translate({n(muneca[0])} {n(muneca[1])}) rotate({ang:.1f})">',
      f'<rect x="-2" y="-6.2" width="8.5" height="12.4" rx="2" fill="{PUNO_GUANTE}"/>',
      f'<path d="M5.5 -5.6H11C15 -5.6 17 -3 17 0S15 5.6 11 5.6H5.5z" fill="{GUANTE}"/>',
      f'<path d="M6.8 4.8C7 10.8 12.8 12.2 14.3 9 15.1 7.2 13.4 5.6 11.4 5.2" fill="{GUANTE}"/>',
      '<path d="M13.2 -4.4v5" stroke-width="1.3" fill="none"/>',
      '</g>')


a(f'<g transform="matrix({TEC[0]} 0 0 {TEC[0]} {n(TEC[1])} {n(TEC[2])})" stroke-width="{SW_TEC}">')
miembro("M566 362L558 422L612 424", 19, UF)                       # pierna lejana, rodilla en el piso
a(f'<path d="M602 432v-15a3 3 0 0 1 3-3h17c12 0 18 8 18 18z" fill="{BOTA}"/>')
brazo(BRAZO_LEJOS, UF)
miembro("M527 302L519 290", 9, PIEL)                              # cuello
a('<g transform="translate(548 333) rotate(-32)">',
  '<g clip-path="url(#t)">',
  f'<rect x="-20" y="-38" width="40" height="76" fill="{UN}" stroke="none"/>',
  f'<rect x="-20" y="-27" width="40" height="60" fill="{VEST}" stroke="none"/>',
  f'<path d="M-20 -6h40M-20 14h40" stroke="{CINTA}" stroke-width="6"/>',
  '<path d="M-20 -27h40M-20 33h40" stroke-width="2"/>',
  '</g>',
  '<rect x="-20" y="-38" width="40" height="76" rx="15" fill="none"/>',
  '</g>')
miembro("M566 362L502 350L514 418", 21, UN)                       # pierna cercana, rodilla arriba
a(f'<path d="M534 432H497q-9 0-9-8t9-8h9l3-6h23z" fill="{BOTA}"/>')
brazo(BRAZO_CERCA, UN)
a('<g transform="translate(515 283) rotate(-16)">',
  f'<circle r="13.5" fill="{PIEL}"/>',
  '<path d="M-17.5 -2a17.5 17.5 0 0 1 35 0z" fill="#fff"/>',
  '<rect x="-27" y="-4.5" width="47" height="6.5" rx="3.25" fill="#fff"/>',
  '<path d="M1 -19v14" stroke-width="2" fill="none"/>',
  '</g>')
a('</g>')   # técnico

a('</g>')   # trazo
a('</g>')   # escena

# ---------------------------------------------------------------- insignia (fuera de la escala de la escena)
if INSIGNIA:
    a('<circle cx="85" cy="67" r="29" fill="#48537E" opacity=".12"/>',
      '<circle cx="82" cy="62" r="29" fill="#fff" stroke="#E1E5EE" stroke-width="1.5"/>',
      '<g transform="translate(62.8 42.8) scale(1.6)" fill="none" stroke="#CC504B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
      '<path d="M3.87 17.87 10.52 11.22A5 5 0 0 1 17.31 4.57L15 6.88 17.12 9 19.43 6.69A5 5 0 0 1 12.78 13.48L6.13 20.13A1.6 1.6 0 0 1 3.87 17.87Z"/>'
      '<path d="m19.8 14.2-2.3 3.6h3l-2.3 3.7"/></g>')
a('</svg>')

svg = "\n".join(P) + "\n"
datos = svg.encode("utf-8")
os.makedirs(os.path.dirname(SALIDA), exist_ok=True)
with open(SALIDA, "wb") as fh:
    fh.write(datos)
print(f"{SALIDA}  {len(datos)} bytes  sha256 {hashlib.sha256(datos).hexdigest()}")
