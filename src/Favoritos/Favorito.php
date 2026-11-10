<?php
declare(strict_types=1);

namespace AutoCar\Favoritos;

use AutoCar\Core\Database;

/** Tabla favorito: qué vehículos guardó cada usuario (012-favoritos.sql). */
final class Favorito
{
    /** Guardar dos veces el mismo no da error: queda uno solo. */
    public static function agregar(int $usuarioId, int $vehiculoId): void
    {
        Database::pdo()
            ->prepare('INSERT INTO favorito (usuario_id, vehiculo_id) VALUES (:u, :v) ON CONFLICT DO NOTHING')
            ->execute(['u' => $usuarioId, 'v' => $vehiculoId]);
    }

    public static function sacar(int $usuarioId, int $vehiculoId): void
    {
        Database::pdo()
            ->prepare('DELETE FROM favorito WHERE usuario_id = :u AND vehiculo_id = :v')
            ->execute(['u' => $usuarioId, 'v' => $vehiculoId]);
    }
}
