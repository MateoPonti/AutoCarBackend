<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

/**
 * Reglas del reclamo (B8). Funciones puras: no tocan la base.
 * El comprador reclama una sola vez, dentro de los días de reclamo de su inspección (Estados::enPlazoDeReclamo).
 * Quien resuelve decide si procede; si procede, la compensación llega hasta el tope de su inspección: tope_veces
 * por el precio (Precio::topeCompensacion). Los dos quedan guardados en la inspección al pedirla.
 */
final class Reclamo
{
    public const ESTADOS          = ['abierto', 'procede', 'no_procede'];
    public const MIN_DESCRIPCION  = 10;
    public const MAX_DESCRIPCION  = 1000;
    public const MIN_RESPUESTA    = 3;
    public const MAX_RESPUESTA    = 1000;

    /**
     * POST /api/inspecciones/{id}/reclamo {descripcion, item_codigo?}
     * @return array{0: array{descripcion: string, item_codigo: ?string}, 1: array<string, string>}
     */
    public static function validarPedido(array $in): array
    {
        $errores = [];
        $descripcion = is_string($in['descripcion'] ?? null) ? trim($in['descripcion']) : '';
        $largo = mb_strlen($descripcion);
        if ($largo < self::MIN_DESCRIPCION || $largo > self::MAX_DESCRIPCION) {
            $errores['descripcion'] = 'Contá qué pasó (entre ' . self::MIN_DESCRIPCION . ' y ' . self::MAX_DESCRIPCION . ' caracteres).';
        }
        $item = $in['item_codigo'] ?? null;
        if ($item === '') {
            $item = null;
        }
        if ($item !== null && !(is_string($item) && isset(Checklist::ITEMS[$item]))) {
            $errores['item_codigo'] = 'Elegí un ítem del checklist.';
            $item = null;
        }
        return [['descripcion' => $descripcion, 'item_codigo' => $item], $errores];
    }

    /**
     * PATCH /api/admin/reclamos/{id} {procede: bool, compensacion?, respuesta}
     * Si procede, la compensación es obligatoria y llega hasta el tope. Si no procede, no lleva compensación.
     * @return array{0: array{estado: string, compensacion: ?float, respuesta: string}, 1: array<string, string>}
     */
    public static function validarResolucion(array $in, float $precioInspeccion, float $topeVeces): array
    {
        $errores = [];
        $procede = $in['procede'] ?? null;
        if (!is_bool($procede)) {
            $errores['procede'] = 'Indicá si el reclamo procede (true o false).';
        }
        $respuesta = is_string($in['respuesta'] ?? null) ? trim($in['respuesta']) : '';
        $largo = mb_strlen($respuesta);
        if ($largo < self::MIN_RESPUESTA || $largo > self::MAX_RESPUESTA) {
            $errores['respuesta'] = 'Escribí la respuesta para el comprador (entre ' . self::MIN_RESPUESTA . ' y ' . self::MAX_RESPUESTA . ' caracteres).';
        }

        $compensacion = null;
        if ($procede === true) {
            $c = $in['compensacion'] ?? null;
            if (is_string($c) && is_numeric($c)) {
                $c = $c + 0;
            }
            $tope = Precio::topeCompensacion($precioInspeccion, $topeVeces);
            if (!is_int($c) && !is_float($c)) {
                $errores['compensacion'] = 'Ingresá la compensación en pesos (puede ser 0).';
            } elseif ($c < 0 || $c > $tope) {
                $errores['compensacion'] = 'Tiene que estar entre $ 0 y $ ' . number_format($tope, 0, ',', '.')
                    . ' (' . $topeVeces . ' veces el precio).';
            } else {
                $compensacion = (float)$c;
            }
        }
        return [[
            'estado'       => $procede === true ? 'procede' : 'no_procede',
            'compensacion' => $compensacion,
            'respuesta'    => $respuesta,
        ], $errores];
    }
}
