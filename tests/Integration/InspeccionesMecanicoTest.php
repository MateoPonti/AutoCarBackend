<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

use AutoCar\Inspecciones\Checklist;
use AutoCar\Inspecciones\Huella;
use AutoCar\Inspecciones\Inspeccion;
use AutoCar\Inspecciones\Precio;

/**
 * Lado del mecánico (B2, B6 y B7 del PLAN.md): perfil, bandeja, aceptar o rechazar, checklist y firma.
 * Las inspecciones se cargan directo en la base porque el pedido del comprador (B4) todavía no existe.
 */
final class InspeccionesMecanicoTest extends ApiTestCase
{
    private const PERFIL = [
        'nombre' => 'Taller de Prueba', 'direccion' => 'Calle 1 123', 'localidad' => 'Mercedes',
        'telefono' => '02324 123456', 'precio_inspeccion' => 130000,
    ];

    private static function idDe(string $email): int
    {
        $st = Entorno::pdo()->prepare('SELECT id FROM usuario WHERE email = :e');
        $st->execute(['e' => $email]);
        return (int)$st->fetchColumn();
    }

    /** Mecánico nuevo, ya logueado. Con $perfil, también con el taller cargado. */
    private function ingresarComoMecanico(bool $perfil = true): int
    {
        $email = $this->registrar();
        Entorno::pdo()
            ->prepare("UPDATE usuario SET rol_id = (SELECT id FROM rol WHERE nombre = 'mecanico') WHERE email = :e")
            ->execute(['e' => $email]);
        self::assertSame(200, $this->ingresar($email)['status']);
        if ($perfil) {
            self::assertSame(200, $this->pedir('PUT', '/api/mecanico/perfil', self::PERFIL)['status']);
        }
        return self::idDe($email);
    }

