"""verificar_archivo_pdf.py — T2.28.17b: que el Archivo (ot_archivo) sea de
verdad accesible POR LA WEB, no solo íntegro en disco (eso ya lo comprueba
archivo_verificar_cli.php, T2.28.17a, por SSH y sin pasar por sesión ninguna).

QUÉ HACE
  1. Arma una muestra ESTRATIFICADA de 300 órdenes con `en_servidor = 1`
     (origen × zona × año), reproducible (semilla fija).
  2. Por cada una: `GET pdf.php?ot=…`
       - con sesión ADMIN            -> 200, Content-Type application/pdf,
                                         cuerpo que empieza por `%PDF-`
       - con sesión TECNICO          -> lo mismo. D1 (2026-09-12): el Archivo
         es de lectura para los CUATRO roles (`ots.archivo`, confirmado en
         `rol_permisos` el 2026-09-23), así que un técnico entra a cualquier
         orden, no solo a las suyas -- no hay un segundo caso que probar.
       - sin sesión                  -> 302 o 401
  3. Error nº 16: localiza por grep, CONTRA LO DESPLEGADO (no el working tree
     local, que puede tener cambios de otro carril sin subir), los sitios que
     arman `pdf.php?ot=` en app.js/casos.php/mis.php/ordenes.php, y comprueba
     que cada uno tenga alguna guarda de existencia (`existePdf(`, `en_servidor`,
     etc.) en las 25 líneas de arriba.

NO ES 100 % AUTOMÁTICO EL PASO 3: es un grep + una búsqueda de patrones
conocidos, no un parser de PHP. Sirve para notar si alguien agrega un décimo
sitio sin guarda, no para demostrar la ausencia de cualquier guarda posible.

SOLO LECTURA sobre `ot_archivo` (ni una escritura). Usa las cuentas del arnés
(T2.28.1): requiere haber corrido antes ~/respaldos/preparar_prueba.php.

Uso:  python verificar_archivo_pdf.py     (misma llave SSH que verificar_http.py)
Sale con 1 si algo falla.
"""
import json
import random
import sys
import urllib.error
import urllib.request
from collections import defaultdict

from verificar_http import BASE, D, SALIDA, Sesion, anotar, resultados, sql, ssh

# En Windows la consola es cp1252 y la flecha «→» de los mensajes reventaba la
# prueba antes de la primera comprobación (AUDITORIA_2026-09-12, P-07).
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

MUESTRA = 300
# Fija a propósito: la misma muestra en cada corrida, para poder comparar un
# «300/300» de hoy con el de la próxima vez sin que el azar mueva el terreno.
SEMILLA = 20280917


def binario_inicio(sesion, ruta, n=5):
    """GET sin descargar el PDF entero: status, Content-Type y los primeros
    `n` bytes del cuerpo. Con 300 órdenes × 2 sesiones autenticadas, bajar el
    PDF completo habría significado cientos de MB por corrida para comprobar
    5 bytes (`%PDF-`); esto pide lo mismo y corta la conexión apenas los tiene."""
    req = urllib.request.Request(BASE + ruta, headers={"User-Agent": "verificar_archivo_pdf/1.0"})
    try:
        r = sesion.op.open(req, timeout=30)
        tipo = r.headers.get("Content-Type", "")
        inicio = r.read(n)
        r.close()
        return r.status, tipo, inicio
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get("Content-Type", ""), e.read(n)


