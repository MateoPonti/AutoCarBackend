<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/** Contacto (público) y reportes de publicaciones o usuarios (con sesión), y su lectura en el panel. */
final class SoporteTest extends ApiTestCase
{
    public function testContactoSinCuentaYElAdminLoLee(): void
    {
        $email = self::emailNuevo();
        $r = $this->pedir('POST', '/api/contacto', ['email' => $email, 'mensaje' => 'No puedo entrar a mi cuenta.']);
        self::assertSame(201, $r['status'], json_encode($r['json']));
        self::assertSame(422, $this->pedir('POST', '/api/contacto', ['email' => 'no-es-un-mail', 'mensaje' => 'Hola'])['status']);

        $this->ingresarComoAdmin();
        $items = $this->pedir('GET', '/api/admin/contactos')['json']['items'];
        $mio = array_values(array_filter($items, fn($i) => $i['email'] === $email))[0];
        self::assertFalse($mio['leido']);
        self::assertSame(204, $this->pedir('PATCH', "/api/admin/contactos/{$mio['id']}")['status']);
    }

    public function testContactoTieneUnLimitePorHora(): void
    {
        $email = self::emailNuevo();
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(201, $this->pedir('POST', '/api/contacto', ['email' => $email, 'mensaje' => "Mensaje número $i."])['status']);
        }
        self::assertSame(429, $this->pedir('POST', '/api/contacto', ['email' => $email, 'mensaje' => 'Otro mensaje más.'])['status']);
    }

    public function testReportarUnaPublicacionYUnUsuarioYElModeradorLosResuelve(): void
    {
        $vehiculo = $this->vehiculoDisponible();
        $otro = $this->registrar();
        $this->ingresarComoUsuario();
        self::assertSame(201, $this->pedir('POST', '/api/reportes', ['vehiculo_id' => $vehiculo, 'motivo' => 'fraude', 'detalle' => 'Pide seña por adelantado.'])['status']);
        $idOtro = (int)Entorno::pdo()->query("SELECT id FROM usuario WHERE email = '$otro'")->fetchColumn();
        self::assertSame(201, $this->pedir('POST', '/api/reportes', ['usuario_id' => $idOtro, 'motivo' => 'trato'])['status']);
        self::assertSame(422, $this->pedir('POST', '/api/reportes', ['vehiculo_id' => $vehiculo, 'motivo' => 'cualquiera'])['status']);
        self::assertSame(422, $this->pedir('POST', '/api/reportes', ['motivo' => 'fraude'])['status']);
        self::assertSame(404, $this->pedir('POST', '/api/reportes', ['vehiculo_id' => 999999, 'motivo' => 'fraude'])['status']);
        self::assertSame(403, $this->pedir('GET', '/api/admin/reportes')['status'], 'un usuario común no ve los reportes');

        $this->ingresarComoAdmin();
        $items = $this->pedir('GET', '/api/admin/reportes')['json']['items'];
        $delAuto = array_values(array_filter($items, fn($i) => ($i['vehiculo']['id'] ?? null) === $vehiculo))[0];
        self::assertSame('pendiente', $delAuto['estado']);
        self::assertSame(204, $this->pedir('PATCH', "/api/admin/reportes/{$delAuto['id']}")['status']);
    }

    public function testSinSesionNoSeReporta(): void
    {
        self::assertSame(401, $this->pedir('POST', '/api/reportes', ['vehiculo_id' => 1, 'motivo' => 'fraude'])['status']);
    }
}
