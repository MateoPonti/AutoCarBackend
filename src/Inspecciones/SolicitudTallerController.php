<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Auth\Auth;
use AutoCar\Auth\Usuario;
use AutoCar\Core\Database;
use AutoCar\Core\Request;
use AutoCar\Core\Response;
use PDOException;

/**
 * "Adherí tu taller" (ver SolicitudTaller). Del lado del taller: mandar la solicitud (crea su cuenta).
 * Del lado del admin (permiso usuarios.editar, porque aprobar le cambia el rol al usuario): la cola y la respuesta.
 */
final class SolicitudTallerController
{
    private const EMAIL_USADO = 'Ese email ya tiene una cuenta en AutoCar. Para el taller usá otro email (por ejemplo, el del taller).';

    /**
     * POST /api/solicitudes-taller (sin sesión) {responsable, email, password, nombre, cuit, direccion, localidad,
     * telefono, horarios, precio_inspeccion, condiciones: true} → 201 {solicitud}.
     * La cuenta del taller es aparte de la cuenta personal: se crea acá con rol mecanico y no puede ingresar hasta
     * que un admin aprueba la solicitud. Un email es una sola cuenta, así que el email tiene que ser nuevo.
     * Si la solicitud de ese email fue rechazada, se vuelve a mandar con la misma contraseña.
     */
    public function enviar(): void
    {
        $in = Request::json();
        $responsable = Request::texto($in, 'responsable');
        $email = mb_strtolower(Request::texto($in, 'email'));
        $pass = is_string($in['password'] ?? null) ? $in['password'] : '';

        $errores = Usuario::validarCuenta($responsable, $email, $pass, $pass);
        // En este formulario "nombre" es el del taller: el de la persona se llama "responsable"
        if (isset($errores['nombre'])) {
            $errores['responsable'] = $errores['nombre'];
            unset($errores['nombre']);
        }
        [$datos, $erroresTaller] = SolicitudTaller::validar($in);
        $errores = $erroresTaller + $errores;
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos del taller.', $errores);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $cuenta = Usuario::porEmail($email);
        if ($cuenta === null) {
            try {
                $id = Usuario::crear($responsable, $email, password_hash($pass, PASSWORD_DEFAULT), 'mecanico');
            } catch (PDOException $e) {
                if ($e->getCode() !== '23505') { // 23505 = email duplicado (alguien lo registró recién)
                    throw $e;
                }
                self::fallar(422, 'validacion', 'Revisá los datos del taller.', ['email' => self::EMAIL_USADO]);
            }
        } else {
            $id = (int)$cuenta['id'];
            $anterior = SolicitudTaller::delUsuario($id, true);
            if ($anterior !== null && $anterior['estado'] === 'pendiente') {
                self::fallar(409, 'pendiente', 'Ya hay una solicitud con ese email: está en revisión.');
            }
            $rechazada = $anterior !== null && $anterior['estado'] === 'rechazado' && $cuenta['rol'] === 'mecanico';
            if (!$rechazada) {
                self::fallar(422, 'validacion', 'Revisá los datos del taller.', ['email' => self::EMAIL_USADO]);
            }
            if (!password_verify($pass, $cuenta['password_hash'])) {
                self::fallar(422, 'validacion', 'Revisá los datos del taller.',
                    ['password' => 'Para volver a mandar la solicitud, usá la misma contraseña que la primera vez.']);
            }
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

    private static function fallar(int $status, string $codigo, string $mensaje, array $errores = []): never
    {
        $pdo = Database::pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        Response::error($status, $codigo, $mensaje, $errores);
    }
}
