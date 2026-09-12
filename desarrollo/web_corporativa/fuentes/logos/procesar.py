"""Logos de clientes y marcas para la web de INDUSTEC.

Origen: fuentes/zyro/logos/, los archivos tal como los publica hoy www.industec.me.
Salida (junto a este script): clientes/<slug>.webp y marcas/<slug>.webp (96 px de alto), alternativas/,
retenidas/ y manifiesto.json. La hoja de revisión (hoja_logos.png) va a la carpeta temporal del sistema.
Después se copian clientes/ y marcas/ a sitio/assets/img/ (LEEME, sección 9.2) y se corre el verificador,
que exige que la portada y servicios muestren estos logos, en este orden, con este nombre como texto
alternativo y con class="compacto" en los que el manifiesto marca como compactos.

Uso (desde web_corporativa/):   python fuentes/logos/procesar.py
  SALIDA=<carpeta>    escribe la salida en otra carpeta (para comparar sin tocar la vigente)
  HOJA=<archivo.png>  otra ruta para la hoja de revisión

Orden: el de las galerías de www.industec.me. Las marcas son la excepción: abren con los fabricantes de
cocina profesional y siguen refrigeración y extracción, por el pedido de César del 11-sep-2026 (que la web
no se lea como servicio para el hogar). Al final, las de consumo (Samsung, LG, Westinghouse): se retiraron
unas horas el 11-sep-2026 y Andrés pidió reponerlas ese mismo día. RETENIDAS queda para una marca que no deba
publicarse: se procesa en retenidas/, que no se copia al sitio, y el verificador falla si aparece en sitio/.
SilverChef, que está en la galería de marcas de Zyro, no entra: es una financiera de equipos, no un
fabricante (fuentes/zyro/descartadas/).
"""
import os, io, json, tempfile
from PIL import Image, ImageChops, ImageDraw, ImageFont, ImageFilter

RAIZ = os.path.dirname(os.path.abspath(__file__))
ORIG = os.path.normpath(os.path.join(RAIZ, "..", "zyro", "logos"))
FINAL = os.environ.get("SALIDA") or RAIZ
HOJA = os.environ.get("HOJA") or os.path.join(tempfile.gettempdir(), "industec-web", "hoja_logos.png")
ALTO = 96
COMPACTO = 1.6  # proporción ancho/alto por debajo de la cual el logo lleva class="compacto" (más alto en la web)

