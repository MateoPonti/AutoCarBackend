<?php
declare(strict_types=1);

namespace AutoCar\Admin;

use AutoCar\Catalogo\CatalogoController;
use AutoCar\Catalogo\Vehiculo;
use AutoCar\Core\Request;
use AutoCar\Core\Response;

/** ABM de vehículos. Todas las rutas llevan el guard 'admin' en routes.php (se verifica en el servidor). */
final class AdminVehiculoController
{
    public function index(): void
    {
        // El admin ve también los vendidos
        Response::json(CatalogoController::listado(CatalogoController::filtros($_GET), false, 15));
    }

    public function crear(): void
    {
        [$datos, $errores] = Vehiculo::validar(Request::json());
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        $id = Vehiculo::crear($datos);
        header('Location: /api/vehiculos/' . $id);
        Response::json(['vehiculo' => Vehiculo::paraApi(Vehiculo::find($id))], 201);
    }

    public function actualizar(int $id): void
    {
        $actual = Vehiculo::find($id);
        if ($actual === null) {
            Response::error(404, 'no_encontrado', 'El vehículo no existe.');
        }
        // El formulario del admin no manda los campos de la publicación (versión, localidad, canje...):
        // los que no vienen se completan con los actuales para no borrarlos al guardar.
        [$datos, $errores] = Vehiculo::validar(Request::json() + Vehiculo::paraApi($actual));
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        Vehiculo::actualizar($id, $datos);
        Response::json(['vehiculo' => Vehiculo::paraApi(Vehiculo::find($id))]);
    }

    public function eliminar(int $id): void
    {
        if (Vehiculo::find($id) === null) {
            Response::error(404, 'no_encontrado', 'El vehículo no existe.');
        }
        Vehiculo::eliminar($id);
        Response::noContent();
    }
}
