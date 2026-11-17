<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Comparador\Clave;
use AutoCar\Core\Database;
use AutoCar\Parametros\Parametros;
use DateTimeImmutable;

/** Acceso a la base de las fallas típicas (tablas falla_tipica y falla_voto, migración 026). Las reglas están en FallaTipica. */
final class FallasTipicas
{
    /**
     * Todo lo de un modelo: las fallas con su estado y sus votos, cuántas inspecciones firmadas hay, el % medido de
     * cada ítem (null con menos del mínimo) y las fallas medidas. Con $mecanicoId trae su voto en cada una.
     * Sin $conDescartadas, las descartadas no se muestran (el público solo ve las que no se cayeron).
     */
    public static function delModelo(string $marca, string $modelo, ?int $mecanicoId = null, bool $conDescartadas = false): array
    {
        $pdo = Database::pdo();
        $minimo = (int)Parametros::valor('fallas_minimo_inspecciones');
        $necesarios = (int)Parametros::valor('fallas_votos_confirmar');

        $st = $pdo->prepare("SELECT COUNT(*) FROM inspeccion WHERE estado = 'firmada' AND marca = :m AND modelo = :mo");
        $st->execute(['m' => $marca, 'mo' => $modelo]);
        $inspecciones = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT it.codigo, COUNT(*) AS n FROM inspeccion i JOIN inspeccion_item it ON it.inspeccion_id = i.id
            WHERE i.estado = 'firmada' AND i.marca = :m AND i.modelo = :mo AND it.resultado <> 'ok' GROUP BY it.codigo");
        $st->execute(['m' => $marca, 'mo' => $modelo]);
        $conItem = array_map('intval', $st->fetchAll(\PDO::FETCH_KEY_PAIR));
        $porcentajes = FallaTipica::porcentajes($conItem, $inspecciones, $minimo);

        $st = $pdo->prepare(
            'SELECT f.id, f.item_codigo, f.titulo, f.descripcion, f.origen, f.fuente_url, f.created_at,
                    COUNT(v.*) FILTER (WHERE v.confirma) AS confirman, COUNT(v.*) FILTER (WHERE NOT v.confirma) AS descartan,
                    (SELECT mv.confirma FROM falla_voto mv WHERE mv.falla_id = f.id AND mv.mecanico_id = :yo) AS mi_voto
               FROM falla_tipica f LEFT JOIN falla_voto v ON v.falla_id = f.id
              WHERE f.marca = :m AND f.modelo = :mo
              GROUP BY f.id ORDER BY f.created_at, f.id'
        );
        $st->execute(['m' => $marca, 'mo' => $modelo, 'yo' => $mecanicoId ?? 0]);
        $fallas = [];
        foreach ($st->fetchAll() as $f) {
            $estado = FallaTipica::estado((int)$f['confirman'], (int)$f['descartan'], $necesarios);
            if ($estado === 'descartada' && !$conDescartadas) {
                continue;
            }
            $falla = [
                'id'          => (int)$f['id'],
                'item_codigo' => $f['item_codigo'],
                'titulo'      => $f['titulo'],
                'descripcion' => $f['descripcion'],
                'origen'      => $f['origen'],
                'fuente_url'  => $f['fuente_url'],
                'estado'      => $estado,
                'votos'       => ['confirman' => (int)$f['confirman'], 'descartan' => (int)$f['descartan']],
                // Lo que midieron las inspecciones en ese ítem (null si todavía hay menos del mínimo)
                'porcentaje'  => $porcentajes[$f['item_codigo']] ?? null,
                'created_at'  => (new DateTimeImmutable($f['created_at']))->format(DATE_ATOM),
            ];
            if ($mecanicoId !== null) {
                $falla['mi_voto'] = $f['mi_voto'] === null ? null : (bool)$f['mi_voto'];
            }
            $fallas[] = $falla;
        }
        // Confirmadas primero, después las sin confirmar; dentro de cada grupo, las más medidas primero
        $peso = ['confirmada' => 0, 'sin_confirmar' => 1, 'descartada' => 2];
        usort($fallas, static fn(array $a, array $b): int =>
            [$peso[$a['estado']], -($a['porcentaje'] ?? -1)] <=> [$peso[$b['estado']], -($b['porcentaje'] ?? -1)]);

        return [
            'marca'        => $marca,
            'modelo'       => $modelo,
            'clave'        => Clave::de($marca, $modelo),   // la misma que usa el comparador
            'inspecciones' => $inspecciones,
            'minimo'       => $minimo,
            'medido'       => $inspecciones >= $minimo,
            'fallas'       => $fallas,
            'medidas'      => FallaTipica::medidas($porcentajes, Parametros::valor('fallas_porcentaje_medido')),
        ];
    }

    /** Carga una falla nueva. → id */
    public static function crear(string $marca, string $modelo, array $datos, int $usuarioId): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO falla_tipica (marca, modelo, item_codigo, titulo, descripcion, origen, fuente_url, creada_por)
             VALUES (:m, :mo, :item, :t, :d, :o, :f, :u) RETURNING id'
        );
        $st->execute(['m' => $marca, 'mo' => $modelo, 'item' => $datos['item_codigo'], 't' => $datos['titulo'],
            'd' => $datos['descripcion'], 'o' => $datos['origen'], 'f' => $datos['fuente_url'], 'u' => $usuarioId]);
        return (int)$st->fetchColumn();
    }

    /** marca y modelo de una falla, o null si no existe. */
    public static function modeloDe(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT marca, modelo FROM falla_tipica WHERE id = :id');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    /** El voto del mecánico (lo puede cambiar). */
    public static function votar(int $fallaId, int $mecanicoId, bool $confirma): void
    {
        Database::pdo()->prepare(
            'INSERT INTO falla_voto (falla_id, mecanico_id, confirma) VALUES (:f, :m, :c)
             ON CONFLICT (falla_id, mecanico_id) DO UPDATE SET confirma = EXCLUDED.confirma, created_at = now()'
        )->execute(['f' => $fallaId, 'm' => $mecanicoId, 'c' => $confirma ? 'true' : 'false']);
    }
}