def muestra_estratificada(filas, n, semilla):
    """Reparte `n` proporcional al tamaño de cada estrato (origen, zona, año),
    con al menos 1 de cada estrato no vacío y sin superar su tamaño. La
    diferencia que deja el redondeo se ajusta desde los estratos más grandes,
    así que la suma siempre da exactamente `n`. Reproducible con `semilla`."""
    rnd = random.Random(semilla)
    por_estrato = defaultdict(list)
    for f in filas:
        # `Db::todos` (PDO, sin ATTR_STRINGIFY_FETCHES) devuelve YEAR(...) como
        # int de PHP cuando no es NULL, y como null si lo es: sin forzar los
        # dos casos a str, la clave mezclaba int y str y sorted() reventaba
        # comparando una tupla contra otra (visto al correr esto de verdad).
        anio = str(f["anio"]) if f["anio"] is not None else "SIN_FECHA"
        clave = (f["origen"], f["zona"], anio)
        por_estrato[clave].append(f["id_industec"])
    total = sum(len(v) for v in por_estrato.values())
    claves = sorted(por_estrato)
    cuota = {k: min(len(por_estrato[k]), max(1, round(n * len(por_estrato[k]) / total))) for k in claves}
    diferencia = n - sum(cuota.values())
    orden = sorted(claves, key=lambda k: -len(por_estrato[k]))
    i, tope = 0, 50 * len(orden) + 1000
    while diferencia != 0 and orden and i < tope:
        k = orden[i % len(orden)]
        if diferencia > 0 and cuota[k] < len(por_estrato[k]):
            cuota[k] += 1
            diferencia -= 1
        elif diferencia < 0 and cuota[k] > 1:
            cuota[k] -= 1
            diferencia += 1
        i += 1
    elegidos = []
    for k in claves:
        elegidos += [(k, ot) for ot in rnd.sample(por_estrato[k], cuota[k])]
    rnd.shuffle(elegidos)  # que no queden agrupadas por estrato al imprimir
    return elegidos, cuota, total


