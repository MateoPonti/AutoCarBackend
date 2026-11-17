<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use DateTimeImmutable;

/**
 * Idea A: costo total del auto = precio del aviso + el presupuesto vigente más barato de cada arreglo del informe.
 * Funciones puras (se prueban sin base). Los presupuestos los piden y los guardan Presupuestos.
 */
final class CostoTotal
{
    /** Los ítems que se arreglan en un taller. Kilometraje e identificación se revisan, pero no tienen arreglo. */
    public const ARREGLABLES = ['carroceria', 'estructura', 'motor', 'transmision', 'frenos', 'suspension', 'neumaticos', 'electricidad'];

    /**
     * Los arreglos de un informe: los ítems arreglables observados o con falla, en el orden del checklist.
     * @param array<string, array{0: string, 1: ?string}> $items codigo => [resultado, nota] (Inspeccion::items)
     * @return array<string, array{resultado: string, nota: ?string}>
     */
    public static function arreglos(array $items): array
    {
        $lista = [];
        foreach (self::ARREGLABLES as $codigo) {
            if (isset($items[$codigo]) && $items[$codigo][0] !== 'ok') {
                $lista[$codigo] = ['resultado' => $items[$codigo][0], 'nota' => $items[$codigo][1]];
            }
        }
        return $lista;
    }

    /**
     * Elige a qué talleres pedirle presupuesto: nunca el que inspeccionó; primero los de su localidad (en el orden del
     * ranking), y si no alcanzan, los de otras. $cercanos y $todos vienen de Taller::red (ya ordenados).
     * @return list<int> ids de los mecánicos
     */
    public static function elegirTalleres(array $cercanos, array $todos, int $inspector, int $cantidad): array
    {
        $ids = [];
        foreach ([...$cercanos, ...$todos] as $t) {
            $id = (int)$t['usuario_id'];
            if ($id !== $inspector && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (count($ids) >= $cantidad) {
                break;
            }
        }
        return $ids;
    }

    /**
     * Arma el bloque "Costo total" del informe.
     * $presupuestos: filas con item_codigo, estado, monto, vigente_hasta (Presupuestos::deLaInspeccion).
     * Un ítem sin ningún presupuesto vigente deja el total "incompleto": se suma lo que hay y se avisa.
     */
    public static function calcular(array $arreglos, array $presupuestos, ?float $precioPublicado, DateTimeImmutable $ahora): array
    {
        $porItem = [];
        foreach ($presupuestos as $p) {
            $porItem[$p['item_codigo']][] = $p;
        }
        $items = [];
        $suma = 0.0;
        $completo = true;
        foreach ($arreglos as $codigo => $a) {
            $mejor = null;
            foreach ($porItem[$codigo] ?? [] as $p) {
                if (self::vigente($p, $ahora) && ($mejor === null || (float)$p['monto'] < $mejor)) {
                    $mejor = (float)$p['monto'];
                }
            }
            if ($mejor === null) {
                $completo = false;
            } else {
                $suma += $mejor;
            }
            $items[] = ['codigo' => $codigo, 'resultado' => $a['resultado'], 'mejor' => $mejor];
        }
        return [
            'items'            => $items,
            'arreglos'         => $suma,
            'precio_publicado' => $precioPublicado,
            'total'            => $precioPublicado === null ? null : $precioPublicado + $suma,
            'completo'         => $completo,
        ];
    }

    /** Un presupuesto cuenta si el taller lo respondió y todavía no venció. */
    public static function vigente(array $p, DateTimeImmutable $ahora): bool
    {
        return $p['estado'] === 'respondido' && $p['vigente_hasta'] !== null && new DateTimeImmutable($p['vigente_hasta']) >= $ahora;
    }
}
