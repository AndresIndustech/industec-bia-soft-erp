"""
t2_19_subir_pdfs.py - Que el servidor tenga TODOS los PDF de orden: el
histórico y lo nuevo, sin «Pedir copia» (decisión de Andrés, 2026-09-14).

POR QUE
D2 (PLAN_INDUSTEC.md) dejaba los PDF solo en la estación: el Archivo publicado
servía 116 órdenes y para el resto ofrecía «Pedir copia», que alguien tenía que
atender a mano (t2_18_atender_pedidos_copia.py). El 2026-09-14 el buzón mostró
el costo: casos atendidos cuyo informe no se podía abrir (10354415, 10354383,
10351229). Andrés: «Quiero que no sea necesario pedir copia a la estación y se
muestren todos los pdfs de todos los casos y ordenes atendidas y en curso».
Eligió subir TODO el histórico más lo nuevo cada noche, y SOLO desde la
estación: producción sigue siendo de solo lectura y no se copia de un sitio a
otro dentro del servidor.

DE DÓNDE
  RESPALDOS/ORDENES DE TRABAJO/<año>/<módulo>/<ZONA>/<CADENA>/*.pdf   árbol canónico
  RESPALDOS/ORDENES DE TRABAJO/_DEL_BUZON/*.pdf                       informes del correo
Las demás carpetas que empiezan por «_» (cuarentenas, divergentes) NO se suben:
están apartadas a propósito. OTROS CLIENTES tampoco: no es KFC, y va aparte.

QUÉ SE SUBE
Solo nombres que pdf.php sabe servir (Emision::PATRON_OT, replicado abajo: si
cambia uno, cambia el otro). El resto se cuenta y se lista sin subirlo; entre
ellos el patrón con el correlativo al final (OT-Cajun-10280653-CNLJ-023, 161
documentos), que pdf.php todavía no acepta.
El mismo nombre dos veces en la estación con contenido distinto: no se sube
ninguno (I-11, colisión real: a revisión humana). Con el mismo contenido, una vez.
Un nombre que ya está en el servidor con OTRA huella: no se pisa, se reporta.
El mismo informe con dos nombres (OT-2488-K061-... del correo y
OT-2488-K061EC-... del árbol) son dos archivos distintos para pdf.php: suben
los dos, y el buzón los muestra juntos porque los relaciona por aviso.

CÓMO (copiar -> verificar -> recién entonces mover, I-2)
 1. Inventario local con sha256 (siete mil PDF tardan unos minutos).
 2. Inventario del servidor: `find` de ordenes_pdf/ (nombre y tamaño) y la
    huella que ya calculó el indexador (`ot_archivo.sha256`). No se re-hashean
    5 GB en el hosting cada noche. Sin huella en el índice se compara el tamaño.
 3. Lo que falta va en lotes .tar (sin comprimir: el PDF ya viene comprimido)
    de unos --lote-mb, cada uno con su manifiesto sha256 adentro.
 4. El lote se sube a ~/respaldos/subida_pdf/<sello>/ (fuera de la web), se
    desempaca ahí y se verifica contra el manifiesto. Si UNA línea falla, ese
    lote no se mueve.
 5. `mv -n` a ordenes_pdf/ (nunca pisa), se verifica otra vez en el destino, y
    se borra la carpeta temporal del lote (es nuestra, no un original).
 6. Al final, `archivo_indexar_cli.php --solo-pdf` para que el Archivo y el
    buzón los vean (en_servidor = 1, con su huella).

Simula por defecto, como la purga y el clasificador.

Uso (en la estación):
    .venv/Scripts/python.exe scripts/t2_19_subir_pdfs.py                  # simula y resume
    .venv/Scripts/python.exe scripts/t2_19_subir_pdfs.py --ejecutar       # sube lo que falta
    .venv/Scripts/python.exe scripts/t2_19_subir_pdfs.py --ejecutar --limite 500
    .venv/Scripts/python.exe scripts/t2_19_subir_pdfs.py --ejecutar --destino-prueba
        (sube a ~/respaldos/prueba_t2_19 y no toca el Archivo: la prueba de punta a punta)
Código de salida: 0 si todo lo intentado quedó verificado en el servidor; 1 si algo falló.
"""
from __future__ import annotations

