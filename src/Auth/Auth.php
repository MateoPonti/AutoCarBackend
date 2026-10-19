<?php
declare(strict_types=1);

/** Sesión y control de acceso. El rol se consulta en la base, no se confía en lo guardado en la sesión. */
final class Auth
{
    public static function user(): ?array
    {
        $u = $_SESSION['user'] ?? null;
        return is_array($u) ? $u : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && Usuario::rolDe((int)$u['id']) === 'admin';
    }

    public static function login(array $usuario): void
    {
        session_regenerate_id(true); // evita fijación de sesión
        $_SESSION['user'] = [
            'id'     => (int)$usuario['id'],
            'nombre' => (string)$usuario['nombre'],
            'rol'    => (string)$usuario['rol'],
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => $p['path'],
                'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            flash_set('Ingresá para continuar.');
            redirect('/login');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            View::error(403, 'No tenés permisos para entrar a esta sección.');
        }
    }
}
