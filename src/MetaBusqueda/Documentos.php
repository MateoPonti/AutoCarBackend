<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

/**
 * Base de documentos (NoSQL) del metabuscador. Rutas "coleccion/id", como en Firestore.
 * Implementaciones: Firestore (Render y la PC del scraper), DocumentosArchivos (local y pruebas de integración)
 * y DocumentosMemoria (pruebas unitarias). Se elige con MERCADO_ALMACEN (ver Almacenamiento).
 *
 * Colecciones:
 *   mercado/{clave}       autos de un modelo de todas las fuentes, con su historial (ver Catalogo)
 *   indices/mercado       resumen de cada modelo: marcas, fuentes y rangos de precio
 *   recolecciones/{fuente} últimas corridas de cada recolector
 *   busquedas/{usuario}   búsquedas guardadas de un usuario y hasta cuándo vio sus avisos
 *   avisos/{usuario}      avisos que generó el importador para ese usuario
 */
interface Documentos
{
    /** @return array<string, mixed>|null el documento, o null si no existe */
    public function leer(string $ruta): ?array;

    /**
     * Escribe (reemplaza) varios documentos.
     *
     * @param array<string, array<string, mixed>> $documentos ruta → campos
     */
    public function escribir(array $documentos): void;

    /** @return array<string, array<string, mixed>> id → campos de cada documento de la colección */
    public function listar(string $coleccion): array;
}
