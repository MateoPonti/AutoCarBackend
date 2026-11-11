<?php
declare(strict_types=1);

namespace AutoCar\Soporte;

use AutoCar\Auth\Auth;
use AutoCar\Core\Database;
use AutoCar\Core\Request;
use AutoCar\Core\Response;

/**
 * Mensajes al equipo de AutoCar (015-contacto-y-reportes.sql):
 *  - Contacto: cualquiera, sin cuenta, escribe con su email; el admin los lee en el panel y contesta por mail.
 *  - Reportes: un usuario logueado reporta una publicación o a otro usuario; el moderador los revisa y los resuelve
 *    (si hace falta, da de baja la publicación desde Moderar).
 */
final class SoporteController
{
    public const MOTIVOS = ['fraude', 'datos', 'vendido', 'trato', 'spam', 'otro'];
    private const CONTACTOS_POR_HORA = 3; // por email: que no llenen la tabla desde un script

    /** POST /api/contacto {email, mensaje} (público) → 201 */
    public function contactar(): void
    {
        $in = Request::json();
        $email = Request::texto($in, 'email');
        $mensaje = Request::texto($in, 'mensaje');
        $errores = [];
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores['email'] = 'Ingresá un email válido para poder contestarte.';
        }
        if (mb_strlen($mensaje) < 10 || mb_strlen($mensaje) > 1000) {
            $errores['mensaje'] = 'Escribí tu mensaje (entre 10 y 1000 caracteres).';
        }
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT count(*) FROM contacto WHERE email = :e AND created_at > now() - interval '1 hour'");
        $st->execute(['e' => $email]);
        if ((int)$st->fetchColumn() >= self::CONTACTOS_POR_HORA) {
            Response::error(429, 'demasiados', 'Ya nos escribiste varias veces en la última hora. Probá más tarde.');
        }
        $pdo->prepare('INSERT INTO contacto (email, mensaje) VALUES (:e, :m)')->execute(['e' => $email, 'm' => $mensaje]);
        Response::json(['ok' => true], 201);
    }

    /** POST /api/reportes {vehiculo_id | usuario_id, motivo, detalle?} (sesión) → 201 */
    public function reportar(): void
    {
        $in = Request::json();
        $vehiculo = filter_var($in['vehiculo_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $usuario = filter_var($in['usuario_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $motivo = Request::texto($in, 'motivo');
        $detalle = Request::texto($in, 'detalle');
        $errores = [];
        if (($vehiculo === null) === ($usuario === null)) {
            $errores['objetivo'] = 'Indicá qué publicación o qué usuario reportás.';
        }
        if (!in_array($motivo, self::MOTIVOS, true)) {
            $errores['motivo'] = 'Elegí un motivo.';
        }
        if (mb_strlen($detalle) > 500) {
            $errores['detalle'] = 'El detalle puede tener hasta 500 caracteres.';
        }
        if ($usuario !== null && $usuario === (int)Auth::id()) {
            $errores['objetivo'] = 'No podés reportarte a vos mismo.';
        }
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        $pdo = Database::pdo();
        $tabla = $vehiculo !== null ? 'vehiculo' : 'usuario';
        $st = $pdo->prepare("SELECT 1 FROM $tabla WHERE id = :id");
        $st->execute(['id' => $vehiculo ?? $usuario]);
        if ($st->fetchColumn() === false) {
            Response::error(404, 'no_encontrado', $vehiculo !== null ? 'La publicación no existe.' : 'El usuario no existe.');
        }
        $pdo->prepare('INSERT INTO reporte (autor_id, vehiculo_id, usuario_id, motivo, detalle)
                        VALUES (:a, :v, :u, :m, :d)')
            ->execute(['a' => Auth::id(), 'v' => $vehiculo, 'u' => $usuario, 'm' => $motivo, 'd' => $detalle !== '' ? $detalle : null]);
        Response::json(['ok' => true], 201);
    }

    /** GET /api/admin/contactos (admin): los no leídos primero, los más nuevos arriba. */
    public function contactos(): void
    {
        $filas = Database::pdo()->query('SELECT id, email, mensaje, leido, created_at FROM contacto
                                          ORDER BY leido, created_at DESC LIMIT 200')->fetchAll();
        Response::json(['items' => array_map(static fn(array $f): array => [
            'id' => (int)$f['id'], 'email' => $f['email'], 'mensaje' => $f['mensaje'], 'leido' => (bool)$f['leido'],
            'fecha' => date('c', (int)strtotime((string)$f['created_at'])),
        ], $filas)]);
    }

    /** PATCH /api/admin/contactos/{id} → 204: lo marca como leído. */
    public function leerContacto(int $id): void
    {
        $st = Database::pdo()->prepare('UPDATE contacto SET leido = true WHERE id = :id');
        $st->execute(['id' => $id]);
        if ($st->rowCount() === 0) {
            Response::error(404, 'no_encontrado', 'El mensaje no existe.');
        }
        Response::noContent();
    }

    /** GET /api/admin/reportes (moderador): pendientes primero, con qué se reportó y quién. */
    public function reportes(): void
    {
        $filas = Database::pdo()->query(
            "SELECT r.id, r.motivo, r.detalle, r.estado, r.created_at, a.nombre AS autor,
                    r.vehiculo_id, v.marca, v.modelo, v.anio, r.usuario_id, u.nombre AS usuario
               FROM reporte r JOIN usuario a ON a.id = r.autor_id
               LEFT JOIN vehiculo v ON v.id = r.vehiculo_id LEFT JOIN usuario u ON u.id = r.usuario_id
              ORDER BY r.estado = 'resuelto', r.created_at DESC LIMIT 200"
        )->fetchAll();
        Response::json(['items' => array_map(static fn(array $f): array => [
            'id' => (int)$f['id'], 'motivo' => $f['motivo'], 'detalle' => $f['detalle'], 'estado' => $f['estado'],
            'autor' => $f['autor'], 'fecha' => date('c', (int)strtotime((string)$f['created_at'])),
            'vehiculo' => $f['vehiculo_id'] === null ? null
                : ['id' => (int)$f['vehiculo_id'], 'titulo' => "{$f['marca']} {$f['modelo']} {$f['anio']}"],
            'usuario' => $f['usuario_id'] === null ? null : ['id' => (int)$f['usuario_id'], 'nombre' => $f['usuario']],
        ], $filas)]);
    }

    /** PATCH /api/admin/reportes/{id} → 204: lo marca como resuelto. */
    public function resolverReporte(int $id): void
    {
        $st = Database::pdo()->prepare("UPDATE reporte SET estado = 'resuelto' WHERE id = :id");
        $st->execute(['id' => $id]);
        if ($st->rowCount() === 0) {
            Response::error(404, 'no_encontrado', 'El reporte no existe.');
        }
        Response::noContent();
    }
}
