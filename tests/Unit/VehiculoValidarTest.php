<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Catalogo\Vehiculo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VehiculoValidarTest extends TestCase
{
    private static function valido(array $cambios = []): array
    {
        return $cambios + [
            'marca' => 'Ford', 'modelo' => 'Focus', 'anio' => '2019', 'precio' => '15000000',
            'kilometraje' => '60000', 'combustible' => 'nafta', 'transmision' => 'manual',
        ];
    }

    public function testDatosValidosNoTienenErroresYSeNormalizan(): void
    {
        [$datos, $errores] = Vehiculo::validar(self::valido(['marca' => '  Ford  ', 'descripcion' => '']));
        self::assertSame([], $errores);
        self::assertSame('Ford', $datos['marca']);
        self::assertSame(2019, $datos['anio']);
        self::assertSame(60000, $datos['kilometraje']);
        self::assertSame('disponible', $datos['estado']);
        self::assertNull($datos['descripcion']);
        self::assertFalse($datos['acepta_canje']);
    }

    public static function camposInvalidos(): array
    {
        $proximoAnio = (string)((int)date('Y') + 2);
        return [
            'marca vacía'            => ['marca', ''],
            'marca muy larga'        => ['marca', str_repeat('a', 51)],
            'año anterior a 1950'    => ['anio', '1949'],
            'año futuro'             => ['anio', $proximoAnio],
            'año no numérico'        => ['anio', 'dos mil'],
            'precio bajo'            => ['precio', '99999'],
            'precio con 3 decimales' => ['precio', '150000.123'],
            'kilometraje negativo'   => ['kilometraje', '-1'],
            'kilometraje excesivo'   => ['kilometraje', '2000001'],
            'combustible inventado'  => ['combustible', 'hidrogeno'],
            'transmision inventada'  => ['transmision', 'cvt'],
            'estado inventado'       => ['estado', 'robado'],
            'url sin http'           => ['imagen_url', 'javascript:alert(1)'],
            'url ftp'                => ['imagen_url', 'ftp://fotos.com/a.jpg'],
        ];
    }

    #[DataProvider('camposInvalidos')]
    public function testCampoInvalidoDaErrorEnEseCampo(string $campo, string $valor): void
    {
        [, $errores] = Vehiculo::validar(self::valido([$campo => $valor]));
        self::assertArrayHasKey($campo, $errores);
        self::assertCount(1, $errores, 'Solo el campo modificado debería fallar');
    }

    public function testPrecioAceptaComaDecimal(): void
    {
        [$datos, $errores] = Vehiculo::validar(self::valido(['precio' => '150000,50']));
        self::assertSame([], $errores);
        self::assertSame('150000.50', $datos['precio']);
    }

    public function testUnArrayEnUnCampoDeTextoSeTrataComoVacio(): void
    {
        [, $errores] = Vehiculo::validar(self::valido(['marca' => ['Ford']]));
        self::assertArrayHasKey('marca', $errores);
    }

    public function testEnUnaPublicacionElEstadoLoDecideElServidor(): void
    {
        [$datos, $errores] = Vehiculo::validar(
            self::valido(['estado' => 'disponible', 'condicion' => 'bueno', 'localidad' => 'Mercedes']),
            true,
        );
        self::assertSame([], $errores);
        self::assertSame('pendiente', $datos['estado']);
    }

    public function testEnUnaPublicacionCondicionYLocalidadSonObligatorias(): void
    {
        [, $errores] = Vehiculo::validar(self::valido(), true);
        self::assertArrayHasKey('condicion', $errores);
        self::assertArrayHasKey('localidad', $errores);
    }

    public function testBuscaCanjeSoloSeGuardaSiAceptaCanje(): void
    {
        [$sin] = Vehiculo::validar(self::valido(['busca_canje' => 'Una camioneta']));
        self::assertNull($sin['busca_canje']);

        [$con] = Vehiculo::validar(self::valido(['acepta_canje' => 'on', 'busca_canje' => 'Una camioneta']));
        self::assertTrue($con['acepta_canje']);
        self::assertSame('Una camioneta', $con['busca_canje']);
    }

    public function testParaApiConvierteLosTiposDeLaBase(): void
    {
        $fila = [
            'id' => '7', 'marca' => 'Ford', 'modelo' => 'Ka', 'anio' => '2015', 'precio' => '8500000.00',
            'kilometraje' => '90000', 'combustible' => 'nafta', 'transmision' => 'manual', 'estado' => 'disponible',
            'descripcion' => null, 'imagen_url' => null, 'version' => null, 'condicion' => 'bueno',
            'acepta_canje' => 'f', 'busca_canje' => null, 'localidad' => 'Luján', 'foto_id' => '12',
            'created_at' => '2026-10-01 12:00:00+00',
        ];
        $api = Vehiculo::paraApi($fila);
        self::assertSame(7, $api['id']);
        self::assertSame(8500000.0, $api['precio']);
        self::assertSame('/api/fotos/12', $api['foto']);
        self::assertNull($api['motivo_rechazo']);
        self::assertMatchesRegularExpression('/^2026-10-01T/', $api['creado']);
    }
}
