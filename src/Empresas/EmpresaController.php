<?php
declare(strict_types=1);

namespace AutoCar\Empresas;

use AutoCar\Auth\Auth;
use AutoCar\Auth\Usuario;
use AutoCar\Catalogo\CatalogoController;
use AutoCar\Catalogo\Vehiculo;
use AutoCar\Core\Database;
use AutoCar\Core\Request;
use AutoCar\Core\Response;
use AutoCar\Estadisticas\Panel;
use AutoCar\Inspecciones\SolicitudTaller;
use DateTimeImmutable;
use PDOException;

/** "Sumá tu concesionaria" (ver Empresa): la solicitud, la cola del admin, la vitrina pública y el panel de la empresa. */
final class EmpresaController
{
    private const EMAIL_USADO = 'Ese email ya tiene una cuenta en AutoCar. Para la empresa usá otro email (por ejemplo, el de la concesionaria).';

    /**
     * POST /api/solicitudes-empresa (sin sesión) {responsable, email, password, razon_social, cuit, localidad, telefono,
     * sitio_web?, condiciones: true} → 201 {solicitud}. La cuenta de la empresa es aparte de la personal: nace con rol
     * empresa y no puede ingresar hasta que un admin la aprueba. Si fue rechazada, se vuelve a mandar con la misma contraseña.
     */
    public function enviar(): void
    {
        $in = Request::json();
        $responsable = Request::texto($in, 'responsable');
        $email = mb_strtolower(Request::texto($in, 'email'));
        $pass = is_string($in['password'] ?? null) ? $in['password'] : '';
        $errores = Usuario::validarCuenta($responsable, $email, $pass, $pass);
        if (isset($errores['nombre'])) {
            $errores['responsable'] = $errores['nombre'];
            unset($errores['nombre']);
        }
        [$datos, $erroresEmpresa] = Empresa::validar($in);
        $errores = $erroresEmpresa + $errores;
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos de la empresa.', $errores);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $cuenta = Usuario::porEmail($email);
        if ($cuenta === null) {
            try {
                $id = Usuario::crear($responsable, $email, password_hash($pass, PASSWORD_DEFAULT), 'empresa', true);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
                self::fallar(422, 'validacion', 'Revisá los datos de la empresa.', ['email' => self::EMAIL_USADO]);
            }
        } else {
            $id = (int)$cuenta['id'];
            $anterior = Empresa::delUsuario($id, true);
            if ($anterior !== null && $anterior['estado'] === 'pendiente') {
                self::fallar(409, 'pendiente', 'Ya hay una solicitud con ese email: está en revisión.');
            }
            if (!($anterior !== null && $anterior['estado'] === 'rechazado' && $cuenta['rol'] === 'empresa')) {
                self::fallar(422, 'validacion', 'Revisá los datos de la empresa.', ['email' => self::EMAIL_USADO]);
            }
            if (!password_verify($pass, $cuenta['password_hash'])) {
                self::fallar(422, 'validacion', 'Revisá los datos de la empresa.',
                    ['password' => 'Para volver a mandar la solicitud, usá la misma contraseña que la primera vez.']);
            }
        }
        Empresa::guardar($id, $datos);
        $pdo->commit();
        Response::json(['solicitud' => Empresa::paraApi(Empresa::delUsuario($id))], 201);
    }

    /** GET /api/admin/solicitudes-empresa?estado=pendiente|aprobado|rechazado → {items} */
    public function index(): void
    {
        $estado = is_string($_GET['estado'] ?? null) && $_GET['estado'] !== '' ? $_GET['estado'] : null;
        if ($estado !== null && !in_array($estado, Empresa::ESTADOS, true)) {
            Response::error(422, 'validacion', 'Revisá el filtro.', ['estado' => 'Tiene que ser pendiente, aprobado o rechazado.']);
        }
        Response::json(['items' => array_map([Empresa::class, 'paraApi'], Empresa::listar($estado))]);
    }

    /** PATCH /api/admin/solicitudes-empresa/{id} {aprobar: true} o {aprobar: false, motivo} → {solicitud} ({id} = usuario) */
    public function resolver(int $id): void
    {
        // Las mismas reglas que la respuesta a un taller (motivo de 5 a 300 caracteres al rechazar)
        [$resolucion, $errores] = SolicitudTaller::validarResolucion(Request::json());
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá la respuesta.', $errores);
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $solicitud = Empresa::delUsuario($id, true);
        if ($solicitud === null) {
            self::fallar(404, 'no_encontrado', 'La solicitud no existe.');
        }
        if ($solicitud['estado'] !== 'pendiente') {
            self::fallar(409, 'resuelta', 'Esta solicitud ya se resolvió.');
        }
        Empresa::resolver($id, $resolucion['aprobar'], $resolucion['motivo'], (int)Auth::id());
        $pdo->commit();
        Response::json(['solicitud' => Empresa::paraApi(Empresa::delUsuario($id))]);
    }

    /** GET /api/empresas/{id}: la vitrina de una empresa aprobada → {empresa, vehiculos} (sus autos publicados) */
    public function vitrina(int $id): void
    {
        $e = Empresa::delUsuario($id);
        if ($e === null || $e['estado'] !== 'aprobado' || !$e['activo']) {
            Response::error(404, 'no_encontrado', 'La empresa no existe.');
        }
        $publicados = array_values(array_filter(Vehiculo::delUsuario($id), static fn(array $v): bool => $v['estado'] === 'disponible'));
        Response::json(['empresa' => Empresa::publica($e), 'vehiculos' => CatalogoController::conInsignia($publicados)]);
    }

    /** GET /api/empresa/panel?dias=7|30|90 (la empresa con sesión) → {empresa, dias, resumen, mas_vista, serie, autos} */
    public function panel(): void
    {
        $id = (int)Auth::id();
        $e = Empresa::delUsuario($id);
        if ($e === null) {
            Response::error(404, 'no_encontrado', 'La empresa no existe.');
        }
        Response::json(['empresa' => Empresa::publica($e)] + PanelEmpresa::de($id, Panel::dias($_GET['dias'] ?? null), new DateTimeImmutable()));
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
