<?php
declare(strict_types=1);

final class AuthController
{
    public function registro(): void
    {
        $in      = Request::json();
        $nombre  = Request::texto($in, 'nombre');
        $email   = mb_strtolower(Request::texto($in, 'email'));
        $pass    = is_string($in['password'] ?? null) ? $in['password'] : '';
        $confirm = is_string($in['password_confirm'] ?? null) ? $in['password_confirm'] : $pass; // opcional
        $errores = [];

        $len = mb_strlen($nombre);
        if ($len < 2 || $len > 80) {
            $errores['nombre'] = 'Ingresá tu nombre (entre 2 y 80 caracteres).';
        }
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores['email'] = 'Ingresá un email válido.';
        }
        if (strlen($pass) < 8 || strlen($pass) > 72) {
            $errores['password'] = 'La contraseña debe tener entre 8 y 72 caracteres.';
        } elseif (!preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
            $errores['password'] = 'La contraseña necesita al menos una letra y un número.';
        }
        if ($pass !== $confirm) {
            $errores['password_confirm'] = 'Las contraseñas no coinciden.';
        }
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

        $usuario = null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && $pass !== '' && strlen($pass) <= 72) {
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

        Response::json([
            'token'      => Auth::emitirToken($usuario),
            'tipo'       => 'Bearer',
            'expira_en'  => Auth::ttl(),
            'usuario'    => Usuario::publico($usuario),
        ]);
    }

    public function me(): void
    {
        $u = Usuario::porId((int)Auth::id());
        if ($u === null) { // el usuario fue borrado después de emitir el token
            Response::error(401, 'no_autenticado', 'Necesitás iniciar sesión.');
        }
        Response::json(['usuario' => Usuario::publico($u)]);
    }
}
