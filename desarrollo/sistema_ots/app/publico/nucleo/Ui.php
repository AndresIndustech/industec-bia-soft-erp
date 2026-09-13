<?php
declare(strict_types=1);

require_once __DIR__ . '/Auth.php';

/**
 * Ui.php — El armazón de la aplicación: barra, navegación y distintivos.
 *
 * POR QUE EXISTE
 * Hasta el 2026-09-09 cada pantalla dibujaba su propia barra de arriba en un
 * <style> pegado al archivo. Seis copias. Consecuencias medidas:
 *
 *   - No se podía saltar de un módulo a otro. Para ir del buzón al cronograma
 *     había que volver al panel. La administradora hace ese recorrido decenas
 *     de veces al día.
 *   - Cada pantalla se veía distinta, y la gente preguntaba si era el mismo
 *     sistema.
 *   - Un arreglo en una barra no llegaba a las otras cinco.
 *
 * QUE NO HACE ESTA CLASE, Y ES IMPORTANTE
 * **No protege nada.** Dibujar u ocultar un enlace no es seguridad: el endpoint
 * sigue abierto para quien conozca la URL. La protección de verdad está en el
 * `Auth::exigir()` con el que empieza cada página y en el filtro de alcance de
 * `Casos::enAlcance()`, ambos en el servidor. Aquí solo se decide qué se
 * muestra, para no ofrecerle a nadie una puerta que le va a dar 403.
 *
 * LOS CONTADORES DE LA NAVEGACION
 * Cada módulo puede llevar su número de pendientes. Es lo que convierte una
 * barra de enlaces en un tablero: el jefe de zona ve que tiene 4 repuestos
 * atrasados sin entrar a mirarlos. Los calcula la página que llama, porque solo
 * ella sabe hacerlo barato; aquí se dibujan.
 */
final class Ui
{
    public const ROL = [
        'SUPERADMIN' => 'Superadministrador',
        'ADMIN'      => 'Administración',
        'JEFE_ZONA'  => 'Jefe de zona',
        'TECNICO'    => 'Técnico',
    ];

