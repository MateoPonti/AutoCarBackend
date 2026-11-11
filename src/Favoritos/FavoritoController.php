<?php
declare(strict_types=1);

namespace AutoCar\Favoritos;

use AutoCar\Auth\Auth;
use AutoCar\Catalogo\Vehiculo;
use AutoCar\Core\Response;

/** Favoritos del usuario logueado. Todas las rutas llevan el guard 'auth' en routes.php. */
final class FavoritoController
{
    /** GET /api/favoritos → {items}: las mismas fichas que el catálogo, el último guardado primero. */
    public function index(): void
    {
        $items = array_map([Vehiculo::class, 'paraApi'], Vehiculo::favoritosDe((int)Auth::id()));
        Response::json(['items' => $items]);
    }

    /** PUT /api/favoritos/{id}: guarda el vehículo (si ya estaba, no pasa nada) → 204. */
    public function agregar(int $id): void
    {
        $v = Vehiculo::find($id);
        if ($v === null || $v['estado'] !== 'disponible') {
            Response::error(404, 'no_encontrado', 'El vehículo no existe o ya no está disponible.');
        }
        if ((int)$v['usuario_id'] === (int)Auth::id()) {
            Response::error(409, 'vehiculo_propio', 'No podés guardar tu propia publicación en favoritos.');
        }
        Favorito::agregar((int)Auth::id(), $id);
        Response::noContent();
    }

    /** DELETE /api/favoritos/{id}: lo saca de favoritos (si no estaba, no pasa nada) → 204. */
    public function sacar(int $id): void
    {
        Favorito::sacar((int)Auth::id(), $id);
        Response::noContent();
    }
}
