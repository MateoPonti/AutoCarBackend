<?php
declare(strict_types=1);

namespace AutoCar\Estadisticas;

use AutoCar\Core\Database;
use PDO;

/**
 * Los números del panel de estadísticas del admin para un período (los últimos 7, 30 o 90 días), comparados con el
 * período anterior del mismo largo. "Compró" = pagó la inspección: el pago se hace al pedirla (simulado hasta que
 * haya Mercado Pago), así que cuenta cada inspección pedida, aunque después se haya reembolsado.
 * Las fechas se agrupan en la hora de Argentina.
 */
final class Panel
{
    public const PERIODOS = [7, 30, 90];
    private const ZONA = 'America/Argentina/Buenos_Aires';

    /** El período pedido, o 30 días si no es uno de los permitidos. */
    public static function dias(mixed $valor): int
    {
        $dias = filter_var($valor, FILTER_VALIDATE_INT);
        return in_array($dias, self::PERIODOS, true) ? $dias : 30;
    }

    /**
     * Totales del período y del anterior: nuevos usuarios, inspecciones pagadas, compradores, firmadas,
     * facturado, ganancia de AutoCar y lo que el fondo pagó por reclamos (neto de franquicias).
     * @return array{actual: array<string, float|int>, anterior: array<string, float|int>}
     */
    public static function resumen(int $dias): array
    {
        return ['actual' => self::totales($dias, 0), 'anterior' => self::totales($dias, $dias)];
    }

    /** @return array<string, float|int> */
    private static function totales(int $dias, int $corrimiento): array
    {
        // $dias y $corrimiento son enteros (dias() valida el período): van en la consulta como números
        $desde = $dias + $corrimiento;
        $st = Database::pdo()->query(
            "WITH r AS (SELECT now() - make_interval(days => $desde) AS desde, now() - make_interval(days => $corrimiento) AS hasta)
             SELECT (SELECT COUNT(*) FROM usuario, r WHERE created_at >= r.desde AND created_at < r.hasta) AS nuevos_usuarios,
                    (SELECT COUNT(*) FROM inspeccion, r WHERE created_at >= r.desde AND created_at < r.hasta) AS pagaron,
                    (SELECT COUNT(DISTINCT comprador_id) FROM inspeccion, r WHERE created_at >= r.desde AND created_at < r.hasta) AS compradores,
                    (SELECT COUNT(*) FROM inspeccion, r WHERE firmada_at >= r.desde AND firmada_at < r.hasta) AS firmadas,
                    (SELECT COALESCE(SUM(precio - credito_usado), 0) FROM inspeccion, r
                      WHERE pago = 'liberado' AND firmada_at >= r.desde AND firmada_at < r.hasta) AS facturado,
                    (SELECT COALESCE(SUM(monto_autocar - monto_fondo - credito_usado), 0) FROM inspeccion, r
                      WHERE pago = 'liberado' AND firmada_at >= r.desde AND firmada_at < r.hasta) AS ganancia,
                    (SELECT COALESCE(SUM(COALESCE(devolucion, 0) + COALESCE(compensacion, 0) - COALESCE(franquicia, 0)), 0)
                       FROM reclamo, r WHERE estado = 'procede' AND resuelto_at >= r.desde AND resuelto_at < r.hasta) AS reclamos"
        );
        $t = $st->fetch();
        return [
            'nuevos_usuarios' => (int)$t['nuevos_usuarios'],
            'pagaron'         => (int)$t['pagaron'],
            'compradores'     => (int)$t['compradores'],
            'firmadas'        => (int)$t['firmadas'],
            'facturado'       => (float)$t['facturado'],
            'ganancia'        => (float)$t['ganancia'],
            'reclamos'        => (float)$t['reclamos'],
        ];
    }

