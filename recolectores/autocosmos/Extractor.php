<?php
declare(strict_types=1);

namespace AutoCar\Recolectores\Autocosmos;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Lee el listado de autocosmos.com.ar/auto/usado. Cada aviso es un <article itemtype="schema.org/Car"> con
 * microdatos (brand, model, modelDate, mileageFromOdometer, price, priceCurrency): son estándar y cambian poco.
 */
final class Extractor
{
    /** @return list<array<string, mixed>> avisos en el formato común */
    public static function avisos(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        $avisos = [];
        foreach ($xp->query('//article[contains(@itemtype, "schema.org/Car")]') ?: [] as $art) {
            /** @var DOMElement $art */
            $texto = static function (string $clase) use ($xp, $art): ?string {
                $n = $xp->query('.//*[contains(concat(" ", @class, " "), " ' . $clase . ' ")]', $art)?->item(0);
                $t = $n === null ? '' : trim(preg_replace('/\s+/u', ' ', $n->textContent) ?? '');
                return $t === '' ? null : $t;
            };
            $prop = static function (string $nombre, string $atributo = 'content') use ($xp, $art): ?string {
                $n = $xp->query('.//*[@itemprop="' . $nombre . '"]', $art)?->item(0);
                return $n instanceof DOMElement && $n->hasAttribute($atributo) ? trim($n->getAttribute($atributo)) : null;
            };

            $href = $prop('url', 'href') ?? '';
            $id = basename($href);
            $marca = $texto('listing-card__brand');
            $modelo = $texto('listing-card__model');
            $anio = (int)($texto('listing-card__year') ?? 0);
            $km = preg_replace('/\D/', '', $prop('mileageFromOdometer') ?? '');
            $precio = $prop('price');
            if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1 || $marca === null || $modelo === null || $anio < 1950 || $km === '') {
                continue;
            }
            $ciudad = rtrim((string)$texto('listing-card__city'), ' |');
            $provincia = $texto('listing-card__province');
            $avisos[$id] = [
                'id_externo'     => $id,
                'marca'          => self::marca($marca),
                'modelo'         => $modelo,
                'version'        => $texto('listing-card__version'),
                'anio'           => $anio,
                'km'             => (int)$km,
                'transmision'    => null,
                'combustible'    => null,
                'precio'         => is_numeric($precio) && (float)$precio > 0 ? (float)$precio : null,
                'precio_contado' => null,
                'moneda'         => $prop('priceCurrency') === 'USD' ? 'USD' : 'ARS',
                'reservado'      => false,
                'ubicacion'      => trim($ciudad . ($provincia !== null ? " - $provincia" : ''), ' -') ?: null,
                'url'            => 'https://www.autocosmos.com.ar' . $href,
                'foto'           => $prop('image'),
            ];
        }
        return array_values($avisos);
    }

    /** Autocosmos escribe algunas marcas en mayúsculas ("FIAT", "BMW"): se dejan como en MercadoLibre. */
    private static function marca(string $marca): string
    {
        return mb_strlen($marca) > 3 && mb_strtoupper($marca) === $marca ? mb_convert_case($marca, MB_CASE_TITLE) : $marca;
    }
}
