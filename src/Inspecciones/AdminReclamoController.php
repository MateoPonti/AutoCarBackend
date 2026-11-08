<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Auth\Auth;
use AutoCar\Core\Database;
use AutoCar\Core\Request;
use AutoCar\Core\Response;

/**
 * Cola de reclamos (B8): permiso reclamos.resolver (moderador y admin, ver routes.php).
 * Si un reclamo procede, al comprador se le devuelve lo que pagó, se le paga la compensación (hasta 3 veces el
 * precio) y el mecánico baja en el ranking (Ranking cuenta los reclamos que procedieron).
 */
final class AdminReclamoController
{
    /** GET /api/admin/reclamos?estado=abierto|procede|no_procede → {items} */
    public function index(): void
    {
        $estado = is_string($_GET['estado'] ?? null) && $_GET['estado'] !== '' ? $_GET['estado'] : null;
        if ($estado !== null && !in_array($estado, Reclamo::ESTADOS, true)) {
            Response::error(422, 'validacion', 'Revisá el filtro.', ['estado' => 'Tiene que ser abierto, procede o no_procede.']);
        }
        Response::json(['items' => array_map([Reclamos::class, 'paraApi'], Reclamos::listar($estado))]);
    }

    /** PATCH /api/admin/reclamos/{id} {procede: bool, compensacion?, respuesta} → {reclamo} */
    public function resolver(int $id): void
    {
        $in = Request::json();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $reclamo = Reclamos::porId($id, true);
        if ($reclamo === null) {
            self::fallar(404, 'no_encontrado', 'El reclamo no existe.');
        }
        if ($reclamo['estado'] !== 'abierto') {
            self::fallar(409, 'resuelto', 'Este reclamo ya se resolvió.');
        }
        // Nadie resuelve un reclamo sobre su propia inspección
        if ((int)$reclamo['mecanico_id'] === (int)Auth::id() || (int)$reclamo['comprador_id'] === (int)Auth::id()) {
            self::fallar(409, 'interes_propio', 'No podés resolver un reclamo sobre una inspección tuya.');
        }
        [$resolucion, $errores] = Reclamo::validarResolucion($in, (float)$reclamo['precio']);
        if ($errores) {
            self::fallar(422, 'validacion', 'Revisá la resolución.', $errores);
        }
        Reclamos::resolver($reclamo, $resolucion, (int)Auth::id());
        $pdo->commit();
        Response::json(['reclamo' => Reclamos::paraApi(Reclamos::porId($id))]);
    }

    private static function fallar(int $status, string $codigo, string $mensaje, array $errores = []): never
    {
        $pdo = Database::pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        Response::error($status, $codigo, $mensaje, $errores);
    }
}
