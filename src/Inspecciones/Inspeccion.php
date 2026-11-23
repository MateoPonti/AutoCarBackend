<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

use AutoCar\Comparador\Clave;
use AutoCar\Core\Database;
use AutoCar\Pagos\Cobros;
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
        i.monto_mecanico, i.monto_autocar, i.pago, i.conclusion, i.resumen, i.firmado_por,
        i.taller_firma, i.firmada_at, i.huella, i.patente, i.created_at, i.updated_at, i.turno_confirmado_at, i.credito_usado,
        c.tipo AS credito_tipo, c.monto AS credito_monto, i.marca, i.modelo,
        EXISTS (SELECT 1 FROM inspeccion_patente_foto pf WHERE pf.inspeccion_id = i.id) AS tiene_foto_patente, u.nombre AS comprador_nombre,
        (SELECT v.estado FROM vehiculo v WHERE v.id = i.vehiculo_id) AS vehiculo_estado,
        mu.nombre AS mecanico_nombre, t.nombre AS taller_nombre, t.direccion AS taller_direccion, t.localidad AS taller_localidad';

    // El comprador y el mecánico con su taller vienen en la misma consulta
    private const DESDE = 'FROM inspeccion i
        JOIN usuario u ON u.id = i.comprador_id
        JOIN usuario mu ON mu.id = i.mecanico_id
        LEFT JOIN taller t ON t.usuario_id = i.mecanico_id
        LEFT JOIN credito c ON c.inspeccion_id = i.id';

    // Cómo se ordena cada pestaña de la bandeja del mecánico
    private const ORDEN = [
        'pedida'       => 'i.created_at ASC',
        'aceptada'     => 'i.fecha_turno ASC NULLS LAST',
        'firmada'      => 'i.firmada_at DESC',
        'rechazada'    => 'i.updated_at DESC',
        'cancelada'    => 'i.updated_at DESC',
        'no_realizada' => 'i.updated_at DESC',
        'caida'        => 'i.updated_at DESC',
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

    /**
     * Alta del pedido (B4). $datos ya validados con Pedido::validar; $montos de Precio::desgloseActual.
     * → id
     */
    public static function crear(int $compradorId, array $datos, array $montos): int
    {
        $st = self::pdo()->prepare(
            'INSERT INTO inspeccion (comprador_id, mecanico_id, vehiculo_id, url, url_clave, fuente, titulo, anio, kilometraje,
               precio_publicado, localidad, precio, monto_mecanico, monto_autocar, marca, modelo, origen)
             VALUES (:c, :m, :v, :url, :clave, :fuente, :titulo, :anio, :km, :pp, :loc, :precio, :mm, :ma, :marca, :modelo, :origen)
             RETURNING id'
        );
        $st->execute([
            'c' => $compradorId, 'm' => $datos['mecanico_id'], 'v' => $datos['vehiculo_id'], 'url' => $datos['url'],
            'clave' => Fuente::clave($datos['url'], $datos['fuente'], $datos['vehiculo_id']),
            'fuente' => $datos['fuente'], 'titulo' => $datos['titulo'], 'anio' => $datos['anio'],
            'km' => $datos['kilometraje'], 'pp' => $datos['precio_publicado'], 'loc' => $datos['localidad'],
            'precio' => $montos['precio'], 'mm' => $montos['monto_mecanico'], 'ma' => $montos['monto_autocar'],
            'marca' => $datos['marca'] ?? null, 'modelo' => $datos['modelo'] ?? null, 'origen' => $datos['origen'] ?? null,
        ]);
        return (int)$st->fetchColumn();
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
        if ($st->rowCount() !== 1) {
            return false;
        }
        if ($pago === 'reembolsado') { // se cayó, se canceló o se rechazó: el crédito que usó vuelve
            Creditos::liberar($id);
            Cobros::devolver($id); // y lo que pagó por Mercado Pago (si no pagó, el cobro se cancela)
        }
        return true;
    }

    /**
     * Reemplaza el checklist guardado (borrador). Se llama dentro de una transacción con la fila bloqueada.
     * La marca y el modelo solo cambian si vienen (si no, quedan los del pedido).
     */
    public static function guardarInforme(int $id, array $items, ?string $conclusion, ?string $resumen, ?string $patente = null,
        ?string $marca = null, ?string $modelo = null): void
    {
        $pdo = self::pdo();
        $pdo->prepare('DELETE FROM inspeccion_item WHERE inspeccion_id = :id')->execute(['id' => $id]);
        $alta = $pdo->prepare('INSERT INTO inspeccion_item (inspeccion_id, codigo, resultado, nota) VALUES (:id, :c, :r, :n)');
        foreach ($items as $codigo => [$resultado, $nota]) {
            $alta->execute(['id' => $id, 'c' => $codigo, 'r' => $resultado, 'n' => $nota]);
        }
        $pdo->prepare('UPDATE inspeccion SET conclusion = :c, resumen = :r, patente = :p, marca = COALESCE(:marca, marca),
               modelo = COALESCE(:modelo, modelo), updated_at = now() WHERE id = :id')
            ->execute(['id' => $id, 'c' => $conclusion, 'r' => $resumen, 'p' => $patente, 'marca' => $marca, 'modelo' => $modelo]);
    }

    /** La foto de la chapa (reemplaza la que hubiera). $tmp: archivo ya validado con Foto::validarSubidas. */
    public static function guardarFotoPatente(int $id, string $tmp, string $mime): void
    {
        $st = self::pdo()->prepare(
            'INSERT INTO inspeccion_patente_foto (inspeccion_id, mime, datos) VALUES (:id, :mime, :datos)
             ON CONFLICT (inspeccion_id) DO UPDATE SET mime = EXCLUDED.mime, datos = EXCLUDED.datos, created_at = now()'
        );
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':mime', $mime);
        $st->bindValue(':datos', (string)file_get_contents($tmp), PDO::PARAM_LOB);
        $st->execute();
    }

    /** La foto de la chapa: mime y datos, o null si no hay. */
    public static function fotoPatente(int $id): ?array
    {
        $st = self::pdo()->prepare('SELECT mime, datos FROM inspeccion_patente_foto WHERE inspeccion_id = :id');
        $st->execute(['id' => $id]);
        $f = $st->fetch();
        if (!$f) {
            return null;
        }
        // BYTEA llega como stream desde pdo_pgsql
        return ['mime' => $f['mime'], 'datos' => is_resource($f['datos']) ? stream_get_contents($f['datos']) : $f['datos']];
    }

    /** La foto de un ítem del checklist (reemplaza la que hubiera). $tmp: archivo ya validado con Foto::validarSubidas. */
    public static function guardarFotoItem(int $id, string $codigo, string $tmp, string $mime): void
    {
        $st = self::pdo()->prepare(
            'INSERT INTO inspeccion_item_foto (inspeccion_id, codigo, mime, datos) VALUES (:id, :codigo, :mime, :datos)
             ON CONFLICT (inspeccion_id, codigo) DO UPDATE SET mime = EXCLUDED.mime, datos = EXCLUDED.datos, created_at = now()'
        );
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':codigo', $codigo);
        $st->bindValue(':mime', $mime);
        $st->bindValue(':datos', (string)file_get_contents($tmp), PDO::PARAM_LOB);
        $st->execute();
    }

    /** La foto de un ítem: mime y datos, o null si no hay. */
    public static function fotoItem(int $id, string $codigo): ?array
    {
        $st = self::pdo()->prepare('SELECT mime, datos FROM inspeccion_item_foto WHERE inspeccion_id = :id AND codigo = :codigo');
        $st->execute(['id' => $id, 'codigo' => $codigo]);
        $f = $st->fetch();
        if (!$f) {
            return null;
        }
        // BYTEA llega como stream desde pdo_pgsql
        return ['mime' => $f['mime'], 'datos' => is_resource($f['datos']) ? stream_get_contents($f['datos']) : $f['datos']];
    }

    /** @return string[] los códigos de los ítems que tienen foto */
    public static function itemsConFoto(int $id): array
    {
        $st = self::pdo()->prepare('SELECT codigo FROM inspeccion_item_foto WHERE inspeccion_id = :id');
        $st->execute(['id' => $id]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
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
            // Si la publicación de AutoCar sigue a la vista. Que se venda o se dé de baja es lo normal: el informe
            // sigue valiendo y queda en la patente. null = aviso de otro sitio (no sabemos si sigue publicado).
            'aviso_publicado'  => $i['vehiculo_id'] === null ? null : ($i['vehiculo_estado'] ?? null) === 'disponible',
            'titulo'           => $i['titulo'],
            // Del catálogo (idea B): con la misma clave que el comparador; null si todavía no se sabe
            'marca'            => $i['marca'],
            'modelo'           => $i['modelo'],
            'modelo_clave'     => $i['marca'] !== null && $i['modelo'] !== null ? Clave::de($i['marca'], $i['modelo']) : null,
            'anio'             => $entero($i['anio']),
            'kilometraje'      => $entero($i['kilometraje']),
            'precio_publicado' => $numero($i['precio_publicado']),
            'localidad'        => $i['localidad'],
            'fecha_turno'      => $fecha($i['fecha_turno']),
            'motivo_rechazo'   => $i['motivo_rechazo'],
            'precio'           => (float)$i['precio'],
            // Crédito del comprador descontado del precio (si el auto de una inspección anterior se vendió)
            'credito_usado'    => (float)$i['credito_usado'],
            'a_pagar'          => (float)$i['precio'] - (float)$i['credito_usado'],
            // El crédito que dio esta inspección: auto_vendido (se firmó con el auto vendido) o cupon (se vendió enseguida)
            'credito'          => $i['credito_tipo'] === null ? null : ['tipo' => $i['credito_tipo'], 'monto' => (float)$i['credito_monto']],
            // El vendedor confirmó el turno (solo autos de AutoCar, ver AutoVendido)
            'turno_confirmado_at' => $fecha($i['turno_confirmado_at']),
            'monto_mecanico'   => (float)$i['monto_mecanico'],
            'monto_autocar'    => (float)$i['monto_autocar'],
            'pago'             => $i['pago'],
            'conclusion'       => $i['conclusion'],
            'resumen'          => $i['resumen'],
            'firmado_por'      => $i['firmado_por'],
            'taller_firma'     => $i['taller_firma'],
            'firmada_at'       => $fecha($i['firmada_at']),
            'huella'           => $i['huella'],
            // La carga el mecánico (obligatoria para firmar); la foto de la chapa la ven los mismos que el detalle
            'patente'          => $i['patente'],
            'patente_foto'     => $i['tiene_foto_patente'] ? "/api/inspecciones/{$i['id']}/patente-foto" : null,
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
        ];
        if ($items !== null) {
            // Idea A: el costo total (precio del aviso + el presupuesto vigente más barato de cada arreglo)
            $datos['presupuestos'] = [];
            $datos['costo_total'] = null;
            if ($i['estado'] === 'firmada') {
                $presupuestos = Presupuestos::deLaInspeccion((int)$i['id']);
                $datos['presupuestos'] = array_map([Presupuestos::class, 'paraApi'], $presupuestos);
                $datos['costo_total'] = CostoTotal::calcular(CostoTotal::arreglos($items), $presupuestos,
                    $numero($i['precio_publicado']), new DateTimeImmutable());
            }
            // Cada ítem con su foto, si el mecánico subió una (obligatoria en "falla", ver Checklist::faltanFotos)
            $conFoto = self::itemsConFoto((int)$i['id']);
            $datos['items'] = [];
            foreach ($items as $codigo => [$resultado, $nota]) {
                $foto = null;
                if (in_array($codigo, $conFoto, true)) {
                    $foto = "/api/inspecciones/{$i['id']}/item-foto?codigo=$codigo";
                }
                $datos['items'][] = ['codigo' => $codigo, 'resultado' => $resultado, 'nota' => $nota, 'foto' => $foto];
            }
        }
        return $datos;
    }
}
