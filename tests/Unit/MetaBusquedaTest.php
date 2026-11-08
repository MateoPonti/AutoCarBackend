<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\MetaBusqueda\Busqueda;
use AutoCar\MetaBusqueda\Comparables;
use AutoCar\MetaBusqueda\Estadistica;
use AutoCar\MetaBusqueda\Importador;
use AutoCar\MetaBusqueda\Novedades;
use AutoCar\MetaBusqueda\Reporte;
use AutoCar\Recolectores\Kavak\Normalizador;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/recolectores/kavak/Normalizador.php';

final class MetaBusquedaTest extends TestCase
{
    private static function auto(int $id, array $cambios = []): array
    {
        return $cambios + [
            'id' => $id, 'fuente_id' => 1, 'fuente' => 'kavak', 'marca' => 'Renault', 'modelo' => 'Sandero',
            'version' => '1.6', 'anio' => 2017, 'transmision' => 'manual', 'ubicacion' => 'Buenos Aires', 'url' => null,
            'primera_vez' => '2026-11-01', 'ultima_vez' => '2026-11-01', 'estado' => 'activo',
            'precio' => 15000000.0, 'precio_contado' => null, 'moneda' => 'ARS', 'km' => 100000,
            'reservado' => false, 'es_nuevo' => false,
        ];
    }

    private static function busqueda(array $cambios = []): array
    {
        return $cambios + [
            'marca' => 'Renault', 'modelo' => 'Sandero', 'anio_desde' => null, 'anio_hasta' => null,
            'precio_max' => null, 'km_max' => null, 'transmision' => null,
        ];
    }

    public function testMedianaYPercentilesInterpolan(): void
    {
        self::assertNull(Estadistica::mediana([]));
        self::assertSame(3.0, Estadistica::mediana([5, 1, 3]));
        self::assertSame(2.5, Estadistica::mediana([1, 2, 3, 4]));
        self::assertSame(1.75, Estadistica::percentil([1, 2, 3, 4], 0.25));
    }

    public function testLaMedianaResisteUnPrecioMuyDistinto(): void
    {
        self::assertSame(15.0, Estadistica::mediana([14, 15, 16]));
        self::assertSame(15.5, Estadistica::mediana([14, 15, 16, 400]));
    }

    public function testComparablesExcluyenElPropioOtroModeloReservadosYOtraPlataforma(): void
    {
        $auto = self::auto(1);
        $candidatos = [
            $auto,
            self::auto(2, ['modelo' => 'Sandero Stepway']),
            self::auto(3, ['reservado' => true, 'precio' => null]),
            self::auto(4, ['fuente_id' => 2]),
            self::auto(5, ['moneda' => 'USD']),
            self::auto(6),
        ];
        self::assertSame([6], array_column(Comparables::elegir($auto, $candidatos)['autos'], 'id'));
    }

    public function testComparablesAbrenLaBusquedaPorPasos(): void
    {
        $auto = self::auto(1);
        $cerca = array_map(fn(int $i): array => self::auto($i), range(2, 6));
        self::assertSame('anio_2_km', Comparables::elegir($auto, $cerca)['criterio']);

        $anio3 = array_map(fn(int $i): array => self::auto($i, ['anio' => 2020]), range(2, 6));
        self::assertSame('anio_3_km', Comparables::elegir($auto, $anio3)['criterio']);

        $kmLejos = array_map(fn(int $i): array => self::auto($i, ['anio' => 2020, 'km' => 300000]), range(2, 6));
        self::assertFalse(Comparables::elegir($auto, $kmLejos)['suficientes'], 'nunca compara sin mirar los kilómetros');

        $pocos = Comparables::elegir($auto, [self::auto(2), self::auto(3)]);
        self::assertFalse($pocos['suficientes']);
        self::assertNull($pocos['mediana_precio']);
    }

    public function testEtiquetaSegunDiferenciaConLaMediana(): void
    {
        $c = ['autos' => array_fill(0, 5, []), 'suficientes' => true, 'mediana_precio' => 100.0];
        self::assertSame('barato', Comparables::etiqueta(93.0, $c)['valor']);
        self::assertSame('normal', Comparables::etiqueta(94.0, $c)['valor']);
        self::assertSame('normal', Comparables::etiqueta(106.0, $c)['valor']);
        self::assertSame('caro', Comparables::etiqueta(107.0, $c)['valor']);
        self::assertSame(-7.0, Comparables::etiqueta(93.0, $c)['diferencia_pct']);
        self::assertStringContainsString('7% menos', Comparables::etiqueta(93.0, $c)['explicacion']);

        $sinDatos = Comparables::etiqueta(93.0, ['autos' => [], 'suficientes' => false, 'mediana_precio' => null]);
        self::assertSame('datos_insuficientes', $sinDatos['valor']);
        self::assertNull($sinDatos['diferencia_pct']);
    }

