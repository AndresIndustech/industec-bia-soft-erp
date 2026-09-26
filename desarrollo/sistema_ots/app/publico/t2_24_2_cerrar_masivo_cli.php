<?php
declare(strict_types=1);

/**
 * t2_24_2_cerrar_masivo_cli.php — Cierra en bloque los preventivos "sin cerrar".
 *
 * POR QUE EXISTE
 * El rediseño de la pantalla de preventivos (T2.23) destapó 174 ingresos con
 * órdenes emitidas y sin que nadie registrara el cierre — algunos desde enero.
 * Andrés pidió el 2026-09-21 cerrarlos en bloque: llevan más de 7 días de
 * atraso, Grupo KFC ya los dio por hechos y la administradora sigue
 * regularizándolos a mano — mañana empieza a probar la plataforma y no debe
 * encontrar ese arrastre. Es la misma decisión, con el mismo criterio, que
 * `regularizar_masivo_cli.php` aplicó al buzón (ver ESTADO.md §1h).
 *
 * LO QUE NO HACE ESTE SCRIPT: inventar una fecha (I-7). Las fechas reales de
 * cada cierre salen de `catalogos/t2_24_2_cierres_masivo.json`, generado en la
 * estación cruzando cada ingreso contra `ots.fecha_atencion` — la fecha real
 * extraída del PDF de cada orden, independiente de lo que dice el cronograma
 * (I-10). Las 174 filas tienen al menos una orden con fecha real; ninguna se
 * completó por verosimilitud.
 *
 * REPRODUCE EXACTAMENTE la acción "cerrar" de `cronograma_accion.php`: mismo
 * cálculo de "a tiempo" (`real_fin <= plan_original_fin`), misma tabla, mismo
 * tipo de novedad CIERRE. No inventa un camino nuevo.
 *
 * ATRIBUCION: `cronograma_novedades.por` es NOT NULL con FK a usuarios, así
 * que no se puede usar `usuario='sistema'` como en la bitácora general. Se
 * atribuye a Andrés Basantes (usuario_id 2) porque es literalmente su
 * decisión — no se le atribuye a un técnico ni a un jefe de zona que no hizo
 * el clic. El detalle de cada novedad deja dicho que fue una regularización
 * masiva sin verificación caso por caso.
 *
 * DEFENSIVO: antes de escribir, vuelve a pedir cada ingreso a la base y
 * exige que siga en EN_CURSO, con plan_vigente_fin ya pasado y sin que nadie
 * lo haya tocado desde la app (`actualizado_por IS NULL`). Si algo cambió
 * desde que se generó el JSON, esa fila se salta y queda dicho — nunca se
 * fuerza. Reejecutarlo es seguro: la segunda vez no encuentra nada que hacer.
 *
 * Por omisión solo cuenta. Para escribir hay que pasar --ejecutar.
 *
 * Uso:
 *   php t2_24_2_cerrar_masivo_cli.php              # cuenta, no escribe
 *   php t2_24_2_cerrar_masivo_cli.php --ejecutar    # escribe de verdad
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/nucleo/Db.php';

$ejecutar = in_array('--ejecutar', $argv, true);
$ANDRES_ID = 2; // abasantes, SUPERADMIN — verificado contra la tabla usuarios antes de escribir esto

$ruta = __DIR__ . '/catalogos/t2_24_2_cierres_masivo.json';
if (!is_file($ruta)) { fwrite(STDERR, "falta $ruta\n"); exit(1); }
$payload = json_decode((string) file_get_contents($ruta), true);
$cierres = $payload['cierres'] ?? null;
if (!is_array($cierres)) { fwrite(STDERR, "el JSON no trae 'cierres'\n"); exit(1); }

echo "T2.24.2 · marca masiva de ejecutado de los preventivos atrasados, por marcar como ejecutados\n";
echo 'generado del payload : ' . ($payload['generado'] ?? '?') . "\n";
echo 'en el payload         : ' . count($cierres) . "\n";

$fecha = static function ($v): ?string {
    $s = trim((string) ($v ?? ''));
    if ($s === '') { return null; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return ($d !== false && $d->format('Y-m-d') === $s) ? $s : null;
};

function novedad(int $ingresoId, string $motivo, string $detalle, ?string $antes, ?string $despues, int $por): void
{
    Db::ejecutar(
        'INSERT INTO cronograma_novedades (ingreso_id, tipo, motivo, detalle, fecha_antes, fecha_despues, por)
         VALUES (?,\'CIERRE\',?,?,?,?,?)',
        [$ingresoId, mb_substr($motivo, 0, 120), mb_substr($detalle, 0, 600), $antes, $despues, $por]
    );
}

$ok = 0; $aTiempo = 0; $tarde = 0; $saltados = [];
foreach ($cierres as $c) {
    $id = (int) ($c['ingreso_id'] ?? 0);
    $rIni = $fecha($c['real_inicio'] ?? null);
    $rFin = $fecha($c['real_fin'] ?? null);
    if ($id <= 0 || $rIni === null || $rFin === null) {
        $saltados[] = [$id, $c['local_codigo'] ?? '?', 'payload inválido']; continue;
    }

    // Re-verifica contra la base VIVA, no contra el snapshot del JSON: es el
    // checkpoint de I-10 antes de cada escritura, no solo al principio.
    $i = Db::uno('SELECT * FROM ingresos_preventivos WHERE ingreso_id = ?', [$id]);
    if (!$i) { $saltados[] = [$id, $c['local_codigo'] ?? '?', 'ya no existe']; continue; }
    if ($i['local_codigo'] !== ($c['local_codigo'] ?? null) || (int) $i['numero'] !== (int) ($c['numero'] ?? -1)) {
        $saltados[] = [$id, $c['local_codigo'] ?? '?', 'no calza local/numero contra la base — payload desactualizado'];
        continue;
    }
    if ($i['estado'] !== 'EN_CURSO') { $saltados[] = [$id, $i['local_codigo'], 'ya no está EN_CURSO (' . $i['estado'] . ')']; continue; }
    if ($i['plan_vigente_fin'] === null || $i['plan_vigente_fin'] >= date('Y-m-d')) {
        $saltados[] = [$id, $i['local_codigo'], 'ya no está atrasado']; continue;
    }
    if ($i['actualizado_por'] !== null) {
        $saltados[] = [$id, $i['local_codigo'], 'alguien ya lo tocó desde la app — no se pisa']; continue;
    }

    $aTiempoEste = $i['plan_original_fin'] === null || $rFin <= $i['plan_original_fin'];
    $aTiempoEste ? $aTiempo++ : $tarde++;
    $ok++;

    if (!$ejecutar) { continue; }

    Db::ejecutar(
        'UPDATE ingresos_preventivos
            SET real_inicio = ?, real_fin = ?, estado = "CUMPLIDO",
                actualizado_por = ?, actualizado_en = NOW()
          WHERE ingreso_id = ?',
        [$rIni, $rFin, $ANDRES_ID, $id]
    );
    // VALORES GUARDADOS: el título y el detalle del movimiento del ingreso quedaron
    // escritos en cronograma_novedades cuando este script se corrió el 2026-09-21. Se
    // dejan tal cual se grabaron para que una búsqueda por el texto del script encuentre
    // esas filas (están en lista_negra_excepciones de vocabulario.json); la consola sí
    // habla con el diccionario.
    $detalle = 'Cierre masivo T2.24.2 (2026-09-21), a pedido de Andrés Basantes: más de 7 días de atraso, '
             . 'KFC y la administradora ya lo dan por hecho. Fecha real de ' . ($c['fuente_fecha'] ?? '?')
             . '. No se verificó caso por caso contra SAP ni contra el local.';
    novedad($id, $aTiempoEste ? 'Cumplido a tiempo (cierre masivo)' : 'Cumplido tarde (cierre masivo)',
             $detalle, $i['plan_original_fin'], $rFin, $ANDRES_ID);
}

echo "\nlistos para marcar como ejecutados : $ok\n";
echo "  a tiempo                         : $aTiempo\n";
echo "  tarde                            : $tarde\n";
echo 'saltados (' . count($saltados) . "):\n";
foreach ($saltados as $s) { echo '  ' . implode(' · ', $s) . "\n"; }

if ($ejecutar) {
    echo "\nMARCADOS COMO EJECUTADOS: $ok\n";
} else {
    echo "\nNO se escribió nada. Agrega --ejecutar para aplicarlo.\n";
}
