<?php
declare(strict_types=1);

namespace AutoCar\Core;

use AutoCar\Auth\Auth;

/**
 * Router mínimo. Cada ruta puede llevar un "guard" que se aplica en el servidor:
 *   'auth'               → hace falta la cookie de sesión válida
 *   'permiso:a.b|c.d'    → sesión válida y al menos uno de esos permisos (según el rol, consultado en la base)
 * En ambos casos, POST/PUT/PATCH/DELETE además exigen el header X-CSRF-Token.
 */
final class Router
{
    private array $routes = [];

    public function get(string $p, array $h, ?string $g = null): void    { $this->add('GET', $p, $h, $g); }
    public function post(string $p, array $h, ?string $g = null): void   { $this->add('POST', $p, $h, $g); }
    public function put(string $p, array $h, ?string $g = null): void    { $this->add('PUT', $p, $h, $g); }
    public function patch(string $p, array $h, ?string $g = null): void  { $this->add('PATCH', $p, $h, $g); }
    public function delete(string $p, array $h, ?string $g = null): void { $this->add('DELETE', $p, $h, $g); }

    private function add(string $method, string $pattern, array $handler, ?string $guard): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[0-9]+)', $pattern) . '$#'; // {id} = solo dígitos
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
            } elseif ($guard !== null && str_starts_with($guard, 'permiso:')) {
                Auth::requirePermiso(...explode('|', substr($guard, strlen('permiso:'))));
            }
            // Con sesión, todo lo que cambia datos exige el token CSRF (los GET no cambian nada)
            if ($guard !== null && $method !== 'GET') {
                Auth::requireCsrf();
            }
            $params = array_map('intval', array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY));
            [$clase, $accion] = $handler;
            (new $clase())->$accion(...$params);
            return;
        }

        if ($existe) {
            Response::error(405, 'metodo_no_permitido', 'Método no permitido para esta ruta.');
        }
        Response::error(404, 'no_encontrado', 'La ruta no existe.');
    }
}