# (slug, nombre = texto alternativo, archivo de origen, modo, relleno relativo[, opciones])
#   alpha  : el original tiene transparencia -> recorte por canal alfa, salida RGBA
#   blanco : fondo blanco opaco -> recorte de blanco, salida RGB (se deja blanco)
#   fondo  : fondo de color opaco (color de la esquina) -> recorte de ese color
#   tinta  : tinta oscura sobre fondo claro opaco -> la tinta pasa a negro con transparencia y el fondo
#            desaparece (queda sobre el blanco de la tarjeta)
# opciones: recorte = (izq, arriba, der, abajo) sobre el original, antes de todo; nota = por qué se tocó;
#           tenir = (r, g, b): el logo es blanco y se tiñe de ese color (el blanco original va a alternativas/).
#           Lo que se publica es SIEMPRE lo de clientes/ y marcas/: el verificador exige copia exacta.
# El nombre es la grafía del propio logo (revisión del 11-sep-2026): Decameron, Naturíssimo, TropiBurger,
# Corporación GPF, Vaco y Vaca. Los archivos de Ali's, El Español e Il Cappo di Mangi no dicen de quién son:
# se identificaron mirando la imagen.
CLIENTES = [
    ("kfc", "KFC", "kfc_logo-Yle6pDoWl4fZgWlq.png", "blanco", 0.0),
    ("american-deli", "American Deli", "logo_american-m5K26pXLqPI4vqn6.png", "alpha", 0.0),
    ("alis", "Ali's Parrilladas & Pizzería", "logo-YD0DMyLBVqCoDwO5.png", "alpha", 0.0),
    ("arrayanes", "Arrayanes", "logoarrayanes-Yg2apOoqDWIoR8ow.png", "alpha", 0.0),
    ("tropi-burger", "TropiBurger", "tropiburguer-logo-d953wW21vkT5wv4Q.png", "alpha", 0.0),
    ("cinnabon", "Cinnabon", "logocinnabon-AGB3eLEne5C6MB2O.png", "alpha", 0.0),
    ("naturissimo", "Naturíssimo", "logo-naturissimo-ALpOx0NeB3cB1oZA.png", "alpha", 0.0),
    ("yanbal", "Yanbal", "logo_yanbal-AzG8pEB32xFkXw33.png", "alpha", 0.0),
    ("el-espanol", "El Español", "logo_header-dJoBaEz5XjTB7VJ7.png", "alpha", 0.0),
    ("gus", "Gus", "loggus-copia1-mk3yp2oqRvtq24qL.png", "alpha", 0.0),
    ("tevcol", "TEVCOL", "tevcol_f269a7e466de3bab4db837ef7f4dcd9c-AR07v5XLZMTpK2o4.jpg", "blanco", 0.06),
    ("gpf", "Corporación GPF", "logo-gpf-dOq75rJ8GGhBnO1Z.png", "alpha", 0.0),
    ("menestras-del-negro", "Menestras del Negro", "menestras_del_negro_edited-YX4XJWol84u6y3Zr.png", "blanco", 0.06,
     dict(recorte=(0, 0, 198, 62), nota="Sin el lema «Como preparado en casa.»: se conservan la carita, el nombre y "
          "la línea de debajo (pedido de César: nada que se lea como servicio para el hogar). NOTAS, A6.")),
    ("juan-valdez-cafe", "Juan Valdez Café", "juan_valdez_cafa-c-YbNZpOkqKKSbgxng.png", "alpha", 0.0),
    ("vaco-y-vaca", "Vaco y Vaca", "logo-v-v-Aq26p1Pq46IRzrw3.jpg", "blanco", 0.0),
    ("el-bodegon", "El Bodegón", "el-bodegon-logo-300x300-m6LDWqljBXTlQ3P1.jpg", "fondo", 0.12),
    ("baskin-robbins", "Baskin Robbins", "baskin_robbins.svg-meP1pOoxXqi4e5v4.png", "alpha", 0.0),
    ("il-cappo-di-mangi", "Il Cappo di Mangi", "descarga-AQE47QwDr9U5295K.jpg", "tinta", 0.0,
     dict(recorte=(0, 0, 144, 110), nota="Sin la franja con la bandera de Italia ni el fondo beige: tinta negra sobre "
          "transparente. El original mide 144 × 144 px: se ve algo blando (NOTAS, A6).")),
    ("san-felipe", "San Felipe", "san-felipemesa-de-trabajo-1ldpi-2-mv04pkZW9EURQP7n.png", "alpha", 0.0,
     dict(tenir=(51, 51, 51), nota="Su único archivo es blanco sobre transparente: en la web va en gris oscuro #333333 "
          "para verse sobre la tarjeta blanca. El blanco original queda en alternativas/san-felipe-blanco.webp.")),
    ("decameron", "Decameron", "decameron-pgn-Y4L8NowDypt901Mn.png", "alpha", 0.0),
]
MARCAS = [
    # 1. Equipo profesional de cocina
    ("frymaster", "Frymaster", "frymaster-mP47Ew4bp6iy31JP.png", "alpha", 0.0),
    ("henny-penny", "Henny Penny", "henny-penny-mk3yp23olliDR9ZB.png", "alpha", 0.0),
    ("dean", "Dean", "deanlogo-YBg8nLgW3MC10nGm.png", "alpha", 0.0),
    ("hobart", "Hobart", "hobart_logo.svg-YyvPpEv1Qlt5BWJO.png", "alpha", 0.0),
    ("garland", "Garland", "garland-logo-mnl6pZlozoFOj5Dl.png", "alpha", 0.0),
    ("taylor", "Taylor", "taylor-logo-mxBlpEBNRNH5GLq7.png", "alpha", 0.0),
    ("manitowoc", "Manitowoc", "manitowoc-logo-mjEQp8Eeakh3oK4v.gif", "alpha", 0.0),
    ("vollrath", "Vollrath", "vollrath-YD0DMy0bVJUQlLkB.png", "alpha", 0.0),
    ("bunn", "Bunn", "bunn_logo-AE0MZ50wBQSylrEG.png", "alpha", 0.0),
    ("vitamix", "Vitamix", "vitamix-logo-A3QPqoQ1eNtZaoZ1.png", "blanco", 0.06),
    # 2. Refrigeración y extracción
    ("copeland", "Copeland", "copeland-logo-AGB3eLBPkQI39G3p.png", "alpha", 0.0),
    ("tecumseh", "Tecumseh", "tecumseh_products_logo.svg-meP1pOPoPLfEDQMl.png", "alpha", 0.0),
    ("danfoss", "Danfoss", "1024px-danfoss.svg-AwvJpEv3ZGs7GaJo.png", "alpha", 0.0),
    ("greenheck", "Greenheck", "greenheck-logo-YyvPpEv1qvhwL9g2.png", "alpha", 0.0),
    ("torrey", "Torrey", "logo_torrey-AzG8pEGBnJFJ4l1E.png", "alpha", 0.0),
    # 3. Al final, las que el público asocia con electrodomésticos. Se retiraron unas horas el 11-sep-2026 y Andrés
    #    pidió reponerlas ese mismo día («para que quede todo completo»).
    ("samsung", "Samsung", "samsung_logo.svg-Yle6pDeB8MC6jrl3.png", "alpha", 0.0),
    ("lg", "LG", "lg_logo.svg-mnl6pZlokyIpLkaJ.png", "alpha", 0.0),
    ("westinghouse", "Westinghouse", "westinghouse_electric_company_logo.svg-YZ97wW9zqDhXl0eD.png", "alpha", 0.0),
]
# Marcas que no deben publicarse (hoy ninguna): se procesan en retenidas/, que no se copia al sitio.
RETENIDAS = []


