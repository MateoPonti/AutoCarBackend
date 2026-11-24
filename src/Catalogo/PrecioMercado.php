<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

use AutoCar\Comparador\Almacenes;
use AutoCar\Comparador\Clave;
use AutoCar\Comparador\Comparador;
use AutoCar\Core\Database;
use AutoCar\MetaBusqueda\Documentos;
use PDO;

/**
 * El precio de cada publicación de AutoCar contra autos parecidos de otros sitios (el mismo cálculo de "La publicación
 * que se compara sola", Comparador), guardado en vehiculo_mercado (033).
 * Solo para los autos de Explorar (las publicaciones de AutoCar): los de los otros sitios son la referencia de precio.
 * Explorar y el panel de la empresa leen lo guardado acá (guardados). Se calcula:
 *   - una vez por día en el scraper (bin/precios-explorar.php): con los autos de los otros sitios recién armados y los
 *     de Explorar que lee de la API pública, deja todo en UN documento de Firestore (DOCUMENTO). El back lo copia a Neon
 *     la primera vez que alguien abre Explorar después de la corrida (sincronizar: a lo sumo una lectura por hora);
 *   - cuando un moderador aprueba una publicación (recalcular), para que un auto nuevo no espere al día siguiente;
 *   - a mano desde el admin (recalcularTodas).
 * Si el precio, los km o el año cambiaron desde el último cálculo, lo guardado no vale hasta que se recalcule.
 */
final class PrecioMercado
{
    /** El documento de Firestore que deja el scraper con el cálculo del día */
    public const DOCUMENTO = 'explorar/precios';
    /** Cada cuánto el back se fija si hay un documento nuevo */
    public const SEGUNDOS_ENTRE_REVISIONES = 3600;

