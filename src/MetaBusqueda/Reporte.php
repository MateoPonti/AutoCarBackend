<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

/**
 * Arma el reporte de una búsqueda y el detalle de un auto externo. Las estadísticas van separadas por plataforma.
 * Los autos de AutoCar aparecen en los resultados, pero no reciben etiqueta ni compiten en los destacados.
 * Los pesos y márgenes son un punto de partida para ajustar con datos reales.
 */
final class Reporte
{
    public const POR_PAGINA = 20;
    public const ACLARACION = 'Son precios publicados, no de venta.';
    private const PESO_KM = 0.2;          // en "mejor relación", 10% menos de km equivale a 2% menos de precio
    private const KM_ALTOS = 1.25;        // advertencia si supera en 25% el kilometraje típico
    private const MAX_OTROS_MODELOS = 6;

    public function __construct(private Mercado $mercado)
    {
    }

    /**
     * @param array<string, mixed> $b búsqueda
     * @param array<string, mixed> $get filtros del listado que llegan por la URL
     */
    public function construir(array $b, array $get): array
    {
        $recolecciones = $this->mercado->ultimasRecolecciones();
        $actuales = array_column($recolecciones, 'actual');
        $modelo = $this->mercado->fotosDelModelo($actuales, $b['marca'], $b['modelo']);
        $externos = array_values(array_filter($modelo, static fn(array $a): bool => Busqueda::coincide($b, $a)));
        $portal = $this->mercado->delPortal($b);

        $comparaciones = [];
        foreach ($externos as $a) {
            if ($a['precio'] !== null && !$a['reservado']) {
                $comparaciones[$a['id']] = Comparables::elegir($a, $modelo);
            }
        }

        $items = [];
        foreach ($externos as $a) {
            $c = $comparaciones[$a['id']] ?? null;
            $items[] = self::item($a, $c !== null ? Comparables::etiqueta($a['precio'], $c) : null);
        }
        foreach ($portal as $a) {
            $items[] = self::item($a, null);
        }

        return [
            'busqueda'      => Busqueda::paraApi($b),
            'actualizado'   => array_map(static fn(array $r): array => ['fuente' => $r['fuente'], 'fecha' => $r['fecha']], $recolecciones),
            'aclaracion'    => self::ACLARACION,
            'referencia'    => self::referencia(array_merge($externos, $portal)),
            'novedades'     => $this->novedades($b, $recolecciones),
            'destacados'    => self::destacados($externos, $comparaciones),
            'resultados'    => self::paginar(self::filtrarYOrdenar($items, $get), $get),
            'otros_modelos' => $this->otrosModelos($b, $externos, $actuales),
        ];
    }

    /** Precio típico y rango por plataforma; sin el mínimo de autos no se informan. */
    private static function referencia(array $autos): array
    {
        $grupos = [];
        foreach ($autos as $a) {
            if ($a['precio'] !== null) {
                $grupos[$a['fuente'] . '|' . $a['moneda']][] = $a['precio'];
            }
        }
        ksort($grupos);

        $salida = [];
        foreach ($grupos as $clave => $precios) {
            [$fuente, $moneda] = explode('|', (string)$clave);
            $suficiente = count($precios) >= Comparables::MINIMO;
            $salida[] = [
                'fuente'     => $fuente,
                'moneda'     => $moneda,
                'cantidad'   => count($precios),
                'suficiente' => $suficiente,
                'mediana'    => $suficiente ? Estadistica::mediana($precios) : null,
                'p25'        => $suficiente ? Estadistica::percentil($precios, 0.25) : null,
                'p75'        => $suficiente ? Estadistica::percentil($precios, 0.75) : null,
            ];
        }
        return $salida;
    }

    private function novedades(array $b, array $recolecciones): array
    {
        $totales = [Novedades::NUEVO => 0, Novedades::BAJO => 0, Novedades::SUBIO => 0, Novedades::RESERVADO => 0, Novedades::RETIRADO => 0];
        $items = [];
        $sinCambios = 0;
        $disponible = false;

        foreach ($recolecciones as $r) {
            if ($r['anterior'] === null) {
                continue; // la primera corrida de una plataforma no tiene contra qué compararse
            }
            $disponible = true;
            $resultado = Novedades::comparar(
                $this->mercado->fotosDelModelo([$r['actual']], $b['marca'], $b['modelo']),
                $this->mercado->fotosDelModelo([$r['anterior']], $b['marca'], $b['modelo']),
            );
            foreach ($resultado['cambios'] as $c) {
                if (Busqueda::coincide($b, $c['auto'])) {
                    $totales[$c['tipo']]++;
                    $items[] = [
                        'tipo'            => $c['tipo'],
                        'precio_anterior' => $c['precio_anterior'],
                        'precio_actual'   => $c['precio_actual'],
                        'auto'            => self::item($c['auto'], null),
                    ];
                }
            }
            $sinCambios += count(array_filter($resultado['sin_cambios'], static fn(array $a): bool => Busqueda::coincide($b, $a)));
        }

        return [
            'disponible'  => $disponible,
            'nuevos'      => $totales[Novedades::NUEVO],
            'bajaron'     => $totales[Novedades::BAJO],
            'subieron'    => $totales[Novedades::SUBIO],
            'sin_cambios' => $sinCambios,
            'reservados'  => $totales[Novedades::RESERVADO],
            'retirados'   => $totales[Novedades::RETIRADO],
            'items'       => $items,
        ];
    }

