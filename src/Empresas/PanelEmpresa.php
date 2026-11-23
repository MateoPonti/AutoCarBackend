<?php
declare(strict_types=1);

namespace AutoCar\Empresas;

use AutoCar\Catalogo\PrecioMercado;
use AutoCar\Catalogo\Vehiculo;
use AutoCar\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * El panel de una empresa (033): cómo le va a cada publicación. Visitas del período contra el anterior, la más vista,
 * visitas por día, y por auto visitas → favoritos → consultas → inspecciones pedidas, más su precio contra el mercado
 * (autos parecidos de otros sitios) y cuántos días lleva publicado.
 */
final class PanelEmpresa
{
    public const ZONA = 'America/Argentina/Buenos_Aires';

    public static function de(int $empresaId, int $dias, DateTimeImmutable $ahora): array
    {
        $autos = Vehiculo::delUsuario($empresaId);
        $ids = array_map(static fn(array $v): int => (int)$v['id'], $autos);
        $hoy = $ahora->setTimezone(new DateTimeZone(self::ZONA))->format('Y-m-d');
        $porAuto = ['visitas' => [], 'anterior' => [], 'favoritos' => [], 'consultas' => [], 'inspecciones' => []];
        $serie = [];
        if ($ids !== []) {
            $pdo = Database::pdo();
            $en = implode(',', $ids); // enteros de la base
            $desde = "(?::date - $dias)"; // $dias validado (7, 30 o 90)
            $st = $pdo->prepare("SELECT vehiculo_id, COUNT(*) FILTER (WHERE dia > $desde) AS actual,
                                        COUNT(*) FILTER (WHERE dia <= $desde AND dia > (?::date - 2 * $dias)) AS anterior
                                   FROM vehiculo_visita WHERE vehiculo_id IN ($en) GROUP BY 1");
            $st->execute([$hoy, $hoy, $hoy]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $porAuto['visitas'][(int)$f['vehiculo_id']] = (int)$f['actual'];
                $porAuto['anterior'][(int)$f['vehiculo_id']] = (int)$f['anterior'];
            }
            $st = $pdo->prepare("SELECT to_char(dia, 'YYYY-MM-DD') AS dia, COUNT(*) AS n FROM vehiculo_visita
                                  WHERE vehiculo_id IN ($en) AND dia > $desde GROUP BY dia");
            $st->execute([$hoy]);
            $serie = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            $periodo = "created_at > now() - make_interval(days => $dias)";
            foreach (['favoritos' => 'favorito', 'consultas' => 'conversacion', 'inspecciones' => 'inspeccion'] as $clave => $tabla) {
                $porAuto[$clave] = array_map('intval', $pdo->query(
                    "SELECT vehiculo_id, COUNT(*) FROM $tabla WHERE vehiculo_id IN ($en) AND $periodo GROUP BY 1"
                )->fetchAll(PDO::FETCH_KEY_PAIR));
            }
        }
        $disponibles = array_values(array_filter($autos, static fn(array $v): bool => $v['estado'] === 'disponible'));
        \AutoCar\Catalogo\CatalogoController::sincronizarPrecios();
        return self::armar($autos, $porAuto, $serie, PrecioMercado::guardados($disponibles), $dias, $ahora);
    }

    /**
     * Función pura: arma el panel.
     * @param list<array<string, mixed>> $autos filas de Vehiculo::delUsuario
     * @param array<string, array<int, int>> $porAuto visitas, anterior, favoritos, consultas e inspecciones por id
     * @param array<string, int|string> $serie visitas por día ("AAAA-MM-DD" → n)
     * @param array<int, array<string, mixed>> $mercado PrecioMercado::de por id
     */
    public static function armar(array $autos, array $porAuto, array $serie, array $mercado, int $dias, DateTimeImmutable $ahora): array
    {
        $local = $ahora->setTimezone(new DateTimeZone(self::ZONA));
        $filas = [];
        foreach ($autos as $v) {
            $id = (int)$v['id'];
            $visitas = $porAuto['visitas'][$id] ?? 0;
            $consultas = $porAuto['consultas'][$id] ?? 0;
            $m = $mercado[$id] ?? null;
            $filas[] = [
                'id'             => $id,
                'titulo'         => trim("{$v['marca']} {$v['modelo']} {$v['anio']}"),
                'estado'         => $v['estado'],
                'precio'         => $v['precio'] !== null ? (float)$v['precio'] : null,
                'foto'           => isset($v['foto_id']) ? '/api/fotos/' . (int)$v['foto_id'] : null,
                'dias_publicado' => max(0, (int)floor(($ahora->getTimestamp() - strtotime((string)$v['created_at'])) / 86400)),
                'visitas'        => $visitas,
                'visitas_anterior' => $porAuto['anterior'][$id] ?? 0,
                'favoritos'      => $porAuto['favoritos'][$id] ?? 0,
                'consultas'      => $consultas,
                'inspecciones'   => $porAuto['inspecciones'][$id] ?? 0,
                // De los que lo vieron, qué parte consultó (en %)
                'conversion'     => $visitas > 0 ? round(min($consultas / $visitas, 1) * 100, 1) : null,
                'mercado'        => $m !== null && $m['suficientes']
                    ? ['diferencia_pct' => $m['diferencia_pct'], 'mediana_precio' => $m['mediana_precio'], 'comparables' => $m['comparables']]
                    : null,
            ];
        }
        usort($filas, static fn(array $a, array $b): int => [$b['visitas'], $b['id']] <=> [$a['visitas'], $a['id']]);

        $dias_serie = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = $local->modify("-$i days")->format('Y-m-d');
            $dias_serie[] = ['fecha' => $fecha, 'visitas' => (int)($serie[$fecha] ?? 0)];
        }
        $suma = static fn(string $clave): int => array_sum(array_column($filas, $clave));
        $masVista = $filas !== [] && $filas[0]['visitas'] > 0 ? $filas[0] : null;
        return [
            'dias'      => $dias,
            'resumen'   => [
                'visitas'          => $suma('visitas'),
                'visitas_anterior' => $suma('visitas_anterior'),
                'favoritos'        => $suma('favoritos'),
                'consultas'        => $suma('consultas'),
                'inspecciones'     => $suma('inspecciones'),
                'autos_activos'    => count(array_filter($filas, static fn(array $f): bool => in_array($f['estado'], Vehiculo::ACTIVOS, true))),
            ],
            'mas_vista' => $masVista === null ? null : ['id' => $masVista['id'], 'titulo' => $masVista['titulo'], 'visitas' => $masVista['visitas']],
            'serie'     => $dias_serie,
            'autos'     => $filas,
        ];
    }
}
