<?php
declare(strict_types=1);
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Despacho.php';
require_once __DIR__ . '/Emision.php';

/**
 * Correo.php — La cuenta desde la que salen los correos, y el envío (T2.29,
 * pedido de Andrés del 2026-09-29; migración 023).
 *
 * POR QUÉ EXISTE. El despachador esperaba la cuenta SMTP en config.php
 * (`smtp_host`, `smtp_clave`…), que en el sitio de pruebas no la tiene y no se
 * toca. El formulario viejo la guarda en texto plano en cinco config.php dentro
 * del docroot. Aquí la cuenta vive en `correo_cuentas`, la clave cifrada con
 * AES-256-GCM y la llave fuera de la base (en config.php), y se administra desde
 * Correos: la de `reclutamiento@industec.me` —la misma del formulario viejo,
 * decisión de Andrés— queda cargada, y se puede agregar otra y cambiar a ella.
 *
 * LA CLAVE NUNCA SE MUESTRA. No sale en pantallas, en la bitácora, en el
 * historial ni en un mensaje de error (`limpiar()` la tacha si un SMTP la
 * repitiera). Se escribe, se cifra y solo la descifra quien la va a usar.
 *
 * UN SOLO CAMINO DE ENVÍO. El cron (despachar_correo_cli.php), envio.php justo
 * después de responderle al técnico y el botón «Enviar ahora» de Correos llaman
 * a `despachar()`. Un candado de archivo y el reclamo atómico de la cola evitan
 * que dos lo manden dos veces.
 */
final class Correo
{
    private const CIFRADO = 'aes-256-gcm';
    private const INFO_LLAVE = 'industec-correo-cuentas-v1';
    /** Correos de prueba por cuenta y hora: el SMTP corta en 50 por hora y buzón (T2.28.2, §2c). */
    public const PRUEBAS_POR_HORA = 5;

    // ------------------------------------------------------------------
    //  La clave, cifrada. Funciones puras: se prueban sin base.
    // ------------------------------------------------------------------

    /**
     * La llave: `correo_llave` de config.php si trae 32 caracteres o más; si
     * no, derivada de `sync_secreto`, que el sitio exige de 32+ desde el
     * primer día. Se deriva con HKDF para no usar el secreto de sincronización
     * tal cual en otro oficio. Si alguien cambia esa clave de config.php, las
     * claves guardadas dejan de leerse y Correos pide escribirlas de nuevo.
     */
    public static function llave(?array $cfg = null): string
    {
        $cfg = $cfg ?? Db::config();
        $base = (string) ($cfg['correo_llave'] ?? '');
        if (strlen($base) < 32) { $base = (string) ($cfg['sync_secreto'] ?? ''); }
        if (strlen($base) < 32) {
            throw new RuntimeException('no hay llave para cifrar la clave del correo (config.php sin sync_secreto)');
        }
        return hash_hkdf('sha256', $base, 32, self::INFO_LLAVE);
    }

    /** 16 caracteres que identifican la llave sin revelarla. */
    public static function huellaLlave(string $llave): string
    {
        return substr(hash_hmac('sha256', 'huella de la llave del correo', $llave), 0, 16);
    }

