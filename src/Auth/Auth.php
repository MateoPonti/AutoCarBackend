<?php
declare(strict_types=1);

namespace AutoCar\Auth;

use AutoCar\Core\Config;
use AutoCar\Core\Response;

/**
 * Sesión con cookie: el JWT viaja en la cookie "autocar_sesion" (HttpOnly, así JavaScript no la puede leer
 * aunque hubiera un XSS). Como el navegador manda la cookie solo, los pedidos que cambian datos llevan
 * además el header X-CSRF-Token (ver requireCsrf).
 *
 * El rol NO se toma del token: se consulta en la base, así un cambio de rol o un usuario borrado
 * surten efecto de inmediato.
 */
final class Auth
{
    public const COOKIE = 'autocar_sesion';

    private static ?array $claims = null;
    private static bool $leido = false;

    public static function ttl(): int
    {
        return max(300, (int)(Config::get('JWT_TTL', '7200'))); // 2 horas por defecto
    }

    /** Emite el JWT y lo deja en la cookie de sesión. */
    public static function iniciarSesion(array $usuario): void
    {
        $token = Jwt::emitir(['sub' => (int)$usuario['id']], self::ttl());
        self::cookie($token, time() + self::ttl());
        $_COOKIE[self::COOKIE] = $token; // para que csrf() ya lo vea en esta misma respuesta
        self::$leido = false;
    }

    public static function cerrarSesion(): void
    {
        self::cookie('', time() - 3600); // vencida: el navegador la borra
    }

    /**
     * Secure: solo viaja por HTTPS (en local, con http, no se puede poner o el navegador la descarta).
     * SameSite=Strict: el navegador no la manda en pedidos que se originan en otro sitio (primera barrera contra CSRF).
     */
    private static function cookie(string $valor, int $vence): void
    {
        setcookie(self::COOKIE, $valor, [
            'expires'  => $vence,
            'path'     => '/',
            'secure'   => self::https(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function https(): bool
    {
        // Detrás de Render (y del proxy de Vercel) PHP recibe http; el protocolo real viene en X-Forwarded-Proto
        return (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** ID del usuario autenticado, o null. */
    public static function id(): ?int
    {
        if (!self::$leido) {
            self::$leido = true;
            $token = $_COOKIE[self::COOKIE] ?? null;
            self::$claims = is_string($token) && $token !== '' ? Jwt::verificar($token) : null;
        }
        return self::$claims !== null ? (int)self::$claims['sub'] : null;
    }

    /**
     * Token CSRF ligado a la sesión: HMAC del JWT con JWT_SECRET. No hace falta guardarlo en la base,
     * se recalcula en cada pedido. Otro sitio no lo puede conocer porque no puede leer la cookie ni las respuestas.
     */
    public static function csrf(): ?string
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        return is_string($token) && $token !== '' ? Jwt::firmar($token) : null;
    }

    public static function requireLogin(): void
    {
        if (self::id() === null) {
            Response::error(401, 'no_autenticado', 'Necesitás iniciar sesión.');
        }
    }

    /** Para POST, PUT y DELETE con sesión: el header X-CSRF-Token tiene que coincidir con csrf(). */
    public static function requireCsrf(): void
    {
        $esperado = self::csrf();
        $recibido = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($esperado === null || !is_string($recibido) || !hash_equals($esperado, $recibido)) {
            Response::error(403, 'csrf', 'La sesión no es válida. Recargá la página e intentá de nuevo.');
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
