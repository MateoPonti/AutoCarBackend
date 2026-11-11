<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\MetaBusqueda\Catalogo;
use PHPUnit\Framework\TestCase;

/** Un auto que vino con datos inválidos (pero con su número de aviso) sigue publicado: no se da por retirado. */
final class RetirarProtegidosTest extends TestCase
{
    public function testElProtegidoNoSeRetiraYElAusenteSi(): void
    {
        $viejo = ['fuente' => 'mercadolibre', 'ultima_vez' => '2026-11-08T06:00:00+00:00', 'estado' => 'activo'];
        $modelo = ['autos' => [
            'mercadolibre:MLA1' => $viejo + ['id_externo' => 'MLA1'], // vino con km absurdos: protegido
            'mercadolibre:MLA2' => $viejo + ['id_externo' => 'MLA2'], // no vino: se retira
        ]];
        $n = Catalogo::retirarAusentes($modelo, 'mercadolibre', '2026-11-10T06:00:00+00:00', ['MLA1' => true]);
        self::assertSame(1, $n);
        self::assertSame('activo', $modelo['autos']['mercadolibre:MLA1']['estado']);
        self::assertSame('retirado', $modelo['autos']['mercadolibre:MLA2']['estado']);
    }
}
