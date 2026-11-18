<?php
declare(strict_types=1);

namespace AutoCar\Pagos;

use AutoCar\Auth\Auth;
use AutoCar\Core\Response;
use AutoCar\Inspecciones\Inspeccion;

/**
 * Pagar la inspección con Mercado Pago (ver Cobros). Con MP_ACTIVO=false, "pagar" responde 409 y el aviso no hace nada.
 */
final class PagoController
{
    /**
     * POST /api/inspecciones/{id}/pago → {inspeccion, cobro: {estado, monto, checkout_url, pagado_at}}
     * Solo el comprador. Si falta pagar, trae el link (arma otra order si la anterior venció); el front lo manda ahí.
     */
    public function pagar(int $id): void
    {
        self::propia($id);
        $cobro = Cobros::link($id);
        if ($cobro === null) {
            Response::error(409, 'sin_cobro', 'Esta inspección no se paga con Mercado Pago.');
        }
        if ($cobro['estado'] === 'pendiente' && $cobro['checkout_url'] === null) {
            Response::error(502, 'mercado_pago', 'Mercado Pago no respondió. Probá de nuevo en un rato.');
        }
        self::responder($id, $cobro);
    }

    /**
     * POST /api/inspecciones/{id}/pago/estado → {inspeccion, cobro}
     * Solo el comprador, al volver de Mercado Pago: le pregunta a Mercado Pago cómo quedó (no arma otra order).
     */
    public function estado(int $id): void
    {
        self::propia($id);
        self::responder($id, Cobros::actualizar($id));
    }

    /**
     * POST /api/pagos/mercadopago?data.id=ORD...&type=order (público: lo llama Mercado Pago) → 200 siempre.
     * No le cree al cuerpo: con el id de la order le pregunta a Mercado Pago cómo quedó (Cobros::aviso).
     * Responde 200 aunque la order no sea nuestra, para que Mercado Pago no la reintente.
     */
    public function aviso(): void
    {
        // PHP cambia el punto de "data.id" por "_" en $_GET
        $ordenId = $_GET['data_id'] ?? null;
        if (!is_string($ordenId)) {
            $cuerpo = json_decode((string)file_get_contents('php://input'), true);
            $ordenId = is_array($cuerpo) ? ($cuerpo['data']['id'] ?? null) : null;
        }
        $nuestra = is_string($ordenId) && preg_match('/^ORD[A-Z0-9]{10,60}$/', $ordenId) === 1 && Cobros::aviso($ordenId);
        Response::json(['recibido' => true, 'nuestra' => $nuestra]);
    }

    private static function propia(int $id): void
    {
        $i = Inspeccion::porId($id);
        if ($i === null || (int)$i['comprador_id'] !== (int)Auth::id()) {
            Response::error(404, 'no_encontrado', 'La inspección no existe.');
        }
    }

    private static function responder(int $id, ?array $cobro): never
    {
        Response::json([
            'inspeccion' => Inspeccion::paraApi(Inspeccion::porId($id), Inspeccion::items($id)) + ['cobro' => Cobros::paraApi($cobro)],
            'cobro'      => Cobros::paraApi($cobro),
        ]);
    }
}
