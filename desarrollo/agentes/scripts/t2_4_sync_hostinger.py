"""
T2.4.1 / T2.15.2 - Espejo verificado de lo que produce Hostinger: el sistema viejo y el nuevo.

Trae a disco local, SIN MODIFICAR NADA EN EL SERVIDOR, todos los PDFs que el
sistema de OTs en produccion va generando (los cinco modulos del sistema viejo)
y, desde T2.15.2, tambien lo que emite la app nueva del sitio de pruebas:
`ordenes_pdf/` y `ordenes_fotos/`. Es la unica pieza que toca el hosting para
leer; la purga (t2_4_purga_hostinger.py) es un script aparte y depende del
manifiesto que este deja escrito.

POR QUE ASI:
- El sistema viejo no escribe en ninguna base: el PDF y el correo son el unico
  registro que existe de una OT. Y uploads/ solo conserva ~3 meses. Cada dia sin
  espejo local es informacion que se pierde sola.
- I-4 manda: copiar -> verificar por hash -> recien entonces mover o borrar.
  Aqui se copia y se verifica. Nada mas.
- El destino es un ESPEJO CRUDO, hermano de _ORIGEN_DRIVE: los nombres llegan
  tal cual los emitio el servidor. La normalizacion canonica es
  t2_4_normalizar_nuevas.py.
- UN PDF DIVERGENTE NO SOBREESCRIBE EL ESPEJO (T2.15.3). Si el servidor tiene
  bajo el mismo nombre un contenido distinto del que ya teniamos (la condicion
  de carrera del contador del sistema viejo lo hace), el nuevo va a
  `_divergentes/<nombre>.<sha12>.pdf` y al manifiesto. Lo que ya estaba
  verificado no se toca: son dos ordenes distintas con el mismo numero, y las
  dos hay que conservarlas.

ACCESO: todo por `hostinger_ssh.py` (T2.15.1): una llave por equipo, el sitio
de produccion solo para leer. Ya no hacen falta las claves HOSTINGER_* del .env.

TRANSPORTE: sftp del OpenSSH de Windows en modo lote (-b). Una sola conexion
para todo el lote; scp por archivo abriria una sesion SSH por PDF.

CODIGO DE SALIDA: 0 si todo se bajo y verifico; 1 si un modulo entero fallo, si
hubo archivos que no pasaron el hash, o si un modulo que tenia archivos en el
espejo aparece con 0 en el servidor (algo se movio: hay que mirar antes de que
la purga o la ingesta trabajen sobre un espejo mentiroso).

USO:
    .venv/Scripts/python.exe scripts/t2_4_sync_hostinger.py               # sincroniza todo
    .venv/Scripts/python.exe scripts/t2_4_sync_hostinger.py --inventario  # solo mira, no baja
    .venv/Scripts/python.exe scripts/t2_4_sync_hostinger.py --modulo uio --modulo app_pdf
"""
from __future__ import annotations

import argparse
import csv
import json
import shlex
import sys
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import RESPALDOS, leer_env, sha256_de  # noqa: E402
import hostinger_ssh as H  # noqa: E402

# Espejo crudo del sistema viejo, hermano de _ORIGEN_DRIVE. Nunca se modifica a
# mano ni se renombra: es la prueba de que lo que bajamos es identico a lo que
# el servidor tenia.
DESTINO = RESPALDOS / "_ORIGEN_SISTEMA"
# Espejo de lo que emite la app nueva (sitio de pruebas hoy; produccion al corte).
DESTINO_APP = RESPALDOS / "_ORIGEN_APP"
MANIFIESTOS = DESTINO / "_manifiestos"
CUARENTENA = DESTINO / "_cuarentena_hash"

# El docroot del sistema viejo, relativo al home. SOLO LECTURA: `hostinger_ssh`
# rechaza cualquier comando que lo nombre y parezca escribir.
DOCROOT_VIEJO = f"domains/{H.SITIO_PRODUCCION}.hostingersite.com/public_html"

