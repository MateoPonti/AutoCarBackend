<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/** Sin documentos de modelos (la base de prueba no tiene scraper), la ficha igual responde: "sin_datos". */
final class ComparadorTest extends ApiTestCase
{
    public function testUnaPublicacionSinDatosDeMercadoDaSinDatos(): void
    {
        $id = $this->pedir('GET', '/api/vehiculos')['json']['items'][0]['id'];
        $r = $this->pedir('GET', "/api/vehiculos/$id/comparacion");
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame($id, $r['json']['vehiculo_id']);
        self::assertContains($r['json']['comparacion']['etiqueta'], ['sin_datos', 'buen_precio', 'en_precio', 'caro']);
        self::assertArrayHasKey('criterio', $r['json']['comparacion']);
    }

    public function testUnaPublicacionInexistenteDa404(): void
    {
        $r = $this->pedir('GET', '/api/vehiculos/999999/comparacion');
        self::assertSame(404, $r['status']);
        self::assertSame('no_encontrado', $r['json']['error']);
    }
}
