<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

final class FavoritosTest extends ApiTestCase
{
    public function testGuardarListarYSacar(): void
    {
        $id = $this->vehiculoDisponible(['marca' => 'Renault', 'modelo' => 'Kwid']);
        $this->ingresarComoUsuario();
        self::assertSame([], $this->pedir('GET', '/api/favoritos')['json']['items']);

        self::assertSame(204, $this->pedir('PUT', "/api/favoritos/$id")['status']);
        self::assertSame(204, $this->pedir('PUT', "/api/favoritos/$id")['status'], 'guardarlo dos veces no da error');
        $items = $this->pedir('GET', '/api/favoritos')['json']['items'];
        self::assertCount(1, $items);
        self::assertSame($id, $items[0]['id']);
        self::assertSame('Kwid', $items[0]['modelo']);

        self::assertSame(204, $this->pedir('DELETE', "/api/favoritos/$id")['status']);
        self::assertSame([], $this->pedir('GET', '/api/favoritos')['json']['items']);
    }

    public function testCadaUnoVeLosSuyos(): void
    {
        $id = $this->vehiculoDisponible();
        $this->ingresarComoUsuario();
        $this->pedir('PUT', "/api/favoritos/$id");

        $this->ingresarComoUsuario();
        self::assertSame([], $this->pedir('GET', '/api/favoritos')['json']['items']);
    }

    public function testNoSeGuardaUnoQueNoEstaPublicadoNiElPropio(): void
    {
        $this->ingresarComoUsuario();
        self::assertSame(404, $this->pedir('PUT', '/api/favoritos/999999')['status']);

        $propia = $this->pedirFormulario('/api/mis-vehiculos', ['marca' => 'Volkswagen', 'modelo' => 'Gol Trend', 'anio' => '2018']);
        self::assertSame(201, $propia['status']);
        self::assertSame(404, $this->pedir('PUT', "/api/favoritos/{$propia['json']['vehiculo']['id']}")['status'], 'pendiente: no está publicada');
    }

    public function testUnoDadoDeBajaDejaDeAparecer(): void
    {
        $id = $this->vehiculoDisponible();
        $email = $this->ingresarComoUsuario();
        $this->pedir('PUT', "/api/favoritos/$id");

        $this->ingresarComoAdmin();
        self::assertSame(204, $this->pedir('DELETE', "/api/admin/vehiculos/$id")['status']);

        $this->ingresar($email);
        self::assertSame([], $this->pedir('GET', '/api/favoritos')['json']['items']);
    }

    public function testSinSesionDa401(): void
    {
        self::assertSame(401, $this->pedir('GET', '/api/favoritos')['status']);
        self::assertSame(401, $this->pedir('PUT', '/api/favoritos/1')['status']);
    }
}
