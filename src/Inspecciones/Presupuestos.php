<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Core\Database;
use AutoCar\Parametros\Parametros;
use DateTimeImmutable;

/**
 * Acceso a la base de los presupuestos (tabla presupuesto, migración 025). La regla del costo total está en CostoTotal.
 * Se piden solos al firmar (y el comprador los puede volver a pedir para un informe de antes); cada taller elegido
 * responde con un monto y un detalle, o rechaza.
 */
final class Presupuestos
{
    public const MAX_DETALLE = 500;

    private const COLUMNAS = 'p.id, p.inspeccion_id, p.item_codigo, p.mecanico_id, p.estado, p.monto, p.detalle, p.vigente_hasta,
        p.created_at, p.respondido_at, t.nombre AS taller, t.localidad AS taller_localidad, t.direccion AS taller_direccion';

    /**
     * Pide presupuesto de cada arreglo del informe a los talleres que elige CostoTotal::elegirTalleres. Si ya se
     * pidieron, no los repite. Se llama al firmar (dentro de la transacción) o cuando el comprador los pide.
     * → cuántos pedidos nuevos se crearon
     */
    public static function pedir(int $inspeccionId, int $inspectorId, ?string $localidadInspector): int
    {
        $arreglos = CostoTotal::arreglos(Inspeccion::items($inspeccionId));
        if (!$arreglos) {
            return 0;
        }
        $talleres = CostoTotal::elegirTalleres(
            $localidadInspector !== null ? Taller::red($localidadInspector) : [],
            Taller::red(null),
            $inspectorId,
            (int)Parametros::valor('presupuestos_por_arreglo'),
        );
        $alta = Database::pdo()->prepare('INSERT INTO presupuesto (inspeccion_id, item_codigo, mecanico_id)
            VALUES (:i, :c, :m) ON CONFLICT (inspeccion_id, item_codigo, mecanico_id) DO NOTHING');
        $nuevos = 0;
        foreach (array_keys($arreglos) as $codigo) {
            foreach ($talleres as $mecanicoId) {
                $alta->execute(['i' => $inspeccionId, 'c' => $codigo, 'm' => $mecanicoId]);
                $nuevos += $alta->rowCount();
            }
        }
        return $nuevos;
    }

    /** Los presupuestos de una inspección, por ítem y del más barato al más caro (los sin responder al final). */
    public static function deLaInspeccion(int $inspeccionId): array
    {
        $st = Database::pdo()->prepare('SELECT ' . self::COLUMNAS . ' FROM presupuesto p
            LEFT JOIN taller t ON t.usuario_id = p.mecanico_id
            WHERE p.inspeccion_id = :i ORDER BY p.item_codigo, p.monto ASC NULLS LAST, p.id');
        $st->execute(['i' => $inspeccionId]);
        return $st->fetchAll();
    }

    /** Lo que le pidieron a un taller: con el auto, la ubicación y la nota del ítem, para poder presupuestar. */
    public static function delTaller(int $mecanicoId, ?string $estado): array
    {
        $where = 'p.mecanico_id = :m' . ($estado !== null ? ' AND p.estado = :e' : '');
        $st = Database::pdo()->prepare('SELECT ' . self::COLUMNAS . ', i.titulo, i.anio, i.kilometraje, i.localidad, i.patente,
                it.resultado AS item_resultado, it.nota AS item_nota
            FROM presupuesto p
            JOIN inspeccion i ON i.id = p.inspeccion_id
            LEFT JOIN taller t ON t.usuario_id = p.mecanico_id
            LEFT JOIN inspeccion_item it ON it.inspeccion_id = p.inspeccion_id AND it.codigo = p.item_codigo
            WHERE ' . $where . " ORDER BY (p.estado = 'pedido') DESC, p.created_at DESC, p.id LIMIT 100");
        $st->execute($estado !== null ? ['m' => $mecanicoId, 'e' => $estado] : ['m' => $mecanicoId]);
        return $st->fetchAll();
    }

    public static function porId(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT ' . self::COLUMNAS . ' FROM presupuesto p
            LEFT JOIN taller t ON t.usuario_id = p.mecanico_id WHERE p.id = :id');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    /**
     * Valida la respuesta del taller: {monto, detalle} o {rechazar: true}. Función pura.
     * @return array{0: array{estado: string, monto: ?float, detalle: ?string}, 1: array<string, string>}
     */
    public static function validarRespuesta(array $in): array
    {
        if (($in['rechazar'] ?? false) === true) {
            return [['estado' => 'rechazado', 'monto' => null, 'detalle' => null], []];
        }
        $errores = [];
        $monto = $in['monto'] ?? null;
        if (is_string($monto) && is_numeric($monto)) {
            $monto = $monto + 0;
        }
        if ((!is_int($monto) && !is_float($monto)) || $monto <= 0 || $monto > 100000000) {
            $errores['monto'] = 'Ingresá el monto del arreglo en pesos.';
        }
        $detalle = is_string($in['detalle'] ?? null) ? trim($in['detalle']) : '';
        $largo = mb_strlen($detalle);
        if ($largo < 3 || $largo > self::MAX_DETALLE) {
            $errores['detalle'] = 'Contá qué incluye el arreglo (entre 3 y ' . self::MAX_DETALLE . ' caracteres).';
        }
        return [['estado' => 'respondido', 'monto' => $errores ? null : (float)$monto, 'detalle' => $detalle], $errores];
    }

    /** Guarda la respuesta si el pedido sigue sin responder. → false si ya estaba respondido o rechazado. */
    public static function responder(int $id, array $respuesta): bool
    {
        $st = Database::pdo()->prepare(
            "UPDATE presupuesto SET estado = :e, monto = :m, detalle = :d, respondido_at = now(),
               vigente_hasta = CASE WHEN :e2 = 'respondido' THEN now() + make_interval(days => :dias) END
             WHERE id = :id AND estado = 'pedido'"
        );
        $st->execute(['id' => $id, 'e' => $respuesta['estado'], 'e2' => $respuesta['estado'], 'm' => $respuesta['monto'],
            'd' => $respuesta['detalle'], 'dias' => (int)Parametros::valor('dias_presupuesto')]);
        return $st->rowCount() === 1;
    }

    /** Un presupuesto como lo ven el comprador (en el informe) y el taller (con $paraTaller, el auto y la nota del ítem). */
    public static function paraApi(array $p, bool $paraTaller = false): array
    {
        $fecha = static fn($v): ?string => $v === null ? null : (new DateTimeImmutable($v))->format(DATE_ATOM);
        $datos = [
            'id'            => (int)$p['id'],
            'inspeccion_id' => (int)$p['inspeccion_id'],
            'item_codigo'   => $p['item_codigo'],
            'estado'        => $p['estado'],
            'vigente'       => CostoTotal::vigente($p, new DateTimeImmutable()),
            'monto'         => $p['monto'] === null ? null : (float)$p['monto'],
            'detalle'       => $p['detalle'],
            'vigente_hasta' => $fecha($p['vigente_hasta']),
            'created_at'    => $fecha($p['created_at']),
            'respondido_at' => $fecha($p['respondido_at']),
            'taller'        => ['id' => (int)$p['mecanico_id'], 'nombre' => $p['taller'], 'localidad' => $p['taller_localidad'],
                'direccion' => $p['taller_direccion']],
        ];
        if ($paraTaller) {
            $datos['auto'] = [
                'titulo' => $p['titulo'], 'anio' => $p['anio'] === null ? null : (int)$p['anio'],
                'kilometraje' => $p['kilometraje'] === null ? null : (int)$p['kilometraje'], 'localidad' => $p['localidad'],
            ];
            $datos['item'] = ['resultado' => $p['item_resultado'], 'nota' => $p['item_nota']];
        }
        return $datos;
    }
}
