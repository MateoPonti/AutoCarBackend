<?php
declare(strict_types=1);

namespace AutoCar\Catalogo;

use AutoCar\Core\Database;
use PDO;

/**
 * Marcas y modelos que se pueden publicar: tablas marca y modelo (013-marcas-y-modelos.sql), cargadas desde las
 * corridas del scraper. Es la única lista: el front la pide a GET /api/marcas-modelos.
 * La base también lo controla: vehiculo (marca, modelo) es una clave foránea a modelo, así que un auto
 * "Pepita 21021" no entra ni por la API ni por un SQL a mano.
 */
final class MarcaModelo
{
    /** @return list<array{marca: string, modelos: list<string>}> */
    public static function lista(): array
    {
        $filas = Database::pdo()->query('SELECT marca, nombre FROM modelo ORDER BY marca, nombre')->fetchAll(PDO::FETCH_ASSOC);
        $porMarca = [];
        foreach ($filas as $f) {
            $porMarca[$f['marca']][] = $f['nombre'];
        }
        $lista = [];
        foreach ($porMarca as $marca => $modelos) {
            $lista[] = ['marca' => (string)$marca, 'modelos' => $modelos];
        }
        return $lista;
    }

    /**
     * Errores por campo si la marca o el modelo no están en la lista (vacío si están). Se compara exacto:
     * el front manda los nombres tal cual vienen de lista().
     * @return array<string, string>
     */
    public static function errores(string $marca, string $modelo): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT 1 FROM marca WHERE nombre = :marca');
        $st->execute(['marca' => $marca]);
        if ($st->fetchColumn() === false) {
            return ['marca' => 'Elegí una marca de la lista.'];
        }
        $st = $pdo->prepare('SELECT 1 FROM modelo WHERE marca = :marca AND nombre = :modelo');
        $st->execute(['marca' => $marca, 'modelo' => $modelo]);
        return $st->fetchColumn() === false ? ['modelo' => "Elegí un modelo de $marca de la lista."] : [];
    }
}
