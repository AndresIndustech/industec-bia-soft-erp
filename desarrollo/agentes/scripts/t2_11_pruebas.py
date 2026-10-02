"""
T2.11 / T2.29 - Pruebas del lector de informes de OT frente a los correos que ahora
manda la app (cuenta reclutamiento@, desde el 2026-09-29): los correos de PRUEBA
de la cuenta de envio ya no cuentan como «no se pudieron parsear», y las OT de la
app, que no traen «Fecha:» en el cuerpo, toman la del encabezado Date del correo.
IMAP SIMULADO: no se conecta a nada y no escribe ningun archivo.

Uso:
    .venv/Scripts/python.exe scripts/t2_11_pruebas.py      # sale con 1 si algo falla
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
import t2_11_informes_ot as T  # noqa: E402

total = fallos = 0


def afirmar(que, real, esperado):
    global total, fallos
    total += 1
    ok = real == esperado
    if not ok:
        fallos += 1
    print(f"  {'ok   ' if ok else 'FALLA'} {que}" + ("" if ok else f"  -> dio {real!r}, esperaba {esperado!r}"))


class IMAPFalso:
    """Lo minimo de imaplib que usa t2_11: select, search y fetch por lote.
    `mensajes` = {id: {"cuerpo": str, "date": str}}, en una sola carpeta."""
    def __init__(self, mensajes):
        self.mensajes = mensajes
        self.pedidos = []

    def select(self, carpeta, readonly=True):
        return "OK", [b"1"]

    def search(self, charset, *criterio):
        return "OK", [b" ".join(str(i).encode() for i in self.mensajes)]

    def fetch(self, ids, partes):
        self.pedidos.append(partes)
        salida = []
        for i in ids.split(b","):
            m = self.mensajes[int(i)]
            if "HEADER.FIELDS (DATE)" in partes:
                crudo = f"Date: {m['date']}\r\n\r\n".encode()
                salida.append((i + b" (BODY[HEADER.FIELDS (DATE)] {%d}" % len(crudo), crudo))
            else:
                crudo = m["cuerpo"].encode("utf-8")
                salida.append((i + b" (BODY[1] {%d}" % len(crudo), crudo))
            salida.append(b")")
        return "OK", salida


VIEJO = ("Se ha generado una nueva OT: OT-1964-G018-10357626-UIO\r\nZona: UIO\r\nFecha: 2026-10-01 11:33:54\r\n"
         "Local: G018 \r\nORDEN SAP: 10357626 \r\nTipo de Trabajo: Correctivo \r\nEstado de OT: Cerrada \r\n"
         "Equipo: Freidora de presas \r\nEstado de Equipo: Operativo")
APP = ("Se ha generado una nueva OT: OT-1964-G021EC-10358019-UIO\r\nZona: UIO\r\nLocal: G021EC\r\n"
       "ORDEN SAP: 10358019\r\nTipo de Trabajo: Correctivo\r\nEstado de OT: Abierta\r\n"
       "Técnico: Anthony Guillermo Morales Chugchilan")
PRUEBA_CUENTA = ("Este es un correo de prueba de B.IA Soft ERP para comprobar la cuenta de envío «Órdenes de Trabajo "
                 "INDUSTEC» (reclutamiento@industec.me).\r\n\r\nNo es una OT INDUSTEC y no hay que hacer nada con él.")
PRUEBA_LOTE = "Prueba del envío de la cola de B.IA Soft ERP: dos correos por la misma conexión.\r\nNo es una OT."

print("=== 1. Correos de prueba de la app: no son «no parseables» ===")
afirmar("la prueba de la cuenta de envio se reconoce", T.es_correo_de_prueba_del_sistema(PRUEBA_CUENTA), True)
afirmar("la prueba del despacho en lote se reconoce", T.es_correo_de_prueba_del_sistema(PRUEBA_LOTE), True)
afirmar("una OT NO es de prueba", T.es_correo_de_prueba_del_sistema(APP), False)
afirmar("«B.IA Soft ERP» sin «prueba» NO se ignora (I-7: se sigue reportando)",
        T.es_correo_de_prueba_del_sistema("Resumen semanal de B.IA Soft ERP: 12 órdenes"), False)
afirmar("«prueba» sin «B.IA Soft ERP» NO se ignora", T.es_correo_de_prueba_del_sistema("Hola, esta es una prueba de algo"), False)
afirmar("cuerpo vacio o None no se ignora", (T.es_correo_de_prueba_del_sistema(""), T.es_correo_de_prueba_del_sistema(None)), (False, False))
afirmar("la mencion tiene que estar al comienzo (200 caracteres), no en el pie",
        T.es_correo_de_prueba_del_sistema("Hola. " + "x" * 300 + " prueba de B.IA Soft ERP"), False)

M = IMAPFalso({1: {"cuerpo": VIEJO, "date": "Thu, 1 Oct 2026 11:33:56 -0500"},
               2: {"cuerpo": APP, "date": "Thu, 1 Oct 2026 09:49:07 -0500"},
               3: {"cuerpo": PRUEBA_CUENTA, "date": "Tue, 29 Sep 2026 21:25:19 -0500"},
               4: {"cuerpo": PRUEBA_LOTE, "date": "Tue, 29 Sep 2026 21:52:28 -0500"},
               5: {"cuerpo": "Hola, adjunto el informe de ayer", "date": "Tue, 29 Sep 2026 10:00:00 -0500"},
               6: {"cuerpo": "Resumen semanal de B.IA Soft ERP: 12 órdenes", "date": "Tue, 29 Sep 2026 10:00:00 -0500"}})
informes, sin_parsear, por_carpeta, pruebas = T.leer_informes(M, 90, ["INBOX"])
afirmar("leer_informes devuelve los informes de OT (la vieja y la de la app)", [i["ot"] for i in informes],
        ["OT-1964-G018-10357626-UIO", "OT-1964-G021EC-10358019-UIO"])
afirmar("las dos pruebas de la app van aparte", [p["id_imap"] for p in pruebas], ["3", "4"])
afirmar("lo que de verdad no se entiende sigue siendo «no parseable»", [s["id_imap"] for s in sin_parsear], ["5", "6"])
afirmar("por carpeta cuenta solo los informes de OT", por_carpeta, {"INBOX": 2})

print("\n=== 2. La fecha de las OT de la app: la del encabezado Date, solo para ORDENAR ===")
afirmar("el informe viejo trae su «Fecha:»", informes[0]["fecha"], "2026-10-01 11:33:54")
afirmar("el de la app no la trae", informes[1]["fecha"], None)
M.pedidos.clear()
n = T.completar_fechas(M, informes)
afirmar("completar_fechas anota la del correo solo al que no tenia fecha", (n, informes[1].get("fecha_correo")), (1, "2026-10-01 09:49:07"))
afirmar("`fecha` sigue vacia: el servidor no debe tomar la hora del despacho como atendido_en", informes[1]["fecha"], None)
afirmar("no toca la del formulario viejo", (informes[0]["fecha"], "fecha_correo" in informes[0]), ("2026-10-01 11:33:54", False))
afirmar("pide SOLO el encabezado Date de ese correo (no los demas, ni el cuerpo)", M.pedidos, ["(BODY.PEEK[HEADER.FIELDS (DATE)])"])
afirmar("repetirlo es idempotente: anota lo mismo y no acumula nada", (T.completar_fechas(M, informes), informes[1]["fecha_correo"]), (1, "2026-10-01 09:49:07"))

# El caso que motivo el diseño: un aviso con una OT cerrada del formulario viejo
# (dia 28) y otra mas nueva de la app (dia 1). Reconciliar toma como OT de cierre
# la ULTIMA cerrada de la lista: tiene que ser la de la app.
vieja_28 = {"ot": "OT-1900-G018-10357626-UIO", "fecha": "2026-09-28 10:00:00", "estado_ot": "Cerrada"}
app_01 = {"ot": "OT-1964-G018EC-10357626-UIO", "fecha": None, "fecha_correo": "2026-10-01 09:49:07", "estado_ot": "Cerrada"}
afirmar("ordena por fecha o, si no hay, por la del correo: la de la app (mas nueva) queda ultima",
        [x["ot"] for x in sorted([app_01, vieja_28], key=T.fecha_para_ordenar)],
        ["OT-1900-G018-10357626-UIO", "OT-1964-G018EC-10357626-UIO"])
afirmar("sin la fecha del correo, la de la app iria PRIMERA y la vieja ganaria (el defecto que se corrige)",
        [x["ot"] for x in sorted([dict(app_01, fecha_correo=None), vieja_28], key=T.fecha_para_ordenar)],
        ["OT-1964-G018EC-10357626-UIO", "OT-1900-G018-10357626-UIO"])
afirmar("una OT sin ninguna fecha (ni cuerpo ni correo) sigue yendo primera, sin romper el orden", T.fecha_para_ordenar({}), "")

afirmar("hora de Ecuador desde UTC", T.fecha_del_encabezado("Thu, 1 Oct 2026 14:49:07 +0000"), "2026-10-01 09:49:07")
afirmar("hora de Ecuador desde otro desfase", T.fecha_del_encabezado("Thu, 1 Oct 2026 16:49:07 +0200"), "2026-10-01 09:49:07")
afirmar("cambia de dia cuando toca (00:30 UTC es la noche anterior)", T.fecha_del_encabezado("Thu, 1 Oct 2026 00:30:00 +0000"), "2026-09-30 19:30:00")
afirmar("encabezado ilegible -> None, no se inventa", (T.fecha_del_encabezado("no es una fecha"), T.fecha_del_encabezado(None)), (None, None))
afirmar("sin zona horaria (-0000) -> None: no se adivina la hora", T.fecha_del_encabezado("Thu, 1 Oct 2026 09:49:07 -0000"), None)

M2 = IMAPFalso({1: {"cuerpo": APP, "date": "basura"}})
inf2, _, _, _ = T.leer_informes(M2, 90, ["INBOX"])
afirmar("si el Date no se puede leer, no se anota nada", (T.completar_fechas(M2, inf2), inf2[0].get("fecha_correo")), (0, None))

print(f"\n{total} comprobaciones · {fallos} fallos")
sys.exit(1 if fallos else 0)
