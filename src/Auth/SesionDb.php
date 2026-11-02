<?php
declare(strict_types=1);

namespace AutoCar\Auth;

use AutoCar\Core\Database;
use PDO;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Guarda las sesiones de PHP en la tabla "sesion" en vez de en archivos: el disco de Render se borra en
 * cada deploy y con archivos se cerraría la sesión de todos. PHP llama a estos métodos solo
 * (session_start, session_regenerate_id, session_destroy y al terminar el pedido).
 */
final class SesionDb implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(private readonly int $ttl)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    /** Los datos de la sesión (serializados por PHP), o '' si no existe o venció. */
    public function read(string $id): string|false
    {
        $st = Database::pdo()->prepare('SELECT datos FROM sesion WHERE id = :id AND vence > now()');
        $st->execute(['id' => $id]);
        $datos = $st->fetchColumn();
        return $datos === false ? '' : $datos;
    }

    /** Una sesión vacía no se guarda: así un pedido sin login no deja filas en la tabla. */
    public function write(string $id, string $data): bool
    {
        if ($data === '') {
            return $this->destroy($id);
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO sesion (id, datos, vence) VALUES (:id, :datos, now() + make_interval(secs => :ttl))
             ON CONFLICT (id) DO UPDATE SET datos = EXCLUDED.datos, vence = EXCLUDED.vence'
        );
        $st->bindValue(':id', $id);
        $st->bindValue(':datos', $data);
        $st->bindValue(':ttl', $this->ttl, PDO::PARAM_INT);
        return $st->execute();
    }

    public function destroy(string $id): bool
    {
        return Database::pdo()->prepare('DELETE FROM sesion WHERE id = :id')->execute(['id' => $id]);
    }

    /** Borra las vencidas (PHP lo llama de vez en cuando, según session.gc_probability). */
    public function gc(int $max_lifetime): int|false
    {
        return Database::pdo()->exec('DELETE FROM sesion WHERE vence < now()');
    }

    /** Con session.use_strict_mode, PHP rechaza un id que no existe y genera uno nuevo (evita fijación de sesión). */
    public function validateId(string $id): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM sesion WHERE id = :id AND vence > now()');
        $st->execute(['id' => $id]);
        return $st->fetchColumn() !== false;
    }

    /** Los datos no cambiaron en este pedido: solo se extiende la vida de la fila. */
    public function updateTimestamp(string $id, string $data): bool
    {
        $st = Database::pdo()->prepare('UPDATE sesion SET vence = now() + make_interval(secs => :ttl) WHERE id = :id');
        $st->bindValue(':id', $id);
        $st->bindValue(':ttl', $this->ttl, PDO::PARAM_INT);
        return $st->execute();
    }
}
