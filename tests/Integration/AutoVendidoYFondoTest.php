<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/**
 * Auto vendido (turno que confirma el vendedor y que se cae solo, crédito al comprador y cupón) y fondo de garantía
 * (devolución, compensación y franquicia del mecánico), de punta a punta por la API.
 */
final class AutoVendidoYFondoTest extends ApiTestCase
{
    use ConInspecciones;

    /** Publicación disponible de un vendedor nuevo. → [vehiculo_id, url, email del vendedor] */
    private function autoDeAutoCar(): array
    {
        $vendedor = $this->registrar();
        $st = Entorno::pdo()->prepare("INSERT INTO vehiculo (usuario_id, marca, modelo, version, anio, precio, kilometraje,
            combustible, transmision, localidad) VALUES (:u, 'Fiat', 'Cronos', 'Drive', 2022, 21000000, 60000, 'nafta', 'manual', 'Luján')
            RETURNING id");
        $st->execute(['u' => self::idDe($vendedor)]);
        $id = (int)$st->fetchColumn();
        return [$id, "http://front.test/front/pages/vehiculos/vehiculo.html?id=$id", $vendedor];
    }

    private function venderComo(string $vendedor, int $vehiculo): void
    {
        $this->ingresar($vendedor);
        $r = $this->pedir('PATCH', "/api/mis-vehiculos/$vehiculo/estado", ['estado' => 'vendido']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
    }

    public function testElVendedorConfirmaElTurnoYSiVendeAntesSeCae(): void
    {
        [$mecanicoId, $mecanico] = $this->ingresarComoMecanico();
        [$vehiculo, $url, $vendedor] = $this->autoDeAutoCar();
        $comprador = $this->ingresarComoUsuario();
        $id = $this->pedir('POST', '/api/inspecciones', ['url' => $url, 'mecanico_id' => $mecanicoId])['json']['inspeccion']['id'];
        $this->ingresar($mecanico);
        self::assertSame(200, $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'aceptar', 'fecha_turno' => self::turno()])['status']);

        // El vendedor ve el turno (sin el nombre del mecánico) y lo confirma; otro usuario no
        $this->ingresarComoUsuario();
        self::assertSame(404, $this->pedir('POST', "/api/inspecciones/$id/confirmar-turno", [])['status']);
        $this->ingresar($vendedor);
        $turnos = $this->pedir('GET', '/api/mis-vehiculos/turnos')['json']['items'];
        self::assertSame([$id], array_column($turnos, 'id'));
        self::assertArrayNotHasKey('mecanico', $turnos[0]);
        $r = $this->pedir('POST', "/api/inspecciones/$id/confirmar-turno", []);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertNotNull($r['json']['turno']['confirmado_at']);

