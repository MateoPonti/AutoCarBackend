<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Core\Database;

/** Acceso a la base de los reclamos (tabla reclamo). Las reglas están en Reclamo. */
final class Reclamos
{
    public const POR_PAGINA = 100;

    private const COLUMNAS = 'r.id, r.inspeccion_id, r.item_codigo, r.descripcion, r.estado, r.compensacion, r.respuesta,
        r.resuelto_por, r.created_at, r.resuelto_at, i.precio, i.titulo, i.url, i.fuente, i.firmada_at, i.conclusion,
        i.mecanico_id, i.comprador_id, mu.nombre AS mecanico_nombre, i.taller_firma, u.nombre AS comprador_nombre,
        ru.nombre AS resuelto_por_nombre';

    private const DESDE = 'FROM reclamo r
        JOIN inspeccion i ON i.id = r.inspeccion_id
        JOIN usuario mu ON mu.id = i.mecanico_id
        JOIN usuario u ON u.id = i.comprador_id
        LEFT JOIN usuario ru ON ru.id = r.resuelto_por';

    /** → id del reclamo, o null si la inspección ya tenía uno (la base admite uno solo por inspección). */
    public static function crear(int $inspeccionId, array $datos): ?int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO reclamo (inspeccion_id, item_codigo, descripcion) VALUES (:i, :item, :d)
             ON CONFLICT (inspeccion_id) DO NOTHING RETURNING id'
        );
        $st->execute(['i' => $inspeccionId, 'item' => $datos['item_codigo'], 'd' => $datos['descripcion']]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public static function porId(int $id, bool $bloquear = false): ?array
    {
        $st = Database::pdo()->prepare('SELECT ' . self::COLUMNAS . ' ' . self::DESDE
            . ' WHERE r.id = :id' . ($bloquear ? ' FOR UPDATE OF r' : ''));
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    /** Cola de reclamos: los abiertos, los más viejos primero; los resueltos, los últimos primero. */
    public static function listar(?string $estado): array
    {
        $where = $estado !== null ? 'WHERE r.estado = :e' : '';
        $orden = $estado === 'abierto' ? 'r.created_at ASC' : 'COALESCE(r.resuelto_at, r.created_at) DESC';
        $st = Database::pdo()->prepare('SELECT ' . self::COLUMNAS . ' ' . self::DESDE
            . " $where ORDER BY $orden, r.id LIMIT " . self::POR_PAGINA);
        $st->execute($estado !== null ? ['e' => $estado] : []);
        return $st->fetchAll();
    }

    /** Se llama dentro de una transacción con el reclamo bloqueado y todavía abierto. */
    public static function resolver(array $reclamo, array $resolucion, int $resueltoPor): void
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            'UPDATE reclamo SET estado = :e, compensacion = :c, respuesta = :r, resuelto_por = :por, resuelto_at = now()
             WHERE id = :id AND estado = \'abierto\''
        )->execute([
            'id' => $reclamo['id'], 'e' => $resolucion['estado'], 'c' => $resolucion['compensacion'],
            'r' => $resolucion['respuesta'], 'por' => $resueltoPor,
        ]);
        // Si procede, al comprador se le devuelve lo que pagó (el mecánico lo devuelve de sus próximas inspecciones)
        if ($resolucion['estado'] === 'procede') {
            $pdo->prepare("UPDATE inspeccion SET pago = 'reembolsado', updated_at = now() WHERE id = :id")
                ->execute(['id' => $reclamo['inspeccion_id']]);
        }
    }

    public static function paraApi(array $r): array
    {
        $fecha = static fn($v): ?string => $v === null ? null : (new \DateTimeImmutable($v))->format(DATE_ATOM);
        return [
            'id'           => (int)$r['id'],
            'estado'       => $r['estado'],
            'item_codigo'  => $r['item_codigo'],
            'descripcion'  => $r['descripcion'],
            'compensacion' => $r['compensacion'] === null ? null : (float)$r['compensacion'],
            'tope'         => Precio::topeCompensacion((float)$r['precio']),
            'respuesta'    => $r['respuesta'],
            'created_at'   => $fecha($r['created_at']),
            'resuelto_at'  => $fecha($r['resuelto_at']),
            'resuelto_por' => $r['resuelto_por_nombre'],
            'inspeccion'   => [
                'id'         => (int)$r['inspeccion_id'],
                'titulo'     => $r['titulo'],
                'url'        => $r['url'],
                'fuente'     => $r['fuente'],
                'precio'     => (float)$r['precio'],
                'conclusion' => $r['conclusion'],
                'firmada_at' => $fecha($r['firmada_at']),
                'mecanico'   => ['id' => (int)$r['mecanico_id'], 'nombre' => $r['mecanico_nombre'], 'taller' => $r['taller_firma']],
                'comprador'  => ['id' => (int)$r['comprador_id'], 'nombre' => $r['comprador_nombre']],
            ],
        ];
    }
}
