<?php
/**
 * Junta la última corrida de cada fuente (storage/mercado/{fuente}/*.json), arma un documento por modelo y guarda
 * solo los que cambiaron en el almacén configurado (COMPARADOR_ALMACEN=archivos|firestore).
 * Uso: php bin/armar-modelos.php [--almacen=archivos|firestore]
 */
declare(strict_types=1);

use AutoCar\Comparador\AlmacenArchivos;
use AutoCar\Comparador\AlmacenFirestore;
use AutoCar\Comparador\Almacenes;
use AutoCar\Comparador\Firestore;
use AutoCar\Comparador\Modelos;
use AutoCar\Core\Config;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';
Config::load($raiz . '/.env');

$opciones = getopt('', ['almacen:']);
$almacen = match ($opciones['almacen'] ?? null) {
    'archivos'  => new AlmacenArchivos($raiz . '/storage/mercado/modelos'),
    'firestore' => new AlmacenFirestore(Firestore::desdeConfig()),
    default     => Almacenes::configurado(),
};

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
        echo "{$c['fuente']}: " . count($c['autos']) . ' autos (' . basename(end($archivos)) . ($c['completo'] ? '' : ', incompleta') . ")\n";
    }
}
if ($corridas === []) {
    fwrite(STDERR, "No hay corridas en storage/mercado: primero corré los recolectores.\n");
    exit(1);
}

$modelos = Modelos::armar($corridas);
$mayor = 0;
foreach ($modelos as $m) {
    $mayor = max($mayor, strlen(json_encode($m['autos'])));
}
$escritos = $almacen->guardarModelos($modelos);
echo count($modelos) . " modelos, $escritos escritos (los demás no cambiaron). Documento más grande: "
    . round($mayor / 1024) . " KiB (Firestore admite hasta 1024).\n";
