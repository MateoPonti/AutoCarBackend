<?php
declare(strict_types=1);

namespace AutoCar\Auth;

use AutoCar\Core\Config;
use AutoCar\Core\Response;

/**
 * Sesión de PHP ($_SESSION) guardada en la base (ver SesionDb). El navegador solo tiene la cookie
 * "autocar_sesion" con el id de la sesión (HttpOnly, así JavaScript no la puede leer aunque hubiera un XSS).
 * Como el navegador manda la cookie solo, los pedidos que cambian datos llevan además el header
 * X-CSRF-Token (ver requireCsrf).
 *
 * El rol NO se guarda en la sesión: en cada pedido se consulta en la base el usuario (si sigue activo) y los
 * permisos de su rol (tablas rol, permiso y rol_permiso). Así un cambio de rol o una baja surten efecto de inmediato.
 * Las rutas piden PERMISOS, no roles (ver routes.php): un rol nuevo solo necesita permisos en la base.
 */
final class Auth
{
    public const COOKIE = 'autocar_sesion';

    private static ?int $usuarioId = null;
    private static ?array $usuario = null;
    private static ?array $permisos = null;
    private static bool $leido = false;

    public static function ttl(): int
    {
        return max(300, (int)(Config::get('SESION_TTL', '7200'))); // 2 horas por defecto
    }

    /** Arranca la sesión de PHP la primera vez que se necesita en el pedido. */
    private static function arrancar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');  // no acepta ids inventados por el cliente (fijación de sesión)
        ini_set('session.use_only_cookies', '1'); // el id nunca viaja en la URL
        ini_set('session.gc_maxlifetime', (string)self::ttl());
        session_name(self::COOKIE);
        session_set_cookie_params(self::atributos(self::ttl()));
        session_cache_limiter(''); // las cabeceras de caché las decide Response (por ejemplo, las fotos públicas)
        session_set_save_handler(new SesionDb(self::ttl()), true);
        session_start();
    }

    /**
     * Secure: solo viaja por HTTPS (en local, con http, no se puede poner o el navegador la descarta).
     * SameSite=Strict: el navegador no la manda en pedidos que se originan en otro sitio (primera barrera contra CSRF).
     */
    private static function atributos(int $lifetime): array
    {
        return [
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => self::https(),
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    private static function https(): bool
    {
        // Detrás de Render (y del proxy de Vercel) PHP recibe http; el protocolo real viene en X-Forwarded-Proto
        return (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** Login: id de sesión nuevo (evita fijación de sesión) y se guardan el usuario y el token CSRF. */
    public static function iniciarSesion(array $usuario): void
    {
        self::arrancar();
        session_regenerate_id(true); // true: borra la sesión vieja
        $_SESSION = [
            'usuario_id' => (int)$usuario['id'],
            'csrf'       => bin2hex(random_bytes(32)),
            'vence'      => time() + self::ttl(),
        ];
        self::$usuarioId = (int)$usuario['id'];
        self::$usuario = $usuario;
        self::$permisos = null;
        self::$leido = true;
    }

    /** Logout en los tres pasos de siempre: vaciar $_SESSION, vencer la cookie y destruir la sesión. */
    public static function cerrarSesion(): void
    {
        if (self::cookieValida()) {
            self::arrancar();
        }
        $_SESSION = [];
        $a = self::atributos(0);
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600, // vencida: el navegador la borra
            'path'     => $a['path'],
            'secure'   => $a['secure'],
            'httponly' => $a['httponly'],
            'samesite' => $a['samesite'],
        ]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy(); // borra la fila de la tabla sesion: el id deja de servir aunque alguien lo haya copiado
        }
        self::$usuarioId = null;
        self::$usuario = null;
        self::$leido = true;
    }

    /** ID del usuario autenticado, o null. */
    public static function id(): ?int
    {
        if (!self::$leido) {
            self::$leido = true;
            self::$usuarioId = self::leer();
        }
        return self::$usuarioId;
    }

    private static function leer(): ?int
    {
        if (!self::cookieValida()) {
            return null; // sin cookie no se arranca la sesión: los pedidos anónimos no tocan la tabla
        }
        self::arrancar();
        $id = $_SESSION['usuario_id'] ?? null;
        if (!is_int($id) || ($_SESSION['vence'] ?? 0) < time()) {
            $_SESSION = []; // vencida o vacía: al terminar el pedido SesionDb borra la fila
            return null;
        }
        // Un usuario dado de baja (o borrado) pierde la sesión en el próximo pedido
        $u = Usuario::porId($id);
        if ($u === null || !$u['activo']) {
            $_SESSION = [];
            return null;
        }
        self::$usuario = $u;
        return $id;
    }

    /** El usuario autenticado (con el nombre de su rol), o null. */
    public static function usuario(): ?array
    {
        return self::id() !== null ? self::$usuario : null;
    }

    /** Permisos del usuario autenticado (según su rol, leídos de la base una vez por pedido). */
    public static function permisos(): array
    {
        if (self::id() === null) {
            return [];
        }
        return self::$permisos ??= Usuario::permisosDe((int)self::id());
    }

    public static function tiene(string $permiso): bool
    {
        return in_array($permiso, self::permisos(), true);
    }

    /** Un id con otros caracteres (por ejemplo, una cookie vieja con un JWT) haría que PHP emita un warning. */
    private static function cookieValida(): bool
    {
        $c = $_COOKIE[self::COOKIE] ?? null;
        return is_string($c) && preg_match('/^[A-Za-z0-9,-]{22,256}$/', $c) === 1;
    }

    /** Token CSRF de la sesión. Se genera al azar en el login y otro sitio no lo puede conocer. */
    public static function csrf(): ?string
    {
        return self::id() !== null && is_string($_SESSION['csrf'] ?? null) ? $_SESSION['csrf'] : null;
    }

    public static function requireLogin(): void
    {
        if (self::id() === null) {
            Response::error(401, 'no_autenticado', 'Necesitás iniciar sesión.');
        }
    }

    /** Para POST, PUT y DELETE con sesión: el header X-CSRF-Token tiene que coincidir con csrf(). */
    public static function requireCsrf(): void
    {
        $esperado = self::csrf();
        $recibido = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($esperado === null || !is_string($recibido) || !hash_equals($esperado, $recibido)) {
            Response::error(403, 'csrf', 'La sesión no es válida. Recargá la página e intentá de nuevo.');
        }
    }

    /** Sesión válida y al menos uno de los permisos pedidos; si no, 401 o 403. */
    public static function requirePermiso(string ...$permisos): void
    {
        self::requireLogin();
        if (!array_intersect($permisos, self::permisos())) {
            Response::error(403, 'prohibido', 'No tenés permisos para esta acción.');
        }
    }
}
