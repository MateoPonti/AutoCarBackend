<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

final class ExplorarTest extends ApiTestCase
{
    use ConInspecciones;

    private function verificados(): array
    {
        $r = $this->pedir('GET', '/api/explorar');
        self::assertSame(200, $r['status'], json_encode($r['json']));
        $filas = $r['json']['filas'];
        self::assertSame('verificados', $filas[0]['tipo']);
        self::assertSame(['verificados', 'asociadas'], array_column($filas, 'tipo'));
        return array_column($filas[0]['items'], 'id');
    }

    public function testLasVariantesDeUnaZonaCompartenOpcionYPublicaciones(): void
    {
        $ids = [];
        foreach (['Mercedes', 'Mercedes, Buenos Aires', 'Mercedes, Mercedes, Bs.',
                  'Mercedes, Corrientes', 'Luján', 'Lujan, Buenos Aires'] as $zona) {
            $ids[$zona] = $this->vehiculoDisponible();
        }
        $actualizar = Entorno::pdo()->prepare('UPDATE vehiculo SET localidad = ? WHERE id = ?');
        foreach ($ids as $zona => $id) {
            $actualizar->execute([$zona, $id]);
        }
        $r = $this->pedir('GET', '/api/zonas?zona=Mercedes%2C%20Bs.%20As.')['json'];
        self::assertSame('Mercedes', $r['seleccionada']);
        self::assertContains('Mercedes, Corrientes', $r['zonas']);
        self::assertContains('Luján', $r['zonas']);
        self::assertNotContains('Mercedes, Buenos Aires', $r['zonas']);
        self::assertNotContains('Lujan, Buenos Aires', $r['zonas']);
        self::assertSame('', $this->pedir('GET', '/api/zonas')['json']['seleccionada']);

        foreach (['relevantes', 'recientes'] as $orden) {
            $items = $this->pedir('GET', "/api/vehiculos?marca=Peugeot&zona=Mercedes&orden=$orden")['json']['items'];
            self::assertSame(['Mercedes'], array_values(array_unique(array_column($items, 'localidad'))));
            $autos = array_column($items, 'id');
            foreach (array_slice($ids, 0, 3) as $id) {
                self::assertContains($id, $autos);
            }
            self::assertNotContains($ids['Mercedes, Corrientes'], $autos);
        }
        $lujan = $this->pedir('GET', '/api/vehiculos?zona=lujan&orden=recientes')['json']['items'];
        self::assertContains($ids['Luján'], array_column($lujan, 'id'));
        self::assertContains($ids['Lujan, Buenos Aires'], array_column($lujan, 'id'));
        self::assertSame(0, $this->pedir('GET', '/api/vehiculos?zona=SinAvisos')['json']['total']);
        self::assertSame(0, $this->pedir('GET', '/api/vehiculos?zona=Mercedes&marca=OtraMarca')['json']['total']);
    }

    public function testSoloLaUltimaFirmaFavorableVigenteDelAutoDisponibleLoVerifica(): void
    {
        [$mecanicoId, $mecanico] = $this->ingresarComoMecanico();
        $auto = $this->vehiculoDisponible(['marca' => 'Volkswagen', 'modelo' => 'Gol Trend']);
        $this->ingresarComoUsuario();
        $pedido = ['mecanico_id' => $mecanicoId, 'url' => "http://front.test/front/pages/vehiculos/vehiculo.html?id=$auto"];
        $id = $this->pedir('POST', '/api/inspecciones', $pedido)['json']['inspeccion']['id'];
        self::assertNotContains($auto, $this->verificados());

        $this->ingresar($mecanico);
        $this->aceptarYFirmar($id);
        self::assertContains($auto, $this->verificados());
        self::assertTrue($this->pedir('GET', "/api/vehiculos/$auto")['json']['vehiculo']['verificado']);
        $catalogo = array_column($this->pedir('GET', '/api/vehiculos?orden=recientes')['json']['items'], null, 'id');
        self::assertTrue($catalogo[$auto]['verificado']);

        $db = Entorno::pdo();
        $db->prepare("UPDATE vehiculo SET localidad = 'Mercedes, Bs. As.' WHERE id = ?")->execute([$auto]);
        $zona = $this->pedir('GET', '/api/explorar?zona=Mercedes')['json']['filas'];
        self::assertContains($auto, array_column($zona[0]['items'], 'id'));
        $otra = $this->pedir('GET', '/api/explorar?zona=lujan')['json']['filas'];
        self::assertNotContains($auto, array_column($otra[0]['items'], 'id'));

        $db->prepare("UPDATE inspeccion SET firmada_at = now() - interval '91 days' WHERE id = ?")->execute([$id]);
        self::assertNotContains($auto, $this->verificados());
        $db->prepare("UPDATE inspeccion SET firmada_at = now() - interval '1 day' WHERE id = ?")->execute([$id]);
        $db->prepare("UPDATE vehiculo SET kilometraje = kilometraje + 1 WHERE id = ?")->execute([$auto]);
        self::assertNotContains($auto, $this->verificados());
        $db->prepare("UPDATE vehiculo SET kilometraje = kilometraje - 1 WHERE id = ?")->execute([$auto]);

        $this->ingresarComoUsuario();
        $otro = $this->pedir('POST', '/api/inspecciones', $pedido)['json']['inspeccion']['id'];
        $this->ingresar($mecanico);
        $this->aceptarYFirmar($otro);
        self::assertSame(1, count(array_keys($this->verificados(), $auto, true)));
        $db->prepare("UPDATE inspeccion SET conclusion = 'no_recomendable' WHERE id = ?")->execute([$otro]);
        self::assertNotContains($auto, $this->verificados());
    }
}
