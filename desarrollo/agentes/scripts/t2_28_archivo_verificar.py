"""
T2.28.17f - Que no vuelva a pasar: la integridad de los PDF del Archivo, cada
noche, con su cifra.

POR QUE EXISTE. El 23-sep se midio que el Archivo ofrecia «Ver» sobre PDF que
no estaban o no se abrian (T2.28.17a-e los arreglo). Nada avisaba si volvia a
pasar: una subida cortada, un PDF de 0 bytes o uno que se pierde en el servidor
solo se descubren cuando alguien lo abre. Este paso, el ultimo del saneamiento
nocturno, corre por SSH la misma verificacion de 17a (`archivo_verificar_cli.php`,
SOLO LECTURA: existe, pesa, empieza por %PDF-, termina en %%EOF y su huella es la
del indice) y cuenta las filas del Archivo que no tienen PDF en el servidor.

Deja `SALIDAS IA/OTS/estado_archivo.json` con «N sin PDF (por origen) · M con
problema» y la cifra de OTRO DIA (`anterior`). InspectorBot avisa si M > 0 o si
una OT emitida por la APP no tiene su PDF. El total «sin PDF» NO es alerta: son
OT del formulario viejo (origen CORREO) cuyo PDF el robot no baja por no ser de un
caso pendiente, y crece 5 a 30 por dia (173 el 30-sep, 200 el 1-oct); alertar por
cada subida seria avisar todas las mananas.
El nocturno corre dos veces algunas noches (02:30 y 04:00 el 2026-10-01): si la
segunda se comparara con la primera, una subida real dejaba de verse a las dos
horas. Por eso `anterior` es la ultima medicion de un dia distinto.

SALE CON 0 AUNQUE HAYA PROBLEMAS, Y TAMBIEN SI NO PUDO MEDIR: es una medicion,
no una compuerta. La primera noche (2026-10-01, 04:00) un `sha256sum` por SSH
se colgo 300 s y puso en FALLO un nocturno cuyos nueve pasos de datos habian
salido bien: GRAVE en InspectorBot por una medicion de solo lectura. Ahora la
falla queda en `ultimo_error` del mismo JSON, sin borrar la ultima cifra buena,
e InspectorBot la muestra como MEDIO. Sale con 1 solo si no puede escribir el
JSON (disco).

Linea base del 2026-09-30: 7.797/7.797 integros, 173 filas sin PDF, 159 s.
Primera noche (2026-10-01 03:52): 7.838/7.838 integros, 200 sin PDF, 32 s.

Uso:
    .venv/Scripts/python.exe scripts/t2_28_archivo_verificar.py
"""
from __future__ import annotations

import hashlib
import json
import re
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import SALIDAS, escribir_json_atomico  # noqa: E402
import hostinger_ssh as H  # noqa: E402

ESTADO = SALIDAS / "estado_archivo.json"
CLI_LOCAL = Path(__file__).resolve().parents[2] / "sistema_ots" / "app" / "publico" / "archivo_verificar_cli.php"
CLI_REMOTO = "respaldos/archivo_verificar_cli.php"
MAX_PROBLEMAS = 2000


def asegurar_cli() -> None:
    """El verificador vive en ~/respaldos (fuera de la web). Si falta o no es el
    del repositorio, se sube: medir con una version vieja daria otra cifra.
    El `sha256sum` es de solo lectura: va con reintentos y 60 s (el 2026-10-01
    se colgo los 300 s por omision, como el `mkdir` de T2.28.18b)."""
    local = hashlib.sha256(CLI_LOCAL.read_bytes()).hexdigest()
    remoto = H.ssh(f"sha256sum {CLI_REMOTO} 2>/dev/null | cut -d' ' -f1; true",
                   timeout=60, idempotente=True).strip()
    if remoto != local:
        H.scp_subir(CLI_LOCAL, CLI_REMOTO)
        print(f"archivo_verificar_cli.php subido a ~/respaldos ({'faltaba' if not remoto else 'era otra version'})")


def leer_salida(texto: str) -> dict:
    """Las cifras de archivo_verificar_cli.php. Lanza si no las encuentra: una
    salida que no se entiende no se da por buena (I-7)."""
    m_tot = re.search(r"comprobados:\s*(\d+)", texto)
    m_ok = re.search(r"ntegros:\s*(\d+)/(\d+)", texto)
    if not (m_tot and m_ok):
        raise ValueError("la salida de archivo_verificar_cli.php no trae sus cifras: " + texto[:300])
    m_pro = re.search(r"con problema:\s*(\d+)", texto)
    problemas = [dict(zip(("ot", "motivo"), l.strip().split("\t", 1)))
                 for l in texto.splitlines() if l.startswith("  ") and "\t" in l]
    # Hasta 2.000 en el archivo, y se dice si se cortó: InspectorBot manda a
    # mirar «la lista» y quien la abre no puede creer que ve todas si no es así.
    return {"total": int(m_tot.group(1)), "integros": int(m_ok.group(1)),
            "con_problema": int(m_pro.group(1)) if m_pro else 0,
            "problemas": problemas[:MAX_PROBLEMAS], "problemas_truncados": len(problemas) > MAX_PROBLEMAS}


