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

    public const DIAS_TURNO_MAX = 60;  // hasta cuándo se puede dar el turno
    public const MOTIVO_MIN     = 3;
    public const MOTIVO_MAX     = 300; // igual que inspeccion.motivo_rechazo

    /**
     * Fecha del turno al aceptar: fecha y hora ISO 8601 ("2026-11-12T13:00:00.000Z" o con -03:00),
     * desde ahora (con 5 minutos de margen) y hasta DIAS_TURNO_MAX días.
     * @return array{0: ?DateTimeImmutable, 1: ?string} [fecha, error]
     */
    public static function validarTurno(mixed $valor, DateTimeImmutable $ahora): array
    {
        if (!is_string($valor) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $valor)) {
            return [null, 'Elegí el día y la hora del turno.'];
        }
        try {
            $fecha = new DateTimeImmutable($valor);
        } catch (\Exception) {
            return [null, 'La fecha del turno no es válida.'];
        }
        if ($fecha < $ahora->modify('-5 minutes')) {
            return [null, 'El turno no puede ser en el pasado.'];
        }
        if ($fecha > $ahora->modify('+' . self::DIAS_TURNO_MAX . ' days')) {
            return [null, 'El turno tiene que ser dentro de los próximos ' . self::DIAS_TURNO_MAX . ' días.'];
        }
        return [$fecha, null];
    }

    /** @return array{0: string, 1: ?string} [motivo limpio, error] */
    public static function validarMotivo(mixed $valor): array
    {
        $motivo = is_string($valor) ? trim($valor) : '';
        $largo = mb_strlen($motivo);
        if ($largo < self::MOTIVO_MIN || $largo > self::MOTIVO_MAX) {
            return [$motivo, 'Contale al comprador por qué la rechazás (entre ' . self::MOTIVO_MIN . ' y ' . self::MOTIVO_MAX . ' caracteres).'];
        }
        return [$motivo, null];
    }

    public static function enPlazoDeReclamo(DateTimeImmutable $firmadaAt, DateTimeImmutable $ahora): bool
    {
        return $ahora >= $firmadaAt && $ahora <= $firmadaAt->modify('+' . self::DIAS_RECLAMO . ' days');
    }
}
