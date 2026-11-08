<?php
/**
 * Importa corridas de los recolectores a la base de documentos del metabuscador (MERCADO_ALMACEN=archivos|firestore).
 * Uso: php bin/importar-autos.php archivo.json [archivo.json ...]
 *      php bin/importar-autos.php --hoy      (la corrida de hoy de cada fuente en storage/mercado/{fuente}/AAAA-MM-DD.json)
 * Código de salida: 0 bien; 1 alguna no se pudo importar, o se importó con advertencias.
 */
declare(strict_types=1);

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
if ($archivos === ['--hoy']) {
    $archivos = glob($raiz . '/storage/mercado/*/' . date('Y-m-d') . '.json') ?: [];
}
if ($archivos === []) {
    fwrite(STDERR, "Uso: php bin/importar-autos.php archivo.json [...] | --hoy\n");
    exit(1);
}

$importador = new Importador(Almacenamiento::documentos());
$salida = 0;
foreach ($archivos as $archivo) {
    $crudo = is_file($archivo) ? file_get_contents($archivo) : false;
    $datos = $crudo !== false ? json_decode($crudo, true) : null;
    if (!is_array($datos)) {
        fwrite(STDERR, "No se pudo leer el JSON de \"$archivo\".\n");
        $salida = 1;
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
        . ", {$r['modelos_escritos']} modelos escritos, {$r['retirados']} retirados, {$r['avisos']} avisos.\n";
    foreach ($r['advertencias'] as $a) {
        fwrite(STDERR, "Advertencia: $a\n");
        $salida = 1;
    }
}
exit($salida);
