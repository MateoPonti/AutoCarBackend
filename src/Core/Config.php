<?php
declare(strict_types=1);

namespace AutoCar\Core;

/**
 * Configuración: primero variables de entorno reales (Render), y como alternativa el .env local.
 */
final class Config
{
    private static array $env = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            self::$env[trim($k)] = trim($v, " \t\"'");
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }
        return self::$env[$key] ?? $default;
    }
}
