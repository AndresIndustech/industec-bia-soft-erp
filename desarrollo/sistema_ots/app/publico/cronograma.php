<?php
declare(strict_types=1);

/**
 * cronograma.php — Sirve el cronograma preventivo y los pendientes correctivos.
 *
 * DE DÓNDE SALE (D15, T2.14.5). Desde la 009 el cronograma vive en la base:
 * `ingresos_preventivos` (lo acordado con KFC, lo vigente, el kit, lo real)
 * y `cronograma_novedades` (cada reagenda con su motivo). Mientras la tabla
 * esté vacía —hasta correr `cronograma_importar_cli.php`— se sigue leyendo
 * `catalogos/cronograma_preventivo.json`, y la respuesta dice de dónde salió
 * (`fuente`) para que la pantalla lo muestre en vez de fingir que escribe.
 *
 * Lo que escribe la pantalla va por `cronograma_accion.php` (POST JSON con
 * X-Csrf): por eso aquí viaja también el token, los módulos de la barra (la
 * navegación deja de estar escrita a mano) y el rol.
 *
 * -------------------------------------------------------------------------
 * EXIGE SESIÓN Y FILTRA POR ZONA. Verificado el 2026-09-10: no hacía ninguna
 * de las dos cosas. El alcance se aplica AQUÍ, en el servidor, igual que en
 * `Casos::enAlcance()`. Lo que un jefe de otra zona no necesita —el maestro,
 * el padrón, los ingresos ajenos— no viaja.
 * -------------------------------------------------------------------------
 */
require_once __DIR__ . '/nucleo/Auth.php';
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Ui.php';
require_once __DIR__ . '/nucleo/Reportes.php';
$u = Auth::exigir('cronograma.ver', true);
$zonaAlcance = Auth::zonaAlcance();   // null = las tres zonas

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

