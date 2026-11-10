<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

use AutoCar\MetaBusqueda\Importador;
use AutoCar\MetaBusqueda\Reporte;
use InvalidArgumentException;

/** Importa corridas simuladas y consulta la API real. Cada prueba usa su propia plataforma y marca. */
final class MetaBusquedaTest extends ApiTestCase
{
    private static function unico(string $prefijo): string
    {
        return $prefijo . bin2hex(random_bytes(4));
    }

    private static function coche(string $id, string $marca, array $cambios = []): array
    {
        return $cambios + [
            'id_externo' => $id, 'marca' => $marca, 'modelo' => 'Sandero', 'version' => '1.6', 'anio' => 2017, 'km' => 100000,
            'transmision' => 'manual', 'precio' => 15000000, 'precio_contado' => null, 'moneda' => 'ARS',
            'reservado' => false, 'ubicacion' => 'Buenos Aires', 'url' => "https://plataforma.test/$id",
        ];
    }

    private static function importar(string $fuente, string $fecha, array $autos, bool $completo = true): array
    {
        Entorno::url(); // recrea la base la primera vez
        return (new Importador(Entorno::documentos()))->importar(['fuente' => $fuente, 'fecha' => $fecha, 'completo' => $completo, 'autos' => $autos]);
    }

    /** Siete autos del modelo con precios escalonados y uno de otro modelo parecido. */
    private static function primeraSemana(string $marca): array
    {
        $autos = [];
        foreach ([13, 14, 14.5, 15, 15.5, 16, 17] as $i => $millones) {
            $autos[] = self::coche('a' . ($i + 1), $marca, ['precio' => (int)($millones * 1000000), 'km' => 95000 + $i * 2000]);
        }
        $autos[] = self::coche('otro', $marca, ['modelo' => 'Logan', 'precio' => 15000000]);
        return $autos;
    }

    private function crearBusqueda(string $marca, array $cambios = []): int
    {
        $r = $this->pedir('POST', '/api/meta-busquedas', $cambios + ['marca' => $marca, 'modelo' => 'Sandero']);
        self::assertSame(201, $r['status'], json_encode($r['json']));
        return $r['json']['busqueda']['id'];
    }

    public function testExigeSesionYCsrf(): void
    {
        Entorno::url();
        self::assertSame(401, $this->pedir('GET', '/api/meta-busquedas')['status']);
        self::assertSame(401, $this->pedir('GET', '/api/meta-busquedas/1')['status']);
        self::assertSame(401, $this->pedir('GET', '/api/autos-externos?id=kavak~renault_sandero~1')['status']);
        self::assertSame(401, $this->pedir('GET', '/api/meta-busquedas/avisos')['status']);

        $this->ingresarComoUsuario();
        $r = $this->pedir('POST', '/api/meta-busquedas', ['marca' => 'A', 'modelo' => 'B'], [], false);
        self::assertSame(403, $r['status']);
    }

    public function testValidaYPermiteEditarYBorrar(): void
    {
        $this->ingresarComoUsuario();
        $malo = $this->pedir('POST', '/api/meta-busquedas', ['marca' => '', 'modelo' => 'X', 'anio_desde' => 2020, 'anio_hasta' => 2010]);
        self::assertSame(422, $malo['status']);
        self::assertArrayHasKey('marca', $malo['json']['errores']);
        self::assertArrayHasKey('anio_hasta', $malo['json']['errores']);

        $id = $this->crearBusqueda('Fiat', ['precio_max' => 1000000]);
        $editada = $this->pedir('PUT', "/api/meta-busquedas/$id", ['marca' => 'Fiat', 'modelo' => 'Uno', 'km_max' => 50000]);
        self::assertSame(200, $editada['status']);
        self::assertSame('Uno', $editada['json']['busqueda']['modelo']);
        self::assertNull($editada['json']['busqueda']['precio_max']);
        self::assertArrayNotHasKey('usuario_id', $editada['json']['busqueda']);

        self::assertSame(204, $this->pedir('DELETE', "/api/meta-busquedas/$id")['status']);
        self::assertSame(404, $this->pedir('GET', "/api/meta-busquedas/$id")['status']);
    }

