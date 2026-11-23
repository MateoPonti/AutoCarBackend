<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Parametros\Parametros;

/**
 * Orden público de los mecánicos: por experiencia, nunca por un pago. Sin reclamos (se sacaron el 23/11: no hay
 * forma de verificar si un informe falló), lo que se puede medir es cuántas inspecciones firmó cada uno.
 * Con menos de ranking_minimo_firmadas (parámetro, de fábrica 3) aparece como "Nuevo en la red" y va después.
 * Sin indicar valores se usan los de fábrica (así se prueba sin base).
 */
final class Ranking
{
    public const MINIMO_FIRMADAS = 3;

    /**
     * El valor de Parámetros (030). Si todavía no está en la base, el de fábrica.
     * @return array{minimo_firmadas: int}
     */
    public static function valores(): array
    {
        try {
            return ['minimo_firmadas' => (int)Parametros::valor('ranking_minimo_firmadas')];
        } catch (\RuntimeException) {
            return self::deFabrica();
        }
    }

    /** @return array{minimo_firmadas: int} */
    public static function deFabrica(): array
    {
        return ['minimo_firmadas' => self::MINIMO_FIRMADAS];
    }

    /** Si todavía es "Nuevo en la red" (menos firmadas que el mínimo). */
    public static function esNuevo(int $firmadas, ?array $v = null): bool
    {
        $v ??= self::deFabrica();
        return $firmadas < max($v['minimo_firmadas'], 1);
    }

    /**
     * Ordena los mecánicos: primero los que ya tienen el mínimo, de más a menos firmadas; después los nuevos.
     * @param array<array{firmadas: int}> $mecanicos
     * @return array la misma lista con "nuevo" agregado, ordenada
     */
    public static function ordenar(array $mecanicos, ?array $v = null): array
    {
        foreach ($mecanicos as &$m) {
            $m['nuevo'] = self::esNuevo((int)$m['firmadas'], $v);
        }
        unset($m);
        usort($mecanicos, static fn(array $a, array $b): int =>
            [$a['nuevo'], -$a['firmadas']] <=> [$b['nuevo'], -$b['firmadas']]);
        return $mecanicos;
    }

    /**
     * Especialidad en un auto: cuántos de ese modelo firmó o, si no revisó ninguno, cuántos de otros modelos de la marca.
     * @param array{modelo: int, marca: int} $r de Taller::revisados
     * @return array{en: string, revisados: int}|null null si no revisó ninguno de esa marca
     */
    public static function especialidad(array $r): ?array
    {
        [$en, $revisados] = $r['modelo'] > 0 ? ['modelo', $r['modelo']] : ['marca', $r['marca']];
        return $revisados > 0 ? ['en' => $en, 'revisados' => $revisados] : null;
    }

    /**
     * Ordena por especialidad: 1) revisó ese modelo, 2) revisó la marca, 3) sin experiencia en esos autos.
     * Dentro de cada grupo, más revisados primero; si empatan, queda el orden general (usort es estable).
     * @param array<array{especialidad: ?array}> $mecanicos ya ordenados con ordenar()
     */
    public static function porEspecialidad(array $mecanicos): array
    {
        $clave = static function (array $m): array {
            $e = $m['especialidad'];
            $grupo = match (true) {
                $e === null           => 3,
                $e['en'] === 'modelo' => 1,
                default               => 2,
            };
            return [$grupo, -($e['revisados'] ?? 0)];
        };
        usort($mecanicos, static fn(array $a, array $b): int => $clave($a) <=> $clave($b));
        return $mecanicos;
    }
}
