<?php
declare(strict_types=1);

namespace AutoCar\Empresas;

use AutoCar\Core\Database;
use DateTimeImmutable;
use PDO;

/**
 * "Sumá tu concesionaria" (033): una concesionaria o agencia pide sumarse y un admin la aprueba o la rechaza, como los
 * talleres. La cuenta nace con rol empresa y no puede ingresar mientras la solicitud está pendiente o rechazada (ver
 * AuthController::login). Aprobada, publica sin límite de autos, sus autos llevan la insignia con su nombre (sin logo),
 * tiene vitrina y panel, no sale en el metabuscador y paga el abono mensual (Afiliacion).
 */
final class Empresa
{
    public const ESTADOS = ['pendiente', 'aprobado', 'rechazado'];
    public const POR_PAGINA = 100;

    private const COLUMNAS = 'e.usuario_id, e.razon_social, e.cuit, e.localidad, e.telefono, e.sitio_web, e.estado,
        e.motivo_rechazo, e.created_at, e.resuelto_at, u.nombre AS responsable, u.email, u.activo, ru.nombre AS resuelto_por_nombre';

    private const DESDE = 'FROM empresa e
        JOIN usuario u ON u.id = e.usuario_id
        LEFT JOIN usuario ru ON ru.id = e.resuelto_por';

    /** Las que ya están en AutoCar (para el SQL de otras partes: insignia, metabuscador, abonos) */
    public const APROBADAS_SQL = "SELECT e.usuario_id FROM empresa e JOIN usuario u ON u.id = e.usuario_id WHERE e.estado = 'aprobado' AND u.activo";

