<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Parametros\Parametros;

/**
 * Cuánto se lleva cada uno de una inspección. El precio lo pone el taller.
 * AutoCar se queda la comisión (redondeada a miles) y el resto es del mecánico. No hay reclamos ni fondo de garantía
 * (se sacaron el 23/11: el informe reduce el riesgo, no lo elimina, y la decisión de comprar es del comprador).
 * La comisión es un parámetro que cambia el admin (tabla parametro): acá no hay números fijos.
 * Con $ 100.000 y 23 %: $ 77.000 para el mecánico y $ 23.000 para AutoCar.
 */
final class Precio
{
    public const REDONDEO = 1000;

    /**
     * Función pura (se prueba sin base). $comision en porcentaje (23 = 23 %).
     * @return array{precio: float, monto_mecanico: float, monto_autocar: float}
     */
    public static function desglose(float $precio, float $comision): array
    {
        $autocar = min($precio, self::redondear($precio * $comision / 100));
        return [
            'precio'         => $precio,
            'monto_mecanico' => $precio - $autocar,
            'monto_autocar'  => $autocar,
        ];
    }

    /** El desglose con los parámetros de hoy. */
    public static function desgloseActual(float $precio): array
    {
        return self::desglose($precio, Parametros::valor('comision_autocar'));
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