# Los 5 modulos del sistema viejo, con su ruta relativa al docroot viejo.
#
# OJO CON ESTAS RUTAS: comprobado por HTTP el 2026-09-06: todo se movio a
# ot/produccion/. Si un modulo empieza a fallar con "exit 7", lo primero que
# hay que sospechar es que volvieron a moverlo.
MODULOS = {
    "uio":   "ot/produccion/ot_normal_v3/uio/uploads",
    "larb":  "ot/produccion/ot_normal_v3/larb/uploads",
    "cnlj":  "ot/produccion/ot_normal_v3/cnlj/uploads",
    "mant":  "ot/produccion/ot_mantenimiento/uploads",
    "otros": "ot/produccion/ot_normal_otros/uploads",
}

# Lo que emite la app nueva: (ruta relativa al home, patron, profundidad).
# Las fotos van en subcarpeta por orden (`ordenes_fotos/<uuid>/<uuid>.jpg`).
MODULOS_APP = {
    "app_pdf":   (f"{H.DOCROOT_PRUEBAS}/ordenes_pdf",   "*.pdf", 1),
    "app_fotos": (f"{H.DOCROOT_PRUEBAS}/ordenes_fotos", "*.jpg", 2),
}

# Se bajan tambien los contadores y los logs del sistema viejo: el contador es
# la unica prueba de cuantas OTs se emitieron de verdad.
AUXILIARES = {
    "uio":   ["ot/produccion/ot_normal_v3/uio/contadores",  "ot/produccion/ot_normal_v3/uio/registros"],
    "larb":  ["ot/produccion/ot_normal_v3/larb/contadores", "ot/produccion/ot_normal_v3/larb/registros"],
    "cnlj":  ["ot/produccion/ot_normal_v3/cnlj/contadores", "ot/produccion/ot_normal_v3/cnlj/registros"],
    "mant":  ["ot/produccion/ot_mantenimiento/contadores", "ot/produccion/ot_mantenimiento/registros"],
    "otros": ["ot/produccion/ot_normal_otros/contadores", "ot/produccion/ot_normal_otros/registros"],
}
TODOS = list(MODULOS) + list(MODULOS_APP)


def cargar_env() -> dict:
    """El .env, sin exigir claves: la identidad SSH la resuelve hostinger_ssh."""
    return leer_env()


def ruta_remota(env: dict, modulo: str) -> str:
    if modulo in MODULOS_APP:
        return MODULOS_APP[modulo][0]
    docroot = (env.get("HOSTINGER_DOCROOT") or "").strip() or DOCROOT_VIEJO
    return f"{docroot}/{MODULOS[modulo]}"


def patron_de(modulo: str) -> tuple[str, int]:
    if modulo in MODULOS_APP:
        return MODULOS_APP[modulo][1], MODULOS_APP[modulo][2]
    return "*.pdf", 1


def destino_de(modulo: str) -> Path:
    if modulo in MODULOS_APP:
        return DESTINO_APP / modulo[len("app_"):]
    return DESTINO / modulo


def ssh_ejecutar(env: dict, comando: str, timeout: int = 300) -> str:
    """Corre un comando en el servidor y devuelve stdout. Levanta si falla.
    (Se deja como funcion del modulo para que t2_4_pruebas la pueda sustituir.)"""
    return H.ssh(comando, timeout=timeout, env=env)


