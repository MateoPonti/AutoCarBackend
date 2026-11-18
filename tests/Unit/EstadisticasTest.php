<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Estadisticas\Eventos;
use AutoCar\Estadisticas\Panel;
use AutoCar\Estadisticas\PorPerfil;
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

    public function testUnOrdenSoloValeEnElMetabuscadorYConUnOrdenConocido(): void
    {
        self::assertSame(
            [['tipo' => 'orden', 'origen' => 'metabuscador', 'ref' => 'km']],
            Eventos::validos([
                ['tipo' => 'orden', 'origen' => 'metabuscador', 'ref' => 'km'],
                ['tipo' => 'orden', 'origen' => 'metabuscador', 'ref' => 'otro'],
                ['tipo' => 'orden', 'origen' => 'metabuscador'],
                ['tipo' => 'orden', 'origen' => 'para_vos', 'ref' => 'precio'],
                ['tipo' => 'busco', 'origen' => 'metabuscador'], // solo lo anota el back
            ])
        );
        self::assertSame('nuevos', Eventos::orden('nuevos'));
        self::assertSame('recomendado', Eventos::orden(null));
        self::assertSame('recomendado', Eventos::orden('para_vos'));
    }

    public function testLaPlataPorTipoDeUsuarioTieneUnaFilaPorPerfilYLaNeta(): void
    {
        $filas = PorPerfil::filasPlata(
            [['uso' => 'familia', 'pagaron' => '3', 'compradores' => '2', 'ingresos' => '69000', 'por_cobrar' => '23000']],
            [['uso' => 'familia', 'perdida' => '50000', 'reclamos' => '1'], ['uso' => 'trabajo', 'perdida' => '10000', 'reclamos' => '1']]
        );
        self::assertSame(['personal', 'familia', 'trabajo', 'aplicacion', 'sin_perfil'], array_column($filas, 'uso'));
        self::assertSame(3, $filas[1]['pagaron']);
        self::assertSame(1, $filas[1]['reclamos_procedieron']);
        self::assertEqualsWithDelta(19000.0, $filas[1]['neta'], 0.01);
        self::assertEqualsWithDelta(-10000.0, $filas[2]['neta'], 0.01); // un reclamo sin ingresos del período
        self::assertSame(0, $filas[0]['pagaron']);
    }

    public function testLaMejorRecomendacionEsLaDeMasConversion(): void
    {
        $embudo = PorPerfil::filasEmbudo(
            [
                ['uso' => 'familia', 'origen' => 'para_vos', 'tipo' => 'mostrado', 'n' => '10'],
                ['uso' => 'familia', 'origen' => 'destacado', 'tipo' => 'mostrado', 'n' => '4'],
                ['uso' => 'familia', 'origen' => 'destacado', 'tipo' => 'tocado', 'n' => '2'],
            ],
            [['uso' => 'familia', 'origen' => 'para_vos', 'n' => '2'], ['uso' => 'familia', 'origen' => 'destacado', 'n' => '2']]
        );
        $familia = $embudo[1];
        self::assertSame('destacado', $familia['mejor']); // 50 % contra 20 %
        self::assertSame(20.0, $familia['origenes'][0]['conversion']);
        self::assertNull($embudo[0]['mejor']); // sin pagos no hay "mejor"
        self::assertNull($embudo[0]['origenes'][0]['conversion']);
        // Si empatan en conversión, gana la que tiene más pagos
        self::assertSame('b', PorPerfil::mejorOrigen([
            ['origen' => 'a', 'pagaron' => 1, 'conversion' => 50.0],
            ['origen' => 'b', 'pagaron' => 3, 'conversion' => 50.0],
        ]));
    }

    public function testBuscaronYCompraronSumanElTotal(): void
    {
        $m = PorPerfil::filasMetabuscador([
            ['uso' => 'trabajo', 'buscaron' => '5', 'compraron' => '2', 'desde_metabuscador' => '1'],
            ['uso' => 'sin_perfil', 'buscaron' => '3', 'compraron' => '0', 'desde_metabuscador' => '0'],
        ]);
        self::assertSame(['buscaron' => 8, 'compraron' => 2, 'desde_metabuscador' => 1], $m['total']);
        self::assertCount(5, $m['por_uso']);
    }

    public function testElOrdenPreferidoEsElQueMasCompradoresEligieron(): void
    {
        $o = PorPerfil::filasOrdenes([
            ['uso' => 'familia', 'orden' => 'km', 'usuarios' => '4', 'veces' => '6'],
            ['uso' => 'familia', 'orden' => 'precio', 'usuarios' => '4', 'veces' => '9'],
            ['uso' => 'familia', 'orden' => 'inventado', 'usuarios' => '99', 'veces' => '99'],
        ]);
        self::assertSame('precio', $o[1]['preferido']); // empatan en compradores: gana el de más veces
        self::assertSame(['recomendado', 'precio', 'km', 'nuevos'], array_keys($o[1]['ordenes']));
        self::assertNull($o[0]['preferido']);
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
