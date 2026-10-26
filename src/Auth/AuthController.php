<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthController {

    public static function login(): void {
        $data = Request::json();
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            Response::json(['error' => 'Email y contraseña requeridos.'], 400);
            return;
        }

        $usuario = Usuario::findByEmail($email);

        if (!$usuario || !password_verify($password, $usuario['password'])) {
            Response::json(['error' => 'Credenciales inválidas.'], 401);
            return;
        }

        // Clave secreta desde las variables de entorno de Render
        $secretKey = getenv('JWT_SECRET') ?: 'clave_secreta_default';

        $payload = [
            'iss' => 'autocar-backend',
            'iat' => time(),
            'exp' => time() + (60 * 60 * 24), // Token válido por 24 horas
            'data' => [
                'id' => $usuario['id'],
                'email' => $usuario['email'],
                'rol' => $usuario['rol'] ?? 'usuario'
            ]
        ];

        // Generar token con la librería oficial de Firebase
        $token = JWT::encode($payload, $secretKey, 'HS256');

        Response::json([
            'message' => 'Login exitoso',
            'token' => $token,
            'user' => [
                'id' => $usuario['id'],
                'email' => $usuario['email'],
                'nombre' => $usuario['nombre'] ?? ''
            ]
        ]);
    }

    public static function registro(): void {
        $data = Request::json();
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $nombre = trim($data['nombre'] ?? '');

        if (empty($email) || empty($password)) {
            Response::json(['error' => 'Email y contraseña requeridos.'], 400);
            return;
        }

        if (Usuario::findByEmail($email)) {
            Response::json(['error' => 'El email ya está registrado.'], 409);
            return;
        }

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $nuevoId = Usuario::crear($email, $hashedPassword, $nombre);

        if ($nuevoId) {
            Response::json(['message' => 'Usuario registrado exitosamente.'], 201);
        } else {
            Response::json(['error' => 'No se pudo crear el usuario.'], 500);
        }
    }
}