    public function testUnUsuarioNoVeNiTocaLasBusquedasDeOtro(): void
    {
        $this->ingresarComoUsuario();
        $id = $this->crearBusqueda('Fiat');

        $this->setUp();
        $this->ingresarComoUsuario();
        self::assertSame(404, $this->pedir('GET', "/api/meta-busquedas/$id")['status']);
        self::assertSame(404, $this->pedir('PUT', "/api/meta-busquedas/$id", ['marca' => 'A', 'modelo' => 'B'])['status']);
        self::assertSame(404, $this->pedir('DELETE', "/api/meta-busquedas/$id")['status']);
        self::assertSame(404, $this->pedir('POST', "/api/meta-busquedas/$id/avisos/vistos")['status']);
        self::assertSame([], $this->pedir('GET', '/api/meta-busquedas')['json']['busquedas']);
    }

    public function testLimiteDeBusquedasPorUsuario(): void
    {
        $this->ingresarComoUsuario();
        for ($i = 0; $i < 20; $i++) {
            $this->crearBusqueda('Fiat');
        }
        self::assertSame(409, $this->pedir('POST', '/api/meta-busquedas', ['marca' => 'Fiat', 'modelo' => 'Uno'])['status']);
    }

    public function testReporteConUnaSolaCorridaNoTieneNovedades(): void
    {
        $fuente = self::unico('f');
        $marca = self::unico('M');
        self::importar($fuente, '2026-11-02T06:00:00-03:00', self::primeraSemana($marca));

        $this->ingresarComoUsuario();
        $id = $this->crearBusqueda($marca);
        $r = $this->pedir('GET', "/api/meta-busquedas/$id");
        self::assertSame(200, $r['status'], json_encode($r['json']));
        $j = $r['json'];

        self::assertSame($marca, $j['busqueda']['marca']);
        self::assertSame([], $j['novedades']['items'], 'la primera corrida no tiene contra qué compararse');
        self::assertSame(7, $j['resultados']['total']);
        self::assertSame(Reporte::ACLARACION, $j['aclaracion']);

        $ref = self::deFuente($j['referencia'], $fuente);
        self::assertSame(7, $ref['cantidad']);
        self::assertEquals(15000000, $ref['mediana']);
        self::assertEquals(14250000, $ref['p25']);
        self::assertEquals(15750000, $ref['p75']);

        self::assertEquals(13000000, $j['destacados']['mas_barato']['auto']['precio']);
        self::assertSame(95000, $j['destacados']['menos_usado']['auto']['km']);
        self::assertNotNull($j['destacados']['mejor_relacion']);
        self::assertSame('barato', $j['destacados']['mas_barato']['auto']['etiqueta']['valor']);

        self::assertSame(['Logan'], array_column(array_filter($j['otros_modelos'], fn($o) => $o['marca'] === $marca), 'modelo'));
        foreach ($j['resultados']['items'] as $item) {
            self::assertSame('Sandero', $item['modelo'], 'los otros modelos no se mezclan con los resultados');
        }
    }

