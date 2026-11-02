<?php
declare(strict_types=1);

namespace AutoCar\Admin;

use AutoCar\Core\Database;
use AutoCar\Core\Response;
use PDO;

/**
 * GET /api/admin/metricas (permiso metricas.ver): números de la app sacados de la base.
 * Ninguna consulta recibe datos del usuario, así que no hace falta preparar parámetros.
 */
final class AdminMetricasController
{
    public function index(): void
    {
        Response::json([
            'usuarios'      => self::usuarios(),
            'publicaciones' => self::publicaciones(),
            'moderacion'    => self::moderacion(),
            'chat'          => self::chat(),
            // No hay cobros todavía: los planes son de muestra y no hay pagos ni suscripciones en la base.
            'ganancias'     => [
                'disponible' => false,
                'motivo'     => 'Todavía no hay cobros: los planes son de muestra y no se registran pagos.',
            ],
            'generado'      => date(DATE_ATOM),
        ]);
    }

    private static function usuarios(): array
    {
        $pdo = Database::pdo();
        $t = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE activo) AS activos,
                    COUNT(*) FILTER (WHERE NOT activo) AS bajas,
                    COUNT(*) FILTER (WHERE created_at > now() - interval '30 days') AS nuevos_30d
               FROM usuario"
        )->fetch();
        // "Activos en los últimos 30 días": publicaron o escribieron un mensaje en ese lapso
        $conActividad = (int)$pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT usuario_id FROM vehiculo WHERE usuario_id IS NOT NULL AND created_at > now() - interval '30 days'
                UNION
                SELECT usuario_id FROM mensaje WHERE created_at > now() - interval '30 days'
             ) a"
        )->fetchColumn();
        $porRol = $pdo->query(
            'SELECT r.nombre AS rol, COUNT(u.id) AS cantidad FROM rol r LEFT JOIN usuario u ON u.rol_id = r.id
              GROUP BY r.id ORDER BY r.id'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_map('intval', $t) + [
            'con_actividad_30d' => $conActividad,
            'por_rol'           => array_map('intval', $porRol),
        ];
    }

    private static function publicaciones(): array
    {
        $pdo = Database::pdo();
        $t = $pdo->query(
            "SELECT COUNT(*) FILTER (WHERE estado <> 'eliminado') AS total,
                    COUNT(*) FILTER (WHERE created_at > now() - interval '30 days') AS nuevas_30d,
                    COUNT(*) FILTER (WHERE usuario_id IS NOT NULL) AS de_usuarios,
                    COALESCE(ROUND(AVG(precio) FILTER (WHERE estado = 'disponible')), 0) AS precio_promedio,
                    COALESCE(SUM(precio) FILTER (WHERE estado = 'disponible'), 0) AS valor_en_venta,
                    COALESCE(SUM(precio) FILTER (WHERE estado = 'vendido'), 0) AS valor_vendido
               FROM vehiculo"
        )->fetch();
        $porEstado = $pdo->query('SELECT estado, COUNT(*) FROM vehiculo GROUP BY estado ORDER BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);
        $marcas = $pdo->query(
            "SELECT marca, COUNT(*) AS cantidad FROM vehiculo WHERE estado <> 'eliminado'
              GROUP BY marca ORDER BY cantidad DESC, marca LIMIT 5"
        )->fetchAll();
        return [
            'total'           => (int)$t['total'],
            'nuevas_30d'      => (int)$t['nuevas_30d'],
            'de_usuarios'     => (int)$t['de_usuarios'],
            'precio_promedio' => (float)$t['precio_promedio'],
            'valor_en_venta'  => (float)$t['valor_en_venta'],
            'valor_vendido'   => (float)$t['valor_vendido'],
            'por_estado'      => array_map('intval', $porEstado),
            'top_marcas'      => array_map(fn(array $m) => ['marca' => $m['marca'], 'cantidad' => (int)$m['cantidad']], $marcas),
        ];
    }

    private static function moderacion(): array
    {
        $m = Database::pdo()->query(
            "SELECT COUNT(*) FILTER (WHERE estado = 'pendiente') AS pendientes,
                    COUNT(*) FILTER (WHERE estado = 'rechazado') AS rechazadas,
                    MIN(created_at) FILTER (WHERE estado = 'pendiente') AS pendiente_desde
               FROM vehiculo"
        )->fetch();
        return [
            'pendientes'      => (int)$m['pendientes'],
            'rechazadas'      => (int)$m['rechazadas'],
            // Cuánto hace que espera la publicación pendiente más vieja (null si no hay ninguna)
            'espera_maxima_h' => $m['pendiente_desde'] !== null
                ? (int)floor((time() - strtotime($m['pendiente_desde'])) / 3600)
                : null,
        ];
    }

    private static function chat(): array
    {
        $c = Database::pdo()->query(
            "SELECT (SELECT COUNT(*) FROM conversacion) AS conversaciones,
                    (SELECT COUNT(*) FROM conversacion WHERE created_at > now() - interval '30 days') AS conversaciones_30d,
                    (SELECT COUNT(*) FROM mensaje WHERE created_at > now() - interval '30 days') AS mensajes_30d"
        )->fetch();
        return array_map('intval', $c);
    }
}
