<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Auth\Auth;
use AutoCar\Catalogo\MarcaModelo;
use AutoCar\Core\Config;
use AutoCar\Core\Database;
use AutoCar\Core\Request;
use AutoCar\Core\Response;
use DateTimeImmutable;
use PDOException;

/**
 * Lado del comprador (y lo público): ranking de mecánicos (B3), pedir (B4), mis inspecciones, detalle y
 * cancelar (B5), y reclamar (B8). Cada comprador ve solo sus inspecciones: las de otro responden 404.
 */
final class InspeccionController
{
    private const DUPLICADA = 'Ya tenés una inspección en curso para ese auto.';

    /**
     * GET /api/mecanicos?localidad=&marca=&modelo=&orden=especialidad|ranking (público)
     * → {items: [mecánico con puntaje], localidades, orden}
     * Con marca (la del auto que se quiere revisar), cada mecánico trae "revisados" (cuántas firmó de ese modelo y
     * de otros de la marca, y en cuántas procedió un reclamo) y "especialidad" (Ranking::especialidad, o null).
     * Así, el orden por defecto es por especialidad (Ranking::porEspecialidad); con orden=ranking, el ranking general.
     */
    public function mecanicos(): void
    {
        $texto = static fn(string $clave, int $largo): ?string => is_string($_GET[$clave] ?? null) && trim($_GET[$clave]) !== ''
            ? mb_substr(trim($_GET[$clave]), 0, $largo) : null;
        $localidad = $texto('localidad', 100);
        $marca = $texto('marca', 50);
        $modelo = $marca !== null ? $texto('modelo', 60) : null;
        $orden = $marca !== null && ($_GET['orden'] ?? '') !== 'ranking' ? 'especialidad' : 'ranking';

        $items = array_map([Taller::class, 'paraRanking'], Taller::red($localidad));
        if ($marca !== null) {
            $revisados = Taller::revisados($marca, $modelo);
            $nada = ['modelo' => 0, 'reclamos_modelo' => 0, 'marca' => 0, 'reclamos_marca' => 0];
            foreach ($items as &$m) {
                $m['revisados'] = $revisados[$m['id']] ?? $nada;
                $m['especialidad'] = Ranking::especialidad($m['revisados']);
            }
            unset($m);
            if ($orden === 'especialidad') {
                $items = Ranking::porEspecialidad($items);
            }
        }
        Response::json(['items' => $items, 'localidades' => Taller::localidades(), 'orden' => $orden]);
    }

    /**
     * POST /api/inspecciones {url, mecanico_id, titulo?, anio?, kilometraje?, precio_publicado?, localidad?} → 201
     * El link puede ser de cualquier sitio. Si es de AutoCar, los datos del auto salen siempre de la publicación
     * (lo que mande el comprador no cuenta: así el precio del informe es el publicado).
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
        // Marca y modelo (idea B): de la publicación si es de AutoCar; si no, los que eligió el comprador, del catálogo
        if ($vehiculo !== null) {
            $datos['marca'] = $vehiculo['marca'];
            $datos['modelo'] = $vehiculo['modelo'];
        } elseif ($datos['marca'] !== null || $datos['modelo'] !== null) {
            $errores += MarcaModelo::errores((string)$datos['marca'], (string)$datos['modelo']);
        }
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos del pedido.', $errores);
        }

        $conflicto = Pedido::conflicto($yo, $datos['mecanico_id'], $vehiculo !== null ? (int)$vehiculo['usuario_id'] : null);
        if ($conflicto !== null) {
            Response::error(409, ...$conflicto);
        }
        if (Inspeccion::enCursoPorUrl($yo, Fuente::clave($datos['url'], $datos['fuente'], $datos['vehiculo_id'])) !== null) {
            Response::error(409, 'duplicada', self::DUPLICADA);
        }

        if ($vehiculo !== null) { // los datos del auto son los de la publicación, no los que mande el comprador
            $datos['titulo'] = trim("{$vehiculo['marca']} {$vehiculo['modelo']} " . ($vehiculo['version'] ?? ''));
            $datos['anio'] = (int)$vehiculo['anio'];
            $datos['kilometraje'] = (int)$vehiculo['kilometraje'];
            $datos['precio_publicado'] = (float)$vehiculo['precio'];
            $datos['localidad'] = $vehiculo['localidad'];
        }

        // Dos pedidos a la vez pasan juntos el chequeo de arriba: el índice único de la base deja entrar solo uno.
        // Si el comprador tiene un crédito (un auto anterior se vendió), se descuenta solo en la misma transacción.
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $id = Inspeccion::crear($yo, $datos, Precio::desgloseActual((float)$taller['precio_inspeccion']));
            Creditos::usarEn($yo, $id, (float)$taller['precio_inspeccion']);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() !== '23505') { // 23505 = unique_violation
                throw $e;
            }
            Response::error(409, 'duplicada', self::DUPLICADA);
        }
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
        $i = self::visible($id);
        Response::json(['inspeccion' => Inspeccion::paraApi($i, Inspeccion::items($id))]);
    }

    /** GET /api/inspecciones/{id}/patente-foto: la foto de la chapa. La ven los mismos que el detalle. */
    public function fotoPatente(int $id): void
    {
        self::visible($id);
        $f = Inspeccion::fotoPatente($id);
        if ($f === null) {
            Response::error(404, 'no_encontrado', 'Esta inspección no tiene foto de la chapa.');
        }
        Response::archivo($f['datos'], $f['mime'], false);
    }

