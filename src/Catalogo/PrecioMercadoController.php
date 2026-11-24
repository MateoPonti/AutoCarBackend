<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

use AutoCar\Core\Logger;
use AutoCar\Core\Response;
use Throwable;

/** El precio contra el mercado de las publicaciones (ver PrecioMercado): lo que pide el scraper y el recálculo a mano. */
final class PrecioMercadoController
{
    /** GET /api/vehiculos/mercado (pública): los autos de Explorar, para que el scraper calcule su precio contra el mercado. */
    public function paraElScraper(): void
    {
        Response::json(['vehiculos' => PrecioMercado::paraElScraper()]);
    }

    /** POST /api/admin/precios-mercado (permiso parametros.editar): recalcula todo a mano desde el panel. */
    public function admin(): void
    {
        self::recalcular();
    }

    private static function recalcular(): never
    {
        try {
            Response::json(PrecioMercado::recalcularTodas());
        } catch (Throwable $e) {
            Logger::error('Precio contra el mercado: ' . $e->getMessage());
            Response::error(503, 'comparador_no_disponible', 'No se pudieron leer los autos de los otros sitios. Probá de nuevo más tarde.');
        }
    }
}
