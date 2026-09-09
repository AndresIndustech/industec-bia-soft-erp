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
        $u = self::cargar('usuario = ?', [$usuario]);

        // Mismo mensaje para usuario inexistente y clave mala: decir cuál de los
        // dos falló le regala al atacante la mitad del trabajo.
        if (!$u || !$u['activo']) {
            self::registrar(null, $usuario, 'RECHAZADO', $u ? 'inactivo' : 'no existe');
            return ['ok' => false, 'motivo' => 'Usuario o contraseña incorrectos.'];
        }

        if ((int) $u['minutos_bloqueo'] > 0) {
            $min = (int) $u['minutos_bloqueo'];
            self::registrar((int) $u['usuario_id'], $usuario, 'RECHAZADO', 'bloqueado');
            return ['ok' => false, 'motivo' => "Demasiados intentos. Vuelve a probar en $min minuto(s)."];
        }

        if (!password_verify($clave, $u['clave_hash'])) {
            $n = (int) $u['intentos_fallidos'] + 1;
            $bloqueo = $n >= self::MAX_INTENTOS
                ? date('Y-m-d H:i:s', time() + self::BLOQUEO_MIN * 60) : null;
            Db::ejecutar(
                'UPDATE usuarios SET intentos_fallidos = ?, bloqueado_hasta = ? WHERE usuario_id = ?',
                [$n, $bloqueo, $u['usuario_id']]
            );
            self::registrar((int) $u['usuario_id'], $usuario, 'RECHAZADO', 'clave incorrecta');
            return ['ok' => false, 'motivo' => 'Usuario o contraseña incorrectos.'];
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
        if ($permiso !== null && !self::puede($permiso)) {
            self::bitacora('DENEGADO', 'permiso', $permiso);
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
     *     $sql = 'SELECT ... FROM casos' . ($z ? ' WHERE zona = ?' : '');
     */
    public static function zonaAlcance(): ?string
    {
        $u = self::actual();
        if (!$u) {
            return null;
        }
        return in_array($u['rol'], ['SUPERADMIN', 'ADMIN'], true) ? null : $u['zona'];
    }

    /** ¿Este usuario puede tocar algo de esta zona? */
    public static function alcanzaZona(?string $zona): bool
    {
        $mia = self::zonaAlcance();
        return $mia === null || ($zona !== null && $zona === $mia);
    }

    // ---------------------------------------------------------------- registro

    public static function bitacora(string $accion, ?string $entidad = null,
                                    ?string $referencia = null, ?string $detalle = null): void
    {
        $u = self::$usuario;
        Db::ejecutar(
            'INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia, detalle, ip)
             VALUES (?,?,?,?,?,?,?)',
            [$u['usuario_id'] ?? null, $u['usuario'] ?? null, $accion, $entidad,
             $referencia, $detalle, self::ip()]
        );
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
