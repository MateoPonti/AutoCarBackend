<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

/**
 * La patente del auto inspeccionado: prueba que el informe es de ese auto (no se arma un historial con ella desde el
 * 23/11). La carga el mecánico al ver el auto, con una foto de la chapa como control,
 * y sin ella no se puede firmar. Se guarda solo la patente y el informe: nada del dueño.
 * Formatos argentinos: la vieja (ABC123, desde 1995) y la del Mercosur (AB123CD, desde 2016).
 */
final class Patente
{
    private const VIEJA    = '/^[A-Z]{3}\d{3}$/';
    private const MERCOSUR = '/^[A-Z]{2}\d{3}[A-Z]{2}$/';

    /**
     * La patente sin espacios ni guiones y en mayúsculas ("ab 123 cd" → "AB123CD"), o null si no tiene un formato
     * argentino. Función pura.
     */
    public static function normalizar(mixed $texto): ?string
    {
        if (!is_string($texto)) {
            return null;
        }
        $limpia = strtoupper(str_replace([' ', '-', '.'], '', trim($texto)));
        if (preg_match(self::VIEJA, $limpia) || preg_match(self::MERCOSUR, $limpia)) {
            return $limpia;
        }
        return null;
    }
}
