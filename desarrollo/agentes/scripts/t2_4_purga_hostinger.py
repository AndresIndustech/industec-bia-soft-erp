"""
T2.4.2 / T2.15.5 - Purga de los uploads del sistema viejo en Hostinger, con compuertas.

Borra del servidor UNICAMENTE los PDFs que ya estan en el espejo local con el
sha256 identico, recomprobado en el momento del borrado, y que ademas tienen
una SEGUNDA COPIA con el mismo hash (T2.15.5: el equipo Veeam/TrueNAS, la ruta
`SEGUNDA_COPIA` de config/.env). Mientras no exista la segunda copia, este
script SOLO INFORMA: «0 borrables, motivo: sin segunda copia».

POR QUE EXISTE ESTE SCRIPT Y NO SE USA cleanup.php:
El cleanup.php que vino con el sistema hace `glob('/uploads/*')` y `unlink()`
sobre todo, sin filtro de antiguedad, sin verificar que exista copia y sin
mirar el resultado del unlink. Viola tres invariantes de una sola vez (I-2,
I-4, I-5). Ademas el plan Premium de Hostinger solo trae respaldo SEMANAL.

POR QUE SI SE PURGA: los PDFs son publicos y su nombre es adivinable, con
nombre, correo y firma de administradores de locales de Grupo KFC (cada dia
que sobran es exposicion innecesaria), y el contrato de hosting prohibe usar el
servicio como repositorio. El archivo definitivo es RESPALDOS.

LAS CINCO COMPUERTAS, todas escritas en el informe archivo por archivo:
  1. copia local en el espejo con el mismo hash (recalculado ahora);
  2. segunda copia con el mismo hash (SEGUNDA_COPIA/_ORIGEN_SISTEMA/<modulo>/<nombre>);
  3. hash presente en `ots.hash_pdf` de la estacion, si la base esta al alcance
     (si no lo esta, se dice y se retiene);
  4. edad en el servidor mayor que la retencion (`PURGA_RETENCION_DIAS` del
     .env, 90 hasta D+30 del corte; 30 por defecto si no esta);
  5. `sha256sum -c` en el servidor en el mismo instante del borrado.

ESCRITURA EN PRODUCCION: es el unico script que la pide a `hostinger_ssh`
(`escritura_produccion=True`), y solo con `--ejecutar --confirmo-borrado`.

USO:
    .venv/Scripts/python.exe scripts/t2_4_purga_hostinger.py                      # informa
    .venv/Scripts/python.exe scripts/t2_4_purga_hostinger.py --ejecutar --confirmo-borrado
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
from comun import leer_env  # noqa: E402
import hostinger_ssh as H  # noqa: E402
from t2_4_sync_hostinger import (  # noqa: E402
    MODULOS, DESTINO, cargar_env, inventario_remoto, ruta_remota, sha256_de, ssh_ejecutar)

BITACORA = DESTINO / "_purgas"
RETENCION_DIAS_DEFECTO = 30
# Freno de mano: si una corrida quiere borrar mas que esto, algo se rompio.
TOPE_POR_CORRIDA = 400


def retencion_configurada(env: dict) -> int:
    v = (env.get("PURGA_RETENCION_DIAS") or "").strip()
    return int(v) if v.isdigit() else RETENCION_DIAS_DEFECTO


def segunda_copia_configurada(env: dict) -> Path | None:
    v = (env.get("SEGUNDA_COPIA") or "").strip().strip('"')
    return Path(v) if v else None


def hashes_en_base(env: dict) -> set[str] | None:
    """Los hash_pdf de la tabla ots de la estacion, o None si la base no esta."""
    try:
        import mysql.connector
        cnx = mysql.connector.connect(host=env["DB_HOST"], port=int(env.get("DB_PORT", "3306")),
                                      user=env["DB_USER"], password=env["DB_PASSWORD"], database=env["DB_NAME"],
                                      connection_timeout=10)
        cur = cnx.cursor()
        cur.execute("SELECT hash_pdf FROM ots WHERE hash_pdf IS NOT NULL")
        out = {r[0] for r in cur.fetchall()}
        cur.close(); cnx.close()
        return out
    except Exception:
        return None


def edad_dias_remota(env: dict, modulo: str, nombres):
    """Edad en dias de cada archivo, medida por el propio servidor (el mtime
    local es la fecha de descarga, no la de emision)."""
    remoto = ruta_remota(env, modulo)
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


def evaluar_modulo(env: dict, modulo: str, retencion: int, segunda_copia: Path | None = None,
                   en_base: set[str] | None = None):
    """Decide, archivo por archivo, si es borrable. Todo lo que no cumpla las
    compuertas queda fuera con el motivo escrito."""
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
        h_local = sha256_de(local)
        if h_local != meta["sha256"]:
            retenidos.append((nombre, f"hash distinto (local {h_local[:12]} / remoto {meta['sha256'][:12]})"))
            continue
        if local.stat().st_size != meta["bytes"]:
            retenidos.append((nombre, "tamano distinto"))
            continue
        if segunda_copia is None:
            retenidos.append((nombre, "sin segunda copia (SEGUNDA_COPIA no configurada)"))
            continue
        copia2 = segunda_copia / "_ORIGEN_SISTEMA" / modulo / nombre
        if not copia2.is_file():
            retenidos.append((nombre, "sin segunda copia (no esta en SEGUNDA_COPIA)"))
            continue
        if sha256_de(copia2) != meta["sha256"]:
            retenidos.append((nombre, "segunda copia con hash distinto"))
            continue
        if en_base is None:
            retenidos.append((nombre, "sin verificar en la base (ots.hash_pdf no alcanzable)"))
            continue
        if meta["sha256"] not in en_base:
            retenidos.append((nombre, "hash no consta en ots.hash_pdf"))
            continue
        if edad is None:
            retenidos.append((nombre, "sin fecha en el servidor"))
            continue
        if edad < retencion:
            retenidos.append((nombre, f"dentro de la retencion ({edad:.1f} d < {retencion} d)"))
            continue
        borrables.append({"archivo": nombre, "sha256": meta["sha256"], "bytes": meta["bytes"],
                          "edad_dias": round(edad, 1), "copia_local": str(local), "segunda_copia": str(copia2)})

    for nombre in sin_hash:
        retenidos.append((nombre, "el servidor no pudo calcular su hash"))
    return borrables, retenidos, len(remotos)


def borrar_en_servidor(env: dict, modulo: str, borrables) -> tuple[list[str], list[str]]:
    """Borra con una ultima verificacion del lado del servidor (`sha256sum -c`
    en el mismo instante). Es el unico comando de escritura sobre produccion y
    pasa por la compuerta de `hostinger_ssh` de forma explicita."""
    remoto = ruta_remota(env, modulo)
    lineas = "\n".join(f'{b["sha256"]}  ./{b["archivo"]}' for b in borrables)
    guion = (
        f"cd {shlex.quote(remoto)} || exit 7\n"
        f"cat > /tmp/purga_$$.sha <<'FIN_MANIFIESTO'\n{lineas}\nFIN_MANIFIESTO\n"
        f"sha256sum -c --quiet /tmp/purga_$$.sha > /tmp/purga_$$.err 2>&1\n"
        f"OK=$(sha256sum -c /tmp/purga_$$.sha 2>/dev/null | grep ': OK$' | sed 's/: OK$//')\n"
        f"echo \"$OK\" | while IFS= read -r f; do [ -n \"$f\" ] && rm -f -- \"$f\" && echo \"BORRADO\t$f\"; done\n"
        f"cat /tmp/purga_$$.err | sed 's/^/NOCOINCIDE\\t/'\n"
        f"rm -f /tmp/purga_$$.sha /tmp/purga_$$.err\n")
    salida = H.ssh(guion, timeout=900, escritura_produccion=True, env=env)
    borrados, rechazados = [], []
    for linea in salida.splitlines():
        if linea.startswith("BORRADO\t"):
            n = linea.split("\t", 1)[1].strip()
            borrados.append(n[2:] if n.startswith("./") else n)
        elif linea.startswith("NOCOINCIDE\t"):
            rechazados.append(linea.split("\t", 1)[1].strip())
    return borrados, rechazados


def main() -> None:
    ap = argparse.ArgumentParser(
        description="Purga de uploads del sistema viejo con compuertas. Por defecto NO borra.")
    ap.add_argument("--ejecutar", action="store_true", help="borra de verdad (requiere ademas --confirmo-borrado)")
    ap.add_argument("--confirmo-borrado", action="store_true", help="segunda compuerta explicita")
    ap.add_argument("--retencion-dias", type=int, default=None,
                    help="no borra nada mas nuevo que esto (defecto: PURGA_RETENCION_DIAS del .env o 30)")
    ap.add_argument("--modulo", choices=sorted(MODULOS), action="append")
    ap.add_argument("--tope", type=int, default=TOPE_POR_CORRIDA)
    args = ap.parse_args()

    borrar_de_verdad = args.ejecutar and args.confirmo_borrado
    if args.ejecutar and not args.confirmo_borrado:
        sys.exit("--ejecutar exige tambien --confirmo-borrado. No se borro nada.")

    env = cargar_env()
    retencion = args.retencion_dias if args.retencion_dias is not None else retencion_configurada(env)
    segunda = segunda_copia_configurada(env)
    en_base = hashes_en_base(env)
    modulos = args.modulo or list(MODULOS)
    inicio = datetime.now(timezone.utc)

    print("Purga de uploads del sistema viejo en Hostinger")
    print(f"  modo          : {'BORRADO REAL' if borrar_de_verdad else 'SIMULACION (no borra nada)'}")
    print(f"  retencion     : {retencion} dias")
    print(f"  espejo        : {DESTINO}")
    print(f"  segunda copia : {segunda if segunda else 'NO CONFIGURADA -> solo informa'}")
    print(f"  base estacion : {'al alcance (' + str(len(en_base)) + ' hashes)' if en_base is not None else 'no alcanzable -> se retiene todo'}\n")

    informe, total_borrables = [], 0
    for m in modulos:
        print(f"[{m}]")
        try:
            borrables, retenidos, n_remoto = evaluar_modulo(env, m, retencion, segunda, en_base)
        except Exception as e:
            print(f"  ERROR: {e}\n")
            informe.append({"modulo": m, "error": str(e)})
            continue
        mb = sum(b["bytes"] or 0 for b in borrables) / (1024 * 1024)
        print(f"  en servidor : {n_remoto}")
        print(f"  borrables   : {len(borrables)}  ({mb:.1f} MB)")
        print(f"  retenidos   : {len(retenidos)}")
        motivos: dict[str, int] = {}
        for _, motivo in retenidos:
            clave = motivo.split("(")[0].strip()
            motivos[clave] = motivos.get(clave, 0) + 1
        for k, v in sorted(motivos.items(), key=lambda x: -x[1]):
            print(f"      {v:>5}  {k}")
        sin_copia = [n for n, mo in retenidos if mo == "sin copia local"]
        if sin_copia:
            print(f"  ATENCION: {len(sin_copia)} archivos NO tienen copia local. Corre t2_4_sync_hostinger.py antes.")
        total_borrables += len(borrables)
        informe.append({"modulo": m, "en_servidor": n_remoto, "borrables": borrables,
                        "retenidos": [{"archivo": n, "motivo": mo} for n, mo in retenidos],
                        "megabytes": round(mb, 1)})
        print()

    if total_borrables > args.tope:
        sys.exit(f"FRENO: la corrida quiere borrar {total_borrables} archivos, por encima del tope de "
                 f"{args.tope}. Revisa el informe antes de subir el tope con --tope. No se borro nada.")

    borrados_reales = {}
    if borrar_de_verdad and segunda is not None:
        for bloque in informe:
            if not bloque.get("borrables"):
                continue
            m = bloque["modulo"]
            print(f"[{m}] borrando {len(bloque['borrables'])} archivos...")
            ok, rech = borrar_en_servidor(env, m, bloque["borrables"])
            borrados_reales[m] = {"borrados": ok, "rechazados": rech}
            print(f"  borrados: {len(ok)}   rechazados por el servidor: {len(rech)}")

    BITACORA.mkdir(parents=True, exist_ok=True)
    sello = inicio.strftime("%Y%m%dT%H%M%SZ")
    registro = {
        "inicio_utc": inicio.isoformat(), "fin_utc": datetime.now(timezone.utc).isoformat(),
        "modo": "BORRADO_REAL" if borrar_de_verdad else "SIMULACION",
        "retencion_dias": retencion, "segunda_copia": str(segunda) if segunda else None,
        "servidor": H.destino(env), "evaluacion": informe, "borrados_reales": borrados_reales,
    }
    (BITACORA / f"purga_{sello}.json").write_text(json.dumps(registro, indent=2, ensure_ascii=False), encoding="utf-8")
    if borrados_reales:
        with open(BITACORA / f"borrados_{sello}.csv", "w", newline="", encoding="utf-8") as f:
            w = csv.writer(f)
            w.writerow(["modulo", "archivo", "sha256", "bytes", "edad_dias", "copia_local", "segunda_copia"])
            for bloque in informe:
                m = bloque.get("modulo")
                ok = set(borrados_reales.get(m, {}).get("borrados", []))
                for b in bloque.get("borrables", []):
                    if b["archivo"] in ok:
                        w.writerow([m, b["archivo"], b["sha256"], b["bytes"], b["edad_dias"], b["copia_local"], b["segunda_copia"]])

    print("=" * 66)
    if borrar_de_verdad and segunda is not None:
        n = sum(len(v["borrados"]) for v in borrados_reales.values())
        print(f"Borrados en el servidor: {n}")
    else:
        print(f"SIMULACION. Borrables: {total_borrables}" + ("" if segunda else "  (motivo: sin segunda copia)"))
        print("Para borrar de verdad, y solo con autorizacion del momento: --ejecutar --confirmo-borrado")
    print(f"Informe: {BITACORA / f'purga_{sello}.json'}")


if __name__ == "__main__":
    main()
