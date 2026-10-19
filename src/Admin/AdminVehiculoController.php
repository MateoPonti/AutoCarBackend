<?php
declare(strict_types=1);

/** ABM de vehículos. Todas las rutas llevan el guard 'admin' en routes.php (se verifica en el servidor). */
final class AdminVehiculoController
{
    private const VISTAS = SRC_PATH . '/Admin/views/';
    private const POR_PAGINA = 15;
    private const MAX_FOTO = 2 * 1024 * 1024;
    private const TIPOS_FOTO = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function index(): void
    {
        $f = CatalogoController::filtros($_GET);
        $pagina = max(1, (int)($_GET['page'] ?? 1));
        $r = Vehiculo::buscar($f, $pagina, self::POR_PAGINA, false);

        View::render(self::VISTAS . 'listado.php', [
            'titulo'  => 'Administrar vehículos',
            'items'   => $r['items'],
            'total'   => $r['total'],
            'filtros' => $f,
            'marcas'  => Vehiculo::marcas(),
            'pagina'  => $pagina,
            'paginas' => max(1, (int)ceil($r['total'] / self::POR_PAGINA)),
        ]);
    }

    public function nuevo(): void
    {
        $this->form('Nuevo vehículo', '/admin/vehiculos', ['estado' => 'disponible'], []);
    }

    public function guardar(): void
    {
        [$datos, $errores] = Vehiculo::validar($_POST);
        [$foto, $errFoto] = $this->procesarFoto();
        if ($errFoto) {
            $errores['imagen'] = $errFoto;
        }

        if ($errores) {
            $this->borrarArchivo($foto);
            $this->form('Nuevo vehículo', '/admin/vehiculos', $_POST, $errores);
            return;
        }
        Vehiculo::crear($datos + ['imagen' => $foto]);
        flash_set('Vehículo creado.');
        redirect('/admin/vehiculos');
    }

    public function editar(int $id): void
    {
        $v = Vehiculo::find($id) ?? View::error(404);
        $this->form('Editar vehículo', "/admin/vehiculos/$id", $v, [], $v['imagen']);
    }

    public function actualizar(int $id): void
    {
        $actual = Vehiculo::find($id) ?? View::error(404);
        [$datos, $errores] = Vehiculo::validar($_POST);
        [$foto, $errFoto] = $this->procesarFoto();
        if ($errFoto) {
            $errores['imagen'] = $errFoto;
        }

        if ($errores) {
            $this->borrarArchivo($foto);
            $this->form('Editar vehículo', "/admin/vehiculos/$id", $_POST, $errores, $actual['imagen']);
            return;
        }
        if ($foto !== null) {
            $this->borrarArchivo($actual['imagen']);
        }
        Vehiculo::actualizar($id, $datos + ['imagen' => $foto ?? $actual['imagen']]);
        flash_set('Vehículo actualizado.');
        redirect('/admin/vehiculos');
    }

    public function eliminar(int $id): void
    {
        $v = Vehiculo::find($id) ?? View::error(404);
        Vehiculo::eliminar($id);
        $this->borrarArchivo($v['imagen']);
        flash_set('Vehículo eliminado.');
        redirect('/admin/vehiculos');
    }

    private function form(string $titulo, string $accion, array $datos, array $errores, ?string $imagen = null): void
    {
        View::render(self::VISTAS . 'form.php', [
            'titulo' => $titulo, 'accion' => $accion, 'd' => $datos, 'errores' => $errores, 'imagenActual' => $imagen,
        ]);
    }

    /**
     * Valida la foto por contenido real (no por la extensión que manda el navegador).
     * @return array{0: ?string, 1: ?string} [nombre guardado, error]
     */
    private function procesarFoto(): array
    {
        $f = $_FILES['imagen'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return [null, 'No se pudo subir la foto. Probá con otra.'];
        }
        if ($f['size'] > self::MAX_FOTO) {
            return [null, 'La foto pesa más de 2 MB.'];
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset(self::TIPOS_FOTO[$mime]) || @getimagesize($f['tmp_name']) === false) {
            return [null, 'La foto debe ser JPG, PNG o WEBP.'];
        }
        $nombre = bin2hex(random_bytes(16)) . '.' . self::TIPOS_FOTO[$mime]; // nombre aleatorio, extensión por contenido
        if (!move_uploaded_file($f['tmp_name'], BASE_PATH . '/public/uploads/' . $nombre)) {
            return [null, 'No se pudo guardar la foto.'];
        }
        return [$nombre, null];
    }

    private function borrarArchivo(?string $nombre): void
    {
        if ($nombre) {
            @unlink(BASE_PATH . '/public/uploads/' . basename($nombre));
        }
    }
}
