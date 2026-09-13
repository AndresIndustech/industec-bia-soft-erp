"""
T2.15.2 - Volcado nocturno de la base de la app nueva, verificado por hash.

Cada noche baja a `RESPALDOS/_ORIGEN_APP/_bd/` un `mysqldump` de la base de la
app (`u671729428_ots` en el sitio de pruebas hoy; produccion al corte), hecho
EN EL SERVIDOR con credenciales que nunca viajan: `hostinger_ssh` arma un guion
que hace que PHP lea `nucleo/config.php`, escriba un `.cnf` temporal 0600 en
`~/respaldos` y lo borre al terminar. La contrasena no aparece en la linea de
comandos, ni en `ps`, ni aqui.

QUE COMPRUEBA (I-4, I-5):
  - `set -o pipefail`: un mysqldump que falle no deja un .gz vacio pasando por bueno;
  - `gzip -t` en el servidor y descompresion completa en local;
  - sha256 calculado en el servidor y en local: si no coinciden, el archivo se
    descarta y el script sale con 1.

RETENCION en local: 30 volcados diarios + el primero de cada mes durante 12
meses. En el servidor quedan solo los 3 ultimos (no es un almacen: es una
escala). El registro va a `logs/volcado-<fecha>.log` con --log.

USO (en la estacion, o en cualquier equipo con su llave):
    .venv/Scripts/python.exe scripts/t2_4_volcado_bd.py
    .venv/Scripts/python.exe scripts/t2_4_volcado_bd.py --sin-retencion --log
"""
from __future__ import annotations

import argparse
import gzip
import json
import shlex
import sys
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import RESPALDOS, abrir_log, leer_env, sello_utc, sha256_de  # noqa: E402
import hostinger_ssh as H  # noqa: E402

DESTINO = RESPALDOS / "_ORIGEN_APP" / "_bd"
DIARIOS = 30
MENSUALES = 12
REMOTOS_QUE_QUEDAN = 3


def volcar(env: dict) -> dict:
    """Vuelca en el servidor, baja, verifica. Devuelve el resumen o levanta."""
    sello = sello_utc()
    nombre = f"volcado_{sello}.sql.gz"
    cuerpo = (
        f'F="$D/{nombre}"\n'
        'mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers '
        '--skip-comments "$BD" | gzip -9 > "$F"\n'
        'gzip -t "$F"\n'
        'echo "SHA $(sha256sum "$F" | cut -d\' \' -f1)"\n'
        'echo "BYTES $(stat -c %s "$F")"\n'
        'echo "BASE $BD"\n'
    )
    salida = H.ssh(H.guion_con_credenciales(cuerpo, env=env), timeout=1800, env=env)
    datos = dict(l.split(" ", 1) for l in salida.splitlines() if " " in l and l.split(" ", 1)[0] in ("SHA", "BYTES", "BASE"))
    if len(datos.get("SHA", "")) != 64:
        raise RuntimeError("el servidor no devolvio el sha256 del volcado:\n" + salida[-400:])

    DESTINO.mkdir(parents=True, exist_ok=True)
    local = DESTINO / nombre
    H.scp_bajar(f"respaldos/{nombre}", local, timeout=1800, env=env)
    sha_local = sha256_de(local)
    if sha_local != datos["SHA"]:
        local.unlink(missing_ok=True)
        raise RuntimeError(f"hash distinto: servidor {datos['SHA'][:12]} / local {sha_local[:12]}; se descarto la copia")
    # Descompresion completa: `gzip -t` remoto ya lo probo, pero la copia local
    # es la que vale y se prueba entera aqui.
    n = 0
    with gzip.open(local, "rb") as f:
        for trozo in iter(lambda: f.read(1024 * 1024), b""):
            n += len(trozo)
    if n < 1000:
        local.unlink(missing_ok=True)
        raise RuntimeError(f"el volcado descomprimido mide {n} bytes: no es una base")
    (local.with_suffix(local.suffix + ".sha256")).write_text(f"{sha_local}  {nombre}\n", encoding="utf-8")

    # En el servidor quedan los ultimos tres; el resto se borra (es el sitio de
    # pruebas: `hostinger_ssh` no deja escribir en produccion).
    H.ssh("cd ~/respaldos && ls -1t volcado_*.sql.gz 2>/dev/null | tail -n +" + str(REMOTOS_QUE_QUEDAN + 1)
          + " | xargs -r rm -f --", timeout=60, env=env)

    return {"archivo": str(local), "bytes_gz": int(datos.get("BYTES", local.stat().st_size)),
            "bytes_sql": n, "sha256": sha_local, "base": datos.get("BASE", "")}


def que_conservar(nombres: list[str], diarios: int = DIARIOS, mensuales: int = MENSUALES) -> set[str]:
    """Que volcados se quedan: los `diarios` mas recientes y, de los demas, el
    primero de cada mes hasta `mensuales` meses. Funcion pura: la prueba la corre."""
    def fecha(n: str) -> str:
        return n.split("_", 1)[1][:8] if "_" in n else n
    orden = sorted(nombres, key=fecha, reverse=True)
    quedan = set(orden[:diarios])
    por_mes: dict[str, str] = {}
    for n in sorted(orden[diarios:], key=fecha):
        mes = fecha(n)[:6]
        por_mes.setdefault(mes, n)          # el primero del mes
    for mes in sorted(por_mes, reverse=True)[:mensuales]:
        quedan.add(por_mes[mes])
    return quedan


def aplicar_retencion() -> list[str]:
    if not DESTINO.is_dir():
        return []
    nombres = [p.name for p in DESTINO.glob("volcado_*.sql.gz")]
    quedan = que_conservar(nombres)
    borrados = []
    for n in nombres:
        if n not in quedan:
            (DESTINO / n).unlink(missing_ok=True)
            (DESTINO / (n + ".sha256")).unlink(missing_ok=True)
            borrados.append(n)
    return borrados


def main() -> int:
    ap = argparse.ArgumentParser(description="Volcado nocturno de la base de la app, verificado por hash")
    ap.add_argument("--sin-retencion", action="store_true", help="no borra volcados viejos en local")
    ap.add_argument("--log", action="store_true", help="escribe en logs/ en vez de por pantalla")
    a = ap.parse_args()
    if a.log:
        abrir_log("volcado")
    env = leer_env()
    inicio = datetime.now(timezone.utc)
    print(f"Volcado de la base de la app -> {DESTINO}")
    print(f"  servidor : {H.destino(env)}:{H.PUERTO} · config {H.config_app(env)}")
    try:
        r = volcar(env)
    except (H.ErrorSsh, RuntimeError) as e:
        print(f"ERROR: {e}")
        return 1
    print(f"  base     : {r['base']}")
    print(f"  archivo  : {r['archivo']}")
    print(f"  tamano   : {r['bytes_gz'] / 1024:.0f} KB comprimido · {r['bytes_sql'] / 1024:.0f} KB de SQL")
    print(f"  sha256   : {r['sha256']}")
    borrados = [] if a.sin_retencion else aplicar_retencion()
    if borrados:
        print(f"  retencion: se retiraron {len(borrados)} volcados viejos")
    estado = DESTINO / "ultimo.json"
    estado.write_text(json.dumps({"cuando_utc": inicio.isoformat(), **r, "retirados": borrados},
                                 indent=1, ensure_ascii=False), encoding="utf-8")
    print("OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
