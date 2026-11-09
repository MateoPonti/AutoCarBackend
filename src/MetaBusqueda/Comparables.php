<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

/**
 * Autos parecidos con los que se decide si un precio está bien: misma plataforma y moneda, mismo modelo,
 * año y kilometraje cercanos. Si no se llega al mínimo, se abre la búsqueda por pasos. Los valores son
 * un punto de partida para ajustar con datos reales.
 */
final class Comparables
{
    public const MINIMO = 5;
    public const UMBRAL_ETIQUETA = 7.0;     // % de diferencia con la mediana para "barato" o "caro"
    private const TOLERANCIA_KM = 0.30;     // ±30%
    private const TOLERANCIA_KM_MIN = 10000; // para que un auto de pocos km no se quede sin comparables

    /**
     * @param array<string, mixed> $auto
     * @param list<array<string, mixed>> $candidatos autos de la última recolección completa
     * @return array{autos: list<array<string, mixed>>, suficientes: bool, criterio: string, mediana_precio: ?float, mediana_km: ?float}
     */
    public static function elegir(array $auto, array $candidatos): array
    {
        $base = array_values(array_filter($candidatos, static fn(array $c): bool =>
            $c['id'] !== $auto['id']
            && $c['precio'] !== null
            && !$c['reservado']
            && $c['fuente'] === $auto['fuente']
            && $c['moneda'] === $auto['moneda']
            && mb_strtolower($c['marca']) === mb_strtolower($auto['marca'])
            && mb_strtolower($c['modelo']) === mb_strtolower($auto['modelo'])));

        // Los kilómetros siempre se filtran: sin eso, un auto poco usado parecería caro frente a otros muy usados
        $elegidos = [];
        $criterio = '';
        foreach (['anio_2_km' => 2, 'anio_3_km' => 3] as $criterio => $anios) {
            $elegidos = array_values(array_filter($base, static fn(array $c): bool =>
                abs($c['anio'] - $auto['anio']) <= $anios && self::kmParecidos($c['km'], $auto['km'])));
            if (count($elegidos) >= self::MINIMO) {
                return [
                    'autos'          => $elegidos,
                    'suficientes'    => true,
                    'criterio'       => $criterio,
                    'mediana_precio' => Estadistica::mediana(array_column($elegidos, 'precio')),
                    'mediana_km'     => Estadistica::mediana(array_column($elegidos, 'km')),
                ];
            }
        }
        return ['autos' => $elegidos, 'suficientes' => false, 'criterio' => $criterio, 'mediana_precio' => null, 'mediana_km' => null];
    }

    private static function kmParecidos(int $a, int $b): bool
    {
        return abs($a - $b) <= max($b * self::TOLERANCIA_KM, self::TOLERANCIA_KM_MIN);
    }

    /**
     * Etiqueta del precio de un auto frente a sus comparables.
     *
     * @param array{autos: list<array<string, mixed>>, suficientes: bool, mediana_precio: ?float} $comparacion
     * @return array{valor: string, diferencia_pct: ?float, comparables: int, explicacion: string}
     */
    public static function etiqueta(?float $precio, array $comparacion): array
    {
        $cantidad = count($comparacion['autos']);
        if ($precio === null || !$comparacion['suficientes']) {
            return [
                'valor'          => 'datos_insuficientes',
                'diferencia_pct' => null,
                'comparables'    => $cantidad,
                'explicacion'    => 'No hay suficientes autos parecidos para comparar este precio.',
            ];
        }

        $diferencia = round(($precio - $comparacion['mediana_precio']) / $comparacion['mediana_precio'] * 100, 1);
        $valor = 'normal';
        if ($diferencia <= -self::UMBRAL_ETIQUETA) {
            $valor = 'barato';
        } elseif ($diferencia >= self::UMBRAL_ETIQUETA) {
            $valor = 'caro';
        }

        $relacion = match (true) {
            $diferencia < 0 => abs($diferencia) . '% menos que',
            $diferencia > 0 => $diferencia . '% más que',
            default         => 'Igual que',
        };
        return [
            'valor'          => $valor,
            'diferencia_pct' => $diferencia,
            'comparables'    => $cantidad,
            'explicacion'    => "$relacion el precio típico de $cantidad autos parecidos.",
        ];
    }
}
