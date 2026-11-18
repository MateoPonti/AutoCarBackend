<?php
declare(strict_types=1);

namespace AutoCar\Estadisticas;

use AutoCar\Auth\Preferencias;
use AutoCar\Core\Database;
use PDO;

/**
 * Las estadísticas del admin separadas por tipo de usuario: la respuesta a "¿Para qué buscás el auto?"
 * (Auth\Preferencias::USOS). El que no contestó queda en "sin_perfil".
 * Sirve para decidir qué cambiar: si a "Para la familia" casi todos eligen "Menos km", conviene subir el peso de los km
 * de ese perfil en la fórmula de "para vos" (Parámetros).
 * Todo cuenta compradores distintos en el período (los últimos N días), salvo "veces" en los órdenes.
 */
final class PorPerfil
{
    public const SIN_PERFIL = 'sin_perfil';
    /** Los orígenes que son del metabuscador (la publicación de AutoCar no) */
    private const DEL_METABUSCADOR = ['para_vos', 'destacado', 'metabuscador'];

    /** @return array<string, string> clave → texto, en el orden en que se muestran */
    public static function usos(): array
    {
        return Preferencias::USOS + [self::SIN_PERFIL => 'Sin perfil'];
    }

    /** Todo junto, para GET /api/admin/metricas. $dias ya viene validado (Panel::dias). */
    public static function todo(int $dias): array
    {
        return [
            'usos'         => self::usos(),
            'plata'        => self::plata($dias),
            'embudo'       => self::embudo($dias),
            'metabuscador' => self::metabuscador($dias),
            'ordenes'      => self::ordenes($dias),
        ];
    }

