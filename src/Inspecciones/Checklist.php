<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

/**
 * Ítems que revisa el mecánico. Los códigos son los mismos en el back y en el front.
 * Mientras no firma, el mecánico puede guardar el checklist a medias; para firmar tienen que estar los 10.
 */
final class Checklist
{
    public const ITEMS = [
        'carroceria'     => 'Carrocería: pintura y golpes',
        'estructura'     => 'Estructura: chasis, largueros y rastros de choque',
        'motor'          => 'Motor: pérdidas y ruidos',
        'transmision'    => 'Transmisión: caja y embrague',
        'frenos'         => 'Frenos',
        'suspension'     => 'Suspensión: tren delantero y amortiguadores',
        'neumaticos'     => 'Neumáticos',
        'electricidad'   => 'Electricidad: tablero, luces y testigos',
        'kilometraje'    => 'Kilometraje: desgaste coherente con los km',
        'identificacion' => 'Identificación: motor y chasis coinciden con la cédula',
    ];
    public const RESULTADOS   = ['ok', 'observado', 'falla'];
    public const CONCLUSIONES = ['recomendable', 'con_reparos', 'no_recomendable'];
    public const MAX_NOTA     = 300;
    public const MAX_RESUMEN  = 1000;

    /**
     * Valida los ítems que manda el mecánico: [{codigo, resultado, nota}, ...].
     * Si el resultado no es "ok", la nota es obligatoria: el mecánico firma lo que vio.
     * @return array{0: array, 1: ?string} [ítems por código [codigo => [resultado, nota]], error o null]
     */
    public static function validarItems(mixed $items, bool $completo): array
    {
        if (!is_array($items)) {
            return [[], 'Mandá los ítems del checklist.'];
        }
        $validos = [];
        foreach ($items as $item) {
            $codigo = is_array($item) ? trim((string)($item['codigo'] ?? '')) : '';
            if (!isset(self::ITEMS[$codigo])) {
                return [[], 'Hay un ítem del checklist que no existe.'];
            }
            if (isset($validos[$codigo])) {
                return [[], "El ítem \"$codigo\" está repetido."];
            }
            $resultado = (string)($item['resultado'] ?? '');
            if (!in_array($resultado, self::RESULTADOS, true)) {
                return [[], "El resultado de \"$codigo\" tiene que ser ok, observado o falla."];
            }
            $nota = trim((string)($item['nota'] ?? ''));
            if (mb_strlen($nota) > self::MAX_NOTA) {
                return [[], "La nota de \"$codigo\" puede tener hasta " . self::MAX_NOTA . ' caracteres.'];
            }
            if ($resultado !== 'ok' && $nota === '') {
                return [[], "Explicá qué encontraste en \"$codigo\"."];
            }
            $validos[$codigo] = [$resultado, $nota === '' ? null : $nota];
        }
        if ($completo && count($validos) < count(self::ITEMS)) {
            $faltan = array_keys(array_diff_key(self::ITEMS, $validos));
            return [[], 'Para firmar faltan estos ítems: ' . implode(', ', $faltan) . '.'];
        }
        return [$validos, null];
    }

    /** Lo que exige la firma además de los ítems. Devuelve el error o null. */
    public static function validarConclusion(mixed $conclusion, mixed $resumen): ?string
    {
        if (!in_array($conclusion, self::CONCLUSIONES, true)) {
            return 'Elegí una conclusión: recomendable, con_reparos o no_recomendable.';
        }
        if (mb_strlen(trim((string)$resumen)) > self::MAX_RESUMEN) {
            return 'El resumen puede tener hasta ' . self::MAX_RESUMEN . ' caracteres.';
        }
        return null;
    }
}