    public function testDosSemanasGeneranNovedadesAvisosYEstados(): void
    {
        $fuente = self::unico('f');
        $marca = self::unico('M');
        $semana1 = self::primeraSemana($marca);
        self::importar($fuente, '2026-11-02T06:00:00-03:00', $semana1);

        $this->ingresarComoUsuario();
        $id = $this->crearBusqueda($marca);

        $semana2 = $semana1;
        $semana2[0]['precio'] = 12000000;               // a1 baja
        $semana2[1]['precio'] = 14500000;               // a2 sube
        unset($semana2[2]);                             // a3 se retira
        $semana2[3]['precio'] = null;                   // a4 se reserva
        $semana2[3]['reservado'] = true;
        $semana2[] = self::coche('a8', $marca, ['precio' => 15200000]); // a8 es nuevo
        $r = self::importar($fuente, '2026-11-09T06:00:00-03:00', array_values($semana2));
        self::assertTrue($r['completo']);
        self::assertSame(1, $r['retirados']);
        self::assertSame(4, $r['avisos'], 'nuevo, bajó, reservado y retirado; una suba no avisa');

        $j = $this->pedir('GET', "/api/meta-busquedas/$id")['json'];
        self::assertTrue($j['novedades']['disponible']);
        self::assertSame(1, $j['novedades']['nuevos']);
        self::assertSame(1, $j['novedades']['bajaron']);
        self::assertSame(1, $j['novedades']['subieron']);
        self::assertSame(1, $j['novedades']['reservados']);
        self::assertSame(1, $j['novedades']['retirados']);
        self::assertSame(3, $j['novedades']['sin_cambios']);

        // El reservado se lista sin etiqueta y no cuenta en el precio típico
        $reservado = array_values(array_filter($j['resultados']['items'], fn($i) => $i['reservado']))[0];
        self::assertNull($reservado['precio']);
        self::assertNull($reservado['etiqueta']);
        self::assertSame(6, self::deFuente($j['referencia'], $fuente)['cantidad']);
        self::assertSame(7, $j['resultados']['total']);

        // Avisos
        $avisos = $this->pedir('GET', '/api/meta-busquedas/avisos?sin_ver=1')['json']['avisos'];
        $tipos = array_column($avisos, 'tipo');
        sort($tipos);
        self::assertSame(['bajo', 'nuevo', 'reservado', 'retirado'], $tipos);
        self::assertSame(4, $this->pedir('GET', '/api/meta-busquedas')['json']['busquedas'][0]['avisos_sin_ver']);

        self::assertSame(204, $this->pedir('POST', "/api/meta-busquedas/$id/avisos/vistos")['status']);
        self::assertSame([], $this->pedir('GET', '/api/meta-busquedas/avisos?sin_ver=1')['json']['avisos']);
        self::assertCount(4, $this->pedir('GET', '/api/meta-busquedas/avisos')['json']['avisos']);

        // Detalle del que bajó: historial y comparables
        $baja = array_values(array_filter($j['novedades']['items'], fn($i) => $i['tipo'] === 'bajo'))[0];
        self::assertEquals(13000000, $baja['precio_anterior']);
        self::assertEquals(12000000, $baja['precio_actual']);
        $detalle = $this->pedir('GET', '/api/autos-externos?id=' . rawurlencode($baja['auto']['id']));
        self::assertSame(200, $detalle['status'], json_encode($detalle['json']));
        self::assertCount(2, $detalle['json']['historial']);
        self::assertEquals(13000000, $detalle['json']['historial'][0]['precio']);
        self::assertEquals(12000000, $detalle['json']['historial'][1]['precio']);
        self::assertSame(5, $detalle['json']['comparables']['cantidad']);
        self::assertSame('barato', $detalle['json']['auto']['etiqueta']['valor']);
        self::assertSame(7, $detalle['json']['dias_publicado']);
        foreach ($detalle['json']['comparables']['autos'] as $c) {
            self::assertNotSame($baja['auto']['id'], $c['id'], 'un auto no se compara consigo mismo');
            self::assertFalse($c['reservado']);
        }

        // El retirado conserva su historial, pero no tiene etiqueta
        $retirado = array_values(array_filter($j['novedades']['items'], fn($i) => $i['tipo'] === 'retirado'))[0];
        $d = $this->pedir('GET', '/api/autos-externos?id=' . rawurlencode($retirado['auto']['id']))['json'];
        self::assertSame('retirado', $d['auto']['estado']);
        self::assertNull($d['auto']['etiqueta']);
        self::assertNull($d['comparables']);
        self::assertCount(1, $d['historial'], 'el historial guarda cada cambio de precio');
    }

