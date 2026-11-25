<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Catalogo\Verificacion;
use PHPUnit\Framework\TestCase;

final class VerificacionTest extends TestCase
{
    public function testUnAvisoSigueVerificadoSiLaUltimaFueRecomendableYNoCambioElAuto(): void
    {
        $inspeccion = ['conclusion' => 'recomendable', 'anio' => 2017, 'kilometraje' => 85000];
        self::assertTrue(Verificacion::sigueIgual($inspeccion, ['anio' => 2017, 'km' => 85000]));
        // El vendedor cambió los km o el año: deja de estar verificado
        self::assertFalse(Verificacion::sigueIgual($inspeccion, ['anio' => 2017, 'km' => 60000]));
        self::assertFalse(Verificacion::sigueIgual($inspeccion, ['anio' => 2018, 'km' => 85000]));
        // Con reparos o no recomendable no es "verificado"
        self::assertFalse(Verificacion::sigueIgual(['conclusion' => 'con_reparos'] + $inspeccion, ['anio' => 2017, 'km' => 85000]));
        // Un dato que falta no lo invalida
        self::assertTrue(Verificacion::sigueIgual(['kilometraje' => null] + $inspeccion, ['anio' => 2017, 'km' => 85000]));
    }

    public function testLaFechaSaleEnIso8601(): void
    {
        self::assertSame('2026-11-20T13:00:05+00:00', Verificacion::fecha('2026-11-20 13:00:05+00'));
    }
}
