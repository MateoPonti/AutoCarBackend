<?php
declare(strict_types=1);

/**
 * CORS: solo se habilitan los orígenes listados en FRONT_URL (separados por coma).
 * Se usa token Bearer (no cookies), por eso no hace falta Allow-Credentials.
 */
final class Cors
{
    public static function handle(): void
    {
        $permitidos = array_filter(array_map(
            static fn(string $o): string => rtrim(trim($o), '/'),
            explode(',', Config::get('FRONT_URL', '') ?? '')
        ));
        $origen = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');

        header('Vary: Origin');
        if ($origen !== '' && in_array($origen, $permitidos, true)) {
            header('Access-Control-Allow-Origin: ' . $origen);
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            header('Access-Control-Max-Age: 600');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
