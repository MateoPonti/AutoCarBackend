<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/**
 * Lado del comprador y del admin: ranking (B3), pedir (B4), mis inspecciones y cancelar (B5), reclamo (B8).
 * La primera prueba es el recorrido de la demo del 30/11 (PLAN.md, sección 3) de punta a punta por la API.
 */
final class InspeccionesCompradorTest extends ApiTestCase
{
    use ConInspecciones;

    private const ML = 'https://auto.mercadolibre.com.ar/MLA-2268147919-gol-trend-_JM';

    private function pedirInspeccion(int $mecanicoId, array $extra = []): array
    {
        return $this->pedir('POST', '/api/inspecciones', $extra + ['url' => self::ML, 'mecanico_id' => $mecanicoId]);
    }

    public function testLasGananciasDelAdminSumanLaInspeccionFirmada(): void
    {
        $ganancias = function (): array {
            $this->ingresarComoAdmin();
            return $this->pedir('GET', '/api/admin/metricas')['json']['ganancias'];
        };
        $antes = $ganancias();
        self::assertTrue($antes['simulado']);

        [$mecanicoId, $mecanico] = $this->ingresarComoMecanico();
        $this->ingresarComoUsuario();
        $pedido = $this->pedirInspeccion($mecanicoId)['json']['inspeccion'];
        $this->ingresar($mecanico);
        $this->aceptarYFirmar($pedido['id']);

        $despues = $ganancias();
        $fila = Entorno::pdo()->query("SELECT precio, monto_autocar, monto_fondo, monto_mecanico FROM inspeccion WHERE id = {$pedido['id']}")->fetch();
        self::assertEqualsWithDelta((float)$fila['precio'], $despues['facturado'] - $antes['facturado'], 0.01);
        self::assertEqualsWithDelta($fila['monto_autocar'] - $fila['monto_fondo'], $despues['autocar'] - $antes['autocar'], 0.01);
        self::assertEqualsWithDelta((float)$fila['monto_mecanico'], $despues['mecanicos'] - $antes['mecanicos'], 0.01);
        self::assertEqualsWithDelta((float)$fila['monto_fondo'], $despues['fondo']['acumulado'] - $antes['fondo']['acumulado'], 0.01);
    }

    public function testRecorridoDeLaDemo(): void
    {
        $localidad = 'Pueblo ' . bin2hex(random_bytes(3)); // propia, para no mezclarse con otras pruebas
        [$mecanicoId, $mecanico] = $this->ingresarComoMecanico(true, ['localidad' => $localidad]);

        // 1. El ranking es público y muestra al mecánico nuevo
        $this->pedir('POST', '/api/logout'); // sin sesión
        $ranking = $this->pedir('GET', '/api/mecanicos?localidad=' . rawurlencode(strtoupper($localidad)));
        self::assertSame(200, $ranking['status']);
        self::assertSame([$mecanicoId], array_column($ranking['json']['items'], 'id'));
        self::assertTrue($ranking['json']['items'][0]['nuevo']);
        self::assertSame(100000.0, (float)$ranking['json']['items'][0]['desglose']['monto_mecanico']);
        self::assertArrayNotHasKey('telefono', $ranking['json']['items'][0]);
        self::assertContains($localidad, $ranking['json']['localidades']);

        // 2. El comprador pide la inspección de un auto de MercadoLibre
        $comprador = $this->ingresarComoUsuario();
        $pedido = $this->pedirInspeccion($mecanicoId, ['titulo' => 'VW Gol Trend', 'anio' => 2018, 'kilometraje' => 98000]);
        self::assertSame(201, $pedido['status'], json_encode($pedido['json']));
        $i = $pedido['json']['inspeccion'];
        $id = $i['id'];
        self::assertSame('/api/inspecciones/' . $id, $pedido['headers']['location']);
        self::assertSame(['pedida', 'mercadolibre', 'retenido'], [$i['estado'], $i['fuente'], $i['pago']]);
        self::assertSame([130000.0, 100000.0, 30000.0, 10000.0],
            [(float)$i['precio'], (float)$i['monto_mecanico'], (float)$i['monto_autocar'], (float)$i['monto_fondo']]);
        self::assertSame('Taller de Prueba', $i['mecanico']['taller']);
        self::assertSame(409, $this->pedirInspeccion($mecanicoId)['status'], 'el mismo auto dos veces');
        self::assertSame([$id], array_column($this->pedir('GET', '/api/mis-inspecciones')['json']['items'], 'id'));

        // 3-4. El mecánico acepta, revisa y firma
        $this->ingresar($mecanico);
        $this->aceptarYFirmar($id);

        // 5. El comprador ve el informe firmado y reclama
        $this->ingresar($comprador);
        $firmada = $this->pedir('GET', "/api/inspecciones/$id")['json']['inspeccion'];
        self::assertSame('firmada', $firmada['estado']);
        self::assertTrue($firmada['puede_reclamar']);
        self::assertNotNull($firmada['reclamo_hasta']);
        $reclamo = $this->pedir('POST', "/api/inspecciones/$id/reclamo",
            ['descripcion' => 'Tenía un golpe en el zócalo y no lo marcó', 'item_codigo' => 'carroceria']);
        self::assertSame(201, $reclamo['status'], json_encode($reclamo['json']));
        self::assertSame('abierto', $reclamo['json']['inspeccion']['reclamo']['estado']);
        self::assertFalse($reclamo['json']['inspeccion']['puede_reclamar']);
        self::assertSame(409, $this->pedir('POST', "/api/inspecciones/$id/reclamo", ['descripcion' => 'Otro reclamo más largo'])['status']);

        // 6. El admin resuelve: procede, con compensación hasta el tope
        $this->ingresarComoAdmin();
        $cola = $this->pedir('GET', '/api/admin/reclamos?estado=abierto');
        self::assertSame(200, $cola['status']);
        $item = array_values(array_filter($cola['json']['items'], fn($r) => $r['inspeccion']['id'] === $id))[0];
        self::assertSame(390000.0, (float)$item['tope']);
        $rid = $item['id'];
        $pasado = $this->pedir('PATCH', "/api/admin/reclamos/$rid", ['procede' => true, 'compensacion' => 400000, 'respuesta' => 'Procede']);
        self::assertSame(422, $pasado['status']);
        $ok = $this->pedir('PATCH', "/api/admin/reclamos/$rid", ['procede' => true, 'compensacion' => 390000, 'respuesta' => 'Se veía en las fotos.']);
        self::assertSame(200, $ok['status'], json_encode($ok['json']));
        self::assertSame('procede', $ok['json']['reclamo']['estado']);
        self::assertSame(409, $this->pedir('PATCH', "/api/admin/reclamos/$rid", ['procede' => false, 'respuesta' => 'Cambio de idea'])['status']);

        // El comprador ve la devolución y el mecánico suma el reclamo en el ranking
        $this->ingresar($comprador);
        $final = $this->pedir('GET', "/api/inspecciones/$id")['json']['inspeccion'];
        self::assertSame('reembolsado', $final['pago']);
        self::assertSame(390000.0, (float)$final['reclamo']['compensacion']);
        $despues = $this->pedir('GET', '/api/mecanicos?localidad=' . rawurlencode($localidad))['json']['items'][0];
        self::assertSame([1, 1], [$despues['firmadas'], $despues['reclamos_procedentes']]);
    }

