<?php
declare(strict_types=1);

namespace AutoCar\Comparador;

use AutoCar\MetaBusqueda\Estadistica;

/**
 * La publicación que se compara sola: compara el precio de un auto nuestro contra los autos iguales de todos
 * los sitios (MercadoLibre, Kavak, Autocosmos). Sin base ni red: recibe el documento del modelo ya leído.
 *
 * Reglas (las del modelo de negocio):
 *   - Mismo modelo, año ±2 y km parecidos (±30 %, mínimo ±10.000). Si no alcanza, se abre a año ±3.
 *   - Se compara en pesos: los avisos en dólares se pasan con el dólar del día de la corrida.
 *   - Se descartan los precios extremos (fuera de 1,5 veces el rango intercuartil): anticipos, cuotas o errores de carga.
 *   - Con menos de 5 autos parecidos no se muestra el dato. Si está caro, también se dice.
 */
final class Comparador
{
    public const MINIMO = 5;
    public const UMBRAL = 7.0; // % respecto de la mediana para "buen precio" o "caro"
    private const TOLERANCIA_KM = 0.30;
    private const TOLERANCIA_KM_MIN = 10000;
    private const PASOS_ANIOS = [2, 3];
    private const PARECIDOS = 6;

    public const BUEN_PRECIO = 'buen_precio';
    public const EN_PRECIO = 'en_precio';
    public const CARO = 'caro';
    public const SIN_DATOS = 'sin_datos';

    /**
     * @param array{marca: string, modelo: string, anio: int, kilometraje: int, precio: float} $vehiculo
     * @param array<string, mixed>|null $modelo documento del modelo (ver Modelos::armar), null si no hay datos
     * @return array<string, mixed>
     */
    public static function comparar(array $vehiculo, ?array $modelo): array
    {
        $precio = (float)$vehiculo['precio'];
        $anio = (int)$vehiculo['anio'];
        $km = (int)$vehiculo['kilometraje'];
        $dolar = isset($modelo['dolar']) ? (float)$modelo['dolar'] : null;

        $candidatos = [];
        foreach ($modelo['autos'] ?? [] as $tupla) {
            $a = Modelos::desplegar($tupla);
            $a['precio_ars'] = self::enPesos($a['precio'], $a['moneda'], $dolar);
            if ($a['precio_ars'] !== null && self::kmParecidos($a['km'], $km)) {
                $candidatos[] = $a;
            }
        }

        $elegidos = [];
        $anios = 0;
        $descartados = 0;
        foreach (self::PASOS_ANIOS as $anios) {
            $enRango = array_values(array_filter($candidatos, static fn(array $a): bool => abs($a['anio'] - $anio) <= $anios));
            [$elegidos, $descartados] = self::sinExtremos($enRango);
            if (count($elegidos) >= self::MINIMO) {
                break;
            }
        }

        $base = [
            'clave'       => Clave::de($vehiculo['marca'], $vehiculo['modelo']),
            'fecha_datos' => $modelo['fecha'] ?? null,
            'precio'      => $precio,
            'precio_usd'  => $dolar ? round($precio / $dolar) : null,
            'dolar'       => $dolar,
            'criterio'    => ['anios' => $anios, 'km_pct' => (int)(self::TOLERANCIA_KM * 100), 'minimo' => self::MINIMO],
        ];
        if (count($elegidos) < self::MINIMO) {
            return $base + [
                'etiqueta'    => self::SIN_DATOS,
                'texto'       => 'Todavía no hay suficientes autos parecidos en otros sitios para comparar este precio.',
                'comparables' => count($elegidos),
            ];
        }

        $precios = array_column($elegidos, 'precio_ars');
        $mediana = Estadistica::mediana($precios);
        $diferencia = round(($precio - $mediana) / $mediana * 100, 1);
        $etiqueta = match (true) {
            $diferencia <= -self::UMBRAL => self::BUEN_PRECIO,
            $diferencia >= self::UMBRAL  => self::CARO,
            default                      => self::EN_PRECIO,
        };

        return $base + [
            'etiqueta'       => $etiqueta,
            'texto'          => self::texto($diferencia, count($elegidos)),
            'diferencia_pct' => $diferencia,
            'ahorro'         => round($mediana - $precio),
            'comparables'    => count($elegidos),
            'descartados'    => $descartados,
            'mercado'        => [
                'mediana' => round($mediana),
                'p25'     => round(Estadistica::percentil($precios, 0.25)),
                'p75'     => round(Estadistica::percentil($precios, 0.75)),
                'minimo'  => min($precios),
                'maximo'  => max($precios),
            ],
            'km'             => ['este' => $km, 'mediana_mercado' => round(Estadistica::mediana(array_column($elegidos, 'km')))],
            'anio'           => ['este' => $anio, 'mediana_mercado' => (int)round(Estadistica::mediana(array_column($elegidos, 'anio')))],
            'por_fuente'     => self::porFuente($elegidos),
            'parecidos'      => self::masParecidos($elegidos, $anio, $km),
        ];
    }

