<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

final class VehiculosTest extends ApiTestCase
{
    public function testCatalogoPublicoConPaginacion(): void
    {
        $r = $this->pedir('GET', '/api/vehiculos');
        self::assertSame(200, $r['status']);
        self::assertIsArray($r['json']['items']);
        self::assertGreaterThan(0, $r['json']['total'], 'seed.sql carga vehículos disponibles');
        self::assertSame(1, $r['json']['pagina']);
        self::assertSame(max(1, (int)ceil($r['json']['total'] / $r['json']['por_pagina'])), $r['json']['paginas']);
    }

    public function testElFiltroDeCombustibleSeRespeta(): void
    {
        $r = $this->pedir('GET', '/api/vehiculos?combustible=diesel');
        self::assertSame(200, $r['status']);
        foreach ($r['json']['items'] as $v) {
            self::assertSame('diesel', $v['combustible']);
        }
        $todos = $this->pedir('GET', '/api/vehiculos')['json']['total'];
        self::assertLessThanOrEqual($todos, $r['json']['total']);
    }

    public function testMarcasDevuelveUnaLista(): void
    {
        $r = $this->pedir('GET', '/api/marcas');
        self::assertSame(200, $r['status']);
        self::assertIsArray($r['json']['marcas']);
        self::assertNotEmpty($r['json']['marcas']);
    }

    public function testVehiculoInexistenteDa404(): void
    {
        self::assertSame(404, $this->pedir('GET', '/api/vehiculos/999999')['status']);
    }

    public function testAdminHaceElAbmCompleto(): void
    {
        $this->ingresarComoAdmin();

        $alta = $this->pedir('POST', '/api/admin/vehiculos', self::vehiculo(['marca' => 'Renault', 'modelo' => 'Kwid']));
        self::assertSame(201, $alta['status'], json_encode($alta['json']));
        $id = $alta['json']['vehiculo']['id'];
        self::assertSame('/api/vehiculos/' . $id, $alta['headers']['location']);

        $detalle = $this->pedir('GET', "/api/vehiculos/$id");
        self::assertSame(200, $detalle['status']);

        $buscar = $this->pedir('GET', '/api/vehiculos?q=Kwid');
        self::assertStringContainsString('Kwid', json_encode($buscar['json']));

        $edicion = $this->pedir('PUT', "/api/admin/vehiculos/$id", self::vehiculo(['marca' => 'Renault', 'modelo' => 'Kwid', 'precio' => 17000000]));
        self::assertSame(200, $edicion['status'], json_encode($edicion['json']));
        self::assertSame(17000000.0, (float)$edicion['json']['vehiculo']['precio']);

        self::assertSame(204, $this->pedir('DELETE', "/api/admin/vehiculos/$id")['status']);
        self::assertNotSame(200, $this->pedir('GET', "/api/vehiculos/$id")['status']);
    }

    public function testAltaConDatosInvalidosDa422(): void
    {
        $this->ingresarComoAdmin();
        $r = $this->pedir('POST', '/api/admin/vehiculos', self::vehiculo(['anio' => 1800, 'combustible' => 'vapor']));
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('anio', $r['json']['errores']);
        self::assertArrayHasKey('combustible', $r['json']['errores']);
    }

    public function testSinSesionLasRutasDeAdminDan401(): void
    {
        self::assertSame(401, $this->pedir('GET', '/api/admin/vehiculos')['status']);
        self::assertSame(401, $this->pedir('POST', '/api/admin/vehiculos', self::vehiculo())['status']);
    }

    public function testUnUsuarioComunNoPuedeAdministrar(): void
    {
        $this->ingresarComoUsuario();
        self::assertSame(403, $this->pedir('GET', '/api/admin/vehiculos')['status']);
        self::assertSame(403, $this->pedir('POST', '/api/admin/vehiculos', self::vehiculo())['status']);
        self::assertSame(403, $this->pedir('GET', '/api/admin/usuarios')['status']);
    }

    public function testSinTokenCsrfNoSeModificaNada(): void
    {
        $this->ingresarComoAdmin();
        $r = $this->pedir('POST', '/api/admin/vehiculos', self::vehiculo(), [], conCsrf: false);
        self::assertSame(403, $r['status']);
        self::assertSame('csrf', $r['json']['error']);

        $r = $this->pedir('POST', '/api/admin/vehiculos', self::vehiculo(), ['X-CSRF-Token' => str_repeat('0', 64)], conCsrf: false);
        self::assertSame(403, $r['status']);
    }

    public function testJsonInvalidoDa400(): void
    {
        $this->ingresarComoAdmin();
        $c = curl_init(Entorno::url() . '/api/registro');
        curl_setopt_array($c, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{no es json', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        curl_exec($c);
        self::assertSame(400, curl_getinfo($c, CURLINFO_RESPONSE_CODE));
    }
}
