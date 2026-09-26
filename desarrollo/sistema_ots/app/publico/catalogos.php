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

/* -------------------------------------------------------------------------
   EXIGE SESION. Verificado el 2026-09-10: no la exigia.

   El `.htaccess` cierra los .json de `catalogos/` con 403, y de ahi se dio por
   hecho que el dato estaba protegido. No lo estaba: este archivo es la puerta
   que los sirve, y estaba abierta. Lo que salia por aqui sin ninguna sesion:

     - los 100 locales, con su correo y su centro de coste
     - los 1.173 activos fijos instalados, local por local
     - los NOMBRES de los 19 tecnicos vigentes

   Los dos ultimos son datos personales y de infraestructura de un cliente. La
   pantalla del formulario los necesita; el internet, no.

   Se responde en JSON (`true`) y no con una redireccion al login: quien pide
   esto es un `fetch`, y una redireccion la guardaria como si fuera el catalogo.
   ------------------------------------------------------------------------- */
require_once __DIR__ . '/nucleo/Auth.php';
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Catalogo.php';
require_once __DIR__ . '/nucleo/Vocabulario.php';
require_once __DIR__ . '/nucleo/Destinatarios.php';
$u = Auth::exigir('ots.crear', true);

header('Content-Type: application/json; charset=utf-8');
/* `private`: el trabajador de servicio SI la guarda —de eso vive el modo sin
   senal— pero ningun proxy compartido debe. `no-store` habria dejado al
   tecnico sin catalogo en cuanto se le cayera la cobertura. */
header('Cache-Control: private, max-age=60');

