<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

use AutoCar\Inspecciones\Checklist;

/** Ayudas de las pruebas de inspecciones: usuarios con rol, perfil del taller y checklist. */
trait ConInspecciones
{
    private static array $perfil = [
        'nombre' => 'Taller de Prueba', 'direccion' => 'Calle 1 123', 'localidad' => 'Mercedes',
        'telefono' => '02324 123456', 'precio_inspeccion' => 130000,
    ];

    private static function idDe(string $email): int
    {
        $st = Entorno::pdo()->prepare('SELECT id FROM usuario WHERE email = :e');
        $st->execute(['e' => $email]);
        return (int)$st->fetchColumn();
    }

    /** Mecánico nuevo, ya logueado. Con $perfil, también con el taller cargado. → [id, email] */
    private function ingresarComoMecanico(bool $perfil = true, array $datosPerfil = []): array
    {
        $email = $this->registrar();
        Entorno::pdo()
            ->prepare("UPDATE usuario SET rol_id = (SELECT id FROM rol WHERE nombre = 'mecanico') WHERE email = :e")
            ->execute(['e' => $email]);
        self::assertSame(200, $this->ingresar($email)['status']);
        if ($perfil) {
            $r = $this->pedir('PUT', '/api/mecanico/perfil', $datosPerfil + self::$perfil);
            self::assertSame(200, $r['status'], json_encode($r['json']));
        }
        return [self::idDe($email), $email];
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

    /** Con la sesión del mecánico: acepta, completa el checklist y firma. */
    private function aceptarYFirmar(int $id): array
    {
        self::assertSame(200, $this->pedir('PATCH', "/api/mecanico/inspecciones/$id", ['accion' => 'aceptar', 'fecha_turno' => self::turno()])['status']);
        self::assertSame(200, $this->pedir('PUT', "/api/mecanico/inspecciones/$id/informe",
            ['items' => self::checklistCompleto(), 'conclusion' => 'recomendable'])['status']);
        $firma = $this->pedir('POST', "/api/mecanico/inspecciones/$id/firma", []);
        self::assertSame(200, $firma['status'], json_encode($firma['json']));
        return $firma['json']['inspeccion'];
    }
}