    /**
     * Valida el formulario de "Sumá tu concesionaria" (los datos de la empresa; los de la cuenta, Usuario::validarCuenta).
     * Función pura. @return array{0: array, 1: array<string, string>} [datos limpios, errores por campo]
     */
    public static function validar(array $in): array
    {
        $texto = static fn(string $k): string => is_scalar($in[$k] ?? null) ? trim((string)$in[$k]) : '';
        $errores = [];
        $datos = [
            'razon_social' => $texto('razon_social'),
            'localidad'    => $texto('localidad'),
            'telefono'     => $texto('telefono'),
            'sitio_web'    => $texto('sitio_web') === '' ? null : $texto('sitio_web'),
        ];
        if (mb_strlen($datos['razon_social']) < 2 || mb_strlen($datos['razon_social']) > 100) {
            $errores['razon_social'] = 'Tiene que tener entre 2 y 100 caracteres.';
        }
        // CUIT: 11 números, con o sin guiones; se guarda como 30-12345678-9 (igual que el de un taller)
        $numeros = str_replace('-', '', $texto('cuit'));
        if (preg_match('/^[0-9]{11}$/', $numeros) === 1) {
            $datos['cuit'] = substr($numeros, 0, 2) . '-' . substr($numeros, 2, 8) . '-' . substr($numeros, 10, 1);
        } else {
            $datos['cuit'] = $texto('cuit');
            $errores['cuit'] = 'Usá el formato 30-12345678-9.';
        }
        if (mb_strlen($datos['localidad']) < 2 || mb_strlen($datos['localidad']) > 100) {
            $errores['localidad'] = 'Tiene que tener entre 2 y 100 caracteres.';
        }
        if (preg_match('/^\+?[0-9 ()-]{6,30}$/', $datos['telefono']) !== 1) {
            $errores['telefono'] = 'Ingresá un teléfono (solo números, espacios, guiones o paréntesis).';
        }
        if ($datos['sitio_web'] !== null
            && (mb_strlen($datos['sitio_web']) > 200 || preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $datos['sitio_web']) !== 1)) {
            $errores['sitio_web'] = 'Tiene que ser una dirección que empiece con http:// o https://.';
        }
        if (($in['condiciones'] ?? null) !== true) {
            $errores['condiciones'] = 'Tenés que aceptar las condiciones (abono mensual y moderación de las publicaciones).';
        }
        return [$datos, $errores];
    }

    /** La solicitud (fila de la empresa) de un usuario, o null si nunca pidió sumarse. */
    public static function delUsuario(int $usuarioId, bool $bloquear = false): ?array
    {
        $sql = 'SELECT ' . self::COLUMNAS . ' ' . self::DESDE . ' WHERE e.usuario_id = :id' . ($bloquear ? ' FOR UPDATE OF e' : '');
        $st = Database::pdo()->prepare($sql);
        $st->execute(['id' => $usuarioId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Guarda la solicitud como pendiente (si estaba rechazada, la reemplaza: puede volver a pedir). */
    public static function guardar(int $usuarioId, array $datos): void
    {
        Database::pdo()->prepare(
            "INSERT INTO empresa (usuario_id, razon_social, cuit, localidad, telefono, sitio_web, estado)
             VALUES (:id, :razon, :cuit, :localidad, :telefono, :sitio, 'pendiente')
             ON CONFLICT (usuario_id) DO UPDATE SET
               razon_social = EXCLUDED.razon_social, cuit = EXCLUDED.cuit, localidad = EXCLUDED.localidad,
               telefono = EXCLUDED.telefono, sitio_web = EXCLUDED.sitio_web, estado = 'pendiente', motivo_rechazo = NULL,
               resuelto_por = NULL, resuelto_at = NULL, created_at = now(), updated_at = now()"
        )->execute([
            'id' => $usuarioId, 'razon' => $datos['razon_social'], 'cuit' => $datos['cuit'], 'localidad' => $datos['localidad'],
            'telefono' => $datos['telefono'], 'sitio' => $datos['sitio_web'],
        ]);
    }

    /** Cola del admin: las pendientes, las más viejas primero; las resueltas, las últimas primero. */
    public static function listar(?string $estado): array
    {
        $where = $estado !== null ? 'WHERE e.estado = :estado' : '';
        $orden = $estado === 'pendiente' ? 'e.created_at ASC' : 'COALESCE(e.resuelto_at, e.created_at) DESC';
        $st = Database::pdo()->prepare('SELECT ' . self::COLUMNAS . ' ' . self::DESDE . " $where ORDER BY $orden, e.usuario_id LIMIT " . self::POR_PAGINA);
        $st->execute($estado !== null ? ['estado' => $estado] : []);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Filtro "Concesionaria" de Explorar: las aprobadas con al menos un auto disponible, por nombre.
     * @return list<array{id: int, nombre: string, autos: int}>
     */
    public static function conAutos(): array
    {
        $st = Database::pdo()->query(
            "SELECT e.usuario_id, e.razon_social, COUNT(*) AS autos
               FROM empresa e JOIN usuario u ON u.id = e.usuario_id
               JOIN vehiculo v ON v.usuario_id = e.usuario_id AND v.estado = 'disponible'
              WHERE e.estado = 'aprobado' AND u.activo
              GROUP BY e.usuario_id, e.razon_social
              ORDER BY e.razon_social, e.usuario_id"
        );
        return array_map(static fn(array $f): array => [
            'id' => (int)$f['usuario_id'], 'nombre' => $f['razon_social'], 'autos' => (int)$f['autos'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Aprueba o rechaza (dentro de una transacción, con la solicitud bloqueada y pendiente). La cuenta ya es empresa. */
    public static function resolver(int $usuarioId, bool $aprobar, ?string $motivo, int $resueltoPor): void
    {
        Database::pdo()->prepare(
            "UPDATE empresa SET estado = :estado, motivo_rechazo = :motivo, resuelto_por = :por, resuelto_at = now(), updated_at = now()
              WHERE usuario_id = :id AND estado = 'pendiente'"
        )->execute(['id' => $usuarioId, 'estado' => $aprobar ? 'aprobado' : 'rechazado', 'motivo' => $motivo, 'por' => $resueltoPor]);
    }

    /**
     * El nombre de cada empresa aprobada entre esos dueños de publicaciones (para la insignia).
     * @param list<int> $usuarioIds @return array<int, array{id: int, nombre: string}>
     */
    public static function insignias(array $usuarioIds): array
    {
        $usuarioIds = array_values(array_unique(array_map('intval', $usuarioIds)));
        if ($usuarioIds === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($usuarioIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT e.usuario_id, e.razon_social FROM empresa e JOIN usuario u ON u.id = e.usuario_id
              WHERE e.estado = 'aprobado' AND u.activo AND e.usuario_id IN ($marcas)"
        );
        $st->execute($usuarioIds);
        $salida = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $salida[(int)$f['usuario_id']] = ['id' => (int)$f['usuario_id'], 'nombre' => $f['razon_social']];
        }
        return $salida;
    }

    /**
     * Las empresas aprobadas y activas, con el mes desde el que pagan abono (el de su aprobación), para Afiliacion.
     * @return list<array{id: int, nombre: string, desde: string}>
     */
    public static function conAbono(): array
    {
        return array_map(static fn(array $f): array => [
            'id'     => (int)$f['usuario_id'],
            'nombre' => $f['razon_social'],
            'desde'  => $f['desde'],
        ], Database::pdo()->query(
            "SELECT e.usuario_id, e.razon_social,
                    to_char(LEAST(
                        date_trunc('month', COALESCE(e.resuelto_at, e.created_at) AT TIME ZONE 'America/Argentina/Buenos_Aires')::date,
                        -- un abono cobrado antes (cargado a mano o de demo) también cuenta: si no, ese mes no aparecería
                        (SELECT min(c.mes) FROM cobro_afiliacion c WHERE c.tipo = 'empresa' AND c.socio = e.usuario_id::text)
                    ), 'YYYY-MM-01') AS desde
               FROM empresa e JOIN usuario u ON u.id = e.usuario_id
              WHERE e.estado = 'aprobado' AND u.activo ORDER BY e.razon_social"
        )->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function paraApi(array $e): array
    {
        $fecha = static fn(?string $f): ?string => $f === null ? null : (new DateTimeImmutable($f))->format(DATE_ATOM);
        return [
            'usuario_id'     => (int)$e['usuario_id'],
            'responsable'    => $e['responsable'],
            'email'          => $e['email'],
            'razon_social'   => $e['razon_social'],
            'cuit'           => $e['cuit'],
            'localidad'      => $e['localidad'],
            'telefono'       => $e['telefono'],
            'sitio_web'      => $e['sitio_web'],
            'estado'         => $e['estado'],
            'motivo_rechazo' => $e['motivo_rechazo'],
            'creada'         => $fecha($e['created_at']),
            'resuelta'       => $fecha($e['resuelto_at']),
            'resuelta_por'   => $e['resuelto_por_nombre'],
        ];
    }

    /** Lo público de una empresa aprobada: lo que se ve en su vitrina (nunca el email ni el CUIT completo). */
    public static function publica(array $e): array
    {
        return [
            'id'        => (int)$e['usuario_id'],
            'nombre'    => $e['razon_social'],
            'localidad' => $e['localidad'],
            'telefono'  => $e['telefono'],
            'sitio_web' => $e['sitio_web'],
            'desde'     => (new DateTimeImmutable($e['resuelto_at'] ?? $e['created_at']))->format(DATE_ATOM),
        ];
    }
}
