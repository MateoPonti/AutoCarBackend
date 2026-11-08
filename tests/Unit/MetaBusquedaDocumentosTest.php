<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\MetaBusqueda\Avisos;
use AutoCar\MetaBusqueda\Busqueda;
use AutoCar\MetaBusqueda\Catalogo;
use AutoCar\MetaBusqueda\DocumentosArchivos;
use AutoCar\MetaBusqueda\DocumentosMemoria;
use AutoCar\MetaBusqueda\Importador;
use AutoCar\MetaBusqueda\Mercado;
use AutoCar\MetaBusqueda\Reporte;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** El metabuscador sobre la base de documentos (en memoria): importar corridas simuladas y armar el reporte. */
final class MetaBusquedaDocumentosTest extends TestCase
{
    private const SEMANA_1 = '2026-11-02T06:00:00-03:00';
    private const SEMANA_2 = '2026-11-09T06:00:00-03:00';
    private const SEMANA_3 = '2026-11-16T06:00:00-03:00';

    private DocumentosMemoria $docs;

    protected function setUp(): void
    {
        $this->docs = new DocumentosMemoria();
    }

    private static function coche(string $id, array $cambios = []): array
    {
        return $cambios + [
            'id_externo' => $id, 'marca' => 'Renault', 'modelo' => 'Sandero', 'version' => '1.6', 'anio' => 2017, 'km' => 100000,
            'transmision' => 'manual', 'precio' => 15000000, 'precio_contado' => null, 'moneda' => 'ARS',
            'reservado' => false, 'ubicacion' => 'Buenos Aires', 'url' => "https://plataforma.test/$id",
        ];
    }

    /** Siete autos del modelo con precios escalonados y uno de otro modelo parecido. */
    private static function primeraSemana(): array
    {
        $autos = [];
        foreach ([13, 14, 14.5, 15, 15.5, 16, 17] as $i => $millones) {
            $autos[] = self::coche('a' . ($i + 1), ['precio' => (int)($millones * 1000000), 'km' => 95000 + $i * 2000]);
        }
        $autos[] = self::coche('otro', ['modelo' => 'Logan', 'precio' => 15000000]);
        return $autos;
    }

    private function importar(string $fecha, array $autos, bool $completo = true, string $fuente = 'kavak'): array
    {
        return (new Importador($this->docs))->importar(['fuente' => $fuente, 'fecha' => $fecha, 'completo' => $completo, 'autos' => $autos]);
    }

    private function busqueda(array $cambios = [], int $usuario = 7): array
    {
        [$d] = Busqueda::validar($cambios + ['marca' => 'Renault', 'modelo' => 'Sandero']);
        return Busqueda::crear($this->docs, $usuario, $d);
    }

    private function reporte(array $b, array $get = []): array
    {
        return (new Reporte(new Mercado($this->docs)))->construir($b, $get);
    }

    public function testReporteConUnaSolaCorrida(): void
    {
        $this->importar(self::SEMANA_1, self::primeraSemana());
        $j = $this->reporte($this->busqueda());

        self::assertFalse($j['novedades']['disponible']);
        self::assertSame(7, $j['resultados']['total']);
        self::assertSame(7, $j['referencia'][0]['cantidad']);
        self::assertEquals(15000000, $j['referencia'][0]['mediana']);
        self::assertEquals(13000000, $j['destacados']['mas_barato']['auto']['precio']);
        self::assertSame('barato', $j['destacados']['mas_barato']['auto']['etiqueta']['valor']);
        self::assertSame(['Logan'], array_column($j['otros_modelos'], 'modelo'));
    }

