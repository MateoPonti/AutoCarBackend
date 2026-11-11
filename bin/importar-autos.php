<?php
/**
 * Importa corridas de los recolectores a la base de documentos del metabuscador (MERCADO_ALMACEN=archivos|firestore).
 * Uso: php bin/importar-autos.php archivo.json [archivo.json ...]
 *      php bin/importar-autos.php --hoy      (la corrida de hoy de cada fuente en storage/mercado/{fuente}/AAAA-MM-DD.json)
 *      php bin/importar-autos.php --revisar-marcas archivo.json [...]   (no importa nada: ver más abajo)
 * Los autos con una marca que no está en el catálogo (MARCAS_URL o la tabla marca) no se cargan y se avisa en la salida.
 * --revisar-marcas muestra solo esos autos (marca como la escribió el sitio, modelo y link) para ver si es una marca
 * nueva (se agrega a la tabla marca con una migración y entran en la carga siguiente) o un error del que publicó.
 * Cada corrida anota en recolecciones/{fuente} las marcas que no se cargaron y los modelos que no están en el catálogo
 * (esos sí se cargan), con cuántos autos: es la lista para decidir qué agregar a Neon.
 * Código de salida: 0 bien; 1 alguna no se pudo importar, o se importó con advertencias.
 */
declare(strict_types=1);

use AutoCar\Catalogo\MarcaModelo;
use AutoCar\Core\Config;
use AutoCar\MetaBusqueda\Almacenamiento;
use AutoCar\MetaBusqueda\Importador;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';
Config::load($raiz . '/.env');

$archivos = array_slice($argv, 1);
$revisar = ($archivos[0] ?? null) === '--revisar-marcas';
if ($revisar) {
    $archivos = array_slice($archivos, 1);
}
if ($archivos === ['--hoy']) {
    $archivos = glob($raiz . '/storage/mercado/*/' . date('Y-m-d') . '.json') ?: [];
}
if ($archivos === []) {
    fwrite(STDERR, "Uso: php bin/importar-autos.php [--revisar-marcas] archivo.json [...] | --hoy\n");
    exit(1);
}

// El catálogo (las mismas marcas y modelos que se pueden publicar). Sin lista, se importa todo sin apartar ninguna.
$catalogo = leerCatalogo();
if ($revisar && $catalogo === null) {
    exit(1);
}
$marcas = $catalogo === null ? null : array_column($catalogo, 'marca');
$importador = new Importador(Almacenamiento::documentos(), $marcas, $catalogo);
$salida = 0;
foreach ($archivos as $archivo) {
    $crudo = is_file($archivo) ? file_get_contents($archivo) : false;
    $datos = $crudo !== false ? json_decode($crudo, true) : null;
    if (!is_array($datos)) {
        fwrite(STDERR, "No se pudo leer el JSON de \"$archivo\".\n");
        $salida = 1;
        continue;
    }
    if ($revisar) {
        revisarMarcas($importador, $datos);
        continue;
    }
    try {
        $r = $importador->importar($datos);
    } catch (Throwable $e) {
        fwrite(STDERR, "No se importó $archivo: " . $e->getMessage() . "\n");
        $salida = 1;
        continue;
    }
    echo "{$r['fuente']} {$r['fecha']}: {$r['autos']} autos, " . ($r['completo'] ? 'completa' : 'INCOMPLETA')
        . ", {$r['modelos_escritos']} modelos escritos, {$r['retirados']} retirados, {$r['avisos']} avisos, {$r['apartados']} apartados, {$r['modelos_faltantes']} con modelo fuera del catálogo.\n";
    foreach ($r['advertencias'] as $a) {
        fwrite(STDERR, "Advertencia: $a\n");
        $salida = 1;
    }
}
exit($salida);

/**
 * Las marcas y modelos del catálogo. Con MARCAS_URL, de la API del back de ese entorno (GET /api/marcas-modelos,
 * pública): así la PC del scraper usa el catálogo de dev o de prod sin conectarse a Neon. Sin MARCAS_URL, de la base del .env.
 * @return list<array{marca: string, modelos: list<string>}>|null null si no se pudo leer
 */
function leerCatalogo(): ?array
{
    $url = Config::get('MARCAS_URL');
    try {
        if ($url === null || $url === '') {
            return MarcaModelo::lista();
        }
        $ch = curl_init($url);
        $opciones = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120]; // Render dormido tarda en despertar
        $ca = Config::get('CURL_CAINFO');
        if ($ca !== null && is_file($ca)) {
            $opciones[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opciones);
        $r = json_decode((string)curl_exec($ch), true);
        if (!is_array($r['marcas'] ?? null) || $r['marcas'] === []) {
            throw new RuntimeException("$url no devolvió la lista de marcas");
        }
        return $r['marcas'];
    } catch (Throwable $e) {
        fwrite(STDERR, 'Advertencia: no se pudo leer la lista de marcas (' . $e->getMessage()
            . "): se importa sin apartar las marcas desconocidas.\n");
        return null;
    }
}

/** Lista los autos de la corrida con una marca que no está en el catálogo, agrupados por cómo la escribió el sitio. */
function revisarMarcas(Importador $importador, array $datos): void
{
    $porMarca = [];
    foreach ($datos['autos'] ?? [] as $auto) {
        $a = is_array($auto) ? Importador::validarAuto($auto) : null;
        if ($a !== null && !$importador->marcaConocida($a['marca'])) {
            $porMarca[$a['marca']][] = $a;
        }
    }
    $fuente = $datos['fuente'] ?? '?';
    if ($porMarca === []) {
        echo "$fuente: todas las marcas están en el catálogo.\n";
        return;
    }
    foreach ($porMarca as $marca => $autos) {
        echo "$fuente · \"$marca\": " . count($autos) . " autos\n";
        foreach ($autos as $a) {
            echo "    {$a['modelo']} {$a['anio']} · " . ($a['url'] ?? 'sin link') . "\n";
        }
    }
}