// El mismo lector que usa envio.php para validar: si los dos leyeran cada uno
// a su manera, el formulario ofrecería lo que el servidor después rechaza.
$cat = Catalogo::cargar();
if ($cat === null) {
    http_response_code(500);
    echo json_encode(['error' => 'faltan los catálogos del formulario; corre t2_5_catalogos.py'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}
$base = (string) Catalogo::carpeta();
['locales' => $locales, 'tecnicos' => $tecnicos, 'tipos' => $tipos, 'equipos' => $equipos] = $cat;

/* Prellenados del formulario (H-08, H-11, D8, D9): el administrador de cada
   local, y las familias/diagnósticos/repuestos frecuentes para el selector
   «Falla encontrada». Si la 009 no está aplicada, cada llave llega vacía y el
   formulario sigue con el diagnóstico y los repuestos en texto libre. */
$admins = Catalogo::admins();
$adminsV2 = Catalogo::adminsV2();
$diagCat = Catalogo::diagnosticos();
// T2.28.6 (obs. 4): la ficha de cada equipo (marca, modelo, serie que se
// quedan) y las marcas/modelos más frecuentes para las sugerencias.
$fichas = Catalogo::fichas();
$marcasCat = Catalogo::marcas();
$modelosCat = Catalogo::modelos();

/* «También se enviará a» (T2.28.3, obs. 2): reemplaza el campo fijo
 * «Correo del jefe de operaciones» del formulario (D-G). Se calcula para
 * los 100 locales de una sola vez -$filasDest se lee UNA vez, no una por
 * local- con Destinatarios::copiasPorLocal(), la misma tabla y las mismas
 * reglas (ámbito, activo, cadena) que usa Emision::encolar() al emitir; si
 * la 013 no está o no tiene filas, cae al mismo respaldo de siempre
 * (correo_jefe_op del maestro + config.php), así que la línea nunca queda
 * vacía por falta de siembra. */
$filasDestOrden = Destinatarios::filas('ORDEN');
$destinatariosCc = [];
foreach ($locales as $l) {
    $cod = (string) ($l['codigo'] ?? '');
    if ($cod === '') { continue; }
    $destinatariosCc[$cod] = Destinatarios::copiasPorLocal(
        $filasDestOrden, $l, (string) ($l['zona'] ?? ''), $cod, $l['cadena'] ?? null
    );
}

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
/* La forma que espera el formulario, desde una fila del catálogo del buzón.
   El `estatus` sale del estado de la orden en la gestión, con el diccionario:
   hasta el 24-sep-2026 era «POR ASIGNAR» fijo, y el jefe de zona lo leía así
   aunque la orden ya tuviera técnico o estuviera atendida. */
$forma = static fn(array $c, array $gest = []): array => [
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
    'estatus'             => Casos::etiquetaEstado($gest[$c['aviso']]['estado'] ?? null),
    // El estado de la BASE (VOCABULARIO.md §10.2): app.js lo nombra con UI.T, así el
    // catálogo que el celular guardó sin señal toma el término del diccionario que
    // tenga cargado. `estatus` (el texto armado aquí) queda para una app vieja en caché.
    'estatus_clave'       => (string) ($gest[$c['aviso']]['estado'] ?? 'NUEVO'),
    'orden_trabajo'       => $c['orden_trabajo'] ?? null,
];

$avisos = ['datos' => [], 'cobertura' => null];
if ($u['rol'] === 'TECNICO') {
    /* Al técnico, sus casos ABIERTOS y desde la base (T2.13.2 y T2.13.3): los
       que tiene ASIGNADO o ESPERA_REPUESTO, estén o no en el catálogo del
       buzón. Uno que ya se atendió o se cerró deja de ofrecerse —el formulario
       le ofrecía casos terminados—, y uno que quedó fuera de la ventana del
       catálogo sigue apareciendo, con su número y nada que se invente (I-7). */
    $gest   = Casos::gestion();
    $mios   = Casos::delTecnico((int) $u['usuario_id'], Casos::ABIERTOS_TECNICO, $gest);
    $sinCat = count(array_filter($mios, static fn($c) => !empty($c['sin_catalogo'])));
    $avisos = [
        'datos' => array_map(static function (array $c) use ($forma, $gest): array {
            return $forma($c, $gest) + ['sin_catalogo' => !empty($c['sin_catalogo'])];
        }, $mios),
        'cobertura' => [
            'fuente'      => 'tus órdenes (' . Vocabulario::t('ASIGNADA', 2) . ' y ' . Vocabulario::t('ESPERA_REPUESTO') . '), en la base',
            'generado'    => date('c'),
            'hasta'       => null,
            'advertencia' => $sinCat === 0 ? null : ($sinCat === 1
                ? 'Una de ellas no está en el listado del buzón: de esa solo se conoce el aviso SAP.'
                : "$sinCat de ellas no están en el listado del buzón: de esas solo se conoce el aviso SAP."),
        ],
    ];
} elseif (is_file("$base/casos_sap.json")) {
    $j = json_decode((string) file_get_contents("$base/casos_sap.json"), true);
    // Con sesión no alcanzaba: cualquier técnico recibía los 909 casos de las 3 zonas.
    $gestB = Casos::gestion();
    $j['datos'] = Casos::enAlcance($j['datos'] ?? [], $gestB);
    $avisos = [
        'datos' => array_map(static fn(array $c): array => $forma($c, $gestB), $j['datos'] ?? []),
        'cobertura' => [
            'fuente'      => 'buzón de INDUSTEC, en vivo',
            'generado'    => $j['generado'] ?? null,
            'hasta'       => null,
            'advertencia' => 'El correo avisa cuando KFC crea o elimina una orden; '
                           . 'no avisa cuando la cierra. Esta lista son las órdenes que KFC '
                           . 'mandó por correo, no las abiertas en SAP.',
        ],
    ];
} elseif (is_file("$base/avisos_abiertos.json")) {
    $j = json_decode((string) file_get_contents("$base/avisos_abiertos.json"), true);
    $avisos = ['datos' => Casos::enAlcance($j['datos'] ?? [], Casos::gestion()),
               'cobertura' => $j['cobertura'] ?? null];
}

echo json_encode([
    'generado'  => date('c'),
    'locales'   => array_values($locales),
    'tecnicos'  => array_values($tecnicos),
    'tipos'     => array_values(array_filter($tipos)),
    'equipos'   => $equipos,
    'avisos'    => $avisos,
    // Prellenados del formulario (H-08, H-11, D8, D9). No son parte del
    // catálogo de validación (Catalogo::cargar() ya fusionó los equipos
    // propuestos ahí dentro): son datos de apoyo para la interfaz.
    'admins'              => (object) $admins,
    'admins_v2'           => (object) $adminsV2,
    // T2.28.3: quién más recibe la orden de cada local (jefe de zona +
    // copias), para la línea «también se enviará a» del formulario.
    'destinatarios_cc'    => (object) $destinatariosCc,
    'familias'            => $diagCat['familias'],
    'diagnosticos'        => $diagCat['diagnosticos'],
    'repuestos_frecuentes' => $diagCat['repuestos'],
    // T2.28.6: prellenado de marca/modelo/serie desde la última orden de ese
    // equipo, y las sugerencias de marca/modelo para el texto libre.
    'fichas'              => (object) $fichas,
    'marcas'              => array_values($marcasCat),
    'modelos'             => (object) $modelosCat,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
