<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Guarda la salida de un recolector (formato común, ver recolectores/kavak/README.md) como una recolección nueva.
 * Todo o nada: corre en una transacción. Las corridas incompletas se guardan, pero no retiran autos ni generan
 * avisos ni se usan para calcular, porque un auto ausente puede ser solo una página que falló.
 */
final class Importador
{
    /** Con menos que este porcentaje de la corrida anterior, se desconfía de la corrida. */
    private const PISO_RESPECTO_ANTERIOR = 0.8;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $datos
     * @return array{recoleccion_id: int, fuente: string, autos: int, completo: bool, retirados: int, avisos: int, advertencias: list<string>}
     */
    public function importar(array $datos): array
    {
        $fuente = is_string($datos['fuente'] ?? null) ? $datos['fuente'] : '';
        if (preg_match('/^[a-z0-9_-]{1,30}$/', $fuente) !== 1) {
            throw new InvalidArgumentException('"fuente" debe ser un nombre corto en minúsculas.');
        }
        $marcaDeTiempo = is_string($datos['fecha'] ?? null) ? strtotime($datos['fecha']) : false;
        if ($marcaDeTiempo === false) {
            throw new InvalidArgumentException('"fecha" no es una fecha válida.');
        }
        $fecha = date(DATE_ATOM, $marcaDeTiempo);
        if (!is_array($datos['autos'] ?? null)) {
            throw new InvalidArgumentException('"autos" debe ser una lista.');
        }

        $autos = [];
        $invalidos = 0;
        foreach ($datos['autos'] as $auto) {
            $limpio = is_array($auto) ? self::validarAuto($auto) : null;
            if ($limpio === null) {
                $invalidos++;
                continue;
            }
            $autos[$limpio['id_externo']] = $limpio;
        }

        $advertencias = [];
        $completo = ($datos['completo'] ?? false) === true;
        if ($invalidos > 0) {
            $completo = false;
            $advertencias[] = "$invalidos autos con datos inválidos: la corrida queda marcada como incompleta.";
        }

        $this->pdo->beginTransaction();
        try {
            $fuenteId = $this->fuenteId($fuente);

            $st = $this->pdo->prepare('SELECT max(fecha) FROM recoleccion WHERE fuente_id = :f');
            $st->execute(['f' => $fuenteId]);
            $ultima = $st->fetchColumn();
            if ($ultima !== null && $ultima !== false && strtotime((string)$ultima) >= $marcaDeTiempo) {
                throw new InvalidArgumentException("Ya hay una recolección de $fuente con esa fecha o posterior ($ultima).");
            }

            $anterior = $this->ultimaCompleta($fuenteId);
            if ($completo && $anterior !== null && count($autos) < $anterior['autos'] * self::PISO_RESPECTO_ANTERIOR) {
                $completo = false;
                $advertencias[] = 'Trajo ' . count($autos) . " autos contra {$anterior['autos']} de la corrida anterior: queda marcada como incompleta.";
            }

            $st = $this->pdo->prepare(
                'INSERT INTO recoleccion (fuente_id, fecha, completo, autos) VALUES (:f, :fecha, :c, :n) RETURNING id'
            );
            $st->bindValue('f', $fuenteId, PDO::PARAM_INT);
            $st->bindValue('fecha', $fecha);
            $st->bindValue('c', $completo, PDO::PARAM_BOOL);
            $st->bindValue('n', count($autos), PDO::PARAM_INT);
            $st->execute();
            $recoleccionId = (int)$st->fetchColumn();

            $this->guardarAutos($fuenteId, $recoleccionId, $fecha, $autos);

            $retirados = 0;
            $avisos = 0;
            if ($completo) {
                $st = $this->pdo->prepare(
                    "UPDATE auto_externo SET estado = 'retirado' WHERE fuente_id = :f AND estado <> 'retirado' AND ultima_vez < :fecha"
                );
                $st->execute(['f' => $fuenteId, 'fecha' => $fecha]);
                $retirados = $st->rowCount();

                if ($anterior !== null) {
                    $avisos = $this->generarAvisos($recoleccionId, $anterior['id'], $fecha);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'recoleccion_id' => $recoleccionId,
            'fuente'         => $fuente,
            'autos'          => count($autos),
            'completo'       => $completo,
            'retirados'      => $retirados,
            'avisos'         => $avisos,
            'advertencias'   => $advertencias,
        ];
    }

    /** @param array<string, mixed> $a */
    public static function validarAuto(array $a): ?array
    {
        $texto = static function (mixed $v, int $max): ?string {
            if (!is_string($v) || trim($v) === '') {
                return null;
            }
            return mb_substr(trim($v), 0, $max);
        };
        $idCrudo = $a['id_externo'] ?? null;
        $id = is_int($idCrudo) ? (string)$idCrudo : $texto($idCrudo, 60);
        $marca = $texto($a['marca'] ?? null, 50);
        $modelo = $texto($a['modelo'] ?? null, 60);
        $anio = $a['anio'] ?? null;
        $km = $a['km'] ?? null;
        $moneda = $a['moneda'] ?? null;
        $precio = self::precio($a['precio'] ?? null);
        $contado = self::precio($a['precio_contado'] ?? null);

        if ($id === null || $marca === null || $modelo === null
            || !is_int($anio) || $anio < 1950 || $anio > 2100
            || !is_int($km) || $km < 0 || $km > 5000000
            || !in_array($moneda, ['ARS', 'USD'], true)
            || $precio === false || $contado === false) {
            return null;
        }
        $transmision = $a['transmision'] ?? null;
        $url = $texto($a['url'] ?? null, 500);

        return [
            'id_externo'     => $id,
            'marca'          => $marca,
            'modelo'         => $modelo,
            'version'        => $texto($a['version'] ?? null, 120),
            'anio'           => $anio,
            'km'             => $km,
            'transmision'    => in_array($transmision, ['manual', 'automatica'], true) ? $transmision : null,
            'precio'         => $precio,
            'precio_contado' => $contado,
            'moneda'         => $moneda,
            'reservado'      => ($a['reservado'] ?? false) === true,
            'ubicacion'      => $texto($a['ubicacion'] ?? null, 100),
            'url'            => $url !== null && preg_match('#^https?://#i', $url) === 1 ? $url : null, // se muestra como link
        ];
    }

    /** Número positivo, o null si no hay precio. false si el valor no es válido. */
    private static function precio(mixed $v): float|false|null
    {
        if ($v === null) {
            return null;
        }
        return (is_int($v) || is_float($v)) && $v > 0 && $v < 1e12 ? (float)$v : false;
    }

    private function fuenteId(string $nombre): int
    {
        $this->pdo->prepare('INSERT INTO fuente (nombre) VALUES (:n) ON CONFLICT (nombre) DO NOTHING')->execute(['n' => $nombre]);
        $st = $this->pdo->prepare('SELECT id FROM fuente WHERE nombre = :n');
        $st->execute(['n' => $nombre]);
        return (int)$st->fetchColumn();
    }

    /** @return array{id: int, autos: int}|null */
    private function ultimaCompleta(int $fuenteId): ?array
    {
        $st = $this->pdo->prepare('SELECT id, autos FROM recoleccion WHERE fuente_id = :f AND completo ORDER BY fecha DESC LIMIT 1');
        $st->execute(['f' => $fuenteId]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        return $f ? ['id' => (int)$f['id'], 'autos' => (int)$f['autos']] : null;
    }

    /** @param array<string, array<string, mixed>> $autos */
    private function guardarAutos(int $fuenteId, int $recoleccionId, string $fecha, array $autos): void
    {
        $auto = $this->pdo->prepare(
            "INSERT INTO auto_externo (fuente_id, id_externo, marca, modelo, version, anio, transmision, ubicacion, url, primera_vez, ultima_vez, estado)
             VALUES (:fuente, :id_externo, :marca, :modelo, :version, :anio, :transmision, :ubicacion, :url, :fecha, :fecha2, :estado)
             ON CONFLICT (fuente_id, id_externo) DO UPDATE SET
               marca = EXCLUDED.marca, modelo = EXCLUDED.modelo, version = EXCLUDED.version, anio = EXCLUDED.anio,
               transmision = EXCLUDED.transmision, ubicacion = EXCLUDED.ubicacion, url = EXCLUDED.url,
               ultima_vez = EXCLUDED.ultima_vez, estado = EXCLUDED.estado
             RETURNING id"
        );
        $foto = $this->pdo->prepare(
            'INSERT INTO auto_foto_semanal (recoleccion_id, auto_id, precio, precio_contado, moneda, km, reservado)
             VALUES (:r, :a, :precio, :contado, :moneda, :km, :reservado)'
        );

        foreach ($autos as $a) {
            $auto->execute([
                'fuente' => $fuenteId, 'id_externo' => $a['id_externo'], 'marca' => $a['marca'], 'modelo' => $a['modelo'],
                'version' => $a['version'], 'anio' => $a['anio'], 'transmision' => $a['transmision'],
                'ubicacion' => $a['ubicacion'], 'url' => $a['url'], 'fecha' => $fecha, 'fecha2' => $fecha,
                'estado' => $a['reservado'] ? 'reservado' : 'activo',
            ]);
            $autoId = (int)$auto->fetchColumn();
            $auto->closeCursor();

            $foto->bindValue('r', $recoleccionId, PDO::PARAM_INT);
            $foto->bindValue('a', $autoId, PDO::PARAM_INT);
            $foto->bindValue('precio', $a['precio'] !== null ? (string)$a['precio'] : null, $a['precio'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $foto->bindValue('contado', $a['precio_contado'] !== null ? (string)$a['precio_contado'] : null, $a['precio_contado'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $foto->bindValue('moneda', $a['moneda']);
            $foto->bindValue('km', $a['km'], PDO::PARAM_INT);
            $foto->bindValue('reservado', $a['reservado'], PDO::PARAM_BOOL);
            $foto->execute();
        }
    }

    private function generarAvisos(int $recoleccionId, int $anteriorId, string $fecha): int
    {
        $mercado = new Mercado($this->pdo);
        $cambios = array_values(array_filter(
            Novedades::comparar($mercado->fotosDe($recoleccionId), $mercado->fotosDe($anteriorId))['cambios'],
            static fn(array $c): bool => in_array($c['tipo'], Novedades::CON_AVISO, true)
        ));
        if ($cambios === []) {
            return 0;
        }

        $insertar = $this->pdo->prepare(
            'INSERT INTO coincidencia (busqueda_id, auto_id, recoleccion_id, tipo, precio_anterior, precio_actual, fecha)
             VALUES (:b, :a, :r, :t, :antes, :ahora, :f)'
        );
        $total = 0;
        foreach (Busqueda::todas($this->pdo) as $busqueda) {
            foreach ($cambios as $c) {
                if (!Busqueda::coincide($busqueda, $c['auto'])) {
                    continue;
                }
                $insertar->execute([
                    'b' => $busqueda['id'], 'a' => $c['auto']['id'], 'r' => $recoleccionId, 't' => $c['tipo'],
                    'antes' => $c['precio_anterior'], 'ahora' => $c['precio_actual'], 'f' => $fecha,
                ]);
                $total++;
            }
        }
        return $total;
    }
}
