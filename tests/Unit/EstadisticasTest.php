<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Estadisticas\Eventos;
use AutoCar\Estadisticas\Panel;
use PHPUnit\Framework\TestCase;

final class EstadisticasTest extends TestCase
{
    public function testSoloSeGuardanLosEventosValidos(): void
    {
        self::assertSame([], Eventos::validos(null));
        self::assertSame([], Eventos::validos('texto'));
        self::assertSame(
            [['tipo' => 'mostrado', 'origen' => 'para_vos', 'ref' => 'https://a.test/1'], ['tipo' => 'tocado', 'origen' => 'publicacion', 'ref' => null]],
            Eventos::validos([
                ['tipo' => 'mostrado', 'origen' => 'para_vos', 'ref' => ' https://a.test/1 '],
                ['tipo' => 'visto', 'origen' => 'para_vos'],
                ['tipo' => 'mostrado', 'origen' => 'otro'],
                ['tipo' => 'tocado', 'origen' => 'publicacion', 'ref' => ''],
                5,
            ])
        );
        // Hasta 50 por pedido
        self::assertCount(50, Eventos::validos(array_fill(0, 80, ['tipo' => 'mostrado', 'origen' => 'destacado'])));
    }

    public function testElPeriodoEsUnoDeLosPermitidos(): void
    {
        self::assertSame(7, Panel::dias('7'));
        self::assertSame(90, Panel::dias(90));
        self::assertSame(30, Panel::dias(null));
        self::assertSame(30, Panel::dias('5'));
        self::assertSame(30, Panel::dias('abc'));
    }
}
