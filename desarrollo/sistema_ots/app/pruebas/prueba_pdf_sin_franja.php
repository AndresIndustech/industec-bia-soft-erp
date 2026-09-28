<?php
declare(strict_types=1);

/**
 * prueba_pdf_sin_franja.php — El PDF de la OT INDUSTEC ya no lleva la franja
 * «DOCUMENTO DE PRUEBA», la fecha de atención corregida por la administración
 * es la que vale, y regenerar un PDF conserva la fecha de emisión original
 * (decisiones de Andrés del 28-sep-2026).
 *
 * POR QUÉ EXISTE
 * Andrés: «de ahora en adelante ninguna orden salga con esa franja». Los 14 PDF
 * del piloto (OT-9125…OT-9152) se regeneran sin ella, con la fecha de emisión
 * de entonces, y 6 con la fecha de atención corregida. La corrección tiene que
 * sobrevivir a `archivo_indexar_cli.php`, que cada noche rehace
 * `ot_archivo.fecha_atencion` desde la carga, y a cualquier regeneración
 * futura: por eso las dos lecturas pasan por UNA función,
 * `Emision::fechaAtencion()`.
 *
 * NO TOCA NINGUNA BASE NI LEE DATOS DE LA OPERACIÓN. `Emision::html()` se
 * ejecuta de verdad —con la plantilla real— sobre una orden sintética (aviso
 * 91009999, local ficticio), con una base SQLite en memoria puesta en
 * `Db::$pdo` (la tabla `ot_fotos`, vacía) y un config temporal por
 * `INDUSTEC_CONFIG` para cambiar el modo. `archivo_indexar_cli.php` es un
 * script de consola sobre MariaDB (JSON_UNQUOTE, ON DUPLICATE KEY): aquí se
 * comprueba que llama a la función y con qué forma de datos; su efecto sobre
 * la base real lo muestra el simulacro de `regenerar_pdf_piloto_cli.php`.
 *
 *   php pruebas/prueba_pdf_sin_franja.php
 */

$cfg = tempnam(sys_get_temp_dir(), 'industec_cfg_');
function modo(string $cfg, string $m): void
{
    file_put_contents($cfg, "<?php return ['emision_modo' => '$m'];");
}
modo($cfg, 'PRUEBA');
putenv('INDUSTEC_CONFIG=' . $cfg);

require_once __DIR__ . '/../publico/nucleo/Emision.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                               PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE ot_fotos (foto_id INTEGER PRIMARY KEY, envio_uuid TEXT, ruta TEXT, orden_n INTEGER)');
(new ReflectionProperty(Db::class, 'pdo'))->setValue(null, $pdo);

$fallos = 0;
$total  = 0;
function afirmar(string $que, $real, $esperado): void
{
    global $fallos, $total;
    $total++;
    $bien = $real === $esperado;
    if (!$bien) { $fallos++; }
    printf("  %-74s %-12s %s\n", $que,
           is_bool($real) ? ($real ? 'true' : 'false') : ($real === null ? 'null' : (is_scalar($real) ? (string) $real : json_encode($real))),
           $bien ? 'ok' : 'FALLA (esperaba ' . var_export($esperado, true) . ')');
}

// Una OT del piloto sintética: la forma de ot_capturadas y de su carga.
$c = ['captura_id' => 1, 'envio_uuid' => 'prueba-sin-franja', 'aviso' => '91009999', 'local_codigo' => 'Z999EC',
      'zona' => 'UIO', 'cadena' => 'KFC', 'modulo' => 'CORRECTIVO', 'con_proveedor' => null,
      'emitida_en' => null, 'id_industec' => 'OT-9999-Z999EC-91009999-UIO'];
$orden = ['fecha_atencion' => '2026-09-24', 'inicio' => '2026-09-18T08:00', 'fin' => '2026-09-18T10:00',
          'tecnico' => 'Técnico de Prueba', 'admin' => 'Administrador de Prueba', 'actividades' => 'Revisión',
          'estado_ot' => 'Cerrada', 'atiempo' => 'SI', 'satisfaccion' => 9, 'equipos' => []];
