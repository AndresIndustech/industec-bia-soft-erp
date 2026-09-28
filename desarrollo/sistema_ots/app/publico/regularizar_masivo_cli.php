<?php
declare(strict_types=1);

/**
 * regularizar_masivo_cli.php — Vacía el pendiente de la administradora, en bloque.
 *
 * POR QUE EXISTE
 * El buzón acumuló cientos de casos ATENDIDO (esperando el botón «Ya lo cerré
 * en SAP») y CERRADO_SIN_ATENCION sin regularizar, y eso lo hace abrumador.
 * Andrés pidió el 2026-09-21 tratarlos en bloque, **asumiendo que la
 * administradora ya hizo las dos cosas en SAP** — no se verifica caso por caso
 * contra SAP: es una decisión de negocio suya, no un hallazgo de datos. Por
 * eso no hay checkpoint de verificación cruzada (I-10): no hay con qué
 * cruzar, es la premisa que Andrés dio.
 *
 * QUE HACE, Y QUE NO
 * Reproduce exactamente las dos transiciones que ya existen en casos.php
 * (Casos::TRANSICIONES 'cerrado_sap' y 'regularizar'), con las mismas
 * condiciones — incluido no tocar un ATENDIDO con un pendiente de repuestos
 * vivo (ASG-01) — para no inventar un camino nuevo por fuera de la máquina de
 * estados. NO toca `avisos_sap.estatus_general`: ese es el estado real de SAP
 * y esta corrida no lo simula (regla 5 de ESTADO.md §6, I-7). Solo mueve la
 * capa de gestión interna que decide qué le sigue apareciendo pendiente a la
 * administradora.
 *
 * QUEDA EN LA BITACORA CON usuario='sistema', no con el id de la
 * administradora: ella no hizo el clic, y atribuírselo falsearía el registro
 * (la misma razón por la que Reconciliar::anotar() usa 'sistema'). El
 * `detalle` de cada fila dice que fue una regularización masiva pedida por
 * Andrés Basantes y por qué.
 *
 * Por omisión solo cuenta. Para escribir hay que pasar --ejecutar.
 *
 * Uso:
 *   php regularizar_masivo_cli.php              # cuenta, no escribe
 *   php regularizar_masivo_cli.php --ejecutar    # escribe de verdad
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/nucleo/Db.php';
require_once __DIR__ . '/nucleo/Pendientes.php';
require_once __DIR__ . '/nucleo/Vocabulario.php';

$ejecutar = in_array('--ejecutar', $argv, true);
// VALORES GUARDADOS: $motivo (bitácora) y los dos literales de los UPDATE de abajo
// quedaron escritos en la base cuando este script se corrió el 2026-09-21. Se dejan
// tal cual se grabaron —con «casos», «cerrado» y «regularizado»— para que una búsqueda
// por el texto del script encuentre esas filas. Son datos, no pantalla: están en
// lista_negra_excepciones de vocabulario.json. Lo que imprime la consola sí usa el
// diccionario.
$motivo = 'Regularización masiva 2026-09-21, a pedido de Andrés Basantes: se asume que '
        . 'la administradora ya reportó/cerró estos casos en SAP. No se verificó caso por '
        . 'caso contra SAP.';

/** Copia de tienePendienteVivo() en casos.php — mismo criterio, mismo motivo (ASG-01). */
function tienePendienteVivo(string $aviso): bool
{
    if (!Pendientes::disponible()) { return false; }
    return (bool) Db::uno(
        "SELECT 1 FROM pendientes WHERE aviso = ? AND estado NOT IN ('RESUELTO','CANCELADO') LIMIT 1",
        [$aviso]
    );
}

function anotar(string $accion, string $aviso, string $antes, string $despues, string $motivo): void
{
    Db::ejecutar(
        "INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia,
                               estado_antes, estado_despues, exito, detalle, datos, ip)
         VALUES (NULL, 'sistema', ?, 'caso', ?, ?, ?, 1, ?, ?, 'estacion')",
        [$accion, $aviso, $antes, $despues, $motivo,
         json_encode(['motivo' => $motivo, 'pedido_por' => 'Andres Basantes',
                       'fecha_pedido' => '2026-09-21'], JSON_UNESCAPED_UNICODE)]
    );
}

