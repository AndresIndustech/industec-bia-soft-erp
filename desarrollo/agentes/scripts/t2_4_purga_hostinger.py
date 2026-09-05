"""
T2.4.2 - Purga nocturna de los uploads de Hostinger, con compuerta de hash.

Borra del servidor UNICAMENTE los PDFs que ya estan en el espejo local con el
sha256 identico, recomprobado en el momento del borrado y no en el del ultimo
espejo. Todo lo demas se queda donde esta.

POR QUE EXISTE ESTE SCRIPT Y NO SE USA cleanup.php:
El cleanup.php que vino con el sistema hace `glob('/uploads/*')` y `unlink()`
sobre todo, sin filtro de antiguedad, sin verificar que exista copia y sin
mirar el resultado del unlink. Sobre el estado medido el 2026-09-03 habria
borrado 1.952 PDFs (1,41 GB) y los 10 archivos de registros/ (838 KB de logs).
Viola tres invariantes de una sola vez:
  I-2  un cron nocturno es exactamente lo contrario de "que el cliente lo pida
       en el momento";
  I-4  no hay copia ni hash: borra primero y no verifica nunca;
  I-5  unlink() falla en silencio, no aborta ruidosamente.
Ademas el plan Premium de Hostinger solo trae respaldo SEMANAL (el diario es un
add-on de pago), asi que un borrado equivocado se lleva hasta 7 dias de OTs sin
red debajo.

POR QUE SI SE PURGA, entonces:
No por espacio -- ese argumento no se sostiene: son 1,41 GB de 20 GB y ~2.000
archivos de 400.000 inodos. Se purga por dos razones que si se sostienen:
  1. Los PDFs son PUBLICOS y su nombre es adivinable (OT-{4 digitos}-{local}-
     {aviso}-{zona}.pdf). Contienen nombre, correo y firma manuscrita de
     administradores de locales de Grupo KFC. Cada dia que un PDF sobra en el
     servidor es un dia de exposicion innecesaria.
  2. El contrato de hosting (act. 2026-08-28) prohibe usar el servicio como
     "a repository or storage for files". El archivo definitivo es D:\\RESPALDOS.

USO:
    # 1. Siempre primero, sin argumentos: no borra nada, dice que borraria.
    .venv/Scripts/python.exe scripts/t2_4_purga_hostinger.py

    # 2. Solo despues de leer el informe del paso 1 y con autorizacion del momento:
    .venv/Scripts/python.exe scripts/t2_4_purga_hostinger.py --ejecutar --confirmo-borrado
"""
import argparse
import csv
import hashlib
import json
import shlex
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from t2_4_sync_hostinger import (  # noqa: E402
    MODULOS, DESTINO, cargar_env, sha256_de, ssh_ejecutar, inventario_remoto)

BITACORA = DESTINO / "_purgas"

# Ventana de gracia. Un PDF recien emitido puede estar todavia rebotando en la
# bandeja de alguien, o el tecnico puede necesitar reenviarlo desde el servidor.
# 30 dias es holgado y sigue dejando el servidor muy por debajo de los 3 meses
# que hoy acumula solo.
RETENCION_DIAS_DEFECTO = 30

# Freno de mano. Si una sola corrida quiere borrar mas que esto, algo se rompio
# (un espejo apuntando al directorio equivocado, un inventario a medias) y es
# preferible parar y mirar que borrar 2.000 archivos por un bug.
TOPE_POR_CORRIDA = 400


def edad_dias_remota(env, modulo, nombres):
    """Edad en dias de cada archivo, medida por el propio servidor.

    Se pregunta al servidor y no al mtime local porque el mtime local es la
    fecha de descarga, no la de emision de la OT. Usar el local haria que todo
    lo bajado hoy pareciera de hoy, y la ventana de retencion no protegeria nada.
    """
    remoto = f'{env["HOSTINGER_DOCROOT"]}/{MODULOS[modulo]}'
    salida = ssh_ejecutar(env, (
        f"cd {shlex.quote(remoto)} 2>/dev/null || exit 7; "
        f"find . -maxdepth 1 -type f -name '*.pdf' -printf '%T@\\t%f\\n'"), timeout=600)
    ahora = datetime.now(timezone.utc).timestamp()
    edades = {}
    for linea in salida.splitlines():
        if "\t" not in linea:
            continue
        ts, nombre = linea.split("\t", 1)
        try:
            edades[nombre.strip()] = (ahora - float(ts)) / 86400.0
        except ValueError:
            continue
    return {n: edades.get(n) for n in nombres}


