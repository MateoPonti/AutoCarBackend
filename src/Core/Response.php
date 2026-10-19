<?php
declare(strict_types=1);

final class Response
{
    public static function json(mixed $datos, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    /** Formato de error único: {"error": "codigo", "mensaje": "...", "errores": {campo: mensaje}} */
    public static function error(int $status, string $codigo, string $mensaje, array $errores = []): never
    {
        $cuerpo = ['error' => $codigo, 'mensaje' => $mensaje];
        if ($errores) {
            $cuerpo['errores'] = $errores;
        }
        self::json($cuerpo, $status);
    }
}
