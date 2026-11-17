<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

/**
 * Idea B, fallas típicas por modelo: reglas puras (se prueban sin base). El acceso a la base está en FallasTipicas.
 * Una falla viene de internet (la carga el admin con la fuente) o de los mecánicos (la propone uno); el voto de los
 * mecánicos cambia su estado; y las inspecciones firmadas miden qué % de los autos de ese modelo la tuvo.
 */
final class FallaTipica
{
    public const ORIGENES     = ['internet', 'mecanicos'];
    public const MAX_TITULO   = 120;
    public const MAX_DETALLE  = 500;

    /**
     * Estado según los votos: con $necesarios votos de un lado (y más que del otro) queda confirmada o descartada;
     * si no, sin confirmar.
     */
    public static function estado(int $confirman, int $descartan, int $necesarios): string
    {
        if ($confirman >= $necesarios && $confirman > $descartan) {
            return 'confirmada';
        }
        if ($descartan >= $necesarios && $descartan > $confirman) {
            return 'descartada';
        }
        return 'sin_confirmar';
    }

    /**
     * Qué % de las inspecciones firmadas del modelo tuvo cada ítem observado o con falla. Con menos de $minimo
     * inspecciones no se calcula (null): con pocas, un solo auto cambia todo.
     * @param array<string, int> $conItem codigo => cuántas inspecciones lo tuvieron mal
     * @return array<string, ?float> codigo => porcentaje (con un decimal) o null
     */
    public static function porcentajes(array $conItem, int $inspecciones, int $minimo): array
    {
        $lista = [];
        foreach (array_keys(Checklist::ITEMS) as $codigo) {
            $lista[$codigo] = $inspecciones >= $minimo && $inspecciones > 0
                ? round(100 * ($conItem[$codigo] ?? 0) / $inspecciones, 1) : null;
        }
        return $lista;
    }

    /**
     * Las fallas "medidas": los ítems que superan el umbral en las inspecciones del modelo, del más frecuente al menos.
     * @param array<string, ?float> $porcentajes de porcentajes()
     * @return list<array{item_codigo: string, porcentaje: float}>
     */
    public static function medidas(array $porcentajes, float $umbral): array
    {
        $lista = [];
        foreach ($porcentajes as $codigo => $p) {
            if ($p !== null && $p >= $umbral) {
                $lista[] = ['item_codigo' => $codigo, 'porcentaje' => $p];
            }
        }
        usort($lista, static fn(array $a, array $b): int => $b['porcentaje'] <=> $a['porcentaje']);
        return $lista;
    }

    /**
     * Valida una falla nueva: {item_codigo, titulo, descripcion?, fuente_url?}. La fuente es obligatoria si es de internet.
     * @return array{0: array, 1: array<string, string>}
     */
    public static function validar(array $in, string $origen): array
    {
        $errores = [];
        $item = $in['item_codigo'] ?? null;
        if (!is_string($item) || !isset(Checklist::ITEMS[$item])) {
            $errores['item_codigo'] = 'Elegí el ítem del checklist donde se ve.';
            $item = null;
        }
        $titulo = is_string($in['titulo'] ?? null) ? trim($in['titulo']) : '';
        if (mb_strlen($titulo) < 5 || mb_strlen($titulo) > self::MAX_TITULO) {
            $errores['titulo'] = 'Contá la falla en pocas palabras (entre 5 y ' . self::MAX_TITULO . ' caracteres).';
        }
        $descripcion = is_string($in['descripcion'] ?? null) ? trim($in['descripcion']) : '';
        if (mb_strlen($descripcion) > self::MAX_DETALLE) {
            $errores['descripcion'] = 'Puede tener hasta ' . self::MAX_DETALLE . ' caracteres.';
        }
        $fuente = is_string($in['fuente_url'] ?? null) ? trim($in['fuente_url']) : '';
        if ($fuente !== '' && (mb_strlen($fuente) > 500 || !preg_match('#^https?://[^\s]+$#i', $fuente))) {
            $errores['fuente_url'] = 'Pegá el link completo de la fuente (empieza con https://).';
        } elseif ($fuente === '' && $origen === 'internet') {
            $errores['fuente_url'] = 'Una falla de internet necesita la fuente.';
        }
        return [[
            'item_codigo' => $item,
            'titulo'      => $titulo,
            'descripcion' => $descripcion === '' ? null : $descripcion,
            'fuente_url'  => $fuente === '' ? null : $fuente,
            'origen'      => $origen,
        ], $errores];
    }
}
