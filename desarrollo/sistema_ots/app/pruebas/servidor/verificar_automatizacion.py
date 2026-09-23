"""verificar_automatizacion.py — El panel «Automatización» (T2.27.7), contra el sitio de pruebas.

Andrés pidió el 2026-09-23 las tareas programadas dentro del panel, INACTIVAS: se
activan después con la aprobación de la administradora. Esta batería comprueba
eso sin activar nada de verdad:

  1. la migración 020: 5 tareas, las 5 inactivas, 23 destinatarios, 2 permisos;
  2. la puerta de la estación (automatizacion_cli.php): lista, simula un registro
     y rechaza una corrida «de horario» de una tarea inactiva;
  3. las reglas de activar/desactivar, DENTRO DE UNA TRANSACCIÓN QUE SE REVIERTE:
     sin nota no activa, con nota sí; con la tarea activa no se cambia el modo ni
     se desactiva el único revisor; al final todo vuelve a como estaba;
  4. la pantalla: la administración la ve con las 5 tareas inactivas; un jefe de
     zona y un técnico reciben 403;
  5. el programador de la estación: sin tareas activas no hace nada.

Solo lectura, salvo la transacción del punto 3, que se revierte (y se comprueba).
    PYTHONUTF8=1 python verificar_automatizacion.py
"""
import json
import re
import subprocess
import sys
from pathlib import Path

import verificar_http as vh

sys.stdout.reconfigure(encoding="utf-8")
res = []


def ok(que, cond, obt=""):
    res.append(("ok " if cond else "MAL", que, obt))


def php(codigo: str, entrada: str | None = None) -> tuple[int, str]:
    r = subprocess.run(vh.SSH + [f"cd {vh.D} && php -d display_errors=1"], input=codigo if entrada is None else codigo,
                       capture_output=True, text=True, encoding="utf-8", timeout=180)
    return r.returncode, r.stdout


def cli(args: str, entrada: dict | None = None) -> tuple[int, dict]:
    r = subprocess.run(vh.SSH + [f"cd {vh.D} && php automatizacion_cli.php {args}"],
                       input=json.dumps(entrada) if entrada is not None else None,
                       capture_output=True, text=True, encoding="utf-8", timeout=120)
    try:
        return r.returncode, json.loads(r.stdout.strip().splitlines()[-1])
    except Exception:
        return r.returncode, {"crudo": r.stdout[-300:] + r.stderr[-300:]}


# 1. La migración ------------------------------------------------------------
t = vh.sql("SELECT clave, activa, modo FROM automatizacion_tareas ORDER BY tarea_id")
ok("020: cinco tareas", len(t) == 5, len(t))
ok("020: las cinco INACTIVAS", all(int(x["activa"]) == 0 for x in t), [x["activa"] for x in t])
n_dest = int(vh.sql("SELECT COUNT(*) n FROM automatizacion_destinatarios")[0]["n"])
ok("020: 23 destinatarios sembrados del correo enviado", n_dest == 23, n_dest)
ok("020: el permiso para SUPERADMIN y ADMIN", int(vh.sql("SELECT COUNT(*) n FROM rol_permisos WHERE permiso='automatizacion.configurar'")[0]["n"]) == 2)
antes = {k: int(vh.sql(f"SELECT COUNT(*) n FROM {k}")[0]["n"]) for k in ("automatizacion_corridas", "automatizacion_cambios")}

# 2. La puerta de la estación --------------------------------------------------
c, d = cli("tareas")
ok("cli tareas: responde con las 5 y sus destinatarios activos", c == 0 and len(d.get("tareas", [])) == 5
   and sum(len(x["destinatarios"]) for x in d["tareas"]) == 23, c)
corrida = {"clave": "KFC_STATUS_MARTES", "programada_para": "2026-09-22 07:00:00", "inicio": "2026-09-22 07:00:05",
           "fin": "2026-09-22 07:01:10", "estado": "OK", "enviado": "NO", "archivos": ["prueba.xlsx"],
           "mensaje": "prueba de verificar_automatizacion", "equipo": "prueba", "manual": True}
