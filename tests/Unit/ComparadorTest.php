<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Comparador\AlmacenArchivos;
use AutoCar\Comparador\Clave;
use AutoCar\Comparador\Comparador;
use AutoCar\Comparador\Firestore;
use AutoCar\Comparador\Modelos;
use PHPUnit\Framework\TestCase;

final class ComparadorTest extends TestCase
{
    private const GOL = ['marca' => 'Volkswagen', 'modelo' => 'Gol Trend', 'anio' => 2018, 'kilometraje' => 100000, 'precio' => 15000000.0];

    /** Documento de modelo con autos de 2018 y 100.000 km a esos precios (en pesos salvo que se indique). */
    private static function modelo(array $precios, array $cambios = []): array
    {
        $autos = [];
        foreach ($precios as $i => $p) {
            $autos[] = $cambios[$i] ?? ['mercadolibre', "MLA$i", 2018, 100000, (float)$p, 'ARS', '1.6', null];
        }
        return ['clave' => 'volkswagen_gol_trend', 'fecha' => '2026-11-08', 'dolar' => 1500.0, 'autos' => $autos];
    }

    public function testLaClaveIgualaComoEscribeCadaSitio(): void
    {
        self::assertSame('citroen_c4_cactus', Clave::de('Citroën', 'C4 Cactus'));
        self::assertSame('citroen_c4_cactus', Clave::de('CITROEN', 'c4-cactus'));
        self::assertSame('volkswagen_gol_trend', Clave::de('VW', 'Volkswagen Gol Trend'));
        self::assertSame('mercedes_benz_clase_c', Clave::de('Mercedes-Benz', 'Clase C'));
        self::assertNotSame(Clave::de('Volkswagen', 'Gol'), Clave::de('Volkswagen', 'Gol Trend'));
    }

    public function testBuenPrecioEnPrecioYCaro(): void
    {
        $mercado = self::modelo([16e6, 16e6, 16e6, 16e6, 16e6]);
        $r = Comparador::comparar(['precio' => 14.5e6] + self::GOL, $mercado);
        self::assertSame(Comparador::BUEN_PRECIO, $r['etiqueta']);
        self::assertSame(-9.4, $r['diferencia_pct']);
        self::assertSame(1500000.0, $r['ahorro']);
        // 15 M contra 16 M es 6,25 % abajo: no llega al 7 %, queda "en precio"
        self::assertSame(Comparador::EN_PRECIO, Comparador::comparar(self::GOL, $mercado)['etiqueta']);
        $caro = Comparador::comparar(['precio' => 18e6] + self::GOL, $mercado);
        self::assertSame(Comparador::CARO, $caro['etiqueta']);
        self::assertStringContainsString('12.5 % más que', $caro['texto']);
    }

    public function testConMenosDeCincoParecidosNoSeMuestraElDato(): void
    {
        $r = Comparador::comparar(self::GOL, self::modelo([16e6, 16e6, 16e6, 16e6]));
        self::assertSame(Comparador::SIN_DATOS, $r['etiqueta']);
        self::assertSame(4, $r['comparables']);
        self::assertSame(Comparador::SIN_DATOS, Comparador::comparar(self::GOL, null)['etiqueta']);
    }

    public function testFiltraPorAnioYKmYAbreAAnioMasMenosTres(): void
    {
        $lejos = ['mercadolibre', 'x', 2018, 300000, 1e6, 'ARS', null, null];      // km muy distintos: nunca cuenta
        $tresAnios = ['kavak', 'k', 2015, 100000, 16e6, 'ARS', null, 'https://kavak'];
        $m = self::modelo([16e6, 16e6, 16e6, 16e6, 0, 0], [4 => $lejos, 5 => $tresAnios]);
        $r = Comparador::comparar(self::GOL, $m);
        self::assertSame(5, $r['comparables']);
        self::assertSame(3, $r['criterio']['anios']);
    }

    public function testPasaLosDolaresAPesosConElDolarDeLaCorrida(): void
    {
        $usd = ['autocosmos', 'a', 2018, 100000, 10000.0, 'USD', null, 'https://ac'];
        $r = Comparador::comparar(self::GOL, self::modelo([15e6, 15e6, 15e6, 15e6, 0], [4 => $usd]));
        self::assertSame(5, $r['comparables']);
        self::assertSame(10000.0, Comparador::comparar(self::GOL, self::modelo([15e6]))['precio_usd']);
        self::assertSame(15000000.0, Comparador::enPesos(10000.0, 'USD', 1500.0));
        self::assertNull(Comparador::enPesos(10000.0, 'USD', null));
    }

    public function testDescartaPreciosExtremos(): void
    {
        // Un "anticipo" de 2 M entre autos de 15 a 17 M no tiene que bajar la mediana
        $r = Comparador::comparar(self::GOL, self::modelo([15e6, 15.5e6, 16e6, 16.5e6, 17e6, 2e6]));
        self::assertSame(1, $r['descartados']);
        self::assertSame(16000000.0, $r['mercado']['mediana']);
    }

