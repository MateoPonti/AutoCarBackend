<?php
declare(strict_types=1);

namespace AutoCar\Recolectores\MercadoLibre;

use AutoCar\Recolectores\Comun\JsonEmbebido;

/**
 * Lee una página de resultados de autos.mercadolibre.com.ar sin navegador ni red (se prueba con HTML guardado).
 *
 * Cada aviso viene como un objeto {"id":"POLYCARD",...} dentro del estado de la página, repetido varias veces.
 * La tarjeta trae título, precio y moneda, año, km y localidad. No trae transmisión ni combustible.
 * La marca y el modelo no se sacan del título: vienen del filtro con el que se buscó, que es exacto.
 */
final class Extractor
{
    /** MercadoLibre corta cada búsqueda en 42 páginas: con más resultados hay que dividirla (por modelo, después por año). */
    public const MAXIMO_POR_BUSQUEDA = 2016;
    public const POR_PAGINA = 48;

    /**
     * Avisos de la página en el formato común (ver recolectores/README.md), sin repetir.
     *
     * @return list<array<string, mixed>>
     */
    public static function avisos(string $html, string $marca, string $modelo): array
    {
        $avisos = [];
        foreach (JsonEmbebido::todos($html, '{"id":"POLYCARD"') as $tarjeta) {
            $aviso = self::aviso($tarjeta['polycard'] ?? [], $marca, $modelo);
            if ($aviso !== null) {
                $avisos[$aviso['id_externo']] = $aviso;
            }
        }
        return array_values($avisos);
    }

    /** @param array<string, mixed> $p */
    public static function aviso(array $p, string $marca, string $modelo): ?array
    {
        $meta = $p['metadata'] ?? [];
        $id = is_string($meta['id'] ?? null) ? $meta['id'] : '';
        $componentes = array_column($p['components'] ?? [], null, 'type');
        $titulo = trim((string)($componentes['title']['title']['text'] ?? ''));
        $precio = $componentes['price']['price']['current_price'] ?? [];
        $datos = $componentes['attributes_list']['attributes_list']['texts'] ?? [];

        $anio = null;
        $km = null;
        foreach ($datos as $d) {
            if (preg_match('/^(19[5-9]\d|20\d\d)$/', (string)$d)) {
                $anio = (int)$d;
            } elseif (preg_match('/^([\d.]+)\s*km$/i', (string)$d, $m)) {
                $km = (int)str_replace('.', '', $m[1]);
            }
        }
        $moneda = ($precio['currency'] ?? '') === 'USD' ? 'USD' : 'ARS';
        if (preg_match('/^MLA\d+$/', $id) !== 1 || $anio === null || $km === null || $titulo === '') {
            return null;
        }

        $foto = $p['pictures']['pictures'][0]['id'] ?? null;
        return [
            'id_externo'     => $id,
            'marca'          => $marca,
            'modelo'         => $modelo,
            'version'        => self::version($titulo, $marca, $modelo, $anio),
            'anio'           => $anio,
            'km'             => $km,
            'transmision'    => null,
            'combustible'    => null,
            'precio'         => is_numeric($precio['value'] ?? null) ? (float)$precio['value'] : null,
            'precio_contado' => null,
            'moneda'         => $moneda,
            'reservado'      => false,
            'ubicacion'      => isset($componentes['location']['location']['text']) ? (string)$componentes['location']['location']['text'] : null,
            'url'            => is_string($meta['url'] ?? null) ? 'https://' . $meta['url'] : null,
            'foto'           => is_string($foto) ? "https://http2.mlstatic.com/D_NQ_NP_$foto-O.webp" : null,
            'titulo'         => $titulo,
        ];
    }

    /** "Volkswagen Gol Trend 2018 1.6 Comfortline 101cv" → "1.6 Comfortline 101cv". */
    private static function version(string $titulo, string $marca, string $modelo, int $anio): ?string
    {
        $resto = $titulo;
        foreach ([$marca, $modelo, (string)$anio] as $quitar) {
            $resto = preg_replace('/(^|\s)' . preg_quote($quitar, '/') . '(?=\s|$)/iu', ' ', $resto) ?? $resto;
        }
        $resto = trim(preg_replace('/\s+/', ' ', $resto) ?? '');
        return $resto === '' ? null : $resto;
    }

    /** Cantidad total de resultados de la búsqueda, o null si la página no la informa. */
    public static function total(string $html): ?int
    {
        return preg_match('/"offset":\d+,"total":(\d+)/', $html, $m) ? (int)$m[1] : null;
    }

    /**
     * Valores de un filtro de la columna izquierda: 'BRAND', 'MODEL' o 'VEHICLE_YEAR'.
     * Las URL ya incluyen los filtros aplicados en la página (por ejemplo, "usado").
     *
     * @return list<array{nombre: string, url: string, cantidad: int}>
     */
    public static function filtro(string $html, string $id): array
    {
        $salida = [];
        $desde = 0;
        // El filtro aparece dos veces: una con los ids sueltos y otra con nombre, URL y cantidad. Se busca la segunda.
        while (($pos = strpos($html, '"id":"' . $id . '","name":', $desde)) !== false) {
            $desde = $pos + 1;
            $valores = JsonEmbebido::listaDespuesDe($html, '"values":', $pos);
            if ($valores === null || !is_array($valores[0] ?? null) || !isset($valores[0]['url'])) {
                continue;
            }
            foreach ($valores as $v) {
                $salida[] = [
                    'nombre'   => (string)$v['name'],
                    'url'      => strtok((string)$v['url'], '#'),
                    'cantidad' => (int)preg_replace('/\D/', '', (string)($v['results'] ?? '0')),
                ];
            }
            return $salida;
        }
        return [];
    }

    /** URL de la página n (desde 0). MercadoLibre pagina con "_Desde_{primer aviso}". */
    public static function pagina(string $url, int $n): string
    {
        if ($n === 0) {
            return $url;
        }
        $desde = '_Desde_' . ($n * self::POR_PAGINA + 1);
        // Los filtros con "_" (ej. _ITEM*CONDITION_2230581) van al final: la paginación se pone antes
        if (preg_match('#^(.*/)(_[^/]*)$#', $url, $m)) {
            return $m[1] . $desde . $m[2] . '_NoIndex_True';
        }
        return rtrim($url, '/') . '/' . $desde . '_NoIndex_True';
    }
}
