"""verificar_cifras.py — Las cifras del inicio, de Reportes y del buzón, contra los datos reales.

Nació el 2026-09-22, cuando Andrés pidió revisar que las estadísticas reportaran
información real. Tres cifras no lo hacían: «Vivos en 90 días» mostraba 884 cuando
abiertos había 118; «se concluye en una visita» daba 100 % porque salía de una
tabla con 4 filas de prueba; y 644 «sin atender» ya regularizados se pintaban en rojo.

Dibuja panel.php y casos.php por GET como la administradora, en procesos PHP
separados (Ui::pie() termina con exit), y compara cada cifra con una referencia
independiente sacada de SQL y del JSON crudo. No escribe nada en el servidor: ni reportes.php se dibuja,
porque anota una consulta en la bitácora a nombre de la administradora.

    PYTHONUTF8=1 python verificar_cifras.py      # 21 · 0 el 2026-09-22

Desde el 2026-09-24 (vocabulario único, tarjeta por zona) el cuadro «Siguen
abiertos» pasó a «TOTAL DE ÓRDENES ABIERTAS» y CAMBIÓ DE CIFRA: ya no incluye
ATENDIDO («atendidas, por cerrar en SAP» va al pie de cada tarjeta) y cuenta
una vez cada cadena de continuidad. La referencia TOTAL_ABIERTAS se calcula
aparte, por estado y sin pasar por `Casos::grupoOrden()`, para que la
comprobación no sea tautológica. Además se comprueba que las tarjetas cuadren
con el cuadro y que cada cifra sea igual a las filas de su enlace.
"""
import html
import json
import re
import subprocess
import sys

import verificar_http as vh   # la llave, la ruta del sitio y la compuerta de SSH, en un solo sitio

sys.stdout.reconfigure(encoding="utf-8")
D, SSH = vh.D, vh.SSH

CABEZA = r"""<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$_SERVER['REQUEST_METHOD'] = 'GET';
require getcwd() . '/nucleo/Reportes.php';
$u = Db::uno("SELECT * FROM usuarios WHERE rol = 'ADMIN' AND activo = 1 ORDER BY usuario_id LIMIT 1");
$u['debe_cambiar_clave'] = 0;
$rp = new ReflectionProperty(Auth::class, 'usuario'); $rp->setAccessible(true); $rp->setValue(null, $u);
"""

