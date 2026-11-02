<?php
declare(strict_types=1);

namespace AutoCar\Admin;

use AutoCar\Auth\Auth;
use AutoCar\Auth\Usuario;
use AutoCar\Core\Request;
use AutoCar\Core\Response;

/**
 * Gestión de usuarios (rol admin). Las rutas piden usuarios.ver o usuarios.editar en routes.php.
 * La baja es lógica (activo = false): la fila queda porque la referencian publicaciones y chats.
 */
final class AdminUsuarioController
{
    /** GET /api/admin/roles: los roles con sus permisos (para el selector de rol). */
    public function roles(): void
    {
        Response::json(['items' => Usuario::roles()]);
    }

    /** GET /api/admin/usuarios?q=&rol=&activo=1|0&page= */
    public function index(): void
    {
        $texto = static fn(string $k, int $max): string => is_string($_GET[$k] ?? null) ? mb_substr(trim($_GET[$k]), 0, $max) : '';
        $activo = $_GET['activo'] ?? null;
        $filtros = [
            'q'      => $texto('q', 80),
            'rol'    => $texto('rol', 20),
            'activo' => $activo === '1' ? true : ($activo === '0' ? false : null),
        ];
        $pagina = max(1, (int)($_GET['page'] ?? 1));
        $r = Usuario::listar($filtros, $pagina);
        Response::json([
            'items'      => array_map([Usuario::class, 'paraAdmin'], $r['items']),
            'total'      => $r['total'],
            'pagina'     => $pagina,
            'por_pagina' => Usuario::POR_PAGINA,
            'paginas'    => max(1, (int)ceil($r['total'] / Usuario::POR_PAGINA)),
        ]);
    }

    /** GET /api/admin/usuarios/{id} */
    public function detalle(int $id): void
    {
        Response::json(['usuario' => Usuario::paraAdmin(self::existente($id))]);
    }

    /**
     * PATCH /api/admin/usuarios/{id}  {"nombre"?, "rol"?, "activo"?} (solo lo que se quiere cambiar).
     * Un admin no puede cambiarse su propio rol ni darse de baja: se quedaría sin acceso al panel.
     */
    public function actualizar(int $id): void
    {
        self::existente($id);
        $in = Request::json();
        $cambios = [];
        $errores = [];

        if (array_key_exists('nombre', $in)) {
            $nombre = Request::texto($in, 'nombre');
            $largo = mb_strlen($nombre);
            if ($largo < 2 || $largo > 80) {
                $errores['nombre'] = 'El nombre debe tener entre 2 y 80 caracteres.';
            }
            $cambios['nombre'] = $nombre;
        }
        if (array_key_exists('rol', $in)) {
            $rol = Request::texto($in, 'rol');
            if (!Usuario::existeRol($rol)) {
                $errores['rol'] = 'Ese rol no existe.';
            }
            $cambios['rol'] = $rol;
        }
        if (array_key_exists('activo', $in)) {
            if (!is_bool($in['activo'])) {
                $errores['activo'] = 'Tiene que ser true o false.';
            }
            $cambios['activo'] = $in['activo'] === true;
        }
        if (!$cambios) {
            $errores['general'] = 'No se mandó ningún cambio (nombre, rol o activo).';
        }
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        if ($id === Auth::id() && (isset($cambios['rol']) || isset($cambios['activo']))) {
            Response::error(409, 'sobre_si_mismo', 'No podés cambiar tu propio rol ni darte de baja.');
        }

        Usuario::actualizar($id, $cambios);
        Response::json(['usuario' => Usuario::paraAdmin(Usuario::porId($id))]);
    }

    /** DELETE /api/admin/usuarios/{id}: baja lógica. No puede ingresar y su sesión deja de valer en el próximo pedido. */
    public function eliminar(int $id): void
    {
        self::existente($id);
        if ($id === Auth::id()) {
            Response::error(409, 'sobre_si_mismo', 'No podés darte de baja a vos mismo.');
        }
        Usuario::actualizar($id, ['activo' => false]);
        Response::noContent();
    }

    private static function existente(int $id): array
    {
        $u = Usuario::porId($id);
        if ($u === null) {
            Response::error(404, 'no_encontrado', 'El usuario no existe.');
        }
        return $u;
    }
}
