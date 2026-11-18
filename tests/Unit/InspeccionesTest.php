<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Inspecciones\Checklist;
use AutoCar\Inspecciones\Estados;
use AutoCar\Inspecciones\Fuente;
use AutoCar\Inspecciones\Huella;
use AutoCar\Inspecciones\Patente;
use AutoCar\Inspecciones\Precio;
use AutoCar\Inspecciones\Ranking;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Reglas de negocio de las inspecciones que no necesitan base (PLAN.md, sección 7). */
final class InspeccionesTest extends TestCase
{
    private const FRONT = ['autocar-zeta.vercel.app'];

    // --- Fuente: de qué sitio es el link

    public function testDetectaLosSitiosPorElDominio(): void
    {
        self::assertSame('mercadolibre', Fuente::detectar('https://auto.mercadolibre.com.ar/MLA-123-toyota-corolla-_JM')['fuente']);
        self::assertSame('mercadolibre', Fuente::detectar('https://articulo.mercadolibre.com.ar/MLA-1')['fuente']);
        self::assertSame('kavak', Fuente::detectar('https://www.kavak.com/ar/usado/toyota-corolla-2020')['fuente']);
        self::assertSame('autocosmos', Fuente::detectar('https://www.autocosmos.com.ar/auto/usado/ford/ka/x')['fuente']);
        self::assertSame('otro', Fuente::detectar('https://www.otrositio.com/auto/1')['fuente']);
    }

    public function testLaClaveDelAvisoEsLaMismaConCualquierLink(): void
    {
        $ml = Fuente::clave('https://auto.mercadolibre.com.ar/MLA-123-toyota-corolla-_JM', 'mercadolibre', null);
        self::assertSame('mercadolibre:MLA123', $ml);
        self::assertSame($ml, Fuente::clave('https://articulo.mercadolibre.com.ar/MLA123?tracking=x', 'mercadolibre', null));
        self::assertSame(
            Fuente::clave('https://www.kavak.com/ar/usado/corolla-2020/', 'kavak', null),
            Fuente::clave('HTTPS://kavak.com/ar/usado/corolla-2020?utm_source=x#fotos', 'kavak', null),
        );
        self::assertSame('autocar:7', Fuente::clave('https://front.test/vehiculo.html?id=7&x=1', 'autocar', 7));
        self::assertNotSame(Fuente::clave('https://kavak.com/ar/a', 'kavak', null), Fuente::clave('https://kavak.com/ar/b', 'kavak', null));
    }

    public function testUnDominioParecidoNoEngana(): void
    {
        self::assertSame('otro', Fuente::detectar('https://mercadolibre.com.ar.estafa.com/MLA-1')['fuente']);
        self::assertSame('otro', Fuente::detectar('https://falsokavak.com/auto')['fuente']);
    }

    public function testLinkDeAutoCarTraeElIdDelVehiculo(): void
    {
        self::assertSame(
            ['fuente' => 'autocar', 'vehiculo_id' => 7],
            Fuente::detectar('https://autocar-zeta.vercel.app/front/vehiculo.html?id=7', self::FRONT),
        );
        self::assertSame('otro', Fuente::detectar('https://autocar-zeta.vercel.app/front/vehiculo.html?id=abc', self::FRONT)['fuente']);
        self::assertSame('otro', Fuente::detectar('https://autocar-zeta.vercel.app/front/catalogo.html?id=7', self::FRONT)['fuente']);
    }

    public function testRechazaLoQueNoEsUnLinkWeb(): void
    {
        self::assertNull(Fuente::detectar(''));
        self::assertNull(Fuente::detectar('no es un link'));
        self::assertNull(Fuente::detectar('javascript:alert(1)'));
        self::assertNull(Fuente::detectar('ftp://kavak.com/auto'));
        self::assertNull(Fuente::detectar('https://kavak.com/' . str_repeat('a', Fuente::MAX_URL)));
    }

    public function testHostsSaleDeFrontUrl(): void
    {
        self::assertSame(['localhost', 'autocar-zeta.vercel.app'], Fuente::hosts('http://localhost:5500, https://autocar-zeta.vercel.app'));
        self::assertSame([], Fuente::hosts(''));
    }

    // --- Precio: desglose

