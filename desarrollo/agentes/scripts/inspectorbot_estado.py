# -*- coding: utf-8 -*-
"""
InspectorBot - la capa que LEE el estado del robot. Sin una sola linea de interfaz.

POR QUE ESTA SEPARADA DE LA VENTANA
Para poder comprobarla desde la consola (`--json`) sin abrir nada, y porque el
calculo es lo que tiene valor: la ventana solo lo pinta. Si manana la interfaz
cambia entera, esto no se toca.

SOLO LECTURA, SIN EXCEPCIONES. Lee archivos, consulta el listado de procesos y
las Tareas programadas. No escribe, no borra, no toca la base, no toca el buzon
y no se conecta a Hostinger. Se puede abrir y cerrar cuantas veces se quiera.

QUE VIGILA, Y POR QUE ESO Y NO "SI EL PROCESO ESTA VIVO"
El 2026-09-20 el vigilante llevaba CINCO DIAS corriendo con el codigo del 15 de
septiembre: detectaba correos y empujaba bien, pero la mitad nueva de su trabajo
-bajar de produccion los informes que el formulario acaba de generar- no se
ejecuto ni una vez, porque un proceso de Python ya arrancado no recoge los
cambios del .py. La Tarea programada marcaba "En ejecucion" y el registro se
veia sano. Un robot que corre sin hacer su trabajo tiene que verse tan mal como
uno caido, asi que aqui casi todo se mide en "desde hace cuanto no pasa" y no en
"esta encendido".

USO
    .venv\\Scripts\\python.exe scripts\\inspectorbot_estado.py          # resumen legible
    .venv\\Scripts\\python.exe scripts\\inspectorbot_estado.py --json   # el dict entero
"""
from __future__ import annotations

import hashlib
import json
import os
import re
import subprocess
import sys
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comun import BASE, RAIZ, RESPALDOS, SALIDAS  # noqa: E402

# --- Dónde vive cada señal ----------------------------------------------------
SCRIPTS = BASE / "scripts"
LOGS = BASE / "logs"
CONFIG = BASE / "config"
CATALOGOS = SALIDAS / "catalogos"
CASOS = CATALOGOS / "casos_sap.json"
CASOS_TMP = CATALOGOS / "casos_sap.json.tmp"
ATENCIONES = CATALOGOS / "atenciones.json"
NOCTURNO = SALIDAS / "estado_nocturno.json"
ESTADO_VIGILANTE = CONFIG / "vigilante_estado.json"
ESPEJO = RESPALDOS / "_ORIGEN_SISTEMA"
MANIFIESTOS = ESPEJO / "_manifiestos"
DIVERGENTES = ESPEJO / "_divergentes"
CUARENTENA = ESPEJO / "_cuarentena_hash"
BUZON_PDF = RESPALDOS / "_ORIGEN_BUZON"
CANDADO_NOCTURNO = LOGS / "saneamiento.lock"

# Los cinco módulos del sistema viejo, con el nombre que entiende la gente.
# `otros` no es una zona: son trabajos fuera del contrato de KFC.
MODULOS = [("uio", "UIO"), ("larb", "LARB"), ("cnlj", "CNLJ"),
           ("mant", "PREVENTIVO"), ("otros", "OTROS CLIENTES")]

TAREAS = ["INDUSTEC - Vigilante del buzon",
          "INDUSTEC - Informes de OT",
          "INDUSTEC - Saneamiento nocturno"]

# LOS DOS UNICOS ARCHIVOS QUE SE CONGELAN EN MEMORIA. `t2_9` es el proceso y
# `comun` lo importa, asi que un cambio en ellos NO entra hasta reiniciar. Los
# otros tres (t2_6, t2_11, t2_4) se lanzan como subproceso en cada ciclo y leen
# el disco siempre: meterlos aqui daria una alarma roja permanente y falsa.
FUENTES_EN_MEMORIA = ["t2_9_buzon_vigilante.py", "comun.py"]

# El vigilante renueva su conexión IMAP cada 9 minutos y lo escribe. Si el
# registro lleva más de eso sin una línea nueva, algo se trabó: no es un umbral
# inventado, es el propio ciclo del robot.
SILENCIO_SOSPECHOSO_MIN = 12
# Salvo que esté en mitad de un trabajo largo. El espejo de producción tiene
# una hora de tiempo límite y no escribe una línea hasta que termina, así que
# durante una corrida lenta el registro se calla de forma perfectamente sana.
# Sin esta excepción, «lleva callado demasiado» salta en rojo justo cuando el
# robot está haciendo su trabajo más pesado, y una alarma que grita cuando todo
# va bien es peor que no tener alarma: enseña a ignorarla.
TRABAJOS_LARGOS = ("espejando", "barriendo", "revisando los informes")
SILENCIO_TRABAJANDO_MIN = 65
# El espejo se dispara con cada novedad del buzón, no con un reloj. En un día
# tranquilo puede pasar horas sin correr con el robot perfecto; medio día no.
ESPEJO_VIEJO_HORAS = 12
# El nocturno corre a las 02:30. Más de 26 h sin una corrida real es un día
# entero perdido de ingesta, normalización y subida de PDF.
NOCTURNO_VIEJO_HORAS = 26

