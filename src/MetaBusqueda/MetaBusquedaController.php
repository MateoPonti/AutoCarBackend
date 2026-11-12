<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

use AutoCar\Auth\Auth;
use AutoCar\Core\Database;
use AutoCar\Core\Logger;
use AutoCar\Core\Request;
use AutoCar\Core\Response;
use Throwable;

/**
 * Búsquedas guardadas, su reporte, el detalle de los autos externos y los avisos. Todo requiere sesión.
 * Lee y escribe en la base de documentos (Firestore); de Neon solo lee los vehículos publicados en AutoCar.
 * Si la base de documentos no responde, 503 (el resto del sitio sigue andando).
 */
final class MetaBusquedaController
{
    public function index(): void
    {
        $docs = self::docs();
        $usuario = (int)Auth::id();
        $mercado = new Mercado($docs, Database::pdo());
        $actuales = array_column(self::intentar(static fn() => $mercado->ultimasRecolecciones()), 'actual');
        $sinVer = array_count_values(array_column(self::intentar(static fn() => Avisos::delUsuario($docs, $usuario, true, PHP_INT_MAX)), 'busqueda_id'));

        $salida = [];
        foreach (self::intentar(static fn() => Busqueda::delUsuario($docs, $usuario)) as $b) {
            $salida[] = Busqueda::paraApi($b) + [
                'autos'          => array_sum(self::contar($mercado, $actuales, $b)),
                'avisos_sin_ver' => $sinVer[$b['id']] ?? 0,
            ];
        }
        Response::json(['busquedas' => $salida]);
    }

    /**
     * GET /api/meta-busquedas/conteo?marca=&modelo=&anio_desde=&anio_hasta=&precio_max=&km_max=&transmision=
     * Cuántos autos coinciden con esos criterios hoy, en total y por sitio, sin guardar nada: la pantalla
     * "Nueva búsqueda" lo muestra mientras se eligen los filtros. Valida igual que al crear.
     * → {autos, por_fuente: {mercadolibre: n, kavak: n, autocosmos: n, autocar: n}}
     */
    public function conteo(): void
    {
        [$b, $errores] = Busqueda::validar($_GET);
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        $mercado = new Mercado(self::docs(), Database::pdo());
        $actuales = array_column(self::intentar(static fn() => $mercado->ultimasRecolecciones()), 'actual');
        $porFuente = self::contar($mercado, $actuales, $b);
        Response::json(['autos' => array_sum($porFuente), 'por_fuente' => $porFuente]);
    }

    /**
     * Autos que coinciden con la búsqueda $b, por sitio: los de la última corrida de cada plataforma
     * y los publicados en AutoCar.
     * @return array<string, int>
     */
    private static function contar(Mercado $mercado, array $actuales, array $b): array
    {
        $porFuente = [];
        $externos = self::intentar(static fn() => $mercado->fotosDelModelo($actuales, $b['marca'], $b['modelo']));
        foreach ($externos as $a) {
            if (Busqueda::coincide($b, $a)) {
                $porFuente[$a['fuente']] = ($porFuente[$a['fuente']] ?? 0) + 1;
            }
        }
        $portal = count($mercado->delPortal($b));
        if ($portal > 0) {
            $porFuente['autocar'] = ($porFuente['autocar'] ?? 0) + $portal;
        }
        ksort($porFuente);
        return $porFuente;
    }

    public function crear(): void
    {
        [$datos, $errores] = Busqueda::validar(Request::json());
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        $docs = self::docs();
        $usuario = (int)Auth::id();
        if (self::intentar(static fn() => Busqueda::cantidad($docs, $usuario)) >= Busqueda::MAXIMO_POR_USUARIO) {
            Response::error(409, 'limite_alcanzado', 'Llegaste al máximo de ' . Busqueda::MAXIMO_POR_USUARIO . ' búsquedas guardadas.');
        }
        $b = self::intentar(static fn() => Busqueda::crear($docs, $usuario, $datos));
        header('Location: /api/meta-busquedas/' . $b['id']);
        Response::json(['busqueda' => Busqueda::paraApi($b)], 201);
    }

    public function actualizar(int $id): void
    {
        [$datos, $errores] = Busqueda::validar(Request::json());
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        $docs = self::docs();
        $b = self::intentar(static fn() => Busqueda::actualizar($docs, (int)Auth::id(), $id, $datos));
        if ($b === null) {
            self::noExiste();
        }
        Response::json(['busqueda' => Busqueda::paraApi($b)]);
    }

    public function eliminar(int $id): void
    {
        $docs = self::docs();
        if (!self::intentar(static fn() => Busqueda::eliminar($docs, (int)Auth::id(), $id))) {
            self::noExiste();
        }
        Response::noContent();
    }

    /** GET /api/meta-busquedas/{id}: filtros opcionales del listado: fuente, etiqueta, precio_max, km_max, orden, page. */
    public function reporte(int $id): void
    {
        $docs = self::docs();
        $b = self::intentar(static fn() => Busqueda::delUsuarioPorId($docs, (int)Auth::id(), $id));
        if ($b === null) {
            self::noExiste();
        }
        $reporte = new Reporte(new Mercado($docs, Database::pdo()));
        Response::json(self::intentar(static fn() => $reporte->construir($b, $_GET)));
    }

    /** GET /api/autos-externos?id=fuente~modelo~id: el id viene en la query porque es texto (las rutas solo aceptan números). */
    public function autoExterno(): void
    {
        $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
        $reporte = new Reporte(new Mercado(self::docs()));
        $detalle = self::intentar(static fn() => $reporte->detalle($id));
        if ($detalle === null) {
            Response::error(404, 'no_encontrado', 'El auto no existe.');
        }
        Response::json($detalle);
    }

    /** GET /api/meta-busquedas/avisos?sin_ver=1 */
    public function avisos(): void
    {
        $docs = self::docs();
        $sinVer = ($_GET['sin_ver'] ?? '') === '1';
        Response::json(['avisos' => self::intentar(static fn() => Avisos::delUsuario($docs, (int)Auth::id(), $sinVer))]);
    }

    /** Marca como vistos los avisos de una búsqueda propia. */
    public function marcarVistos(int $id): void
    {
        $docs = self::docs();
        $usuario = (int)Auth::id();
        if (self::intentar(static fn() => Busqueda::delUsuarioPorId($docs, $usuario, $id)) === null) {
            self::noExiste();
        }
        self::intentar(static fn() => Busqueda::marcarVistos($docs, $usuario, $id));
        Response::noContent();
    }

    private static function docs(): Documentos
    {
        return self::intentar(static fn() => Almacenamiento::documentos());
    }

    /**
     * @template T
     * @param callable(): T $accion
     * @return T
     */
    private static function intentar(callable $accion): mixed
    {
        try {
            return $accion();
        } catch (Throwable $e) {
            Logger::error('Metabuscador: ' . $e->getMessage());
            Response::error(503, 'metabuscador_no_disponible', 'El metabuscador no está disponible en este momento.');
        }
    }

    private static function noExiste(): never
    {
        Response::error(404, 'no_encontrado', 'La búsqueda no existe.');
    }
}
