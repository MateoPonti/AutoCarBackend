<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

use AutoCar\Comparador\Firestore;
use AutoCar\Core\Config;

/**
 * Dónde viven los documentos del metabuscador, según MERCADO_ALMACEN:
 *   firestore → Firestore (misma credencial que el comparador, FIRESTORE_CREDENCIALES[_JSON])
 *   archivos  → JSON en MERCADO_CARPETA (por defecto storage/mercado/docs). Es el valor por defecto.
 */
final class Almacenamiento
{
    private static ?Documentos $documentos = null;

    public static function documentos(): Documentos
    {
        return self::$documentos ??= Config::get('MERCADO_ALMACEN', 'archivos') === 'firestore'
            ? Firestore::desdeConfig()
            : new DocumentosArchivos(Config::get('MERCADO_CARPETA') ?: dirname(__DIR__, 2) . '/storage/mercado/docs');
    }
}
