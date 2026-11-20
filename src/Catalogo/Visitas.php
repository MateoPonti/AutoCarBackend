<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

use AutoCar\Auth\Auth;
use AutoCar\Core\Config;
use AutoCar\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Visitas a las publicaciones (033), para el panel de la empresa: una por visitante, auto y día (en hora de Argentina).
 * Cuentan todas, también las de alguien sin sesión. El visitante es la cuenta si tiene sesión; si no, una marca anónima
 * que cambia todos los días (no se guarda la IP ni nada que identifique a la persona). La del dueño no cuenta.
 */
final class Visitas
{
    public static function registrar(int $vehiculoId, int $duenoId): void
    {
        try {
            $usuario = Auth::id();
            if ($usuario === $duenoId) {
                return;
            }
            $dia = (new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
            Database::pdo()->prepare('INSERT INTO vehiculo_visita (vehiculo_id, visitante, dia) VALUES (?, ?, ?) ON CONFLICT DO NOTHING')
                ->execute([$vehiculoId, self::visitante($usuario, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', $dia), $dia]);
        } catch (Throwable) {
            // Una visita que no se anota no tiene que romper la ficha del auto
        }
    }

    /** Función pura: "u:<id>" con sesión; sin sesión, un hash del día (no se puede volver a la IP). */
    public static function visitante(?int $usuarioId, string $ip, string $agente, string $dia): string
    {
        if ($usuarioId !== null) {
            return 'u:' . $usuarioId;
        }
        $sal = (string)Config::get('SESION_SAL', 'autocar-visitas');
        return 'a:' . substr(hash('sha256', "$sal|$dia|$ip|$agente"), 0, 40);
    }
}
