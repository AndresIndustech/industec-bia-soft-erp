<?php
declare(strict_types=1);

/**
 * Prueba del reloj de 48 horas y de la maquina de las cuatro vias.
 *
 * No toca la base: solo ejercita la logica pura. Es la parte que decide si un
 * equipo parado se ve o no se ve en el tablero del jefe de zona, y por eso es
 * lo primero que hay que poder comprobar sin levantar MySQL.
 */

require_once 'd:/INDUSTECH IA/desarrollo/sistema_ots/app/publico/nucleo/Ui.php';
require_once 'd:/INDUSTECH IA/desarrollo/sistema_ots/app/publico/nucleo/Pendientes.php';

$fallos = 0;
$total = 0;

function afirmar(string $que, $real, $esperado): void
{
    global $fallos, $total;
    $total++;
    $bien = $real === $esperado;
    if (!$bien) { $fallos++; }
    printf("  %-58s %-22s %s\n", $que,
           is_bool($real) ? ($real ? 'true' : 'false') : (string) $real,
           $bien ? 'ok' : 'FALLA (esperaba ' . var_export($esperado, true) . ')');
}

function hace(float $horas): string
{
    return date('Y-m-d H:i:s', time() - (int) round($horas * 3600));
}

echo "=== Ui::reloj48() — el plazo del veredicto ===\n";

$r = Ui::reloj48(hace(1));
afirmar('a 1 h: no esta vencido', $r['vencido'], false);
afirmar('a 1 h: avisa en verde', $r['clase'], 'edad edad-hoy');

$r = Ui::reloj48(hace(30));
afirmar('a 30 h: todavia a tiempo', $r['vencido'], false);
afirmar('a 30 h: pasa a azul (quedan menos de 24)', $r['clase'], 'edad edad-3');

$r = Ui::reloj48(hace(40));
afirmar('a 40 h: avisa en ambar (quedan menos de 12)', $r['clase'], 'edad edad-7');
afirmar('a 40 h: sigue sin vencer', $r['vencido'], false);

$r = Ui::reloj48(hace(47.9));
afirmar('a 47,9 h: NO esta vencido', $r['vencido'], false);

$r = Ui::reloj48(hace(48.1));
afirmar('a 48,1 h: SI esta vencido', $r['vencido'], true);
afirmar('a 48,1 h: en rojo', $r['clase'], 'edad edad-viejo');

$r = Ui::reloj48(hace(120));
afirmar('a 5 dias: vencido', $r['vencido'], true);
afirmar('a 5 dias: lo dice en dias', str_contains($r['texto'], 'día'), true);

$r = Ui::reloj48('esto no es una fecha');
afirmar('sin fecha valida: no inventa un vencimiento', $r['vencido'], false);
afirmar('sin fecha valida: lo dice (I-7)', $r['texto'], 'sin fecha');

echo "\n=== Pendientes::reloj() — cuando SI y cuando NO corresponde medir ===\n";

$base = ['estado' => 'SIN_VEREDICTO', 'via' => 'SIN_VEREDICTO',
         'abierto_en' => hace(60), 'veredicto_en' => null, 'deshabilitado' => 1];

$r = Pendientes::reloj($base);
afirmar('parado, sin veredicto, 60 h: vencido', $r['vencido'], true);

// El equipo que sigue operando no tiene plazo: el local puede usarlo.
$r = Pendientes::reloj(array_merge($base, ['deshabilitado' => 0]));
afirmar('equipo operando: no se mide plazo', $r, null);

// Ya cerrado: no tiene sentido seguir contando.
$r = Pendientes::reloj(array_merge($base, ['estado' => 'RESUELTO']));
afirmar('ya resuelto: no se mide plazo', $r, null);

// Con veredicto tarde: se guarda COMO se cumplio, no se sigue contando.
$r = Pendientes::reloj(array_merge($base, [
    'via' => 'REPUESTO', 'estado' => 'COTIZANDO',
    'abierto_en' => hace(60), 'veredicto_en' => hace(5),
]));
afirmar('veredicto a las 55 h: queda como tarde', $r['vencido'], true);
afirmar('veredicto tarde: el reloj deja de correr', $r['cerrado'], true);

// Con veredicto a tiempo.
$r = Pendientes::reloj(array_merge($base, [
    'via' => 'GARANTIA', 'estado' => 'GARANTIA_RECLAMADA',
    'abierto_en' => hace(30), 'veredicto_en' => hace(20),
]));
afirmar('veredicto a las 10 h: a tiempo', $r['vencido'], false);
afirmar('garantia de semanas NO cuenta como incumplida', $r['cerrado'], true);