    public static function cifrar(string $claro, string $llave): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($claro, self::CIFRADO, $llave, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false || strlen($tag) !== 16) { throw new RuntimeException('no se pudo cifrar la clave'); }
        return base64_encode($iv . $tag . $ct);
    }

    /** null si no se puede leer: otra llave, o el texto alterado (GCM lo detecta). */
    public static function descifrar(string $cifrado, string $llave): ?string
    {
        $b = base64_decode($cifrado, true);
        if ($b === false || strlen($b) < 29) { return null; }
        $claro = openssl_decrypt(substr($b, 28), self::CIFRADO, $llave, OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
        return $claro === false ? null : $claro;
    }

    /** Un mensaje de error apto para mostrar: sin la clave, aunque el SMTP la repitiera. */
    public static function limpiar(string $mensaje, ?string $clave): string
    {
        if ($clave !== null && $clave !== '') {
            $mensaje = str_replace($clave, '********', $mensaje);
        }
        return mb_substr(trim(preg_replace('/\s+/', ' ', $mensaje) ?? $mensaje), 0, 300);
    }

    /**
     * Valida los datos de una cuenta (alta o edición). Pura.
     * @return array{0:?string, 1:array<string,mixed>} [error, datos limpios]
     */
    public static function validarCuenta(array $in, bool $exigirClave): array
    {
        $d = [
            'nombre'           => trim((string) ($in['nombre'] ?? '')),
            'host'             => strtolower(trim((string) ($in['host'] ?? ''))),
            'puerto'           => (int) ($in['puerto'] ?? 0),
            'seguridad'        => strtoupper(trim((string) ($in['seguridad'] ?? ''))),
            'usuario'          => trim((string) ($in['usuario'] ?? '')),
            'clave'            => (string) ($in['clave'] ?? ''),
            'remitente'        => strtolower(trim((string) ($in['remitente'] ?? ''))),
            'remitente_nombre' => trim((string) ($in['remitente_nombre'] ?? '')),
        ];
        if ($d['nombre'] === '' || mb_strlen($d['nombre']) > 80) { return ['Escribe un nombre para la cuenta (hasta 80 caracteres).', $d]; }
        if (!preg_match('/^(?=.{3,120}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $d['host'])) {
            return ['El servidor no es válido (p. ej. smtp.titan.email).', $d];
        }
        if ($d['puerto'] < 1 || $d['puerto'] > 65535) { return ['El puerto no es válido (587 con STARTTLS, 465 con SSL).', $d]; }
        if (!in_array($d['seguridad'], ['STARTTLS', 'SSL'], true)) {
            return ['Elige STARTTLS o SSL: sin cifrar, la clave viajaría en claro.', $d];
        }
        if ($d['usuario'] === '' || mb_strlen($d['usuario']) > 160) { return ['Escribe el usuario de la cuenta.', $d]; }
        if ($exigirClave && $d['clave'] === '') { return ['Escribe la clave de la cuenta.', $d]; }
        if (strlen($d['clave']) > 200) { return ['La clave es demasiado larga.', $d]; }
        if (!filter_var($d['remitente'], FILTER_VALIDATE_EMAIL)) { return ['El remitente tiene que ser un correo válido.', $d]; }
        if ($d['remitente_nombre'] === '' || mb_strlen($d['remitente_nombre']) > 120) {
            return ['Escribe el nombre que verá quien recibe el correo.', $d];
        }
        return [null, $d];
    }

    /**
     * A quién le llega, por grupos, un correo con estos destinatarios. Pura.
     * Lo usa el recibo del técnico para no afirmar «sale al local» cuando el
     * único destinatario es un buzón de INDUSTEC del maestro (95 de 100
     * locales tienen servicioalcliente@ como correo del local): revisión del
     * 29-sep (T2.29), I-7.
     *
     * @return array{local:bool, kfc:bool, industec:bool}
     */
    public static function aQuienSale(array $para, array $cc): array
    {
        $todos = array_map(static fn($d) => strtolower(trim((string) $d)), array_merge($para, $cc));
        $esIndustec = static fn(string $d): bool => str_ends_with($d, '@industec.me');
        return [
            'local'    => array_filter(array_map(static fn($d) => strtolower(trim((string) $d)), $para),
                                       static fn($d) => $d !== '' && !$esIndustec($d)) !== [],
            'kfc'      => array_filter($todos, static fn($d) => (bool) preg_match('/@kfc\.com(\.ec)?$/', $d)) !== [],
            'industec' => array_filter($todos, $esIndustec) !== [],
        ];
    }

    // ------------------------------------------------------------------
    //  Las cuentas.
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> todas, sin la clave. [] sin la 023. */
    public static function cuentas(): array
    {
        try {
            return Db::todos(
                'SELECT cuenta_id, nombre, host, puerto, seguridad, usuario, remitente, remitente_nombre, activa, habilitada,
                        origen, probada_en, probada_ok, probada_detalle, creado_en, actualizado_en, llave_huella,
                        (clave_cifrada IS NOT NULL) AS tiene_clave
                   FROM correo_cuentas ORDER BY activa DESC, habilitada DESC, cuenta_id'
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * La cuenta con la que se envía, con su clave en claro en `clave` (o null
     * y el motivo en `clave_error`). La activa de `correo_cuentas`; sin la 023
     * o sin ninguna activa, la de config.php si la trae (el contrato de antes
     * del 29-sep). null si no hay ninguna.
     */
    public static function cuentaActiva(): ?array
    {
        try {
            $f = Db::uno('SELECT * FROM correo_cuentas WHERE activa = 1 AND habilitada = 1 LIMIT 1');
        } catch (Throwable $e) {
            $f = null;
        }
        if ($f !== null) {
            return self::conClave($f);
        }
        $cfg = Db::config();
        foreach (['smtp_host', 'smtp_usuario', 'smtp_clave', 'smtp_de'] as $k) {
            if (empty($cfg[$k])) { return null; }
        }
        $puerto = (int) ($cfg['smtp_puerto'] ?? 465);
        return [
            'cuenta_id' => null, 'nombre' => 'config.php', 'host' => (string) $cfg['smtp_host'], 'puerto' => $puerto,
            'seguridad' => $puerto === 465 ? 'SSL' : 'STARTTLS', 'usuario' => (string) $cfg['smtp_usuario'],
            'clave' => (string) $cfg['smtp_clave'], 'clave_error' => null, 'remitente' => (string) $cfg['smtp_de'],
            'remitente_nombre' => 'INDUSTEC · Órdenes de trabajo', 'origen' => 'CONFIG_PHP',
            'probada_ok' => null, 'probada_en' => null,
        ];
    }

    /** Una fila de `correo_cuentas` con su clave descifrada (`clave`, o null y `clave_error`). */
    public static function conClave(array $f): array
    {
        $cifrada = $f['clave_cifrada'] ?? null;
        unset($f['clave_cifrada']);
        $f['clave'] = null;
        $f['clave_error'] = null;
        if ($cifrada === null || $cifrada === '') {
            $f['clave_error'] = 'la cuenta no tiene clave guardada';
            return $f;
        }
        try {
            $llave = self::llave();
        } catch (Throwable $e) {
            $f['clave_error'] = $e->getMessage();
            return $f;
        }
        if (($f['llave_huella'] ?? '') !== self::huellaLlave($llave)) {
            $f['clave_error'] = 'la clave se guardó con otra llave del servidor: hay que volver a escribirla';
            return $f;
        }
        $claro = self::descifrar((string) $cifrada, $llave);
        if ($claro === null) {
            $f['clave_error'] = 'la clave guardada no se puede leer: hay que volver a escribirla';
            return $f;
        }
        $f['clave'] = $claro;
        return $f;
    }

    public static function cuenta(int $id): ?array
    {
        $f = Db::uno('SELECT * FROM correo_cuentas WHERE cuenta_id = ?', [$id]);
        return $f === null ? null : self::conClave($f);
    }

    /** Lo que se puede mostrar de una cuenta en el historial: nunca la clave. */
    private static function publica(array $f): array
    {
        return array_intersect_key($f, array_flip(['nombre', 'host', 'puerto', 'seguridad', 'usuario', 'remitente',
                                                     'remitente_nombre', 'activa', 'habilitada']));
    }

    private static function cambio(int $cuentaId, string $accion, ?array $antes, ?array $despues, ?string $resultado, int $por): void
    {
        Db::ejecutar('INSERT INTO correo_cuentas_cambios (cuenta_id, accion, antes, despues, resultado, por) VALUES (?, ?, ?, ?, ?, ?)',
                     [$cuentaId, $accion,
                      $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
                      $despues !== null ? json_encode($despues, JSON_UNESCAPED_UNICODE) : null,
                      $resultado !== null ? mb_substr($resultado, 0, 300) : null, $por]);
    }

    /**
     * Da de alta una cuenta o edita una existente. En la cuenta ACTIVA, un
     * cambio de servidor, usuario o clave se prueba ANTES de guardarse: una
     * clave mal escrita dejaría todos los correos en FALLIDO.
     *
     * @return array{ok:bool, error:?string, id:?int}
     */
    public static function guardar(array $in, ?int $cuentaId, int $usuarioId, string $origen = 'MANUAL'): array
    {
        $antes = $cuentaId !== null ? Db::uno('SELECT * FROM correo_cuentas WHERE cuenta_id = ?', [$cuentaId]) : null;
        if ($cuentaId !== null && $antes === null) { return ['ok' => false, 'error' => 'Esa cuenta no existe.', 'id' => null]; }
        [$error, $d] = self::validarCuenta($in, $antes === null);
        if ($error !== null) { return ['ok' => false, 'error' => $error, 'id' => $cuentaId]; }

        $llave = self::llave();
        $claveNueva = $d['clave'] !== '';
        $credenciales = $antes === null || $claveNueva
            || $d['host'] !== $antes['host'] || (int) $d['puerto'] !== (int) $antes['puerto']
            || $d['seguridad'] !== $antes['seguridad'] || $d['usuario'] !== $antes['usuario'];

        $activa = $antes !== null && (int) $antes['activa'] === 1;
        if ($activa && $d['remitente'] !== strtolower((string) $antes['remitente'])) {
            // Probar la conexión no dice si el servidor deja enviar como otra
            // dirección: eso solo se sabe mandando. Y si no deja, cada OT
            // quedaría FALLIDO. El cambio se hace con una cuenta aparte.
            return ['ok' => false, 'id' => $cuentaId,
                    'error' => 'El remitente de la cuenta con la que salen los correos no se cambia aquí: agrega otra cuenta con el remitente nuevo, mándale un correo de prueba y úsala.'];
        }
        $pruebaPrevia = null;
        if ($activa && $credenciales) {
            $prueba = $d;
            $prueba['clave'] = $claveNueva ? $d['clave'] : (self::conClave($antes)['clave'] ?? null);
            if ($prueba['clave'] === null) {
                return ['ok' => false, 'error' => 'Escribe la clave: la guardada no se puede leer.', 'id' => $cuentaId];
            }
            $pruebaPrevia = self::probar($prueba);
            if (!$pruebaPrevia['ok']) {
                return ['ok' => false, 'id' => $cuentaId,
                        'error' => 'No se guardó: es la cuenta con la que salen los correos y con estos datos no conecta (' . $pruebaPrevia['detalle'] . ').'];
            }
        }

        $cifrada = $claveNueva ? self::cifrar($d['clave'], $llave) : null;
        if ($antes === null) {
            try {
                Db::ejecutar(
                    'INSERT INTO correo_cuentas (nombre, host, puerto, seguridad, usuario, clave_cifrada, llave_huella,
                                                 remitente, remitente_nombre, origen, creado_por)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$d['nombre'], $d['host'], $d['puerto'], $d['seguridad'], $d['usuario'], $cifrada, self::huellaLlave($llave),
                     $d['remitente'], $d['remitente_nombre'], $origen, $usuarioId]
                );
            } catch (Throwable $e) {
                return ['ok' => false, 'id' => null, 'error' => str_contains($e->getMessage(), 'uq_cuenta')
                    ? 'Ya existe una cuenta con ese servidor y ese usuario: edítala en vez de duplicarla.' : 'No se pudo guardar la cuenta.'];
            }
            $id = (int) Db::conn()->lastInsertId();
            self::cambio($id, $origen === 'SISTEMA_VIEJO' ? 'IMPORTACION' : 'ALTA', null, self::publica($d), null, $usuarioId);
            Auth::bitacora('CORREO_CUENTA_ALTA', 'correo_cuenta', (string) $id, $d['usuario'] . ' @ ' . $d['host'], null, null,
                           self::publica($d));
            return ['ok' => true, 'error' => null, 'id' => $id];
        }

        try {
            Db::ejecutar(
                'UPDATE correo_cuentas
                    SET nombre = ?, host = ?, puerto = ?, seguridad = ?, usuario = ?, remitente = ?, remitente_nombre = ?,
                        clave_cifrada = COALESCE(?, clave_cifrada), llave_huella = IF(? IS NULL, llave_huella, ?),
                        probada_ok = IF(?, NULL, probada_ok), actualizado_por = ?, actualizado_en = NOW()
                  WHERE cuenta_id = ?',
                [$d['nombre'], $d['host'], $d['puerto'], $d['seguridad'], $d['usuario'], $d['remitente'], $d['remitente_nombre'],
                 $cifrada, $cifrada, self::huellaLlave($llave), $credenciales && (int) $antes['activa'] !== 1 ? 1 : 0,
                 $usuarioId, $cuentaId]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => $cuentaId, 'error' => str_contains($e->getMessage(), 'uq_cuenta')
                ? 'Ya existe otra cuenta con ese servidor y ese usuario.' : 'No se pudo guardar la cuenta.'];
        }
        $despues = self::publica($d) + ['clave' => $claveNueva ? 'cambiada' : 'sin cambios'];
        self::cambio($cuentaId, $claveNueva ? 'CLAVE' : 'EDICION', self::publica($antes), $despues, null, $usuarioId);
        if ($pruebaPrevia !== null) {
            self::anotarPrueba($cuentaId, $pruebaPrevia, 'PRUEBA_CONEXION', $usuarioId);
        }
        Auth::bitacora('CORREO_CUENTA_EDITA', 'correo_cuenta', (string) $cuentaId,
                       $d['usuario'] . ' @ ' . $d['host'] . ($claveNueva ? ' (clave cambiada)' : ''), null, null, $despues);
        return ['ok' => true, 'error' => null, 'id' => $cuentaId];
    }

    /**
     * La fecha del último correo de prueba que salió bien con esta cuenta
     * DESPUÉS de su último cambio de datos o de clave, o null. Solo un envío
     * real dice que el servidor deja mandar como ese remitente y con adjunto;
     * conectar no lo dice.
     */
    public static function envioProbado(int $cuentaId): ?string
    {
        $f = Db::uno("SELECT MAX(en) ok_en,
                             (SELECT MAX(en) FROM correo_cuentas_cambios
                               WHERE cuenta_id = ? AND accion IN ('ALTA','IMPORTACION','EDICION','CLAVE')) cambio_en
                        FROM correo_cuentas_cambios
                       WHERE cuenta_id = ? AND accion = 'PRUEBA_ENVIO' AND resultado LIKE 'OK:%'",
                     [$cuentaId, $cuentaId]);
        if ($f === null || $f['ok_en'] === null) { return null; }
        return ($f['cambio_en'] === null || strcmp((string) $f['ok_en'], (string) $f['cambio_en']) >= 0) ? (string) $f['ok_en'] : null;
    }

    /**
     * Usar esta cuenta para enviar. Solo una habilitada, con clave legible y
     * con un correo de prueba que salió bien después de su último cambio.
     *
     * @return array{ok:bool, error:?string}
     */
    public static function activar(int $cuentaId, int $usuarioId, bool $sinPrueba = false): array
    {
        $c = self::cuenta($cuentaId);
        if ($c === null) { return ['ok' => false, 'error' => 'Esa cuenta no existe.']; }
        if ((int) $c['habilitada'] !== 1) { return ['ok' => false, 'error' => 'La cuenta está deshabilitada: habilítala primero.']; }
        if ($c['clave'] === null) { return ['ok' => false, 'error' => 'La cuenta no tiene una clave legible: ' . $c['clave_error'] . '.']; }
        // $sinPrueba solo lo usa la importación de la cuenta del formulario
        // viejo, que ya envía todos los días con esos mismos datos.
        if (!$sinPrueba && self::envioProbado($cuentaId) === null) {
            return ['ok' => false, 'error' => 'Manda un correo de prueba con esta cuenta (y que salga bien) antes de usarla.'];
        }
        $pdo = Db::conn();
        $pdo->beginTransaction();
        try {
            $antes = Db::uno('SELECT cuenta_id FROM correo_cuentas WHERE activa = 1 FOR UPDATE');
            Db::ejecutar('UPDATE correo_cuentas SET activa = 0 WHERE activa = 1');
            Db::ejecutar('UPDATE correo_cuentas SET activa = 1, actualizado_por = ?, actualizado_en = NOW() WHERE cuenta_id = ?',
                         [$usuarioId, $cuentaId]);
            self::cambio($cuentaId, 'ACTIVAR', ['activa' => 0, 'antes_activa' => $antes['cuenta_id'] ?? null], ['activa' => 1], null, $usuarioId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            return ['ok' => false, 'error' => 'No se pudo cambiar la cuenta de envío.'];
        }
        Auth::bitacora('CORREO_CUENTA_ACTIVA', 'correo_cuenta', (string) $cuentaId, 'los correos salen ahora desde ' . $c['remitente'],
                       isset($antes['cuenta_id']) ? (string) $antes['cuenta_id'] : null, (string) $cuentaId);
        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok:bool, error:?string} */
    public static function habilitar(int $cuentaId, bool $si, int $usuarioId): array
    {
        $f = Db::uno('SELECT cuenta_id, activa, habilitada, remitente FROM correo_cuentas WHERE cuenta_id = ?', [$cuentaId]);
        if ($f === null) { return ['ok' => false, 'error' => 'Esa cuenta no existe.']; }
        if (!$si && (int) $f['activa'] === 1) {
            return ['ok' => false, 'error' => 'Es la cuenta con la que salen los correos: usa otra antes de deshabilitarla.'];
        }
        Db::ejecutar('UPDATE correo_cuentas SET habilitada = ?, actualizado_por = ?, actualizado_en = NOW() WHERE cuenta_id = ?',
                     [$si ? 1 : 0, $usuarioId, $cuentaId]);
        self::cambio($cuentaId, $si ? 'HABILITAR' : 'DESHABILITAR', ['habilitada' => (int) $f['habilitada']], ['habilitada' => $si ? 1 : 0], null, $usuarioId);
        Auth::bitacora($si ? 'CORREO_CUENTA_HABILITA' : 'CORREO_CUENTA_DESHABILITA', 'correo_cuenta', (string) $cuentaId, (string) $f['remitente']);
        return ['ok' => true, 'error' => null];
    }

    // ------------------------------------------------------------------
    //  El SMTP.
    // ------------------------------------------------------------------

    /** PHPMailer vive fuera de la carpeta web, junto a dompdf (app/lib/LEEME.md). */
    public static function cargarPhpMailer(): bool
    {
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) { return true; }
        $cfg = Db::config();
        foreach (array_filter([
            $cfg['dompdf_autoload'] ?? null,
            dirname(__DIR__, 5) . '/lib/ot/vendor/autoload.php',   // Hostinger: nucleo → ot → public_html → dominio → domains → ~
            dirname(__DIR__, 2) . '/lib/vendor/autoload.php',      // estación: nucleo → publico → app; ahí está app/lib
        ]) as $a) {
            if (is_file($a)) { require_once $a; break; }
        }
        return class_exists(\PHPMailer\PHPMailer\PHPMailer::class);
    }

    /** Un PHPMailer listo para esta cuenta, sin destinatarios. Lanza si falta algo. */
    public static function mailer(array $cuenta): \PHPMailer\PHPMailer\PHPMailer
    {
        if (!self::cargarPhpMailer()) {
            throw new RuntimeException('falta PHPMailer en el servidor (app/lib/LEEME.md)');
        }
        if (($cuenta['clave'] ?? null) === null) {
            throw new RuntimeException((string) ($cuenta['clave_error'] ?? 'la cuenta no tiene clave'));
        }
        $m = new \PHPMailer\PHPMailer\PHPMailer(true);
        $m->CharSet    = 'UTF-8';
        $m->isSMTP();
        $m->Host       = (string) $cuenta['host'];
        $m->Port       = (int) $cuenta['puerto'];
        $m->SMTPSecure = $cuenta['seguridad'] === 'SSL' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                                                        : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $m->SMTPAuth   = true;
        $m->Username   = (string) $cuenta['usuario'];
        $m->Password   = (string) $cuenta['clave'];
        $m->Timeout    = 30;
        $m->setFrom((string) $cuenta['remitente'], (string) $cuenta['remitente_nombre']);
        return $m;
    }

    /**
     * Conecta y se autentica, sin mandar nada. No anota.
     * @return array{ok:bool, detalle:string}
     */
    public static function probar(array $cuenta): array
    {
        try {
            $m = self::mailer($cuenta);
            $m->Timeout = 20;
            $m->smtpConnect();
            $m->smtpClose();
            return ['ok' => true, 'detalle' => 'conectó con ' . $cuenta['host'] . ':' . $cuenta['puerto']
                                            . ' y el servidor aceptó el usuario y la clave'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detalle' => self::limpiar($e->getMessage(), $cuenta['clave'] ?? null)];
        }
    }

    /** «Probar conexión» de Correos: prueba y lo anota en la cuenta. */
    public static function probarYAnotar(int $cuentaId, int $usuarioId): array
    {
        $c = self::cuenta($cuentaId);
        if ($c === null) { return ['ok' => false, 'detalle' => 'esa cuenta no existe']; }
        $r = self::probar($c);
        self::anotarPrueba($cuentaId, $r, 'PRUEBA_CONEXION', $usuarioId);
        return $r;
    }

    private static function anotarPrueba(int $cuentaId, array $r, string $accion, int $usuarioId): void
    {
        Db::ejecutar('UPDATE correo_cuentas SET probada_en = NOW(), probada_ok = ?, probada_detalle = ? WHERE cuenta_id = ?',
                     [$r['ok'] ? 1 : 0, mb_substr((string) $r['detalle'], 0, 300), $cuentaId]);
        self::cambio($cuentaId, $accion, null, null, ($r['ok'] ? 'OK: ' : 'FALLA: ') . $r['detalle'], $usuarioId);
        Auth::bitacora($accion === 'PRUEBA_ENVIO' ? 'CORREO_CUENTA_PRUEBA_ENVIO' : 'CORREO_CUENTA_PRUEBA', 'correo_cuenta',
                       (string) $cuentaId, (string) $r['detalle'], null, $r['ok'] ? 'OK' : 'FALLA', [], $r['ok']);
    }

    /**
     * Manda un correo de prueba, con un PDF adjunto, a una dirección de
     * INDUSTEC. Pasa por el mismo camino que una OT (PHPMailer, SMTP, adjunto)
     * sin serlo: el cuerpo no dice «nueva OT» para que el robot del buzón no lo
     * tome por una (t2_11_informes_ot.py busca «nueva OT:»).
     *
     * @return array{ok:bool, detalle:string, referencia:string}
     */
    public static function enviarPrueba(int $cuentaId, string $destino, int $usuarioId, string $quien): array
    {
        $ref = 'P-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $destino = strtolower(trim($destino));
        if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'detalle' => 'la dirección de destino no es válida', 'referencia' => $ref];
        }
        if (!str_ends_with($destino, '@industec.me')) {
            // Una prueba nunca va a Grupo KFC ni a un local.
            return ['ok' => false, 'detalle' => 'el correo de prueba solo va a un buzón @industec.me', 'referencia' => $ref];
        }
        $recientes = (int) (Db::uno("SELECT COUNT(*) n FROM correo_cuentas_cambios
                                      WHERE cuenta_id = ? AND accion = 'PRUEBA_ENVIO' AND en >= NOW() - INTERVAL 1 HOUR",
                                    [$cuentaId])['n'] ?? 0);
        if ($recientes >= self::PRUEBAS_POR_HORA) {
            return ['ok' => false, 'referencia' => $ref,
                    'detalle' => 'ya van ' . $recientes . ' correos de prueba en la última hora con esta cuenta; espera un rato (el servidor de correo corta en 50 por hora)'];
        }
        $c = self::cuenta($cuentaId);
        if ($c === null) { return ['ok' => false, 'detalle' => 'esa cuenta no existe', 'referencia' => $ref]; }

        $cuando = date('Y-m-d H:i');
        $cuerpo = "Este es un correo de prueba de B.IA Soft ERP para comprobar la cuenta de envío «{$c['nombre']}» ({$c['remitente']}).\n\n"
                . "No es una OT INDUSTEC y no hay que hacer nada con él. Lleva un PDF adjunto para comprobar que los adjuntos llegan.\n\n"
                . "Referencia: $ref\nEnviado: $cuando, por $quien.";
        $adjunto = null;
        $avisoAdjunto = '';
        try {
            $pdf = Emision::pdfSimple('<html><body style="font-family:DejaVu Sans;font-size:12px">'
                . '<h2>B.IA Soft ERP · correo de prueba</h2>'
                . '<p>Cuenta: ' . htmlspecialchars((string) $c['remitente'], ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>Referencia: ' . $ref . '</p><p>Fecha: ' . $cuando . '</p>'
                . '<p>Este documento no es una OT INDUSTEC.</p></body></html>');
            $adjunto = sys_get_temp_dir() . '/prueba_correo_' . $ref . '.pdf';
            file_put_contents($adjunto, $pdf);
        } catch (Throwable $e) {
            $adjunto = null;
            $avisoAdjunto = ' (sin adjunto: no se pudo generar el PDF)';
        }
        try {
            $m = self::mailer($c);
            $m->addAddress($destino);
            $m->Subject = 'Prueba de la cuenta de envío · B.IA Soft ERP · ' . $ref;
            $m->Body = $cuerpo;
            if ($adjunto !== null) { $m->addAttachment($adjunto, 'prueba-' . $ref . '.pdf'); }
            $m->send();
            $r = ['ok' => true, 'detalle' => 'enviado a ' . $destino . ' con la referencia ' . $ref . $avisoAdjunto];
        } catch (Throwable $e) {
            $r = ['ok' => false, 'detalle' => self::limpiar($e->getMessage(), $c['clave'] ?? null)];
        } finally {
            if ($adjunto !== null) { @unlink($adjunto); }
        }
        self::anotarPrueba($cuentaId, $r, 'PRUEBA_ENVIO', $usuarioId);
        return $r + ['referencia' => $ref];
    }

    // ------------------------------------------------------------------
    //  El despacho de la cola.
    // ------------------------------------------------------------------

    /** ¿Hay algún correo esperando su turno? Barato: se pregunta en cada OT recibida. */
    public static function hayPorDespachar(): bool
    {
        return Db::uno("SELECT 1 FROM email_queue
                         WHERE (estado = 'PENDIENTE' AND (proximo_intento_en IS NULL OR proximo_intento_en <= NOW()))
                            OR (estado = 'ENVIANDO' AND tomado_en < NOW() - INTERVAL 15 MINUTE)
                         LIMIT 1") !== null;
    }

    /**
     * Saca de la cola hasta $max correos PENDIENTE y los manda con la cuenta
     * activa. Lo de siempre del despachador (T2.14.6, T2.28.2): reclamo
     * atómico, tope diario y por hora, espera creciente, 5xx permanente, el
     * cupo por hora del SMTP sin gastar intentos. Y lo nuevo (T2.29):
     *
     *   - un fallo de CONEXIÓN o de clave no es culpa de ningún correo: se
     *     devuelven todos a PENDIENTE sin gastar intentos y se avisa una vez;
     *   - una OT con número del piloto, o emitida por una cuenta de prueba, no
     *     sale nunca aunque esté PENDIENTE: vuelve a RETENIDO;
     *   - `config.php` con emision_modo = PRUEBA (el freno de emergencia) no
     *     conecta a nada;
     *   - $segundos > 0 corta a tiempo (envio.php, después de responder).
     *
     * @return array{estado:string, enviados:int, temporales:int, fallidos:int, retenidos:int, lineas:string[]}
     */
    public static function despachar(int $max = 30, int $segundos = 0): array
    {
        $r = ['estado' => 'NADA', 'enviados' => 0, 'temporales' => 0, 'fallidos' => 0, 'retenidos' => 0, 'lineas' => []];
        $inicio = microtime(true);
        if (Emision::modoConfig() === 'PRUEBA') {
            $r['estado'] = 'FRENADO';
            $r['lineas'][] = 'config.php frena el envío de todo el sitio (emision_modo = PRUEBA): no se conecta';
            return $r;
        }
        $candado = @fopen(sys_get_temp_dir() . '/industec_correo.lock', 'c');
        if ($candado === false || !flock($candado, LOCK_EX | LOCK_NB)) {
            $r['estado'] = 'OCUPADO';
            $r['lineas'][] = 'otro despacho sigue en marcha';
            return $r;
        }
        try {
            if (!self::hayPorDespachar()) {
                $r['lineas'][] = 'nada pendiente de enviar';
                return $r;
            }
            $cuenta = self::cuentaActiva();
            if ($cuenta === null || $cuenta['clave'] === null) {
                $r['estado'] = 'SIN_CUENTA';
                $r['lineas'][] = $cuenta === null ? 'no hay ninguna cuenta de envío activa (Correos → Cuenta de envío)'
                                                  : 'la cuenta de envío no tiene una clave legible: ' . $cuenta['clave_error'];
                return $r;
            }
            if (!self::cargarPhpMailer()) {
                $r['estado'] = 'SIN_PHPMAILER';
                $r['lineas'][] = 'falta PHPMailer: composer install en ~/lib/ot (app/lib/LEEME.md)';
                return $r;
            }

            // --- Los topes ---------------------------------------------------
            $tope = (int) (Db::config()['correo_tope_dia'] ?? 900);
            $hoy = (int) (Db::uno("SELECT COUNT(*) n FROM email_queue WHERE estado = 'ENVIADO' AND enviado_en >= CURDATE()")['n'] ?? 0);
            if ($hoy >= $tope) {
                $r['estado'] = 'TOPE_DIA';
                $r['lineas'][] = "tope diario alcanzado ($hoy de $tope): se reanuda mañana";
                return $r;
            }
            $hora = (int) (Db::uno("SELECT COUNT(*) n FROM email_queue WHERE estado = 'ENVIADO' AND enviado_en >= NOW() - INTERVAL 1 HOUR")['n'] ?? 0);
            if (Despacho::superoTopeHora($hora)) {
                $r['estado'] = 'TOPE_HORA';
                $r['lineas'][] = "tope por hora alcanzado ($hora de " . Despacho::TOPE_HORA . '): no se conecta esta vez';
                return $r;
            }
            $max = max(1, min($max, $tope - $hoy, Despacho::TOPE_HORA - $hora));

            // --- El reclamo atómico -------------------------------------------
            $marca = 'reclamo ' . bin2hex(random_bytes(6));
            Db::ejecutar(
                "UPDATE email_queue SET estado = 'ENVIANDO', tomado_en = NOW(), error_ultimo = ?
                  WHERE (estado = 'PENDIENTE' AND (proximo_intento_en IS NULL OR proximo_intento_en <= NOW()))
                     OR (estado = 'ENVIANDO' AND tomado_en < NOW() - INTERVAL 15 MINUTE)
                  ORDER BY creado_en LIMIT " . $max,
                [$marca]
            );
            $lote = Db::todos(
                "SELECT q.*, u.usuario AS emisor_usuario
                   FROM email_queue q
                   LEFT JOIN ot_capturadas c ON c.captura_id = q.captura_id
                   LEFT JOIN usuarios u ON u.usuario_id = c.usuario_id
                  WHERE q.estado = 'ENVIANDO' AND q.error_ultimo = ? ORDER BY q.creado_en",
                [$marca]
            );
            if ($lote === []) {
                $r['lineas'][] = 'nada pendiente de enviar';
                return $r;
            }
            $r['estado'] = 'HECHO';

            // --- Lo que nunca sale ----------------------------------------------
            $salen = [];
            foreach ($lote as $c) {
                if (Emision::esDePrueba((string) $c['id_industec']) || str_contains((string) ($c['emisor_usuario'] ?? ''), '_prueba')) {
                    Db::ejecutar("UPDATE email_queue SET estado = 'RETENIDO', tomado_en = NULL, error_ultimo = NULL,
                                         motivo = 'OT del piloto o de una cuenta de prueba: su correo no sale'
                                   WHERE correo_id = ?", [$c['correo_id']]);
                    $r['retenidos']++;
                    $r['lineas'][] = "  retenido {$c['id_industec']}: del piloto o de una cuenta de prueba";
                    continue;
                }
                $salen[] = $c;
            }
            if ($salen === []) { return $r; }

            // --- Una sola conexión para el lote ----------------------------------
            try {
                $m = self::mailer($cuenta);
                $m->SMTPKeepAlive = true;
                $m->smtpConnect();
            } catch (Throwable $e) {
                $msg = self::limpiar($e->getMessage(), $cuenta['clave']);
                self::devolver(array_column($salen, 'correo_id'), 15, 'la cuenta de envío no conectó: ' . $msg);
                $r['estado'] = 'CUENTA_FALLA';
                $r['temporales'] = count($salen);
                $r['lineas'][] = 'la cuenta de envío no conectó (' . $msg . '): los ' . count($salen)
                               . ' correos vuelven a la cola sin gastar intentos';
                Auth::bitacora('CORREO_CUENTA_FALLA', 'correo_cuenta', (string) ($cuenta['cuenta_id'] ?? 'config.php'), $msg,
                               null, null, ['correos' => count($salen)], false);
                return $r;
            }

            foreach ($salen as $i => $c) {
                if ($segundos > 0 && (microtime(true) - $inicio) > $segundos) {
                    self::devolver(array_column(array_slice($salen, $i), 'correo_id'), 0, null);
                    $r['lineas'][] = '  se acabó el tiempo: ' . (count($salen) - $i) . ' vuelven a la cola';
                    break;
                }
                $intento = (int) $c['intentos'] + 1;
                try {
                    $m->clearAddresses();
                    $m->clearCCs();
                    $m->clearAttachments();
                    $para = json_decode((string) $c['para'], true) ?: [];
                    foreach ($para as $d) { if (filter_var($d, FILTER_VALIDATE_EMAIL)) { $m->addAddress($d); } }
                    if ($m->getToAddresses() === []) { throw new RuntimeException('550 sin destinatarios válidos'); }
                    foreach ((json_decode((string) ($c['cc'] ?? ''), true) ?: []) as $d) {
                        if (filter_var($d, FILTER_VALIDATE_EMAIL)) { $m->addCC($d); }
                    }
                    $m->Subject = (string) $c['asunto'];
                    $m->Body    = (string) $c['cuerpo'];
                    if ($c['adjunto']) {
                        $ruta = Emision::dirPdf() . '/' . basename((string) $c['adjunto']);
                        if (!is_file($ruta)) { throw new RuntimeException('450 el PDF todavía no está en el servidor: ' . basename($ruta)); }
                        $m->addAttachment($ruta);
                    }
                    $m->send();
                    Db::ejecutar("UPDATE email_queue
                                     SET estado = 'ENVIADO', enviado_en = NOW(), enviado_desde = ?, intentos = ?, ultimo_intento_en = NOW(),
                                         error_ultimo = NULL, proximo_intento_en = NULL, tomado_en = NULL
                                   WHERE correo_id = ?", [$cuenta['remitente'], $intento, $c['correo_id']]);
                    Db::ejecutar("UPDATE ot_capturadas SET estado = 'ENVIADA' WHERE captura_id = ? AND estado = 'EMITIDA'",
                                 [$c['captura_id']]);
                    Auth::bitacora('CORREO_ENVIADO', 'ot', (string) $c['id_industec'],
                                   count($para) . ' destinatarios · desde ' . $cuenta['remitente'], null, 'ENVIADO',
                                   ['correo_id' => $c['correo_id'], 'intento' => $intento]);
                    $r['enviados']++;
                    $r['lineas'][] = "  enviado  {$c['id_industec']} (intento $intento, " . count($para) . ' destinatarios)';
                } catch (Throwable $e) {
                    $msg = self::limpiar($e->getMessage(), $cuenta['clave']);
                    try { $m->getSMTPInstance()->reset(); } catch (Throwable $e2) { /* la conexión se rehace sola */ }
                    $k = Despacho::clasificar($msg, (int) $c['intentos']);
                    if ($k['permanente']) {
                        Db::ejecutar("UPDATE email_queue SET estado = 'FALLIDO', intentos = ?, ultimo_intento_en = NOW(), error_ultimo = ?,
                                             motivo = ?, tomado_en = NULL WHERE correo_id = ?",
                                     [$k['intentosGuardar'], $msg, $k['motivo'], $c['correo_id']]);
                        Auth::bitacora('CORREO_FALLIDO', 'ot', (string) $c['id_industec'], $msg, null, 'FALLIDO',
                                       ['correo_id' => $c['correo_id'], 'intento' => $k['intentosGuardar']], false);
                        $r['fallidos']++;
                        $r['lineas'][] = "  FALLIDO  {$c['id_industec']}: $msg";
                    } else {
                        Db::ejecutar("UPDATE email_queue SET estado = 'PENDIENTE', intentos = ?, ultimo_intento_en = NOW(), error_ultimo = ?,
                                             proximo_intento_en = NOW() + INTERVAL ? MINUTE, tomado_en = NULL WHERE correo_id = ?",
                                     [$k['intentosGuardar'], $msg, $k['espera'], $c['correo_id']]);
                        $r['temporales']++;
                        $r['lineas'][] = "  reintento {$c['id_industec']} en {$k['espera']} min"
                                       . ($k['cupoHora'] ? ' (cupo por hora del SMTP)' : '') . ": $msg";
                        if ($k['cupoHora']) {
                            // El SMTP ya cortó: los demás del lote esperarían lo mismo.
                            self::devolver(array_column(array_slice($salen, $i + 1), 'correo_id'), 60, null);
                            $r['lineas'][] = '  el SMTP cortó por cupo: el resto del lote vuelve en 60 min';
                            break;
                        }
                    }
                }
            }
            try { $m->smtpClose(); } catch (Throwable $e) { /* nada que hacer */ }
            return $r;
        } finally {
            flock($candado, LOCK_UN);
            fclose($candado);
        }
    }

    /** Devuelve correos reclamados a PENDIENTE sin gastar intentos. */
    private static function devolver(array $ids, int $esperaMin, ?string $motivo): void
    {
        foreach ($ids as $id) {
            Db::ejecutar("UPDATE email_queue
                             SET estado = 'PENDIENTE', tomado_en = NULL,
                                 error_ultimo = ?, proximo_intento_en = IF(? > 0, NOW() + INTERVAL ? MINUTE, NULL)
                           WHERE correo_id = ? AND estado = 'ENVIANDO'",
                         [$motivo !== null ? mb_substr($motivo, 0, 300) : null, $esperaMin, $esperaMin, $id]);
        }
    }

    /**
     * Cómo va la cola, para Correos: cuántos por estado hoy y lo último.
     * @return array<string,mixed>
     */
    public static function resumenCola(): array
    {
        $out = ['PENDIENTE' => 0, 'ENVIANDO' => 0, 'FALLIDO' => 0, 'RETENIDO' => 0, 'ENVIADO_HOY' => 0, 'ENVIADO_HORA' => 0,
                'ultimo_enviado' => null, 'ultimo_error' => null];
        foreach (Db::todos("SELECT estado, COUNT(*) n FROM email_queue WHERE estado IN ('PENDIENTE','ENVIANDO','FALLIDO','RETENIDO') GROUP BY estado") as $f) {
            $out[$f['estado']] = (int) $f['n'];
        }
        $out['ENVIADO_HOY'] = (int) (Db::uno("SELECT COUNT(*) n FROM email_queue WHERE estado = 'ENVIADO' AND enviado_en >= CURDATE()")['n'] ?? 0);
        $out['ENVIADO_HORA'] = (int) (Db::uno("SELECT COUNT(*) n FROM email_queue WHERE estado = 'ENVIADO' AND enviado_en >= NOW() - INTERVAL 1 HOUR")['n'] ?? 0);
        $out['ultimo_enviado'] = Db::uno("SELECT id_industec, enviado_en, enviado_desde FROM email_queue WHERE estado = 'ENVIADO'
                                          ORDER BY enviado_en DESC LIMIT 1");
        $out['ultimo_error'] = Db::uno("SELECT id_industec, estado, error_ultimo, ultimo_intento_en FROM email_queue
                                         WHERE error_ultimo IS NOT NULL AND error_ultimo NOT LIKE 'reclamo %'
                                           AND estado IN ('PENDIENTE','FALLIDO') ORDER BY ultimo_intento_en DESC LIMIT 1");
        return $out;
    }
}