$id = $c['id_industec'];
const FRANJA = 'DOCUMENTO DE PRUEBA';
$fechaEn = fn(string $h, string $f): bool => str_contains($h, '<b>Fecha de Atención:</b> ' . $f);

echo "=== 1. La franja no sale en ningún modo ===\n";
foreach (['PRUEBA', 'PRODUCCION'] as $m) {
    modo($cfg, $m);
    afirmar("el modo que rige es $m (la prueba cambia de verdad de modo)", Emision::modo(), $m);
    $h = Emision::html($c, $orden, $id);
    afirmar("$m: el HTML no dice «" . FRANJA . "»", str_contains($h, FRANJA), false);
    afirmar("$m: ni pinta el bloque de la franja (div.prueba)", str_contains($h, '<div class="prueba">'), false);
    afirmar("$m: y sigue siendo la OT (número y título del contrato)",
            str_contains($h, $id) && str_contains($h, 'ORDEN DE TRABAJO'), true);
}
modo($cfg, 'PRUEBA');
$src = (string) file_get_contents(__DIR__ . '/../publico/nucleo/Emision.php');
afirmar("Emision::html() ya no deriva la franja del modo", str_contains($src, "'prueba'          => self::modo()"), false);
afirmar("y la plantilla (contrato) sigue igual: conserva el bloque si alguien pasa true",
        str_contains((string) file_get_contents(__DIR__ . '/../publico/nucleo/plantilla_ot.php'), "<?php if (\$d['prueba']): ?>"), true);

echo "\n=== 2. La fecha de atención que vale: Emision::fechaAtencion() ===\n";
$corr = fn($f) => $orden + [Emision::CORRECCION => ['fecha_atencion' => $f, 'antes' => '2026-09-24',
                                                   'por' => 'prueba', 'en' => '2026-09-28 20:00:00', 'motivo' => 'prueba']];
afirmar('la clave de la corrección', Emision::CORRECCION, 'correccion_admin');
afirmar('sin corrección: la del técnico', Emision::fechaAtencion($orden), '2026-09-24');
afirmar('con corrección: la corregida', Emision::fechaAtencion($corr('2026-09-18')), '2026-09-18');
afirmar('y el registro del técnico no se toca', $corr('2026-09-18')['fecha_atencion'], '2026-09-24');
foreach (['2026-02-30' => 'día que no existe', '18/09/2026' => 'otro formato', '' => 'vacía',
          '2026-09-18T08:00' => 'con hora'] as $mala => $por) {
    afirmar("corrección inválida ($por): la del técnico, no se inventa otra", Emision::fechaAtencion($corr($mala)), '2026-09-24');
}
afirmar('corrección que no es un objeto: la del técnico',
        Emision::fechaAtencion($orden + [Emision::CORRECCION => '2026-09-18']), '2026-09-24');
afirmar('sin fecha del técnico ni corrección: vacío, no se inventa', Emision::fechaAtencion([]), '');

echo "\n=== 3. html() imprime la corregida (sobrevive a toda regeneración) ===\n";
$h = Emision::html($c, $corr('2026-09-18'), $id);
afirmar('«Fecha de Atención: 2026-09-18»', $fechaEn($h, '2026-09-18'), true);
afirmar('y ya no la 2026-09-24', $fechaEn($h, '2026-09-24'), false);
afirmar('las horas del técnico, como estaban (08:00 a 10:00 del 18)',
        str_contains($h, '2026-09-18 08:00') && str_contains($h, '2026-09-18 10:00'), true);
afirmar('sin corrección, la del técnico', $fechaEn(Emision::html($c, $orden, $id), '2026-09-24'), true);

echo "\n=== 4. El índice nocturno lee la misma función ===\n";
$idx = (string) file_get_contents(__DIR__ . '/../publico/archivo_indexar_cli.php');
afirmar('archivo_indexar_cli.php extrae carga.correccion_admin', str_contains($idx, "JSON_EXTRACT(c.carga, '\$.correccion_admin') AS correccion"), true);
afirmar('y decide con Emision::fechaAtencion()', str_contains($idx, 'Emision::fechaAtencion('), true);
afirmar('ya no toma carga.fecha_atencion directo para ot_archivo',
        str_contains($idx, "preg_match('/^\\d{4}-\\d{2}-\\d{2}/', (string) \$c['fecha_atencion']"), false);
