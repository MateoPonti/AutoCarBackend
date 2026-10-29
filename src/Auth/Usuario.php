<?php
declare(strict_types=1);

namespace AutoCar\Auth;

use AutoCar\Core\Database;

/** Acceso a la tabla users. Todo con sentencias preparadas. */
final class Usuario
{
    public static function porEmail(string $email): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, nombre, email, password_hash, rol FROM users WHERE email = :email LIMIT 1');
        $st->execute(['email' => $email]);
        return $st->fetch() ?: null;
    }

    public static function porId(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, nombre, email, rol FROM users WHERE id = :id');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    public static function crear(string $nombre, string $email, string $hash): int
    {
        // El rol de un registro público es siempre 'usuario'; el admin se asigna en la base.
        $st = Database::pdo()->prepare(
            "INSERT INTO users (nombre, email, password_hash, rol) VALUES (:nombre, :email, :hash, 'usuario') RETURNING id"
        );
        $st->execute(['nombre' => $nombre, 'email' => $email, 'hash' => $hash]);
        return (int)$st->fetchColumn();
    }

    public static function rolDe(int $id): ?string
    {
        $st = Database::pdo()->prepare('SELECT rol FROM users WHERE id = :id');
        $st->execute(['id' => $id]);
        $rol = $st->fetchColumn();
        return $rol === false ? null : (string)$rol;
    }

    /** Datos que el front puede ver (nunca el hash). */
    public static function publico(array $u): array
    {
        return ['id' => (int)$u['id'], 'nombre' => $u['nombre'], 'email' => $u['email'], 'rol' => $u['rol']];
    }
}