    /** GET /api/inspecciones/{id}/item-foto?codigo=motor: la foto de un ítem del checklist. La ven los mismos que el detalle. */
    public function fotoItem(int $id): void
    {
        self::visible($id);
        $codigo = is_string($_GET['codigo'] ?? null) ? $_GET['codigo'] : '';
        $f = isset(Checklist::ITEMS[$codigo]) ? Inspeccion::fotoItem($id, $codigo) : null;
        if ($f === null) {
            Response::error(404, 'no_encontrado', 'Ese ítem no tiene foto.');
        }
        Response::archivo($f['datos'], $f['mime'], false);
    }

    /** La inspección si la puede ver quien pregunta (comprador, mecánico o quien resuelve reclamos); si no, 404. */
    private static function visible(int $id): array
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
        return $i;
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
     * Solo el comprador, con la inspección firmada, dentro de los días de reclamo de su inspección (parámetro dias_reclamo) y una sola vez.
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
        if (!Estados::enPlazoDeReclamo(new DateTimeImmutable($i['firmada_at']), new DateTimeImmutable(), (int)$i['dias_reclamo'])) {
            Response::error(409, 'fuera_de_plazo', 'Pasaron más de ' . (int)$i['dias_reclamo'] . ' días desde la firma.');
        }
        if (Reclamos::crear($id, $datos) === null) { // otro pedido lo creó recién
            Response::error(409, 'reclamo_existente', 'Ya hiciste un reclamo por esta inspección.');
        }
        Response::json(['inspeccion' => Inspeccion::paraApi(Inspeccion::porId($id), Inspeccion::items($id))], 201);
    }

    /** GET /api/mis-creditos → {items, total}: los créditos que el comprador todavía no usó (se descuentan solos al pedir). */
    public function creditos(): void
    {
        $items = Creditos::disponibles((int)Auth::id());
        Response::json(['items' => $items, 'total' => array_sum(array_column($items, 'monto'))]);
    }

    /**
     * GET /api/mis-vehiculos/turnos → {items}: los turnos de inspección de las publicaciones del vendedor, para que
     * los confirme. No dice qué mecánico es: solo el día y la localidad del taller.
     */
    public function turnos(): void
    {
        Response::json(['items' => AutoVendido::turnosDelVendedor((int)Auth::id())]);
    }

    /** POST /api/inspecciones/{id}/confirmar-turno: el vendedor (dueño de la publicación) confirma el turno. → {turno} */
    public function confirmarTurno(int $id): void
    {
        [$turno, $error] = AutoVendido::confirmar($id, (int)Auth::id());
        if ($error !== null) {
            Response::error(...$error);
        }
        Response::json(['turno' => $turno]);
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
