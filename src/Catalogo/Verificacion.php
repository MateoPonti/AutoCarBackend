<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

use AutoCar\Core\Database;
use PDO;

final class Verificacion
{
    /** Solo se publica el estado de verificación. El informe mecánico sigue siendo privado. */
    public const VIGENTES = "SELECT v.id FROM vehiculo v
        JOIN (SELECT DISTINCT ON (vehiculo_id) vehiculo_id, firmada_at, conclusion, marca, modelo, anio, kilometraje
                FROM inspeccion WHERE estado = 'firmada' AND fuente = 'autocar'
                 AND firmada_at > now() - interval '90 days'
               ORDER BY vehiculo_id, firmada_at DESC, id DESC) i ON i.vehiculo_id = v.id
        WHERE v.estado = 'disponible' AND i.conclusion = 'recomendable'
          AND (i.marca, i.modelo, i.anio, i.kilometraje) = (v.marca, v.modelo, v.anio, v.kilometraje)";

    public static function dePublicaciones(array $ids): array
    {
        if ($ids === []) return [];
        $parametros = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(self::VIGENTES . " AND v.id IN ($parametros)");
        $st->execute($ids);
        return array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
    }
}
