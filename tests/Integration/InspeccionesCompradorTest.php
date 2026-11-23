<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/**
 * Lado del comprador y del admin: ranking (B3), pedir (B4), mis inspecciones y cancelar (B5).
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
        $fila = Entorno::pdo()->query("SELECT precio, monto_autocar, monto_mecanico FROM inspeccion WHERE id = {$pedido['id']}")->fetch();
        self::assertEqualsWithDelta((float)$fila['precio'], $despues['facturado'] - $antes['facturado'], 0.01);
        self::assertEqualsWithDelta((float)$fila['monto_autocar'], $despues['autocar'] - $antes['autocar'], 0.01);
        self::assertEqualsWithDelta((float)$fila['monto_mecanico'], $despues['mecanicos'] - $antes['mecanicos'], 0.01);
        self::assertArrayNotHasKey('fondo', $despues); // sin fondo de garantía desde el 23/11
    }

    public function testElInformeNoTraeOtrasInspeccionesDeLaPatente(): void
    {
        $patente = 'AA' . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT) . 'ZZ'; // propia de esta prueba
        [$mecanicoId, $mecanico] = $this->ingresarComoMecanico();
        foreach (['https://auto.mercadolibre.com.ar/MLA-111-gol-_JM', 'https://auto.mercadolibre.com.ar/MLA-222-gol-_JM'] as $url) {
            $comprador = $this->ingresarComoUsuario();
            $id = $this->pedirInspeccion($mecanicoId, ['url' => $url])['json']['inspeccion']['id'];
            $this->ingresar($mecanico);
            $this->aceptarYFirmar($id, $patente);
        }

        // Sin historial por patente (23/11): la patente queda en el informe, pero no las inspecciones anteriores
        $this->ingresar($comprador);
        $r = $this->pedir('GET', "/api/inspecciones/{$id}");
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame($patente, $r['json']['inspeccion']['patente']);
        self::assertArrayNotHasKey('anteriores', $r['json']['inspeccion']);
        self::assertSame(404, $this->pedir('GET', '/api/historial-patente?patente=' . $patente)['status']);
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
        self::assertSame([130000.0, 100000.0, 30000.0],
            [(float)$i['precio'], (float)$i['monto_mecanico'], (float)$i['monto_autocar']]);
        self::assertSame('Taller de Prueba', $i['mecanico']['taller']);
        self::assertSame(409, $this->pedirInspeccion($mecanicoId)['status'], 'el mismo auto dos veces');
        self::assertSame([$id], array_column($this->pedir('GET', '/api/mis-inspecciones')['json']['items'], 'id'));

        // 3-4. El mecánico acepta, revisa y firma
        $this->ingresar($mecanico);
        $this->aceptarYFirmar($id);

        // 5. El comprador ve el informe firmado (sin reclamo después) y el mecánico suma una firmada en el ranking
        $this->ingresar($comprador);
        $firmada = $this->pedir('GET', "/api/inspecciones/$id")['json']['inspeccion'];
        self::assertSame(['firmada', 'liberado'], [$firmada['estado'], $firmada['pago']]);
        self::assertArrayNotHasKey('puede_reclamar', $firmada);
        self::assertArrayNotHasKey('reclamo', $firmada);
        self::assertSame(404, $this->pedir('POST', "/api/inspecciones/$id/reclamo", ['descripcion' => 'Tenía un golpe y no lo marcó'])['status']);
        $despues = $this->pedir('GET', '/api/mecanicos?localidad=' . rawurlencode($localidad))['json']['items'][0];
        self::assertSame(1, $despues['firmadas']);
        self::assertArrayNotHasKey('puntaje', $despues);
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

        // Otro comprador sí, y los datos del auto salen de la publicación aunque mande otros
        $this->ingresarComoUsuario();
        $r = $this->pedir('POST', '/api/inspecciones', ['url' => $url, 'mecanico_id' => $mecanicoId,
            'titulo' => 'Otro auto', 'anio' => 2010, 'kilometraje' => 1, 'precio_publicado' => 1000, 'localidad' => 'Otra']);
        self::assertSame(201, $r['status'], json_encode($r['json']));
        $i = $r['json']['inspeccion'];
        self::assertSame(['autocar', $vehiculo, 'Fiat Cronos Drive', 2022, 60000, 21000000, 'Luján'],
            [$i['fuente'], $i['vehiculo_id'], $i['titulo'], $i['anio'], $i['kilometraje'], $i['precio_publicado'], $i['localidad']]);

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
}
