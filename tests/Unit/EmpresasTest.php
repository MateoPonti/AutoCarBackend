<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Afiliacion\Afiliacion;
use AutoCar\Catalogo\PrecioMercado;
use AutoCar\Catalogo\RankingExplorar;
use AutoCar\Catalogo\Visitas;
use AutoCar\Empresas\Empresa;
use AutoCar\Empresas\PanelEmpresa;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EmpresasTest extends TestCase
{
    private const SOLICITUD = ['razon_social' => 'Autos del Oeste SA', 'cuit' => '30712345679', 'localidad' => 'Luján',
        'telefono' => '+54 2323 44-5566', 'sitio_web' => 'https://autosdeloeste.test', 'condiciones' => true];

    public function testLaSolicitudDeUnaEmpresaSeValida(): void
    {
        [$datos, $errores] = Empresa::validar(self::SOLICITUD);
        self::assertSame([], $errores);
        self::assertSame('30-71234567-9', $datos['cuit']);

        [, $errores] = Empresa::validar(['razon_social' => 'A', 'cuit' => '123', 'localidad' => '', 'telefono' => 'llamame',
            'sitio_web' => 'javascript:alert(1)', 'condiciones' => false]);
        self::assertSame(['razon_social', 'cuit', 'localidad', 'telefono', 'sitio_web', 'condiciones'], array_keys($errores));

        // El sitio web es opcional
        [$datos, $errores] = Empresa::validar(['sitio_web' => ''] + self::SOLICITUD);
        self::assertSame([], $errores);
        self::assertNull($datos['sitio_web']);
    }

    private static function auto(int $id, array $cambios = []): array
    {
        return $cambios + ['id' => $id, 'anio' => 2018, 'kilometraje' => 80000, 'created_at' => '2026-11-10T10:00:00-03:00'];
    }

    public function testExplorarOrdenaComoElMetabuscador(): void
    {
        $ahora = new DateTimeImmutable('2026-11-20T12:00:00-03:00');
        $autos = [
            self::auto(1),                                                 // 10 % más barato
            self::auto(2, ['kilometraje' => 40000]),                       // a precio, la mitad de km que los parecidos
            self::auto(3, ['created_at' => '2026-11-19T10:00:00-03:00']),  // sin parecidos: va al final, aunque sea nuevo
            self::auto(4),                                                 // 15 % más caro
        ];
        $mercado = [
            1 => ['suficientes' => true, 'diferencia_pct' => -10.0, 'mediana_km' => 80000],
            2 => ['suficientes' => true, 'diferencia_pct' => 0.0, 'mediana_km' => 80000],
            3 => ['suficientes' => false, 'diferencia_pct' => null, 'mediana_km' => null],
            4 => ['suficientes' => true, 'diferencia_pct' => 15.0, 'mediana_km' => 80000],
        ];
        // Sin perfil: por la diferencia con el precio típico
        $ids = static fn(array $r): array => array_column($r, 'id');
        self::assertSame([1, 2, 4, 3], $ids(RankingExplorar::ordenar($autos, $mercado, null, null, $ahora)));
        // "Para la familia" pesa más los km: el de pocos km pasa adelante del barato
        self::assertSame([2, 1, 4, 3], $ids(RankingExplorar::ordenar($autos, $mercado, 'familia', null, $ahora)));
        // "Para trabajar" pesa más el precio
        self::assertSame([1, 2, 4, 3], $ids(RankingExplorar::ordenar($autos, $mercado, 'trabajo', null, $ahora)));
        self::assertNull(RankingExplorar::ordenar($autos, $mercado, null, null, $ahora)[3]['puntaje']);
    }

    public function testElPanelDeLaEmpresaSumaYOrdenaPorVisitas(): void
    {
        $autos = [
            ['id' => 1, 'marca' => 'Fiat', 'modelo' => 'Cronos', 'anio' => 2022, 'estado' => 'disponible', 'precio' => '18000000',
             'foto_id' => 9, 'created_at' => '2026-11-01T10:00:00-03:00'],
            ['id' => 2, 'marca' => 'Ford', 'modelo' => 'Ka', 'anio' => 2017, 'estado' => 'pendiente', 'precio' => null,
             'foto_id' => null, 'created_at' => '2026-11-15T10:00:00-03:00'],
        ];
        $porAuto = ['visitas' => [1 => 40, 2 => 60], 'anterior' => [1 => 20], 'favoritos' => [1 => 3], 'consultas' => [1 => 4, 2 => 6], 'inspecciones' => [1 => 1]];
        $p = PanelEmpresa::armar($autos, $porAuto, ['2026-11-20' => 12], [1 => ['suficientes' => true, 'diferencia_pct' => -8.5,
            'mediana_precio' => 19700000.0, 'comparables' => 9]], 7, new DateTimeImmutable('2026-11-20T12:00:00-03:00'));

        self::assertSame(100, $p['resumen']['visitas']);
        self::assertSame(20, $p['resumen']['visitas_anterior']);
        self::assertSame(2, $p['resumen']['autos_activos']);
        self::assertSame(['id' => 2, 'titulo' => 'Ford Ka 2017', 'visitas' => 60], $p['mas_vista']);
        self::assertSame([2, 1], array_column($p['autos'], 'id'));
        self::assertSame(10.0, $p['autos'][1]['conversion']);           // 4 consultas de 40 visitas
        self::assertSame(-8.5, $p['autos'][1]['mercado']['diferencia_pct']);
        self::assertNull($p['autos'][0]['mercado']);
        self::assertSame(19, $p['autos'][1]['dias_publicado']);
        self::assertCount(7, $p['serie']);
        self::assertSame(['fecha' => '2026-11-20', 'visitas' => 12], $p['serie'][6]);
    }

    public function testElVisitanteSinSesionEsUnaMarcaQueCambiaCadaDia(): void
    {
        self::assertSame('u:7', Visitas::visitante(7, '1.2.3.4', 'x', '2026-11-20'));
        $hoy = Visitas::visitante(null, '1.2.3.4', 'navegador', '2026-11-20');
        self::assertStringStartsWith('a:', $hoy);
        self::assertSame($hoy, Visitas::visitante(null, '1.2.3.4', 'navegador', '2026-11-20'));
        self::assertNotSame($hoy, Visitas::visitante(null, '1.2.3.4', 'navegador', '2026-11-21'));
        self::assertStringNotContainsString('1.2.3.4', $hoy);
    }

    public function testDelComparadorSeGuardaLoQueSirveParaElRanking(): void
    {
        self::assertSame(['suficientes' => false, 'diferencia_pct' => null, 'mediana_precio' => null, 'mediana_km' => null, 'comparables' => 2],
            PrecioMercado::desdeComparacion(['etiqueta' => 'sin_datos', 'comparables' => 2]));
        $r = PrecioMercado::desdeComparacion(['etiqueta' => 'buen_precio', 'diferencia_pct' => -9.1, 'comparables' => 8,
            'mercado' => ['mediana' => 15000000], 'km' => ['mediana_mercado' => 90000]]);
        self::assertTrue($r['suficientes']);
        self::assertSame(90000, $r['mediana_km']);
    }

    public function testElScraperCalculaElPrecioContraElMercadoConLosModelosEnMemoria(): void
    {
        $autos = [];
        foreach ([9.0, 9.5, 10.0, 10.5, 11.0, 12.0] as $i => $millones) {
            $autos[] = ['kavak', "k$i", 2019, 70000 + $i * 1000, $millones * 1000000, 'ARS', null, null];
        }
        $modelos = ['renault_sandero' => ['clave' => 'renault_sandero', 'fecha' => '2026-11-20', 'dolar' => null, 'autos' => $autos]];
        $r = PrecioMercado::calcularConModelos([
            ['id' => 5, 'marca' => 'Renault', 'modelo' => 'Sandero', 'anio' => 2019, 'kilometraje' => 72000, 'precio' => 9000000],
            ['id' => 6, 'marca' => 'Fiat', 'modelo' => 'Uno', 'anio' => 2010, 'kilometraje' => 150000, 'precio' => 4000000],   // sin datos
            ['id' => 7, 'marca' => 'Renault', 'modelo' => 'Sandero', 'anio' => 2019, 'kilometraje' => null, 'precio' => null], // incompleta
        ], $modelos);

        self::assertSame([5, 6], array_keys($r)); // en Firestore quedan como claves de texto
        self::assertTrue($r[5]['suficientes']);
        self::assertLessThan(0, $r[5]['diferencia_pct']);   // más barato que la mediana de los parecidos
        self::assertSame(9000000.0, $r[5]['precio']);        // con qué datos se calculó
        self::assertFalse($r[6]['suficientes']);
    }

    public function testLasEmpresasPaganSuAbonoDesdeElMesEnQueSeAprobaron(): void
    {
        $convenios = [['fuente' => 'kavak', 'nombre' => 'Kavak', 'tarifa_clic' => 200.0, 'activo' => true]];
        $clics = [['mes' => '2026-11-01', 'fuente' => 'kavak', 'clics' => 5, 'validos' => 5]];
        $empresas = [['id' => 7, 'nombre' => 'Autos del Oeste SA', 'desde' => '2026-10-01'], ['id' => 8, 'nombre' => 'Nueva', 'desde' => '2026-11-01']];
        $ahora = new DateTimeImmutable('2026-11-20T12:00:00-03:00');

        $m = Afiliacion::esteMes($convenios, $clics, $ahora, $empresas, 50000.0);
        self::assertSame(1000.0, $m['de_clics']);
        self::assertSame(100000.0, $m['de_abonos']);
        self::assertSame(101000.0, $m['total']);
        self::assertSame(['plataforma', 'empresa', 'empresa'], array_column($m['filas'], 'tipo'));

        $cobros = [['tipo' => 'empresa', 'mes' => '2026-10-01', 'socio' => '7', 'cantidad' => 0, 'monto' => 40000, 'cobrado_at' => '2026-11-02']];
        $porMes = array_column(Afiliacion::evolucion($convenios, $clics, $cobros, $ahora, $empresas, 50000.0), null, 'mes');
        self::assertSame(40000.0, $porMes['2026-10-01']['de_abonos']);   // cobrado con el abono de ese momento
        self::assertSame('cobrado', $porMes['2026-10-01']['estado']);
        self::assertSame(0.0, $porMes['2026-09-01']['de_abonos']);       // antes de aprobarse no paga
    }
}