REF = CABEZA + r"""
$datos = Casos::catalogo()['datos'] ?? [];
$enCat = array_flip(array_map('strval', array_column($datos, 'aviso')));
$ref = ['CSA_SIN' => 0, 'REG' => 0, 'ABIERTOS' => 0, 'TOTAL_ABIERTAS' => 0, 'N' => count($datos)];
$filas = [];
// `continua_de` llega con la 012: sin ella, cada orden es su propia cadena.
try {
    // `repuesto_gestion` llega con la 022: sin ella, todo es sin precisar.
    $gs = Db::todos('SELECT aviso, estado, regularizado_en, continua_de, repuesto_gestion FROM casos_gestion');
} catch (Throwable $e) {
    try {
        $gs = Db::todos('SELECT aviso, estado, regularizado_en, continua_de, NULL AS repuesto_gestion FROM casos_gestion');
    } catch (Throwable $e2) {
        $gs = Db::todos('SELECT aviso, estado, regularizado_en, NULL AS continua_de, NULL AS repuesto_gestion FROM casos_gestion');
    }
}
foreach ($gs as $f) { $filas[(string) $f['aviso']] = $f; }
$raices = [];
foreach ($enCat as $a => $_) {
    $f = $filas[(string) $a] ?? ['estado' => 'NUEVO', 'regularizado_en' => null, 'continua_de' => null];
    if ($f['estado'] === 'CERRADO_SIN_ATENCION') { $f['regularizado_en'] ? $ref['REG']++ : $ref['CSA_SIN']++; }
    if (!in_array($f['estado'], ['RESUELTO', 'NO_COMPETE', 'CERRADO_SIN_ATENCION'], true)) { $ref['ABIERTOS']++; }
    // El TOTAL DE ÓRDENES ABIERTAS, por estado y una vez por cadena (la cadena
    // se guarda plana: todas apuntan a la primera).
    if (in_array($f['estado'], ['NUEVO', 'ASIGNADO', 'EN_REVISION', 'ESPERA_REPUESTO'], true)) {
        $raices[trim((string) ($f['continua_de'] ?? '')) ?: (string) $a] = true;
    }
}
$ref['TOTAL_ABIERTAS'] = count($raices);
// El cuadro «En qué estado están» (27-sep-2026): conteo por estado DE VISTA, orden por
// orden, escrito aquí y no con Casos::CIFRAS_ESTADO, para que la comparación no sea tautológica.
$ref['VISTA'] = [];
foreach ($enCat as $a => $_) {
    $f = $filas[(string) $a] ?? ['estado' => 'NUEVO', 'regularizado_en' => null];
    $v = strtoupper((string) $f['estado']);
    if ($v === 'CERRADO_SIN_ATENCION' && !empty($f['regularizado_en'])) { $v = 'REGULARIZADO'; }
    $ref['VISTA'][$v] = ($ref['VISTA'][$v] ?? 0) + 1;
    if ($v === 'ESPERA_REPUESTO') {
        $p = strtoupper((string) ($f['repuesto_gestion'] ?? '')) ?: 'SIN_PRECISAR';
        $ref['REP'][$p] = ($ref['REP'][$p] ?? 0) + 1;
    }
}
$ref['REP'] = $ref['REP'] ?? [];
$conc = 0; $una = 0; $curso = 0;
foreach (Casos::atenciones() as $a => $x) {
    if (!isset($enCat[(string) $a])) { continue; }
    if ($x['estado_industec'] !== 'CERRADA') { $curso++; continue; }
    $conc++;
    $d = array_unique(array_map(fn($o) => substr((string) $o['fecha'], 0, 10), $x['ots']));
    if (count($d) <= 1) { $una++; }
}
$ref += ['concluidos' => $conc, 'una' => $una, 'en_curso' => $curso];
$r = Reportes::calcular(null, null);
// El rótulo del estado de vista REGULARIZADO, del diccionario: la prueba no
// escribe la palabra (era «regularizado»; desde el 24-sep-2026, «regularizada»).
$ref['ETQ_REG'] = Ui::etiquetaEstado('REGULARIZADO');
$ref['ETQ_CSA'] = Ui::etiquetaEstado('CERRADO_SIN_ATENCION');   // antes «sin atender»
$ref['CASOS_ABIERTOS'] = count($r['casos_abiertos']);
echo json_encode(['ref' => $ref, 'por_estado' => $r['por_estado'], 'estados' => $r['estados'], 'salud' => $r['salud'],
                  'rend' => array_map(fn($t) => [$t['nombre'], $t['cerrados'], $t['una_visita'], $t['pct_una_visita']], $r['rendimiento'])],
                 JSON_UNESCAPED_UNICODE);
"""


def pagina(nombre, get):
    php = CABEZA + f"$_SERVER['SCRIPT_NAME'] = '/ot/{nombre}'; $_GET = {get};\ninclude getcwd() . '/{nombre}';\n"
    return correr(php)


def correr(php):
    r = subprocess.run(SSH + [f"cd {D} && php -d display_errors=1"], input=php, capture_output=True,
                       text=True, encoding="utf-8", timeout=180)
    if r.returncode != 0:
        raise SystemExit(f"php salió con {r.returncode}: {r.stderr[:300]} {r.stdout[-300:]}")
    return r.stdout


res = []


def ok(que, cond, obt=""):
    res.append(("ok " if cond else "MAL", que, obt))


