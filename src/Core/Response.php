<?php
declare(strict_types=1);

namespace AutoCar\Core;

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

    /** Archivo binario (fotos). $publico = se puede cachear; si no, queda privado del navegador que lo pidió. */
    public static function archivo(string $contenido, string $tipo, bool $publico): never
    {
        http_response_code(200);
        header('Content-Type: ' . $tipo);
        header('Content-Length: ' . strlen($contenido));
        header('Cache-Control: ' . ($publico ? 'public, max-age=86400' : 'private, no-store'));
        header("Content-Security-Policy: default-src 'none'; sandbox"); // si se abre directo, no ejecuta nada
        echo $contenido;
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
