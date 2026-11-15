<?php
declare(strict_types=1);

namespace AutoCar\Parametros;

use AutoCar\Core\Config;
use AutoCar\Core\Database;
use AutoCar\Core\Logger;

/**
 * La cotización del dólar (parámetro cotizacion_dolar) que usa el metabuscador para mostrar en pesos los autos
 * publicados en dólares. Se actualiza sola: si tiene más de VIGENCIA_HORAS, se pide la del dólar blue (venta) a
 * dolarapi.com, que es pública y gratuita, y se guarda con su cambio anotado (sin usuario = automático).
 * Blue y no oficial: es la referencia de la compraventa de autos usados en dólares.
 * Si dolarapi.com no responde o manda algo raro, queda la última cotización: el metabuscador no se corta.
 * El admin la puede cambiar a mano en Parámetros; a las VIGENCIA_HORAS se vuelve a actualizar sola.
 */
final class Cotizacion
{
    public const CLAVE = 'cotizacion_dolar';
    public const URL = 'https://dolarapi.com/v1/dolares/blue';
    public const VIGENCIA_HORAS = 12;
    private const SALTO_MAXIMO = 0.5; // una cotización que cambia más de 50 % de un día al otro se toma como error

    /** La cotización vigente, actualizándola antes si está vieja. */
    public static function actual(): float
    {
        $fila = Database::pdo()->query(
            "SELECT valor, minimo, maximo, updated_at < now() - interval '" . self::VIGENCIA_HORAS . " hours' AS vieja
               FROM parametro WHERE clave = '" . self::CLAVE . "'"
        )->fetch();
        if (!$fila) {
            throw new \RuntimeException("Falta el parámetro '" . self::CLAVE . "' (¿se corrió la 023?).");
        }
        $valor = (float)$fila['valor'];
        if (!$fila['vieja']) {
            return $valor;
        }
        $nueva = self::validar(self::pedir(), $valor, (float)$fila['minimo'], (float)$fila['maximo']);
        if ($nueva === null) {
            return $valor;
        }
        return self::guardar($valor, $nueva) ? $nueva : $valor;
    }

    /**
     * La nueva cotización si es creíble: un número dentro de los límites del parámetro y sin un salto de más
     * del 50 % contra la actual. Función pura (se prueba sin red ni base).
     */
    public static function validar(mixed $venta, float $actual, float $minimo, float $maximo): ?float
    {
        if (!is_int($venta) && !is_float($venta)) {
            return null;
        }
        $venta = round((float)$venta, 2);
        if ($venta < $minimo || $venta > $maximo || abs($venta - $actual) > $actual * self::SALTO_MAXIMO) {
            return null;
        }
        return $venta;
    }

    /** La venta del dólar blue según dolarapi.com, o null si no se pudo. Espera poco: corre dentro de un pedido. */
    private static function pedir(): mixed
    {
        $ch = curl_init(self::URL);
        $opciones = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3];
        $ca = Config::get('CURL_CAINFO');
        if ($ca !== null && is_file($ca)) {
            $opciones[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opciones);
        $respuesta = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $datos = is_string($respuesta) ? json_decode($respuesta, true) : null;
        if ($codigo !== 200 || !is_array($datos)) {
            Logger::error("Cotización: dolarapi.com respondió $codigo");
            return null;
        }
        return $datos['venta'] ?? null;
    }

    /**
     * Guarda la cotización y anota el cambio. El UPDATE solo pasa si sigue vieja: si dos pedidos llegan juntos,
     * uno solo la cambia. → true si la guardó este pedido.
     */
    private static function guardar(float $anterior, float $nueva): bool
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $st = $pdo->prepare(
            "UPDATE parametro SET valor = :valor, updated_at = now(), updated_by = NULL
              WHERE clave = :clave AND updated_at < now() - interval '" . self::VIGENCIA_HORAS . " hours'"
        );
        $st->execute(['valor' => $nueva, 'clave' => self::CLAVE]);
        if ($st->rowCount() === 0) {
            $pdo->rollBack();
            return false;
        }
        if ($nueva !== $anterior) {
            $pdo->prepare('INSERT INTO parametro_cambio (clave, valor_anterior, valor_nuevo, usuario_id) VALUES (:clave, :antes, :despues, NULL)')
                ->execute(['clave' => self::CLAVE, 'antes' => $anterior, 'despues' => $nueva]);
        }
        $pdo->commit();
        return true;
    }
}
