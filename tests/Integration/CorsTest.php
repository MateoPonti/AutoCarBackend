<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/** La API de prueba se levanta con FRONT_URL=http://front.test (ver Entorno). */
final class CorsTest extends ApiTestCase
{
    public function testElFrontPermitidoRecibeLasCabeceras(): void
    {
        $r = $this->pedir('GET', '/api/marcas', null, ['Origin' => 'http://front.test']);
        self::assertSame('http://front.test', $r['headers']['access-control-allow-origin'] ?? null);
        self::assertSame('true', $r['headers']['access-control-allow-credentials'] ?? null);
    }

    public function testOtroOrigenNoRecibeCabecerasCors(): void
    {
        $r = $this->pedir('GET', '/api/marcas', null, ['Origin' => 'https://sitio-malicioso.example']);
        self::assertArrayNotHasKey('access-control-allow-origin', $r['headers']);
    }

    public function testPreflightResponde204(): void
    {
        $r = $this->pedir('OPTIONS', '/api/admin/vehiculos', null, [
            'Origin' => 'http://front.test', 'Access-Control-Request-Method' => 'POST',
        ]);
        self::assertSame(204, $r['status']);
        self::assertStringContainsString('X-CSRF-Token', $r['headers']['access-control-allow-headers'] ?? '');
    }
}
