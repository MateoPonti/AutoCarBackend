<?php
declare(strict_types=1);

namespace AutoCar\Admin;

use AutoCar\Auth\Auth;
use AutoCar\Auth\Usuario;
use AutoCar\Catalogo\CatalogoController;
use AutoCar\Catalogo\Vehiculo;
use AutoCar\Core\Request;
use AutoCar\Core\Response;
use AutoCar\Publicaciones\Foto;

/**
 * Moderación y baja de vehículos. Cada ruta pide su permiso en routes.php (se verifica en el servidor):
 * vehiculos.moderar (moderador) para revisar publicaciones y vehiculos.gestionar (admin) además para la baja.
 * Acá no se crean ni se editan vehículos: toda publicación tiene un vendedor, entra por POST /api/mis-vehiculos
 * y la corrige solo su dueño (si algo está mal, el moderador la rechaza con un motivo).
 */
final class AdminVehiculoController
{
    /** Moderación: a qué estados puede pasar el moderador una publicación según su estado actual. */
    private const TRANSICIONES = [
        'pendiente'  => ['disponible', 'rechazado'], // aprobar o rechazar
        'disponible' => ['rechazado'],               // dar de baja una publicación ya aprobada
        'rechazado'  => ['disponible'],              // revertir un rechazo
    ];

    /** GET /api/admin/vehiculos?estado=pendiente → la cola de moderación. Sin estado: todos menos los eliminados. */
    public function index(): void
    {
        Response::json(CatalogoController::listado(CatalogoController::filtros($_GET), false, 15));
    }

    /** GET /api/admin/vehiculos/{id}: detalle para moderar (con todas las fotos y quién lo publicó). */
    public function detalle(int $id): void
    {
        $v = self::existente($id);
        $duenio = $v['usuario_id'] !== null ? Usuario::porId((int)$v['usuario_id']) : null;
        Response::json(['vehiculo' => Vehiculo::paraApi($v) + [
            'fotos'      => Foto::rutasDe($id),
            'publicador' => $duenio !== null ? Usuario::publico($duenio) : null,
        ]]);
    }

    /**
     * PATCH /api/admin/vehiculos/{id}/estado
     *   {"estado": "disponible"}                         → aprobar
     *   {"estado": "rechazado", "motivo": "Fotos que..."} → rechazar (el motivo es obligatorio y lo ve el dueño)
     */
    public function moderar(int $id): void
    {
        $actual = self::existente($id);
        $in     = Request::json();
        $nuevo  = Request::texto($in, 'estado');
        $motivo = Request::texto($in, 'motivo');

        // Conflicto de interés: nadie aprueba ni rechaza su propia publicación
        if ((int)$actual['usuario_id'] === (int)Auth::id()) {
            Response::error(409, 'publicacion_propia', 'No podés moderar tu propia publicación: la tiene que revisar otro moderador.');
        }
        $errores = [];
        if (!in_array($nuevo, self::TRANSICIONES[$actual['estado']] ?? [], true)) {
            $errores['estado'] = "Una publicación en estado '{$actual['estado']}' no puede pasar a '{$nuevo}'.";
        }
        $largo = mb_strlen($motivo);
        if ($nuevo === 'rechazado' && ($largo < 5 || $largo > 300)) {
            $errores['motivo'] = 'Escribí el motivo del rechazo (entre 5 y 300 caracteres).';
        }
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        // Publicar por partes: no se aprueba hasta que el vendedor la complete (rechazarla sí se puede)
        $faltan = Vehiculo::faltan($actual);
        if ($nuevo === 'disponible' && $faltan) {
            Response::error(409, 'incompleta', 'Todavía no se puede aprobar: le falta ' . implode(', ', $faltan) . '.');
        }

        Vehiculo::cambiarEstado($id, $nuevo, $motivo);
        Response::json(['vehiculo' => Vehiculo::paraApi(Vehiculo::find($id))]);
    }

    /** Baja lógica (estado 'eliminado'): la fila queda para el historial y para los chats que la referencian. */
    public function eliminar(int $id): void
    {
        self::existente($id);
        Vehiculo::cambiarEstado($id, 'eliminado');
        Response::noContent();
    }

    /** Vehículo que no esté eliminado, o 404. */
    private static function existente(int $id): array
    {
        $v = Vehiculo::find($id);
        if ($v === null || $v['estado'] === 'eliminado') {
            Response::error(404, 'no_encontrado', 'El vehículo no existe.');
        }
        return $v;
    }
}