def inventario_remoto(env: dict, modulo: str):
    """Lista los archivos remotos con su tamano y su sha256, en una sola llamada.

    Se pide el hash desde el servidor porque es la unica forma de verificar la
    transferencia contra una fuente independiente (I-5). Comparar solo tamanos
    dejaria pasar una descarga truncada.

    El `|| true` evita que un directorio vacio haga fallar todo el modulo: un
    modulo sin archivos es un estado legitimo. Los nombres salen relativos al
    directorio (`%P`), con su subcarpeta cuando la hay (las fotos de la app).
    """
    remoto = ruta_remota(env, modulo)
    patron, prof = patron_de(modulo)
    salida = ssh_ejecutar(env, (
        f"cd {shlex.quote(remoto)} 2>/dev/null || exit 7; "
        f"find . -maxdepth {prof} -type f -name {shlex.quote(patron)} -printf '%s\\t%P\\n'; "
        f"echo '---SEPARADOR---'; "
        f"find . -maxdepth {prof} -type f -name {shlex.quote(patron)} -print0 | xargs -0 -r sha256sum 2>/dev/null || true"),
        timeout=900)

    if "---SEPARADOR---" not in salida:
        raise RuntimeError(f"respuesta inesperada del servidor para {modulo}")
    bloque_size, bloque_hash = salida.split("---SEPARADOR---", 1)

    tamanos = {}
    for linea in bloque_size.splitlines():
        if "\t" not in linea:
            continue
        s, nombre = linea.split("\t", 1)
        tamanos[nombre.strip()] = int(s)

    archivos = {}
    for linea in bloque_hash.splitlines():
        linea = linea.strip()
        if not linea or "  " not in linea:
            continue
        h, ruta = linea.split("  ", 1)
        nombre = ruta.strip()
        if nombre.startswith("./"):
            nombre = nombre[2:]
        if len(h) != 64:
            continue
        archivos[nombre] = {"sha256": h, "bytes": tamanos.get(nombre)}

    # Un archivo con tamano pero sin hash significa que sha256sum no lo pudo
    # leer: permisos, o se estaba escribiendo en ese instante. No se baja en
    # esta corrida; el proximo pase lo recoge. Nunca se da por bueno sin hash.
    sin_hash = sorted(set(tamanos) - set(archivos))
    return archivos, sin_hash


def descargar_lote(env: dict, modulo: str, nombres, destino_tmp: Path) -> None:
    """Baja una lista de archivos en UNA sola sesion sftp."""
    remoto = ruta_remota(env, modulo)
    pares = [(f"{remoto}/{n}", destino_tmp / n) for n in nombres]
    H.sftp_bajar(pares, timeout=7200, env=env)


def contar_locales(destino: Path, patron: str) -> int:
    if not destino.is_dir():
        return 0
    return sum(1 for p in destino.rglob(patron)
               if not any(parte.startswith("_") for parte in p.relative_to(destino).parts[:-1]))