    public function testNovedadesDetectaCadaTipoDeCambio(): void
    {
        $anterior = [self::auto(1), self::auto(2), self::auto(3), self::auto(4), self::auto(5)];
        $actual = [
            self::auto(1, ['precio' => 14000000.0]),
            self::auto(2, ['precio' => 16000000.0]),
            self::auto(3, ['reservado' => true, 'precio' => null]),
            self::auto(4),
            self::auto(6, ['es_nuevo' => true]),
            self::auto(7, ['es_nuevo' => false]), // volvió a aparecer: no es nuevo
        ];
        $r = Novedades::comparar($actual, $anterior);
        $tipos = [];
        foreach ($r['cambios'] as $c) {
            $tipos[$c['auto']['id']] = $c['tipo'];
        }
        ksort($tipos);
        self::assertSame([
            1 => Novedades::BAJO, 2 => Novedades::SUBIO, 3 => Novedades::RESERVADO, 5 => Novedades::RETIRADO, 6 => Novedades::NUEVO,
        ], $tipos);
        self::assertSame([4], array_column($r['sin_cambios'], 'id'));
    }

    public function testNoComparaPreciosEnDistintaMoneda(): void
    {
        $r = Novedades::comparar([self::auto(1, ['moneda' => 'USD', 'precio' => 10000.0])], [self::auto(1)]);
        self::assertSame([], $r['cambios']);
    }

    public function testCoincidenciaRespetaCadaCriterio(): void
    {
        $a = self::auto(1);
        self::assertTrue(Busqueda::coincide(self::busqueda(), $a));
        self::assertTrue(Busqueda::coincide(self::busqueda(['marca' => 'renault', 'modelo' => 'SANDERO']), $a));
        self::assertFalse(Busqueda::coincide(self::busqueda(['modelo' => 'Sandero Stepway']), $a));
        self::assertFalse(Busqueda::coincide(self::busqueda(['anio_desde' => 2018]), $a));
        self::assertFalse(Busqueda::coincide(self::busqueda(['anio_hasta' => 2016]), $a));
        self::assertFalse(Busqueda::coincide(self::busqueda(['km_max' => 99999]), $a));
        self::assertFalse(Busqueda::coincide(self::busqueda(['transmision' => 'automatica']), $a));
        self::assertFalse(Busqueda::coincide(self::busqueda(['precio_max' => 14999999.0]), $a));
        self::assertTrue(Busqueda::coincide(self::busqueda(['precio_max' => 15000000.0]), $a));
    }

    public function testElPrecioMaximoEnPesosNoAplicaADolaresYNoExcluyeReservados(): void
    {
        self::assertFalse(Busqueda::coincide(self::busqueda(['precio_max' => 1.0e9]), self::auto(1, ['moneda' => 'USD', 'precio' => 10000.0])));
        self::assertTrue(Busqueda::coincide(self::busqueda(['precio_max' => 1.0]), self::auto(1, ['precio' => null, 'reservado' => true])));
    }

    public function testValidarBusqueda(): void
    {
        [, $errores] = Busqueda::validar([]);
        self::assertArrayHasKey('marca', $errores);
        self::assertArrayHasKey('modelo', $errores);

        [, $errores] = Busqueda::validar(['marca' => 'A', 'modelo' => 'B', 'anio_desde' => 2020, 'anio_hasta' => 2010]);
        self::assertArrayHasKey('anio_hasta', $errores);

        [, $errores] = Busqueda::validar(['marca' => 'A', 'modelo' => 'B', 'precio_max' => -1, 'km_max' => 'x', 'transmision' => 'cvt']);
        self::assertSame(['precio_max', 'km_max', 'transmision'], array_keys($errores));

        [$d, $errores] = Busqueda::validar(['marca' => ' Renault ', 'modelo' => 'Sandero', 'anio_desde' => '2016', 'transmision' => 'manual']);
        self::assertSame([], $errores);
        self::assertSame('Renault', $d['marca']);
        self::assertSame(2016, $d['anio_desde']);
        self::assertNull($d['precio_max']);
    }

