<?php
declare(strict_types=1);

/** Token CSRF por sesión. Se verifica en el servidor en TODOS los POST (ver public/index.php). */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(self::token()) . '">';
    }

    public static function verify(mixed $enviado): bool
    {
        return is_string($enviado) && $enviado !== '' && hash_equals(self::token(), $enviado);
    }
}
