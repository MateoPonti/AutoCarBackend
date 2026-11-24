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

    public function testElInformeDePlataTraeIngresosSinPerdidaPorReclamos(): void
    {
        $m = $this->metricas(90);
        foreach (['actual', 'anterior'] as $periodo) {
            $t = $m['resumen'][$periodo];
            foreach (['ingresos', 'por_cobrar', 'mecanicos'] as $clave) {
                self::assertArrayHasKey($clave, $t);
            }
            foreach (['perdida', 'neta', 'reclamos', 'reclamos_procedieron'] as $clave) {
                self::assertArrayNotHasKey($clave, $t); // sin reclamos desde el 23/11
            }
        }
        foreach ($m['serie']['puntos'] as $p) {
            self::assertEquals($p['ingresos'], $p['ingresos_inspecciones'] + $p['ingresos_clics'] + $p['ingresos_abonos']);
            self::assertArrayNotHasKey('reclamos', $p);
        }
    }

    public function testPlataDistingueClicsPendientesYLiquidacionesCobradas(): void
    {
        $inicial = $this->metricas(90);
        $antes = $inicial['resumen']['actual'];
        $db = Entorno::pdo();
        $db->exec("INSERT INTO convenio_plataforma (fuente, nombre, tarifa_clic) VALUES ('prueba_plata', 'Prueba', 125)");
        $db->exec("INSERT INTO clic_salida (fuente, auto_id, valido, created_at) VALUES
            ('prueba_plata', 'vigente', true, now()), ('prueba_plata', 'repetido', false, now()),
            ('prueba_plata', 'liquidado', true, now() - interval '1 month')");
        $db->exec("INSERT INTO cobro_afiliacion (tipo, socio, mes, cantidad, monto) VALUES
            ('plataforma', 'prueba_plata', date_trunc('month', (now() AT TIME ZONE 'America/Argentina/Buenos_Aires') - interval '1 month'), 1, 95)");
        $db->exec("INSERT INTO cobro_afiliacion (tipo, socio, mes, cantidad, monto) VALUES
            ('empresa', '101', date_trunc('month', now() - interval '1 month'), 1, 210)");
        $final = $this->metricas(90);
        $m = $final['resumen']['actual'];
        $ultima = array_key_last($final['serie']['puntos']);
        foreach (['ingresos_clics' => 95, 'ingresos_abonos' => 210, 'ingresos_inspecciones' => 0] as $clave => $extra) {
            self::assertEquals($inicial['serie']['puntos'][$ultima][$clave] + $extra, $final['serie']['puntos'][$ultima][$clave]);
        }
        self::assertEquals($antes['clics_por_cobrar'] + 125, $m['clics_por_cobrar']);
        self::assertEquals($antes['clics_pendientes'] + 1, $m['clics_pendientes']);
        self::assertEquals($antes['ingresos_clics'] + 95, $m['ingresos_clics']);
        self::assertEquals($antes['ingresos'] + 305, $m['ingresos']);
        // Cantidad contra plata: el clic válido de este mes y el del mes pasado cuentan (el repetido no), con la tarifa de hoy
        self::assertSame($antes['clics_validos'] + 2, $m['clics_validos']);
        self::assertEquals($antes['clics_valor'] + 250, $m['clics_valor']);
        self::assertSame($antes['abonos_cobrados'] + 1, $m['abonos_cobrados']);
    }

    public function testLosEventosSonSoloDeCuentasPersonalesYElPeriodoSeValida(): void
    {
        $this->ingresarComoAdmin();
        self::assertSame(403, $this->pedir('POST', '/api/eventos', ['eventos' => []])['status']);
        // Un período que no existe vuelve a 30 días; con 90 la serie va por semana
        self::assertSame(30, $this->pedir('GET', '/api/admin/metricas?dias=5')['json']['periodo']['dias']);
        self::assertSame('semana', $this->pedir('GET', '/api/admin/metricas?dias=90')['json']['serie']['unidad']);
    }

    /** La fila de un tipo de usuario en una lista de por_perfil */
    private static function delUso(array $filas, string $uso): array
    {
        return array_values(array_filter($filas, static fn(array $f): bool => $f['uso'] === $uso))[0];
    }

    public function testPorTipoDeUsuarioCuentaLaBusquedaElOrdenLaRecomendacionYLaPlata(): void
    {
        $antes = $this->metricas()['por_perfil'];
        self::assertSame(['personal', 'familia', 'trabajo', 'aplicacion'], array_keys($antes['usos']));

        [$mecanicoId] = $this->ingresarComoMecanico();
        $this->ingresarComoUsuario();
        self::assertSame(200, $this->pedir('PUT', '/api/me/preferencias', ['uso' => 'trabajo'])['status']);
        $busqueda = $this->pedir('POST', '/api/meta-busquedas', ['marca' => 'Toyota', 'modelo' => 'Hilux']);
        self::assertSame(201, $busqueda['status'], json_encode($busqueda['json']));
        $id = $busqueda['json']['busqueda']['id'];

        // Abrir los resultados cuenta como búsqueda (la página 2 no); elegir "Menos km" cuenta como orden
        self::assertSame(200, $this->pedir('GET', "/api/meta-busquedas/$id?orden=km")['status']);
        self::assertSame(200, $this->pedir('GET', "/api/meta-busquedas/$id?orden=km&page=2")['status']);
        $url = 'https://auto.mercadolibre.com.ar/MLA-' . random_int(1000000000, 9999999999) . '-hilux-_JM';
        self::assertSame(204, $this->pedir('POST', '/api/eventos', ['eventos' => [
            ['tipo' => 'orden', 'origen' => 'metabuscador', 'ref' => 'km'],
            ['tipo' => 'orden', 'origen' => 'metabuscador', 'ref' => 'inventado'], // se ignora
            ['tipo' => 'orden', 'origen' => 'destacado', 'ref' => 'precio'],      // se ignora: solo en el metabuscador
            ['tipo' => 'busco', 'origen' => 'metabuscador'],                     // se ignora: lo anota el back
            ['tipo' => 'mostrado', 'origen' => 'metabuscador', 'ref' => $url],
        ]])['status']);
        $pedido = $this->pedir('POST', '/api/inspecciones', ['url' => $url, 'mecanico_id' => $mecanicoId, 'origen' => 'metabuscador']);
        self::assertSame(201, $pedido['status'], json_encode($pedido['json']));

        $despues = $this->metricas()['por_perfil'];
        $mAntes = self::delUso($antes['metabuscador']['por_uso'], 'trabajo');
        $m = self::delUso($despues['metabuscador']['por_uso'], 'trabajo');
        self::assertSame($mAntes['buscaron'] + 1, $m['buscaron']);
        self::assertSame($mAntes['compraron'] + 1, $m['compraron']);
        self::assertSame($mAntes['desde_metabuscador'] + 1, $m['desde_metabuscador']);
        self::assertSame($antes['metabuscador']['total']['buscaron'] + 1, $despues['metabuscador']['total']['buscaron']);

        $o = self::delUso($despues['ordenes'], 'trabajo')['ordenes'];
        $oAntes = self::delUso($antes['ordenes'], 'trabajo')['ordenes'];
        self::assertSame($oAntes['km']['usuarios'] + 1, $o['km']['usuarios']);
        self::assertSame($oAntes['precio']['veces'], $o['precio']['veces']);

        $e = self::origen(self::delUso($despues['embudo'], 'trabajo'), 'metabuscador');
        $eAntes = self::origen(self::delUso($antes['embudo'], 'trabajo'), 'metabuscador');
        self::assertSame($eAntes['vieron'] + 1, $e['vieron']);
        self::assertSame($eAntes['pagaron'] + 1, $e['pagaron']);
        // La inspección no está firmada todavía: no suma ganancia, pero el origen trae cuánto deja cada paso
        self::assertSame($eAntes['ganancia'], $e['ganancia']);
        self::assertArrayHasKey('por_vio', $e);
        self::assertArrayHasKey('por_pago', $despues['valor']['total'] + self::origen(self::delUso($despues['embudo'], 'trabajo'), 'para_vos'));
        self::assertArrayHasKey('ganancia', $this->metricas()['embudo']['total']);

        $p = self::delUso($despues['plata'], 'trabajo');
        self::assertSame(self::delUso($antes['plata'], 'trabajo')['pagaron'] + 1, $p['pagaron']);
        self::assertArrayHasKey('ingresos', $p);
        self::assertArrayNotHasKey('perdida', $p); // sin reclamos desde el 23/11

        // Valor por interacción: buscó y eligió un orden (2 interacciones; "mostrado" no cuenta) y después pagó
        $v = self::delUso($despues['valor']['por_uso'], 'trabajo');
        $vAntes = self::delUso($antes['valor']['por_uso'], 'trabajo');
        self::assertSame($vAntes['interacciones'] + 2, $v['interacciones']);
        self::assertSame($vAntes['activos'] + 1, $v['activos']);
        self::assertSame($vAntes['compradores'] + 1, $v['compradores']);
        self::assertSame($vAntes['compradores_sin_interacciones'], $v['compradores_sin_interacciones']);
        self::assertNotNull($v['interacciones_hasta_comprar']);
        self::assertSame($antes['valor']['total']['interacciones'] + 2, $despues['valor']['total']['interacciones']);
    }

    public function testLasInspeccionesDelPeriodoTraenEstadosYMecanicos(): void
    {
        $i = $this->metricas(30)['inspecciones'];
        foreach (['pedidas', 'precio_promedio', 'horas_hasta_firma', 'por_estado', 'conclusiones', 'top_mecanicos'] as $clave) {
            self::assertArrayHasKey($clave, $i);
        }
        self::assertSame(array_sum($i['por_estado']), $i['pedidas']);
        self::assertLessThanOrEqual(5, count($i['top_mecanicos']));
    }
}
