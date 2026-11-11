<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Acceso a la base de las inspecciones (tablas inspeccion e inspeccion_item).
 * Las reglas están en las clases puras de al lado (Estados, Checklist, Huella); acá solo se lee y se guarda.
 * Los cambios de estado se hacen con "UPDATE ... WHERE estado = <el de antes>": si dos pedidos llegan a la vez,
 * solo uno cambia la fila y el otro recibe 409.
 */
final class Inspeccion
{
    public const POR_PAGINA = 100;

    private const COLUMNAS = 'i.id, i.comprador_id, i.mecanico_id, i.vehiculo_id, i.url, i.fuente, i.titulo, i.anio,
        i.kilometraje, i.precio_publicado, i.localidad, i.estado, i.fecha_turno, i.motivo_rechazo, i.precio,
        i.monto_mecanico, i.monto_autocar, i.monto_fondo, i.pago, i.conclusion, i.resumen, i.firmado_por,
        i.taller_firma, i.firmada_at, i.huella, i.created_at, i.updated_at, u.nombre AS comprador_nombre,
        mu.nombre AS mecanico_nombre, t.nombre AS taller_nombre, t.direccion AS taller_direccion, t.localidad AS taller_localidad,
        r.id AS reclamo_id, r.item_codigo AS reclamo_item, r.descripcion AS reclamo_descripcion, r.estado AS reclamo_estado,
        r.compensacion AS reclamo_compensacion, r.respuesta AS reclamo_respuesta, r.created_at AS reclamo_created_at,
        r.resuelto_at AS reclamo_resuelto_at';

    // El comprador, el mecánico con su taller y el reclamo (si hay) vienen en la misma consulta
    private const DESDE = 'FROM inspeccion i
        JOIN usuario u ON u.id = i.comprador_id
        JOIN usuario mu ON mu.id = i.mecanico_id
        LEFT JOIN taller t ON t.usuario_id = i.mecanico_id
        LEFT JOIN reclamo r ON r.inspeccion_id = i.id';

    // Cómo se ordena cada pestaña de la bandeja del mecánico
    private const ORDEN = [
        'pedida'       => 'i.created_at ASC',
        'aceptada'     => 'i.fecha_turno ASC NULLS LAST',
        'firmada'      => 'i.firmada_at DESC',
        'rechazada'    => 'i.updated_at DESC',
        'cancelada'    => 'i.updated_at DESC',
        'no_realizada' => 'i.updated_at DESC',
    ];

    private static function pdo(): PDO
    {
        return Database::pdo();
    }

