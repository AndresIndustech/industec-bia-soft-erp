<?php
declare(strict_types=1);

/**
 * Prueba de unidad: el catálogo de prueba del arnés (T2.28.1) solo se fusiona
 * para quien está probando, nunca para una cuenta real -- aunque las dos
 * corran por la misma vía (CLI, sin servidor web de por medio).
 *
 * NO TOCA LA BASE NI EL SERVIDOR. Simula la sesión con la misma
 * ReflectionProperty que ya usan `pruebas/servidor/alcance_cli.php`,
 * `verificar_cifras.py` y `verificar_automatizacion.py` contra
 * `Auth::$usuario`: no se inventó un segundo mecanismo de sesión para esta
 * prueba (criterio de aceptación de T2.28.1, que pide reusar "el acceso que
 * ya exista en nucleo/Auth.php").
 *
 * Escribe un `catalogos/casos_prueba.json` temporal (el mismo formato que deja
 * preparar_prueba.php: los avisos 99990021 y 99990022) y lo restaura al
 * salir, pase lo que pase: nunca deja el archivo puesto en un checkout local.
 *
 *   php pruebas/prueba_casos_prueba.php
 */

require_once __DIR__ . '/../publico/nucleo/Casos.php';

// Arranca la sesión ANTES del primer echo: si `Auth::iniciarCookie()` la
// arranca más tarde, con salida ya impresa, PHP avisa "headers already sent"
// -inofensivo bajo CLI (no hay cookie real que mandar), pero ruidoso.
Auth::iniciarCookie();

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

$archivo = __DIR__ . '/../publico/catalogos/casos_prueba.json';
$existia = is_file($archivo);
$antes   = $existia ? file_get_contents($archivo) : null;
// Pase lo que pase -incluida una comprobación que falle a mitad-, el archivo
// del checkout local queda exactamente como estaba: esta prueba no deja
// basura ni destruye un casos_prueba.json real que alguien tuviera puesto.
register_shutdown_function(static function () use ($archivo, $existia, $antes): void {
    if ($existia) { file_put_contents($archivo, $antes); } else { @unlink($archivo); }
});

file_put_contents($archivo, json_encode(['generado' => date('c'), 'datos' => [
    ['aviso' => '99990021', 'local' => 'G007EC', 'zona' => 'UIO', 'caso' => 'PRUEBA del arnés (T2.28.1)'],
    ['aviso' => '99990022', 'local' => 'G018EC', 'zona' => 'UIO', 'caso' => 'PRUEBA del arnés (T2.28.1)'],
]], JSON_UNESCAPED_UNICODE));

/**
 * Simula la sesión de este usuario (o su ausencia, con null) y limpia la
 * caché del catálogo: `Casos::catalogo()` memoiza en `self::$catalogo`, y sin
 * limpiarla la segunda simulación seguiría viendo lo que calculó la primera.
 */
function simular(?array $usuario): void
{
    $ru = new ReflectionProperty(Auth::class, 'usuario');
    $ru->setAccessible(true);
    $ru->setValue(null, $usuario);
    $rc = new ReflectionProperty(Casos::class, 'catalogo');
    $rc->setAccessible(true);
    $rc->setValue(null, null);
}

function trae99990021(): bool
{
    foreach (Casos::catalogo()['datos'] ?? [] as $c) {
        if (($c['aviso'] ?? '') === '99990021') { return true; }
    }
    return false;
}

echo "=== Sin sesión, por CLI (como corren preparar_prueba.php y compañía) ===\n";
simular(null);
afirmar('sin sesión, por CLI: trae el catálogo de prueba', trae99990021(), true);

echo "\n=== Con sesión de una cuenta REAL (isabel), aunque sea por la vía de CLI ===\n";
// Simula lo que haría `alcance_cli.php isabel`: una cuenta real, sin "_prueba"
// en el login. Aunque PHP_SAPI siga siendo 'cli', la sesión manda sobre el
// atajo de CLI -si no, alcance_cli.php le mentiría a cualquiera que lo corra
// para ver el alcance real de una persona.
simular(['usuario_id' => 501, 'usuario' => 'isabel', 'rol' => 'ADMIN', 'zona' => null, 'activo' => 1]);
afirmar('isabel NO ve el caso de prueba 99990021 en su buzón', trae99990021(), false);

echo "\n=== Con sesión de una cuenta DE PRUEBA (admin_prueba) ===\n";
simular(['usuario_id' => 999, 'usuario' => 'admin_prueba', 'rol' => 'ADMIN', 'zona' => null, 'activo' => 1]);
afirmar('admin_prueba SÍ ve el caso de prueba 99990021', trae99990021(), true);

echo "\n=== Un técnico de prueba también lo ve ===\n";
simular(['usuario_id' => 998, 'usuario' => 'tec_prueba_uio_a', 'rol' => 'TECNICO', 'zona' => 'UIO', 'activo' => 1]);
afirmar('tec_prueba_uio_a también lo ve', trae99990021(), true);

echo "\n=== Un jefe de zona real, otra vez que no ===\n";
simular(['usuario_id' => 502, 'usuario' => 'marco.taipe', 'rol' => 'JEFE_ZONA', 'zona' => 'UIO', 'activo' => 1]);
afirmar('un jefe de zona real tampoco lo ve', trae99990021(), false);

echo "\n=== El catálogo real sigue intacto ===\n";
simular(null);
$real = Casos::catalogo();
afirmar('el catálogo real no queda vacío por la fusión', count($real['datos'] ?? []) > 0, true);
$reales9999 = array_filter($real['datos'], static fn($c) => str_starts_with((string) ($c['aviso'] ?? ''), '9999')
                                                          && ($c['aviso'] ?? '') !== '99990021'
                                                          && ($c['aviso'] ?? '') !== '99990022');
afirmar('ningún aviso real 9999xxxx se pisó (no debería haber ninguno)', count($reales9999), 0);

printf("\n%d comprobaciones · %d fallos\n", $total, $fallos);
exit($fallos === 0 ? 0 : 1);
