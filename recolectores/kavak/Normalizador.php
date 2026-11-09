<?php
declare(strict_types=1);

namespace AutoCar\Recolectores\Kavak;

/**
 * Convierte lo que se lee de una tarjeta de Kavak al formato común de los recolectores (ver README).
 * Sin dependencias, para poder probarlo sin navegador ni red.
 *
 * Kavak muestra hasta dos precios y su significado depende del texto que los acompaña:
 *   "Precio desde", solo el grande:  el grande es financiado; el de contado no aparece en la tarjeta.
 *   "Precio desde", grande y chico:  el grande es financiado y el chico es el de contado.
 *   "Precio de contado":             el grande es el de contado; el chico, el anterior a la oferta.
 * "precio" es siempre el grande, porque es el único que está en todas las tarjetas con precio.
 */
final class Normalizador
{
    /** @param array<string, mixed> $tarjeta */
    public static function auto(array $tarjeta): ?array
    {
        $partes = array_map('trim', explode('•', (string)($tarjeta['titulo'] ?? ''), 2));
        $datos = array_map('trim', explode('•', (string)($tarjeta['subtitulo'] ?? '')));
        $anio = (int)($datos[0] ?? 0);
        $km = self::numero($datos[1] ?? null);
        $id = trim((string)($tarjeta['id'] ?? ''));

        if ($id === '' || count($partes) !== 2 || $partes[0] === '' || $partes[1] === '' || $anio < 1950 || $anio > 2100 || $km === null) {
            return null;
        }

        $grande = self::numero($tarjeta['precio_grande'] ?? null);
        $chico = self::numero($tarjeta['precio_chico'] ?? null);
        $etiqueta = mb_strtolower(trim((string)($tarjeta['etiqueta_precio'] ?? '')));

        return [
            'id_externo'     => $id,
            'marca'          => $partes[0],
            'modelo'         => $partes[1],
            'version'        => ($datos[2] ?? '') !== '' ? $datos[2] : null,
            'anio'           => $anio,
            'km'             => $km,
            'transmision'    => self::transmision($datos[3] ?? ''),
            'precio'         => $grande,
            'precio_contado' => str_contains($etiqueta, 'contado') ? $grande : $chico,
            'moneda'         => 'ARS',
            'reservado'      => in_array('Reservado', (array)($tarjeta['etiquetas'] ?? []), true),
            'ubicacion'      => $tarjeta['ubicacion'] ?? null,
            'url'            => $tarjeta['url'] ?? null,
        ];
    }

    /** "$ 15.020.000" → 15020000; "88.000 km" → 88000; sin dígitos → null. */
    private static function numero(mixed $texto): ?int
    {
        $digitos = preg_replace('/\D/', '', (string)$texto);
        return $digitos === '' ? null : (int)$digitos;
    }

    private static function transmision(string $texto): ?string
    {
        return match (mb_strtolower(trim($texto))) {
            'manual' => 'manual',
            'automático', 'automatico' => 'automatica',
            default => null,
        };
    }
}
