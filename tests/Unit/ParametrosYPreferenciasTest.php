<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Auth\Preferencias;
use AutoCar\Parametros\Cotizacion;
use AutoCar\Parametros\Parametros;
use PHPUnit\Framework\TestCase;

/** Validación de los parámetros que cambia el admin y de las preferencias del comprador (sin base). */
final class ParametrosYPreferenciasTest extends TestCase
{
    /** Los parámetros como los devuelve Parametros::todos(), indexados por clave. */
    private static function actuales(): array
    {
        $p = static fn(float $valor, float $minimo, float $maximo, string $unidad): array =>
            ['valor' => $valor, 'minimo' => $minimo, 'maximo' => $maximo, 'unidad' => $unidad];
        return [
            'comision_autocar' => $p(23, 0, 50, 'porcentaje'),
            'parte_fondo'      => $p(33.3333, 0, 100, 'porcentaje'),
            'tope_veces'       => $p(3, 0, 10, 'veces'),
            'dias_reclamo'     => $p(15, 1, 60, 'dias'),
            'precio_minimo'    => $p(70000, 1000, 10000000, 'pesos'),
            'precio_sugerido'  => $p(100000, 1000, 10000000, 'pesos'),
            'precio_maximo'    => $p(150000, 1000, 10000000, 'pesos'),
        ];
    }

    // --- Parámetros

    public function testSoloDevuelveLosQueCambian(): void
    {
        [$cambian, $errores] = Parametros::validar(self::actuales(), ['comision_autocar' => '20', 'dias_reclamo' => 15]);
        self::assertSame([], $errores);
        self::assertSame(['comision_autocar' => 20.0], $cambian);
    }

    public function testNoDejaPonerUnValorFueraDeSusLimites(): void
    {
        // 770 % en vez de 77 %: el error típico que frena el mínimo y el máximo
        [, $errores] = Parametros::validar(self::actuales(), ['comision_autocar' => 770]);
        self::assertArrayHasKey('comision_autocar', $errores);
        [, $errores] = Parametros::validar(self::actuales(), ['dias_reclamo' => 0]);
        self::assertArrayHasKey('dias_reclamo', $errores);
    }

    public function testDiasYPesosSinDecimalesYSoloNumeros(): void
    {
        [, $errores] = Parametros::validar(self::actuales(), ['dias_reclamo' => 10.5, 'precio_maximo' => 'mucho']);
        self::assertArrayHasKey('dias_reclamo', $errores);
        self::assertArrayHasKey('precio_maximo', $errores);
        [, $errores] = Parametros::validar(self::actuales(), ['parte_fondo' => 40.5]);
        self::assertSame([], $errores);
    }

    public function testUnaClaveQueNoExisteEsUnError(): void
    {
        [, $errores] = Parametros::validar(self::actuales(), ['comision_mercado_pago' => 2]);
        self::assertArrayHasKey('comision_mercado_pago', $errores);
    }

    public function testElSugeridoQuedaEntreElMinimoYElMaximo(): void
    {
        [, $errores] = Parametros::validar(self::actuales(), ['precio_sugerido' => 200000]);
        self::assertArrayHasKey('precio_sugerido', $errores);
        // Si sube el máximo en el mismo cambio, pasa
        [$cambian, $errores] = Parametros::validar(self::actuales(), ['precio_sugerido' => 200000, 'precio_maximo' => 250000]);
        self::assertSame([], $errores);
        self::assertSame(['precio_sugerido' => 200000.0, 'precio_maximo' => 250000.0], $cambian);
        [, $errores] = Parametros::validar(self::actuales(), ['precio_minimo' => 160000]);
        self::assertArrayHasKey('precio_minimo', $errores);
    }

    // --- Preferencias del comprador

    public function testElUsoEsObligatorioYLoDemasOpcional(): void
    {
        [$datos, $errores] = Preferencias::validar(['uso' => 'familia']);
        self::assertSame([], $errores);
        self::assertSame(['uso' => 'familia', 'tipo' => null, 'presupuesto' => null, 'localidad' => null], $datos);

        [, $errores] = Preferencias::validar([]);
        self::assertSame(['uso'], array_keys($errores));
    }

    public function testElRevendedorYaNoEsUnaOpcion(): void
    {
        [, $errores] = Preferencias::validar(['uso' => 'revender']);
        self::assertArrayHasKey('uso', $errores);
        self::assertArrayNotHasKey('revender', Preferencias::USOS);
    }

    public function testTipoPresupuestoYLocalidad(): void
    {
        [$datos, $errores] = Preferencias::validar(['uso' => 'trabajo', 'tipo' => 'pickup', 'presupuesto' => '25000000', 'localidad' => '  Luján ']);
        self::assertSame([], $errores);
        self::assertSame('pickup', $datos['tipo']);
        self::assertSame(25000000, $datos['presupuesto']);
        self::assertSame('Luján', $datos['localidad']);

        [, $errores] = Preferencias::validar(['uso' => 'trabajo', 'tipo' => 'tanque', 'presupuesto' => '25.000.000']);
        self::assertSame(['tipo', 'presupuesto'], array_keys($errores));
    }

    public function testVacioEsComoNoContestar(): void
    {
        [$datos, $errores] = Preferencias::validar(['uso' => 'personal', 'tipo' => '', 'presupuesto' => '', 'localidad' => '']);
        self::assertSame([], $errores);
        self::assertNull($datos['tipo']);
        self::assertNull($datos['presupuesto']);
        self::assertNull($datos['localidad']);
    }

    public function testLaCotizacionAutomaticaSoloAceptaValoresCreibles(): void
    {
        self::assertSame(1575.0, Cotizacion::validar(1575, 1450.0, 1.0, 100000.0));
        self::assertSame(1580.9, Cotizacion::validar(1580.9, 1575.0, 1.0, 100000.0));
        // Lo que no es un número, fuera de los límites o un salto de más del 50 % de un día al otro: queda la anterior
        self::assertNull(Cotizacion::validar(null, 1575.0, 1.0, 100000.0));
        self::assertNull(Cotizacion::validar('1575', 1575.0, 1.0, 100000.0));
        self::assertNull(Cotizacion::validar(0, 1575.0, 1.0, 100000.0));
        self::assertNull(Cotizacion::validar(15750, 1575.0, 1.0, 100000.0));
        self::assertNull(Cotizacion::validar(500, 1575.0, 1.0, 100000.0));
    }
}
