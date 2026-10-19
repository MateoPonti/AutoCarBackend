<?php
declare(strict_types=1);

/** Vista pública: listado con búsqueda y filtros, y detalle. */
final class CatalogoController
{
    private const POR_PAGINA = 9;

    public function index(): void
    {
        $f = self::filtros($_GET);
        $pagina = max(1, (int)($_GET['page'] ?? 1));
        $r = Vehiculo::buscar($f, $pagina, self::POR_PAGINA, true);

        View::render(SRC_PATH . '/Catalogo/views/listado.php', [
            'titulo'   => 'Vehículos',
            'items'    => $r['items'],
            'total'    => $r['total'],
            'filtros'  => $f,
            'marcas'   => Vehiculo::marcas(),
            'pagina'   => $pagina,
            'paginas'  => max(1, (int)ceil($r['total'] / self::POR_PAGINA)),
        ]);
    }

    public function detalle(int $id): void
    {
        $v = Vehiculo::find($id);
        if ($v === null) {
            View::error(404);
        }
        View::render(SRC_PATH . '/Catalogo/views/detalle.php', [
            'titulo' => $v['marca'] . ' ' . $v['modelo'],
            'v'      => $v,
        ]);
    }

    /** Normaliza lo que llega por la URL: nada de lo que viene del navegador se usa sin validar. */
    public static function filtros(array $get): array
    {
        $precio = str_replace(',', '.', trim((string)($get['precio_max'] ?? '')));
        $comb = (string)($get['combustible'] ?? '');
        return [
            'q'           => mb_substr(trim((string)($get['q'] ?? '')), 0, 60),
            'marca'       => mb_substr(trim((string)($get['marca'] ?? '')), 0, 50),
            'combustible' => in_array($comb, Vehiculo::COMBUSTIBLES, true) ? $comb : '',
            'precio_max'  => preg_match('/^\d{1,9}(\.\d{1,2})?$/', $precio) ? $precio : null,
        ];
    }
}
