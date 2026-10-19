<?php
declare(strict_types=1);

/**
 * Router mínimo. Cada ruta puede llevar un "guard" que se aplica en el servidor:
 *   'auth'  → hace falta sesión iniciada
 *   'admin' → hace falta rol administrador
 */
final class Router
{
    private array $routes = [];

    public function get(string $pattern, array $handler, ?string $guard = null): void
    {
        $this->add('GET', $pattern, $handler, $guard);
    }

    public function post(string $pattern, array $handler, ?string $guard = null): void
    {
        $this->add('POST', $pattern, $handler, $guard);
    }

    private function add(string $method, string $pattern, array $handler, ?string $guard): void
    {
        // {id} captura solo dígitos
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[0-9]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $guard];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = rtrim((string)(parse_url($uri, PHP_URL_PATH) ?: '/'), '/') ?: '/';
        $existe = false;

        foreach ($this->routes as [$m, $regex, $handler, $guard]) {
            if (!preg_match($regex, $path, $match)) {
                continue;
            }
            $existe = true;
            if ($m !== $method) {
                continue;
            }
            if ($guard === 'auth') {
                Auth::requireLogin();
            } elseif ($guard === 'admin') {
                Auth::requireAdmin();
            }
            $params = array_map('intval', array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY));
            [$clase, $accion] = $handler;
            (new $clase())->$accion(...$params);
            return;
        }

        View::error($existe ? 405 : 404);
    }
}
