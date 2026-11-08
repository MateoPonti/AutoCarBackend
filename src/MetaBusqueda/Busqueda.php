<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

use AutoCar\Core\Request;

/**
 * Búsqueda guardada de un usuario: validación, criterio de coincidencia y guardado.
 * Las búsquedas de un usuario van juntas en el documento "busquedas/{usuario_id}" (son 20 como mucho), con el
 * próximo id y hasta cuándo vio los avisos de cada una. Solo lo escribe la API; los avisos los escribe el
 * importador en otro documento (ver Avisos), así nunca pisan lo mismo.
 */
final class Busqueda
{
    public const MAXIMO_POR_USUARIO = 20;

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
     * ¿Este auto entra en la búsqueda? Marca y modelo se comparan con la clave del modelo, que no distingue mayúsculas,
     * acentos ni guiones y entiende "VW" como "Volkswagen"; pero "Sandero" no incluye "Sandero Stepway".
     * Un auto sin precio (reservado) no queda afuera por el precio máximo, que está en pesos.
     *
     * @param array<string, mixed> $b
     * @param array<string, mixed> $auto
     */
    public static function coincide(array $b, array $auto): bool
    {
        if (Catalogo::claveDe($b['marca'], $b['modelo']) !== Catalogo::claveDe($auto['marca'], $auto['modelo'])) {
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

    /** @return list<array<string, mixed>> la más nueva primero */
    public static function delUsuario(Documentos $docs, int $usuarioId): array
    {
        return array_reverse(self::documento($docs, $usuarioId)['busquedas']);
    }

    /** Solo la devuelve a su dueño: la búsqueda de otro usuario se trata como inexistente. */
    public static function delUsuarioPorId(Documentos $docs, int $usuarioId, int $id): ?array
    {
        foreach (self::documento($docs, $usuarioId)['busquedas'] as $b) {
            if ($b['id'] === $id) {
                return $b;
            }
        }
        return null;
    }

    /** @return list<array<string, mixed>> las de todos los usuarios: el importador genera los avisos de cada una */
    public static function todas(Documentos $docs): array
    {
        $salida = [];
        foreach ($docs->listar('busquedas') as $doc) {
            foreach ($doc['busquedas'] ?? [] as $b) {
                $salida[] = $b + ['usuario_id' => (int)$doc['usuario_id']];
            }
        }
        return $salida;
    }

    public static function cantidad(Documentos $docs, int $usuarioId): int
    {
        return count(self::documento($docs, $usuarioId)['busquedas']);
    }

    /** @param array<string, mixed> $d datos ya validados */
    public static function crear(Documentos $docs, int $usuarioId, array $d): array
    {
        $doc = self::documento($docs, $usuarioId);
        $ahora = date(DATE_ATOM);
        $b = ['id' => $doc['siguiente']] + $d + ['created_at' => $ahora, 'updated_at' => $ahora];
        $doc['busquedas'][] = $b;
        $doc['siguiente']++;
        self::guardar($docs, $doc);
        return $b;
    }

    /** @param array<string, mixed> $d datos ya validados */
    public static function actualizar(Documentos $docs, int $usuarioId, int $id, array $d): ?array
    {
        $doc = self::documento($docs, $usuarioId);
        foreach ($doc['busquedas'] as $i => $b) {
            if ($b['id'] === $id) {
                $doc['busquedas'][$i] = array_merge($b, $d, ['updated_at' => date(DATE_ATOM)]);
                self::guardar($docs, $doc);
                return $doc['busquedas'][$i];
            }
        }
        return null;
    }

    /** Borra la búsqueda; sus avisos dejan de mostrarse porque la búsqueda ya no existe. */
    public static function eliminar(Documentos $docs, int $usuarioId, int $id): bool
    {
        $doc = self::documento($docs, $usuarioId);
        $quedan = array_values(array_filter($doc['busquedas'], static fn(array $b): bool => $b['id'] !== $id));
        if (count($quedan) === count($doc['busquedas'])) {
            return false;
        }
        $doc['busquedas'] = $quedan;
        unset($doc['vistos']["b$id"]);
        self::guardar($docs, $doc);
        return true;
    }

    /** @return array<int, float> id de búsqueda → momento hasta el que vio sus avisos */
    public static function vistos(Documentos $docs, int $usuarioId): array
    {
        $salida = [];
        foreach (self::documento($docs, $usuarioId)['vistos'] as $k => $momento) {
            $salida[(int)substr((string)$k, 1)] = (float)$momento;
        }
        return $salida;
    }

    public static function marcarVistos(Documentos $docs, int $usuarioId, int $id): void
    {
        $doc = self::documento($docs, $usuarioId);
        $doc['vistos']["b$id"] = microtime(true); // las claves llevan letra: Firestore no acepta mapas con claves 0, 1, 2...
        self::guardar($docs, $doc);
    }

    /** @return array{usuario_id: int, siguiente: int, busquedas: list<array<string, mixed>>, vistos: array<string, float>} */
    private static function documento(Documentos $docs, int $usuarioId): array
    {
        $doc = $docs->leer("busquedas/$usuarioId") ?? [];
        return [
            'usuario_id' => $usuarioId,
            'siguiente'  => (int)($doc['siguiente'] ?? 1),
            'busquedas'  => array_map([self::class, 'fila'], $doc['busquedas'] ?? []),
            'vistos'     => is_array($doc['vistos'] ?? null) ? $doc['vistos'] : [],
        ];
    }

    private static function guardar(Documentos $docs, array $doc): void
    {
        $docs->escribir(["busquedas/{$doc['usuario_id']}" => $doc]);
    }

    /** Tipos fijos: Firestore y JSON devuelven números que pueden venir como float o int. */
    private static function fila(array $f): array
    {
        $entero = static fn(mixed $v): ?int => $v !== null ? (int)$v : null;
        return [
            'id'          => (int)$f['id'],
            'marca'       => (string)$f['marca'],
            'modelo'      => (string)$f['modelo'],
            'anio_desde'  => $entero($f['anio_desde'] ?? null),
            'anio_hasta'  => $entero($f['anio_hasta'] ?? null),
            'precio_max'  => isset($f['precio_max']) ? (float)$f['precio_max'] : null,
            'km_max'      => $entero($f['km_max'] ?? null),
            'transmision' => $f['transmision'] ?? null,
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
