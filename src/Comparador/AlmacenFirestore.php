<?php
declare(strict_types=1);

namespace AutoCar\Comparador;

/**
 * Modelos en Firestore: colección "modelos" (un documento por clave) e índice "indices/modelos" con las huellas.
 *
 * Firestore no admite listas dentro de listas, así que los autos de cada modelo van como texto JSON en "autos_json".
 * Cuentas del plan gratis: la API hace 1 lectura por publicación vista; la carga escribe solo los modelos que
 * cambiaron (como mucho ~1.500 por día) más el índice, lejos de las 20.000 escrituras diarias.
 */
final class AlmacenFirestore implements Almacen
{
    public function __construct(private Firestore $db)
    {
    }

    public function modelo(string $clave): ?array
    {
        if (preg_match('/^[a-z0-9_]+$/', $clave) !== 1) {
            return null;
        }
        $doc = $this->db->leer("modelos/$clave");
        if ($doc === null) {
            return null;
        }
        $doc['autos'] = json_decode((string)($doc['autos_json'] ?? '[]'), true) ?: [];
        unset($doc['autos_json']);
        return $doc;
    }

    public function huellas(): array
    {
        $indice = $this->db->leer('indices/modelos');
        return is_array($indice['huellas'] ?? null) ? $indice['huellas'] : [];
    }

    public function guardarModelos(array $modelos): int
    {
        $huellas = $this->huellas();
        $documentos = [];
        foreach ($modelos as $clave => $m) {
            if (($huellas[$clave] ?? null) === $m['huella']) {
                continue;
            }
            $autos = $m['autos'];
            unset($m['autos']);
            $documentos["modelos/$clave"] = $m + ['autos_json' => json_encode($autos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            $huellas[$clave] = $m['huella'];
        }
        if ($documentos === []) {
            return 0;
        }
        $this->db->escribir($documentos);
        $this->db->escribir(['indices/modelos' => ['huellas' => $huellas, 'actualizado' => date(DATE_ATOM)]]);
        return count($documentos);
    }
}
