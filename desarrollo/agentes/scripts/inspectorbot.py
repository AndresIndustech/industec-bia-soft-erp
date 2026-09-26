# -*- coding: utf-8 -*-
"""
InspectorBot - la ventana que vigila al robot del correo.

QUE ES
La versión con pantalla de `t2_22_consola_robot.py`. Lo mismo que hacía la
consola de texto, pero legible de un vistazo desde el otro lado del escritorio:
un semáforo grande, la lista de lo que está mal ordenada por gravedad, y las
cifras del buzón y de Yellow Elephant en barras en vez de en columnas de texto.

La consola de texto se conserva (`scripts\\consola.bat`): sigue siendo la forma
de mirar esto por SSH o sin escritorio.

POR QUE UNA VENTANA Y NO EL CMD
Tres motivos medidos, no de gusto:
  1. La consola de Windows rompe los acentos. Los nombres de los técnicos vienen
     con tilde correcta en el JSON (`Meléndrez`, `Chávez`) y en el CMD salen
     como `Mel?ndrez`. Esta pantalla la mira una persona.
  2. Una ventana con icono propio se queda abierta en la barra de tareas. Una
     ventana negra de CMD se cierra sin pensar.
  3. El color y el tamaño permiten poner la alerta grave arriba y grande. En
     texto plano, «el nocturno no ha corrido nunca» ocupa lo mismo que
     «casos vigentes: 898».

SOLO LECTURA, SIN EXCEPCIONES. Todo el cálculo vive en `inspectorbot_estado.py`
y ese módulo no escribe nada. Esta ventana solo pinta y, si se pulsa un botón,
abre un archivo o una carpeta para mirarlos.

USO
    .venv\\Scripts\\InspectorBot.exe scripts\\inspectorbot.py   (lo normal)
    .venv\\Scripts\\python.exe scripts\\inspectorbot.py         (para ver errores)

El ejecutable con nombre e icono propios lo fabrica `crear_inspectorbot.py`.
"""
from __future__ import annotations

import ctypes
import os
import sys
import threading
import tkinter as tk
from ctypes import wintypes
from pathlib import Path
from tkinter import font as tkfont

sys.path.insert(0, str(Path(__file__).parent))
import inspectorbot_estado as est  # noqa: E402
from inspectorbot_estado import GRAVE, LEVE, MEDIO, fmt, hace_cuanto  # noqa: E402
from comun import corto, termino  # noqa: E402

ICONO = est.BASE / "recursos" / "InspectorBot.ico"

# --- Paleta -------------------------------------------------------------------
# Es la de B.IA Soft ERP (app/publico/estilo.css) llevada a fondo oscuro: los
# acentos y los colores de zona son EXACTAMENTE los mismos que en la web, para
# que UIO sea del mismo violeta aquí y allá. Fondo oscuro porque esta ventana se
# queda abierta todo el día y porque hace que el rojo de una alerta salte.
FONDO = "#0b1220"        # --ink llevado al fondo
PANEL = "#111c2e"
PANEL2 = "#16243a"
BORDE = "#1e3049"
TINTA = "#e8eef6"
SUAVE = "#8aa0bb"
TENUE = "#5b7291"
ACENTO = "#0ea5e9"       # --accent
OK = "#4ade80"           # --ok, aclarado para fondo oscuro
WARN = "#fbbf24"         # --warn
MAL = "#f87171"          # --danger
ZONA = {"UIO": "#a78bfa", "LARB": "#2dd4bf", "CNLJ": "#fb923c",
        "PREVENTIVO": "#38bdf8", "OTROS CLIENTES": "#94a3b8", "OTRA": "#94a3b8"}
COLOR_GRAVEDAD = {GRAVE: MAL, MEDIO: WARN, LEVE: ACENTO}

F, FM = "Segoe UI", "Consolas"
M = 20                   # margen lateral
REFRESCO_MS = 1000       # el reloj
RECOLECTA_SEG = 8        # cada cuánto se relee el estado


def redondeado(c, x1, y1, x2, y2, r=12, **kw):
    """Tkinter no tiene rectángulo redondeado; un polígono suavizado sí."""
    pts = [x1 + r, y1, x2 - r, y1, x2, y1, x2, y1 + r, x2, y2 - r, x2, y2,
           x2 - r, y2, x1 + r, y2, x1, y2, x1, y2 - r, x1, y1 + r, x1, y1]
    return c.create_polygon(pts, smooth=True, **kw)


