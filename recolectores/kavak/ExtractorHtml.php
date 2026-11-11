<?php
declare(strict_types=1);

namespace AutoCar\Recolectores\Kavak;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Lee las tarjetas del HTML que devuelve Kavak sin navegador (desde noviembre de 2026 vienen en el HTML).
 * Arma el arreglo de cada tarjeta que espera Normalizador (el mismo que armaba el scraper con Playwright, ya borrado).
 */
final class ExtractorHtml
{
    /** @return list<array<string, mixed>> */
    public static function tarjetas(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        $salida = [];
        foreach ($xp->query('//a[starts-with(@data-testid, "card-product-")]') ?: [] as $card) {
            /** @var DOMElement $card */
            $texto = static function (string $consulta) use ($xp, $card): ?string {
                $n = $xp->query($consulta, $card)?->item(0);
                return $n === null ? null : trim($n->textContent);
            };
            $seccion = $xp->query('.//*[contains(@class, "priceSection")]', $card)?->item(0);
            $chico = $seccion === null ? null : $xp->query('.//*[contains(@class, "cardProduct__price__")]', $seccion)?->item(0);
            $etiqueta = null;
            if ($seccion !== null) {
                $etiqueta = '';
                foreach ($seccion->childNodes as $hijo) {
                    if ($hijo->nodeType === XML_TEXT_NODE) {
                        $etiqueta .= $hijo->textContent;
                    }
                }
            }
            $etiquetas = [];
            foreach ($xp->query('.//*[contains(@class, "badge_content")]', $card) ?: [] as $b) {
                $etiquetas[] = trim($b->textContent);
            }
            $salida[] = [
                'id'              => substr($card->getAttribute('data-testid'), strlen('card-product-')),
                'titulo'          => $texto('.//h3'),
                'subtitulo'       => $texto('.//p'),
                'precio_grande'   => $texto('.//*[contains(@class, "amount__large__price")]'),
                'precio_chico'    => $chico === null ? null : trim($chico->textContent),
                'etiqueta_precio' => $etiqueta,
                'etiquetas'       => $etiquetas,
                'ubicacion'       => $texto('.//*[contains(@class, "footerInfo")]'),
                'url'             => $card->getAttribute('href'),
            ];
        }
        return $salida;
    }

    /** Última página del listado (se cuenta desde 0), leída de los links de la paginación. */
    public static function ultimaPagina(string $html): int
    {
        preg_match_all('/[?&]page=(\d+)/', $html, $m);
        return $m[1] === [] ? 0 : max(array_map('intval', $m[1]));
    }
}