    /**
     * Los mismos números día por día (7 y 30 días) o semana por semana (90 días), con todos los días o
     * semanas del período aunque no haya pasado nada.
     * @return array{unidad: string, puntos: list<array<string, mixed>>}
     */
    public static function serie(int $dias): array
    {
        $unidad = $dias <= 31 ? 'day' : 'week';
        $grupo = static fn(string $columna): string => "date_trunc('$unidad', ($columna) AT TIME ZONE '" . self::ZONA . "')";
        $antes = $dias - 1; // entero: dias() valida el período
        $st = Database::pdo()->query(
            "WITH f AS (
               SELECT generate_series({$grupo("now() - make_interval(days => $antes)")}, {$grupo('now()')},
                                      interval '1 $unidad') AS fecha
             ),
             u AS (SELECT {$grupo('created_at')} AS fecha, COUNT(*) AS n FROM usuario GROUP BY 1),
             p AS (SELECT {$grupo('created_at')} AS fecha, COUNT(*) AS n FROM inspeccion GROUP BY 1),
             g AS (SELECT {$grupo('firmada_at')} AS fecha, COUNT(*) AS firmadas,
                          SUM(precio - credito_usado) FILTER (WHERE pago = 'liberado') AS facturado,
                          SUM(monto_autocar - monto_fondo - credito_usado) FILTER (WHERE pago = 'liberado') AS ganancia
                     FROM inspeccion WHERE firmada_at IS NOT NULL GROUP BY 1),
             r AS (SELECT {$grupo('resuelto_at')} AS fecha,
                          SUM(COALESCE(devolucion, 0) + COALESCE(compensacion, 0) - COALESCE(franquicia, 0)) AS reclamos
                     FROM reclamo WHERE estado = 'procede' AND resuelto_at IS NOT NULL GROUP BY 1)
             SELECT to_char(f.fecha, 'YYYY-MM-DD') AS fecha,
                    COALESCE(u.n, 0) AS nuevos_usuarios, COALESCE(p.n, 0) AS pagaron, COALESCE(g.firmadas, 0) AS firmadas,
                    COALESCE(g.facturado, 0) AS facturado, COALESCE(g.ganancia, 0) AS ganancia, COALESCE(r.reclamos, 0) AS reclamos
               FROM f LEFT JOIN u USING (fecha) LEFT JOIN p USING (fecha) LEFT JOIN g USING (fecha) LEFT JOIN r USING (fecha)
              ORDER BY f.fecha"
        );
        $puntos = array_map(static fn(array $x): array => [
            'fecha'           => $x['fecha'],
            'nuevos_usuarios' => (int)$x['nuevos_usuarios'],
            'pagaron'         => (int)$x['pagaron'],
            'firmadas'        => (int)$x['firmadas'],
            'facturado'       => (float)$x['facturado'],
            'ganancia'        => (float)$x['ganancia'],
            'reclamos'        => (float)$x['reclamos'],
        ], $st->fetchAll());
        return ['unidad' => $unidad === 'day' ? 'dia' : 'semana', 'puntos' => $puntos];
    }

    /**
     * Embudo de las recomendaciones en el período, por origen: cuántos compradores la vieron, cuántos tocaron
     * "Mandale un mecánico", cuántos pagaron la inspección desde ahí y cuántos ya la tienen firmada. Todo cuenta
     * compradores distintos (un comprador con dos inspecciones cuenta una vez), así ningún paso supera al anterior.
     * "total" junta todos los orígenes; "sin_origen" son las inspecciones pedidas sin dato (anteriores a medirlo).
     * @return array{origenes: list<array<string, mixed>>, total: array<string, int>, sin_origen: int}
     */
    public static function embudo(int $dias): array
    {
        $pdo = Database::pdo();
        $desde = "now() - make_interval(days => $dias)"; // $dias es uno de PERIODOS (entero validado)
        $origenes = [];
        $st = $pdo->prepare(
            "SELECT (SELECT COUNT(DISTINCT usuario_id) FROM evento WHERE origen = :o1 AND tipo = 'mostrado' AND created_at >= $desde) AS vieron,
                    (SELECT COUNT(DISTINCT usuario_id) FROM evento WHERE origen = :o2 AND tipo = 'tocado' AND created_at >= $desde) AS tocaron,
                    (SELECT COUNT(DISTINCT comprador_id) FROM inspeccion WHERE origen = :o3 AND created_at >= $desde) AS pagaron,
                    (SELECT COUNT(DISTINCT comprador_id) FROM inspeccion WHERE origen = :o4 AND estado = 'firmada' AND created_at >= $desde) AS firmadas"
        );
        foreach (Eventos::ORIGENES as $origen) {
            $st->execute(['o1' => $origen, 'o2' => $origen, 'o3' => $origen, 'o4' => $origen]);
            $origenes[] = ['origen' => $origen] + array_map('intval', $st->fetch(PDO::FETCH_ASSOC));
        }
        $total = array_map('intval', $pdo->query(
            "SELECT (SELECT COUNT(DISTINCT usuario_id) FROM evento WHERE tipo = 'mostrado' AND created_at >= $desde) AS vieron,
                    (SELECT COUNT(DISTINCT usuario_id) FROM evento WHERE tipo = 'tocado' AND created_at >= $desde) AS tocaron,
                    (SELECT COUNT(DISTINCT comprador_id) FROM inspeccion WHERE origen IS NOT NULL AND created_at >= $desde) AS pagaron,
                    (SELECT COUNT(DISTINCT comprador_id) FROM inspeccion WHERE origen IS NOT NULL AND estado = 'firmada' AND created_at >= $desde) AS firmadas,
                    (SELECT COUNT(*) FROM inspeccion WHERE origen IS NULL AND created_at >= $desde) AS sin_origen"
        )->fetch(PDO::FETCH_ASSOC));
        $sinOrigen = $total['sin_origen'];
        unset($total['sin_origen']);
        return ['origenes' => $origenes, 'total' => $total, 'sin_origen' => $sinOrigen];
    }

    /**
     * Cómo anduvieron las inspecciones pedidas en el período: en qué estado están, cuánto se tardó de pagar a
     * firmar (mediana, en horas), el precio promedio, qué concluyó el mecánico, los reclamos y los mecánicos
     * que más firmaron. Sirve para ver si el servicio funciona, no solo cuánto se vende.
     * @return array<string, mixed>
     */
    public static function inspecciones(int $dias): array
    {
        $pdo = Database::pdo();
        $desde = "now() - make_interval(days => $dias)"; // $dias es uno de PERIODOS (entero validado)
        $t = $pdo->query(
            "SELECT COUNT(*) AS pedidas,
                    COALESCE(ROUND(AVG(precio)), 0) AS precio_promedio,
                    ROUND((percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM firmada_at - created_at) / 3600)
                           FILTER (WHERE estado = 'firmada'))::numeric) AS horas_hasta_firma
               FROM inspeccion WHERE created_at >= $desde"
        )->fetch();
        $porEstado = $pdo->query(
            "SELECT estado, COUNT(*) FROM inspeccion WHERE created_at >= $desde GROUP BY estado"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $conclusiones = $pdo->query(
            "SELECT conclusion, COUNT(*) FROM inspeccion
              WHERE estado = 'firmada' AND firmada_at >= $desde GROUP BY conclusion"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $reclamos = $pdo->query(
            "SELECT COUNT(*) AS abiertos_en_periodo,
                    COUNT(*) FILTER (WHERE estado = 'procede') AS proceden,
                    COUNT(*) FILTER (WHERE estado = 'no_procede') AS no_proceden,
                    COUNT(*) FILTER (WHERE estado = 'abierto') AS sin_resolver
               FROM reclamo WHERE created_at >= $desde"
        )->fetch();
        // Los que más firmaron en el período, con sus reclamos que procedieron (de esas mismas inspecciones)
        $mecanicos = $pdo->query(
            "SELECT COALESCE(t.nombre, u.nombre) AS nombre, COUNT(*) AS firmadas,
                    COUNT(r.id) FILTER (WHERE r.estado = 'procede') AS reclamos,
                    COALESCE(SUM(i.monto_mecanico), 0) AS cobrado
               FROM inspeccion i
               JOIN usuario u ON u.id = i.mecanico_id
               LEFT JOIN taller t ON t.usuario_id = i.mecanico_id
               LEFT JOIN reclamo r ON r.inspeccion_id = i.id
              WHERE i.estado = 'firmada' AND i.firmada_at >= $desde
              GROUP BY 1 ORDER BY firmadas DESC, nombre LIMIT 5"
        )->fetchAll();
        return [
            'pedidas'           => (int)$t['pedidas'],
            'precio_promedio'   => (float)$t['precio_promedio'],
            'horas_hasta_firma' => $t['horas_hasta_firma'] !== null ? (int)$t['horas_hasta_firma'] : null,
            'por_estado'        => array_map('intval', $porEstado),
            'conclusiones'      => array_map('intval', $conclusiones),
            'reclamos'          => array_map('intval', $reclamos),
            'top_mecanicos'     => array_map(static fn(array $m): array => [
                'nombre'   => $m['nombre'],
                'firmadas' => (int)$m['firmadas'],
                'reclamos' => (int)$m['reclamos'],
                'cobrado'  => (float)$m['cobrado'],
            ], $mecanicos),
        ];
    }

    /** Compradores que pidieron más de una inspección (en toda la historia): los que vuelven. */
    public static function recurrentes(): array
    {
        $r = Database::pdo()->query(
            'SELECT COUNT(*) AS compradores, COUNT(*) FILTER (WHERE n > 1) AS recurrentes
               FROM (SELECT comprador_id, COUNT(*) AS n FROM inspeccion GROUP BY comprador_id) c'
        )->fetch();
        return ['compradores' => (int)$r['compradores'], 'recurrentes' => (int)$r['recurrentes']];
    }
}
