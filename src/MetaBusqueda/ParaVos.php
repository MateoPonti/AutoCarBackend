<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

use AutoCar\Parametros\Parametros;

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
    /**
     * Peso de cada cosa por perfil: 3 = alto, 2 = medio, 1 = bajo, 0 = no cuenta. Son los valores de fábrica:
     * los que se usan salen de los parámetros peso_<perfil>_<criterio> (el admin los cambia, ver pesosActuales).
     */
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
     * Los pesos que configuró el admin en Parámetros (migración 028). Si todavía no están en la base, los de fábrica.
     * @return array<string, array<string, float>>
     */
    public static function pesosActuales(): array
    {
        try {
            $pesos = [];
            foreach (self::PESOS as $uso => $criterios) {
                foreach (array_keys($criterios) as $criterio) {
                    $pesos[$uso][$criterio] = Parametros::valor("peso_{$uso}_{$criterio}");
                }
            }
            return $pesos;
        } catch (\RuntimeException) {
            return self::PESOS;
        }
    }

    /**
     * @param array<string, mixed> $auto
     * @param array<string, mixed>|null $comparacion la de Comparables::elegir (null si no tiene precio)
     * @param array<string, mixed>|null $etiqueta la de Comparables::etiqueta
     * @param float $anioTipico mediana de los años de los resultados
     * @param array<string, array<string, float>>|null $pesosPorPerfil los de pesosActuales (sin indicar, los de fábrica)
     * @return array{puntaje: float, motivo: string}|null null si el perfil no existe, no hay con qué comparar o
     *         el admin puso todos los pesos de ese perfil en 0
     */
    public static function puntuar(array $auto, ?array $comparacion, ?array $etiqueta, string $uso, float $anioTipico,
        ?array $pesosPorPerfil = null): ?array
    {
        $pesos = ($pesosPorPerfil ?? self::PESOS)[$uso] ?? null;
        if ($pesos === null || array_sum($pesos) <= 0 || $comparacion === null || !$comparacion['suficientes']) {
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

        return [
            'puntaje' => $puntaje,
            'motivo'  => self::motivo($aportes, $diferencia, $auto, $medianaKm, $dias),
            'mensaje' => self::mensaje($uso, $pesos, $aportes, $diferencia, $auto, $medianaKm, $dias),
        ];
    }

    /** Cómo se nombra cada perfil en el mensaje: "Como lo buscás …" */
    private const COMO_LO_BUSCAS = [
        'personal'   => 'para vos',
        'familia'    => 'para la familia',
        'trabajo'    => 'para trabajar',
        'aplicacion' => 'para manejar con aplicaciones',
    ];

    /** Qué prioriza la fórmula cuando esa cosa es la que más pesa en el perfil */
    private const PRIORIZAMOS = [
        'precio' => 'priorizamos el precio',
        'km'     => 'priorizamos pocos kilómetros',
        'dias'   => 'buscamos margen para negociar',
        'anio'   => 'priorizamos autos nuevos',
    ];

    /**
     * El mensaje de los primeros de "Más recomendados": nombra el perfil y lo que más sumó para ESE auto (peso × factor).
     * Si eso es lo que más pesa en el perfil, lo dice ("priorizamos pocos kilómetros"); si no, solo que le conviene.
     * Sale todo de datos del auto y de lo que el usuario contestó: no se inventa nada. null si nada suma a favor.
     * Ej.: "Como lo buscás para la familia, priorizamos pocos kilómetros: tiene 32 % menos km que autos parecidos,
     * y además está 8 % más barato."
     */
    private static function mensaje(string $uso, array $pesos, array $aportes, float $diferencia, array $auto,
        float $medianaKm, int $dias): ?string
    {
        arsort($aportes);
        $favor = array_keys(array_filter($aportes, static fn(float $a): bool => $a > 0.05));
        if ($favor === []) {
            return null;
        }
        $km = (int)round(abs($medianaKm - $auto['km']) / $medianaKm * 100);
        $pct = str_replace('.', ',', (string)abs($diferencia));
        // La primera razón, completa; la segunda, corta
        $largo = [
            'precio' => "está $pct % más barato que autos parecidos",
            'km'     => "tiene $km % menos km que autos parecidos",
            'dias'   => "lleva $dias días publicado, así que hay margen para negociar",
            'anio'   => "es un {$auto['anio']}, de los más nuevos de tu búsqueda",
        ];
        $corto = [
            'precio' => "está $pct % más barato",
            'km'     => "tiene $km % menos km",
            'dias'   => "lleva $dias días publicado",
            'anio'   => "es un {$auto['anio']}",
        ];

        $primera = $favor[0];
        $foco = $pesos[$primera] >= max($pesos) ? self::PRIORIZAMOS[$primera] : 'este te conviene';
        $texto = 'Como lo buscás ' . (self::COMO_LO_BUSCAS[$uso] ?? 'así') . ", $foco: " . $largo[$primera];
        if (isset($favor[1])) {
            $texto .= ', y además ' . $corto[$favor[1]];
        }
        return $texto . '.';
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
