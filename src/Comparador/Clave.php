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
        'd s'              => 'ds', // "D.S."
    ];

    /** Marcas que no se escriben con mayúscula inicial y nada más; mismos nombres que la tabla marca de Neon. */
    private const NOMBRES = [
        'baic'          => 'BAIC',
        'bmw'           => 'BMW',
        'byd'           => 'BYD',
        'citroen'       => 'Citroën',
        'dfsk'          => 'DFSK',
        'ds'            => 'DS',
        'gac'           => 'GAC',
        'gmc'           => 'GMC',
        'gwm'           => 'GWM',
        'ika'           => 'IKA',
        'ime'           => 'IME',
        'jac'           => 'JAC',
        'mercedes benz' => 'Mercedes-Benz',
        'mg'            => 'MG',
        'mini'          => 'MINI',
        'ram'           => 'RAM',
        'saab'          => 'SAAB',
        'ssangyong'     => 'SsangYong',
        'swm'           => 'SWM',
    ];

    public static function de(string $marca, string $modelo): string
    {
        $m = self::marca($marca);
        $modelo = self::limpiar($modelo);
        // Algunos sitios repiten la marca en el modelo ("Volkswagen Gol Trend")
        if (str_starts_with($modelo, $m . ' ')) {
            $modelo = substr($modelo, strlen($m) + 1);
        }
        // ...o pegada a un número ("DS3" es el DS 3, "RAM1500" la RAM 1500). Solo con número: "Rampage" no es "page".
        if (preg_match('/^' . preg_quote($m, '/') . '(\d.*)$/', $modelo, $pegado)) {
            $modelo = $pegado[1];
        }
        return str_replace(' ', '_', "$m $modelo");
    }

    /**
     * Cómo se muestra una marca, la escriba como la escriba cada sitio:
     * "Ds", "D.S." y "DS" → "DS"; "Citroen" → "Citroën"; "KIA" → "Kia"; "Mercedes Benz" → "Mercedes-Benz".
     */
    public static function nombreMarca(string $marca): string
    {
        $m = self::marca($marca);
        return self::NOMBRES[$m] ?? ucwords($m);
    }

    /** Clave de una marca, con los apodos resueltos: "MB", "Mercedes" y "Mercedes-Benz" → "mercedes benz". */
    public static function marca(string $marca): string
    {
        $m = self::limpiar($marca);
        return self::MARCAS[$m] ?? $m;
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
