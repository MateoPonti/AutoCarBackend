<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Inspecciones\AutoVendido;
use AutoCar\Inspecciones\Estados;
use AutoCar\Inspecciones\Precio;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Auto vendido (turno que se cae, crédito y cupón): las reglas que no necesitan base. */
final class AutoVendidoTest extends TestCase
{
    public function testElTurnoSeCaeSiTodaviaNoPaso(): void
    {
        $ahora = new DateTimeImmutable('2026-11-18 10:00:00');
        self::assertTrue(AutoVendido::seCae('pedida', null, $ahora));
        self::assertTrue(AutoVendido::seCae('aceptada', new DateTimeImmutable('2026-11-19 10:00:00'), $ahora));
        // El mecánico ya fue: se firma igual
        self::assertFalse(AutoVendido::seCae('aceptada', new DateTimeImmutable('2026-11-18 09:00:00'), $ahora));
        self::assertFalse(AutoVendido::seCae('firmada', null, $ahora));
        self::assertFalse(AutoVendido::seCae('cancelada', null, $ahora));
    }

    public function testElCuponPideTurnoConfirmadoYVentaDentroDeLasHoras(): void
    {
        $confirmado = new DateTimeImmutable('2026-11-16 12:00:00');
        $firma = new DateTimeImmutable('2026-11-17 10:00:00');
        self::assertTrue(AutoVendido::correspondeCupon($confirmado, $firma, new DateTimeImmutable('2026-11-18 10:00:00'), 48));
        self::assertTrue(AutoVendido::correspondeCupon($confirmado, $firma, new DateTimeImmutable('2026-11-19 10:00:00'), 48));
        self::assertFalse(AutoVendido::correspondeCupon($confirmado, $firma, new DateTimeImmutable('2026-11-19 10:01:00'), 48));
        // Sin turno confirmado no hay cupón: comprador y vendedor se podrían arreglar
        self::assertFalse(AutoVendido::correspondeCupon(null, $firma, new DateTimeImmutable('2026-11-18 10:00:00'), 48));
        // Vendido antes de la firma no es cupón (eso es el crédito al firmar)
        self::assertFalse(AutoVendido::correspondeCupon($confirmado, $firma, new DateTimeImmutable('2026-11-17 09:00:00'), 48));
    }

    public function testCaidaDevuelveTodo(): void
    {
        self::assertTrue(Estados::puedePasar('pedida', 'caida'));
        self::assertTrue(Estados::puedePasar('aceptada', 'caida'));
        self::assertFalse(Estados::puedePasar('firmada', 'caida'));
        self::assertSame('reembolsado', Estados::pagoAl('caida'));
    }

    public function testCredito(): void
    {
        self::assertSame(30000.0, Precio::credito(100000, 30));
        self::assertSame(0.0, Precio::credito(100000, 0));
    }
}