    /** Una inspección pedida por un comprador nuevo a ese mecánico. → [id de la inspección, email del comprador] */
    private function pedidaA(int $mecanicoId): array
    {
        $email = $this->registrar();
        $d = Precio::desglose(130000);
        $st = Entorno::pdo()->prepare(
            "INSERT INTO inspeccion (comprador_id, mecanico_id, url, fuente, titulo, anio, kilometraje, localidad,
               precio, monto_mecanico, monto_autocar, monto_fondo)
             VALUES (:c, :m, 'https://auto.mercadolibre.com.ar/MLA-1-gol-_JM', 'mercadolibre', 'VW Gol Trend', 2018,
               98000, 'Luján', :p, :mm, :ma, :mf) RETURNING id"
        );
        $st->execute(['c' => self::idDe($email), 'm' => $mecanicoId, 'p' => $d['precio'],
            'mm' => $d['monto_mecanico'], 'ma' => $d['monto_autocar'], 'mf' => $d['monto_fondo']]);
        return [(int)$st->fetchColumn(), $email];
    }

    private static function turno(string $cuando = '+2 days'): string
    {
        return (new \DateTimeImmutable($cuando))->format(DATE_ATOM);
    }

    private static function checklistCompleto(): array
    {
        $items = [];
        foreach (array_keys(Checklist::ITEMS) as $codigo) {
            $items[] = ['codigo' => $codigo, 'resultado' => 'ok', 'nota' => ''];
        }
        $items[0] = ['codigo' => 'carroceria', 'resultado' => 'observado', 'nota' => 'Rayón en la puerta trasera'];
        return $items;
    }

    // --- Perfil (B2)

    public function testElMecanicoCargaYEditaSuPerfil(): void
    {
        $this->ingresarComoMecanico(false);
        $r = $this->pedir('GET', '/api/mecanico/perfil');
        self::assertSame(200, $r['status']);
        self::assertNull($r['json']['taller']);

        $mal = $this->pedir('PUT', '/api/mecanico/perfil', ['nombre' => 'X', 'precio_inspeccion' => 10] + self::PERFIL);
        self::assertSame(422, $mal['status']);
        self::assertArrayHasKey('nombre', $mal['json']['errores']);
        self::assertArrayHasKey('precio_inspeccion', $mal['json']['errores']);

        self::assertSame(200, $this->pedir('PUT', '/api/mecanico/perfil', self::PERFIL)['status']);
        $editado = $this->pedir('PUT', '/api/mecanico/perfil', ['precio_inspeccion' => 150000] + self::PERFIL);
        self::assertSame(150000.0, (float)$editado['json']['taller']['precio_inspeccion']);
        self::assertSame('Taller de Prueba', $this->pedir('GET', '/api/mecanico/perfil')['json']['taller']['nombre']);
    }

    public function testUnUsuarioComunNoEntraAlPanelDelMecanico(): void
    {
        $this->ingresarComoUsuario();
        self::assertSame(403, $this->pedir('GET', '/api/mecanico/perfil')['status']);
        self::assertSame(403, $this->pedir('GET', '/api/mecanico/inspecciones')['status']);
    }

    public function testSinSesionDa401(): void
    {
        self::assertSame(401, $this->pedir('GET', '/api/mecanico/inspecciones')['status']);
        self::assertSame(401, $this->pedir('GET', '/api/inspecciones/1')['status']);
    }

    // --- Flujo completo (B6 y B7)

    public function testPedidaAceptadaChecklistYFirma(): void
    {
        $mecanicoId = $this->ingresarComoMecanico();
        [$id] = $this->pedidaA($mecanicoId);

        $bandeja = $this->pedir('GET', '/api/mecanico/inspecciones?estado=pedida');
        self::assertSame(200, $bandeja['status']);
        self::assertSame([$id], array_column($bandeja['json']['items'], 'id'));
        self::assertSame(1, $bandeja['json']['conteos']['pedida']);
        self::assertSame(100000.0, (float)$bandeja['json']['items'][0]['monto_mecanico']);

        // No se carga el informe antes de aceptar
        self::assertSame(409, $this->pedir('PUT', "/api/mecanico/inspecciones/$id/informe", ['items' => []])['status']);

        $pasado = $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'aceptar', 'fecha_turno' => self::turno('-1 day')]);
        self::assertSame(422, $pasado['status']);
        self::assertArrayHasKey('fecha_turno', $pasado['json']['errores']);

        $ok = $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'aceptar', 'fecha_turno' => self::turno()]);
        self::assertSame(200, $ok['status'], json_encode($ok['json']));
        self::assertSame('aceptada', $ok['json']['inspeccion']['estado']);
        self::assertNotNull($ok['json']['inspeccion']['fecha_turno']);
        self::assertSame(409, $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'aceptar', 'fecha_turno' => self::turno()])['status']);

        // Borrador a medias; una falla sin nota no se guarda
        $sinNota = $this->pedir('PUT', "/api/mecanico/inspecciones/$id/informe",
            ['items' => [['codigo' => 'motor', 'resultado' => 'falla', 'nota' => '']]]);
        self::assertSame(422, $sinNota['status']);
        $borrador = $this->pedir('PUT', "/api/mecanico/inspecciones/$id/informe",
            ['items' => array_slice(self::checklistCompleto(), 0, 3)]);
        self::assertSame(200, $borrador['status'], json_encode($borrador['json']));
        self::assertCount(3, $borrador['json']['inspeccion']['items']);

        $incompleta = $this->pedir('POST', "/api/mecanico/inspecciones/$id/firma", []);
        self::assertSame(422, $incompleta['status']);
        self::assertSame('informe_incompleto', $incompleta['json']['error']);

        // Completo pero sin conclusión: tampoco
        $this->pedir('PUT', "/api/mecanico/inspecciones/$id/informe", ['items' => self::checklistCompleto()]);
        self::assertSame(422, $this->pedir('POST', "/api/mecanico/inspecciones/$id/firma", [])['status']);

        $this->pedir('PUT', "/api/mecanico/inspecciones/$id/informe",
            ['items' => self::checklistCompleto(), 'conclusion' => 'recomendable', 'resumen' => 'Buen estado general.']);
        $firma = $this->pedir('POST', "/api/mecanico/inspecciones/$id/firma", []);
        self::assertSame(200, $firma['status'], json_encode($firma['json']));
        $i = $firma['json']['inspeccion'];
        self::assertSame('firmada', $i['estado']);
        self::assertSame('liberado', $i['pago']);
        self::assertSame('Prueba', $i['firmado_por']);
        self::assertSame('Taller de Prueba', $i['taller_firma']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $i['huella']);
        self::assertCount(10, $i['items']);

        // La huella se puede volver a calcular con lo que quedó guardado
        $items = [];
        foreach ($i['items'] as $item) {
            $items[$item['codigo']] = [$item['resultado'], $item['nota']];
        }
        self::assertSame($i['huella'], Huella::calcular($id, $items, $i['conclusion'], $i['resumen'],
            $i['firmado_por'], $i['taller_firma'], Inspeccion::momentoHuella($i['firmada_at'])));

        // Firmada no cambia más
        $cambio = $this->pedir('PUT', "/api/mecanico/inspecciones/$id/informe", ['items' => self::checklistCompleto(), 'conclusion' => 'no_recomendable']);
        self::assertSame(409, $cambio['status']);
        self::assertSame('firmada', $cambio['json']['error']);
        self::assertSame(409, $this->pedir('POST', "/api/mecanico/inspecciones/$id/firma", [])['status']);
        self::assertSame(1, $this->pedir('GET', '/api/mecanico/inspecciones?estado=firmada')['json']['conteos']['firmada']);
    }

    public function testRechazarPideMotivoYReembolsa(): void
    {
        $mecanicoId = $this->ingresarComoMecanico();
        [$id] = $this->pedidaA($mecanicoId);

        $sinMotivo = $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'rechazar']);
        self::assertSame(422, $sinMotivo['status']);
        self::assertArrayHasKey('motivo', $sinMotivo['json']['errores']);
        self::assertSame(422, $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'borrar'])['status']);

        $r = $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'rechazar', 'motivo' => 'No tengo turnos esta semana']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame('rechazada', $r['json']['inspeccion']['estado']);
        self::assertSame('reembolsado', $r['json']['inspeccion']['pago']);
        self::assertSame('No tengo turnos esta semana', $r['json']['inspeccion']['motivo_rechazo']);
        self::assertSame(409, $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'aceptar', 'fecha_turno' => self::turno()])['status']);
    }

    public function testSinPerfilNoAceptaPedidos(): void
    {
        $mecanicoId = $this->ingresarComoMecanico(false);
        [$id] = $this->pedidaA($mecanicoId);
        $r = $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'aceptar', 'fecha_turno' => self::turno()]);
        self::assertSame(409, $r['status']);
        self::assertSame('sin_taller', $r['json']['error']);
    }

    public function testElFiltroDeEstadoSeValida(): void
    {
        $this->ingresarComoMecanico();
        self::assertSame(422, $this->pedir('GET', '/api/mecanico/inspecciones?estado=cualquiera')['status']);
        $todas = $this->pedir('GET', '/api/mecanico/inspecciones');
        self::assertSame(200, $todas['status']);
        self::assertSame([], $todas['json']['items']);
    }

    // --- Quién ve qué

    public function testOtroMecanicoYOtroUsuarioReciben404(): void
    {
        $mecanicoId = $this->ingresarComoMecanico();
        [$id, $comprador] = $this->pedidaA($mecanicoId);

        $this->ingresarComoMecanico(); // otro mecánico
        self::assertSame(404, $this->pedir('GET', "/api/inspecciones/$id")['status']);
        self::assertSame(404, $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'rechazar', 'motivo' => 'No es mía'])['status']);
        self::assertSame(404, $this->pedir('POST', "/api/mecanico/inspecciones/$id/firma", [])['status']);
        self::assertSame([], $this->pedir('GET', '/api/mecanico/inspecciones')['json']['items']);

        $this->ingresarComoUsuario(); // un usuario cualquiera
        self::assertSame(404, $this->pedir('GET', "/api/inspecciones/$id")['status']);

        self::assertSame(200, $this->ingresar($comprador)['status']); // el comprador sí la ve
        $detalle = $this->pedir('GET', "/api/inspecciones/$id");
        self::assertSame(200, $detalle['status']);
        self::assertSame('pedida', $detalle['json']['inspeccion']['estado']);
        self::assertSame([], $detalle['json']['inspeccion']['items']);

        $this->ingresarComoAdmin(); // quien resuelve reclamos también
        self::assertSame(200, $this->pedir('GET', "/api/inspecciones/$id")['status']);
    }

    public function testCambiarDatosSinTokenCsrfDa403(): void
    {
        $this->ingresarComoMecanico(false);
        self::assertSame(403, $this->pedir('PUT', '/api/mecanico/perfil', self::PERFIL, [], false)['status']);
    }
}
