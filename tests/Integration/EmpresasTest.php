<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

use AutoCar\Catalogo\PrecioMercado;
use AutoCar\MetaBusqueda\Mercado;

/**
 * Empresas (033): una concesionaria pide sumarse, no ingresa hasta que la aprueban, publica sin límite (un particular
 * hasta 3), sus autos llevan la insignia, tienen vitrina, no salen en el metabuscador, cuentan las visitas para su panel
 * y paga el abono en la grilla de Afiliaciones.
 */
final class EmpresasTest extends ApiTestCase
{
    private static function solicitud(string $email, array $cambios = []): array
    {
        return $cambios + [
            'responsable' => 'Ana Pérez', 'email' => $email, 'password' => self::PASSWORD,
            'razon_social' => 'Autos ' . bin2hex(random_bytes(3)) . ' SA', 'cuit' => '30-71234567-9', 'localidad' => 'Luján',
            'telefono' => '2323 445566', 'condiciones' => true,
        ];
    }

    public function testUnaEmpresaSeSumaPublicaSinLimiteYTieneSuInsigniaVitrinaYPanel(): void
    {
        $email = self::emailNuevo();
        $datos = self::solicitud($email);
        self::assertSame(422, $this->pedir('POST', '/api/solicitudes-empresa', ['cuit' => '1'] + $datos)['status']);
        $r = $this->pedir('POST', '/api/solicitudes-empresa', $datos);
        self::assertSame(201, $r['status'], json_encode($r['json']));
        self::assertSame('pendiente', $r['json']['solicitud']['estado']);
        $empresaId = $r['json']['solicitud']['usuario_id'];
        self::assertSame(409, $this->pedir('POST', '/api/solicitudes-empresa', $datos)['status']);

        // No ingresa hasta que la aprueban
        self::assertSame('empresa_pendiente', $this->ingresar($email)['json']['error']);
        $this->ingresarComoAdmin();
        $cola = $this->pedir('GET', '/api/admin/solicitudes-empresa?estado=pendiente')['json']['items'];
        self::assertContains($empresaId, array_column($cola, 'usuario_id'));
        $aprobada = $this->pedir('PATCH', "/api/admin/solicitudes-empresa/$empresaId", ['aprobar' => true]);
        self::assertSame('aprobado', $aprobada['json']['solicitud']['estado']);

        // Aprobada: publica más de 3 autos
        $ingreso = $this->ingresar($email);
        self::assertSame(200, $ingreso['status']);
        self::assertSame('empresa', $ingreso['json']['usuario']['rol']);
        $marca = 'Marca' . bin2hex(random_bytes(3));
        self::asegurarModelo($marca, 'Uno');
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $p = $this->pedirFormulario('/api/mis-vehiculos', ['marca' => $marca, 'modelo' => 'Uno', 'anio' => '2019']);
            self::assertSame(201, $p['status'], json_encode($p['json']));
            $ids[] = $p['json']['vehiculo']['id'];
        }
        Entorno::pdo()->prepare("UPDATE vehiculo SET estado = 'disponible', precio = 9000000, kilometraje = 60000, combustible = 'nafta',
            transmision = 'manual', condicion = 'bueno', localidad = 'Luján' WHERE id = ?")->execute([$ids[0]]);

        // Su panel (otro rol no entra)
        $panel = $this->pedir('GET', '/api/empresa/panel?dias=7');
        self::assertSame(200, $panel['status'], json_encode($panel['json']));
        self::assertCount(4, $panel['json']['autos']);
        self::assertSame(4, $panel['json']['resumen']['autos_activos']);
        self::assertCount(7, $panel['json']['serie']);

        // Un particular: hasta 3 autos activos
        $this->ingresarComoUsuario();
        self::assertSame(403, $this->pedir('GET', '/api/empresa/panel')['status']);
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(201, $this->pedirFormulario('/api/mis-vehiculos', ['marca' => $marca, 'modelo' => 'Uno', 'anio' => '2015'])['status']);
        }
        $cuarto = $this->pedirFormulario('/api/mis-vehiculos', ['marca' => $marca, 'modelo' => 'Uno', 'anio' => '2015']);
        self::assertSame(409, $cuarto['status']);
        self::assertSame('limite_particular', $cuarto['json']['error']);

        // Explorar: la insignia, el filtro por vendedor y el detalle (la visita cuenta para el panel de la empresa)
        $deEmpresas = $this->pedir('GET', '/api/vehiculos?vendedor=empresa&orden=recientes&q=' . $marca)['json']['items'];
        self::assertSame([$ids[0]], array_column($deEmpresas, 'id'));
        self::assertSame($datos['razon_social'], $deEmpresas[0]['empresa']['nombre']);
        self::assertSame([], $this->pedir('GET', '/api/vehiculos?vendedor=particular&q=' . $marca)['json']['items']);
        $relevantes = $this->pedir('GET', '/api/vehiculos?q=' . $marca);
        self::assertSame('relevantes', $relevantes['json']['orden']);
        self::assertSame([$ids[0]], array_column($relevantes['json']['items'], 'id'));
        $detalle = $this->pedir('GET', "/api/vehiculos/{$ids[0]}")['json']['vehiculo'];
        self::assertSame($empresaId, $detalle['empresa']['id']);
        $this->pedir('GET', "/api/vehiculos/{$ids[0]}"); // la misma persona el mismo día: una sola visita
        $visitas = Entorno::pdo()->prepare('SELECT COUNT(*) FROM vehiculo_visita WHERE vehiculo_id = ?');
        $visitas->execute([$ids[0]]);
        self::assertSame(1, (int)$visitas->fetchColumn());

