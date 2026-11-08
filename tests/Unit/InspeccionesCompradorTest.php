<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Inspecciones\Pedido;
use AutoCar\Inspecciones\Reclamo;
use PHPUnit\Framework\TestCase;

/** Reglas del pedido (B4) y del reclamo (B8) que no necesitan base. */
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

    // --- Reclamo

    public function testPedidoDeReclamo(): void
    {
        [$datos, $errores] = Reclamo::validarPedido(['descripcion' => ' Tenía un golpe en el zócalo y no lo marcó ', 'item_codigo' => 'carroceria']);
        self::assertSame([], $errores);
        self::assertSame('carroceria', $datos['item_codigo']);
        self::assertSame('Tenía un golpe en el zócalo y no lo marcó', $datos['descripcion']);

        self::assertArrayHasKey('descripcion', Reclamo::validarPedido(['descripcion' => 'mal'])[1]);
        self::assertArrayHasKey('item_codigo', Reclamo::validarPedido(['descripcion' => str_repeat('x', 20), 'item_codigo' => 'tapizado'])[1]);
        self::assertNull(Reclamo::validarPedido(['descripcion' => str_repeat('x', 20), 'item_codigo' => ''])[0]['item_codigo']);
    }

    public function testProcedeConCompensacionHastaElTope(): void
    {
        [$r, $errores] = Reclamo::validarResolucion(['procede' => true, 'compensacion' => 390000, 'respuesta' => 'Se veía en las fotos.'], 130000);
        self::assertSame([], $errores);
        self::assertSame('procede', $r['estado']);
        self::assertSame(390000.0, $r['compensacion']);

        $pasado = Reclamo::validarResolucion(['procede' => true, 'compensacion' => 390001, 'respuesta' => 'ok, procede'], 130000);
        self::assertArrayHasKey('compensacion', $pasado[1]);
        self::assertArrayHasKey('compensacion', Reclamo::validarResolucion(['procede' => true, 'respuesta' => 'ok, procede'], 130000)[1]);
        self::assertSame([], Reclamo::validarResolucion(['procede' => true, 'compensacion' => 0, 'respuesta' => 'Solo devolución'], 130000)[1]);
    }

    public function testNoProcedeNoLlevaCompensacion(): void
    {
        [$r, $errores] = Reclamo::validarResolucion(['procede' => false, 'compensacion' => 999999, 'respuesta' => 'Era un defecto oculto.'], 130000);
        self::assertSame([], $errores);
        self::assertSame('no_procede', $r['estado']);
        self::assertNull($r['compensacion']);
    }

    public function testLaResolucionPideDecisionYRespuesta(): void
    {
        [, $errores] = Reclamo::validarResolucion(['procede' => 'si'], 130000);
        self::assertArrayHasKey('procede', $errores);
        self::assertArrayHasKey('respuesta', $errores);
    }
}
