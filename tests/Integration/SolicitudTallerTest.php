<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/**
 * "Adherí tu taller" de punta a punta por la API: el taller manda la solicitud sin sesión y eso le crea una cuenta
 * aparte (otro email); no puede ingresar ni aparece en el ranking hasta que un admin lo aprueba.
 */
final class SolicitudTallerTest extends ApiTestCase
{
    /** Formulario completo: datos del taller + la cuenta nueva del taller. */
    private static function formulario(string $localidad, string $email): array
    {
        return [
            'nombre' => 'Taller Solicitud', 'cuit' => '30-12345678-9', 'direccion' => 'Calle 1 123',
            'localidad' => $localidad, 'telefono' => '02324 123456', 'horarios' => 'Lun a vie 8 a 18',
            'precio_inspeccion' => 120000, 'condiciones' => true,
            'responsable' => 'Responsable Prueba', 'email' => $email, 'password' => self::PASSWORD,
        ];
    }

    private function ranking(string $localidad): array
    {
        $r = $this->pedir('GET', '/api/mecanicos?localidad=' . urlencode($localidad));
        self::assertSame(200, $r['status']);
        return $r['json']['items'];
    }

    public function testMandarLaSolicitudCreaLaCuentaYEntraCuandoLaAprueban(): void
    {
        $localidad = 'Pueblo ' . bin2hex(random_bytes(3)); // propia, para no mezclarse con otras pruebas
        $email = self::emailNuevo();

        // 1. Sin sesión manda la solicitud: crea la cuenta del taller (rol mecanico) y queda pendiente
        $r = $this->pedir('POST', '/api/solicitudes-taller', self::formulario($localidad, $email));
        self::assertSame(201, $r['status'], json_encode($r['json']));
        self::assertSame('pendiente', $r['json']['solicitud']['estado']);
        self::assertSame('mecanico', $r['json']['solicitud']['rol']);
        self::assertSame($email, $r['json']['solicitud']['email']);
        $usuarioId = $r['json']['solicitud']['usuario_id'];
        self::assertSame([], $this->ranking($localidad));

        // 2. Con la contraseña correcta igual no puede ingresar mientras está pendiente, ni mandar otra
        $login = $this->ingresar($email);
        self::assertSame(403, $login['status']);
        self::assertSame('taller_pendiente', $login['json']['error']);
        self::assertSame(409, $this->pedir('POST', '/api/solicitudes-taller', self::formulario($localidad, $email))['status']);

        // 3. El admin la ve en la cola y la aprueba
        $this->ingresarComoAdmin();
        $cola = $this->pedir('GET', '/api/admin/solicitudes-taller?estado=pendiente');
        self::assertSame(200, $cola['status']);
        self::assertContains($usuarioId, array_column($cola['json']['items'], 'usuario_id'));
        $ok = $this->pedir('PATCH', "/api/admin/solicitudes-taller/$usuarioId", ['aprobar' => true]);
        self::assertSame(200, $ok['status'], json_encode($ok['json']));
        self::assertSame('aprobado', $ok['json']['solicitud']['estado']);

        // 4. No se puede resolver dos veces
        self::assertSame(409, $this->pedir('PATCH', "/api/admin/solicitudes-taller/$usuarioId", ['aprobar' => true])['status']);

        // 5. Ya está en el ranking y con la cuenta del taller entra a su panel de mecánico
        $enLaRed = $this->ranking($localidad);
        self::assertCount(1, $enLaRed);
        self::assertSame('Taller Solicitud', $enLaRed[0]['taller']);
        self::assertSame(200, $this->ingresar($email)['status']);
        $perfil = $this->pedir('GET', '/api/mecanico/perfil');
        self::assertSame(200, $perfil['status']);
        self::assertSame('aprobado', $perfil['json']['taller']['estado']);

        // 6. Aprobada, ese email ya no sirve para otra solicitud
        $otra = $this->pedir('POST', '/api/solicitudes-taller', self::formulario($localidad, $email));
        self::assertSame(422, $otra['status']);
        self::assertArrayHasKey('email', $otra['json']['errores']);
    }

