<?php
declare(strict_types=1);

/**
 * Prueba de unidad: Despacho::superoTopeHora() y Despacho::clasificar()
 * (T2.28.2, §2c) — las dos decisiones puras que despachar_correo_cli.php
 * aplica contra el SMTP de verdad, aquí sin conectar a ninguno.
 *
 * NO TOCA LA BASE NI NINGÚN SMTP.
 *
 *   php pruebas/prueba_despacho.php
 */

require_once __DIR__ . '/../publico/nucleo/Despacho.php';

$fallos = 0;
$total  = 0;

function afirmar(string $que, $real, $esperado): void
{
    global $fallos, $total;
    $total++;
    $bien = $real === $esperado;
    if (!$bien) { $fallos++; }
    printf("  %-72s %s\n", $que,
           $bien ? 'ok' : 'FALLA (obtuvo ' . var_export($real, true) . ', esperaba ' . var_export($esperado, true) . ')');
}

echo "=== El tope por hora ===\n";
afirmar('44 enviados: no se llegó al tope', Despacho::superoTopeHora(44), false);
afirmar('45 enviados (el 45.º ya se mandó): se llegó al tope', Despacho::superoTopeHora(45), true);
afirmar('46.º correo de la hora: no conecta (superoTopeHora con 45 ya enviados)', Despacho::superoTopeHora(45), true);
afirmar('0 enviados: lejos del tope', Despacho::superoTopeHora(0), false);

echo "\n=== Un 5xx es permanente ===\n";
$c = Despacho::clasificar('550 sin destinatarios válidos', 0);
afirmar('550 → permanente', $c['permanente'], true);
afirmar('motivo: rechazo permanente', $c['motivo'], 'rechazo permanente del SMTP');
afirmar('no es el cupo por hora', $c['cupoHora'], false);

echo "\n=== Un 4xx temporal, con las esperas de siempre ===\n";
$c = Despacho::clasificar('450 el PDF todavía no está en el servidor', 0);
afirmar('primer intento (450) → temporal', $c['permanente'], false);
afirmar('espera 5 min', $c['espera'], 5);
afirmar('intentos sube a 1', $c['intentosGuardar'], 1);
$c = Despacho::clasificar('450 otra vez', 2);
afirmar('tercer intento → espera 1 h (60 min)', $c['espera'], 60);

echo "\n=== A los 6 intentos, FALLIDO aunque el error sea temporal ===\n";
$c = Despacho::clasificar('450 sigue sin responder', 5);   // este sería el 6.º intento
afirmar('6.º intento (450) → permanente por agotar los reintentos', $c['permanente'], true);
afirmar('motivo: agotó los intentos', $c['motivo'], 'agotó los 6 intentos');

echo "\n=== «Sender Hourly Quota Exceeded»: nunca FALLIDO, siempre 60 min ===\n";
$c = Despacho::clasificar('452 4.3.1 Sender Hourly Quota Exceeded', 0);
afirmar('primer intento: NO es permanente', $c['permanente'], false);
afirmar('se marca como cupo por hora', $c['cupoHora'], true);
afirmar('reintenta a los 60 min', $c['espera'], 60);
afirmar('los intentos NO suben (no cuenta para los 6)', $c['intentosGuardar'], 0);
$c2 = Despacho::clasificar('452 4.3.1 Sender Hourly Quota Exceeded', 9);
afirmar('aunque ya lleve 9 «intentos» previos, sigue sin ser permanente', $c2['permanente'], false);
afirmar('y sigue sin subir el contador', $c2['intentosGuardar'], 9);
$c3 = Despacho::clasificar('452 4.3.1 SENDER HOURLY QUOTA EXCEEDED', 0);
afirmar('detecta el aviso sin importar mayúsculas/minúsculas', $c3['cupoHora'], true);

printf("\n%d comprobaciones · %d fallos\n", $total, $fallos);
exit($fallos === 0 ? 0 : 1);