def sincronizar_modulo(env: dict, modulo: str, solo_inventario: bool) -> dict:
    destino = destino_de(modulo)
    destino.mkdir(parents=True, exist_ok=True)
    patron, _ = patron_de(modulo)
    locales_antes = contar_locales(destino, patron)

    remotos, sin_hash = inventario_remoto(env, modulo)
    print(f"  remoto : {len(remotos)} archivos" +
          (f"  ({len(sin_hash)} sin hash, se omiten)" if sin_hash else ""))

    # Un archivo local con el hash correcto no se vuelve a bajar. Uno con hash
    # distinto SI se baja: significa que el servidor reescribio ese nombre, y
    # eso pasa de verdad. Pero no pisa el local: va a _divergentes (T2.15.3).
    pendientes, ya_ok, divergentes = [], 0, []
    for nombre, meta in remotos.items():
        local = destino / nombre
        if local.exists() and sha256_de(local) == meta["sha256"]:
            ya_ok += 1
        else:
            if local.exists():
                divergentes.append(nombre)
            pendientes.append(nombre)

    print(f"  local  : {ya_ok} ya verificados, {len(pendientes)} por bajar" +
          (f", {len(divergentes)} DIVERGENTES (el servidor los reescribio; van a _divergentes)" if divergentes else ""))

    sospechoso = locales_antes > 0 and len(remotos) == 0
    if sospechoso:
        print(f"  SOSPECHOSO: el espejo tenia {locales_antes} archivos y el servidor devuelve 0. "
              f"No se toca nada; revisar si movieron la carpeta.")

    base = {"modulo": modulo, "remoto": len(remotos), "ya_ok": ya_ok, "locales_antes": locales_antes,
            "sospechoso": sospechoso, "bajados": 0, "fallidos": [], "divergentes": divergentes,
            "divergentes_guardados": [], "sin_hash": sin_hash, "archivos": remotos}
    if solo_inventario or not pendientes:
        return base

    # Se baja a un temporal y solo se promueve al espejo tras verificar el
    # hash. Sin este paso, una descarga truncada quedaria en el espejo
    # haciendose pasar por buena, y el espejo es lo que despues autoriza la
    # purga del servidor.
    tmp = destino / "_bajando"
    tmp.mkdir(exist_ok=True)
    LOTE = 150
    bajados, fallidos, guardados = 0, [], []
    for i in range(0, len(pendientes), LOTE):
        trozo = pendientes[i:i + LOTE]
        print(f"    lote {i // LOTE + 1}: {len(trozo)} archivos...", flush=True)
        descargar_lote(env, modulo, trozo, tmp)
        for nombre in trozo:
            t = tmp / nombre
            if not t.exists():
                fallidos.append((nombre, "no llego"))
                continue
            h = sha256_de(t)
            if h != remotos[nombre]["sha256"]:
                CUARENTENA.mkdir(parents=True, exist_ok=True)
                t.replace(CUARENTENA / f"{modulo}__{nombre.replace('/', '__')}")
                fallidos.append((nombre, f"hash distinto: {h[:12]} != {remotos[nombre]['sha256'][:12]}"))
                continue
            final = destino / nombre
            if nombre in divergentes:
                # El local que ya estaba verificado se conserva; el nuevo se
                # guarda aparte con su huella en el nombre.
                div = destino / "_divergentes"
                div.mkdir(parents=True, exist_ok=True)
                p = Path(nombre)
                final = div / f"{p.stem}.{h[:12]}{p.suffix}"
                guardados.append(str(final))
            final.parent.mkdir(parents=True, exist_ok=True)
            t.replace(final)
            bajados += 1
    for sub in sorted(tmp.rglob("*"), reverse=True):
        if sub.is_dir():
            try:
                sub.rmdir()
            except OSError:
                pass
    try:
        tmp.rmdir()
    except OSError:
        pass  # quedaron restos en cuarentena o archivos sueltos; se revisa a mano

    base.update({"bajados": bajados, "fallidos": fallidos, "divergentes_guardados": guardados})
    return base


def sincronizar_auxiliares(env: dict, modulo: str) -> None:
    """Contadores y logs del sistema viejo. Son chicos y cambian siempre, asi
    que se bajan completos cada vez, versionados por fecha."""
    sello = datetime.now().strftime("%Y-%m-%d")
    destino = DESTINO / modulo / "_auxiliares" / sello
    destino.mkdir(parents=True, exist_ok=True)
    docroot = (env.get("HOSTINGER_DOCROOT") or "").strip() or DOCROOT_VIEJO
    for ruta in AUXILIARES.get(modulo, []):
        remoto = f"{docroot}/{ruta}"
        sub = destino / Path(ruta).name
        sub.mkdir(exist_ok=True)
        try:
            salida = ssh_ejecutar(env, f"cd {shlex.quote(remoto)} 2>/dev/null && ls -1 || true")
            nombres = [n.strip() for n in salida.splitlines() if n.strip()]
            if not nombres:
                continue
            H.sftp_bajar([(f"{remoto}/{n}", sub / n) for n in nombres], timeout=300, env=env)
        except Exception as e:
            print(f"    aviso: no se pudieron traer los auxiliares de {ruta}: {e}")


