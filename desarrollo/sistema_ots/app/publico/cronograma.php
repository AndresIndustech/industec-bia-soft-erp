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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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

echo json_encode([
    'generado'    => date('c'),
    'cronograma'  => $cron,
    'locales'     => array_values($locales),
    'tecnicos'    => array_values($tecnicos),
    'correctivos' => $correctivos,
], JSON_UNESCAPED_UNICODE);