def correr_verificador():
    """El CLI por SSH. Sin idempotente=True: sale con 1 cuando HAY problemas, y
    eso dispararia tres reintentos de ~160 s sin motivo. Lo que se reintenta
    (una vez) es el cuelgue: 900 s son cinco veces lo mas lento medido."""
    for intento in (1, 2):
        try:
            return H.ssh_crudo(f"cd {H.DOCROOT_PRUEBAS} && php ~/{CLI_REMOTO} 2>&1", timeout=900)
        except subprocess.TimeoutExpired:
            if intento == 2:
                raise
            time.sleep(60)


def leer_estado() -> dict | None:
    try:
        return json.loads(ESTADO.read_text(encoding="utf-8")) if ESTADO.is_file() else None
    except (OSError, ValueError):
        return None


def mismo_dia_local(fecha_utc: str | None, ahora: datetime) -> bool:
    try:
        return datetime.fromisoformat(str(fecha_utc)).astimezone().date() == ahora.astimezone().date()
    except ValueError:
        return False


def main() -> int:
    inicio = time.time()
    ahora = datetime.now(timezone.utc)
    previo = leer_estado()
    try:
        asegurar_cli()
        r = correr_verificador()
        try:
            cifras = leer_salida(r.stdout or "")
        except ValueError as e:
            # Un SSH cortado deja stdout vacío: sin el código y el stderr, el
            # registro decía «no trae sus cifras» sin decir por qué.
            raise ValueError(f"{e} · codigo {r.returncode} · stderr: {(r.stderr or '').strip()[:300]}") from None
        # Por origen: el total solo informa (es un atraso que crece ~5 a 30 por
        # dia con las OT del formulario viejo cuyo PDF el robot no baja, porque
        # no son de un caso pendiente). Lo que de verdad es una falla es una OT
        # de la APP sin su PDF: la emitio este sistema y tenia que guardarlo.
        sin_pdf_por_origen = {fila[0]: int(fila[1]) for fila in H.sql_remoto(
            "SELECT origen, COUNT(*) FROM ot_archivo WHERE en_servidor = 0 GROUP BY origen", timeout=120)}
        sin_pdf = sum(sin_pdf_por_origen.values())
    except (Exception, SystemExit) as e:
        motivo = f"{type(e).__name__}: {e}"
        print(f"AVISO: no se pudo medir el Archivo ({motivo[:300]}); queda anotado y la ultima cifra buena se conserva",
              file=sys.stderr)
        estado = dict(previo or {})
        estado["ultimo_error"] = {"fecha_utc": ahora.isoformat(timespec="seconds"), "error": motivo[:500]}
        try:
            escribir_json_atomico(ESTADO, json.dumps(estado, indent=1, ensure_ascii=False))
        except OSError as ex:
            print(f"y tampoco se pudo escribir {ESTADO}: {ex}", file=sys.stderr)
            return 1
        return 0
    if previo and previo.get("fecha_utc"):
        anterior = (previo.get("anterior") if mismo_dia_local(previo.get("fecha_utc"), ahora)
                    else {"fecha_utc": previo.get("fecha_utc"), "sin_pdf": previo.get("sin_pdf"),
                          "con_problema": previo.get("con_problema")})
    else:
        anterior = None
    estado = {"fecha_utc": ahora.isoformat(timespec="seconds"),
              "segundos": round(time.time() - inicio), "sin_pdf": sin_pdf,
              "sin_pdf_por_origen": sin_pdf_por_origen, **cifras, "anterior": anterior}
    escribir_json_atomico(ESTADO, json.dumps(estado, indent=1, ensure_ascii=False))
    subio = anterior is not None and anterior.get("sin_pdf") is not None and sin_pdf > int(anterior["sin_pdf"])
    desglose = ", ".join(f"{o}: {n}" for o, n in sorted(sin_pdf_por_origen.items())) or "ninguna"
    print(f"Archivo: {cifras['integros']}/{cifras['total']} PDF integros · {cifras['con_problema']} con problema · "
          f"{sin_pdf} filas sin PDF ({desglose})" + (f", antes {anterior['sin_pdf']}" if subio else "")
          + f" · {estado['segundos']} s")
    for p in cifras["problemas"][:10]:
        print(f"   {p.get('ot')}: {p.get('motivo')}")
    print(f"-> {ESTADO}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