    public function testUnAutoQueVuelveNoEsNuevoYSeReactiva(): void
    {
        $fuente = self::unico('f');
        $marca = self::unico('M');
        $base = self::primeraSemana($marca);
        self::importar($fuente, '2026-11-02T06:00:00-03:00', $base);

        $sinUno = $base;
        unset($sinUno[0]);
        self::importar($fuente, '2026-11-09T06:00:00-03:00', array_values($sinUno));

        $this->ingresarComoUsuario();
        $id = $this->crearBusqueda($marca);
        self::importar($fuente, '2026-11-16T06:00:00-03:00', $base);

        $j = $this->pedir('GET', "/api/meta-busquedas/$id")['json'];
        self::assertSame(0, $j['novedades']['nuevos']);
        self::assertSame([], $this->pedir('GET', '/api/meta-busquedas/avisos')['json']['avisos']);
        $estados = array_unique(array_column($j['resultados']['items'], 'estado'));
        self::assertSame(['activo'], array_values($estados));
    }

    public function testUnaCorridaIncompletaNoRetiraNiAvisaNiSeUsa(): void
    {
        $fuente = self::unico('f');
        $marca = self::unico('M');
        $base = self::primeraSemana($marca);
        self::importar($fuente, '2026-11-02T06:00:00-03:00', $base);

        $this->ingresarComoUsuario();
        $id = $this->crearBusqueda($marca);

        $r = self::importar($fuente, '2026-11-09T06:00:00-03:00', array_slice($base, 0, 2), false);
        self::assertFalse($r['completo']);
        self::assertSame(0, $r['retirados']);
        self::assertSame(0, $r['avisos']);

        $j = $this->pedir('GET', "/api/meta-busquedas/$id")['json'];
        self::assertSame(7, $j['resultados']['total'], 'el reporte sigue usando la última corrida completa');
        self::assertSame([], $j['novedades']['items']);

        // Si la plataforma trae un auto nuevo en la incompleta, igual se avisa en la siguiente completa
        $conNuevo = array_merge($base, [self::coche('a9', $marca, ['precio' => 15100000])]);
        $parcial = array_merge(array_slice($base, 0, 2), [self::coche('a9', $marca, ['precio' => 15100000])]);
        self::importar($fuente, '2026-11-10T06:00:00-03:00', $parcial, false);
        $r = self::importar($fuente, '2026-11-16T06:00:00-03:00', $conNuevo);
        self::assertSame(1, $r['avisos']);
    }

    public function testDesconfiaDeUnaCorridaConMuchosMenosAutos(): void
    {
        $fuente = self::unico('f');
        $marca = self::unico('M');
        $base = self::primeraSemana($marca);
        self::importar($fuente, '2026-11-02T06:00:00-03:00', $base);

        $r = self::importar($fuente, '2026-11-09T06:00:00-03:00', array_slice($base, 0, 3));
        self::assertFalse($r['completo']);
        self::assertSame(0, $r['retirados']);
        self::assertNotEmpty($r['advertencias']);
    }

    public function testRechazaFechasRepetidasYDatosInvalidosNoDejanNada(): void
    {
        $fuente = self::unico('f');
        $marca = self::unico('M');
        $base = self::primeraSemana($marca);
        self::importar($fuente, '2026-11-02T06:00:00-03:00', $base);

        try {
            self::importar($fuente, '2026-11-02T06:00:00-03:00', $base);
            self::fail('Debería rechazar la misma fecha');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Ya hay una recolección', $e->getMessage());
        }

        self::assertCount(1, Entorno::documentos()->leer("recolecciones/$fuente")['corridas']);

        $conMalo = array_merge($base, [['id_externo' => 'x', 'marca' => $marca]]);
        $r = self::importar($fuente, '2026-11-09T06:00:00-03:00', $conMalo);
        self::assertFalse($r['completo'], 'un auto inválido hace que la corrida no sirva para retirar');
        self::assertSame(8, $r['autos']);
    }

    public function testIncluyeAutosDelPortalSinMezclarlosEnLasEtiquetas(): void
    {
        $fuente = self::unico('f');
        $marca = self::unico('M');
        self::importar($fuente, '2026-11-02T06:00:00-03:00', self::primeraSemana($marca));

        $propio = $this->vehiculoDisponible(['marca' => $marca, 'modelo' => 'Sandero', 'anio' => 2017, 'precio' => 1000000, 'kilometraje' => 90000]);
        $this->ingresarComoUsuario();

        $id = $this->crearBusqueda($marca);
        $j = $this->pedir('GET', "/api/meta-busquedas/$id")['json'];
        self::assertSame(8, $j['resultados']['total']);

        $portal = array_values(array_filter($j['resultados']['items'], fn($i) => $i['fuente'] === 'autocar'));
        self::assertCount(1, $portal);
        self::assertSame($propio, $portal[0]['id']);
        self::assertNull($portal[0]['etiqueta']);
        self::assertEquals(13000000, $j['destacados']['mas_barato']['auto']['precio'], 'el del portal no compite en los destacados');
    }

