<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

use AutoCar\Core\Database;

final class Localidad
{
    /** La localidad sin provincia se usa para los avisos bonaerenses. Conserva otras provincias explícitas. */
    public static function nombre(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }
        $partes = [];
        foreach (explode(',', $texto) as $parte) {
            $parte = trim((string)preg_replace('/\s+/u', ' ', $parte));
            if ($parte === '') {
                continue;
            }
            $clave = self::clave($parte);
            if ($partes && preg_match('/^(?:buenos aires|bs\.?\s*(?:as\.?)?|pba)$/', $clave)) {
                continue;
            }
            $partes[$clave] ??= match ($clave) {
                'lujan' => 'Luján',
                'mercedes' => 'Mercedes',
                'caba' => 'CABA',
                default => $parte,
            };
        }
        return implode(', ', $partes);
    }

    /** Nombres del selector y etiquetas originales que identifican a cada zona en los avisos. */
    public static function disponibles(): array
    {
        $filas = Database::pdo()->query("SELECT DISTINCT localidad FROM vehiculo
            WHERE estado = 'disponible' AND trim(localidad) <> '' ORDER BY localidad");
        $zonas = [];
        foreach ($filas as $fila) {
            $nombre = self::nombre($fila['localidad']);
            if ($nombre === '') continue;
            $clave = self::clave($nombre);
            $zonas[$clave] ??= ['nombre' => $nombre, 'variantes' => []];
            $zonas[$clave]['variantes'][] = $fila['localidad'];
        }
        return $zonas;
    }

    /** Condición sobre la columna localidad, compartida por catálogo y colecciones. */
    public static function condicion(string $zona, array &$params): string
    {
        $clave = self::clave(self::nombre($zona));
        $variantes = self::disponibles()[$clave]['variantes'] ?? [];
        if (!$variantes) return 'FALSE';
        $marcadores = [];
        foreach ($variantes as $i => $valor) {
            $params['zona' . $i] = $valor;
            $marcadores[] = ':zona' . $i;
        }
        return 'localidad IN (' . implode(', ', $marcadores) . ')';
    }

    public static function clave(string $nombre): string
    {
        return strtr(mb_strtolower($nombre), ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u']);
    }
}
