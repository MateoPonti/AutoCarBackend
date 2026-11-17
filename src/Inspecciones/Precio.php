<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Parametros\Parametros;

/**
 * Cuánto se lleva cada uno de una inspección. El precio lo pone el taller.
 * AutoCar se queda la comisión (redondeada a miles) y una parte de eso va al fondo de garantía,
 * que es de donde salen las devoluciones y compensaciones. El resto es del mecánico.
 * Los porcentajes y el tope son parámetros que cambia el admin (tabla parametro): acá no hay números fijos.
 * Con $ 100.000, 23 % y un tercio al fondo: $ 77.000 para el mecánico y $ 23.000 para AutoCar ($ 8.000 al fondo).
 */
final class Precio
{
    public const REDONDEO = 1000;

    /**
     * Función pura (se prueba sin base). $comision y $parteFondo en porcentaje (23 = 23 %).
     * @return array{precio: float, monto_mecanico: float, monto_autocar: float, monto_fondo: float}
     */
    public static function desglose(float $precio, float $comision, float $parteFondo): array
    {
        $autocar = min($precio, self::redondear($precio * $comision / 100));
        $fondo = min($autocar, self::redondear($autocar * $parteFondo / 100));
        return [
            'precio'         => $precio,
            'monto_mecanico' => $precio - $autocar,
            'monto_autocar'  => $autocar,
            'monto_fondo'    => $fondo,
        ];
    }

    /** El desglose con los parámetros de hoy. */
    public static function desgloseActual(float $precio): array
    {
        return self::desglose($precio, Parametros::valor('comision_autocar'), Parametros::valor('parte_fondo'));
    }

    /** La compensación de un reclamo llega hasta $veces el precio. */
    public static function topeCompensacion(float $precio, float $veces): float
    {
        return $precio * $veces;
    }

    /**
     * Franquicia del mecánico si un reclamo procede: un porcentaje de lo que cobró (parámetro franquicia).
     * Con $ 77.000 y 40 %: $ 30.800. Se redondea al peso.
     */
    public static function franquicia(float $montoMecanico, float $porcentaje): float
    {
        return round($montoMecanico * $porcentaje / 100);
    }

    /**
     * Crédito para el comprador si el auto se vendió: un porcentaje del precio (parámetro credito_vendido), al peso.
     * Con $ 100.000 y 30 %: $ 30.000.
     */
    public static function credito(float $precio, float $porcentaje): float
    {
        return round($precio * $porcentaje / 100);
    }

    private static function redondear(float $monto): float
    {
        return round($monto / self::REDONDEO) * self::REDONDEO;
    }
}