    /**
     * Los módulos, en el orden en que se navegan.
     *
     * Cada fila: [url, permiso, etiqueta, clave de contador, roles de respaldo].
     *
     * LOS ROLES DE RESPALDO son para los permisos que todavía no están en la
     * tabla `permisos` porque su migración no se ha aplicado. Sin esto, un
     * módulo nuevo queda invisible para todo el mundo hasta que alguien corra
     * el SQL en Hostinger, y no hay forma de probarlo antes. Cuando el permiso
     * existe, manda el permiso y el respaldo se ignora.
     */
    private const MODULOS = [
        ['panel.php',       null,                 'Inicio',      null,        null],
        ['mis.php',         'ots.crear',          'Mis órdenes', 'mis',       ['TECNICO']],
        ['casos.php',       'casos.ver',          'Buzón',       'casos',     null],
        ['asignacion.php',  'casos.asignar',      'Asignar',     'asignar',   null],
        ['pendientes.php',  'repuestos.ver',      'Repuestos y equipos', 'repuestos', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO']],
        /* `novedades_visita.php`, no `novedades.php`: ese nombre ya lo ocupa el
           extremo JSON que el buzon consulta cada 30 segundos. */
        ['novedades_visita.php', 'novedades.ver',  'Novedades',   'novedades', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO']],
        /* El Archivo: las órdenes de todas las zonas, en solo lectura, para los
           cuatro roles (decisión de Andrés del 2026-09-12, D1). El permiso puede
           ser una lista: basta con tener uno. */
        ['ordenes.php',     ['ots.archivo', 'ots.ver'], 'Archivo', null,     ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO']],
        ['cronograma.html', 'cronograma.ver',     'Preventivos', 'preventivo', null],
        ['reportes.php',    'reportes.ver',       'Reportes',    null,        null],
        ['documentos.php',  'documentos.ver',     'Aprendizaje', null,        ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO']],
        ['usuarios.php',    ['usuarios.gestionar', 'usuarios.operativos'], 'Usuarios', null, null],
        ['bitacora.php',    'bitacora.ver',       'Bitácora',    null,        ['SUPERADMIN', 'ADMIN']],
    ];

    /** Lo que ya está construido. El resto no se dibuja: un 404 no es un módulo. */
    private const LISTOS = [
        'panel.php', 'mis.php', 'casos.php', 'asignacion.php', 'pendientes.php',
        'novedades_visita.php', 'ordenes.php', 'cronograma.html', 'reportes.php',
        'usuarios.php', 'index.html', 'documentos.php', 'bitacora.php',
    ];

    /** El técnico usa su bandeja, no el buzón de escritorio: son otra cosa. */
    private const OCULTOS_TECNICO = ['casos.php', 'panel.php'];

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * ¿Existe este código de permiso en el catálogo?
     *
     * Se pregunta una sola vez por petición. Sirve para distinguir «este rol no
     * tiene el permiso» de «este permiso todavía no existe en la base», que son
     * dos cosas distintas y con la segunda hay que usar el rol de respaldo.
     */
    public static function permisoExiste(string $codigo): bool
    {
        static $cat = null;
        if ($cat === null) {
            $cat = [];
            try {
                foreach (Db::todos('SELECT codigo FROM permisos') as $f) {
                    $cat[$f['codigo']] = true;
                }
            } catch (Throwable $e) {
                $cat = [];        // sin base, se cae al respaldo por rol
            }
        }
        return isset($cat[$codigo]);
    }

    /**
     * ¿Este usuario puede abrir este módulo?
     *
     * @param string|string[]|null $permiso uno, o una lista de la que basta tener uno
     */
    public static function puedeModulo($permiso, ?array $rolesRespaldo, array $u): bool
    {
        if ($permiso === null) { return true; }
        $alguno = false;
        foreach ((array) $permiso as $p) {
            if (self::permisoExiste($p)) {
                $alguno = true;
                if (Auth::puede($p)) { return true; }
            }
        }
        if ($alguno) { return false; }        // el permiso existe y no lo tiene
        return $rolesRespaldo !== null && in_array($u['rol'], $rolesRespaldo, true);
    }

    /** Los módulos visibles para este usuario, ya resueltos. */
    public static function modulos(array $u): array
    {
        $out = [];
        foreach (self::MODULOS as [$url, $perm, $etiq, $clave, $respaldo]) {
            if (!in_array($url, self::LISTOS, true)) { continue; }
            if ($u['rol'] === 'TECNICO' && in_array($url, self::OCULTOS_TECNICO, true)) { continue; }
            if ($u['rol'] !== 'TECNICO' && $url === 'mis.php') { continue; }
            if (!self::puedeModulo($perm, $respaldo, $u)) { continue; }
            $out[] = ['url' => $url, 'etiqueta' => $etiq, 'clave' => $clave];
        }
        return $out;
    }

    /**
     * La cabecera completa: <head>, barra y navegación.
     *
     * @param string $activa  archivo de la pantalla actual, para subrayarlo
     * @param array  $cuentas ['casos' => 12, 'repuestos' => 3, ...]
     * @param array  $opts    'titulo', 'ancho' (tabla ancha), 'css' (hojas extra)
     */
    public static function cabecera(array $u, string $activa, array $cuentas = [], array $opts = []): void
    {
        $titulo = (string) ($opts['titulo'] ?? 'B.IA Soft ERP');
        $zona   = Auth::zonaAlcance();
        $extra  = (array) ($opts['css'] ?? []);
        $rol    = self::ROL[$u['rol']] ?? $u['rol'];

        echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">',
             '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">',
             '<meta name="theme-color" content="#0b4f8f">',
             // El token contra peticiones forjadas, para los POST que hace ui.js
             // por fetch (SEG-07). Los formularios llevan el suyo como campo oculto.
             '<meta name="csrf" content="', self::e(Auth::csrfToken()), '">',
             '<title>', self::e($titulo), ' · B.IA Soft ERP</title>',
             '<link rel="stylesheet" href="estilo.css">';
        foreach ($extra as $hoja) { echo '<link rel="stylesheet" href="', self::e($hoja), '">'; }
        echo '</head><body>';

        echo '<header class="app-barra"><div class="app-barra-fila">',
             '<a class="app-marca" href="panel.php"><span class="punto"></span>',
             'B.IA <span class="fino">Soft ERP</span></a>',
             '<span class="app-espacio"></span>',
             '<div class="app-yo">',
             '<span class="nom">', self::e($u['nombre']), '</span>',
             '<span class="chip siempre">', self::e($rol), '</span>',
             '<span class="chip">', $zona ? 'Zona ' . self::e($zona) : 'Las 3 zonas', '</span>',
             '<a class="btn sm" href="salir.php">Salir</a>',
             '</div></div>';

        $mods = self::modulos($u);
        if (count($mods) > 1) {
            echo '<nav class="app-nav" aria-label="Módulos"><div class="app-nav-fila">';
            foreach ($mods as $m) {
                $on = $m['url'] === $activa ? ' class="on"' : '';
                echo '<a href="', self::e($m['url']), '"', $on,
                     $m['url'] === $activa ? ' aria-current="page"' : '', '>',
                     self::e($m['etiqueta']);
                $n = $m['clave'] !== null ? ($cuentas[$m['clave']] ?? null) : null;
                if (is_array($n)) {
                    // ['n' => 4, 'tono' => 'urge'] — el tono lo decide la página,
                    // que es la que sabe si 4 es mucho o es lo normal.
                    if ((int) $n['n'] > 0) {
                        echo '<span class="cuenta ', self::e((string) ($n['tono'] ?? '')), '">',
                             (int) $n['n'], '</span>';
                    }
                } elseif (is_int($n) && $n > 0) {
                    echo '<span class="cuenta">', $n, '</span>';
                }
                echo '</a>';
            }
            echo '</div></nav>';
        }
        echo '</header>';
    }

    /**
     * El cierre: contenedor de avisos efímeros, el guion común y el flash.
     *
     * @param array $opts 'js' (guiones extra), 'novedades' (bool: vigilar el buzón)
     */
    public static function pie(array $opts = []): void
    {
        echo '<div id="toasts" role="status" aria-live="polite"></div>';

        if (!empty($opts['novedades'])) {
            echo '<div id="novedades" hidden><span class="vivo"></span>',
                 '<span id="nov-txt">El buzón se actualizó</span>',
                 '<button class="btn primary" type="button" id="nov-ver">Ver lo nuevo</button>',
                 '<button class="btn" type="button" id="nov-no" ',
                 'title="Se vuelve a avisar en el próximo cambio">Ahora no</button></div>';
        }

        echo '<script src="ui.js"></script>';
        foreach ((array) ($opts['js'] ?? []) as $g) {
            echo '<script src="', self::e($g), '"></script>';
        }

        // El flash de la acción anterior sale como aviso efímero: ya ocurrió, y
        // quien lo lee no tiene que hacer nada. Los errores NO: esos van como
        // aviso fijo dentro de la página, porque hay que leerlos y actuar.
        $f = $_SESSION['flash'] ?? null;
        if (!empty($f['ok'])) {
            // JSON_HEX_*: el texto va dentro de un <script>; sin ellos un «</script>»
            // o unas comillas dentro del mensaje rompen la página (SEG-22).
            echo '<script>UI.toast(', json_encode((string) $f['ok'],
                     JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
                 ', "ok");</script>';
            unset($_SESSION['flash']['ok']);
        }
        echo '</body></html>';
    }

    /** El error del flash, para pintarlo dentro de la página. Lo consume. */
    public static function errorFlash(): ?string
    {
        $e = $_SESSION['flash']['error'] ?? null;
        unset($_SESSION['flash']['error']);
        return $e !== null ? (string) $e : null;
    }

    /**
     * Un aviso contextual.
     *
     * @param string $tono ok | info | warn | err | neutro
     */
    public static function aviso(string $tono, string $html, bool $cerrable = false): string
    {
        $ic = ['ok' => '✓', 'info' => 'i', 'warn' => '!', 'err' => '✕', 'neutro' => '·'][$tono] ?? '·';
        $rol = $tono === 'err' ? 'alert' : 'status';
        return '<div class="aviso ' . self::e($tono) . '" role="' . $rol . '">'
             . '<span class="ic" aria-hidden="true">' . $ic . '</span>'
             . '<div class="cuerpo">' . $html . '</div>'
             . ($cerrable ? '<button class="cerrar" type="button" aria-label="Cerrar"'
                          . ' onclick="this.closest(\'.aviso\').remove()">&times;</button>' : '')
             . '</div>';
    }

    // ------------------------------------------------------------------
    //  Distintivos. Todos llevan la palabra además del color: el color solo
    //  nunca es la señal (hay daltónicos repartiendo trabajo).
    // ------------------------------------------------------------------

    /**
     * Los siete estados de `casos_gestion`, más el de espera de repuesto.
     *
     * ESTA ES LA UNICA LISTA COMPLETA del ciclo de vida, y por eso vive aquí y
     * no repetida en cada pantalla. `Casos::etiquetaEstado()` se quedó corta
     * dos veces —le faltaban ATENDIDO y CERRADO_SIN_ATENCION— y esos casos se
     * dibujaban en crudo, en mayúsculas y con guion bajo, delante del cliente.
     */
    public const ESTADOS = [
        'NUEVO'                => ['sin asignar',      'Llegó del correo de SAP y todavía no tiene técnico'],
        'ASIGNADO'             => ['asignado',         'Tiene técnico; se espera el informe de la orden'],
        'EN_REVISION'          => ['en revisión',      'El jefe de zona lo mandó a la administración con un motivo'],
        'ESPERA_REPUESTO'      => ['espera repuesto',  'El técnico fue, y el trabajo depende de un repuesto'],
        'ATENDIDO'             => ['atendido',         'INDUSTEC emitió la orden. Falta que la administración lo cierre en SAP'],
        'RESUELTO'             => ['resuelto',         'Cerrado por las dos partes'],
        'NO_COMPETE'           => ['no nos compete',   'La administración resolvió que no es trabajo de INDUSTEC'],
        'CERRADO_SIN_ATENCION' => ['sin atender',      'Pasó una semana sin ningún informe y se cerró; hay que regularizarlo ante KFC'],
    ];

    public static function etiquetaEstado(?string $est): string
    {
        $e = strtoupper((string) ($est ?: 'NUEVO'));
        return self::ESTADOS[$e][0] ?? strtolower(str_replace('_', ' ', $e));
    }

    public static function ayudaEstado(?string $est): string
    {
        $e = strtoupper((string) ($est ?: 'NUEVO'));
        return self::ESTADOS[$e][1] ?? '';
    }

    public static function estado(?string $est): string
    {
        $e = strtoupper((string) ($est ?: 'NUEVO'));
        return '<span class="est est-' . self::e(strtolower($e)) . '"'
             . ' title="' . self::e(self::ayudaEstado($e)) . '">'
             . self::e(self::etiquetaEstado($e)) . '</span>';
    }

    public static function zona(?string $z): string
    {
        $z = strtoupper(trim((string) $z));
        if ($z === '') {
            return '<span class="zona zona-otra" title="El nombre que manda SAP no calza con ningún local del maestro">sin zona</span>';
        }
        $cl = in_array($z, ['UIO', 'LARB', 'CNLJ'], true) ? strtolower($z) : 'otra';
        return '<span class="zona zona-' . $cl . '">' . self::e($z) . '</span>';
    }

    public static function prioridad(?string $p): string
    {
        $p = strtoupper(trim((string) $p));
        $cl = in_array($p, ['ALTA', 'MEDIA', 'BAJA'], true) ? strtolower($p) : 'sd';
        return '<span class="prio prio-' . $cl . '">' . self::e($p !== '' ? $p : 'S/D') . '</span>';
    }

    /** Días enteros desde una fecha `Y-m-d`. Null si la fecha no sirve. */
    public static function dias(?string $fecha): ?int
    {
        $f = substr(trim((string) $fecha), 0, 10);
        if ($f === '' || strtotime($f) === false) { return null; }
        return (int) floor((strtotime(date('Y-m-d')) - strtotime($f)) / 86400);
    }

    /**
     * La antigüedad como distintivo.
     *
     * Los tramos no son decorativos: a los 7 días sin informe `Reconciliar.php`
     * cierra el caso por falta de atención. El rojo aparece justo en ese borde,
     * para que se vea venir antes de que ocurra.
     */
    public static function edad(?string $fechaCreacion): string
    {
        $d = self::dias($fechaCreacion);
        if ($d === null) { return '<span class="edad" title="Sin fecha de creación">—</span>'; }
        if ($d <= 0)  { return '<span class="edad edad-hoy">hoy</span>'; }
        if ($d === 1) { return '<span class="edad edad-hoy">ayer</span>'; }
        if ($d <= 3)  { return '<span class="edad edad-3">' . $d . ' días</span>'; }
        if ($d <= 7)  { return '<span class="edad edad-7">' . $d . ' días</span>'; }
        return '<span class="edad edad-viejo" title="Pasada la semana, el caso se cierra por falta de atención">'
             . $d . ' días</span>';
    }

    /**
     * El reloj de 48 horas de los equipos deshabilitados.
     *
     * DE DONDE SALE ESE PLAZO: es la misión que fijó la gerencia — un equipo
     * parado en un local de KFC deja de facturar, así que se resuelve dentro de
     * las 48 horas. No es un plazo del sistema: es el compromiso del negocio, y
     * por eso se mide en horas y no en días como el resto.
     *
     * @return array{clase:string,texto:string,vencido:bool,horas:float}
     */
    public static function reloj48(?string $desde): array
    {
        $t = strtotime((string) $desde);
        if ($t === false) {
            return ['clase' => 'edad', 'texto' => 'sin fecha', 'vencido' => false, 'horas' => 0.0];
        }
        $h = (time() - $t) / 3600;
        if ($h >= 48) {
            $d = floor($h / 24);
            return ['clase' => 'edad edad-viejo',
                    'texto' => 'vencido hace ' . ($d >= 1 ? $d . ' día' . ($d == 1 ? '' : 's') : round($h - 48) . ' h'),
                    'vencido' => true, 'horas' => $h];
        }
        $faltan = 48 - $h;
        $cl = $faltan <= 12 ? 'edad edad-7' : ($faltan <= 24 ? 'edad edad-3' : 'edad edad-hoy');
        return ['clase' => $cl,
                'texto' => 'quedan ' . ($faltan >= 2 ? round($faltan) . ' h' : round($faltan * 60) . ' min'),
                'vencido' => false, 'horas' => $h];
    }

    /**
     * El color de cada estado para los gráficos.
     *
     * Vive junto a las etiquetas a propósito: si el anillo pinta «atendido» de
     * verde y el distintivo de la fila lo pinta de azul, quien mira cree que
     * son dos cosas distintas. Los tonos son los de la paleta ya validada.
     */
    public static function colorEstado(?string $est): string
    {
        return [
            'NUEVO' => '#94a3b8', 'ASIGNADO' => '#2a78d6', 'EN_REVISION' => '#eda100',
            'ESPERA_REPUESTO' => '#eb6834', 'ATENDIDO' => '#1baf7a', 'RESUELTO' => '#008300',
            'NO_COMPETE' => '#4a3aa7', 'CERRADO_SIN_ATENCION' => '#e34948',
        ][strtoupper((string) $est)] ?? '#94a3b8';
    }

    /** El color de cada zona. Validado contra TODOS los pares, no solo adyacentes. */
    public static function colorZona(?string $z): string
    {
        return ['UIO' => '#7c3aed', 'LARB' => '#0d9488', 'CNLJ' => '#ea580c'][strtoupper((string) $z)] ?? '#64748b';
    }

    /** El primer nombre, para saludar sin sonar a formulario. */
    public static function nombrePila(string $nombre): string
    {
        $p = preg_split('/\s+/', trim($nombre)) ?: [];
        return $p[0] ?? $nombre;
    }

    /* --- Búsqueda por coincidencia parcial -------------------------------
       El buscador de casos y de órdenes tiene que encontrar `OT-2466-V093-...`
       tecleando solo `2466`, o `v093`, o el aviso con o sin los ceros de SAP.
       Para eso, servidor y navegador reducen cada texto a la MISMA forma:
       minúsculas, sin tildes y sin nada que no sea letra o dígito. Así los
       guiones y los espacios dejan de estorbar.

       El gemelo en JavaScript es `Busqueda.normalizar()` en `busqueda.js`.
       Cambiar uno obliga a cambiar el otro; `prueba_contratos.mjs` lo vigila. */
    /* La tabla la GENERO un script en tiempo de desarrollo a partir de la misma
       descomposicion NFD que usa `Busqueda.normalizar()` en JavaScript, no a
       mano. Por eso los dos lados coinciden por construccion.

       POR QUE NO SE USA `Normalizer` EN EJECUCION
       Necesita la extension `intl`, y no esta garantizada en el PHP de
       Hostinger. Si faltara, la busqueda del lado servidor se comportaria
       distinto que la del navegador SOLO en el servidor — el peor modo de
       fallar: imposible de reproducir en la estacion. Con la tabla escrita, el
       resultado no depende de ninguna extension.

       QUE PASABA ANTES: la tabla estaba escrita a mano con 16 entradas y no
       cubria â ê î ô û ã õ ç. Esas letras no se transliteraban: las borraba el
       filtro final. `Sâo` daba `so` en el servidor y `sao` en el navegador,
       y `Francois` con cedilla daba `franois` y `francois`. El sintoma en la
       mesa de servicio era el peor posible: escribes y salen tres resultados;
       recargas, y salen otros. */
    private const SIN_TILDE = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i',
            'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o',
            'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u',
            'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
            'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'ď' => 'd', 'ē' => 'e',
            'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e', 'ĝ' => 'g', 'ğ' => 'g',
            'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i',
            'į' => 'i', 'i̇' => 'i', 'ĵ' => 'j', 'ķ' => 'k', 'ĺ' => 'l', 'ļ' => 'l',
            'ľ' => 'l', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ō' => 'o', 'ŏ' => 'o',
            'ő' => 'o', 'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r', 'ś' => 's', 'ŝ' => 's',
            'ş' => 's', 'š' => 's', 'ţ' => 't', 'ť' => 't', 'ũ' => 'u', 'ū' => 'u',
            'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u', 'ŵ' => 'w', 'ŷ' => 'y',
            'ź' => 'z', 'ż' => 'z', 'ž' => 'z', 'ơ' => 'o', 'ư' => 'u', 'ǎ' => 'a',
            'ǐ' => 'i', 'ǒ' => 'o', 'ǔ' => 'u', 'ǖ' => 'u', 'ǘ' => 'u', 'ǚ' => 'u',
            'ǜ' => 'u', 'ǟ' => 'a', 'ǡ' => 'a', 'ǧ' => 'g', 'ǩ' => 'k', 'ǫ' => 'o',
            'ǭ' => 'o', 'ǰ' => 'j', 'ǵ' => 'g', 'ǹ' => 'n', 'ǻ' => 'a', 'ȁ' => 'a',
            'ȃ' => 'a', 'ȅ' => 'e', 'ȇ' => 'e', 'ȉ' => 'i', 'ȋ' => 'i', 'ȍ' => 'o',
            'ȏ' => 'o', 'ȑ' => 'r', 'ȓ' => 'r', 'ȕ' => 'u', 'ȗ' => 'u', 'ș' => 's',
            'ț' => 't', 'ȟ' => 'h', 'ȧ' => 'a', 'ȩ' => 'e', 'ȫ' => 'o', 'ȭ' => 'o',
            'ȯ' => 'o', 'ȱ' => 'o', 'ȳ' => 'y',
    ];

    public static function normalizarBusqueda(?string $s): string
    {
        $s = mb_strtolower((string) $s, 'UTF-8');
        $s = strtr($s, self::SIN_TILDE);
        return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    }

    /** '000010352936' y '10352936' son el mismo aviso: se quita el cero inicial
        de cada grupo de dígitos para poder buscar cualquiera de las dos formas. */
    public static function busquedaSinCeros(string $s): string
    {
        return preg_replace('/0+(\d)/', '$1', $s) ?? $s;
    }

    /**
     * ¿El texto de esta fila contiene todas las palabras del término?
     *
     * `$campos` son los valores crudos de la fila (aviso, orden, local, equipo,
     * lo que sea). Se buscan como subcadena, no como palabra completa: por eso
     * `2466` encuentra la orden 2466.
     */
    public static function coincide(array $campos, string $termino): bool
    {
        $termino = trim($termino);
        if ($termino === '') {
            return true;
        }
        $heno  = self::normalizarBusqueda(implode(' ', $campos));
        $pajar = $heno . ' ' . self::busquedaSinCeros($heno);
        foreach (preg_split('/\s+/', $termino) ?: [] as $parte) {
            $p = self::normalizarBusqueda($parte);
            if ($p === '') {
                continue;
            }
            if (mb_strpos($pajar, $p) === false
                && mb_strpos($pajar, self::busquedaSinCeros($p)) === false) {
                return false;
            }
        }
        return true;
    }

    /** El texto que va en `data-b` de cada fila: lo mismo sobre lo que buscó el
        servidor, ya normalizado, para que el filtro en vivo del navegador dé el
        mismo resultado sin volver a pedir la página. */
    public static function claveFila(array $campos): string
    {
        return self::e(self::normalizarBusqueda(implode(' ', $campos)));
    }
}
