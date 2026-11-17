<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Core\Database;
use AutoCar\Parametros\Parametros;
use DateTimeImmutable;

/**
 * Fondo de garantía: SOLO paga reclamos (lo pidió el profe el 13/11).
 *   Entra: la parte de cada inspección firmada que va al fondo (monto_fondo) y la franquicia que paga el mecánico
 *   cuando un reclamo procede.
 *   Sale: lo que se le devuelve al comprador (devolucion) y la compensación de cada reclamo que procede.
 * El saldo se calcula con las inspecciones y los reclamos (no hay una tabla aparte que se pueda desincronizar).
 * El pago es simulado hasta que haya Mercado Pago.
 */
final class Fondo
{
    /**
     * El supuesto de la cuenta, con los parámetros de hoy y el precio sugerido. Función pura.
     * Un reclamo cuesta la devolución + la compensación al tope − la franquicia del mecánico.
     * @return array{aporte_por_inspeccion: float, costo_reclamo: float, inspecciones_por_reclamo: ?float}
     */
    public static function supuesto(float $precio, float $comision, float $parteFondo, float $tope, float $franquicia): array
    {
        $d = Precio::desglose($precio, $comision, $parteFondo);
        $costo = $precio + Precio::topeCompensacion($precio, $tope) - Precio::franquicia($d['monto_mecanico'], $franquicia);
        return [
            'aporte_por_inspeccion'    => $d['monto_fondo'],
            'costo_reclamo'            => $costo,
            // El fondo cierra si hay menos de 1 reclamo que procede cada tantas inspecciones
            'inspecciones_por_reclamo' => $d['monto_fondo'] > 0 ? round($costo / $d['monto_fondo'], 1) : null,
        ];
    }

    /** Saldo, totales, el supuesto y los últimos movimientos (el más nuevo primero). */
    public static function resumen(int $movimientos = 50): array
    {
        $pdo = Database::pdo();
        $t = $pdo->query(
            "SELECT (SELECT COALESCE(SUM(monto_fondo), 0) FROM inspeccion WHERE estado = 'firmada')               AS aportes,
                    (SELECT COUNT(*) FROM inspeccion WHERE estado = 'firmada')                                    AS firmadas,
                    (SELECT COALESCE(SUM(devolucion), 0) FROM reclamo WHERE estado = 'procede')                   AS devoluciones,
                    (SELECT COALESCE(SUM(compensacion), 0) FROM reclamo WHERE estado = 'procede')                 AS compensaciones,
                    (SELECT COALESCE(SUM(franquicia), 0) FROM reclamo WHERE estado = 'procede')                   AS franquicias,
                    (SELECT COUNT(*) FROM reclamo WHERE estado = 'procede')                                       AS reclamos"
        )->fetch();
        $saldo = (float)$t['aportes'] + (float)$t['franquicias'] - (float)$t['devoluciones'] - (float)$t['compensaciones'];

        $st = $pdo->prepare(
            "SELECT * FROM (
               SELECT 'aporte' AS tipo, i.firmada_at AS fecha, i.monto_fondo AS monto, i.id AS inspeccion_id, i.titulo
                 FROM inspeccion i WHERE i.estado = 'firmada' AND i.monto_fondo > 0
               UNION ALL
               SELECT 'devolucion', r.resuelto_at, -r.devolucion, r.inspeccion_id, i.titulo
                 FROM reclamo r JOIN inspeccion i ON i.id = r.inspeccion_id WHERE r.estado = 'procede' AND r.devolucion > 0
               UNION ALL
               SELECT 'compensacion', r.resuelto_at, -r.compensacion, r.inspeccion_id, i.titulo
                 FROM reclamo r JOIN inspeccion i ON i.id = r.inspeccion_id WHERE r.estado = 'procede' AND r.compensacion > 0
               UNION ALL
               SELECT 'franquicia', r.resuelto_at, r.franquicia, r.inspeccion_id, i.titulo
                 FROM reclamo r JOIN inspeccion i ON i.id = r.inspeccion_id WHERE r.estado = 'procede' AND r.franquicia > 0
             ) m ORDER BY fecha DESC, inspeccion_id DESC LIMIT :n"
        );
        $st->bindValue(':n', $movimientos, \PDO::PARAM_INT);
        $st->execute();

        return [
            'simulado'       => true,
            'saldo'          => $saldo,
            'aportes'        => (float)$t['aportes'],
            'franquicias'    => (float)$t['franquicias'],
            'devoluciones'   => (float)$t['devoluciones'],
            'compensaciones' => (float)$t['compensaciones'],
            'firmadas'       => (int)$t['firmadas'],
            'reclamos'       => (int)$t['reclamos'],
            'supuesto'       => self::supuesto(Parametros::valor('precio_sugerido'), Parametros::valor('comision_autocar'),
                Parametros::valor('parte_fondo'), Parametros::valor('tope_veces'), Parametros::valor('franquicia')),
            'movimientos'    => array_map(static fn(array $m): array => [
                'tipo'          => $m['tipo'],
                'fecha'         => $m['fecha'] === null ? null : (new DateTimeImmutable($m['fecha']))->format(DATE_ATOM),
                'monto'         => (float)$m['monto'],
                'inspeccion_id' => (int)$m['inspeccion_id'],
                'titulo'        => $m['titulo'],
            ], $st->fetchAll()),
        ];
    }
}
