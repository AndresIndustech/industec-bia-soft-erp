<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * Auth.php — Ingreso, permisos, alcance por zona y sesión única.
 *
 * TRES REGLAS QUE ORDENAN TODO ESTO
 *
 * 1. EL PERMISO SE COMPRUEBA EN EL SERVIDOR, SIEMPRE.
 *    Esconder un botón no protege nada: el endpoint sigue abierto para quien
 *    conozca la URL. Es el mismo error por el que hoy un GET a submit.php genera
 *    una OT en blanco y la manda a KFC — pasó 87 veces. Por eso cada página
 *    empieza con Auth::exigir(...) y no con un `if` sobre el menú.
 *
 * 2. EL ALCANCE NO ES UN PERMISO, ES UN FILTRO DE FILAS.
 *    "Puede asignar" es un permiso. "Sobre qué casos" es el alcance, y sale de
 *    la zona del usuario. Un jefe de zona con `casos.asignar` solo puede tocar
 *    los de SU zona; no ve las otras ni en lectura (decisión de Andrés,
 *    2026-09-08). Mezclar las dos cosas obliga a inventar un permiso por zona.
 *
 * 3. UNA SOLA SESIÓN POR USUARIO.
 *    El token vive en la fila del usuario. Entrar desde otro equipo sobrescribe
 *    la columna, y la sesión anterior queda inválida sola porque su token deja
 *    de coincidir. No hace falta borrar filas ni resolver carreras.
 */
final class Auth
{
    /** Minutos sin actividad antes de cerrar la sesión. Los portátiles se prestan. */
    private const INACTIVIDAD_MIN = 120;
    private const MAX_INTENTOS    = 5;
    private const BLOQUEO_MIN     = 15;
    /** Intentos rechazados desde una misma conexión en 15 minutos antes de cortarla. */
    private const TOPE_POR_IP     = 20;

    /* Un solo mensaje para usuario inexistente, clave mala y cuenta bloqueada:
       distinguirlos le decía a quien prueba qué cuentas existen. */
    private const MSG_FALLO = 'Usuario o contraseña incorrectos. Tras varios intentos fallidos el ingreso se bloquea 15 minutos.';

    /** Un hash bcrypt cualquiera: el usuario inexistente cuesta lo mismo que uno real. */
    private const HASH_RELLENO = '$2y$10$abcdefghijklmnopqrstuu5Gg6mXg1K1xDQ.8dLYHPYO/7gM7c9Gu';

    private static ?array $usuario = null;

    // ------------------------------------------------------------------ sesión