d = json.loads(correr(REF))
ref, pe, s = d["ref"], d["por_estado"], d["salud"]
print("referencia:", json.dumps(ref, ensure_ascii=False))
print("salud:", json.dumps(s, ensure_ascii=False))
ok("reportes: regularizados aparte, con su cifra", pe.get("REGULARIZADO", 0) == ref["REG"], pe.get("REGULARIZADO", 0))
ok("reportes: «sin atender» = solo los sin regularizar", pe.get("CERRADO_SIN_ATENCION", 0) == ref["CSA_SIN"], pe.get("CERRADO_SIN_ATENCION", 0))
ok("reportes: la suma de estados = casos del periodo", sum(pe.values()) == ref["N"], f"{sum(pe.values())} / {ref['N']}")
col = next((x["c"] for x in d["estados"] if x["e"] == ref["ETQ_REG"]), None)
ok(f"reportes: el color de «{ref['ETQ_REG']}» no es el rojo", col is not None and col != "#e34948", col)
ok("reportes: concluidos = casos con orden Cerrada", s["concluidos"] == ref["concluidos"], s["concluidos"])
ok("reportes: en una visita = mismo día", s["concluye_una"] == ref["una"], s["concluye_una"])
ok("reportes: con la orden abierta, aparte", s["en_curso"] == ref["en_curso"], s["en_curso"])
esperado = round(ref["una"] * 100 / ref["concluidos"]) if ref["concluidos"] else None
ok("reportes: % en una visita = referencia", s["pct_concluye"] == esperado, s["pct_concluye"])
# Desde el 24-sep-2026 el reporte cuenta el TOTAL DE ÓRDENES ABIERTAS de la tarjeta
# (sin ATENDIDO, una vez por cadena), no «siguen abiertos»: la misma referencia
# independiente que el cuadro del panel.
ok("reportes: TOTAL DE ÓRDENES ABIERTAS = SQL (sin ATENDIDO, una por cadena)", s["abiertos"] == ref["TOTAL_ABIERTAS"], s["abiertos"])
ok("reportes: la hoja del total tiene tantas filas como el total", ref["CASOS_ABIERTOS"] == ref["TOTAL_ABIERTAS"], [ref["CASOS_ABIERTOS"], ref["TOTAL_ABIERTAS"]])
ok("reportes: ABIERTAS + A ESPERA = TOTAL (o no disponible)",
   s.get("abiertas") is None or s["abiertas"] + s["espera_informe"] == s["abiertos"], [s.get("abiertas"), s.get("espera_informe"), s["abiertos"]])
ok("rendimiento: nadie con % sin casos cerrados", not [t for t in d["rend"] if t[3] is not None and t[1] == 0], "")

h = pagina("panel.php", "[]")
ok("panel: sin error de PHP", not re.search(r"Fatal error|Uncaught|Warning:", h), f"{len(h)} bytes")
ok("panel: ya no dice «Vivos en 90 días»", "Vivos en 90 días" not in h)
ok("panel: ya no dice «Siguen abiertos»", "Siguen abiertos" not in h)
m = re.search(r'data-n="(\d+)">0</div>\s*<div class="t">TOTAL DE ÓRDENES ABIERTAS', h)
cuadro = int(m.group(1)) if m else None
ok("panel: «TOTAL DE ÓRDENES ABIERTAS» = SQL (sin ATENDIDO, una por cadena)", cuadro == ref["TOTAL_ABIERTAS"], cuadro if m else "no está")

# La tarjeta por zona: tres zonas (y OTRA si tiene), cada una con su TOTAL.
tarjetas = re.findall(r'<div class="zona-card zona-(\w+)">(.*?)</ul></div>', h, re.S)
ok("panel: una tarjeta por zona (UIO, LARB, CUENCA-LOJA)", len(tarjetas) >= 3, [t[0] for t in tarjetas])
ok("panel: CNLJ se rotula «ZONA CUENCA-LOJA»", "ZONA CUENCA-LOJA" in h)
tot_zona = {}
for cl, cuerpo in tarjetas:
    mt = re.search(r'TOTAL DE ÓRDENES ABIERTAS</span><span class="zf-n">(?:<a [^>]*>)?<b>(\d+)</b>', cuerpo)
    tot_zona[cl] = int(mt.group(1)) if mt else None
mg = re.search(r'data-total-general="(\d+)"', h)
general = int(mg.group(1)) if mg else None
ok("panel: total general = UIO + LARB + CUENCA-LOJA",
   general is not None and general == sum(tot_zona.get(z) or 0 for z in ("uio", "larb", "cnlj")), [general, tot_zona])