c, d = cli("registrar --simular", corrida)
ok("cli registrar --simular: inserta y revierte", c == 0 and d.get("simulado") is True and d.get("filas") == 1, d)
c, d = cli("registrar --simular", {**corrida, "manual": False})
ok("cli registrar: rechaza una corrida de horario de una tarea INACTIVA", c == 2 and "inactiva" in d.get("error", ""), d)
c, d = cli("registrar --simular", {**corrida, "estado": "BORRADO"})
ok("cli registrar: rechaza un estado inventado", c == 2, d)

# 3. Las reglas, en una transacción que se revierte -----------------------------
codigo = r"""<?php
error_reporting(E_ALL);
require getcwd() . '/nucleo/Automatizacion.php';
$u = Db::uno("SELECT * FROM usuarios WHERE rol = 'ADMIN' AND activo = 1 ORDER BY usuario_id LIMIT 1");
$rp = new ReflectionProperty(Auth::class, 'usuario'); $rp->setAccessible(true); $rp->setValue(null, $u);
$pdo = Db::conn(); $pdo->beginTransaction();
$id = (int) Db::uno("SELECT tarea_id FROM automatizacion_tareas WHERE clave = 'KFC_STATUS_MARTES'")['tarea_id'];
$r = [];
$r['sin_nota'] = Automatizacion::activar($id, 'ok', $u);
$r['con_nota'] = Automatizacion::activar($id, 'Aprobado en prueba automatica, se revierte', $u);
$r['quedo_activa'] = (int) Db::uno('SELECT activa FROM automatizacion_tareas WHERE tarea_id = ?', [$id])['activa'];
$r['aprobada_por'] = Db::uno('SELECT aprobada_por FROM automatizacion_tareas WHERE tarea_id = ?', [$id])['aprobada_por'] === $u['usuario_id'] || (int) Db::uno('SELECT aprobada_por FROM automatizacion_tareas WHERE tarea_id = ?', [$id])['aprobada_por'] === (int) $u['usuario_id'];
$r['modo_activa'] = Automatizacion::cambiarModo($id, 'ENVIAR', $u);
$rev = Db::uno("SELECT destinatario_id FROM automatizacion_destinatarios WHERE tarea_id = ? AND tipo = 'REVISION'", [$id]);
$r['quitar_unico_revisor'] = Automatizacion::destinatarioActivo((int) $rev['destinatario_id'], false, $u);
$r['revision_externa'] = Automatizacion::agregarDestinatario($id, 'REVISION', 'alguien@kfc.com.ec', '', $u);
$r['horario_malo'] = Automatizacion::cambiarHorario($id, [], null, '25:00', $u);
$r['desactivar'] = Automatizacion::desactivar($id, '', $u);
$r['quedo_inactiva'] = (int) Db::uno('SELECT activa FROM automatizacion_tareas WHERE tarea_id = ?', [$id])['activa'];
$pdo->rollBack();
$r['despues_rollback'] = (int) Db::uno('SELECT activa FROM automatizacion_tareas WHERE tarea_id = ?', [$id])['activa'];
echo json_encode($r, JSON_UNESCAPED_UNICODE);
"""
c, salida = php(codigo)
try:
    r = json.loads(salida.strip().splitlines()[-1])
except Exception:
    r = {}
    print(salida[-800:])
