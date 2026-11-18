<?php
declare(strict_types=1);

namespace AutoCar\Estadisticas;

use AutoCar\Auth\Auth;
use AutoCar\Core\Database;
use AutoCar\Core\Request;
use AutoCar\Core\Response;

/**
 * POST /api/eventos (cuenta personal): qué recomendaciones vio y tocó el comprador, para el embudo del admin
 * ("de los que vieron un destacado, ¿cuántos pagaron la inspección?"). Ver database/028-estadisticas.sql.
 * Cuerpo: {eventos: [{tipo: mostrado|tocado, origen, ref?}]}, hasta 50 por pedido. Lo que no es válido se ignora:
 * son datos para estadísticas, no tienen que hacer fallar la página del comprador.
 */
final class Eventos
{
    public const TIPOS    = ['mostrado', 'tocado'];
    public const ORIGENES = ['para_vos', 'destacado', 'metabuscador', 'publicacion'];
    private const MAXIMO_POR_PEDIDO = 50;

    public function registrar(): void
    {
        $validos = self::validos(Request::json()['eventos'] ?? null);
        if ($validos !== []) {
            $st = Database::pdo()->prepare('INSERT INTO evento (usuario_id, tipo, origen, ref) VALUES (:u, :t, :o, :r)');
            foreach ($validos as $e) {
                $st->execute(['u' => (int)Auth::id(), 't' => $e['tipo'], 'o' => $e['origen'], 'r' => $e['ref']]);
            }
        }
        Response::noContent();
    }

    /**
     * Los eventos que se pueden guardar, como {tipo, origen, ref}. Función pura (se prueba sin base).
     * @return list<array{tipo: string, origen: string, ref: ?string}>
     */
    public static function validos(mixed $eventos): array
    {
        if (!is_array($eventos)) {
            return [];
        }
        $salida = [];
        foreach (array_slice(array_values($eventos), 0, self::MAXIMO_POR_PEDIDO) as $e) {
            if (!is_array($e) || !in_array($e['tipo'] ?? null, self::TIPOS, true) || !self::esOrigen($e['origen'] ?? null)) {
                continue;
            }
            $ref = is_scalar($e['ref'] ?? null) && trim((string)$e['ref']) !== '' ? mb_substr(trim((string)$e['ref']), 0, 500) : null;
            $salida[] = ['tipo' => $e['tipo'], 'origen' => $e['origen'], 'ref' => $ref];
        }
        return $salida;
    }

    public static function esOrigen(mixed $origen): bool
    {
        return in_array($origen, self::ORIGENES, true);
    }
}