    public function testDestacadosEligenYExplican(): void
    {
        $a = self::auto(1, ['precio' => 10000000.0, 'km' => 300000]);
        $b = self::auto(2, ['precio' => 14000000.0, 'km' => 90000]);
        $c = self::auto(3, ['precio' => 16000000.0, 'km' => 30000]);
        $reservado = self::auto(4, ['precio' => null, 'reservado' => true, 'km' => 1000]);
        $comparacion = ['autos' => array_fill(0, 5, []), 'suficientes' => true, 'criterio' => 'anio_2_km', 'mediana_precio' => 15000000.0, 'mediana_km' => 100000.0];

        $d = Reporte::destacados([$a, $b, $c, $reservado], [2 => $comparacion, 3 => $comparacion]);

        self::assertSame(2, $d['mejor_relacion']['auto']['id']);
        self::assertSame(1, $d['mas_barato']['auto']['id']);
        self::assertStringContainsString('más kilómetros', $d['mas_barato']['explicacion']);
        self::assertSame(3, $d['menos_usado']['auto']['id']);
        self::assertStringContainsString('$6.000.000', $d['menos_usado']['explicacion']);
    }

    public function testSinComparablesNoHayMejorRelacion(): void
    {
        $d = Reporte::destacados([self::auto(1)], []);
        self::assertNull($d['mejor_relacion']);
        self::assertSame(1, $d['mas_barato']['auto']['id']);
        self::assertSame(['mejor_relacion' => null, 'mas_barato' => null, 'menos_usado' => null], Reporte::destacados([], []));
    }

    public function testImportadorValidaCadaAuto(): void
    {
        $ok = ['id_externo' => '1', 'marca' => 'Fiat', 'modelo' => 'Uno', 'anio' => 2015, 'km' => 1000, 'moneda' => 'ARS', 'precio' => 100, 'url' => 'https://a.test/1'];
        self::assertSame(100.0, Importador::validarAuto($ok)['precio']);
        self::assertNull(Importador::validarAuto(['precio' => null] + $ok)['precio']);
        self::assertNull(Importador::validarAuto(['url' => 'javascript:alert(1)'] + $ok)['url']);
        foreach ([['marca' => ''], ['anio' => 1900], ['km' => -1], ['moneda' => 'EUR'], ['precio' => 0], ['id_externo' => null]] as $malo) {
            self::assertNull(Importador::validarAuto($malo + $ok), json_encode($malo));
        }
    }

    private static function tarjeta(array $cambios = []): array
    {
        return $cambios + [
            'id' => '562339', 'titulo' => 'Nissan • Versa', 'subtitulo' => '2016 • 97.353 km • 1.6 SENSE • Manual',
            'precio_grande' => '13.350.580', 'precio_chico' => null, 'etiqueta_precio' => 'Precio desde',
            'etiquetas' => [], 'ubicacion' => 'Buenos Aires', 'url' => 'https://www.kavak.com/ar/venta/x',
        ];
    }

    public function testNormalizadorSeparaLosDatosDeLaTarjeta(): void
    {
        $a = Normalizador::auto(self::tarjeta(['titulo' => 'Renault • Sandero Stepway']));
        self::assertSame('Renault', $a['marca']);
        self::assertSame('Sandero Stepway', $a['modelo']);
        self::assertSame(2016, $a['anio']);
        self::assertSame(97353, $a['km']);
        self::assertSame('1.6 SENSE', $a['version']);
        self::assertSame('manual', $a['transmision']);
        self::assertSame('automatica', Normalizador::auto(self::tarjeta(['subtitulo' => '2016 • 1 km • X • Automático']))['transmision']);
    }

    public function testNormalizadorInterpretaLosPreciosSegunElTexto(): void
    {
        $soloGrande = Normalizador::auto(self::tarjeta());
        self::assertSame(13350580, $soloGrande['precio']);
        self::assertNull($soloGrande['precio_contado']);

        $financiado = Normalizador::auto(self::tarjeta(['precio_grande' => '30.107.771', 'precio_chico' => "$\u{a0}30.745.771"]));
        self::assertSame(30107771, $financiado['precio']);
        self::assertSame(30745771, $financiado['precio_contado']);

        $outlet = Normalizador::auto(self::tarjeta(['precio_chico' => '$ 14.750.478', 'etiqueta_precio' => 'Precio de contado']));
        self::assertSame(13350580, $outlet['precio']);
        self::assertSame(13350580, $outlet['precio_contado']);
    }

    public function testNormalizadorReconoceReservadosYRechazaTarjetasRaras(): void
    {
        $r = Normalizador::auto(self::tarjeta(['precio_grande' => null, 'etiqueta_precio' => null, 'etiquetas' => ['Reservado']]));
        self::assertTrue($r['reservado']);
        self::assertNull($r['precio']);

        self::assertNull(Normalizador::auto(self::tarjeta(['titulo' => 'Sin separador'])));
        self::assertNull(Normalizador::auto(self::tarjeta(['subtitulo' => 'sin datos'])));
    }
}
