<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Inspecciones\CostoTotal;
use AutoCar\Inspecciones\Presupuestos;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Idea A, costo total: qué se presupuesta, a quién se le pide y cómo se suma. */
final class CostoTotalTest extends TestCase
{
    public function testSePresupuestanLosItemsArreglablesQueNoEstanOk(): void
    {
        $items = [
            'carroceria'     => ['observado', 'Rayón'],
            'motor'          => ['ok', null],
            'frenos'         => ['falla', 'Pastillas gastadas'],
            'kilometraje'    => ['falla', 'Desgaste de más'],   // se revisa, pero no tiene arreglo
            'identificacion' => ['observado', 'Chasis limado'],
        ];
        self::assertSame(['carroceria', 'frenos'], array_keys(CostoTotal::arreglos($items)));
    }

    public function testNuncaSeLePideAlQueInspeccionoYPrimeroLosDeSuLocalidad(): void
    {
        $cercanos = [['usuario_id' => 1], ['usuario_id' => 2]];
        $todos = [['usuario_id' => 5], ['usuario_id' => 1], ['usuario_id' => 2], ['usuario_id' => 7]];
        self::assertSame([2, 5, 7], CostoTotal::elegirTalleres($cercanos, $todos, 1, 3));
        self::assertSame([2], CostoTotal::elegirTalleres($cercanos, [], 1, 3), 'si no hay más talleres, se piden menos');
    }

    public function testElTotalSumaElPresupuestoVigenteMasBaratoDeCadaArreglo(): void
    {
        $ahora = new DateTimeImmutable('2026-11-18 10:00:00');
        $arreglos = CostoTotal::arreglos(['carroceria' => ['observado', 'x'], 'frenos' => ['falla', 'y']]);
        $p = static fn(string $item, string $estado, ?float $monto, ?string $hasta): array =>
            ['item_codigo' => $item, 'estado' => $estado, 'monto' => $monto, 'vigente_hasta' => $hasta];
        $presupuestos = [
            $p('carroceria', 'respondido', 90000, '2026-11-30'),
            $p('carroceria', 'respondido', 60000, '2026-11-01'), // vencido: no cuenta
            $p('carroceria', 'respondido', 75000, '2026-12-01'),
            $p('frenos', 'pedido', null, null),
            $p('frenos', 'rechazado', null, null),
        ];
        $c = CostoTotal::calcular($arreglos, $presupuestos, 15000000, $ahora);
        self::assertSame(75000.0, $c['items'][0]['mejor']);
        self::assertNull($c['items'][1]['mejor']);
        self::assertSame(75000.0, $c['arreglos']);
        self::assertSame(15075000.0, $c['total']);
        self::assertFalse($c['completo'], 'a los frenos todavía no les respondió nadie');

        $presupuestos[] = $p('frenos', 'respondido', 40000, '2026-11-20');
        $c = CostoTotal::calcular($arreglos, $presupuestos, null, $ahora);
        self::assertTrue($c['completo']);
        self::assertSame(115000.0, $c['arreglos']);
        self::assertNull($c['total'], 'sin el precio del aviso no hay total');
    }

    public function testRespuestaDelTaller(): void
    {
        [$r, $e] = Presupuestos::validarRespuesta(['monto' => '85000', 'detalle' => 'Pastillas y discos delanteros']);
        self::assertSame([], $e);
        self::assertSame(['estado' => 'respondido', 'monto' => 85000.0, 'detalle' => 'Pastillas y discos delanteros'], $r);
        self::assertSame('rechazado', Presupuestos::validarRespuesta(['rechazar' => true])[0]['estado']);
        [, $e] = Presupuestos::validarRespuesta(['monto' => 0, 'detalle' => 'x']);
        self::assertArrayHasKey('monto', $e);
        self::assertArrayHasKey('detalle', $e);
    }
}
