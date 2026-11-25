<?php
declare(strict_types=1);

namespace AutoCar\Admin;

use AutoCar\Afiliacion\Afiliacion;
use AutoCar\Auth\Preferencias;
use AutoCar\Core\Database;
use AutoCar\Estadisticas\Panel;
use AutoCar\Estadisticas\PlataEscapada;
use AutoCar\Estadisticas\PorPerfil;
use AutoCar\Core\Response;
use DateTimeImmutable;
use PDO;

/**
 * GET /api/admin/metricas?dias=7|30|90 (permiso metricas.ver): números de la app sacados de la base.
 * Lo que depende del período (resumen contra el período anterior, serie en el tiempo y embudo de las
 * recomendaciones) sale de Estadisticas\Panel; el resto es la foto de hoy.
 */
final class AdminMetricasController
{
    public function index(): void
    {
        $dias = Panel::dias($_GET['dias'] ?? null);
        $embudo = Panel::embudo($dias);
        Response::json([
            'periodo'       => ['dias' => $dias, 'opciones' => Panel::PERIODOS],
            'resumen'       => Panel::resumen($dias),
            // Clics y abonos que todavía no se cobraron (el resumen cuenta solo lo cobrado): para mostrarlo al lado
            'sin_liquidar'  => Afiliacion::sinLiquidar(Afiliacion::panel(new DateTimeImmutable())['evolucion']),
            'serie'         => Panel::serie($dias),
            'embudo'        => $embudo,
            // Lo que se dejó de ganar donde la gente se fue (sin pagar, sin tocar, sin clic), en pesos
            'escapado'      => PlataEscapada::del($dias, $embudo['total']),
            // Plata, recomendaciones, búsquedas y órdenes separados por tipo de usuario ("¿Para qué buscás el auto?")
            'por_perfil'    => PorPerfil::todo($dias),
            'inspecciones'  => Panel::inspecciones($dias),
            'recurrentes'   => Panel::recurrentes(),
            'usuarios'      => self::usuarios(),
            'publicaciones' => self::publicaciones(),
            'moderacion'    => self::moderacion(),
            'chat'          => self::chat(),
            'ganancias'     => self::ganancias(),
            // "¿Para qué buscás el auto?": cuántas cuentas personales eligieron cada respuesta
            'compradores_por_uso' => Preferencias::porUso(),
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

    /**
     * Lo que dejan las inspecciones, el ingreso de AutoCar (ver Precio.php: la comisión de AutoCar; sin fondo de
     * garantía desde el 23/11: lo que antes se apartaba para el fondo también cuenta como de AutoCar). El comprador paga con
     * Mercado Pago (Pagos\Cobros); el estado del pago queda retenido al pedir, se libera al firmar y se reembolsa si el
     * mecánico rechaza, el comprador cancela o el auto se vende antes del turno. El pago al mecánico se registra acá,
     * pero todavía no se le transfiere.
     * Los créditos que usan los compradores (un auto anterior se vendió) los pone AutoCar de su parte.
     */
    private static function ganancias(): array
    {
        $pdo = Database::pdo();
        $g = $pdo->query(
            "SELECT COALESCE(SUM(precio - credito_usado) FILTER (WHERE pago = 'liberado'), 0)                AS facturado,
                    COALESCE(SUM(monto_autocar - credito_usado) FILTER (WHERE pago = 'liberado'), 0)               AS autocar,
                    COALESCE(SUM(credito_usado) FILTER (WHERE pago = 'liberado'), 0)                         AS creditos_usados,
                    COALESCE(SUM(monto_mecanico) FILTER (WHERE pago = 'liberado'), 0)                        AS mecanicos,
                    COALESCE(SUM(precio - credito_usado) FILTER (WHERE pago = 'retenido'), 0)                AS retenido,
                    COALESCE(SUM(precio - credito_usado) FILTER (WHERE pago = 'reembolsado'), 0)             AS reembolsado,
                    COALESCE(SUM(monto_autocar - credito_usado) FILTER (WHERE pago = 'liberado'
                             AND created_at > now() - interval '30 days'), 0)                                 AS autocar_30d
               FROM inspeccion"
        )->fetch();
        $creditosPendientes = (float)$pdo->query('SELECT COALESCE(SUM(monto), 0) FROM credito WHERE usado_en IS NULL')->fetchColumn();
        $porEstado = $pdo->query('SELECT estado, COUNT(*) FROM inspeccion GROUP BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'disponible'       => true,
            'simulado'         => true,
            'aclaracion'       => 'Los compradores pagan la inspección con Mercado Pago. Lo que le toca al mecánico se registra, pero todavía no se le transfiere.',
            'inspecciones'     => array_map('intval', $porEstado),
            'facturado'        => (float)$g['facturado'],   // lo que pagaron los compradores (inspecciones firmadas)
            'autocar'          => (float)$g['autocar'],     // ganancia de AutoCar: la comisión menos los créditos
            'autocar_30d'      => (float)$g['autocar_30d'],
            'mecanicos'        => (float)$g['mecanicos'],   // pagado a los mecánicos
            'creditos'         => [
                'usados'     => (float)$g['creditos_usados'], // descontados del precio (los pone AutoCar)
                'pendientes' => $creditosPendientes,          // dados y todavía sin usar
            ],
            'retenido'         => (float)$g['retenido'],    // pedidos en curso: se libera al firmar
            'reembolsado'      => (float)$g['reembolsado'], // rechazadas, canceladas, no realizadas o caídas: vuelve al comprador
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
