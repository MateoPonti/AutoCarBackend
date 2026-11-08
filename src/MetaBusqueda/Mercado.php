<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

use PDO;

/** Consultas sobre los autos de otras plataformas. Las estadísticas solo usan recolecciones completas. */
final class Mercado
{
    private const SELECT_FOTO =
        'SELECT a.id, a.fuente_id, f.nombre AS fuente, a.marca, a.modelo, a.version, a.anio, a.transmision, a.ubicacion, a.url,
                a.primera_vez, a.ultima_vez, a.estado, s.precio, s.precio_contado, s.moneda, s.km, s.reservado,
                NOT EXISTS (SELECT 1 FROM auto_foto_semanal s2 JOIN recoleccion r2 ON r2.id = s2.recoleccion_id
                             WHERE s2.auto_id = s.auto_id AND r2.completo AND r2.fecha < r.fecha) AS es_nuevo
           FROM auto_foto_semanal s
           JOIN auto_externo a ON a.id = s.auto_id
           JOIN fuente f ON f.id = a.fuente_id
           JOIN recoleccion r ON r.id = s.recoleccion_id';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Las dos últimas recolecciones completas de cada plataforma.
     *
     * @return list<array{fuente_id: int, fuente: string, actual: int, fecha: string, anterior: ?int, fecha_anterior: ?string}>
     */
    public function ultimasRecolecciones(): array
    {
        $filas = $this->pdo->query(
            'SELECT r.id, r.fuente_id, r.fecha, f.nombre AS fuente, r.n
               FROM (SELECT id, fuente_id, fecha, row_number() OVER (PARTITION BY fuente_id ORDER BY fecha DESC) AS n
                       FROM recoleccion WHERE completo) r
               JOIN fuente f ON f.id = r.fuente_id
              WHERE r.n <= 2
              ORDER BY f.nombre, r.n'
        )->fetchAll(PDO::FETCH_ASSOC);

        $porFuente = [];
        foreach ($filas as $f) {
            $id = (int)$f['fuente_id'];
            if ((int)$f['n'] === 1) {
                $porFuente[$id] = [
                    'fuente_id' => $id, 'fuente' => $f['fuente'], 'actual' => (int)$f['id'],
                    'fecha' => $f['fecha'], 'anterior' => null, 'fecha_anterior' => null,
                ];
            } else {
                $porFuente[$id]['anterior'] = (int)$f['id'];
                $porFuente[$id]['fecha_anterior'] = $f['fecha'];
            }
        }
        return array_values($porFuente);
    }

