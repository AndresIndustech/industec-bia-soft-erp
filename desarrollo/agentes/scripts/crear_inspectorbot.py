# -*- coding: utf-8 -*-
r"""
Fabrica `.venv\Scripts\InspectorBot.exe`: el intérprete de Python de siempre, con
otro nombre, otro icono y otra ficha de versión. Y, si se pide, el acceso
directo que lo arranca con el equipo.

No empaqueta nada, no congela el código y no hay que reconstruirlo cuando cambia
un .py: el .exe ES el intérprete, así que se edita `inspectorbot.py` y el
siguiente arranque ya lleva el cambio.

POR QUE ASI Y NO COPIANDO EL pythonw.exe DEL VENV
El `pythonw.exe` que vive dentro de un venv NO es el intérprete: es un lanzador
que lee `pyvenv.cfg` y arranca el intérprete de verdad COMO PROCESO HIJO. Si se
copia ese, en el Administrador de tareas salen DOS procesos -`InspectorBot.exe`
esperando y `pythonw.exe` con la ventana- y el que manda en la barra de tareas
es el hijo, que se sigue llamando Python. Medido: exactamente eso le pasa hoy al
vigilante, que aparece como dos `python.exe`. Por eso se copia el intérprete
BASE (`sys.base_prefix`) y se lo deja DENTRO de `.venv\Scripts\`: ahí encuentra
el `pyvenv.cfg` un nivel arriba, usa el site-packages del venv, y es un solo
proceso llamado InspectorBot.

Y necesita `python312.dll` al lado: fuera de su carpeta original el .exe ya no
la encuentra, salvo que Python esté en el PATH, y en eso no se puede confiar.

QUE VE EL ADMINISTRADOR DE TAREAS
La lista «Aplicaciones» muestra el NOMBRE DEL ARCHIVO, no el FileDescription:
llamar al archivo `InspectorBot.exe` es lo que hace que diga InspectorBot. El
FileDescription se escribe igual, porque es lo que lee la columna «Descripción»
de la pestaña Detalles y lo que devuelve `Get-Process`. Poniendo los dos, no hay
forma de que en ningún sitio salga «Python».

USO
    .venv\Scripts\python.exe scripts\crear_inspectorbot.py
    .venv\Scripts\python.exe scripts\crear_inspectorbot.py --con-arranque
    .venv\Scripts\python.exe scripts\crear_inspectorbot.py --quitar-arranque
    .venv\Scripts\python.exe scripts\crear_inspectorbot.py --solo-icono
"""
from __future__ import annotations

import argparse
import ctypes
import os
import shutil
import struct
import subprocess
import sys
from ctypes import wintypes
from io import BytesIO
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter

sys.path.insert(0, str(Path(__file__).parent))
from comun import BASE  # noqa: E402

RECURSOS = BASE / "recursos"
ICO = RECURSOS / "InspectorBot.ico"
GUION = BASE / "scripts" / "inspectorbot.py"

CAMPOS = {
    "CompanyName": "INDUSTECH SOLUTIONS S.A.S.",
    "FileDescription": "InspectorBot",
    "FileVersion": "1.0.0.0",
    "InternalName": "InspectorBot",
    "LegalCopyright": "INDUSTECH SOLUTIONS S.A.S.",
    "OriginalFilename": "InspectorBot.exe",
    "ProductName": "InspectorBot - vigilante del buzon (B.IA Soft ERP)",
    "ProductVersion": "1.0.0.0",
}

# ==============================================================================
# 1. El icono
# ==============================================================================
# La paleta de marca de B.IA Soft ERP (app/publico/estilo.css): --marca-osc
# #083a6b de fondo y --accent #0ea5e9 en los ojos. El punto verde de la antena
# es el guiño a «está vivo», que es de lo que va esta aplicación.
MARCA_OSC = (8, 58, 107)
MARCA = (11, 79, 143)
ACENTO = (14, 165, 233)
ACENTO_CLA = (125, 211, 252)
BLANCO = (241, 247, 252)
GRIS = (148, 163, 184)
VERDE = (74, 222, 128)
TINTA = (10, 28, 48)

TAMANOS = [256, 128, 64, 48, 40, 32, 24, 20, 16]
S = 1024