// --- 1. ATENDIDO -> RESUELTO ("Ya lo cerré en SAP" en bloque) --------------
$atendidos = Db::todos("SELECT aviso FROM casos_gestion WHERE estado = 'ATENDIDO'");
$conPendiente = [];
$aCerrar = [];
foreach ($atendidos as $c) {
    if (tienePendienteVivo($c['aviso'])) { $conPendiente[] = $c['aviso']; continue; }
    $aCerrar[] = $c['aviso'];
}

echo '1. ATENDIDO (' . Vocabulario::t('ATENDIDA') . ') -> RESUELTO (' . Vocabulario::t('CERRADA_SAP') . ")\n";
echo '   ' . str_pad(Vocabulario::t('ATENDIDA', 2), 44) . ': ' . count($atendidos) . "\n";
echo '   ' . str_pad('con ' . Vocabulario::t('SOLICITUD_EN_TRAMITE') . ' (se saltan)', 44) . ': ' . count($conPendiente) . "\n";
echo '   ' . str_pad('a marcar ' . Vocabulario::t('CERRADA_SAP'), 44) . ': ' . count($aCerrar) . "\n";

if ($ejecutar) {
    foreach ($aCerrar as $aviso) {
        Db::ejecutar(
            "UPDATE casos_gestion
                SET estado = 'RESUELTO', veredicto_por = NULL, veredicto_en = NOW(),
                    veredicto_motivo = 'cerrado en SAP (regularización masiva 2026-09-21)'
              WHERE aviso = ?",
            [$aviso]
        );
        anotar('CERRADO_SAP_MASIVO', $aviso, 'ATENDIDO', 'RESUELTO', $motivo);
    }
    echo '   ' . str_pad(Vocabulario::t('CERRADA_SAP', 2), 44) . ': ' . count($aCerrar) . "\n";
} else {
    echo "   NO se escribio nada. Agrega --ejecutar para aplicarlo.\n";
}

// --- 2. CERRADO_SIN_ATENCION sin regularizar -> regularizado ---------------
$pendReg = Db::todos(
    "SELECT aviso FROM casos_gestion WHERE estado = 'CERRADO_SIN_ATENCION' AND regularizado_en IS NULL"
);
echo "\n2. CERRADO_SIN_ATENCION (" . Vocabulario::t('CERRADA_SIN_ATENCION') . ') -> ' . Vocabulario::t('REGULARIZADA') . "\n";
echo '   ' . str_pad(Vocabulario::t('SIN_REGULARIZAR', 2), 44) . ': ' . count($pendReg) . "\n";

if ($ejecutar) {
    foreach ($pendReg as $c) {
        Db::ejecutar(
            "UPDATE casos_gestion SET regularizado_por = NULL, regularizado_en = NOW(),
                    nota = COALESCE(NULLIF(nota, ''), 'regularizado (regularización masiva 2026-09-21)')
              WHERE aviso = ?",
            [$c['aviso']]
        );
        anotar('REGULARIZAR_MASIVO', $c['aviso'], 'CERRADO_SIN_ATENCION', 'CERRADO_SIN_ATENCION', $motivo);
    }
    echo '   ' . str_pad(Vocabulario::t('REGULARIZADA', 2), 44) . ': ' . count($pendReg) . "\n";
} else {
    echo "   NO se escribio nada. Agrega --ejecutar para aplicarlo.\n";
}

echo "\nEstado de la tabla:\n";
// El código de la base y, al lado, su nombre en el diccionario: quien lee la
// consola es la misma persona que después ve la pantalla.
foreach (Db::todos('SELECT estado, COUNT(*) n FROM casos_gestion GROUP BY estado ORDER BY n DESC') as $x) {
    // Un estado que el diccionario no conoce no tumba la consola: se dice (I-7).
    try { $txt = Vocabulario::t(Vocabulario::deEstado((string) $x['estado'])); }
    catch (VocabularioError $ex) { $txt = '(sin concepto en el diccionario)'; }
    printf("   %-22s %-30s %5d\n", $x['estado'], $txt, $x['n']);
}
$p = Db::uno("SELECT COUNT(*) n FROM casos_gestion
               WHERE estado = 'CERRADO_SIN_ATENCION' AND regularizado_en IS NULL");
echo '   ' . str_pad(Vocabulario::t('SIN_REGULARIZAR', 2), 44) . ': ' . $p['n'] . "\n";
$a = Db::uno("SELECT COUNT(*) n FROM casos_gestion WHERE estado = 'ATENDIDO'");
echo '   ' . str_pad(Vocabulario::t('ATENDIDA', 2), 44) . ': ' . $a['n'] . "\n";
