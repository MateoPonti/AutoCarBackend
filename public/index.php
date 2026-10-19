<?php
/**
 * Front controller de la API: todo pedido que no sea un archivo real llega acá (ver public/.htaccess).
 *
 * En local:  php -S localhost:8000 -t public public/index.php
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$path = rtrim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/') ?: '/';

if ($path === '/' || $path === '/health') { // chequeo de vida, sin tocar la base
    Response::json(['servicio' => 'AutoCar API', 'estado' => 'ok']);
}

$router = new Router();
require SRC_PATH . '/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