    public function testDosSemanasGeneranNovedadesAvisosYEstados(): void
    {
        $semana1 = self::primeraSemana();
        $this->importar(self::SEMANA_1, $semana1);
        $b = $this->busqueda();

        $semana2 = $semana1;
        $semana2[0]['precio'] = 12000000;               // a1 baja
        $semana2[1]['precio'] = 14500000;               // a2 sube
        unset($semana2[2]);                             // a3 se retira
        $semana2[3]['precio'] = null;                   // a4 se reserva
        $semana2[3]['reservado'] = true;
        $semana2[] = self::coche('a8', ['precio' => 15200000]); // a8 es nuevo
        $r = $this->importar(self::SEMANA_2, array_values($semana2));
        self::assertTrue($r['completo']);
        self::assertSame(1, $r['retirados']);
        self::assertSame(4, $r['avisos'], 'nuevo, bajó, reservado y retirado; una suba no avisa');

        $j = $this->reporte($b);
        self::assertSame([1, 1, 1, 1, 1, 3], [
            $j['novedades']['nuevos'], $j['novedades']['bajaron'], $j['novedades']['subieron'],
            $j['novedades']['reservados'], $j['novedades']['retirados'], $j['novedades']['sin_cambios'],
        ]);
        self::assertSame(7, $j['resultados']['total']);
        self::assertSame(6, $j['referencia'][0]['cantidad'], 'el reservado no cuenta en el precio típico');

        $avisos = Avisos::delUsuario($this->docs, 7, true);
        $tipos = array_column($avisos, 'tipo');
        sort($tipos);
        self::assertSame(['bajo', 'nuevo', 'reservado', 'retirado'], $tipos);
        Busqueda::marcarVistos($this->docs, 7, $b['id']);
        self::assertSame([], Avisos::delUsuario($this->docs, 7, true));
        self::assertCount(4, Avisos::delUsuario($this->docs, 7));
        self::assertSame([], Avisos::delUsuario($this->docs, 8), 'otro usuario no ve avisos ajenos');

        $baja = array_values(array_filter($j['novedades']['items'], fn($i) => $i['tipo'] === 'bajo'))[0];
        $detalle = (new Reporte(new Mercado($this->docs)))->detalle($baja['auto']['id']);
        self::assertCount(2, $detalle['historial']);
        self::assertEquals(12000000, $detalle['historial'][1]['precio']);
        self::assertSame(5, $detalle['comparables']['cantidad']);
        self::assertSame(7, $detalle['dias_publicado']);

        $retirado = array_values(array_filter($j['novedades']['items'], fn($i) => $i['tipo'] === 'retirado'))[0];
        $d = (new Reporte(new Mercado($this->docs)))->detalle($retirado['auto']['id']);
        self::assertSame('retirado', $d['auto']['estado']);
        self::assertNull($d['comparables']);
    }

    public function testReimportarNoDuplicaAvisos(): void
    {
        $this->importar(self::SEMANA_1, self::primeraSemana());
        $this->busqueda();
        $segunda = self::primeraSemana();
        $segunda[0]['precio'] = 12000000;
        $this->importar(self::SEMANA_2, $segunda);
        self::assertCount(1, Avisos::delUsuario($this->docs, 7));

        // Simula que falló antes de registrar la corrida: se vuelve a importar el mismo archivo
        $recolecciones = $this->docs->leer('recolecciones/kavak');
        array_pop($recolecciones['corridas']);
        $this->docs->escribir(['recolecciones/kavak' => $recolecciones]);
        $this->importar(self::SEMANA_2, $segunda);
        self::assertCount(1, Avisos::delUsuario($this->docs, 7));
    }

    public function testUnAutoQueVuelveNoEsNuevoYSeReactiva(): void
    {
        $base = self::primeraSemana();
        $this->importar(self::SEMANA_1, $base);
        $sinUno = $base;
        unset($sinUno[0]);
        $this->importar(self::SEMANA_2, array_values($sinUno));
        $b = $this->busqueda();
        $this->importar(self::SEMANA_3, $base);

        $j = $this->reporte($b);
        self::assertSame(0, $j['novedades']['nuevos']);
        self::assertSame([], Avisos::delUsuario($this->docs, 7));
        self::assertSame(['activo'], array_values(array_unique(array_column($j['resultados']['items'], 'estado'))));
    }

    public function testUnaCorridaIncompletaNoRetiraNiAvisaNiSeUsa(): void
    {
        $base = self::primeraSemana();
        $this->importar(self::SEMANA_1, $base);
        $b = $this->busqueda();

        $r = $this->importar(self::SEMANA_2, array_slice($base, 0, 2), false);
        self::assertFalse($r['completo']);
        self::assertSame(0, $r['retirados']);
        self::assertSame(7, $this->reporte($b)['resultados']['total'], 'el reporte sigue usando la última corrida completa');

        // Un auto que apareció primero en una incompleta igual es "nuevo" en la siguiente completa
        $nuevo = self::coche('a9', ['precio' => 15100000]);
        $this->importar('2026-11-10T06:00:00-03:00', array_merge(array_slice($base, 0, 2), [$nuevo]), false);
        self::assertSame(1, $this->importar(self::SEMANA_3, array_merge($base, [$nuevo]))['avisos']);
    }

    public function testDesconfiaDeUnaCorridaConMuchosMenosAutosYRechazaFechasRepetidas(): void
    {
        $base = self::primeraSemana();
        $this->importar(self::SEMANA_1, $base);
        try {
            $this->importar(self::SEMANA_1, $base);
            self::fail('Debería rechazar la misma fecha');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Ya hay una recolección', $e->getMessage());
        }
        self::assertCount(1, $this->docs->leer('recolecciones/kavak')['corridas']);

        $r = $this->importar(self::SEMANA_2, array_slice($base, 0, 3));
        self::assertFalse($r['completo']);
        self::assertNotEmpty($r['advertencias']);

        $r = $this->importar(self::SEMANA_3, array_merge($base, [['id_externo' => 'x', 'marca' => 'Renault']]));
        self::assertFalse($r['completo'], 'un auto inválido hace que la corrida no sirva para retirar');
        self::assertSame(8, $r['autos']);
    }

