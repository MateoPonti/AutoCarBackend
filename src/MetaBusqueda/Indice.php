<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

/**
 * Documento "indices/mercado": el resumen de cada modelo (Catalogo::resumen). Con una sola lectura la API arma la lista
 * de marcas y elige qué modelos mirar para "otros modelos", y el importador sabe qué modelos tienen autos de una fuente.
 * Va como texto JSON (un campo) para no gastar el límite de 1 MiB en nombres de campo.
 */
final class Indice
{
    public const RUTA = 'indices/mercado';

    /** @return array<string, array<string, mixed>> */
    public static function leer(Documentos $docs): array
    {
        $doc = $docs->leer(self::RUTA);
        return json_decode((string)($doc['modelos_json'] ?? '{}'), true) ?: [];
    }

    /** @param array<string, array<string, mixed>> $modelos */
    public static function documento(array $modelos): array
    {
        ksort($modelos);
        return [self::RUTA => [
            'actualizado'  => date(DATE_ATOM),
            'modelos_json' => json_encode((object)$modelos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]];
    }
}