    public function testSoloSeEligenMecanicosDeLaRed(): void
    {
        [$sinPerfil] = $this->ingresarComoMecanico(false);
        $this->ingresarComoUsuario();
        $r = $this->pedirInspeccion($sinPerfil);
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('mecanico_id', $r['json']['errores']);
        $mal = $this->pedir('POST', '/api/inspecciones', ['url' => 'no es un link']);
        self::assertSame(422, $mal['status']);
        self::assertArrayHasKey('url', $mal['json']['errores']);
        self::assertArrayHasKey('mecanico_id', $mal['json']['errores']);
    }

    public function testPedirSinSesionDa401(): void
    {
        self::assertSame(401, $this->pedir('POST', '/api/inspecciones', ['url' => self::ML, 'mecanico_id' => 1])['status']);
        self::assertSame(401, $this->pedir('GET', '/api/mis-inspecciones')['status']);
    }

    public function testLaCuentaDelTallerNoPideInspecciones(): void
    {
        // La cuenta del taller es aparte de la personal: no compra (antes daba 409 mecanico_propio; ahora ni llega)
        [$yo] = $this->ingresarComoMecanico();
        $r = $this->pedirInspeccion($yo);
        self::assertSame(403, $r['status']);
        self::assertSame('cuenta_del_equipo', $r['json']['error']);
    }

    public function testAutosDeAutoCar(): void
    {
        [$mecanicoId] = $this->ingresarComoMecanico();
        $vendedor = $this->ingresarComoUsuario();
        $st = Entorno::pdo()->prepare("INSERT INTO vehiculo (usuario_id, marca, modelo, version, anio, precio, kilometraje,
            combustible, transmision, localidad) VALUES (:u, 'Fiat', 'Cronos', 'Drive', 2022, 21000000, 60000, 'nafta', 'manual', 'Luján')
            RETURNING id");
        $st->execute(['u' => self::idDe($vendedor)]);
        $vehiculo = (int)$st->fetchColumn();
        $url = "http://front.test/front/pages/vehiculos/vehiculo.html?id=$vehiculo";

        // El vendedor no puede pedir la inspección de su propio auto
        $propio = $this->pedir('POST', '/api/inspecciones', ['url' => $url, 'mecanico_id' => $mecanicoId]);
        self::assertSame(409, $propio['status']);
        self::assertSame('vehiculo_propio', $propio['json']['error']);

        // Otro comprador sí, y los datos del auto salen de la publicación
        $this->ingresarComoUsuario();
        $r = $this->pedir('POST', '/api/inspecciones', ['url' => $url, 'mecanico_id' => $mecanicoId]);
        self::assertSame(201, $r['status'], json_encode($r['json']));
        $i = $r['json']['inspeccion'];
        self::assertSame(['autocar', $vehiculo, 'Fiat Cronos Drive', 2022, 60000, 'Luján'],
            [$i['fuente'], $i['vehiculo_id'], $i['titulo'], $i['anio'], $i['kilometraje'], $i['localidad']]);

        // Una publicación que no está disponible no se puede inspeccionar
        Entorno::pdo()->prepare("UPDATE vehiculo SET estado = 'vendido' WHERE id = :id")->execute(['id' => $vehiculo]);
        $this->ingresarComoUsuario();
        self::assertSame(422, $this->pedir('POST', '/api/inspecciones', ['url' => $url, 'mecanico_id' => $mecanicoId])['status']);
    }

