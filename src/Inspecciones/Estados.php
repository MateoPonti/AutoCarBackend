<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use DateTimeImmutable;

/**
 * Ciclo de vida de una inspección y del pago simulado.
 *   pedida → aceptada → firmada
 *   pedida → rechazada (el mecánico) o cancelada (el comprador): se le devuelve todo al comprador.
 * Firmada no cambia más. Después de firmar, el comprador tiene 15 días para reclamar (una sola vez).
 */
final class Estados
{
    public const ESTADOS = ['pedida', 'aceptada', 'rechazada', 'cancelada', 'firmada'];
    public const PAGOS   = ['retenido', 'liberado', 'reembolsado'];
    public const DIAS_RECLAMO = 15;

    private const TRANSICIONES = [
        'pedida'   => ['aceptada', 'rechazada', 'cancelada'],
        'aceptada' => ['firmada'],
    ];

    // estado nuevo => cómo queda el pago
    private const PAGO_SEGUN_ESTADO = [
        'rechazada' => 'reembolsado',
        'cancelada' => 'reembolsado',
        'firmada'   => 'liberado',
    ];

    public static function puedePasar(string $desde, string $hacia): bool
    {
        return in_array($hacia, self::TRANSICIONES[$desde] ?? [], true);
    }

    /** Cómo queda el pago al pasar al estado nuevo (null: no cambia). */
    public static function pagoAl(string $estado): ?string
    {
        return self::PAGO_SEGUN_ESTADO[$estado] ?? null;
    }

    public static function enPlazoDeReclamo(DateTimeImmutable $firmadaAt, DateTimeImmutable $ahora): bool
    {
        return $ahora >= $firmadaAt && $ahora <= $firmadaAt->modify('+' . self::DIAS_RECLAMO . ' days');
    }
}
