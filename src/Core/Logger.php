<?php
declare(strict_types=1);

namespace AutoCar\Core;

/** Registra errores en storage/logs/app.log (fuera de public/) y en el log del servidor. */
final class Logger
{
    public static function error(string $mensaje): void
    {
        $linea = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $mensaje);
        @file_put_contents(BASE_PATH . '/storage/logs/app.log', $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
        error_log($linea); // en Render aparece en la pestaña Logs
    }
}
