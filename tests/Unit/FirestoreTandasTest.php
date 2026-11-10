<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Comparador\Firestore;
use PHPUnit\Framework\TestCase;

/** Los commits a Firestore se arman por tamaño: con la corrida completa de ML, 400 documentos pasaban los 10 MiB. */
final class FirestoreTandasTest extends TestCase
{
    private static function write(int $bytes): array
    {
        return ['update' => ['name' => 'x', 'fields' => ['autos_json' => ['stringValue' => str_repeat('a', $bytes)]]]];
    }

    public function testDocumentosGrandesVanEnVariosCommits(): void
    {
        $writes = array_fill(0, 30, self::write(700 * 1024)); // 30 modelos de ~700 KB ≈ 21 MB
        $tandas = Firestore::tandas($writes);
        self::assertGreaterThan(1, count($tandas));
        foreach ($tandas as $t) {
            self::assertLessThanOrEqual(Firestore::BYTES_POR_COMMIT, strlen(json_encode($t)));
        }
        self::assertSame(30, array_sum(array_map('count', $tandas)), 'no se pierde ninguno');
    }

    public function testDocumentosChicosRespetanElMaximoDeEscrituras(): void
    {
        $tandas = Firestore::tandas(array_fill(0, 1000, self::write(10)));
        self::assertSame([400, 400, 200], array_map('count', $tandas));
    }

    public function testUnDocumentoMasGrandeQueElLimiteVaSolo(): void
    {
        $tandas = Firestore::tandas([self::write(10), self::write(100), self::write(10)], 50);
        self::assertSame([1, 1, 1], array_map('count', $tandas));
    }
}
