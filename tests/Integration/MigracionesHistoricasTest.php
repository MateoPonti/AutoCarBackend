<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

final class MigracionesHistoricasTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        Entorno::url();
        $this->db = Entorno::pdo();
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    private function ejecutar(string $archivo): void
    {
        $this->db->exec((string)file_get_contents(dirname(__DIR__, 2) . '/database/' . $archivo));
    }

    public function testRepetirSobreLaEntregaFinalConservaEsquemaYPrecios(): void
    {
        $this->ejecutar('007-demo-inspecciones-solo-dev.sql');
        $this->db->exec("UPDATE parametro SET valor = 130000 WHERE clave = 'precio_maximo'");
        // Taller de muestra antiguo sin la marca demo, y una cuenta ajena a las dos generaciones de muestra.
        $this->db->exec("UPDATE usuario SET email = 'retro@autocar.test' WHERE email = 'mecanico4@demo.autocar.com.ar'");
        $this->db->exec("UPDATE usuario SET email = 'externo@example.org' WHERE email = 'mecanico5@demo.autocar.com.ar'");
        $this->db->exec("UPDATE taller SET demo = false, precio_inspeccion = 165000 WHERE usuario_id IN
            (SELECT id FROM usuario WHERE email IN ('retro@autocar.test','externo@example.org'))");
        $this->db->exec("UPDATE taller SET precio_inspeccion = 165000 WHERE usuario_id =
            (SELECT id FROM usuario WHERE email = 'mecanico1@demo.autocar.com.ar')");
        $unidades = "SELECT pg_get_constraintdef(oid) FROM pg_constraint
            WHERE conrelid = 'parametro'::regclass AND conname = 'parametro_unidad_check'";
        $antes = $this->db->query($unidades)->fetchColumn();

        for ($vez = 0; $vez < 2; $vez++) {
            $this->ejecutar('001-e4.sql');
            $this->ejecutar('024-auto-vendido-y-fondo.sql');
            $this->ejecutar('036-tope-talleres-demo.sql');
        }

        self::assertSame($antes, $this->db->query($unidades)->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT count(*) FROM parametro WHERE clave = 'franquicia'")->fetchColumn());
        self::assertTrue((bool)$this->db->query("SELECT to_regclass('vehiculo_fotos') IS NULL AND to_regclass('reclamo') IS NULL")->fetchColumn());
        $precios = $this->db->query("SELECT u.email, t.precio_inspeccion::integer FROM taller t
            JOIN usuario u ON u.id = t.usuario_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertEquals(130000, $precios['retro@autocar.test']);
        self::assertEquals(130000, $precios['mecanico1@demo.autocar.com.ar']);
        self::assertEquals(120000, $precios['mecanico3@demo.autocar.com.ar']);
        self::assertEquals(165000, $precios['externo@example.org']);
    }

    public function testRepetirEnElEsquemaHistoricoPreservaElReclamoYLaPublicacion(): void
    {
        $this->db->exec("CREATE SCHEMA legado; SET LOCAL search_path TO legado;
            CREATE TABLE users (id integer PRIMARY KEY);
            CREATE TABLE vehiculos (id integer PRIMARY KEY, estado text);
            INSERT INTO vehiculos VALUES (1, 'disponible');");
        $this->ejecutar('001-e4.sql');
        $this->ejecutar('001-e4.sql');
        $this->ejecutar('002-nombres.sql');
        $this->ejecutar('001-e4.sql');
        self::assertSame('disponible', $this->db->query('SELECT estado FROM vehiculo WHERE id = 1')->fetchColumn());

        $this->db->exec("CREATE TABLE inspeccion (id integer PRIMARY KEY, estado text, precio numeric);
            CREATE TABLE reclamo (inspeccion_id integer, estado text);
            CREATE TABLE parametro (clave text PRIMARY KEY, valor numeric, minimo numeric, maximo numeric,
                unidad text CHECK (unidad IN ('porcentaje','pesos','dias','veces')), descripcion text, orden integer);
            INSERT INTO inspeccion VALUES (1, 'firmada', 125000);
            INSERT INTO reclamo VALUES (1, 'procede');");
        $this->ejecutar('024-auto-vendido-y-fondo.sql');
        $this->ejecutar('024-auto-vendido-y-fondo.sql');
        self::assertEquals(125000, $this->db->query('SELECT devolucion FROM reclamo')->fetchColumn());
        self::assertSame(3, (int)$this->db->query('SELECT count(*) FROM parametro')->fetchColumn());
    }
}
