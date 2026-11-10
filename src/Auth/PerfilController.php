<?php
declare(strict_types=1);

namespace AutoCar\Auth;

use AutoCar\Core\Request;
use AutoCar\Core\Response;
use AutoCar\Publicaciones\Foto;

/**
 * Mi perfil: el usuario logueado cambia su nombre y su foto. El email no se cambia (es con lo que ingresa).
 * La foto es opcional y no se pide al registrarse. Se guarda en la base (tabla usuario_foto, BYTEA), igual que
 * las fotos de las publicaciones, con los mismos controles: JPG, PNG o WebP de hasta 2 MB.
 */
final class PerfilController
{
    /** PUT /api/me {nombre} → {usuario} */
    public function actualizar(): void
    {
        $nombre = Request::texto(Request::json(), 'nombre');
        $len = mb_strlen($nombre);
        if ($len < 2 || $len > 80) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', ['nombre' => 'Ingresá tu nombre (entre 2 y 80 caracteres).']);
        }
        Usuario::actualizar((int)Auth::id(), ['nombre' => $nombre]);
        Response::json(['usuario' => self::yo()]);
    }

    /** POST /api/me/foto (multipart/form-data: foto) → {usuario}. Reemplaza la que tenía. */
    public function subirFoto(): void
    {
        [$fotos, $error] = Foto::validarSubidas($_FILES['foto'] ?? null);
        if ($error !== null || count($fotos) !== 1) {
            Response::error(422, 'validacion', 'Revisá la foto.', ['foto' => $error ?? 'Subí una sola foto.']);
        }
        Usuario::guardarFoto((int)Auth::id(), $fotos[0][0], $fotos[0][1]);
        Response::json(['usuario' => self::yo()]);
    }

    /** DELETE /api/me/foto → 204 */
    public function borrarFoto(): void
    {
        Usuario::borrarFoto((int)Auth::id());
        Response::noContent();
    }

    /** GET /api/usuarios/{id}/foto: pública (se ve en el detalle de sus publicaciones y en el chat). 404 si no tiene. */
    public function foto(int $id): void
    {
        $f = Usuario::foto($id);
        if ($f === null) {
            Response::error(404, 'no_encontrado', 'Ese usuario no tiene foto.');
        }
        Response::archivo($f['datos'], $f['mime'], true);
    }

    private static function yo(): array
    {
        return Usuario::publico((array)Usuario::porId((int)Auth::id())) + ['permisos' => Auth::permisos()];
    }
}
