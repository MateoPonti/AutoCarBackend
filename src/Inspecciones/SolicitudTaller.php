<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Core\Database;
use DateTimeImmutable;

/**
 * "Adherí tu taller": un usuario pide sumar su taller a la red y un admin lo aprueba o lo rechaza.
 * La solicitud es la misma fila de la tabla taller, con estado 'pendiente', 'aprobado' o 'rechazado'.
 * Mientras está pendiente o rechazada, el taller no aparece en el ranking (ver Taller::EN_LA_RED).
 * Al aprobarla, el usuario pasa a rol mecanico: desde ahí entra con su usuario de siempre y ve su panel.
 */
final class SolicitudTaller
{
    public const ESTADOS = ['pendiente', 'aprobado', 'rechazado'];
    public const POR_PAGINA = 100;

    private const COLUMNAS = 't.usuario_id, t.nombre, t.cuit, t.direccion, t.localidad, t.telefono, t.horarios,
        t.precio_inspeccion, t.estado, t.motivo_rechazo, t.created_at, t.resuelto_at,
        u.nombre AS responsable, u.email, r.nombre AS rol, ru.nombre AS resuelto_por_nombre';

    private const DESDE = 'FROM taller t
        JOIN usuario u ON u.id = t.usuario_id
        JOIN rol r ON r.id = u.rol_id
        LEFT JOIN usuario ru ON ru.id = t.resuelto_por';

    /**
     * Valida el formulario de "Adherí tu taller". Función pura (se prueba sin base).
     * Usa las mismas reglas que el perfil del mecánico (Taller::validar) y suma CUIT, horarios y las condiciones.
     * @return array{0: array, 1: array<string, string>} [datos limpios, errores por campo]
     */
    public static function validar(array $in): array
    {
        [$datos, $errores] = Taller::validar($in);

        // CUIT: 11 números. Se acepta con o sin guiones y se guarda siempre como 30-12345678-9.
        $cuit = is_scalar($in['cuit'] ?? null) ? trim((string)$in['cuit']) : '';
        $soloNumeros = str_replace('-', '', $cuit);
        if (preg_match('/^[0-9]{11}$/', $soloNumeros) === 1) {
            $datos['cuit'] = substr($soloNumeros, 0, 2) . '-' . substr($soloNumeros, 2, 8) . '-' . substr($soloNumeros, 10, 1);
        } else {
            $datos['cuit'] = $cuit;
            $errores['cuit'] = 'Usá el formato 30-12345678-9.';
        }

        $horarios = is_scalar($in['horarios'] ?? null) ? trim((string)$in['horarios']) : '';
        $datos['horarios'] = $horarios;
        if (mb_strlen($horarios) < 3 || mb_strlen($horarios) > 80) {
            $errores['horarios'] = 'Tiene que tener entre 3 y 80 caracteres.';
        }

        if (($in['condiciones'] ?? null) !== true) {
            $errores['condiciones'] = 'Tenés que aceptar las condiciones de adhesión.';
        }
        return [$datos, $errores];
    }

    /**
     * Valida la respuesta del admin: {aprobar: true} o {aprobar: false, motivo}. Función pura.
     * @return array{0: array{aprobar: bool, motivo: ?string}, 1: array<string, string>}
     */
    public static function validarResolucion(array $in): array
    {
        $errores = [];
        $aprobar = $in['aprobar'] ?? null;
        if (!is_bool($aprobar)) {
            $errores['aprobar'] = 'Tiene que ser true (aprobar) o false (rechazar).';
            $aprobar = false;
        }

        $motivo = is_scalar($in['motivo'] ?? null) ? trim((string)$in['motivo']) : '';
        if ($aprobar) {
            $motivo = null; // al aprobar no hace falta motivo
        } elseif (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 300) {
            $errores['motivo'] = 'Contale al taller por qué se rechaza (entre 5 y 300 caracteres).';
        }
        return [['aprobar' => $aprobar, 'motivo' => $motivo], $errores];
    }

