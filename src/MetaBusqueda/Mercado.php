<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

use PDO;

/**
 * Consultas sobre los autos de otras plataformas, en la base de documentos (ver Catalogo). Las estadísticas solo usan
 * corridas completas. Una corrida se identifica como "fuente@fecha".
 * Los autos publicados en AutoCar siguen en Neon (tabla vehiculo): para eso es el PDO.
 */
final class Mercado
{
    private const OTROS_MODELOS_A_LEER = 12;

    /** @var array<string, array|null> documentos ya leídos en este pedido */
    private array $modelos = [];
    private ?array $indice = null;

    public function __construct(private Documentos $docs, private ?PDO $pdo = null)
    {
    }

    public static function corrida(string $fuente, string $fecha): string
    {
        return "$fuente@$fecha";
    }

    /**
     * Las dos últimas corridas completas de cada plataforma.
     *
     * @return list<array{fuente: string, actual: string, fecha: string, anterior: ?string, fecha_anterior: ?string}>
     */
    public function ultimasRecolecciones(): array
    {
        $salida = [];
        foreach ($this->docs->listar('recolecciones') as $r) {
            $completas = array_values(array_filter($r['corridas'] ?? [], static fn(array $c): bool => $c['completo']));
            if ($completas === []) {
                continue;
            }
            $actual = $completas[count($completas) - 1];
            $anterior = $completas[count($completas) - 2] ?? null;
            $salida[] = [
                'fuente'         => (string)$r['fuente'],
                'actual'         => self::corrida((string)$r['fuente'], $actual['fecha']),
                'fecha'          => $actual['fecha'],
                'anterior'       => $anterior !== null ? self::corrida((string)$r['fuente'], $anterior['fecha']) : null,
                'fecha_anterior' => $anterior['fecha'] ?? null,
            ];
        }
        usort($salida, static fn(array $a, array $b): int => $a['fuente'] <=> $b['fuente']);
        return $salida;
    }

    /**
     * Autos de un modelo en esas corridas.
     *
     * @param list<string> $corridas
     * @return list<array<string, mixed>>
     */
    public function fotosDelModelo(array $corridas, string $marca, string $modelo): array
    {
        $doc = $this->modelo(Catalogo::claveDe($marca, $modelo));
        return $doc === null ? [] : self::fotosDe($doc, $corridas);
    }

    /**
     * Candidatos de otros modelos, con el precio más cercano primero. El índice dice qué modelos pueden tener autos en
     * esos rangos; solo se leen los más cercanos.
     *
     * @param list<string> $corridas
     * @param array{precio_min: float, precio_max: float, anio_min: int, anio_max: int, km_min: float, km_max: float, precio_tipico: float, marca: string, modelo: string} $c
     * @return list<array<string, mixed>>
     */
    public function otrosModelos(array $corridas, array $c): array
    {
        $propia = Catalogo::claveDe($c['marca'], $c['modelo']);
        $cruza = static fn(array $r, float $min, float $max): bool => $r[0] !== null && $r[0] <= $max && $r[1] >= $min;
        $elegidos = [];
        foreach ($this->indice() as $clave => $m) {
            if ($clave !== $propia && $cruza($m['precio'], $c['precio_min'], $c['precio_max'])
                && $cruza($m['anio'], $c['anio_min'], $c['anio_max']) && $cruza($m['km'], $c['km_min'], $c['km_max'])) {
                $elegidos[$clave] = abs((float)$m['mediana'] - $c['precio_tipico']);
            }
        }
        asort($elegidos);

        $candidatos = [];
        foreach (array_slice(array_keys($elegidos), 0, self::OTROS_MODELOS_A_LEER) as $clave) {
            $doc = $this->modelo((string)$clave);
            foreach ($doc === null ? [] : self::fotosDe($doc, $corridas) as $a) {
                if (!$a['reservado'] && $a['moneda'] === 'ARS' && $a['precio'] !== null
                    && $a['precio'] >= $c['precio_min'] && $a['precio'] <= $c['precio_max']
                    && $a['anio'] >= $c['anio_min'] && $a['anio'] <= $c['anio_max']
                    && $a['km'] >= $c['km_min'] && $a['km'] <= $c['km_max']) {
                    $candidatos[] = $a;
                }
            }
        }
        usort($candidatos, static fn(array $a, array $b): int =>
            [abs($a['precio'] - $c['precio_tipico']), $a['id']] <=> [abs($b['precio'] - $c['precio_tipico']), $b['id']]);
        return $candidatos;
    }