def abrir(ruta) -> None:
    """Abre un archivo o carpeta con lo que Windows tenga asociado. Es lectura:
    no modifica nada."""
    try:
        if ruta and Path(ruta).exists():
            os.startfile(str(ruta))         # noqa: S606 - es la API de Windows
    except OSError:
        pass


class InspectorBot(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("InspectorBot")
        self.configure(bg=FONDO)

        # OBLIGATORIO, no decorativo: Tk instala su propia pluma como icono de
        # clase, así que sin esto la barra de tareas muestra la pluma de Tcl/Tk
        # por muy bien que esté marcado el .exe. `default=` lo heredan también
        # los diálogos. No llamar a iconphoto después: ImageTk sobre un .ico
        # toma el frame de 256 y Tk lo reduce mal.
        if ICONO.exists():
            try:
                self.iconbitmap(default=str(ICONO))
            except tk.TclError:
                pass

        pw, ph = self.winfo_screenwidth(), self.winfo_screenheight()
        w, h = min(1280, pw - 60), min(860, ph - 90)
        self.geometry(f"{w}x{h}+{(pw - w) // 2}+{max(10, (ph - h) // 2 - 20)}")
        self.minsize(880, 520)

        self.f_titulo = tkfont.Font(family=F, size=21, weight="bold")
        self.f_seccion = tkfont.Font(family=F, size=11, weight="bold")
        self.f_chip = tkfont.Font(family=F, size=9)

        self.c = tk.Canvas(self, bg=FONDO, highlightthickness=0)
        self.c.pack(fill="both", expand=True)

        self.estado: dict | None = None
        self.error_recolecta: str | None = None
        self.desplazamiento = 0
        self.alto_contenido = 0
        self.botones: list[tuple] = []
        self.nuevos_pdf: list[str] = []
        self.nuevos_avisos: list[str] = []
        self.base_pdf: dict | None = None
        self.base_casos: int | None = None

        self.c.bind("<MouseWheel>", self._rueda)
        self.c.bind("<Button-1>", self._clic)
        self.bind("<Control-r>", lambda e: self._recolectar_ya())
        self.bind("<F5>", lambda e: self._recolectar_ya())
        self.bind("<Escape>", lambda e: self.destroy())

        self._arrancar_recolector()
        self._latido()

    # ---------------------------------------------------------------- datos
    def _arrancar_recolector(self) -> None:
        """La lectura va en un hilo aparte.

        Consultar los procesos lanza PowerShell, que tarda alrededor de un
        segundo y a veces bastante más. Hacerlo en el hilo de la interfaz
        congela la ventana justo cuando el equipo está cargado, que es cuando
        más ganas dan de mirarla.
        """
        self._pedir = threading.Event()

        def bucle():
            while True:
                try:
                    nuevo = est.instantanea()
                    self.estado, self.error_recolecta = nuevo, None
                    self._anotar_novedades(nuevo)
                except Exception as e:                # la ventana nunca muere
                    self.error_recolecta = f"{type(e).__name__}: {e}"
                self._pedir.wait(RECOLECTA_SEG)
                self._pedir.clear()

        threading.Thread(target=bucle, daemon=True, name="inspectorbot").start()

    def _recolectar_ya(self) -> None:
        est._cache_ps["cuando"] = 0.0                 # que no sirva el cacheado
        self._pedir.set()

    def _anotar_novedades(self, e: dict) -> None:
        """Lo que aparece con la ventana abierta.

        El ritmo real del espejo es de unidades por día, así que sin esta lista
        la pantalla parece congelada incluso cuando todo va bien: cuando por fin
        entra un informe, hay que verlo y que se quede visible.
        """
        ahora_pdf = {m: d["nombres"] for m, d in e["espejo"]["por_modulo"].items()}
        if self.base_pdf is None:
            self.base_pdf = ahora_pdf
        else:
            for mod, nombres in ahora_pdf.items():
                for n in sorted(nombres - self.base_pdf.get(mod, set())):
                    if n not in self.nuevos_pdf:
                        self.nuevos_pdf.append(n)

        casos = e["buzon"].get("casos")
        if casos is not None:
            if self.base_casos is None:
                self.base_casos = casos
            elif casos > self.base_casos:
                self.nuevos_avisos.append(
                    f"{e['momento']:%H:%M}  entraron {casos - self.base_casos} "
                    f"requerimientos nuevos (van {fmt(casos)})")
                self.base_casos = casos

    # ------------------------------------------------------------ interacción
    def _rueda(self, ev) -> None:
        alto = self.c.winfo_height()
        if self.alto_contenido <= alto:
            return
        self.desplazamiento = max(min(self.desplazamiento + (120 if ev.delta > 0 else -120),
                                      0), alto - self.alto_contenido)
        self._pintar()

    def _clic(self, ev) -> None:
        for x1, y1, x2, y2, accion in self.botones:
            if x1 <= ev.x <= x2 and y1 <= ev.y <= y2:
                accion()
                return

    # ------------------------------------------------------------------ pintar
    def _latido(self) -> None:
        self._pintar()
        self.after(REFRESCO_MS, self._latido)

    def _boton(self, x, y, etiqueta, accion, ancho=None):
        w = ancho or self.f_chip.measure(etiqueta) + 26
        redondeado(self.c, x, y, x + w, y + 24, 8, fill=PANEL2, outline=BORDE)
        self.c.create_text(x + w / 2, y + 12, text=etiqueta, font=self.f_chip, fill=SUAVE)
        self.botones.append((x, y, x + w, y + 24, accion))
        return w

    def _pintar(self) -> None:
        c = self.c
        c.delete("all")
        self.botones = []
        W = c.winfo_width() or 1280
        H = c.winfo_height() or 800
        e = self.estado
        y = 16 + self.desplazamiento

        # ------------------------------------------------------ barra superior
        c.create_text(M + 2, y + 12, text="InspectorBot", anchor="w",
                      font=self.f_titulo, fill=TINTA)
        c.create_text(M + 6 + self.f_titulo.measure("InspectorBot") + 12, y + 16,
                      text="vigilante del buzón · B.IA Soft ERP", anchor="w",
                      font=(F, 10), fill=TENUE)
        reloj = est.ahora()
        c.create_text(W - M, y + 6, text=f"{reloj:%d/%m/%Y  %H:%M:%S}", anchor="e",
                      font=(FM, 12), fill=SUAVE)
        c.create_text(W - M, y + 26, anchor="e", font=(F, 9), fill=TENUE,
                      text=f"se actualiza solo cada {RECOLECTA_SEG} s · Ctrl+R ahora")
        y += 42
        c.create_line(M, y, W - M, y, fill=BORDE)
        y += 14

        if e is None:
            c.create_text(W / 2, H / 2, text="Leyendo el estado del robot…",
                          font=(F, 13), fill=SUAVE)
            if self.error_recolecta:
                c.create_text(W / 2, H / 2 + 26, text=self.error_recolecta,
                              font=(FM, 9), fill=MAL)
            self.alto_contenido = H
            return

        y = self._franja_estado(c, W, y, e)
        y = self._alertas(c, W, y, e)
        y = self._kpis(c, W, y, e)
        y = self._paneles(c, W, y, e)
        y = self._novedades(c, W, y)
        y = self._registro(c, W, y, e)

        c.create_text(M + 2, y + 10, anchor="w", font=(F, 9), fill=TENUE,
                      text="Solo mira: no escribe, no borra, no toca la base y no se "
                           "conecta a Hostinger. Ciérralo cuando quieras; el robot sigue solo.")
        self.alto_contenido = y + 30 - self.desplazamiento

    # ------------------------------------------------------------------ bloques
    def _franja_estado(self, c, W, y, e) -> int:
        r, reg = e["robot"], e["registro"]
        h = 104
        redondeado(c, M, y, W - M, y + h, 14, fill=PANEL, outline=BORDE)

        if r["consulta_error"]:
            col, titulo = WARN, "NO SE PUEDE COMPROBAR SI EL ROBOT VIVE"
            detalle = r["consulta_error"]
        elif not r["vivo"]:
            col, titulo = MAL, "EL ROBOT ESTÁ CAÍDO"
            detalle = ("Ningún proceso del vigilante corriendo. "
                       f"La Tarea programada lo levanta {est.en_cuanto(r['rescate'])}.")
        elif e["salud"] == GRAVE:
            col, titulo = MAL, "EL ROBOT CORRE, PERO ALGO ESTÁ MAL"
            detalle = ""
        elif e["salud"] == MEDIO:
            col, titulo = WARN, "EL ROBOT FUNCIONA, CON REPAROS"
            detalle = ""
        else:
            col, titulo = OK, "TODO EN ORDEN"
            detalle = ""

        cx, cy = M + 56, y + h / 2
        halo = {MAL: "#411219", WARN: "#40300b", OK: "#123a22"}[col]
        c.create_oval(cx - 34, cy - 34, cx + 34, cy + 34, fill=halo, outline="")
        c.create_oval(cx - 18, cy - 18, cx + 18, cy + 18, fill=col, outline="")
        # Un punto más claro arriba a la izquierda: da volumen y, sobre todo,
        # distingue de un vistazo el semáforo encendido del halo apagado.
        c.create_oval(cx - 13, cy - 13, cx - 3, cy - 3, fill="#ffffff", outline="")

        c.create_text(M + 104, y + 28, text=titulo, anchor="w",
                      font=(F, 17, "bold"), fill=col)
        if not detalle and r["vivo"]:
            detalle = (f"PID {r['pid']} · arrancado {hace_cuanto(r['inicio'])}"
                       f" · última señal {hace_cuanto(r['ultima_senal'])}"
                       f" · espejo {hace_cuanto(e['espejo']['ultimo_espejo'])}")
        c.create_text(M + 104, y + 54, text=detalle, anchor="w", font=(F, 10), fill=SUAVE)

        pie = []
        if r["vivo"] and r["trabajando"]:
            pie.append("ahora mismo está trabajando")
        if r["vivo"] and r["memoria_mb"]:
            pie.append(f"{r['memoria_mb']:.0f} MB")
        if r["rescate"]:
            pie.append(f"si se cae vuelve solo {est.en_cuanto(r['rescate'])}")
        if reg["novedades"]:
            pie.append(f"{reg['novedades']} avisos del buzón en 24 h")
        if reg["conexiones_perdidas"]:
            pie.append(f"{reg['conexiones_perdidas']} reconexiones en 24 h")
        c.create_text(M + 104, y + 78, text="   ·   ".join(pie), anchor="w",
                      font=(F, 9), fill=TENUE)

        bx = W - M - 16
        for etiqueta, accion in (
                ("Actualizar", self._recolectar_ya),
                ("Informes", lambda: abrir(est.ESPEJO)),
                ("Registro", lambda: abrir(reg["ruta"]))):
            w = self.f_chip.measure(etiqueta) + 26
            self._boton(bx - w, y + h - 36, etiqueta, accion)
            bx -= w + 8
        return y + h + 12

    def _alertas(self, c, W, y, e) -> int:
        alertas = e["alertas"]
        if not alertas:
            h = 44
            redondeado(c, M, y, W - M, y + h, 12, fill=PANEL, outline=BORDE)
            c.create_oval(M + 18, y + 16, M + 30, y + 28, fill=OK, outline="")
            c.create_text(M + 42, y + h / 2, anchor="w", font=(F, 11), fill=OK,
                          text="Nada que revisar: las once señales que vigila "
                               "InspectorBot están donde deben.")
            return y + h + 12

        alto_fila = 46
        h = 40 + alto_fila * len(alertas)
        redondeado(c, M, y, W - M, y + h, 12, fill=PANEL, outline=BORDE)
        graves = sum(1 for a in alertas if a["gravedad"] == GRAVE)
        c.create_text(M + 16, y + 22, anchor="w", font=self.f_seccion, fill=TINTA,
                      text="Lo que hay que revisar")
        c.create_text(W - M - 16, y + 22, anchor="e", font=(F, 9), fill=TENUE,
                      text=(f"{graves} grave(s) de {len(alertas)}" if graves
                            else f"{len(alertas)} avisos, ninguno grave"))

        yy = y + 40
        for a in alertas:
            col = COLOR_GRAVEDAD[a["gravedad"]]
            c.create_rectangle(M + 16, yy + 6, M + 20, yy + alto_fila - 8,
                               fill=col, outline="")
            c.create_text(M + 32, yy + 16, anchor="w", font=(F, 10, "bold"),
                          text=a["titulo"], fill=col)
            # El detalle y el qué hacer se recortan al ancho real: una línea del
            # registro puede medir 600 caracteres (lleva el comando ssh entero).
            resto = " ".join(x for x in (a["detalle"], a["que_hacer"]) if x)
            c.create_text(M + 32, yy + 33, anchor="w", font=(F, 9), fill=SUAVE,
                          text=self._recortar(resto, W - 2 * M - 60, (F, 9)))
            yy += alto_fila
        return y + h + 12

    def _recortar(self, texto: str, ancho: int, fuente) -> str:
        f = tkfont.Font(family=fuente[0], size=fuente[1])
        if f.measure(texto) <= ancho:
            return texto
        while texto and f.measure(texto + "…") > ancho:
            texto = texto[:-1]
        return texto + "…"

    def _kpis(self, c, W, y, e) -> int:
        b, at, esp = e["buzon"], e["atenciones"], e["espejo"]
        h = 84
        # Las mismas palabras que la tarjeta «Por zona» de B.IA (vocabulario.json): con OT
        # de cierre (atendidas), abiertas (con OT y sin la de cierre) y a espera de informe
        # técnico (ninguna OT todavía). Las claves de atenciones.json no cambian.
        ot = termino("OT_INDUSTEC")
        espera = termino("ESPERA_INFORME", 2)
        tarjetas = [
            (termino("ORDEN", 2), fmt(b.get("casos")),
             f"de KFC, ventana 90 días", ACENTO),
            (f"Con {ot}", fmt(at.get("con_atencion")),
             f"{fmt(at.get('cerradas'))} con {corto('OT_INDUSTEC')} de cierre · "
             f"{fmt(at.get('en_curso'))} {termino('ABIERTA', 2)}", OK),
            (espera, fmt(at.get("sin_atencion")), "esperan visita", WARN),
            (f"{ot} espejadas", fmt(esp["total"]),
             f"el más nuevo {hace_cuanto(esp['pdf_mas_nuevo'])}", TINTA),
        ]
        aw = (W - 2 * M - 3 * 12) / 4
        for i, (tit, val, pie, col) in enumerate(tarjetas):
            x = M + i * (aw + 12)
            redondeado(c, x, y, x + aw, y + h, 12, fill=PANEL, outline=BORDE)
            c.create_text(x + 16, y + 20, text=tit.upper(), anchor="w",
                          font=(F, 8, "bold"), fill=TENUE)
            c.create_text(x + 16, y + 50, text=val, anchor="w",
                          font=(F, 24, "bold"), fill=col)
            c.create_text(x + 16, y + 70, text=self._recortar(pie, aw - 32, (F, 9)),
                          anchor="w", font=(F, 9), fill=SUAVE)
        return y + h + 12

    def _paneles(self, c, W, y, e) -> int:
        b, esp = e["buzon"], e["espejo"]
        pw = (W - 2 * M - 12) / 2
        izq = [(z, b.get("por_zona", {}).get(z, 0)) for z in ("UIO", "LARB", "CNLJ")]
        der = [(d["etiqueta"], d["n"]) for d in esp["por_modulo"].values()]
        h = max(56 + 30 * len(izq), 56 + 30 * len(der)) + 46

        def panel(x, titulo, sub, filas, total, unidad, err=None):
            redondeado(c, x, y, x + pw, y + h, 12, fill=PANEL, outline=BORDE)
            c.create_text(x + 16, y + 22, text=titulo, anchor="w",
                          font=self.f_seccion, fill=TINTA)
            c.create_text(x + 16, y + 40, text=sub, anchor="w", font=(F, 9), fill=TENUE)
            if err:
                c.create_text(x + 16, y + 70, text=err, anchor="w", font=(F, 10), fill=MAL)
                return
            mx = max((v for _, v in filas), default=1) or 1
            # La columna de etiquetas se dimensiona con la más larga que haya
            # («OTROS CLIENTES» mide casi el doble que «UIO»): con un ancho fijo
            # se montaba encima del arranque de su barra.
            f_et = tkfont.Font(family=F, size=10, weight="bold")
            col_et = min(pw * 0.42, max(f_et.measure(n) for n, _ in filas) + 26)
            yy = y + 58
            for nom, val in filas:
                m = yy + 11
                c.create_text(x + 16, m, text=nom, anchor="w",
                              font=(F, 10, "bold"), fill=SUAVE)
                bx0 = x + 16 + col_et
                bw = x + pw - 86 - bx0
                c.create_rectangle(bx0, m - 6, bx0 + bw, m + 6, fill=PANEL2, outline="")
                c.create_rectangle(bx0, m - 6, bx0 + max(3, bw * val / mx), m + 6,
                                   fill=ZONA.get(nom, ACENTO), outline="")
                c.create_text(x + pw - 16, m, text=fmt(val), anchor="e",
                              font=(FM, 11), fill=TINTA)
                yy += 30
            c.create_line(x + 16, y + h - 38, x + pw - 16, y + h - 38, fill=BORDE)
            c.create_text(x + 16, y + h - 20, text="TOTAL", anchor="w",
                          font=(F, 8, "bold"), fill=TENUE)
            c.create_text(x + pw - 16, y + h - 20, text=f"{fmt(total)} {unidad}",
                          anchor="e", font=(F, 12, "bold"), fill=TINTA)

        sub_izq = "lo que entra por el buzón"
        if b.get("generado"):
            sub_izq += f" · catálogo {hace_cuanto(b['generado'])}"
        panel(M, "Requerimientos de SAP / Grupo KFC", sub_izq, izq,
              b.get("casos") or 0, "casos",
              err=f"catálogo {b['error']}" if b.get("error") else None)

        sub_der = "lo que genera el formulario, ya bajado a la estación"
        if esp["ultimo_espejo"]:
            sub_der = f"último espejo {hace_cuanto(esp['ultimo_espejo'])}"
        panel(M + pw + 12, "Informes en Yellow Elephant", sub_der, der,
              esp["total"], "PDF")
        return y + h + 12

    def _novedades(self, c, W, y) -> int:
        lineas = self.nuevos_avisos[-3:] + [f"informe nuevo: {n}" for n in self.nuevos_pdf[-3:]]
        if not lineas:
            return y
        h = 34 + 19 * len(lineas)
        redondeado(c, M, y, W - M, y + h, 12, fill="#0e2a1c", outline="#1c4a33")
        c.create_text(M + 16, y + 19, anchor="w", font=(F, 10, "bold"), fill=OK,
                      text="Ha entrado algo con la ventana abierta")
        yy = y + 40
        for t in lineas:
            c.create_text(M + 16, yy, text=t, anchor="w", font=(FM, 9), fill=OK)
            yy += 19
        return y + h + 12

    def _registro(self, c, W, y, e) -> int:
        reg = e["registro"]
        lineas = [x for x in reg["lineas"] if x["cuando"] or x["texto"]][-9:]
        h = 44 + 19 * max(1, len(lineas))
        redondeado(c, M, y, W - M, y + h, 12, fill=PANEL, outline=BORDE)
        c.create_text(M + 16, y + 22, anchor="w", font=self.f_seccion, fill=TINTA,
                      text="Lo último que dijo el robot")
        if reg["ruta"]:
            c.create_text(M + 16 + self.f_seccion.measure("Lo último que dijo el robot") + 12,
                          y + 23, text=reg["ruta"].name, anchor="w",
                          font=(FM, 9), fill=TENUE)
        colores = {"error": MAL, "aviso": WARN, "novedad": ACENTO,
                   "bien": OK, "ciclo": SUAVE, "normal": SUAVE}
        yy = y + 42
        if not lineas:
            c.create_text(M + 16, yy, text="sin registro todavía", anchor="w",
                          font=(FM, 9), fill=TENUE)
        for ln in lineas:
            hora = f"{ln['cuando']:%H:%M:%S}" if ln["cuando"] else ""
            c.create_text(M + 16, yy, text=hora, anchor="w", font=(FM, 9), fill=TENUE)
            c.create_text(M + 86, yy, anchor="w", font=(FM, 9),
                          fill=colores.get(ln["tipo"], SUAVE),
                          text=self._recortar(ln["texto"], W - 2 * M - 110, (FM, 9)))
            yy += 19
        return y + h + 12


def main() -> int:
    # Identidad propia en la barra de tareas. No es lo que arregla el icono
    # -eso lo hace iconbitmap-, pero sí lo que hace que Windows agrupe y ancle
    # esta ventana como InspectorBot y no junto a cualquier otro Python. Si
    # falla no pasa nada, así que no se deja reventar el arranque.
    try:
        shell32 = ctypes.WinDLL("shell32", use_last_error=True)
        shell32.SetCurrentProcessExplicitAppUserModelID.argtypes = [wintypes.LPCWSTR]
        shell32.SetCurrentProcessExplicitAppUserModelID("INDUSTECH.InspectorBot.1")
    except (OSError, AttributeError):
        pass
    InspectorBot().mainloop()
    return 0


if __name__ == "__main__":
    sys.exit(main())
