<?php
/**
 * Front controller: todo pedido que no sea un archivo real llega acá (ver public/.htaccess).
 *
 * En local:  php -S localhost:8000 -t public public/index.php
 */
declare(strict_types=1);

// Con el servidor embebido de PHP, los archivos estáticos se sirven solos.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . $path)) {
    return false;
}

require __DIR__ . '/../src/bootstrap.php';

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/health') { // para chequear que el servicio está vivo, sin tocar la base
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
    exit;
}

// Todo POST lleva token CSRF.
if ($metodo === 'POST' && !Csrf::verify($_POST['csrf_token'] ?? null)) {
    View::error(403, 'El formulario venció o no es válido. Volvé atrás, recargá la página e intentá de nuevo.');
}

$router = new Router();
require SRC_PATH . '/routes.php';
$router->dispatch($metodo, $_SERVER['REQUEST_URI'] ?? '/');
