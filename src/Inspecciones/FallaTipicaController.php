<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Auth\Auth;
use AutoCar\Catalogo\MarcaModelo;
use AutoCar\Core\Request;
use AutoCar\Core\Response;

/**
 * Idea B, fallas típicas por modelo. Lo público (para el comprador, en el auto y en el informe), lo del mecánico
 * (proponer y votar) y lo del admin (cargar las de internet con su fuente).
 */
final class FallaTipicaController
{
    /** GET /api/fallas-tipicas?marca=&modelo= (público) → {fallas_tipicas}: sin las descartadas */
    public function index(): void
    {
        [$marca, $modelo] = self::modelo($_GET);
        Response::json(['fallas_tipicas' => FallasTipicas::delModelo($marca, $modelo)]);
    }

    /** GET /api/mecanico/fallas-tipicas?marca=&modelo= → {fallas_tipicas}: con las descartadas y su voto en cada una */
    public function delMecanico(): void
    {
        [$marca, $modelo] = self::modelo($_GET);
        Response::json(['fallas_tipicas' => FallasTipicas::delModelo($marca, $modelo, (int)Auth::id(), true)]);
    }

    /**
     * POST /api/mecanico/fallas-tipicas {marca, modelo, item_codigo, titulo, descripcion?, fuente_url?} → 201
     * Un mecánico propone una falla que vio (origen "mecanicos"); queda con su voto a favor.
     */
    public function proponer(): void
    {
        $this->crear('mecanicos');
    }

    /** POST /api/admin/fallas-tipicas {marca, modelo, item_codigo, titulo, descripcion?, fuente_url} → 201 (origen "internet") */
    public function cargar(): void
    {
        $this->crear('internet');
    }

    /** PUT /api/mecanico/fallas-tipicas/{id}/voto {confirma: bool} → {fallas_tipicas} del modelo, con el voto nuevo */
    public function votar(int $id): void
    {
        $confirma = Request::json()['confirma'] ?? null;
        if (!is_bool($confirma)) {
            Response::error(422, 'validacion', 'Revisá el voto.', ['confirma' => 'Indicá si la viste (true) o si no es típica (false).']);
        }
        $modelo = FallasTipicas::modeloDe($id);
        if ($modelo === null) {
            Response::error(404, 'no_encontrado', 'La falla no existe.');
        }
        FallasTipicas::votar($id, (int)Auth::id(), $confirma);
        Response::json(['fallas_tipicas' => FallasTipicas::delModelo($modelo['marca'], $modelo['modelo'], (int)Auth::id(), true)]);
    }

    private function crear(string $origen): void
    {
        $in = Request::json();
        [$marca, $modelo] = self::modelo($in);
        [$datos, $errores] = FallaTipica::validar($in, $origen);
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá la falla.', $errores);
        }
        $id = FallasTipicas::crear($marca, $modelo, $datos, (int)Auth::id());
        if ($origen === 'mecanicos') {
            FallasTipicas::votar($id, (int)Auth::id(), true); // el que la propone la vio
        }
        Response::json(['id' => $id, 'fallas_tipicas' => FallasTipicas::delModelo($marca, $modelo,
            $origen === 'mecanicos' ? (int)Auth::id() : null, $origen === 'mecanicos')], 201);
    }

    /** Marca y modelo del catálogo (los mismos nombres que GET /api/marcas-modelos), o 422. */
    private static function modelo(array $in): array
    {
        $marca = is_string($in['marca'] ?? null) ? trim($in['marca']) : '';
        $modelo = is_string($in['modelo'] ?? null) ? trim($in['modelo']) : '';
        $errores = $marca === '' ? ['marca' => 'Elegí una marca de la lista.']
            : ($modelo === '' ? ['modelo' => 'Elegí un modelo de la lista.'] : MarcaModelo::errores($marca, $modelo));
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá la marca y el modelo.', $errores);
        }
        return [$marca, $modelo];
    }
}
