"""
T2.27.7 — El programador de la estación: corre los reportes que el panel «Automatización» tenga ACTIVOS.

CÓMO ENCAJA
La configuración vive en el sitio (automatizacion.php, migración 020): qué
reporte, qué días y a qué hora, en qué modo y a quién. Este programador corre en
la estación —donde están la base local, el correo y Excel—, lee esa
configuración por SSH (`automatizacion_cli.php tareas`) y, para cada tarea
ACTIVA cuyo horario ya pasó y que todavía no corrió en ese horario:
  1. corre su generador (los t2_27_*.py de siempre);
  2. según el modo: GENERAR deja los archivos; AVISAR se los manda a los
     revisores internos; ENVIAR se los manda al cliente, respondiendo en el hilo;
  3. registra la corrida en el panel (`automatizacion_cli.php registrar`).

LO QUE NO HACE
- No corre una tarea INACTIVA en su horario. Andrés pidió el 2026-09-23 que todo
  naciera inactivo y se activara con la aprobación de la administradora; activar
  se hace en el panel, con la nota de quién aprobó. El servidor, además, rechaza
  registrar una corrida «de horario» de una tarea inactiva.
- En modo ENVIAR nunca adjunta los archivos de REVISIÓN ni el texto del correo:
  esos son para la administración, no para KFC.
- Sin `SMTP_*` en config/.env no manda nada: la corrida queda en ERROR con el motivo.
  Configurar el SMTP es parte de activar, no de construir.

Uso:
    .venv/Scripts/python.exe scripts/t2_27_programador.py --listar        # qué hay y cuándo toca
    .venv/Scripts/python.exe scripts/t2_27_programador.py --simular       # qué haría ahora, sin hacer nada
    .venv/Scripts/python.exe scripts/t2_27_programador.py                 # lo que corre la Tarea programada
    .venv/Scripts/python.exe scripts/t2_27_programador.py --forzar KFC_STATUS_MARTES --simular
    .venv/Scripts/python.exe scripts/t2_27_programador.py --instalar-tarea   # muestra cómo registrarlo en Windows

La Tarea programada de Windows NO se registra al construir: se registra al activar
(«--instalar-tarea» imprime el comando exacto).
"""
from __future__ import annotations

import argparse
import datetime as dt
import email.message
import email.utils
import imaplib
import json
import mimetypes
import os
import re
import smtplib
import socket
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import t2_27_fuentes as F  # noqa: E402

SITIO = "domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot"
VENTANA_HORAS = 6       # una tarea que no corrió en su hora se recupera si la estación prende dentro de 6 h
FIRMA = "Servicio al Cliente\nIndustec\nservicioalcliente@industec.me · www.industec.me"


def cli(argumentos: str, entrada: dict | None = None) -> dict:
    """Llama a automatizacion_cli.php en el sitio, por SSH. La única puerta al panel."""
    llave = os.environ.get("INDUSTEC_LLAVE_SSH") or str(F.BASE / "config" / "clave_hostinger")
    r = subprocess.run(["ssh", "-i", llave, "-o", "IdentitiesOnly=yes", "-p", "65002", "-o", "BatchMode=yes",
                        "-o", "ConnectTimeout=20", "u671729428@82.25.73.181",
                        f"cd {SITIO} && php automatizacion_cli.php {argumentos}"],
                       input=json.dumps(entrada) if entrada is not None else None,
                       capture_output=True, text=True, encoding="utf-8", timeout=120)
    try:
        datos = json.loads(r.stdout.strip().splitlines()[-1]) if r.stdout.strip() else {}
    except json.JSONDecodeError:
        datos = {}
    if r.returncode != 0:
        raise RuntimeError(f"automatizacion_cli.php {argumentos.split()[0]} salió con {r.returncode}: "
                           f"{datos.get('error') or r.stderr.strip()[:200]}")
    return datos


def horario_vigente(t: dict, ahora: dt.datetime) -> dt.datetime | None:
    """El último horario que le tocó (≤ ahora), si cae dentro de la ventana de recuperación."""
    h, m = (int(x) for x in str(t["hora"])[:5].split(":"))
    for atras in range(0, 2):
        d = (ahora - dt.timedelta(days=atras)).replace(hour=h, minute=m, second=0, microsecond=0)
        if d > ahora:
            continue
        toca = (int(t["dia_mes"]) == d.day) if t.get("dia_mes") else str(d.isoweekday()) in str(t["dias"]).split(",")
        if toca:
            return d if ahora - d <= dt.timedelta(hours=VENTANA_HORAS) else None
    return None


