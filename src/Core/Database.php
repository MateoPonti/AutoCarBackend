<?php
declare(strict_types=1);

/** Conexión PDO única. Todas las consultas del proyecto usan sentencias preparadas. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST', '127.0.0.1'),
            Config::get('DB_PORT', '3306'),
            Config::get('DB_NAME', 'autocar')
        );
        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // Aiven exige SSL: el certificado público va en database/ca.pem (o en DB_SSL_CA).
        $ca = Config::get('DB_SSL_CA') ?? BASE_PATH . '/database/ca.pem';
        if ($ca[0] !== '/') {
            $ca = BASE_PATH . '/' . $ca;
        }
        if (is_file($ca)) {
            $opciones[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        }

        self::$pdo = new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASS', ''), $opciones);
        return self::$pdo;
    }
}
