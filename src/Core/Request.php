<?php
declare(strict_types=1);

namespace AutoCar\Core;

final class Request
{
    /** Cuerpo JSON como array. Si no es JSON válido responde 400. */
    public static function json(): array
    {
        $crudo = file_get_contents('php://input', false, null, 0, 65537);
        if ($crudo === false || strlen($crudo) > 65536) {
            Response::error(413, 'cuerpo_muy_grande', 'El cuerpo del pedido es demasiado grande.');
        }
        $datos = json_decode($crudo === '' ? 'null' : $crudo, true);
        if (!is_array($datos)) {
            Response::error(400, 'json_invalido', 'El cuerpo debe ser un objeto JSON válido.');
        }
        return $datos;
    }

    /**
     * Campos de un formulario multipart/form-data (el que lleva archivos). Los archivos quedan en $_FILES.
     * Si el pedido supera post_max_size, PHP descarta todo y $_POST llega vacío: se responde 413.
     */
    public static function formulario(): array
    {
        $tipo = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (!str_starts_with($tipo, 'multipart/form-data')) {
            Response::error(415, 'tipo_no_soportado', 'El pedido debe enviarse como multipart/form-data.');
        }
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > self::bytes((string)ini_get('post_max_size'))) {
            Response::error(413, 'cuerpo_muy_grande', 'Las fotos pesan demasiado en total. Probá con menos o más livianas.');
        }
        // La base es UTF-8: un texto en otra codificación haría fallar el INSERT con un 500
        foreach ($_POST as $v) {
            if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
                Response::error(400, 'codificacion_invalida', 'El formulario debe enviarse en UTF-8.');
            }
        }
        return $_POST;
    }

    /** "12M" → 12582912 (formato de las directivas de php.ini). */
    private static function bytes(string $valor): int
    {
        $n = (int)$valor;
        return match (strtoupper(substr(trim($valor), -1))) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }

    /**
     * Errores por campo para los que llegan como arreglo u objeto ({"precio": [1]}) en vez de un valor simple.
     * Sin esto, texto() los lee como vacíos y una edición por partes los tomaría como "sin cargar" y borraría lo guardado.
     * @param string[] $campos
     * @return array<string, string>
     */
    public static function noSimples(array $datos, array $campos): array
    {
        $errores = [];
        foreach ($campos as $campo) {
            $v = $datos[$campo] ?? null;
            if ($v !== null && !is_scalar($v)) {
                $errores[$campo] = 'Este dato tiene que ser un texto o un número.';
            }
        }
        return $errores;
    }

    /** Lee un campo como texto. Si no es un valor simple (array, objeto) lo trata como vacío. */
    public static function texto(array $datos, string $campo): string
    {
        $v = $datos[$campo] ?? '';
        return is_scalar($v) ? trim((string)$v) : '';
    }
}
