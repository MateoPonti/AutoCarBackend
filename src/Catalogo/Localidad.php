<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

final class Localidad
{
    /** La localidad sin provincia se usa para los avisos bonaerenses. Conserva otras provincias explícitas. */
    public static function nombre(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }
        $partes = [];
        foreach (explode(',', $texto) as $parte) {
            $parte = trim((string)preg_replace('/\s+/u', ' ', $parte));
            if ($parte === '') {
                continue;
            }
            $clave = self::clave($parte);
            if ($partes && preg_match('/^(?:buenos aires|bs\.?\s*(?:as\.?)?|pba)$/', $clave)) {
                continue;
            }
            $partes[$clave] ??= match ($clave) {
                'lujan' => 'Luján',
                'mercedes' => 'Mercedes',
                'caba' => 'CABA',
                default => $parte,
            };
        }
        return implode(', ', $partes);
    }

    public static function clave(string $nombre): string
    {
        return strtr(mb_strtolower($nombre), ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u']);
    }
}
