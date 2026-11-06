<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

use PDO;
use RuntimeException;

/**
 * Prepara una vez por corrida la base DESCARTABLE y la API real (php -S) que usan las pruebas de integración.
 * La base se borra entera: por eso se niega a correr contra Neon o contra la base de desarrollo (autocar en 5433).
 */
final class Entorno
{
    private static ?string $url = null;
    /** @var resource|null */
    private static $proceso = null;
    private static ?PDO $pdo = null;

    public static function db(): array
    {
        return [
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TEST_DB_PORT') ?: '5434',
            'name' => getenv('TEST_DB_NAME') ?: 'autocar_test',
            'user' => getenv('TEST_DB_USER') ?: 'autocar',
            'pass' => getenv('TEST_DB_PASS') ?: 'autocar',
        ];
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $db = self::db();
            self::$pdo = new PDO(
                "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']};sslmode=disable",
                $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        }
        return self::$pdo;
    }

    /** URL base de la API levantada para las pruebas (la levanta la primera vez). */
    public static function url(): string
    {
        if (self::$url === null) {
            self::recrearBase();
            self::levantarApi();
        }
        return self::$url;
    }

    private static function recrearBase(): void
    {
        $db = self::db();
        if (str_contains($db['host'], 'neon.tech') || !str_ends_with($db['name'], '_test')) {
            throw new RuntimeException(
                "Las pruebas de integración BORRAN la base: solo corren contra una base cuyo nombre termine en _test " .
                "y que no sea de Neon (recibido {$db['name']} en {$db['host']}). Ver phpunit.xml.dist.");
        }
        $raiz = dirname(__DIR__, 2);
        $pdo = self::pdo();
        $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');
        $pdo->exec((string)file_get_contents("$raiz/database/schema.sql"));
        $pdo->exec((string)file_get_contents("$raiz/database/seed.sql"));
    }

    private static function levantarApi(): void
    {
        $raiz = dirname(__DIR__, 2);
        $socket = stream_socket_server('tcp://127.0.0.1:0'); // puerto libre que elige el sistema
        $puerto = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $db = self::db();
        $env = getenv() + [];
        $env = array_merge($env, [
            'DB_HOST' => $db['host'], 'DB_PORT' => $db['port'], 'DB_NAME' => $db['name'],
            'DB_USER' => $db['user'], 'DB_PASS' => $db['pass'], 'DB_SSLMODE' => 'disable',
            'FRONT_URL' => 'http://front.test',
            'SESION_TTL' => '7200',
        ]);
        $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'autocar-api-pruebas.log';
        self::$proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$puerto", '-t', 'public', 'public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes, $raiz, $env,
        );
        if (!is_resource(self::$proceso)) {
            throw new RuntimeException('No se pudo iniciar php -S.');
        }
        register_shutdown_function(static function (): void {
            if (is_resource(self::$proceso)) {
                proc_terminate(self::$proceso);
            }
        });

        self::$url = "http://127.0.0.1:$puerto";
        for ($i = 0; $i < 50; $i++) { // hasta 5 segundos
            if (@file_get_contents(self::$url . '/health') !== false) {
                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException("La API de prueba no respondió en " . self::$url . " (ver $log).");
    }
}
