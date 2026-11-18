<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Core\Database;

/**
 * Perfil del taller de un mecánico (tabla taller, la clave es el usuario_id del mecánico).
 * Sin el perfil completo el mecánico no aparece en la lista y no puede firmar.
 */
final class Taller
{
    private const COLUMNAS = 'usuario_id, nombre, direccion, localidad, telefono, precio_inspeccion, activo, estado';

    public static function delMecanico(int $usuarioId): ?array
    {
        $st = Database::pdo()->prepare('SELECT ' . self::COLUMNAS . ' FROM taller WHERE usuario_id = :id');
        $st->execute(['id' => $usuarioId]);
        $t = $st->fetch();
        return $t ?: null;
    }

    // Quién está en la red: taller aprobado y activo, cuenta activa y un rol que puede hacer inspecciones.
    // Un taller 'pendiente' (pidió sumarse desde "Adherí tu taller") no aparece hasta que lo aprueba un admin.
    private const EN_LA_RED = "t.estado = 'aprobado' AND t.activo AND u.activo AND EXISTS (
        SELECT 1 FROM rol_permiso rp JOIN permiso p ON p.id = rp.permiso_id
        WHERE rp.rol_id = u.rol_id AND p.nombre = 'verificaciones.realizar')";

    /**
     * Los mecánicos de la red con lo que hace falta para el ranking (B3): inspecciones firmadas y reclamos que
     * procedieron. Ordenados con Ranking::ordenar. Con $localidad, solo los de esa localidad (sin distinguir mayúsculas).
     */
    public static function red(?string $localidad): array
    {
        $where = self::EN_LA_RED . ($localidad !== null ? ' AND lower(t.localidad) = lower(:loc)' : '');
        $st = Database::pdo()->prepare(
            "SELECT t.usuario_id, u.nombre AS mecanico, t.nombre, t.direccion, t.localidad, t.precio_inspeccion,
                    (SELECT COUNT(*) FROM inspeccion i WHERE i.mecanico_id = t.usuario_id AND i.estado = 'firmada') AS firmadas,
                    (SELECT COUNT(*) FROM reclamo r JOIN inspeccion i ON i.id = r.inspeccion_id
                      WHERE i.mecanico_id = t.usuario_id AND r.estado = 'procede') AS procedentes
             FROM taller t JOIN usuario u ON u.id = t.usuario_id
             WHERE $where ORDER BY t.nombre"
        );
        $st->execute($localidad !== null ? ['loc' => $localidad] : []);
        return Ranking::ordenar(array_map(static function (array $f): array {
            $f['firmadas'] = (int)$f['firmadas'];
            $f['procedentes'] = (int)$f['procedentes'];
            return $f;
        }, $st->fetchAll()), Ranking::valores());
    }

    /**
     * Cuántas inspecciones firmadas de esa marca hizo cada mecánico y en cuántas procedió un reclamo:
     * "modelo" = del mismo modelo, "marca" = de otros modelos de la marca (los autos parecidos).
     * Con eso sale la especialidad (Ranking::especialidad): haber revisado muchos no alcanza si salieron mal.
     * @return array<int, array{modelo: int, reclamos_modelo: int, marca: int, reclamos_marca: int}> por mecanico_id
     */
    public static function revisados(string $marca, ?string $modelo): array
    {
        $st = Database::pdo()->prepare(
            "SELECT i.mecanico_id,
                    COUNT(*) FILTER (WHERE lower(i.modelo) = lower(:m1)) AS modelo,
                    COUNT(*) FILTER (WHERE lower(i.modelo) = lower(:m2) AND r.inspeccion_id IS NOT NULL) AS reclamos_modelo,
                    COUNT(*) FILTER (WHERE lower(i.modelo) IS DISTINCT FROM lower(:m3)) AS marca,
                    COUNT(*) FILTER (WHERE lower(i.modelo) IS DISTINCT FROM lower(:m4) AND r.inspeccion_id IS NOT NULL) AS reclamos_marca
             FROM inspeccion i
             LEFT JOIN (SELECT DISTINCT inspeccion_id FROM reclamo WHERE estado = 'procede') r ON r.inspeccion_id = i.id
             WHERE i.estado = 'firmada' AND lower(i.marca) = lower(:marca)
             GROUP BY i.mecanico_id"
        );
        $m = $modelo ?? '';
        $st->execute(['marca' => $marca, 'm1' => $m, 'm2' => $m, 'm3' => $m, 'm4' => $m]);
        $porMecanico = [];
        foreach ($st->fetchAll() as $f) {
            $porMecanico[(int)$f['mecanico_id']] = [
                'modelo' => (int)$f['modelo'], 'reclamos_modelo' => (int)$f['reclamos_modelo'],
                'marca'  => (int)$f['marca'],  'reclamos_marca'  => (int)$f['reclamos_marca'],
            ];
        }
        return $porMecanico;
    }

    /** Localidades con al menos un mecánico en la red (para el filtro). */
    public static function localidades(): array
    {
        $st = Database::pdo()->query('SELECT DISTINCT t.localidad FROM taller t JOIN usuario u ON u.id = t.usuario_id
            WHERE ' . self::EN_LA_RED . ' ORDER BY t.localidad');
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** El taller del mecánico, solo si está en la red (se lo puede elegir para una inspección). */
    public static function elegible(int $usuarioId): ?array
    {
        $st = Database::pdo()->prepare('SELECT t.usuario_id, t.nombre, t.precio_inspeccion
            FROM taller t JOIN usuario u ON u.id = t.usuario_id WHERE t.usuario_id = :id AND ' . self::EN_LA_RED);
        $st->execute(['id' => $usuarioId]);
        return $st->fetch() ?: null;
    }

    /** Un mecánico de la lista pública. No incluye el teléfono: el contacto pasa por AutoCar. */
    public static function paraRanking(array $m): array
    {
        return [
            'id'                => (int)$m['usuario_id'],
            'mecanico'          => $m['mecanico'],
            'taller'            => $m['nombre'],
            'direccion'         => $m['direccion'],
            'localidad'         => $m['localidad'],
            'precio_inspeccion' => (float)$m['precio_inspeccion'],
            'desglose'          => Precio::desgloseActual((float)$m['precio_inspeccion']),
            'firmadas'          => $m['firmadas'],
            'reclamos_procedentes' => $m['procedentes'],
            'puntaje'           => $m['puntaje'],
            'nuevo'             => $m['puntaje'] === null,
        ];
    }

    /** Alta o modificación del perfil (un taller por mecánico). $datos ya validados con validar(). */
    public static function guardar(int $usuarioId, array $datos): array
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO taller (usuario_id, nombre, direccion, localidad, telefono, precio_inspeccion)
             VALUES (:id, :nombre, :direccion, :localidad, :telefono, :precio)
             ON CONFLICT (usuario_id) DO UPDATE SET
               nombre = EXCLUDED.nombre, direccion = EXCLUDED.direccion, localidad = EXCLUDED.localidad,
               telefono = EXCLUDED.telefono, precio_inspeccion = EXCLUDED.precio_inspeccion, updated_at = now()
             RETURNING ' . self::COLUMNAS
        );
        $st->execute([
            'id'        => $usuarioId,
            'nombre'    => $datos['nombre'],
            'direccion' => $datos['direccion'],
            'localidad' => $datos['localidad'],
            'telefono'  => $datos['telefono'],
            'precio'    => $datos['precio_inspeccion'],
        ]);
        return $st->fetch();
    }

    /**
     * Valida el cuerpo de PUT /api/mecanico/perfil. Función pura (se prueba sin base).
     * El precio tiene que quedar entre $precioMinimo y $precioMaximo (parámetros precio_minimo y precio_maximo).
     * @return array{0: array, 1: array<string, string>} [datos limpios, errores por campo]
     */
    public static function validar(array $in, float $precioMinimo, float $precioMaximo): array
    {
        $texto = static fn(string $k): string => is_scalar($in[$k] ?? null) ? trim((string)$in[$k]) : '';
        $datos = [
            'nombre'    => $texto('nombre'),
            'direccion' => $texto('direccion'),
            'localidad' => $texto('localidad'),
            'telefono'  => $texto('telefono'),
        ];
        $errores = [];
        $largos = ['nombre' => [2, 80], 'direccion' => [3, 120], 'localidad' => [2, 100], 'telefono' => [6, 30]];
        foreach ($largos as $campo => [$min, $max]) {
            $largo = mb_strlen($datos[$campo]);
            if ($largo < $min || $largo > $max) {
                $errores[$campo] = "Tiene que tener entre $min y $max caracteres.";
            }
        }
        if (!isset($errores['telefono']) && !preg_match('/^[0-9+()\s-]+$/', $datos['telefono'])) {
            $errores['telefono'] = 'Solo números, espacios, paréntesis, guiones y +.';
        }

        $precio = $in['precio_inspeccion'] ?? null;
        if (is_string($precio) && is_numeric($precio)) {
            $precio = (float)$precio;
        }
        if (!is_int($precio) && !is_float($precio)) {
            $errores['precio_inspeccion'] = 'Ingresá el precio en pesos.';
        } elseif ($precio < $precioMinimo || $precio > $precioMaximo) {
            $errores['precio_inspeccion'] = 'Tiene que estar entre $ ' . number_format($precioMinimo, 0, ',', '.')
                . ' y $ ' . number_format($precioMaximo, 0, ',', '.') . '.';
        } elseif (fmod((float)$precio, 1.0) !== 0.0) {
            $errores['precio_inspeccion'] = 'Ingresá un precio sin centavos.';
        }
        $datos['precio_inspeccion'] = (float)$precio;
        return [$datos, $errores];
    }

    public static function paraApi(?array $t): ?array
    {
        if ($t === null) {
            return null;
        }
        return [
            'nombre'            => $t['nombre'],
            'direccion'         => $t['direccion'],
            'localidad'         => $t['localidad'],
            'telefono'          => $t['telefono'],
            'precio_inspeccion' => (float)$t['precio_inspeccion'],
            'activo'            => (bool)$t['activo'],
            'estado'            => $t['estado'],
        ];
    }
}
