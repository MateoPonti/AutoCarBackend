<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

/** Documentos en memoria, para las pruebas unitarias. Cuenta las escrituras para poder verificar que solo escribe lo que cambió. */
final class DocumentosMemoria implements Documentos
{
    /** @var array<string, array<string, mixed>> */
    private array $docs = [];
    public int $escrituras = 0;

    public function leer(string $ruta): ?array
    {
        return $this->docs[$ruta] ?? null;
    }

    public function escribir(array $documentos): void
    {
        foreach ($documentos as $ruta => $campos) {
            $this->docs[$ruta] = $campos;
            $this->escrituras++;
        }
    }

    public function listar(string $coleccion): array
    {
        $salida = [];
        foreach ($this->docs as $ruta => $campos) {
            if (str_starts_with($ruta, "$coleccion/")) {
                $salida[substr($ruta, strlen($coleccion) + 1)] = $campos;
            }
        }
        ksort($salida);
        return $salida;
    }
}
