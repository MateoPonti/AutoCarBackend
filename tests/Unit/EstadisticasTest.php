<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Estadisticas\Eventos;
use AutoCar\Estadisticas\Panel;
use AutoCar\Estadisticas\PlataEscapada;
use AutoCar\Estadisticas\PorPerfil;
use DateTimeImmutable;
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

    public function testCadaPeriodoSeComparaConOtroDelMismoLargo(): void
    {
        $ahora = new DateTimeImmutable('2026-11-24T15:00:00-03:00');
        // 7 días: contra los mismos 7 días del mes pasado
        $siete = Panel::rangos(7, $ahora);
        self::assertSame('2026-11-17', $siete['actual'][0]->format('Y-m-d'));
        self::assertSame('2026-10-17', $siete['anterior'][0]->format('Y-m-d'));
        self::assertSame('2026-10-24', $siete['anterior'][1]->format('Y-m-d'));
        // 30 días y 3 meses: contra el tramo de antes, pegado al actual
        foreach ([30, 90] as $dias) {
            $r = Panel::rangos($dias, $ahora);
            self::assertEquals($r['actual'][0], $r['anterior'][1]);
            self::assertSame($dias, $r['anterior'][0]->diff($r['anterior'][1])->days);
        }
    }

    public function testLaPlataPorTipoDeUsuarioTieneUnaFilaPorPerfil(): void
    {
        $filas = PorPerfil::filasPlata(
            [['uso' => 'familia', 'pagaron' => '3', 'compradores' => '2', 'ingresos' => '69000', 'por_cobrar' => '23000']]
        );
        self::assertSame(['personal', 'familia', 'trabajo', 'aplicacion'], array_column($filas, 'uso')); // el perfil es obligatorio
        self::assertSame(3, $filas[1]['pagaron']);
        self::assertEqualsWithDelta(69000.0, $filas[1]['ingresos'], 0.01);
        self::assertEqualsWithDelta(23000.0, $filas[1]['por_cobrar'], 0.01);
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
            [['uso' => 'familia', 'origen' => 'para_vos', 'n' => '2', 'firmadas' => '1'], ['uso' => 'familia', 'origen' => 'destacado', 'n' => '2']]
        );
        $familia = $embudo[1];
        self::assertSame(1, $familia['origenes'][0]['firmadas']);
        self::assertSame(0, $familia['origenes'][1]['firmadas']); // sin el dato, 0
        $conOrden = PorPerfil::filasEmbudo([], [], [['uso' => 'trabajo', 'origen' => 'para_vos', 'orden' => 'precio', 'n' => '3']]);
        self::assertSame(['precio' => 3, 'km' => 0, 'nuevos' => 0], $conOrden[2]['origenes'][0]['ordenaron']);
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
            ['uso' => 'personal', 'buscaron' => '3', 'compraron' => '0', 'desde_metabuscador' => '0'],
        ]);
        self::assertSame(['buscaron' => 8, 'compraron' => 2, 'desde_metabuscador' => 1], $m['total']);
        self::assertCount(4, $m['por_uso']);
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

    public function testCuantoTuvoQueInteractuarParaComprarCadaTipoDeUsuario(): void
    {
        $plata = [['uso' => 'trabajo', 'ingresos' => 30000.0], ['uso' => 'familia', 'ingresos' => 15000.0]];
        $actividad = [['uso' => 'trabajo', 'activos' => 3, 'interacciones' => 20], ['uso' => 'familia', 'activos' => 5, 'interacciones' => 60]];
        $compradores = [
            ['uso' => 'trabajo', 'interacciones' => 4, 'dias' => 1.5],
            ['uso' => 'trabajo', 'interacciones' => 8, 'dias' => 3.5],
            ['uso' => 'familia', 'interacciones' => 18, 'dias' => 12.0],
            ['uso' => 'familia', 'interacciones' => 0, 'dias' => null], // pagó sin pasar por el metabuscador
        ];
        $v = PorPerfil::filasValor($plata, $actividad, $compradores);
        $porUso = array_column($v['por_uso'], null, 'uso');

        self::assertSame(array_keys(PorPerfil::usos()), array_column($v['por_uso'], 'uso'));
        self::assertSame(20, $porUso['trabajo']['interacciones']);
        self::assertSame(30000.0, $porUso['trabajo']['ingresos']);
        self::assertSame(6.0, $porUso['trabajo']['interacciones_hasta_comprar']);
        self::assertSame(2.5, $porUso['trabajo']['dias_hasta_comprar']);
        self::assertSame(18.0, $porUso['familia']['interacciones_hasta_comprar']);
        self::assertSame(1, $porUso['familia']['compradores_sin_interacciones']);
        // Sin datos: sin división por cero
        self::assertNull($porUso['personal']['interacciones_hasta_comprar']);
        // Total: 80 interacciones; mediana de 4, 8 y 18
        self::assertSame(80, $v['total']['interacciones']);
        self::assertSame(8.0, $v['total']['interacciones_hasta_comprar']);
        self::assertSame(4, $v['total']['compradores']);
    }

    public function testLaGananciaDeCadaRecomendacionPorPersonaQueLaVioTocoYPago(): void
    {
        $fila = PorPerfil::conGanancia(['origen' => 'para_vos', 'vieron' => 92, 'tocaron' => 44, 'pagaron' => 13, 'ganancia' => 165000]);
        self::assertSame(165000.0, $fila['ganancia']);
        self::assertSame(1793.0, $fila['por_vio']);   // 165.000 ÷ 92
        self::assertSame(3750.0, $fila['por_toco']);  // 165.000 ÷ 44
        self::assertSame(12692.0, $fila['por_pago']); // 165.000 ÷ 13
        self::assertSame('para_vos', $fila['origen']);

        // Sin nadie en un paso, no hay división por cero
        $vacia = PorPerfil::conGanancia(['vieron' => 0, 'tocaron' => 0, 'pagaron' => 0]);
        self::assertSame(0.0, $vacia['ganancia']);
        self::assertNull($vacia['por_vio']);

        // El embudo por tipo de usuario trae la ganancia de cada origen
        $embudo = PorPerfil::filasEmbudo(
            [['uso' => 'familia', 'origen' => 'para_vos', 'tipo' => 'mostrado', 'n' => 10]],
            [['uso' => 'familia', 'origen' => 'para_vos', 'n' => 2, 'firmadas' => 1, 'ganancia' => 15000]],
        );
        $familia = array_values(array_filter($embudo, static fn(array $f): bool => $f['uso'] === 'familia'))[0];
        $paraVos = array_values(array_filter($familia['origenes'], static fn(array $o): bool => $o['origen'] === 'para_vos'))[0];
        self::assertSame(15000.0, $paraVos['ganancia']);
        self::assertSame(1500.0, $paraVos['por_vio']);
    }

    public function testElPeriodoEsUnoDeLosPermitidos(): void
    {
        self::assertSame(7, Panel::dias('7'));
        self::assertSame(90, Panel::dias(90));
        self::assertSame(30, Panel::dias(null));
        self::assertSame(30, Panel::dias('5'));
        self::assertSame(30, Panel::dias('abc'));
    }

    public function testLaPlataEscapadaCuentaCadaPasoDondeSeFueLaGente(): void
    {
        // 100 vieron, 20 tocaron, 5 pagaron; 300 avisos mostrados y 40 clics; $10.000 por inspección, $150 por clic
        $e = PlataEscapada::calcular(['vieron' => 100, 'tocaron' => 20, 'pagaron' => 5], ['mostrados' => 300, 'clics' => 40], 10000.0, 150.0);
        self::assertSame(15, $e['sin_pagar']['cantidad']);
        self::assertEqualsWithDelta(150000, $e['sin_pagar']['monto'], 0.01);
        // 80 sin tocar × 25 % de los que tocan pagan × $10.000
        self::assertSame(80, $e['sin_tocar']['cantidad']);
        self::assertEqualsWithDelta(0.25, $e['sin_tocar']['conversion'], 0.0001);
        self::assertEqualsWithDelta(200000, $e['sin_tocar']['monto'], 0.01);
        self::assertSame(260, $e['sin_clic']['cantidad']);
        self::assertEqualsWithDelta(39000, $e['sin_clic']['monto'], 0.01);
        self::assertEqualsWithDelta(389000, $e['total'], 0.01);
    }

    public function testSinDatosParaCalcularElMontoQuedaNull(): void
    {
        // Nadie tocó, sin inspecciones cobradas ni convenios activos; más clics que avisos no da negativo
        $e = PlataEscapada::calcular(['vieron' => 10, 'tocaron' => 0, 'pagaron' => 0], ['mostrados' => 2, 'clics' => 5], null, null);
        self::assertNull($e['sin_tocar']['conversion']);
        self::assertNull($e['sin_tocar']['monto']);
        self::assertNull($e['sin_pagar']['monto']);
        self::assertSame(0, $e['sin_clic']['cantidad']);
        self::assertNull($e['sin_clic']['monto']);
        self::assertSame(0.0, $e['total']);
    }
}
