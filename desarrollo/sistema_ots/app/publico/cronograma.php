<?php
declare(strict_types=1);

/**
 * cronograma.php — Sirve el cronograma preventivo y los pendientes correctivos.
 *
 * PARA REVISIÓN LOCAL: lee los JSON que generan
 *   t2_7_cronograma_preventivo.py  -> cronograma_preventivo.json
 *   t2_6_imap_avisos.py            -> casos_sap.json
 *
 * EN PRODUCCIÓN esto consulta MySQL. El shape de salida no cambia, así que la
 * pantalla no se entera.
 */

/* -------------------------------------------------------------------------
   EXIGE SESION Y FILTRA POR ZONA. Verificado el 2026-09-10: no hacia ninguna
   de las dos cosas.

   Lo que salia por aqui sin sesion: el cronograma preventivo completo de los
   96 locales, los 100 locales del maestro, el padron de tecnicos y los 918
   casos correctivos vivos con sus alertas de alcance.

   Y aunque hubiera exigido sesion, seguia sin filtrar: un jefe de zona de LARB
   recibia los datos de UIO y de CNLJ. La pantalla los escondia con un
   desplegable, que no protege nada — el JSON viajaba entero al navegador y se
   lee con abrir la consola.

   El alcance se aplica AQUI, en el servidor, igual que en `Casos::enAlcance()`.
   Es la misma regla que ya se corrigio una vez en el buzon de casos.
   ------------------------------------------------------------------------- */
require_once __DIR__ . '/nucleo/Auth.php';
require_once __DIR__ . '/nucleo/Casos.php';
$u = Auth::exigir('cronograma.ver', true);
$zonaAlcance = Auth::zonaAlcance();   // null = las tres zonas

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

