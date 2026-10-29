<?php
declare(strict_types=1);

namespace AutoCar\Core;

use PDO;

/** Conexión PDO única (PostgreSQL / Neon). Todas las consultas del proyecto usan sentencias preparadas. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        // Neon exige SSL (require). En un Postgres local sin SSL se usa DB_SSLMODE=disable.
        $ssl = Config::get('DB_SSLMODE', 'require');
        if (!in_array($ssl, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            $ssl = 'require';
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;connect_timeout=15',
            Config::get('DB_HOST', '127.0.0.1'),
            Config::get('DB_PORT', '5432'),
            Config::get('DB_NAME', 'neondb'),
            $ssl
        );

        self::$pdo = new PDO($dsn, Config::get('DB_USER', 'postgres'), Config::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return self::$pdo;
    }
}
