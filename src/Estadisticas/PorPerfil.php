<?php
declare(strict_types=1);

namespace AutoCar\Estadisticas;

use AutoCar\Auth\Preferencias;
use AutoCar\Core\Database;
use AutoCar\MetaBusqueda\Estadistica;
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
        $plata = self::plata($dias);
        return [
            'usos'         => self::usos(),
            'plata'        => $plata,
            'embudo'       => self::embudo($dias),
            'metabuscador' => self::metabuscador($dias),
            'ordenes'      => self::ordenes($dias),
            'valor'        => self::valor($dias, $plata),
        ];
    }

    /** Lo que cuenta como interacción: acciones del usuario (buscar, cambiar el orden, tocar "Mandale un mecánico") */
    public const INTERACCIONES = ['busco', 'orden', 'tocado'];
    /** Hasta cuántos días antes de pagar se cuentan las interacciones de un comprador */
    public const DIAS_ANTES_DE_COMPRAR = 90;

    /**
     * Cuánto tuvo que interactuar cada tipo de usuario para comprar (y cuántas interacciones tuvo en el período).
     * "mostrado" no cuenta: es que el sistema le mostró algo, no que el usuario hizo algo. Cuánta ganancia deja cada
     * recomendación está en el embudo (conGanancia), atribuida por el origen de cada inspección.
     * @param list<array<string, mixed>> $plata
     */
    public static function valor(int $dias, array $plata): array
    {
        $pdo = Database::pdo();
        $desde = "now() - make_interval(days => $dias)"; // entero validado
        $tipos = "'" . implode("', '", self::INTERACCIONES) . "'";
        $actividad = $pdo->query(
            "SELECT COALESCE(p.uso, '" . self::SIN_PERFIL . "') AS uso,
                    COUNT(DISTINCT e.usuario_id) AS activos, COUNT(*) AS interacciones
               FROM evento e LEFT JOIN usuario_preferencia p ON p.usuario_id = e.usuario_id
              WHERE e.tipo IN ($tipos) AND e.created_at >= $desde
              GROUP BY 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        // Cada comprador del período, en su primer pago: cuántas interacciones tuvo en los 90 días anteriores y
        // cuántos días pasaron desde la primera de esas
        $antes = self::DIAS_ANTES_DE_COMPRAR;
        $compradores = $pdo->query(
            "WITH primera AS (SELECT comprador_id AS usuario_id, MIN(created_at) AS pago
                                FROM inspeccion WHERE created_at >= $desde GROUP BY 1)
             SELECT COALESCE(p.uso, '" . self::SIN_PERFIL . "') AS uso,
                    COUNT(e.created_at) AS interacciones,
                    EXTRACT(EPOCH FROM pr.pago - MIN(e.created_at)) / 86400 AS dias
               FROM primera pr
               LEFT JOIN evento e ON e.usuario_id = pr.usuario_id AND e.tipo IN ($tipos)
                    AND e.created_at <= pr.pago AND e.created_at > pr.pago - make_interval(days => $antes)
               LEFT JOIN usuario_preferencia p ON p.usuario_id = pr.usuario_id
              GROUP BY pr.usuario_id, pr.pago, p.uso"
        )->fetchAll(PDO::FETCH_ASSOC);
        return self::filasValor($plata, $actividad, $compradores);
    }

    /**
     * Función pura. Por tipo de usuario y en total:
     *   interacciones_hasta_comprar y dias_hasta_comprar = medianas de los compradores que interactuaron antes de pagar
     *   (los que pagaron sin pasar por el metabuscador no tienen interacciones registradas: se cuentan aparte).
     * @param list<array<string, mixed>> $plata filas de filasPlata (uso, ingresos)
     * @param list<array<string, mixed>> $actividad {uso, activos, interacciones}
     * @param list<array<string, mixed>> $compradores uno por comprador: {uso, interacciones, dias}
     */
    public static function filasValor(array $plata, array $actividad, array $compradores): array
    {
        $ingresos = array_column($plata, 'ingresos', 'uso');
        $act = array_column($actividad, null, 'uso');
        $porUso = [];
        foreach ($compradores as $c) {
            $porUso[$c['uso']][] = $c;
        }
        $fila = static function (float $ingresos, int $activos, int $interacciones, array $compradores): array {
            $con = array_values(array_filter($compradores, static fn(array $c): bool => (int)$c['interacciones'] > 0));
            $medInter = Estadistica::mediana(array_map(static fn(array $c): int => (int)$c['interacciones'], $con));
            $medDias = Estadistica::mediana(array_map(static fn(array $c): float => (float)$c['dias'], $con));
            return [
                'ingresos'                    => $ingresos,
                'activos'                     => $activos,
                'interacciones'               => $interacciones,
                'compradores'                 => count($compradores),
                'compradores_sin_interacciones' => count($compradores) - count($con),
                'interacciones_hasta_comprar' => $medInter !== null ? round($medInter, 1) : null,
                'dias_hasta_comprar'          => $medDias !== null ? round($medDias, 1) : null,
            ];
        };

        $salida = [];
        $total = ['ingresos' => 0.0, 'activos' => 0, 'interacciones' => 0, 'compradores' => []];
        foreach (self::usos() as $uso => $nombre) {
            $i = (float)($ingresos[$uso] ?? 0);
            $a = (int)($act[$uso]['activos'] ?? 0);
            $n = (int)($act[$uso]['interacciones'] ?? 0);
            $c = $porUso[$uso] ?? [];
            $salida[] = ['uso' => $uso, 'nombre' => $nombre] + $fila($i, $a, $n, $c);
            $total['ingresos'] += $i;
            $total['activos'] += $a;
            $total['interacciones'] += $n;
            $total['compradores'] = array_merge($total['compradores'], $c);
        }
        return [
            'total'   => $fila($total['ingresos'], $total['activos'], $total['interacciones'], $total['compradores']),
            'por_uso' => $salida,
        ];
    }

    /**
     * La plata del período por tipo de usuario, con las mismas cuentas que el informe de plata (Panel::totales):
     * ingresos = comisión de AutoCar de las firmadas ya cobradas (o reembolsadas por un reclamo antes del 23/11), menos
     * los créditos. Sin reclamos no hay pérdida.
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
        return self::filasPlata($inspecciones);
    }

    /**
     * Arma una fila por tipo de usuario (también los que no tienen datos). Función pura.
     * @param list<array<string, mixed>> $inspecciones filas {uso, pagaron, compradores, ingresos, por_cobrar}
     * @return list<array<string, mixed>>
     */
    public static function filasPlata(array $inspecciones): array
    {
        $porUso = array_column($inspecciones, null, 'uso');
        $salida = [];
        foreach (self::usos() as $uso => $nombre) {
            $f = $porUso[$uso] ?? [];
            $salida[] = [
                'uso'                  => $uso,
                'nombre'               => $nombre,
                'pagaron'              => (int)($f['pagaron'] ?? 0),
                'compradores'          => (int)($f['compradores'] ?? 0),
                'ingresos'             => (float)($f['ingresos'] ?? 0),
                'por_cobrar'           => (float)($f['por_cobrar'] ?? 0),
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
                    COUNT(DISTINCT i.comprador_id) FILTER (WHERE i.estado = 'firmada') AS firmadas,
                    COALESCE(SUM(i.monto_autocar - i.credito_usado) FILTER (WHERE i.estado = 'firmada'
                             AND i.pago IN ('liberado', 'reembolsado')), 0) AS ganancia
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
            $cuenta[$p['uso']][$p['origen']]['ganancia'] = (float)($p['ganancia'] ?? 0);
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
                $origenes[] = self::conGanancia([
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
                    'ganancia'   => $c['ganancia'] ?? 0.0,
                ]);
            }
            $salida[] = ['uso' => $uso, 'nombre' => $nombre, 'origenes' => $origenes, 'mejor' => self::mejorOrigen($origenes)];
        }
        return $salida;
    }

    /**
     * Agrega a una fila del embudo cuánta ganancia dejó cada paso. "ganancia" es la comisión de AutoCar (menos créditos)
     * de las inspecciones pedidas en el período desde esa recomendación que ya se firmaron y se cobraron, igual que el
     * informe de plata. Función pura:
     *   por_vio = ganancia ÷ vieron; por_toco = ganancia ÷ tocaron "Mandale un mecánico"; por_pago = ganancia ÷ pagaron.
     * @param array<string, mixed> $fila con vieron, tocaron, pagaron y ganancia
     */
    public static function conGanancia(array $fila): array
    {
        $ganancia = (float)($fila['ganancia'] ?? 0);
        $por = static fn(int $cuantos): ?float => $cuantos > 0 ? round($ganancia / $cuantos) : null;
        return ['ganancia' => $ganancia] + $fila + [
            'por_vio'  => $por((int)($fila['vieron'] ?? 0)),
            'por_toco' => $por((int)($fila['tocaron'] ?? 0)),
            'por_pago' => $por((int)($fila['pagaron'] ?? 0)),
        ];
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
