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
        $localidad = $usuario ? (Preferencias::delUsuario($usuario)['localidad'] ?? '') : '';
        // Instalaciones anteriores a la migración 033 todavía no tienen vitrinas.
        $empresas = $db->query("SELECT to_regclass('empresa')")->fetchColumn();
        $vitrinas = $empresas ? "
            UNION ALL
            SELECT v.id, 'empresa', e.usuario_id::text, e.razon_social
              FROM disponibles v JOIN empresa e ON e.usuario_id = v.usuario_id
              JOIN usuario u ON u.id = e.usuario_id JOIN rol r ON r.id = u.rol_id
             WHERE e.estado = 'aprobado' AND u.activo AND r.nombre = 'empresa'" : '';

        $st = $db->prepare("WITH disponibles AS (
              SELECT id, usuario_id, marca, modelo, anio, kilometraje, localidad, created_at FROM vehiculo WHERE estado = 'disponible'
            ), marcas AS (
              SELECT marca FROM disponibles GROUP BY marca ORDER BY count(*) DESC, marca LIMIT 3
            ), ultimas_firmas AS (
              SELECT DISTINCT ON (i.vehiculo_id) i.vehiculo_id, i.firmada_at, i.conclusion,
                     i.marca, i.modelo, i.anio, i.kilometraje
                FROM inspeccion i JOIN disponibles v ON v.id = i.vehiculo_id
               WHERE i.estado = 'firmada' AND i.fuente = 'autocar'
               ORDER BY i.vehiculo_id, i.firmada_at DESC, i.id DESC
            ), grupos(id, tipo, clave, nombre) AS (
              SELECT v.id, 'verificados', 'mecanica', 'Autos verificados'
                FROM disponibles v JOIN ultimas_firmas i ON i.vehiculo_id = v.id
               WHERE i.conclusion = 'recomendable' AND i.firmada_at > now() - interval '90 days'
                 AND (i.marca, i.modelo, i.anio, i.kilometraje) = (v.marca, v.modelo, v.anio, v.kilometraje)
              UNION ALL
              SELECT id, 'localidad', lower(trim(localidad)), trim(localidad) FROM disponibles
               WHERE trim(localidad) <> '' AND lower(trim(localidad)) = lower(trim(:localidad))
              UNION ALL
              SELECT id, 'marca', v.marca, v.marca FROM disponibles v JOIN marcas m ON m.marca = v.marca
              $vitrinas
            ), posiciones AS (
              SELECT g.*, row_number() OVER (PARTITION BY tipo, clave ORDER BY v.created_at DESC, v.id DESC) AS puesto
                FROM grupos g JOIN disponibles v USING (id)
            )
            SELECT p.tipo, p.clave, p.nombre, v.*, f.id AS foto_id
              FROM posiciones p JOIN vehiculo v USING (id)
              LEFT JOIN LATERAL (SELECT id FROM vehiculo_foto WHERE vehiculo_id = v.id ORDER BY orden, id LIMIT 1) f ON true
             WHERE p.puesto <= 12
             ORDER BY CASE p.tipo WHEN 'localidad' THEN 0 WHEN 'empresa' THEN 1 ELSE 2 END, p.clave, p.puesto");
        $st->execute(['localidad' => $localidad]);
        // La sección también existe cuando aún no hay vehículos que cumplan el criterio.
        $filas = ['verificados-mecanica' => ['tipo' => 'verificados', 'nombre' => 'Autos verificados', 'items' => []]];
        foreach ($st as $v) {
            $clave = $v['tipo'] . '-' . $v['clave'];
            $filas[$clave] ??= ['tipo' => $v['tipo'], 'nombre' => $v['nombre'], 'items' => []];
            $filas[$clave]['items'][] = [
                'id' => (int)$v['id'], 'marca' => $v['marca'], 'modelo' => $v['modelo'], 'anio' => (int)$v['anio'],
                'kilometraje' => (int)$v['kilometraje'], 'precio' => (float)$v['precio'], 'localidad' => $v['localidad'],
                'transmision' => $v['transmision'], 'combustible' => $v['combustible'], 'estado' => $v['estado'],
                'foto' => $v['foto_id'] ? '/api/fotos/' . $v['foto_id'] : null, 'imagen_url' => $v['imagen_url'],
            ];
        }
        header('Cache-Control: private, no-store');
        Response::json(['filas' => array_values($filas)]);
    }
}