def evaluar_modulo(env, modulo, retencion):
    """Decide, archivo por archivo, si es borrable. Todo lo que no cumpla las
    tres condiciones queda fuera con el motivo escrito."""
    remotos, sin_hash = inventario_remoto(env, modulo)
    edades = edad_dias_remota(env, modulo, list(remotos))
    espejo = DESTINO / modulo

    borrables, retenidos = [], []
    for nombre, meta in remotos.items():
        local = espejo / nombre
        edad = edades.get(nombre)

        if not local.exists():
            retenidos.append((nombre, "sin copia local"))
            continue
        # Se recalcula el hash local AHORA. El del manifiesto pudo quedar viejo
        # si el archivo local se corrompio o alguien lo movio despues.
        h_local = sha256_de(local)
        if h_local != meta["sha256"]:
            retenidos.append((nombre, f"hash distinto (local {h_local[:12]} / remoto {meta['sha256'][:12]})"))
            continue
        if local.stat().st_size != meta["bytes"]:
            retenidos.append((nombre, "tamano distinto"))
            continue
        if edad is None:
            retenidos.append((nombre, "sin fecha en el servidor"))
            continue
        if edad < retencion:
            retenidos.append((nombre, f"dentro de la retencion ({edad:.1f} d < {retencion} d)"))
            continue
        borrables.append({"archivo": nombre, "sha256": meta["sha256"],
                          "bytes": meta["bytes"], "edad_dias": round(edad, 1),
                          "copia_local": str(local)})

    for nombre in sin_hash:
        retenidos.append((nombre, "el servidor no pudo calcular su hash"))

    return borrables, retenidos, len(remotos)


def borrar_en_servidor(env, modulo, borrables):
    """Borra con una ultima verificacion del lado del servidor.

    El `sha256sum -c` remoto es la tercera compuerta: aunque el inventario y la
    comparacion local hayan dicho que si, el archivo se borra solo si en ese
    mismo instante el servidor confirma que su contenido sigue siendo el que
    nosotros tenemos copiado. Si el archivo cambio entre el inventario y este
    momento (un envio que reutilizo el correlativo), no se borra.
    """
    remoto = f'{env["HOSTINGER_DOCROOT"]}/{MODULOS[modulo]}'
    lineas = "\n".join(f'{b["sha256"]}  ./{b["archivo"]}' for b in borrables)
    guion = (
        f"cd {shlex.quote(remoto)} || exit 7\n"
        f"cat > /tmp/purga_$$.sha <<'FIN_MANIFIESTO'\n{lineas}\nFIN_MANIFIESTO\n"
        f"sha256sum -c --quiet /tmp/purga_$$.sha > /tmp/purga_$$.err 2>&1\n"
        f"OK=$(sha256sum -c /tmp/purga_$$.sha 2>/dev/null | grep ': OK$' | sed 's/: OK$//')\n"
        f"echo \"$OK\" | while IFS= read -r f; do [ -n \"$f\" ] && rm -f -- \"$f\" && echo \"BORRADO\t$f\"; done\n"
        f"cat /tmp/purga_$$.err | sed 's/^/NOCOINCIDE\\t/'\n"
        f"rm -f /tmp/purga_$$.sha /tmp/purga_$$.err\n")
    salida = ssh_ejecutar(env, guion, timeout=900)

    borrados, rechazados = [], []
    for linea in salida.splitlines():
        if linea.startswith("BORRADO\t"):
            n = linea.split("\t", 1)[1].strip()
            borrados.append(n[2:] if n.startswith("./") else n)
        elif linea.startswith("NOCOINCIDE\t"):
            rechazados.append(linea.split("\t", 1)[1].strip())
    return borrados, rechazados


