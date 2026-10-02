<?php
declare(strict_types=1);

/**
 * prueba_pdf_contenido.php — Todo lo que el técnico escribe en el formulario sale
 * en el PDF de la orden (decisión de Andrés del 2026-10-01: «arregla el PDF para
 * que todo aparezca correctamente tal cual lo ingresa el técnico»).
 *
 * POR QUÉ EXISTE
 * La OT-1964-G021EC (la primera con un repuesto solicitado, 1-oct-2026) salió a
 * Grupo KFC y al local con «REPUESTOS: No se usaron repuestos.» y ni una palabra
 * del repuesto que el técnico había pedido en «Qué repuesto haría falta». El PDF
 * NUNCA imprimió el bloque `pendiente` (diagnóstico y repuestos a solicitar):
 * `git log -S"pendiente" -- plantilla_ot.php` está vacío desde la 008. Tampoco
 * mostraba el motivo de una OT sin aviso de SAP, el área de un equipo nuevo ni el
 * código de activo que el técnico tecleaba. Las baterías miraban que el PDF
 * existiera y tuviera fotos, no que dijera lo que el técnico escribió.
 *
 * Cómo: `Emision::html()` se ejecuta de verdad, con la plantilla real, sobre las
 * cargas REALES de las OT-1952 y OT-1964 (copiadas de ot_capturadas) y sobre
 * variantes que ejercitan cada campo. Sin base de datos (SQLite en memoria para
 * `ot_fotos`). Lo que dompdf hace con el HTML se comprueba en el servidor:
 * `verificar_emision.py` emite una OT y lee el texto del PDF.
 *
 *   php pruebas/prueba_pdf_contenido.php
 */

$cfg = tempnam(sys_get_temp_dir(), 'industec_cfg_');
file_put_contents($cfg, "<?php return ['emision_modo' => 'PRODUCCION'];");
putenv('INDUSTEC_CONFIG=' . $cfg);

require_once __DIR__ . '/../publico/nucleo/Emision.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                               PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE ot_fotos (foto_id INTEGER PRIMARY KEY, envio_uuid TEXT, ruta TEXT, orden_n INTEGER, equipo_n INTEGER, momento TEXT)');
// MariaDB tiene FIELD(); SQLite no. html() ordena las fotos con ella.
$pdo->sqliteCreateFunction('FIELD', static function ($valor, ...$lista) {
    $i = array_search($valor, $lista, true);
    return $i === false ? 0 : $i + 1;
});
(new ReflectionProperty(Db::class, 'pdo'))->setValue(null, $pdo);

$fallos = 0;
$total  = 0;
function afirmar(string $que, $real, $esperado): void
{
    global $fallos, $total;
    $total++;
    $bien = $real === $esperado;
    if (!$bien) { $fallos++; }
    printf("  %-84s %s\n", $que, $bien ? 'ok' : 'FALLA (dio ' . var_export($real, true) . ', esperaba ' . var_export($esperado, true) . ')');
}
/** El HTML como lo leería una persona: una línea por bloque (div, br, párrafo), sin etiquetas
 *  ni espacios repetidos. Primero se juntan los espacios del código fuente (la plantilla tiene
 *  saltos de línea de formato) y luego cada div y cada <br> pasa a ser un salto. */
function texto(string $html): string
{
    $t = preg_replace('/<style.*?<\/style>/s', '', $html) ?? $html;
    $t = preg_replace('/\s+/', ' ', $t) ?? $t;
    $t = preg_replace('/<\/?(?:div|p|h1|h2|table|tr|td|ul|li)\b[^>]*>|<br\s*\/?>/i', "\n", $t) ?? $t;
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
    $lineas = array_filter(array_map(static fn($l) => trim($l), explode("\n", $t)), static fn($l) => $l !== '');
    return implode("\n", $lineas);
}
$tiene = fn(string $h, string $aguja): bool => str_contains(texto($h), $aguja);
$orden_de = fn(string $h, string ...$secciones): bool => (function () use ($h, $secciones) {
    $ult = -1;
    foreach ($secciones as $s) {
        $p = strpos($h, '>' . $s . '<');
        if ($p === false || $p < $ult) { return false; }
        $ult = $p;
    }
    return true;
})();