// La forma exacta con que el índice llama a la función: JSON_EXTRACT devuelve
// el objeto como texto JSON, o NULL si la carga no trae corrección.
$comoIndice = fn(?string $fa, ?string $json) => Emision::fechaAtencion(
    ['fecha_atencion' => $fa, Emision::CORRECCION => json_decode((string) ($json ?? ''), true)]);
afirmar('índice, con corrección → la corregida',
        $comoIndice('2026-09-24', '{"fecha_atencion": "2026-09-18", "antes": "2026-09-24"}'), '2026-09-18');
afirmar('índice, sin corrección (NULL) → la del técnico', $comoIndice('2026-09-24', null), '2026-09-24');
afirmar('índice, sin fecha ni corrección → vacío (cae a emitida_en, como antes)', $comoIndice(null, null), '');

echo "\n=== 5. La fecha de emisión: la original al regenerar ===\n";
afirmar('fechaEmision de emitida_en (DATETIME)', Emision::fechaEmision('2026-09-24 10:15:51'), '2026-09-24 10:15');
afirmar('fechaEmision con T', Emision::fechaEmision('2026-09-24T10:15:51'), '2026-09-24 10:15');
$antes = date('Y-m-d H:i');
$ahora = Emision::fechaEmision(null);
$despues = date('Y-m-d H:i');
afirmar('primera emisión (sin emitida_en): la de ahora', in_array($ahora, [$antes, $despues], true), true);
afirmar('emitida_en ilegible: la de ahora, no un texto roto', in_array(Emision::fechaEmision('ayer'), [$antes, date('Y-m-d H:i')], true), true);
$hReg = Emision::html(['emitida_en' => '2026-09-24 10:15:51'] + $c, $orden, $id);
afirmar('html() de una ya emitida: «generado automáticamente el 2026-09-24 10:15»',
        str_contains($hReg, 'generado automáticamente el 2026-09-24 10:15'), true);
afirmar('y no la hora de la regeneración', str_contains($hReg, 'generado automáticamente el ' . date('Y-m-d H:i')), false);
$hPrim = Emision::html($c, $orden, $id);
afirmar('html() de la primera emisión: la de ahora',
        str_contains($hPrim, 'generado automáticamente el ' . $antes) || str_contains($hPrim, 'generado automáticamente el ' . date('Y-m-d H:i')), true);
// emitir() fija UN instante para la emisión y lo usa en el PDF y en la columna
// (revisión del 28-sep-2026): antes el PDF tomaba date() antes de dompdf y la
// columna NOW() después, y si el render cruzaba un minuto la copia discrepaba.
afirmar('emitir(): un solo instante, la emitida_en leída bajo el candado o la de ahora',
        str_contains($src, "\$emitidaEn = (\$fila['emitida_en'] ?? null) !== null ? (string) \$fila['emitida_en'] : date('Y-m-d H:i:s');"), true);
afirmar('emitir(): ese instante es el que recibe el PDF',
        str_contains($src, "\$pdf = self::pdf(['emitida_en' => \$emitidaEn] + \$c, \$orden, \$id);"), true);
afirmar('emitir(): y el que se graba (COALESCE con ?, ya no NOW())',
        str_contains($src, 'SET emitida_en = COALESCE(emitida_en, ?)') && !str_contains($src, 'COALESCE(emitida_en, NOW())'), true);
afirmar('emitir(): el parámetro va primero, en el orden del UPDATE',
        str_contains($src, "[\$emitidaEn, \$huella, \$regen"), true);
// La unión de arrays deja la emitida_en fijada por encima de la de la fila.
$fijada = '2026-09-28 21:07:59';
afirmar('html() con el instante fijado: «generado automáticamente el 2026-09-28 21:07»',
        str_contains(Emision::html(['emitida_en' => $fijada] + $c, $orden, $id), 'generado automáticamente el 2026-09-28 21:07'), true);