    /**
     * La plata del período por tipo de usuario, con las mismas cuentas que el informe de plata (Panel::totales):
     * ingresos = comisión de AutoCar de las firmadas ya cobradas o reembolsadas por un reclamo, menos los créditos;
     * pérdida = lo que costaron los reclamos que procedieron; neta = ingresos − pérdida.
     * @return list<array<string, mixed>>
     */
    public static function plata(int $dias): array
    {
        $pdo = Database::pdo();
        $desde = "now() - make_interval(days => $dias)"; // entero validado
        $inspecciones = $pdo->query(
            "SELECT COALESCE(p.uso, '" . self::SIN_PERFIL . "') AS uso,
                    COUNT(*) FILTER (WHERE i.created_at >= $desde) AS pagaron,
                    COUNT(DISTINCT i.comprador_id) FILTER (WHERE i.created_at >= $desde) AS compradores,
                    COALESCE(SUM(i.monto_autocar - i.credito_usado) FILTER (WHERE i.estado = 'firmada'
                             AND i.pago IN ('liberado', 'reembolsado') AND i.firmada_at >= $desde), 0) AS ingresos,
                    COALESCE(SUM(i.monto_autocar - i.credito_usado) FILTER (WHERE i.estado = 'firmada'
                             AND i.pago = 'retenido' AND i.firmada_at >= $desde), 0) AS por_cobrar
               FROM inspeccion i LEFT JOIN usuario_preferencia p ON p.usuario_id = i.comprador_id
              WHERE i.created_at >= $desde OR i.firmada_at >= $desde
              GROUP BY 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        $reclamos = $pdo->query(
            "SELECT COALESCE(p.uso, '" . self::SIN_PERFIL . "') AS uso,
                    COALESCE(SUM(COALESCE(r.devolucion, 0) + COALESCE(r.compensacion, 0) - COALESCE(r.franquicia, 0)), 0) AS perdida,
                    COUNT(*) AS reclamos
               FROM reclamo r
               JOIN inspeccion i ON i.id = r.inspeccion_id
               LEFT JOIN usuario_preferencia p ON p.usuario_id = i.comprador_id
              WHERE r.estado = 'procede' AND r.resuelto_at >= $desde
              GROUP BY 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        return self::filasPlata($inspecciones, $reclamos);
    }

    /**
     * Arma una fila por tipo de usuario (también los que no tienen datos). Función pura.
     * @param list<array<string, mixed>> $inspecciones filas {uso, pagaron, compradores, ingresos, por_cobrar}
     * @param list<array<string, mixed>> $reclamos filas {uso, perdida, reclamos}: los que procedieron
     * @return list<array<string, mixed>>
     */
    public static function filasPlata(array $inspecciones, array $reclamos): array
    {
        $porUso = array_column($inspecciones, null, 'uso');
        $reclamosPorUso = array_column($reclamos, null, 'uso');
        $salida = [];
        foreach (self::usos() as $uso => $nombre) {
            $f = $porUso[$uso] ?? [];
            $r = $reclamosPorUso[$uso] ?? [];
            $ingresos = (float)($f['ingresos'] ?? 0);
            $perdida = (float)($r['perdida'] ?? 0);
            $salida[] = [
                'uso'                  => $uso,
                'nombre'               => $nombre,
                'pagaron'              => (int)($f['pagaron'] ?? 0),
                'compradores'          => (int)($f['compradores'] ?? 0),
                'ingresos'             => $ingresos,
                'perdida'              => $perdida,
                'neta'                 => $ingresos - $perdida,
                'por_cobrar'           => (float)($f['por_cobrar'] ?? 0),
                'reclamos_procedieron' => (int)($r['reclamos'] ?? 0),
            ];
        }
        return $salida;
    }

    /**
     * Qué recomendación funciona mejor para cada tipo de usuario: por cada origen, cuántos la vieron, cuántos tocaron
     * "Mandale un mecánico" y cuántos pagaron la inspección desde ahí; "mejor" es el origen con más conversión.
     * @return list<array{uso: string, nombre: string, origenes: list<array<string, mixed>>, mejor: ?string}>
     */
    public static function embudo(int $dias): array
    {
        $pdo = Database::pdo();
        $desde = "now() - make_interval(days => $dias)"; // entero validado
        $eventos = $pdo->query(
            "SELECT COALESCE(p.uso, '" . self::SIN_PERFIL . "') AS uso, e.origen, e.tipo, COUNT(DISTINCT e.usuario_id) AS n
               FROM evento e LEFT JOIN usuario_preferencia p ON p.usuario_id = e.usuario_id
              WHERE e.tipo IN ('mostrado', 'tocado') AND e.created_at >= $desde
              GROUP BY 1, 2, 3"
        )->fetchAll(PDO::FETCH_ASSOC);
        $pagos = $pdo->query(
            "SELECT COALESCE(p.uso, '" . self::SIN_PERFIL . "') AS uso, i.origen, COUNT(DISTINCT i.comprador_id) AS n,
                    COUNT(DISTINCT i.comprador_id) FILTER (WHERE i.estado = 'firmada') AS firmadas
               FROM inspeccion i LEFT JOIN usuario_preferencia p ON p.usuario_id = i.comprador_id
              WHERE i.origen IS NOT NULL AND i.created_at >= $desde
              GROUP BY 1, 2"
        )->fetchAll(PDO::FETCH_ASSOC);
        // De los que vieron cada recomendación, cuántos ordenaron la lista por precio, km o fecha de publicación
        $ordenaron = $pdo->query(
            "SELECT COALESCE(p.uso, '" . self::SIN_PERFIL . "') AS uso, m.origen, o.ref AS orden, COUNT(DISTINCT m.usuario_id) AS n
               FROM (SELECT DISTINCT usuario_id, origen FROM evento WHERE tipo = 'mostrado' AND created_at >= $desde) m
               JOIN (SELECT DISTINCT usuario_id, ref FROM evento WHERE tipo = 'orden' AND created_at >= $desde) o
                 ON o.usuario_id = m.usuario_id
               LEFT JOIN usuario_preferencia p ON p.usuario_id = m.usuario_id
              GROUP BY 1, 2, 3"
        )->fetchAll(PDO::FETCH_ASSOC);
        return self::filasEmbudo($eventos, $pagos, $ordenaron);
    }

    /**
     * Función pura: de las filas agrupadas de la base al embudo por tipo de usuario.
     * @param list<array{uso: string, origen: string, tipo: string, n: mixed}> $eventos
     * @param list<array{uso: string, origen: string, n: mixed, firmadas?: mixed}> $pagos
     * @param list<array{uso: string, origen: string, orden: string, n: mixed}> $ordenaron
     */
    public static function filasEmbudo(array $eventos, array $pagos, array $ordenaron = []): array
    {
        $cuenta = [];
        foreach ($eventos as $e) {
            $cuenta[$e['uso']][$e['origen']][$e['tipo'] === 'mostrado' ? 'vieron' : 'tocaron'] = (int)$e['n'];
        }
        foreach ($pagos as $p) {
            $cuenta[$p['uso']][$p['origen']]['pagaron'] = (int)$p['n'];
            $cuenta[$p['uso']][$p['origen']]['firmadas'] = (int)($p['firmadas'] ?? 0);
        }
        foreach ($ordenaron as $o) {
            $cuenta[$o['uso']][$o['origen']]['ordenaron'][$o['orden']] = (int)$o['n'];
        }
        $salida = [];
        foreach (self::usos() as $uso => $nombre) {
            $origenes = [];
            foreach (Eventos::ORIGENES as $origen) {
                $c = $cuenta[$uso][$origen] ?? [];
                $vieron = $c['vieron'] ?? 0;
                $pagaron = $c['pagaron'] ?? 0;
                $origenes[] = [
                    'origen'     => $origen,
                    'vieron'     => $vieron,
                    'tocaron'    => $c['tocaron'] ?? 0,
                    'pagaron'    => $pagaron,
                    'firmadas'   => $c['firmadas'] ?? 0, // de los que pagaron, cuántos ya tienen la inspección firmada
                    // De los que la vieron, cuántos ordenaron por precio, km o fecha de publicación ("Más nuevos")
                    'ordenaron'  => [
                        'precio' => $c['ordenaron']['precio'] ?? 0,
                        'km'     => $c['ordenaron']['km'] ?? 0,
                        'nuevos' => $c['ordenaron']['nuevos'] ?? 0,
                    ],
                    // Pagaron ÷ vieron, en %. Sin nadie que la viera no hay conversión
                    'conversion' => $vieron > 0 ? round(min($pagaron / $vieron, 1) * 100, 1) : null,
                ];
            }
            $salida[] = ['uso' => $uso, 'nombre' => $nombre, 'origenes' => $origenes, 'mejor' => self::mejorOrigen($origenes)];
        }
        return $salida;
    }

    /**
     * El origen con más conversión (y, si empatan, con más pagos). null si ninguno logró un pago todavía. Función pura.
     * @param list<array{origen: string, pagaron: int, conversion: ?float}> $origenes
     */
    public static function mejorOrigen(array $origenes): ?string
    {
        $conPagos = array_filter($origenes, static fn(array $o): bool => $o['pagaron'] > 0 && $o['conversion'] !== null);
        if ($conPagos === []) {
            return null;
        }
        usort($conPagos, static fn(array $a, array $b): int =>
            [$b['conversion'], $b['pagaron']] <=> [$a['conversion'], $a['pagaron']]);
        return $conPagos[0]['origen'];
    }

    /**
     * Cuánta gente buscó en el metabuscador (abrió los resultados de una búsqueda) y, de esos, cuántos pagaron una
     * inspección en el período ("compró"): de cualquier lado y desde el metabuscador. Por tipo de usuario y en total.
     * @return array{total: array<string, int>, por_uso: list<array<string, mixed>>}
     */
    public static function metabuscador(int $dias): array
    {
        $desde = "now() - make_interval(days => $dias)"; // entero validado
        $origenes = "'" . implode("', '", self::DEL_METABUSCADOR) . "'";
        $filas = Database::pdo()->query(
            "WITH b AS (SELECT DISTINCT usuario_id FROM evento WHERE tipo = 'busco' AND created_at >= $desde),
                  c AS (SELECT DISTINCT comprador_id AS usuario_id FROM inspeccion WHERE created_at >= $desde),
                  m AS (SELECT DISTINCT comprador_id AS usuario_id FROM inspeccion
                         WHERE created_at >= $desde AND origen IN ($origenes))
             SELECT COALESCE(p.uso, '" . self::SIN_PERFIL . "') AS uso,
                    COUNT(*) AS buscaron, COUNT(c.usuario_id) AS compraron, COUNT(m.usuario_id) AS desde_metabuscador
               FROM b
               LEFT JOIN c ON c.usuario_id = b.usuario_id
               LEFT JOIN m ON m.usuario_id = b.usuario_id
               LEFT JOIN usuario_preferencia p ON p.usuario_id = b.usuario_id
              GROUP BY 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        return self::filasMetabuscador($filas);
    }

    /** Función pura: una fila por tipo de usuario (con ceros) y el total. */
    public static function filasMetabuscador(array $filas): array
    {
        $porUso = array_column($filas, null, 'uso');
        $total = ['buscaron' => 0, 'compraron' => 0, 'desde_metabuscador' => 0];
        $salida = [];
        foreach (self::usos() as $uso => $nombre) {
            $f = $porUso[$uso] ?? [];
            $fila = ['uso' => $uso, 'nombre' => $nombre];
            foreach (array_keys($total) as $clave) {
                $fila[$clave] = (int)($f[$clave] ?? 0);
                $total[$clave] += $fila[$clave];
            }
            $salida[] = $fila;
        }
        return ['total' => $total, 'por_uso' => $salida];
    }

    /**
     * Qué orden eligen en los resultados del metabuscador ("Menor precio", "Menos km", "Más nuevos" o "Recomendado"),
     * por tipo de usuario: cuántos compradores distintos lo eligieron y cuántas veces. "preferido" es el que más
     * compradores eligieron.
     * @return list<array{uso: string, nombre: string, ordenes: array<string, array{usuarios: int, veces: int}>, preferido: ?string}>
     */
    public static function ordenes(int $dias): array
    {
        $desde = "now() - make_interval(days => $dias)"; // entero validado
        $filas = Database::pdo()->query(
            "SELECT COALESCE(p.uso, '" . self::SIN_PERFIL . "') AS uso, e.ref AS orden,
                    COUNT(DISTINCT e.usuario_id) AS usuarios, COUNT(*) AS veces
               FROM evento e LEFT JOIN usuario_preferencia p ON p.usuario_id = e.usuario_id
              WHERE e.tipo = 'orden' AND e.created_at >= $desde
              GROUP BY 1, 2"
        )->fetchAll(PDO::FETCH_ASSOC);
        return self::filasOrdenes($filas);
    }

    /** Función pura: los órdenes conocidos por tipo de usuario (con ceros) y el preferido de cada uno. */
    public static function filasOrdenes(array $filas): array
    {
        $cuenta = [];
        foreach ($filas as $f) {
            if (in_array($f['orden'], Eventos::ORDENES, true)) {
                $cuenta[$f['uso']][$f['orden']] = ['usuarios' => (int)$f['usuarios'], 'veces' => (int)$f['veces']];
            }
        }
        $salida = [];
        foreach (self::usos() as $uso => $nombre) {
            $ordenes = [];
            foreach (Eventos::ORDENES as $orden) {
                $ordenes[$orden] = $cuenta[$uso][$orden] ?? ['usuarios' => 0, 'veces' => 0];
            }
            $preferido = null;
            foreach ($ordenes as $orden => $o) {
                if ($o['usuarios'] > 0 && ($preferido === null || [$o['usuarios'], $o['veces']] > [$ordenes[$preferido]['usuarios'], $ordenes[$preferido]['veces']])) {
                    $preferido = $orden;
                }
            }
            $salida[] = ['uso' => $uso, 'nombre' => $nombre, 'ordenes' => $ordenes, 'preferido' => $preferido];
        }
        return $salida;
    }
}
