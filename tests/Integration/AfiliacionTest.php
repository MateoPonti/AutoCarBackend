<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

use AutoCar\MetaBusqueda\Importador;

/** "Ver en …" del metabuscador: el clic se anota y redirige; el admin ve el mes, cambia la tarifa y cobra meses cerrados. */
final class AfiliacionTest extends ApiTestCase
{
    public function testElClicSeAnotaRedirigeYSeCobraPorMes(): void
    {
        // Una plataforma propia de esta prueba, con su convenio, y un auto suyo en el metabuscador
        $fuente = 'f' . bin2hex(random_bytes(4));
        $marca = 'M' . bin2hex(random_bytes(4));
        $url = "https://plataforma.test/$fuente/1";
        Entorno::url();
        (new Importador(Entorno::documentos()))->importar(['fuente' => $fuente, 'fecha' => '2026-11-02T06:00:00-03:00', 'completo' => true, 'autos' => [[
            'id_externo' => '1', 'marca' => $marca, 'modelo' => 'Sandero', 'version' => '1.6', 'anio' => 2017, 'km' => 100000,
            'transmision' => 'manual', 'precio' => 15000000, 'precio_contado' => null, 'moneda' => 'ARS',
            'reservado' => false, 'ubicacion' => 'Buenos Aires', 'url' => $url,
        ]]]);
        Entorno::pdo()->prepare("INSERT INTO convenio_plataforma (fuente, nombre, tarifa_clic) VALUES (?, 'Prueba', 200)")->execute([$fuente]);

        $this->ingresarComoUsuario();
        $b = $this->pedir('POST', '/api/meta-busquedas', ['marca' => $marca, 'modelo' => 'Sandero']);
        self::assertSame(201, $b['status'], json_encode($b['json']));
        $auto = $this->pedir('GET', '/api/meta-busquedas/' . $b['json']['busqueda']['id'])['json']['resultados']['items'][0];

        // Redirige al aviso de la plataforma (la dirección sale de los datos del scraper); repetido no vale
        $salida = $this->pedir('GET', '/api/salida?id=' . rawurlencode($auto['id']) . '&origen=para_vos');
        self::assertSame(302, $salida['status']);
        self::assertSame($url, $salida['headers']['location']);
        self::assertSame(302, $this->pedir('GET', '/api/salida?id=' . rawurlencode($auto['id']))['status']);
        self::assertSame(404, $this->pedir('GET', '/api/salida?id=no~existe~1')['status']);
        self::assertSame(403, $this->pedir('GET', '/api/admin/afiliacion')['status']);

        // El admin: 2 clics, 1 válido × $200
        $this->ingresarComoAdmin();
        $fila = self::fila($this->pedir('GET', '/api/admin/afiliacion')['json']['este_mes']['filas'], $fuente);
        self::assertSame(2, $fila['clics']);
        self::assertSame(1, $fila['validos']);
        self::assertEquals(200, $fila['monto']);
        $origen = Entorno::pdo()->prepare('SELECT origen FROM clic_salida WHERE fuente = ? ORDER BY id LIMIT 1');
        $origen->execute([$fuente]);
        self::assertSame('para_vos', $origen->fetchColumn());

        // Cambiar la tarifa cambia lo que se va a cobrar este mes
        self::assertSame(422, $this->pedir('PUT', '/api/admin/afiliacion/convenios', ['fuente' => $fuente, 'tarifa_clic' => -1, 'activo' => true])['status']);
        self::assertSame(404, $this->pedir('PUT', '/api/admin/afiliacion/convenios', ['fuente' => 'nadie', 'tarifa_clic' => 1, 'activo' => true])['status']);
        self::assertSame(200, $this->pedir('PUT', '/api/admin/afiliacion/convenios', ['fuente' => $fuente, 'tarifa_clic' => 300, 'activo' => true])['status']);
        self::assertEquals(300, self::fila($this->pedir('GET', '/api/admin/afiliacion')['json']['este_mes']['filas'], $fuente)['monto']);

        // El mes en curso no se cobra; uno cerrado sí, una sola vez, con el monto de ese momento
        $ahora = new \DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires'));
        self::assertSame(409, $this->pedir('POST', '/api/admin/afiliacion/cobros', ['fuente' => $fuente, 'mes' => $ahora->format('Y-m')])['status']);
        $pasado = $ahora->modify('first day of last month')->modify('+10 days');
        Entorno::pdo()->prepare("INSERT INTO clic_salida (fuente, auto_id, valido, created_at) VALUES (?, 'x', true, ?), (?, 'y', true, ?)")
            ->execute([$fuente, $pasado->format(DATE_ATOM), $fuente, $pasado->format(DATE_ATOM)]);
        $mes = $pasado->format('Y-m');
        $panel = $this->pedir('POST', '/api/admin/afiliacion/cobros', ['fuente' => $fuente, 'mes' => $mes]);
        self::assertSame(200, $panel['status'], json_encode($panel['json']));
        $delMes = array_values(array_filter($panel['json']['evolucion'], static fn(array $m): bool => $m['mes'] === "$mes-01"))[0];
        $cobrada = self::fila($delMes['filas'], $fuente);
        self::assertNotNull($cobrada['cobrado_at']);
        self::assertEquals(600, $cobrada['monto']); // 2 × 300
        self::assertSame(409, $this->pedir('POST', '/api/admin/afiliacion/cobros', ['fuente' => $fuente, 'mes' => $mes])['status']);
        self::assertSame(422, $this->pedir('POST', '/api/admin/afiliacion/cobros', ['fuente' => $fuente, 'mes' => '2026-13'])['status']);

        // Si después cambia la tarifa, el mes cobrado no se mueve
        $this->pedir('PUT', '/api/admin/afiliacion/convenios', ['fuente' => $fuente, 'tarifa_clic' => 1000, 'activo' => true]);
        $evo = $this->pedir('GET', '/api/admin/afiliacion')['json']['evolucion'];
        $delMes = array_values(array_filter($evo, static fn(array $m): bool => $m['mes'] === "$mes-01"))[0];
        self::assertEquals(600, self::fila($delMes['filas'], $fuente)['monto']);
    }

    private static function fila(array $filas, string $fuente): array
    {
        return array_values(array_filter($filas, static fn(array $f): bool => $f['fuente'] === $fuente))[0];
    }
}