def main() -> None:
    ap = argparse.ArgumentParser(description="Espejo verificado de Hostinger (sistema viejo y app nueva)")
    ap.add_argument("--inventario", action="store_true", help="solo compara, no descarga nada")
    ap.add_argument("--modulo", choices=TODOS, action="append",
                    help="limita a uno o varios modulos (por defecto, todos)")
    ap.add_argument("--sin-auxiliares", action="store_true", help="no baja contadores ni logs")
    ap.add_argument("--sin-app", action="store_true", help="no espeja lo que emite la app nueva")
    args = ap.parse_args()

    env = cargar_env()
    modulos = args.modulo or (list(MODULOS) if args.sin_app else TODOS)
    inicio = datetime.now(timezone.utc)
    print(f"Sincronizacion de Hostinger -> {DESTINO} y {DESTINO_APP}")
    print(f"  servidor : {H.destino(env)}:{H.PUERTO}")
    print(f"  modo     : {'SOLO INVENTARIO' if args.inventario else 'DESCARGA'}\n")

    resultados, errores = [], 0
    for m in modulos:
        print(f"[{m}]")
        try:
            r = sincronizar_modulo(env, m, args.inventario)
            resultados.append(r)
            if r["fallidos"]:
                print(f"  FALLIDOS: {len(r['fallidos'])}")
                for n, motivo in r["fallidos"][:10]:
                    print(f"    - {n}: {motivo}")
            if not args.inventario and not args.sin_auxiliares and m in AUXILIARES:
                sincronizar_auxiliares(env, m)
        except Exception as e:
            errores += 1
            print(f"  ERROR: {e}")
            resultados.append({"modulo": m, "error": str(e), "archivos": {}, "fallidos": [],
                               "divergentes": [], "divergentes_guardados": [], "sin_hash": [],
                               "remoto": 0, "ya_ok": 0, "bajados": 0, "sospechoso": False})
        print()

    # El manifiesto es lo que despues autoriza la purga: sin una linea aqui con
    # el hash verificado, ningun archivo puede borrarse del servidor.
    MANIFIESTOS.mkdir(parents=True, exist_ok=True)
    sello = inicio.strftime("%Y%m%dT%H%M%SZ")
    csv_path = MANIFIESTOS / f"manifiesto_{sello}.csv"
    with open(csv_path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["modulo", "archivo", "sha256", "bytes", "verificado_local", "ruta_local", "divergente"])
        for r in resultados:
            destino = destino_de(r["modulo"])
            for nombre, meta in r.get("archivos", {}).items():
                local = destino / nombre
                ok = local.exists() and sha256_de(local) == meta["sha256"]
                div = ""
                if not ok:
                    p = Path(nombre)
                    cand = destino / "_divergentes" / f"{p.stem}.{meta['sha256'][:12]}{p.suffix}"
                    if cand.exists():
                        ok, local, div = True, cand, "SI"
                w.writerow([r["modulo"], nombre, meta["sha256"], meta["bytes"],
                            "SI" if ok else "NO", str(local) if ok else "", div])

    resumen = {
        "inicio_utc": inicio.isoformat(),
        "fin_utc": datetime.now(timezone.utc).isoformat(),
        "modo": "inventario" if args.inventario else "descarga",
        "servidor": H.destino(env),
        "manifiesto_csv": str(csv_path),
        "modulos": [{k: v for k, v in r.items() if k != "archivos"} for r in resultados],
    }
    (MANIFIESTOS / f"resumen_{sello}.json").write_text(
        json.dumps(resumen, indent=2, ensure_ascii=False, default=str), encoding="utf-8")

    print("=" * 66)
    tot_r = sum(r.get("remoto", 0) for r in resultados)
    tot_b = sum(r.get("bajados", 0) for r in resultados)
    tot_f = sum(len(r.get("fallidos", [])) for r in resultados)
    tot_d = sum(len(r.get("divergentes", [])) for r in resultados)
    sosp = [r["modulo"] for r in resultados if r.get("sospechoso")]
    print(f"En el servidor : {tot_r} archivos")
    print(f"Bajados ahora  : {tot_b}")
    print(f"Fallidos       : {tot_f}")
    print(f"Divergentes    : {tot_d}  (mismo nombre, contenido distinto: guardados aparte, el espejo no se piso)")
    print(f"Manifiesto     : {csv_path}")
    if errores or tot_f or sosp:
        if sosp:
            print(f"\nModulos que bajaron a 0 teniendo archivos: {', '.join(sosp)}")
        print("\nHubo fallos. La purga NO debe correrse hasta resolverlos.")
        sys.exit(1)


if __name__ == "__main__":
    main()
