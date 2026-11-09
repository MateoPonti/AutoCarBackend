<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

/**
 * Huella del informe firmado: SHA-256 de su contenido en un orden fijo.
 * Si alguien cambia un ítem, la conclusión o el nombre del mecánico, la huella ya no coincide.
 * No es una firma digital con validez legal: prueba que el informe no cambió desde que se firmó.
 */
final class Huella
{
    /**
     * @param array<string, array{0: string, 1: ?string}> $items codigo => [resultado, nota]
     */
    public static function calcular(
        int $inspeccionId,
        array $items,
        string $conclusion,
        ?string $resumen,
        string $firmadoPor,
        string $taller,
        string $firmadaAt,
    ): string {
        ksort($items);
        $renglones = [];
        foreach ($items as $codigo => [$resultado, $nota]) {
            $renglones[] = [$codigo, $resultado, $nota];
        }
        $contenido = json_encode(
            [$inspeccionId, $renglones, $conclusion, $resumen, $firmadoPor, $taller, $firmadaAt],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        return hash('sha256', $contenido);
    }
}
