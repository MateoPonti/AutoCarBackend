<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Pagos\Cobros;
use AutoCar\Pagos\MercadoPago;
use PHPUnit\Framework\TestCase;

/** Las partes puras del cobro con Mercado Pago (sin red ni base). */
final class MercadoPagoTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MP_ACTIVO');
        putenv('MP_ACCESS_TOKEN');
        putenv('FRONT_URL');
    }

    public function testElCuerpoDeLaOrdenVaComoPideMercadoPago(): void
    {
        $c = MercadoPago::cuerpoOrden(100000, 'Inspección precompra: Gol Trend', 'inspeccion-7', 'a@b.com', 'https://x/vuelta');
        self::assertSame('online', $c['type']);
        self::assertSame('manual', $c['processing_mode']);
        // El monto va como texto con dos decimales e igual a la suma de los ítems
        self::assertSame('100000.00', $c['total_amount']);
        self::assertSame('100000.00', $c['items'][0]['unit_price']);
        self::assertSame(1, $c['items'][0]['quantity']);
        self::assertSame('inspeccion-7', $c['external_reference']);
        self::assertSame('a@b.com', $c['payer']['email']);
        self::assertSame('https://x/vuelta', $c['config']['online']['success_url']);
        self::assertSame('approved', $c['config']['online']['auto_return']);
    }

    public function testElMontoConCentavosNoSeRedondeaMal(): void
    {
        self::assertSame('69999.50', MercadoPago::cuerpoOrden(69999.5, 't', 'r', 'e', 'v')['total_amount']);
    }

    public function testCadaStatusDeLaOrdenTieneSuEstado(): void
    {
        self::assertSame('pagado', MercadoPago::estadoDe('processed'));
        self::assertSame('devuelto', MercadoPago::estadoDe('refunded'));
        foreach (['canceled', 'expired', 'failed'] as $s) {
            self::assertSame('vencida', MercadoPago::estadoDe($s), $s);
        }
        foreach (['created', 'processing', 'action_required', ''] as $s) {
            self::assertSame('pendiente', MercadoPago::estadoDe($s), $s);
        }
    }

    public function testSoloCobraConElInterruptorPrendidoYElToken(): void
    {
        putenv('MP_ACTIVO=false');
        putenv('MP_ACCESS_TOKEN=TEST-x');
        self::assertFalse(MercadoPago::activo());
        putenv('MP_ACTIVO=true');
        self::assertTrue(MercadoPago::activo());
    }

    public function testConMercadoPagoApagadoNoHayCobroNiSeTocaLaBase(): void
    {
        putenv('MP_ACTIVO=false');
        self::assertNull(Cobros::de(1));
        self::assertFalse(Cobros::faltaPagar(1));
        self::assertNull(Cobros::iniciar(1, 100000));
        self::assertFalse(Cobros::aviso('ORD123'));
        Cobros::devolver(1); // no hace nada
        self::assertNull(Cobros::paraApi(null));
    }

    public function testVuelveAlDetalleDeLaInspeccionEnElPrimerFront(): void
    {
        putenv('FRONT_URL=https://autocar-zeta.vercel.app/, http://localhost:5500');
        self::assertSame('https://autocar-zeta.vercel.app/front/pages/talleres/verificacion.html?id=9&pago=vuelta', Cobros::vuelta(9));
    }

    public function testElLinkDePagoSoloSeMuestraSiFaltaPagar(): void
    {
        $fila = ['estado' => 'pagado', 'monto' => '100000.00', 'checkout_url' => 'https://mp/x', 'pagado_at' => '2026-11-18 15:00:00+00'];
        self::assertNull(Cobros::paraApi($fila)['checkout_url']);
        self::assertSame('https://mp/x', Cobros::paraApi(['estado' => 'pendiente'] + $fila)['checkout_url']);
        self::assertSame(100000.0, Cobros::paraApi($fila)['monto']);
    }
}
