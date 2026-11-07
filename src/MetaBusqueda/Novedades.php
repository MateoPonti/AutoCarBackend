<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

/** Compara dos recolecciones completas de una misma plataforma y dice qué cambió. */
final class Novedades
{
    public const NUEVO = 'nuevo';
    public const BAJO = 'bajo';
    public const SUBIO = 'subio';
    public const RESERVADO = 'reservado';
    public const RETIRADO = 'retirado';

    /** Los que generan un aviso. Una suba de precio solo se informa en el reporte. */
    public const CON_AVISO = [self::NUEVO, self::BAJO, self::RESERVADO, self::RETIRADO];

    /**
     * Un auto que vuelve a aparecer no es "nuevo": por eso se usa es_nuevo (nunca estuvo en una recolección completa anterior).
     * Una suba o baja solo se compara si los dos precios existen y están en la misma moneda.
     *
     * @param list<array<string, mixed>> $actual
     * @param list<array<string, mixed>> $anterior
     * @return array{cambios: list<array<string, mixed>>, sin_cambios: list<array<string, mixed>>}
     */
    public static function comparar(array $actual, array $anterior): array
    {
        $antes = array_column($anterior, null, 'id');
        $ahora = array_column($actual, null, 'id');
        $cambios = [];
        $sinCambios = [];

        foreach ($actual as $auto) {
            $previo = $antes[$auto['id']] ?? null;
            if ($previo === null) {
                if ($auto['es_nuevo']) {
                    $cambios[] = self::cambio(self::NUEVO, $auto, null, $auto['precio']);
                }
                continue;
            }
            if ($auto['reservado'] && !$previo['reservado']) {
                $cambios[] = self::cambio(self::RESERVADO, $auto, $previo['precio'], $auto['precio']);
                continue;
            }
            if ($auto['precio'] === null || $previo['precio'] === null || $auto['moneda'] !== $previo['moneda']) {
                continue;
            }
            if ($auto['precio'] < $previo['precio']) {
                $cambios[] = self::cambio(self::BAJO, $auto, $previo['precio'], $auto['precio']);
            } elseif ($auto['precio'] > $previo['precio']) {
                $cambios[] = self::cambio(self::SUBIO, $auto, $previo['precio'], $auto['precio']);
            } else {
                $sinCambios[] = $auto;
            }
        }

        foreach ($anterior as $previo) {
            if (!isset($ahora[$previo['id']])) {
                $cambios[] = self::cambio(self::RETIRADO, $previo, $previo['precio'], null);
            }
        }

        return ['cambios' => $cambios, 'sin_cambios' => $sinCambios];
    }

    private static function cambio(string $tipo, array $auto, ?float $antes, ?float $ahora): array
    {
        return ['tipo' => $tipo, 'auto' => $auto, 'precio_anterior' => $antes, 'precio_actual' => $ahora];
    }
}