    public function testDesgloseDeLaInspeccionDeReferencia(): void
    {
        self::assertSame(
            ['precio' => 130000.0, 'monto_mecanico' => 100000.0, 'monto_autocar' => 30000.0, 'monto_fondo' => 10000.0],
            Precio::desglose(130000, 23, 33.3333),
        );
    }

    public function testLasPartesSiempreSumanElPrecio(): void
    {
        foreach ([120000, 140000, 99999, 1000] as $precio) {
            $d = Precio::desglose($precio, 23, 33.3333);
            self::assertEqualsWithDelta($precio, $d['monto_mecanico'] + $d['monto_autocar'], 0.001);
            self::assertLessThanOrEqual($d['monto_autocar'], $d['monto_fondo']);
            self::assertGreaterThanOrEqual(0, $d['monto_mecanico']);
        }
    }

    public function testTopeDeLaCompensacion(): void
    {
        self::assertSame(390000.0, Precio::topeCompensacion(130000, 3));
        self::assertSame(200000.0, Precio::topeCompensacion(100000, 2));
    }

    public function testDesgloseConElPrecioSugeridoDel14DeNoviembre(): void
    {
        self::assertSame(
            ['precio' => 100000.0, 'monto_mecanico' => 77000.0, 'monto_autocar' => 23000.0, 'monto_fondo' => 8000.0],
            Precio::desglose(100000, 23, 33.3333),
        );
    }

    public function testSiElAdminCambiaLaComisionCambiaElDesglose(): void
    {
        $d = Precio::desglose(100000, 20, 50);
        self::assertSame(80000.0, $d['monto_mecanico']);
        self::assertSame(20000.0, $d['monto_autocar']);
        self::assertSame(10000.0, $d['monto_fondo']);
    }

    // --- Patente

    public function testLaPatenteSeGuardaSinEspaciosYEnMayusculas(): void
    {
        self::assertSame('AB123CD', Patente::normalizar(' ab 123 cd '));
        self::assertSame('ABC123', Patente::normalizar('abc-123'));
        self::assertSame('AB123CD', Patente::normalizar('AB.123.CD'));
    }

    public function testUnaPatenteConOtroFormatoNoSirve(): void
    {
        foreach (['', 'A123BCD', 'ABCD123', 'AB1234C', '123ABC', 'patente', null, 123456] as $mala) {
            self::assertNull(Patente::normalizar($mala), var_export($mala, true));
        }
    }

    public function testLaPatenteEntraEnLaHuella(): void
    {
        $items = ['motor' => ['ok', null]];
        $sin = Huella::calcular(1, $items, 'recomendable', null, 'Ana', 'Taller', '2026-11-14T10:00:00Z');
        $con = Huella::calcular(1, $items, 'recomendable', null, 'Ana', 'Taller', '2026-11-14T10:00:00Z', 'AB123CD');
        $otra = Huella::calcular(1, $items, 'recomendable', null, 'Ana', 'Taller', '2026-11-14T10:00:00Z', 'AB123CE');
        self::assertNotSame($sin, $con);
        self::assertNotSame($con, $otra);
        // Las firmadas antes de la patente dan la misma huella que antes
        self::assertSame($sin, Huella::calcular(1, $items, 'recomendable', null, 'Ana', 'Taller', '2026-11-14T10:00:00Z', null));
    }

    // --- Ranking

    public function testConMenosDeTresFirmadasEsNuevoEnLaRed(): void
    {
        self::assertNull(Ranking::puntaje(0, 0));
        self::assertNull(Ranking::puntaje(2, 0));
    }

    public function testPuntajeSegunLosReclamosQueProcedieron(): void
    {
        self::assertSame(5.0, Ranking::puntaje(10, 0));
        self::assertSame(4.0, Ranking::puntaje(10, 1));
        self::assertSame(0.0, Ranking::puntaje(10, 5));
        self::assertSame(0.0, Ranking::puntaje(3, 3)); // nunca negativo
    }

