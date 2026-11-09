<?php
/**
 * Autoload de los recolectores, que no forman parte de la API ni se despliegan en Render (ver .dockerignore).
 * AutoCar\Recolectores\Comun\Http        → recolectores/comun/Http.php
 * AutoCar\Recolectores\MercadoLibre\Extractor → recolectores/mercadolibre/Extractor.php
 * Las carpetas van en minúscula (en Linux importa), por eso no se usa el PSR-4 de composer.json.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(static function (string $clase): void {
    $prefijo = 'AutoCar\\Recolectores\\';
    if (!str_starts_with($clase, $prefijo)) {
        return;
    }
    $partes = explode('\\', substr($clase, strlen($prefijo)));
    if (count($partes) !== 2) {
        return;
    }
    $archivo = __DIR__ . '/' . strtolower($partes[0]) . '/' . $partes[1] . '.php';
    if (is_file($archivo)) {
        require_once $archivo;
    }
});
