<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

use AutoCar\Comparador\Clave;

/**
 * Documento por modelo en la colección "mercado": todos los autos de ese modelo, de todas las fuentes, con su historial.
 * Reemplaza a las tablas auto_externo y auto_foto_semanal de Neon: buscar "Gol Trend" es leer un documento.
 *
 * Cada auto guarda:
 *   - lo fijo (versión, año, transmisión, ubicación, url),
 *   - primera_vez / ultima_vez: la primera y la última corrida en la que apareció,
 *   - primera_completa: la primera corrida COMPLETA en la que apareció (para saber si es "nuevo"),
 *   - en: las dos últimas corridas completas en las que apareció (para comparar contra la anterior),
 *   - historial: [fecha, precio, precio_contado, moneda, km, reservado], un renglón solo cuando algo cambió.
 *
 * Firestore no admite listas dentro de listas y un documento pesa como mucho 1 MiB: los autos van como texto JSON
 * en "autos_json" y, si un modelo no entra, se reparte en "{clave}~1", "{clave}~2"...
 */
final class Catalogo
{
    private const MAXIMO_POR_DOCUMENTO = 700000; // bytes de autos_json, con margen sobre el 1 MiB de Firestore
    private const DIAS_RETIRADO = 120;          // un auto retirado hace más que esto se borra del documento

    /** @return array{clave: string, marca: string, modelo: string, autos: array<string, array<string, mixed>>}|null */
    public static function leer(Documentos $docs, string $clave): ?array
    {
        if (preg_match('/^[a-z0-9_]+$/', $clave) !== 1) {
            return null;
        }
        $doc = $docs->leer("mercado/$clave");
        if ($doc === null) {
            return null;
        }
        $lista = json_decode((string)($doc['autos_json'] ?? '[]'), true) ?: [];
        for ($i = 1; $i < (int)($doc['partes'] ?? 1); $i++) {
            $parte = $docs->leer("mercado/$clave~$i");
            $lista = array_merge($lista, json_decode((string)($parte['autos_json'] ?? '[]'), true) ?: []);
        }
        $autos = [];
        foreach ($lista as $a) {
            $autos[self::idAuto($a['fuente'], $a['id_externo'])] = $a;
        }
        return ['clave' => $clave, 'marca' => (string)$doc['marca'], 'modelo' => (string)$doc['modelo'], 'autos' => $autos];
    }

