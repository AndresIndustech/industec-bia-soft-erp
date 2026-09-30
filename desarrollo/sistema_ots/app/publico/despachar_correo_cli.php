<?php
declare(strict_types=1);

/**
 * despachar_correo_cli.php — Saca los correos de la cola y los manda por SMTP.
 *
 * POR QUE UNA COLA Y NO UN ENVIO DIRECTO (PLAN T2.3)
 * Producción manda el correo en el mismo clic que genera la orden: con el SMTP
 * caído la orden no llegaba a nadie (82 fallos), y nadie reintentaba. Aquí la
 * orden ya está guardada y emitida cuando el correo se intenta; si falla, espera
 * y se vuelve a intentar con espera creciente. Nada se pierde en silencio.
 *
 * DESDE EL 29-SEP-2026 (T2.29) todo el trabajo lo hace `Correo::despachar()`,
 * el mismo que llama envio.php justo después de responderle al técnico y el
 * botón «Enviar ahora» de Correos. Este archivo es la puerta del cron: toma el
 * candado, llama y cuenta. Lo que hace, paso a paso, está en Correo.php:
 * reclamo atómico, una sola conexión por lote con la cuenta activa de
 * `correo_cuentas`, topes diario y por hora, espera creciente, 5xx permanente,
 * el cupo por hora sin gastar intentos, un fallo de la cuenta sin gastar
 * ninguno, y las OT del piloto que nunca salen.
 *
 * YA NO SALE POR «MODO PRUEBA». Hasta el 29-sep, sin `emision_modo` en
 * config.php este script contaba los retenidos y salía sin conectar. Ahora el
 * modo es por zona: un correo solo llega a PENDIENTE si su OT se emitió en una
 * zona con el envío real activo, y los RETENIDO no se tocan nunca. El freno de
 * emergencia sigue: `emision_modo => 'PRUEBA'` en config.php y no conecta.
 *
 * SOLO CLI.
 *
 * CRON DE hPANEL (lo programa Andrés; cada 5 minutos):
 *   cd /home/<usuario>/domains/<sitio>/public_html/ot && php despachar_correo_cli.php >> ~/logs/correo.log 2>&1
 *
 * Uso:  php despachar_correo_cli.php [--max N]   (N por omisión 30)
 * Sale con 0 si no hubo fallos permanentes, 2 si alguno quedó FALLIDO, 3 si falta
 * PHPMailer o una cuenta de envío con clave legible.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/nucleo/Correo.php';

$max = 30;
foreach ($argv as $i => $a) {
    if ($a === '--max' && isset($argv[$i + 1])) { $max = max(1, min(200, (int) $argv[$i + 1])); }
}

$r = Correo::despachar($max);
$hoy = date('Y-m-d H:i');
foreach ($r['lineas'] as $l) { echo $hoy . ' · ' . ltrim($l) . "\n"; }
if ($r['estado'] === 'HECHO' || $r['estado'] === 'CUENTA_FALLA') {
    echo "$hoy · {$r['enviados']} enviados, {$r['temporales']} por reintentar, {$r['fallidos']} fallidos"
       . ($r['retenidos'] ? ", {$r['retenidos']} retenidos (del piloto)" : '') . "\n";
}
if (in_array($r['estado'], ['SIN_CUENTA', 'SIN_PHPMAILER'], true)) {
    fwrite(STDERR, implode("\n", $r['lineas']) . "\n");
    exit(3);
}
exit($r['fallidos'] > 0 ? 2 : 0);
