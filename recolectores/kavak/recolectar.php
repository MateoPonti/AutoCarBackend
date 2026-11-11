<?php
/**
 * Recolector de Kavak con curl, sin navegador (el HTML ya trae las tarjetas). Guarda en
 * storage/mercado/kavak/{fecha}.json.
 * Uso: php recolectores/kavak/recolectar.php [paginas]   (sin límite: todas, ~15 minutos por la pausa de 20 s)
 */
declare(strict_types=1);

use AutoCar\Recolectores\Comun\Http;
use AutoCar\Recolectores\Comun\Salida;
use AutoCar\Recolectores\Kavak\ExtractorHtml;
use AutoCar\Recolectores\Kavak\Normalizador;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/autoload.php';

$limite = isset($argv[1]) ? max(1, (int)$argv[1]) : PHP_INT_MAX;
$http = new Http(20.0); // robots.txt de Kavak: Crawl-delay: 20

$autos = [];
$paginas = 1;
$rechazos = 0;
$errores = [];

for ($p = 0; $p < min($paginas, $limite); $p++) {
    try {
        $html = $http->get("https://www.kavak.com/ar/usados?page=$p");
    } catch (Throwable $e) {
        $errores[] = $e->getMessage();
        fwrite(STDERR, 'Falló la página ' . ($p + 1) . ': ' . $e->getMessage() . "\n");
        break;
    }
    if ($p === 0) {
        $paginas = ExtractorHtml::ultimaPagina($html) + 1;
        fwrite(STDERR, "El listado tiene $paginas páginas\n");
    }
    $tarjetas = ExtractorHtml::tarjetas($html);
    if ($tarjetas === []) {
        $errores[] = 'Página ' . ($p + 1) . ' sin tarjetas: puede que Kavak haya cambiado el HTML.';
        break;
    }
    foreach ($tarjetas as $t) {
        $auto = Normalizador::auto($t);
        if ($auto === null) {
            $rechazos++;
            continue;
        }
        $autos[$auto['id_externo']] = $auto;
    }
    fwrite(STDERR, 'Página ' . ($p + 1) . ': ' . count($autos) . " autos\n");
}

if ($autos === []) {
    exit(1);
}
// Una tarjeta rechazada no invalida la corrida: los reservados, por ejemplo, vienen sin precio pero se aceptan
$completo = $errores === [] && $limite >= $paginas;
$archivo = Salida::guardar('kavak', $completo, $autos, ['pedidos' => $http->pedidos, 'rechazadas' => $rechazos, 'errores' => $errores]);
fwrite(STDERR, count($autos) . " autos en $archivo" . ($completo ? '' : ' (corrida incompleta)') . "\n");
exit($errores === [] ? 0 : 1);
