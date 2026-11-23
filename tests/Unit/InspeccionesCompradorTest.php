<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Inspecciones\Pedido;
use PHPUnit\Framework\TestCase;

/** Reglas del pedido (B4) que no necesitan base. */
final class InspeccionesCompradorTest extends TestCase
{
    private const FRONT = ['autocar-zeta.vercel.app'];
    private const ML = 'https://auto.mercadolibre.com.ar/MLA-123-gol-_JM';

    private static function pedido(array $in): array
    {
        return Pedido::validar($in, self::FRONT, 2026);
    }

    // --- Pedido

    public function testPedidoMinimoConLinkYMecanico(): void
    {
        [$datos, $errores] = self::pedido(['url' => self::ML, 'mecanico_id' => 7]);
        self::assertSame([], $errores);
        self::assertSame('mercadolibre', $datos['fuente']);
        self::assertNull($datos['vehiculo_id']);
        self::assertSame(7, $datos['mecanico_id']);
        self::assertNull($datos['anio']);
    }

    public function testUnLinkDeAutoCarTraeElIdDelVehiculo(): void
    {
        [$datos] = self::pedido(['url' => 'https://autocar-zeta.vercel.app/front/pages/vehiculos/vehiculo.html?id=12', 'mecanico_id' => 7]);
        self::assertSame('autocar', $datos['fuente']);
        self::assertSame(12, $datos['vehiculo_id']);
    }

    public function testSinLinkValidoNiMecanico(): void
    {
        [, $errores] = self::pedido(['url' => 'mercadolibre gol', 'mecanico_id' => 'cualquiera']);
        self::assertArrayHasKey('url', $errores);
        self::assertArrayHasKey('mecanico_id', $errores);
        self::assertArrayHasKey('url', self::pedido(['url' => 'javascript:alert(1)', 'mecanico_id' => 1])[1]);
    }

    public function testSoloAceptaLinksDeSitiosConocidos(): void
    {
        [, $errores] = self::pedido(['url' => 'https://www.otrositio.com/auto/1', 'mecanico_id' => 7]);
        self::assertSame(Pedido::SOLO_SITIOS_CONOCIDOS, $errores['url']);
        [, $errores] = self::pedido(['url' => 'https://mercadolibre.com.ar.estafa.com/MLA-1', 'mecanico_id' => 7]);
        self::assertArrayHasKey('url', $errores);
        // Un link de AutoCar que no es de un vehículo tampoco
        [, $errores] = self::pedido(['url' => 'https://autocar-zeta.vercel.app/front/catalogo.html?id=7', 'mecanico_id' => 7]);
        self::assertArrayHasKey('url', $errores);
    }

    public function testDatosOpcionalesDelAuto(): void
    {
        [$datos, $errores] = self::pedido(['url' => self::ML, 'mecanico_id' => 7, 'titulo' => ' VW Gol ',
            'anio' => '2018', 'kilometraje' => 98000, 'precio_publicado' => '15500000', 'localidad' => '']);
        self::assertSame([], $errores);
        self::assertSame('VW Gol', $datos['titulo']);
        self::assertSame(2018, $datos['anio']);
        self::assertSame(98000, $datos['kilometraje']);
        self::assertSame(15500000.0, $datos['precio_publicado']);
        self::assertNull($datos['localidad']);
    }

    public function testDatosDelAutoFueraDeRango(): void
    {
        [, $errores] = self::pedido(['url' => self::ML, 'mecanico_id' => 7, 'anio' => 2030, 'kilometraje' => -5,
            'precio_publicado' => 0, 'titulo' => str_repeat('x', Pedido::MAX_TITULO + 1)]);
        self::assertSame(['titulo', 'anio', 'kilometraje', 'precio_publicado'], array_keys($errores));
        self::assertArrayHasKey('anio', self::pedido(['url' => self::ML, 'mecanico_id' => 7, 'anio' => 2018.5])[1]);
    }

    public function testNadieConInteresEnElResultado(): void
    {
        self::assertNull(Pedido::conflicto(1, 2, null));
        self::assertNull(Pedido::conflicto(1, 2, 3));
        self::assertSame('mecanico_propio', Pedido::conflicto(1, 1, null)[0]);
        self::assertSame('vehiculo_propio', Pedido::conflicto(1, 2, 1)[0]);
        self::assertSame('mecanico_vendedor', Pedido::conflicto(1, 2, 2)[0]);
    }
}
