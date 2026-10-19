<?php
declare(strict_types=1);

/**
 * Identidad del pedido a partir del token Bearer. El rol NO se toma del token:
 * se consulta en la base, así un cambio de rol o un usuario borrado surten efecto de inmediato.
 */
final class Auth
{
    private static ?array $claims = null;
    private static bool $leido = false;

    public static function ttl(): int
    {
        return max(300, (int)(Config::get('JWT_TTL', '7200'))); // 2 horas por defecto
    }

    public static function emitirToken(array $usuario): string
    {
        return Jwt::emitir(['sub' => (int)$usuario['id']], self::ttl());
    }

    /** ID del usuario autenticado, o null. */
    public static function id(): ?int
    {
        if (!self::$leido) {
            self::$leido = true;
            $token = Request::bearer();
            self::$claims = $token !== null ? Jwt::verificar($token) : null;
        }
        return self::$claims !== null ? (int)self::$claims['sub'] : null;
    }

    public static function requireLogin(): void
    {
        if (self::id() === null) {
            header('WWW-Authenticate: Bearer');
            Response::error(401, 'no_autenticado', 'Necesitás iniciar sesión.');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (Usuario::rolDe((int)self::id()) !== 'admin') {
            Response::error(403, 'prohibido', 'No tenés permisos para esta acción.');
        }
    }
}
