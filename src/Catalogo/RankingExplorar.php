<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

use AutoCar\MetaBusqueda\Estadistica;
use AutoCar\MetaBusqueda\ParaVos;
use DateTimeImmutable;

/**
 * El orden "Más relevantes" de Explorar: el MISMO cálculo que el metabuscador. Con perfil ("¿Para qué buscás el auto?"),
 * el puntaje de "para vos" (precio y km contra autos parecidos, días publicado y año, con los pesos de ese perfil que el
 * admin cambia en Parámetros); sin perfil, la diferencia con el precio típico. Los autos parecidos son los de otros
 * sitios (PrecioMercado, ya calculado: Explorar no lee Firestore). Particulares y empresas compiten igual. Sin al menos 5 parecidos el auto no tiene puntaje y va
 * después de los que sí, del más nuevo al más viejo. Cada fila trae "mensaje" (por qué se recomienda, o null): Explorar
 * se lo muestra solo a los primeros (CatalogoController::RECOMENDADOS).
 */
final class RankingExplorar
{
    /** Tope de publicaciones que se ordenan (el catálogo entero de AutoCar entra de sobra) */
    public const MAXIMO = 1000;

    /**
     * Función pura: ordena las publicaciones del catálogo.
     * @param list<array<string, mixed>> $vehiculos filas de Vehiculo::buscar
     * @param array<int, array<string, mixed>> $mercado PrecioMercado::de por id
     * @param string|null $uso perfil del que mira (null sin perfil o sin sesión)
     * @param array<string, array<string, float>>|null $pesos ParaVos::pesosActuales()
     * @return list<array<string, mixed>> las mismas filas, con "puntaje" (null si no tiene), de la más relevante a la menos
     */
    public static function ordenar(array $vehiculos, array $mercado, ?string $uso, ?array $pesos, DateTimeImmutable $ahora): array
    {
        $conPerfil = $uso !== null && isset(ParaVos::PESOS[$uso]);
        $anioTipico = (float)(Estadistica::mediana(array_map(static fn(array $v): int => (int)$v['anio'], $vehiculos)) ?? 0);
        foreach ($vehiculos as &$v) {
            $m = $mercado[(int)$v['id']] ?? null;
            $v['puntaje'] = null;
            $v['mensaje'] = null;
            if ($m === null || !$m['suficientes']) {
                continue;
            }
            if ($conPerfil) {
                $r = ParaVos::puntuar(
                    ['km' => (int)$v['kilometraje'], 'anio' => (int)$v['anio'],
                     'primera_vez' => (string)$v['created_at'], 'ultima_vez' => $ahora->format(DATE_ATOM)],
                    ['suficientes' => true, 'mediana_km' => $m['mediana_km']],
                    ['diferencia_pct' => $m['diferencia_pct']],
                    $uso, $anioTipico, $pesos,
                );
                $v['puntaje'] = $r['puntaje'] ?? null;
                $v['mensaje'] = $r['mensaje'] ?? null;
            } else {
                // Sin perfil, como el metabuscador: cuanto más barato frente a los parecidos, más arriba (20 % = 1)
                $v['puntaje'] = round(max(-1.0, min(1.0, -(float)$m['diferencia_pct'] / 20)), 2);
                if ((float)$m['diferencia_pct'] < 0) {
                    $v['mensaje'] = 'Está ' . str_replace('.', ',', (string)abs((float)$m['diferencia_pct']))
                        . ' % por debajo del precio típico de autos parecidos en otros sitios.';
                }
            }
        }
        unset($v);
        usort($vehiculos, static fn(array $a, array $b): int =>
            [$b['puntaje'] !== null, $b['puntaje'] ?? 0, (string)$b['created_at'], (int)$b['id']]
            <=> [$a['puntaje'] !== null, $a['puntaje'] ?? 0, (string)$a['created_at'], (int)$a['id']]);
        return $vehiculos;
    }
}
