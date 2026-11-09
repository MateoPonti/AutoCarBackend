<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Las migraciones llevan el orden en el nombre (ver database/README.md): que nadie suba una con otro formato. */
final class MigracionesTest extends TestCase
{
    public function testLasMigracionesEstanNumeradasSinHuecosNiRepetidos(): void
    {
        $carpeta = dirname(__DIR__, 2) . '/database';
        $numeros = [];
        foreach (glob("$carpeta/*.sql") ?: [] as $archivo) {
            $nombre = basename($archivo);
            if (in_array($nombre, ['schema.sql', 'seed.sql'], true)) {
                continue;
            }
            self::assertMatchesRegularExpression('/^\d{3}-[a-z0-9]+(-[a-z0-9]+)*\.sql$/', $nombre,
                "$nombre: las migraciones se llaman NNN-nombre.sql con el número que sigue (ver database/README.md)");
            $numeros[] = (int)substr($nombre, 0, 3);
        }
        sort($numeros);
        self::assertSame(range(1, count($numeros)), $numeros, 'los números van de 001 en adelante, sin repetir ni saltear');
    }

    public function testCadaMigracionFiguraEnElReadmeDeLaCarpeta(): void
    {
        $carpeta = dirname(__DIR__, 2) . '/database';
        $readme = (string)file_get_contents("$carpeta/README.md");
        foreach (glob("$carpeta/[0-9][0-9][0-9]-*.sql") ?: [] as $archivo) {
            self::assertStringContainsString(basename($archivo), $readme, basename($archivo) . ' falta en database/README.md');
        }
    }
}