$candidatos = [
    __DIR__ . '/catalogos',
    __DIR__ . '/../../../../SALIDAS IA/OTS/catalogos',
];
$base = null;
foreach ($candidatos as $c) {
    if (is_file($c . '/cronograma_preventivo.json')) { $base = $c; break; }
}
if ($base === null) {
    http_response_code(500);
    echo json_encode(['error' => 'falta cronograma_preventivo.json; corre t2_7_cronograma_preventivo.py'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

$cron = json_decode((string) file_get_contents($base . '/cronograma_preventivo.json'), true);

// Locales, para el selector de "agendar un local".
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
$correctivos = [];
if (is_file($base . '/casos_sap.json')) {
    $j = json_decode((string) file_get_contents($base . '/casos_sap.json'), true);
    foreach (($j['datos'] ?? []) as $c) {
        $correctivos[] = [
            'aviso'          => $c['aviso'],
            'local'          => $c['local'],
            'local_nombre'   => $c['local_nombre'],
            'zona'           => $c['zona'],
            'caso'           => $c['caso'],
            'prioridad'      => $c['prioridad'],
            'fecha_creacion' => $c['fecha_creacion'] ?? $c['recibido'] ?? null,
            'fecha_estimada' => $c['fecha_estimada'] ?? null,
            'estado_alerta'  => $c['estado_alerta'] ?? null,
            'alertas'        => $c['alertas'] ?? [],
        ];
    }
}
// El mismo alcance del buzón: el técnico, solo lo asignado; el jefe, su zona (con derivaciones).
$correctivos = Casos::enAlcance($correctivos, Casos::gestion());

/* -------------------------------------------------------------------------
   El recorte por zona.

   Se hace sobre las cuatro colecciones y no solo sobre los correctivos: el
   cronograma dice que locales tiene el cliente y cuando entra a cada uno, el
   maestro trae sus correos, y el padron trae los nombres del personal. Ninguna
   de las tres le hace falta a un jefe de otra zona.

   `zona_de()` mira primero el campo `zona` y, si no esta, lo resuelve por el
   codigo del local contra el maestro. NUNCA por el prefijo del codigo: `J018EC`
   es Cajun y otros codigos con `J` son Juan Valdez, y ese atajo ya costo cuatro
   locales cruzados de Quito a Cuenca.
   ------------------------------------------------------------------------- */
$zonaPorLocal = [];
foreach ($locales as $l) {
    $cod = $l['codigo'] ?? $l['local_codigo'] ?? null;
    if ($cod !== null) { $zonaPorLocal[$cod] = $l['zona'] ?? null; }
}

$zona_de = function (array $fila) use ($zonaPorLocal): ?string {
    if (!empty($fila['zona'])) { return (string) $fila['zona']; }
    foreach (['local', 'local_codigo', 'codigo'] as $k) {
        if (!empty($fila[$k]) && isset($zonaPorLocal[$fila[$k]])) {
            return $zonaPorLocal[$fila[$k]];
        }
    }
    return null;
};

if ($zonaAlcance !== null) {
    $mia = function (array $fila) use ($zona_de, $zonaAlcance): bool {
        return $zona_de($fila) === $zonaAlcance;
    };

    $locales     = array_values(array_filter($locales, $mia));
    $tecnicos    = array_values(array_filter($tecnicos, $mia));

    /* Del cronograma se recorta SOLO `ingresos`, que es la lista de trabajo.
       Hacerlo en bloque sobre toda lista que aparezca en la raiz tenia un
       efecto que costaba ver: `conversion.sin_convertir` tambien es una lista,
       no lleva campo `zona`, y el filtro la habria dejado vacia. El contador
       de «sin resolver» del encabezado habria dicho 0 a todos los jefes de
       zona, que es una cifra falsa que ademas tranquiliza.

       `resumen` y `conversion` se recalculan aparte, porque venian contados
       sobre los 368 ingresos de las tres zonas y para un jefe de LARB ese
       total no significa nada. */
    if (is_array($cron) && isset($cron['ingresos']) && is_array($cron['ingresos'])) {
        $cron['ingresos'] = array_values(array_filter($cron['ingresos'], $mia));

        if (isset($cron['conversion']) && is_array($cron['conversion'])) {
            $conv = $cron['conversion'];
            $conv['total_ingresos'] = count($cron['ingresos']);
            $conv['convertidos'] = count(array_filter(
                $cron['ingresos'],
                fn($i) => !empty($i['plan_vigente']['fecha_inicio'] ?? $i['plan_vigente'] ?? null)
            ));
            /* `sin_convertir` se recorta por local, que es el unico campo que
               trae, contra los locales que este usuario si alcanza. */
            $misLocales = [];
            foreach ($cron['ingresos'] as $i) {
                if (!empty($i['local'])) { $misLocales[$i['local']] = true; }
            }
            if (isset($conv['sin_convertir']) && is_array($conv['sin_convertir'])) {
                $conv['sin_convertir'] = array_values(array_filter(
                    $conv['sin_convertir'],
                    function ($x) use ($misLocales) {
                        if (!is_array($x)) { return true; }   // texto suelto: se conserva
                        $loc = $x['local'] ?? null;
                        return $loc === null || isset($misLocales[$loc]);
                    }
                ));
            }
            $cron['conversion'] = $conv;
        }

        if (isset($cron['locales_fuera_del_maestro'])) {
            /* Es una lista de diagnostico del maestro, no de trabajo de zona.
               Se quita del todo para el jefe de zona en vez de recortarla: sin
               el maestro completo no puede hacer nada con ella. */
            unset($cron['locales_fuera_del_maestro']);
        }
        unset($cron['origen']);   // es una ruta del Drive; no le sirve a nadie en pantalla
    }
}

Auth::bitacora('CONSULTAR', 'cronograma', 'datos',
               'alcance=' . ($zonaAlcance ?? 'todas')
             . ' locales=' . count($locales) . ' correctivos=' . count($correctivos));

echo json_encode([
    'generado'    => date('c'),
    /* La pantalla necesita saber que alcance le toco para poder decirlo, y para
       no ofrecer un selector de zonas que el servidor va a ignorar. */
    'alcance'     => ['zona' => $zonaAlcance, 'rol' => $u['rol'], 'nombre' => $u['nombre']],
    'cronograma'  => $cron,
    'locales'     => array_values($locales),
    'tecnicos'    => array_values($tecnicos),
    'correctivos' => $correctivos,
], JSON_UNESCAPED_UNICODE);