$candidatos = [
    __DIR__ . '/catalogos',
    __DIR__ . '/../../../../SALIDAS IA/OTS/catalogos',
];
$base = null;
foreach ($candidatos as $c) {
    if (is_file($c . '/locales.json')) { $base = $c; break; }
}
if ($base === null) {
    http_response_code(500);
    echo json_encode(['error' => 'faltan los catálogos (locales.json); corre t2_5_catalogos.py'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- Los ingresos: de la tabla si tiene filas, si no del JSON -------------- */
$zonaConsulta = $zonaAlcance !== null ? ($zonaAlcance !== '' ? $zonaAlcance : '-') : null;
$pre = Reportes::ingresosPreventivos($zonaConsulta);
$fuente = $pre['fuente'];
$ingresos = $pre['ingresos'];
$generadoCron = $pre['generado'];
$anio = (int) date('Y');
$conversion = null;
$fueraMaestro = null;
if ($fuente === 'json' && is_file($base . '/cronograma_preventivo.json')) {
    $j = json_decode((string) file_get_contents($base . '/cronograma_preventivo.json'), true);
    $anio = (int) ($j['anio'] ?? $anio);
    $conversion = $j['conversion'] ?? null;
    $fueraMaestro = $j['locales_fuera_del_maestro'] ?? null;
    $generadoCron = (string) ($j['generado'] ?? $generadoCron);
} elseif ($fuente === 'tabla') {
    $anios = array_count_values(array_map(fn($i) => (int) ($i['anio'] ?? 0), $ingresos));
    if ($anios) { arsort($anios); $anio = (int) array_key_first($anios); }
    $ultima = null;
    try { $ultima = Db::uno('SELECT MAX(actualizado_en) m FROM ingresos_preventivos')['m'] ?? null; } catch (Throwable $e) {}
    $generadoCron = $ultima ?: date('c');
}

// Cada ingreso trae su estado calculado HOY (como lo hacía cronograma.js),
// para que la pantalla, el reporte y la exportación digan lo mismo.
$hoy = (new DateTimeImmutable('now', new DateTimeZone('America/Guayaquil')))->format('Y-m-d');
foreach ($ingresos as &$i) {
    $i['estado_hoy'] = Reportes::estadoPreventivo($i, $hoy);
    unset($i['forma_origen']);
}
unset($i);

// Locales, para el selector de «agendar un local».
$locales = [];
if (is_file($base . '/locales.json')) {
    $j = json_decode((string) file_get_contents($base . '/locales.json'), true);
    $locales = $j['datos'] ?? $j;
}

// Técnicos, para saber quién entra y con qué zona.
$tecnicos = [];
if (is_file($base . '/tecnicos.json')) {
    $j = json_decode((string) file_get_contents($base . '/tecnicos.json'), true);
    $tecnicos = $j['datos'] ?? $j;
}

// Correctivos abiertos: alimentan el contador de pendientes del técnico.
// Con `??` en todas las claves (TR-24): un caso sin `prioridad` o sin
// `local_nombre` emitía un Warning dentro de la respuesta JSON.
$correctivos = [];
if (is_file($base . '/casos_sap.json')) {
    $j = json_decode((string) file_get_contents($base . '/casos_sap.json'), true);
    foreach (($j['datos'] ?? []) as $c) {
        $correctivos[] = [
            'aviso'          => $c['aviso'] ?? null,
            'local'          => $c['local'] ?? null,
            'local_nombre'   => $c['local_nombre'] ?? null,
            'zona'           => $c['zona'] ?? null,
            'caso'           => $c['caso'] ?? null,
            'prioridad'      => $c['prioridad'] ?? null,
            'fecha_creacion' => $c['fecha_creacion'] ?? $c['recibido'] ?? null,
            'fecha_estimada' => $c['fecha_estimada'] ?? null,
            'estado_alerta'  => $c['estado_alerta'] ?? null,
            'alertas'        => $c['alertas'] ?? [],
        ];
    }
}
// El mismo alcance del buzón: el técnico, solo lo asignado; el jefe, su zona (con derivaciones).
$correctivos = Casos::enAlcance($correctivos, Casos::gestion());

/* --- El recorte por zona del maestro y el padrón ----------------------------
   `zona_de()` mira primero el campo `zona` y, si no está, lo resuelve por el
   código del local contra el maestro. NUNCA por el prefijo del código: `J018EC`
   es Cajun y otros códigos con `J` son Juan Valdez. */
$zonaPorLocal = [];
foreach ($locales as $l) {
    $cod = $l['codigo'] ?? $l['local_codigo'] ?? null;
    if ($cod !== null) { $zonaPorLocal[$cod] = $l['zona'] ?? null; }
}
$zona_de = function (array $fila) use ($zonaPorLocal): ?string {
    if (!empty($fila['zona'])) { return (string) $fila['zona']; }
    foreach (['local', 'local_codigo', 'codigo'] as $k) {
        if (!empty($fila[$k]) && isset($zonaPorLocal[$fila[$k]])) { return $zonaPorLocal[$fila[$k]]; }
    }
    return null;
};
if ($zonaAlcance !== null) {
    $mia = fn(array $fila): bool => $zona_de($fila) === $zonaAlcance;
    $locales  = array_values(array_filter($locales, $mia));
    $tecnicos = array_values(array_filter($tecnicos, $mia));
    if ($fuente === 'json') {
        // Los ingresos ya vienen recortados de Reportes::ingresosPreventivos();
        // `conversion` se recalcula sobre ellos porque venía contada sobre las tres zonas.
        if (is_array($conversion)) {
            $conversion['total_ingresos'] = count($ingresos);
            $conversion['convertidos'] = count(array_filter($ingresos, fn($i) => !empty($i['plan_vigente']['inicio'] ?? null)));
            $misLocales = [];
            foreach ($ingresos as $i) { if (!empty($i['local'])) { $misLocales[$i['local']] = true; } }
            if (isset($conversion['sin_convertir']) && is_array($conversion['sin_convertir'])) {
                $conversion['sin_convertir'] = array_values(array_filter($conversion['sin_convertir'],
                    fn($x) => !is_array($x) || ($x['local'] ?? null) === null || isset($misLocales[$x['local']])));
            }
        }
        $fueraMaestro = null;   // lista de diagnóstico del maestro: no es trabajo de zona
    }
}
if ($fuente === 'tabla') {
    $conversion = [
        'total_ingresos' => count($ingresos),
        'convertidos' => count(array_filter($ingresos, fn($i) => !empty($i['plan_vigente']['inicio'] ?? null))),
        'sin_convertir' => [],
    ];
}

Auth::bitacora('CONSULTAR', 'cronograma', 'datos',
               'alcance=' . ($zonaAlcance ?? 'todas') . ' fuente=' . ($fuente ?? 'ninguna')
             . ' ingresos=' . count($ingresos) . ' correctivos=' . count($correctivos));

/* Al técnico no le van ni el padrón ni el maestro de locales (T2.13.4). */
$esTecnico = $u['rol'] === 'TECNICO';
$salida = [
    'generado'    => date('c'),
    'alcance'     => ['zona' => $zonaAlcance, 'rol' => $u['rol'], 'rol_nombre' => Ui::ROL[$u['rol']] ?? $u['rol'],
                      'nombre' => $u['nombre'], 'puede_editar' => Auth::puede('cronograma.editar')],
    'csrf'        => Auth::csrfToken(),
    'modulos'     => Ui::modulos($u),
    'fuente'      => $fuente,
    'generado_cronograma' => $generadoCron,
    'cronograma'  => [
        'anio' => $anio, 'ingresos' => $ingresos, 'conversion' => $conversion,
        // `origen` (una ruta del Drive) no le sirve a nadie en pantalla (TR-25).
    ],
    'locales'     => $esTecnico ? [] : array_values($locales),
    'correctivos' => $correctivos,
];
if ($fueraMaestro !== null && $zonaAlcance === null) { $salida['cronograma']['locales_fuera_del_maestro'] = $fueraMaestro; }
if (!$esTecnico) { $salida['tecnicos'] = array_values($tecnicos); }
echo json_encode($salida, JSON_UNESCAPED_UNICODE);