def main():
    claves = json.loads(ssh("cat ~/respaldos/claves_prueba.json"))
    s_admin = Sesion("admin_prueba")
    s_tec = Sesion("tec_prueba_uio_a")
    for s, u in ((s_admin, "admin_prueba"), (s_tec, "tec_prueba_uio_a")):
        st, _ = s.entrar(claves["claves"][u])
        anotar("T2.28.17b", f"{u} entra", st == 302, st)
    s_anon = Sesion("sin_sesion")  # a propósito nunca llama a .entrar(): sin cookie de sesión

    print(f"\n== muestra estratificada de {MUESTRA} órdenes con PDF en el servidor (origen × zona × año) ==")
    filas = sql("SELECT id_industec, origen, zona, YEAR(fecha_atencion) anio FROM ot_archivo WHERE en_servidor = 1")
    n_pedido = min(MUESTRA, len(filas))
    anotar("T2.28.17b", f"hay al menos {MUESTRA} filas en_servidor=1 para muestrear", len(filas) >= MUESTRA, len(filas))
    elegidos, cuota, total = muestra_estratificada(filas, n_pedido, SEMILLA)

    origenes = sorted({k[0] for k in cuota})
    print(f"  pool: {total} filas en_servidor=1 · {len(cuota)} estratos (origen, zona, año) · origen(es): {origenes}")
    if set(origenes) != {"HISTORICO", "CORREO", "APP"}:
        # I-7: se dice lo que hay, no lo que el diseño esperaba encontrar.
        print(f"  AVISO (I-7): T2_28_OBSERVACIONES_INDUSTEC.md pide estratificar por HISTORICO/CORREO/APP; "
              f"hoy `ot_archivo` en darkviolet solo tiene origen={origenes} con en_servidor=1 "
              "(el volcado del árbol canónico -HISTORICO- y el de la app -APP- a esta tabla del sitio de "
              "pruebas todavía no corrió: son T2.28.17a/17c, de otro carril). La muestra de abajo estratifica "
              "por lo que de verdad hay; no se inventa una fila de un origen que no está indexado.")
    print(f"  muestra final: {sum(cuota.values())} (semilla {SEMILLA}, reproducible)")

    print("\n== por cada una: ADMIN, TECNICO y sin sesión ==")
    fallos_admin = fallos_tec = fallos_anon = 0
    for (origen, zona, anio), ot in elegidos:
        etiqueta = f"{ot} [{origen}/{zona}/{anio}]"

        st, tipo, cuerpo = binario_inicio(s_admin, f"pdf.php?ot={ot}")
        ok = st == 200 and tipo.startswith("application/pdf") and cuerpo.startswith(b"%PDF-")
        fallos_admin += 0 if ok else 1
        anotar("T2.28.17b", f"ADMIN pdf.php?ot={etiqueta}", ok, f"{st} · {tipo} · {cuerpo!r}")

        st, tipo, cuerpo = binario_inicio(s_tec, f"pdf.php?ot={ot}")
        ok = st == 200 and tipo.startswith("application/pdf") and cuerpo.startswith(b"%PDF-")
        fallos_tec += 0 if ok else 1
        anotar("T2.28.17b", f"TECNICO pdf.php?ot={etiqueta} (D1: lectura de las 4 zonas)", ok, f"{st} · {tipo} · {cuerpo!r}")

        st, _, _ = binario_inicio(s_anon, f"pdf.php?ot={ot}")
        ok = st in (302, 401)
        fallos_anon += 0 if ok else 1
        anotar("T2.28.17b", f"SIN SESION pdf.php?ot={ot}", ok, st)

    n = len(elegidos)
    print(f"\n  ADMIN {n - fallos_admin}/{n} · TECNICO {n - fallos_tec}/{n} · SIN SESION {n - fallos_anon}/{n}")

    print("\n== error nº 16 · los sitios que arman pdf.php?ot=, contra lo DESPLEGADO en darkviolet ==")
    ARCHIVOS = ["app.js", "casos.php", "mis.php", "ordenes.php"]
    salida_grep = ssh(f"cd {D} && grep -n 'pdf.php?ot=' " + " ".join(ARCHIVOS))
    sitios = [l for l in salida_grep.splitlines() if l.strip()]
    por_archivo = defaultdict(int)
    for l in sitios:
        por_archivo[l.split(":", 1)[0]] += 1
    anotar("T2.28.17b", "son exactamente 9 sitios (app.js·1, casos.php·2, mis.php·4, ordenes.php·2)",
           len(sitios) == 9 and dict(por_archivo) == {"app.js": 1, "casos.php": 2, "mis.php": 4, "ordenes.php": 2},
           dict(por_archivo))

    # Patrones que ya se confirmaron a mano el 2026-09-23/24 sobre estos 9
    # sitios: `existePdf(`/`en_servidor` en casos.php/mis.php/ordenes.php, y
    # `emitida` en app.js (ese último no es Emision::existePdf(): el recibo
    # solo ofrece «Ver» cuando el servidor acaba de contestar que la orden
    # quedó EMITIDA -y por lo tanto el PDF ya se escribió en la misma
    # petición-, una garantía distinta pero igual de firme para ese caso
    # puntual; se anota así y no se hace pasar por lo mismo, I-7).
    GUARDAS = ("existePdf(", "['pdf']", "en_servidor", "aqui)", "emitida")
    for linea in sitios:
        archivo, num, _ = linea.split(":", 2)
        ventana = ssh(f"cd {D} && sed -n '{max(1, int(num) - 25)},{num}p' {archivo}")
        guarda = next((g for g in GUARDAS if g in ventana), None)
        anotar("T2.28.17b", f"{archivo}:{num} ofrece «Ver»/el enlace solo si el PDF existe",
               guarda is not None, guarda or "SIN GUARDA CONOCIDA EN LAS 25 LINEAS DE ARRIBA -- revisar a mano")

    salida_bp = ssh(f"cd {D} && grep -l 'pedirCopia' " + " ".join(ARCHIVOS) + " ; true")
    con_pedir_copia = [l.strip() for l in salida_bp.splitlines() if l.strip()]
    anotar("T2.28.17b", "ordenes.php ofrece el botón «Pedir copia» cuando el PDF no está "
           "(casos.php, mis.php y app.js solo condicionan «Ver»/el enlace, sin un botón alterno)",
           con_pedir_copia == ["ordenes.php"], con_pedir_copia)

    for se in (s_admin, s_tec):
        se.pedir("salir.php")
    (SALIDA / "resultado_archivo_pdf.json").write_text(json.dumps(resultados, ensure_ascii=False, indent=1), encoding="utf-8")
    fallas = [r for r in resultados if not r["ok"]]
    print(f"\n{len(resultados) - len(fallas)} de {len(resultados)} comprobaciones pasan; {len(fallas)} fallan")
    return 1 if fallas else 0


if __name__ == "__main__":
    sys.exit(main())
