<?php
declare(strict_types=1);

namespace AutoCar\MetaBusqueda;

use AutoCar\Comparador\Clave;
use InvalidArgumentException;

/**
 * Carga la salida de un recolector (formato común, ver recolectores/README.md) en la base de documentos.
 * Las corridas incompletas se guardan, pero no retiran autos ni generan avisos ni se usan para calcular, porque un
 * auto ausente puede ser solo una página que falló.
 *
 * Escribe solo los documentos de modelos que cambiaron, después el índice y los avisos, y al final la corrida en
 * "recolecciones/{fuente}". Si algo falla a mitad de camino, la corrida no queda registrada y se puede volver a
 * importar el mismo archivo: aplicar dos veces la misma corrida da el mismo resultado y los avisos no se repiten.
 */
final class Importador
{
    /** Con menos que este porcentaje de la corrida anterior, se desconfía de la corrida. */
    private const PISO_RESPECTO_ANTERIOR = 0.8;
    private const CORRIDAS_GUARDADAS = 30;
    /** Cuántas marcas y modelos que faltan en el catálogo se anotan por corrida (los de más autos). */
    private const FALTANTES_GUARDADOS = 100;

    /** @var array<string, int>|null clave de cada marca del catálogo ("mercedes benz"), para buscarla rápido */
    private ?array $clavesConocidas;
    /** @var array<string, true>|null clave de cada modelo del catálogo ("toyota_corolla") */
    private ?array $modelosConocidos = null;