        // Vende el auto antes del turno: la inspección se cae y al comprador se le devuelve todo
        $this->venderComo($vendedor, $vehiculo);
        $this->ingresar($comprador);
        $i = $this->pedir('GET', "/api/inspecciones/$id")['json']['inspeccion'];
        self::assertSame(['caida', 'reembolsado'], [$i['estado'], $i['pago']]);
        self::assertNull($i['credito']);
    }

    public function testSiElMecanicoYaFueSeFirmaIgualYElCompradorRecibeElCredito(): void
    {
        [$mecanicoId, $mecanico] = $this->ingresarComoMecanico();
        [$vehiculo, $url, $vendedor] = $this->autoDeAutoCar();
        $comprador = $this->ingresarComoUsuario();
        $id = $this->pedir('POST', '/api/inspecciones', ['url' => $url, 'mecanico_id' => $mecanicoId])['json']['inspeccion']['id'];
        $this->ingresar($mecanico);
        $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'aceptar', 'fecha_turno' => self::turno()]);

        // El turno ya pasó (el mecánico fue) y el auto se vende: la inspección sigue
        Entorno::pdo()->prepare("UPDATE inspeccion SET fecha_turno = now() - interval '1 hour' WHERE id = :id")->execute(['id' => $id]);
        $this->venderComo($vendedor, $vehiculo);
        $this->ingresar($mecanico);
        $firmada = $this->aceptarYFirmarSinAceptar($id);
        self::assertSame('firmada', $firmada['estado']);
        self::assertSame('auto_vendido', $firmada['credito']['tipo']);
        self::assertSame(round($firmada['precio'] * 0.3), $firmada['credito']['monto']);

        // El crédito se descuenta solo en la próxima inspección del comprador
        $this->ingresar($comprador);
        $creditos = $this->pedir('GET', '/api/mis-creditos')['json'];
        self::assertSame($firmada['credito']['monto'], $creditos['total']);
        $otra = $this->pedir('POST', '/api/inspecciones', ['url' => 'https://auto.mercadolibre.com.ar/MLA-1111-ka-_JM', 'mecanico_id' => $mecanicoId])['json']['inspeccion'];
        self::assertSame($firmada['credito']['monto'], $otra['credito_usado']);
        self::assertSame($otra['precio'] - $otra['credito_usado'], $otra['a_pagar']);
        self::assertSame(0, $this->pedir('GET', '/api/mis-creditos')['json']['total']);

        // Si la cancela, el crédito vuelve
        $this->pedir('POST', "/api/inspecciones/{$otra['id']}/cancelar", []);
        self::assertSame($firmada['credito']['monto'], $this->pedir('GET', '/api/mis-creditos')['json']['total']);
    }

    public function testCuponSiSeVendeEnseguidaConElTurnoConfirmado(): void
    {
        [$mecanicoId, $mecanico] = $this->ingresarComoMecanico();
        [$vehiculo, $url, $vendedor] = $this->autoDeAutoCar();
        $comprador = $this->ingresarComoUsuario();
        $id = $this->pedir('POST', '/api/inspecciones', ['url' => $url, 'mecanico_id' => $mecanicoId])['json']['inspeccion']['id'];
        $this->ingresar($mecanico);
        $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'aceptar', 'fecha_turno' => self::turno()]);
        $this->ingresar($vendedor);
        $this->pedir('POST', "/api/inspecciones/$id/confirmar-turno", []);
        $this->ingresar($mecanico);
        $this->aceptarYFirmarSinAceptar($id);

        $this->venderComo($vendedor, $vehiculo); // al rato de la firma
        $this->ingresar($comprador);
        $i = $this->pedir('GET', "/api/inspecciones/$id")['json']['inspeccion'];
        self::assertSame('cupon', $i['credito']['tipo']);
    }

    public function testSinTurnoConfirmadoNoHayCupon(): void
    {
        [$mecanicoId, $mecanico] = $this->ingresarComoMecanico();
        [$vehiculo, $url, $vendedor] = $this->autoDeAutoCar();
        $comprador = $this->ingresarComoUsuario();
        $id = $this->pedir('POST', '/api/inspecciones', ['url' => $url, 'mecanico_id' => $mecanicoId])['json']['inspeccion']['id'];
        $this->ingresar($mecanico);
        $this->aceptarYFirmar($id);
        $this->venderComo($vendedor, $vehiculo);
        $this->ingresar($comprador);
        self::assertNull($this->pedir('GET', "/api/inspecciones/$id")['json']['inspeccion']['credito']);
    }

    public function testElReclamoQueProcedeSaleDelFondoYElMecanicoPagaLaFranquicia(): void
    {
        $this->ingresarComoAdmin();
        $antes = $this->pedir('GET', '/api/admin/fondo')['json']['fondo'];

        [$mecanicoId, $mecanico] = $this->ingresarComoMecanico();
        $comprador = $this->ingresarComoUsuario();
        $id = $this->pedir('POST', '/api/inspecciones', ['url' => 'https://auto.mercadolibre.com.ar/MLA-2222-gol-_JM', 'mecanico_id' => $mecanicoId])['json']['inspeccion']['id'];
        $this->ingresar($mecanico);
        $i = $this->aceptarYFirmar($id);
        $this->ingresar($comprador);
        $this->pedir('POST', "/api/inspecciones/$id/reclamo", ['descripcion' => 'Tenía un golpe en el zócalo y no lo marcó']);

        $this->ingresarComoAdmin();
        $reclamoId = $this->pedir('GET', '/api/admin/reclamos?estado=abierto')['json']['items'];
        $reclamoId = array_values(array_filter($reclamoId, static fn($r) => $r['inspeccion']['id'] === $id))[0]['id'];
        $r = $this->pedir('PATCH', "/api/admin/reclamos/$reclamoId", ['procede' => true, 'compensacion' => 50000, 'respuesta' => 'Se veía a simple vista']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame($i['precio'], $r['json']['reclamo']['devolucion']);
        self::assertSame(round($i['monto_mecanico'] * 0.4), $r['json']['reclamo']['franquicia']);

        $despues = $this->pedir('GET', '/api/admin/fondo')['json']['fondo'];
        $esperado = $i['monto_fondo'] + $r['json']['reclamo']['franquicia'] - $i['precio'] - 50000;
        self::assertEqualsWithDelta($esperado, $despues['saldo'] - $antes['saldo'], 0.01);
        self::assertSame($antes['reclamos'] + 1, $despues['reclamos']);
        self::assertGreaterThan(0, $despues['supuesto']['inspecciones_por_reclamo']);

        $this->ingresarComoUsuario();
        self::assertSame(403, $this->pedir('GET', '/api/admin/fondo')['status']);
    }

    /** Con la sesión del mecánico y la inspección ya aceptada: completa el checklist, sube la chapa y firma. */
    private function aceptarYFirmarSinAceptar(int $id): array
    {
        self::assertSame(200, $this->pedir('PUT', "/api/mecanico/inspecciones/$id/informe",
            ['items' => self::checklistCompleto(), 'conclusion' => 'recomendable', 'patente' => 'AC123DE'])['status']);
        self::assertSame(200, $this->pedirFormulario("/api/mecanico/inspecciones/$id/patente-foto", [], 1, 'foto')['status']);
        $firma = $this->pedir('POST', "/api/mecanico/inspecciones/$id/firma", []);
        self::assertSame(200, $firma['status'], json_encode($firma['json']));
        return $firma['json']['inspeccion'];
    }
}
