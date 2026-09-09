<?php
declare(strict_types=1);

/**
 * Db.php — Conexión a la base operativa.
 *
 * Las credenciales viven en nucleo/config.php, que NO está en git y NO se sirve
 * por web (nucleo/ lleva su propio .htaccess que niega todo). El sistema viejo
 * guardaba la clave del SMTP en texto plano en cinco copias dentro del docroot;
 * esto es lo contrario de eso.
 */

final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $cfg = require __DIR__ . '/config.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['db_host'], $cfg['db_port'], $cfg['db_name']
        );
        self::$pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            // Que un error de SQL sea una excepción y no un valor de retorno que
            // nadie mira: los fallos silenciosos son los que corrompen datos.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Consultas preparadas de verdad, no emuladas: es lo que impide la
            // inyección de SQL aunque alguien concatene por descuido.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return self::$pdo;
    }

    /** @return array<int,array<string,mixed>> */
    public static function todos(string $sql, array $params = []): array
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function uno(string $sql, array $params = []): ?array
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        $f = $st->fetch();
        return $f === false ? null : $f;
    }

    public static function ejecutar(string $sql, array $params = []): int
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function config(): array
    {
        return require __DIR__ . '/config.php';
    }
}
