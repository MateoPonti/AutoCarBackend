<?php
declare(strict_types=1);

namespace AutoCar\Recolectores\Comun;

/**
 * Saca objetos y listas JSON que vienen metidos dentro del HTML (el estado que la página manda al navegador).
 * Es más estable que leer el HTML: las clases CSS cambian seguido, los nombres de los datos casi nunca.
 */
final class JsonEmbebido
{
    /**
     * Todos los valores JSON que empiezan donde aparece $marca (el primer carácter de $marca tiene que ser { o [).
     *
     * @return list<array<mixed>>
     */
    public static function todos(string $texto, string $marca): array
    {
        $salida = [];
        $desde = 0;
        while (($pos = strpos($texto, $marca, $desde)) !== false) {
            $crudo = self::balanceado($texto, $pos);
            $desde = $pos + strlen($marca);
            if ($crudo === null) {
                continue;
            }
            $valor = json_decode($crudo, true);
            if (is_array($valor)) {
                $salida[] = $valor;
            }
        }
        return $salida;
    }

    /** La lista JSON que sigue a $clave (por ejemplo '"values":'), o null si no está. */
    public static function listaDespuesDe(string $texto, string $clave, int $desde = 0): ?array
    {
        $pos = strpos($texto, $clave, $desde);
        if ($pos === false) {
            return null;
        }
        $inicio = strpos($texto, '[', $pos + strlen($clave));
        $crudo = $inicio === false ? null : self::balanceado($texto, $inicio);
        $valor = $crudo === null ? null : json_decode($crudo, true);
        return is_array($valor) ? $valor : null;
    }

    /** Recorta desde $inicio hasta la llave o corchete que lo cierra, sin contar los que están dentro de strings. */
    public static function balanceado(string $texto, int $inicio): ?string
    {
        $abre = $texto[$inicio] ?? '';
        if ($abre !== '{' && $abre !== '[') {
            return null;
        }
        $nivel = 0;
        $enString = false;
        $largo = strlen($texto);
        for ($i = $inicio; $i < $largo; $i++) {
            $c = $texto[$i];
            if ($enString) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '"') {
                    $enString = false;
                }
                continue;
            }
            if ($c === '"') {
                $enString = true;
            } elseif ($c === '{' || $c === '[') {
                $nivel++;
            } elseif ($c === '}' || $c === ']') {
                $nivel--;
                if ($nivel === 0) {
                    return substr($texto, $inicio, $i - $inicio + 1);
                }
            }
        }
        return null;
    }
}