$base_c = ['captura_id' => 1, 'envio_uuid' => 'prueba-contenido', 'aviso' => '10358019', 'local_codigo' => 'G021EC',
           'zona' => 'UIO', 'cadena' => 'GUS', 'modulo' => 'CORRECTIVO', 'con_proveedor' => null,
           'emitida_en' => '2026-10-01 09:49:06', 'id_industec' => 'OT-1964-G021EC-10358019-UIO'];
$id = $base_c['id_industec'];

// ---- La carga REAL de la OT-1964-G021EC (ot_capturadas.captura_id 297), sin la firma ni las fotos.
$ot1964 = [
    'actividades' => 'Personal administrativo informa que el equipo no llega a temperatura, luego de la inspección se tiene que es porque uno de Los dos ventiladores no funciona',
    'admin' => 'Ivan casa', 'atiempo' => 'Si', 'aviso' => '10358019', 'cadena' => 'GUS', 'con_proveedor' => null,
    'con_proveedor_marcado' => false, 'concluida' => false, 'correo_local' => 'francia.gomez@kfc.com.ec',
    'dia_intervencion' => null,
    'equipos' => [['tipo' => '000642_SY_MAQYEQ_HOLDING GABINETE', 'nuevo' => true, 'equipo_uuid' => 'c95f4574-e143-4603-b6a8-e193809da22d',
                   'estado' => 'Operativo', 'obs' => null, 'marca' => 'HENNY PENNY', 'modelo' => 'HC-903', 'serie' => 'DA1202048',
                   'sin_placa' => false, 'codigo_activo' => null, 'area' => null, 'fotos_antes' => 1, 'fotos_despues' => 3]],
    'estado_ot' => 'Abierta', 'fecha_atencion' => '2026-10-01', 'fin' => '2026-10-01T09:44', 'inicio' => '2026-10-01T08:00',
    'local' => 'G021EC', 'motivo_sin_aviso' => null, 'novedades' => [], 'observaciones' => '',
    'pendiente' => ['activo_fijo' => '', 'diagnostico' => 'Ventilador defectuoso', 'diagnostico_codigo' => null,
                    'equipo_desc' => 'Holding gabinet, HENNY PENNY HC-903',
                    'parte' => 'Henny Penny 25753 Montaje de motor para ventilador, 120 V',
                    'partes' => [['descripcion' => 'Henny Penny 25753 Montaje de motor para ventilador, 120 V', 'cantidad' => 1, 'numero_parte' => null, 'codigo' => null]],
                    'deshabilitado' => false],
    'repuestos' => null, 'satisfaccion' => 10, 'sin_sin_aviso' => false, 'sin_aviso' => false,
    'tecnico' => 'Anthony Guillermo Morales Chugchilan', 'tipo' => 'CORRECTIVO', 'uso_repuesto' => false, 'zona' => 'UIO',
];

echo "=== 1. La OT-1964 real: el repuesto que el técnico pidió SALE en el PDF ===\n";
$h = Emision::html($base_c, $ot1964, $id);
afirmar('«TRABAJO NO CONCLUIDO» es una sección del PDF', $tiene($h, 'TRABAJO NO CONCLUIDO'), true);
afirmar('el repuesto pedido, tal como lo escribió', $tiene($h, '- 1 × Henny Penny 25753 Montaje de motor para ventilador, 120 V'), true);
afirmar('«Repuestos que hacen falta:»', $tiene($h, 'Repuestos que hacen falta:'), true);
afirmar('el diagnóstico', $tiene($h, "Diagnóstico:\nVentilador defectuoso"), true);
afirmar('el equipo que el técnico describió', $tiene($h, 'Equipo: Holding gabinet, HENNY PENNY HC-903'), true);
afirmar('«Equipo deshabilitado: NO» (no lo marcó)', $tiene($h, 'Equipo deshabilitado: NO'), true);
afirmar('REPUESTOS sigue diciendo que no se USARON (son dos cosas distintas)', $tiene($h, "REPUESTOS\nNo se usaron repuestos."), true);
afirmar('el estado de la OT sigue siendo Abierta', $tiene($h, "ESTADO DE LA OT\nAbierta"), true);
afirmar('orden de las secciones: REPUESTOS, TRABAJO NO CONCLUIDO, OBSERVACIONES, ESTADO DE LA OT',
        $orden_de($h, 'REPUESTOS', 'TRABAJO NO CONCLUIDO', 'OBSERVACIONES', 'ESTADO DE LA OT'), true);
afirmar('los datos que ya salían siguen (marca, modelo, serie en su línea de siempre)',
        $tiene($h, 'Marca: HENNY PENNY · Modelo: HC-903 · Serie: DA1202048'), true);

