"""
t2_18_atender_pedidos_copia.py - El enlace: que el Archivo publicado sirva los
PDF que solo viven en la estación.

POR QUE
D2 (PLAN_INDUSTEC.md) dejó el histórico completo de 7.069 órdenes como ÍNDICE
en `ot_archivo` (T2.15/T2.14.4): la fila existe, pero si el PDF no está en
`ordenes_pdf/` del servidor, `ordenes.php` ofrece "Pedir copia" y la petición
queda en `ot_archivo_solicitudes`, con esta nota explícita en el propio plan:
"la petición queda registrada para que la estación la suba" -- pero nada la
sube todavía. Subir los 5 GB completos sigue siendo decisión de Andrés (D2, no
tocada aquí); esto es lo más chico posible que faltaba: atender, una por una,
las copias que alguien de verdad pidió con el botón. Es el "enlace" que pidió
Andrés el 2026-09-13 para que el Archivo cargue tanto lo histórico como lo que
se va reclasificando.

COMPROBADO EN VIVO el 2026-09-13 desde este equipo (solo lectura, sin subir
nada): la conexión SSH a darkviolet funciona y hay 1 solicitud pendiente real
(OT-2503-K146-10354374-CNLJ, origen CORREO, sin `fuente_ruta`) -- ver el cierre
de esta tarea en ESTADO.md para el detalle y por qué esa en particular no se
puede completar todavía.

DE DÓNDE SALE CADA PDF, EN ORDEN (el primero que exista gana)
  1. `SALIDAS IA\\ARCHIVO OTS INDUSTEC\\<ZONA>\\<CADENA>\\<id_industec>.pdf`
     -- lo que deja `t2_18_clasificar_archivo.py`. Es la vía normal: si esa
     tarea ya corrió, el 100% del histórico con PDF real cuelga de ahí.
  2. Una búsqueda por nombre dentro de esa misma carpeta (`cadena` puede venir
     distinto entre `ot_archivo` y el nombre de carpeta que usó
     `t2_4_normalizar_nuevas.py` si el maestro cambió después de clasificar).
  3. `ot_archivo.fuente_ruta` (el catálogo histórico de la estación, D2), si el
     archivo sigue existiendo ahí.
Si ninguna de las tres tiene el PDF -- como la solicitud CORREO de arriba, que
nunca se descargó del buzón -- la solicitud se deja pendiente (nunca se marca
atendida a ciegas) y se informa para que alguien decida: pedirlo al buzón con
`t2_11_informes_ot.py`, o al local.

QUÉ HACE, EN ORDEN
  1. Por SSH (mismo contrato de `hostinger_ssh.py`, nunca toca producción):
     lee `ot_archivo_solicitudes` sin atender, cruzadas con `ot_archivo` para
     zona/cadena/fuente_ruta y para no repetir lo que ya tiene PDF.
  2. Para cada una, ubica el PDF con la cadena de arriba.
  3. Sube por scp a `ordenes_pdf/<id_industec>.pdf` del sitio de PRUEBAS
     (jamás producción: el guardián de `hostinger_ssh.py` lo impediría aunque
     se intentara) y verifica que el tamaño subido cuadre.
  4. Corre `archivo_indexar_cli.php --solo-pdf` UNA vez al final del lote (no
     por archivo) para que `ot_archivo.en_servidor` se actualice.
  5. Marca `atendido_en = NOW()` solo en las solicitudes cuyo PDF quedó subido.

Simula por defecto (igual que la purga y el clasificador). `--limite` (por
defecto 20) tapa un lote inesperadamente grande: esto es para pedidos puntuales
de gente usando el Archivo, no para el volcado masivo de D2.

Uso (en la estación o desde el PC con INDUSTEC_LLAVE_SSH):
    .venv/Scripts/python.exe scripts/t2_18_atender_pedidos_copia.py
    .venv/Scripts/python.exe scripts/t2_18_atender_pedidos_copia.py --ejecutar
    .venv/Scripts/python.exe scripts/t2_18_atender_pedidos_copia.py --ejecutar --limite 5
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import RAIZ, abrir_log  # noqa: E402
from hostinger_ssh import DOCROOT_PRUEBAS, ErrorSsh, scp_subir, sql_remoto, ssh  # noqa: E402

DESTINO_CLASIFICADO = RAIZ / "SALIDAS IA" / "ARCHIVO OTS INDUSTEC"
PATRON_OT = re.compile(r"^OT-\d+-[A-Z0-9]+(?:-\d+)?(?:-D\d+)?-(?:UIO|LARB|CNLJ|OTRA)$")

SQL_PENDIENTES = """
SELECT s.solicitud_id, s.id_industec, a.zona, a.cadena, a.fuente_ruta
  FROM ot_archivo_solicitudes s
  JOIN ot_archivo a ON a.id_industec = s.id_industec
 WHERE s.atendido_en IS NULL AND a.en_servidor = 0
 ORDER BY s.solicitud_id
