<?php
/**
 * STUB DE VISTA PREVIA. Fede lo reemplaza con el front controller y el router reales.
 * Sirve sólo para mirar las pantallas de Auth antes de que existan el router, Auth y Csrf:
 *
 *   php -S localhost:8000 -t public public/index.php
 *
 * Abrí /login, /login?demo=error, /login?demo=ok, /registro y /registro?demo=errores.
 * Los POST no hacen nada real: sólo vuelven a mostrar la pantalla con errores de ejemplo.
 */
declare(strict_types=1);

// Con el servidor embebido de PHP, los archivos estáticos se sirven solos.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . $path)) {
    return false;
}

// Stub de la clase Csrf de Mateo (app/Auth/Csrf.php). Se descarta cuando exista la real.
if (!class_exists('Csrf')) {
    class Csrf
    {
        public static function field(): string
        {
            return '<input type="hidden" name="csrf_token" value="stub">';
        }
    }
}

$viewsDir = __DIR__ . '/../app/Auth/views';
$demo = $_GET['demo'] ?? '';
$esPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

switch ($path) {
    case '/login':
        $old = ['email' => $_POST['email'] ?? ''];
        $errors = [];
        $flash = null;
        if ($esPost || $demo === 'error') {
            $errors['general'] = 'El email o la contraseña no son correctos.';
        }
        if ($demo === 'ok') {
            $flash = 'Cuenta creada. Ya podés ingresar.';
        }
        require $viewsDir . '/login.php';
        break;

    case '/registro':
        $old = ['nombre' => $_POST['nombre'] ?? '', 'email' => $_POST['email'] ?? ''];
        $errors = [];
        if ($esPost || $demo === 'errores') {
            $errors = [
                'email' => 'Ese email ya tiene una cuenta. Ingresá o usá otro.',
                'password' => 'La contraseña necesita al menos una letra y un número.',
                'password_confirm' => 'Las contraseñas no coinciden.',
            ];
        }
        require $viewsDir . '/registro.php';
        break;

    default:
        http_response_code(404);
        echo 'Página no encontrada (stub). Probá /login o /registro.';
}
