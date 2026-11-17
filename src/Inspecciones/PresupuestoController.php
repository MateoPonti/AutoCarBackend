<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Auth\Auth;
use AutoCar\Core\Request;
use AutoCar\Core\Response;

/**
 * Idea A, costo total: los talleres responden los presupuestos que les pidieron y el comprador los pide para un
 * informe firmado antes de que existieran. El bloque "Costo total" sale en GET /api/inspecciones/{id}.
 */
final class PresupuestoController
{
    /** GET /api/mecanico/presupuestos?estado=pedido|respondido|rechazado → {items}: lo que le pidieron a este taller */
    public function bandeja(): void
    {
        $estado = is_string($_GET['estado'] ?? null) && $_GET['estado'] !== '' ? $_GET['estado'] : null;
        if ($estado !== null && !in_array($estado, ['pedido', 'respondido', 'rechazado'], true)) {
            Response::error(422, 'validacion', 'Revisá el filtro.', ['estado' => 'Tiene que ser pedido, respondido o rechazado.']);
        }
        Response::json(['items' => array_map(static fn(array $p): array => Presupuestos::paraApi($p, true),
            Presupuestos::delTaller((int)Auth::id(), $estado))]);
    }

    /**
     * PATCH /api/mecanico/presupuestos/{id} {monto, detalle} o {rechazar: true} → {presupuesto}
     * Solo el taller al que se le pidió (de otro, 404) y una sola vez (ya respondido, 409).
     */
    public function responder(int $id): void
    {
        $p = Presupuestos::porId($id);
        if ($p === null || (int)$p['mecanico_id'] !== (int)Auth::id()) {
            Response::error(404, 'no_encontrado', 'El pedido de presupuesto no existe.');
        }
        [$respuesta, $errores] = Presupuestos::validarRespuesta(Request::json());
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá el presupuesto.', $errores);
        }
        if (!Presupuestos::responder($id, $respuesta)) {
            Response::error(409, 'respondido', 'Este pedido ya se respondió.');
        }
        Response::json(['presupuesto' => Presupuestos::paraApi(Presupuestos::porId($id))]);
    }

    /**
     * POST /api/inspecciones/{id}/presupuestos: el comprador pide los presupuestos de un informe firmado (los que se
     * firmaron antes de que existiera el costo total no los tienen). No repite los que ya se pidieron. → {inspeccion}
     */
    public function pedir(int $id): void
    {
        $i = Inspeccion::porId($id);
        if ($i === null || (int)$i['comprador_id'] !== (int)Auth::id()) {
            Response::error(404, 'no_encontrado', 'La inspección no existe.');
        }
        if ($i['estado'] !== 'firmada') {
            Response::error(409, 'estado_invalido', 'Los presupuestos se piden con el informe firmado.');
        }
        Presupuestos::pedir($id, (int)$i['mecanico_id'], $i['taller_localidad']);
        Response::json(['inspeccion' => Inspeccion::paraApi(Inspeccion::porId($id), Inspeccion::items($id))]);
    }
}
