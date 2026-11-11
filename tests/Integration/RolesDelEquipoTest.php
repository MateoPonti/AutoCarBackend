<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/** Las cuentas del equipo (admin, moderador) no compran ni venden y nadie modera su propia publicación. */
final class RolesDelEquipoTest extends ApiTestCase
{
    public function testElAdminNoPublicaNiGuardaFavoritosNiAbreChats(): void
    {
        $id = $this->vehiculoDisponible();
        $this->ingresarComoAdmin();
        $publicar = $this->pedirFormulario('/api/mis-vehiculos', ['marca' => 'Volkswagen', 'modelo' => 'Gol Trend', 'anio' => '2018']);
        self::assertSame(403, $publicar['status']);
        self::assertSame('cuenta_del_equipo', $publicar['json']['error']);
        self::assertSame(403, $this->pedir('PUT', "/api/favoritos/$id")['status']);
        self::assertSame(403, $this->pedir('POST', "/api/vehiculos/$id/conversacion")['status']);
        // Lo suyo sí: moderar
        self::assertSame(200, $this->pedir('GET', '/api/admin/vehiculos')['status']);
    }

    public function testElAdminNoTieneLosPermisosDelMecanico(): void
    {
        $this->ingresarComoAdmin();
        $permisos = $this->pedir('GET', '/api/me')['json']['usuario']['permisos'];
        self::assertNotContains('verificaciones.realizar', $permisos);
        self::assertContains('vehiculos.moderar', $permisos);
    }

    public function testNadieModeraSuPropiaPublicacion(): void
    {
        // Un usuario publica y después le dan el rol de admin (por ejemplo, con un UPDATE a mano)
        $email = $this->ingresarComoUsuario();
        $id = $this->pedirFormulario('/api/mis-vehiculos', ['marca' => 'Volkswagen', 'modelo' => 'Gol Trend', 'anio' => '2018'])['json']['vehiculo']['id'];
        Entorno::pdo()->prepare("UPDATE usuario SET rol_id = (SELECT id FROM rol WHERE nombre = 'admin') WHERE email = :e")->execute(['e' => $email]);
        $this->ingresar($email);
        $r = $this->pedir('PATCH', "/api/admin/vehiculos/$id/estado", ['estado' => 'rechazado', 'motivo' => 'Me la rechazo yo.']);
        self::assertSame(409, $r['status']);
        self::assertSame('publicacion_propia', $r['json']['error']);
    }
}