def abrir(archivo):
    im = Image.open(os.path.join(ORIG, archivo))
    im.seek(0)
    return im, im.format


def mascara_fondo(rgb, bg, tol, abrir_ruido):
    diff = ImageChops.difference(rgb, Image.new("RGB", rgb.size, bg))
    r, g, b = diff.split()
    m = ImageChops.lighter(ImageChops.lighter(r, g), b)
    m = m.point(lambda v: 255 if v > tol else 0)
    if abrir_ruido:  # quita motas sueltas de compresion JPEG
        m = m.filter(ImageFilter.MinFilter(3)).filter(ImageFilter.MaxFilter(3))
    return m


def procesar(slug, nombre, archivo, modo, relleno, recorte=None):
    im, fmt = abrir(archivo)
    es_jpg = fmt == "JPEG"
    if recorte:
        im = im.crop(recorte)
    dx, dy = (recorte[0], recorte[1]) if recorte else (0, 0)
    if modo == "alpha":
        rgba = im.convert("RGBA")
        a = rgba.getchannel("A")
        caja = a.point(lambda v: 255 if v > 8 else 0).getbbox()
        rec = rgba.crop(caja)
        bg = (255, 255, 255, 0)
        salida_modo = "RGBA"
    elif modo == "tinta":
        gris = im.convert("L")
        fondo_l = gris.getpixel((2, 2))
        umbral = 6  # ruido de compresión JPEG en el fondo
        a = gris.point(lambda v: max(0, min(255, round((fondo_l - umbral - v) * 255 / (fondo_l - umbral)))))
        caja = a.point(lambda v: 255 if v > 8 else 0).getbbox()
        tinta = Image.new("RGBA", im.size, (0, 0, 0, 255))
        tinta.putalpha(a)
        rec = tinta.crop(caja)
        bg = (0, 0, 0, 0)
        salida_modo = "RGBA"
    else:
        rgb = im.convert("RGB")
        if modo == "blanco":
            bg = (255, 255, 255)
        else:
            bg = rgb.getpixel((2, 2))
        tol = 36 if es_jpg else 12
        caja = mascara_fondo(rgb, bg, tol, es_jpg).getbbox()
        rec = rgb.crop(caja)
        salida_modo = "RGB"
    cw, ch = rec.size
    p = round(relleno * ch)
    if p:
        lienzo = Image.new(rec.mode, (cw + 2 * p, ch + 2 * p), bg)
        lienzo.paste(rec, (p, p))
        rec = lienzo
    w0, h0 = rec.size
    ancho = max(1, round(w0 * ALTO / h0))
    fin = rec.resize((ancho, ALTO), Image.LANCZOS)
    if fin.mode != salida_modo:
        fin = fin.convert(salida_modo)
    meta = dict(origen=archivo, caja_origen=[caja[0] + dx, caja[1] + dy, caja[2] + dx, caja[3] + dy],
                contenido_origen=[cw, ch], relleno_px_origen=p, factor=round(ALTO / h0, 3), fondo=modo,
                color_fondo=None if modo in ("alpha", "tinta") else list(bg))
    if recorte:
        meta["recorte_origen"] = list(recorte)
    return fin, meta


