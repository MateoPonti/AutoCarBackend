<?php
declare(strict_types=1);

namespace AutoCar\Comparador;

/**
 * Arma un documento por modelo ("volkswagen_gol_trend") con todos los autos de todas las fuentes del día.
 * Es lo único que lee la API para comparar: una lectura por publicación, aunque la publicación sea nueva.
 *
 * Cada auto va como lista corta para que un modelo con miles de avisos entre en un documento de Firestore (1 MiB):
 *   [fuente, id_externo, anio, km, precio, moneda, version, url]
 * La url de MercadoLibre no se guarda: se arma con el id.
 */
final class Modelos
{
    /**
     * @param list<array<string, mixed>> $corridas salidas de los recolectores (formato común), una por fuente
     * @return array<string, array{clave: string, marca: string, modelo: string, fecha: string, dolar: ?float, fuentes: list<string>, autos: list<list<mixed>>, huella: string}>
     */
    public static function armar(array $corridas): array
    {
        $dolar = null;
        $fecha = '';
        foreach ($corridas as $c) {
            $dolar ??= isset($c['dolar']['venta']) ? (float)$c['dolar']['venta'] : null;
            $fecha = max($fecha, substr((string)($c['fecha'] ?? ''), 0, 10));
        }

        $modelos = [];
        foreach ($corridas as $c) {
            $fuente = (string)$c['fuente'];
            foreach ($c['autos'] ?? [] as $a) {
                // Kavak: si la tarjeta informa el precio de contado se usa ese; el otro es financiado
                $precio = $a['precio_contado'] ?? $a['precio'] ?? null;
                if ($precio === null || !empty($a['reservado']) || !isset($a['marca'], $a['modelo'], $a['anio'], $a['km'])) {
                    continue;
                }
                $clave = Clave::de((string)$a['marca'], (string)$a['modelo']);
                $modelos[$clave] ??= [
                    'clave' => $clave, 'marca' => Clave::nombreMarca((string)$a['marca']), 'modelo' => (string)$a['modelo'],
                    'fecha' => $fecha, 'dolar' => $dolar, 'fuentes' => [], 'autos' => [],
                ];
                $modelos[$clave]['fuentes'][$fuente] = $fuente;
                $modelos[$clave]['autos'][] = [
                    $fuente,
                    (string)$a['id_externo'],
                    (int)$a['anio'],
                    (int)$a['km'],
                    (float)$precio,
                    ($a['moneda'] ?? 'ARS') === 'USD' ? 'USD' : 'ARS',
                    $a['version'] ?? null,
                    $fuente === 'mercadolibre' ? null : ($a['url'] ?? null),
                ];
            }
        }

        foreach ($modelos as &$m) {
            $m['fuentes'] = array_values($m['fuentes']);
            usort($m['autos'], static fn(array $x, array $y): int => [$x[0], $x[1]] <=> [$y[0], $y[1]]);
            // La huella no incluye la fecha ni el dólar: si los autos no cambiaron, no hace falta reescribir el documento
            $m['huella'] = md5(json_encode($m['autos']));
        }
        ksort($modelos);
        return $modelos;
    }

    /** @param list<mixed> $t @return array{fuente: string, id: string, anio: int, km: int, precio: float, moneda: string, version: ?string, url: ?string} */
    public static function desplegar(array $t): array
    {
        [$fuente, $id, $anio, $km, $precio, $moneda, $version, $url] = $t + [null, null, 0, 0, null, 'ARS', null, null];
        if ($url === null && $fuente === 'mercadolibre' && preg_match('/^MLA(\d+)$/', (string)$id, $m)) {
            $url = "https://auto.mercadolibre.com.ar/MLA-{$m[1]}-_JM";
        }
        return [
            'fuente' => (string)$fuente, 'id' => (string)$id, 'anio' => (int)$anio, 'km' => (int)$km,
            'precio' => $precio === null ? null : (float)$precio, 'moneda' => (string)$moneda,
            'version' => $version, 'url' => $url,
        ];
    }
}
