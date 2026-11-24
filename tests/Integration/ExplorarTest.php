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
        self::assertContains('marca', array_column($filas, 'tipo'));
        return array_column($filas[0]['items'], 'id');
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

        $db = Entorno::pdo();
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