def proximo(t: dict, ahora: dt.datetime) -> dt.datetime | None:
    h, m = (int(x) for x in str(t["hora"])[:5].split(":"))
    for i in range(0, 62):
        d = (ahora + dt.timedelta(days=i)).replace(hour=h, minute=m, second=0, microsecond=0)
        if d <= ahora:
            continue
        if (int(t["dia_mes"]) == d.day) if t.get("dia_mes") else str(d.isoweekday()) in str(t["dias"]).split(","):
            return d
    return None


def archivos_nuevos(desde: float) -> list[Path]:
    return sorted(p for p in F.SALIDAS.rglob("*") if p.is_file() and "_ENTRADAS" not in p.parts
                  and p.stat().st_mtime >= desde and not p.name.endswith((".pkl", ".tmp")))


def smtp_config() -> dict | None:
    e = F.env()
    faltan = [k for k in ("SMTP_HOST", "SMTP_PORT", "SMTP_USER", "SMTP_PASSWORD") if not e.get(k)]
    if faltan:
        return None
    return {"host": e["SMTP_HOST"], "port": int(e["SMTP_PORT"]), "user": e["SMTP_USER"],
            "password": e["SMTP_PASSWORD"], "de": e.get("SMTP_DE") or e["SMTP_USER"]}


def mensaje_del_hilo(hilo: str) -> tuple[str, str] | None:
    """(Message-ID, asunto) del último correo del hilo, para responder en él. Solo lectura."""
    e = F.env()
    M = imaplib.IMAP4_SSL(e["IMAP_HOST"], int(e["IMAP_PORT"]))
    try:
        M.login(e["IMAP_USER"], e["IMAP_PASSWORD"])
        mejor = None
        for carpeta in ("INBOX", "Sent"):
            M.select(f'"{carpeta}"', readonly=True)
            desde = (dt.date.today() - dt.timedelta(days=21)).strftime("%d-%b-%Y")
            typ, ids = M.search(None, f"SINCE {desde}")
            for i in reversed(ids[0].split()[-400:]):
                typ, d = M.fetch(i, "(BODY.PEEK[HEADER.FIELDS (SUBJECT DATE MESSAGE-ID)])")
                cab = email.message_from_bytes(next(p[1] for p in d if isinstance(p, tuple)))
                asunto = F._decodificar(cab["Subject"])
                if hilo.upper() in asunto.upper():
                    fecha = email.utils.parsedate_to_datetime(cab["Date"])
                    if mejor is None or fecha > mejor[0]:
                        mejor = (fecha, cab["Message-ID"], asunto)
                    break
        return (mejor[1], mejor[2]) if mejor else None
    finally:
        try:
            M.logout()
        except Exception:
            pass


def armar_correo(t: dict, archivos: list[Path], para: list[str], cc: list[str], revision: bool) -> email.message.EmailMessage:
    msg = email.message.EmailMessage()
    texto = next((p.read_text(encoding="utf-8") for p in archivos if p.name.startswith("CORREO ") and p.suffix == ".txt"), "")
    if revision:
        msg["Subject"] = f"[Para revisar] {t['nombre']} — generado por el agente"
        cuerpo = ("Este es el borrador que generó el sistema. Revísalo y, si está bien, envíalo tú desde tu correo.\n\n"
                  + (("Texto propuesto para el correo:\n\n" + texto + "\n\n") if texto else "")
                  + "Archivos adjuntos:\n" + "\n".join(f"  - {p.name}" for p in archivos))
        adjuntos = [p for p in archivos if p.suffix in (".xlsx", ".pptx", ".html")]
    else:
        hilo = mensaje_del_hilo(t["hilo"]) if t.get("hilo") else None
        asunto = hilo[1] if hilo else (t.get("asunto") or t["nombre"])
        msg["Subject"] = asunto if asunto.upper().startswith("RE:") or not hilo else "RE: " + asunto
        if hilo and hilo[0]:
            msg["In-Reply-To"] = hilo[0]
            msg["References"] = hilo[0]
        cuerpo = (texto or f"Adjunto: {t['nombre']}.") + "\n\n" + FIRMA
        # Al cliente nunca va la revisión interna ni el texto del correo.
        adjuntos = [p for p in archivos if p.suffix in (".xlsx", ".pptx")
                    and not p.name.startswith(("REVISION ", "CORREO ", "TABLERO "))]
    msg["To"] = ", ".join(para)
    if cc:
        msg["Cc"] = ", ".join(cc)
    msg["Date"] = email.utils.formatdate(localtime=True)
    msg["Message-ID"] = email.utils.make_msgid(domain="industec.me")
    msg.set_content(cuerpo)
    for p in adjuntos:
        tipo, _ = mimetypes.guess_type(p.name)
        principal, sub = (tipo or "application/octet-stream").split("/", 1)
        # «(generado agente)» es la marca para la administración (I-4); al cliente le llega el
        # nombre de siempre: «STATUS_PENDIENTES_SEMANA 4 SEPT.xlsx».
        nombre = p.name if revision else p.name.replace(" (generado agente)", "").replace(" (respuesta INDUSTEC, generado agente)", "")
        msg.add_attachment(p.read_bytes(), maintype=principal, subtype=sub, filename=nombre)
    return msg


