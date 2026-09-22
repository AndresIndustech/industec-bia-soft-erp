<?php
declare(strict_types=1);

/**
 * Prueba de la continuidad entre casos del mismo equipo (T2.25.1).
 *
 * NO TOCA LA BASE. Ejercita la lógica pura contra el catálogo real del buzón
 * (`publico/catalogos/casos_sap.json`), que es lo que ve el técnico, pasándole
 * la gestión a mano. Se hace así por la misma razón que `prueba_48h.php`: esto
 * decide si un caso se le cierra o no a Grupo KFC, y tiene que poder
 * comprobarse sin levantar MySQL ni depender del servidor.
 *
 * El caso que da nombre a la tarea está aquí como prueba fija: el horno
 * HORNO-S/M-2023-118 de G006EC, con sus cuatro avisos.
 *
 *   php pruebas/prueba_continuidad.php
 */

require_once __DIR__ . '/../publico/nucleo/Casos.php';

$fallos = 0;
$total  = 0;

function afirmar(string $que, $real, $esperado): void
{
    global $fallos, $total;
    $total++;
    $bien = $real === $esperado;
    if (!$bien) { $fallos++; }
    printf("  %-62s %-18s %s\n", $que,
           is_bool($real) ? ($real ? 'true' : 'false')
                          : ($real === null ? 'null' : (is_scalar($real) ? (string) $real : gettype($real))),
           $bien ? 'ok' : 'FALLA (esperaba ' . var_export($esperado, true) . ')');
}

/** El caso tal como sale del catálogo. Si no está, la prueba lo dice y no finge (I-7). */
function delCatalogo(string $aviso): ?array
{
    foreach (Casos::catalogo()['datos'] ?? [] as $c) {
        if ((string) ($c['aviso'] ?? '') === $aviso) { return $c; }
    }
    return null;
}

/** Los avisos que propone, en orden, como texto separado por comas. */
function propuestos(array $caso, array $gestion): string
{
    return implode(',', array_column(Casos::continuidadPosible($caso, $gestion, []), 'aviso'));
}

echo "=== El catálogo está donde se espera ===\n";
$datos = Casos::catalogo()['datos'] ?? [];
afirmar('casos_sap.json se pudo leer', count($datos) > 0, true);
if (!$datos) {
    echo "\nSin catálogo no hay nada que comprobar. Corre esto desde app/ con los\n"
       . "catálogos en publico/catalogos/.\n";
    exit(1);
}

/* -------------------------------------------------------------------------
   1. LA CADENA DEL HORNO DE G006EC.
   Cuatro avisos por el mismo problema: 10342524 (18-jul, «se bajó la presión
   y no tiene temperatura»), 10342924 (20-jul, «su ayuda con EL REPUESTO del
   horno» — o sea que el técnico ya fue), 10343636 (23-jul) y 10349666 (20-ago).
   ------------------------------------------------------------------------- */
echo "\n=== El horno HORNO-S/M-2023-118 de G006EC, el caso que destapó la tarea ===\n";

$c20jul = delCatalogo('10342924');
afirmar('el caso de la captura sigue en el catálogo', $c20jul !== null, true);
$sinGestion = [];

if ($c20jul !== null) {
    $cand = Casos::continuidadPosible($c20jul, $sinGestion, []);
    afirmar('10342924 propone exactamente un trabajo anterior', count($cand), 1);
    afirmar('  y es 10342524, el del 18-jul', $cand[0]['aviso'] ?? null, '10342524');
    afirmar('  a dos días de distancia', $cand[0]['dias'] ?? null, 2);
    afirmar('  con el texto que escribió KFC, para poder decidir',
            str_contains(mb_strtoupper($cand[0]['pedido'] ?? ''), 'PRESION'), true);
    afirmar('  y sin orden, porque ese caso no alcanzó a emitir una',
            $cand[0]['ot'] ?? null, null);
}

$c23jul = delCatalogo('10343636');
if ($c23jul !== null) {
    // Los dos anteriores, el más cercano primero. Es el orden en que se le
    // ofrecen: arriba el que con más probabilidad es el mismo trabajo.
    afirmar('10343636 propone los dos anteriores, el más cercano primero',
            propuestos($c23jul, $sinGestion), '10342924,10342524');
}