import argparse
import io
import json
import re
import shlex
import subprocess
import sys
import tarfile
import tempfile
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import LOGS, RESPALDOS, abrir_log, sello_utc, sha256_de  # noqa: E402
import hostinger_ssh as H  # noqa: E402

ORIGEN = RESPALDOS / "ORDENES DE TRABAJO"
DEL_BUZON = "_DEL_BUZON"
DESTINO = f"{H.DOCROOT_PRUEBAS}/ordenes_pdf"
DESTINO_PRUEBA = "respaldos/prueba_t2_19"
TEMPORAL = "respaldos/subida_pdf"

# Emision::PATRON_OT (nucleo/Emision.php). Si cambia uno, cambia el otro.
PATRON_OT = re.compile(r"^OT-\d{3,5}-[A-Z]{1,2}\d{2,4}(EC)?(-\d{6,10})?(-D\d{1,2})?-(UIO|LARB|CNLJ|OTRA)$")


# --- Inventarios -------------------------------------------------------------------
def inventario_local(origen: Path) -> tuple[dict, dict]:
    """Lo que la estación tiene para subir.

    Devuelve (subir, apartado): subir = {nombre_remoto: {ruta, bytes, sha}};
    apartado = {"fuera": {motivo: [rutas]}, "colisiones": {nombre: [rutas]}}.
    El nombre remoto es el identificador en MAYÚSCULAS + ".pdf": es lo que
    pdf.php busca en ordenes_pdf/.
    """
    fuera: dict[str, list[str]] = defaultdict(list)
    candidatos: dict[str, list[Path]] = defaultdict(list)
    for ruta in sorted(origen.rglob("*")):
        if not ruta.is_file() or ruta.suffix.lower() != ".pdf":
            continue
        rel = ruta.relative_to(origen)
        if rel.parts[0].startswith("_") and rel.parts[0] != DEL_BUZON:
            fuera["carpeta apartada"].append(str(rel))
            continue
        ot = ruta.stem.strip().upper()
        if not PATRON_OT.match(ot):
            fuera["nombre que pdf.php no sirve"].append(str(rel))
            continue
        candidatos[ot + ".pdf"].append(ruta)

    subir, colisiones = {}, {}
    for nombre, rutas in candidatos.items():
        por_huella: dict[str, Path] = {}
        for r in rutas:
            por_huella.setdefault(sha256_de(r), r)
        if len(por_huella) > 1:
            colisiones[nombre] = [str(r) for r in rutas]
            continue
        (sha, r), = por_huella.items()
        subir[nombre] = {"ruta": r, "bytes": r.stat().st_size, "sha": sha}
    return subir, {"fuera": dict(fuera), "colisiones": colisiones}


def inventario_remoto(destino: str) -> dict:
    """nombre -> {bytes, sha|None} de lo que ya está en el servidor."""
    salida = H.ssh(f"mkdir -p \"$HOME\"/{shlex.quote(destino)} && "
                   f"find \"$HOME\"/{shlex.quote(destino)} -maxdepth 1 -type f -name '*.pdf' -printf '%f\\t%s\\n'",
                   timeout=300)
    remoto = {}
    for linea in salida.splitlines():
        if "\t" in linea:
            nombre, tam = linea.rsplit("\t", 1)
            remoto[nombre] = {"bytes": int(tam), "sha": None}
    if destino == DESTINO and remoto:
        # La huella que ya calculó el indexador: comparar sin re-hashear el hosting.
        for fila in H.sql_remoto("SELECT id_industec, sha256 FROM ot_archivo "
                                 "WHERE en_servidor = 1 AND sha256 IS NOT NULL AND sha256 <> ''"):
            if len(fila) >= 2 and (fila[0] + ".pdf") in remoto:
                remoto[fila[0] + ".pdf"]["sha"] = fila[1]
    return remoto


def planificar(local: dict, remoto: dict) -> tuple[list[str], int, list[str]]:
    """(faltan, iguales, divergentes). Divergente = mismo nombre, otro contenido."""
    faltan, iguales, divergentes = [], 0, []
    for nombre, l in local.items():
        r = remoto.get(nombre)
        if r is None:
            faltan.append(nombre)
        elif (r["sha"] is not None and r["sha"] != l["sha"]) or (r["sha"] is None and r["bytes"] != l["bytes"]):
            divergentes.append(nombre)
        else:
            iguales += 1
    return sorted(faltan), iguales, sorted(divergentes)


