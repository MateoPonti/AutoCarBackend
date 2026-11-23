<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Core\Database;
use DateTimeImmutable;

/**
 * Créditos del comprador cuando el auto se vendió (tabla credito, ver AutoVendido). Los paga AutoCar de su parte.
 * Se usan solos en la próxima inspección que pide el comprador (uno por
 * inspección, el más viejo primero) y vuelven a estar disponibles si esa inspección se cae, se cancela o se rechaza.
 */
final class Creditos
{
    /** Le da el crédito al comprador. Una inspección da uno solo: si ya lo dio, no hace nada. */
    public static function dar(int $usuarioId, int $inspeccionId, string $tipo, float $monto): void
    {
        if ($monto <= 0) {
            return;
        }
        Database::pdo()->prepare(
            'INSERT INTO credito (usuario_id, inspeccion_id, tipo, monto) VALUES (:u, :i, :t, :m)
             ON CONFLICT (inspeccion_id) DO NOTHING'
        )->execute(['u' => $usuarioId, 'i' => $inspeccionId, 't' => $tipo, 'm' => $monto]);
    }

    /** Los créditos que el usuario todavía no usó, el más viejo primero. */
    public static function disponibles(int $usuarioId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT c.id, c.tipo, c.monto, c.created_at, c.inspeccion_id, i.titulo
               FROM credito c JOIN inspeccion i ON i.id = c.inspeccion_id
              WHERE c.usuario_id = :u AND c.usado_en IS NULL ORDER BY c.created_at, c.id'
        );
        $st->execute(['u' => $usuarioId]);
        return array_map(static fn(array $c): array => [
            'id'            => (int)$c['id'],
            'tipo'          => $c['tipo'],
            'monto'         => (float)$c['monto'],
            'created_at'    => (new DateTimeImmutable($c['created_at']))->format(DATE_ATOM),
            'inspeccion_id' => (int)$c['inspeccion_id'],
            'titulo'        => $c['titulo'],
        ], $st->fetchAll());
    }

    /**
     * Usa el crédito más viejo del comprador en la inspección nueva (hasta el precio). Se llama dentro de la misma
     * transacción que la crea. → el monto descontado (0 si no tenía).
     */
    public static function usarEn(int $usuarioId, int $inspeccionId, float $precio): float
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id, monto FROM credito WHERE usuario_id = :u AND usado_en IS NULL
            ORDER BY created_at, id LIMIT 1 FOR UPDATE SKIP LOCKED');
        $st->execute(['u' => $usuarioId]);
        $c = $st->fetch();
        if (!$c) {
            return 0.0;
        }
        $monto = min((float)$c['monto'], $precio);
        $pdo->prepare('UPDATE credito SET usado_en = :i WHERE id = :id')->execute(['i' => $inspeccionId, 'id' => $c['id']]);
        $pdo->prepare('UPDATE inspeccion SET credito_usado = :m WHERE id = :i')->execute(['m' => $monto, 'i' => $inspeccionId]);
        return $monto;
    }

    /** La inspección se cayó, se canceló o se rechazó: el crédito que usó vuelve a estar disponible. */
    public static function liberar(int $inspeccionId): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE credito SET usado_en = NULL WHERE usado_en = :i')->execute(['i' => $inspeccionId]);
        $pdo->prepare('UPDATE inspeccion SET credito_usado = 0 WHERE id = :i')->execute(['i' => $inspeccionId]);
    }
}
