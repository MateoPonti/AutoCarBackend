<?php
declare(strict_types=1);

namespace AutoCar\Comparador;

/** Modelos en archivos JSON: storage/mercado/modelos/{clave}.json y _huellas.json. Para correr todo en local. */
final class AlmacenArchivos implements Almacen
{
    public function __construct(private string $carpeta)
    {
    }

    public function modelo(string $clave): ?array
    {
        if (preg_match('/^[a-z0-9_]+$/', $clave) !== 1) {
            return null;
        }
        $archivo = "$this->carpeta/$clave.json";
        $datos = is_file($archivo) ? json_decode((string)file_get_contents($archivo), true) : null;
        return is_array($datos) ? $datos : null;
    }

    public function huellas(): array
    {
        $archivo = "$this->carpeta/_huellas.json";
        $datos = is_file($archivo) ? json_decode((string)file_get_contents($archivo), true) : null;
        return is_array($datos) ? $datos : [];
    }

    public function guardarModelos(array $modelos): int
    {
        if (!is_dir($this->carpeta)) {
            mkdir($this->carpeta, 0775, true);
        }
        $huellas = $this->huellas();
        $escritos = 0;
        foreach ($modelos as $clave => $m) {
            if (($huellas[$clave] ?? null) === $m['huella']) {
                continue;
            }
            file_put_contents("$this->carpeta/$clave.json", json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $huellas[$clave] = $m['huella'];
            $escritos++;
        }
        file_put_contents("$this->carpeta/_huellas.json", json_encode($huellas, JSON_PRETTY_PRINT));
        return $escritos;
    }
}