    public function testCancelarSoloAntesDeQueLaAcepten(): void
    {
        [$mecanicoId, $mecanico] = $this->ingresarComoMecanico();
        $comprador = $this->ingresarComoUsuario();
        $id = $this->pedirInspeccion($mecanicoId)['json']['inspeccion']['id'];

        $this->ingresarComoUsuario(); // otro usuario no la puede cancelar
        self::assertSame(404, $this->pedir('POST', "/api/inspecciones/$id/cancelar", [])['status']);

        $this->ingresar($comprador);
        $r = $this->pedir('POST', "/api/inspecciones/$id/cancelar", []);
        self::assertSame(200, $r['status']);
        self::assertSame(['cancelada', 'reembolsado'], [$r['json']['inspeccion']['estado'], $r['json']['inspeccion']['pago']]);
        self::assertSame(409, $this->pedir('POST', "/api/inspecciones/$id/cancelar", [])['status']);

        $this->ingresar($mecanico); // y el mecánico ya no la puede aceptar
        self::assertSame(409, $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'aceptar', 'fecha_turno' => self::turno()])['status']);

        // Aceptada, el comprador ya no la cancela
        $this->ingresar($comprador);
        $otra = $this->pedirInspeccion($mecanicoId)['json']['inspeccion']['id'];
        $this->ingresar($mecanico);
        $this->pedir('PATCH', "/api/mecanico/inspecciones/$otra", ['accion' => 'aceptar', 'fecha_turno' => self::turno()]);
        $this->ingresar($comprador);
        self::assertSame(409, $this->pedir('POST', "/api/inspecciones/$otra/cancelar", [])['status']);
    }

    public function testReglasDelReclamo(): void
    {
        [$mecanicoId, $mecanico] = $this->ingresarComoMecanico();
        $comprador = $this->ingresarComoUsuario();
        $id = $this->pedirInspeccion($mecanicoId)['json']['inspeccion']['id'];
        $reclamo = ['descripcion' => 'El motor perdía aceite y no lo marcó'];

        self::assertSame(409, $this->pedir('POST', "/api/inspecciones/$id/reclamo", $reclamo)['status'], 'sin firmar');
        self::assertSame(422, $this->pedir('POST', "/api/inspecciones/$id/reclamo", ['descripcion' => 'corto'])['status']);

        $this->ingresar($mecanico);
        $this->aceptarYFirmar($id);
        self::assertSame(404, $this->pedir('POST', "/api/inspecciones/$id/reclamo", $reclamo)['status'], 'el mecánico no reclama');

        // Pasados los 15 días ya no se puede
        Entorno::pdo()->prepare("UPDATE inspeccion SET firmada_at = now() - interval '16 days' WHERE id = :id")->execute(['id' => $id]);
        $this->ingresar($comprador);
        self::assertFalse($this->pedir('GET', "/api/inspecciones/$id")['json']['inspeccion']['puede_reclamar']);
        $tarde = $this->pedir('POST', "/api/inspecciones/$id/reclamo", $reclamo);
        self::assertSame(409, $tarde['status']);
        self::assertSame('fuera_de_plazo', $tarde['json']['error']);
    }

    public function testLaColaDeReclamosEsDelModeradorYElAdmin(): void
    {
        $this->ingresarComoUsuario();
        self::assertSame(403, $this->pedir('GET', '/api/admin/reclamos')['status']);
        $this->ingresarComoMecanico();
        self::assertSame(403, $this->pedir('PATCH', '/api/admin/reclamos/1', ['procede' => false, 'respuesta' => 'no'])['status']);
        $this->ingresarComoAdmin();
        self::assertSame(422, $this->pedir('GET', '/api/admin/reclamos?estado=cualquiera')['status']);
        self::assertSame(404, $this->pedir('PATCH', '/api/admin/reclamos/999999', ['procede' => false, 'respuesta' => 'No procede'])['status']);
    }
}
