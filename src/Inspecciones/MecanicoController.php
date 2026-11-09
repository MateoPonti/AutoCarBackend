<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Auth\Auth;
use AutoCar\Core\Database;
use AutoCar\Core\Request;
use AutoCar\Core\Response;
use DateTimeImmutable;

/**
 * Lado del mecánico (permiso verificaciones.realizar, ver routes.php): perfil del taller (B2),
 * bandeja con aceptar y rechazar (B6), y checklist y firma (B7). Cada mecánico ve y toca solo sus
 * inspecciones: las de otro responden 404, igual que las que no existen, para no contar cuáles hay.
 */
final class MecanicoController
{
    /** GET /api/mecanico/perfil → {taller} o {taller: null} si todavía no lo completó */
    public function perfil(): void
    {
        Response::json(['taller' => Taller::paraApi(Taller::delMecanico(self::yo()))]);
    }

    /** PUT /api/mecanico/perfil {nombre, direccion, localidad, telefono, precio_inspeccion} → {taller} */
    public function guardarPerfil(): void
    {
        [$datos, $errores] = Taller::validar(Request::json());
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos del taller.', $errores);
        }
        Response::json(['taller' => Taller::paraApi(Taller::guardar(self::yo(), $datos))]);
    }

    /** GET /api/mecanico/inspecciones?estado= → {items, conteos: {pedida, aceptada, ...}} */
    public function bandeja(): void
    {
        $estado = is_string($_GET['estado'] ?? null) && $_GET['estado'] !== '' ? $_GET['estado'] : null;
        if ($estado !== null && !in_array($estado, Estados::ESTADOS, true)) {
            Response::error(422, 'validacion', 'Revisá el filtro.', ['estado' => 'Ese estado no existe.']);
        }
        Response::json([
            'items'   => array_map(static fn(array $i): array => Inspeccion::paraApi($i), Inspeccion::delMecanico(self::yo(), $estado)),
            'conteos' => Inspeccion::conteos(self::yo()),
        ]);
    }

    /**
     * PATCH /api/mecanico/inspecciones/{id}
     *   {"accion": "aceptar", "fecha_turno": "2026-11-12T13:00:00Z"}  o  {"accion": "rechazar", "motivo": "..."}
     * Solo desde "pedida". Rechazar le devuelve todo al comprador (pago reembolsado).
     */
    public function cambiarEstado(int $id): void
    {
        $inspeccion = self::propia($id);
        $in = Request::json();
        $accion = Request::texto($in, 'accion');

        if ($accion === 'aceptar') {
            if (Taller::delMecanico(self::yo()) === null) {
                self::fallar(409, 'sin_taller', 'Completá el perfil del taller antes de aceptar pedidos.');
            }
            [$fecha, $error] = Estados::validarTurno($in['fecha_turno'] ?? null, new DateTimeImmutable());
            if ($error !== null) {
                Response::error(422, 'validacion', 'Revisá la fecha del turno.', ['fecha_turno' => $error]);
            }
            $hacia = 'aceptada';
            $extra = ['fecha_turno' => $fecha->format('Y-m-d H:i:sP')];
        } elseif ($accion === 'rechazar') {
            [$motivo, $error] = Estados::validarMotivo($in['motivo'] ?? null);
            if ($error !== null) {
                Response::error(422, 'validacion', 'Falta el motivo.', ['motivo' => $error]);
            }
            $hacia = 'rechazada';
            $extra = ['motivo_rechazo' => $motivo];
        } else {
            Response::error(422, 'validacion', 'Revisá la acción.', ['accion' => 'Tiene que ser aceptar o rechazar.']);
        }

        if (!Estados::puedePasar($inspeccion['estado'], $hacia)
            || !Inspeccion::cambiarEstado($id, $inspeccion['estado'], $hacia, $extra)) {
            self::fallar(409, 'estado_invalido', self::noSePuede($inspeccion['estado'], 'aceptar o rechazar'));
        }
        self::responderDetalle($id);
    }

    /**
     * PUT /api/mecanico/inspecciones/{id}/informe {items: [{codigo, resultado, nota}], conclusion?, resumen?}
     * Borrador: reemplaza lo guardado y se puede mandar a medias. Solo con la inspección aceptada.
     */
    public function guardarInforme(int $id): void
    {
        $in = Request::json();
        [$items, $error] = Checklist::validarItems($in['items'] ?? null, false);
        if ($error !== null) {
            Response::error(422, 'validacion', $error, ['items' => $error]);
        }
        $conclusion = $in['conclusion'] ?? null;
        if ($conclusion === '') {
            $conclusion = null;
        }
        if ($conclusion !== null && !in_array($conclusion, Checklist::CONCLUSIONES, true)) {
            Response::error(422, 'validacion', 'Revisá la conclusión.',
                ['conclusion' => 'Tiene que ser recomendable, con_reparos o no_recomendable.']);
        }
        $resumen = is_string($in['resumen'] ?? null) ? trim($in['resumen']) : '';
        if (mb_strlen($resumen) > Checklist::MAX_RESUMEN) {
            Response::error(422, 'validacion', 'Revisá el resumen.',
                ['resumen' => 'Puede tener hasta ' . Checklist::MAX_RESUMEN . ' caracteres.']);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $inspeccion = self::propia($id, true);
        self::exigirAceptada($inspeccion);
        Inspeccion::guardarInforme($id, $items, $conclusion, $resumen === '' ? null : $resumen);
        $pdo->commit();
        self::responderDetalle($id);
    }

    /**
     * POST /api/mecanico/inspecciones/{id}/firma: firma lo que está guardado.
     * Exige los 10 ítems y la conclusión. Guarda el nombre del mecánico y del taller, el momento y la huella
     * (SHA-256 del informe, ver Huella). Después, cualquier cambio da 409.
     */
    public function firmar(int $id): void
    {
        $taller = Taller::delMecanico(self::yo());
        if ($taller === null) {
            self::fallar(409, 'sin_taller', 'Completá el perfil del taller antes de firmar.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $inspeccion = self::propia($id, true);
        self::exigirAceptada($inspeccion);

        $guardados = [];
        foreach (Inspeccion::items($id) as $codigo => [$resultado, $nota]) {
            $guardados[] = ['codigo' => $codigo, 'resultado' => $resultado, 'nota' => $nota];
        }
        [$items, $error] = Checklist::validarItems($guardados, true);
        $error ??= Checklist::validarConclusion($inspeccion['conclusion'], $inspeccion['resumen']);
        if ($error !== null) {
            self::fallar(422, 'informe_incompleto', $error);
        }

        $momento = new DateTimeImmutable('@' . time()); // UTC y al segundo, como entra en la huella
        $firmadoPor = (string)(Auth::usuario()['nombre'] ?? '');
        $huella = Huella::calcular($id, $items, $inspeccion['conclusion'], $inspeccion['resumen'],
            $firmadoPor, $taller['nombre'], Inspeccion::momentoHuella($momento));
        Inspeccion::firmar($id, $firmadoPor, $taller['nombre'], $momento, $huella);
        $pdo->commit();
        self::responderDetalle($id);
    }

    // ---------- Ayudas ----------

    private static function yo(): int
    {
        return (int)Auth::id();
    }

    /** La inspección si es de este mecánico; si no, 404. Con $bloquear, la fila queda bloqueada hasta el commit. */
    private static function propia(int $id, bool $bloquear = false): array
    {
        $i = Inspeccion::porId($id, $bloquear);
        if ($i === null || (int)$i['mecanico_id'] !== self::yo()) {
            self::fallar(404, 'no_encontrado', 'La inspección no existe.');
        }
        return $i;
    }

    private static function exigirAceptada(array $inspeccion): void
    {
        if ($inspeccion['estado'] === 'firmada') {
            self::fallar(409, 'firmada', 'El informe ya está firmado y no se puede cambiar.');
        }
        if ($inspeccion['estado'] !== 'aceptada') {
            self::fallar(409, 'estado_invalido', self::noSePuede($inspeccion['estado'], 'cargar el informe'));
        }
    }

    private static function noSePuede(string $estado, string $que): string
    {
        return "No se puede $que: la inspección está " . $estado . '.';
    }

    /** Responde un error deshaciendo la transacción abierta, si hay una. */
    private static function fallar(int $status, string $codigo, string $mensaje): never
    {
        $pdo = Database::pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        Response::error($status, $codigo, $mensaje);
    }

    private static function responderDetalle(int $id): never
    {
        Response::json(['inspeccion' => Inspeccion::paraApi(Inspeccion::porId($id), Inspeccion::items($id))]);
    }
}
