<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Inspecciones\SolicitudTaller;
use PHPUnit\Framework\TestCase;

/** "Adherí tu taller": validación del formulario y de la respuesta del admin (sin base). */
final class SolicitudTallerTest extends TestCase
{
    private static function formulario(array $cambios = []): array
    {
        return $cambios + [
            'nombre' => 'Taller San Martín', 'cuit' => '30-12345678-9', 'direccion' => 'San Martín 450',
            'localidad' => 'Mercedes', 'telefono' => '(02324) 15-123456', 'horarios' => 'Lun a vie 8 a 18',
            'precio_inspeccion' => 130000, 'condiciones' => true,
        ];
    }

    public function testUnFormularioCompletoEsValido(): void
    {
        [$datos, $errores] = SolicitudTaller::validar(self::formulario());
        self::assertSame([], $errores);
        self::assertSame('30-12345678-9', $datos['cuit']);
        self::assertSame('Lun a vie 8 a 18', $datos['horarios']);
        self::assertSame(130000.0, $datos['precio_inspeccion']);
    }

    public function testElCuitSinGuionesSeGuardaConGuiones(): void
    {
        [$datos, $errores] = SolicitudTaller::validar(self::formulario(['cuit' => ' 30123456789 ']));
        self::assertSame([], $errores);
        self::assertSame('30-12345678-9', $datos['cuit']);
    }

    public function testCuitMalEscrito(): void
    {
        foreach (['30-1234567-9', 'abc', '', '30-12345678-99'] as $cuit) {
            [, $errores] = SolicitudTaller::validar(self::formulario(['cuit' => $cuit]));
            self::assertArrayHasKey('cuit', $errores, "CUIT $cuit");
        }
    }

    public function testSinAceptarLasCondicionesNoSePuede(): void
    {
        foreach ([false, 'true', 1, null] as $valor) {
            [, $errores] = SolicitudTaller::validar(self::formulario(['condiciones' => $valor]));
            self::assertArrayHasKey('condiciones', $errores);
        }
    }

    public function testUsaLasMismasReglasQueElPerfilDelMecanico(): void
    {
        [, $errores] = SolicitudTaller::validar(self::formulario(['precio_inspeccion' => 10, 'localidad' => '', 'horarios' => 'x']));
        self::assertArrayHasKey('precio_inspeccion', $errores);
        self::assertArrayHasKey('localidad', $errores);
        self::assertArrayHasKey('horarios', $errores);
    }

    public function testAprobarNoPideMotivo(): void
    {
        [$resolucion, $errores] = SolicitudTaller::validarResolucion(['aprobar' => true, 'motivo' => 'lo que sea']);
        self::assertSame([], $errores);
        self::assertSame(['aprobar' => true, 'motivo' => null], $resolucion);
    }

    public function testRechazarPideMotivo(): void
    {
        [, $errores] = SolicitudTaller::validarResolucion(['aprobar' => false]);
        self::assertArrayHasKey('motivo', $errores);

        [$resolucion, $errores] = SolicitudTaller::validarResolucion(['aprobar' => false, 'motivo' => '  El CUIT no coincide  ']);
        self::assertSame([], $errores);
        self::assertSame('El CUIT no coincide', $resolucion['motivo']);
    }

    public function testAprobarTieneQueSerBooleano(): void
    {
        [, $errores] = SolicitudTaller::validarResolucion(['aprobar' => 'si']);
        self::assertArrayHasKey('aprobar', $errores);
    }
}
