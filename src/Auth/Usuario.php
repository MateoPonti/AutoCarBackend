<?php
declare(strict_types=1);

namespace AutoCar\Auth;

use AutoCar\Core\Database;
use PDO;

/**
 * Acceso a la tabla usuario y a sus roles y permisos (tablas rol, permiso y rol_permiso).
 * Todo con sentencias preparadas.
 */
final class Usuario
{
    public const POR_PAGINA = 20;

    /** Columnas que devuelven las consultas: el rol se trae por nombre con un JOIN. */
    // foto_v: versión de la foto de perfil (cuándo se subió), para que el navegador no muestre una vieja del caché
    private const CAMPOS = 'u.id, u.nombre, u.email, u.activo, u.created_at, r.nombre AS rol,
        (SELECT extract(epoch FROM uf.updated_at)::bigint FROM usuario_foto uf WHERE uf.usuario_id = u.id) AS foto_v';

    public static function porEmail(string $email): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT ' . self::CAMPOS . ', u.password_hash FROM usuario u JOIN rol r ON r.id = u.rol_id WHERE u.email = :email LIMIT 1'
        );
        $st->execute(['email' => $email]);
        return $st->fetch() ?: null;
    }

    public static function porId(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT ' . self::CAMPOS . ' FROM usuario u JOIN rol r ON r.id = u.rol_id WHERE u.id = :id');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    /**
     * Valida los datos de una cuenta nueva (registro y "Adherí tu taller"). Función pura (se prueba sin base).
     * @return array<string, string> errores por campo: nombre, email, password, password_confirm
     */
    public static function validarCuenta(string $nombre, string $email, string $pass, string $confirm): array
    {
        $errores = [];
        $len = mb_strlen($nombre);
        if ($len < 2 || $len > 80) {
            $errores['nombre'] = 'Ingresá tu nombre (entre 2 y 80 caracteres).';
        }
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores['email'] = 'Ingresá un email válido.';
        }
        if (strlen($pass) < 8 || strlen($pass) > 72) {
            $errores['password'] = 'La contraseña debe tener entre 8 y 72 caracteres.';
        } elseif (!preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
            $errores['password'] = 'La contraseña necesita al menos una letra y un número.';
        }
        if ($pass !== $confirm) {
            $errores['password_confirm'] = 'Las contraseñas no coinciden.';
        }
        return $errores;
    }

    /**
     * Un registro público nace con el rol 'usuario'. La cuenta de un taller ("Adherí tu taller") nace 'mecanico',
     * pero no puede ingresar hasta que un admin apruebe la solicitud (ver AuthController::login).
     * Los demás roles los asigna el admin.
     */
    public static function crear(string $nombre, string $email, string $hash, string $rol = 'usuario'): int
    {
        $st = Database::pdo()->prepare(
            "INSERT INTO usuario (nombre, email, password_hash, rol_id)
             VALUES (:nombre, :email, :hash, (SELECT id FROM rol WHERE nombre = :rol)) RETURNING id"
        );
        $st->execute(['nombre' => $nombre, 'email' => $email, 'hash' => $hash, 'rol' => $rol]);
        return (int)$st->fetchColumn();
    }

    /** Nombres de los permisos del usuario, según su rol. Vacío si no existe o está dado de baja. */
    public static function permisosDe(int $id): array
    {
        $st = Database::pdo()->prepare(
            'SELECT p.nombre FROM usuario u
               JOIN rol_permiso rp ON rp.rol_id = u.rol_id
               JOIN permiso p ON p.id = rp.permiso_id
              WHERE u.id = :id AND u.activo
              ORDER BY p.nombre'
        );
        $st->execute(['id' => $id]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Listado para el admin. Filtros: q (nombre o email), rol, activo ('1' / '0'). */
    public static function listar(array $f, int $pagina): array
    {
        $where = [];
        $params = [];
        if (($f['q'] ?? '') !== '') {
            $where[] = '(u.nombre ILIKE :q1 OR u.email ILIKE :q2)';
            $q = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['q']) . '%'; // sin comodines del usuario
            $params['q1'] = $q;
            $params['q2'] = $q;
        }
        if (($f['rol'] ?? '') !== '') {
            $where[] = 'r.nombre = :rol';
            $params['rol'] = $f['rol'];
        }
        if (($f['activo'] ?? null) !== null) {
            $where[] = $f['activo'] ? 'u.activo' : 'NOT u.activo';
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::pdo();

        $st = $pdo->prepare("SELECT COUNT(*) FROM usuario u JOIN rol r ON r.id = u.rol_id $sqlWhere");
        $st->execute($params);
        $total = (int)$st->fetchColumn();

        $st = $pdo->prepare(
            'SELECT ' . self::CAMPOS . ", (SELECT COUNT(*) FROM vehiculo v WHERE v.usuario_id = u.id AND v.estado <> 'eliminado') AS publicaciones
               FROM usuario u JOIN rol r ON r.id = u.rol_id $sqlWhere
              ORDER BY u.id LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $st->bindValue(":$k", $v);
        }
        $st->bindValue(':lim', self::POR_PAGINA, PDO::PARAM_INT);
        $st->bindValue(':off', ($pagina - 1) * self::POR_PAGINA, PDO::PARAM_INT);
        $st->execute();
        return ['items' => $st->fetchAll(), 'total' => $total];
    }

    /** Cambia nombre, rol y/o activo (solo las claves que vengan en $cambios). */
    public static function actualizar(int $id, array $cambios): void
    {
        $set = [];
        $params = ['id' => $id];
        if (array_key_exists('nombre', $cambios)) {
            $set[] = 'nombre = :nombre';
            $params['nombre'] = $cambios['nombre'];
        }
        if (array_key_exists('rol', $cambios)) {
            $set[] = 'rol_id = (SELECT id FROM rol WHERE nombre = :rol)';
            $params['rol'] = $cambios['rol'];
        }
        if (array_key_exists('activo', $cambios)) {
            $set[] = 'activo = :activo';
            $params['activo'] = $cambios['activo'] ? 'true' : 'false'; // PDO mandaría false como '' y Postgres lo rechaza
        }
        if ($set) {
            Database::pdo()->prepare('UPDATE usuario SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
        }
    }

    /** Los roles con sus permisos (para el selector de rol del panel de usuarios). */
    public static function roles(): array
    {
        $filas = Database::pdo()->query(
            "SELECT r.nombre, r.descripcion, COALESCE(string_agg(p.nombre, ',' ORDER BY p.nombre), '') AS permisos
               FROM rol r
               LEFT JOIN rol_permiso rp ON rp.rol_id = r.id
               LEFT JOIN permiso p ON p.id = rp.permiso_id
              GROUP BY r.id ORDER BY r.id"
        )->fetchAll();
        return array_map(fn(array $r) => [
            'nombre'      => $r['nombre'],
            'descripcion' => $r['descripcion'],
            'permisos'    => $r['permisos'] === '' ? [] : explode(',', $r['permisos']),
        ], $filas);
    }

    public static function existeRol(string $nombre): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM rol WHERE nombre = :nombre');
        $st->execute(['nombre' => $nombre]);
        return $st->fetchColumn() !== false;
    }

    /** Datos que el front puede ver (nunca el hash). */
    public static function publico(array $u): array
    {
        return ['id' => (int)$u['id'], 'nombre' => $u['nombre'], 'email' => $u['email'], 'rol' => $u['rol'],
            'foto' => self::rutaFoto((int)$u['id'], $u['foto_v'] ?? null)];
    }

    /** Ruta de la foto de perfil (relativa a la API, el front le antepone API_URL), o null si no subió ninguna. */
    public static function rutaFoto(int $id, mixed $version): ?string
    {
        return $version === null ? null : "/api/usuarios/$id/foto?v=" . (int)$version;
    }

    /** La foto de perfil: mime y datos, o null si no tiene. */
    public static function foto(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT mime, datos FROM usuario_foto WHERE usuario_id = :id');
        $st->execute(['id' => $id]);
        $f = $st->fetch();
        if (!$f) {
            return null;
        }
        // BYTEA llega como stream desde pdo_pgsql
        return ['mime' => $f['mime'], 'datos' => is_resource($f['datos']) ? stream_get_contents($f['datos']) : $f['datos']];
    }

    /** Guarda (o reemplaza) la foto de perfil. $tmp y $mime ya validados con Foto::validarSubidas(). */
    public static function guardarFoto(int $id, string $tmp, string $mime): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO usuario_foto (usuario_id, mime, datos) VALUES (:id, :mime, :datos)
             ON CONFLICT (usuario_id) DO UPDATE SET mime = EXCLUDED.mime, datos = EXCLUDED.datos, updated_at = now()'
        );
        $st->bindValue(':id', $id, \PDO::PARAM_INT);
        $st->bindValue(':mime', $mime);
        $st->bindValue(':datos', (string)file_get_contents($tmp), \PDO::PARAM_LOB);
        $st->execute();
    }

    public static function borrarFoto(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM usuario_foto WHERE usuario_id = :id')->execute(['id' => $id]);
    }

    /** Lo que ve el admin en el panel de usuarios. */
    public static function paraAdmin(array $u): array
    {
        return self::publico($u) + [
            'activo'        => (bool)$u['activo'],
            'creado'        => (new \DateTimeImmutable($u['created_at']))->format(DATE_ATOM),
            'publicaciones' => isset($u['publicaciones']) ? (int)$u['publicaciones'] : null,
        ];
    }
}