def _lienzo(c1, c2):
    grad = Image.new("RGB", (S, S))
    g = ImageDraw.Draw(grad)
    for y in range(S):
        t = (y / S) ** 0.9
        g.line([(0, y), (S, y)],
               fill=tuple(int(c1[i] + (c2[i] - c1[i]) * t) for i in range(3)))
    mask = Image.new("L", (S, S), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, S - 1, S - 1],
                                           radius=int(S * 0.215), fill=255)
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    img.paste(grad, (0, 0), mask)
    return img


def _grande() -> Image.Image:
    img = _lienzo(MARCA_OSC, (5, 38, 72))
    halo = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    ImageDraw.Draw(halo).ellipse([S * 0.13, S * 0.20, S * 0.87, S * 0.94],
                                 fill=(14, 165, 233, 70))
    img = Image.alpha_composite(img, halo.filter(ImageFilter.GaussianBlur(S * 0.075)))
    d = ImageDraw.Draw(img)
    d.line([(S * 0.5, S * 0.145), (S * 0.5, S * 0.275)], fill=ACENTO, width=int(S * 0.042))
    d.ellipse([S * 0.442, S * 0.082, S * 0.558, S * 0.198], fill=VERDE)
    d.rounded_rectangle([S * 0.165, S * 0.255, S * 0.835, S * 0.79],
                        radius=int(S * 0.175), fill=BLANCO)
    d.rounded_rectangle([S * 0.085, S * 0.425, S * 0.165, S * 0.60],
                        radius=int(S * 0.04), fill=GRIS)
    d.rounded_rectangle([S * 0.835, S * 0.425, S * 0.915, S * 0.60],
                        radius=int(S * 0.04), fill=GRIS)
    d.rounded_rectangle([S * 0.255, S * 0.375, S * 0.745, S * 0.578],
                        radius=int(S * 0.10), fill=TINTA)
    d.ellipse([S * 0.318, S * 0.424, S * 0.428, S * 0.534], fill=ACENTO)
    d.ellipse([S * 0.572, S * 0.424, S * 0.682, S * 0.534], fill=ACENTO)
    d.ellipse([S * 0.336, S * 0.442, S * 0.378, S * 0.484], fill=ACENTO_CLA)
    d.ellipse([S * 0.590, S * 0.442, S * 0.632, S * 0.484], fill=ACENTO_CLA)
    d.rounded_rectangle([S * 0.385, S * 0.655, S * 0.615, S * 0.712],
                        radius=int(S * 0.028), fill=(203, 213, 225))
    return img


def _chico() -> Image.Image:
    """A 16 y 24 px el halo, las orejas y la barbilla son ruido: se pierden al
    reducir y ensucian los bordes. Por debajo de 48 px se dibuja una versión
    más plana y con los ojos más grandes, que a ese tamaño se lee."""
    base = _lienzo(MARCA, (8, 58, 107))
    d = ImageDraw.Draw(base)
    d.line([(S * 0.5, S * 0.13), (S * 0.5, S * 0.27)], fill=ACENTO, width=int(S * 0.055))
    d.ellipse([S * 0.425, S * 0.055, S * 0.575, S * 0.205], fill=VERDE)
    d.rounded_rectangle([S * 0.15, S * 0.25, S * 0.85, S * 0.80],
                        radius=int(S * 0.185), fill=BLANCO)
    d.rounded_rectangle([S * 0.245, S * 0.385, S * 0.755, S * 0.60],
                        radius=int(S * 0.105), fill=TINTA)
    d.ellipse([S * 0.305, S * 0.425, S * 0.435, S * 0.555], fill=ACENTO)
    d.ellipse([S * 0.565, S * 0.425, S * 0.695, S * 0.555], fill=ACENTO)
    return base


