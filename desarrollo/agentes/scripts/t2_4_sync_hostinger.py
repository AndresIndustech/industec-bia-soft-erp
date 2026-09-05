"""
T2.4.1 - Espejo verificado de los uploads de Hostinger.

Trae a disco local, SIN MODIFICAR NADA EN EL SERVIDOR, todos los PDFs que el
sistema de OTs en produccion va generando. Es la unica pieza que toca el
hosting para leer; la purga (t2_4_purga_hostinger.py) es un script aparte y
depende del manifiesto que este deja escrito.

POR QUE ASI:
- El sistema en produccion no escribe en ninguna base: el PDF y el correo son
  el unico registro que existe de una OT. Y uploads/ solo conserva ~3 meses
  (medido: los contadores van en 1820/2220/2423 pero solo sobreviven 399/598/742
  archivos). Cada dia sin espejo local es informacion que se pierde sola.
- I-4 manda: copiar -> verificar por hash -> recien entonces mover o borrar.
  Aqui se copia y se verifica. Nada mas. El borrado es otra decision y otro
  script, que exige aprobacion en el momento (I-2).
- El destino es un ESPEJO CRUDO, hermano de _ORIGEN_DRIVE: los nombres llegan
  tal cual los emitio el servidor, con sus 158 grafias de local y sus 23
  archivos degenerados. Normalizar aqui destruiria la evidencia de lo que el
  sistema realmente produjo. La normalizacion canonica es t2_4_ingesta_nuevas.py.

TRANSPORTE: sftp del OpenSSH de Windows en modo lote (-b). Una sola conexion
para todo el lote; scp por archivo abriria una sesion SSH por PDF y sobre el
techo de I/O de 12 MB/s del plan Premium eso compite con los tecnicos que
estan enviando ordenes. rsync seria mejor pero no esta instalado en la
estacion y no vale la pena una dependencia mas para esto.

USO:
    .venv/Scripts/python.exe scripts/t2_4_sync_hostinger.py            # sincroniza
    .venv/Scripts/python.exe scripts/t2_4_sync_hostinger.py --inventario  # solo mira, no baja
    .venv/Scripts/python.exe scripts/t2_4_sync_hostinger.py --modulo uio
"""
import argparse
import csv
import hashlib
import json
import os
import shlex
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

ENV_PATH = Path(r"D:\INDUSTECH IA\desarrollo\agentes\config\.env")

# Espejo crudo, hermano de _ORIGEN_DRIVE. Nunca se modifica a mano ni se
# renombra: es la prueba de que lo que bajamos es identico a lo que el
# servidor tenia. El arbol canonico se construye a partir de aqui, no aqui.
DESTINO = Path(r"D:\RESPALDOS\_ORIGEN_SISTEMA")
MANIFIESTOS = DESTINO / "_manifiestos"
CUARENTENA = DESTINO / "_cuarentena_hash"

# Los 5 modulos del sistema en produccion, con su ruta remota relativa al
# docroot. Verificado contra las rutas absolutas que aparecen en
# registros/error_normal.log de la copia del 2026-09-03.
MODULOS = {
    "uio":   "ot/pruebas/ot_normal_v3/uio/uploads",
    "larb":  "ot/pruebas/ot_normal_v3/larb/uploads",
    "cnlj":  "ot/pruebas/ot_normal_v3/cnlj/uploads",
    "mant":  "ot/produccion/ot_mantenimiento/uploads",
    "otros": "ot/pruebas/ot_normal_otros/uploads",
}

# Se bajan tambien los contadores y los logs: el contador es la unica prueba de
# cuantas OTs se emitieron de verdad (incluidas las que ya no estan en disco), y
# error_normal.log es el unico rastro temporal de los envios fallidos.
AUXILIARES = {
    "uio":   ["ot/pruebas/ot_normal_v3/uio/contadores",  "ot/pruebas/ot_normal_v3/uio/registros"],
    "larb":  ["ot/pruebas/ot_normal_v3/larb/contadores", "ot/pruebas/ot_normal_v3/larb/registros"],
    "cnlj":  ["ot/pruebas/ot_normal_v3/cnlj/contadores", "ot/pruebas/ot_normal_v3/cnlj/registros"],
    "mant":  ["ot/produccion/ot_mantenimiento/contadores", "ot/produccion/ot_mantenimiento/registros"],
    "otros": ["ot/pruebas/ot_normal_otros/contadores", "ot/pruebas/ot_normal_otros/registros"],
}