msz = re.search(r'SIN ZONA <a href="casos\.php\?zona=SIN&amp;grupo=total"><b>(\d+)</b>', h)
sin_zona = int(msz.group(1)) if msz else 0
ok("panel: Σ tarjetas (con OTRA) + sin zona = cuadro TOTAL",
   cuadro is not None and sum(v or 0 for v in tot_zona.values()) + sin_zona == cuadro,
   [sum(v or 0 for v in tot_zona.values()), sin_zona, cuadro])
# Desde el 27-sep-2026 el gráfico de barras se titula «Todos los estados»; «En qué estado
# están» es el cuadro de las cuatro cifras de la administradora, que va justo encima.
m = re.search(r"data-titulo=\"Todos los estados\".*?data-datos='([^']*)'", h, re.S)
barras = {b["e"]: b for b in json.loads(html.unescape(m.group(1)))} if m else {}
# Las barras se rotulan con Ui::etiquetaEstado() desde el diccionario (24-sep-2026):
# la prueba busca el rótulo vigente por su clave y no escribe la palabra. Con el
# literal viejo («regularizado», «sin atender») la primera fallaba siempre y la
# segunda pasaba siempre, sin medir nada.
reg = barras.get(ref["ETQ_REG"])
ok(f"panel: barra «{ref['ETQ_REG']}» con la cifra y en gris", bool(reg) and reg["v"] == ref["REG"] and reg["c"] != "#e34948", reg)
ok(f"panel: sin barra roja «{ref['ETQ_CSA']}» cuando no falta regularizar", ref["CSA_SIN"] > 0 or ref["ETQ_CSA"] not in barras, barras.get(ref["ETQ_CSA"], "no está"))
# La tarea (no el pie de la tarjeta, que siempre dice su cifra, aunque sea 0).
tarea_sr = re.search(r'<span class="num"[^>]*>\d+</span>\s*cerradas? sin atención, sin regularizar ante KFC', h)
ok("panel: la tarea «sin regularizar» aparece solo si hay", (ref["CSA_SIN"] > 0) == bool(tarea_sr))
ok("panel: «Órdenes nuevas en 7 días»", "Órdenes nuevas en 7 días" in h)

# El cuadro «En qué estado están» (27-sep-2026): cuatro cifras, cada una = la referencia por
# estado de vista, y las de un solo estado enlazan a casos.php?est= con tantas filas.
V = ref["VISTA"]
cuadro = {}
seg = h[h.find("<h2>En qué estado están</h2>"):]
seg = seg[:seg.find("viz-grid")] if "viz-grid" in seg else seg
for mt in re.finditer(r'<(?:a|div) class="tile[^"]*"(?: href="([^"]*)")?[^>]*>\s*<div class="n" data-n="(\d+)">', seg):
    cuadro[mt.group(1) or f"sin enlace {len(cuadro)}"] = int(mt.group(2))