    public static function porId(int $id, bool $bloquear = false): ?array
    {
        $st = self::pdo()->prepare('SELECT ' . self::COLUMNAS . ' ' . self::DESDE
            . ' WHERE i.id = :id' . ($bloquear ? ' FOR UPDATE OF i' : ''));
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    /** @return array<string, array{0: string, 1: ?string}> codigo => [resultado, nota], en el orden del checklist */
    public static function items(int $id): array
    {
        $st = self::pdo()->prepare('SELECT codigo, resultado, nota FROM inspeccion_item WHERE inspeccion_id = :id');
        $st->execute(['id' => $id]);
        $porCodigo = [];
        foreach ($st->fetchAll() as $f) {
            $porCodigo[$f['codigo']] = [$f['resultado'], $f['nota']];
        }
        return array_intersect_key(array_replace(Checklist::ITEMS, $porCodigo), $porCodigo);
    }

    /** Bandeja del mecánico: sus inspecciones, de un estado o de todos. */
    public static function delMecanico(int $mecanicoId, ?string $estado): array
    {
        $where = 'i.mecanico_id = :m';
        $params = ['m' => $mecanicoId];
        if ($estado !== null) {
            $where .= ' AND i.estado = :e';
            $params['e'] = $estado;
        }
        $orden = $estado !== null ? self::ORDEN[$estado] : 'i.created_at DESC';
        $st = self::pdo()->prepare('SELECT ' . self::COLUMNAS . ' ' . self::DESDE
            . " WHERE $where ORDER BY $orden, i.id LIMIT " . self::POR_PAGINA);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Las inspecciones que pidió un comprador, las más nuevas primero (B5). */
    public static function delComprador(int $compradorId): array
    {
        $st = self::pdo()->prepare('SELECT ' . self::COLUMNAS . ' ' . self::DESDE
            . ' WHERE i.comprador_id = :c ORDER BY i.created_at DESC, i.id DESC LIMIT ' . self::POR_PAGINA);
        $st->execute(['c' => $compradorId]);
        return $st->fetchAll();
    }

    /**
     * ¿El comprador ya tiene un pedido en curso (pedida o aceptada) para ese mismo aviso? $clave = Fuente::clave.
     * Es el aviso amable; si llegan dos pedidos a la vez, el que frena al segundo es el índice único de la 020.
     */
    public static function enCursoPorUrl(int $compradorId, string $clave): ?int
    {
        $st = self::pdo()->prepare("SELECT id FROM inspeccion
            WHERE comprador_id = :c AND url_clave = :u AND estado IN ('pedida', 'aceptada') LIMIT 1");
        $st->execute(['c' => $compradorId, 'u' => $clave]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** Alta del pedido (B4). $datos ya validados con Pedido::validar; $montos de Precio::desglose. → id */
    public static function crear(int $compradorId, array $datos, array $montos): int
    {
        $st = self::pdo()->prepare(
            'INSERT INTO inspeccion (comprador_id, mecanico_id, vehiculo_id, url, url_clave, fuente, titulo, anio, kilometraje,
               precio_publicado, localidad, precio, monto_mecanico, monto_autocar, monto_fondo)
             VALUES (:c, :m, :v, :url, :clave, :fuente, :titulo, :anio, :km, :pp, :loc, :precio, :mm, :ma, :mf)
             RETURNING id'
        );
        $st->execute([
            'c' => $compradorId, 'm' => $datos['mecanico_id'], 'v' => $datos['vehiculo_id'], 'url' => $datos['url'],
            'clave' => Fuente::clave($datos['url'], $datos['fuente'], $datos['vehiculo_id']),
            'fuente' => $datos['fuente'], 'titulo' => $datos['titulo'], 'anio' => $datos['anio'],
            'km' => $datos['kilometraje'], 'pp' => $datos['precio_publicado'], 'loc' => $datos['localidad'],
            'precio' => $montos['precio'], 'mm' => $montos['monto_mecanico'], 'ma' => $montos['monto_autocar'],
            'mf' => $montos['monto_fondo'],
        ]);
        return (int)$st->fetchColumn();
    }

    /** Hasta cuándo puede reclamar el comprador, o null si no está firmada. */
    public static function reclamoHasta(?string $firmadaAt): ?DateTimeImmutable
    {
        return $firmadaAt === null ? null : (new DateTimeImmutable($firmadaAt))->modify('+' . Estados::DIAS_RECLAMO . ' days');
    }

    /** @return array<string, int> cantidad por estado (todos los estados, con 0 si no hay) */
    public static function conteos(int $mecanicoId): array
    {
        $st = self::pdo()->prepare('SELECT estado, COUNT(*) AS n FROM inspeccion WHERE mecanico_id = :m GROUP BY estado');
        $st->execute(['m' => $mecanicoId]);
        $conteos = array_fill_keys(Estados::ESTADOS, 0);
        foreach ($st->fetchAll() as $f) {
            $conteos[$f['estado']] = (int)$f['n'];
        }
        return $conteos;
    }

    /**
     * Pasa la inspección de $desde a $hacia, solo si sigue en $desde. El pago cambia según Estados::pagoAl().
     * $extra: columnas que se guardan con el cambio (fecha_turno, motivo_rechazo: el del rechazo o el de por qué no se pudo hacer).
     * @return bool false si la inspección ya no estaba en $desde
     */
    public static function cambiarEstado(int $id, string $desde, string $hacia, array $extra = []): bool
    {
        $set = ['estado = :hacia', 'updated_at = now()'];
        $params = ['id' => $id, 'desde' => $desde, 'hacia' => $hacia];
        $pago = Estados::pagoAl($hacia);
        if ($pago !== null) {
            $set[] = 'pago = :pago';
            $params['pago'] = $pago;
        }
        foreach (['fecha_turno', 'motivo_rechazo'] as $columna) {
            if (array_key_exists($columna, $extra)) {
                $set[] = "$columna = :$columna";
                $params[$columna] = $extra[$columna];
            }
        }
        $st = self::pdo()->prepare('UPDATE inspeccion SET ' . implode(', ', $set) . ' WHERE id = :id AND estado = :desde');
        $st->execute($params);
        return $st->rowCount() === 1;
    }

    /** Reemplaza el checklist guardado (borrador). Se llama dentro de una transacción con la fila bloqueada. */
    public static function guardarInforme(int $id, array $items, ?string $conclusion, ?string $resumen): void
    {
        $pdo = self::pdo();
        $pdo->prepare('DELETE FROM inspeccion_item WHERE inspeccion_id = :id')->execute(['id' => $id]);
        $alta = $pdo->prepare('INSERT INTO inspeccion_item (inspeccion_id, codigo, resultado, nota) VALUES (:id, :c, :r, :n)');
        foreach ($items as $codigo => [$resultado, $nota]) {
            $alta->execute(['id' => $id, 'c' => $codigo, 'r' => $resultado, 'n' => $nota]);
        }
        $pdo->prepare('UPDATE inspeccion SET conclusion = :c, resumen = :r, updated_at = now() WHERE id = :id')
            ->execute(['id' => $id, 'c' => $conclusion, 'r' => $resumen]);
    }

    /** Cierra el informe. Se llama dentro de una transacción con la fila bloqueada y el estado ya revisado. */
    public static function firmar(int $id, string $firmadoPor, string $taller, DateTimeImmutable $momento, string $huella): void
    {
        self::pdo()->prepare(
            "UPDATE inspeccion SET estado = 'firmada', pago = :pago, firmado_por = :por, taller_firma = :taller,
               firmada_at = :momento, huella = :huella, updated_at = now()
             WHERE id = :id AND estado = 'aceptada'"
        )->execute([
            'id' => $id, 'pago' => Estados::pagoAl('firmada'), 'por' => $firmadoPor, 'taller' => $taller,
            'momento' => $momento->format('Y-m-d H:i:sP'), 'huella' => $huella,
        ]);
    }

    /**
     * El momento de la firma como entra en la huella: UTC, al segundo ("2026-11-12T13:00:05Z").
     * Sirve igual con lo que se guarda y con lo que se lee de la base, así la huella se puede volver a calcular.
     */
    public static function momentoHuella(DateTimeImmutable|string $momento): string
    {
        $m = is_string($momento) ? new DateTimeImmutable($momento) : $momento;
        return $m->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** Lo que se le devuelve al front. Con $items (detalle) incluye el checklist. */
    public static function paraApi(array $i, ?array $items = null): array
    {
        $numero = static fn($v): ?float => $v === null ? null : (float)$v;
        $entero = static fn($v): ?int => $v === null ? null : (int)$v;
        $fecha = static fn($v): ?string => $v === null ? null : (new DateTimeImmutable($v))->format(DATE_ATOM);
        $datos = [
            'id'               => (int)$i['id'],
            'estado'           => $i['estado'],
            'fuente'           => $i['fuente'],
            'url'              => $i['url'],
            'vehiculo_id'      => $entero($i['vehiculo_id']),
            'titulo'           => $i['titulo'],
            'anio'             => $entero($i['anio']),
            'kilometraje'      => $entero($i['kilometraje']),
            'precio_publicado' => $numero($i['precio_publicado']),
            'localidad'        => $i['localidad'],
            'fecha_turno'      => $fecha($i['fecha_turno']),
            'motivo_rechazo'   => $i['motivo_rechazo'],
            'precio'           => (float)$i['precio'],
            'monto_mecanico'   => (float)$i['monto_mecanico'],
            'monto_autocar'    => (float)$i['monto_autocar'],
            'monto_fondo'      => (float)$i['monto_fondo'],
            'pago'             => $i['pago'],
            'conclusion'       => $i['conclusion'],
            'resumen'          => $i['resumen'],
            'firmado_por'      => $i['firmado_por'],
            'taller_firma'     => $i['taller_firma'],
            'firmada_at'       => $fecha($i['firmada_at']),
            'huella'           => $i['huella'],
            'created_at'       => $fecha($i['created_at']),
            'mecanico_id'      => (int)$i['mecanico_id'],
            'comprador'        => ['id' => (int)$i['comprador_id'], 'nombre' => $i['comprador_nombre']],
            'mecanico'         => [
                'id'        => (int)$i['mecanico_id'],
                'nombre'    => $i['mecanico_nombre'],
                'taller'    => $i['taller_nombre'],
                'direccion' => $i['taller_direccion'],
                'localidad' => $i['taller_localidad'],
            ],
            'reclamo'          => $i['reclamo_id'] === null ? null : [
                'id'           => (int)$i['reclamo_id'],
                'estado'       => $i['reclamo_estado'],
                'item_codigo'  => $i['reclamo_item'],
                'descripcion'  => $i['reclamo_descripcion'],
                'compensacion' => $numero($i['reclamo_compensacion']),
                'respuesta'    => $i['reclamo_respuesta'],
                'created_at'   => $fecha($i['reclamo_created_at']),
                'resuelto_at'  => $fecha($i['reclamo_resuelto_at']),
            ],
        ];
        // El comprador puede reclamar una vez, hasta 15 días después de la firma
        $hasta = self::reclamoHasta($i['firmada_at']);
        $datos['reclamo_hasta'] = $hasta?->format(DATE_ATOM);
        $datos['puede_reclamar'] = $i['estado'] === 'firmada' && $i['reclamo_id'] === null
            && Estados::enPlazoDeReclamo(new DateTimeImmutable($i['firmada_at']), new DateTimeImmutable());
        if ($items !== null) {
            $datos['items'] = [];
            foreach ($items as $codigo => [$resultado, $nota]) {
                $datos['items'][] = ['codigo' => $codigo, 'resultado' => $resultado, 'nota' => $nota];
            }
        }
        return $datos;
    }
}
