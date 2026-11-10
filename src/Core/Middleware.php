<?php
declare(strict_types=1);

namespace AutoCar\Core;

use AutoCar\Auth\Auth;

/**
 * Middleware: lo que se controla en el servidor ANTES de ejecutar el controlador de una ruta. Si el pedido no
 * pasa, responde 401 o 403 y corta: el controlador nunca corre. Cada ruta declara su guard en routes.php:
 *   null                 → ruta pública
 *   'auth'               → hace falta la cookie de sesión válida (401)
 *   'permiso:a.b|c.d'    → sesión válida y al menos uno de esos permisos, según el rol consultado en la base (403)
 * Con guard, todo lo que cambia datos (POST, PUT, PATCH, DELETE) exige además el header X-CSRF-Token (403).
 * Las reglas que dependen del dato (que la publicación sea tuya, no reportarte a vos mismo...) van en el controlador.
 */
final class Middleware
{
    public static function aplicar(?string $guard, string $metodo): void
    {
        if ($guard === 'auth') {
            Auth::requireLogin();
        } elseif ($guard !== null && str_starts_with($guard, 'permiso:')) {
            Auth::requirePermiso(...explode('|', substr($guard, strlen('permiso:'))));
        }
        // Con sesión, todo lo que cambia datos exige el token CSRF (los GET no cambian nada)
        if ($guard !== null && $metodo !== 'GET') {
            Auth::requireCsrf();
        }
    }
}
