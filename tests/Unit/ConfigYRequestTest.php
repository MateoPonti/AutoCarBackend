<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Auth\Auth;
use AutoCar\Auth\Usuario;
use AutoCar\Core\Config;
use AutoCar\Core\Request;
use PHPUnit\Framework\TestCase;

final class ConfigYRequestTest extends TestCase
{
    private string $archivo;

    protected function setUp(): void
    {
        $this->archivo = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($this->archivo, implode("\n", [
            '# comentario',
            'PRUEBA_CLAVE=desde_archivo',
            'PRUEBA_COMILLAS="con espacios"',
            'PRUEBA_IGUAL=a=b',
            'linea sin igual',
            '',
        ]));
        Config::load($this->archivo);
    }

    protected function tearDown(): void
    {
        unlink($this->archivo);
        putenv('PRUEBA_CLAVE');
        putenv('SESION_TTL');
    }

    public function testLeeElArchivoEnv(): void
    {
        self::assertSame('desde_archivo', Config::get('PRUEBA_CLAVE'));
        self::assertSame('con espacios', Config::get('PRUEBA_COMILLAS'));
        self::assertSame('a=b', Config::get('PRUEBA_IGUAL'));
        self::assertSame('defecto', Config::get('NO_EXISTE', 'defecto'));
    }

    public function testLaVariableDeEntornoRealGanaAlArchivo(): void
    {
        // Así funciona Render: las variables del panel pisan cualquier .env.
        putenv('PRUEBA_CLAVE=desde_entorno');
        self::assertSame('desde_entorno', Config::get('PRUEBA_CLAVE'));
    }

    public function testUnArchivoQueNoExisteNoRompe(): void
    {
        Config::load('/no/existe/.env');
        self::assertSame('desde_archivo', Config::get('PRUEBA_CLAVE'));
    }

    public function testTtlDeSesionConMinimoDeCincoMinutos(): void
    {
        putenv('SESION_TTL');
        self::assertSame(7200, Auth::ttl());
        putenv('SESION_TTL=60');
        self::assertSame(300, Auth::ttl());
        putenv('SESION_TTL=3600');
        self::assertSame(3600, Auth::ttl());
    }

    public function testTextoRecortaYDescartaLoQueNoEsSimple(): void
    {
        $datos = ['a' => '  hola ', 'n' => 42, 'arr' => ['x'], 'obj' => new \stdClass()];
        self::assertSame('hola', Request::texto($datos, 'a'));
        self::assertSame('42', Request::texto($datos, 'n'));
        self::assertSame('', Request::texto($datos, 'arr'));
        self::assertSame('', Request::texto($datos, 'obj'));
        self::assertSame('', Request::texto($datos, 'falta'));
    }

    public function testElUsuarioPublicoNuncaIncluyeElHash(): void
    {
        $fila = ['id' => '3', 'nombre' => 'Ana', 'email' => 'ana@x.com', 'rol' => 'admin', 'password_hash' => '$2y$...'];
        $publico = Usuario::publico($fila);
        self::assertSame(['id' => 3, 'nombre' => 'Ana', 'email' => 'ana@x.com', 'rol' => 'admin'], $publico);
    }
}
