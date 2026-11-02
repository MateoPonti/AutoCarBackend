<?php
declare(strict_types=1);

namespace AutoCar\Chat;

use AutoCar\Core\Database;

/**
 * Acceso a la tabla conversacion. Toda lectura filtra por participante: un usuario que no es
 * el interesado ni el vendedor nunca obtiene la conversación (evita IDOR).
 */
final class Conversacion
{
    /**
     * Devuelve [id, creada]. Si el interesado ya tenía conversación por ese vehículo devuelve esa
     * (ON CONFLICT cubre también dos pedidos simultáneos).
     */
    public static function abrir(int $vehiculoId, int $interesadoId, int $vendedorId): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'INSERT INTO conversacion (vehiculo_id, interesado_id, vendedor_id) VALUES (:vehiculo, :interesado, :vendedor)
             ON CONFLICT (vehiculo_id, interesado_id) DO NOTHING RETURNING id'
        );
        $st->execute(['vehiculo' => $vehiculoId, 'interesado' => $interesadoId, 'vendedor' => $vendedorId]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            return [(int)$id, true];
        }
        $st = $pdo->prepare('SELECT id FROM conversacion WHERE vehiculo_id = :vehiculo AND interesado_id = :interesado');
        $st->execute(['vehiculo' => $vehiculoId, 'interesado' => $interesadoId]);
        return [(int)$st->fetchColumn(), false];
    }

    /** La conversación (con el estado del vehículo) sólo si el usuario participa; si no, null. */
    public static function deParticipante(int $id, int $usuarioId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT c.*, v.estado AS vehiculo_estado FROM conversacion c JOIN vehiculo v ON v.id = c.vehiculo_id
              WHERE c.id = :id AND (c.interesado_id = :yo1 OR c.vendedor_id = :yo2)'
        );
        $st->execute(['id' => $id, 'yo1' => $usuarioId, 'yo2' => $usuarioId]);
        return $st->fetch() ?: null;
    }

    /**
     * Bandeja del usuario: vehículo, el otro participante, último mensaje y cuántos no leyó.
     * Al vendedor no le aparece una conversación hasta que el interesado escribe algo.
     */
    public static function delUsuario(int $usuarioId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT c.id, c.vehiculo_id, c.interesado_id, c.updated_at,
                    v.marca, v.modelo, v.anio, v.estado AS vehiculo_estado,
                    (SELECT f.id FROM vehiculo_foto f WHERE f.vehiculo_id = v.id ORDER BY f.orden, f.id LIMIT 1) AS foto_id,
                    o.id AS otro_id, o.nombre AS otro_nombre,
                    (SELECT m.texto FROM mensaje m WHERE m.conversacion_id = c.id ORDER BY m.id DESC LIMIT 1) AS ultimo_texto,
                    (SELECT COUNT(*) FROM mensaje m WHERE m.conversacion_id = c.id AND m.usuario_id <> :yo1 AND NOT m.leido) AS no_leidos
               FROM conversacion c
               JOIN vehiculo v ON v.id = c.vehiculo_id
               JOIN usuario o ON o.id = CASE WHEN c.interesado_id = :yo2 THEN c.vendedor_id ELSE c.interesado_id END
              WHERE c.interesado_id = :yo3
                 OR (c.vendedor_id = :yo4 AND EXISTS (SELECT 1 FROM mensaje m WHERE m.conversacion_id = c.id))
              ORDER BY c.updated_at DESC, c.id DESC'
        );
        $st->execute(['yo1' => $usuarioId, 'yo2' => $usuarioId, 'yo3' => $usuarioId, 'yo4' => $usuarioId]);
        return $st->fetchAll();
    }

    /** Del otro participante sólo se expone id y nombre (nunca el email). */
    public static function paraApi(array $c, int $yo): array
    {
        return [
            'id'       => (int)$c['id'],
            'rol'      => (int)$c['interesado_id'] === $yo ? 'interesado' : 'vendedor',
            'vehiculo' => [
                'id'     => (int)$c['vehiculo_id'],
                'titulo' => "{$c['marca']} {$c['modelo']} {$c['anio']}",
                'estado' => $c['vehiculo_estado'],
                'foto'   => $c['foto_id'] !== null ? '/api/fotos/' . (int)$c['foto_id'] : null,
            ],
            'con'            => ['id' => (int)$c['otro_id'], 'nombre' => $c['otro_nombre']],
            'ultimo_mensaje' => $c['ultimo_texto'],
            'no_leidos'      => (int)$c['no_leidos'],
            'abierta'        => self::abierta($c),
            'actualizada'    => Mensaje::fecha($c['updated_at']),
        ];
    }

    /** Si el vehículo se eliminó, la conversación queda de sólo lectura. */
    public static function abierta(array $c): bool
    {
        return $c['vehiculo_estado'] !== 'eliminado';
    }
}