def lotes(nombres: list[str], local: dict, lote_bytes: int):
    actual, tam = [], 0
    for n in nombres:
        b = local[n]["bytes"]
        if actual and tam + b > lote_bytes:
            yield actual
            actual, tam = [], 0
        actual.append(n)
        tam += b
    if actual:
        yield actual


# --- Transporte ----------------------------------------------------------------------
def scp_relativo(local: Path, remoto: str, timeout: int = 3600) -> None:
    """scp desde la carpeta del archivo, con el nombre suelto: una ruta de Windows
    («C:/...») en la línea de scp se puede leer como «host:ruta»."""
    cmd = (["scp", "-P", str(H.PUERTO)] + H.opciones_base() + [local.name, f"{H.destino()}:{remoto}"])
    r = subprocess.run(cmd, cwd=str(local.parent), capture_output=True, text=True, timeout=timeout)
    if r.returncode != 0:
        raise H.ErrorSsh(f"scp falló ({r.returncode}) subiendo {local.name}: {(r.stderr or '').strip()[:300]}")


def fallidos_de(salida: str) -> list[str]:
    """Los nombres que `sha256sum -c` marcó como FAILED (o que no pudo leer)."""
    return sorted({l.split(":", 1)[0].strip() for l in salida.splitlines()
                   if l.rstrip().endswith(("FAILED", "FAILED open or read"))})


def subir_lote(i: int, nombres: list[str], local: dict, sello: str, destino: str, log) -> tuple[list, list]:
    """Sube, verifica y mueve un lote. Devuelve (verificados, fallidos)."""
    lote = f"lote_{i:03d}"
    carpeta = f"{TEMPORAL}/{sello}/{lote}"
    manifiesto = "".join(f"{local[n]['sha']}  {n}\n" for n in nombres).encode()
    with tempfile.TemporaryDirectory() as tmp:
        tar_local = Path(tmp) / f"{lote}.tar"
        with tarfile.open(tar_local, "w") as tar:
            for n in nombres:
                tar.add(local[n]["ruta"], arcname=n)
            info = tarfile.TarInfo("_MANIFIESTO.sha256")
            info.size = len(manifiesto)
            tar.addfile(info, io.BytesIO(manifiesto))
        H.ssh(f"mkdir -p \"$HOME\"/{carpeta}")
        scp_relativo(tar_local, f"{TEMPORAL}/{sello}/{lote}.tar")

    # 1) Desempacar y verificar ANTES de mover: si algo llegó mal, el lote no se mueve.
    r = H.ssh_crudo(f"cd \"$HOME\"/{carpeta} && tar -xf ../{lote}.tar && sha256sum -c --quiet _MANIFIESTO.sha256",
                    timeout=1800)
    if r.returncode != 0:
        malos = fallidos_de(r.stdout) or nombres
        log(f"  {lote}: la verificación en la carpeta temporal FALLÓ ({len(malos)}); no se mueve nada del lote")
        H.ssh_crudo(f"rm -rf \"$HOME\"/{carpeta} \"$HOME\"/{TEMPORAL}/{sello}/{lote}.tar")
        return [], list(nombres)

    # 2) Mover sin pisar y verificar otra vez donde quedaron.
    r = H.ssh_crudo(f"cd \"$HOME\"/{carpeta} && for f in *.pdf; do mv -n \"$f\" \"$HOME\"/{shlex.quote(destino)}/; done "
                    f"&& cd \"$HOME\"/{shlex.quote(destino)} && sha256sum -c --quiet \"$HOME\"/{carpeta}/_MANIFIESTO.sha256",
                    timeout=1800)
    malos = fallidos_de(r.stdout) if r.returncode != 0 else []
    if r.returncode != 0 and not malos:
        malos = list(nombres)            # falló sin decir cuáles: no se da nada por bueno
    H.ssh_crudo(f"rm -rf \"$HOME\"/{carpeta} \"$HOME\"/{TEMPORAL}/{sello}/{lote}.tar")
    buenos = [n for n in nombres if n not in set(malos)]
    log(f"  {lote}: {len(buenos)} verificados en el destino" + (f", {len(malos)} con otra huella allá" if malos else ""))
    return buenos, malos


