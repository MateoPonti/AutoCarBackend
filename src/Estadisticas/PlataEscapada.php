<?php
declare(strict_types=1);

namespace AutoCar\Estadisticas;

use AutoCar\Core\Database;
use PDO;

/**
 * "Plata que se nos escapó" en el período, por fuente: lo que ganamos, lo que pudimos ganar y la diferencia (como el
 * valor de los carritos abandonados).
 * - Inspecciones: ganado = lo que dejaron las inspecciones firmadas que vinieron de una recomendación. Se escapa:
 *   · sin_pagar: tocaron "Mandale un mecánico" y no pagaron × lo que deja una inspección.
 *   · sin_tocar: vieron un auto recomendado y no tocaron "Mandale un mecánico" × qué parte de los que tocan termina
 *     pagando × lo que deja una inspección (si se hubieran portado como los que sí tocaron).
 * - Clics: ganado = clics válidos a "Ver en…" × la tarifa de cada plataforma. Se escapa (sin_clic): autos de otras
 *   plataformas que alguien vio y no abrió × la tarifa de esa plataforma. "Vio" = la tarjeta apareció en su pantalla
 *   (el evento "mostrado" lo manda el front recién ahí), una vez por persona y por auto.
 * Es un techo, no una promesa: no todos los que se fueron iban a comprar.
 */
final class PlataEscapada
{
    /** Los orígenes que muestran autos de otras plataformas (con "Ver en…"). */
    private const CON_AVISOS = ['para_vos', 'destacado', 'metabuscador'];

    /** $embudo es Panel::embudo($dias)['total'] (vieron, tocaron, pagaron, ganancia). $dias ya viene validado. */
    public static function del(int $dias, array $embudo): array
    {
        $pdo = Database::pdo();
        $desde = "now() - make_interval(days => $dias)"; // entero validado
        $origenes = "'" . implode("', '", self::CON_AVISOS) . "'";
        // Por plataforma con convenio activo: cuántas personas vieron cada auto (una vez por persona y auto) y los
        // clics válidos. La plataforma de un auto visto sale de su link (mercadolibre, kavak, autocosmos).
        $avisos = $pdo->query(
            "SELECT c.fuente, c.tarifa_clic,
                    (SELECT COUNT(DISTINCT (e.usuario_id, e.ref)) FROM evento e
                      WHERE e.tipo = 'mostrado' AND e.origen IN ($origenes) AND e.created_at >= $desde
                        AND e.ref LIKE '%' || c.fuente || '%') AS mostrados,
                    (SELECT COUNT(*) FROM clic_salida s WHERE s.valido AND s.fuente = c.fuente AND s.created_at >= $desde) AS clics
               FROM convenio_plataforma c WHERE c.activo ORDER BY c.fuente"
        )->fetchAll(PDO::FETCH_ASSOC);
        $porInspeccion = $pdo->query("SELECT AVG(monto_autocar) FROM inspeccion WHERE pago = 'liberado'")->fetchColumn();
        $plataformas = [];
        foreach ($avisos as $a) {
            $plataformas[$a['fuente']] = ['mostrados' => (int)$a['mostrados'], 'clics' => (int)$a['clics'], 'tarifa' => (float)$a['tarifa_clic']];
        }
        return self::calcular($embudo, $plataformas, $porInspeccion !== null && $porInspeccion !== false ? round((float)$porInspeccion) : null);
    }

    /**
     * Las cuentas, sin base (se prueba sola). Un monto es null cuando falta con qué calcularlo (sin inspecciones
     * cobradas o, en sin_tocar, sin nadie que haya tocado todavía).
     * @param array{vieron: int, tocaron: int, pagaron: int, ganancia?: float|int} $embudo
     * @param array<string, array{mostrados: int, clics: int, tarifa: float}> $plataformas las de convenio activo
     */
    public static function calcular(array $embudo, array $plataformas, ?float $porInspeccion): array
    {
        $sinPagar = max(0, $embudo['tocaron'] - $embudo['pagaron']);
        $sinTocar = max(0, $embudo['vieron'] - $embudo['tocaron']);
        // De los que tocaron, qué parte pagó (como mucho todos: un pago puede venir de otro origen)
        $conversion = $embudo['tocaron'] > 0 ? min(1.0, $embudo['pagaron'] / $embudo['tocaron']) : null;
        $montoSinPagar = $porInspeccion !== null ? $sinPagar * $porInspeccion : null;
        $montoSinTocar = $porInspeccion !== null && $conversion !== null ? round($sinTocar * $conversion * $porInspeccion) : null;

        $porFuente = [];
        foreach ($plataformas as $fuente => $p) {
            $sinClic = max(0, $p['mostrados'] - $p['clics']);
            $porFuente[$fuente] = ['vistos' => $p['mostrados'], 'clics' => $p['clics'], 'sin_clic' => $sinClic, 'tarifa' => $p['tarifa'],
                'ganado' => $p['clics'] * $p['tarifa'], 'escapado' => $sinClic * $p['tarifa']];
        }

        $inspecciones = (float)($embudo['ganancia'] ?? 0);
        $escapadoInspecciones = (float)(($montoSinPagar ?? 0) + ($montoSinTocar ?? 0));
        $clics = (float)array_sum(array_column($porFuente, 'ganado'));
        $escapadoClics = (float)array_sum(array_column($porFuente, 'escapado'));
        $resumen = static fn(float $ganado, float $escapado): array =>
            ['ganado' => $ganado, 'posible' => $ganado + $escapado, 'escapado' => $escapado];

        return [
            'inspecciones'   => $resumen($inspecciones, $escapadoInspecciones),
            'clics'          => $resumen($clics, $escapadoClics),
            'sin_pagar'      => ['cantidad' => $sinPagar, 'monto' => $montoSinPagar],
            'sin_tocar'      => ['cantidad' => $sinTocar, 'conversion' => $conversion, 'monto' => $montoSinTocar],
            'sin_clic'       => ['cantidad' => (int)array_sum(array_column($porFuente, 'sin_clic')), 'monto' => $escapadoClics, 'por_fuente' => $porFuente],
            'por_inspeccion' => $porInspeccion,
            'total'          => $escapadoInspecciones + $escapadoClics,
        ];
    }
}
