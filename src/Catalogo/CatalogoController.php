<?php
declare(strict_types=1);

/** API pública: listado con búsqueda y filtros, y detalle. */
final class CatalogoController
{
    public const POR_PAGINA = 9;

    public function index(): void
    {
        Response::json(self::listado(self::filtros($_GET), true, self::POR_PAGINA));
    }

    public function detalle(int $id): void
    {
        $v = Vehiculo::find($id);
        // Una publicación pendiente o rechazada no es pública: 404 igual que si no existiera
        if ($v === null || !in_array($v['estado'], Vehiculo::VISIBLES, true)) {
            Response::error(404, 'no_encontrado', 'El vehículo no existe.');
        }
        Response::json(['vehiculo' => Vehiculo::paraApi($v) + ['fotos' => Foto::rutasDe($id)]]);
    }

    public function marcas(): void
    {
        Response::json(['marcas' => Vehiculo::marcas()]);
    }

    /** Respuesta paginada, compartida con el listado de administración. */
    public static function listado(array $filtros, bool $soloDisponibles, int $porPagina): array
    {
        $pagina = max(1, (int)($_GET['page'] ?? 1));
        $r = Vehiculo::buscar($filtros, $pagina, $porPagina, $soloDisponibles);
        return [
            'items'      => array_map([Vehiculo::class, 'paraApi'], $r['items']),
            'total'      => $r['total'],
            'pagina'     => $pagina,
            'por_pagina' => $porPagina,
            'paginas'    => max(1, (int)ceil($r['total'] / $porPagina)),
        ];
    }

    /** Normaliza lo que llega por la URL: nada de lo que viene del navegador se usa sin validar. */
    public static function filtros(array $get): array
    {
        $texto  = static fn(string $k, int $max): string => is_string($get[$k] ?? null) ? mb_substr(trim($get[$k]), 0, $max) : '';
        $precio = str_replace(',', '.', $texto('precio_max', 12));
        $comb   = $texto('combustible', 20);
        return [
            'q'           => $texto('q', 60),
            'marca'       => $texto('marca', 50),
            'combustible' => in_array($comb, Vehiculo::COMBUSTIBLES, true) ? $comb : '',
            'precio_max'  => preg_match('/^\d{1,9}(\.\d{1,2})?$/', $precio) ? $precio : null,
        ];
    }
}
