<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/** Idea B, fallas típicas por modelo: origen, voto de los mecánicos y porcentaje medido en las inspecciones. */
final class FallasTipicasTest extends ApiTestCase
{
    use ConInspecciones;

    public function testOrigenVotosYMedicion(): void
    {
        // Un modelo propio de esta prueba, para que otras no cambien los números
        $modelo = 'Modelo ' . bin2hex(random_bytes(3));
        self::asegurarModelo('Volkswagen', $modelo);
        $q = '/api/fallas-tipicas?marca=Volkswagen&modelo=' . rawurlencode($modelo);

        self::assertSame(422, $this->pedir('GET', '/api/fallas-tipicas?marca=Pepita&modelo=21021')['status']);
        $vacio = $this->pedir('GET', $q)['json']['fallas_tipicas'];
        self::assertSame([0, 20, false, []], [$vacio['inspecciones'], $vacio['minimo'], $vacio['medido'], $vacio['fallas']]);
        self::assertSame('volkswagen_' . str_replace(' ', '_', strtolower($modelo)), $vacio['clave']);

        // El admin carga una de internet (con la fuente); un usuario no puede
        $this->ingresarComoUsuario();
        self::assertSame(403, $this->pedir('POST', '/api/admin/fallas-tipicas', [])['status']);
        $this->ingresarComoAdmin();
        $sinFuente = $this->pedir('POST', '/api/admin/fallas-tipicas', ['marca' => 'Volkswagen', 'modelo' => $modelo,
            'item_codigo' => 'suspension', 'titulo' => 'Bujes de parrilla gastados']);
        self::assertSame(422, $sinFuente['status']);
        $internet = $this->pedir('POST', '/api/admin/fallas-tipicas', ['marca' => 'Volkswagen', 'modelo' => $modelo,
            'item_codigo' => 'suspension', 'titulo' => 'Bujes de parrilla gastados', 'fuente_url' => 'https://foro.example/bujes']);
        self::assertSame(201, $internet['status'], json_encode($internet['json']));
        $fallaId = $internet['json']['id'];

        // Un mecánico propone otra (queda con su voto); tres mecánicos confirman la de internet
        [, $mecanico] = $this->ingresarComoMecanico();
        $propia = $this->pedir('POST', '/api/mecanico/fallas-tipicas', ['marca' => 'Volkswagen', 'modelo' => $modelo,
            'item_codigo' => 'electricidad', 'titulo' => 'Falla el sensor de temperatura']);
        self::assertSame(201, $propia['status'], json_encode($propia['json']));
        $mia = array_values(array_filter($propia['json']['fallas_tipicas']['fallas'], static fn($f) => $f['origen'] === 'mecanicos'))[0];
        self::assertSame([true, 'sin_confirmar'], [$mia['mi_voto'], $mia['estado']]);

        $this->pedir('PUT', "/api/mecanico/fallas-tipicas/$fallaId/voto", ['confirma' => true]);
        $this->ingresarComoMecanico();
        $this->pedir('PUT', "/api/mecanico/fallas-tipicas/$fallaId/voto", ['confirma' => true]);
        $this->ingresarComoMecanico();
        self::assertSame(422, $this->pedir('PUT', "/api/mecanico/fallas-tipicas/$fallaId/voto", ['confirma' => 'si'])['status']);
        self::assertSame(404, $this->pedir('PUT', '/api/mecanico/fallas-tipicas/999999/voto', ['confirma' => true])['status']);
        $r = $this->pedir('PUT', "/api/mecanico/fallas-tipicas/$fallaId/voto", ['confirma' => true]);
        $deInternet = array_values(array_filter($r['json']['fallas_tipicas']['fallas'], static fn($f) => $f['id'] === $fallaId))[0];
        self::assertSame(['confirmada', 3, 0], [$deInternet['estado'], $deInternet['votos']['confirman'], $deInternet['votos']['descartan']]);

        // Tres descartan la del mecánico: el público ya no la ve; el mecánico sí
        for ($n = 0; $n < 4; $n++) {
            $this->ingresarComoMecanico();
            $this->pedir('PUT', "/api/mecanico/fallas-tipicas/{$propia['json']['id']}/voto", ['confirma' => false]);
        }
        $publico = $this->pedir('GET', $q)['json']['fallas_tipicas'];
        self::assertSame([$fallaId], array_column($publico['fallas'], 'id'));
        self::assertNull($publico['fallas'][0]['porcentaje'], 'con menos de 20 inspecciones no se muestra el porcentaje');
        $this->ingresar($mecanico);
        self::assertCount(2, $this->pedir('GET', '/api/mecanico/fallas-tipicas?marca=Volkswagen&modelo=' . rawurlencode($modelo))['json']['fallas_tipicas']['fallas']);

        // Con 20 inspecciones firmadas del modelo, la mitad con la suspensión mal, se mide: 50 %
        $this->cargarFirmadas('Volkswagen', $modelo, 20, 'suspension', 10);
        $medido = $this->pedir('GET', $q)['json']['fallas_tipicas'];
        self::assertSame([20, true], [$medido['inspecciones'], $medido['medido']]);
        self::assertEquals(50, $medido['fallas'][0]['porcentaje']);
        self::assertSame('suspension', $medido['medidas'][0]['item_codigo']);
    }

    /** Inspecciones firmadas cargadas directo en la base (con la API serían 20 recorridos completos). */
    private function cargarFirmadas(string $marca, string $modelo, int $cuantas, string $item, int $conFalla): void
    {
        $pdo = Entorno::pdo();
        $comprador = self::idDe($this->registrar());
        [$mecanico] = $this->ingresarComoMecanico();
        for ($n = 0; $n < $cuantas; $n++) {
            $st = $pdo->prepare("INSERT INTO inspeccion (comprador_id, mecanico_id, url, fuente, estado, precio, monto_mecanico,
                monto_autocar, monto_fondo, pago, conclusion, firmado_por, taller_firma, firmada_at, huella, patente, marca, modelo)
                VALUES (:c, :m, :u, 'mercadolibre', 'firmada', 100000, 77000, 23000, 8000, 'liberado', 'recomendable', 'Prueba',
                'Taller de Prueba', now(), :h, :p, :marca, :modelo) RETURNING id");
            $st->execute(['c' => $comprador, 'm' => $mecanico, 'u' => "https://auto.mercadolibre.com.ar/MLA-9$n-x-_JM",
                'h' => str_repeat('a', 64), 'p' => sprintf('AB%03dCD', $n), 'marca' => $marca, 'modelo' => $modelo]);
            $pdo->prepare("INSERT INTO inspeccion_item (inspeccion_id, codigo, resultado, nota) VALUES (:i, :c, :r, :n)")
                ->execute(['i' => $st->fetchColumn(), 'c' => $item, 'r' => $n < $conFalla ? 'falla' : 'ok', 'n' => $n < $conFalla ? 'Gastado' : null]);
        }
    }
}
