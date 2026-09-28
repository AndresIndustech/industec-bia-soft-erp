<?php
declare(strict_types=1);

/**
 * emitir_pendientes_cli.php — Reemite las OT INDUSTEC que quedaron a medias
 * (OT INDUSTEC no emitidas: RECIBIDA, NUMERADA o FALLIDA).
 *
 * POR QUE EXISTE
 * Cuando la emisión falla (dompdf caído, disco lleno, serie sin contador) la
 * orden queda guardada pero sin número, sin PDF o sin correo, y hasta la 009
 * nadie la reintentaba: el celular ya había marcado su envío como ENVIADA y el
 * único llamador de Emision::emitir() era envio.php (AUDITORIA_2026-09-12,
 * H-03 y E-02). La app le decía al técnico «se reintenta en el próximo envío» y
 * no era cierto. Ahora envio.php reintenta unas pocas al recibir cada orden, y
 * este script barre el resto desde el cron.
 *
 * SOLO CLI. Por web devuelve 404. Con candado de instancia única: dos corridas a
 * la vez sacarían dos números para la misma orden.
 *
 * CRON DE hPANEL (lo programa Andrés; cada 10 minutos):
 *   cd /home/<usuario>/domains/<sitio>/public_html/ot && php emitir_pendientes_cli.php >> ~/logs/emitir.log 2>&1
 *
 * Uso:  php emitir_pendientes_cli.php [--max N]     (N por omisión 50)
 * Sale con 0 si no queda nada pendiente, con 2 si alguna sigue fallando.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/nucleo/Emision.php';
require_once __DIR__ . '/nucleo/Auth.php';

$max = 50;
foreach ($argv as $i => $a) {
    if ($a === '--max' && isset($argv[$i + 1])) { $max = max(1, (int) $argv[$i + 1]); }
}

$candado = fopen(sys_get_temp_dir() . '/industec_emitir.lock', 'c');
if ($candado === false || !flock($candado, LOCK_EX | LOCK_NB)) {
    echo "otra corrida sigue en marcha; nada que hacer\n";
    exit(0);
}

$antes = Db::todos(
    "SELECT captura_id, id_industec, estado, emision_error FROM ot_capturadas
      WHERE estado IN ('RECIBIDA', 'NUMERADA', 'FALLIDA') OR emision_error IS NOT NULL
      ORDER BY recibida_en LIMIT " . $max
);
if ($antes === []) {
    echo date('Y-m-d H:i') . " · ninguna OT INDUSTEC por emitir\n";
    exit(0);
}

$ok = 0;
$mal = [];
foreach ($antes as $f) {
    $r = Emision::emitir((int) $f['captura_id']);
    if ($r['pdf'] && $r['error'] === null) {
        $ok++;
        echo "  emitida  captura {$f['captura_id']} → {$r['id_industec']} · correo " . strtolower((string) $r['correo']) . "\n";
    } else {
        $mal[] = $f['captura_id'];
        echo "  sigue    captura {$f['captura_id']}: " . ($r['error'] ?? 'sin motivo') . "\n";
    }
}
echo date('Y-m-d H:i') . " · $ok OT INDUSTEC emitidas, " . count($mal) . " siguen sin emitirse de " . count($antes) . "\n";
exit($mal === [] ? 0 : 2);