echo "\n=== 2. Una OT concluida no lleva la sección ===\n";
$ot1952 = $ot1964;
$ot1952['concluida'] = true; $ot1952['pendiente'] = null; $ot1952['estado_ot'] = 'Cerrada';
$ot1952['equipos'] = [['tipo' => '003666_O_MAQYEQ', 'nuevo' => true, 'estado' => 'Operativo', 'sin_placa' => true, 'marca' => null,
                       'modelo' => null, 'serie' => null, 'codigo_activo' => null, 'area' => 'Cocina caliente',
                       'obs' => 'Se ajustan los oxigenadores de Los quemadores']];
$h = Emision::html($base_c, $ot1952, $id);
afirmar('sin «TRABAJO NO CONCLUIDO»', $tiene($h, 'TRABAJO NO CONCLUIDO'), false);
afirmar('el área que el técnico escribió al crear el equipo nuevo SALE', $tiene($h, 'Área: Cocina caliente'), true);
afirmar('sin placa, como antes', $tiene($h, 'Marca/Modelo/Serie: sin placa o ilegible'), true);
afirmar('la observación del equipo, como antes', $tiene($h, 'Observación: Se ajustan los oxigenadores de Los quemadores'), true);
afirmar('sin código tecleado: «sin dato en el maestro», como antes', $tiene($h, 'Código Activo Fijo: sin dato en el maestro'), true);
afirmar('un equipo del catálogo no pinta «Área:» (no la teclea nadie)',
        $tiene(Emision::html($base_c, ['equipos' => [['tipo' => 'FREIDORA', 'equipo_sap' => '30046955']]] + $ot1952, $id), 'Área:'), false);

echo "\n=== 3. El código de activo que teclea el técnico ===\n";
$o = $ot1952; $o['equipos'][0]['codigo_activo'] = '021165';
afirmar('equipo nuevo con código tecleado: sale ese código', $tiene(Emision::html($base_c, $o, $id), 'Código Activo Fijo: 021165'), true);

echo "\n=== 4. Los repuestos USADOS, uno por línea ===\n";
$o = $ot1952; $o['uso_repuesto'] = true; $o['repuestos'] = 'Termopila / generador de piloto (Fm8101873); Tornillo x3 (T-10); Cable de encendido';
$t = texto(Emision::html($base_c, $o, $id));
afirmar('tres repuestos, tres líneas', str_contains($t, "REPUESTOS\nTermopila / generador de piloto (Fm8101873)\nTornillo x3 (T-10)\nCable de encendido\nOBSERVACIONES"), true);
afirmar('lineasDeRepuestos: uno solo queda igual', Emision::lineasDeRepuestos('Termopila (Fm8101873)'), ['Termopila (Fm8101873)']);
afirmar('lineasDeRepuestos: vacío -> nada', Emision::lineasDeRepuestos('  '), []);
afirmar('lineasDeRepuestos: respeta una coma o un punto y coma sin espacio',
        Emision::lineasDeRepuestos('Válvula 3/4, de bronce;sin espacio'), ['Válvula 3/4, de bronce;sin espacio']);
$o['uso_repuesto'] = true; $o['repuestos'] = 'Termopila / generador de piloto (Fm8101873)';
afirmar('un repuesto usado sale como siempre (sin viñeta)', str_contains(texto(Emision::html($base_c, $o, $id)), "REPUESTOS\nTermopila / generador de piloto (Fm8101873)\nOBSERVACIONES"), true);

echo "\n=== 5. Cada campo del pedido de repuesto ===\n";
$o = $ot1964;
$o['pendiente'] = ['equipo_desc' => 'Freidora 2', 'diagnostico' => "La resistencia está abierta.\nSe midió continuidad y no pasa.",
                   'diagnostico_codigo' => null, 'deshabilitado' => true, 'parte' => '',
                   'partes' => [['descripcion' => 'Resistencia 6 kW', 'cantidad' => 2, 'numero_parte' => 'HEN12345', 'codigo' => '17000121'],
                                ['descripcion' => 'Termostato', 'cantidad' => 1, 'numero_parte' => '', 'codigo' => null]]];
