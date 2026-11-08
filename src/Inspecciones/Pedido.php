<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

/**
 * Reglas del pedido de una inspección (POST /api/inspecciones, B4). Funciones puras: no tocan la base.
 * El comprador pega el link del auto (de cualquier sitio), elige el mecánico y puede completar datos del auto.
 */
final class Pedido
{
    public const MAX_TITULO    = 150;
    public const MAX_LOCALIDAD = 100;
    public const MAX_KM        = 2000000;
    public const MAX_PRECIO    = 99999999999; // NUMERIC(13,2)

    /**
     * Valida el cuerpo del pedido.
     * @param string[] $hostsAutoCar hosts del front, para reconocer los links de AutoCar
     * @return array{0: array, 1: array<string, string>} [datos limpios con fuente y vehiculo_id, errores por campo]
     */
    public static function validar(array $in, array $hostsAutoCar, int $anioActual): array
    {
        $errores = [];
        $url = is_string($in['url'] ?? null) ? trim($in['url']) : '';
        $fuente = Fuente::detectar($url, $hostsAutoCar);
        if ($fuente === null) {
            $errores['url'] = 'Pegá el link completo del aviso (empieza con https://).';
        }

        $mecanico = filter_var($in['mecanico_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($mecanico === false) {
            $errores['mecanico_id'] = 'Elegí un mecánico de la lista.';
        }

        $texto = static fn(string $k): ?string => is_scalar($in[$k] ?? null) && trim((string)$in[$k]) !== ''
            ? trim((string)$in[$k]) : null;
        $titulo = $texto('titulo');
        if ($titulo !== null && mb_strlen($titulo) > self::MAX_TITULO) {
            $errores['titulo'] = 'Puede tener hasta ' . self::MAX_TITULO . ' caracteres.';
        }
        $localidad = $texto('localidad');
        if ($localidad !== null && mb_strlen($localidad) > self::MAX_LOCALIDAD) {
            $errores['localidad'] = 'Puede tener hasta ' . self::MAX_LOCALIDAD . ' caracteres.';
        }

        // Números opcionales: vacío o null = no lo sabe
        $numero = static function (string $k, int|float $min, int|float $max, string $error) use ($in, &$errores): int|float|null {
            $v = $in[$k] ?? null;
            if ($v === null || $v === '') {
                return null;
            }
            if (is_string($v) && is_numeric($v)) {
                $v = $v + 0;
            }
            if ((!is_int($v) && !is_float($v)) || $v < $min || $v > $max) {
                $errores[$k] = $error;
                return null;
            }
            return $v;
        };
        $anio = $numero('anio', 1950, $anioActual + 1, "Tiene que estar entre 1950 y " . ($anioActual + 1) . '.');
        if ($anio !== null && !is_int($anio)) {
            $errores['anio'] = 'Ingresá el año sin decimales.';
        }
        $km = $numero('kilometraje', 0, self::MAX_KM, 'Ingresá los km (entre 0 y 2.000.000).');
        $precio = $numero('precio_publicado', 1, self::MAX_PRECIO, 'Ingresá el precio del aviso en pesos.');

        return [[
            'url'              => $url,
            'fuente'           => $fuente['fuente'] ?? null,
            'vehiculo_id'      => $fuente['vehiculo_id'] ?? null,
            'mecanico_id'      => $mecanico === false ? null : $mecanico,
            'titulo'           => $titulo,
            'anio'             => is_int($anio) ? $anio : null,
            'kilometraje'      => $km === null ? null : (int)$km,
            'precio_publicado' => $precio === null ? null : (float)$precio,
            'localidad'        => $localidad,
        ], $errores];
    }

    /**
     * Quién no puede pedir o hacer la inspección, para que nadie tenga interés en el resultado.
     * @param ?int $duenoVehiculo quien publicó el auto, si es de AutoCar
     * @return array{0: string, 1: string}|null [código, mensaje] del 409, o null si se puede
     */
    public static function conflicto(int $comprador, int $mecanico, ?int $duenoVehiculo): ?array
    {
        if ($comprador === $mecanico) {
            return ['mecanico_propio', 'No podés pedirte una inspección a vos mismo.'];
        }
        if ($duenoVehiculo !== null && $duenoVehiculo === $comprador) {
            return ['vehiculo_propio', 'No podés pedir la inspección de un auto que publicaste vos.'];
        }
        if ($duenoVehiculo !== null && $duenoVehiculo === $mecanico) {
            return ['mecanico_vendedor', 'Ese mecánico publicó el auto: elegí otro.'];
        }
        return null;
    }
}
