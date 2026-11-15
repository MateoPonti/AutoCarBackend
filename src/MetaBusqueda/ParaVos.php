<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

/**
 * "Para vos": un puntaje de conveniencia de cada auto del metabuscador según el perfil del comprador
 * ("¿Para qué buscás el auto?", ver Auth\Preferencias::USOS). Son las mismas cosas para todos, pero cada perfil
 * les da distinto peso, como en la tabla del doc de ideas del 12/11 (sin el revendedor).
 *
 * Solo se usa lo que el scraper trae de verdad: precio contra autos parecidos, kilómetros contra esos mismos
 * autos, días publicado (margen para negociar) y año. Fallas típicas, repuestos y zona no están en los datos.
 * El puntaje solo cambia el orden y el motivo que se muestra, nunca un precio. Función pura (se prueba sin base).
 */
final class ParaVos
{
    /** Peso de cada cosa por perfil: 3 = alto, 2 = medio, 1 = bajo, 0 = no cuenta */
    public const PESOS = [
        'personal'   => ['precio' => 3, 'km' => 2, 'dias' => 2, 'anio' => 0], // presupuesto justo
        'familia'    => ['precio' => 2, 'km' => 3, 'dias' => 1, 'anio' => 1], // que no se rompa
        'trabajo'    => ['precio' => 3, 'km' => 1, 'dias' => 1, 'anio' => 0], // lo usa mucho: importa el precio
        'aplicacion' => ['precio' => 2, 'km' => 2, 'dias' => 1, 'anio' => 3], // las apps piden autos nuevos
    ];

    // Cuánto tiene que valer cada cosa para sumar el máximo (1): 20 % más barato, 50 % menos km,
    // 60 días publicado, 5 años más nuevo que el típico de la búsqueda
    private const PRECIO_TOPE = 20.0;
    private const KM_TOPE = 0.5;
    private const DIAS_TOPE = 60;
    private const ANIOS_TOPE = 5;

    /**
     * @param array<string, mixed> $auto
     * @param array<string, mixed>|null $comparacion la de Comparables::elegir (null si no tiene precio)
     * @param array<string, mixed>|null $etiqueta la de Comparables::etiqueta
     * @param float $anioTipico mediana de los años de los resultados
     * @return array{puntaje: float, motivo: string}|null null si el perfil no existe o no hay con qué comparar
     */
    public static function puntuar(array $auto, ?array $comparacion, ?array $etiqueta, string $uso, float $anioTipico): ?array
    {
        $pesos = self::PESOS[$uso] ?? null;
        if ($pesos === null || $comparacion === null || !$comparacion['suficientes']) {
            return null;
        }

        // Cada factor va de -1 (en contra) a 1 (a favor)
        $diferencia = (float)($etiqueta['diferencia_pct'] ?? 0.0);
        $medianaKm = max((float)$comparacion['mediana_km'], 1.0);
        $dias = (int)floor((strtotime((string)$auto['ultima_vez']) - strtotime((string)$auto['primera_vez'])) / 86400);
        $factores = [
            'precio' => self::limitar(-$diferencia / self::PRECIO_TOPE),
            'km'     => self::limitar(($medianaKm - $auto['km']) / $medianaKm / self::KM_TOPE),
            'dias'   => self::limitar(max($dias, 0) / self::DIAS_TOPE),
            'anio'   => self::limitar(($auto['anio'] - $anioTipico) / self::ANIOS_TOPE),
        ];

        $suma = 0.0;
        $aportes = [];
        foreach ($factores as $clave => $valor) {
            $suma += $pesos[$clave] * $valor;
            $aportes[$clave] = $pesos[$clave] * $valor;
        }
        $puntaje = round($suma / array_sum($pesos), 2);

        return ['puntaje' => $puntaje, 'motivo' => self::motivo($aportes, $diferencia, $auto, $medianaKm, $dias)];
    }

    private static function limitar(float $valor): float
    {
        return max(-1.0, min(1.0, $valor));
    }

    /** "Te conviene: …" con las dos cosas que más suman, u "Ojo: …" si lo que más pesa está en contra */
    private static function motivo(array $aportes, float $diferencia, array $auto, float $medianaKm, int $dias): string
    {
        $km = (int)round(abs($medianaKm - $auto['km']) / $medianaKm * 100);
        $pct = str_replace('.', ',', (string)abs($diferencia)); // 11,3 %
        $textos = [
            'precio' => $diferencia < 0 ? "$pct % más barato que autos parecidos" : "$pct % más caro que autos parecidos",
            'km'     => $auto['km'] <= $medianaKm ? "$km % menos kilómetros que los parecidos" : "$km % más kilómetros que los parecidos",
            'dias'   => "lleva $dias días publicado: hay margen para negociar",
            'anio'   => $aportes['anio'] >= 0 ? "es de los más nuevos de la búsqueda ({$auto['anio']})" : "es más viejo que la mayoría ({$auto['anio']})",
        ];

        arsort($aportes);
        $favor = array_keys(array_filter($aportes, static fn(float $a): bool => $a > 0.05));
        if ($favor !== []) {
            $partes = array_map(static fn(string $c): string => $textos[$c], array_slice($favor, 0, 2));
            return 'Te conviene: ' . implode(' y ', $partes) . '.';
        }
        $contra = array_key_last($aportes);
        if ($contra !== null && $aportes[$contra] < -0.05) {
            return 'Ojo: ' . $textos[$contra] . '.';
        }
        return 'Está en el promedio de autos parecidos.';
    }
}