def enviar(msg: email.message.EmailMessage, cfg: dict) -> None:
    if msg["From"] is None:
        msg["From"] = cfg["de"]
    clase = smtplib.SMTP_SSL if cfg["port"] == 465 else smtplib.SMTP
    with clase(cfg["host"], cfg["port"], timeout=60) as s:
        if cfg["port"] != 465:
            s.starttls()
        s.login(cfg["user"], cfg["password"])
        s.send_message(msg)


def correr(t: dict, programada: dt.datetime, manual: bool, simular: bool) -> bool:
    dest = t.get("destinatarios", [])
    revisores = [d["correo"] for d in dest if d["tipo"] == "REVISION"]
    para = [d["correo"] for d in dest if d["tipo"] == "PARA"]
    cc = [d["correo"] for d in dest if d["tipo"] == "COPIA"]
    modo = t["modo"]
    print(f"\n== {t['clave']} · {t['nombre']} · horario {programada:%Y-%m-%d %H:%M} · modo {modo}{' · A MANO' if manual else ''}")
    print(f"   corre: {t['comando']}")
    if modo == "AVISAR":
        print(f"   avisa a: {', '.join(revisores) or '(nadie: falta revisor)'}")
    elif modo == "ENVIAR":
        print(f"   envía a: {', '.join(para) or '(nadie)'}" + (f" · copia {', '.join(cc)}" if cc else ""))
        if t.get("hilo"):
            print(f"   respondiendo en el hilo que contenga «{t['hilo']}»")
    if simular:
        print("   (simulación: no se corre, no se envía, no se registra)")
        return True

    ya = cli(f"corrida {t['clave']} '{programada:%Y-%m-%d %H:%M:%S}'").get("corrida")
    if ya and ya.get("estado") == "OK":
        print("   ya corrió en este horario: se omite")
        return True

    inicio = dt.datetime.now()
    marca = inicio.timestamp() - 1
    estado, enviado, mensaje = "OK", "NO", ""
    comando = [str(F.BASE / ".venv" / "Scripts" / "python.exe")] + t["comando"].split()
    if not re.fullmatch(r"scripts/t2_27_[a-z_]+\.py", t["comando"].split()[0]):
        raise SystemExit(f"ABORTADO: el comando de {t['clave']} no es un generador de T2.27: {t['comando']!r}")
    r = subprocess.run(comando, cwd=F.BASE, capture_output=True, text=True, encoding="utf-8",
                       env={**os.environ, "PYTHONUTF8": "1"}, timeout=3600)
    archivos = archivos_nuevos(marca)
    if r.returncode != 0:
        estado, mensaje = "ERROR", f"el generador salió con {r.returncode}: " + (r.stderr or r.stdout).strip()[-600:]
    elif modo in ("AVISAR", "ENVIAR"):
        cfg = smtp_config()
        destinos = revisores if modo == "AVISAR" else para
        if not cfg:
            estado, mensaje = "ERROR", "archivos generados, pero no se envió: falta SMTP_HOST/SMTP_PORT/SMTP_USER/SMTP_PASSWORD en config/.env"
        elif not destinos:
            estado, mensaje = "ERROR", "archivos generados, pero no se envió: no hay destinatarios activos para este modo"
        else:
            try:
                msg = armar_correo(t, archivos, destinos, [] if modo == "AVISAR" else cc, revision=(modo == "AVISAR"))
                enviar(msg, cfg)
                enviado = "REVISORES" if modo == "AVISAR" else "CLIENTE"
                mensaje = f"enviado a {len(destinos)} destinatario(s)"
            except Exception as ex:  # noqa: BLE001 — el motivo va entero al panel
                estado, mensaje = "ERROR", f"archivos generados, pero el envío falló: {ex}"
    salida = (r.stdout or "").strip().splitlines()
    if estado == "OK" and not mensaje:
        mensaje = salida[-1][:300] if salida else "generado"
    reg = {"clave": t["clave"], "programada_para": f"{programada:%Y-%m-%d %H:%M:%S}", "inicio": f"{inicio:%Y-%m-%d %H:%M:%S}",
           "fin": f"{dt.datetime.now():%Y-%m-%d %H:%M:%S}", "estado": estado, "enviado": enviado,
           "archivos": [p.name for p in archivos], "mensaje": mensaje, "equipo": socket.gethostname()[:60], "manual": manual}
    cli("registrar", reg)
    print(f"   {estado} · {enviado} · {len(archivos)} archivo(s) · {mensaje[:200]}")
    return estado == "OK"


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    ap.add_argument("--listar", action="store_true", help="muestra las tareas, su estado y cuándo les toca")
    ap.add_argument("--simular", action="store_true", help="dice qué haría ahora, sin correr, enviar ni registrar")
    ap.add_argument("--forzar", metavar="CLAVE", help="corre esa tarea ahora aunque no sea su hora (queda marcada «a mano»)")
    ap.add_argument("--ahora", help="fecha y hora de referencia AAAA-MM-DDTHH:MM (para probar horarios)")
    ap.add_argument("--instalar-tarea", action="store_true", help="imprime el comando para registrar la Tarea programada")
    a = ap.parse_args()
    if a.instalar_tarea:
        py = F.BASE / ".venv" / "Scripts" / "python.exe"
        print("Se registra AL ACTIVAR, con la aprobación de la administración. El comando es:\n")
        print(f'schtasks /Create /TN "INDUSTEC - Reportes programados" /SC MINUTE /MO 30 /ST 06:00 /DU 14:00 '
              f'/TR "\\"{py}\\" \\"{F.BASE / "scripts" / "t2_27_programador.py"}\\"" /RL LIMITED /F')
        print("\nCorre cada 30 minutos de 06:00 a 20:00. Sin tareas activas en el panel, no hace nada.")
        return
    ahora = dt.datetime.fromisoformat(a.ahora) if a.ahora else dt.datetime.now()
    tareas = cli("tareas")["tareas"]
    if a.listar:
        for t in tareas:
            prox = proximo(t, ahora)
            cuando = f"le toca {prox:%d/%m %H:%M}" if prox else "sin horario"
            print(f"{t['clave']:<26} {'ACTIVA  ' if int(t['activa']) else 'inactiva'} {t['modo']:<8} {cuando}"
                  f"{' (no corre: inactiva)' if not int(t['activa']) else ''} · {len(t['destinatarios'])} destinatarios activos")
        return
    ok = True
    candidatas = []
    for t in tareas:
        if a.forzar:
            if t["clave"] == a.forzar:
                candidatas.append((t, ahora.replace(second=0, microsecond=0), True))
            continue
        if not int(t["activa"]):
            continue
        h = horario_vigente(t, ahora)
        if h:
            candidatas.append((t, h, False))
    if a.forzar and not candidatas:
        raise SystemExit(f"No existe la tarea {a.forzar}.")
    if not candidatas:
        activas = sum(1 for t in tareas if int(t["activa"]))
        print(f"Nada que correr ahora ({ahora:%Y-%m-%d %H:%M}). Tareas activas en el panel: {activas} de {len(tareas)}.")
        return
    for t, h, manual in candidatas:
        try:
            ok = correr(t, h, manual, a.simular) and ok
        except Exception as ex:  # noqa: BLE001
            print(f"   ERROR: {ex}")
            ok = False
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
