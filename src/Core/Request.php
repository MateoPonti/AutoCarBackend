<?php
declare(strict_types=1);

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

    /** Lee un campo como texto. Si no es un valor simple (array, objeto) lo trata como vacío. */
    public static function texto(array $datos, string $campo): string
    {
        $v = $datos[$campo] ?? '';
        return is_scalar($v) ? trim((string)$v) : '';
    }

    public static function bearer(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($h === null && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $h = $v;
                }
            }
        }
        if (is_string($h) && preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
            return $m[1];
        }
        return null;
    }
}
