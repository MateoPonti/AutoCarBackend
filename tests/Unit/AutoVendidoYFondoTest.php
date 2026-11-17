<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Inspecciones\AutoVendido;
use AutoCar\Inspecciones\Estados;
use AutoCar\Inspecciones\Fondo;
use AutoCar\Inspecciones\Precio;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Auto vendido (turno que se cae, crédito y cupón) y fondo de garantía: las reglas que no necesitan base. */
final class AutoVendidoYFondoTest extends TestCase
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

    public function testFranquiciaYCredito(): void
    {
        self::assertSame(30800.0, Precio::franquicia(77000, 40));
        self::assertSame(21560.0, Precio::franquicia(53900, 40));
        self::assertSame(30000.0, Precio::credito(100000, 30));
        self::assertSame(0.0, Precio::credito(100000, 0));
    }

    public function testElSupuestoDelFondo(): void
    {
        // $ 100.000, 23 %, un tercio al fondo, tope 3× y franquicia 40 %: entran $ 8.000 y un reclamo cuesta
        // $ 100.000 + $ 300.000 − $ 30.800 = $ 369.200 → cierra con menos de 1 reclamo cada ~46 inspecciones
        $s = Fondo::supuesto(100000, 23, 33.3333, 3, 40);
        self::assertSame(8000.0, $s['aporte_por_inspeccion']);
        self::assertSame(369200.0, $s['costo_reclamo']);
        self::assertSame(46.2, $s['inspecciones_por_reclamo']);
        self::assertNull(Fondo::supuesto(100000, 23, 0, 3, 40)['inspecciones_por_reclamo']);
    }
}