    /**
     * @param list<array<string, mixed>> $externos
     * @param array<int, array<string, mixed>> $comparaciones por id de auto
     */
    public static function destacados(array $externos, array $comparaciones): array
    {
        $conPrecio = array_values(array_filter($externos, static fn(array $a): bool =>
            $a['precio'] !== null && !$a['reservado'] && $a['moneda'] === 'ARS'));
        if ($conPrecio === []) {
            return ['mejor_relacion' => null, 'mas_barato' => null, 'menos_usado' => null];
        }

        $mejor = null;
        $mejorPuntaje = INF;
        foreach ($conPrecio as $a) {
            $c = $comparaciones[$a['id']] ?? null;
            if ($c === null || !$c['suficientes']) {
                continue;
            }
            $puntaje = ($a['precio'] - $c['mediana_precio']) / $c['mediana_precio']
                + self::PESO_KM * ($a['km'] - $c['mediana_km']) / max($c['mediana_km'], 1.0);
            if ($puntaje < $mejorPuntaje) {
                $mejorPuntaje = $puntaje;
                $mejor = $a;
            }
        }

        $barato = $conPrecio[0];
        $usado = $conPrecio[0];
        foreach ($conPrecio as $a) {
            if ($a['precio'] < $barato['precio']) {
                $barato = $a;
            }
            if ($a['km'] < $usado['km']) {
                $usado = $a;
            }
        }

        $kmTipico = (float)Estadistica::mediana(array_column($conPrecio, 'km'));
        $diferencia = $usado['precio'] - $barato['precio'];

        return [
            'mejor_relacion' => $mejor === null ? null : [
                'auto'        => self::item($mejor, Comparables::etiqueta($mejor['precio'], $comparaciones[$mejor['id']])),
                'explicacion' => self::explicarRelacion($mejor, $comparaciones[$mejor['id']]),
            ],
            'mas_barato' => [
                'auto'        => self::item($barato, isset($comparaciones[$barato['id']]) ? Comparables::etiqueta($barato['precio'], $comparaciones[$barato['id']]) : null),
                'explicacion' => $barato['km'] > $kmTipico * self::KM_ALTOS
                    ? 'Es el más barato, pero tiene más kilómetros que la mayoría.'
                    : 'Es el de menor precio entre los resultados.',
            ],
            'menos_usado' => [
                'auto'        => self::item($usado, isset($comparaciones[$usado['id']]) ? Comparables::etiqueta($usado['precio'], $comparaciones[$usado['id']]) : null),
                'explicacion' => $diferencia > 0
                    ? 'Es el de menos kilómetros. Cuesta $' . number_format($diferencia, 0, ',', '.') . ' más que el más barato.'
                    : 'Es el de menos kilómetros y también el más barato.',
            ],
        ];
    }

    private static function explicarRelacion(array $a, array $c): string
    {
        $km = (int)round($c['mediana_km'] - $a['km']);
        $textoKm = match (true) {
            $km > 0 => number_format($km, 0, ',', '.') . ' km menos que el típico',
            $km < 0 => number_format(-$km, 0, ',', '.') . ' km más que el típico',
            default => 'kilómetros iguales al típico',
        };
        return Comparables::etiqueta($a['precio'], $c)['explicacion'] . ' Tiene ' . $textoKm . '.';
    }

    /** Otros modelos con precio y kilómetros parecidos. No afectan estadísticas, etiquetas, destacados ni avisos. */
    private function otrosModelos(array $b, array $externos, array $actuales): array
    {
        $base = array_values(array_filter($externos, static fn(array $a): bool =>
            $a['precio'] !== null && !$a['reservado'] && $a['moneda'] === 'ARS'));
        if ($base === []) {
            return [];
        }
        $precios = array_column($base, 'precio');
        $anios = array_column($base, 'anio');
        $kmTipico = (float)Estadistica::mediana(array_column($base, 'km'));

        $candidatos = $this->mercado->otrosModelos($actuales, [
            'marca'         => $b['marca'],
            'modelo'        => $b['modelo'],
            'precio_tipico' => (float)Estadistica::mediana($precios),
            'precio_min'    => (float)Estadistica::percentil($precios, 0.25) * 0.9,
            'precio_max'    => $b['precio_max'] ?? (float)Estadistica::percentil($precios, 0.75) * 1.1,
            'anio_min'      => ($b['anio_desde'] ?? min($anios)) - 2,
            'anio_max'      => ($b['anio_hasta'] ?? max($anios)) + 2,
            'km_min'        => (int)floor($kmTipico * 0.8),
            'km_max'        => (int)ceil($kmTipico * 1.2),
        ]);

        $vistos = [];
        $salida = [];
        foreach ($candidatos as $a) {
            $clave = mb_strtolower($a['marca'] . '|' . $a['modelo']);
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $salida[] = self::item($a, null) + ['motivo' => 'Precio y kilómetros parecidos a tu búsqueda.'];
            if (count($salida) === self::MAX_OTROS_MODELOS) {
                break;
            }
        }
        return $salida;
    }

