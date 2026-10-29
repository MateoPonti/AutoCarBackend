<?php
declare(strict_types=1);

namespace AutoCar\Auth;

use AutoCar\Core\Config;
use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * Envoltorio mínimo sobre firebase/php-jwt (HS256), para que el resto del código
 * siga usando Jwt::emitir() y Jwt::verificar(). El secreto viene de JWT_SECRET (mínimo 32 caracteres).
 */
final class Jwt
{
    public static function emitir(array $claims, int $ttl): string
    {
        $ahora = time();
        $payload = $claims + ['iat' => $ahora, 'exp' => $ahora + $ttl];
        return FirebaseJwt::encode($payload, self::secreto(), 'HS256');
    }

    /** Devuelve los claims si el token es válido y no venció; si no, null. */
    public static function verificar(string $token): ?array
    {
        // Fuera del try: si falta JWT_SECRET es un error de configuración (500), no un token inválido (401)
        $secreto = self::secreto();

        try {
            // Key(..., 'HS256') obliga a ese algoritmo: la librería rechaza "none" y cualquier otro
            $claims = (array)FirebaseJwt::decode($token, new Key($secreto, 'HS256'));
        } catch (\UnexpectedValueException|\DomainException|\InvalidArgumentException) {
            // Firma incorrecta, token vencido (ExpiredException), mal formado, etc.
            return null;
        }

        return isset($claims['exp'], $claims['sub']) ? $claims : null;
    }

    private static function secreto(): string
    {
        $s = Config::get('JWT_SECRET');
        if ($s === null || strlen($s) < 32) {
            throw new RuntimeException('JWT_SECRET no está configurado (mínimo 32 caracteres).');
        }
        return $s;
    }
}
