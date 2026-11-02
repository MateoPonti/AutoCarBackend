<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

use AutoCar\Core\Database;
use AutoCar\Core\Request;
use PDO;

/** Entidad principal. Consultas parametrizadas + validación del lado del servidor. */
final class Vehiculo
{
    public const COMBUSTIBLES  = ['nafta', 'diesel', 'gnc', 'hibrido', 'electrico'];
    public const TRANSMISIONES = ['manual', 'automatica'];
    public const CONDICIONES   = ['excelente', 'muy_bueno', 'bueno', 'regular'];
    // pendiente = publicada por un usuario, espera aprobación del admin. El público sólo ve VISIBLES.
    // eliminado = baja lógica hecha por el dueño (la fila queda para el historial y los chats).
    public const ESTADOS       = ['pendiente', 'disponible', 'rechazado', 'vendido', 'eliminado'];
    public const VISIBLES      = ['disponible', 'vendido'];

    /** @return array{items: array, total: int} */
    public static function buscar(array $f, int $pagina, int $porPagina, bool $soloDisponibles): array
    {
        $where  = [];
        $params = [];

        if ($soloDisponibles) {
            $where[] = "estado = 'disponible'";
        } elseif (($f['estado'] ?? '') !== '') { // admin: filtro por estado (p. ej. la cola de 'pendiente')
            $where[] = 'estado = :estado';
            $params['estado'] = $f['estado'];
        } else {
            $where[] = "estado <> 'eliminado'";
        }
        if (($f['q'] ?? '') !== '') {
            // se escapan % y _ para que el usuario no pueda armar comodines
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['q']) . '%';
            $where[] = '(marca ILIKE :q1 OR modelo ILIKE :q2 OR descripcion ILIKE :q3)';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
        }
        if (($f['marca'] ?? '') !== '') {
            $where[] = 'marca = :marca';
            $params['marca'] = $f['marca'];
        }
        if (($f['combustible'] ?? '') !== '') {
            $where[] = 'combustible = :combustible';
            $params['combustible'] = $f['combustible'];
        }
        if (($f['precio_max'] ?? null) !== null) {
            $where[] = 'precio <= :precio_max';
            $params['precio_max'] = $f['precio_max'];
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT COUNT(*) FROM vehiculos $sqlWhere");
        $st->execute($params);
        $total = (int)$st->fetchColumn();

        $st = $pdo->prepare('SELECT vehiculos.*, ' . self::PRIMERA_FOTO . " FROM vehiculos $sqlWhere
                             ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) {
            $st->bindValue(':' . $k, $v);
        }
        $st->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $st->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $st->execute();

        return ['items' => $st->fetchAll(), 'total' => $total];
    }

    public static function marcas(): array
    {
        return Database::pdo()->query('SELECT DISTINCT marca FROM vehiculos ORDER BY marca')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Subconsulta con el id de la primera foto subida (para la ficha del listado). */
    private const PRIMERA_FOTO = '(SELECT f.id FROM vehiculo_fotos f WHERE f.vehiculo_id = vehiculos.id ORDER BY f.orden, f.id LIMIT 1) AS foto_id';

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT vehiculos.*, ' . self::PRIMERA_FOTO . ' FROM vehiculos WHERE id = :id');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    /** Publicaciones de un usuario (todas menos las eliminadas), las más nuevas primero. */
    public static function delUsuario(int $usuarioId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT vehiculos.*, ' . self::PRIMERA_FOTO . " FROM vehiculos
              WHERE usuario_id = :usuario_id AND estado <> 'eliminado'
              ORDER BY created_at DESC, id DESC"
        );
        $st->execute(['usuario_id' => $usuarioId]);
        return $st->fetchAll();
    }

