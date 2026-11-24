<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

use AutoCar\Auth\Auth;
use AutoCar\Auth\Preferencias;
use AutoCar\Auth\Usuario;
use AutoCar\Core\Response;
use AutoCar\Empresas\Empresa;
use AutoCar\MetaBusqueda\ParaVos;
use AutoCar\Publicaciones\Foto;
use DateTimeImmutable;

/** API pública: listado con búsqueda y filtros, y detalle. */
final class CatalogoController
{
    // 12 se divide por 2, 3 y 4: las filas de la grilla quedan completas en celular, tablet y escritorio
    public const POR_PAGINA = 12;
    /** Cuántos de la primera página de "Más relevantes" se marcan como recomendados, con su mensaje */
    public const RECOMENDADOS = 4;

    /** GET /api/vehiculos: Explorar. Por defecto "Más relevantes" (RankingExplorar); orden=recientes, los más nuevos primero. */
    public function index(): void
    {
        $filtros = self::filtros($_GET);
        Response::json($filtros['orden'] === 'relevantes' ? self::relevantes($filtros) : self::listado($filtros, true, self::POR_PAGINA));
    }

    /** Explorar ordenado como el metabuscador, con el perfil del que mira (si tiene). */
    private static function relevantes(array $filtros): array
    {
        $pagina = min(10000, max(1, (int)($_GET['page'] ?? 1)));
        $todos = Vehiculo::buscar($filtros, 1, RankingExplorar::MAXIMO, true)['items'];
        $usuario = Auth::usuario();
        $uso = ($usuario['rol'] ?? null) === 'usuario' ? (Preferencias::delUsuario((int)$usuario['id'])['uso'] ?? null) : null;
        // El precio contra el mercado ya está calculado (PrecioMercado): si el scraper dejó uno nuevo, se copia a Neon
        self::sincronizarPrecios();
        $ordenados = RankingExplorar::ordenar($todos, PrecioMercado::guardados($todos), $uso,
            $uso !== null ? ParaVos::pesosActuales() : null, new DateTimeImmutable());
        $total = count($ordenados);
        $filas = array_slice($ordenados, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA);
        $items = self::conInsignia($filas);
        // En la primera página, los primeros con mensaje llevan "recomendado": el puesto y por qué (el front los marca)
        $puesto = 0;
        foreach ($filas as $i => $v) {
            if ($pagina === 1 && $puesto < self::RECOMENDADOS && $v['mensaje'] !== null) {
                $items[$i]['recomendado'] = ['puesto' => ++$puesto, 'mensaje' => $v['mensaje']];
            }
        }
        return [
            'perfil'     => $uso,
            'items'      => $items,
            'total'      => $total,
            'pagina'     => $pagina,
            'por_pagina' => self::POR_PAGINA,
            'paginas'    => max(1, (int)ceil($total / self::POR_PAGINA)),
            'orden'      => 'relevantes',
        ];
    }

    /** Copia el cálculo del día que dejó el scraper (como mucho una lectura de Firestore por hora). Si falla, sigue. */
    public static function sincronizarPrecios(): void
    {
        try {
            PrecioMercado::sincronizar(\AutoCar\MetaBusqueda\Almacenamiento::documentos());
        } catch (\Throwable $e) {
            \AutoCar\Core\Logger::error('Precio contra el mercado (copia del día): ' . $e->getMessage());
        }
    }

    /**
     * Las filas como las devuelve la API, con "empresa" ({id, nombre} si el vendedor es una concesionaria aprobada,
     * para la insignia "Concesionaria X · Empresa verificada"; null si es un particular).
     * @param list<array<string, mixed>> $filas
     */
    public static function conInsignia(array $filas): array
    {
        $insignias = Empresa::insignias(array_column($filas, 'usuario_id'));
        $verificados = Verificacion::dePublicaciones(array_column($filas, 'id'));
        return array_map(static fn(array $v): array => Vehiculo::paraApi($v) + [
            'empresa' => $insignias[(int)$v['usuario_id']] ?? null,
            'verificado' => isset($verificados[$v['id']]),
        ], $filas);
    }

    public function detalle(int $id): void
    {
        $v = Vehiculo::find($id);
        // Una publicación pendiente o rechazada no es pública: 404 igual que si no existiera
        if ($v === null || !in_array($v['estado'], Vehiculo::VISIBLES, true)) {
            Response::error(404, 'no_encontrado', 'El vehículo no existe.');
        }
        $vendedor = Usuario::porId((int)$v['usuario_id']);
        Visitas::registrar($id, (int)$v['usuario_id']); // para el panel de la empresa (cuentan todas, también sin sesión)
        Response::json(['vehiculo' => self::conInsignia([$v])[0] + [
            'fotos'    => Foto::rutasDe($id),
            // Página pública: del vendedor solo el nombre y desde cuándo está (nunca el email)
            'vendedor' => $vendedor === null ? null : [
                'nombre' => $vendedor['nombre'],
                'desde'  => date('c', (int)strtotime((string)$vendedor['created_at'])),
                'foto'   => Usuario::rutaFoto((int)$vendedor['id'], $vendedor['foto_v']),
            ],
        ]]);
    }

    public function marcas(): void
    {
        Response::json(['marcas' => Vehiculo::marcas()]);
    }

    /** GET /api/marcas-modelos: las marcas y modelos que se pueden publicar, para armar el formulario. */
    public function zonas(): void
    {
        $zonas = Localidad::disponibles();
        $actual = Localidad::nombre(self::filtros($_GET)['zona']);
        Response::json(['zonas' => array_column($zonas, 'nombre'),
            'seleccionada' => $zonas[Localidad::clave($actual)]['nombre'] ?? $actual]);
    }

    public function marcasModelos(): void
    {
        Response::json(['marcas' => MarcaModelo::lista()]);
    }

    /** Respuesta paginada, compartida con el listado de administración. */
    public static function listado(array $filtros, bool $soloDisponibles, int $porPagina): array
    {
        $pagina = min(10000, max(1, (int)($_GET['page'] ?? 1))); // tope: una página enorme desbordaría el OFFSET
        $r = Vehiculo::buscar($filtros, $pagina, $porPagina, $soloDisponibles);
        return [
            'items'      => $soloDisponibles ? self::conInsignia($r['items']) : array_map([Vehiculo::class, 'paraApi'], $r['items']),
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
        $estado = $texto('estado', 12);
        $vendedor = $texto('vendedor', 12);
        $orden = $texto('orden', 12);
        return [
            'estado'      => in_array($estado, Vehiculo::ESTADOS, true) ? $estado : '', // sólo lo usa el admin
            'completa'    => in_array($texto('completa', 1), ['0', '1'], true) ? $texto('completa', 1) : '', // sólo el admin
            'q'           => $texto('q', 60),
            'marca'       => $texto('marca', 50),
            'zona'        => $texto('zona', 100),
            'combustible' => in_array($comb, Vehiculo::COMBUSTIBLES, true) ? $comb : '',
            'precio_max'  => preg_match('/^\d{1,9}(\.\d{1,2})?$/', $precio) ? $precio : null,
            'vendedor'    => in_array($vendedor, ['particular', 'empresa'], true) ? $vendedor : '',
            // Explorar: "Más relevantes" (el ranking) salvo que se pida "recientes" (los más nuevos primero)
            'orden'       => $orden === 'recientes' ? 'recientes' : 'relevantes',
        ];
    }
}