def guardar_webp(im, ruta):
    b1 = io.BytesIO()
    im.save(b1, "WEBP", lossless=True, quality=100, method=6)
    b2 = io.BytesIO()
    kw = dict(quality=92, method=6)
    if im.mode == "RGBA":
        kw["alpha_quality"] = 100
    im.save(b2, "WEBP", **kw)
    if len(b1.getvalue()) <= 1.4 * len(b2.getvalue()):
        datos, cod = b1.getvalue(), "lossless"
    else:
        datos, cod = b2.getvalue(), "q92"
    with open(ruta, "wb") as f:
        f.write(datos)
    return len(datos), cod


manifiesto = {"alto_px": ALTO, "compacto_si_proporcion_menor_que": COMPACTO, "clientes": [], "marcas": [], "alternativas": [],
              "retenidas": []}
finales = {"clientes": [], "marcas": [], "retenidas": []}
blancos = []  # (slug, nombre, imagen) de los logos blancos que se tiñen
for grupo, lista in (("clientes", CLIENTES), ("marcas", MARCAS), ("retenidas", RETENIDAS)):
    carpeta = os.path.join(FINAL, grupo)
    os.makedirs(carpeta, exist_ok=True)
    hechos = set()
    for entrada in lista:
        slug, nombre, archivo, modo, relleno = entrada[:5]
        opc = entrada[5] if len(entrada) > 5 else {}
        fin, meta = procesar(slug, nombre, archivo, modo, relleno, opc.get("recorte"))
        if opc.get("tenir"):  # logo blanco: se tiñe para la tarjeta blanca; el original va a alternativas/
            blancos.append((slug, nombre, fin))
            tenido = Image.new("RGBA", fin.size, tuple(opc["tenir"]) + (255,))
            tenido.putalpha(fin.getchannel("A"))
            fin = tenido
            meta["tenido"] = "#%02X%02X%02X" % tuple(opc["tenir"])
        ruta = os.path.join(carpeta, slug + ".webp")
        peso, cod = guardar_webp(fin, ruta)
        hechos.add(slug + ".webp")
        # relectura para comprobar que el WebP abre y tiene la medida esperada
        chk = Image.open(ruta)
        assert chk.size == fin.size, (slug, chk.size, fin.size)
        meta.update(nombre=nombre, archivo=f"{grupo}/{slug}.webp", ancho=fin.width, alto=fin.height, bytes=peso,
                    codificacion=cod, modo=chk.mode, compacto=fin.width / fin.height < COMPACTO)
        if opc.get("nota"):
            meta["nota"] = opc["nota"]
        if grupo == "retenidas":
            meta["nota"] = "No se publica: marca de consumo, pendiente de que César confirme que es refrigeración o climatización comercial (NOTAS, A6)."
        manifiesto[grupo].append(meta)
        finales[grupo].append((slug, nombre, fin, meta))
        print(f"{grupo:8s} {slug:20s} {fin.width:4d}x{fin.height} {chk.mode:4s} {peso:6d} B {cod:8s} "
              f"compacto={meta['compacto']!s:5s} contenido_origen={meta['contenido_origen']} factor={meta['factor']}")
    # Sin sobrantes: un logo que salió de la lista (o cambió de nombre) no se queda en la carpeta.
    for f in sorted(os.listdir(carpeta)):
        if f.endswith(".webp") and f not in hechos:
            os.remove(os.path.join(carpeta, f))
            print(f"{grupo:8s} retirado: {f}")

# Originales blancos de los logos teñidos (para un fondo oscuro, si alguna vez hace falta). No se publican.
carpeta_alt = os.path.join(FINAL, "alternativas")
os.makedirs(carpeta_alt, exist_ok=True)
hechos_alt = set()
for slug, nombre, blanco in blancos:
    peso, cod = guardar_webp(blanco, os.path.join(carpeta_alt, f"{slug}-blanco.webp"))
    hechos_alt.add(f"{slug}-blanco.webp")
    manifiesto["alternativas"].append(dict(nombre=f"{nombre} (original blanco, para fondo oscuro)",
                                           archivo=f"alternativas/{slug}-blanco.webp",
                                           ancho=blanco.width, alto=blanco.height, bytes=peso, codificacion=cod))
    print(f"alternativa {slug}-blanco", blanco.size, peso, cod)