def cargar_env():
    env = {}
    for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        env[k.strip()] = v.strip()
    faltan = [k for k in ("HOSTINGER_USER", "HOSTINGER_HOST", "HOSTINGER_PORT",
                          "HOSTINGER_DOCROOT", "HOSTINGER_SSH_KEY") if k not in env]
    if faltan:
        sys.exit(
            "FALTAN CREDENCIALES en config/.env: " + ", ".join(faltan) + "\n"
            "Ver desarrollo/sistema_ots/LEEME_ACCESO_HOSTINGER.md para obtenerlas.")
    return env


def sha256_de(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def _base_ssh(env):
    """Opciones comunes a ssh y sftp.

    BatchMode=yes es deliberado: si la llave no sirve, queremos que falle en el
    acto con un error legible, no que se quede colgado pidiendo contrasena en un
    cron nocturno donde nadie la va a escribir (I-5: abortar ruidosamente).
    """
    return [
        "-i", env["HOSTINGER_SSH_KEY"],
        "-o", "BatchMode=yes",
        "-o", "StrictHostKeyChecking=accept-new",
        "-o", "ConnectTimeout=20",
        "-o", "ServerAliveInterval=15",
    ]


def ssh_ejecutar(env, comando, timeout=300):
    """Corre un comando en el servidor y devuelve stdout. Levanta si falla."""
    cmd = ["ssh", "-p", env["HOSTINGER_PORT"]] + _base_ssh(env) + [
        f'{env["HOSTINGER_USER"]}@{env["HOSTINGER_HOST"]}', comando]
    r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    if r.returncode != 0:
        raise RuntimeError(
            f"ssh fallo (codigo {r.returncode})\n"
            f"  comando: {comando[:200]}\n"
            f"  stderr : {r.stderr.strip()[:500]}")
    return r.stdout


def inventario_remoto(env, modulo):
    """Lista los PDFs remotos con su tamano y su sha256, en una sola llamada.

    Se pide el hash desde el servidor porque es la unica forma de verificar la
    transferencia contra una fuente independiente (I-5). Comparar solo tamanos
    dejaria pasar una descarga truncada, que es justo el modo de falla probable
    sobre un enlace domestico.

    El `|| true` evita que un directorio vacio (sha256sum sin argumentos) haga
    fallar todo el modulo: un modulo sin archivos es un estado legitimo.
    """
    remoto = f'{env["HOSTINGER_DOCROOT"]}/{MODULOS[modulo]}'
    salida = ssh_ejecutar(env, (
        f"cd {shlex.quote(remoto)} 2>/dev/null || exit 7; "
        f"find . -maxdepth 1 -type f -name '*.pdf' -printf '%s\\t%f\\n'; "
        f"echo '---SEPARADOR---'; "
        f"sha256sum ./*.pdf 2>/dev/null || true"), timeout=900)

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


def descargar_lote(env, modulo, nombres, destino_tmp):
    """Baja una lista de archivos en UNA sola sesion sftp.

    sftp -b lee el lote de un archivo; asi 400 PDFs cuestan una conexion y no
    400. Se usa `-` delante de cada get para que un archivo que desaparecio
    entre el inventario y la descarga (purga concurrente, envio a medias) no
    aborte el lote entero.
    """
    remoto = f'{env["HOSTINGER_DOCROOT"]}/{MODULOS[modulo]}'
    lote = []
    for n in nombres:
        lote.append(f"-get -p {shlex.quote(remoto + '/' + n)} {shlex.quote(str(destino_tmp / n))}")
    lote.append("quit")

    with tempfile.NamedTemporaryFile("w", suffix=".sftp", delete=False,
                                     encoding="utf-8", newline="\n") as f:
        f.write("\n".join(lote) + "\n")
        batch = f.name
    try:
        cmd = (["sftp", "-P", env["HOSTINGER_PORT"]] + _base_ssh(env) +
               ["-b", batch, f'{env["HOSTINGER_USER"]}@{env["HOSTINGER_HOST"]}'])
        # No se mira el returncode: con `-get` sftp devuelve 0 aunque falten
        # archivos. La verdad la da la verificacion por hash de mas abajo, que
        # es la unica fuente en la que confiamos.
        subprocess.run(cmd, capture_output=True, text=True, timeout=7200)
    finally:
        os.unlink(batch)


def sincronizar_modulo(env, modulo, solo_inventario):
    destino = DESTINO / modulo
    destino.mkdir(parents=True, exist_ok=True)

    remotos, sin_hash = inventario_remoto(env, modulo)
    print(f"  remoto : {len(remotos)} PDFs" +
          (f"  ({len(sin_hash)} sin hash, se omiten)" if sin_hash else ""))

    # Un archivo local con el hash correcto no se vuelve a bajar. Uno con hash
    # distinto SI se vuelve a bajar: significa que el servidor reescribio ese
    # nombre, y eso pasa de verdad (la condicion de carrera del contador puede
    # sobrescribir un PDF con otro distinto bajo el mismo nombre).
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
          (f", {len(divergentes)} DIVERGENTES (el servidor los reescribio)" if divergentes else ""))

    if solo_inventario or not pendientes:
        return {"modulo": modulo, "remoto": len(remotos), "ya_ok": ya_ok,
                "bajados": 0, "fallidos": [], "divergentes": divergentes,
                "sin_hash": sin_hash, "archivos": remotos}

    # Se baja a un temporal y solo se promueve al espejo tras verificar el
    # hash. Sin este paso, una descarga truncada quedaria en el espejo
    # haciendose pasar por buena, y el espejo es lo que despues autoriza la
    # purga del servidor.
    tmp = destino / "_bajando"
    tmp.mkdir(exist_ok=True)
    LOTE = 150
    bajados, fallidos = 0, []
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
                t.replace(CUARENTENA / f"{modulo}__{nombre}")
                fallidos.append((nombre, f"hash distinto: {h[:12]} != {remotos[nombre]['sha256'][:12]}"))
                continue
            t.replace(destino / nombre)
            bajados += 1
    try:
        tmp.rmdir()
    except OSError:
        pass  # quedaron restos en cuarentena o archivos sueltos; se revisa a mano

    return {"modulo": modulo, "remoto": len(remotos), "ya_ok": ya_ok,
            "bajados": bajados, "fallidos": fallidos, "divergentes": divergentes,
            "sin_hash": sin_hash, "archivos": remotos}