    /** @return array{0: array<string, mixed>, 1: array}|null el auto con su estado actual y el documento de su modelo */
    public function auto(string $id): ?array
    {
        $partes = Catalogo::partirIdPublico($id);
        if ($partes === null) {
            return null;
        }
        [$fuente, $clave, $idExterno] = $partes;
        $doc = $this->modelo($clave);
        $a = $doc['autos'][Catalogo::idAuto($fuente, $idExterno)] ?? null;
        return $a === null ? null : [Catalogo::fila($doc, $a), $doc];
    }

    /** @return list<array{fecha: string, precio: ?float, precio_contado: ?float, moneda: string, km: int, reservado: bool}> cada cambio */
    public function historial(string $id): array
    {
        $partes = Catalogo::partirIdPublico($id);
        $doc = $partes === null ? null : $this->modelo($partes[1]);
        $a = $doc['autos'][Catalogo::idAuto($partes[0] ?? '', $partes[2] ?? '')] ?? null;
        return array_map(static fn(array $h): array => [
            'fecha'          => $h[0],
            'precio'         => $h[1] !== null ? (float)$h[1] : null,
            'precio_contado' => $h[2] !== null ? (float)$h[2] : null,
            'moneda'         => $h[3],
            'km'             => (int)$h[4],
            'reservado'      => (bool)$h[5],
        ], $a['historial'] ?? []);
    }

    /**
     * Marcas y sus modelos con autos activos: el formulario necesita los nombres exactos.
     *
     * @return list<array{marca: string, modelos: list<string>}>
     */
    public function marcasYModelos(): array
    {
        $marcas = [];
        foreach ($this->indice() as $m) {
            $marcas[$m['marca']][] = $m['modelo'];
        }
        ksort($marcas, SORT_STRING | SORT_FLAG_CASE);
        $salida = [];
        foreach ($marcas as $marca => $modelos) {
            sort($modelos, SORT_STRING | SORT_FLAG_CASE);
            $salida[] = ['marca' => (string)$marca, 'modelos' => array_values(array_unique($modelos))];
        }
        return $salida;
    }

    /**
     * Vehículos disponibles publicados en AutoCar, con las mismas claves que un auto externo.
     * Se traen todos los disponibles y se filtran acá con la misma clave de modelo que los externos ("VW" = "Volkswagen").
     *
     * @param array<string, mixed> $b búsqueda
     * @return list<array<string, mixed>>
     */
    public function delPortal(array $b): array
    {
        if ($this->pdo === null) {
            return [];
        }
        $st = $this->pdo->query(
            "SELECT id, marca, modelo, version, anio, transmision, localidad, kilometraje, precio, created_at
               FROM vehiculo WHERE estado = 'disponible'"
        );
        $autos = array_map(static fn(array $f): array => [
            'id'             => (int)$f['id'],
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

    /** @param list<string> $corridas "fuente@fecha" */
    private static function fotosDe(array $doc, array $corridas): array
    {
        $salida = [];
        foreach ($corridas as $corrida) {
            [$fuente, $fecha] = explode('@', $corrida, 2);
            array_push($salida, ...Catalogo::fotos($doc, $fuente, $fecha));
        }
        return $salida;
    }

    private function modelo(string $clave): ?array
    {
        if (!array_key_exists($clave, $this->modelos)) {
            $this->modelos[$clave] = Catalogo::leer($this->docs, $clave);
        }
        return $this->modelos[$clave];
    }

    /** @return array<string, array<string, mixed>> clave → resumen del modelo (ver Catalogo::resumen) */
    private function indice(): array
    {
        return $this->indice ??= Indice::leer($this->docs);
    }
}
