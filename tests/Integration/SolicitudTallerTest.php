<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/**
 * "Adherí tu taller" de punta a punta por la API: el usuario pide sumarse, no aparece en el ranking hasta que
 * un admin lo aprueba, y al aprobarlo pasa a rol mecanico y ya puede usar su panel.
 */
final class SolicitudTallerTest extends ApiTestCase
{
    private static function formulario(string $localidad): array
    {
        return [
            'nombre' => 'Taller Solicitud', 'cuit' => '30-12345678-9', 'direccion' => 'Calle 1 123',
            'localidad' => $localidad, 'telefono' => '02324 123456', 'horarios' => 'Lun a vie 8 a 18',
            'precio_inspeccion' => 120000, 'condiciones' => true,
        ];
    }

    private function ranking(string $localidad): array
    {
        $r = $this->pedir('GET', '/api/mecanicos?localidad=' . urlencode($localidad));
        self::assertSame(200, $r['status']);
        return $r['json']['items'];
    }

    public function testPedirSumarseYQueLoApruebenLoPasaAMecanico(): void
    {
        $localidad = 'Pueblo ' . bin2hex(random_bytes(3)); // propia, para no mezclarse con otras pruebas
        $email = $this->ingresarComoUsuario();

        // 1. Todavía no pidió nada
        self::assertNull($this->pedir('GET', '/api/mi-solicitud-taller')['json']['solicitud']);

        // 2. Manda la solicitud: queda pendiente y NO aparece en el ranking
        $r = $this->pedir('POST', '/api/mi-solicitud-taller', self::formulario($localidad));
        self::assertSame(201, $r['status'], json_encode($r['json']));
        self::assertSame('pendiente', $r['json']['solicitud']['estado']);
        $usuarioId = $r['json']['solicitud']['usuario_id'];
        self::assertSame([], $this->ranking($localidad));

        // 3. No puede mandar otra mientras está pendiente, ni entrar al panel del mecánico
        self::assertSame(409, $this->pedir('POST', '/api/mi-solicitud-taller', self::formulario($localidad))['status']);
        self::assertSame(403, $this->pedir('GET', '/api/mecanico/perfil')['status']);

        // 4. Un usuario común no ve la cola del admin
        self::assertSame(403, $this->pedir('GET', '/api/admin/solicitudes-taller')['status']);

        // 5. El admin la ve en la cola y la aprueba
        $this->ingresarComoAdmin();
        $cola = $this->pedir('GET', '/api/admin/solicitudes-taller?estado=pendiente');
        self::assertSame(200, $cola['status']);
        self::assertContains($usuarioId, array_column($cola['json']['items'], 'usuario_id'));
        $ok = $this->pedir('PATCH', "/api/admin/solicitudes-taller/$usuarioId", ['aprobar' => true]);
        self::assertSame(200, $ok['status'], json_encode($ok['json']));
        self::assertSame('aprobado', $ok['json']['solicitud']['estado']);
        self::assertSame('mecanico', $ok['json']['solicitud']['rol']);

        // 6. No se puede resolver dos veces
        self::assertSame(409, $this->pedir('PATCH', "/api/admin/solicitudes-taller/$usuarioId", ['aprobar' => true])['status']);

        // 7. Ya está en el ranking y, con su usuario de siempre, entra a su panel de mecánico
        $enLaRed = $this->ranking($localidad);
        self::assertCount(1, $enLaRed);
        self::assertSame('Taller Solicitud', $enLaRed[0]['taller']);
        self::assertSame(200, $this->ingresar($email)['status']);
        $perfil = $this->pedir('GET', '/api/mecanico/perfil');
        self::assertSame(200, $perfil['status']);
        self::assertSame('aprobado', $perfil['json']['taller']['estado']);
    }

    public function testRechazadaConMotivoYPuedeVolverAPedir(): void
    {
        $localidad = 'Pueblo ' . bin2hex(random_bytes(3));
        $email = $this->ingresarComoUsuario();
        $r = $this->pedir('POST', '/api/mi-solicitud-taller', self::formulario($localidad));
        $usuarioId = $r['json']['solicitud']['usuario_id'];

        $this->ingresarComoAdmin();
        self::assertSame(422, $this->pedir('PATCH', "/api/admin/solicitudes-taller/$usuarioId", ['aprobar' => false])['status']);
        $no = $this->pedir('PATCH', "/api/admin/solicitudes-taller/$usuarioId", ['aprobar' => false, 'motivo' => 'El CUIT no coincide con el taller.']);
        self::assertSame(200, $no['status']);
        self::assertSame('rechazado', $no['json']['solicitud']['estado']);
        self::assertSame('usuario', $no['json']['solicitud']['rol']);
        self::assertSame([], $this->ranking($localidad));

        // El usuario ve el motivo y la puede volver a mandar corregida
        self::assertSame(200, $this->ingresar($email)['status']);
        $mia = $this->pedir('GET', '/api/mi-solicitud-taller')['json']['solicitud'];
        self::assertSame('El CUIT no coincide con el taller.', $mia['motivo_rechazo']);
        $otra = $this->pedir('POST', '/api/mi-solicitud-taller', ['cuit' => '30-87654321-0'] + self::formulario($localidad));
        self::assertSame(201, $otra['status']);
        self::assertSame('pendiente', $otra['json']['solicitud']['estado']);
        self::assertNull($otra['json']['solicitud']['motivo_rechazo']);
    }

    public function testValidacionYRoles(): void
    {
        $this->ingresarComoUsuario();
        $r = $this->pedir('POST', '/api/mi-solicitud-taller', ['condiciones' => false]);
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('cuit', $r['json']['errores']);
        self::assertArrayHasKey('condiciones', $r['json']['errores']);

        // Un admin no pide sumarse como taller
        $this->ingresarComoAdmin();
        self::assertSame(409, $this->pedir('POST', '/api/mi-solicitud-taller', self::formulario('Mercedes'))['status']);

        // Sin sesión, nada
        self::assertSame(204, $this->pedir('POST', '/api/logout')['status']);
        self::assertSame(401, $this->pedir('GET', '/api/mi-solicitud-taller')['status']);
    }
}