    public function testRechazadaConMotivoYSeVuelveAMandarConLaMismaContrasena(): void
    {
        $localidad = 'Pueblo ' . bin2hex(random_bytes(3));
        $email = self::emailNuevo();
        $r = $this->pedir('POST', '/api/solicitudes-taller', self::formulario($localidad, $email));
        $usuarioId = $r['json']['solicitud']['usuario_id'];

        $this->ingresarComoAdmin();
        self::assertSame(422, $this->pedir('PATCH', "/api/admin/solicitudes-taller/$usuarioId", ['aprobar' => false])['status']);
        $no = $this->pedir('PATCH', "/api/admin/solicitudes-taller/$usuarioId", ['aprobar' => false, 'motivo' => 'El CUIT no coincide con el taller.']);
        self::assertSame(200, $no['status']);
        self::assertSame('rechazado', $no['json']['solicitud']['estado']);
        self::assertSame([], $this->ranking($localidad));
        self::assertSame(204, $this->pedir('POST', '/api/logout')['status']);

        // Al querer ingresar ve el motivo
        $login = $this->ingresar($email);
        self::assertSame(403, $login['status']);
        self::assertSame('taller_rechazado', $login['json']['error']);
        self::assertStringContainsString('El CUIT no coincide con el taller.', $login['json']['mensaje']);

        // Con otra contraseña no se puede volver a mandar (si no, cualquiera pisaría la solicitud de otro)
        $ajena = $this->pedir('POST', '/api/solicitudes-taller', ['password' => 'OtraClave99'] + self::formulario($localidad, $email));
        self::assertSame(422, $ajena['status']);
        self::assertArrayHasKey('password', $ajena['json']['errores']);

        // Con la misma, vuelve a quedar pendiente y sin el motivo de antes
        $otra = $this->pedir('POST', '/api/solicitudes-taller', ['cuit' => '30-87654321-0'] + self::formulario($localidad, $email));
        self::assertSame(201, $otra['status'], json_encode($otra['json']));
        self::assertSame('pendiente', $otra['json']['solicitud']['estado']);
        self::assertSame($usuarioId, $otra['json']['solicitud']['usuario_id']);
        self::assertNull($otra['json']['solicitud']['motivo_rechazo']);
    }

    public function testElEmailDeUnaCuentaQueYaExisteNoSirveParaElTaller(): void
    {
        // Mismo email que una cuenta personal (también en mayúsculas): no se crea otra cuenta
        $email = $this->registrar();
        foreach ([$email, strtoupper($email)] as $variante) {
            $r = $this->pedir('POST', '/api/solicitudes-taller', self::formulario('Mercedes', $variante));
            self::assertSame(422, $r['status']);
            self::assertArrayHasKey('email', $r['json']['errores']);
        }
        $cuantas = Entorno::pdo()->prepare('SELECT count(*) FROM usuario WHERE email = :e');
        $cuantas->execute(['e' => $email]);
        self::assertSame(1, (int)$cuantas->fetchColumn());

        // La cuenta personal sigue siendo común y entra normalmente
        self::assertSame(200, $this->ingresar($email)['status']);
        self::assertSame('usuario', $this->pedir('GET', '/api/me')['json']['usuario']['rol']);
    }

    public function testValidacion(): void
    {
        $r = $this->pedir('POST', '/api/solicitudes-taller', ['condiciones' => false, 'responsable' => 'A', 'password' => 'corta']);
        self::assertSame(422, $r['status']);
        foreach (['cuit', 'condiciones', 'responsable', 'email', 'password'] as $campo) {
            self::assertArrayHasKey($campo, $r['json']['errores']);
        }

        // Un usuario común no ve la cola del admin, y sin sesión tampoco
        $this->ingresarComoUsuario();
        self::assertSame(403, $this->pedir('GET', '/api/admin/solicitudes-taller')['status']);
        self::assertSame(204, $this->pedir('POST', '/api/logout')['status']);
        self::assertSame(401, $this->pedir('GET', '/api/admin/solicitudes-taller')['status']);
    }
}
