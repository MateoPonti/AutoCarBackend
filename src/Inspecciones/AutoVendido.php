<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Core\Database;
use AutoCar\Parametros\Parametros;
use DateTimeImmutable;

/**
 * Qué pasa con las inspecciones cuando el auto se vende (lo que pidió el profe el 13/11; decisiones del 14/11).
 * Solo para autos publicados en AutoCar: es lo único que se puede comprobar. Un aviso de otro sitio que desaparece
 * no siempre es un auto vendido (puede estar pausado), así que ahí no se hace nada solo.
 *
 * - El vendedor confirma el turno que dio el mecánico (turno_confirmado_at).
 * - Si la publicación pasa a vendida, eliminada o rechazada ANTES del turno, la inspección se cae sola ('caida') y se le
 *   devuelve todo al comprador: el mecánico no fue.
 * - Si el turno ya pasó (el mecánico fue), se firma igual: el informe queda en la patente. Si al firmar el auto ya está
 *   vendido, el comprador recibe un crédito (parámetro credito_vendido, % del precio) y no la devolución completa,
 *   porque lo que le dijo el mecánico ya lo escuchó.
 * - Cupón: si el turno estaba confirmado y el auto se vende dentro de las horas del cupón (parámetro horas_cupon)
 *   después de la firma, el comprador también recibe el crédito: no tuvo tiempo de decidir. Las dos condiciones se
 *   pueden comprobar (turno confirmado y fecha en que la publicación pasó a vendida).
 * Una inspección da un solo crédito.
 */
final class AutoVendido
{
    /** Los estados de la publicación que hacen caer el turno. 'pendiente' no: es una edición que se vuelve a revisar. */
    public const SACAN_EL_AVISO = ['vendido', 'eliminado', 'rechazado'];

    /**
     * ¿La inspección se cae cuando la publicación deja de estar? Función pura.
     * Pedida: sí. Aceptada: sí, salvo que el turno ya haya pasado (el mecánico fue y firma igual).
     */
    public static function seCae(string $estado, ?DateTimeImmutable $fechaTurno, DateTimeImmutable $ahora): bool
    {
        if ($estado === 'pedida') {
            return true;
        }
        return $estado === 'aceptada' && ($fechaTurno === null || $fechaTurno > $ahora);
    }

    /** ¿Corresponde el cupón? Turno confirmado y el auto vendido dentro de $horas después de la firma. Función pura. */
    public static function correspondeCupon(?DateTimeImmutable $turnoConfirmado, DateTimeImmutable $firmadaAt,
        DateTimeImmutable $vendidoAt, float $horas): bool
    {
        return $turnoConfirmado !== null && $vendidoAt >= $firmadaAt
            && $vendidoAt <= $firmadaAt->modify('+' . (int)round($horas * 60) . ' minutes');
    }

