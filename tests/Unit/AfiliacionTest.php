<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Afiliacion\Afiliacion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AfiliacionTest extends TestCase
{
    private const CONVENIOS = [
        ['fuente' => 'kavak', 'nombre' => 'Kavak', 'tarifa_clic' => 200.0, 'activo' => true],
        ['fuente' => 'mercadolibre', 'nombre' => 'MercadoLibre', 'tarifa_clic' => 150.0, 'activo' => true],
        ['fuente' => 'autocosmos', 'nombre' => 'Autocosmos', 'tarifa_clic' => 150.0, 'activo' => false],
    ];

    public function testElMesEsElDeArgentina(): void
    {
        // 1/12 a la 1 de la mañana en UTC todavía es noviembre en Argentina
        self::assertSame('2026-11-01', Afiliacion::mes(new DateTimeImmutable('2026-12-01T01:00:00+00:00')));
        self::assertSame('2026-12-01', Afiliacion::mes(new DateTimeImmutable('2026-12-01T04:00:00+00:00')));
    }

    public function testSoloVaElOrigenDelMetabuscador(): void
    {
        self::assertSame('para_vos', Afiliacion::origen('para_vos'));
        self::assertNull(Afiliacion::origen('publicacion'));
        self::assertNull(Afiliacion::origen(['x']));
    }

    public function testElMesEnCursoCobraLosClicsValidosPorLaTarifaDeCadaPlataforma(): void
    {
        $clics = [
            ['mes' => '2026-11-01', 'fuente' => 'kavak', 'clics' => 12, 'validos' => 10],
            ['mes' => '2026-11-01', 'fuente' => 'mercadolibre', 'clics' => 30, 'validos' => 20],
            ['mes' => '2026-11-01', 'fuente' => 'autocosmos', 'clics' => 5, 'validos' => 5],  // sin convenio activo
            ['mes' => '2026-10-01', 'fuente' => 'kavak', 'clics' => 99, 'validos' => 99],     // otro mes
        ];
        $m = Afiliacion::esteMes(self::CONVENIOS, $clics, new DateTimeImmutable('2026-11-20T12:00:00-03:00'));
        $porFuente = array_column($m['filas'], null, 'fuente');

        self::assertSame('2026-11-01', $m['mes']);
        self::assertSame(10, $m['dias_al_cierre']);
        self::assertSame(2000.0, $porFuente['kavak']['monto']);          // 10 × 200
        self::assertSame(3000.0, $porFuente['mercadolibre']['monto']);   // 20 × 150
        self::assertSame(0.0, $porFuente['autocosmos']['monto']);        // se cuentan, pero no se cobran
        self::assertSame(5, $porFuente['autocosmos']['validos']);
        self::assertSame(47, $m['clics']);
        self::assertSame(5000.0, $m['total']);
    }

    public function testLaEvolucionUsaLoCobradoYMarcaLosMesesPendientes(): void
    {
        $clics = [
            ['mes' => '2026-09-01', 'fuente' => 'kavak', 'clics' => 4, 'validos' => 4],
            ['mes' => '2026-10-01', 'fuente' => 'kavak', 'clics' => 8, 'validos' => 6],
            ['mes' => '2026-10-01', 'fuente' => 'mercadolibre', 'clics' => 10, 'validos' => 10],
            ['mes' => '2026-11-01', 'fuente' => 'kavak', 'clics' => 1, 'validos' => 1],
        ];
        // Septiembre se cobró con otra tarifa: queda lo que se cobró. Octubre: Kavak cobrado, MercadoLibre no
        $cobros = [
            ['mes' => '2026-09-01', 'socio' => 'kavak', 'cantidad' => 4, 'monto' => 400, 'cobrado_at' => '2026-10-02'],
            ['mes' => '2026-10-01', 'socio' => 'kavak', 'cantidad' => 6, 'monto' => 1200, 'cobrado_at' => '2026-11-03'],
        ];
        $e = Afiliacion::evolucion(self::CONVENIOS, $clics, $cobros, new DateTimeImmutable('2026-11-20T12:00:00-03:00'));
        $porMes = array_column($e, null, 'mes');

        self::assertCount(Afiliacion::MESES, $e);
        self::assertSame('2025-12-01', $e[0]['mes']);
        self::assertSame('2026-11-01', $e[11]['mes']);
        self::assertSame('en_curso', $porMes['2026-11-01']['estado']);
        self::assertSame(200.0, $porMes['2026-11-01']['total']);
        self::assertSame('cobrado', $porMes['2026-09-01']['estado']);
        self::assertSame(400.0, $porMes['2026-09-01']['total']);   // lo cobrado, no 4 × 200
        self::assertSame('pendiente', $porMes['2026-10-01']['estado']);
        self::assertSame(2700.0, $porMes['2026-10-01']['total']);  // 1.200 cobrado + 10 × 150 sin cobrar
        self::assertSame('cobrado', $porMes['2026-08-01']['estado'], 'sin nada que cobrar no queda pendiente');
    }

    public function testLoSinLiquidarSeparaElMesEnCursoDeLosMesesCerradosSinCobrar(): void
    {
        $clics = [
            ['mes' => '2026-10-01', 'fuente' => 'kavak', 'clics' => 6, 'validos' => 6],
            ['mes' => '2026-10-01', 'fuente' => 'mercadolibre', 'clics' => 10, 'validos' => 10],
            ['mes' => '2026-11-01', 'fuente' => 'kavak', 'clics' => 3, 'validos' => 3],
        ];
        $cobros = [
            ['tipo' => 'plataforma', 'mes' => '2026-10-01', 'socio' => 'kavak', 'cantidad' => 6, 'monto' => 1200, 'cobrado_at' => '2026-11-03'],
            ['tipo' => 'empresa', 'mes' => '2026-09-01', 'socio' => '7', 'cantidad' => 0, 'monto' => 50000, 'cobrado_at' => '2026-10-05'],
        ];
        $empresas = [['id' => 7, 'nombre' => 'Autos SA', 'desde' => '2026-09-01']];
        $e = Afiliacion::evolucion(self::CONVENIOS, $clics, $cobros, new DateTimeImmutable('2026-11-20T12:00:00-03:00'), $empresas, 50000.0);

        self::assertSame([
            'mes' => '2026-11-01',
            'clics' => ['en_curso' => 600.0, 'cerrados' => 1500.0],     // 3 × 200 de noviembre; MercadoLibre de octubre
            'abonos' => ['en_curso' => 50000.0, 'cerrados' => 50000.0], // noviembre en curso; octubre sin cobrar (septiembre sí)
        ], Afiliacion::sinLiquidar($e));
    }
}
