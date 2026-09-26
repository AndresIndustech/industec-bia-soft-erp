<?php
declare(strict_types=1);
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Catalogo.php';
require_once __DIR__ . '/Emision.php';

/**
 * Destinatarios.php — A quién va cada correo, en un solo lugar (T2.28.2,
 * obs. 2 y 8 de la revisión con INDUSTEC; D-C, D-G, S-1).
 *
 * POR QUÉ EXISTE. Hasta la migración 013 los destinatarios de una orden
 * estaban fijos en tres sitios (T2_28_OBSERVACIONES_INDUSTEC.md §2): el
 * formulario, el maestro de locales y config.php. Cambiar una copia exigía
 * editar el servidor. Desde aquí, `Emision::encolar()` (la cola), `Emision::
 * html()` (el PDF) y la vista previa de `correos.php` llaman a la misma
 * función: no hay una segunda ruta por la que un correo pueda salir a otra
 * lista de gente.
 *
 * EL CORREO DEL LOCAL NO SE DUPLICA AQUÍ. Ya existe la regla —el que escribió
 * el técnico, si es válido y no es un buzón de INDUSTEC; si no, el del
 * maestro— en `Emision::correoLocal()`. Escribirla de nuevo habría dejado
 * divergir la próxima vez que alguien tocara una sola de las dos (por eso el
 * `require_once` de Emision.php: aunque Emision.php también requiere este
 * archivo para `encolar()`, PHP marca cada archivo como incluido antes de
 * ejecutarlo, así que el ciclo no repite nada ni falla).
 *
 * SIN LA 013, O CON LA TABLA VACÍA: se hace exactamente lo de antes (el
 * correo del local + `correo_jefe_op` del maestro + `correo_por_zona` y
 * `correo_fijos` de config.php), para que una orden nunca se quede sin
 * destinatarios porque la migración no llegó a tiempo.
 */
final class Destinatarios
{
    /**
     * @return array{para:string[], cc:string[], detalle:array<int,array{correo:string,tipo:string,origen:string}>}
     */
    public static function resolver(string $uso, string $zona, ?string $local, ?string $cadena, ?string $correoLocalOrden): array
    {
        return self::resolverConFilas(self::filas($uso), $zona, $local, $cadena, $correoLocalOrden, self::localFila($local));
    }

    /**
     * La lógica pura, sin tocar la base: recibe las filas ya leídas (o null si
     * la 013 no está aplicada o no hay ninguna para ese `uso`) y decide. Separada
     * de `resolver()` para poder probarla sin MySQL, como el resto de las
     * pruebas locales de este proyecto (prueba_continuidad.php, «NO TOCA LA BASE»).
     *
     * @param array<int,array<string,mixed>>|null $filas
     * @return array{para:string[], cc:string[], detalle:array<int,array{correo:string,tipo:string,origen:string}>}
     */
    public static function resolverConFilas(?array $filas, string $zona, ?string $local, ?string $cadena,
                                             ?string $correoLocalOrden, array $localFila): array
    {
        $para = [];
        $cc = [];
        $detalle = [];
        $agregar = static function (string $correo, string $tipo, string $origen) use (&$para, &$cc, &$detalle): void {
            $correo = strtolower(trim($correo));
            if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) { return; }
            if (in_array($correo, $para, true) || in_array($correo, $cc, true)) { return; }
            if ($tipo === 'PARA') { $para[] = $correo; } else { $cc[] = $correo; }
            $detalle[] = ['correo' => $correo, 'tipo' => $tipo, 'origen' => $origen];
        };

        // El correo del local: el que escribió el técnico si es válido, si no
        // el del maestro. La regla vive en Emision::correoLocal(), no aquí.
        $correoLocal = Emision::correoLocal(['correo_local' => $correoLocalOrden], $localFila);
        if ($correoLocal !== '') {
            $agregar($correoLocal, 'PARA', $correoLocalOrden !== null && strtolower(trim($correoLocalOrden)) === $correoLocal
                ? 'correo del local (de la orden)' : 'correo del local (del maestro)');
        }

