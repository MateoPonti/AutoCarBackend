<?php
declare(strict_types=1);

namespace AutoCar\Estadisticas;

use AutoCar\Core\Database;
use PDO;

/**
 * "Plata que se nos escapó" en el período: lo que se dejó de ganar en cada paso donde la gente se fue, en pesos
 * (como el valor de los carritos abandonados). Son tres pasos:
 * - sin_pagar: tocaron "Mandale un mecánico" y no pagaron la inspección × lo que deja una inspección.
 * - sin_tocar: vieron una recomendación y no tocaron "Mandale un mecánico" × cuántos de los que tocan terminan
 *   pagando × lo que deja una inspección (si se hubieran portado como los que sí tocaron).
 * - sin_clic: avisos de otras plataformas que se mostraron y nadie tocó "Ver en…" × la tarifa por clic promedio.
 * Es un techo, no una promesa: no todos los que se fueron iban a comprar.
 */
final class PlataEscapada
{
    /** Los orígenes que muestran avisos de otras plataformas (con "Ver en…"). */
    private const CON_AVISOS = ['para_vos', 'destacado', 'metabuscador'];

    /** $embudo es Panel::embudo($dias)['total'] (vieron, tocaron, pagaron). $dias ya viene validado. */
    public static function del(int $dias, array $embudo): array
    {
        $pdo = Database::pdo();
        $desde = "now() - make_interval(days => $dias)"; // entero validado
        $origenes = "'" . implode("', '", self::CON_AVISOS) . "'";
        $t = $pdo->query(
            "SELECT (SELECT COUNT(DISTINCT (usuario_id, ref)) FROM evento
                      WHERE tipo = 'mostrado' AND origen IN ($origenes) AND ref IS NOT NULL AND created_at >= $desde) AS mostrados,
                    (SELECT COUNT(*) FROM clic_salida WHERE valido AND created_at >= $desde) AS clics,
                    (SELECT AVG(tarifa_clic) FROM convenio_plataforma WHERE activo) AS tarifa,
                    (SELECT AVG(monto_autocar) FROM inspeccion WHERE pago = 'liberado') AS por_inspeccion"
        )->fetch(PDO::FETCH_ASSOC);
        return self::calcular(
            $embudo,
            ['mostrados' => (int)$t['mostrados'], 'clics' => (int)$t['clics']],
            $t['por_inspeccion'] !== null ? round((float)$t['por_inspeccion']) : null,
            $t['tarifa'] !== null ? (float)$t['tarifa'] : null,
        );
    }

    /**
     * Las cuentas, sin base (se prueba sola). Un monto es null cuando falta con qué calcularlo (sin inspecciones
     * cobradas, sin convenios activos o, en sin_tocar, sin nadie que haya tocado todavía).
     * @param array{vieron: int, tocaron: int, pagaron: int} $embudo
     * @param array{mostrados: int, clics: int} $avisos
     */
    public static function calcular(array $embudo, array $avisos, ?float $porInspeccion, ?float $tarifa): array
    {
        $sinPagar = max(0, $embudo['tocaron'] - $embudo['pagaron']);
        $sinTocar = max(0, $embudo['vieron'] - $embudo['tocaron']);
        $sinClic = max(0, $avisos['mostrados'] - $avisos['clics']);
        // De los que tocaron, qué parte pagó (como mucho todos: un pago puede venir de otro origen)
        $conversion = $embudo['tocaron'] > 0 ? min(1.0, $embudo['pagaron'] / $embudo['tocaron']) : null;

        $pasos = [
            'sin_pagar' => ['cantidad' => $sinPagar, 'monto' => $porInspeccion !== null ? $sinPagar * $porInspeccion : null],
            'sin_tocar' => ['cantidad' => $sinTocar, 'conversion' => $conversion,
                'monto' => $porInspeccion !== null && $conversion !== null ? round($sinTocar * $conversion * $porInspeccion) : null],
            'sin_clic'  => ['cantidad' => $sinClic, 'monto' => $tarifa !== null ? round($sinClic * $tarifa) : null],
        ];
        $montos = array_filter(array_column($pasos, 'monto'), static fn(?float $m): bool => $m !== null);
        return $pasos + [
            'por_inspeccion' => $porInspeccion,
            'tarifa'         => $tarifa,
            'total'          => (float)array_sum($montos),
        ];
    }
}
