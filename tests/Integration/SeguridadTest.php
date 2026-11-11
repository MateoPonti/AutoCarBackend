<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

use PDOException;

/**
 * Las cinco fallas de seguridad que encontró la suite de la cátedra sobre el tag Entrega_4 (mapa de capas, 10/11):
 * pedido duplicado, moderación concurrente, 500 con entradas raras, versiones en las cabeceras e ingreso sin límite.
 */
final class SeguridadTest extends ApiTestCase
{
    use ConInspecciones;

    private const ML = 'https://auto.mercadolibre.com.ar/MLA-1234567890-fiat-uno-_JM';

    public function testElMismoAvisoConOtroLinkNoSePideDosVeces(): void
    {
        [$mecanicoId] = $this->ingresarComoMecanico();
        $this->ingresarComoUsuario();
        $r = $this->pedir('POST', '/api/inspecciones', ['url' => self::ML, 'mecanico_id' => $mecanicoId]);
        self::assertSame(201, $r['status'], json_encode($r['json']));

        // Otro link del mismo aviso (otro subdominio, sin guion y con parámetros de seguimiento): es el mismo auto
        $otroLink = 'https://articulo.mercadolibre.com.ar/MLA1234567890?tracking_id=abc#fotos';
        $r = $this->pedir('POST', '/api/inspecciones', ['url' => $otroLink, 'mecanico_id' => $mecanicoId]);
        self::assertSame(409, $r['status']);
        self::assertSame('duplicada', $r['json']['error']);
    }

    public function testLaBaseNoDejaDosPedidosEnCursoAunqueLleguenALaVez(): void
    {
        // Dos pedidos simultáneos pasan juntos el chequeo del back: el que frena al segundo es el índice único
        [$mecanicoId] = $this->ingresarComoMecanico();
        $this->ingresarComoUsuario();
        $id = $this->pedir('POST', '/api/inspecciones', ['url' => self::ML, 'mecanico_id' => $mecanicoId])['json']['inspeccion']['id'];

        $copia = "INSERT INTO inspeccion (comprador_id, mecanico_id, url, url_clave, fuente, precio, monto_mecanico, monto_autocar, monto_fondo)
            SELECT comprador_id, mecanico_id, url, url_clave, fuente, precio, monto_mecanico, monto_autocar, monto_fondo
              FROM inspeccion WHERE id = :id";
        try {
            Entorno::pdo()->prepare($copia)->execute(['id' => $id]);
            self::fail('La base aceptó dos pedidos en curso del mismo aviso.');
        } catch (PDOException $e) {
            self::assertSame('23505', $e->getCode());
        }

        // Cancelado el primero, se puede volver a pedir
        $this->pedir('POST', "/api/inspecciones/$id/cancelar", []);
        self::assertSame(201, $this->pedir('POST', '/api/inspecciones', ['url' => self::ML, 'mecanico_id' => $mecanicoId])['status']);
    }

    public function testLaModeracionNoPisaUnaDecisionYaTomada(): void
    {
        $id = $this->vehiculoDisponible();
        $this->ingresarComoAdmin();
        $baja = $this->pedir('PATCH', "/api/admin/vehiculos/$id/estado", ['estado' => 'rechazado', 'motivo' => 'Fotos de otro auto']);
        self::assertSame(200, $baja['status'], json_encode($baja['json']));

        // Un segundo moderador que todavía la veía disponible quiere darla de baja otra vez: no puede pisarla
        $otro = $this->pedir('PATCH', "/api/admin/vehiculos/$id/estado", ['estado' => 'rechazado', 'motivo' => 'Precio falso']);
        self::assertNotSame(200, $otro['status']);
        $motivo = Entorno::pdo()->query("SELECT motivo_rechazo FROM vehiculo WHERE id = $id")->fetchColumn();
        self::assertSame('Fotos de otro auto', $motivo);
    }

    public function testUnDatoQueLlegaComoArregloNoBorraLoCargado(): void
    {
        $this->ingresarComoUsuario();
        self::asegurarModelo('Volkswagen', 'Gol Trend');
        $id = $this->pedirFormulario('/api/mis-vehiculos', ['marca' => 'Volkswagen', 'modelo' => 'Gol Trend', 'anio' => '2018'])['json']['vehiculo']['id'];
        self::assertSame(200, $this->pedir('PUT', "/api/mis-vehiculos/$id", ['precio' => 9800000])['status']);

        $r = $this->pedir('PUT', "/api/mis-vehiculos/$id", ['precio' => [1], 'kilometraje' => ['a' => 1]]);
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('precio', $r['json']['errores']);
        self::assertArrayHasKey('kilometraje', $r['json']['errores']);
        self::assertEquals(9800000, $this->pedir('GET', "/api/mis-vehiculos/$id")['json']['vehiculo']['precio']);
    }

    public function testEntradasRarasNoDan500(): void
    {
        $idEnorme = $this->pedir('GET', '/api/vehiculos/99999999999999999999');
        self::assertSame(404, $idEnorme['status']);
        self::assertSame('no_encontrado', $idEnorme['json']['error']);

        self::assertSame(200, $this->pedir('GET', '/api/vehiculos?page=99999999999999999999')['status']);
        self::assertSame(200, $this->pedir('GET', '/api/vehiculos?page[]=1&q[]=a&marca[x]=b')['status']);

        // Texto que no es UTF-8 o con un byte nulo: la base lo rechaza, y eso es un 400 (culpa del dato), no un 500
        foreach (['/api/vehiculos?q=%FF%FE', '/api/vehiculos?q=a%00b', '/api/mecanicos?localidad=%C3'] as $ruta) {
            $r = $this->pedir('GET', $ruta);
            self::assertContains($r['status'], [200, 400], "$ruta dio {$r['status']}");
            self::assertIsArray($r['json'], "$ruta no respondió JSON");
        }
    }

    public function testLasRespuestasNoDicenLaVersionDePhp(): void
    {
        $r = $this->pedir('GET', '/api/vehiculos');
        self::assertArrayNotHasKey('x-powered-by', $r['headers']);
    }

    public function testElIngresoSeFrenaDespuesDeCincoIntentos(): void
    {
        $email = $this->registrar();
        for ($i = 1; $i <= 5; $i++) {
            self::assertSame(401, $this->ingresar($email, 'MalaClave99')['status'], "intento $i");
        }
        $frenado = $this->ingresar($email, 'MalaClave99');
        self::assertSame(429, $frenado['status']);
        self::assertSame('demasiados_intentos', $frenado['json']['error']);
        self::assertGreaterThan(0, (int)$frenado['headers']['retry-after']);

        // Ni con la contraseña correcta: si no, el que prueba claves igual se enteraría cuál es
        self::assertSame(429, $this->ingresar($email)['status']);

        // Otro email no se ve afectado, y un ingreso correcto borra los intentos fallidos
        $otro = $this->registrar();
        self::assertSame(401, $this->ingresar($otro, 'MalaClave99')['status']);
        self::assertSame(200, $this->ingresar($otro)['status']);
        self::assertSame(0, (int)Entorno::pdo()->query("SELECT COUNT(*) FROM login_intento WHERE email = '$otro'")->fetchColumn());
    }
}