        if ($filas === null || $filas === []) {
            // Respaldo: la 013 no está aplicada o no tiene ninguna fila para este
            // uso. Se hace lo de siempre (maestro + config.php), para no dejar
            // una orden sin nadie mientras la administración carga la tabla.
            if ((string) ($localFila['correo_jefe_op'] ?? '') !== '') {
                $agregar((string) $localFila['correo_jefe_op'], 'COPIA', 'jefe de zona (correo_jefe_op del maestro)');
            }
            $cfg = Db::config();
            foreach ((array) ($cfg['correo_por_zona'][$zona] ?? []) as $m) {
                $agregar((string) $m, 'COPIA', 'correo_por_zona (config.php)');
            }
            foreach ((array) ($cfg['correo_fijos'] ?? []) as $m) {
                $agregar((string) $m, 'COPIA', 'correo_fijos (config.php)');
            }
            return ['para' => $para, 'cc' => $cc, 'detalle' => $detalle];
        }

        foreach ($filas as $f) {
            if (!self::calza($f, $zona, $local, $cadena)) { continue; }
            $agregar((string) $f['correo'], (string) $f['tipo'], self::origen($f));
        }

        return ['para' => $para, 'cc' => $cc, 'detalle' => $detalle];
    }

    /**
     * El correo configurado como JEFE_OPERACIONES para este local (el contacto
     * de Grupo KFC), o null si no hay ninguno activo o la 013 no está. Lo usa
     * Emision::html() para la línea «Correo de Jefe de Operaciones Local»: es
     * un dato aparte del buzón de zona de INDUSTEC (JEFE_ZONA), que hasta esta
     * migración el maestro mezclaba bajo el mismo nombre (D-G).
     */
    public static function jefeOperaciones(?string $local, ?string $cadena): ?string
    {
        if ($local === null || $local === '') { return null; }
        try {
            $f = Db::uno(
                "SELECT correo FROM correo_destinatarios
                  WHERE uso = 'ORDEN' AND rol = 'JEFE_OPERACIONES' AND ambito = 'LOCAL'
                    AND local_codigo = ? AND activo = 1 AND (cadena IS NULL OR cadena = ?)
                  ORDER BY (cadena IS NOT NULL) DESC LIMIT 1",
                [$local, $cadena]
            );
        } catch (Throwable $e) {
            return null;      // la 013 no está aplicada
        }
        $correo = strtolower(trim((string) ($f['correo'] ?? '')));
        return $correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : null;
    }

    /**
     * @return array<int,array<string,mixed>>|null null si la 013 no está o no hay filas para ese uso
     *
     * Pública (T2.28.3): catalogos.php lee estas filas UNA sola vez y se las
     * pasa a copiasPorLocal() para los 100 locales, en vez de dejar que cada
     * local dispare su propia consulta.
     */
    public static function filas(string $uso): ?array
    {
        try {
            $filas = Db::todos('SELECT * FROM correo_destinatarios WHERE uso = ? AND activo = 1', [$uso]);
        } catch (Throwable $e) {
            return null;
        }
        return $filas === [] ? null : $filas;
    }

    /**
     * Solo las COPIAS que le llegan a una orden de este local -el jefe de
     * zona, el jefe de operaciones de KFC si ya está configurado, y cualquier
     * otra copia general o de zona-, para la línea «también se enviará a»
     * del formulario (T2.28.3, obs. 2).
     *
     * NO llama a resolver(): resolver() recalcula el «para» con
     * Emision::correoLocal(), que a su vez llama a localFila() y esa relee
     * TODO el catálogo (4 JSON + 2 consultas) por cada llamada. Aquí no hace
     * falta el «para» -el técnico ya ve y edita el correo del local en su
     * propio campo-, así que catalogos.php pasa $filas (una sola lectura de
     * `correo_destinatarios`) y la fila de ESTE local, que ya tiene cargada
     * del propio locales.json: se sirven los 100 locales de una sola vez sin
     * repetir 100 veces la carga completa del catálogo.
     *
     * Marca `jefe_zona` en cada dirección porque el texto de la línea nombra
     * al jefe de zona aparte («jefe de zona de INDUSTEC y N copias…»): así el
     * cliente arma esa frase sin adivinar cuál de las direcciones es cuál.
     *
     * @param array<int,array<string,mixed>>|null $filas de self::filas('ORDEN')
     * @param array<string,mixed> $localFila la fila de este local en locales.json
     * @return array<int,array{correo:string,jefe_zona:bool}>
     */
    public static function copiasPorLocal(?array $filas, array $localFila, string $zona, ?string $local, ?string $cadena): array
    {
        $out = [];
        $vistos = [];
        $agregar = static function (string $correo, bool $jefeZona) use (&$out, &$vistos): void {
            $correo = strtolower(trim($correo));
            if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) { return; }
            if (isset($vistos[$correo])) { return; }
            $vistos[$correo] = true;
            $out[] = ['correo' => $correo, 'jefe_zona' => $jefeZona];
        };
        if ($filas === null || $filas === []) {
            // Respaldo (sin la 013, o sin filas activas): lo de siempre, con
            // lo que YA tiene cargado el llamador -sin releer el catálogo-.
            // Antes de sembrar (T2.28.2), `correo_jefe_op` del maestro ERA el
            // buzón de zona: se marca jefe_zona=true por la misma razón.
            if ((string) ($localFila['correo_jefe_op'] ?? '') !== '') {
                $agregar((string) $localFila['correo_jefe_op'], true);
            }
            $cfg = Db::config();
            foreach ((array) ($cfg['correo_por_zona'][$zona] ?? []) as $m) { $agregar((string) $m, false); }
            foreach ((array) ($cfg['correo_fijos'] ?? []) as $m) { $agregar((string) $m, false); }
            return $out;
        }
        foreach ($filas as $f) {
            if ((string) $f['tipo'] !== 'COPIA') { continue; }
            if (!self::calza($f, $zona, $local, $cadena)) { continue; }
            $agregar((string) $f['correo'], (string) $f['rol'] === 'JEFE_ZONA');
        }
        return $out;
    }

    private static function localFila(?string $codigo): array
    {
        if ($codigo === null || $codigo === '') { return []; }
        $cat = Catalogo::cargar();
        foreach ((array) ($cat['locales'] ?? []) as $l) {
            if (($l['codigo'] ?? null) === $codigo) { return $l; }
        }
        return [];
    }

    /** ¿Esta fila de correo_destinatarios corresponde a esta orden? */
    private static function calza(array $f, string $zona, ?string $local, ?string $cadena): bool
    {
        if ((int) $f['activo'] !== 1) { return false; }
        // En todos los ámbitos, la cadena debe ser NULL (aplica a todas) o
        // igual a la de la orden.
        if ($f['cadena'] !== null && (string) $f['cadena'] !== (string) $cadena) { return false; }
        return match ((string) $f['ambito']) {
            'GENERAL' => true,
            'ZONA'    => (string) $f['zona'] === $zona,
            'LOCAL'   => $local !== null && (string) $f['local_codigo'] === $local,
            default   => false,
        };
    }

    /** El texto que ve la vista previa de correos.php: de qué fila salió cada dirección. */
    private static function origen(array $f): string
    {
        $ambito = match ((string) $f['ambito']) {
            'GENERAL' => 'general',
            'ZONA'    => 'zona ' . $f['zona'],
            'LOCAL'   => 'local ' . $f['local_codigo'],
            default   => (string) $f['ambito'],
        };
        $rol = (string) $f['rol'];
        $etiqueta = match ($rol) {
            'JEFE_ZONA'       => 'buzón del jefe de zona',
            'JEFE_OPERACIONES' => 'jefe de operaciones (' . strtolower((string) $f['destino']) . ')',
            default           => (string) ($f['nombre'] ?: strtolower((string) $f['destino'])),
        };
        return $etiqueta . ' · ' . $ambito;
    }
}
