<?php
declare(strict_types=1);

namespace AutoCar\Comparador;

use AutoCar\Core\Config;

/** Fábrica según la configuración. */
final class Almacenes
{
    public static function configurado(): Almacen
    {
        return Config::get('COMPARADOR_ALMACEN', 'archivos') === 'firestore'
            ? new AlmacenFirestore(Firestore::desdeConfig())
            : new AlmacenArchivos(dirname(__DIR__, 2) . '/storage/mercado/modelos');
    }
}