    /**
     * @param list<string>|null $marcasConocidas las de la tabla marca de Neon. Un auto con otra marca no se carga y se
     *                                           avisa (ver marcaConocida). null: se cargan todas.
     * @param list<array{marca: string, modelos: list<string>}>|null $catalogo marcas y modelos de Neon (lo mismo que
     *        GET /api/marcas-modelos). Un auto con un modelo que no está se carga igual, pero se anota en la corrida.
     */
    public function __construct(private Documentos $docs, ?array $marcasConocidas = null, ?array $catalogo = null)
    {
        $this->clavesConocidas = $marcasConocidas === null ? null
            : array_flip(array_map([Clave::class, 'marca'], $marcasConocidas));
        if ($catalogo !== null) {
            $this->modelosConocidos = [];
            foreach ($catalogo as $m) {
                foreach ($m['modelos'] as $modelo) {
                    $this->modelosConocidos[Catalogo::claveDe($m['marca'], $modelo)] = true;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $datos
     * @return array{fuente: string, fecha: string, autos: int, completo: bool, modelos_escritos: int, retirados: int, avisos: int, apartados: int, modelos_faltantes: int, advertencias: list<string>}
     */
    public function importar(array $datos): array
    {
        $fuente = is_string($datos['fuente'] ?? null) ? $datos['fuente'] : '';
        if (preg_match('/^[a-z0-9_-]{1,30}$/', $fuente) !== 1) {
            throw new InvalidArgumentException('"fuente" debe ser un nombre corto en minúsculas.');
        }
        $marcaDeTiempo = is_string($datos['fecha'] ?? null) ? strtotime($datos['fecha']) : false;
        if ($marcaDeTiempo === false) {
            throw new InvalidArgumentException('"fecha" no es una fecha válida.');
        }
        $fecha = date(DATE_ATOM, $marcaDeTiempo);
        if (!is_array($datos['autos'] ?? null)) {
            throw new InvalidArgumentException('"autos" debe ser una lista.');
        }

        $porModelo = [];
        $cantidad = 0;
        $invalidos = 0;
        // Un auto con datos inválidos (por ejemplo 9.999.999 km, mal cargado por el vendedor) no se carga. Si trae su
        // número de aviso, igual se sabe que sigue publicado: queda protegido para no darlo por retirado y la corrida
        // sigue completa. Solo uno sin número de aviso deja la corrida incompleta (no se sabe cuál es).
        $protegidos = [];
        $sinId = 0;
        $apartadas = []; // marca como la escribió el sitio → cantidad de autos
        $modelosFaltantes = []; // "Marca Modelo" que no está en el catálogo → cantidad de autos (se cargan igual)
        foreach ($datos['autos'] as $auto) {
            $limpio = is_array($auto) ? self::validarAuto($auto) : null;
            $clave = $limpio !== null ? Catalogo::claveDe($limpio['marca'], $limpio['modelo']) : '';
            if ($limpio === null || preg_match('/^[a-z0-9][a-z0-9_]*$/', $clave) !== 1) {
                $invalidos++;
                $idMalo = is_array($auto) ? ($auto['id_externo'] ?? null) : null;
                $idMalo = is_int($idMalo) ? (string)$idMalo : (is_string($idMalo) ? trim($idMalo) : '');
                if ($idMalo !== '') {
                    $protegidos[$idMalo] = true;
                } else {
                    $sinId++;
                }
                continue;
            }
            // Marca que no está en el catálogo: no se carga y se avisa. Como sigue publicado, no se da por retirado.
            if (!$this->marcaConocida($limpio['marca'])) {
                $protegidos[$limpio['id_externo']] = true;
                $apartadas[$limpio['marca']] = ($apartadas[$limpio['marca']] ?? 0) + 1;
                continue;
            }
            if (!isset($porModelo[$clave][$limpio['id_externo']])) {
                $cantidad++;
                if ($this->modelosConocidos !== null && !isset($this->modelosConocidos[$clave])) {
                    $nombre = Clave::nombreMarca($limpio['marca']) . ' ' . $limpio['modelo'];
                    $modelosFaltantes[$nombre] = ($modelosFaltantes[$nombre] ?? 0) + 1;
                }
            }
            $porModelo[$clave][$limpio['id_externo']] = $limpio;
        }

        $advertencias = [];
        $completo = ($datos['completo'] ?? false) === true;
        if ($sinId > 0) {
            $completo = false;
            $advertencias[] = "$sinId autos inválidos sin número de aviso: la corrida queda marcada como incompleta.";
        }
        if ($invalidos > $sinId) {
            $advertencias[] = ($invalidos - $sinId) . ' autos con datos inválidos: no se cargan, pero tampoco se dan por retirados.';
        }
        if ($apartadas !== []) {
            $detalle = implode(', ', array_map(static fn($m, $n) => "$m: $n", array_keys($apartadas), $apartadas));
            $advertencias[] = array_sum($apartadas) . " autos con marcas que no están en el catálogo ($detalle): no se cargaron. "
                . 'Para verlos: php bin/importar-autos.php --revisar-marcas archivo.json';
        }

        $recolecciones = $this->docs->leer("recolecciones/$fuente") ?? ['fuente' => $fuente, 'corridas' => []];
        $ultima = $recolecciones['corridas'] === [] ? null : end($recolecciones['corridas'])['fecha'];
        if ($ultima !== null && strtotime($ultima) >= $marcaDeTiempo) {
            throw new InvalidArgumentException("Ya hay una recolección de $fuente con esa fecha o posterior ($ultima).");
        }
        $completas = array_values(array_filter($recolecciones['corridas'], static fn(array $c): bool => $c['completo']));
        $anterior = $completas === [] ? null : end($completas);
        if ($completo && $anterior !== null && $cantidad < $anterior['autos'] * self::PISO_RESPECTO_ANTERIOR) {
            $completo = false;
            $advertencias[] = "Trajo $cantidad autos contra {$anterior['autos']} de la corrida anterior: queda marcada como incompleta.";
        }

        // Modelos a tocar: los que vinieron y, si la corrida es completa, los que tenían autos de esta fuente (para retirarlos)
        $indice = Indice::leer($this->docs);
        $claves = array_keys($porModelo);
        if ($completo) {
            foreach ($indice as $clave => $m) {
                if (($m['fuentes'][$fuente] ?? 0) > 0) {
                    $claves[] = (string)$clave;
                }
            }
        }

        $escribir = [];
        $escritos = 0;
        $retirados = 0;
        $cambios = [];
        foreach (array_unique($claves) as $clave) {
            $clave = (string)$clave;
            $llegados = $porModelo[$clave] ?? [];
            $modelo = Catalogo::leer($this->docs, $clave);
            if ($modelo === null) {
                $primero = reset($llegados);
                $modelo = ['clave' => $clave, 'marca' => Clave::nombreMarca($primero['marca']), 'modelo' => $primero['modelo'], 'autos' => []];
            }
            $antes = $modelo['autos'];
            foreach ($llegados as $a) {
                Catalogo::registrar($modelo, $fuente, $a, $fecha, $completo);
            }
            if ($completo) {
                $retirados += Catalogo::retirarAusentes($modelo, $fuente, $fecha, $protegidos);
                if ($anterior !== null) {
                    $novedades = Novedades::comparar(
                        Catalogo::fotos($modelo, $fuente, $fecha),
                        Catalogo::fotos($modelo, $fuente, $anterior['fecha']),
                    );
                    foreach ($novedades['cambios'] as $c) {
                        if (in_array($c['tipo'], Novedades::CON_AVISO, true)) {
                            $cambios[] = $c;
                        }
                    }
                }
            }
            if ($modelo['autos'] != $antes) {
                $escribir += Catalogo::documentos($modelo);
                $escritos++;
            }
            $resumen = Catalogo::resumen($modelo);
            if ($resumen === null) {
                unset($indice[$clave]);
            } else {
                $indice[$clave] = $resumen;
            }
        }

        if ($escribir !== []) {
            $this->docs->escribir($escribir);
        }
        $this->docs->escribir(Indice::documento($indice));
        $avisos = $this->generarAvisos($cambios, $fecha);

        // Lo que falta en el catálogo de Neon, para decidir qué marca o modelo agregar (o qué nombre unir en Clave)
        $recolecciones['corridas'][] = ['fecha' => $fecha, 'completo' => $completo, 'autos' => $cantidad,
            'marcas_faltantes' => self::faltantes($apartadas), 'modelos_faltantes' => self::faltantes($modelosFaltantes)];
        $recolecciones['corridas'] = array_slice($recolecciones['corridas'], -self::CORRIDAS_GUARDADAS);
        $this->docs->escribir(["recolecciones/$fuente" => $recolecciones]);

        return [
            'fuente'           => $fuente,
            'fecha'            => $fecha,
            'autos'            => $cantidad,
            'completo'         => $completo,
            'modelos_escritos' => $escritos,
            'retirados'        => $retirados,
            'avisos'           => $avisos,
            'apartados'        => array_sum($apartadas),
            'modelos_faltantes' => array_sum($modelosFaltantes),
            'advertencias'     => $advertencias,
        ];
    }

    /**
     * Nombre → cantidad de autos, como lista ordenada de mayor a menor y cortada (el documento tiene tope de tamaño).
     * @param array<string, int> $porNombre
     * @return list<array{nombre: string, autos: int}>
     */
    private static function faltantes(array $porNombre): array
    {
        arsort($porNombre);
        $lista = [];
        foreach (array_slice($porNombre, 0, self::FALTANTES_GUARDADOS, true) as $nombre => $autos) {
            $lista[] = ['nombre' => (string)$nombre, 'autos' => $autos];
        }
        return $lista;
    }

    /**
     * Si la marca está en el catálogo, sin contar mayúsculas, acentos, signos ni apodos ("VW" = "Volkswagen").
     * Sin lista de marcas se aceptan todas.
     */
    public function marcaConocida(string $marca): bool
    {
        return $this->clavesConocidas === null || isset($this->clavesConocidas[Clave::marca($marca)]);
    }

    /** @param array<string, mixed> $a */
    public static function validarAuto(array $a): ?array
    {
        $texto = static function (mixed $v, int $max): ?string {
            if (!is_string($v) || trim($v) === '') {
                return null;
            }
            return mb_substr(trim($v), 0, $max);
        };
        $idCrudo = $a['id_externo'] ?? null;
        $id = is_int($idCrudo) ? (string)$idCrudo : $texto($idCrudo, 60);
        $marca = $texto($a['marca'] ?? null, 50);
        $modelo = $texto($a['modelo'] ?? null, 60);
        $anio = $a['anio'] ?? null;
        $km = $a['km'] ?? null;
        $moneda = $a['moneda'] ?? null;
        $precio = self::precio($a['precio'] ?? null);
        $contado = self::precio($a['precio_contado'] ?? null);

        if ($id === null || $marca === null || $modelo === null
            || !is_int($anio) || $anio < 1950 || $anio > 2100
            || !is_int($km) || $km < 0 || $km > 5000000
            || !in_array($moneda, ['ARS', 'USD'], true)
            || $precio === false || $contado === false) {
            return null;
        }
        $transmision = $a['transmision'] ?? null;
        $url = $texto($a['url'] ?? null, 500);

        return [
            'id_externo'     => $id,
            'marca'          => $marca,
            'modelo'         => $modelo,
            'version'        => $texto($a['version'] ?? null, 120),
            'anio'           => $anio,
            'km'             => $km,
            'transmision'    => in_array($transmision, ['manual', 'automatica'], true) ? $transmision : null,
            'precio'         => $precio,
            'precio_contado' => $contado,
            'moneda'         => $moneda,
            'reservado'      => ($a['reservado'] ?? false) === true,
            'ubicacion'      => $texto($a['ubicacion'] ?? null, 100),
            'url'            => $url !== null && preg_match('#^https?://#i', $url) === 1 ? $url : null, // se muestra como link
        ];
    }

    /** Número positivo, o null si no hay precio. false si el valor no es válido. */
    private static function precio(mixed $v): float|false|null
    {
        if ($v === null) {
            return null;
        }
        return (is_int($v) || is_float($v)) && $v > 0 && $v < 1e12 ? (float)$v : false;
    }

    /** @param list<array<string, mixed>> $cambios */
    private function generarAvisos(array $cambios, string $fecha): int
    {
        if ($cambios === []) {
            return 0;
        }
        $porUsuario = [];
        $total = 0;
        $creado = microtime(true);
        foreach (Busqueda::todas($this->docs) as $b) {
            foreach ($cambios as $c) {
                if (!Busqueda::coincide($b, $c['auto'])) {
                    continue;
                }
                $a = $c['auto'];
                $porUsuario[$b['usuario_id']][] = [
                    'busqueda_id' => $b['id'], 'auto_id' => $a['id'], 'tipo' => $c['tipo'],
                    'precio_anterior' => $c['precio_anterior'], 'precio_actual' => $c['precio_actual'],
                    'fecha' => $fecha, 'creado' => $creado,
                    'marca' => $a['marca'], 'modelo' => $a['modelo'], 'version' => $a['version'],
                    'anio' => $a['anio'], 'fuente' => $a['fuente'],
                ];
                $total++;
            }
        }
        Avisos::agregar($this->docs, $porUsuario);
        return $total;
    }
}
