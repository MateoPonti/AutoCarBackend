<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

use InvalidArgumentException;

/** Documentos como archivos JSON: {carpeta}/{coleccion}/{id}.json. Para correr todo en local sin Firestore. */
final class DocumentosArchivos implements Documentos
{
    public function __construct(private string $carpeta)
    {
    }

    public function leer(string $ruta): ?array
    {
        $archivo = $this->archivo($ruta);
        $datos = is_file($archivo) ? json_decode((string)file_get_contents($archivo), true) : null;
        return is_array($datos) ? $datos : null;
    }

    public function escribir(array $documentos): void
    {
        foreach ($documentos as $ruta => $campos) {
            $archivo = $this->archivo($ruta);
            if (!is_dir(dirname($archivo))) {
                mkdir(dirname($archivo), 0775, true);
            }
            // Primero a un temporal: la API puede estar leyendo el mismo archivo
            file_put_contents("$archivo.tmp", json_encode($campos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            rename("$archivo.tmp", $archivo);
        }
    }

    public function listar(string $coleccion): array
    {
        $salida = [];
        $carpeta = dirname($this->archivo("$coleccion/x"));
        foreach (glob("$carpeta/*.json") ?: [] as $archivo) {
            $datos = json_decode((string)file_get_contents($archivo), true);
            if (is_array($datos)) {
                $salida[basename($archivo, '.json')] = $datos;
            }
        }
        ksort($salida);
        return $salida;
    }

    /** Solo "coleccion/id" con caracteres simples: una ruta nunca sale de la carpeta. */
    private function archivo(string $ruta): string
    {
        if (preg_match('#^[a-z]+/[a-z0-9_~-]+$#', $ruta) !== 1) {
            throw new InvalidArgumentException("Ruta de documento inválida: $ruta");
        }
        return "$this->carpeta/$ruta.json";
    }
}
