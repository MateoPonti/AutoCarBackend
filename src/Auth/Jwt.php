<?php
declare(strict_types=1);

/** JWT HS256 mínimo, sin librerías. El secreto viene de JWT_SECRET (mínimo 32 caracteres). */
final class Jwt
{
    public static function emitir(array $claims, int $ttl): string
    {
        $ahora = time();
        $payload = $claims + ['iat' => $ahora, 'exp' => $ahora + $ttl];
        $h = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $p = self::b64(json_encode($payload, JSON_THROW_ON_ERROR));
        return "$h.$p." . self::b64(hash_hmac('sha256', "$h.$p", self::secreto(), true));
    }

    /** Devuelve los claims si el token es válido y no venció; si no, null. */
    public static function verificar(string $token): ?array
    {
        $partes = explode('.', $token);
        if (count($partes) !== 3) {
            return null;
        }
        [$h, $p, $firma] = $partes;

        $cabecera = json_decode((string)self::deb64($h), true);
        if (!is_array($cabecera) || ($cabecera['alg'] ?? '') !== 'HS256') { // rechaza "none" y otros algoritmos
            return null;
        }
        $esperada = self::b64(hash_hmac('sha256', "$h.$p", self::secreto(), true));
        if (!hash_equals($esperada, $firma)) {
            return null;
        }
        $claims = json_decode((string)self::deb64($p), true);
        if (!is_array($claims) || !isset($claims['exp'], $claims['sub']) || (int)$claims['exp'] < time()) {
            return null;
        }
        return $claims;
    }

    private static function secreto(): string
    {
        $s = Config::get('JWT_SECRET');
        if ($s === null || strlen($s) < 32) {
            throw new RuntimeException('JWT_SECRET no está configurado (mínimo 32 caracteres).');
        }
        return $s;
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function deb64(string $txt): string|false
    {
        return base64_decode(strtr($txt, '-_', '+/'), true);
    }
}
