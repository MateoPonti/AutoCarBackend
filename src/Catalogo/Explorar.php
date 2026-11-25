<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

use AutoCar\Core\Database;
use AutoCar\Core\Response;

/** Colecciones de publicaciones disponibles, independientes de la página actual del catálogo. */
final class Explorar
{
    public function index(): void
    {
        $db = Database::pdo();
        $zona = CatalogoController::filtros($_GET)['zona'];
        $params = [];
        $condicion = $zona === '' ? '' : ' AND ' . Localidad::condicion($zona, $params);
        // Instalaciones anteriores a la migración 033 todavía no tienen vitrinas.
        $empresas = $db->query("SELECT to_regclass('empresa')")->fetchColumn();
        $vitrinas = $empresas ? "
            UNION ALL
            SELECT v.id, 'asociadas', e.razon_social
              FROM disponibles v JOIN empresa e ON e.usuario_id = v.usuario_id
              JOIN usuario u ON u.id = e.usuario_id JOIN rol r ON r.id = u.rol_id
             WHERE e.estado = 'aprobado' AND u.activo AND r.nombre = 'empresa'" : '';

        $vigentes = Verificacion::VIGENTES;
        $st = $db->prepare("WITH disponibles AS (
              SELECT id, usuario_id, marca, modelo, anio, kilometraje, localidad, created_at FROM vehiculo WHERE estado = 'disponible' $condicion
            ), verificados AS ($vigentes), grupos(id, tipo, concesionaria) AS (
              SELECT id, 'verificados', NULL::text FROM verificados
              $vitrinas
            ), por_vendedor AS (
              SELECT g.*, v.created_at,
                     CASE WHEN tipo = 'asociadas' THEN row_number() OVER (
                        PARTITION BY tipo, v.usuario_id ORDER BY v.created_at DESC, v.id DESC) ELSE 1 END AS turno
                FROM grupos g JOIN disponibles v USING (id)
            ), posiciones AS (
              SELECT g.*, row_number() OVER (PARTITION BY tipo ORDER BY turno, created_at DESC, id DESC) AS puesto
                FROM por_vendedor g
            )
            SELECT p.tipo, p.concesionaria, v.*, f.id AS foto_id, (ver.id IS NOT NULL) AS verificado, ver.verificado_at
              FROM posiciones p JOIN vehiculo v USING (id)
              LEFT JOIN verificados ver ON ver.id = v.id
              LEFT JOIN LATERAL (SELECT id FROM vehiculo_foto WHERE vehiculo_id = v.id ORDER BY orden, id LIMIT 1) f ON true
             WHERE p.puesto <= 12
             ORDER BY p.tipo, p.puesto");
        $st->execute($params);
        $filas = [
            'verificados' => ['tipo' => 'verificados', 'nombre' => 'Autos verificados', 'items' => []],
            'asociadas' => ['tipo' => 'asociadas', 'nombre' => 'Concesionarias asociadas', 'items' => []],
        ];
        foreach ($st as $v) {
            $filas[$v['tipo']]['items'][] = [
                'verificado' => (bool)$v['verificado'],
                'verificado_at' => $v['verificado_at'] !== null ? Verificacion::fecha($v['verificado_at']) : null,
                'empresa' => $v['concesionaria'] === null ? null : ['id' => (int)$v['usuario_id'], 'nombre' => $v['concesionaria']],
                'id' => (int)$v['id'], 'marca' => $v['marca'], 'modelo' => $v['modelo'], 'anio' => (int)$v['anio'],
                'kilometraje' => (int)$v['kilometraje'], 'precio' => (float)$v['precio'], 'localidad' => Localidad::nombre($v['localidad']),
                'transmision' => $v['transmision'], 'combustible' => $v['combustible'], 'estado' => $v['estado'],
                'foto' => $v['foto_id'] ? '/api/fotos/' . $v['foto_id'] : null, 'imagen_url' => $v['imagen_url'],
            ];
        }
        header('Cache-Control: private, no-store');
        Response::json(['filas' => array_values($filas)]);
    }
}
