<?php
declare(strict_types=1);

namespace AutoCar\Publicaciones;

use AutoCar\Auth\Auth;
use AutoCar\Catalogo\Vehiculo;
use AutoCar\Core\Database;
use AutoCar\Core\Request;
use AutoCar\Core\Response;
use Throwable;

/**
 * Publicaciones de los usuarios. Las rutas /api/mis-vehiculos llevan el guard 'auth' en routes.php
 * y sólo operan sobre vehículos del usuario logueado (los de otro dan 404).
 */
final class PublicacionController
{
    /** Cambios de estado que puede hacer el dueño (el resto los decide el admin). */
    private const TRANSICIONES = ['disponible' => ['vendido']];

    /** GET /api/mis-vehiculos */
    public function index(): void
    {
        $items = Vehiculo::delUsuario((int)Auth::id());
        Response::json(['items' => array_map([Vehiculo::class, 'paraApi'], $items)]);
    }

    /** GET /api/mis-vehiculos/{id}: una publicación propia con todas sus fotos (para seguir completándola). */
    public function detalle(int $id): void
    {
        $v = self::propio($id);
        Response::json(['vehiculo' => Vehiculo::paraApi($v) + ['fotos' => Foto::rutasDe($id)]]);
    }

    /**
     * POST /api/mis-vehiculos (multipart/form-data: campos del vehículo + fotos[]).
     * Se publica por partes: con marca, modelo y año ya se crea; lo demás y las fotos se pueden mandar ahora o
     * después (PUT y POST .../fotos). Nace 'pendiente' (lo fija el servidor) y el moderador recién la puede
     * aprobar cuando está completa (ver Vehiculo::faltan).
     */
    public function crear(): void
    {
        $in = Request::formulario();
        [$datos, $errores] = Vehiculo::validarPorPartes($in);
        [$fotos, $errorFotos] = Foto::validarSubidas($_FILES['fotos'] ?? null, 0, false);
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
     * PUT /api/mis-vehiculos/{id} (JSON, sin fotos). Cualquier edición vuelve la publicación a 'pendiente'
     * para que el admin la revise de nuevo. Los campos que no vienen se mantienen: así se completa por partes.
     */
    public function actualizar(int $id): void
    {
        $actual = self::propio($id);
        if ($actual['estado'] === 'vendido') {
            Response::error(409, 'no_editable', 'Un vehículo vendido no se puede editar.');
        }
        [$datos, $errores] = Vehiculo::validarPorPartes(Request::json() + Vehiculo::paraApi($actual));
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        Vehiculo::actualizar($id, $datos);
        Response::json(['vehiculo' => Vehiculo::paraApi(Vehiculo::find($id)) + ['fotos' => Foto::rutasDe($id)]]);
    }

    /**
     * POST /api/mis-vehiculos/{id}/fotos (multipart/form-data: fotos[]). Agrega fotos a las que ya tiene (hasta
     * Foto::MAX entre todas). Como cualquier cambio, la publicación vuelve a 'pendiente'. → {vehiculo} con fotos
     */
    public function agregarFotos(int $id): void
    {
        $actual = self::propio($id);
        if ($actual['estado'] === 'vendido') {
            Response::error(409, 'no_editable', 'Un vehículo vendido no se puede editar.');
        }
        [$fotos, $error] = Foto::validarSubidas($_FILES['fotos'] ?? null, Foto::cantidad($id));
        if ($error !== null) {
            Response::error(422, 'validacion', 'Revisá las fotos.', ['fotos' => $error]);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Foto::guardar($id, $fotos);
            Vehiculo::cambiarEstado($id, 'pendiente');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Response::json(['vehiculo' => Vehiculo::paraApi(Vehiculo::find($id)) + ['fotos' => Foto::rutasDe($id)]], 201);
    }

    /** DELETE /api/mis-vehiculos/{id}/fotos/{foto}. La publicación vuelve a 'pendiente'. → {vehiculo} con fotos */
    public function eliminarFoto(int $id, int $foto): void
    {
        $actual = self::propio($id);
        if ($actual['estado'] === 'vendido') {
            Response::error(409, 'no_editable', 'Un vehículo vendido no se puede editar.');
        }
        if (!Foto::eliminar($id, $foto)) {
            Response::error(404, 'no_encontrado', 'La foto no existe.');
        }
        Vehiculo::cambiarEstado($id, 'pendiente');
        Response::json(['vehiculo' => Vehiculo::paraApi(Vehiculo::find($id)) + ['fotos' => Foto::rutasDe($id)]]);
    }

    /** PATCH /api/mis-vehiculos/{id}/estado  {"estado": "vendido"} */
    public function cambiarEstado(int $id): void
    {
        $actual = self::propio($id);
        $nuevo = Request::texto(Request::json(), 'estado');
        if (!in_array($nuevo, self::TRANSICIONES[$actual['estado']] ?? [], true)) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.',
                ['estado' => 'Sólo podés marcar como vendida una publicación disponible.']);
        }
        Vehiculo::cambiarEstado($id, $nuevo);
        Response::json(['vehiculo' => Vehiculo::paraApi(Vehiculo::find($id))]);
    }

    /** DELETE /api/mis-vehiculos/{id}. Baja lógica: pasa a 'eliminado' y deja de verse en todos lados. */
    public function eliminar(int $id): void
    {
        self::propio($id);
        Vehiculo::cambiarEstado($id, 'eliminado');
        Response::noContent();
    }

    /** Vehículo del usuario logueado, o 404 si no existe, es de otro o está eliminado. */
    private static function propio(int $id): array
    {
        $v = Vehiculo::deUsuario($id, (int)Auth::id());
        if ($v === null) {
            Response::error(404, 'no_encontrado', 'La publicación no existe.');
        }
        return $v;
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
        return $yo !== null && ($yo === $duenioId || Auth::tiene('vehiculos.moderar') || Auth::tiene('vehiculos.gestionar'));
    }
}
