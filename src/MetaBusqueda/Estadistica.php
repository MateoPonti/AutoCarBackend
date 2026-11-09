<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

/** Cálculos sobre listas de números. La mediana resiste mejor que el promedio un precio muy distinto al resto. */
final class Estadistica
{
    /** @param list<int|float> $valores */
    public static function mediana(array $valores): ?float
    {
        return self::percentil($valores, 0.5);
    }

    /**
     * Percentil con interpolación lineal (igual que percentile_cont de PostgreSQL). Lista vacía → null.
     *
     * @param list<int|float> $valores
     */
    public static function percentil(array $valores, float $p): ?float
    {
        if ($valores === []) {
            return null;
        }
        sort($valores);
        $posicion = $p * (count($valores) - 1);
        $abajo = (int)floor($posicion);
        $arriba = (int)ceil($posicion);
        return $valores[$abajo] + ($valores[$arriba] - $valores[$abajo]) * ($posicion - $abajo);
    }
}
