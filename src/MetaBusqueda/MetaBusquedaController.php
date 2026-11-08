<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

use AutoCar\Auth\Auth;
use AutoCar\Core\Database;
use AutoCar\Core\Request;
use AutoCar\Core\Response;
use PDO;

/** Búsquedas guardadas, su reporte, el detalle de los autos externos y los avisos. Todo requiere sesión. */
final class MetaBusquedaController
{
    public function index(): void
    {
        $pdo = Database::pdo();
        $mercado = new Mercado($pdo);
        $actuales = array_column($mercado->ultimasRecolecciones(), 'actual');
        $sinVer = $pdo->prepare('SELECT COUNT(*) FROM coincidencia WHERE busqueda_id = :b AND NOT visto');

        $salida = [];
        foreach (Busqueda::delUsuario((int)Auth::id()) as $b) {
            $autos = array_filter(
                $mercado->fotosDelModelo($actuales, $b['marca'], $b['modelo']),
                static fn(array $a): bool => Busqueda::coincide($b, $a)
            );
            $sinVer->execute(['b' => $b['id']]);
            $salida[] = Busqueda::paraApi($b) + [
                'autos'          => count($autos) + count($mercado->delPortal($b)),
                'avisos_sin_ver' => (int)$sinVer->fetchColumn(),
            ];
        }
        Response::json(['busquedas' => $salida]);
    }

    public function crear(): void
    {
        [$datos, $errores] = Busqueda::validar(Request::json());
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        $usuario = (int)Auth::id();
        if (Busqueda::cantidad($usuario) >= Busqueda::MAXIMO_POR_USUARIO) {
            Response::error(409, 'limite_alcanzado', 'Llegaste al máximo de ' . Busqueda::MAXIMO_POR_USUARIO . ' búsquedas guardadas.');
        }
        $b = Busqueda::crear($usuario, $datos);
        header('Location: /api/meta-busquedas/' . $b['id']);
        Response::json(['busqueda' => Busqueda::paraApi($b)], 201);
    }

    public function actualizar(int $id): void
    {
        [$datos, $errores] = Busqueda::validar(Request::json());
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        $b = Busqueda::actualizar((int)Auth::id(), $id, $datos);
        if ($b === null) {
            self::noExiste();
        }
        Response::json(['busqueda' => Busqueda::paraApi($b)]);
    }

    public function eliminar(int $id): void
    {
        if (!Busqueda::eliminar((int)Auth::id(), $id)) {
            self::noExiste();
        }
        Response::noContent();
    }

    /** GET /api/meta-busquedas/{id}: filtros opcionales del listado: fuente, etiqueta, precio_max, km_max, orden, page. */
    public function reporte(int $id): void
    {
        $b = Busqueda::delUsuarioPorId((int)Auth::id(), $id);
        if ($b === null) {
            self::noExiste();
        }
        Response::json((new Reporte(new Mercado(Database::pdo())))->construir($b, $_GET));
    }

    public function autoExterno(int $id): void
    {
        $detalle = (new Reporte(new Mercado(Database::pdo())))->detalle($id);
        if ($detalle === null) {
            Response::error(404, 'no_encontrado', 'El auto no existe.');
        }
        Response::json($detalle);
    }

    /** Nombres exactos de marcas y modelos para armar el formulario de búsqueda. */
    public function marcasYModelos(): void
    {
        $mercado = new Mercado(Database::pdo());
        Response::json(['marcas' => $mercado->marcasYModelos(array_column($mercado->ultimasRecolecciones(), 'actual'))]);
    }

    /** GET /api/meta-busquedas/avisos?sin_ver=1 */
    public function avisos(): void
    {
        $sinVer = ($_GET['sin_ver'] ?? '') === '1';
        $st = Database::pdo()->prepare(
            'SELECT c.id, c.busqueda_id, c.auto_id, c.tipo, c.precio_anterior, c.precio_actual, c.fecha, c.visto,
                    a.marca, a.modelo, a.version, a.anio, f.nombre AS fuente
               FROM coincidencia c
               JOIN busqueda_guardada b ON b.id = c.busqueda_id
               JOIN auto_externo a ON a.id = c.auto_id
               JOIN fuente f ON f.id = a.fuente_id
              WHERE b.usuario_id = :u' . ($sinVer ? ' AND NOT c.visto' : '') . '
              ORDER BY c.fecha DESC, c.id DESC
              LIMIT 100'
        );
        $st->execute(['u' => (int)Auth::id()]);
        $avisos = array_map(static fn(array $f): array => [
            'id'              => (int)$f['id'],
            'busqueda_id'     => (int)$f['busqueda_id'],
            'auto_id'         => (int)$f['auto_id'],
            'tipo'            => $f['tipo'],
            'precio_anterior' => $f['precio_anterior'] !== null ? (float)$f['precio_anterior'] : null,
            'precio_actual'   => $f['precio_actual'] !== null ? (float)$f['precio_actual'] : null,
            'fecha'           => $f['fecha'],
            'visto'           => (bool)$f['visto'],
            'marca'           => $f['marca'],
            'modelo'          => $f['modelo'],
            'version'         => $f['version'],
            'anio'            => (int)$f['anio'],
            'fuente'          => $f['fuente'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        Response::json(['avisos' => $avisos]);
    }

    /** Marca como vistos los avisos de una búsqueda propia. */
    public function marcarVistos(int $id): void
    {
        if (Busqueda::delUsuarioPorId((int)Auth::id(), $id) === null) {
            self::noExiste();
        }
        Database::pdo()->prepare('UPDATE coincidencia SET visto = true WHERE busqueda_id = :b AND NOT visto')->execute(['b' => $id]);
        Response::noContent();
    }

    private static function noExiste(): never
    {
        Response::error(404, 'no_encontrado', 'La búsqueda no existe.');
    }
}
