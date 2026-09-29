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
        self::fusionarCorreos($locales);
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
     * Superpone sobre el maestro el correo del local que la administración ya
     * APROBÓ en `locales_correo_propuesto` (T2.28.2, migración 013): el correo
     * que escribió un técnico no cambia el maestro por su cuenta con solo
     * proponerse (`envio.php`), únicamente cuando alguien con `correos.configurar`
     * lo aprueba en `correos.php`. Si la 013 no está aplicada o no hay nada
     * aprobado, `locales.json` sigue tal cual (try/catch, como fusionarPropuestos).
     */
    private static function fusionarCorreos(array &$locales): void
    {
        try {
            $filas = Db::todos(
                "SELECT local_codigo, correo FROM locales_correo_propuesto
                  WHERE campo = 'LOCAL' AND estado = 'APROBADO'"
            );
        } catch (Throwable $e) {
            return;
        }
        if ($filas === []) { return; }
        $porLocal = [];
        foreach ($filas as $f) { $porLocal[(string) $f['local_codigo']] = (string) $f['correo']; }
        foreach ($locales as &$l) {
            $cod = (string) ($l['codigo'] ?? '');
            if ($cod !== '' && isset($porLocal[$cod])) { $l['correo_local'] = $porLocal[$cod]; }
        }
        unset($l);
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
     * Como admins(), pero con el correo que cada administrador dejó en sus
     * órdenes: `{local: [{nombre, correo}]}`, hasta 8, el más reciente
     * primero. Va en una clave aparte (`admins_v2`) porque los celulares con
     * la app vieja en caché esperan `admins` como lista de textos.
     * Nunca devuelve un `@industec.me`: ese es el buzón de INDUSTEC, no el
     * del local (el error que dejaba el campo fijo en servicioalcliente@).
     */
    public static function adminsV2(): array
    {
        try {
            $filas = Db::todos(
                "SELECT local_codigo, nombre, correo FROM locales_admin
                  WHERE activo = 1 ORDER BY local_codigo, visto_ultimo DESC, veces DESC"
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($filas as $f) {
            $loc = (string) $f['local_codigo'];
            $out[$loc] = $out[$loc] ?? [];
            if (count($out[$loc]) >= 8) { continue; }
            $correo = strtolower(trim((string) ($f['correo'] ?? '')));
            if ($correo !== '' && (!filter_var($correo, FILTER_VALIDATE_EMAIL)
                                   || str_ends_with($correo, '@industec.me'))) {
                $correo = '';
            }
            $out[$loc][] = ['nombre' => (string) $f['nombre'], 'correo' => $correo !== '' ? $correo : null];
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

    /**
     * La ficha de cada equipo (T2.28.6, obs. 4): el último marca/modelo/serie
     * que quedó, por `equipo_clave` (equipo_sap real, o `PROPUESTO:<uuid>`
     * para uno todavía sin aprobar). `[]` si la 014 no está aplicada: el
     * formulario sigue funcionando sin prellenado, como cualquier añadido.
     */
    public static function fichas(): array
    {
        try {
            $filas = Db::todos(
                "SELECT ef.equipo_clave, ef.marca, ef.modelo, ef.serie, ef.sin_placa,
                        ef.actualizado_en, u.nombre AS actualizado_por_nombre
                   FROM equipos_ficha ef
                   LEFT JOIN usuarios u ON u.usuario_id = ef.actualizado_por"
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($filas as $f) {
            $out[(string) $f['equipo_clave']] = [
                'marca'     => $f['marca'],
                'modelo'    => $f['modelo'],
                'serie'     => $f['serie'],
                'sin_placa' => (bool) $f['sin_placa'],
                'en'        => $f['actualizado_en'],
                'por'       => $f['actualizado_por_nombre'],
            ];
        }
        return $out;
    }

    /**
     * Las marcas y modelos más frecuentes (T2.28.6), armados por
     * `t2_28_marcas.py` desde el histórico (`ot_equipos` y el inventario
     * 2023) y subidos como JSON, igual que los demás catálogos de archivo.
     * `[]` si el JSON todavía no se generó: el campo sigue siendo de texto
     * libre con sugerencias vacías, nunca bloquea al técnico.
     */
    public static function marcas(): array
    {
        return self::leerJsonCatalogo('marcas.json');
    }

    /** @return array<string,array<int,string>> {marca: [modelos más frecuentes]} */
    public static function modelos(): array
    {
        return self::leerJsonCatalogo('modelos.json');
    }

    private static function leerJsonCatalogo(string $archivo): array
    {
        $base = self::carpeta();
        if ($base === null) { return []; }
        $ruta = $base . '/' . $archivo;
        if (!is_file($ruta)) { return []; }
        $j = json_decode((string) file_get_contents($ruta), true);
        return is_array($j) ? (array) ($j['datos'] ?? $j) : [];
    }
}
