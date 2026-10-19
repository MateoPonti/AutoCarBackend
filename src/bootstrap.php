<?php
/**
 * Arranque común: autoload, configuración, manejo de errores, sesión y cabeceras de seguridad.
 * Lo carga public/index.php antes de despachar la ruta.
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('SRC_PATH', __DIR__);

// Autoload sin Composer: la clase Foo se busca en src/{Core,Auth,Catalogo,Admin}/Foo.php.
// En Linux las mayúsculas importan: el archivo se llama igual que la clase.
spl_autoload_register(static function (string $class): void {
    foreach (['Core', 'Auth', 'Catalogo', 'Admin'] as $dir) {
        $file = SRC_PATH . "/$dir/$class.php";
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

require SRC_PATH . '/Core/helpers.php';

Config::load(BASE_PATH . '/.env');

// ---------- Errores: nunca se muestran en pantalla (salvo APP_DEBUG=1 en local) ----------
$debug = Config::get('APP_DEBUG', '0') === '1';
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no) || in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

set_exception_handler(static function (Throwable $e) use ($debug): void {
    Logger::error(get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if ($debug) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $e;
        return;
    }
    View::error(500);
});

// ---------- Sesión ----------
$https = (($_SERVER['HTTPS'] ?? '') === 'on')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); // Render termina el HTTPS en su proxy
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('autocar_sid');
session_start();

// ---------- Cabeceras de seguridad ----------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com; "
     . "font-src https://fonts.gstatic.com; img-src 'self' data:; script-src 'self'; "
     . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}
