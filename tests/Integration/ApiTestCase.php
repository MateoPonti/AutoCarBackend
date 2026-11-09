<?php
declare(strict_types=1);

namespace AutoCar\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base de las pruebas de integración: pedidos HTTP reales a la API, con la cookie de sesión y el CSRF
 * manejados como lo hace el front. Cada prueba arranca sin sesión.
 */
abstract class ApiTestCase extends TestCase
{
    protected const PASSWORD = 'Prueba1234';

    private ?string $cookie = null;
    private ?string $csrf = null;

    protected function setUp(): void
    {
        $this->cookie = null;
        $this->csrf = null;
    }

    /**
     * @return array{status: int, json: mixed, headers: array<string, string>}
     */
    protected function pedir(string $metodo, string $ruta, ?array $cuerpo = null, array $cabeceras = [], bool $conCsrf = true): array
    {
        $h = ['Accept: application/json'];
        if ($cuerpo !== null) {
            $h[] = 'Content-Type: application/json';
        }
        if ($this->cookie !== null) {
            $h[] = 'Cookie: autocar_sesion=' . $this->cookie;
        }
        if ($conCsrf && $this->csrf !== null && $metodo !== 'GET') {
            $h[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        foreach ($cabeceras as $nombre => $valor) {
            $h[] = "$nombre: $valor";
        }

        $c = curl_init(Entorno::url() . $ruta);
        curl_setopt_array($c, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($cuerpo));
        }
        $crudo = (string)curl_exec($c);
        $status = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $largoCabeceras = (int)curl_getinfo($c, CURLINFO_HEADER_SIZE);

        $headers = [];
        foreach (explode("\r\n", substr($crudo, 0, $largoCabeceras)) as $linea) {
            if (!str_contains($linea, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $linea, 2));
            $k = strtolower($k);
            $headers[$k] = isset($headers[$k]) ? $headers[$k] . "\n" . $v : $v;
            if ($k === 'set-cookie' && preg_match('/^autocar_sesion=([^;]*)/', $v, $m)) {
                $this->cookie = ($m[1] === '' || $m[1] === 'deleted') ? null : $m[1];
            }
        }
        return ['status' => $status, 'json' => json_decode(substr($crudo, $largoCabeceras), true), 'headers' => $headers];
    }

    /**
     * POST multipart/form-data, como el formulario de publicar: $campos de texto y $fotos PNG generadas en el momento.
     * @return array{status: int, json: mixed}
     */
    protected function pedirFormulario(string $ruta, array $campos, int $fotos = 0): array
    {
        $h = ['Accept: application/json'];
        if ($this->cookie !== null) {
            $h[] = 'Cookie: autocar_sesion=' . $this->cookie;
        }
        if ($this->csrf !== null) {
            $h[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        // Un PNG de 1×1 píxel (el back revisa el contenido, no la extensión)
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $archivos = [];
        for ($i = 0; $i < $fotos; $i++) {
            $ruta_tmp = tempnam(sys_get_temp_dir(), 'foto');
            file_put_contents($ruta_tmp, $png);
            $archivos[] = $ruta_tmp;
            $campos["fotos[$i]"] = new \CURLFile($ruta_tmp, 'image/png', "foto$i.png");
        }

        $c = curl_init(Entorno::url() . $ruta);
        curl_setopt_array($c, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_POSTFIELDS => $campos,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $cuerpo = (string)curl_exec($c);
        $status = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        foreach ($archivos as $archivo) {
            unlink($archivo);
        }
        return ['status' => $status, 'json' => json_decode($cuerpo, true)];
    }

    protected static function emailNuevo(): string
    {
        return 'it+' . bin2hex(random_bytes(5)) . '@autocar.test';
    }

    protected function registrar(?string $email = null): string
    {
        $email ??= self::emailNuevo();
        $r = $this->pedir('POST', '/api/registro', [
            'nombre' => 'Prueba', 'email' => $email, 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD,
        ]);
        self::assertSame(201, $r['status'], json_encode($r['json']));
        return $email;
    }

    protected function ingresar(string $email, string $password = self::PASSWORD): array
    {
        $r = $this->pedir('POST', '/api/login', ['email' => $email, 'password' => $password]);
        if ($r['status'] === 200) {
            $this->csrf = $r['json']['csrf'];
        }
        return $r;
    }

    /** Usuario nuevo con rol admin, ya logueado (como indica el README: registro + UPDATE en la base). */
    protected function ingresarComoAdmin(): string
    {
        $email = $this->registrar();
        Entorno::pdo()
            ->prepare("UPDATE usuario SET rol_id = (SELECT id FROM rol WHERE nombre = 'admin') WHERE email = :e")
            ->execute(['e' => $email]);
        self::assertSame(200, $this->ingresar($email)['status']);
        return $email;
    }

    protected function ingresarComoUsuario(): string
    {
        $email = $this->registrar();
        self::assertSame(200, $this->ingresar($email)['status']);
        return $email;
    }

    protected static function vehiculo(array $cambios = []): array
    {
        return $cambios + [
            'marca' => 'Peugeot', 'modelo' => '208', 'anio' => 2021, 'precio' => 18500000,
            'kilometraje' => 30000, 'combustible' => 'nafta', 'transmision' => 'manual',
        ];
    }
}
