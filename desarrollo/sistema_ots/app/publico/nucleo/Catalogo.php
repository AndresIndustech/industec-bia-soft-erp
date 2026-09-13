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
        self::fusionarPropuestos($equipos);
        return [
            'locales'  => array_values($locales),
            'tecnicos' => array_values($tecnicos),
            // tipos_equipo.json guarda {tipo, activos}; las reglas esperan cadenas.
            'tipos'    => array_values(array_filter(array_map(
                static fn($t) => is_string($t) ? $t : (string) ($t['tipo'] ?? ''), $tipos))),
            'equipos'  => $equipos,
        ];
    }

    /**
     * Los equipos «Equipo nuevo / no está en la lista» que un técnico ya
     * propuso (H-10, D8), sumados al catálogo del local que les corresponde.
     *
     * Visibles para TODAS las zonas desde que se proponen, no solo cuando la
     * administración los aprueba: es lo que evita que dos técnicos de locales
     * distintos registren el mismo activo dos veces como «nuevo». Se marcan
     * `propuesto: true` para que la pantalla los distinga de un activo real de
     * SAP, y viajan con un `equipo_sap` sintético (`PROPUESTO:<uuid>`) para que
     * las reglas de validación los traten como «ya está en el catálogo del
     * local» sin necesitar una regla aparte.
     *
     * Si la migración 009 no está aplicada o la base no responde, se sigue con
     * el catálogo de archivo tal cual: esto es un añadido, no un requisito para
     * que el formulario funcione.
     */
    private static function fusionarPropuestos(array &$equipos): void
    {
        try {
            $filas = Db::todos(
                "SELECT equipo_uuid, local_codigo, tipo, area, activo_fijo
                   FROM equipos_propuestos WHERE estado IN ('PROPUESTO','APROBADO')"
            );
        } catch (Throwable $e) {
            return;
        }
        foreach ($filas as $f) {
            $loc = (string) $f['local_codigo'];
            if ($loc === '') { continue; }
            $equipos[$loc] = $equipos[$loc] ?? [];
            $equipos[$loc][] = [
                'equipo_sap'    => 'PROPUESTO:' . $f['equipo_uuid'],
                'codigo_activo' => $f['activo_fijo'] ?: null,
                'tipo'          => (string) $f['tipo'],
                'area'          => $f['area'] ?: 'Propuestos por técnicos',
                'propuesto'     => true,
            ];
        }
    }

    /**
     * El administrador que más recientemente firmó en cada local (H-08), de
     * los ya ingresados en `locales_admin` (009): hasta 5 por local, el más
     * reciente primero. `[]` si la tabla no existe todavía.
     */
    public static function admins(): array
    {
        try {
            $filas = Db::todos(
                "SELECT local_codigo, nombre FROM locales_admin
                  WHERE activo = 1 ORDER BY local_codigo, veces DESC, visto_ultimo DESC"
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($filas as $f) {
            $loc = (string) $f['local_codigo'];
            $out[$loc] = $out[$loc] ?? [];
            if (count($out[$loc]) < 5) { $out[$loc][] = $f['nombre']; }
        }
        return $out;
    }

    /**
     * Familias de equipo, diagnósticos pre-redactados y repuestos frecuentes
     * (H-11, D9), para el selector «Falla encontrada» del formulario. `[]` en
     * cada llave si la 009 no está aplicada: el formulario sigue funcionando
     * con el diagnóstico y los repuestos en texto libre (mejora progresiva).
     */
    public static function diagnosticos(): array
    {
        $vacio = ['familias' => [], 'diagnosticos' => [], 'repuestos' => []];
        try {
            $familias = Db::todos('SELECT familia, patron FROM familias_equipo WHERE activo = 1 ORDER BY orden');
            $diag = Db::todos(
                'SELECT codigo, familia, titulo, texto, partes_frecuentes
                   FROM diagnosticos WHERE activo = 1 ORDER BY familia, orden'
            );
            $rep = Db::todos(
                'SELECT codigo, familia, descripcion, numero_parte
                   FROM repuestos_frecuentes WHERE activo = 1 ORDER BY familia, orden'
            );
        } catch (Throwable $e) {
            return $vacio;
        }
        foreach ($diag as &$d) {
            $d['partes_frecuentes'] = json_decode((string) ($d['partes_frecuentes'] ?? '[]'), true) ?: [];
        }
        unset($d);
        return ['familias' => $familias, 'diagnosticos' => $diag, 'repuestos' => $rep];
    }
}