GRAVE, MEDIO, LEVE = "grave", "medio", "leve"


# ==============================================================================
# Utilidades
# ==============================================================================
def ahora() -> datetime:
    return datetime.now()


def hace_cuanto(cuando: datetime | None, nunca: str = "nunca") -> str:
    """'hace 3 min'. Lo que importa no es la hora exacta, es la antigüedad."""
    if cuando is None:
        return nunca
    seg = (ahora() - cuando).total_seconds()
    if seg < 0:
        return "recién"
    if seg < 90:
        return f"hace {int(seg)} s"
    if seg < 5400:
        return f"hace {int(seg // 60)} min"
    if seg < 172800:
        return f"hace {seg / 3600:.1f} h".replace(".", ",")
    return f"hace {int(seg // 86400)} días"


def en_cuanto(cuando: datetime | None) -> str:
    if cuando is None:
        return "sin fecha"
    seg = (cuando - ahora()).total_seconds()
    if seg <= 0:
        return "ya toca"
    if seg < 90:
        return f"en {int(seg)} s"
    if seg < 5400:
        return f"en {int(seg // 60)} min"
    return f"en {seg / 3600:.1f} h".replace(".", ",")


def antiguedad_h(cuando: datetime | None) -> float:
    if cuando is None:
        return float("inf")
    return (ahora() - cuando).total_seconds() / 3600


def leer_json(ruta: Path):
    """Devuelve (datos, error). Un catálogo a medio escribir no tumba nada."""
    try:
        return json.loads(ruta.read_text(encoding="utf-8")), None
    except FileNotFoundError:
        return None, "no existe todavía"
    except Exception as e:                      # JSON cortado, permisos, disco
        return None, f"ilegible ({type(e).__name__})"


def mtime(ruta: Path) -> datetime | None:
    try:
        return datetime.fromtimestamp(ruta.stat().st_mtime)
    except OSError:
        return None


def utc_a_local(texto: str | None) -> datetime | None:
    """El robot sella en UTC con desplazamiento explícito; aquí son UTC-5."""
    if not texto:
        return None
    try:
        return datetime.fromisoformat(texto).astimezone().replace(tzinfo=None)
    except Exception:
        return None


def fmt(n) -> str:
    """7803 -> '7.803'. El punto de millar, como lo escribe la administración."""
    try:
        return f"{int(n):,}".replace(",", ".")
    except (TypeError, ValueError):
        return "?"


# ==============================================================================
# Lo caro: procesos y Tareas programadas, en UNA sola llamada a PowerShell
# ==============================================================================
_cache_ps = {"cuando": 0.0, "datos": None}
PS_TIMEOUT = 25
PS_VALIDEZ_SEG = 25

# Una sola invocación para las tres cosas caras. Lanzar PowerShell cuesta ~1 s;
# hacerlo tres veces por refresco se nota en una ventana que se redibuja sola.
_PS = r"""
$ErrorActionPreference = 'SilentlyContinue'
$procs = Get-CimInstance Win32_Process -Filter "name like '%python%'" |
  Where-Object { $_.CommandLine -like '*t2_9_buzon_vigilante*' } |
  Select-Object ProcessId, ParentProcessId, CreationDate, ExecutablePath, WorkingSetSize
$tareas = @('INDUSTEC - Vigilante del buzon','INDUSTEC - Informes de OT','INDUSTEC - Saneamiento nocturno') |
  ForEach-Object {
    $t = Get-ScheduledTask -TaskName $_ -ErrorAction SilentlyContinue
    if ($t) {
      $i = $t | Get-ScheduledTaskInfo
      [pscustomobject]@{ nombre=$_; estado=[string]$t.State; ultimo=$i.LastRunTime;
                         resultado=$i.LastTaskResult; proximo=$i.NextRunTime }
    } else { [pscustomobject]@{ nombre=$_; estado='NO EXISTE' } }
  }
$d = Get-PSDrive -Name D -ErrorAction SilentlyContinue
[pscustomobject]@{
  procesos = @($procs)
  tareas   = @($tareas)
  disco    = if ($d) { [pscustomobject]@{ libre=$d.Free; usado=$d.Used } } else { $null }
} | ConvertTo-Json -Depth 5 -Compress
"""


def _fecha_ps(valor) -> datetime | None:
    """PowerShell serializa fechas de dos formas según el camino que tomen."""
    if not valor:
        return None
    s = str(valor)
    m = re.search(r"(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})", s)
    if m:
        return datetime(*[int(x) for x in m.groups()])
    m = re.search(r"/Date\((\d+)", s)
    if m:
        return datetime.fromtimestamp(int(m.group(1)) / 1000)
    return None