for f in sorted(os.listdir(carpeta_alt)):
    if f.endswith(".webp") and f not in hechos_alt:
        os.remove(os.path.join(carpeta_alt, f))
        print("alternativas retirado:", f)

with open(os.path.join(FINAL, "manifiesto.json"), "w", encoding="utf-8") as f:
    json.dump(manifiesto, f, ensure_ascii=False, indent=1)

# ---------------- hoja de contacto ----------------
try:
    F_T = ImageFont.truetype("arialbd.ttf", 22)
    F_N = ImageFont.truetype("arialbd.ttf", 15)
    F_S = ImageFont.truetype("arial.ttf", 12)
except Exception:
    F_T = F_N = F_S = ImageFont.load_default()

ANCHO_HOJA, M, GAP = 1600, 24, 18
ALTO_CELDA = ALTO + 16 + 40


def ajedrez(w, h, t=8, c1=(255, 255, 255), c2=(228, 228, 228)):
    im = Image.new("RGB", (w, h), c1)
    d = ImageDraw.Draw(im)
    for y in range(0, h, t):
        for x in range(0, w, t):
            if (x // t + y // t) % 2:
                d.rectangle([x, y, x + t - 1, y + t - 1], fill=c2)
    return im


def linea2(slug, im):
    extra = "  (fondo azul solo en esta hoja)" if slug.endswith("-blanco") else ""
    return f"{slug}.webp  {im.width}x{im.height}{extra}"


def maquetar(items):
    filas, fila, x = [], [], M
    for it in items:
        slug, nombre, im = it[0], it[1], it[2]
        w = int(max(im.width + 16, 150, F_N.getlength(nombre) + 8, F_S.getlength(linea2(slug, im)) + 8))
        if fila and x + w > ANCHO_HOJA - M:
            filas.append(fila); fila, x = [], M
        fila.append((x, w, it)); x += w + GAP
    if fila:
        filas.append(fila)
    return filas


secciones = [("CLIENTES — Empresas que han confiado en nuestro trabajo", finales["clientes"]),
             ("MARCAS — Marcas de equipos con las que trabajamos", finales["marcas"]),
             ("RETENIDAS (no se publican: NOTAS, A6)", finales["retenidas"]),
             ("ALTERNATIVAS (no se publican)", [(f"{s}-blanco", f"{n} (original blanco)", b, {}) for s, n, b in blancos])]
maq = [(t, maquetar(items)) for t, items in secciones]
alto_total = M + sum(40 + len(f) * (ALTO_CELDA + GAP) for _, f in maq) + M
hoja = Image.new("RGB", (ANCHO_HOJA, alto_total), (246, 246, 246))
d = ImageDraw.Draw(hoja)
y = M
for titulo, filas in maq:
    d.text((M, y), titulo, fill=(72, 83, 126), font=F_T)
    y += 40
    for fila in filas:
        for x, w, (slug, nombre, im, meta) in fila:
            oscuro_fondo = slug.endswith("-blanco")
            if oscuro_fondo:
                celda = Image.new("RGB", (w, ALTO + 16), (72, 83, 126))
            else:
                celda = ajedrez(w, ALTO + 16)
            ox = (w - im.width) // 2
            if im.mode == "RGBA":
                celda.paste(im, (ox, 8), im)
            else:
                celda.paste(im, (ox, 8))
            dc = ImageDraw.Draw(celda)
            dc.rectangle([ox - 1, 7, ox + im.width, 8 + ALTO], outline=(204, 80, 75))
            hoja.paste(celda, (x, y))
            d.rectangle([x, y, x + w - 1, y + ALTO + 15], outline=(200, 200, 200))
            d.text((x + 2, y + ALTO + 20), nombre, fill=(20, 20, 20), font=F_N)
            d.text((x + 2, y + ALTO + 38), linea2(slug, im), fill=(90, 90, 90), font=F_S)
        y += ALTO_CELDA + GAP
os.makedirs(os.path.dirname(HOJA), exist_ok=True)
hoja.save(HOJA)
print("hoja", hoja.size, HOJA)