    public function testSoloEscribeLosModelosQueCambiaron(): void
    {
        $base = self::primeraSemana();
        $this->importar(self::SEMANA_1, $base);
        $antes = $this->docs->escrituras;
        $r = $this->importar(self::SEMANA_2, $base, false); // incompleta y sin cambios de precio: solo cambia ultima_vez
        self::assertSame(2, $r['modelos_escritos']);
        $base[0]['precio'] = 1;
        $this->importar(self::SEMANA_3, array_slice($base, 0, 1), false);
        self::assertSame(1, $this->importar('2026-11-17T06:00:00-03:00', [self::coche('z', ['modelo' => 'Kwid'])], false)['modelos_escritos']);
        self::assertGreaterThan($antes, $this->docs->escrituras);
    }

    public function testDosFuentesDelMismoModeloVanAlMismoDocumento(): void
    {
        $this->importar(self::SEMANA_1, self::primeraSemana());
        $this->importar(self::SEMANA_1, [self::coche('MLA123', ['marca' => 'RENAULT', 'modelo' => 'sandero'])], true, 'mercadolibre');
        $doc = Catalogo::leer($this->docs, 'renault_sandero');
        self::assertCount(8, $doc['autos']);
        $ml = Catalogo::fila($doc, $doc['autos']['mercadolibre~MLA123']);
        self::assertSame('https://auto.mercadolibre.com.ar/MLA-123-_JM', $ml['url'], 'la url de MercadoLibre se arma con el id');

        $j = $this->reporte($this->busqueda());
        self::assertSame(['kavak', 'mercadolibre'], array_column($j['referencia'], 'fuente'));
        self::assertSame(['Renault' => ['Logan', 'Sandero']], array_column((new Mercado($this->docs))->marcasYModelos(), 'modelos', 'marca'));
    }

    public function testUnModeloGrandeSeParteEnVariosDocumentos(): void
    {
        $modelo = ['clave' => 'renault_sandero', 'marca' => 'Renault', 'modelo' => 'Sandero', 'autos' => []];
        for ($i = 0; $i < 4000; $i++) {
            Catalogo::registrar($modelo, 'mercadolibre', Importador::validarAuto(self::coche("MLA$i", ['version' => str_repeat('x', 100)])), '2026-11-02T06:00:00-03:00', true);
        }
        $documentos = Catalogo::documentos($modelo);
        self::assertGreaterThan(1, count($documentos));
        foreach ($documentos as $campos) {
            self::assertLessThan(1024 * 1024, strlen($campos['autos_json']), 'cada parte entra en un documento de Firestore');
        }
        $this->docs->escribir($documentos);
        self::assertCount(4000, Catalogo::leer($this->docs, 'renault_sandero')['autos']);
    }

    public function testBusquedasPorUsuarioYLimites(): void
    {
        $b = $this->busqueda();
        self::assertNull(Busqueda::delUsuarioPorId($this->docs, 8, $b['id']), 'otro usuario no la ve');
        self::assertNull(Busqueda::actualizar($this->docs, 8, $b['id'], ['modelo' => 'X']));
        self::assertFalse(Busqueda::eliminar($this->docs, 8, $b['id']));
        self::assertSame(1, Busqueda::cantidad($this->docs, 7));

        self::assertTrue(Busqueda::eliminar($this->docs, 7, $b['id']));
        self::assertNotSame($b['id'], $this->busqueda()['id'], 'los ids no se reusan');
        self::assertCount(1, Busqueda::todas($this->docs));
    }

    public function testIdsDeAutosYRutasDeArchivos(): void
    {
        self::assertSame(['kavak', 'renault_sandero', 'a~1'], Catalogo::partirIdPublico('kavak~renault_sandero~a~1'));
        self::assertNull(Catalogo::partirIdPublico('kavak~../x~1'));
        self::assertNull(Catalogo::partirIdPublico('99'));
        self::assertNull((new Mercado($this->docs))->auto('kavak~no_existe~1'));

        $this->expectException(InvalidArgumentException::class);
        (new DocumentosArchivos(sys_get_temp_dir()))->leer('../../etc/passwd');
    }

    /** Las tablas del metabuscador se fueron a Firestore: que nadie las vuelva a agregar a Neon sin darse cuenta. */
    public function testElEsquemaDeNeonNoTieneLasTablasDelMetabuscador(): void
    {
        $esquema = (string)file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        foreach (['fuente', 'recoleccion', 'auto_externo', 'auto_foto_semanal', 'busqueda_guardada', 'coincidencia'] as $tabla) {
            self::assertDoesNotMatchRegularExpression("/CREATE TABLE IF NOT EXISTS $tabla\\b/", $esquema, "$tabla va en Firestore (ver docs/arquitectura-scraper.md)");
        }
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/database/migracion-meta-busqueda.sql');
    }
}
