<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

use AutoCar\Auth\Auth;
use AutoCar\Auth\Preferencias;
use AutoCar\Core\Database;
use AutoCar\Core\Response;

/** Colecciones de publicaciones disponibles, independientes de la página actual del catálogo. */
final class Explorar
{
    public function index(): void
    {
        $db = Database::pdo();
        $usuario = Auth::id();
        $preferida = $usuario ? (Preferencias::delUsuario($usuario)['localidad'] ?? '') : '';
        $zona = $_GET['zona'] ?? '';
        $zona = is_string($zona) ? trim($zona) : '';
        if (mb_strlen($zona) > 100) {
            Response::error(422, 'validacion', 'La localidad puede tener hasta 100 caracteres.');
        }
        $localidades = $db->query("SELECT min(trim(localidad)) FROM vehiculo
             WHERE estado = 'disponible' AND trim(localidad) <> ''
             GROUP BY lower(trim(localidad)) ORDER BY count(*) DESC, lower(trim(localidad))")->fetchAll(\PDO::FETCH_COLUMN);
        $localidad = $zona ?: ($preferida ?: ($localidades[0] ?? ''));
        foreach ($localidades as $nombre) {
            if (mb_strtolower($nombre) === mb_strtolower($localidad)) {
                $localidad = $nombre;
                break;
            }
        }
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
              SELECT id, usuario_id, marca, modelo, anio, kilometraje, localidad, created_at FROM vehiculo WHERE estado = 'disponible'
            ), verificados AS ($vigentes), grupos(id, tipo, concesionaria) AS (
              SELECT id, 'verificados', NULL::text FROM verificados
              UNION ALL
              SELECT id, 'localidad', NULL::text FROM disponibles
               WHERE trim(localidad) <> '' AND lower(trim(localidad)) = lower(trim(:localidad))
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
            SELECT p.tipo, p.concesionaria, v.*, f.id AS foto_id, (ver.id IS NOT NULL) AS verificado
              FROM posiciones p JOIN vehiculo v USING (id)
              LEFT JOIN verificados ver ON ver.id = v.id
              LEFT JOIN LATERAL (SELECT id FROM vehiculo_foto WHERE vehiculo_id = v.id ORDER BY orden, id LIMIT 1) f ON true
             WHERE p.puesto <= 12
             ORDER BY p.tipo, p.puesto");
        $st->execute(['localidad' => $localidad]);
        $filas = [
            'verificados' => ['tipo' => 'verificados', 'nombre' => 'Autos verificados', 'items' => []],
            'localidad' => ['tipo' => 'localidad', 'nombre' => $localidad, 'items' => []],
            'asociadas' => ['tipo' => 'asociadas', 'nombre' => 'Concesionarias asociadas', 'items' => []],
        ];
        foreach ($st as $v) {
            $filas[$v['tipo']]['items'][] = [
                'verificado' => (bool)$v['verificado'],
                'empresa' => $v['concesionaria'] === null ? null : ['id' => (int)$v['usuario_id'], 'nombre' => $v['concesionaria']],
                'id' => (int)$v['id'], 'marca' => $v['marca'], 'modelo' => $v['modelo'], 'anio' => (int)$v['anio'],
                'kilometraje' => (int)$v['kilometraje'], 'precio' => (float)$v['precio'], 'localidad' => $v['localidad'],
                'transmision' => $v['transmision'], 'combustible' => $v['combustible'], 'estado' => $v['estado'],
                'foto' => $v['foto_id'] ? '/api/fotos/' . $v['foto_id'] : null, 'imagen_url' => $v['imagen_url'],
            ];
        }
        header('Cache-Control: private, no-store');
        Response::json(['filas' => array_values($filas), 'localidades' => $localidades,
            'zona_personalizada' => $zona !== '' || $preferida !== '']);
    }
}
