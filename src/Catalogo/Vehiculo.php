<?php
declare(strict_types=1);

/** Entidad principal. Consultas parametrizadas + validación del lado del servidor. */
final class Vehiculo
{
    public const COMBUSTIBLES  = ['nafta', 'diesel', 'gnc', 'hibrido', 'electrico'];
    public const TRANSMISIONES = ['manual', 'automatica'];
    public const ESTADOS       = ['disponible', 'vendido'];

    /** @return array{items: array, total: int} */
    public static function buscar(array $f, int $pagina, int $porPagina, bool $soloDisponibles): array
    {
        $where  = [];
        $params = [];

        if ($soloDisponibles) {
            $where[] = "estado = 'disponible'";
        }
        if (($f['q'] ?? '') !== '') {
            // se escapan % y _ para que el usuario no pueda armar comodines
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['q']) . '%';
            $where[] = '(marca LIKE :q1 OR modelo LIKE :q2 OR descripcion LIKE :q3)';
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

        $st = $pdo->prepare("SELECT * FROM vehiculos $sqlWhere ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off");
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

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM vehiculos WHERE id = :id');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    public static function crear(array $d): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO vehiculos (marca, modelo, anio, precio, kilometraje, combustible, transmision, estado, descripcion, imagen)
             VALUES (:marca, :modelo, :anio, :precio, :kilometraje, :combustible, :transmision, :estado, :descripcion, :imagen)'
        );
        $st->execute(self::columnas($d));
        return (int)Database::pdo()->lastInsertId();
    }

    public static function actualizar(int $id, array $d): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE vehiculos SET marca = :marca, modelo = :modelo, anio = :anio, precio = :precio,
                    kilometraje = :kilometraje, combustible = :combustible, transmision = :transmision,
                    estado = :estado, descripcion = :descripcion, imagen = :imagen
             WHERE id = :id'
        );
        $st->execute(self::columnas($d) + ['id' => $id]);
    }

    public static function eliminar(int $id): void
    {
        $st = Database::pdo()->prepare('DELETE FROM vehiculos WHERE id = :id');
        $st->execute(['id' => $id]);
    }

    private static function columnas(array $d): array
    {
        return [
            'marca' => $d['marca'], 'modelo' => $d['modelo'], 'anio' => $d['anio'], 'precio' => $d['precio'],
            'kilometraje' => $d['kilometraje'], 'combustible' => $d['combustible'], 'transmision' => $d['transmision'],
            'estado' => $d['estado'], 'descripcion' => $d['descripcion'], 'imagen' => $d['imagen'] ?? null,
        ];
    }

    /**
     * Valida y normaliza lo que llega del formulario de administración.
     * @return array{0: array, 1: array} [datos limpios, errores por campo]
     */
    public static function validar(array $in): array
    {
        $errores = [];
        $txt = static fn(string $k): string => trim((string)($in[$k] ?? ''));

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
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $precioTxt) || (float)$precioTxt <= 0) {
            $errores['precio'] = 'Ingresá un precio mayor a 0 (por ejemplo 12500000 o 12500000,50).';
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
        $estado = $txt('estado') ?: 'disponible';
        if (!in_array($estado, self::ESTADOS, true)) {
            $errores['estado'] = 'Estado inválido.';
        }
        $desc = $txt('descripcion');
        if (mb_strlen($desc) > 2000) {
            $errores['descripcion'] = 'La descripción admite hasta 2000 caracteres.';
        }

        $datos = [
            'marca' => $marca, 'modelo' => $modelo, 'anio' => (int)$anio, 'precio' => $precioTxt,
            'kilometraje' => (int)$km, 'combustible' => $comb, 'transmision' => $trans,
            'estado' => $estado, 'descripcion' => $desc !== '' ? $desc : null,
        ];
        return [$datos, $errores];
    }

    /** Texto para mostrar (con tildes) de un valor de los ENUM. */
    public static function etiqueta(string $valor): string
    {
        return [
            'hibrido' => 'Híbrido', 'electrico' => 'Eléctrico', 'automatica' => 'Automática',
            'gnc' => 'GNC', 'diesel' => 'Diésel',
        ][$valor] ?? ucfirst($valor);
    }

    /** URL pública de la foto, o null si no hay (o si el archivo ya no existe en el disco). */
    public static function imagenUrl(?string $archivo): ?string
    {
        if ($archivo === null || $archivo === '' || !is_file(BASE_PATH . '/public/uploads/' . basename($archivo))) {
            return null;
        }
        return '/uploads/' . rawurlencode(basename($archivo));
    }
}
