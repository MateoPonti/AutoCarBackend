<?php
declare(strict_types=1);

namespace AutoCar\Comparador;

/**
 * Dónde viven los documentos de modelos. Dos implementaciones con el mismo contrato:
 *   - AlmacenArchivos: storage/mercado/modelos/ (local, para probar sin nada creado).
 *   - AlmacenFirestore: la colección "modelos" de Firestore (lo que lee Render).
 * Se elige con COMPARADOR_ALMACEN=archivos|firestore (por defecto archivos).
 */
interface Almacen
{
    /** @return array<string, mixed>|null el documento del modelo, o null si no hay datos */
    public function modelo(string $clave): ?array;

    /** @return array<string, string> clave → huella de lo guardado, para escribir solo lo que cambió */
    public function huellas(): array;

    /**
     * Guarda los modelos cuya huella cambió y actualiza el índice de huellas.
     *
     * @param array<string, array<string, mixed>> $modelos
     * @return int cantidad de documentos escritos
     */
    public function guardarModelos(array $modelos): int;
}
