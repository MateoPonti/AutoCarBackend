<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Publicaciones\Foto;
use PHPUnit\Framework\TestCase;

/** Validaciones de $_FILES que no necesitan un archivo subido de verdad. */
final class FotoTest extends TestCase
{
    private static function campo(array $tamanios, int $error = UPLOAD_ERR_OK): array
    {
        $n = count($tamanios);
        return [
            'name' => array_fill(0, $n, 'foto.jpg'),
            'tmp_name' => array_fill(0, $n, '/tmp/no-existe'),
            'error' => array_fill(0, $n, $error),
            'size' => $tamanios,
        ];
    }

    public function testSinFotosPideAlMenosUna(): void
    {
        self::assertSame([[], 'Agregá al menos una foto.'], Foto::validarSubidas(null));
        self::assertSame([[], 'Agregá al menos una foto.'], Foto::validarSubidas(self::campo([0], UPLOAD_ERR_NO_FILE)));
    }

    public function testMasDelMaximoDeFotos(): void
    {
        [$fotos, $error] = Foto::validarSubidas(self::campo(array_fill(0, Foto::MAX + 1, 1000)));
        self::assertSame([], $fotos);
        self::assertStringContainsString((string)Foto::MAX, $error);
    }

    public function testFotoMasPesadaQueElLimite(): void
    {
        self::assertSame('Cada foto puede pesar hasta 2 MB.', Foto::validarSubidas(self::campo([Foto::MAX_BYTES + 1]))[1]);
        self::assertSame('Cada foto puede pesar hasta 2 MB.', Foto::validarSubidas(self::campo([10], UPLOAD_ERR_INI_SIZE))[1]);
    }

    public function testUnArchivoQueNoSeSubioPorHttpSeRechaza(): void
    {
        // is_uploaded_file() es false fuera de un pedido real: protege contra rutas inventadas.
        self::assertSame('No se pudo subir una de las fotos. Probá de nuevo.', Foto::validarSubidas(self::campo([1000]))[1]);
    }
}
