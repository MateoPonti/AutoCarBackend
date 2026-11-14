<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

/**
 * Huella del informe firmado: SHA-256 de su contenido en un orden fijo.
 * Si alguien cambia un ítem, la conclusión, el nombre del mecánico o la patente, la huella ya no coincide.
 * La patente entra desde el 14/11 (022-patente.sql): las firmadas antes no la tienen en la huella.
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
        ?string $patente = null,
    ): string {
        ksort($items);
        $renglones = [];
        foreach ($items as $codigo => [$resultado, $nota]) {
            $renglones[] = [$codigo, $resultado, $nota];
        }
        $partes = [$inspeccionId, $renglones, $conclusion, $resumen, $firmadoPor, $taller, $firmadaAt];
        if ($patente !== null) {
            $partes[] = $patente;
        }
        $contenido = json_encode(
            $partes,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        return hash('sha256', $contenido);
    }
}
