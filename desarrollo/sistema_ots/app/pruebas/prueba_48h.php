<?php
declare(strict_types=1);

/**
 * Prueba del reloj de 48 horas y de la maquina de las cuatro vias.
 *
 * No toca la base: solo ejercita la logica pura. Es la parte que decide si un
 * equipo parado se ve o no se ve en el tablero del jefe de zona, y por eso es
 * lo primero que hay que poder comprobar sin levantar MySQL.
 */

// Rutas relativas: la prueba vive en app/pruebas/ y el núcleo en app/publico/nucleo/.
// Con la ruta absoluta de la estación pegada no corría en ningún otro equipo.
require_once __DIR__ . '/../publico/nucleo/Ui.php';
require_once __DIR__ . '/../publico/nucleo/Pendientes.php';
// Las etiquetas se comparan contra el diccionario, no contra un literal: el
// texto se cambia en vocabulario.json y esta prueba no tiene por qué saberlo.
require_once __DIR__ . '/../publico/nucleo/Vocabulario.php';

$fallos = 0;
$total = 0;

function afirmar(string $que, $real, $esperado): void
{
    global $fallos, $total;
    $total++;
    $bien = $real === $esperado;
    if (!$bien) { $fallos++; }
    printf("  %-58s %-22s %s\n", $que,
           is_bool($real) ? ($real ? 'true' : 'false') : ($real === null ? 'null' : (is_scalar($real) ? (string) $real : gettype($real))),
           $bien ? 'ok' : 'FALLA (esperaba ' . var_export($esperado, true) . ')');
}

/** Lee una clave de lo que devolvió reloj(); si devolvió null, lo dice en vez de reventar. */
function clave(?array $r, string $k)
{
    return $r === null ? 'contrato roto: reloj() devolvió null' : ($r[$k] ?? 'sin clave ' . $k);
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

// LAS HORAS LAS MIDE MYSQL, NO PHP. Desde que restar fechas en PHP dependía de
// la configuración del hosting (error nº 11 del plan), Pendientes::lista()
// inyecta `min_plazo` y `min_veredicto` en minutos y reloj() lee eso. Hasta el
// 2026-09-12 esta prueba seguía pasando `abierto_en`/`veredicto_en` como si el
// cálculo fuera en PHP: reloj() devolvía null y cuatro afirmaciones fallaban
// sin que hubiera defecto (AUDITORIA_2026-09-12 P-01). Se prueba el contrato.

// Con veredicto tarde: se guarda COMO se cumplio, no se sigue contando.
$r = Pendientes::reloj(array_merge($base, [
    'via' => 'REPUESTO', 'estado' => 'COTIZANDO', 'min_veredicto' => 55 * 60,
]));
afirmar('veredicto a las 55 h: queda como tarde', clave($r, 'vencido'), true);
afirmar('veredicto tarde: el reloj deja de correr', clave($r, 'cerrado'), true);
afirmar('veredicto tarde: dice cuantas horas', (float) clave($r, 'horas'), 55.0);

// Con veredicto a tiempo.
$r = Pendientes::reloj(array_merge($base, [
    'via' => 'GARANTIA', 'estado' => 'GARANTIA_RECLAMADA', 'min_veredicto' => 10 * 60,
]));
afirmar('veredicto a las 10 h: a tiempo', clave($r, 'vencido'), false);
afirmar('garantia de semanas NO cuenta como incumplida', clave($r, 'cerrado'), true);

// El borde: 48 h exactas es a tiempo; 48 h 50 min ya no. Hasta la 009
// cumplimiento48() truncaba a horas y contaba 48:50 como a tiempo mientras la
// fila decia «se decidio a las 49 h» (P-02): la unidad es el minuto en los dos.
$r = Pendientes::reloj(array_merge($base, ['via' => 'REPUESTO', 'estado' => 'COTIZANDO', 'min_veredicto' => 48 * 60]));
afirmar('veredicto a las 48 h exactas: a tiempo', clave($r, 'vencido'), false);
$r = Pendientes::reloj(array_merge($base, ['via' => 'REPUESTO', 'estado' => 'COTIZANDO', 'min_veredicto' => 48 * 60 + 50]));
afirmar('veredicto a las 48 h 50 min: tarde', clave($r, 'vencido'), true);

// Con veredicto pero sin la medida de MySQL: no se inventa (I-7), devuelve null.
$r = Pendientes::reloj(array_merge($base, ['via' => 'REPUESTO', 'estado' => 'COTIZANDO']));
afirmar('con veredicto y sin min_veredicto: no se mide', $r, null);

// Sin veredicto, con la medida de MySQL: el reloj corre desde ahi.
$r = Pendientes::reloj(array_merge($base, ['min_plazo' => 60 * 60]));
afirmar('sin veredicto, 60 h medidas por MySQL: vencido', clave($r, 'vencido'), true);
afirmar('sin veredicto: el reloj sigue corriendo', clave($r, 'cerrado'), false);
afirmar('sin veredicto, 60 h: las horas cuadran', abs((float) clave($r, 'horas') - 60.0) < 0.1, true);

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
            'GARANTIA_NEGADA','BAJA_PROPUESTA','BAJA_APROBADA','RESUELTO','CANCELADO',
            // Los de la 009: la cadena real técnico -> jefe -> SAP -> Grupo KFC (D3).
            'SOLICITADO','VALIDADO_JEFE','REGISTRADO_SAP','ESPERA_KFC',
            'REPUESTO_ENVIADO','TALLER_INDUSTEC','OTRO_PROVEEDOR'];
