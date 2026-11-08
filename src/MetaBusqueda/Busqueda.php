<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

use AutoCar\Core\Database;
use AutoCar\Core\Request;

/** Búsqueda guardada de un usuario: validación, criterio de coincidencia y acceso a la tabla. */
final class Busqueda
{
    public const MAXIMO_POR_USUARIO = 20;
    private const COLUMNAS = 'id, usuario_id, marca, modelo, anio_desde, anio_hasta, precio_max, km_max, transmision, created_at, updated_at';

    /**
     * @param array<string, mixed> $d cuerpo del pedido
     * @return array{0: array<string, mixed>, 1: array<string, string>} datos limpios y errores por campo
     */
    public static function validar(array $d): array
    {
        $errores = [];
        $marca = Request::texto($d, 'marca');
        $modelo = Request::texto($d, 'modelo');
        if ($marca === '' || mb_strlen($marca) > 50) {
            $errores['marca'] = 'La marca es obligatoria (hasta 50 caracteres).';
        }
        if ($modelo === '' || mb_strlen($modelo) > 60) {
            $errores['modelo'] = 'El modelo es obligatorio (hasta 60 caracteres).';
        }

        $desde = self::enteroOpcional($d, 'anio_desde', 1950, 2100, 'El año debe estar entre 1950 y 2100.', $errores);
        $hasta = self::enteroOpcional($d, 'anio_hasta', 1950, 2100, 'El año debe estar entre 1950 y 2100.', $errores);
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            $errores['anio_hasta'] = 'El año hasta no puede ser menor que el año desde.';
        }
        $precio = self::enteroOpcional($d, 'precio_max', 1, 999999999999, 'El precio máximo debe ser un número positivo.', $errores);
        $km = self::enteroOpcional($d, 'km_max', 0, 5000000, 'Los kilómetros máximos deben ser un número válido.', $errores);

        $transmision = Request::texto($d, 'transmision');
        if ($transmision !== '' && !in_array($transmision, ['manual', 'automatica'], true)) {
            $errores['transmision'] = 'La transmisión debe ser manual o automatica.';
        }

