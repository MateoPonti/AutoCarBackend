<?php
declare(strict_types=1);

final class AuthController
{
    private const VISTAS = SRC_PATH . '/Auth/views/';

    public function loginForm(): void
    {
        if (Auth::check()) {
            redirect('/');
        }
        View::render(self::VISTAS . 'login.php', ['errors' => [], 'old' => [], 'flash' => flash_get()], false);
    }

    public function login(): void
    {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $pass  = (string)($_POST['password'] ?? '');

        $usuario = null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($pass) > 0 && strlen($pass) <= 72) {
            $usuario = Usuario::porEmail($email);
        }

        if ($usuario === null) {
            password_hash($pass, PASSWORD_DEFAULT); // mismo costo que verificar: no revela si el email existe
            $ok = false;
        } else {
            $ok = password_verify($pass, $usuario['password_hash']);
        }

        if (!$ok) {
            View::render(self::VISTAS . 'login.php', [
                'errors' => ['general' => 'El email o la contraseña no son correctos.'],
                'old'    => ['email' => $email],
                'flash'  => null,
            ], false);
            return;
        }

        Auth::login($usuario);
        redirect($usuario['rol'] === 'admin' ? '/admin/vehiculos' : '/vehiculos');
    }

    public function registroForm(): void
    {
        if (Auth::check()) {
            redirect('/');
        }
        View::render(self::VISTAS . 'registro.php', ['errors' => [], 'old' => []], false);
    }

    public function registro(): void
    {
        $nombre  = trim((string)($_POST['nombre'] ?? ''));
        $email   = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $pass    = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');
        $errors  = [];

        $len = mb_strlen($nombre);
        if ($len < 2 || $len > 80) {
            $errors['nombre'] = 'Ingresá tu nombre (entre 2 y 80 caracteres).';
        }
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Ingresá un email válido.';
        }
        if (strlen($pass) < 8 || strlen($pass) > 72) {
            $errors['password'] = 'La contraseña debe tener entre 8 y 72 caracteres.';
        } elseif (!preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
            $errors['password'] = 'La contraseña necesita al menos una letra y un número.';
        }
        if ($pass !== $confirm) {
            $errors['password_confirm'] = 'Las contraseñas no coinciden.';
        }

        if (!$errors) {
            try {
                Usuario::crear($nombre, $email, password_hash($pass, PASSWORD_DEFAULT));
                flash_set('Cuenta creada. Ya podés ingresar.');
                redirect('/login');
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') { // 23000 = email duplicado (UNIQUE)
                    throw $e;
                }
                $errors['email'] = 'Ese email ya tiene una cuenta. Ingresá o usá otro.';
            }
        }

        View::render(self::VISTAS . 'registro.php', [
            'errors' => $errors,
            'old'    => ['nombre' => $nombre, 'email' => $email],
        ], false);
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }
}
