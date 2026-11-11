<?php
declare(strict_types=1);

namespace AutoCar\Auth;

use AutoCar\Core\Request;
use AutoCar\Core\Response;
use AutoCar\Inspecciones\SolicitudTaller;
use PDOException;

final class AuthController
{
    public function registro(): void
    {
        $in      = Request::json();
        $nombre  = Request::texto($in, 'nombre');
        $email   = mb_strtolower(Request::texto($in, 'email'));
        $pass    = is_string($in['password'] ?? null) ? $in['password'] : '';
        $confirm = is_string($in['password_confirm'] ?? null) ? $in['password_confirm'] : $pass; // opcional
        $errores = Usuario::validarCuenta($nombre, $email, $pass, $confirm);
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }

        try {
            $id = Usuario::crear($nombre, $email, password_hash($pass, PASSWORD_DEFAULT));
        } catch (PDOException $e) {
            if ($e->getCode() !== '23505') { // 23505 = unique_violation (email duplicado)
                throw $e;
            }
            Response::error(422, 'validacion', 'Revisá los datos ingresados.',
                ['email' => 'Ese email ya tiene una cuenta. Ingresá o usá otro.']);
        }
        Response::json(['usuario' => Usuario::publico(Usuario::porId($id))], 201);
    }

    public function login(): void
    {
        $in    = Request::json();
        $email = mb_strtolower(Request::texto($in, 'email'));
        $pass  = is_string($in['password'] ?? null) ? $in['password'] : '';

        $emailValido = filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 254;
        // Más de 5 intentos en 15 minutos con el mismo email: se frena, aunque la contraseña sea la correcta
        if ($emailValido) {
            $espera = IntentosLogin::anotar($email);
            if ($espera > 0) {
                header('Retry-After: ' . $espera);
                Response::error(429, 'demasiados_intentos', 'Probaste demasiadas veces. Esperá '
                    . (int)ceil($espera / 60) . ' minutos y volvé a intentar.');
            }
        }

        $usuario = null;
        if ($emailValido && $pass !== '' && strlen($pass) <= 72) {
            $usuario = Usuario::porEmail($email);
        }
        if ($usuario === null) {
            password_hash($pass, PASSWORD_DEFAULT); // mismo costo que verificar: no revela si el email existe
            $ok = false;
        } else {
            $ok = password_verify($pass, $usuario['password_hash']);
        }
        if (!$ok) {
            Response::error(401, 'credenciales', 'El email o la contraseña no son correctos.');
        }
        IntentosLogin::limpiar($email);
        // Recién con la contraseña correcta se dice que la cuenta está dada de baja (no revela qué emails existen)
        if (!$usuario['activo']) {
            Response::error(403, 'cuenta_deshabilitada', 'Tu cuenta está dada de baja. Si creés que es un error, escribinos.');
        }
        // La cuenta de un taller ingresa recién cuando un admin aprueba la solicitud ("Adherí tu taller")
        if ($usuario['rol'] === 'mecanico') {
            $taller = SolicitudTaller::delUsuario((int)$usuario['id']);
            if ($taller !== null && $taller['estado'] === 'pendiente') {
                Response::error(403, 'taller_pendiente',
                    'Tu taller todavía está en revisión. Vas a poder ingresar cuando un administrador apruebe la solicitud.');
            }
            if ($taller !== null && $taller['estado'] === 'rechazado') {
                Response::error(403, 'taller_rechazado', 'La solicitud de tu taller fue rechazada. Motivo: '
                    . $taller['motivo_rechazo'] . ' Corregí los datos y volvé a mandarla desde "Adherí tu taller" con el mismo email y contraseña.');
            }
        }

        // El id de sesión va solo en la cookie HttpOnly; al front se le da el token CSRF para mandarlo en cada cambio
        Auth::iniciarSesion($usuario);
        Response::json([
            'csrf'       => Auth::csrf(),
            'expira_en'  => Auth::ttl(),
            'usuario'    => Usuario::publico($usuario) + ['permisos' => Auth::permisos()],
        ]);
    }

    /** Destruye la sesión en el servidor y borra la cookie (JavaScript no puede hacerlo porque es HttpOnly). */
    public function logout(): void
    {
        Auth::cerrarSesion();
        Response::noContent();
    }

    public function me(): void
    {
        // Auth::id() ya verificó que el usuario exista y siga activo
        $u = Auth::usuario();
        Response::json(['usuario' => Usuario::publico($u) + ['permisos' => Auth::permisos()], 'csrf' => Auth::csrf()]);
    }
}
