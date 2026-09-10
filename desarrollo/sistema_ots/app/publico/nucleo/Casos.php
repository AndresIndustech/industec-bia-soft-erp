<?php
declare(strict_types=1);
require_once __DIR__ . '/Auth.php';

/**
 * Casos — de dónde salen los casos, y quién puede ver cuáles.
 *
 * POR QUE ESTA CLASE EXISTE
 * Cuatro pantallas necesitan lo mismo: el buzón, la asignación, las órdenes
 * emitidas y el visor de PDF. Si cada una arma su propia consulta, el día que
 * cambie el alcance por zona hay que acordarse de cambiarlo en cuatro sitios, y
 * el que se olvide filtra datos de otra zona. Aquí está una sola vez.
 *
 * TRES FUENTES, CADA UNA CON SU DUEÑO
 *   catalogos/casos_sap.json    lo que pidio KFC. La estacion lo REESCRIBE
 *                               entero en cada barrido: nada se guarda ahi.
 *   catalogos/atenciones.json   que se atendio ya, leido de los informes de OT.
 *                               Tambien lo reescribe la estacion. Temporal.
 *   tabla casos_gestion         lo que NOSOTROS decidimos. Esto si persiste.
 *
 * EL ALCANCE SE APLICA AQUI, NO AL DIBUJAR
 * `enAlcance()` se llama antes de contar y antes de mostrar. Un jefe de zona
 * nunca recibe una fila de otra zona, ni siquiera para descartarla despues.
 */
final class Casos
{
    private static ?array $catalogo = null;
    private static ?array $atenciones = null;

    private static function leerJson(string $nombre, string $clave): array
    {
        $candidatos = [
            __DIR__ . '/../catalogos/' . $nombre,
            __DIR__ . '/../../../../../SALIDAS IA/OTS/catalogos/' . $nombre,
        ];
        foreach ($candidatos as $c) {
            if (is_file($c)) {
                $j = json_decode((string) file_get_contents($c), true);
                if (is_array($j) && isset($j[$clave])) { return $j; }
            }
        }
        return [];
    }

    /** El catálogo completo, tal como lo dejó el último barrido. */
    public static function catalogo(): array
    {
        return self::$catalogo ??= self::leerJson('casos_sap.json', 'datos');
    }

    /** Qué se atendió ya, por aviso. Vacío si todavía no se ha cruzado. */
    public static function atenciones(): array
    {
        self::$atenciones ??= self::leerJson('atenciones.json', 'atenciones');
        return self::$atenciones['atenciones'] ?? [];
    }

    /**
     * Recorta a lo que esta persona puede ver.
     *
     * El técnico ve LO SUYO: los casos que tiene asignados, no los de su zona.
     * Se descubrió probando que veía los 300 de la zona, con el nombre del
     * usuario de KFC que abrió cada uno. `casos.ver` le sirve para consultar sus
     * órdenes, no el buzón entero.
     */
    public static function enAlcance(array $casos, array $gestion): array
    {
        $u = Auth::actual();
        if ($u === null) { return []; }

        if ($u['rol'] === 'TECNICO') {
            $mios = array_keys(array_filter(
                $gestion,
                fn($g) => (int) ($g['asignado_a'] ?? 0) === (int) $u['usuario_id']
            ));
            $mios = array_flip($mios);
            return array_values(array_filter($casos, fn($c) => isset($mios[$c['aviso'] ?? ''])));
        }

        $zona = Auth::zonaAlcance();
        if ($zona === null) { return $casos; }        // administración: las tres

        /* Un caso derivado sale de la zona vieja y entra a la nueva: manda lo
           que diga la gestión, no lo que trae el catálogo. Si no, el jefe que lo
           derivó lo seguiría viendo y el que lo recibió no lo vería nunca. */
        return array_values(array_filter($casos, function ($c) use ($zona, $gestion) {
            $z = $gestion[$c['aviso'] ?? '']['zona'] ?? ($c['zona'] ?? null);
            return $z === $zona;
        }));
    }

    /** La gestión de todos los casos, indexada por aviso. */
    public static function gestion(): array
    {
        $filas = Db::todos(
            'SELECT g.*, t.nombre AS tecnico_nombre, t.usuario AS tecnico_usuario,
                    a.nombre AS asignador_nombre, v.nombre AS veredicto_nombre,
                    r.nombre AS revision_nombre
               FROM casos_gestion g
               LEFT JOIN usuarios t ON t.usuario_id = g.asignado_a
               LEFT JOIN usuarios a ON a.usuario_id = g.asignado_por
               LEFT JOIN usuarios v ON v.usuario_id = g.veredicto_por
               LEFT JOIN usuarios r ON r.usuario_id = g.revision_por'
        );
        $out = [];
        foreach ($filas as $f) { $out[$f['aviso']] = $f; }
        return $out;
    }

    /**
     * Los técnicos a los que este usuario puede asignar.
     *
     * Un jefe de zona solo reparte entre los suyos. La administración ve las
     * tres. Se listan solo los activos: asignarle un caso a alguien que ya no
     * trabaja aquí es una orden que nadie va a atender.
     */
    public static function tecnicosAsignables(): array
    {
        $zona = Auth::zonaAlcance();
        $sql = "SELECT usuario_id, usuario, nombre, zona FROM usuarios
                 WHERE rol IN ('TECNICO','JEFE_ZONA') AND activo = 1";
        $par = [];
        if ($zona !== null) { $sql .= ' AND zona = ?'; $par[] = $zona; }
        $sql .= ' ORDER BY zona, nombre';
        return Db::todos($sql, $par);
    }

    /**
     * Asegura que exista la fila de gestión de un caso, y devuelve su estado.
     *
     * Se crea al primer toque, no al aparecer el caso: 917 filas vacías no
     * dicen nada y habría que mantenerlas sincronizadas con un catálogo que se
     * reescribe entero cada vez.
     */
    public static function asegurar(string $aviso, ?string $zona): void
    {
        Db::ejecutar(
            'INSERT INTO casos_gestion (aviso, zona) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE zona = COALESCE(zona, VALUES(zona))',
            [$aviso, $zona]
        );
    }

    /** ¿Existe ese aviso en el catálogo, y lo alcanza este usuario? */
    public static function alcanzaAviso(string $aviso, array $gestion): ?array
    {
        foreach (self::catalogo()['datos'] ?? [] as $c) {
            if (($c['aviso'] ?? '') === $aviso) {
                $vis = self::enAlcance([$c], $gestion);
                return $vis === [] ? null : $vis[0];
            }
        }
        return null;
    }

    /**
     * Etiqueta legible del estado.
     *
     * La lista completa vive en `Ui::ESTADOS` y esto delega ahí a propósito.
     * Cuando estaba duplicada aquí se quedó corta DOS veces: le faltaron
     * ATENDIDO y CERRADO_SIN_ATENCION al agregarlos al ENUM, y esos casos se
     * dibujaban en crudo —«CERRADO_SIN_ATENCION», en mayúsculas y con guion
     * bajo— en la pantalla que mira la administradora. Con una sola lista, el
     * estado nuevo que se olvide sale mal en un sitio y no en cinco.
     */
    public static function etiquetaEstado(?string $estado): string
    {
        require_once __DIR__ . '/Ui.php';
        return Ui::etiquetaEstado($estado);
    }
}
