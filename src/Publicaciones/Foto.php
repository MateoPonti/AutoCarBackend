<?php
declare(strict_types=1);

namespace AutoCar\Publicaciones;

use AutoCar\Core\Database;
use finfo;
use PDO;

/**
 * Fotos de las publicaciones, guardadas en la tabla vehiculo_foto (BYTEA).
 * El tipo se comprueba leyendo el contenido del archivo, no con lo que declara el navegador.
 */
final class Foto
{
    public const MAX       = 5;
    public const MAX_BYTES = 2 * 1024 * 1024; // 2 MB (igual que upload_max_filesize en el Dockerfile)
    public const TIPOS     = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Valida el campo "fotos[]" de $_FILES.
     * $yaTiene: fotos que la publicación ya tiene (al agregar después), para no pasar de MAX entre todas.
     * $obligatorias = false: se puede no mandar ninguna (publicar por partes: las fotos se suben después).
     * @return array{0: array, 1: ?string} [fotos [[ruta temporal, mime], ...], mensaje de error o null]
     */
    public static function validarSubidas(mixed $campo, int $yaTiene = 0, bool $obligatorias = true): array
    {
        $archivos = self::normalizar($campo);
        if (!$archivos) {
            return [[], $obligatorias ? 'Agregá al menos una foto.' : null];
        }
        if ($yaTiene + count($archivos) > self::MAX) {
            $mensaje = 'Podés subir hasta ' . self::MAX . ' fotos.';
            if ($yaTiene > 0) {
                $mensaje .= " Ya tiene $yaTiene.";
            }
            return [[], $mensaje];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $fotos = [];
        foreach ($archivos as $a) {
            if (in_array($a['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $a['size'] > self::MAX_BYTES) {
                return [[], 'Cada foto puede pesar hasta 2 MB.'];
            }
            if ($a['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($a['tmp_name'])) {
                return [[], 'No se pudo subir una de las fotos. Probá de nuevo.'];
            }
            $mime = $finfo->file($a['tmp_name']);
            if (!in_array($mime, self::TIPOS, true) || getimagesize($a['tmp_name']) === false) {
                return [[], 'Sólo se aceptan fotos JPG, PNG o WebP.'];
            }
            $fotos[] = [$a['tmp_name'], $mime];
        }
        return [$fotos, null];
    }

    /** $_FILES['fotos'] viene como {name: [...], tmp_name: [...], ...}: se pasa a una lista de archivos. */
    private static function normalizar(mixed $campo): array
    {
        if (!is_array($campo) || !isset($campo['tmp_name'], $campo['error'], $campo['size'])) {
            return [];
        }
        $lista = [];
        foreach ((array)$campo['tmp_name'] as $i => $tmp) {
            $error = (int)(((array)$campo['error'])[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) { // input de archivo enviado vacío
                continue;
            }
            $lista[] = ['tmp_name' => (string)$tmp, 'error' => $error, 'size' => (int)(((array)$campo['size'])[$i] ?? 0)];
        }
        return $lista;
    }

    /** Se llama dentro de una transacción. Las fotos nuevas van después de las que ya tenía. */
    public static function guardar(int $vehiculoId, array $fotos): void
    {
        $st = Database::pdo()->prepare('SELECT COALESCE(MAX(orden) + 1, 0) FROM vehiculo_foto WHERE vehiculo_id = :id');
        $st->execute(['id' => $vehiculoId]);
        $siguiente = (int)$st->fetchColumn();

        $st = Database::pdo()->prepare(
            'INSERT INTO vehiculo_foto (vehiculo_id, orden, mime, datos) VALUES (:vehiculo_id, :orden, :mime, :datos)'
        );
        foreach ($fotos as $i => [$ruta, $mime]) {
            $st->bindValue(':vehiculo_id', $vehiculoId, PDO::PARAM_INT);
            $st->bindValue(':orden', $siguiente + $i, PDO::PARAM_INT);
            $st->bindValue(':mime', $mime);
            $st->bindValue(':datos', file_get_contents($ruta), PDO::PARAM_LOB); // LOB = binario (BYTEA)
            $st->execute();
        }
    }

    /** Rutas de las fotos de un vehículo, en orden (relativas a la API). */
    public static function rutasDe(int $vehiculoId): array
    {
        $st = Database::pdo()->prepare('SELECT id FROM vehiculo_foto WHERE vehiculo_id = :id ORDER BY orden, id');
        $st->execute(['id' => $vehiculoId]);
        return array_map(static fn($id): string => '/api/fotos/' . (int)$id, $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function cantidad(int $vehiculoId): int
    {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM vehiculo_foto WHERE vehiculo_id = :id');
        $st->execute(['id' => $vehiculoId]);
        return (int)$st->fetchColumn();
    }

    /** Borra una foto de ese vehículo. → false si la foto no es de ese vehículo. */
    public static function eliminar(int $vehiculoId, int $fotoId): bool
    {
        $st = Database::pdo()->prepare('DELETE FROM vehiculo_foto WHERE id = :foto AND vehiculo_id = :vehiculo');
        $st->execute(['foto' => $fotoId, 'vehiculo' => $vehiculoId]);
        return $st->rowCount() === 1;
    }

    /** Foto con el estado y el dueño de su vehículo (para decidir quién puede verla). */
    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT f.mime, f.datos, v.estado, v.usuario_id
               FROM vehiculo_foto f JOIN vehiculo v ON v.id = f.vehiculo_id
              WHERE f.id = :id'
        );
        $st->execute(['id' => $id]);
        $f = $st->fetch();
        if (!$f) {
            return null;
        }
        if (is_resource($f['datos'])) { // pdo_pgsql devuelve BYTEA como stream
            $f['datos'] = stream_get_contents($f['datos']);
        }
        return $f;
    }
}