    /** La solicitud de un usuario (la fila de su taller), o null si nunca pidió sumarse. */
    public static function delUsuario(int $usuarioId, bool $bloquear = false): ?array
    {
        $sql = 'SELECT ' . self::COLUMNAS . ' ' . self::DESDE . ' WHERE t.usuario_id = :id';
        if ($bloquear) {
            $sql .= ' FOR UPDATE OF t';
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute(['id' => $usuarioId]);
        $fila = $st->fetch();
        return $fila ?: null;
    }

    /**
     * Guarda la solicitud como pendiente. Si el usuario ya tenía una rechazada, la reemplaza (puede volver a pedir).
     * Se llama después de revisar que no tenga una pendiente ni aprobada.
     */
    public static function guardar(int $usuarioId, array $datos): void
    {
        $st = Database::pdo()->prepare(
            "INSERT INTO taller (usuario_id, nombre, cuit, direccion, localidad, telefono, horarios, precio_inspeccion, estado)
             VALUES (:id, :nombre, :cuit, :direccion, :localidad, :telefono, :horarios, :precio, 'pendiente')
             ON CONFLICT (usuario_id) DO UPDATE SET
               nombre = EXCLUDED.nombre, cuit = EXCLUDED.cuit, direccion = EXCLUDED.direccion,
               localidad = EXCLUDED.localidad, telefono = EXCLUDED.telefono, horarios = EXCLUDED.horarios,
               precio_inspeccion = EXCLUDED.precio_inspeccion, estado = 'pendiente', motivo_rechazo = NULL,
               resuelto_por = NULL, resuelto_at = NULL, created_at = now(), updated_at = now()"
        );
        $st->execute([
            'id'        => $usuarioId,
            'nombre'    => $datos['nombre'],
            'cuit'      => $datos['cuit'],
            'direccion' => $datos['direccion'],
            'localidad' => $datos['localidad'],
            'telefono'  => $datos['telefono'],
            'horarios'  => $datos['horarios'],
            'precio'    => $datos['precio_inspeccion'],
        ]);
    }

    /** Cola del admin: las pendientes, las más viejas primero; las resueltas, las últimas primero. */
    public static function listar(?string $estado): array
    {
        $where = '';
        $params = [];
        if ($estado !== null) {
            $where = 'WHERE t.estado = :estado';
            $params['estado'] = $estado;
        }
        $orden = $estado === 'pendiente' ? 't.created_at ASC' : 'COALESCE(t.resuelto_at, t.created_at) DESC';
        $st = Database::pdo()->prepare('SELECT ' . self::COLUMNAS . ' ' . self::DESDE
            . " $where ORDER BY $orden, t.usuario_id LIMIT " . self::POR_PAGINA);
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * Aprueba o rechaza. Se llama dentro de una transacción con la solicitud bloqueada y todavía pendiente.
     * Al aprobar, el usuario pasa a rol mecanico (si era un usuario común: a un admin o moderador no se le baja el rol).
     */
    public static function resolver(int $usuarioId, bool $aprobar, ?string $motivo, int $resueltoPor): void
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE taller SET estado = :estado, motivo_rechazo = :motivo, resuelto_por = :por, resuelto_at = now(),
               updated_at = now()
             WHERE usuario_id = :id AND estado = 'pendiente'"
        )->execute([
            'id'     => $usuarioId,
            'estado' => $aprobar ? 'aprobado' : 'rechazado',
            'motivo' => $motivo,
            'por'    => $resueltoPor,
        ]);

        if ($aprobar) {
            $pdo->prepare(
                "UPDATE usuario SET rol_id = (SELECT id FROM rol WHERE nombre = 'mecanico')
                 WHERE id = :id AND rol_id = (SELECT id FROM rol WHERE nombre = 'usuario')"
            )->execute(['id' => $usuarioId]);
        }
    }

    public static function paraApi(array $s): array
    {
        $resueltoAt = null;
        if ($s['resuelto_at'] !== null) {
            $resueltoAt = (new DateTimeImmutable($s['resuelto_at']))->format(DATE_ATOM);
        }
        return [
            'usuario_id'        => (int)$s['usuario_id'],
            'responsable'       => $s['responsable'],
            'email'             => $s['email'],
            'rol'               => $s['rol'],
            'taller'            => $s['nombre'],
            'cuit'              => $s['cuit'],
            'direccion'         => $s['direccion'],
            'localidad'         => $s['localidad'],
            'telefono'          => $s['telefono'],
            'horarios'          => $s['horarios'],
            'precio_inspeccion' => (float)$s['precio_inspeccion'],
            'estado'            => $s['estado'],
            'motivo_rechazo'    => $s['motivo_rechazo'],
            'creada'            => (new DateTimeImmutable($s['created_at']))->format(DATE_ATOM),
            'resuelta'          => $resueltoAt,
            'resuelta_por'      => $s['resuelto_por_nombre'],
        ];
    }
}
