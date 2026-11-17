<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/** Idea A, costo total: al firmar se piden presupuestos a otros talleres y el informe suma el más barato de cada arreglo. */
final class CostoTotalTest extends ApiTestCase
{
    use ConInspecciones;

    public function testAlFirmarSePidenPresupuestosAOtrosTalleresYElInformeSumaElMasBarato(): void
    {
        // Cuatro talleres de un pueblo propio de esta prueba: uno inspecciona y los otros tres presupuestan
        $pueblo = 'Pueblo ' . bin2hex(random_bytes(3));
        $talleres = [];
        for ($n = 0; $n < 4; $n++) {
            $talleres[] = $this->ingresarComoMecanico(true, ['localidad' => $pueblo]);
        }
        [$inspectorId, $inspector] = $talleres[0];

        $comprador = $this->ingresarComoUsuario();
        $id = $this->pedir('POST', '/api/inspecciones', ['url' => 'https://auto.mercadolibre.com.ar/MLA-3333-gol-_JM',
            'mecanico_id' => $inspectorId, 'precio_publicado' => 9000000])['json']['inspeccion']['id'];
        $this->ingresar($inspector);
        $this->aceptarYFirmar($id); // el checklist de prueba trae la carrocería observada

        // El que inspeccionó no recibe el pedido
        self::assertSame([], array_filter($this->pedir('GET', '/api/mecanico/presupuestos')['json']['items'],
            static fn($p) => $p['inspeccion_id'] === $id));

        // Los otros tres sí, con el auto y la nota del ítem
        $pedidos = [];
        foreach (array_slice($talleres, 1) as [, $email]) {
            $this->ingresar($email);
            $mios = array_values(array_filter($this->pedir('GET', '/api/mecanico/presupuestos?estado=pedido')['json']['items'],
                static fn($p) => $p['inspeccion_id'] === $id));
            self::assertCount(1, $mios);
            self::assertSame('carroceria', $mios[0]['item_codigo']);
            self::assertSame('Rayón en la puerta trasera', $mios[0]['item']['nota']);
            $pedidos[] = [$email, $mios[0]['id']];
        }

        // Uno presupuesta $ 90.000, otro $ 70.000 y el tercero rechaza
        $this->ingresar($pedidos[0][0]);
        self::assertSame(200, $this->pedir('PATCH', "/api/mecanico/presupuestos/{$pedidos[0][1]}", ['monto' => 90000, 'detalle' => 'Pintar la puerta'])['status']);
        self::assertSame(409, $this->pedir('PATCH', "/api/mecanico/presupuestos/{$pedidos[0][1]}", ['monto' => 80000, 'detalle' => 'Otra vez'])['status']);
        self::assertSame(404, $this->pedir('PATCH', "/api/mecanico/presupuestos/{$pedidos[1][1]}", ['monto' => 1, 'detalle' => 'Ajeno'])['status']);
        $this->ingresar($pedidos[1][0]);
        $r = $this->pedir('PATCH', "/api/mecanico/presupuestos/{$pedidos[1][1]}", ['monto' => 70000, 'detalle' => 'Pulido y retoque']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertTrue($r['json']['presupuesto']['vigente']);
        $this->ingresar($pedidos[2][0]);
        self::assertSame(422, $this->pedir('PATCH', "/api/mecanico/presupuestos/{$pedidos[2][1]}", ['monto' => -5, 'detalle' => ''])['status']);
        self::assertSame(200, $this->pedir('PATCH', "/api/mecanico/presupuestos/{$pedidos[2][1]}", ['rechazar' => true])['status']);

        // El comprador ve el costo total: el aviso + el más barato
        $this->ingresar($comprador);
        $i = $this->pedir('GET', "/api/inspecciones/$id")['json']['inspeccion'];
        self::assertCount(3, $i['presupuestos']);
        self::assertEquals(70000, $i['costo_total']['items'][0]['mejor']);
        self::assertEquals(9070000, $i['costo_total']['total']);
        self::assertTrue($i['costo_total']['completo']);

        // Pedirlos de nuevo no los repite
        self::assertCount(3, $this->pedir('POST', "/api/inspecciones/$id/presupuestos", [])['json']['inspeccion']['presupuestos']);
        $this->ingresarComoUsuario();
        self::assertSame(404, $this->pedir('POST', "/api/inspecciones/$id/presupuestos", [])['status']);
    }
}
