<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Inspecciones\Checklist;
use AutoCar\Inspecciones\Estados;
use AutoCar\Inspecciones\Fuente;
use AutoCar\Inspecciones\Huella;
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
            Precio::desglose(130000),
        );
    }

    public function testLasPartesSiempreSumanElPrecio(): void
    {
        foreach ([120000, 140000, 99999, 1000] as $precio) {
            $d = Precio::desglose($precio);
            self::assertEqualsWithDelta($precio, $d['monto_mecanico'] + $d['monto_autocar'], 0.001);
            self::assertLessThanOrEqual($d['monto_autocar'], $d['monto_fondo']);
            self::assertGreaterThanOrEqual(0, $d['monto_mecanico']);
        }
    }

    public function testTopeDeLaCompensacion(): void
    {
        self::assertSame(390000.0, Precio::topeCompensacion(130000));
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
        self::assertTrue(Estados::enPlazoDeReclamo($firma, new DateTimeImmutable('2026-11-16 10:00')));
        self::assertFalse(Estados::enPlazoDeReclamo($firma, new DateTimeImmutable('2026-11-16 10:01')));
        self::assertFalse(Estados::enPlazoDeReclamo($firma, new DateTimeImmutable('2026-10-31 10:00')));
    }
}