ok("panel: el cuadro «En qué estado están» tiene cuatro cifras", len(cuadro) == 4, cuadro)
ok("panel: creadas que no nos competen = NO_COMPETE", cuadro.get("casos.php?est=NO_COMPETE") == V.get("NO_COMPETE", 0), [cuadro.get("casos.php?est=NO_COMPETE"), V.get("NO_COMPETE", 0)])
ok("panel: asignadas, en gestión técnica = ASIGNADO", cuadro.get("casos.php?est=ASIGNADO") == V.get("ASIGNADO", 0), [cuadro.get("casos.php?est=ASIGNADO"), V.get("ASIGNADO", 0)])
ok("panel: a espera de repuesto (gestión proveedores KFC) = ESPERA_REPUESTO", cuadro.get("casos.php?est=ESPERA_REPUESTO") == V.get("ESPERA_REPUESTO", 0), [cuadro.get("casos.php?est=ESPERA_REPUESTO"), V.get("ESPERA_REPUESTO", 0)])
sin_enlace = [v for k, v in cuadro.items() if k.startswith("sin enlace")]
ok("panel: atendidas, cerradas por INDUSTEC = ATENDIDO + RESUELTO", sin_enlace == [V.get("ATENDIDO", 0) + V.get("RESUELTO", 0)], [sin_enlace, V.get("ATENDIDO", 0), V.get("RESUELTO", 0)])
ok("panel: ya no está la tarjeta vieja «A espera de repuesto» del buzón", "est=ESPERA_REPUESTO&amp;grupo=total" not in h)
# El desglose de la cuarta cifra por paso de la gestión de KFC (022): cada enlace del pie
# lleva su cifra, y esa cifra es las filas de casos.php?est=ESPERA_REPUESTO&rep=.
pie_rep = dict((mp.group(1), int(mp.group(2))) for mp in re.finditer(r'href="casos\.php\?est=ESPERA_REPUESTO&amp;rep=([A-Z_]+)"[^>]*>(\d+) ', seg))
for paso, n in pie_rep.items():
    clave = "SIN_PRECISAR" if paso == "SIN" else paso
    ok(f"panel: gestión del repuesto {paso} = SQL", n == ref["REP"].get(clave, 0), [n, ref["REP"].get(clave, 0)])
    hr = pagina("casos.php", f"['est' => 'ESPERA_REPUESTO', 'rep' => '{paso}']")
    mr = re.search(r'<b id="cuenta-casos"[^>]*>(\d+)</b>', hr)
    ok(f"casos.php?est=ESPERA_REPUESTO&rep={paso}: filas = la cifra del pie", mr is not None and int(mr.group(1)) == n, [mr.group(1) if mr else None, n])
ok("panel: el desglose suma la cuarta cifra", sum(pie_rep.values()) == V.get("ESPERA_REPUESTO", 0), [sum(pie_rep.values()), V.get("ESPERA_REPUESTO", 0)])
for est in ("NO_COMPETE", "ASIGNADO", "ESPERA_REPUESTO", "ATENDIDO", "RESUELTO"):
    he = pagina("casos.php", f"['est' => '{est}']")
    mc = re.search(r'<b id="cuenta-casos"[^>]*>(\d+)</b>', he)
    ok(f"casos.php?est={est}: filas = la referencia por estado de vista", mc is not None and int(mc.group(1)) == V.get(est, 0), [mc.group(1) if mc else None, V.get(est, 0)])

# Cada cifra de la tarjeta = las filas de su enlace (misma función en los dos lados).
for zona, cl in (("UIO", "uio"), ("LARB", "larb"), ("CNLJ", "cnlj")):
    hz = pagina("casos.php", f"['zona' => '{zona}', 'grupo' => 'total']")
    mc = re.search(r'<b id="cuenta-casos"[^>]*>(\d+)</b>', hz)
    filas_enlace = int(mc.group(1)) if mc else None
    ok(f"casos.php?zona={zona}&grupo=total: filas = TOTAL de la tarjeta", filas_enlace == tot_zona.get(cl),
       [filas_enlace, tot_zona.get(cl)])

for est, esp in (("CERRADO_SIN_ATENCION", ref["CSA_SIN"]), ("REGULARIZADO", ref["REG"])):
    h = pagina("casos.php", f"['est' => '{est}']")
    rojo = h.count("est est-cerrado_sin_atencion\" title=")
    gris = h.count("est est-regularizado\" title=")
    ok(f"casos.php?est={est}: sin error de PHP", not re.search(r"Fatal error|Uncaught|Warning:", h), f"{len(h)} bytes")
    print(f"casos.php?est={est}: {rojo} distintivos rojos, {gris} grises (esperado {esp})")
    if est == "REGULARIZADO":
        ok("casos.php: los regularizados salen en gris y ninguno en rojo", gris >= esp and rojo == 0, f"{gris} gris · {rojo} rojo")
    else:
        ok("casos.php: «sin atender» lista solo los sin regularizar", gris == 0, f"{gris} gris · {rojo} rojo")

for e, q, o in res:
    print(f"{e} {q:<66} {json.dumps(o, ensure_ascii=False)}")
mal = sum(1 for r in res if r[0] == "MAL")
print(f"\n{len(res)} comprobaciones · {mal} fallos")
sys.exit(1 if mal else 0)
