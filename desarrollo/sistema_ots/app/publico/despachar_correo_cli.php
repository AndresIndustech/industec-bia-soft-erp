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
 * QUE HACE, PASO A PASO
 *   1. Reclama hasta N filas PENDIENTE cuyo `proximo_intento_en` ya pasó, de forma
 *      atómica (UPDATE … ENVIANDO con `tomado_en`), para que dos despachadores no
 *      manden el mismo correo dos veces. Un reclamo de más de 15 min se considera
 *      abandonado y se vuelve a tomar.
 *   2. Manda cada uno con PHPMailer por SMTP (Titan, en config.php).
 *   3. Clasifica el error: 4xx o de red → temporal → espera 5 min, 15 min, 1 h,
 *      4 h, 24 h y FALLIDO al sexto intento; 5xx → permanente → FALLIDO directo
 *      (reintentar un rechazo permanente daña la reputación del dominio).
 *   4. Al enviar, `ot_capturadas.estado = 'ENVIADA'` y bitácora.
 *
 * EN MODO PRUEBA (el que rige mientras config.php no diga PRODUCCION) NO CONECTA
 * NUNCA: los correos del sitio de pruebas están RETENIDO y este script solo los
 * cuenta. Producción manda cada orden al local, a Grupo KFC y al buzón de la
 * administradora, que se lee de forma automática: un correo de prueba llegaría
 * como una orden real.
 *
 * TOPE DIARIO: Titan corta en 1.000 por buzón y día. `correo_tope_dia` en
 * config.php (900 por omisión) detiene el despacho ese día y lo dice.
 *
 * TOPE POR HORA (T2.28.2, §2c): Titan además corta en 45 por hora y lo dice
 * con «Sender Hourly Quota Exceeded», un 4xx que por su número parecería
 * temporal de todos modos, pero como el límite es contra el envío en curso
 * -no un rechazo de ESE correo- se trata aparte: nunca cuenta para los 6
 * intentos y siempre reintenta a los 60 min, sin marcar FALLIDO por eso. Y
 * antes de gastar ni un intento, esta corrida se detiene si ya van 45
 * ENVIADO en la última hora, para no chocar con el tope en cada corrida de 5
 * minutos.
 *
 * SOLO CLI, con candado de instancia única.
 *
 * CRON DE hPANEL (lo programa Andrés; cada 5 minutos):
 *   cd /home/<usuario>/domains/<sitio>/public_html/ot && php despachar_correo_cli.php >> ~/logs/correo.log 2>&1
 *
 * Uso:  php despachar_correo_cli.php [--max N]   (N por omisión 30)
 * Sale con 0 si no hubo fallos permanentes, 2 si alguno quedó FALLIDO, 3 si falta
 * PHPMailer o la configuración SMTP en modo PRODUCCION.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/nucleo/Emision.php';
require_once __DIR__ . '/nucleo/Auth.php';
require_once __DIR__ . '/nucleo/Despacho.php';   // T2.28.2: el tope por hora y la clasificación del error, en un solo lugar probado

$max = 30;
foreach ($argv as $i => $a) {
    if ($a === '--max' && isset($argv[$i + 1])) { $max = max(1, min(200, (int) $argv[$i + 1])); }
}

$candado = fopen(sys_get_temp_dir() . '/industec_correo.lock', 'c');
if ($candado === false || !flock($candado, LOCK_EX | LOCK_NB)) {
    echo "otra corrida sigue en marcha; nada que hacer\n";
    exit(0);
}

$cfg    = Db::config();
$prueba = Emision::modo() === 'PRUEBA';
$hoy    = date('Y-m-d H:i');

if ($prueba) {
    $n = (int) (Db::uno("SELECT COUNT(*) n FROM email_queue WHERE estado IN ('RETENIDO','PENDIENTE')")['n'] ?? 0);
    echo "$hoy · modo PRUEBA: 0 enviados, $n retenidos. No se conecta a ningún SMTP.\n";
    exit(0);
}