echo "\n=== La maquina de las cuatro vias ===\n";

foreach (Pendientes::VIAS as $via => [$etiqueta, $ayuda, $primerPaso]) {
    $pasos = Pendientes::PASOS[$via] ?? [];
    afirmar("via $via: tiene pasos definidos", count($pasos) > 0, true);
    afirmar("via $via: su primer paso esta en la lista", in_array($primerPaso, $pasos, true), true);
    afirmar("via $via: termina en RESUELTO", in_array('RESUELTO', $pasos, true), true);
    foreach ($pasos as $paso) {
        afirmar("  el paso $paso tiene etiqueta legible",
                isset(Pendientes::ESTADOS[$paso]), true);
    }
}

// Ningun estado del ENUM se puede quedar sin etiqueta: si pasa, la pantalla lo
// dibuja en crudo, en mayusculas y con guion bajo, delante del cliente. Es
// exactamente el defecto que tenia Casos::etiquetaEstado().
$delEnum = ['SIN_VEREDICTO','COTIZANDO','COMPRADO','EN_BODEGA','ENTREGADO',
            'EN_TALLER','DEVUELTO_TALLER','GARANTIA_RECLAMADA','GARANTIA_APROBADA',
            'GARANTIA_NEGADA','BAJA_PROPUESTA','BAJA_APROBADA','RESUELTO','CANCELADO'];
foreach ($delEnum as $e) {
    afirmar("ENUM $e tiene etiqueta", isset(Pendientes::ESTADOS[$e]), true);
}
afirmar('ABIERTOS no incluye RESUELTO', in_array('RESUELTO', Pendientes::ABIERTOS, true), false);
afirmar('ABIERTOS no incluye CANCELADO', in_array('CANCELADO', Pendientes::ABIERTOS, true), false);
afirmar('ABIERTOS cubre todos los demas',
        count(Pendientes::ABIERTOS) === count($delEnum) - 2, true);

echo "\n=== Los ocho estados del caso (Ui::ESTADOS) ===\n";
$enumCaso = ['NUEVO','ASIGNADO','EN_REVISION','RESUELTO','NO_COMPETE','ATENDIDO',
             'CERRADO_SIN_ATENCION','ESPERA_REPUESTO'];
foreach ($enumCaso as $e) {
    afirmar("$e tiene etiqueta", isset(Ui::ESTADOS[$e]), true);
    afirmar("  y no sale en crudo", Ui::etiquetaEstado($e) !== $e, true);
}
// El defecto original, comprobado al reves.
afirmar('ATENDIDO ya no sale en crudo', Ui::etiquetaEstado('ATENDIDO'), 'atendido');
afirmar('CERRADO_SIN_ATENCION ya no sale en crudo',
        Ui::etiquetaEstado('CERRADO_SIN_ATENCION'), 'sin atender');

echo "\n=== Ui::edad() — la antiguedad del caso ===\n";
afirmar('creado hoy', strip_tags(Ui::edad(date('Y-m-d'))), 'hoy');
afirmar('creado ayer', strip_tags(Ui::edad(date('Y-m-d', strtotime('-1 day')))), 'ayer');
afirmar('a 3 dias: azul', str_contains(Ui::edad(date('Y-m-d', strtotime('-3 days'))), 'edad-3'), true);
afirmar('a 6 dias: ambar', str_contains(Ui::edad(date('Y-m-d', strtotime('-6 days'))), 'edad-7'), true);
// El rojo aparece justo en el borde en que Reconciliar.php cierra el caso.
afirmar('a 8 dias: rojo (pasado el corte de 7)',
        str_contains(Ui::edad(date('Y-m-d', strtotime('-8 days'))), 'edad-viejo'), true);
afirmar('sin fecha: no inventa (I-7)', strip_tags(Ui::edad(null)), '—');

echo "\n=== Ui::zona() — el color nunca es la unica senal ===\n";
foreach (['UIO', 'LARB', 'CNLJ'] as $z) {
    afirmar("$z lleva su nombre en texto", str_contains(Ui::zona($z), '>' . $z . '<'), true);
    afirmar("$z lleva su clase de color", str_contains(Ui::zona($z), 'zona-' . strtolower($z)), true);
}
afirmar('sin zona: lo dice con palabras', str_contains(Ui::zona(null), 'sin zona'), true);
afirmar('zona desconocida no revienta', str_contains(Ui::zona('XXX'), 'zona-otra'), true);

echo "\n";
printf("%d comprobaciones · %d fallos\n", $total, $fallos);
exit($fallos > 0 ? 1 : 0);