$c20ago = delCatalogo('10349666');
if ($c20ago !== null) {
    // 10343636 queda a 28 días (entra); 10342924 a 31 y 10342524 a 33 (fuera).
    // Es justo el borde de la ventana, y por eso está en la prueba.
    afirmar('10349666 solo propone lo que cae dentro de los 30 días',
            propuestos($c20ago, $sinGestion), '10343636');
}

/* -------------------------------------------------------------------------
   2. LO QUE NO DEBE PROPONER. Un falso positivo aquí no es un detalle: es un
   caso cerrado ante Grupo KFC que nadie atendió (I-7).
   ------------------------------------------------------------------------- */
echo "\n=== Lo que NO propone ===\n";

if ($c20jul !== null) {
    $cand = Casos::continuidadPosible($c20jul, $sinGestion, []);
    $avisos = array_column($cand, 'aviso');
    // G006EC tiene freidoras con casos en la misma ventana (10349670, 10345947,
    // 10335014): mismo local, otro equipo. No son el mismo trabajo.
    afirmar('un equipo distinto del mismo local no entra',
            in_array('10349670', $avisos, true) || in_array('10345947', $avisos, true), false);
    // Los candidatos van siempre hacia atrás: un caso no continúa a uno futuro.
    $futuro = array_filter($cand, fn($x) => $x['fecha'] > ($c20jul['fecha_creacion'] ?? ''));
    afirmar('nunca propone un caso posterior', count($futuro), 0);
}

// Un caso sin equipo identificado no tiene con qué compararse, y no se inventa.
$sinEquipo = ['aviso' => '99999999', 'local' => 'G006EC', 'activo_fijo' => '', 'fecha_creacion' => '2026-07-20'];
afirmar('sin activo fijo no propone nada', count(Casos::continuidadPosible($sinEquipo, $sinGestion, [])), 0);
$sinLocal = ['aviso' => '99999999', 'local' => '', 'activo_fijo' => 'HORNO-S/M-2023-118', 'fecha_creacion' => '2026-07-20'];
afirmar('sin local no propone nada', count(Casos::continuidadPosible($sinLocal, $sinGestion, [])), 0);
$sinFecha = ['aviso' => '99999999', 'local' => 'G006EC', 'activo_fijo' => 'HORNO-S/M-2023-118', 'fecha_creacion' => ''];
afirmar('sin fecha no propone nada', count(Casos::continuidadPosible($sinFecha, $sinGestion, [])), 0);

/* -------------------------------------------------------------------------
   3. EL EQUIPO SE COMPARA NORMALIZADO, PERO NO DE MAS.
   El guion separa denominación, modelo y serie: quitarlo juntaría equipos
   distintos del mismo local.
   ------------------------------------------------------------------------- */
echo "\n=== Cómo se compara el equipo ===\n";
afirmar('mayúsculas y espacios de sobra no cambian nada',
        Casos::equipoNormalizado('  freidora-sr  142gp-2205ma0180 '), 'FREIDORA-SR 142GP-2205MA0180');
afirmar('los guiones de relleno del equipo sin serie se caen',
        Casos::equipoNormalizado('CAMARA DE REFRIGERACION--'), 'CAMARA DE REFRIGERACION');
afirmar('dos modelos distintos NO se confunden',
        Casos::equipoNormalizado('FREIDORA-SR 142GP-2205MA0180')
            === Casos::equipoNormalizado('FREIDORA-OFG-H322L-2308448*'), false);
afirmar('vacío es vacío, no un equipo', Casos::equipoNormalizado(null), '');

/* -------------------------------------------------------------------------
   4. LA CADENA SE GUARDA PLANA Y NO ADMITE CICLOS.
   Aquí la gestión es sintética: es la única forma de ejercitar una fila
   escrita a mano en la base, que es de donde puede salir un ciclo.
   ------------------------------------------------------------------------- */
echo "\n=== La raíz, la cadena y los ciclos ===\n";

$g = [
    '10342524' => ['estado' => 'ATENDIDO', 'continua_de' => null, 'ot_cierre' => 'OT-1564-G006-10342524-UIO'],
    '10342924' => ['estado' => 'ATENDIDO', 'continua_de' => '10342524'],
    '10343636' => ['estado' => 'ASIGNADO', 'continua_de' => '10342524'],
    '10349666' => ['estado' => 'NUEVO',    'continua_de' => null],
];
afirmar('la raíz de un caso enlazado es el primero de la cadena',
        Casos::raiz('10342924', $g), '10342524');
