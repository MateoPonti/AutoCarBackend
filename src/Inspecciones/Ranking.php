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

    /** Desde este puntaje la especialidad es buena (con la fórmula de arriba: hasta 1 reclamo cada 4 revisados) */
    public const ESPECIALIDAD_BUENA = 2.5;

    /**
     * Especialidad en un auto: el mismo puntaje del ranking, pero solo con las inspecciones de ese modelo
     * o, si no revisó ninguno, de otros modelos de la marca (autos parecidos). Sin el mínimo de 3: una sola cuenta.
     * @param array{modelo: int, reclamos_modelo: int, marca: int, reclamos_marca: int} $r de Taller::revisados
     * @return array{en: string, revisados: int, reclamos: int, puntaje: float, buena: bool}|null null si no revisó ninguno
     */
    public static function especialidad(array $r): ?array
    {
        [$en, $revisados, $reclamos] = $r['modelo'] > 0
            ? ['modelo', $r['modelo'], $r['reclamos_modelo']]
            : ['marca', $r['marca'], $r['reclamos_marca']];
        if ($revisados === 0) {
            return null;
        }
        $reclamos = min($reclamos, $revisados);
        $puntaje = round(max(0.0, self::MAXIMO * (1 - 2 * $reclamos / $revisados)), 1);
        return ['en' => $en, 'revisados' => $revisados, 'reclamos' => $reclamos, 'puntaje' => $puntaje,
            'buena' => $puntaje >= self::ESPECIALIDAD_BUENA];
    }

    /**
     * Ordena por especialidad: 1) buena en ese modelo, 2) buena en la marca, 3) sin experiencia, 4) mala (le
     * procedieron reclamos en esos autos). Dentro de cada grupo, mejor especialidad y más revisados primero; si
     * empatan, queda el orden del ranking general (usort es estable).
     * @param array<array{especialidad: ?array}> $mecanicos ya ordenados por ranking
     */
    public static function porEspecialidad(array $mecanicos): array
    {
        $clave = static function (array $m): array {
            $e = $m['especialidad'];
            $grupo = match (true) {
                $e === null                        => 3,
                !$e['buena']                       => 4,
                $e['en'] === 'modelo'              => 1,
                default                            => 2,
            };
            return [$grupo, -($e['puntaje'] ?? 0), -($e['revisados'] ?? 0)];
        };
        usort($mecanicos, static fn(array $a, array $b): int => $clave($a) <=> $clave($b));
        return $mecanicos;
    }
}