    /** Filtros y orden del listado. Lo que no se reconoce en la URL se ignora, como en el catálogo. */
    private static function filtrarYOrdenar(array $items, array $get): array
    {
        $texto = static fn(string $k): string => is_string($get[$k] ?? null) ? trim($get[$k]) : '';
        $numero = static fn(string $k): ?int => ctype_digit($texto($k)) && strlen($texto($k)) <= 12 ? (int)$texto($k) : null;

        $fuente = $texto('fuente');
        $etiqueta = $texto('etiqueta');
        $precioMax = $numero('precio_max');
        $kmMax = $numero('km_max');

        $items = array_values(array_filter($items, static fn(array $i): bool =>
            ($fuente === '' || $i['fuente'] === $fuente)
            && ($etiqueta === '' || ($i['etiqueta']['valor'] ?? null) === $etiqueta)
            && ($precioMax === null || ($i['precio'] !== null && $i['precio'] <= $precioMax))
            && ($kmMax === null || $i['km'] <= $kmMax)));

        $orden = $texto('orden');
        usort($items, static function (array $a, array $b) use ($orden): int {
            $r = match ($orden) {
                'precio' => ($a['precio'] ?? INF) <=> ($b['precio'] ?? INF),
                'km'     => $a['km'] <=> $b['km'],
                'nuevos' => strtotime((string)$b['primera_vez']) <=> strtotime((string)$a['primera_vez']),
                default  => ($a['etiqueta']['diferencia_pct'] ?? INF) <=> ($b['etiqueta']['diferencia_pct'] ?? INF),
            };
            return $r !== 0 ? $r : $a['id'] <=> $b['id'];
        });
        return $items;
    }

    private static function paginar(array $items, array $get): array
    {
        $total = count($items);
        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina = max(1, is_string($get['page'] ?? null) ? (int)$get['page'] : 1);
        return [
            'items'      => array_slice($items, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA),
            'total'      => $total,
            'pagina'     => $pagina,
            'por_pagina' => self::POR_PAGINA,
            'paginas'    => $paginas,
        ];
    }

    /** Detalle de un auto externo; null si no existe. */
    public function detalle(string $id): ?array
    {
        $encontrado = $this->mercado->auto($id);
        if ($encontrado === null) {
            return null;
        }
        $auto = $encontrado[0];

        $etiqueta = null;
        $comparables = [];
        foreach ($this->mercado->ultimasRecolecciones() as $r) {
            if ($r['fuente'] !== $auto['fuente'] || $auto['estado'] === 'retirado' || $auto['precio'] === null || $auto['reservado']) {
                continue;
            }
            $c = Comparables::elegir($auto, $this->mercado->fotosDelModelo([$r['actual']], $auto['marca'], $auto['modelo']));
            $etiqueta = Comparables::etiqueta($auto['precio'], $c);
            $comparables = [
                'cantidad'       => count($c['autos']),
                'suficientes'    => $c['suficientes'],
                'mediana_precio' => $c['mediana_precio'],
                'mediana_km'     => $c['mediana_km'],
                'autos'          => array_map(static fn(array $a): array => self::item($a, null), $c['autos']),
            ];
        }

        $primera = strtotime((string)$auto['primera_vez']);
        $ultima = strtotime((string)$auto['ultima_vez']);
        return [
            'auto'           => self::item($auto, $etiqueta),
            'comparables'    => $comparables === [] ? null : $comparables,
            'historial'      => $this->mercado->historial($id),
            'dias_publicado' => (int)floor(($ultima - $primera) / 86400),
            'aclaracion'     => self::ACLARACION,
        ];
    }

    /** Lo que se expone de un auto. @param array<string, mixed> $a */
    private static function item(array $a, ?array $etiqueta): array
    {
        return [
            'id'             => $a['id'],
            'fuente'         => $a['fuente'],
            'marca'          => $a['marca'],
            'modelo'         => $a['modelo'],
            'version'        => $a['version'],
            'anio'           => $a['anio'],
            'km'             => $a['km'],
            'transmision'    => $a['transmision'],
            'ubicacion'      => $a['ubicacion'],
            'precio'         => $a['precio'],
            'precio_contado' => $a['precio_contado'],
            'moneda'         => $a['moneda'],
            'reservado'      => $a['reservado'],
            'estado'         => $a['estado'],
            'primera_vez'    => $a['primera_vez'],
            'ultima_vez'     => $a['ultima_vez'],
            'url'            => $a['url'],
            'etiqueta'       => $etiqueta,
        ];
    }
}
