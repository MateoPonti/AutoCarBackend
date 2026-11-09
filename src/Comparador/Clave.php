<?php
declare(strict_types=1);

namespace AutoCar\Comparador;

/**
 * Clave de marca y modelo para cruzar sitios que escriben distinto el mismo auto:
 * "Citroën C4 Cactus", "CITROEN c4-cactus" y "Citroen C4 Cactus" → "citroen_c4_cactus".
 * No se hace coincidencia parcial: "Gol" y "Gol Trend" son autos distintos.
 */
final class Clave
{
    private const MARCAS = [
        'vw'               => 'volkswagen',
        'chevy'            => 'chevrolet',
        'mercedes'         => 'mercedes benz',
        'mercedesbenz'     => 'mercedes benz',
        'mb'               => 'mercedes benz',
        'land rover'       => 'land rover',
        'alfa'             => 'alfa romeo',
        'great wall motor' => 'great wall',
    ];

    public static function de(string $marca, string $modelo): string
    {
        $m = self::limpiar($marca);
        $m = self::MARCAS[$m] ?? $m;
        $modelo = self::limpiar($modelo);
        // Algunos sitios repiten la marca en el modelo ("Volkswagen Gol Trend")
        if (str_starts_with($modelo, $m . ' ')) {
            $modelo = substr($modelo, strlen($m) + 1);
        }
        return str_replace(' ', '_', "$m $modelo");
    }

    /** Minúsculas, sin acentos y con un solo espacio entre palabras. */
    public static function limpiar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ë' => 'e', 'ñ' => 'n', 'ç' => 'c']);
        $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto) ?? '';
        return trim($texto);
    }
}
