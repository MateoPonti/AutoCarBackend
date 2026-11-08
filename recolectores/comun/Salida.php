<?php
declare(strict_types=1);

namespace AutoCar\Recolectores\Comun;

use Throwable;

/**
 * Guarda la corrida de un recolector en el formato común (ver recolectores/README.md):
 * storage/mercado/{fuente}/{AAAA-MM-DD}.json. Ese archivo es la entrada del comparador y de la subida a Firestore.
 */
final class Salida
{
    public static function carpeta(): string
    {
        return dirname(__DIR__, 2) . '/storage/mercado';
    }

    /**
     * @param list<array<string, mixed>> $autos
     * @param array<string, mixed> $extra datos de la corrida (páginas, errores...)
     */
    public static function guardar(string $fuente, bool $completo, array $autos, array $extra = [], ?string $archivo = null): string
    {
        $archivo ??= self::carpeta() . "/$fuente/" . date('Y-m-d') . '.json';
        if (!is_dir(dirname($archivo))) {
            mkdir(dirname($archivo), 0775, true);
        }
        $datos = [
            'fuente'   => $fuente,
            'fecha'    => date(DATE_ATOM),
            'completo' => $completo,
            'dolar'    => self::dolar(),
        ] + $extra + ['autos' => array_values($autos)];
        file_put_contents($archivo, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $archivo;
    }

    /**
     * Dólar oficial venta del día (dolarapi.com), para pasar a pesos los avisos en dólares.
     * Si no responde, queda null y el comparador usa la cotización de otra corrida.
     *
     * @return array{tipo: string, venta: float, fecha: string}|null
     */
    public static function dolar(): ?array
    {
        try {
            $r = json_decode((new Http(0))->get('https://dolarapi.com/v1/dolares/oficial'), true);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($r) || !is_numeric($r['venta'] ?? null)) {
            return null;
        }
        return ['tipo' => 'oficial', 'venta' => (float)$r['venta'], 'fecha' => (string)($r['fechaActualizacion'] ?? '')];
    }
}
