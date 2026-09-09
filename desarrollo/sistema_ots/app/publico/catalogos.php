<?php
declare(strict_types=1);

/**
 * catalogos.php — Sirve los catálogos que consume el formulario.
 *
 * PARA REVISIÓN LOCAL: lee los JSON que ya generó
 *   agentes/scripts/t2_5_catalogos.py  ->  SALIDAS IA/OTS/catalogos/
 * que es la misma fuente que la administración revisó en COBERTURA.md.
 *
 * EN PRODUCCIÓN: esto se reemplaza por una consulta a la MySQL de Hostinger,
 * donde los catálogos viven como tablas de solo lectura sincronizadas desde
 * la estación (ARQUITECTURA_SISTEMA_OTS.md §3, "quién es dueño de qué").
 * El shape de salida no cambia, así que el formulario no se entera.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Orden de búsqueda: una copia local junto al formulario (si alguien la puso),
// si no, los catálogos vivos que generó t2_5_catalogos.py en SALIDAS IA.
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
    echo json_encode(['error' => 'no encuentro locales.json en ' . implode(' ni ', $candidatos)], JSON_UNESCAPED_UNICODE);
    exit;
}

function leer(string $ruta): array
{
    if (!is_file($ruta)) {
        http_response_code(500);
        echo json_encode(['error' => "falta $ruta"], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $j = json_decode((string) file_get_contents($ruta), true);
    return $j['datos'] ?? $j;
}

$locales = leer("$base/locales.json");
$tecnicos = leer("$base/tecnicos.json");
$tipos = array_map(
    static fn($t) => is_string($t) ? $t : ($t['tipo'] ?? ''),
    leer("$base/tipos_equipo.json")
);
$equipos = leer("$base/equipos_por_local.json");

/**
 * Las ordenes que se le ofrecen al tecnico.
 *
 * Fuente preferida: casos_sap.json, que arma t2_6_imap_avisos.py leyendo el
 * buzon. Es el dato VIVO y trae lo que el export no tiene -- prioridad, fecha
 * comprometida y el pedido en palabras de KFC.
 *
 * Respaldo: avisos_abiertos.json, del export de SAP en la base, que llega solo
 * hasta el 31-ago.
 *
 * LIMITE QUE HAY QUE DECIR (I-7): el correo anuncia que un caso se CREO o se
 * ELIMINO. No anuncia que se cerro. Asi que esta lista es "casos abiertos por
 * KFC", no "casos pendientes segun SAP". El cierre sigue rigiendose por
 * estatus_general del export.
 */
$avisos = ['datos' => [], 'cobertura' => null];
if (is_file("$base/casos_sap.json")) {
    $j = json_decode((string) file_get_contents("$base/casos_sap.json"), true);
    $avisos = [
        'datos' => array_map(static fn($c) => [
            'aviso'               => $c['aviso'],
            'fecha_notificacion'  => $c['fecha_creacion'] ?? $c['recibido'] ?? null,
            'fecha_estimada'      => $c['fecha_estimada'] ?? null,
            'prioridad'           => $c['prioridad'] ?? null,
            'caso'                => $c['caso'] ?? null,
            'descripcion_trabajo' => $c['descripcion_trabajo'] ?? null,
            'local'               => $c['local'] ?? null,
            'local_nombre'        => $c['local_nombre'] ?? null,
            'zona'                => $c['zona'] ?? null,
            'cadena'              => $c['cadena'] ?? null,
            'centro_coste_sap'    => $c['centro_coste_sap'] ?? null,
            'equipo_sap'          => null,   // el correo no trae el numero de equipo
            'equipo_denominacion' => $c['activo_fijo'] ?? null,
            'estatus'             => 'POR ASIGNAR',
            'orden_trabajo'       => $c['orden_trabajo'] ?? null,
        ], $j['datos'] ?? []),
        'cobertura' => [
            'fuente'      => 'buzon de INDUSTEC, en vivo',
            'generado'    => $j['generado'] ?? null,
            'hasta'       => null,
            'advertencia' => 'El correo avisa cuando KFC crea o elimina un caso; '
                           . 'no avisa cuando lo cierra. Esta lista es lo abierto por KFC, '
                           . 'no el pendiente según SAP.',
        ],
    ];
} elseif (is_file("$base/avisos_abiertos.json")) {
    $j = json_decode((string) file_get_contents("$base/avisos_abiertos.json"), true);
    $avisos = ['datos' => $j['datos'] ?? [], 'cobertura' => $j['cobertura'] ?? null];
}

echo json_encode([
    'generado' => date('c'),
    'locales'  => array_values($locales),
    'tecnicos' => array_values($tecnicos),
    'tipos'    => array_values(array_filter($tipos)),
    'equipos'  => $equipos,
    'avisos'   => $avisos,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