echo "\n=== 6. La consola de regeneración ===\n";
$con = (string) file_get_contents(__DIR__ . '/servidor/regenerar_pdf_piloto_cli.php');
afirmar('usa getcwd(), no __DIR__, para el núcleo (errores nº 42 y 47)',
        str_contains($con, "require_once \$APP . '/nucleo/Emision.php'") && !preg_match('/require[_a-z]*\s+__DIR__/', $con), true);
preg_match_all('/^\s+(\d{3}) => \[\'ot\' => \'(OT-9\d{3}-[A-Z0-9]+-(\d{8})-UIO)\', \'aviso\' => \'(\d{8})\'(?:, \'fecha\' => \[\'([\d-]+)\', \'([\d-]+)\'\])?\]/m',
               $con, $m, PREG_SET_ORDER);
$lista = [];
foreach ($m as $x) { $lista[(int) $x[1]] = ['ot' => $x[2], 'aviso_ok' => $x[3] === $x[4], 'fecha' => isset($x[5]) && $x[5] !== '' ? [$x[5], $x[6]] : null]; }
afirmar('las 14 capturas del pedido (166, 171, 176, 193-203)', array_keys($lista), [166, 171, 176, 193, 194, 195, 196, 197, 198, 199, 200, 201, 202, 203]);
afirmar('de OT-9125 a OT-9152', [substr($lista[166]['ot'] ?? '', 0, 7), substr($lista[203]['ot'] ?? '', 0, 7)], ['OT-9125', 'OT-9152']);
afirmar('cada una con su aviso dentro del número', count(array_filter($lista, fn($r) => $r['aviso_ok'])), 14);
$seis = array_filter(array_map(fn($r) => $r['fecha'], $lista));
afirmar('las 6 fechas de Andrés, antes → nueva', $seis, [
    166 => ['2026-09-24', '2026-09-18'], 171 => ['2026-09-24', '2026-09-17'], 198 => ['2026-09-26', '2026-09-24'],
    200 => ['2026-09-26', '2026-09-17'], 201 => ['2026-09-26', '2026-09-24'], 202 => ['2026-09-27', '2026-09-24']]);
afirmar('193, 195 y 176 NO se corrigen', array_map(fn($k) => array_key_exists($k, $lista) ? $lista[$k]['fecha'] : 'falta', [193, 195, 176]), [null, null, null]);
afirmar('comprueba que el inicio y el fin caen el día nuevo', str_contains($con, "substr(\$ini, 0, 10) !== \$nueva || substr(\$fin, 0, 10) !== \$nueva"), true);
afirmar('la corrección con JSON_INSERT (no reescribe la carga del técnico)', str_contains($con, "JSON_INSERT(carga, '\$.correccion_admin'"), true);
afirmar('pdf_sha256 no se toca: solo pdf_sha256_regen', !preg_match('/SET\s+pdf_sha256\s*=/', $con) && str_contains($con, 'SET pdf_sha256_regen = ?'), true);
afirmar('simulacro por omisión', str_contains($con, "\$modo = 'SIMULACRO';"), true);
// Revisión del 28-sep-2026.
afirmar('solo corre en modo PRUEBA (el sitio del piloto), salida 3 si no',
        (bool) preg_match("/if \(Emision::modo\(\) !== 'PRUEBA'\) \{\s*salir\([^;]+, 3\);/", $con), true);
afirmar('una captura que la lista nombra entra al conjunto sea cual sea su fecha (sumar la 204 = una línea)',
        str_contains($con, "(string) \$f['emitida_en'] <= HASTA_PEDIDO || isset(\$REGENERAR[\$k])"), true);
afirmar('una corrida cortada entre rename y commit se reconoce y dice el remedio (el original del respaldo)',
        str_contains($con, 'parece una corrida cortada entre el reemplazo del PDF y el commit') && str_contains($con, 'sha256sum -c'), true);
afirmar('la OT-9125 lleva su nota (la línea del jefe de operaciones, D-G) a la bitácora',
        (bool) preg_match('/\$NOTAS = \[\s*166 => /', $con) && str_contains($con, "'nota'             => \$NOTAS[\$cid] ?? null"), true);

@unlink($cfg);
echo "\n$total comprobaciones · $fallos fallas\n";
exit($fallos ? 1 : 0);