    public function testMuestraLosMasParecidosYElDetallePorFuente(): void
    {
        $kavak = ['kavak', 'k1', 2018, 100000, 16e6, 'ARS', null, 'https://www.kavak.com/ar/venta/x'];
        $r = Comparador::comparar(self::GOL, self::modelo([16e6, 16e6, 16e6, 16e6, 0], [4 => $kavak]));
        self::assertSame(['kavak', 'mercadolibre'], array_column($r['por_fuente'], 'fuente'));
        self::assertSame('https://auto.mercadolibre.com.ar/MLA-0-_JM', $r['parecidos'][0]['url']);
    }

    public function testArmaUnDocumentoPorModeloConTodasLasFuentes(): void
    {
        $ml = ['fuente' => 'mercadolibre', 'fecha' => '2026-11-08T09:00:00-03:00', 'dolar' => ['venta' => 1570], 'autos' => [
            ['id_externo' => 'MLA1', 'marca' => 'Volkswagen', 'modelo' => 'Gol Trend', 'anio' => 2018, 'km' => 1, 'precio' => 1.0, 'moneda' => 'ARS', 'url' => 'https://x'],
        ]];
        $kavak = ['fuente' => 'kavak', 'fecha' => '2026-11-08T09:30:00-03:00', 'dolar' => null, 'autos' => [
            ['id_externo' => '9', 'marca' => 'VW', 'modelo' => 'Gol Trend', 'anio' => 2018, 'km' => 1, 'precio' => 3.0, 'precio_contado' => 2.0, 'moneda' => 'ARS', 'url' => 'https://k'],
            ['id_externo' => '8', 'marca' => 'VW', 'modelo' => 'Gol Trend', 'anio' => 2018, 'km' => 1, 'precio' => null, 'reservado' => true],
        ]];
        $m = Modelos::armar([$ml, $kavak]);
        self::assertSame(['volkswagen_gol_trend'], array_keys($m));
        $doc = $m['volkswagen_gol_trend'];
        self::assertSame(1570.0, $doc['dolar']);
        self::assertSame(['mercadolibre', 'kavak'], $doc['fuentes']);
        self::assertCount(2, $doc['autos']);                     // el reservado sin precio no entra
        self::assertSame(2.0, $doc['autos'][0][4]);               // kavak: se usa el precio de contado
        self::assertNull($doc['autos'][1][7]);                    // la url de MercadoLibre se arma con el id
        self::assertSame($doc['huella'], Modelos::armar([$kavak, $ml])['volkswagen_gol_trend']['huella']);
    }

    public function testElAlmacenDeArchivosSoloEscribeLoQueCambio(): void
    {
        $dir = sys_get_temp_dir() . '/autocar-test-' . uniqid();
        $almacen = new AlmacenArchivos($dir);
        $doc = ['clave' => 'a_b', 'huella' => 'h1', 'autos' => []];
        self::assertSame(1, $almacen->guardarModelos(['a_b' => $doc]));
        self::assertSame(0, $almacen->guardarModelos(['a_b' => $doc]));
        self::assertSame(1, $almacen->guardarModelos(['a_b' => ['huella' => 'h2'] + $doc]));
        self::assertSame('h2', $almacen->modelo('a_b')['huella']);
        self::assertNull($almacen->modelo('../a_b'));
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }

    public function testFirestoreConvierteIdaYVuelta(): void
    {
        $datos = ['n' => 1, 'x' => 1.5, 's' => 'hola', 'b' => true, 'nada' => null, 'lista' => [1, 'dos'], 'mapa' => ['a' => 1]];
        $campos = Firestore::aCampos($datos);
        self::assertSame(['integerValue' => '1'], $campos['n']);
        self::assertSame($datos, Firestore::desdeCampos($campos));
    }

    public function testFirestoreFirmaElJwtConLaClaveDeLaCuenta(): void
    {
        $clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($clave === false) {
            self::markTestSkipped('openssl sin configuración para generar claves en esta máquina.');
        }
        openssl_pkey_export($clave, $pem);
        $jwt = Firestore::jwt(['client_email' => 'scraper@p.iam.gserviceaccount.com', 'private_key' => $pem], 1000);
        [$cab, $cuerpo, $firma] = explode('.', $jwt);
        $dec = static fn(string $s): string => base64_decode(strtr($s, '-_', '+/'));
        self::assertSame(1, openssl_verify("$cab.$cuerpo", $dec($firma), openssl_pkey_get_details($clave)['key'], OPENSSL_ALGO_SHA256));
        self::assertSame(4600, json_decode($dec($cuerpo), true)['exp']);
    }
}
