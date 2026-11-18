<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Parametros\Parametros;

/**
 * Puntaje público de cada mecánico: sale solo de cómo trabajó, nunca de un pago.
 * puntaje = 5 × (1 − 2 × reclamos que procedieron ÷ inspecciones firmadas), con mínimo 0.
 * Con menos de 3 firmadas no hay puntaje: se muestra "Nuevo en la red".
 * El 2, el 3 y el 2,5 de la especialidad son parámetros (030, el admin los cambia); el 5 queda fijo porque el sitio
 * dibuja cinco estrellas. Sin indicar valores se usan los de fábrica (así se prueban sin base).
 */
final class Ranking
{
    public const MAXIMO          = 5.0;
    public const MINIMO_FIRMADAS = 3;
    /** Cuánto pesa cada reclamo que procedió (el "2" de la fórmula) */
    public const VECES_RECLAMO   = 2.0;

    /**
     * Los valores de Parámetros (030). Si todavía no están en la base, los de fábrica.
     * @return array{veces_reclamo: float, minimo_firmadas: int, especialidad_buena: float}
     */
    public static function valores(): array
    {
        try {
            return [
                'veces_reclamo'      => Parametros::valor('ranking_veces_reclamo'),
                'minimo_firmadas'    => (int)Parametros::valor('ranking_minimo_firmadas'),
                'especialidad_buena' => Parametros::valor('ranking_especialidad_buena'),
            ];
        } catch (\RuntimeException) {
            return self::deFabrica();
        }
    }

    /** @return array{veces_reclamo: float, minimo_firmadas: int, especialidad_buena: float} */
    public static function deFabrica(): array
    {
        return ['veces_reclamo' => self::VECES_RECLAMO, 'minimo_firmadas' => self::MINIMO_FIRMADAS,
            'especialidad_buena' => self::ESPECIALIDAD_BUENA];
    }

    /** @param array{veces_reclamo: float, minimo_firmadas: int, especialidad_buena: float}|null $v los de valores() */
    public static function puntaje(int $firmadas, int $procedentes, ?array $v = null): ?float
    {
        $v ??= self::deFabrica();
        if ($firmadas < max($v['minimo_firmadas'], 1)) {
            return null;
        }
        return self::cuenta($firmadas, $procedentes, $v['veces_reclamo']);
    }

    /** 5 × (1 − veces × reclamos ÷ revisados), entre 0 y 5 */
    private static function cuenta(int $revisados, int $reclamos, float $veces): float
    {
        $reclamos = min(max($reclamos, 0), $revisados);
        return round(max(0.0, self::MAXIMO * (1 - $veces * $reclamos / $revisados)), 1);
    }

    /**
     * Ordena los mecánicos: primero por puntaje (los nuevos al final) y después por cantidad de firmadas.
     * @param array<array{firmadas: int, procedentes: int}> $mecanicos
     * @return array la misma lista con "puntaje" agregado, ordenada
     */
    public static function ordenar(array $mecanicos, ?array $v = null): array
    {
        foreach ($mecanicos as &$m) {
            $m['puntaje'] = self::puntaje((int)$m['firmadas'], (int)$m['procedentes'], $v);
        }
        unset($m);
        usort($mecanicos, static fn(array $a, array $b): int =>
            [$b['puntaje'] ?? -1, $b['firmadas']] <=> [$a['puntaje'] ?? -1, $a['firmadas']]);
        return $mecanicos;
    }

    /** De fábrica: desde este puntaje la especialidad es buena (con la fórmula de arriba: hasta 1 reclamo cada 4 revisados) */
    public const ESPECIALIDAD_BUENA = 2.5;

    /**
     * Especialidad en un auto: el mismo puntaje del ranking, pero solo con las inspecciones de ese modelo
     * o, si no revisó ninguno, de otros modelos de la marca (autos parecidos). Sin el mínimo de 3: una sola cuenta.
     * @param array{modelo: int, reclamos_modelo: int, marca: int, reclamos_marca: int} $r de Taller::revisados
     * @return array{en: string, revisados: int, reclamos: int, puntaje: float, buena: bool}|null null si no revisó ninguno
     */
    public static function especialidad(array $r, ?array $v = null): ?array
    {
        $v ??= self::deFabrica();
        [$en, $revisados, $reclamos] = $r['modelo'] > 0
            ? ['modelo', $r['modelo'], $r['reclamos_modelo']]
            : ['marca', $r['marca'], $r['reclamos_marca']];
        if ($revisados === 0) {
            return null;
        }
        $reclamos = min($reclamos, $revisados);
        $puntaje = self::cuenta($revisados, $reclamos, $v['veces_reclamo']);
        return ['en' => $en, 'revisados' => $revisados, 'reclamos' => $reclamos, 'puntaje' => $puntaje,
            'buena' => $puntaje >= $v['especialidad_buena']];
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
