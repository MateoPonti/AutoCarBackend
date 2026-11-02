<?php
declare(strict_types=1);

namespace AutoCar\Chat;

use AutoCar\Core\Database;
use DateTimeImmutable;
use PDO;

/** Acceso a la tabla mensaje. Quien llama ya verificó que el usuario participa de la conversación. */
final class Mensaje
{
    public const MAX_LARGO = 1000;
    public const POR_PEDIDO = 100;

    /** Mensajes con id mayor a $desde, del más viejo al más nuevo (polling incremental). */
    public static function desde(int $conversacionId, int $desde): array
    {
        $st = Database::pdo()->prepare(
            'SELECT id, usuario_id, texto, leido, created_at FROM mensaje
              WHERE conversacion_id = :conversacion AND id > :desde ORDER BY id LIMIT :lim'
        );
        $st->bindValue(':conversacion', $conversacionId, PDO::PARAM_INT);
        $st->bindValue(':desde', $desde, PDO::PARAM_INT);
        $st->bindValue(':lim', self::POR_PEDIDO, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** Inserta el mensaje y mueve la conversación al tope de la bandeja, en una sola sentencia. */
    public static function crear(int $conversacionId, int $usuarioId, string $texto): array
    {
        $st = Database::pdo()->prepare(
            'WITH nuevo AS (
                INSERT INTO mensaje (conversacion_id, usuario_id, texto) VALUES (:conversacion1, :usuario, :texto)
                RETURNING id, usuario_id, texto, leido, created_at
             ), tocada AS (
                UPDATE conversacion SET updated_at = now() WHERE id = :conversacion2
             )
             SELECT * FROM nuevo'
        );
        $st->execute([
            'conversacion1' => $conversacionId, 'conversacion2' => $conversacionId,
            'usuario' => $usuarioId, 'texto' => $texto,
        ]);
        return $st->fetch();
    }

    /** Marca como leídos los mensajes del otro participante hasta $hastaId (los que el lector ya recibió). */
    public static function marcarLeidos(int $conversacionId, int $lectorId, int $hastaId): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE mensaje SET leido = true
              WHERE conversacion_id = :conversacion AND usuario_id <> :lector AND id <= :hasta AND NOT leido'
        );
        $st->execute(['conversacion' => $conversacionId, 'lector' => $lectorId, 'hasta' => $hastaId]);
    }

    /** El texto va tal cual: el front lo tiene que mostrar con textContent, nunca con innerHTML. */
    public static function paraApi(array $m, int $yo): array
    {
        return [
            'id'     => (int)$m['id'],
            'texto'  => $m['texto'],
            'propio' => (int)$m['usuario_id'] === $yo,
            'leido'  => (bool)$m['leido'],
            'fecha'  => self::fecha($m['created_at']),
        ];
    }

    /** Timestamp de Postgres → ISO 8601, que new Date() del front interpreta sin ambigüedad. */
    public static function fecha(string $ts): string
    {
        return (new DateTimeImmutable($ts))->format(DATE_ATOM);
    }
}
