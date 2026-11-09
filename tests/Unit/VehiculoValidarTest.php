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

    // --- Publicar por partes

    public function testPorPartesAlcanzaConMarcaModeloYAnio(): void
    {
        [$datos, $errores] = Vehiculo::validarPorPartes(['marca' => 'Ford', 'modelo' => 'Ka', 'anio' => '2015']);
        self::assertSame([], $errores);
        self::assertSame('pendiente', $datos['estado']);
        self::assertNull($datos['precio']);
        self::assertNull($datos['kilometraje']);
        self::assertNull($datos['combustible']);
    }

    public function testPorPartesValidaLoQueSiVino(): void
    {
        [, $errores] = Vehiculo::validarPorPartes(['marca' => 'Ford', 'modelo' => 'Ka', 'anio' => '2015',
            'precio' => '10', 'combustible' => 'agua']);
        self::assertArrayHasKey('precio', $errores);
        self::assertArrayHasKey('combustible', $errores);

        [, $errores] = Vehiculo::validarPorPartes(['precio' => '9000000']);
        self::assertArrayHasKey('marca', $errores);
        self::assertArrayHasKey('anio', $errores);
    }

    public function testFaltanDeUnaPublicacionDeUsuario(): void
    {
        $fila = ['usuario_id' => 3, 'precio' => null, 'kilometraje' => '0', 'combustible' => 'nafta',
            'transmision' => null, 'condicion' => 'bueno', 'localidad' => null, 'fotos_cantidad' => 0];
        self::assertSame(['precio', 'transmisión', 'localidad', 'fotos'], Vehiculo::faltan($fila));

        $completa = ['usuario_id' => 3, 'precio' => '9000000', 'kilometraje' => '0', 'combustible' => 'nafta',
            'transmision' => 'manual', 'condicion' => 'bueno', 'localidad' => 'Mercedes', 'fotos_cantidad' => 1];
        self::assertSame([], Vehiculo::faltan($completa));
    }

    public function testElAdminNoNecesitaCondicionLocalidadNiFotos(): void
    {
        $fila = ['usuario_id' => null, 'precio' => '9000000', 'kilometraje' => '1000', 'combustible' => 'nafta',
            'transmision' => 'manual', 'condicion' => null, 'localidad' => null, 'fotos_cantidad' => 0];
        self::assertSame([], Vehiculo::faltan($fila));
    }
}
