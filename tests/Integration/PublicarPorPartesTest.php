<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/**
 * Publicar por partes: el vendedor crea la publicación con marca, modelo y año, la completa de a poco
 * (datos con PUT, fotos con POST .../fotos) y el moderador no la puede aprobar hasta que esté completa.
 */
final class PublicarPorPartesTest extends ApiTestCase
{
    private const PRIMER_PASO = ['marca' => 'Volkswagen', 'modelo' => 'Gol Trend', 'anio' => '2018'];
    private const RESTO = [
        'precio' => 9800000, 'kilometraje' => 85000, 'combustible' => 'nafta', 'transmision' => 'manual',
        'condicion' => 'muy_bueno', 'localidad' => 'Mercedes',
    ];

    public function testSeCreaConLoMinimoYSeCompletaDeAPoco(): void
    {
        $vendedor = $this->ingresarComoUsuario();

        // 1. Solo con marca, modelo y año: se crea pendiente e incompleta
        $r = $this->pedirFormulario('/api/mis-vehiculos', self::PRIMER_PASO);
        self::assertSame(201, $r['status'], json_encode($r['json']));
        $v = $r['json']['vehiculo'];
        $id = $v['id'];
        self::assertSame('pendiente', $v['estado']);
        self::assertFalse($v['completa']);
        self::assertNull($v['precio']);
        self::assertSame(['precio', 'kilometraje', 'combustible', 'transmisión', 'estado general', 'localidad', 'fotos'], $v['faltan']);

        // 2. Sin marca, modelo o año no se crea
        $sinAnio = $this->pedirFormulario('/api/mis-vehiculos', ['marca' => 'Fiat', 'modelo' => 'Uno']);
        self::assertSame(422, $sinAnio['status']);
        self::assertArrayHasKey('anio', $sinAnio['json']['errores']);

        // 3. Un dato mal cargado da error aunque sea opcional en este paso
        $mal = $this->pedir('PUT', "/api/mis-vehiculos/$id", ['precio' => 10]);
        self::assertSame(422, $mal['status']);
        self::assertArrayHasKey('precio', $mal['json']['errores']);

        // 4. Completa una parte: lo que ya tenía se mantiene
        $r = $this->pedir('PUT', "/api/mis-vehiculos/$id", ['precio' => 9800000, 'kilometraje' => 85000]);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame('Gol Trend', $r['json']['vehiculo']['modelo']);
        self::assertEquals(9800000, $r['json']['vehiculo']['precio']);
        self::assertNotContains('precio', $r['json']['vehiculo']['faltan']);

        // 5. El moderador la ve en "incompletas" y no la puede aprobar
        $this->ingresarComoAdmin();
        $incompletas = $this->pedir('GET', '/api/admin/vehiculos?estado=pendiente&completa=0');
        self::assertContains($id, array_column($incompletas['json']['items'], 'id'));
        $completas = $this->pedir('GET', '/api/admin/vehiculos?estado=pendiente&completa=1');
        self::assertNotContains($id, array_column($completas['json']['items'], 'id'));
        $aprobar = $this->pedir('PATCH', "/api/admin/vehiculos/$id/estado", ['estado' => 'disponible']);
        self::assertSame(409, $aprobar['status']);
        self::assertSame('incompleta', $aprobar['json']['error']);
        self::assertStringContainsString('fotos', $aprobar['json']['mensaje']);

        // 6. El vendedor termina los datos y sube las fotos
        self::assertSame(200, $this->ingresar($vendedor)['status']);
        self::assertSame(200, $this->pedir('PUT', "/api/mis-vehiculos/$id", self::RESTO)['status']);
        $fotos = $this->pedirFormulario("/api/mis-vehiculos/$id/fotos", [], 2);
        self::assertSame(201, $fotos['status'], json_encode($fotos['json']));
        self::assertCount(2, $fotos['json']['vehiculo']['fotos']);
        self::assertTrue($fotos['json']['vehiculo']['completa']);
        self::assertSame([], $fotos['json']['vehiculo']['faltan']);

        // 7. Ahora el moderador sí la aprueba y aparece en el catálogo
        $this->ingresarComoAdmin();
        $completas = $this->pedir('GET', '/api/admin/vehiculos?estado=pendiente&completa=1');
        self::assertContains($id, array_column($completas['json']['items'], 'id'));
        $aprobar = $this->pedir('PATCH', "/api/admin/vehiculos/$id/estado", ['estado' => 'disponible']);
        self::assertSame(200, $aprobar['status'], json_encode($aprobar['json']));
        self::assertSame(200, $this->pedir('GET', "/api/vehiculos/$id")['status']);
    }

    public function testFotosSeAgreganYSeSacanHastaElMaximo(): void
    {
        $this->ingresarComoUsuario();
        $r = $this->pedirFormulario('/api/mis-vehiculos', self::PRIMER_PASO + self::RESTO, 4);
        self::assertSame(201, $r['status'], json_encode($r['json']));
        $id = $r['json']['vehiculo']['id'];
        self::assertTrue($r['json']['vehiculo']['completa']); // todo de una, como antes, también se puede

        // Hasta 5 entre todas
        $demasiadas = $this->pedirFormulario("/api/mis-vehiculos/$id/fotos", [], 2);
        self::assertSame(422, $demasiadas['status']);
        self::assertStringContainsString('Ya tiene 4', $demasiadas['json']['errores']['fotos']);

        // Sacar una: la ruta termina en el id de la foto
        $fotos = $r['json']['vehiculo']['fotos'];
        $idFoto = (int)substr($fotos[0], strrpos($fotos[0], '/') + 1);
        $sin = $this->pedir('DELETE', "/api/mis-vehiculos/$id/fotos/$idFoto");
        self::assertSame(200, $sin['status']);
        self::assertCount(3, $sin['json']['vehiculo']['fotos']);
        self::assertSame(404, $this->pedir('DELETE', "/api/mis-vehiculos/$id/fotos/$idFoto")['status']);

        // Las fotos de una publicación ajena no se tocan
        $this->ingresarComoUsuario();
        self::assertSame(404, $this->pedirFormulario("/api/mis-vehiculos/$id/fotos", [], 1)['status']);
    }

    public function testLaBaseNoDejaPublicarUnaIncompleta(): void
    {
        $this->ingresarComoUsuario();
        $id = $this->pedirFormulario('/api/mis-vehiculos', self::PRIMER_PASO)['json']['vehiculo']['id'];
        // Aunque alguien se saltee el back, la base rechaza una visible sin precio
        $this->expectException(\PDOException::class);
        Entorno::pdo()->prepare("UPDATE vehiculo SET estado = 'disponible' WHERE id = :id")->execute(['id' => $id]);
    }
}