    /**
     * La publicación $vehiculoId acaba de pasar a $estado (la llama Vehiculo::cambiarEstado). Hace caer los turnos
     * que todavía no pasaron y, si se vendió, da el cupón a los que corresponde.
     */
    public static function alCambiarElAviso(int $vehiculoId, string $estado): void
    {
        if (!in_array($estado, self::SACAN_EL_AVISO, true)) {
            return;
        }
        $pdo = Database::pdo();
        $ahora = new DateTimeImmutable();
        $motivo = $estado === 'vendido'
            ? 'El auto se vendió antes del turno. Se te devuelve todo lo que pagaste.'
            : 'La publicación se dio de baja antes del turno. Se te devuelve todo lo que pagaste.';

        $st = $pdo->prepare("SELECT id, estado, fecha_turno FROM inspeccion
            WHERE vehiculo_id = :v AND estado IN ('pedida', 'aceptada')");
        $st->execute(['v' => $vehiculoId]);
        foreach ($st->fetchAll() as $i) {
            $turno = $i['fecha_turno'] === null ? null : new DateTimeImmutable($i['fecha_turno']);
            if (self::seCae($i['estado'], $turno, $ahora)) {
                Inspeccion::cambiarEstado((int)$i['id'], $i['estado'], 'caida', ['motivo_rechazo' => $motivo]);
            }
        }

        if ($estado !== 'vendido') {
            return;
        }
        $horas = Parametros::valor('horas_cupon');
        $st = $pdo->prepare("SELECT id, comprador_id, precio, turno_confirmado_at, firmada_at FROM inspeccion
            WHERE vehiculo_id = :v AND estado = 'firmada' AND turno_confirmado_at IS NOT NULL
              AND firmada_at >= now() - make_interval(hours => :h)");
        $st->execute(['v' => $vehiculoId, 'h' => (int)ceil($horas)]);
        foreach ($st->fetchAll() as $i) {
            if (self::correspondeCupon(new DateTimeImmutable($i['turno_confirmado_at']), new DateTimeImmutable($i['firmada_at']), $ahora, $horas)) {
                Creditos::dar((int)$i['comprador_id'], (int)$i['id'], 'cupon',
                    Precio::credito((float)$i['precio'], Parametros::valor('credito_vendido')));
            }
        }
    }

    /** Al firmar: si la publicación de AutoCar ya está vendida, el comprador recibe el crédito. */
    public static function alFirmar(array $inspeccion): void
    {
        if ($inspeccion['vehiculo_id'] !== null && ($inspeccion['vehiculo_estado'] ?? null) === 'vendido') {
            Creditos::dar((int)$inspeccion['comprador_id'], (int)$inspeccion['id'], 'auto_vendido',
                Precio::credito((float)$inspeccion['precio'], Parametros::valor('credito_vendido')));
        }
    }

    /**
     * Turnos de inspección de las publicaciones de un vendedor: los que tiene que confirmar y los confirmados que
     * todavía no pasaron. No dice qué mecánico es (el vendedor no lo elige ni lo conoce hasta la cita), solo la localidad del taller.
     */
    public static function turnosDelVendedor(int $vendedorId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT i.id, i.vehiculo_id, i.titulo, i.fecha_turno, i.turno_confirmado_at, t.localidad AS taller_localidad
               FROM inspeccion i
               JOIN vehiculo v ON v.id = i.vehiculo_id
               LEFT JOIN taller t ON t.usuario_id = i.mecanico_id
              WHERE v.usuario_id = :u AND i.estado = 'aceptada' AND i.fecha_turno > now() - interval '1 day'
              ORDER BY i.fecha_turno ASC, i.id LIMIT 100"
        );
        $st->execute(['u' => $vendedorId]);
        return array_map(static fn(array $f): array => self::turnoParaApi($f), $st->fetchAll());
    }

    public static function turnoParaApi(array $f): array
    {
        return [
            'id'               => (int)$f['id'],
            'vehiculo_id'      => (int)$f['vehiculo_id'],
            'titulo'           => $f['titulo'],
            'fecha_turno'      => $f['fecha_turno'] === null ? null : (new DateTimeImmutable($f['fecha_turno']))->format(DATE_ATOM),
            'confirmado_at'    => $f['turno_confirmado_at'] === null ? null : (new DateTimeImmutable($f['turno_confirmado_at']))->format(DATE_ATOM),
            'taller_localidad' => $f['taller_localidad'],
        ];
    }

    /**
     * El vendedor confirma el turno: solo el dueño de la publicación, con la inspección aceptada y el turno por venir.
     * @return array{0: ?array, 1: ?array{int, string, string}} [turno, error (status, código, mensaje)]
     */
    public static function confirmar(int $inspeccionId, int $vendedorId): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "SELECT i.id, i.vehiculo_id, i.titulo, i.estado, i.fecha_turno, i.turno_confirmado_at, t.localidad AS taller_localidad
               FROM inspeccion i JOIN vehiculo v ON v.id = i.vehiculo_id LEFT JOIN taller t ON t.usuario_id = i.mecanico_id
              WHERE i.id = :id AND v.usuario_id = :u"
        );
        $st->execute(['id' => $inspeccionId, 'u' => $vendedorId]);
        $i = $st->fetch();
        if (!$i) {
            return [null, [404, 'no_encontrado', 'El turno no existe.']];
        }
        if ($i['estado'] !== 'aceptada') {
            return [null, [409, 'estado_invalido', 'Ese turno ya no está vigente.']];
        }
        if (new DateTimeImmutable($i['fecha_turno']) <= new DateTimeImmutable()) {
            return [null, [409, 'turno_pasado', 'El turno ya pasó.']];
        }
        if ($i['turno_confirmado_at'] === null) {
            $pdo->prepare("UPDATE inspeccion SET turno_confirmado_at = now(), updated_at = now()
                WHERE id = :id AND estado = 'aceptada' AND turno_confirmado_at IS NULL")->execute(['id' => $inspeccionId]);
            $st->execute(['id' => $inspeccionId, 'u' => $vendedorId]);
            $i = $st->fetch();
        }
        return [self::turnoParaApi($i), null];
    }
}
