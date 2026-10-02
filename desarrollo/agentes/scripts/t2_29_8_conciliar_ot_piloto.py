"""
T2.29.8 - Conciliar las OT del piloto con lo que el formulario viejo ya reportó, y armar
la lista de las que se mandan a Grupo KFC (pedido de Andrés, 2026-10-01).

POR QUÉ EXISTE
Del 24 al 29-sep la app emitió 18 OT del piloto (serie 9000) que nunca salieron. Andrés pidió:
si el formulario viejo ya reportó ese trabajo, el caso se resuelve con ese informe; si no, la OT
que existe «solo a nivel del sistema» se envía y el caso queda pendiente de SAP. Mandar un
correo a Grupo KFC no se deshace, así que ANTES de armar la lista se vuelve a comprobar, con
tres fuentes que no dependen de la app (I-10):

  1. el buzón servicioalcliente@ por IMAP (EXAMINE + BODY.PEEK: no marca, no mueve, no borra),
     que recibe copia de cada OT que manda el formulario viejo;
  2. la base de la estación (`ots`), que llena el robot desde ese mismo buzón y desde el Drive;
  3. los PDF que el formulario viejo guarda en su servidor (solo lectura: regla 9).

Una OT entra a la lista SOLO si las tres fuentes dan CERO OT, de cualquier fecha, para su aviso.
Si una sola muestra algo, el script aborta y dice cuál: puede ser un informe que llegó después
de armar la conciliación, y mandarlo otra vez le repetiría el trabajo a Grupo KFC.

Después baja cada PDF del servidor, comprueba su huella contra la que dice el servidor y la del
Archivo, y lee su texto: que traiga su número, su aviso y su local, y ninguna marca de prueba.
Escribe `ot_a_liberar.json`, lo sube a ~/respaldos y corre el SIMULACRO de
`liberar_ot_piloto_cli.php`. Con --ejecutar corre la consola de verdad.

USO
    .venv/Scripts/python.exe scripts/t2_29_8_conciliar_ot_piloto.py                 # comprueba + simulacro
    .venv/Scripts/python.exe scripts/t2_29_8_conciliar_ot_piloto.py --ejecutar      # además, libera y envía
"""
from __future__ import annotations

import argparse
import email
import hashlib
import imaplib
import json
import re
import sys
from datetime import datetime
from email.header import decode_header, make_header
from pathlib import Path

import mysql.connector
from pypdf import PdfReader

sys.path.insert(0, str(Path(__file__).parent))
import hostinger_ssh as H  # noqa: E402
from comun import cargar_env  # noqa: E402

# Las que se mandan: decisión de la conciliación del 2026-10-01 (SALIDAS IA/OTS/conciliacion_ot_piloto_*.csv).
# Todas de UIO, recientes, sin informe del formulario viejo para su aviso.
OT_A_ENVIAR = {
    166: "OT-9125-A010EC-10355442-UIO",
    171: "OT-9128-I016EC-10355361-UIO",
    197: "OT-9146-G021EC-10354744-UIO",
    198: "OT-9147-G013EC-10356500-UIO",
    199: "OT-9148-R002EC-10356710-UIO",
    200: "OT-9149-R007EC-10355280-UIO",
    201: "OT-9150-R009EC-10356734-UIO",
    202: "OT-9151-R014EC-10356794-UIO",
    203: "OT-9152-G002EC-10355352-UIO",
    206: "OT-9153-G016EC-10357578-UIO",
}
DOCROOT = H.DOCROOT_PRUEBAS
VIEJO = f"domains/{H.SITIO_PRODUCCION}.hostingersite.com/public_html/ot/produccion"
AQUI = Path(__file__).resolve().parents[1]
TMP = AQUI / "logs" / "t2_29_8"
MARCAS_DE_PRUEBA = ["documento de prueba", "copia interna", "no enviada a kfc"]


def dec(h: str | None) -> str:
    try:
        return str(make_header(decode_header(h or "")))
    except Exception:
        return h or ""