$t = texto(Emision::html($base_c, $o, $id));
afirmar('equipo deshabilitado -> «SI»', str_contains($t, 'Equipo deshabilitado: SI'), true);
afirmar('el diagnóstico conserva sus dos líneas', str_contains($t, "La resistencia está abierta.\nSe midió continuidad y no pasa."), true);
afirmar('cantidad, descripción, número de parte y código',
        str_contains($t, "- 2 × Resistencia 6 kW · n.º de parte: HEN12345 · código: 17000121\n- 1 × Termostato"), true);
$o['pendiente'] = ['diagnostico' => 'Sin repuesto conocido todavía', 'partes' => [], 'parte' => '', 'deshabilitado' => false];
afirmar('sin repuestos indicados, lo dice', str_contains(texto(Emision::html($base_c, $o, $id)), 'Repuestos que hacen falta: sin repuestos indicados'), true);
$o['pendiente'] = ['diagnostico' => 'Una app vieja en caché', 'parte' => 'Válvula check de 3/4 pulgada (HP FR21800)'];
afirmar('una app vieja que solo manda el texto compuesto: sale ese texto',
        str_contains(texto(Emision::html($base_c, $o, $id)), "Repuestos que hacen falta:
Válvula check de 3/4 pulgada (HP FR21800)"), true);
$o['pendiente'] = null; $o['concluida'] = false;
$t = texto(Emision::html($base_c, $o, $id));
afirmar('dijo «no concluí» y no escribió nada: el PDF lo dice, no lo calla (I-7)',
        str_contains($t, 'TRABAJO NO CONCLUIDO') && str_contains($t, 'no quedó concluido y no escribió más detalle'), true);
$o['concluida'] = null; unset($o['concluida']);
afirmar('una carga sin la clave `concluida` y sin pedido: nada (no se inventa)', str_contains(texto(Emision::html($base_c, $o, $id)), 'TRABAJO NO CONCLUIDO'), false);

echo "\n=== 6. Lo que viene escrito por el técnico se escapa ===\n";
$o = $ot1964;
$o['pendiente']['partes'][0]['descripcion'] = '<script>alert(1)</script> & "comillas"';
$o['pendiente']['diagnostico'] = '<b>negrita</b>';
$h = Emision::html($base_c, $o, $id);
afirmar('sin HTML suelto en el repuesto', str_contains($h, '<script>'), false);
afirmar('ni en el diagnóstico', str_contains($h, '<b>negrita</b>'), false);
afirmar('y el texto sí está, escapado', str_contains($h, '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;comillas&quot;'), true);

echo "\n=== 7. OT sin aviso de SAP: el motivo sale ===\n";
$o = $ot1952; $o['sin_aviso'] = true; $o['aviso'] = null; $o['motivo_sin_aviso'] = 'El gerente pidió la visita por teléfono';
$cSin = ['aviso' => ''] + $base_c;
$t = texto(Emision::html($cSin, $o, $id));
afirmar('«ID-ORDEN-GRUPOKFC: Sin aviso de SAP» y debajo su motivo',
        str_contains($t, "ID-ORDEN-GRUPOKFC: Sin aviso de SAP\nMotivo de no tener aviso SAP: El gerente pidió la visita por teléfono"), true);
afirmar('con aviso, no hay línea de motivo', str_contains(texto(Emision::html($base_c, $ot1952, $id)), 'Motivo de no tener aviso'), false);
$o['motivo_sin_aviso'] = '';
afirmar('sin motivo escrito, no se inventa la línea', str_contains(texto(Emision::html($cSin, $o, $id)), 'Motivo de no tener aviso'), false);

echo "\n=== 8. Lo que no cambia (contrato con Grupo KFC) ===\n";
$h = Emision::html($base_c, $ot1964, $id);
foreach (['DATOS GENERALES', 'DETALLE DEL EQUIPO', 'DETALLE DE LA INTERVENCIÓN', 'REPUESTOS', 'OBSERVACIONES', 'ESTADO DE LA OT',
          'SATISFACCIÓN DEL CLIENTE', 'FIRMA DEL ADMINISTRADOR'] as $sec) {
    afirmar("sigue la sección «{$sec}»", str_contains($h, '>' . $sec . '<'), true);
}
foreach (['ID-ORDEN-INDUSTEC:', 'ID-ORDEN-GRUPOKFC:', 'Técnico Asignado:', 'Hora Inicio:', 'Su requerimiento fue atendido a tiempo:'] as $rot) {
    afirmar("y el rótulo «{$rot}»", str_contains($h, $rot), true);
}

echo "\n$total comprobaciones · $fallos fallos\n";
unlink($cfg);
exit($fallos > 0 ? 1 : 0);