foreach ($delEnum as $e) {
    afirmar("ENUM $e tiene etiqueta", isset(Pendientes::ESTADOS[$e]), true);
}
afirmar('ABIERTOS no incluye RESUELTO', in_array('RESUELTO', Pendientes::ABIERTOS, true), false);
afirmar('ABIERTOS no incluye CANCELADO', in_array('CANCELADO', Pendientes::ABIERTOS, true), false);
afirmar('ABIERTOS cubre todos los demas',
        count(Pendientes::ABIERTOS) === count($delEnum) - 2, true);
// Cada camino que deja la decisión de Grupo KFC termina en RESUELTO y arranca
// en un estado con etiqueta; OTRO_PROVEEDOR no tiene camino (se resuelve en
// el acto) y el técnico nunca ve «veredicto» a secas para lo que valida el jefe.
foreach (Pendientes::PASOS_KFC as $k => $camino) {
    afirmar("camino KFC $k termina en RESUELTO", end($camino), 'RESUELTO');
    afirmar("camino KFC $k arranca con etiqueta", isset(Pendientes::ESTADOS[$camino[0]]), true);
}
afirmar('OTRO_PROVEEDOR no tiene camino', isset(Pendientes::PASOS_KFC['OTRO_PROVEEDOR']), false);
afirmar('etiqueta de la decisión de KFC', Pendientes::etiquetaVeredictoKfc('TALLER_INDUSTEC'),
        Vocabulario::t(Vocabulario::deEstado('TALLER_INDUSTEC', 'decision_kfc')));
afirmar('decisión de KFC desconocida cae en «sin decisión»', Pendientes::etiquetaVeredictoKfc('XX'),
        Vocabulario::t(Vocabulario::deEstado('PENDIENTE', 'decision_kfc')));
afirmar('SOLICITADO se explica sin decir «veredicto»', str_contains(Pendientes::ayudaEstado('SOLICITADO'), 'veredicto'), false);

// Los textos que arma Pendientes para la solicitud hablan con el diccionario
// único (24-sep-2026): sin vía todavía es «por validar», el plazo vencido es
// «vencida (más de 48 h sin validar)» y la validación va en femenino, porque
// lo validado es la solicitud. Se compara contra el diccionario, no contra un
// literal, salvo para comprobar que no vuelve la forma retirada.
echo "\n=== Textos de la solicitud (vocabulario único) ===\n";
afirmar('sin vía todavía se lee «por validar»', Pendientes::etiquetaVia('SIN_VEREDICTO'), Vocabulario::t('POR_VALIDAR'));
$r = Pendientes::reloj(array_merge($base, ['via' => 'REPUESTO', 'estado' => 'VALIDADO_JEFE', 'min_veredicto' => 10 * 60]));
afirmar('validada a tiempo: el reloj lo dice en femenino',
        str_starts_with((string) clave($r, 'texto'), 'validada en'), true);
afirmar('  y no vuelve «validado»', (bool) preg_match('/\bvalidado\b/u', (string) clave($r, 'texto')), false);
$vencida = array_merge($base, ['min_plazo' => 60 * 60]);
$vencida['reloj'] = Pendientes::reloj($vencida);
afirmar('barra de 48 h vencida: el aria-label usa el término del diccionario',
        str_contains(Pendientes::barra48($vencida), Vocabulario::t('VENCIDO_48H')), true);
afirmar('  y no dice «Plazo de 48 horas vencido»',
        str_contains(Pendientes::barra48($vencida), 'horas vencido'), false);

echo "\n=== Los ocho estados del caso (Ui::ESTADOS) ===\n";
$enumCaso = ['NUEVO','ASIGNADO','EN_REVISION','RESUELTO','NO_COMPETE','ATENDIDO',
             'CERRADO_SIN_ATENCION','ESPERA_REPUESTO'];
foreach ($enumCaso as $e) {
    afirmar("$e tiene etiqueta", isset(Ui::ESTADOS[$e]), true);
    afirmar("  y no sale en crudo", Ui::etiquetaEstado($e) !== $e, true);
}
// El defecto original, comprobado al reves.
afirmar('ATENDIDO ya no sale en crudo', Ui::etiquetaEstado('ATENDIDO'),
        Vocabulario::t(Vocabulario::deEstado('ATENDIDO')));
afirmar('CERRADO_SIN_ATENCION ya no sale en crudo',
        Ui::etiquetaEstado('CERRADO_SIN_ATENCION'), Vocabulario::t(Vocabulario::deEstado('CERRADO_SIN_ATENCION')));

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
    // El nombre que se lee sale del diccionario (CNLJ se lee «CUENCA-LOJA»,
    // 24-sep-2026); la clase de color sigue siendo la del código de la base.
    afirmar("$z lleva su nombre en texto",
            str_contains(Ui::zona($z), '>' . Vocabulario::corto(Vocabulario::deEstado($z, 'zona')) . '<'), true);
    afirmar("$z lleva su clase de color", str_contains(Ui::zona($z), 'zona-' . strtolower($z)), true);
}
afirmar('sin zona: lo dice con palabras',
        str_contains(Ui::zona(null), '>' . Vocabulario::corto('SIN_ZONA') . '<'), true);
afirmar('zona desconocida no revienta', str_contains(Ui::zona('XXX'), 'zona-otra'), true);

echo "\n";
printf("%d comprobaciones · %d fallos\n", $total, $fallos);
exit($fallos > 0 ? 1 : 0);
