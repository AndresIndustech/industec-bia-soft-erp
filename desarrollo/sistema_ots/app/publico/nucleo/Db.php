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

/*
 * LA HORA DEL NEGOCIO ES LA DE ECUADOR, en la base y en PHP a la vez.
 *
 * Hostinger corre MariaDB y PHP en UTC. Con eso una orden de las 19:30 caía
 * «mañana», los reportes cortaban el día a las 19:00 y el «hace 3 h» de las
 * pantallas salía corrido cinco horas (T2.13.7, medido el 2026-09-11). Se fija
 * aquí porque todo lo que toca la base pasa por este archivo, en la web y en la
 * consola. Lo que el servidor guardó antes en UTC se corrió una sola vez con
 * `pruebas/servidor/hora_ecuador.php`.
 */
date_default_timezone_set('America/Guayaquil');

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
            // La intercalación de la conexión, fijada a la de las tablas. Con
            // preparadas nativas, MariaDB le da a cada parámetro la intercalación
            // por defecto del juego (utf8mb4_general_ci) y a los literales la del
            // servidor (utf8mb4_unicode_ci en Hostinger): `NULLIF(?, '')` fallaba
            // con «Illegal mix of collations» y tumbaba el veredicto, el cierre de
            // un pendiente y la resolución de novedades (medido el 2026-09-11).
            // Y la zona de la conexión, la misma que la de PHP (arriba): NOW(),
            // CURRENT_TIMESTAMP y FROM_UNIXTIME salen en hora de Ecuador. Va el
            // desfase y no el nombre porque Hostinger no tiene cargadas las
            // zonas con nombre (error 1298); Ecuador no cambia de hora en el año.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '-05:00'",
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
