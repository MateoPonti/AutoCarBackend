<?php
declare(strict_types=1);

namespace AutoCar\Comparador;

use AutoCar\Catalogo\Vehiculo;
use AutoCar\Core\Logger;
use AutoCar\Core\Response;
use Throwable;

/** Público: el bloque "¿Está bien el precio?" de la ficha. Compara en el momento con una sola lectura del modelo. */
final class ComparadorController
{
    public function vehiculo(int $id): void
    {
        $v = Vehiculo::find($id);
        if ($v === null || !in_array($v['estado'], Vehiculo::VISIBLES, true)) {
            Response::error(404, 'no_encontrado', 'El vehículo no existe.');
        }
        try {
            $modelo = Almacenes::configurado()->modelo(Clave::de((string)$v['marca'], (string)$v['modelo']));
        } catch (Throwable $e) {
            // Si Firestore no responde, la ficha sigue andando: solo falta este bloque
            Logger::error('Comparador: ' . $e->getMessage());
            Response::error(503, 'comparador_no_disponible', 'La comparación de precios no está disponible en este momento.');
        }
        Response::json(['vehiculo_id' => $id, 'comparacion' => Comparador::comparar([
            'marca'       => (string)$v['marca'],
            'modelo'      => (string)$v['modelo'],
            'anio'        => (int)$v['anio'],
            'kilometraje' => (int)$v['kilometraje'],
            'precio'      => (float)$v['precio'],
        ], $modelo)]);
    }
}
