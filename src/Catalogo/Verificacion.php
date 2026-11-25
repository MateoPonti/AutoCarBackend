<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

use AutoCar\Core\Database;
use AutoCar\Inspecciones\Fuente;
use DateTimeImmutable;
use PDO;

/**
 * "Verificado": la última inspección firmada del auto, de los últimos 90 días, dio "recomendable" y el auto sigue con
 * los datos que revisó el mecánico. Siempre cuenta la más reciente, aunque haya varias; su fecha es verificado_at.
 */
final class Verificacion
{
    /** Solo se publica el estado de verificación. El informe mecánico sigue siendo privado. */
    public const VIGENTES = "SELECT v.id, i.firmada_at AS verificado_at FROM vehiculo v
        JOIN (SELECT DISTINCT ON (vehiculo_id) vehiculo_id, firmada_at, conclusion, marca, modelo, anio, kilometraje
                FROM inspeccion WHERE estado = 'firmada' AND fuente = 'autocar'
                 AND firmada_at > now() - interval '90 days'
               ORDER BY vehiculo_id, firmada_at DESC, id DESC) i ON i.vehiculo_id = v.id
        WHERE v.estado = 'disponible' AND i.conclusion = 'recomendable'
          AND (i.marca, i.modelo, i.anio, i.kilometraje) = (v.marca, v.modelo, v.anio, v.kilometraje)";

    /**
     * Publicaciones de AutoCar verificadas.
     * @return array<int, string> id => fecha de la última verificación (ISO 8601)
     */
    public static function dePublicaciones(array $ids): array
    {
        if ($ids === []) return [];
        $parametros = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(self::VIGENTES . " AND v.id IN ($parametros)");
        $st->execute(array_values($ids));
        return array_map([self::class, 'fecha'], $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * Autos del metabuscador verificados: los de AutoCar por su id y los de otras plataformas por el link del aviso
     * (la misma clave que guarda la inspección, Fuente::clave).
     * @param list<array<string, mixed>> $autos con id, fuente, url, anio y km
     * @return array<string, string> id del auto => fecha de la última verificación (ISO 8601)
     */
    public static function deAutos(array $autos): array
    {
        $propias = [];
        $claves = [];
        foreach ($autos as $a) {
            if ($a['fuente'] === 'autocar') {
                $propias[] = (int)$a['id'];
            } elseif (is_string($a['url'] ?? null) && $a['url'] !== '') {
                $claves[(string)$a['id']] = Fuente::clave($a['url'], (string)$a['fuente'], null);
            }
        }
        $salida = [];
        foreach (self::dePublicaciones(array_values(array_unique($propias))) as $id => $fecha) {
            $salida[(string)$id] = $fecha;
        }
        if ($claves === []) {
            return $salida;
        }
        $unicas = array_values(array_unique($claves));
        $st = Database::pdo()->prepare(
            "SELECT DISTINCT ON (url_clave) url_clave, firmada_at, conclusion, anio, kilometraje
               FROM inspeccion WHERE estado = 'firmada' AND fuente <> 'autocar'
                AND firmada_at > now() - interval '90 days' AND url_clave IN (" . implode(',', array_fill(0, count($unicas), '?')) . ")
              ORDER BY url_clave, firmada_at DESC, id DESC"
        );
        $st->execute($unicas);
        $ultimas = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $ultimas[$i['url_clave']] = $i;
        }
        foreach ($autos as $a) {
            $i = $ultimas[$claves[(string)$a['id']] ?? ''] ?? null;
            if ($i !== null && self::sigueIgual($i, $a)) {
                $salida[(string)$a['id']] = self::fecha($i['firmada_at']);
            }
        }
        return $salida;
    }

    /**
     * La última inspección del aviso dio "recomendable" y el aviso sigue con el año y los km que revisó el mecánico
     * (si el vendedor los cambió, deja de estar verificado). Función pura.
     * @param array{conclusion: string, anio: mixed, kilometraje: mixed} $inspeccion
     * @param array{anio: mixed, km: mixed} $auto
     */
    public static function sigueIgual(array $inspeccion, array $auto): bool
    {
        $igual = static fn(mixed $a, mixed $b): bool => $a === null || $b === null || (int)$a === (int)$b;
        return $inspeccion['conclusion'] === 'recomendable'
            && $igual($inspeccion['anio'], $auto['anio'] ?? null)
            && $igual($inspeccion['kilometraje'], $auto['km'] ?? null);
    }

    public static function fecha(string $momento): string
    {
        return (new DateTimeImmutable($momento))->format(DATE_ATOM);
    }
}