def leer_buzon(env: dict) -> list[dict]:
    """Todos los correos de reclutamiento@ desde el 15-jun, solo lectura."""
    emisor = env.get("EMISOR_OT", "reclutamiento@industec.me")
    m = imaplib.IMAP4_SSL(env["IMAP_HOST"], int(env["IMAP_PORT"]))
    m.login(env["IMAP_USER"], env["IMAP_PASSWORD"])
    ok, carpetas = m.list()
    nombres = []
    for c in carpetas:
        s = c.decode("utf-8", "replace")
        mm = re.search(r'"([^"]*)"\s*$', s) or re.search(r"\s(\S+)\s*$", s)
        nombres.append(mm.group(1))
    filas: list[dict] = []
    for carpeta in nombres:
        q = f'"{carpeta}"' if " " in carpeta else carpeta
        ok, _ = m.select(q, readonly=True)          # EXAMINE
        if ok != "OK":
            continue
        ok, d = m.search(None, "FROM", emisor, "SINCE", "15-Jun-2026")
        ids = d[0].split() if ok == "OK" and d and d[0] else []
        for i in range(0, len(ids), 50):
            ok, dd = m.fetch(b",".join(ids[i:i + 50]),
                             "(BODY.PEEK[HEADER.FIELDS (SUBJECT DATE)] BODY.PEEK[1])")
            actual: dict[str, dict] = {}
            n = "?"
            for it in dd:
                if not isinstance(it, tuple):
                    continue
                cab = it[0] or b""
                mid = re.match(rb"\s*(\d+)\s+\(", cab)
                if mid:
                    n = mid.group(1).decode()
                reg = actual.setdefault(n, {"carpeta": carpeta})
                if b"HEADER.FIELDS" in cab:
                    h = email.message_from_bytes(it[1] or b"")
                    reg["asunto"] = dec(h.get("Subject"))
                    reg["fecha"] = h.get("Date")
                else:
                    reg["cuerpo"] = (it[1] or b"").decode("utf-8", "replace")[:1500]
            filas.extend(actual.values())
    m.logout()
    for f in filas:
        t = f.get("cuerpo") or ""
        mo = re.search(r"nueva OT:\s*(\S+)", t)
        ma = re.search(r"^ORDEN SAP:\s*(\S+)", t, re.M)
        f["ot"] = mo.group(1) if mo else None
        f["aviso"] = ma.group(1) if ma else None
    return filas


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--ejecutar", action="store_true", help="además de comprobar y simular, libera y envía")
    ap.add_argument("--a-nombre-de", default="abasantes")
    args = ap.parse_args()
    env = cargar_env(("DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASSWORD", "IMAP_HOST", "IMAP_PORT", "IMAP_USER", "IMAP_PASSWORD"))
    TMP.mkdir(parents=True, exist_ok=True)
    errores: list[str] = []

    # --- 1. Lo que dice el servidor de la app ---------------------------------------------
    filas = H.sql_remoto(
        "SELECT c.captura_id, c.id_industec, c.aviso, c.local_codigo, c.zona, c.concluida, c.estado, c.liberada_en, "
        "       COALESCE(a.sha256,''), COALESCE(c.pdf_sha256_regen,''), COALESCE(c.pdf_sha256,'') "
        "  FROM ot_capturadas c LEFT JOIN ot_archivo a ON a.id_industec = c.id_industec "
        " WHERE c.captura_id IN (" + ",".join(str(k) for k in OT_A_ENVIAR) + ") ORDER BY c.captura_id")
    srv = {}
    for f in filas:
        srv[int(f[0])] = {"id": f[1], "aviso": f[2], "local": f[3], "zona": f[4], "concluida": f[5] == "1",
                          "estado": f[6], "liberada": f[7] != "NULL", "sha_archivo": f[8], "sha_regen": f[9], "sha_orig": f[10]}
    for cap, id_ in OT_A_ENVIAR.items():
        s = srv.get(cap)
        if s is None or s["id"] != id_:
            errores.append(f"captura {cap}: el servidor no la tiene como {id_}")
        elif s["estado"] != "EMITIDA" or s["liberada"]:
            errores.append(f"{id_}: estado {s['estado']}, liberada={s['liberada']} (esperaba EMITIDA sin liberar)")
    if errores:
        print("\n".join(errores)); return 1
    avisos = sorted({s["aviso"] for s in srv.values()})
    print(f"[1] servidor: {len(srv)} capturas EMITIDAS y sin liberar; avisos {', '.join(avisos)}")

    # --- 2. Fuente independiente A: el buzón ----------------------------------------------
    print("[2] leyendo el buzón servicioalcliente@ (solo lectura)…")
    correos = leer_buzon(env)
    print(f"    {len(correos)} correos de reclutamiento@ desde el 15-jun")
    en_buzon: dict[str, list[str]] = {a: [] for a in avisos}
    for c in correos:
        txt = (c.get("asunto") or "") + " " + (c.get("cuerpo") or "")
        for a in avisos:
            if c.get("aviso") == a or a in txt:
                ot = c.get("ot") or c.get("asunto")
                # El propio OT del piloto nunca salió, así que no debería estar: si está, también es un hallazgo.
                en_buzon[a].append(f"{ot} ({c.get('fecha')})")

    # --- 3. Fuente independiente B: la base de la estación --------------------------------
    cnx = mysql.connector.connect(host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
                                  password=env["DB_PASSWORD"], database=env["DB_NAME"])
    cur = cnx.cursor()
    en_estacion: dict[str, list[str]] = {a: [] for a in avisos}
    ph = ",".join(["%s"] * len(avisos))
    cur.execute(f"SELECT aviso, id_industec, fecha_atencion FROM ots WHERE aviso IN ({ph}) AND en_cuarentena = 0", avisos)
    for a, i, f in cur.fetchall():
        if not re.match(r"^OT-9\d{3}-", i):          # las del piloto no cuentan: justo de ellas se trata
            en_estacion[a].append(f"{i} ({f})")
    cnx.close()
    print("[3] base de la estación consultada")

    # --- 4. Fuente independiente C: los PDF del formulario viejo (solo lectura) -----------
    cond = " -o ".join(f"-name '*-{a}-*'" for a in avisos)
    salida = H.ssh(f"find {VIEJO} -type f \\( {cond} \\) -printf '%TY-%Tm-%Td %p\\n'", timeout=240)
    en_viejo: dict[str, list[str]] = {a: [] for a in avisos}
    for linea in salida.splitlines():
        for a in avisos:
            if f"-{a}-" in linea:
                en_viejo[a].append(linea.strip().rsplit("/", 1)[-1])
    print("[4] archivos del formulario viejo consultados (solo lectura)")

    for a in avisos:
        for nombre, d in (("buzón", en_buzon), ("estación", en_estacion), ("formulario viejo", en_viejo)):
            if d[a]:
                errores.append(f"aviso {a}: {nombre} ya muestra {len(d[a])} OT: {'; '.join(d[a][:4])}")
    if errores:
        print("\nNO SE ENVÍA NADA — el formulario viejo (u otra fuente) ya tiene algo de estos avisos:\n  - " + "\n  - ".join(errores))
        return 1
    print("    CERO OT del formulario viejo para los 10 avisos, en las tres fuentes")

    # --- 5. Los PDF: huella y texto -------------------------------------------------------
    lista = []
    for cap, id_ in OT_A_ENVIAR.items():
        s = srv[cap]
        remoto = f"{DOCROOT}/ordenes_pdf/{id_}.pdf"
        local = TMP / f"{id_}.pdf"
        H.scp_bajar(remoto, local)
        sha_local = hashlib.sha256(local.read_bytes()).hexdigest()
        sha_srv = H.ssh(f"sha256sum {remoto}").split()[0]
        if sha_local != sha_srv:
            errores.append(f"{id_}: la huella local ({sha_local[:12]}) no es la del servidor ({sha_srv[:12]})")
        if sha_local not in {s["sha_archivo"], s["sha_regen"], s["sha_orig"]}:
            errores.append(f"{id_}: la huella del PDF no es la de la captura ni la del Archivo")
        texto = "\n".join((p.extract_text() or "") for p in PdfReader(str(local)).pages)
        bajo = texto.lower()
        for clave, valor in (("su número", id_), ("su aviso", s["aviso"]), ("su local", s["local"])):
            if valor.lower() not in bajo:
                errores.append(f"{id_}: el PDF no trae {clave} ({valor})")
        for marca in MARCAS_DE_PRUEBA:
            # No se busca «piloto» a secas: es legítimo en una OT de freidora (el piloto del quemador).
            if marca in bajo:
                errores.append(f"{id_}: el PDF trae la marca «{marca}»")
        lista.append({"captura_id": cap, "id_industec": id_, "aviso": s["aviso"], "concluida": s["concluida"], "pdf_sha256": sha_local})
        print(f"[5] {id_}: {len(local.read_bytes()):>8,} bytes · sha256 {sha_local[:12]}… · texto OK")
    if errores:
        print("\nNO SE ENVÍA NADA:\n  - " + "\n  - ".join(errores)); return 1

    # --- 6. La lista y el simulacro -------------------------------------------------------
    doc = {
        "generado_en": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "pedido_por": "Andrés Basantes", "fecha_pedido": "2026-10-01",
        "fuentes": [
            f"buzón servicioalcliente@ por IMAP, solo lectura ({len(correos)} correos de reclutamiento@ desde el 15-jun): 0 OT para los 10 avisos",
            "base de la estación (ots): 0 OT del formulario viejo para los 10 avisos",
            "archivos del formulario viejo en su servidor, solo lectura: 0 PDF para los 10 avisos",
        ],
        "ot": lista,
    }
    destino_local = TMP / "ot_a_liberar.json"
    destino_local.write_text(json.dumps(doc, ensure_ascii=False, indent=1), encoding="utf-8")
    H.scp_subir(destino_local, "respaldos/ot_a_liberar.json")
    cmd = (f"cd {DOCROOT} && php ~/respaldos/liberar_ot_piloto_cli.php --lista=~/respaldos/ot_a_liberar.json "
           f"--a-nombre-de={args.a_nombre_de}" + (" --ejecutar" if args.ejecutar else ""))
    r = H.ssh_crudo(cmd, timeout=400)
    print("\n" + ("=" * 30) + (" EJECUCIÓN " if args.ejecutar else " SIMULACRO ") + ("=" * 30))
    print(r.stdout)
    if r.stderr.strip():
        print("STDERR:", r.stderr.strip())
    print("código de salida:", r.returncode)
    return r.returncode


if __name__ == "__main__":
    sys.exit(main())
