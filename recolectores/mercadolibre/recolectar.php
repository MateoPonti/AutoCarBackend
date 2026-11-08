<?php
/**
 * Recolector de autos usados de MercadoLibre (curl, sin navegador). Genera storage/mercado/mercadolibre/{fecha}.json.
 *
 * Uso:
 *   php recolectores/mercadolibre/recolectar.php                         todas las marcas (~2 h 15 min)
 *   php recolectores/mercadolibre/recolectar.php --marcas=volkswagen,fiat solo esas marcas (corrida incompleta)
 *   php recolectores/mercadolibre/recolectar.php --max-paginas=2          tope por búsqueda, para probar (incompleta)
 *   --pausa=3   segundos entre pedidos (por defecto 3)
 *
 * Cómo recorre: cada búsqueda de MercadoLibre muestra como máximo 42 páginas (~2.016 autos), así que va
 * marca → modelo, y si un modelo tiene más, modelo → año. La marca y el modelo salen del filtro, no del título.
 */
declare(strict_types=1);

use AutoCar\Recolectores\Comun\Http;
use AutoCar\Recolectores\Comun\Salida;
use AutoCar\Recolectores\MercadoLibre\Extractor;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/autoload.php';

$opciones = getopt('', ['marcas:', 'max-paginas:', 'pausa:', 'salida:']);
$soloMarcas = isset($opciones['marcas']) ? array_map('trim', explode(',', mb_strtolower((string)$opciones['marcas']))) : null;
$maxPaginas = isset($opciones['max-paginas']) ? max(1, (int)$opciones['max-paginas']) : PHP_INT_MAX;
$http = new Http(isset($opciones['pausa']) ? max(1.0, (float)$opciones['pausa']) : 3.0);

const USADOS = 'https://autos.mercadolibre.com.ar/_ITEM*CONDITION_2230581';

$autos = [];
$completo = $soloMarcas === null && $maxPaginas === PHP_INT_MAX;
$errores = [];
$inicio = time();

$log = static fn(string $m) => fwrite(STDERR, '[' . date('H:i:s') . "] $m\n");

/** Recorre una búsqueda ya filtrada por marca y modelo. Devuelve false si no la pudo recorrer entera. */
$recorrer = static function (string $url, string $marca, string $modelo, ?string $primera = null) use ($http, &$autos, $maxPaginas, $log): bool {
    $html = $primera ?? $http->get($url);
    $total = Extractor::total($html) ?? 0;
    $paginas = min((int)ceil($total / Extractor::POR_PAGINA), 42);
    $entera = $total <= Extractor::MAXIMO_POR_BUSQUEDA;
    for ($n = 0; $n < $paginas; $n++) {
        if ($n >= $maxPaginas) {
            return false;
        }
        if ($n > 0) {
            $html = $http->get(Extractor::pagina($url, $n));
        }
        $nuevos = Extractor::avisos($html, $marca, $modelo);
        foreach ($nuevos as $a) {
            $autos[$a['id_externo']] = $a;
        }
        if ($nuevos === []) {
            break; // página vacía: el listado se achicó mientras se recorría
        }
    }
    $log("  $marca $modelo: $total autos" . ($entera ? '' : ' (pasa el tope de 42 páginas)'));
    return $entera;
};

try {
    $marcas = Extractor::filtro($http->get(USADOS), 'BRAND');
    if ($marcas === []) {
        throw new RuntimeException('No se encontró el filtro de marcas: puede que MercadoLibre haya cambiado la página.');
    }
    $log(count($marcas) . ' marcas');

    foreach ($marcas as $marca) {
        if ($soloMarcas !== null && !in_array(mb_strtolower($marca['nombre']), $soloMarcas, true)) {
            continue;
        }
        $log("{$marca['nombre']} ({$marca['cantidad']})");
        $modelos = Extractor::filtro($http->get($marca['url']), 'MODEL');
        foreach ($modelos as $modelo) {
            $primera = $http->get($modelo['url']);
            $total = Extractor::total($primera) ?? 0;
            if ($total <= Extractor::MAXIMO_POR_BUSQUEDA) {
                $completo = $recorrer($modelo['url'], $marca['nombre'], $modelo['nombre'], $primera) && $completo;
                continue;
            }
            // Modelo grande: se divide por año
            foreach (Extractor::filtro($primera, 'VEHICLE_YEAR') as $anio) {
                $completo = $recorrer($anio['url'], $marca['nombre'], $modelo['nombre']) && $completo;
            }
        }
    }
} catch (Throwable $e) {
    // Sin autos no hay nada que guardar; con autos, se guarda lo juntado como corrida incompleta
    $log('Error: ' . $e->getMessage());
    $errores[] = $e->getMessage();
    $completo = false;
    if ($autos === []) {
        exit(1);
    }
}

$archivo = Salida::guardar('mercadolibre', $completo, $autos, [
    'pedidos'  => $http->pedidos,
    'segundos' => time() - $inicio,
    'errores'  => $errores,
], $opciones['salida'] ?? null);
$log(count($autos) . " autos en $archivo" . ($completo ? '' : ' (corrida incompleta)') . ", {$http->pedidos} pedidos");
exit($errores === [] ? 0 : 1);