ok("activar sin nota: se rechaza", bool(r.get("sin_nota")), r.get("sin_nota"))
ok("activar con la nota de quién aprobó: se activa", r.get("con_nota") is None and r.get("quedo_activa") == 1, r.get("con_nota"))
ok("la activación guarda quién aprobó", r.get("aprobada_por") is True)
ok("con la tarea activa no se cambia el modo", bool(r.get("modo_activa")), r.get("modo_activa"))
ok("con la tarea activa no se desactiva el único revisor", bool(r.get("quitar_unico_revisor")), r.get("quitar_unico_revisor"))
ok("un revisor tiene que ser @industec.me", bool(r.get("revision_externa")), r.get("revision_externa"))
ok("un horario inválido se rechaza", bool(r.get("horario_malo")), r.get("horario_malo"))
ok("desactivar funciona", r.get("desactivar") is None and r.get("quedo_inactiva") == 0)
ok("la transacción se revirtió: sigue INACTIVA", r.get("despues_rollback") == 0, r.get("despues_rollback"))
despues = {k: int(vh.sql(f"SELECT COUNT(*) n FROM {k}")[0]["n"]) for k in ("automatizacion_corridas", "automatizacion_cambios")}
ok("ni una corrida ni un cambio quedaron guardados", antes == despues, f"{antes} → {despues}")
ok("las cinco siguen inactivas al terminar", all(int(x["activa"]) == 0 for x in vh.sql("SELECT activa FROM automatizacion_tareas")))

# 4. La pantalla, por rol -----------------------------------------------------
def pantalla(rol: str) -> str:
    cod = rf"""<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SCRIPT_NAME'] = '/ot/automatizacion.php';
require getcwd() . '/nucleo/Ui.php';
$u = Db::uno("SELECT * FROM usuarios WHERE rol = '{rol}' AND activo = 1 ORDER BY usuario_id LIMIT 1");
if (!$u) {{ echo 'SIN_USUARIO'; exit; }}
$u['debe_cambiar_clave'] = 0;
$rp = new ReflectionProperty(Auth::class, 'usuario'); $rp->setAccessible(true); $rp->setValue(null, $u);
include getcwd() . '/automatizacion.php';
"""
    return php(cod)[1]


h = pantalla("ADMIN")
ok("administración: la pantalla se dibuja sin errores de PHP", "Reportes programados" in h and not re.search(r"Fatal error|Warning:|Uncaught", h), len(h))
ok("administración: las 5 tareas aparecen inactivas", h.count('class="estado-red sin" style="margin-left:8px">inactiva') == 5)
ok("administración: está la sección de correos para T2.28.2", "Correos de las órdenes" in h)
ok("administración: el menú muestra «Automatización»", 'href="automatizacion.php"' in h)
for rol in ("JEFE_ZONA", "TECNICO"):
    h = pantalla(rol)
    ok(f"{rol}: no entra (403)", "SIN_USUARIO" in h or "No tienes acceso al panel" in h, h[:80])

# 5. El programador de la estación ---------------------------------------------
AG = vh.REPO / "desarrollo" / "agentes"
r = subprocess.run([str(AG / ".venv" / "Scripts" / "python.exe"), str(AG / "scripts" / "t2_27_programador.py")],
                   capture_output=True, text=True, encoding="utf-8", timeout=300, env={**__import__("os").environ, "PYTHONUTF8": "1"})
ok("programador sin tareas activas: no corre nada", r.returncode == 0 and "Nada que correr" in r.stdout and "Tareas activas en el panel: 0 de 5" in r.stdout, r.stdout.strip()[-120:])
r = subprocess.run([str(AG / ".venv" / "Scripts" / "python.exe"), str(AG / "scripts" / "t2_27_programador.py"), "--forzar", "KFC_STATUS_MARTES", "--simular"],
                   capture_output=True, text=True, encoding="utf-8", timeout=300, env={**__import__("os").environ, "PYTHONUTF8": "1"})
ok("programador --forzar --simular: muestra el plan y no hace nada", r.returncode == 0 and "simulación" in r.stdout and "servicioalcliente@industec.me" in r.stdout, r.stdout.strip()[-160:])

for e, q, o in res:
    print(f"{e} {q:<74} {json.dumps(o, ensure_ascii=False, default=str)[:140]}")
mal = sum(1 for x in res if x[0] == "MAL")
print(f"\n{len(res)} comprobaciones · {mal} fallos")
sys.exit(1 if mal else 0)
