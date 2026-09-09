"""
Comprueba si los PDFs y logs del sistema de OTs siguen siendo publicos.

SOLO LECTURA Y SOLO CABECERAS. Usa HEAD, nunca GET: interesa el codigo de
respuesta, no el contenido. Descargar el PDF seria traerse los datos personales
que justamente se quieren proteger.

Se corre ANTES y DESPUES de subir el .htaccess:

    .venv/Scripts/python.exe scripts/verificar_exposicion.py

ANTES  se espera 200 en los PDFs -> la exposicion existe.
DESPUES se espera 403 en todo    -> quedo cerrada.

Sale con codigo 1 si algo sigue expuesto, para poder encadenarlo.
"""

import random
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "https://yellow-elephant-166233.hostingersite.com"
RAIZ = "/ot/produccion"

# Rutas verificadas el 2026-09-08. El sistema se movio de ot/pruebas a
# ot/produccion: las rutas viejas dan 404 y comprobarlas daria un falso "cerrado".
OBJETIVOS = [
    ("PDF con firma, UIO",   f"{RAIZ}/ot_normal_v3/uio/uploads/OT-1427-G025-10334255-UIO.pdf"),
    ("PDF con firma, LARB",  f"{RAIZ}/ot_normal_v3/larb/uploads/OT-1629-K080-10332528-LARB.pdf"),
    ("PDF con firma, CNLJ",  f"{RAIZ}/ot_normal_v3/cnlj/uploads/OT-1688-K069-10330081-CNLJ.pdf"),
    ("PDF preventivo",       f"{RAIZ}/ot_mantenimiento/uploads/OT-0131-K073-10319745-Dia 3-CNLJ.pdf"),
    ("Log de correos",       f"{RAIZ}/ot_mantenimiento/registros/mail_normal.log"),
    ("Log de errores",       f"{RAIZ}/ot_normal_v3/uio/registros/error_normal.log"),
    ("Contador UIO",         f"{RAIZ}/ot_normal_v3/uio/contadores/counter_UIO.txt"),
    ("Listado de uploads",   f"{RAIZ}/ot_normal_v3/uio/uploads/"),
]

# Esto NO se debe romper: es lo que usa el tecnico.
NO_ROMPER = [
    ("Formulario UIO",        f"{RAIZ}/ot_normal_v3/uio/ot_uio.html"),
    ("Formulario preventivo", f"{RAIZ}/ot_mantenimiento/ot_mant_uio.html"),
]


def estado(ruta, romper_cache=False):
    """Codigo HTTP de una ruta. Solo HEAD, nunca descarga el contenido.

    `romper_cache` agrega un parametro aleatorio para saltarse el CDN y ver que
    responde el ORIGEN. Los dos casos importan y miden cosas distintas:

      sin romper cache -> lo que recibe de verdad quien pega la URL
      rompiendo cache  -> si la regla del .htaccess esta bien puesta

    Hace falta separarlos porque el 2026-09-08 pasó esto: el .htaccess ya
    bloqueaba en el origen (403 con cache-buster) y el CDN de Hostinger seguia
    sirviendo los PDFs cacheados con 200 y `Age: 974`. Medir solo con
    cache-buster habria dado un "todo cerrado" que era falso.
    """
    if romper_cache:
        ruta = f"{ruta}{'&' if '?' in ruta else '?'}nc={random.randint(10**8, 10**9)}"
    url = BASE + urllib.parse.quote(ruta, safe="/?=&")
    pedido = urllib.request.Request(url, method="HEAD")
    try:
        with urllib.request.urlopen(pedido, timeout=15) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code
    except Exception as e:
        return f"error: {e}"


def main():
    print(f"Comprobando {BASE}{RAIZ}\n")

    print(f"{'CDN':>5} {'ORIGEN':>7}   estado")
    print("-" * 66)
    expuestos, solo_cache = [], []
    for nombre, ruta in OBJETIVOS:
        c_cdn = estado(ruta)
        c_org = estado(ruta, romper_cache=True)
        if c_cdn == 200 and c_org == 200:
            marca, _ = "EXPUESTO", expuestos.append(nombre)
        elif c_cdn == 200:
            # El origen ya bloquea, pero el CDN sigue sirviendo lo que cacheo.
            marca, _ = "EN CACHE", solo_cache.append(nombre)
        else:
            marca = "cerrado"
        print(f"  {str(c_cdn):<5} {str(c_org):<7} {marca:<9} {nombre}")

    print("\nESTO TIENE QUE SEGUIR FUNCIONANDO (200)")
    print("-" * 66)
    rotos = []
    for nombre, ruta in NO_ROMPER:
        c = estado(ruta)
        if c != 200:
            rotos.append(nombre)
        print(f"  {str(c):<7} {'ok' if c == 200 else 'ROTO':<9} {nombre}")

    print()
    if rotos:
        print(f"ALERTA: el parche rompio {len(rotos)} cosa(s) que el tecnico usa: "
              f"{', '.join(rotos)}")
        print("Quitar el .htaccess de inmediato y revisar.")
        sys.exit(1)
    if expuestos:
        print(f"EXPUESTOS EN EL ORIGEN ({len(expuestos)}): {', '.join(expuestos)}")
        print("El .htaccess no esta bloqueando. Revisar que este en ot/produccion/.")
        sys.exit(1)
    if solo_cache:
        print(f"BLOQUEADOS EN EL ORIGEN, PERO EL CDN LOS SIGUE SIRVIENDO "
              f"({len(solo_cache)}): {', '.join(solo_cache)}")
        print("Falta vaciar la cache: hPanel -> el sitio -> Rendimiento/Cache -> "
              "Purgar. Hasta entonces la exposicion sigue abierta para quien "
              "tenga la URL.")
        sys.exit(1)
    print("Todo cerrado en origen y en CDN, y el formulario del tecnico sigue en pie.")


if __name__ == "__main__":
    main()
