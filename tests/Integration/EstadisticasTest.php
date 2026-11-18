<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

/**
 * Estadísticas del admin (028): el comprador ve y toca una recomendación, paga la inspección desde ahí
 * y el embudo del panel lo cuenta. "Compró" = pagó la inspección.
 */
final class EstadisticasTest extends ApiTestCase
{
    use ConInspecciones;

    private function metricas(int $dias = 7): array
    {
        $this->ingresarComoAdmin();
        $r = $this->pedir('GET', "/api/admin/metricas?dias=$dias");
        self::assertSame(200, $r['status']);
        return $r['json'];
    }

    private static function origen(array $embudo, string $origen): array
    {
        return array_values(array_filter($embudo['origenes'], static fn(array $o): bool => $o['origen'] === $origen))[0];
    }

    public function testElEmbudoCuentaLaRecomendacionVistaTocadaYPagada(): void
    {
        $antes = $this->metricas();
        self::assertSame(['dias' => 7, 'opciones' => [7, 30, 90]], $antes['periodo']);
        self::assertSame('dia', $antes['serie']['unidad']);
        self::assertCount(7, $antes['serie']['puntos']);
        $destacadoAntes = self::origen($antes['embudo'], 'destacado');

        [$mecanicoId] = $this->ingresarComoMecanico();
        $this->ingresarComoUsuario();
        $url = 'https://auto.mercadolibre.com.ar/MLA-' . random_int(1000000000, 9999999999) . '-gol-trend-_JM';
        // Lo que no es válido se ignora sin error: son datos para estadísticas
        $r = $this->pedir('POST', '/api/eventos', ['eventos' => [
            ['tipo' => 'mostrado', 'origen' => 'destacado', 'ref' => $url],
            ['tipo' => 'tocado', 'origen' => 'destacado', 'ref' => $url],
            ['tipo' => 'mostrado', 'origen' => 'inventado'],
            'cualquier cosa',
        ]]);
        self::assertSame(204, $r['status']);
        $pedido = $this->pedir('POST', '/api/inspecciones', ['url' => $url, 'mecanico_id' => $mecanicoId, 'origen' => 'destacado']);
        self::assertSame(201, $pedido['status'], json_encode($pedido['json']));

        $despues = $this->metricas();
        $destacado = self::origen($despues['embudo'], 'destacado');
        self::assertSame($destacadoAntes['vieron'] + 1, $destacado['vieron']);
        self::assertSame($destacadoAntes['tocaron'] + 1, $destacado['tocaron']);
        self::assertSame($destacadoAntes['pagaron'] + 1, $destacado['pagaron']);
        self::assertSame($antes['resumen']['actual']['pagaron'] + 1, $despues['resumen']['actual']['pagaron']);
        self::assertSame($antes['serie']['puntos'][6]['pagaron'] + 1, $despues['serie']['puntos'][6]['pagaron']);
    }

    public function testElInformeDePlataTraeGananciaPerdidaYNeta(): void
    {
        $m = $this->metricas(90);
        foreach (['actual', 'anterior'] as $periodo) {
            $t = $m['resumen'][$periodo];
            foreach (['ingresos', 'perdida', 'neta', 'por_cobrar', 'reclamos_procedieron'] as $clave) {
                self::assertArrayHasKey($clave, $t);
            }
            self::assertEqualsWithDelta($t['ingresos'] - $t['perdida'], $t['neta'], 0.01);
            self::assertEqualsWithDelta($t['reclamos'], $t['perdida'], 0.01);
        }
        foreach ($m['serie']['puntos'] as $p) {
            self::assertEqualsWithDelta($p['ingresos'] - $p['reclamos'], $p['neta'], 0.01);
        }
    }

    public function testLosEventosSonSoloDeCuentasPersonalesYElPeriodoSeValida(): void
    {
        $this->ingresarComoAdmin();
        self::assertSame(403, $this->pedir('POST', '/api/eventos', ['eventos' => []])['status']);
        // Un período que no existe vuelve a 30 días; con 90 la serie va por semana
        self::assertSame(30, $this->pedir('GET', '/api/admin/metricas?dias=5')['json']['periodo']['dias']);
        self::assertSame('semana', $this->pedir('GET', '/api/admin/metricas?dias=90')['json']['serie']['unidad']);
    }

    public function testLasInspeccionesDelPeriodoTraenEstadosReclamosYMecanicos(): void
    {
        $i = $this->metricas(30)['inspecciones'];
        foreach (['pedidas', 'precio_promedio', 'horas_hasta_firma', 'por_estado', 'conclusiones', 'reclamos', 'top_mecanicos'] as $clave) {
            self::assertArrayHasKey($clave, $i);
        }
        self::assertSame(array_sum($i['por_estado']), $i['pedidas']);
        self::assertLessThanOrEqual(5, count($i['top_mecanicos']));
    }
}
