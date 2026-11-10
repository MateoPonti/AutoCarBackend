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

    public function testElDetalleMuestraElVendedorSinSuEmail(): void
    {
        $id = $this->vehiculoDisponible();
        $v = $this->pedir('GET', "/api/vehiculos/$id")['json']['vehiculo'];
        self::assertSame('Prueba', $v['vendedor']['nombre']);
        self::assertArrayHasKey('desde', $v['vendedor']);
        self::assertStringNotContainsString('@', json_encode($v['vendedor']));
    }

    public function testVehiculoInexistenteDa404(): void
    {
        self::assertSame(404, $this->pedir('GET', '/api/vehiculos/999999')['status']);
    }

    public function testElPanelNoPublicaNiEditaSoloModeraYDaDeBaja(): void
    {
        $id = $this->vehiculoDisponible(['marca' => 'Renault', 'modelo' => 'Kwid']);
        $this->ingresarComoAdmin();

        // Nadie del panel crea ni edita: toda publicación tiene un vendedor y la corrige su dueño
        self::assertSame(405, $this->pedir('POST', '/api/admin/vehiculos', self::vehiculo())['status']);
        self::assertSame(405, $this->pedir('PUT', "/api/admin/vehiculos/$id", self::vehiculo(['precio' => 17000000]))['status']);
        self::assertSame(18500000.0, (float)$this->pedir('GET', "/api/vehiculos/$id")['json']['vehiculo']['precio']);

        // Sí modera (dar de baja una aprobada) y el admin la borra
        $baja = $this->pedir('PATCH', "/api/admin/vehiculos/$id/estado", ['estado' => 'rechazado', 'motivo' => 'Fotos que no son del auto.']);
        self::assertSame(200, $baja['status'], json_encode($baja['json']));
        self::assertSame(204, $this->pedir('DELETE', "/api/admin/vehiculos/$id")['status']);
        self::assertNotSame(200, $this->pedir('GET', "/api/vehiculos/$id")['status']);
    }

    public function testLaBaseNoAceptaUnVehiculoSinVendedor(): void
    {
        $this->expectException(\PDOException::class);
        Entorno::pdo()->exec("INSERT INTO vehiculo (marca, modelo, anio) VALUES ('Fiat', 'Uno', 2010)");
    }

    public function testPublicarConDatosInvalidosDa422(): void
    {
        $this->ingresarComoUsuario();
        $r = $this->pedirFormulario('/api/mis-vehiculos', ['marca' => 'Fiat', 'modelo' => 'Uno', 'anio' => '1800', 'combustible' => 'vapor']);
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('anio', $r['json']['errores']);
        self::assertArrayHasKey('combustible', $r['json']['errores']);
    }

    public function testSinSesionLasRutasDeAdminDan401(): void
    {
        self::assertSame(401, $this->pedir('GET', '/api/admin/vehiculos')['status']);
        self::assertSame(401, $this->pedir('DELETE', '/api/admin/vehiculos/1')['status']);
    }

    public function testUnUsuarioComunNoPuedeAdministrar(): void
    {
        $id = $this->vehiculoDisponible();
        $this->ingresarComoUsuario();
        self::assertSame(403, $this->pedir('GET', '/api/admin/vehiculos')['status']);
        self::assertSame(403, $this->pedir('DELETE', "/api/admin/vehiculos/$id")['status']);
        self::assertSame(403, $this->pedir('GET', '/api/admin/usuarios')['status']);
    }

    public function testSinTokenCsrfNoSeModificaNada(): void
    {
        $id = $this->vehiculoDisponible();
        $this->ingresarComoAdmin();
        $r = $this->pedir('DELETE', "/api/admin/vehiculos/$id", null, [], conCsrf: false);
        self::assertSame(403, $r['status']);
        self::assertSame('csrf', $r['json']['error']);

        $r = $this->pedir('DELETE', "/api/admin/vehiculos/$id", null, ['X-CSRF-Token' => str_repeat('0', 64)], conCsrf: false);
        self::assertSame(403, $r['status']);
        self::assertSame(200, $this->pedir('GET', "/api/vehiculos/$id")['status'], 'sigue publicado');
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
