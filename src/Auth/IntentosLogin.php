<?php
declare(strict_types=1);

namespace AutoCar\Auth;

use AutoCar\Core\Database;

/**
 * Límite de intentos de ingreso por email (tabla login_intento, migración 020): sin esto se pueden probar
 * contraseñas sin parar. Se cuenta por email y no por IP porque el front llama por el proxy de Vercel, y todos
 * los usuarios llegarían con las mismas IP.
 *
 * Cada intento se anota ANTES de verificar la contraseña: así, aunque lleguen muchos a la vez, el sexto en
 * guardarse ya ve seis y queda frenado. Un ingreso correcto borra los intentos de ese email.
 */
final class IntentosLogin
{
    public const MAXIMO  = 5;  // intentos por email
    public const MINUTOS = 15; // en esta ventana

    /** Anota un intento y devuelve cuántos segundos faltan para poder volver a probar (0 = puede seguir). */
    public static function anotar(string $email): int
    {
        $pdo = Database::pdo();
        // Lo de más de un día ya no cuenta para nada: se limpia de paso para que la tabla no crezca
        $pdo->prepare("DELETE FROM login_intento WHERE email = :e AND created_at < now() - interval '1 day'")
            ->execute(['e' => $email]);
        $pdo->prepare('INSERT INTO login_intento (email) VALUES (:e)')->execute(['e' => $email]);

        $st = $pdo->prepare('SELECT COUNT(*) AS n, EXTRACT(EPOCH FROM MIN(created_at) + make_interval(mins => :m1) - now()) AS faltan
            FROM login_intento WHERE email = :e AND created_at > now() - make_interval(mins => :m2)');
        $st->execute(['e' => $email, 'm1' => self::MINUTOS, 'm2' => self::MINUTOS]);
        $fila = $st->fetch();
        return (int)$fila['n'] > self::MAXIMO ? max(1, (int)ceil((float)$fila['faltan'])) : 0;
    }

    /** Ingreso correcto: se olvidan los intentos fallidos de ese email. */
    public static function limpiar(string $email): void
    {
        Database::pdo()->prepare('DELETE FROM login_intento WHERE email = :e')->execute(['e' => $email]);
    }
}