"""


def _carpeta_cadena(zona: str, cadena: str | None) -> str:
    """Mismo criterio que t2_4_normalizar_nuevas.py: sin cadena, la carpeta es
    'SIN CADENA'. Se replica aquí a propósito -- es una regla de nombres, no de
    negocio, y este script no importa ese módulo para no arrastrar su conexión
    a MariaDB (este script solo habla con Hostinger por SSH)."""
    return (cadena or "SIN CADENA").strip().upper() or "SIN CADENA"


def ubicar_pdf(id_industec: str, zona: str, cadena: str | None, fuente_ruta: str | None) -> Path | None:
    carpeta = _carpeta_cadena(zona, cadena)
    candidato = DESTINO_CLASIFICADO / zona / carpeta / f"{id_industec}.pdf"
    if candidato.is_file():
        return candidato

    if DESTINO_CLASIFICADO.is_dir():
        for hallado in DESTINO_CLASIFICADO.rglob(f"{id_industec}.pdf"):
            return hallado

    if fuente_ruta:
        directo = Path(fuente_ruta)
        if directo.is_file():
            return directo

    return None


def pendientes(env=None) -> list[dict]:
    filas = sql_remoto(SQL_PENDIENTES, env=env)
    out = []
    for f in filas:
        solicitud_id, id_industec, zona, cadena, fuente_ruta = (f + [None] * 5)[:5]
        out.append({
            "solicitud_id": solicitud_id,
            "id_industec": id_industec,
            "zona": zona,
            "cadena": None if cadena == "NULL" else cadena,
            "fuente_ruta": None if fuente_ruta == "NULL" else fuente_ruta,
        })
    return out


def atender(limite: int, ejecutar: bool, log=print) -> dict:
    resumen = {"encontrados": 0, "subidos": 0, "no_encontrados": [], "invalidos": []}
    pend = pendientes()
    # «solicitud» a secas es el registro de repuesto (vocabulario.json): aqui son pedidos de copia.
    log(f"{len(pend)} pedido(s) de copia por atender con PDF ausente del servidor")

    subidas: list[tuple[str, int]] = []   # (solicitud_id, bytes) de lo que sí se subió
    for p in pend[:limite]:
        ot = (p["id_industec"] or "").strip().upper()
        if not PATRON_OT.match(ot):
            resumen["invalidos"].append(ot or "(vacío)")
            log(f"  {p['solicitud_id']}: '{ot}' no tiene forma de OT canónica; se omite")
            continue

        ruta = ubicar_pdf(ot, p["zona"] or "", p["cadena"], p["fuente_ruta"])
        if ruta is None:
            resumen["no_encontrados"].append(ot)
            log(f"  {ot}: NO se encontró el PDF (ni en el clasificado ni en fuente_ruta) — "
                f"queda pendiente, no se marca atendida")
            continue

        resumen["encontrados"] += 1
        log(f"  {ot}: {ruta}")
        if ejecutar:
            try:
                scp_subir(ruta, f"{DOCROOT_PRUEBAS}/ordenes_pdf/{ot}.pdf")
            except ErrorSsh as e:
                log(f"  {ot}: ERROR subiendo — {e}")
                continue
            subidas.append((p["solicitud_id"], ruta.stat().st_size))
            resumen["subidos"] += 1

    if ejecutar and subidas:
        log("reindexando en el servidor (archivo_indexar_cli.php --solo-pdf) ...")
        try:
            salida = ssh(f"cd {DOCROOT_PRUEBAS} && php archivo_indexar_cli.php --solo-pdf")
            log(salida.strip())
        except ErrorSsh as e:
            log(f"ERROR al reindexar — las solicitudes NO se marcan atendidas hasta confirmar: {e}")
            return resumen

        ids = ",".join(str(int(sid)) for sid, _ in subidas)
        sql_remoto(f"UPDATE ot_archivo_solicitudes SET atendido_en = NOW() "
                   f"WHERE solicitud_id IN ({ids}) AND atendido_en IS NULL")
        log(f"{len(subidas)} solicitud(es) marcada(s) atendida(s)")

    return resumen


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Sube a darkviolet los PDF de 'pedir copia' que ya existen en la estación, y reindexa. Simula por defecto.")
    ap.add_argument("--ejecutar", action="store_true", help="sube de verdad y marca atendidas las solicitudes")
    ap.add_argument("--limite", type=int, default=20, help="máximo de solicitudes por corrida (defecto 20)")
    ap.add_argument("--log", action="store_true", help="manda la salida a logs/ en vez de la consola")
    args = ap.parse_args()

    if args.log:
        abrir_log("atender_pedidos_copia")

    try:
        resumen = atender(args.limite, args.ejecutar, log=print)
    except ErrorSsh as e:
        sys.exit(f"NO CONECTA a Hostinger: {e}")

    print(f"\n{resumen['encontrados']} encontrado(s) · {resumen['subidos']} subido(s) · "
          f"{len(resumen['no_encontrados'])} sin PDF en ningún lado · {len(resumen['invalidos'])} inválidos")
    if not args.ejecutar and resumen["encontrados"]:
        print("Simulación: nada se subió. Repite con --ejecutar para subir y marcar atendidas.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