    public static function iniciarCookie(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            // La sesión viaja por internet, no por una red local.
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['SERVER_PORT'] ?? '') === '443'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Intenta ingresar.
     *
     * @return array{ok:bool, motivo?:string, sesion_abierta?:array}
     *         Si ya hay sesión en otro equipo devuelve ok=false con
     *         `sesion_abierta`, para que la pantalla ofrezca cerrarla.
     */
    public static function ingresar(string $usuario, string $clave, bool $desplazar = false): array
    {
        self::iniciarCookie();

        // Tope por conexión, contado en el registro que ya existe. Sin él,
        // cualquiera sin sesión podía mantener bloqueadas las cuentas que quisiera.
        $porIp = Db::uno("SELECT COUNT(*) c FROM sesiones_log
                           WHERE ip = ? AND evento = 'RECHAZADO'
                             AND cuando > DATE_SUB(NOW(), INTERVAL 15 MINUTE)", [self::ip()]);
        if ((int) ($porIp['c'] ?? 0) >= self::TOPE_POR_IP) {
            self::registrar(null, $usuario, 'RECHAZADO', 'tope por conexion');
            return ['ok' => false, 'motivo' => self::MSG_FALLO];
        }

        $u = self::cargar('usuario = ?', [$usuario]);

        // Mismo mensaje y mismo trabajo para usuario inexistente, clave mala y
        // cuenta bloqueada: distinguirlos le regala al atacante qué cuentas existen.
        if (!$u || !$u['activo']) {
            password_verify($clave, self::HASH_RELLENO);
            self::registrar(null, $usuario, 'RECHAZADO', $u ? 'inactivo' : 'no existe');
            return ['ok' => false, 'motivo' => self::MSG_FALLO];
        }

        if ((int) $u['minutos_bloqueo'] > 0) {
            self::registrar((int) $u['usuario_id'], $usuario, 'RECHAZADO', 'bloqueado');
            return ['ok' => false, 'motivo' => self::MSG_FALLO];
        }

        if (!password_verify($clave, $u['clave_hash'])) {
            /* El bloqueo lo calcula MySQL. Antes salía de date() de PHP: si la
               web corriera en otra zona que la base, `bloqueado_hasta` quedaría
               horas en el pasado y el bloqueo no bloquearía (el 2026-09-10 las
               dos están en UTC, pero eso lo decide el hosting). Un bloqueo
               vencido tampoco se suma al siguiente: el contador vuelve a cero. */
            Db::ejecutar('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL
                           WHERE usuario_id = ? AND bloqueado_hasta IS NOT NULL
                             AND bloqueado_hasta <= NOW()', [$u['usuario_id']]);
            // Izquierda a derecha: el IF ya ve el contador incrementado.
            Db::ejecutar('UPDATE usuarios
                             SET intentos_fallidos = intentos_fallidos + 1,
                                 bloqueado_hasta = IF(intentos_fallidos >= ?,
                                                      DATE_ADD(NOW(), INTERVAL ? MINUTE), bloqueado_hasta)
                           WHERE usuario_id = ?',
                         [self::MAX_INTENTOS, self::BLOQUEO_MIN, $u['usuario_id']]);
            self::registrar((int) $u['usuario_id'], $usuario, 'RECHAZADO', 'clave incorrecta');
            return ['ok' => false, 'motivo' => self::MSG_FALLO];
        }

        // ¿Hay una sesión viva en otro lado?
        if (!$desplazar && $u['sesion_token'] !== null && !self::sesionVencida($u)) {
            return [
                'ok' => false,
                'motivo' => 'Ya tienes una sesión abierta en otro equipo.',
                'sesion_abierta' => [
                    'equipo' => $u['sesion_equipo'],
                    'desde'  => $u['sesion_desde'],
                    'ip'     => $u['sesion_ip'],
                ],
            ];
        }

        $desplazado = $u['sesion_token'] !== null && !self::sesionVencida($u);
        $token = bin2hex(random_bytes(32));
        Db::ejecutar(
            'UPDATE usuarios
                SET sesion_token = ?, sesion_desde = NOW(), sesion_ultima = NOW(),
                    sesion_equipo = ?, sesion_ip = ?,
                    intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_ingreso = NOW()
              WHERE usuario_id = ?',
            [$token, self::equipo(), self::ip(), $u['usuario_id']]
        );

        session_regenerate_id(true);   // contra fijación de sesión
        $_SESSION['usuario_id'] = (int) $u['usuario_id'];
        $_SESSION['token']      = $token;

        if ($desplazado) {
            self::registrar((int) $u['usuario_id'], $usuario, 'DESPLAZADO',
                            'cerró su sesión anterior desde otro equipo');
        }
        self::registrar((int) $u['usuario_id'], $usuario, 'INGRESO', null);
        return ['ok' => true];
    }

    public static function salir(): void
    {
        self::iniciarCookie();
        $u = self::actual();
        if ($u) {
            Db::ejecutar('UPDATE usuarios SET sesion_token = NULL WHERE usuario_id = ?',
                         [$u['usuario_id']]);
            self::registrar((int) $u['usuario_id'], $u['usuario'], 'SALIDA', null);
        }
        $_SESSION = [];
        session_destroy();
    }

    /** El usuario de esta petición, o null. Revalida contra la base en cada llamada. */
    public static function actual(): ?array
    {
        if (self::$usuario !== null) {
            return self::$usuario;
        }
        self::iniciarCookie();
        if (empty($_SESSION['usuario_id']) || empty($_SESSION['token'])) {
            return null;
        }
        $u = self::cargar('usuario_id = ? AND activo = 1', [$_SESSION['usuario_id']]);

        // El token tiene que seguir siendo el de la base. Si alguien entró desde
        // otro equipo, este dejó de valer sin que haga falta avisarle a nadie.
        if (!$u || !hash_equals((string) $u['sesion_token'], (string) $_SESSION['token'])) {
            $_SESSION = [];
            return null;
        }
        if (self::sesionVencida($u)) {
            Db::ejecutar('UPDATE usuarios SET sesion_token = NULL WHERE usuario_id = ?',
                         [$u['usuario_id']]);
            self::registrar((int) $u['usuario_id'], $u['usuario'], 'EXPIRADO', 'inactividad');
            $_SESSION = [];
            return null;
        }
        Db::ejecutar('UPDATE usuarios SET sesion_ultima = NOW() WHERE usuario_id = ?',
                     [$u['usuario_id']]);
        return self::$usuario = $u;
    }

    /**
     * Carga un usuario con los tiempos YA CALCULADOS POR MYSQL.
     *
     * POR QUE ASI Y NO CON strtotime(): MySQL guarda `2026-09-08 19:02:51` en
     * hora de Ecuador y PHP lo interpretaba como UTC. La resta contra time()
     * daba +5 horas, así que TODA sesión nacía vencida y el token se borraba en
     * la misma petición que lo creaba: nadie podía entrar. Comparar fechas entre
     * dos relojes con zonas distintas es el error; la comparación la hace quien
     * guarda el dato.
     */
    private static function cargar(string $donde, array $params): ?array
    {
        return Db::uno(
            'SELECT *,
                    TIMESTAMPDIFF(MINUTE, sesion_ultima, NOW())    AS minutos_inactivo,
                    GREATEST(0, TIMESTAMPDIFF(MINUTE, NOW(), bloqueado_hasta)) AS minutos_bloqueo
               FROM usuarios
              WHERE ' . $donde,
            $params
        );
    }

    private static function sesionVencida(array $u): bool
    {
        if ($u['sesion_ultima'] === null || $u['minutos_inactivo'] === null) {
            return true;
        }
        return (int) $u['minutos_inactivo'] > self::INACTIVIDAD_MIN;
    }

    // ---------------------------------------------------------------- permisos

    /** @return string[] Los permisos efectivos: los del rol, más y menos las excepciones. */
    public static function permisos(): array
    {
        $u = self::actual();
        if (!$u) {
            return [];
        }
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $filas = Db::todos(
            'SELECT p.codigo,
                    COALESCE(up.concedido, 1) AS concedido
               FROM permisos p
               LEFT JOIN rol_permisos rp ON rp.permiso = p.codigo AND rp.rol = ?
               LEFT JOIN usuario_permisos up ON up.permiso = p.codigo AND up.usuario_id = ?
              WHERE rp.permiso IS NOT NULL OR up.usuario_id IS NOT NULL',
            [$u['rol'], $u['usuario_id']]
        );
        $out = [];
        foreach ($filas as $f) {
            if ((int) $f['concedido'] === 1) {
                $out[] = $f['codigo'];
            }
        }
        return $cache = $out;
    }

    public static function puede(string $permiso): bool
    {
        return in_array($permiso, self::permisos(), true);
    }

    /**
     * Corta la petición si no hay sesión o falta el permiso.
     * Es la primera línea de toda página y de todo endpoint.
     */
    public static function exigir(?string $permiso = null, bool $json = false): array
    {
        $u = self::actual();
        if (!$u) {
            if ($json) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'sesion_requerida'], JSON_UNESCAPED_UNICODE);
            } else {
                header('Location: login.php?r=' . urlencode($_SERVER['REQUEST_URI'] ?? 'panel.php'));
            }
            exit;
        }
        // La clave provisional impresa solo sirve para cambiarla, también en los
        // extremos JSON: hasta el 2026-09-10 el cambio se exigía únicamente en
        // las pantallas, y con ella se consultaban catálogos y se enviaban órdenes.
        $pagina = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!empty($u['debe_cambiar_clave']) && !in_array($pagina, ['clave.php', 'salir.php'], true)) {
            if ($json) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'debe_cambiar_clave'], JSON_UNESCAPED_UNICODE);
            } else {
                header('Location: clave.php');
            }
            exit;
        }
        if ($permiso !== null && !self::puede($permiso)) {
            /* `exito = false`. Se detecto al correr las propias consultas de
               deteccion: esta denegacion se guardaba como exitosa, asi que
               «cuantos intentos se rechazaron» no contaba ninguno de los que
               ocurren al ABRIR una pantalla -- que son la mayoria, porque es
               donde primero choca alguien que no deberia estar ahi. */
            self::bitacora('DENEGADO', 'permiso', $permiso, null, null, null,
                           ['permiso' => $permiso,
                            'pagina' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''))],
                           false);
            http_response_code(403);
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'sin_permiso', 'permiso' => $permiso],
                                 JSON_UNESCAPED_UNICODE);
            } else {
                echo '<p style="font-family:system-ui;padding:24px">No tienes permiso para esta sección.</p>';
            }
            exit;
        }
        return $u;
    }

    // ------------------------------------------------------------------ rango

    /**
     * Rango de cada rol. Es el segundo control sobre la creación de usuarios,
     * y el que impide la escalada de privilegios.
     *
     * El permiso `usuarios.operativos` abre la pantalla; el rango decide qué
     * roles se pueden elegir dentro. Sin esto, darle a la administradora la
     * capacidad de crear usuarios le permitiría crear un SUPERADMIN y ascenderse
     * a sí misma.
     */
    private const RANGO = ['TECNICO' => 0, 'JEFE_ZONA' => 1, 'ADMIN' => 2, 'SUPERADMIN' => 3];

    /**
     * Los roles que este usuario puede crear. Nadie crea un rango igual o
     * superior al suyo -- salvo el superadministrador, que sí puede crear otro,
     * porque si no, perder el único acceso dejaría el sistema sin dueño.
     *
     * @return string[]
     */
    public static function rolesQuePuedeCrear(): array
    {
        $u = self::actual();
        if (!$u) {
            return [];
        }
        $mio = self::RANGO[$u['rol']] ?? -1;
        if ($u['rol'] === 'SUPERADMIN') {
            return array_keys(self::RANGO);
        }
        if (!self::puede('usuarios.operativos')) {
            return [];
        }
        return array_keys(array_filter(self::RANGO, fn($r) => $r < $mio));
    }

    /** ¿Puede este usuario tocar la ficha de ese otro? */
    public static function puedeGestionarA(array $otro): bool
    {
        $u = self::actual();
        if (!$u) {
            return false;
        }
        if ($u['rol'] === 'SUPERADMIN') {
            return true;
        }
        if (!self::puede('usuarios.operativos')) {
            return false;
        }
        // Solo por debajo, y solo dentro de su alcance de zona.
        $mio  = self::RANGO[$u['rol']] ?? -1;
        $suyo = self::RANGO[$otro['rol']] ?? 99;
        return $suyo < $mio && self::alcanzaZona($otro['zona'] ?? null);
    }

    // ----------------------------------------------------------------- alcance

    /**
     * La zona a la que está limitado el usuario, o null si ve todas.
     *
     * Se usa así, y nunca escondiendo opciones en la interfaz:
     *
     *     $z = Auth::zonaAlcance();
     *     $sql = 'SELECT ... FROM casos' . ($z !== null ? ' WHERE zona = ?' : '');
     *
     * Con `!== null` y no por verdad: '' es «sin zona» y tiene que filtrar.
     */
    public static function zonaAlcance(): ?string
    {
        $u = self::actual();
        if (!$u) {
            return null;
        }
        // Falla cerrado: un jefe o un técnico sin zona no ve ninguna, en vez de
        // las tres. null significa «todas» y es solo para la administración.
        return in_array($u['rol'], ['SUPERADMIN', 'ADMIN'], true) ? null : (string) ($u['zona'] ?? '');
    }

    /** ¿Este usuario puede tocar algo de esta zona? */
    public static function alcanzaZona(?string $zona): bool
    {
        $mia = self::zonaAlcance();
        return $mia === null || ($zona !== null && $zona === $mia);
    }

    // ---------------------------------------------------------------- registro

    /**
     * Deja constancia de una acción.
     *
     * NO ES SOLO PARA AUDITAR. El registro se va a minar para encontrar
     * comportamientos anómalos, y de ahí los cuatro parámetros extra:
     *
     *   $antes / $despues  convierten la fila en una TRANSICION. Sin el estado
     *                      anterior no se puede detectar un salto imposible --
     *                      un caso que llega a RESUELTO sin haber pasado nunca
     *                      por ATENDIDO es alguien cerrando trabajo que no
     *                      consta que se hiciera.
     *   $datos             los campos de la acción, consultables. `$detalle` es
     *                      para que una persona lo lea; esto es para preguntar.
     *   $exito = false     la acción se INTENTO y se rechazó. Es la señal más
     *                      útil de todas: nadie tropieza tres veces con el mismo
     *                      permiso por casualidad.
     *
     * Se guarda también el equipo por acción, no solo al entrar: el mismo
     * usuario operando desde dos equipos muy distintos en minutos es una señal
     * de credencial compartida.
     *
     * Nunca lanza. Que falle el registro no puede tumbar la operación que se
     * estaba registrando -- pero se anota en el log de PHP para que no
     * desaparezca en silencio.
     */
    public static function bitacora(string $accion, ?string $entidad = null,
                                    ?string $referencia = null, ?string $detalle = null,
                                    ?string $antes = null, ?string $despues = null,
                                    ?array $datos = null, bool $exito = true): void
    {
        $u = self::$usuario;
        try {
            Db::ejecutar(
                'INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia,
                                       estado_antes, estado_despues, exito, detalle, datos,
                                       ip, equipo)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$u['usuario_id'] ?? null, $u['usuario'] ?? null, $accion, $entidad,
                 $referencia, $antes, $despues, $exito ? 1 : 0, $detalle,
                 $datos === null ? null : json_encode($datos, JSON_UNESCAPED_UNICODE),
                 self::ip(), self::equipo()]
            );
        } catch (Throwable $e) {
            error_log('bitacora: ' . $e->getMessage());
        }
    }

    private static function registrar(?int $id, string $usuario, string $evento, ?string $motivo): void
    {
        Db::ejecutar(
            'INSERT INTO sesiones_log (usuario_id, usuario, evento, motivo, ip, equipo)
             VALUES (?,?,?,?,?,?)',
            [$id, $usuario, $evento, $motivo, self::ip(), self::equipo()]
        );
    }

    private static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    private static function equipo(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'desconocido'), 0, 200);
    }
}
