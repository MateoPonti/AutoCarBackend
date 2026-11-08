<?php
/**
 * Recolector de autos usados de Autocosmos (curl). Genera storage/mercado/autocosmos/{fecha}.json.
 * Uso: php recolectores/autocosmos/recolectar.php [paginas]   (sin límite: hasta que una página no traiga autos nuevos)
 */
declare(strict_types=1);

use AutoCar\Recolectores\Autocosmos\Extractor;
use AutoCar\Recolectores\Comun\Http;
use AutoCar\Recolectores\Comun\Salida;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/autoload.php';

$limite = isset($argv[1]) ? max(1, (int)$argv[1]) : 2000;
$http = new Http(3.0);
$autos = [];
$errores = [];
$fin = false;

for ($p = 1; $p <= $limite; $p++) {
    try {
        $html = $http->get('https://www.autocosmos.com.ar/auto/usado' . ($p > 1 ? "?pidx=$p" : ''));
    } catch (Throwable $e) {
        $errores[] = $e->getMessage();
        fwrite(STDERR, "Falló la página $p: " . $e->getMessage() . "\n");
        break;
    }
    $antes = count($autos);
    foreach (Extractor::avisos($html) as $a) {
        $autos[$a['id_externo']] = $a;
    }
    if (count($autos) === $antes) {
        $fin = true; // pasada la última página, el sitio repite la última o viene vacía
        break;
    }
    if ($p % 10 === 0) {
        fwrite(STDERR, "Página $p: " . count($autos) . " autos\n");
    }
}

if ($autos === []) {
    exit(1);
}
$completo = $fin && $errores === [];
$archivo = Salida::guardar('autocosmos', $completo, $autos, ['pedidos' => $http->pedidos, 'errores' => $errores]);
fwrite(STDERR, count($autos) . " autos en $archivo" . ($completo ? '' : ' (corrida incompleta)') . "\n");
exit($errores === [] ? 0 : 1);
