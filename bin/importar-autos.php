<?php
/**
 * Importa la salida de un recolector a la base configurada en las variables de entorno o en .env.
 * Uso: php bin/importar-autos.php recolectores/kavak/autos.json
 * Código de salida: 0 bien; 1 no se pudo importar, o se importó con advertencias.
 */
declare(strict_types=1);

use AutoCar\Core\Config;
use AutoCar\Core\Database;
use AutoCar\MetaBusqueda\Importador;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';
Config::load($raiz . '/.env');

$archivo = $argv[1] ?? '';
$crudo = is_file($archivo) ? file_get_contents($archivo) : false;
$datos = $crudo !== false ? json_decode($crudo, true) : null;
if (!is_array($datos)) {
    fwrite(STDERR, "No se pudo leer el JSON de \"$archivo\".\n");
    exit(1);
}

try {
    $r = (new Importador(Database::pdo()))->importar($datos);
} catch (Throwable $e) {
    fwrite(STDERR, 'No se importó: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Recolección {$r['recoleccion_id']} de {$r['fuente']}: {$r['autos']} autos, "
    . ($r['completo'] ? 'completa' : 'INCOMPLETA') . ", {$r['retirados']} retirados, {$r['avisos']} avisos.\n";
foreach ($r['advertencias'] as $a) {
    fwrite(STDERR, "Advertencia: $a\n");
}
exit($r['advertencias'] === [] ? 0 : 1);
