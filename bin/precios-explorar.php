<?php
/**
 * El precio contra el mercado de los autos de Explorar (las publicaciones de AutoCar), para su ranking. Lo corre el
 * workflow del scraper después de armar los modelos del comparador, sin conectarse a Neon:
 *   1. lee los autos de Explorar de la API pública del back de este entorno (GET /api/vehiculos/mercado; la dirección
 *      sale de MARCAS_URL, la misma que se usa para el catálogo de marcas);
 *   2. arma los modelos con la última corrida de cada fuente (storage/mercado/{fuente}/*.json, como armar-modelos.php);
 *   3. deja el cálculo en un documento (PrecioMercado::DOCUMENTO) de la base de documentos (MERCADO_ALMACEN). El back lo
 *      copia a Neon la primera vez que alguien abre Explorar después.
 * Uso: php bin/precios-explorar.php            Código de salida: 0 bien (o sin MARCAS_URL: no hace nada); 1 si falló.
 */
declare(strict_types=1);

use AutoCar\Catalogo\PrecioMercado;
use AutoCar\Comparador\Modelos;
use AutoCar\Core\Config;
use AutoCar\MetaBusqueda\Almacenamiento;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';
Config::load($raiz . '/.env');

$marcas = (string)Config::get('MARCAS_URL', '');
if ($marcas === '') {
    echo "Sin MARCAS_URL: no se sabe qué back consultar, no se calcula nada.\n";
    exit(0);
}
$base = preg_replace('#/api/.*$#', '', rtrim($marcas, '/'));

// 1. Los autos de Explorar (el back gratuito puede estar dormido: hasta 3 intentos con espera larga)
$vehiculos = null;
for ($intento = 1; $intento <= 3 && $vehiculos === null; $intento++) {
    $crudo = @file_get_contents("$base/api/vehiculos/mercado", false, stream_context_create(['http' => ['timeout' => 120]]));
    $datos = $crudo !== false ? json_decode($crudo, true) : null;
    $vehiculos = is_array($datos['vehiculos'] ?? null) ? $datos['vehiculos'] : null;
    if ($vehiculos === null && $intento < 3) {
        sleep(20);
    }
}
if ($vehiculos === null) {
    fwrite(STDERR, "No se pudieron leer los autos de Explorar de $base.\n");
    exit(1);
}

// 2. Los modelos con la última corrida de cada fuente
$corridas = [];
foreach (glob($raiz . '/storage/mercado/*', GLOB_ONLYDIR) ?: [] as $carpeta) {
    $archivos = glob("$carpeta/????-??-??.json") ?: [];
    if ($archivos === []) {
        continue;
    }
    sort($archivos);
    $c = json_decode((string)file_get_contents(end($archivos)), true);
    if (is_array($c) && isset($c['fuente'], $c['autos'])) {
        $corridas[] = $c;
    }
}
if ($corridas === []) {
    fwrite(STDERR, "No hay corridas en storage/mercado: primero corré los recolectores.\n");
    exit(1);
}

// 3. El cálculo, en un solo documento
$autos = PrecioMercado::calcularConModelos($vehiculos, Modelos::armar($corridas));
Almacenamiento::documentos()->escribir([PrecioMercado::DOCUMENTO => ['fecha' => date(DATE_ATOM), 'autos' => $autos]]);
$conDatos = count(array_filter($autos, static fn(array $a): bool => $a['suficientes']));
echo count($vehiculos) . " autos en Explorar: $conDatos con al menos 5 parecidos en otros sitios, " . (count($autos) - $conDatos) . " sin datos suficientes.\n";