        return [[
            'marca'       => $marca,
            'modelo'      => $modelo,
            'anio_desde'  => $desde,
            'anio_hasta'  => $hasta,
            'precio_max'  => $precio,
            'km_max'      => $km,
            'transmision' => $transmision !== '' ? $transmision : null,
        ], $errores];
    }

    /** @param array<string, string> $errores */
    private static function enteroOpcional(array $d, string $campo, int $min, int $max, string $mensaje, array &$errores): ?int
    {
        $v = $d[$campo] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        $n = filter_var($v, FILTER_VALIDATE_INT);
        if ($n === false || is_bool($v) || $n < $min || $n > $max) {
            $errores[$campo] = $mensaje;
            return null;
        }
        return $n;
    }

    /**
     * ¿Este auto entra en la búsqueda? Marca y modelo son exactos ("Sandero" no incluye "Sandero Stepway").
     * Un auto sin precio (reservado) no queda afuera por el precio máximo, que está en pesos.
     *
     * @param array<string, mixed> $b
     * @param array<string, mixed> $auto
     */
    public static function coincide(array $b, array $auto): bool
    {
        if (mb_strtolower($b['marca']) !== mb_strtolower($auto['marca'])
            || mb_strtolower($b['modelo']) !== mb_strtolower($auto['modelo'])) {
            return false;
        }
        if (($b['anio_desde'] !== null && $auto['anio'] < $b['anio_desde'])
            || ($b['anio_hasta'] !== null && $auto['anio'] > $b['anio_hasta'])
            || ($b['km_max'] !== null && $auto['km'] > $b['km_max'])
            || ($b['transmision'] !== null && $auto['transmision'] !== $b['transmision'])) {
            return false;
        }
        if ($b['precio_max'] !== null && $auto['precio'] !== null) {
            return $auto['moneda'] === 'ARS' && $auto['precio'] <= $b['precio_max'];
        }
        return true;
    }

    /** @return list<array<string, mixed>> */
    public static function delUsuario(int $usuarioId): array
    {
        $st = Database::pdo()->prepare('SELECT ' . self::COLUMNAS . ' FROM busqueda_guardada WHERE usuario_id = :u ORDER BY id DESC');
        $st->execute(['u' => $usuarioId]);
        return array_map([self::class, 'fila'], $st->fetchAll());
    }

    /** Solo la devuelve a su dueño: la búsqueda de otro usuario se trata como inexistente. */
    public static function delUsuarioPorId(int $usuarioId, int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT ' . self::COLUMNAS . ' FROM busqueda_guardada WHERE id = :id AND usuario_id = :u');
        $st->execute(['id' => $id, 'u' => $usuarioId]);
        $f = $st->fetch();
        return $f ? self::fila($f) : null;
    }

    /** @return list<array<string, mixed>> todas las búsquedas: el importador genera los avisos de cada una */
    public static function todas(\PDO $pdo): array
    {
        return array_map([self::class, 'fila'], $pdo->query('SELECT ' . self::COLUMNAS . ' FROM busqueda_guardada')->fetchAll(\PDO::FETCH_ASSOC));
    }

    public static function cantidad(int $usuarioId): int
    {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM busqueda_guardada WHERE usuario_id = :u');
        $st->execute(['u' => $usuarioId]);
        return (int)$st->fetchColumn();
    }

    /** @param array<string, mixed> $d datos ya validados */
    public static function crear(int $usuarioId, array $d): array
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO busqueda_guardada (usuario_id, marca, modelo, anio_desde, anio_hasta, precio_max, km_max, transmision)
             VALUES (:u, :marca, :modelo, :anio_desde, :anio_hasta, :precio_max, :km_max, :transmision)
             RETURNING ' . self::COLUMNAS
        );
        $st->execute(['u' => $usuarioId] + $d);
        return self::fila($st->fetch());
    }

    /** @param array<string, mixed> $d datos ya validados */
    public static function actualizar(int $usuarioId, int $id, array $d): ?array
    {
        $st = Database::pdo()->prepare(
            'UPDATE busqueda_guardada SET marca = :marca, modelo = :modelo, anio_desde = :anio_desde, anio_hasta = :anio_hasta,
                    precio_max = :precio_max, km_max = :km_max, transmision = :transmision, updated_at = now()
              WHERE id = :id AND usuario_id = :u
              RETURNING ' . self::COLUMNAS
        );
        $st->execute(['id' => $id, 'u' => $usuarioId] + $d);
        $f = $st->fetch();
        return $f ? self::fila($f) : null;
    }

    public static function eliminar(int $usuarioId, int $id): bool
    {
        $st = Database::pdo()->prepare('DELETE FROM busqueda_guardada WHERE id = :id AND usuario_id = :u');
        $st->execute(['id' => $id, 'u' => $usuarioId]);
        return $st->rowCount() > 0;
    }

    /** @param array<string, mixed> $f */
    private static function fila(array $f): array
    {
        return [
            'id'          => (int)$f['id'],
            'usuario_id'  => (int)$f['usuario_id'],
            'marca'       => $f['marca'],
            'modelo'      => $f['modelo'],
            'anio_desde'  => $f['anio_desde'] !== null ? (int)$f['anio_desde'] : null,
            'anio_hasta'  => $f['anio_hasta'] !== null ? (int)$f['anio_hasta'] : null,
            'precio_max'  => $f['precio_max'] !== null ? (float)$f['precio_max'] : null,
            'km_max'      => $f['km_max'] !== null ? (int)$f['km_max'] : null,
            'transmision' => $f['transmision'],
            'created_at'  => $f['created_at'],
            'updated_at'  => $f['updated_at'],
        ];
    }

    /** Lo que se devuelve por la API (el dueño ya está implícito). */
    public static function paraApi(array $b): array
    {
        unset($b['usuario_id']);
        return $b;
    }
}
