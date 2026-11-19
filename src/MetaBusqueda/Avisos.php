<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

/**
 * Avisos de un usuario en el documento "avisos/{usuario_id}": auto nuevo, baja de precio, reservado o retirado.
 * Los escribe solo el importador. Si un aviso está visto se decide con lo que guarda Busqueda::marcarVistos.
 */
final class Avisos
{
    private const MAXIMO_POR_USUARIO = 300; // se guardan los más nuevos

    /**
     * Agrega avisos a cada usuario. Si una corrida se vuelve a importar, no se repiten.
     *
     * @param array<int, list<array<string, mixed>>> $porUsuario usuario → avisos sin id
     */
    public static function agregar(Documentos $docs, array $porUsuario): void
    {
        $escribir = [];
        foreach ($porUsuario as $usuarioId => $nuevos) {
            $doc = $docs->leer("avisos/$usuarioId") ?? ['usuario_id' => $usuarioId, 'siguiente' => 1, 'avisos' => []];
            $existentes = [];
            foreach ($doc['avisos'] as $a) {
                $existentes[self::firma($a)] = true;
            }
            foreach ($nuevos as $a) {
                if (isset($existentes[self::firma($a)])) {
                    continue;
                }
                $doc['avisos'][] = ['id' => (int)$doc['siguiente']] + $a;
                $doc['siguiente']++;
            }
            $doc['avisos'] = array_slice($doc['avisos'], -self::MAXIMO_POR_USUARIO);
            $escribir["avisos/$usuarioId"] = $doc;
        }
        if ($escribir !== []) {
            $docs->escribir($escribir);
        }
    }

    /**
     * Últimos avisos del usuario de sus búsquedas que siguen existiendo, el más nuevo primero.
     *
     * @return list<array<string, mixed>>
     */
    public static function delUsuario(Documentos $docs, int $usuarioId, bool $soloSinVer = false, int $limite = 100): array
    {
        $busquedas = array_column(Busqueda::delUsuario($docs, $usuarioId), null, 'id');
        $vistos = Busqueda::vistos($docs, $usuarioId);
        $salida = [];
        foreach (($docs->leer("avisos/$usuarioId") ?? [])['avisos'] ?? [] as $a) {
            $busqueda = (int)$a['busqueda_id'];
            if (!isset($busquedas[$busqueda])) {
                continue; // de una búsqueda borrada (los ids no se reusan)
            }
            $visto = (float)$a['creado'] <= ($vistos[$busqueda] ?? 0.0);
            if ($soloSinVer && $visto) {
                continue;
            }
            $salida[] = [
                'id'              => (int)$a['id'],
                'busqueda_id'     => $busqueda,
                'auto_id'         => (string)$a['auto_id'],
                'tipo'            => $a['tipo'],
                'precio_anterior' => $a['precio_anterior'] !== null ? (float)$a['precio_anterior'] : null,
                'precio_actual'   => $a['precio_actual'] !== null ? (float)$a['precio_actual'] : null,
                'fecha'           => $a['fecha'],
                'visto'           => $visto,
                'marca'           => $a['marca'],
                'modelo'          => $a['modelo'],
                'version'         => $a['version'],
                'anio'            => (int)$a['anio'],
                'fuente'          => $a['fuente'],
            ];
        }
        usort($salida, static fn(array $x, array $y): int => [$y['fecha'], $y['id']] <=> [$x['fecha'], $x['id']]);
        return array_slice($salida, 0, $limite);
    }

    /** Los tipos que muestra el gráfico de novedades por día (el reservado no: el auto sigue en la lista) */
    public const POR_DIA = [Novedades::NUEVO, Novedades::BAJO, Novedades::RETIRADO];

    /**
     * Cuántos avisos de cada tipo hubo cada día de los últimos $dias (hasta $hoy incluido, con ceros), en hora de
     * Argentina. Para el gráfico de barras de la página de novedades de una búsqueda. Función pura.
     *
     * @param list<array<string, mixed>> $avisos los de Avisos::delUsuario (con "fecha" de la corrida y "tipo")
     * @return list<array{fecha: string, nuevo: int, bajo: int, retirado: int}> del más viejo al de hoy
     */
    public static function porDia(array $avisos, \DateTimeImmutable $hoy, int $dias): array
    {
        $zona = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $hoy = $hoy->setTimezone($zona);
        $salida = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = $hoy->modify("-$i days")->format('Y-m-d');
            $salida[$fecha] = ['fecha' => $fecha] + array_fill_keys(self::POR_DIA, 0);
        }
        foreach ($avisos as $a) {
            try {
                $fecha = (new \DateTimeImmutable((string)$a['fecha']))->setTimezone($zona)->format('Y-m-d');
            } catch (\Exception) {
                continue;
            }
            if (isset($salida[$fecha]) && in_array($a['tipo'], self::POR_DIA, true)) {
                $salida[$fecha][$a['tipo']]++;
            }
        }
        return array_values($salida);
    }

    private static function firma(array $a): string
    {
        return "{$a['busqueda_id']}|{$a['auto_id']}|{$a['fecha']}|{$a['tipo']}";
    }
}