def sincronizar_auxiliares(env, modulo):
    """Contadores y logs. Son chicos y cambian siempre, asi que se bajan
    completos cada vez, versionados por fecha para no perder el valor anterior:
    el contador de ayer contra el de hoy dice cuantas OTs se emitieron, incluso
    las que nunca llegamos a ver."""
    sello = datetime.now().strftime("%Y-%m-%d")
    destino = DESTINO / modulo / "_auxiliares" / sello
    destino.mkdir(parents=True, exist_ok=True)
    for ruta in AUXILIARES.get(modulo, []):
        remoto = f'{env["HOSTINGER_DOCROOT"]}/{ruta}'
        sub = destino / Path(ruta).name
        sub.mkdir(exist_ok=True)
        try:
            salida = ssh_ejecutar(env, f"cd {shlex.quote(remoto)} 2>/dev/null && ls -1 || true")
            nombres = [n.strip() for n in salida.splitlines() if n.strip()]
            if not nombres:
                continue
            lote = [f"-get -p {shlex.quote(remoto + '/' + n)} {shlex.quote(str(sub / n))}"
                    for n in nombres] + ["quit"]
            with tempfile.NamedTemporaryFile("w", suffix=".sftp", delete=False,
                                             encoding="utf-8", newline="\n") as f:
                f.write("\n".join(lote) + "\n")
                batch = f.name
            try:
                subprocess.run(
                    ["sftp", "-P", env["HOSTINGER_PORT"]] + _base_ssh(env) +
                    ["-b", batch, f'{env["HOSTINGER_USER"]}@{env["HOSTINGER_HOST"]}'],
                    capture_output=True, text=True, timeout=300)
            finally:
                os.unlink(batch)
        except Exception as e:
            print(f"    aviso: no se pudieron traer los auxiliares de {ruta}: {e}")


