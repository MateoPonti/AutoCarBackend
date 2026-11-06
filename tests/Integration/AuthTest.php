<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

final class AuthTest extends ApiTestCase
{
    public function testHealthNoTocaLaBase(): void
    {
        $r = $this->pedir('GET', '/health');
        self::assertSame(200, $r['status']);
        self::assertSame('ok', $r['json']['estado']);
    }

    public function testRegistroCreaUnUsuarioComun(): void
    {
        $email = self::emailNuevo();
        $r = $this->pedir('POST', '/api/registro', [
            'nombre' => 'Ana', 'email' => $email, 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD,
            'rol' => 'admin', // el cliente no puede elegir el rol
        ]);
        self::assertSame(201, $r['status']);
        self::assertSame($email, $r['json']['usuario']['email']);
        self::assertSame('usuario', $r['json']['usuario']['rol']);
        self::assertArrayNotHasKey('password_hash', $r['json']['usuario']);
    }

    public function testRegistroConDatosInvalidosDa422PorCampo(): void
    {
        $r = $this->pedir('POST', '/api/registro', ['nombre' => 'A', 'email' => 'no-es-email', 'password' => 'corta']);
        self::assertSame(422, $r['status']);
        self::assertSame('validacion', $r['json']['error']);
        self::assertArrayHasKey('nombre', $r['json']['errores']);
        self::assertArrayHasKey('email', $r['json']['errores']);
        self::assertArrayHasKey('password', $r['json']['errores']);
    }

    public function testEmailRepetidoDa422(): void
    {
        $email = $this->registrar();
        $r = $this->pedir('POST', '/api/registro', [
            'nombre' => 'Otra', 'email' => $email, 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD,
        ]);
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('email', $r['json']['errores']);
    }

    public function testLoginDevuelveCsrfYCookieHttpOnly(): void
    {
        $email = $this->registrar();
        $r = $this->ingresar($email);
        self::assertSame(200, $r['status']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $r['json']['csrf']);
        self::assertSame(7200, $r['json']['expira_en']);
        self::assertStringContainsString('autocar_sesion=', $r['headers']['set-cookie']);
        self::assertStringContainsStringIgnoringCase('HttpOnly', $r['headers']['set-cookie']);
        self::assertStringContainsStringIgnoringCase('SameSite=Strict', $r['headers']['set-cookie']);
    }

    public function testCookieSecureSoloDetrasDeHttps(): void
    {
        $email = $this->registrar();
        $r = $this->pedir('POST', '/api/login', ['email' => $email, 'password' => self::PASSWORD],
            ['X-Forwarded-Proto' => 'https']);
        self::assertStringContainsStringIgnoringCase('secure', $r['headers']['set-cookie']);
    }

    public function testLoginConClaveIncorrectaDa401(): void
    {
        $email = $this->registrar();
        $r = $this->ingresar($email, 'Incorrecta99');
        self::assertSame(401, $r['status']);
        self::assertSame('credenciales', $r['json']['error']);
    }

    public function testMeSinSesionDa401YConSesionDevuelveElUsuario(): void
    {
        self::assertSame(401, $this->pedir('GET', '/api/me')['status']);

        $email = $this->ingresarComoUsuario();
        $r = $this->pedir('GET', '/api/me');
        self::assertSame(200, $r['status']);
        self::assertSame($email, $r['json']['usuario']['email']);
        self::assertIsArray($r['json']['usuario']['permisos']);
    }

    public function testLogoutInvalidaLaSesionEnElServidor(): void
    {
        $this->ingresarComoUsuario();
        $cookieAntes = $this->pedir('GET', '/api/me');
        self::assertSame(200, $cookieAntes['status']);

        self::assertSame(204, $this->pedir('POST', '/api/logout')['status']);
        self::assertSame(401, $this->pedir('GET', '/api/me')['status']);
    }

    public function testLaSesionSeGuardaEnLaBase(): void
    {
        $antes = (int)Entorno::pdo()->query('SELECT count(*) FROM sesion')->fetchColumn();
        $this->ingresarComoUsuario();
        $despues = (int)Entorno::pdo()->query('SELECT count(*) FROM sesion')->fetchColumn();
        self::assertGreaterThan($antes, $despues);
    }
}
