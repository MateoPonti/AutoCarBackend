<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/**
 * Parámetros del negocio (los cambia el admin) y "¿Para qué buscás el auto?" con la política de privacidad
 * (obligatorias para las cuentas personales). Contra la API real y la base de prueba.
 */
final class ParametrosYPreferenciasTest extends ApiTestCase
{
    // --- Política de privacidad y preferencias

    public function testSinAceptarLaPrivacidadNoSeCreaLaCuenta(): void
    {
        $r = $this->pedir('POST', '/api/registro', [
            'nombre' => 'Sin aceptar', 'email' => self::emailNuevo(), 'password' => self::PASSWORD,
            'password_confirm' => self::PASSWORD,
        ]);
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('acepta_privacidad', $r['json']['errores']);
    }

    public function testUnaCuentaNuevaTieneQueContestarAntesDeSeguir(): void
    {
        $this->ingresar($this->registrar());
        $yo = $this->pedir('GET', '/api/me')['json']['usuario'];
        self::assertTrue($yo['preferencias_pendientes']);
        self::assertFalse($yo['privacidad_pendiente']); // la aceptó al registrarse
        self::assertNull($yo['preferencias']);

        $opciones = $this->pedir('GET', '/api/me/preferencias')['json'];
        self::assertArrayHasKey('familia', $opciones['usos']);
        self::assertArrayNotHasKey('revender', $opciones['usos']);

        $mal = $this->pedir('PUT', '/api/me/preferencias', ['tipo' => 'suv']);
        self::assertSame(422, $mal['status']);
        self::assertArrayHasKey('uso', $mal['json']['errores']);

        $bien = $this->pedir('PUT', '/api/me/preferencias', ['uso' => 'familia', 'tipo' => 'suv', 'presupuesto' => 20000000]);
        self::assertSame(200, $bien['status'], json_encode($bien['json']));
        self::assertSame('familia', $bien['json']['preferencias']['uso']);

        $yo = $this->pedir('GET', '/api/me')['json']['usuario'];
        self::assertFalse($yo['preferencias_pendientes']);
        self::assertSame('suv', $yo['preferencias']['tipo']);
    }

    public function testUnaCuentaDeAntesAceptaLaPrivacidadAlContestar(): void
    {
        $email = $this->registrar();
        // Como las cuentas que ya existían antes de la 021
        Entorno::pdo()->prepare('UPDATE usuario SET privacidad_aceptada_at = NULL WHERE email = :e')->execute(['e' => $email]);
        $this->ingresar($email);
        self::assertTrue($this->pedir('GET', '/api/me')['json']['usuario']['privacidad_pendiente']);

        $sinAceptar = $this->pedir('PUT', '/api/me/preferencias', ['uso' => 'trabajo']);
        self::assertSame(422, $sinAceptar['status']);
        self::assertArrayHasKey('acepta_privacidad', $sinAceptar['json']['errores']);

        $aceptando = $this->pedir('PUT', '/api/me/preferencias', ['uso' => 'trabajo', 'acepta_privacidad' => true]);
        self::assertSame(200, $aceptando['status']);
        self::assertFalse($this->pedir('GET', '/api/me')['json']['usuario']['privacidad_pendiente']);
    }

    public function testLasCuentasDelEquipoNoContestan(): void
    {
        $this->ingresarComoAdmin();
        $yo = $this->pedir('GET', '/api/me')['json']['usuario'];
        self::assertFalse($yo['preferencias_pendientes']);
        self::assertFalse($yo['privacidad_pendiente']);
        self::assertSame(403, $this->pedir('PUT', '/api/me/preferencias', ['uso' => 'familia'])['status']);
    }

    // --- Parámetros

    public function testLosValoresSonPublicos(): void
    {
        $r = $this->pedir('GET', '/api/parametros');
        self::assertSame(200, $r['status']);
        self::assertSame(23.0, (float)$r['json']['comision_autocar']);
        self::assertLessThanOrEqual($r['json']['precio_sugerido'], $r['json']['precio_minimo']);
        self::assertLessThanOrEqual($r['json']['precio_maximo'], $r['json']['precio_sugerido']);
    }

    public function testSoloElAdminVeYCambiaLosParametros(): void
    {
        self::assertSame(401, $this->pedir('GET', '/api/admin/parametros')['status']);
        $this->ingresar($this->registrar());
        self::assertSame(403, $this->pedir('GET', '/api/admin/parametros')['status']);
    }

    public function testElAdminCambiaUnValorYQuedaAnotado(): void
    {
        $this->ingresarComoAdmin();
        $antes = $this->pedir('GET', '/api/admin/parametros')['json'];
        $claves = array_column($antes['parametros'], 'clave');
        self::assertContains('comision_autocar', $claves);
        self::assertContains('dias_reclamo', $claves);

        $fuera = $this->pedir('PUT', '/api/admin/parametros', ['valores' => ['comision_autocar' => 770]]);
        self::assertSame(422, $fuera['status']);
        self::assertArrayHasKey('comision_autocar', $fuera['json']['errores']);

        $original = array_column($antes['parametros'], 'valor', 'clave')['dias_reclamo'];
        $nuevo = $original === 20.0 ? 21 : 20;
        $r = $this->pedir('PUT', '/api/admin/parametros', ['valores' => ['dias_reclamo' => $nuevo]]);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame((float)$nuevo, array_column($r['json']['parametros'], 'valor', 'clave')['dias_reclamo']);
        self::assertSame('dias_reclamo', $r['json']['cambios'][0]['clave']);
        self::assertSame($original, $r['json']['cambios'][0]['valor_anterior']);

        // Lo dejo como estaba para las otras pruebas
        $this->pedir('PUT', '/api/admin/parametros', ['valores' => ['dias_reclamo' => $original]]);
    }
}