afirmar('la raíz de un caso suelto es él mismo', Casos::raiz('10349666', $g), '10349666');
afirmar('la raíz de un aviso que ni existe es él mismo', Casos::raiz('00000000', $g), '00000000');

$cad = Casos::cadena('10342924', $g);
sort($cad);
afirmar('la cadena trae la raíz y los hermanos, sin repetir',
        implode(',', $cad), '10342524,10342924,10343636');
afirmar('la cadena de un caso suelto es él solo',
        implode(',', Casos::cadena('10349666', $g)), '10349666');
afirmar('un aviso vacío no arma cadena', count(Casos::cadena('', $g)), 0);

// El ciclo: dos filas que se apuntan. No puede pasar por la aplicación
// —`motivoRechazoEnlace` lo corta—, pero sí escribiendo la base a mano, y
// entonces `raiz()` tiene que salir igual en vez de colgar la pantalla.
$ciclo = [
    'A' => ['continua_de' => 'B'],
    'B' => ['continua_de' => 'A'],
];
afirmar('un ciclo escrito a mano no cuelga: se corta y devuelve algo',
        in_array(Casos::raiz('A', $ciclo), ['A', 'B'], true), true);

echo "\n=== La regla que impide crear el ciclo ===\n";
afirmar('enlazar un caso consigo mismo se rechaza',
        Casos::motivoRechazoEnlace('10342924', '10342924', $g) !== null, true);
afirmar('enlazar al revés (el viejo al nuevo) se rechaza',
        Casos::motivoRechazoEnlace('10342524', '10342924', $g) !== null, true);
afirmar('  y el mensaje dice cuál es el orden bueno',
        str_contains((string) Casos::motivoRechazoEnlace('10342524', '10342924', $g), 'al revés'), true);
afirmar('un caso ya enlazado no se vuelve a enlazar',
        Casos::motivoRechazoEnlace('10342924', '10349666', $g) !== null, true);
afirmar('un enlace legítimo NO se rechaza',
        Casos::motivoRechazoEnlace('10349666', '10342524', $g), null);
afirmar('sin origen se rechaza', Casos::motivoRechazoEnlace('10349666', '', $g) !== null, true);

/* -------------------------------------------------------------------------
   5. LO QUE YA ESTA ENLAZADO NO SE VUELVE A OFRECER, Y LA ORDEN DE LA CADENA
      SI SE OFRECE. Es lo que evita que el técnico enlace dos veces lo mismo.
   ------------------------------------------------------------------------- */
echo "\n=== Con la cadena ya armada ===\n";
if ($c23jul !== null) {
    // 10342924 ya cuelga de 10342524: se ofrece la raíz, no el intermedio.
    afirmar('un caso que ya está en la cadena no se ofrece; sí su raíz',
            propuestos($c23jul, $g), '10342524');
    $cand = Casos::continuidadPosible($c23jul, $g, []);
    afirmar('  y viene con la orden que ya cubre ese trabajo',
            $cand[0]['ot'] ?? null, 'OT-1564-G006-10342524-UIO');
}

/* -------------------------------------------------------------------------
   6. LA PRUEBA NEGATIVA QUE MAS IMPORTA: el fenómeno es frecuente, así que
      esto tiene que seguir siendo una PROPUESTA y no un cierre automático.
      Se comprueba que la función solo devuelve datos y no escribe nada.
   ------------------------------------------------------------------------- */
echo "\n=== Propone, no decide ===\n";
$conCandidatos = 0;
foreach ($datos as $c) {
    if (Casos::continuidadPosible($c, $sinGestion, [])) { $conCandidatos++; }
}
afirmar('hay casos con trabajo anterior del mismo equipo en la ventana viva',
        $conCandidatos > 0, true);
printf("  (%d de %d casos del catálogo tienen al menos un candidato a %d días)\n",
       $conCandidatos, count($datos), Casos::CONTINUIDAD_DIAS);
afirmar('ninguno queda enlazado por el solo hecho de consultarlos',
        count(array_filter($sinGestion, fn($x) => !empty($x['continua_de']))), 0);

printf("\n%d comprobaciones · %d fallos\n", $total, $fallos);
exit($fallos === 0 ? 0 : 1);
