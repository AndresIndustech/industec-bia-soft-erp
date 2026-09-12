#!/bin/sh
# Renderiza la ilustración de la portada para revisarla, sin publicar nada:
#   1) corre herramientas/gen_ilustracion.py, que escribe sitio/assets/img/portada-cocina-cadena-frontal.svg;
#   2) la captura a 2x con Edge sin ventana, con un perfil temporal NUEVO en cada corrida (puede haber otras
#      capturas usando Edge en el mismo equipo);
#   3) deja en la carpeta de salida:
#        ilustracion-2x.png     1424 x 1072, el SVG entero
#        ilustracion-492.png    a su tamaño en la portada de escritorio (492 px de ancho)
#        ilustracion-movil.png  el recorte 16:10 del celular (object-fit: cover; object-position: 50% 25%,
#                               como .portada__foto img en estilos.css), a 700 px de ancho
#
# Uso (desde web_corporativa/):  sh herramientas/render.sh [carpeta_de_salida] [--sin-insignia]
#   Sin carpeta, usa la temporal del sistema (industec-web/ilustracion).
#   --sin-insignia: no toca el sitio; genera en la carpeta de salida la variante sin la insignia redonda de la
#   llave (variante-sin-insignia.svg) y renderiza esa.
#   Requiere Python con Pillow, y Edge (otra ruta con la variable EDGE).
set -e
cd "$(dirname "$0")/.."
WEB="$(pwd -W 2>/dev/null || pwd)"
SALIDA=""
VARIANTE=""
for x in "$@"; do
  case "$x" in
    --sin-insignia) VARIANTE=1 ;;
    --*) echo "Opción desconocida: $x" >&2; exit 2 ;;
    *) SALIDA="$x" ;;
  esac
done
[ -n "$SALIDA" ] || SALIDA="${TMPDIR:-${TEMP:-/tmp}}/industec-web/ilustracion"
mkdir -p "$SALIDA"
SALIDA="$(cd "$SALIDA" && (pwd -W 2>/dev/null || pwd))"

if [ -n "$VARIANTE" ]; then
  SVG="$SALIDA/variante-sin-insignia.svg"
  python herramientas/gen_ilustracion.py "$SVG" --sin-insignia
else
  SVG="$WEB/sitio/assets/img/portada-cocina-cadena-frontal.svg"
  python herramientas/gen_ilustracion.py
fi

EDGE="${EDGE:-C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe}"
URL="$(python -c "import pathlib, sys; print(pathlib.Path(sys.argv[1]).resolve().as_uri())" "$SVG")"
PERFIL="$SALIDA/perfil-edge-$$"
PNG="$SALIDA/ilustracion-2x.png"
rm -rf "$PERFIL" "$PNG"
"$EDGE" --headless=new --disable-gpu --hide-scrollbars --no-first-run --no-default-browser-check \
  --user-data-dir="$PERFIL" --force-device-scale-factor=2 --window-size=712,536 \
  --screenshot="$PNG" "$URL" >/dev/null 2>&1 || true
i=0
while [ ! -s "$PNG" ] && [ $i -lt 40 ]; do sleep 1; i=$((i + 1)); done
[ -s "$PNG" ] || { echo "Edge no produjo la captura" >&2; exit 1; }
sleep 1
i=0
while [ -d "$PERFIL" ] && [ $i -lt 20 ]; do rm -rf "$PERFIL" 2>/dev/null || sleep 1; i=$((i + 1)); done

python - "$PNG" "$SALIDA" <<'EOF'
import sys
from PIL import Image
png, carpeta = sys.argv[1], sys.argv[2]
im = Image.open(png).convert("RGB")
w, h = im.size
assert (w, h) == (1424, 1072), f"la captura mide {w} x {h}, no 1424 x 1072"
alto = round(w / 1.6)
y0 = round(0.25 * (h - alto))
im.crop((0, y0, w, y0 + alto)).resize((700, round(700 / 1.6)), Image.LANCZOS).save(f"{carpeta}/ilustracion-movil.png")
im.resize((492, round(492 * h / w)), Image.LANCZOS).save(f"{carpeta}/ilustracion-492.png")
print(f"{carpeta}: ilustracion-2x.png ({w} x {h}), ilustracion-492.png, ilustracion-movil.png (recorte y {y0}-{y0 + alto} de {h})")
EOF
