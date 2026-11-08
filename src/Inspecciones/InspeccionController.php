<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Auth\Auth;
use AutoCar\Core\Config;
use AutoCar\Core\Database;
use AutoCar\Core\Request;
use AutoCar\Core\Response;
use DateTimeImmutable;

/**
 * Lado del comprador (y lo público): ranking de mecánicos (B3), pedir (B4), mis inspecciones, detalle y
 * cancelar (B5), y reclamar (B8). Cada comprador ve solo sus inspecciones: las de otro responden 404.
 */
final class InspeccionController
{
    /** GET /api/mecanicos?localidad= (público) → {items: [mecánico con puntaje], localidades} */
    public function mecanicos(): void
    {
        $localidad = is_string($_GET['localidad'] ?? null) && trim($_GET['localidad']) !== ''
            ? mb_substr(trim($_GET['localidad']), 0, 100) : null;
        Response::json([
            'items'       => array_map([Taller::class, 'paraRanking'], Taller::red($localidad)),
            'localidades' => Taller::localidades(),
        ]);
    }

    /**
     * POST /api/inspecciones {url, mecanico_id, titulo?, anio?, kilometraje?, precio_publicado?, localidad?} → 201
     * El link puede ser de cualquier sitio. Si es de AutoCar, los datos del auto salen de la publicación.
     * El precio es el del taller en ese momento y queda fijo en la inspección. El pago es simulado (retenido).
     */
    public function crear(): void
    {
        $yo = (int)Auth::id();
        [$datos, $errores] = Pedido::validar(Request::json(), Fuente::hosts((string)Config::get('FRONT_URL', '')), (int)date('Y'));

        $taller = $datos['mecanico_id'] !== null ? Taller::elegible($datos['mecanico_id']) : null;
        if ($datos['mecanico_id'] !== null && $taller === null) {
            $errores['mecanico_id'] = 'Ese mecánico no está disponible. Elegí otro de la lista.';
        }
        $vehiculo = null;
        if ($datos['vehiculo_id'] !== null) {
            $vehiculo = self::vehiculo($datos['vehiculo_id']);
            if ($vehiculo === null || $vehiculo['estado'] !== 'disponible') {
                $errores['url'] = 'Esa publicación de AutoCar no está disponible.';
            }
        }
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos del pedido.', $errores);
        }

        $conflicto = Pedido::conflicto($yo, $datos['mecanico_id'], $vehiculo !== null ? (int)$vehiculo['usuario_id'] : null);
        if ($conflicto !== null) {
            Response::error(409, ...$conflicto);
        }
        if (Inspeccion::enCursoPorUrl($yo, $datos['url']) !== null) {
            Response::error(409, 'duplicada', 'Ya tenés una inspección en curso para ese auto.');
        }

        if ($vehiculo !== null) { // lo que no completó el comprador sale de la publicación
            $datos['titulo'] ??= trim("{$vehiculo['marca']} {$vehiculo['modelo']} " . ($vehiculo['version'] ?? ''));
            $datos['anio'] ??= (int)$vehiculo['anio'];
            $datos['kilometraje'] ??= (int)$vehiculo['kilometraje'];
            $datos['precio_publicado'] ??= (float)$vehiculo['precio'];
            $datos['localidad'] ??= $vehiculo['localidad'];
        }

        $id = Inspeccion::crear($yo, $datos, Precio::desglose((float)$taller['precio_inspeccion']));
        header('Location: /api/inspecciones/' . $id);
        Response::json(['inspeccion' => Inspeccion::paraApi(Inspeccion::porId($id), [])], 201);
    }

    /** GET /api/mis-inspecciones → {items}: las que pidió el usuario, las más nuevas primero */
    public function mias(): void
    {
        Response::json(['items' => array_map(static fn(array $i): array => Inspeccion::paraApi($i),
            Inspeccion::delComprador((int)Auth::id()))]);
    }

    /**
     * GET /api/inspecciones/{id} → {inspeccion} con el checklist.
     * La ven el comprador, el mecánico asignado y quien resuelve reclamos (moderador y admin). Para el resto, 404.
     */
    public function detalle(int $id): void
    {
        $i = Inspeccion::porId($id);
        $yo = (int)Auth::id();
        $puedeVer = $i !== null && (
            (int)$i['comprador_id'] === $yo
            || (int)$i['mecanico_id'] === $yo
            || Auth::tiene('reclamos.resolver')
        );
        if (!$puedeVer) {
            Response::error(404, 'no_encontrado', 'La inspección no existe.');
        }
        Response::json(['inspeccion' => Inspeccion::paraApi($i, Inspeccion::items($id))]);
    }

    /** POST /api/inspecciones/{id}/cancelar: solo el comprador y antes de que el mecánico la acepte. */
    public function cancelar(int $id): void
    {
        $i = self::propia($id);
        if (!Estados::puedePasar($i['estado'], 'cancelada') || !Inspeccion::cambiarEstado($id, $i['estado'], 'cancelada')) {
            Response::error(409, 'estado_invalido', $i['estado'] === 'pedida'
                ? 'El mecánico acaba de responder el pedido. Recargá para ver cómo quedó.'
                : 'Solo se puede cancelar antes de que el mecánico acepte. La inspección está ' . $i['estado'] . '.');
        }
        Response::json(['inspeccion' => Inspeccion::paraApi(Inspeccion::porId($id), Inspeccion::items($id))]);
    }

    /**
     * POST /api/inspecciones/{id}/reclamo {descripcion, item_codigo?} → 201
     * Solo el comprador, con la inspección firmada, hasta 15 días después de la firma y una sola vez.
     */
    public function reclamar(int $id): void
    {
        $i = self::propia($id);
        [$datos, $errores] = Reclamo::validarPedido(Request::json());
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá el reclamo.', $errores);
        }
        if ($i['estado'] !== 'firmada') {
            Response::error(409, 'estado_invalido', 'Solo se puede reclamar una inspección firmada.');
        }
        if ($i['reclamo_id'] !== null) {
            Response::error(409, 'reclamo_existente', 'Ya hiciste un reclamo por esta inspección.');
        }
        if (!Estados::enPlazoDeReclamo(new DateTimeImmutable($i['firmada_at']), new DateTimeImmutable())) {
            Response::error(409, 'fuera_de_plazo', 'Pasaron más de ' . Estados::DIAS_RECLAMO . ' días desde la firma.');
        }
        if (Reclamos::crear($id, $datos) === null) { // otro pedido lo creó recién
            Response::error(409, 'reclamo_existente', 'Ya hiciste un reclamo por esta inspección.');
        }
        Response::json(['inspeccion' => Inspeccion::paraApi(Inspeccion::porId($id), Inspeccion::items($id))], 201);
    }

    /** La inspección si la pidió este usuario; si no, 404. */
    private static function propia(int $id): array
    {
        $i = Inspeccion::porId($id);
        if ($i === null || (int)$i['comprador_id'] !== (int)Auth::id()) {
            Response::error(404, 'no_encontrado', 'La inspección no existe.');
        }
        return $i;
    }

    private static function vehiculo(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, usuario_id, marca, modelo, version, anio, kilometraje, precio, localidad, estado
            FROM vehiculo WHERE id = :id');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }
}