    public function testOrdenPorPuntajeDespuesPorCantidadYLosNuevosAlFinal(): void
    {
        $orden = Ranking::ordenar([
            ['id' => 'nuevo', 'firmadas' => 1, 'procedentes' => 0],
            ['id' => 'pocas', 'firmadas' => 4, 'procedentes' => 0],
            ['id' => 'reclamado', 'firmadas' => 20, 'procedentes' => 2],
            ['id' => 'muchas', 'firmadas' => 30, 'procedentes' => 0],
        ]);
        self::assertSame(['muchas', 'pocas', 'reclamado', 'nuevo'], array_column($orden, 'id'));
        self::assertSame(4.0, $orden[2]['puntaje']);
    }

    public function testLaEspecialidadSaleDelModeloYSiNoDeLaMarcaYCuentaLosReclamos(): void
    {
        $e = Ranking::especialidad(['modelo' => 4, 'reclamos_modelo' => 1, 'marca' => 9, 'reclamos_marca' => 0]);
        self::assertSame(['en' => 'modelo', 'revisados' => 4, 'reclamos' => 1, 'puntaje' => 2.5, 'buena' => true], $e);

        $e = Ranking::especialidad(['modelo' => 0, 'reclamos_modelo' => 0, 'marca' => 2, 'reclamos_marca' => 1]);
        self::assertSame('marca', $e['en']);
        self::assertSame(0.0, $e['puntaje']);
        self::assertFalse($e['buena']);

        self::assertNull(Ranking::especialidad(['modelo' => 0, 'reclamos_modelo' => 0, 'marca' => 0, 'reclamos_marca' => 0]));
    }

    public function testPorEspecialidadBuenaEnElModeloDespuesLaMarcaDespuesSinExperienciaYLaMalaAlFinal(): void
    {
        $esp = static fn(int $modelo, int $reclamosModelo, int $marca = 0, int $reclamosMarca = 0): ?array =>
            Ranking::especialidad(['modelo' => $modelo, 'reclamos_modelo' => $reclamosModelo, 'marca' => $marca, 'reclamos_marca' => $reclamosMarca]);
        // En el orden del ranking general
        $orden = Ranking::porEspecialidad([
            ['id' => 'mejor-ranking-sin-experiencia', 'especialidad' => $esp(0, 0)],
            ['id' => 'mala-en-el-modelo', 'especialidad' => $esp(3, 2)],
            ['id' => 'buena-en-la-marca', 'especialidad' => $esp(0, 0, 6, 0)],
            ['id' => 'modelo-pocos', 'especialidad' => $esp(2, 0)],
            ['id' => 'modelo-muchos', 'especialidad' => $esp(7, 0)],
            ['id' => 'modelo-con-un-reclamo', 'especialidad' => $esp(8, 1)],
            ['id' => 'otro-sin-experiencia', 'especialidad' => null],
        ]);
        self::assertSame([
            'modelo-muchos', 'modelo-pocos', 'modelo-con-un-reclamo', 'buena-en-la-marca',
            'mejor-ranking-sin-experiencia', 'otro-sin-experiencia', 'mala-en-el-modelo',
        ], array_column($orden, 'id'));
    }

    // --- Checklist

    private static function checklistCompleto(): array
    {
        return array_map(static fn(string $c): array => ['codigo' => $c, 'resultado' => 'ok'], array_keys(Checklist::ITEMS));
    }

    public function testChecklistCompletoEsValido(): void
    {
        [$items, $error] = Checklist::validarItems(self::checklistCompleto(), true);
        self::assertNull($error);
        self::assertCount(10, $items);
    }

    public function testParaFirmarTienenQueEstarLosDiezItems(): void
    {
        $parcial = array_slice(self::checklistCompleto(), 0, 8);
        self::assertNull(Checklist::validarItems($parcial, false)[1]); // a medias se puede guardar
        self::assertStringContainsString('kilometraje, identificacion', Checklist::validarItems($parcial, true)[1]);
    }

    public function testUnaObservacionSinNotaNoSeAcepta(): void
    {
        $items = [['codigo' => 'carroceria', 'resultado' => 'observado', 'nota' => '  ']];
        self::assertSame('Explicá qué encontraste en "carroceria".', Checklist::validarItems($items, false)[1]);
        $items[0]['nota'] = 'Golpe en el zócalo derecho';
        self::assertSame(['carroceria' => ['observado', 'Golpe en el zócalo derecho']], Checklist::validarItems($items, false)[0]);
    }