    public function testFiltrosOrdenYPaginacionDelListado(): void
    {
        $fuente = self::unico('f');
        $marca = self::unico('M');
        self::importar($fuente, '2026-11-02T06:00:00-03:00', self::primeraSemana($marca));
        $this->ingresarComoUsuario();
        $id = $this->crearBusqueda($marca);

        $precio = $this->pedir('GET', "/api/meta-busquedas/$id?orden=precio")['json']['resultados']['items'];
        self::assertEquals(13000000, $precio[0]['precio']);
        self::assertEquals(17000000, $precio[6]['precio']);

        $km = $this->pedir('GET', "/api/meta-busquedas/$id?orden=km&km_max=97000")['json']['resultados'];
        self::assertSame(2, $km['total']);

        $tope = $this->pedir('GET', "/api/meta-busquedas/$id?precio_max=14000000")['json'];
        self::assertSame(2, $tope['resultados']['total']);
        self::assertSame(7, self::deFuente($tope['referencia'], $fuente)['cantidad'], 'los filtros del listado no cambian la referencia');

        $barato = $this->pedir('GET', "/api/meta-busquedas/$id?etiqueta=barato")['json']['resultados'];
        foreach ($barato['items'] as $i) {
            self::assertSame('barato', $i['etiqueta']['valor']);
        }
        self::assertSame(0, $this->pedir('GET', "/api/meta-busquedas/$id?fuente=otra")['json']['resultados']['total']);
        self::assertSame([], $this->pedir('GET', "/api/meta-busquedas/$id?page=2")['json']['resultados']['items']);
    }

    public function testCriteriosGuardadosFiltranLosResultados(): void
    {
        $fuente = self::unico('f');
        $marca = self::unico('M');
        self::importar($fuente, '2026-11-02T06:00:00-03:00', self::primeraSemana($marca));
        $this->ingresarComoUsuario();
        $id = $this->crearBusqueda($marca, ['precio_max' => 14500000]);
        $j = $this->pedir('GET', "/api/meta-busquedas/$id")['json'];
        self::assertSame(3, $j['resultados']['total']);
        self::assertFalse(self::deFuente($j['referencia'], $fuente)['suficiente']);
        self::assertNull(self::deFuente($j['referencia'], $fuente)['mediana']);
        // Los comparables salen del modelo entero, aunque superen el precio máximo de la búsqueda
        self::assertSame('barato', $j['destacados']['mas_barato']['auto']['etiqueta']['valor']);
    }

    public function testMarcasYAutoInexistente(): void
    {
        $fuente = self::unico('f');
        $marca = self::unico('M');
        self::importar($fuente, '2026-11-02T06:00:00-03:00', self::primeraSemana($marca));
        $this->ingresarComoUsuario();

        $marcas = $this->pedir('GET', '/api/meta-busquedas/marcas')['json']['marcas'];
        $mia = array_values(array_filter($marcas, fn($m) => $m['marca'] === $marca))[0];
        self::assertSame(['Logan', 'Sandero'], $mia['modelos']);
        self::assertSame(404, $this->pedir('GET', '/api/autos-externos?id=kavak~no_existe~1')['status']);
        self::assertSame(404, $this->pedir('GET', '/api/autos-externos?id=../../x')['status']);
        self::assertSame(404, $this->pedir('GET', '/api/autos-externos')['status']);
    }

    private static function deFuente(array $referencia, string $fuente): array
    {
        foreach ($referencia as $r) {
            if ($r['fuente'] === $fuente) {
                return $r;
            }
        }
        self::fail("Sin referencia para $fuente");
    }
}
