<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Auth\Auth;
use AutoCar\Auth\Usuario;
use AutoCar\Core\Database;
use AutoCar\Core\Request;
use AutoCar\Core\Response;

/**
 * "Adherí tu taller" (ver SolicitudTaller). Del lado del usuario: ver su solicitud y mandarla.
 * Del lado del admin (permiso usuarios.editar, porque aprobar le cambia el rol al usuario): la cola y la respuesta.
 */
final class SolicitudTallerController
{
    /** GET /api/mi-solicitud-taller → {solicitud} o {solicitud: null} si nunca pidió sumarse */
    public function mia(): void
    {
        $solicitud = SolicitudTaller::delUsuario((int)Auth::id());
        Response::json(['solicitud' => $solicitud === null ? null : SolicitudTaller::paraApi($solicitud)]);
    }

    /**
     * POST /api/mi-solicitud-taller {nombre, cuit, direccion, localidad, telefono, horarios, precio_inspeccion,
     * condiciones: true} → 201 {solicitud}. Si ya tenía una rechazada, la vuelve a mandar.
     */
    public function enviar(): void
    {
        $id = (int)Auth::id();
        $usuario = Usuario::porId($id);
        // Solo un usuario común pide sumarse: un mecánico ya está en la red y un admin o moderador no inspecciona
        if ($usuario['rol'] !== 'usuario') {
            Response::error(409, 'rol', 'Tu cuenta ya tiene otro rol (' . $usuario['rol'] . '): no hace falta pedir la adhesión.');
        }

        [$datos, $errores] = SolicitudTaller::validar(Request::json());
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos del taller.', $errores);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $anterior = SolicitudTaller::delUsuario($id, true);
        if ($anterior !== null && $anterior['estado'] === 'pendiente') {
            self::fallar(409, 'pendiente', 'Ya mandaste una solicitud: está pendiente de revisión.');
        }
        if ($anterior !== null && $anterior['estado'] === 'aprobado') {
            self::fallar(409, 'aprobada', 'Tu taller ya está aprobado.');
        }
        SolicitudTaller::guardar($id, $datos);
        $pdo->commit();
        Response::json(['solicitud' => SolicitudTaller::paraApi(SolicitudTaller::delUsuario($id))], 201);
    }

    /** GET /api/admin/solicitudes-taller?estado=pendiente|aprobado|rechazado → {items} */
    public function index(): void
    {
        $estado = null;
        if (is_string($_GET['estado'] ?? null) && $_GET['estado'] !== '') {
            $estado = $_GET['estado'];
        }
        if ($estado !== null && !in_array($estado, SolicitudTaller::ESTADOS, true)) {
            Response::error(422, 'validacion', 'Revisá el filtro.', ['estado' => 'Tiene que ser pendiente, aprobado o rechazado.']);
        }
        $items = [];
        foreach (SolicitudTaller::listar($estado) as $fila) {
            $items[] = SolicitudTaller::paraApi($fila);
        }
        Response::json(['items' => $items]);
    }

    /** PATCH /api/admin/solicitudes-taller/{id} {aprobar: true} o {aprobar: false, motivo} → {solicitud} ({id} = usuario) */
    public function resolver(int $id): void
    {
        [$resolucion, $errores] = SolicitudTaller::validarResolucion(Request::json());
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá la respuesta.', $errores);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $solicitud = SolicitudTaller::delUsuario($id, true);
        if ($solicitud === null) {
            self::fallar(404, 'no_encontrado', 'La solicitud no existe.');
        }
        if ($solicitud['estado'] !== 'pendiente') {
            self::fallar(409, 'resuelta', 'Esta solicitud ya se resolvió.');
        }
        SolicitudTaller::resolver($id, $resolucion['aprobar'], $resolucion['motivo'], (int)Auth::id());
        $pdo->commit();
        Response::json(['solicitud' => SolicitudTaller::paraApi(SolicitudTaller::delUsuario($id))]);
    }

    private static function fallar(int $status, string $codigo, string $mensaje): never
    {
        $pdo = Database::pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        Response::error($status, $codigo, $mensaje);
    }
}