    /**
     * Autos de un modelo en esas recolecciones.
     *
     * @param list<int> $recolecciones
     * @return list<array<string, mixed>>
     */
    public function fotosDelModelo(array $recolecciones, string $marca, string $modelo): array
    {
        if ($recolecciones === []) {
            return [];
        }
        [$lista, $params] = self::lista($recolecciones);
        $st = $this->pdo->prepare(
            self::SELECT_FOTO . " WHERE s.recoleccion_id IN ($lista) AND lower(a.marca) = lower(:marca) AND lower(a.modelo) = lower(:modelo)"
        );
        $st->execute($params + ['marca' => $marca, 'modelo' => $modelo]);
        return array_map([self::class, 'fila'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> todos los autos de una recolección */
    public function fotosDe(int $recoleccion): array
    {
        $st = $this->pdo->prepare(self::SELECT_FOTO . ' WHERE s.recoleccion_id = :r');
        $st->execute(['r' => $recoleccion]);
        return array_map([self::class, 'fila'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Candidatos de otros modelos, con el precio más cercano primero.
     *
     * @param list<int> $recolecciones
     * @param array{precio_min: float, precio_max: float, anio_min: int, anio_max: int, km_min: float, km_max: float, precio_tipico: float, marca: string, modelo: string} $c
     * @return list<array<string, mixed>>
     */
    public function otrosModelos(array $recolecciones, array $c): array
    {
        if ($recolecciones === []) {
            return [];
        }
        [$lista, $params] = self::lista($recolecciones);
        $st = $this->pdo->prepare(
            self::SELECT_FOTO . " WHERE s.recoleccion_id IN ($lista)
               AND NOT s.reservado AND s.moneda = 'ARS'
               AND s.precio BETWEEN :precio_min AND :precio_max
               AND a.anio BETWEEN :anio_min AND :anio_max
               AND s.km BETWEEN :km_min AND :km_max
               AND NOT (lower(a.marca) = lower(:marca) AND lower(a.modelo) = lower(:modelo))
             ORDER BY abs(s.precio - :precio_tipico), a.id
             LIMIT 200"
        );
        $st->execute($params + $c);
        return array_map([self::class, 'fila'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null datos fijos de un auto y su foto más reciente en una recolección completa */
    public function auto(int $id): ?array
    {
        $st = $this->pdo->prepare(
            self::SELECT_FOTO . ' WHERE a.id = :id AND r.completo ORDER BY r.fecha DESC LIMIT 1'
        );
        $st->execute(['id' => $id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        return $f ? self::fila($f) : null;
    }

    /** @return list<array{fecha: string, precio: ?float, precio_contado: ?float, moneda: string, km: int, reservado: bool}> */
    public function historial(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT r.fecha, s.precio, s.precio_contado, s.moneda, s.km, s.reservado
               FROM auto_foto_semanal s JOIN recoleccion r ON r.id = s.recoleccion_id
              WHERE s.auto_id = :id AND r.completo
              ORDER BY r.fecha'
        );
        $st->execute(['id' => $id]);
        return array_map(static fn(array $f): array => [
            'fecha'          => $f['fecha'],
            'precio'         => $f['precio'] !== null ? (float)$f['precio'] : null,
            'precio_contado' => $f['precio_contado'] !== null ? (float)$f['precio_contado'] : null,
            'moneda'         => $f['moneda'],
            'km'             => (int)$f['km'],
            'reservado'      => (bool)$f['reservado'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Marcas y sus modelos en las últimas recolecciones: el formulario necesita los nombres exactos.
     *
     * @param list<int> $recolecciones
     * @return list<array{marca: string, modelos: list<string>}>
     */
    public function marcasYModelos(array $recolecciones): array
    {
        if ($recolecciones === []) {
            return [];
        }
        [$lista, $params] = self::lista($recolecciones);
        $st = $this->pdo->prepare(
            "SELECT DISTINCT a.marca, a.modelo FROM auto_foto_semanal s JOIN auto_externo a ON a.id = s.auto_id
              WHERE s.recoleccion_id IN ($lista) ORDER BY a.marca, a.modelo"
        );
        $st->execute($params);
        $marcas = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $marcas[$f['marca']][] = $f['modelo'];
        }
        $salida = [];
        foreach ($marcas as $marca => $modelos) {
            $salida[] = ['marca' => (string)$marca, 'modelos' => $modelos];
        }
        return $salida;
    }

    /**
     * Vehículos disponibles publicados en AutoCar, con las mismas claves que un auto externo.
     *
     * @param array<string, mixed> $b búsqueda
     * @return list<array<string, mixed>>
     */
    public function delPortal(array $b): array
    {
        $st = $this->pdo->prepare(
            "SELECT id, marca, modelo, version, anio, transmision, localidad, kilometraje, precio, created_at
               FROM vehiculo
              WHERE estado = 'disponible' AND lower(marca) = lower(:marca) AND lower(modelo) = lower(:modelo)"
        );
        $st->execute(['marca' => $b['marca'], 'modelo' => $b['modelo']]);
        $autos = array_map(static fn(array $f): array => [
            'id'             => (int)$f['id'],
            'fuente_id'      => null,
            'fuente'         => 'autocar',
            'marca'          => $f['marca'],
            'modelo'         => $f['modelo'],
            'version'        => $f['version'],
            'anio'           => (int)$f['anio'],
            'transmision'    => $f['transmision'],
            'ubicacion'      => $f['localidad'],
            'url'            => null,
            'primera_vez'    => $f['created_at'],
            'ultima_vez'     => null,
            'estado'         => 'activo',
            'precio'         => (float)$f['precio'],
            'precio_contado' => null,
            'moneda'         => 'ARS',
            'km'             => (int)$f['kilometraje'],
            'reservado'      => false,
            'es_nuevo'       => false,
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        return array_values(array_filter($autos, static fn(array $a): bool => Busqueda::coincide($b, $a)));
    }

    /** @param list<int> $ids */
    private static function lista(array $ids): array
    {
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $params["r$i"] = $id;
        }
        return [implode(', ', array_map(static fn(string $k): string => ":$k", array_keys($params))), $params];
    }

    /** @param array<string, mixed> $f */
    private static function fila(array $f): array
    {
        return [
            'id'             => (int)$f['id'],
            'fuente_id'      => (int)$f['fuente_id'],
            'fuente'         => $f['fuente'],
            'marca'          => $f['marca'],
            'modelo'         => $f['modelo'],
            'version'        => $f['version'],
            'anio'           => (int)$f['anio'],
            'transmision'    => $f['transmision'],
            'ubicacion'      => $f['ubicacion'],
            'url'            => $f['url'],
            'primera_vez'    => $f['primera_vez'],
            'ultima_vez'     => $f['ultima_vez'],
            'estado'         => $f['estado'],
            'precio'         => $f['precio'] !== null ? (float)$f['precio'] : null,
            'precio_contado' => $f['precio_contado'] !== null ? (float)$f['precio_contado'] : null,
            'moneda'         => $f['moneda'],
            'km'             => (int)$f['km'],
            'reservado'      => (bool)$f['reservado'],
            'es_nuevo'       => (bool)$f['es_nuevo'],
        ];
    }
}