def main():
    ap = argparse.ArgumentParser(description="Espejo verificado de los uploads de Hostinger")
    ap.add_argument("--inventario", action="store_true",
                    help="solo compara, no descarga nada")
    ap.add_argument("--modulo", choices=sorted(MODULOS), action="append",
                    help="limita a uno o varios modulos (por defecto, todos)")
    ap.add_argument("--sin-auxiliares", action="store_true",
                    help="no baja contadores ni logs")
    args = ap.parse_args()

    env = cargar_env()
    modulos = args.modulo or list(MODULOS)
    inicio = datetime.now(timezone.utc)
    print(f"Sincronizacion de Hostinger -> {DESTINO}")
    print(f"  servidor : {env['HOSTINGER_USER']}@{env['HOSTINGER_HOST']}:{env['HOSTINGER_PORT']}")
    print(f"  modo     : {'SOLO INVENTARIO' if args.inventario else 'DESCARGA'}\n")

    resultados = []
    for m in modulos:
        print(f"[{m}]")
        try:
            r = sincronizar_modulo(env, m, args.inventario)
            resultados.append(r)
            if r["fallidos"]:
                print(f"  FALLIDOS: {len(r['fallidos'])}")
                for n, motivo in r["fallidos"][:10]:
                    print(f"    - {n}: {motivo}")
            if not args.inventario and not args.sin_auxiliares:
                sincronizar_auxiliares(env, m)
        except Exception as e:
            print(f"  ERROR: {e}")
            resultados.append({"modulo": m, "error": str(e), "archivos": {},
                               "fallidos": [], "divergentes": [], "sin_hash": [],
                               "remoto": 0, "ya_ok": 0, "bajados": 0})
        print()

    # El manifiesto es lo que despues autoriza la purga: sin una linea aqui con
    # el hash verificado, ningun archivo puede borrarse del servidor.
    MANIFIESTOS.mkdir(parents=True, exist_ok=True)
    sello = inicio.strftime("%Y%m%dT%H%M%SZ")
    csv_path = MANIFIESTOS / f"manifiesto_{sello}.csv"
    with open(csv_path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["modulo", "archivo", "sha256", "bytes", "verificado_local", "ruta_local"])
        for r in resultados:
            for nombre, meta in r.get("archivos", {}).items():
                local = DESTINO / r["modulo"] / nombre
                ok = local.exists() and sha256_de(local) == meta["sha256"]
                w.writerow([r["modulo"], nombre, meta["sha256"], meta["bytes"],
                            "SI" if ok else "NO", str(local) if ok else ""])

    resumen = {
        "inicio_utc": inicio.isoformat(),
        "fin_utc": datetime.now(timezone.utc).isoformat(),
        "modo": "inventario" if args.inventario else "descarga",
        "servidor": f"{env['HOSTINGER_USER']}@{env['HOSTINGER_HOST']}",
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
    print(f"En el servidor : {tot_r} PDFs")
    print(f"Bajados ahora  : {tot_b}")
    print(f"Fallidos       : {tot_f}")
    print(f"Divergentes    : {tot_d}  (mismo nombre, contenido distinto al que teniamos)")
    print(f"Manifiesto     : {csv_path}")
    if tot_f:
        print("\nHubo fallos. La purga NO debe correrse hasta resolverlos.")
        sys.exit(1)


if __name__ == "__main__":
    main()
