<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

final class PerfilTest extends ApiTestCase
{
    public function testCambiaElNombre(): void
    {
        $this->ingresarComoUsuario();
        $r = $this->pedir('PUT', '/api/me', ['nombre' => 'Nombre Nuevo']);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame('Nombre Nuevo', $r['json']['usuario']['nombre']);
        self::assertSame('Nombre Nuevo', $this->pedir('GET', '/api/me')['json']['usuario']['nombre']);

        self::assertSame(422, $this->pedir('PUT', '/api/me', ['nombre' => 'A'])['status']);
    }

    public function testSubeVeYBorraLaFoto(): void
    {
        $this->ingresarComoUsuario();
        self::assertNull($this->pedir('GET', '/api/me')['json']['usuario']['foto'], 'al registrarse no tiene foto');

        $r = $this->pedirFormulario('/api/me/foto', [], 1, 'foto');
        self::assertSame(200, $r['status'], json_encode($r['json']));
        $ruta = $r['json']['usuario']['foto'];
        self::assertMatchesRegularExpression('#^/api/usuarios/\d+/foto\?v=\d+$#', $ruta);

        // La foto es pública: se ve sin sesión (detalle de sus publicaciones y chat)
        $this->ingresar('nadie@autocar.test');
        $c = curl_init(Entorno::url() . $ruta);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true]);
        curl_exec($c);
        self::assertSame(200, curl_getinfo($c, CURLINFO_RESPONSE_CODE));
        self::assertSame('image/png', curl_getinfo($c, CURLINFO_CONTENT_TYPE));
    }

    public function testBorrarLaFoto(): void
    {
        $this->ingresarComoUsuario();
        $this->pedirFormulario('/api/me/foto', [], 1, 'foto');
        self::assertSame(204, $this->pedir('DELETE', '/api/me/foto')['status']);
        self::assertNull($this->pedir('GET', '/api/me')['json']['usuario']['foto']);
    }

    public function testSinFotoOConOtraCosaDa422(): void
    {
        $this->ingresarComoUsuario();
        self::assertSame(422, $this->pedirFormulario('/api/me/foto', [])['status']);
    }

    public function testElVendedorMuestraSuFotoEnElDetalle(): void
    {
        $id = $this->vehiculoDisponible();
        self::assertNull($this->pedir('GET', "/api/vehiculos/$id")['json']['vehiculo']['vendedor']['foto']);
    }

    public function testSinSesionNoSeEditaElPerfil(): void
    {
        self::assertSame(401, $this->pedir('PUT', '/api/me', ['nombre' => 'Otro'])['status']);
        self::assertSame(401, $this->pedirFormulario('/api/me/foto', [], 1, 'foto')['status']);
    }
}