// --- PHPMailer, fuera de la carpeta web (app/lib, como dompdf) --------------
if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    foreach (array_filter([
        $cfg['dompdf_autoload'] ?? null,
        dirname(__DIR__, 5) . '/lib/ot/vendor/autoload.php',
        dirname(__DIR__, 2) . '/lib/vendor/autoload.php',
    ]) as $a) {
        if (is_file($a)) { require_once $a; break; }
    }
}
if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    fwrite(STDERR, "falta PHPMailer: composer install en ~/lib/ot (app/lib/LEEME.md)\n");
    exit(3);
}
foreach (['smtp_host', 'smtp_usuario', 'smtp_clave', 'smtp_de'] as $k) {
    if (empty($cfg[$k])) {
        fwrite(STDERR, "falta $k en config.php: en modo PRODUCCION no se despacha sin SMTP configurado\n");
        exit(3);
    }
}

// --- El tope diario ----------------------------------------------------------
$tope = (int) ($cfg['correo_tope_dia'] ?? 900);
$enviadosHoy = (int) (Db::uno("SELECT COUNT(*) n FROM email_queue WHERE estado = 'ENVIADO' AND enviado_en >= CURDATE()")['n'] ?? 0);
if ($enviadosHoy >= $tope) {
    echo "$hoy · tope diario alcanzado ($enviadosHoy de $tope): se reanuda mañana\n";
    exit(0);
}
$max = min($max, $tope - $enviadosHoy);

// --- El tope por hora (T2.28.2) -----------------------------------------------
$enviadosHora = (int) (Db::uno(
    "SELECT COUNT(*) n FROM email_queue WHERE estado = 'ENVIADO' AND enviado_en >= NOW() - INTERVAL 1 HOUR"
)['n'] ?? 0);
if (Despacho::superoTopeHora($enviadosHora)) {
    echo "$hoy · tope por hora alcanzado ($enviadosHora de " . Despacho::TOPE_HORA . "): no se conecta esta corrida\n";
    exit(0);
}
$max = min($max, Despacho::TOPE_HORA - $enviadosHora);

// --- Reclamo atómico ---------------------------------------------------------
$marca = bin2hex(random_bytes(6));
Db::ejecutar(
    "UPDATE email_queue
        SET estado = 'ENVIANDO', tomado_en = NOW(), error_ultimo = ?
      WHERE (estado = 'PENDIENTE' AND (proximo_intento_en IS NULL OR proximo_intento_en <= NOW()))
         OR (estado = 'ENVIANDO' AND tomado_en < DATE_SUB(NOW(), INTERVAL 15 MINUTE))
      ORDER BY creado_en
      LIMIT " . $max,
    ['reclamo ' . $marca]
);
$lote = Db::todos("SELECT * FROM email_queue WHERE estado = 'ENVIANDO' AND error_ultimo = ?", ['reclamo ' . $marca]);
if ($lote === []) {
    echo "$hoy · nada pendiente de enviar\n";
    exit(0);
}