        // Vitrina pública
        $vitrina = $this->pedir('GET', "/api/empresas/$empresaId")['json'];
        self::assertSame($datos['razon_social'], $vitrina['empresa']['nombre']);
        self::assertSame([$ids[0]], array_column($vitrina['vehiculos'], 'id'));
        self::assertArrayNotHasKey('email', $vitrina['empresa']);

        // El scraper lee los autos de Explorar de la API pública y deja el cálculo en un documento; Explorar lo copia a Neon
        $paraElScraper = array_column($this->pedir('GET', '/api/vehiculos/mercado')['json']['vehiculos'], null, 'id');
        self::assertEquals(9000000, $paraElScraper[$ids[0]]['precio']);
        Entorno::documentos()->escribir([PrecioMercado::DOCUMENTO => ['fecha' => date(DATE_ATOM) . bin2hex(random_bytes(2)), 'autos' => [
            (string)$ids[0] => ['precio' => 9000000, 'kilometraje' => 60000, 'anio' => 2019, 'suficientes' => true,
                'diferencia_pct' => -12.5, 'mediana_precio' => 10300000, 'mediana_km' => 70000, 'comparables' => 8],
        ]]]);
        @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'autocar-precios-explorar.json'); // que revise ya
        $this->pedir('GET', '/api/vehiculos?q=' . $marca);
        $copiado = Entorno::pdo()->prepare('SELECT diferencia_pct FROM vehiculo_mercado WHERE vehiculo_id = ?');
        $copiado->execute([$ids[0]]);
        self::assertEquals(-12.5, $copiado->fetchColumn());

        // No sale en el metabuscador (los autos de AutoCar de particulares sí)
        $portal = (new Mercado(Entorno::documentos(), Entorno::pdo()))->delPortal(['marca' => $marca, 'modelo' => 'Uno']);
        self::assertNotContains($ids[0], array_column($portal, 'id'));

        // El panel de la empresa ve esa visita, y paga su abono en la grilla de Afiliaciones
        $this->ingresar($email);
        $p = $this->pedir('GET', '/api/empresa/panel')['json'];
        self::assertSame(1, $p['resumen']['visitas']);
        self::assertSame($ids[0], $p['mas_vista']['id']);
        $this->ingresarComoAdmin();
        $precios = $this->pedir('POST', '/api/admin/precios-mercado');
        self::assertSame(200, $precios['status'], json_encode($precios['json']));
        self::assertGreaterThanOrEqual(1, $precios['json']['autos']);
        $guardado = Entorno::pdo()->prepare('SELECT comparables FROM vehiculo_mercado WHERE vehiculo_id = ?');
        $guardado->execute([$ids[0]]);
        self::assertNotFalse($guardado->fetchColumn());
        $mes = $this->pedir('GET', '/api/admin/afiliacion')['json']['este_mes'];
        $fila = array_values(array_filter($mes['filas'], static fn(array $f): bool => $f['tipo'] === 'empresa' && $f['socio'] === (string)$empresaId))[0];
        self::assertEquals(50000, $fila['monto']);
        self::assertGreaterThanOrEqual(50000, $mes['de_abonos']);
    }

    public function testUnaSolicitudRechazadaSeVuelveAMandarConLaMismaContrasena(): void
    {
        $email = self::emailNuevo();
        $id = $this->pedir('POST', '/api/solicitudes-empresa', self::solicitud($email))['json']['solicitud']['usuario_id'];
        $this->ingresarComoAdmin();
        self::assertSame(422, $this->pedir('PATCH', "/api/admin/solicitudes-empresa/$id", ['aprobar' => false])['status']);
        $this->pedir('PATCH', "/api/admin/solicitudes-empresa/$id", ['aprobar' => false, 'motivo' => 'El CUIT no coincide']);
        self::assertSame('empresa_rechazada', $this->ingresar($email)['json']['error']);
        self::assertSame(422, $this->pedir('POST', '/api/solicitudes-empresa', self::solicitud($email, ['password' => 'Otra12345']))['status']);
        self::assertSame(201, $this->pedir('POST', '/api/solicitudes-empresa', self::solicitud($email))['status']);
        // Un email de una cuenta personal no sirve para la empresa
        self::assertSame(422, $this->pedir('POST', '/api/solicitudes-empresa', self::solicitud($this->registrar()))['status']);
    }
}
