<?php
declare(strict_types=1);

/**
 * Catalogo.php — Los catálogos del formulario, leídos en un solo lugar.
 *
 * envio.php los leía con otras claves y otros nombres de archivo que los que
 * genera t2_5_catalogos.py, y habría rechazado el 100 % de las órdenes en
 * cuanto se desplegara (auditoría del 2026-09-10). catalogos.php y envio.php
 * leen desde aquí para que no vuelvan a separarse.
 */
final class Catalogo
{
    /** La carpeta de los catálogos: junto a la app en el servidor, o SALIDAS IA en la estación. */
    public static function carpeta(): ?string
    {
        foreach ([__DIR__ . '/../catalogos', __DIR__ . '/../../../../../SALIDAS IA/OTS/catalogos'] as $c) {
            if (is_file($c . '/locales.json')) { return $c; }
        }
        return null;
    }

    /**
     * @return array{locales:array,tecnicos:array,tipos:string[],equipos:array}|null
     *         null si falta cualquiera de los cuatro: sin catálogo no se valida nada.
     */
    public static function cargar(): ?array
    {
        $base = self::carpeta();
        if ($base === null) { return null; }
        $leer = static function (string $archivo) use ($base): ?array {
            $ruta = $base . '/' . $archivo;
            if (!is_file($ruta)) { return null; }
            $j = json_decode((string) file_get_contents($ruta), true);
            return is_array($j) ? ($j['datos'] ?? $j) : null;
        };
        $locales  = $leer('locales.json');
        $tecnicos = $leer('tecnicos.json');
        $tipos    = $leer('tipos_equipo.json');
        $equipos  = $leer('equipos_por_local.json');
        if ($locales === null || $tecnicos === null || $tipos === null || $equipos === null) {
            return null;
        }
        return [
            'locales'  => array_values($locales),
            'tecnicos' => array_values($tecnicos),
            // tipos_equipo.json guarda {tipo, activos}; las reglas esperan cadenas.
            'tipos'    => array_values(array_filter(array_map(
                static fn($t) => is_string($t) ? $t : (string) ($t['tipo'] ?? ''), $tipos))),
            'equipos'  => $equipos,
        ];
    }
}
