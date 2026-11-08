<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

/**
 * Cuánto se lleva cada uno de una inspección. El precio lo pone el taller.
 * AutoCar se queda el 23 % (redondeado a miles) y un tercio de eso va al fondo de garantía,
 * que es de donde salen las devoluciones y compensaciones. El resto es del mecánico.
 * Con $ 130.000: $ 100.000 para el mecánico y $ 30.000 para AutoCar ($ 10.000 al fondo).
 */
final class Precio
{
    public const COMISION    = 0.23;
    public const PARTE_FONDO = 1 / 3;
    public const REDONDEO    = 1000;
    public const TOPE_VECES  = 3; // la compensación de un reclamo llega hasta 3 veces el precio

    /** @return array{precio: float, monto_mecanico: float, monto_autocar: float, monto_fondo: float} */
    public static function desglose(float $precio): array
    {
        $autocar = min($precio, self::redondear($precio * self::COMISION));
        $fondo = min($autocar, self::redondear($autocar * self::PARTE_FONDO));
        return [
            'precio'         => $precio,
            'monto_mecanico' => $precio - $autocar,
            'monto_autocar'  => $autocar,
            'monto_fondo'    => $fondo,
        ];
    }

    public static function topeCompensacion(float $precio): float
    {
        return $precio * self::TOPE_VECES;
    }

    private static function redondear(float $monto): float
    {
        return round($monto / self::REDONDEO) * self::REDONDEO;
    }
}