$ok = 0; $temporales = 0; $permanentes = 0;
foreach ($lote as $c) {
    $para = json_decode((string) $c['para'], true) ?: [];
    $adjunto = $c['adjunto'] ? Emision::dirPdf() . '/' . basename((string) $c['adjunto']) : null;
    $intento = (int) $c['intentos'] + 1;
    try {
        $m = new \PHPMailer\PHPMailer\PHPMailer(true);
        $m->CharSet = 'UTF-8';
        $m->isSMTP();
        $m->Host       = (string) $cfg['smtp_host'];
        $m->Port       = (int) ($cfg['smtp_puerto'] ?? 465);
        $m->SMTPSecure = $m->Port === 465 ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                                          : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $m->SMTPAuth   = true;
        $m->Username   = (string) $cfg['smtp_usuario'];
        $m->Password   = (string) $cfg['smtp_clave'];
        $m->Timeout    = 30;
        $m->setFrom((string) $cfg['smtp_de'], 'INDUSTEC · Órdenes de trabajo');
        foreach ($para as $dir) {
            if (filter_var($dir, FILTER_VALIDATE_EMAIL)) { $m->addAddress($dir); }
        }
        if ($m->getToAddresses() === []) {
            throw new RuntimeException('550 sin destinatarios válidos');
        }
        // T2.28.2: las copias que Destinatarios::resolver() congeló al encolar.
        $cc = json_decode((string) ($c['cc'] ?? ''), true) ?: [];
        foreach ($cc as $dir) {
            if (filter_var($dir, FILTER_VALIDATE_EMAIL)) { $m->addCC($dir); }
        }
        $m->Subject = (string) $c['asunto'];
        $m->Body    = (string) $c['cuerpo'];
        if ($adjunto !== null && is_file($adjunto)) {
            $m->addAttachment($adjunto);
        } elseif ($adjunto !== null) {
            throw new RuntimeException('450 el PDF todavía no está en el servidor: ' . basename($adjunto));
        }
        $m->send();

        Db::ejecutar("UPDATE email_queue
                         SET estado = 'ENVIADO', enviado_en = NOW(), intentos = ?, ultimo_intento_en = NOW(),
                             error_ultimo = NULL, proximo_intento_en = NULL, tomado_en = NULL
                       WHERE correo_id = ?", [$intento, $c['correo_id']]);
        Db::ejecutar("UPDATE ot_capturadas SET estado = 'ENVIADA' WHERE captura_id = ? AND estado = 'EMITIDA'",
                     [$c['captura_id']]);
        Auth::bitacora('CORREO_ENVIADO', 'ot', (string) $c['id_industec'], count($para) . ' destinatarios',
                       null, 'ENVIADO', ['correo_id' => $c['correo_id'], 'intento' => $intento]);
        $ok++;
        echo "  enviado  {$c['id_industec']} (intento $intento, " . count($para) . " destinatarios)\n";
    } catch (Throwable $e) {
        $msg = mb_substr($e->getMessage(), 0, 300);
        // La clasificación (permanente / temporal / cupo por hora) es la misma
        // regla que prueba Despacho::clasificar() sin tocar SMTP ni la base
        // (T2.28.2, §2c): aquí solo se aplica lo que ya decidió.
        $c2 = Despacho::clasificar($msg, (int) $c['intentos']);
        if ($c2['permanente']) {
            Db::ejecutar("UPDATE email_queue
                             SET estado = 'FALLIDO', intentos = ?, ultimo_intento_en = NOW(), error_ultimo = ?,
                                 motivo = ?, tomado_en = NULL
                           WHERE correo_id = ?",
                         [$c2['intentosGuardar'], $msg, $c2['motivo'], $c['correo_id']]);
            Auth::bitacora('CORREO_FALLIDO', 'ot', (string) $c['id_industec'], $msg, null, 'FALLIDO',
                           ['correo_id' => $c['correo_id'], 'intento' => $c2['intentosGuardar']], false);
            $permanentes++;
            echo "  FALLIDO  {$c['id_industec']}: $msg\n";
        } else {
            Db::ejecutar("UPDATE email_queue
                             SET estado = 'PENDIENTE', intentos = ?, ultimo_intento_en = NOW(), error_ultimo = ?,
                                 proximo_intento_en = DATE_ADD(NOW(), INTERVAL ? MINUTE), tomado_en = NULL
                           WHERE correo_id = ?", [$c2['intentosGuardar'], $msg, $c2['espera'], $c['correo_id']]);
            $temporales++;
            echo "  reintento {$c['id_industec']} en {$c2['espera']} min" . ($c2['cupoHora'] ? ' (cupo por hora del SMTP)' : '') . ": $msg\n";
        }
    }
}
echo "$hoy · $ok enviados, $temporales por reintentar, $permanentes fallidos\n";
exit($permanentes > 0 ? 2 : 0);
