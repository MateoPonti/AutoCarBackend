<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Inspecciones\Estados;
use AutoCar\Inspecciones\Inspeccion;
use AutoCar\Inspecciones\Taller;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Reglas del lado del mecánico que no necesitan base: perfil del taller, turno, motivo y momento de la firma. */
final class InspeccionesMecanicoTest extends TestCase
{
    private const AHORA = '2026-11-08T12:00:00-03:00';

    private static function perfil(array $cambios = []): array
    {
        return $cambios + [
            'nombre' => 'Taller San Martín', 'direccion' => 'San Martín 450', 'localidad' => 'Mercedes',
            'telefono' => '(02324) 15-123456', 'precio_inspeccion' => 130000,
        ];
    }

    // --- Perfil del taller

    public function testUnPerfilCompletoEsValido(): void
    {
        [$datos, $errores] = Taller::validar(self::perfil(['nombre' => '  Taller San Martín  ']));
        self::assertSame([], $errores);
        self::assertSame('Taller San Martín', $datos['nombre']);
        self::assertSame(130000.0, $datos['precio_inspeccion']);
    }

    public function testElPrecioPuedeLlegarComoTextoNumerico(): void
    {
        [$datos, $errores] = Taller::validar(self::perfil(['precio_inspeccion' => '95000']));
        self::assertSame([], $errores);
        self::assertSame(95000.0, $datos['precio_inspeccion']);
    }

    public function testMarcaCadaCampoQueFalta(): void
    {
        [, $errores] = Taller::validar([]);
        self::assertSame(['nombre', 'direccion', 'localidad', 'telefono', 'precio_inspeccion'], array_keys($errores));
    }

    public function testElPrecioTieneLimitesYNoLlevaCentavos(): void
    {
        self::assertArrayHasKey('precio_inspeccion', Taller::validar(self::perfil(['precio_inspeccion' => 500]))[1]);
        self::assertArrayHasKey('precio_inspeccion', Taller::validar(self::perfil(['precio_inspeccion' => 20000000]))[1]);
        self::assertArrayHasKey('precio_inspeccion', Taller::validar(self::perfil(['precio_inspeccion' => 130000.5]))[1]);
        self::assertArrayHasKey('precio_inspeccion', Taller::validar(self::perfil(['precio_inspeccion' => 'mucho']))[1]);
        self::assertArrayHasKey('precio_inspeccion', Taller::validar(self::perfil(['precio_inspeccion' => true]))[1]);
    }

    public function testElTelefonoSoloLlevaNumerosYSignos(): void
    {
        self::assertArrayHasKey('telefono', Taller::validar(self::perfil(['telefono' => 'llamame al 1234']))[1]);
        self::assertSame([], Taller::validar(self::perfil(['telefono' => '+54 9 2324 123456']))[1]);
    }

    public function testUnValorQueNoEsTextoCuentaComoVacio(): void
    {
        self::assertArrayHasKey('nombre', Taller::validar(self::perfil(['nombre' => ['<b>x</b>']]))[1]);
    }

    // --- Turno al aceptar

    public function testTurnoValidoConZona(): void
    {
        [$fecha, $error] = Estados::validarTurno('2026-11-12T10:00:00-03:00', new DateTimeImmutable(self::AHORA));
        self::assertNull($error);
        self::assertSame('2026-11-12T13:00:00+00:00', $fecha->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    public function testTurnoComoLoMandaElFront(): void
    {
        // new Date(...).toISOString() en el navegador
        self::assertNull(Estados::validarTurno('2026-11-12T13:00:00.000Z', new DateTimeImmutable(self::AHORA))[1]);
    }

    public function testTurnoEnElPasadoOMuyLejosOMalFormado(): void
    {
        $ahora = new DateTimeImmutable(self::AHORA);
        self::assertNotNull(Estados::validarTurno('2026-11-07T10:00:00-03:00', $ahora)[1]);
        self::assertNotNull(Estados::validarTurno('2027-03-01T10:00:00-03:00', $ahora)[1]);
        self::assertNotNull(Estados::validarTurno('12/11/2026', $ahora)[1]);
        self::assertNotNull(Estados::validarTurno('2026-11-12', $ahora)[1]); // falta la hora
        self::assertNotNull(Estados::validarTurno(null, $ahora)[1]);
        self::assertNotNull(Estados::validarTurno('2026-13-45T99:99', $ahora)[1]);
    }

    public function testElTurnoTieneCincoMinutosDeMargen(): void
    {
        self::assertNull(Estados::validarTurno('2026-11-08T11:57:00-03:00', new DateTimeImmutable(self::AHORA))[1]);
    }

    // --- Motivo al rechazar

    public function testMotivo(): void
    {
        self::assertSame(['No tengo turnos', null], Estados::validarMotivo('  No tengo turnos '));
        self::assertNotNull(Estados::validarMotivo('')[1]);
        self::assertNotNull(Estados::validarMotivo('no')[1]);
        self::assertNotNull(Estados::validarMotivo(str_repeat('x', Estados::MOTIVO_MAX + 1))[1]);
        self::assertNotNull(Estados::validarMotivo(['x'])[1]);
    }

    // --- Momento de la firma en la huella

    public function testElMomentoDeLaFirmaEsElMismoAlGuardarYAlLeer(): void
    {
        $guardado = new DateTimeImmutable('@1794150005'); // como se firma
        $leido = '2026-11-08 12:00:05-03'; // el mismo instante, como lo devuelve Postgres con otra zona
        self::assertSame(Inspeccion::momentoHuella($guardado), Inspeccion::momentoHuella($leido));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', Inspeccion::momentoHuella($guardado));
    }
}
