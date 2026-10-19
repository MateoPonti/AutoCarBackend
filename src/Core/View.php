<?php
declare(strict_types=1);

final class View
{
    /** Renderiza una vista. Con $layout=true la envuelve en src/Views/layout.php. */
    public static function render(string $archivo, array $datos = [], bool $layout = true): void
    {
        $contenido = self::capturar($archivo, $datos);
        if ($layout) {
            $contenido = self::capturar(SRC_PATH . '/Views/layout.php', [
                'contenido' => $contenido,
                'titulo'    => $datos['titulo'] ?? 'AutoCar',
            ]);
        }
        echo $contenido;
    }

    /** Páginas de error 403, 404, 405 y 500. La 500 no usa layout para no fallar dos veces. */
    public static function error(int $codigo, ?string $mensaje = null): never
    {
        http_response_code($codigo);
        $vista = match ($codigo) {
            403, 419 => '403',
            404, 405 => '404',
            default  => '500',
        };
        try {
            self::render(SRC_PATH . "/Views/errors/$vista.php",
                ['titulo' => 'Error', 'mensaje' => $mensaje], $codigo !== 500);
        } catch (Throwable $e) {
            echo 'Ocurrió un error.';
        }
        exit;
    }

    private static function capturar(string $__archivo, array $__datos): string
    {
        extract($__datos, EXTR_SKIP);
        ob_start();
        try {
            require $__archivo;
        } catch (Throwable $t) {
            ob_end_clean();
            throw $t;
        }
        return (string)ob_get_clean();
    }
}