def _consultar_windows(forzar: bool = False) -> dict:
    if not forzar and time.time() - _cache_ps["cuando"] < PS_VALIDEZ_SEG:
        if _cache_ps["datos"] is not None:
            return _cache_ps["datos"]
    salida = {"procesos": [], "tareas": [], "disco": None, "error": None}
    try:
        r = subprocess.run(
            ["powershell", "-NoProfile", "-NonInteractive", "-Command", _PS],
            capture_output=True, text=True, timeout=PS_TIMEOUT,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        crudo = (r.stdout or "").strip()
        if crudo:
            salida.update(json.loads(crudo))
        elif r.returncode != 0:
            salida["error"] = f"PowerShell salió con {r.returncode}"
    except subprocess.TimeoutExpired:
        salida["error"] = f"PowerShell no respondió en {PS_TIMEOUT} s"
    except Exception as e:
        salida["error"] = f"{type(e).__name__}: {e}"
    _cache_ps.update(cuando=time.time(), datos=salida)
    return salida


# ==============================================================================
# 1. El robot
# ==============================================================================
def _registro_vigilante() -> Path | None:
    """El registro MÁS RECIENTE POR FECHA DE ESCRITURA, no por nombre.

    El nombre lo pone el script al arrancar, así que un vigilante que lleva
    cinco días vivo sigue escribiendo en `vigilante-2026-09-15.log`. Buscarlo
    por la fecha de hoy daría "no hay registro" justo cuando sí lo hay.
    """
    try:
        archivos = sorted(LOGS.glob("vigilante-*.log"),
                          key=lambda p: p.stat().st_mtime, reverse=True)
    except OSError:
        return None
    return archivos[0] if archivos else None


def _proceso_real(procesos: list) -> tuple[dict | None, int]:
    """Cuál de los procesos es el robot de verdad, y cuántos robots hay.

    En Windows `.venv\\Scripts\\python.exe` es un LANZADOR: lee `pyvenv.cfg` y
    arranca el intérprete base como proceso hijo, heredando la línea de
    comandos. Por eso la consulta devuelve siempre DOS filas para un solo
    robot. El bueno es el hijo -el que hace el trabajo-, pero quien manda es la
    raíz del árbol: se toma como robot el proceso cuyo padre NO está en la
    lista, y se cuenta un robot por cada raíz. Cuatro procesos (dos raíces) sí
    serían dos robots de verdad, y eso es una alarma legítima: dos vigilantes
    escribirían el mismo registro y el mismo `vigilante_estado.json`. Desde el
    2026-09-24 `t2_9` toma un candado de instancia única (`logs/vigilante.lock`)
    y el segundo se cierra solo; si aun así aparecen dos, uno corre código de
    antes de ese cambio.
    """
    if not procesos:
        return None, 0
    pids = {p.get("ProcessId") for p in procesos}
    raices = [p for p in procesos if p.get("ParentProcessId") not in pids]
    if not raices:                       # árbol raro: no inventes, toma el más viejo
        raices = procesos
    raices.sort(key=lambda p: _fecha_ps(p.get("CreationDate")) or datetime.max)
    return raices[0], len(raices)


def _codigo_congelado(inicio: datetime | None) -> list[dict]:
    """Archivos que cambiaron DESPUÉS de que el proceso arrancó.

    Es el cálculo más valioso de toda la pantalla y es una resta de fechas. Si
    el `.py` es más nuevo que el arranque, el robot corre código viejo: es
    exactamente el fallo de los cinco días, y no hay ninguna otra señal que lo
    delate -el proceso está vivo, el registro se ve sano y la Tarea programada
    dice "En ejecución".
    """
    if inicio is None:
        return []
    viejos = []
    for nombre in FUENTES_EN_MEMORIA:
        cambiado = mtime(SCRIPTS / nombre)
        if cambiado and cambiado > inicio:
            viejos.append({"archivo": nombre, "cambiado": cambiado})
    return viejos


def bloque_robot(win: dict) -> dict:
    procesos = win.get("procesos") or []
    proc, cuantos = _proceso_real(procesos)
    inicio = _fecha_ps(proc.get("CreationDate")) if proc else None
    reg = _registro_vigilante()
    visto = mtime(reg) if reg else None

    tareas = {t.get("nombre"): t for t in (win.get("tareas") or [])}
    tv = tareas.get("INDUSTEC - Vigilante del buzon", {})
    proximo = _fecha_ps(tv.get("proximo"))

    # La memoria se suma sobre TODO el árbol. El proceso raíz es el lanzador del
    # venv y ocupa menos de 1 MB; quien hace el trabajo es el hijo. Mostrar solo
    # la raíz haría parecer que el robot no está haciendo nada.
    memoria = sum((p.get("WorkingSetSize") or 0) for p in procesos) / 1048576

    ultima = ""
    if reg:
        cola = _leer_cola(reg, 4096)
        if cola:
            m = LINEA.match(cola[-1])
            ultima = (m.group(2) if m else cola[-1]).strip().lower()
    trabajando = any(t in ultima for t in TRABAJOS_LARGOS)
    limite = SILENCIO_TRABAJANDO_MIN if trabajando else SILENCIO_SOSPECHOSO_MIN
    callado = (ahora() - visto).total_seconds() / 60 if visto else 0

    return {
        "vivo": proc is not None,
        "pid": proc.get("ProcessId") if proc else None,
        "inicio": inicio,
        "memoria_mb": round(memoria, 1) if procesos else None,
        "procesos_en_lista": len(procesos),
        "robots": cuantos,
        "codigo_congelado": _codigo_congelado(inicio),
        "registro": reg,
        "ultima_senal": visto,
        "silencioso": visto is not None and callado > limite,
        "trabajando": trabajando,
        "rescate": proximo,
        "tarea_estado": tv.get("estado"),
        "consulta_error": win.get("error"),
    }


# ==============================================================================
# 2. El registro: últimas líneas y qué pasó en 24 h
# ==============================================================================
LINEA = re.compile(r"^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s?(.*)$")

# Cuatro familias, en orden de prioridad: la primera que case manda. `ERROR` y
# `AGOTADOS` pueden ir en cualquier posición de la línea, no solo al principio.
def clasificar(texto: str) -> str:
    t = texto.strip()
    if "ERROR" in t or "AGOTADOS" in t or t.startswith("Traceback"):
        return "error"
    if "conexión perdida" in t or "conexion perdida" in t or t.startswith("AVISO"):
        return "aviso"
    if " EXISTS" in t or " EXPUNGE" in t or t.startswith("aviso del servidor"):
        return "novedad"
    if t.startswith("empujado") and "HTTP 200" in t:
        return "bien"
    if "vigilante en marcha" in t or t.startswith("escuchando"):
        return "ciclo"
    return "normal"


_cache_log = {"ruta": None, "sello": None, "datos": None}


def _leer_cola(ruta: Path, bytes_max: int = 262144) -> list[str]:
    try:
        with open(ruta, "rb") as f:
            f.seek(0, 2)
            fin = f.tell()
            f.seek(max(0, fin - bytes_max))
            texto = f.read().decode("utf-8", "replace")
    except OSError:
        return []
    lineas = texto.splitlines()
    if fin > bytes_max and lineas:
        lineas = lineas[1:]                     # la primera quedó cortada a medias
    return [x.rstrip() for x in lineas if x.strip()]


def bloque_registro(ruta: Path | None) -> dict:
    vacio = {"lineas": [], "conexiones_perdidas": 0, "novedades": 0, "arranques": 0,
             "errores": 0, "ultimo_empuje": None, "ultimo_empuje_hora": None,
             "ultima_novedad": None, "ruta": ruta}
    if ruta is None:
        return vacio

    # Releer 256 KB en cada refresco no cuesta nada, pero reparsear sí: se
    # cachea por (tamaño, mtime), que es lo que cambia cuando el robot escribe.
    try:
        st = ruta.stat()
        sello = (st.st_size, st.st_mtime)
    except OSError:
        return vacio
    if _cache_log["ruta"] == ruta and _cache_log["sello"] == sello:
        return _cache_log["datos"]

    crudas = _leer_cola(ruta)
    corte = ahora() - timedelta(hours=24)
    lineas, cp, nov, arr, err = [], 0, 0, 0, 0
    ultimo_empuje = ultimo_empuje_hora = ultima_novedad = None

    for cruda in crudas:
        m = LINEA.match(cruda)
        if m:
            try:
                cuando = datetime.strptime(m.group(1), "%Y-%m-%d %H:%M:%S")
            except ValueError:
                cuando = None
            texto = m.group(2)
        else:
            cuando, texto = None, cruda          # traceback: sin hora, va entero
        tipo = clasificar(texto)
        lineas.append({"cuando": cuando, "texto": texto, "tipo": tipo, "cruda": cruda})

        if cuando is None or cuando < corte:
            continue
        if tipo == "error":
            err += 1
        if "conexión perdida" in texto or "conexion perdida" in texto:
            cp += 1
        if " EXISTS" in texto or " EXPUNGE" in texto:
            nov += 1
            ultima_novedad = cuando
        if "vigilante en marcha" in texto:
            arr += 1
        # Los registros anteriores a T2.15.1 dicen `empujado:` sin paréntesis
        # (864 líneas así frente a 13 de la forma nueva). Anclar en `^empujado`
        # y no en `empujado \(` es lo que hace que el histórico no se pierda.
        if texto.startswith("empujado"):
            ultimo_empuje, ultimo_empuje_hora = texto, cuando

    datos = {"lineas": lineas, "conexiones_perdidas": cp, "novedades": nov,
             "arranques": arr, "errores": err, "ultimo_empuje": ultimo_empuje,
             "ultimo_empuje_hora": ultimo_empuje_hora,
             "ultima_novedad": ultima_novedad, "ruta": ruta}
    _cache_log.update(ruta=ruta, sello=sello, datos=datos)
    return datos


# ==============================================================================
# 3. El buzón (SAP / Grupo KFC)
# ==============================================================================
_cache_cat: dict = {}


def _catalogo(ruta: Path) -> tuple[dict | None, str | None, str | None]:
    """Devuelve (datos, error, huella). La huella es el hash del contenido SIN
    el campo `generado`, que lleva la hora dentro y cambia en cada barrido
    aunque no haya novedades. Sin quitarlo, el mtime y el hash del archivo no
    prueban nada: distinguen mal "el robot corre y no hay nada nuevo" de "el
    robot corre y su salida está congelada".
    """
    try:
        st = ruta.stat()
        sello = (st.st_size, st.st_mtime)
    except OSError:
        return None, "no existe todavía", None
    guardado = _cache_cat.get(ruta)
    if guardado and guardado[0] == sello:
        return guardado[1], guardado[2], guardado[3]

    datos, err = leer_json(ruta)
    huella = None
    if datos is not None:
        sin_hora = {k: v for k, v in datos.items() if k != "generado"}
        huella = hashlib.sha256(
            json.dumps(sin_hora, sort_keys=True, ensure_ascii=False).encode("utf-8")
        ).hexdigest()[:16]
    _cache_cat[ruta] = (sello, datos, err, huella)
    return datos, err, huella


def bloque_buzon() -> dict:
    datos, err, huella = _catalogo(CASOS)
    if datos is None:
        return {"error": err, "huella": None}
    r = datos.get("resumen") or {}
    revisar = datos.get("revisar") or {}
    try:
        generado = datetime.strptime(datos.get("generado", ""), "%Y-%m-%d %H:%M")
    except (ValueError, TypeError):
        generado = None
    alerta = r.get("por_estado_alerta") or {}
    return {
        "error": None,
        "generado": generado,
        "casos": r.get("casos_vigentes"),
        "por_zona": r.get("por_zona") or {},
        "por_prioridad": r.get("por_prioridad") or {},
        "eliminadas": r.get("ordenes_eliminadas") or 0,
        "con_alerta": alerta.get("CON_ALERTA", 0),
        "por_regla": r.get("por_regla") or {},
        "sin_local": r.get("sin_local_resuelto") or 0,
        "zona_discrepante": r.get("zona_discrepante") or 0,
        "correos_no_reconocidos": r.get("correos_no_reconocidos") or 0,
        "revisar_sin_local": revisar.get("sin_local") or [],
        "correos_leidos": datos.get("correos_leidos"),
        "huella": huella,
        "tmp_huerfano": CASOS_TMP.exists(),
    }


def bloque_atenciones() -> dict:
    datos, err, huella = _catalogo(ATENCIONES)
    if datos is None:
        return {"error": err, "huella": None}
    r = datos.get("resumen") or {}
    try:
        generado = datetime.strptime(datos.get("generado", ""), "%Y-%m-%d %H:%M")
    except (ValueError, TypeError):
        generado = None
    return {
        "error": None,
        "generado": generado,
        "pendientes": r.get("casos_pendientes"),
        "con_atencion": r.get("con_atencion"),
        "sin_atencion": r.get("sin_atencion"),
        "cerradas": r.get("cerradas_por_industec"),
        "en_curso": r.get("en_curso"),
        "informes_leidos": r.get("informes_leidos"),
        "no_parseados": r.get("informes_no_parseados") or 0,
        "sin_tecnico": r.get("tecnico_no_identificado") or 0,
        "huella": huella,
    }


# ==============================================================================
# 4. Yellow Elephant: el espejo de producción
# ==============================================================================
def _contar_pdf(carpeta: Path) -> tuple[set, datetime | None]:
    """os.scandir y no glob: son ~900 archivos por carpeta y esto corre cada
    pocos segundos."""
    nombres, ultimo = set(), None
    try:
        with os.scandir(carpeta) as it:
            for e in it:
                if e.is_file() and e.name.lower().endswith(".pdf"):
                    nombres.add(e.name)
                    try:
                        t = datetime.fromtimestamp(e.stat().st_mtime)
                        if ultimo is None or t > ultimo:
                            ultimo = t
                    except OSError:
                        pass
    except OSError:
        pass
    return nombres, ultimo


def _resumen_espejo() -> dict:
    """El `resumen_*.json` más reciente del espejo.

    Aquí está el diagnóstico de verdad y hoy no lo abre nadie: el registro del
    vigilante solo ecoa Bajados/Fallidos/Divergentes cuando el número no es 0,
    así que una corrida sana y una corrida que no ocurrió se ven idénticas.
    `sospechoso` es el campo que vale: lo pone `t2_4` cuando un módulo bajó 0
    teniendo archivos, que es el síntoma de un espejo apuntando mal.
    """
    vacio = {"hay": False, "cuando": None, "bajados": 0, "fallidos": 0,
             "divergentes": 0, "sospechosos": [], "errores": []}
    try:
        archivos = sorted(MANIFIESTOS.glob("resumen_*.json"),
                          key=lambda p: p.stat().st_mtime, reverse=True)
    except OSError:
        return vacio
    if not archivos:
        return vacio
    datos, err = leer_json(archivos[0])
    if datos is None:
        return {**vacio, "hay": True, "cuando": mtime(archivos[0]), "errores": [err]}

    bajados = fallidos = divergentes = 0
    sospechosos, errores = [], []
    for mod, _ in MODULOS:
        d = datos.get(mod)
        if not isinstance(d, dict):
            continue
        bajados += int(d.get("bajados") or 0)
        fallidos += len(d.get("fallidos") or [])
        divergentes += len(d.get("divergentes") or [])
        if d.get("sospechoso"):
            sospechosos.append(mod)
        if d.get("error"):
            errores.append(f"{mod}: {str(d['error'])[:160]}")
    return {"hay": True, "cuando": mtime(archivos[0]), "bajados": bajados,
            "fallidos": fallidos, "divergentes": divergentes,
            "sospechosos": sospechosos, "errores": errores,
            "archivo": archivos[0].name}


def _cuantos(carpeta: Path) -> int:
    try:
        with os.scandir(carpeta) as it:
            return sum(1 for e in it if e.is_file())
    except OSError:
        return 0


def bloque_espejo() -> dict:
    est, _ = leer_json(ESTADO_VIGILANTE)
    ultimo = utc_a_local((est or {}).get("ultimo_espejo_utc"))
    por_modulo, total, mas_nuevo = {}, 0, None
    for mod, etiqueta in MODULOS:
        nombres, reciente = _contar_pdf(ESPEJO / mod)
        por_modulo[mod] = {"etiqueta": etiqueta, "nombres": nombres,
                           "n": len(nombres), "ultimo": reciente}
        total += len(nombres)
        if reciente and (mas_nuevo is None or reciente > mas_nuevo):
            mas_nuevo = reciente

    _, buzon_ultimo = _contar_pdf(BUZON_PDF)
    return {
        "ultimo_espejo": ultimo,
        "espejo_viejo": antiguedad_h(ultimo) > ESPEJO_VIEJO_HORAS,
        "por_modulo": por_modulo,
        "total": total,
        "pdf_mas_nuevo": mas_nuevo,
        "resumen": _resumen_espejo(),
        "divergentes": _cuantos(DIVERGENTES),
        "cuarentena": _cuantos(CUARENTENA),
        "buzon_pdf_ultimo": buzon_ultimo,
        "buzon_pdf_n": _cuantos(BUZON_PDF),
    }


# ==============================================================================
# 5. El saneamiento nocturno
# ==============================================================================
def bloque_nocturno(win: dict) -> dict:
    datos, err = leer_json(NOCTURNO)
    tareas = {t.get("nombre"): t for t in (win.get("tareas") or [])}
    t = tareas.get("INDUSTEC - Saneamiento nocturno", {})
    ultimo_tarea = _fecha_ps(t.get("ultimo"))
    # 30/11/1999 es el cero del Programador y 267011 (0x41303) es
    # SCHED_S_TASK_HAS_NOT_RUN: la tarea existe pero no corrió nunca.
    nunca_corrio = t.get("resultado") == 267011 or (
        ultimo_tarea is not None and ultimo_tarea.year < 2000)

    base = {"error": err, "ok": None, "ensayo": None, "fin": None, "pasos": [],
            "tarea_estado": t.get("estado"), "tarea_ultimo": ultimo_tarea,
            "tarea_resultado": t.get("resultado"),
            "tarea_proximo": _fecha_ps(t.get("proximo")),
            "nunca_corrio": nunca_corrio,
            "candado_huerfano": None}

    try:
        if CANDADO_NOCTURNO.exists():
            lock, _ = leer_json(CANDADO_NOCTURNO)
            desde = utc_a_local((lock or {}).get("desde")) or mtime(CANDADO_NOCTURNO)
            base["candado_huerfano"] = desde if antiguedad_h(desde) > 6 else None
    except OSError:
        pass

    if datos is None:
        return base
    base.update({
        "ok": datos.get("ok"),
        # Si `ensayo` es True, la corrida fue un simulacro: no escribió en la
        # base ni movió un archivo. Un tablero que pinte "nocturno OK" sin mirar
        # este campo miente, que es justo el fallo que esta pantalla persigue.
        "ensayo": bool(datos.get("ensayo")),
        "fin": utc_a_local(datos.get("fin_utc")),
        "duracion_min": datos.get("duracion_min"),
        "pasos": datos.get("pasos") or [],
    })
    return base


# ==============================================================================
# 6. Las alertas: qué está mal, ordenado por gravedad
# ==============================================================================
def calcular_alertas(e: dict) -> list[dict]:
    """La lista de lo que hay que mirar. Es el corazón de la pantalla: sin esto
    serían cifras bonitas que nadie sabe interpretar."""
    a, robot = [], e["robot"]
    reg, esp, noc = e["registro"], e["espejo"], e["nocturno"]

    def add(grav, titulo, detalle, que_hacer=""):
        a.append({"gravedad": grav, "titulo": titulo, "detalle": detalle,
                  "que_hacer": que_hacer})

    if robot["consulta_error"]:
        add(MEDIO, "No se pudo consultar Windows",
            robot["consulta_error"],
            "Sin esto no se sabe si el robot está vivo; el resto de la pantalla sí vale.")
    elif not robot["vivo"]:
        cuando = en_cuanto(robot["rescate"])
        add(GRAVE, "El robot está caído",
            "No hay ningún proceso del vigilante corriendo.",
            f"La Tarea programada lo levanta sola {cuando}. Si no vuelve, lánzala "
            f"desde el Programador de tareas («INDUSTEC - Vigilante del buzon» → "
            f"Ejecutar), no con el .bat a mano: la Tarea no reconoce como suyo un "
            f"robot lanzado fuera de ella y seguiría intentando levantar otro.")
    else:
        if robot["codigo_congelado"]:
            cuales = ", ".join(c["archivo"] for c in robot["codigo_congelado"])
            add(GRAVE, "El robot corre código viejo",
                f"{cuales} cambió después de que el proceso arrancó "
                f"({robot['inicio']:%d/%m %H:%M}). Python no recoge los cambios "
                f"de un .py ya cargado.",
                "Reinícialo: termina el proceso y la Tarea programada lo levanta "
                "en ≤10 min (o ejecútala desde el Programador de tareas; no "
                "con el .bat a mano).")
        if robot["robots"] > 1:
            add(GRAVE, f"Hay {robot['robots']} robots corriendo a la vez",
                "Dos procesos escriben el mismo registro y el mismo "
                "vigilante_estado.json. El candado de instancia única "
                "(logs\\vigilante.lock) debería impedirlo: uno de los dos corre "
                "código de antes del 2026-09-24.",
                "Deja uno solo. Cierra el que arrancaste a mano.")
        if robot["silencioso"]:
            porque = ("y lleva más de una hora en el mismo trabajo, que ya es "
                      "más de lo que tarda el espejo completo"
                      if robot["trabajando"]
                      else "y renueva su conexión cada 9 minutos")
            add(GRAVE, "El robot lleva callado demasiado",
                f"La última línea del registro es {hace_cuanto(robot['ultima_senal'])}, "
                f"{porque}.",
                "Está vivo pero trabado. Reinícialo.")

    if noc["nunca_corrio"]:
        add(GRAVE, "El saneamiento nocturno no ha corrido nunca",
            "La Tarea existe pero el Programador dice que no se ejecutó ni una vez. "
            "El nocturno es quien hace la ingesta a la base, la normalización al "
            "árbol canónico y la subida de PDF al servidor.",
            "Las tres tareas del proyecto están en modo «solo interactivo»: no "
            "corren si nadie inició sesión. Hay que recrearla con /ru SYSTEM "
            "desde una sesión de administrador.")
    elif noc["ensayo"]:
        add(GRAVE, "La última corrida del nocturno fue un ensayo",
            "estado_nocturno.json dice ensayo: true — no escribió en la base ni "
            "movió un archivo.",
            "No cuenta como corrida. Hay que lanzarlo de verdad.")
    elif noc["ok"] is False:
        fallo = next((p for p in noc["pasos"] if not p.get("ok")), {})
        add(GRAVE, "El saneamiento nocturno falló",
            f"Se cortó en el paso «{fallo.get('paso', '?')}» "
            f"(código {fallo.get('codigo', '?')}). {fallo.get('que', '')}",
            "Corre scripts\\saneamiento_nocturno.bat y mira el registro.")
    elif noc["fin"] and antiguedad_h(noc["fin"]) > NOCTURNO_VIEJO_HORAS:
        add(MEDIO, "El nocturno lleva más de un día sin correr",
            f"Última corrida real {hace_cuanto(noc['fin'])}.", "")
    if noc["candado_huerfano"]:
        add(MEDIO, "Quedó un candado del nocturno abandonado",
            f"logs\\saneamiento.lock es de {hace_cuanto(noc['candado_huerfano'])}. "
            "Una corrida murió sin soltarlo.",
            "Se toma por abandonado a las 6 h y la siguiente corrida lo pisa.")

    if esp["ultimo_espejo"] is None:
        add(GRAVE, "El espejo de producción no ha corrido nunca",
            "Nunca se bajó un informe de producción a la estación. "
            "En el servidor los PDF se borran a los ~3 meses.", "")
    elif esp["espejo_viejo"]:
        add(GRAVE, "El espejo de producción lleva demasiado sin correr",
            f"Último espejo con éxito {hace_cuanto(esp['ultimo_espejo'])}. "
            "Es el único almacenamiento definitivo de los informes.", "")
    if esp["resumen"]["sospechosos"]:
        add(GRAVE, "El espejo bajó 0 archivos teniendo archivos",
            f"Módulos sospechosos: {', '.join(esp['resumen']['sospechosos'])}. "
            "Es el síntoma de un espejo apuntando mal.", "")
    for err in esp["resumen"]["errores"]:
        add(MEDIO, "El espejo reportó un error", err, "")
    if esp["resumen"]["fallidos"]:
        add(MEDIO, f"{esp['resumen']['fallidos']} archivos no se pudieron bajar",
            "En el último resumen del espejo.", "")
    if esp["divergentes"] or esp["resumen"]["divergentes"]:
        n = esp["divergentes"] or esp["resumen"]["divergentes"]
        add(MEDIO, f"{n} archivos divergentes esperando ojo humano",
            "Mismo nombre y contenido distinto: se guardaron aparte y el espejo "
            "no se pisó (I-11).",
            "Están en _ORIGEN_SISTEMA\\_divergentes.")
    if esp["cuarentena"]:
        add(MEDIO, f"{esp['cuarentena']} archivos en cuarentena por hash", "", "")

    # El IDLE muerto: el robot lo tapa reconectando, así que el resultado sale
    # bien y nadie lo nota. La promesa de "reacciona en segundos" pasa a ser
    # "hasta 9 minutos" sin que ninguna otra señal lo diga.
    if reg["conexiones_perdidas"] >= 5 and reg["novedades"] == 0:
        add(MEDIO, "La escucha del buzón no está funcionando",
            f"{reg['conexiones_perdidas']} caídas de conexión en 24 h y ningún "
            "aviso del servidor. El robot se entera por el barrido de "
            "reconexión, no por la escucha: reacciona en hasta 9 minutos en vez "
            "de en segundos.", "")
    if reg["arranques"] > 6:
        add(GRAVE, f"El robot arrancó {reg['arranques']} veces en 24 h",
            "Está en un bucle de caída.", "")
    elif reg["arranques"] > 3:
        add(MEDIO, f"El robot arrancó {reg['arranques']} veces en 24 h", "", "")
    if reg["errores"]:
        add(MEDIO, f"{reg['errores']} errores en el registro en 24 h", "", "")

    if e["buzon"].get("error"):
        add(MEDIO, "No se puede leer el catálogo de casos",
            f"casos_sap.json {e['buzon']['error']}", "")
    else:
        if e["buzon"]["tmp_huerfano"]:
            add(MEDIO, "Quedó un casos_sap.json.tmp a medio escribir",
                "Un barrido murió mientras escribía el catálogo.", "")
        if e["buzon"]["con_alerta"]:
            reglas = ", ".join(f"{k.replace('_', ' ').lower()} {v}"
                               for k, v in e["buzon"]["por_regla"].items())
            add(LEVE, f"{e['buzon']['con_alerta']} casos con alerta para la administración",
                reglas or "",
                "El catálogo avisa que las alertas marcan, no deciden: "
                "el veredicto es de la administración.")
        if e["buzon"]["sin_local"]:
            avisos = ", ".join(str(x.get("aviso"))
                               for x in e["buzon"]["revisar_sin_local"][:4])
            add(LEVE, f"{e['buzon']['sin_local']} casos sin local resuelto",
                f"Avisos {avisos}" if avisos else "", "")
        if e["buzon"]["zona_discrepante"]:
            add(LEVE, f"{e['buzon']['zona_discrepante']} casos con zona discrepante", "", "")

    at = e["atenciones"]
    if at.get("error"):
        add(MEDIO, "No se puede leer el catálogo de atenciones",
            f"atenciones.json {at['error']}", "")
    else:
        if at["no_parseados"]:
            add(MEDIO, f"{at['no_parseados']} informes no se pudieron leer",
                "Alguien tiene que abrirlos a mano.", "")
        if at["sin_tecnico"]:
            add(LEVE, f"{at['sin_tecnico']} informes sin técnico identificado", "", "")

    if e["disco"]["libre_gb"] is not None and e["disco"]["libre_gb"] < 20:
        add(GRAVE, f"Quedan {e['disco']['libre_gb']:.0f} GB libres en D:",
            "Un disco lleno mata el espejo en silencio.", "")

    orden = {GRAVE: 0, MEDIO: 1, LEVE: 2}
    a.sort(key=lambda x: orden[x["gravedad"]])
    return a


# ==============================================================================
# La instantánea completa
# ==============================================================================
def instantanea(forzar: bool = False) -> dict:
    win = _consultar_windows(forzar)
    disco = win.get("disco") or {}
    libre = disco.get("libre")
    robot = bloque_robot(win)
    estado = {
        "momento": ahora(),
        "robot": robot,
        "registro": bloque_registro(robot["registro"]),
        "buzon": bloque_buzon(),
        "atenciones": bloque_atenciones(),
        "espejo": bloque_espejo(),
        "nocturno": bloque_nocturno(win),
        "tareas": win.get("tareas") or [],
        "disco": {"libre_gb": libre / 2**30 if libre else None,
                  "usado_gb": (disco.get("usado") or 0) / 2**30},
    }
    estado["alertas"] = calcular_alertas(estado)
    estado["salud"] = (GRAVE if any(x["gravedad"] == GRAVE for x in estado["alertas"])
                       else MEDIO if any(x["gravedad"] == MEDIO for x in estado["alertas"])
                       else "bien")
    return estado


def _json_seguro(o):
    if isinstance(o, datetime):
        return o.isoformat()
    if isinstance(o, (set, Path)):
        return sorted(o) if isinstance(o, set) else str(o)
    return str(o)


def main() -> int:
    e = instantanea(forzar=True)
    if "--json" in sys.argv:
        print(json.dumps(e, default=_json_seguro, ensure_ascii=False, indent=2))
        return 0

    r, esp = e["robot"], e["espejo"]
    print(f"\n  InspectorBot · {e['momento']:%Y-%m-%d %H:%M:%S} · salud: {e['salud'].upper()}\n")
    if r["vivo"]:
        print(f"  Robot     vivo, PID {r['pid']}, arrancado {hace_cuanto(r['inicio'])}"
              f" ({r['robots']} robot(s), {r['procesos_en_lista']} procesos en la lista)")
    else:
        print("  Robot     CAÍDO")
    print(f"  Señal     {hace_cuanto(r['ultima_senal'])}"
          f"  ({r['registro'].name if r['registro'] else 'sin registro'})")
    print(f"  Buzón     {fmt(e['buzon'].get('casos'))} casos vigentes"
          f"  ·  atendidos {fmt(e['atenciones'].get('con_atencion'))}"
          f"  ·  sin atender {fmt(e['atenciones'].get('sin_atencion'))}")
    print(f"  Espejo    {fmt(esp['total'])} PDF  ·  último {hace_cuanto(esp['ultimo_espejo'])}")
    print(f"\n  {len(e['alertas'])} alertas:")
    for x in e["alertas"]:
        print(f"    [{x['gravedad']:>5}] {x['titulo']}")
        if x["detalle"]:
            print(f"            {x['detalle'][:120]}")
    print()
    return 0


if __name__ == "__main__":
    sys.exit(main())