    public static function enPesos(?float $precio, string $moneda, ?float $dolar): ?float
    {
        if ($precio === null || $precio <= 0) {
            return null;
        }
        if ($moneda === 'USD') {
            return $dolar ? round($precio * $dolar) : null;
        }
        return $precio;
    }

    private static function kmParecidos(int $a, int $b): bool
    {
        return abs($a - $b) <= max($b * self::TOLERANCIA_KM, self::TOLERANCIA_KM_MIN);
    }

    /**
     * Saca los precios fuera de [p25 − 1,5·IQR, p75 + 1,5·IQR]. Con pocos autos no se saca nada: no hay con qué decidir.
     *
     * @param list<array<string, mixed>> $autos
     * @return array{list<array<string, mixed>>, int}
     */
    public static function sinExtremos(array $autos): array
    {
        if (count($autos) < 4) {
            return [$autos, 0];
        }
        $precios = array_column($autos, 'precio_ars');
        $p25 = Estadistica::percentil($precios, 0.25);
        $p75 = Estadistica::percentil($precios, 0.75);
        $iqr = $p75 - $p25;
        $quedan = array_values(array_filter($autos, static fn(array $a): bool =>
            $a['precio_ars'] >= $p25 - 1.5 * $iqr && $a['precio_ars'] <= $p75 + 1.5 * $iqr));
        return [$quedan, count($autos) - count($quedan)];
    }

    private static function texto(float $diferencia, int $cantidad): string
    {
        $relacion = match (true) {
            $diferencia < 0 => abs($diferencia) . ' % menos que',
            $diferencia > 0 => $diferencia . ' % más que',
            default         => 'Igual que',
        };
        return "$relacion el precio típico de $cantidad autos parecidos en otros sitios.";
    }

    /** @return list<array{fuente: string, cantidad: int, mediana: float}> */
    private static function porFuente(array $autos): array
    {
        $grupos = [];
        foreach ($autos as $a) {
            $grupos[$a['fuente']][] = $a['precio_ars'];
        }
        ksort($grupos);
        $salida = [];
        foreach ($grupos as $fuente => $precios) {
            $salida[] = ['fuente' => (string)$fuente, 'cantidad' => count($precios), 'mediana' => round(Estadistica::mediana($precios))];
        }
        return $salida;
    }

    /** Los más cercanos en año y km, para que el usuario pueda ir a verlos ("no nos creas, mirá"). */
    private static function masParecidos(array $autos, int $anio, int $km): array
    {
        usort($autos, static fn(array $x, array $y): int =>
            [abs($x['anio'] - $anio), abs($x['km'] - $km)] <=> [abs($y['anio'] - $anio), abs($y['km'] - $km)]);
        return array_map(static fn(array $a): array => [
            'fuente'     => $a['fuente'],
            'version'    => $a['version'],
            'anio'       => $a['anio'],
            'km'         => $a['km'],
            'precio'     => $a['precio'],
            'moneda'     => $a['moneda'],
            'precio_ars' => $a['precio_ars'],
            'url'        => $a['url'],
        ], array_slice($autos, 0, self::PARECIDOS));
    }
}
