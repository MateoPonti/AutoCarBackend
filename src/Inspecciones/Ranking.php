<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

/**
 * Puntaje público de cada mecánico: sale solo de cómo trabajó, nunca de un pago.
 * puntaje = 5 × (1 − 2 × reclamos que procedieron ÷ inspecciones firmadas), con mínimo 0.
 * Con menos de 3 firmadas no hay puntaje: se muestra "Nuevo en la red".
 */
final class Ranking
{
    public const MAXIMO          = 5.0;
    public const MINIMO_FIRMADAS = 3;

    public static function puntaje(int $firmadas, int $procedentes): ?float
    {
        if ($firmadas < self::MINIMO_FIRMADAS) {
            return null;
        }
        $procedentes = min(max($procedentes, 0), $firmadas);
        return round(max(0.0, self::MAXIMO * (1 - 2 * $procedentes / $firmadas)), 1);
    }

    /**
     * Ordena los mecánicos: primero por puntaje (los nuevos al final) y después por cantidad de firmadas.
     * @param array<array{firmadas: int, procedentes: int}> $mecanicos
     * @return array la misma lista con "puntaje" agregado, ordenada
     */
    public static function ordenar(array $mecanicos): array
    {
        foreach ($mecanicos as &$m) {
            $m['puntaje'] = self::puntaje((int)$m['firmadas'], (int)$m['procedentes']);
        }
        unset($m);
        usort($mecanicos, static fn(array $a, array $b): int =>
            [$b['puntaje'] ?? -1, $b['firmadas']] <=> [$a['puntaje'] ?? -1, $a['firmadas']]);
        return $mecanicos;
    }
}