    /** @return array<string, array<string, mixed>> ruta → campos, listo para Documentos::escribir */
    public static function documentos(array $modelo): array
    {
        $partes = [[]];
        $tamanio = 0;
        foreach (array_values($modelo['autos']) as $a) {
            $largo = strlen(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) + 1;
            if ($tamanio + $largo > self::MAXIMO_POR_DOCUMENTO && $partes[array_key_last($partes)] !== []) {
                $partes[] = [];
                $tamanio = 0;
            }
            $partes[array_key_last($partes)][] = $a;
            $tamanio += $largo;
        }
        $json = static fn(array $autos): string => json_encode($autos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $salida = ["mercado/{$modelo['clave']}" => [
            'clave' => $modelo['clave'], 'marca' => $modelo['marca'], 'modelo' => $modelo['modelo'],
            'partes' => count($partes), 'actualizado' => date(DATE_ATOM), 'autos_json' => $json($partes[0]),
        ]];
        for ($i = 1; $i < count($partes); $i++) {
            $salida["mercado/{$modelo['clave']}~$i"] = ['autos_json' => $json($partes[$i])];
        }
        return $salida;
    }

    /**
     * Aplica un auto de una corrida (ya validado por el Importador). Si es nuevo, lo agrega.
     *
     * @param array<string, mixed> $a
     */
    public static function registrar(array &$modelo, string $fuente, array $a, string $fecha, bool $completo): void
    {
        $id = self::idAuto($fuente, $a['id_externo']);
        $x = $modelo['autos'][$id] ?? [
            'fuente' => $fuente, 'id_externo' => $a['id_externo'], 'primera_vez' => $fecha,
            'primera_completa' => null, 'en' => [], 'historial' => [],
        ];
        // La url de MercadoLibre se arma con el id: no hace falta guardarla (ver fila)
        $url = $fuente === 'mercadolibre' && self::urlMercadoLibre($a['id_externo']) !== null ? null : $a['url'];
        $x = array_merge($x, [
            'version' => $a['version'], 'anio' => $a['anio'], 'transmision' => $a['transmision'],
            'ubicacion' => $a['ubicacion'], 'url' => $url, 'ultima_vez' => $fecha,
            'estado' => $a['reservado'] ? 'reservado' : 'activo',
        ]);

        $foto = [$fecha, $a['precio'], $a['precio_contado'], $a['moneda'], $a['km'], $a['reservado']];
        $ultima = $x['historial'] === [] ? null : $x['historial'][array_key_last($x['historial'])];
        if ($ultima === null || array_slice($ultima, 1) != array_slice($foto, 1)) {
            $x['historial'][] = $foto;
        }
        if ($completo) {
            $x['primera_completa'] ??= $fecha;
            $x['en'] = array_slice(array_values(array_unique([$fecha, ...$x['en']])), 0, 2);
        }
        $modelo['autos'][$id] = $x;
    }

    /**
     * Después de una corrida completa: los autos de esa fuente que no aparecieron pasan a "retirado".
     * Los retirados hace mucho se borran para que el documento no crezca sin fin.
     * $protegidos: id_externo de los que vinieron con datos inválidos (siguen publicados: no se retiran).
     *
     * @param array<string, true> $protegidos
     * @return int cuántos se retiraron
     */
    public static function retirarAusentes(array &$modelo, string $fuente, string $fecha, array $protegidos = []): int
    {
        $retirados = 0;
        $limite = strtotime($fecha) - self::DIAS_RETIRADO * 86400;
        foreach ($modelo['autos'] as $id => $a) {
            if ($a['fuente'] !== $fuente || $a['ultima_vez'] === $fecha || isset($protegidos[(string)$a['id_externo']])) {
                continue;
            }
            if ($a['estado'] !== 'retirado') {
                $modelo['autos'][$id]['estado'] = 'retirado';
                $retirados++;
            } elseif (strtotime($a['ultima_vez']) < $limite) {
                unset($modelo['autos'][$id]);
            }
        }
        return $retirados;
    }

    /**
     * Los autos de una fuente tal como estaban en una corrida completa (la que dice "en"), con las mismas claves que
     * usaban las filas de Neon: lo que necesitan Comparables, Novedades y Reporte.
     *
     * @return list<array<string, mixed>>
     */
    public static function fotos(array $modelo, string $fuente, string $fecha): array
    {
        $salida = [];
        foreach ($modelo['autos'] as $a) {
            if ($a['fuente'] === $fuente && in_array($fecha, $a['en'], true)) {
                $salida[] = self::fila($modelo, $a, $fecha);
            }
        }
        return $salida;
    }

    /** Un auto con su precio en una fecha (el último cambio hasta ese momento). Sin fecha, el estado actual. */
    public static function fila(array $modelo, array $a, ?string $fecha = null): array
    {
        $foto = null;
        foreach ($a['historial'] as $h) {
            if ($fecha !== null && strtotime($h[0]) > strtotime($fecha)) {
                break;
            }
            $foto = $h;
        }
        [, $precio, $contado, $moneda, $km, $reservado] = $foto ?? [null, null, null, 'ARS', 0, false];
        return [
            'id'             => self::idPublico($a['fuente'], $modelo['clave'], $a['id_externo']),
            'fuente'         => $a['fuente'],
            'marca'          => $modelo['marca'],
            'modelo'         => $modelo['modelo'],
            'version'        => $a['version'],
            'anio'           => (int)$a['anio'],
            'transmision'    => $a['transmision'],
            'ubicacion'      => $a['ubicacion'],
            'url'            => $a['url'] ?? ($a['fuente'] === 'mercadolibre' ? self::urlMercadoLibre($a['id_externo']) : null),
            'primera_vez'    => $a['primera_vez'],
            'ultima_vez'     => $a['ultima_vez'],
            'estado'         => $a['estado'],
            'precio'         => $precio !== null ? (float)$precio : null,
            'precio_contado' => $contado !== null ? (float)$contado : null,
            'moneda'         => $moneda,
            'km'             => (int)$km,
            'reservado'      => (bool)$reservado,
            'es_nuevo'       => $fecha !== null && $a['primera_completa'] === $fecha,
        ];
    }

    /**
     * Lo que va al índice para elegir modelos sin leerlos todos: nombre, autos activos por fuente y rangos de los
     * que tienen precio en pesos. null si al modelo no le queda ningún auto activo.
     */
    public static function resumen(array $modelo): ?array
    {
        $fuentes = [];
        $pesos = [];
        foreach ($modelo['autos'] as $a) {
            if ($a['estado'] === 'retirado') {
                continue;
            }
            $fuentes[$a['fuente']] = ($fuentes[$a['fuente']] ?? 0) + 1;
            $f = self::fila($modelo, $a);
            if ($f['precio'] !== null && !$f['reservado'] && $f['moneda'] === 'ARS') {
                $pesos[] = $f;
            }
        }
        if ($fuentes === []) {
            return null;
        }
        ksort($fuentes);
        $rango = static fn(string $campo): array => $pesos === [] ? [null, null] : [min(array_column($pesos, $campo)), max(array_column($pesos, $campo))];
        return [
            'marca'   => $modelo['marca'],
            'modelo'  => $modelo['modelo'],
            'fuentes' => $fuentes,
            'precio'  => $rango('precio'),
            'anio'    => $rango('anio'),
            'km'      => $rango('km'),
            'mediana' => Estadistica::mediana(array_column($pesos, 'precio')),
        ];
    }

    /** Clave del auto dentro del documento. */
    public static function idAuto(string $fuente, string $idExterno): string
    {
        return "$fuente~$idExterno";
    }

    /** Id que ve la API: fuente, modelo e id en la plataforma. Alcanza para encontrar el auto con una lectura. */
    public static function idPublico(string $fuente, string $clave, string $idExterno): string
    {
        return "$fuente~$clave~$idExterno";
    }

    /** @return array{0: string, 1: string, 2: string}|null fuente, clave e id externo */
    public static function partirIdPublico(string $id): ?array
    {
        $partes = explode('~', $id, 3);
        if (count($partes) !== 3 || preg_match('/^[a-z0-9_-]{1,30}$/', $partes[0]) !== 1
            || preg_match('/^[a-z0-9_]+$/', $partes[1]) !== 1 || $partes[2] === '') {
            return null;
        }
        return [$partes[0], $partes[1], $partes[2]];
    }

    public static function claveDe(string $marca, string $modelo): string
    {
        return Clave::de($marca, $modelo);
    }

    private static function urlMercadoLibre(string $id): ?string
    {
        return preg_match('/^MLA(\d+)$/', $id, $m) === 1 ? "https://auto.mercadolibre.com.ar/MLA-{$m[1]}-_JM" : null;
    }
}
