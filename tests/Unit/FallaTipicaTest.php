<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Inspecciones\FallaTipica;
use PHPUnit\Framework\TestCase;

/** Idea B, fallas típicas por modelo: estado por votos, porcentaje medido y validación. */
final class FallaTipicaTest extends TestCase
{
    public function testElVotoDeLosMecanicosCambiaElEstado(): void
    {
        self::assertSame('sin_confirmar', FallaTipica::estado(0, 0, 3));
        self::assertSame('sin_confirmar', FallaTipica::estado(2, 0, 3));
        self::assertSame('confirmada', FallaTipica::estado(3, 1, 3));
        self::assertSame('descartada', FallaTipica::estado(1, 3, 3));
        self::assertSame('sin_confirmar', FallaTipica::estado(3, 3, 3), 'empatados no se decide');
    }

    public function testElPorcentajeNoSeMuestraConMenosDelMinimo(): void
    {
        $con19 = FallaTipica::porcentajes(['frenos' => 10], 19, 20);
        self::assertNull($con19['frenos']);
        $con20 = FallaTipica::porcentajes(['frenos' => 10, 'motor' => 3], 20, 20);
        self::assertSame(50.0, $con20['frenos']);
        self::assertSame(15.0, $con20['motor']);
        self::assertSame(0.0, $con20['carroceria']);
        self::assertCount(10, $con20, 'un porcentaje por ítem del checklist');
    }

    public function testLasMedidasSonLasQueSuperanElUmbral(): void
    {
        $medidas = FallaTipica::medidas(['frenos' => 50.0, 'motor' => 15.0, 'suspension' => 25.0, 'carroceria' => null], 20);
        self::assertSame([['item_codigo' => 'frenos', 'porcentaje' => 50.0], ['item_codigo' => 'suspension', 'porcentaje' => 25.0]], $medidas);
    }

    public function testValidar(): void
    {
        [$d, $e] = FallaTipica::validar(['item_codigo' => 'suspension', 'titulo' => 'Bujes de parrilla gastados'], 'mecanicos');
        self::assertSame([], $e);
        self::assertSame('mecanicos', $d['origen']);
        [, $e] = FallaTipica::validar(['item_codigo' => 'suspension', 'titulo' => 'Bujes de parrilla gastados'], 'internet');
        self::assertArrayHasKey('fuente_url', $e, 'una falla de internet necesita la fuente');
        [, $e] = FallaTipica::validar(['item_codigo' => 'volante', 'titulo' => 'x', 'fuente_url' => 'ftp://x'], 'internet');
        self::assertSame(['item_codigo', 'titulo', 'fuente_url'], array_keys($e));
    }
}