    /**
     * Lo guardado para esas publicaciones (solo Neon). Las que no tienen cálculo vigente no aparecen.
     * @param list<array<string, mixed>> $vehiculos filas con id, anio, kilometraje y precio
     * @return array<int, array{suficientes: bool, diferencia_pct: ?float, mediana_precio: ?float, mediana_km: ?int, comparables: int}>
     */
    public static function guardados(array $vehiculos): array
    {
        $porId = [];
        foreach ($vehiculos as $v) {
            if ($v['precio'] !== null && $v['kilometraje'] !== null) {
                $porId[(int)$v['id']] = $v;
            }
        }
        if ($porId === []) {
            return [];
        }
        $st = Database::pdo()->prepare('SELECT * FROM vehiculo_mercado WHERE vehiculo_id IN ('
            . implode(',', array_fill(0, count($porId), '?')) . ')');
        $st->execute(array_keys($porId));
        $salida = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $v = $porId[(int)$g['vehiculo_id']];
            if ((float)$g['precio'] === (float)$v['precio'] && (int)$g['kilometraje'] === (int)$v['kilometraje'] && (int)$g['anio'] === (int)$v['anio']) {
                $salida[(int)$g['vehiculo_id']] = self::fila($g);
            }
        }
        return $salida;
    }

    /**
     * Calcula y guarda el precio contra el mercado de esas publicaciones: una lectura de Firestore por modelo.
     * @param list<array<string, mixed>> $vehiculos filas con id, marca, modelo, anio, kilometraje y precio
     * @return array{autos: int, modelos: int}
     */
    public static function recalcular(array $vehiculos): array
    {
        $porModelo = [];
        foreach ($vehiculos as $v) {
            if ($v['precio'] !== null && $v['kilometraje'] !== null && $v['anio'] !== null) {
                $porModelo[Clave::de((string)$v['marca'], (string)$v['modelo'])][] = $v;
            }
        }
        if ($porModelo === []) {
            return ['autos' => 0, 'modelos' => 0];
        }
        $guardar = Database::pdo()->prepare(self::UPSERT);
        $almacen = Almacenes::configurado();
        $autos = 0;
        foreach ($porModelo as $clave => $delModelo) {
            $modelo = $almacen->modelo((string)$clave);
            foreach ($delModelo as $v) {
                $r = self::desdeComparacion(Comparador::comparar([
                    'marca' => (string)$v['marca'], 'modelo' => (string)$v['modelo'], 'anio' => (int)$v['anio'],
                    'kilometraje' => (int)$v['kilometraje'], 'precio' => (float)$v['precio'],
                ], $modelo));
                $guardar->execute([(int)$v['id'], (float)$v['precio'], (int)$v['kilometraje'], (int)$v['anio'],
                    $r['suficientes'] ? 'true' : 'false', $r['diferencia_pct'], $r['mediana_precio'], $r['mediana_km'], $r['comparables']]);
                $autos++;
            }
        }
        return ['autos' => $autos, 'modelos' => count($porModelo)];
    }

    /**
     * Función pura: el cálculo de cada auto con los modelos ya armados (Comparador\Modelos::armar, clave → documento).
     * @param list<array<string, mixed>> $vehiculos id, marca, modelo, anio, kilometraje y precio
     * @return array<string, array<string, mixed>> por id (texto: es la clave del documento de Firestore)
     */
    public static function calcularConModelos(array $vehiculos, array $modelos): array
    {
        $salida = [];
        foreach ($vehiculos as $v) {
            if ($v['precio'] === null || $v['kilometraje'] === null || $v['anio'] === null) {
                continue;
            }
            $r = self::desdeComparacion(Comparador::comparar([
                'marca' => (string)$v['marca'], 'modelo' => (string)$v['modelo'], 'anio' => (int)$v['anio'],
                'kilometraje' => (int)$v['kilometraje'], 'precio' => (float)$v['precio'],
            ], $modelos[Clave::de((string)$v['marca'], (string)$v['modelo'])] ?? null));
            $salida[(string)(int)$v['id']] = ['precio' => (float)$v['precio'], 'kilometraje' => (int)$v['kilometraje'], 'anio' => (int)$v['anio']] + $r;
        }
        return $salida;
    }

    /**
     * Copia a Neon el documento que dejó el scraper, si es nuevo. Se fija como mucho una vez por hora (lo anota en
     * $marcador): Explorar no lee Firestore en cada pedido. Solo copia los autos cuyos precio, km y año siguen iguales.
     */
    public static function sincronizar(Documentos $docs, ?string $marcador = null): void
    {
        $marcador ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'autocar-precios-explorar.json';
        $anterior = is_file($marcador) ? json_decode((string)file_get_contents($marcador), true) : null;
        if (is_array($anterior) && time() - (int)($anterior['revisado'] ?? 0) < self::SEGUNDOS_ENTRE_REVISIONES) {
            return;
        }
        $doc = $docs->leer(self::DOCUMENTO);
        $fecha = is_string($doc['fecha'] ?? null) ? $doc['fecha'] : null;
        if ($fecha !== null && $fecha !== ($anterior['fecha'] ?? null) && is_array($doc['autos'] ?? null)) {
            self::copiar($doc['autos']);
        }
        @file_put_contents($marcador, json_encode(['revisado' => time(), 'fecha' => $fecha ?? ($anterior['fecha'] ?? null)]));
    }

    /** @param array<string, array<string, mixed>> $autos lo del documento, por id */
    private static function copiar(array $autos): void
    {
        $ids = array_values(array_filter(array_map('intval', array_keys($autos))));
        if ($ids === []) {
            return;
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id, precio, kilometraje, anio FROM vehiculo WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $guardar = $pdo->prepare(self::UPSERT);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $a = $autos[(string)$v['id']];
            if ($v['precio'] === null || (float)$v['precio'] !== (float)$a['precio'] || (int)$v['kilometraje'] !== (int)$a['kilometraje'] || (int)$v['anio'] !== (int)$a['anio']) {
                continue; // cambió después de la corrida: se recalcula al reaprobarla
            }
            $guardar->execute([(int)$v['id'], (float)$a['precio'], (int)$a['kilometraje'], (int)$a['anio'], $a['suficientes'] ? 'true' : 'false',
                $a['diferencia_pct'], $a['mediana_precio'], $a['mediana_km'], (int)$a['comparables']]);
        }
    }

    private const UPSERT = 'INSERT INTO vehiculo_mercado (vehiculo_id, precio, kilometraje, anio, suficientes, diferencia_pct, mediana_precio, mediana_km, comparables, calculado_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, now())
             ON CONFLICT (vehiculo_id) DO UPDATE SET precio = EXCLUDED.precio, kilometraje = EXCLUDED.kilometraje, anio = EXCLUDED.anio,
               suficientes = EXCLUDED.suficientes, diferencia_pct = EXCLUDED.diferencia_pct, mediana_precio = EXCLUDED.mediana_precio,
               mediana_km = EXCLUDED.mediana_km, comparables = EXCLUDED.comparables, calculado_at = now()';

    /** Lo que el scraper necesita de los autos de Explorar (las publicaciones disponibles), para GET /api/vehiculos/mercado */
    public static function paraElScraper(): array
    {
        return array_map(static fn(array $v): array => [
            'id' => (int)$v['id'], 'marca' => $v['marca'], 'modelo' => $v['modelo'], 'anio' => (int)$v['anio'],
            'kilometraje' => $v['kilometraje'] !== null ? (int)$v['kilometraje'] : null, 'precio' => $v['precio'] !== null ? (float)$v['precio'] : null,
        ], Database::pdo()->query("SELECT id, marca, modelo, anio, kilometraje, precio FROM vehiculo WHERE estado = 'disponible'")->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Todas las publicaciones disponibles (a mano desde el admin). */
    public static function recalcularTodas(): array
    {
        $disponibles = Database::pdo()->query(
            "SELECT id, marca, modelo, anio, kilometraje, precio FROM vehiculo WHERE estado = 'disponible'"
        )->fetchAll(PDO::FETCH_ASSOC);
        return self::recalcular($disponibles);
    }

    /** Lo que se guarda del resultado del Comparador. Función pura. */
    public static function desdeComparacion(array $c): array
    {
        $suficientes = ($c['etiqueta'] ?? Comparador::SIN_DATOS) !== Comparador::SIN_DATOS;
        return [
            'suficientes'    => $suficientes,
            'diferencia_pct' => $suficientes ? (float)$c['diferencia_pct'] : null,
            'mediana_precio' => $suficientes ? (float)$c['mercado']['mediana'] : null,
            'mediana_km'     => $suficientes ? (int)$c['km']['mediana_mercado'] : null,
            'comparables'    => (int)($c['comparables'] ?? 0),
        ];
    }

    private static function fila(array $g): array
    {
        return [
            'suficientes'    => (bool)$g['suficientes'],
            'diferencia_pct' => $g['diferencia_pct'] !== null ? (float)$g['diferencia_pct'] : null,
            'mediana_precio' => $g['mediana_precio'] !== null ? (float)$g['mediana_precio'] : null,
            'mediana_km'     => $g['mediana_km'] !== null ? (int)$g['mediana_km'] : null,
            'comparables'    => (int)$g['comparables'],
        ];
    }
}