    public function testItemsInvalidos(): void
    {
        self::assertNotNull(Checklist::validarItems('texto', false)[1]);
        self::assertNotNull(Checklist::validarItems([['codigo' => 'radio', 'resultado' => 'ok']], false)[1]);
        self::assertNotNull(Checklist::validarItems([['codigo' => 'motor', 'resultado' => 'maso']], false)[1]);
        self::assertNotNull(Checklist::validarItems([['codigo' => 'motor', 'resultado' => 'ok'], ['codigo' => 'motor', 'resultado' => 'ok']], false)[1]);
    }

    public function testConclusion(): void
    {
        self::assertNull(Checklist::validarConclusion('con_reparos', 'Cambiar cubiertas'));
        self::assertNotNull(Checklist::validarConclusion('excelente', ''));
        self::assertNotNull(Checklist::validarConclusion('recomendable', str_repeat('a', Checklist::MAX_RESUMEN + 1)));
    }

    public function testCadaFallaNecesitaFotoParaFirmar(): void
    {
        $items = ['motor' => ['falla', 'Pierde aceite'], 'frenos' => ['observado', 'Pastillas al 30 %'], 'luces' => ['ok', null]];
        self::assertStringContainsString('motor', (string)Checklist::faltanFotos($items, []));
        self::assertStringNotContainsString('frenos', (string)Checklist::faltanFotos($items, [])); // "observado": opcional
        self::assertNull(Checklist::faltanFotos($items, ['motor']));
        self::assertNull(Checklist::faltanFotos(['luces' => ['ok', null]], []));
    }

    // --- Huella

    public function testHuellaNoDependeDelOrdenDeLosItemsPeroSiDelContenido(): void
    {
        $args = ['recomendable', null, 'Raúl Ferreyra', 'Taller Ferreyra', '2026-11-08T10:00:00-03:00'];
        $a = Huella::calcular(1, ['motor' => ['ok', null], 'frenos' => ['falla', 'Pastillas gastadas']], ...$args);
        $b = Huella::calcular(1, ['frenos' => ['falla', 'Pastillas gastadas'], 'motor' => ['ok', null]], ...$args);
        $c = Huella::calcular(1, ['frenos' => ['ok', null], 'motor' => ['ok', null]], ...$args);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
    }

    // --- Estados y plazo de reclamo

    public function testTransicionesPermitidas(): void
    {
        self::assertTrue(Estados::puedePasar('pedida', 'aceptada'));
        self::assertTrue(Estados::puedePasar('pedida', 'cancelada'));
        self::assertTrue(Estados::puedePasar('aceptada', 'firmada'));
        self::assertFalse(Estados::puedePasar('pedida', 'firmada'));   // no se firma sin aceptar
        self::assertFalse(Estados::puedePasar('aceptada', 'cancelada')); // aceptada ya no se cancela
        self::assertFalse(Estados::puedePasar('firmada', 'aceptada'));   // firmada no cambia más
    }

    public function testPagoSegunElEstado(): void
    {
        self::assertSame('reembolsado', Estados::pagoAl('rechazada'));
        self::assertSame('reembolsado', Estados::pagoAl('cancelada'));
        self::assertSame('liberado', Estados::pagoAl('firmada'));
        self::assertNull(Estados::pagoAl('aceptada'));
    }

    public function testPlazoDeQuinceDiasParaReclamar(): void
    {
        $firma = new DateTimeImmutable('2026-11-01 10:00');
        self::assertTrue(Estados::enPlazoDeReclamo($firma, new DateTimeImmutable('2026-11-16 10:00'), 15));
        self::assertFalse(Estados::enPlazoDeReclamo($firma, new DateTimeImmutable('2026-11-16 10:01'), 15));
        self::assertFalse(Estados::enPlazoDeReclamo($firma, new DateTimeImmutable('2026-10-31 10:00'), 15));
    }

    public function testElPlazoEsElQueTeniaLaInspeccion(): void
    {
        $firma = new DateTimeImmutable('2026-11-01 10:00');
        self::assertFalse(Estados::enPlazoDeReclamo($firma, new DateTimeImmutable('2026-11-12 10:00'), 10));
        self::assertTrue(Estados::enPlazoDeReclamo($firma, new DateTimeImmutable('2026-11-12 10:00'), 15));
    }
}