def _dib32(img: Image.Image) -> bytes:
    """BITMAPINFOHEADER + BGRA de abajo hacia arriba + máscara AND.

    `biHeight` va al DOBLE del alto real: el formato de icono cuenta el XOR y la
    máscara AND como una sola imagen.
    """
    w, h = img.size
    px = img.convert("RGBA").load()
    xor = bytearray()
    for y in range(h - 1, -1, -1):
        for x in range(w):
            r, g, b, a = px[x, y]
            xor += bytes((b, g, r, a))
    fila = ((w + 31) // 32) * 4
    andm = bytearray()
    for y in range(h - 1, -1, -1):
        bits = bytearray(fila)
        for x in range(w):
            if px[x, y][3] == 0:
                bits[x // 8] |= 0x80 >> (x % 8)
        andm += bits
    cab = struct.pack("<IiiHHIIiiII", 40, w, h * 2, 1, 32, 0,
                      len(xor) + len(andm), 0, 0, 0, 0)
    return bytes(cab + xor + andm)


def entradas_icono():
    """[(ancho, alto, planos, bits, carga)].

    Hasta 128 px van como mapa de bits clásico y solo el de 256 como PNG:
    Pillow guarda TODO como PNG y varias rutas viejas del shell de Windows
    esperan el mapa de bits en los tamaños chicos.
    """
    grande, chico = _grande(), _chico()
    salida = []
    for t in TAMANOS:
        img = (grande if t >= 48 else chico).resize((t, t), Image.LANCZOS)
        if t >= 256:
            b = BytesIO()
            img.save(b, format="PNG")
            carga = b.getvalue()
        else:
            carga = _dib32(img)
        salida.append((t, t, 1, 32, carga))
    return salida


def escribir_ico(destino: Path, ents) -> None:
    desp = 6 + 16 * len(ents)
    dir_, cuerpo = b"", b""
    for w, h, pl, bits, carga in ents:
        dir_ += struct.pack("<BBBBHHII", w & 0xFF, h & 0xFF, 0, 0, pl, bits,
                            len(carga), desp)
        cuerpo += carga
        desp += len(carga)
    destino.parent.mkdir(parents=True, exist_ok=True)
    destino.write_bytes(struct.pack("<HHH", 0, 1, len(ents)) + dir_ + cuerpo)


def leer_ico(ruta: Path):
    d = ruta.read_bytes()
    res, tipo, n = struct.unpack_from("<HHH", d, 0)
    if res or tipo != 1:
        raise ValueError(f"{ruta} no parece un .ico")
    ents = []
    for i in range(n):
        w, h, _, _, pl, bits, tam, off = struct.unpack_from("<BBBBHHII", d, 6 + i * 16)
        carga = d[off:off + tam]
        if carga[:8] == b"\x89PNG\r\n\x1a\n":
            pl2, bits2 = 1, 32
        else:
            pl2, bits2 = struct.unpack_from("<HH", carga, 12)
        ents.append((w, h, pl or pl2, bits or bits2, carga))
    return ents


# ==============================================================================
# 2. Los recursos del .exe
# ==============================================================================
k32 = ctypes.WinDLL("kernel32", use_last_error=True)
k32.BeginUpdateResourceW.argtypes = [wintypes.LPCWSTR, wintypes.BOOL]
k32.BeginUpdateResourceW.restype = wintypes.HANDLE
k32.UpdateResourceW.argtypes = [wintypes.HANDLE, wintypes.LPVOID, wintypes.LPVOID,
                                wintypes.WORD, wintypes.LPVOID, wintypes.DWORD]
k32.UpdateResourceW.restype = wintypes.BOOL
k32.EndUpdateResourceW.argtypes = [wintypes.HANDLE, wintypes.BOOL]
k32.EndUpdateResourceW.restype = wintypes.BOOL

RT_ICON, RT_GROUP_ICON, RT_VERSION = 3, 14, 16
LANG = 0x0409                      # los recursos de CPython vienen en en-US


def _rva2off(secs, rva):
    for vaddr, vsize, raddr, rsize in secs:
        if vaddr <= rva < vaddr + max(vsize, rsize):
            return raddr + (rva - vaddr)
    return None


def listar_recursos(ruta: Path):
    """[(tipo, nombre, lang, tam, offset)] leyendo el .rsrc a mano."""
    d = ruta.read_bytes()
    pe = struct.unpack_from("<I", d, 0x3C)[0]
    if d[pe:pe + 4] != b"PE\0\0":
        raise ValueError(f"{ruta} no es un PE")
    coff = pe + 4
    n_sec, = struct.unpack_from("<H", d, coff + 2)
    tam_opt, = struct.unpack_from("<H", d, coff + 16)
    opt = coff + 20
    pe32p = struct.unpack_from("<H", d, opt)[0] == 0x20B
    res_rva, _ = struct.unpack_from("<II", d, opt + (112 if pe32p else 96) + 16)
    secs = [struct.unpack_from("<IIII", d, opt + tam_opt + i * 40 + 8)
            for i in range(n_sec)]
    secs = [(v, s, r, rs) for s, v, rs, r in secs]
    if not res_rva:
        return []
    base = _rva2off(secs, res_rva)
    salida = []

    def recorrer(off, camino):
        _, _, _, _, n_nom, n_id = struct.unpack_from("<IIHHHH", d, off)
        for i in range(n_nom + n_id):
            nid, hijo = struct.unpack_from("<II", d, off + 16 + i * 8)
            if nid & 0x80000000:
                no = base + (nid & 0x7FFFFFFF)
                ln, = struct.unpack_from("<H", d, no)
                nom = d[no + 2:no + 2 + ln * 2].decode("utf-16-le")
            else:
                nom = nid
            if hijo & 0x80000000:
                recorrer(base + (hijo & 0x7FFFFFFF), camino + [nom])
            else:
                rva, tam, _, _ = struct.unpack_from("<IIII", d, base + hijo)
                salida.append(tuple(camino + [nom]) + (tam, _rva2off(secs, rva)))

    recorrer(base, [])
    return salida


def _res(v):
    if isinstance(v, int):
        return ctypes.c_void_p(v)
    return ctypes.cast(ctypes.c_wchar_p(v), ctypes.c_void_p)


class Actualizacion:
    """Un solo BeginUpdateResource para todos los cambios: si algo revienta se
    descarta entero y el .exe nunca queda a medio escribir."""

    def __init__(self, exe: Path):
        self.exe, self.h = str(exe), None

    def __enter__(self):
        # bDeleteExistingResources=False para que sobreviva el MANIFEST de
        # CPython (asInvoker, longPathAware, Common-Controls 6.0). Borrarlo
        # cambiaría cómo arranca el intérprete.
        self.h = k32.BeginUpdateResourceW(self.exe, False)
        if not self.h:
            raise ctypes.WinError(ctypes.get_last_error())
        return self

    def poner(self, tipo, nombre, datos, lang=LANG):
        buf = ctypes.create_string_buffer(datos, len(datos)) if datos else None
        ok = k32.UpdateResourceW(self.h, _res(tipo), _res(nombre), lang,
                                 ctypes.cast(buf, wintypes.LPVOID) if buf else None,
                                 len(datos) if datos else 0)
        if not ok:
            raise ctypes.WinError(ctypes.get_last_error())

    def __exit__(self, *exc):
        descartar = exc[0] is not None
        ok = k32.EndUpdateResourceW(self.h, descartar)
        if not ok and not descartar:
            raise ctypes.WinError(ctypes.get_last_error())
        return False


def grupo_icono(ents, primer_id=1) -> bytes:
    """GRPICONDIR.

    OJO: no es el ICONDIR del archivo .ico. El último campo deja de ser un
    desplazamiento (DWORD) y pasa a ser el ID del recurso (WORD), o sea 14 bytes
    por entrada y no 16. Esa diferencia de dos bytes es la que rompe la mitad de
    los intentos de poner un icono a mano.
    """
    b = struct.pack("<HHH", 0, 1, len(ents))
    for i, (w, h, pl, bits, carga) in enumerate(ents):
        b += struct.pack("<BBBBHHIH", w & 0xFF, h & 0xFF, 0, 0, pl, bits,
                         len(carga), primer_id + i)
    return b


def _pad4(b: bytes) -> bytes:
    return b + b"\0" * ((-len(b)) % 4)


def _nodo(clave: str, valor, tipo_texto: bool, hijos=()) -> bytes:
    """Nodo de VS_VERSIONINFO: wLength, wValueLength, wType, clave UTF-16 con
    NUL, relleno a 4, valor, relleno, hijos."""
    k = clave.encode("utf-16-le") + b"\0\0"
    if tipo_texto:
        v = (valor.encode("utf-16-le") + b"\0\0") if valor else b""
        vlen = len(v) // 2
    else:
        v = valor or b""
        vlen = len(v)
    cuerpo = _pad4(b"\0" * 6 + k) + v
    for h in hijos:
        cuerpo = _pad4(cuerpo) + h
    return struct.pack("<HHH", len(cuerpo), vlen, 1 if tipo_texto else 0) + cuerpo[6:]


def versioninfo(campos: dict, version=(1, 0, 0, 0), lang=0x0409, cp=0x04B0) -> bytes:
    """Se construye uno nuevo en vez de parchear el que trae Python: cambiar el
    texto de un campo cambia longitudes en tres niveles anidados, y parchear eso
    a mano es justo donde se rompe."""
    ms = (version[0] << 16) | version[1]
    ls = (version[2] << 16) | version[3]
    ffi = struct.pack("<IIIIIIIIIIIIII", 0xFEEF04BD, 0x00010000, ms, ls, ms, ls,
                      0x3F, 0x00, 0x04, 0x00, 0x00, 0x00, 0, 0)
    tabla = _nodo(f"{lang:04X}{cp:04X}", "", True,
                  [_nodo(k, v, True) for k, v in campos.items()])
    sfi = _nodo("StringFileInfo", "", True, [tabla])
    vfi = _nodo("VarFileInfo", "", True,
                [_nodo("Translation", struct.pack("<HH", lang, cp), False)])
    return _nodo("VS_VERSION_INFO", ffi, False, [sfi, vfi])


def marcar(exe: Path, ico: Path, campos: dict) -> int:
    """Borrar un recurso que NO existe devuelve ERROR_INVALID_PARAMETER (87) y
    además INUTILIZA el handle: la siguiente escritura, correcta, falla con
    ERROR_INTERNAL_ERROR (1359) sin decir por qué. Por eso se leen primero los
    iconos que el .exe trae de verdad y solo se borran esos."""
    viejos = [r for r in listar_recursos(exe) if r[0] == RT_ICON]
    ents = leer_ico(ico)
    with Actualizacion(exe) as u:
        for r in viejos:
            u.poner(RT_ICON, r[1], None, r[2])
        for i, e in enumerate(ents, start=1):
            u.poner(RT_ICON, i, e[4])
        u.poner(RT_GROUP_ICON, 1, grupo_icono(ents, 1))
        u.poner(RT_VERSION, 1, versioninfo(campos))
    return len(ents)


# ==============================================================================
# 3. El montaje
# ==============================================================================
def construir(venv: Path | None = None, base: Path | None = None) -> Path:
    venv = Path(venv or sys.prefix)
    base = Path(base or sys.base_prefix)
    if venv == base:
        raise SystemExit("Hay que ejecutarlo con el python del venv, no con el "
                         "del sistema:\n  .venv\\Scripts\\python.exe "
                         "scripts\\crear_inspectorbot.py")

    scripts = venv / "Scripts"
    destino = scripts / "InspectorBot.exe"
    origen = base / "pythonw.exe"          # sin consola: es una app de ventanas
    if not origen.exists():
        raise SystemExit(f"No está el intérprete base: {origen}")

    dll = f"python{sys.version_info.major}{sys.version_info.minor}.dll"
    for nombre in (dll, "vcruntime140.dll", "vcruntime140_1.dll"):
        o = base / nombre
        if o.exists() and not (scripts / nombre).exists():
            shutil.copy2(o, scripts / nombre)
            print(f"  copiada {nombre}")

    if not ICO.exists():
        escribir_ico(ICO, entradas_icono())
        print(f"  generado {ICO.relative_to(BASE)}")

    try:
        shutil.copy2(origen, destino)      # falla claro si está corriendo
    except PermissionError:
        raise SystemExit(f"{destino.name} está en uso. Cierra InspectorBot y "
                         f"vuelve a intentarlo.")
    print(f"  copiado {origen.name} -> {destino.relative_to(BASE.parent.parent)}")

    n = marcar(destino, ICO, CAMPOS)
    print(f"  icono ({n} resoluciones) y ficha de versión escritos")
    return destino


# ==============================================================================
# 4. El arranque con el equipo
# ==============================================================================
# Los identificadores que Windows usa para sus carpetas conocidas. Se consultan
# con SHGetKnownFolderPath y NO se arman a mano a partir de %USERPROFILE%:
# aquí el Escritorio está redirigido a OneDrive y se llama «Desktop» aunque el
# sistema esté en español, así que cualquier ruta adivinada falla. La API
# devuelve la de verdad, esté donde esté y se llame como se llame.
FOLDERID_Desktop = "{B4BFCC3A-DB2C-424C-B029-7FE99A87C641}"
FOLDERID_Startup = "{B97D20BB-F46A-4C97-BA10-5E3608430854}"


def carpeta_conocida(guid: str) -> Path | None:
    class GUID(ctypes.Structure):
        _fields_ = [("d1", wintypes.DWORD), ("d2", wintypes.WORD),
                    ("d3", wintypes.WORD), ("d4", ctypes.c_byte * 8)]

    shell32 = ctypes.WinDLL("shell32", use_last_error=True)
    ole32 = ctypes.WinDLL("ole32", use_last_error=True)
    g = GUID()
    if ole32.CLSIDFromString(ctypes.c_wchar_p(guid), ctypes.byref(g)) != 0:
        return None
    salida = ctypes.c_wchar_p()
    if shell32.SHGetKnownFolderPath(ctypes.byref(g), 0, None,
                                    ctypes.byref(salida)) != 0:
        return None
    try:
        return Path(salida.value)
    finally:
        ole32.CoTaskMemFree(salida)


def carpeta_inicio() -> Path:
    return (carpeta_conocida(FOLDERID_Startup)
            or Path(os.environ["APPDATA"]) /
            "Microsoft/Windows/Start Menu/Programs/Startup")


def acceso_directo(destino: Path, exe: Path) -> None:
    """El .lnk lo crea WScript.Shell, que es lo que hay en Windows sin instalar
    nada. Lleva dentro el directorio de trabajo, que es justo lo que una entrada
    del registro `Run` no puede guardar: sin él las rutas relativas del proyecto
    no resuelven."""
    ps = f"""
$s = (New-Object -ComObject WScript.Shell).CreateShortcut('{destino}')
$s.TargetPath = '{exe}'
$s.Arguments = '"{GUION}"'
$s.WorkingDirectory = '{BASE}'
$s.IconLocation = '{exe},0'
$s.Description = 'InspectorBot - vigila el robot del buzon (B.IA Soft ERP)'
$s.Save()
"""
    r = subprocess.run(["powershell", "-NoProfile", "-NonInteractive", "-Command", ps],
                       capture_output=True, text=True, timeout=60)
    if r.returncode != 0 or not destino.exists():
        raise SystemExit(f"No se pudo crear el acceso directo:\n{r.stderr.strip()}")


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Fabrica InspectorBot.exe con su icono y su nombre propios.")
    ap.add_argument("--con-arranque", action="store_true",
                    help="además, que arranque con el equipo (carpeta de Inicio)")
    ap.add_argument("--con-escritorio", action="store_true",
                    help="además, deja un acceso directo en el Escritorio")
    ap.add_argument("--quitar-arranque", action="store_true",
                    help="quita el arranque automático y no toca nada más")
    ap.add_argument("--solo-icono", action="store_true",
                    help="regenera el .ico y sale (para retocar el diseño)")
    args = ap.parse_args()

    if os.name != "nt":
        raise SystemExit("Esto es específico de Windows.")

    lnk = carpeta_inicio() / "InspectorBot.lnk"

    if args.quitar_arranque:
        if lnk.exists():
            lnk.unlink()
            print(f"Quitado del arranque: {lnk}")
        else:
            print("No estaba en el arranque; nada que quitar.")
        return 0

    if args.solo_icono:
        escribir_ico(ICO, entradas_icono())
        print(f"Icono regenerado: {ICO}  ({ICO.stat().st_size} bytes)")
        return 0

    print("InspectorBot")
    exe = construir()

    if args.con_arranque:
        acceso_directo(lnk, exe)
        print(f"  arranca con el equipo: {lnk}")
        print("  (se apaga desde Administrador de tareas > Inicio, o con "
              "--quitar-arranque)")
    else:
        print("\n  Para que arranque con el equipo: --con-arranque")

    if args.con_escritorio:
        escritorio = carpeta_conocida(FOLDERID_Desktop)
        if escritorio and escritorio.is_dir():
            acceso_directo(escritorio / "InspectorBot.lnk", exe)
            print(f"  acceso directo en el Escritorio: {escritorio}")
        else:
            print("  Windows no devolvió la carpeta del Escritorio; se omite")

    print(f"\nListo. Para abrirlo:\n  \"{exe}\" \"{GUION}\"")
    return 0


if __name__ == "__main__":
    sys.exit(main())
