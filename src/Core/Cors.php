<?php
declare(strict_types=1);

namespace AutoCar\Core;

/**
 * CORS: solo se habilitan los orígenes listados en FRONT_URL (separados por coma).
 * En producción el front llama a la API por el proxy de Vercel (mismo origen) y CORS no interviene;
 * esto sirve en local (front en localhost:5500, API en localhost:8000). Allow-Credentials permite que
 * el navegador mande y guarde la cookie de sesión en esos pedidos.
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
            header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 600');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