def main():
    ap = argparse.ArgumentParser(
        description="Purga de uploads en Hostinger con compuerta de hash. Por defecto NO borra.")
    ap.add_argument("--ejecutar", action="store_true",
                    help="borra de verdad (requiere ademas --confirmo-borrado)")
    ap.add_argument("--confirmo-borrado", action="store_true",
                    help="segunda compuerta explicita, obligatoria para borrar")
    ap.add_argument("--retencion-dias", type=int, default=RETENCION_DIAS_DEFECTO,
                    help=f"no borra nada mas nuevo que esto (defecto {RETENCION_DIAS_DEFECTO})")
    ap.add_argument("--modulo", choices=sorted(MODULOS), action="append")
    ap.add_argument("--tope", type=int, default=TOPE_POR_CORRIDA,
                    help=f"maximo de archivos a borrar en una corrida (defecto {TOPE_POR_CORRIDA})")
    args = ap.parse_args()

    borrar_de_verdad = args.ejecutar and args.confirmo_borrado
    if args.ejecutar and not args.confirmo_borrado:
        sys.exit("--ejecutar exige tambien --confirmo-borrado. No se borro nada.")

    env = cargar_env()
    modulos = args.modulo or list(MODULOS)
    inicio = datetime.now(timezone.utc)

    print("Purga de uploads en Hostinger")
    print(f"  modo      : {'BORRADO REAL' if borrar_de_verdad else 'SIMULACION (no borra nada)'}")
    print(f"  retencion : {args.retencion_dias} dias")
    print(f"  espejo    : {DESTINO}\n")

    informe, total_borrables = [], 0
    for m in modulos:
        print(f"[{m}]")
        try:
            borrables, retenidos, n_remoto = evaluar_modulo(env, m, args.retencion_dias)
        except Exception as e:
            print(f"  ERROR: {e}\n")
            informe.append({"modulo": m, "error": str(e)})
            continue

        mb = sum(b["bytes"] or 0 for b in borrables) / (1024 * 1024)
        print(f"  en servidor : {n_remoto}")
        print(f"  borrables   : {len(borrables)}  ({mb:.1f} MB)")
        print(f"  retenidos   : {len(retenidos)}")
        motivos = {}
        for _, motivo in retenidos:
            clave = motivo.split("(")[0].strip()
            motivos[clave] = motivos.get(clave, 0) + 1
        for k, v in sorted(motivos.items(), key=lambda x: -x[1]):
            print(f"      {v:>5}  {k}")

        sin_copia = [n for n, mo in retenidos if mo == "sin copia local"]
        if sin_copia:
            print(f"  ATENCION: {len(sin_copia)} archivos NO tienen copia local. "
                  f"Corre t2_4_sync_hostinger.py antes de purgar.")

        total_borrables += len(borrables)
        informe.append({"modulo": m, "en_servidor": n_remoto, "borrables": borrables,
                        "retenidos": [{"archivo": n, "motivo": mo} for n, mo in retenidos],
                        "megabytes": round(mb, 1)})
        print()

    if total_borrables > args.tope:
        sys.exit(f"FRENO: la corrida quiere borrar {total_borrables} archivos, por encima "
                 f"del tope de {args.tope}. Revisa el informe antes de subir el tope "
                 f"con --tope. No se borro nada.")

    borrados_reales = {}
    if borrar_de_verdad:
        for bloque in informe:
            if "borrables" not in bloque or not bloque["borrables"]:
                continue
            m = bloque["modulo"]
            print(f"[{m}] borrando {len(bloque['borrables'])} archivos...")
            ok, rech = borrar_en_servidor(env, m, bloque["borrables"])
            borrados_reales[m] = {"borrados": ok, "rechazados": rech}
            print(f"  borrados: {len(ok)}   rechazados por el servidor: {len(rech)}")
            if rech:
                for r in rech[:5]:
                    print(f"    - {r}")

    BITACORA.mkdir(parents=True, exist_ok=True)
    sello = inicio.strftime("%Y%m%dT%H%M%SZ")
    registro = {
        "inicio_utc": inicio.isoformat(),
        "fin_utc": datetime.now(timezone.utc).isoformat(),
        "modo": "BORRADO_REAL" if borrar_de_verdad else "SIMULACION",
        "retencion_dias": args.retencion_dias,
        "servidor": f"{env['HOSTINGER_USER']}@{env['HOSTINGER_HOST']}",
        "evaluacion": informe,
        "borrados_reales": borrados_reales,
    }
    (BITACORA / f"purga_{sello}.json").write_text(
        json.dumps(registro, indent=2, ensure_ascii=False), encoding="utf-8")

    # Un CSV plano de lo borrado, para que la administracion pueda revisarlo sin
    # abrir un JSON. Cada linea lleva el hash y la ruta local: eso es lo que
    # permite reponer el archivo en el servidor si alguna vez hiciera falta.
    if borrados_reales:
        with open(BITACORA / f"borrados_{sello}.csv", "w", newline="", encoding="utf-8") as f:
            w = csv.writer(f)
            w.writerow(["modulo", "archivo", "sha256", "bytes", "edad_dias", "copia_local"])
            for bloque in informe:
                m = bloque.get("modulo")
                ok = set(borrados_reales.get(m, {}).get("borrados", []))
                for b in bloque.get("borrables", []):
                    if b["archivo"] in ok:
                        w.writerow([m, b["archivo"], b["sha256"], b["bytes"],
                                    b["edad_dias"], b["copia_local"]])

    print("=" * 66)
    if borrar_de_verdad:
        n = sum(len(v["borrados"]) for v in borrados_reales.values())
        print(f"Borrados en el servidor: {n}")
        print(f"Bitacora: {BITACORA / f'purga_{sello}.json'}")
    else:
        print(f"SIMULACION. Se habrian borrado {total_borrables} archivos.")
        print("Para borrar de verdad, y solo con autorizacion del momento:")
        print("  --ejecutar --confirmo-borrado")
        print(f"Informe: {BITACORA / f'purga_{sello}.json'}")


if __name__ == "__main__":
    main()
