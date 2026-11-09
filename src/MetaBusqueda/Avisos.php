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

    private static function firma(array $a): string
    {
        return "{$a['busqueda_id']}|{$a['auto_id']}|{$a['fecha']}|{$a['tipo']}";
    }
}