    /** El vehículo sólo si es de ese usuario y no está eliminado; si no, null (evita IDOR). */
    public static function deUsuario(int $id, int $usuarioId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT vehiculos.*, ' . self::PRIMERA_FOTO . " FROM vehiculos
              WHERE id = :id AND usuario_id = :usuario_id AND estado <> 'eliminado'"
        );
        $st->execute(['id' => $id, 'usuario_id' => $usuarioId]);
        return $st->fetch() ?: null;
    }

    /** $motivo sólo se guarda al rechazar; cualquier otro cambio de estado lo borra. */
    public static function cambiarEstado(int $id, string $estado, ?string $motivo = null): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE vehiculos SET estado = :estado, motivo_rechazo = :motivo, updated_at = now() WHERE id = :id'
        );
        $st->execute(['estado' => $estado, 'motivo' => $estado === 'rechazado' ? $motivo : null, 'id' => $id]);
    }

    /** $usuarioId: dueño de la publicación; null cuando el vehículo lo carga el admin. */
    public static function crear(array $d, ?int $usuarioId = null): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO vehiculos (usuario_id, marca, modelo, version, anio, precio, kilometraje, combustible, transmision,
                                    condicion, estado, descripcion, imagen_url, acepta_canje, busca_canje, localidad)
             VALUES (:usuario_id, :marca, :modelo, :version, :anio, :precio, :kilometraje, :combustible, :transmision,
                     :condicion, :estado, :descripcion, :imagen_url, :acepta_canje, :busca_canje, :localidad)
             RETURNING id'
        );
        $st->execute(self::columnas($d) + ['usuario_id' => $usuarioId]);
        return (int)$st->fetchColumn();
    }

    public static function actualizar(int $id, array $d): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE vehiculos SET marca = :marca, modelo = :modelo, version = :version, anio = :anio, precio = :precio,
                    kilometraje = :kilometraje, combustible = :combustible, transmision = :transmision,
                    condicion = :condicion, estado = :estado, descripcion = :descripcion, imagen_url = :imagen_url,
                    acepta_canje = :acepta_canje, busca_canje = :busca_canje, localidad = :localidad,
                    motivo_rechazo = CASE WHEN CAST(:estado_motivo AS VARCHAR) = \'rechazado\' THEN motivo_rechazo END, updated_at = now()
             WHERE id = :id'
        );
        // El motivo de rechazo se conserva sólo si sigue rechazado (al editar vuelve a 'pendiente' y se borra)
        $st->execute(self::columnas($d) + ['estado_motivo' => $d['estado'], 'id' => $id]);
    }

    private static function columnas(array $d): array
    {
        return [
            'marca' => $d['marca'], 'modelo' => $d['modelo'], 'anio' => $d['anio'], 'precio' => $d['precio'],
            'kilometraje' => $d['kilometraje'], 'combustible' => $d['combustible'], 'transmision' => $d['transmision'],
            'estado' => $d['estado'], 'descripcion' => $d['descripcion'], 'imagen_url' => $d['imagen_url'],
            'version' => $d['version'], 'condicion' => $d['condicion'], 'busca_canje' => $d['busca_canje'],
            'localidad' => $d['localidad'],
            'acepta_canje' => $d['acepta_canje'] ? 'true' : 'false', // PDO mandaría false como '' y PostgreSQL lo rechaza
        ];
    }

    /**
     * Valida y normaliza lo que llega del formulario de administración o del de publicar.
     * $publicacion = true: lo publica un usuario, así que condición y localidad son obligatorias
     * y el estado lo decide el servidor ('pendiente'), no lo que mande el cliente.
     * @return array{0: array, 1: array} [datos limpios, errores por campo]
     */
    public static function validar(array $in, bool $publicacion = false): array
    {
        $errores = [];
        $txt = static fn(string $k): string => Request::texto($in, $k);

        $marca = $txt('marca');
        if ($marca === '' || mb_strlen($marca) > 50) {
            $errores['marca'] = 'La marca es obligatoria (hasta 50 caracteres).';
        }
        $modelo = $txt('modelo');
        if ($modelo === '' || mb_strlen($modelo) > 60) {
            $errores['modelo'] = 'El modelo es obligatorio (hasta 60 caracteres).';
        }

        $anio = filter_var($txt('anio'), FILTER_VALIDATE_INT);
        if ($anio === false || $anio < 1950 || $anio > (int)date('Y') + 1) {
            $errores['anio'] = 'Ingresá un año entre 1950 y ' . ((int)date('Y') + 1) . '.';
        }

        $precioTxt = str_replace(',', '.', $txt('precio'));
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $precioTxt) || (float)$precioTxt < 100000) {
            $errores['precio'] = 'Ingresá un precio de al menos $100.000 (por ejemplo 12500000).';
        }

        $km = filter_var($txt('kilometraje'), FILTER_VALIDATE_INT);
        if ($km === false || $km < 0 || $km > 2000000) {
            $errores['kilometraje'] = 'Ingresá un kilometraje entre 0 y 2.000.000.';
        }

        $comb = $txt('combustible');
        if (!in_array($comb, self::COMBUSTIBLES, true)) {
            $errores['combustible'] = 'Elegí un combustible de la lista.';
        }
        $trans = $txt('transmision');
        if (!in_array($trans, self::TRANSMISIONES, true)) {
            $errores['transmision'] = 'Elegí una transmisión de la lista.';
        }
        $estado = $publicacion ? 'pendiente' : ($txt('estado') ?: 'disponible');
        if (!in_array($estado, self::ESTADOS, true)) {
            $errores['estado'] = 'Estado inválido.';
        }

        $version = $txt('version');
        if (mb_strlen($version) > 60) {
            $errores['version'] = 'La versión admite hasta 60 caracteres.';
        }
        $condicion = $txt('condicion');
        if (($condicion !== '' || $publicacion) && !in_array($condicion, self::CONDICIONES, true)) {
            $errores['condicion'] = 'Elegí el estado general de la lista.';
        }
        $localidad = $txt('localidad');
        if (($localidad === '' && $publicacion) || mb_strlen($localidad) > 100) {
            $errores['localidad'] = 'Ingresá la localidad (hasta 100 caracteres).';
        }
        // Checkbox: llega "1" (formulario) o true (JSON); si no viene, es que no acepta canje
        $aceptaCanje = in_array(strtolower($txt('acepta_canje')), ['1', 'true', 'on'], true);
        $buscaCanje = $aceptaCanje ? $txt('busca_canje') : '';
        if (mb_strlen($buscaCanje) > 120) {
            $errores['busca_canje'] = 'Este campo admite hasta 120 caracteres.';
        }

        $desc = $txt('descripcion');
        if (mb_strlen($desc) > 2000) {
            $errores['descripcion'] = 'La descripción admite hasta 2000 caracteres.';
        }

        $url = $txt('imagen_url');
        if ($url !== '' && (mb_strlen($url) > 255 || !filter_var($url, FILTER_VALIDATE_URL)
                || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $errores['imagen_url'] = 'Ingresá una URL http(s) válida (hasta 255 caracteres) o dejalo vacío.';
        }

        $datos = [
            'marca' => $marca, 'modelo' => $modelo, 'anio' => (int)$anio, 'precio' => $precioTxt,
            'kilometraje' => (int)$km, 'combustible' => $comb, 'transmision' => $trans,
            'estado' => $estado, 'descripcion' => $desc !== '' ? $desc : null,
            'imagen_url' => $url !== '' ? $url : null,
            'version' => $version !== '' ? $version : null, 'condicion' => $condicion !== '' ? $condicion : null,
            'acepta_canje' => $aceptaCanje, 'busca_canje' => $buscaCanje !== '' ? $buscaCanje : null,
            'localidad' => $localidad !== '' ? $localidad : null,
        ];
        return [$datos, $errores];
    }

    /** Fila de la base → JSON con los tipos correctos (NUMERIC llega como texto desde la base). */
    public static function paraApi(array $v): array
    {
        return [
            'id' => (int)$v['id'], 'marca' => $v['marca'], 'modelo' => $v['modelo'], 'anio' => (int)$v['anio'],
            'precio' => (float)$v['precio'], 'kilometraje' => (int)$v['kilometraje'],
            'combustible' => $v['combustible'], 'transmision' => $v['transmision'], 'estado' => $v['estado'],
            'descripcion' => $v['descripcion'], 'imagen_url' => $v['imagen_url'],
            'version' => $v['version'], 'condicion' => $v['condicion'], 'acepta_canje' => (bool)$v['acepta_canje'],
            'busca_canje' => $v['busca_canje'], 'localidad' => $v['localidad'],
            'motivo_rechazo' => $v['motivo_rechazo'] ?? null,
            // Ruta relativa a la API: el front le antepone API_URL
            'foto' => isset($v['foto_id']) ? '/api/fotos/' . (int)$v['foto_id'] : null,
            'creado' => date('c', (int)strtotime((string)$v['created_at'])),
        ];
    }
}
