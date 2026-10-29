<?php
declare(strict_types=1);

namespace AutoCar\Publicaciones;

use AutoCar\Auth\Auth;
use AutoCar\Auth\Usuario;
use AutoCar\Catalogo\Vehiculo;
use AutoCar\Core\Database;
use AutoCar\Core\Request;
use AutoCar\Core\Response;
use Throwable;

/** Publicaciones de los usuarios. crear lleva el guard 'auth' en routes.php. */
final class PublicacionController
{
    /** POST /api/mis-vehiculos (multipart/form-data: campos del vehículo + fotos[]) */
    public function crear(): void
    {
        $in = Request::formulario();
        [$datos, $errores] = Vehiculo::validar($in, true); // estado = 'pendiente', lo fija el servidor
        [$fotos, $errorFotos] = Foto::validarSubidas($_FILES['fotos'] ?? null);
        if ($errorFotos !== null) {
            $errores['fotos'] = $errorFotos;
        }
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }

        // Vehículo y fotos en una transacción: o se guarda todo o nada
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $id = Vehiculo::crear($datos, (int)Auth::id());
            Foto::guardar($id, $fotos);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        header('Location: /api/vehiculos/' . $id);
        Response::json(['vehiculo' => Vehiculo::paraApi(Vehiculo::find($id)) + ['fotos' => Foto::rutasDe($id)]], 201);
    }

    /**
     * GET /api/fotos/{id}. Las fotos de publicaciones visibles son públicas; las de una pendiente o
     * rechazada sólo las ven su dueño y el admin (si no, 404, para no revelar que existen).
     */
    public function foto(int $id): void
    {
        $f = Foto::find($id);
        $publica = $f !== null && in_array($f['estado'], Vehiculo::VISIBLES, true);
        if ($f === null || (!$publica && !self::puedeVerPrivada((int)$f['usuario_id']))) {
            Response::error(404, 'no_encontrado', 'La foto no existe.');
        }
        Response::archivo($f['datos'], $f['mime'], $publica);
    }

    private static function puedeVerPrivada(int $duenioId): bool
    {
        $yo = Auth::id();
        return $yo !== null && ($yo === $duenioId || Usuario::rolDe($yo) === 'admin');
    }
}
