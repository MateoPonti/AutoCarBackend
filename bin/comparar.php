<?php
/**
 * Prueba el comparador desde la consola, con el almacén configurado.
 *   php bin/comparar.php Volkswagen "Gol Trend" 2018 110000 15500000   un auto cualquiera
 *   php bin/comparar.php --publicaciones                              todas las publicaciones disponibles (Neon)
 */
declare(strict_types=1);

use AutoCar\Comparador\Almacenes;
use AutoCar\Comparador\Clave;
use AutoCar\Comparador\Comparador;
use AutoCar\Core\Config;
use AutoCar\Core\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';
Config::load($raiz . '/.env');
$almacen = Almacenes::configurado();

if (($argv[1] ?? '') === '--publicaciones') {
    $vehiculos = Database::pdo()->query(
        "SELECT id, marca, modelo, anio, kilometraje, precio FROM vehiculo WHERE estado = 'disponible' ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
} elseif (count($argv) === 6) {
    $vehiculos = [['id' => '-', 'marca' => $argv[1], 'modelo' => $argv[2], 'anio' => $argv[3], 'kilometraje' => $argv[4], 'precio' => $argv[5]]];
} else {
    fwrite(STDERR, "Uso: php bin/comparar.php MARCA MODELO AÑO KM PRECIO  |  php bin/comparar.php --publicaciones\n");
    exit(1);
}

foreach ($vehiculos as $v) {
    $r = Comparador::comparar([
        'marca' => (string)$v['marca'], 'modelo' => (string)$v['modelo'], 'anio' => (int)$v['anio'],
        'kilometraje' => (int)$v['kilometraje'], 'precio' => (float)$v['precio'],
    ], $almacen->modelo(Clave::de((string)$v['marca'], (string)$v['modelo'])));
    printf("#%s %s %s %d, %s km, $ %s → %s (%d comparables)\n   %s\n",
        $v['id'], $v['marca'], $v['modelo'], $v['anio'], number_format((int)$v['kilometraje'], 0, ',', '.'),
        number_format((float)$v['precio'], 0, ',', '.'), $r['etiqueta'], $r['comparables'], $r['texto']);
    if (isset($r['mercado'])) {
        printf("   Mediana $ %s (p25 %s · p75 %s) · %s\n", number_format($r['mercado']['mediana'], 0, ',', '.'),
            number_format($r['mercado']['p25'], 0, ',', '.'), number_format($r['mercado']['p75'], 0, ',', '.'),
            implode(' · ', array_map(static fn(array $f): string => "{$f['fuente']} {$f['cantidad']}", $r['por_fuente'])));
    }
}
