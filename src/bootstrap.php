<?php
/**
 * Arranque común de la API: autoload (Composer), configuración, manejo de errores, CORS y cabeceras.
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('SRC_PATH', __DIR__);

// Autoload de Composer (ver composer.json): carga las librerías de vendor/ y también las clases
// propias, que se buscan en src/{Core,Auth,Catalogo,Admin}/Foo.php. En Linux las mayúsculas importan.
// vendor/ no está en el repo: se genera con `composer install` (en Docker lo hace el Dockerfile).
$autoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($autoload)) {
    error_log('Falta vendor/autoload.php: ejecutá "composer install".');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    exit('{"error":"servidor","mensaje":"Ocurrió un error inesperado. Probá de nuevo en unos minutos."}');
}
require $autoload;

Config::load(BASE_PATH . '/.env');

// ---------- Errores: nunca se muestran al cliente; se registran en storage/logs/app.log ----------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no) || in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    Logger::error(get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    Response::error(500, 'servidor', 'Ocurrió un error inesperado. Probá de nuevo en unos minutos.');
});

// ---------- Cabeceras ----------
header('X-Content-Type-Options: nosniff');
$https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}

Cors::handle(); // responde el preflight OPTIONS y habilita solo al front de FRONT_URL