# --- Orquestación ----------------------------------------------------------------------
def correr(origen: Path, ejecutar: bool, destino: str, limite: int, lote_mb: float, log=print) -> dict:
    sello = sello_utc()
    if not origen.is_dir():
        raise SystemExit(f"No existe {origen}: esto corre en la estación (o con --origen).")
    log(f"origen : {origen}")
    log(f"destino: {destino}")
    local, apartado = inventario_local(origen)
    remoto = inventario_remoto(destino)
    faltan, iguales, divergentes = planificar(local, remoto)
    if limite > 0:
        faltan = faltan[:limite]
    mb = sum(local[n]["bytes"] for n in faltan) / 1e6

    res = {"sello": sello, "destino": destino, "ejecutado": ejecutar,
           "en_estacion": len(local), "ya_en_servidor": iguales, "faltan": len(faltan),
           "faltan_mb": round(mb, 1), "divergentes": divergentes,
           "colisiones": apartado["colisiones"],
           "fuera": {k: len(v) for k, v in apartado["fuera"].items()},
           "fuera_muestra": {k: v[:5] for k, v in apartado["fuera"].items()},
           "subidos": 0, "fallidos": []}
    log(f"en la estación: {len(local)} · ya en el servidor: {iguales} · faltan: {len(faltan)} ({mb:.1f} MB)")
    for k, v in apartado["fuera"].items():
        log(f"no se suben ({k}): {len(v)} — p. ej. {', '.join(v[:3])}")
    if apartado["colisiones"]:
        log(f"COLISIONES (mismo nombre, otro contenido en la estación; a revisión humana): {len(apartado['colisiones'])}")
    if divergentes:
        log(f"DIVERGENTES (en el servidor con otra huella; no se pisan): {len(divergentes)} — {', '.join(divergentes[:5])}")

    if not ejecutar or not faltan:
        if not ejecutar and faltan:
            log("Simulación: nada se subió. Repite con --ejecutar.")
        return res

    for i, grupo in enumerate(lotes(faltan, local, int(lote_mb * 1e6)), start=1):
        try:
            buenos, malos = subir_lote(i, grupo, local, sello, destino, log)
        except H.ErrorSsh as e:
            log(f"  lote_{i:03d}: ERROR — {e}")
            buenos, malos = [], list(grupo)
        res["subidos"] += len(buenos)
        res["fallidos"] += malos

    if destino == DESTINO and res["subidos"]:
        log("reindexando el Archivo (archivo_indexar_cli.php --solo-pdf) ...")
        try:
            log(H.ssh(f"cd {H.DOCROOT_PRUEBAS} && php archivo_indexar_cli.php --solo-pdf", timeout=3600).strip())
        except H.ErrorSsh as e:
            log(f"ERROR al reindexar: {e}")
            res["fallidos"].append("(reindexar)")
    log(f"subidos y verificados: {res['subidos']} · fallidos: {len(res['fallidos'])}")
    return res


def main() -> int:
    ap = argparse.ArgumentParser(description="Sube a darkviolet los PDF de orden que faltan, verificados por hash. Simula por defecto.")
    ap.add_argument("--ejecutar", action="store_true", help="sube de verdad")
    ap.add_argument("--limite", type=int, default=0, help="máximo de archivos por corrida (0 = todos)")
    ap.add_argument("--lote-mb", type=float, default=200.0, help="tamaño aproximado de cada lote (defecto 200 MB)")
    ap.add_argument("--destino-prueba", action="store_true", help=f"sube a ~/{DESTINO_PRUEBA} en vez de ordenes_pdf/")
    ap.add_argument("--origen", type=Path, default=ORIGEN, help="carpeta de órdenes (defecto: RESPALDOS/ORDENES DE TRABAJO)")
    ap.add_argument("--log", action="store_true", help="manda la salida a logs/ en vez de la consola")
    args = ap.parse_args()
    if args.log:
        abrir_log("subir_pdfs")
    try:
        res = correr(args.origen, args.ejecutar, DESTINO_PRUEBA if args.destino_prueba else DESTINO,
                     args.limite, args.lote_mb)
    except H.ErrorSsh as e:
        sys.exit(f"NO CONECTA a Hostinger: {e}")
    LOGS.mkdir(parents=True, exist_ok=True)
    (LOGS / f"subir_pdfs-{res['sello']}.json").write_text(json.dumps(res, ensure_ascii=False, indent=1), encoding="utf-8")
    return 1 if res["fallidos"] else 0


if __name__ == "__main__":
    sys.exit(main())
