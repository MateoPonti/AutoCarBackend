<?php
declare(strict_types=1);

namespace AutoCar\Parametros;

use AutoCar\Core\Database;

/**
 * Los números del negocio que el admin cambia sin tocar código (tabla parametro): la comisión de AutoCar, el precio
 * de la inspección, el crédito del comprador si el auto se vendió, los pesos de la fórmula de "para vos" y el mínimo del
 * ranking de mecánicos. Sin reclamos ni fondo de garantía desde el 23/11 (migración 034).
 * Las reglas (por ejemplo, "sin patente no se firma") quedan en el código: acá solo hay números.
 */
final class Parametros
{
    /** Los que se pueden cambiar, en el orden del panel. */
    public const CLAVES = ['comision_autocar',
        'precio_minimo', 'precio_sugerido', 'precio_maximo', 'cotizacion_dolar', 'credito_vendido', 'horas_cupon',
        'presupuestos_por_arreglo', 'dias_presupuesto', 'comision_arreglos',
        'fallas_minimo_inspecciones', 'fallas_votos_confirmar', 'fallas_porcentaje_medido',
        // La fórmula de "para vos" (ver MetaBusqueda\ParaVos): peso_<perfil>_<criterio>
        'peso_personal_precio', 'peso_personal_km', 'peso_personal_dias', 'peso_personal_anio',
        'peso_familia_precio', 'peso_familia_km', 'peso_familia_dias', 'peso_familia_anio',
        'peso_trabajo_precio', 'peso_trabajo_km', 'peso_trabajo_dias', 'peso_trabajo_anio',
        'peso_aplicacion_precio', 'peso_aplicacion_km', 'peso_aplicacion_dias', 'peso_aplicacion_anio',
        // El ranking de los mecánicos (ver Inspecciones\Ranking)
        'ranking_minimo_firmadas',
        // Empresas (033): cuántos autos activos puede tener un particular y el abono mensual de cada empresa
        'limite_autos_particular', 'abono_empresa_mensual'];

    /** @var array<string, float>|null se leen una sola vez por pedido */
    private static ?array $valores = null;

    /** El valor de un parámetro. Si la clave no existe es un error de programación: corta con una excepción. */
    public static function valor(string $clave): float
    {
        if (self::$valores === null) {
            self::$valores = [];
            $filas = Database::pdo()->query('SELECT clave, valor FROM parametro')->fetchAll();
            foreach ($filas as $fila) {
                self::$valores[$fila['clave']] = (float)$fila['valor'];
            }
        }
        if (!isset(self::$valores[$clave])) {
            throw new \RuntimeException("Falta el parámetro '$clave' en la tabla parametro (¿se corrió la 021?).");
        }
        return self::$valores[$clave];
    }

    /** Todos, con sus límites y quién los cambió por última vez (para el panel). */
    public static function todos(): array
    {
        $filas = Database::pdo()->query(
            'SELECT p.clave, p.valor, p.minimo, p.maximo, p.unidad, p.descripcion, p.updated_at, u.nombre AS cambiado_por
               FROM parametro p LEFT JOIN usuario u ON u.id = p.updated_by
              ORDER BY p.orden'
        )->fetchAll();
        $lista = [];
        foreach ($filas as $fila) {
            $lista[] = [
                'clave'        => $fila['clave'],
                'valor'        => (float)$fila['valor'],
                'minimo'       => (float)$fila['minimo'],
                'maximo'       => (float)$fila['maximo'],
                'unidad'       => $fila['unidad'],
                'descripcion'  => $fila['descripcion'],
                'updated_at'   => (new \DateTimeImmutable($fila['updated_at']))->format(DATE_ATOM),
                'cambiado_por' => $fila['cambiado_por'],
            ];
        }
        return $lista;
    }

    /** Los últimos cambios, el más nuevo primero. */
    public static function cambios(int $cantidad = 30): array
    {
        $st = Database::pdo()->prepare(
            'SELECT c.clave, c.valor_anterior, c.valor_nuevo, c.created_at, u.nombre AS usuario
               FROM parametro_cambio c LEFT JOIN usuario u ON u.id = c.usuario_id
              ORDER BY c.created_at DESC, c.id DESC LIMIT :n'
        );
        $st->bindValue(':n', $cantidad, \PDO::PARAM_INT);
        $st->execute();
        $lista = [];
        foreach ($st->fetchAll() as $fila) {
            $lista[] = [
                'clave'          => $fila['clave'],
                'valor_anterior' => (float)$fila['valor_anterior'],
                'valor_nuevo'    => (float)$fila['valor_nuevo'],
                'fecha'          => (new \DateTimeImmutable($fila['created_at']))->format(DATE_ATOM),
                // Sin usuario: lo cambió el sistema (la cotización del dólar se actualiza sola, ver Cotizacion)
                'usuario'        => $fila['usuario'] ?? ($fila['clave'] === Cotizacion::CLAVE ? 'Automático (dólar blue, dolarapi.com)' : null),
            ];
        }
        return $lista;
    }

    /**
     * Revisa los valores nuevos que mandó el admin. Función pura (se prueba sin base).
     * $actuales: clave => ['valor', 'minimo', 'maximo', 'unidad'] como los devuelve todos().
     * $nuevos: clave => valor. Las claves que no vienen quedan como estaban.
     * @return array{0: array<string, float>, 1: array<string, string>} [valores que cambian, errores por clave]
     */
    public static function validar(array $actuales, array $nuevos): array
    {
        $errores = [];
        $cambian = [];
        foreach ($nuevos as $clave => $valor) {
            if (!isset($actuales[$clave])) {
                $errores[$clave] = 'Ese parámetro no existe.';
                continue;
            }
            if (is_string($valor) && is_numeric($valor)) {
                $valor = (float)$valor;
            }
            if (!is_int($valor) && !is_float($valor)) {
                $errores[$clave] = 'Ingresá un número.';
                continue;
            }
            $valor = (float)$valor;
            $p = $actuales[$clave];
            if ($valor < $p['minimo'] || $valor > $p['maximo']) {
                $errores[$clave] = 'Tiene que estar entre ' . self::numero($p['minimo']) . ' y ' . self::numero($p['maximo']) . '.';
                continue;
            }
            if (in_array($p['unidad'], ['dias', 'pesos', 'horas', 'autos'], true) && floor($valor) !== $valor) {
                $errores[$clave] = 'Ingresá un número sin decimales.';
                continue;
            }
            if ($valor !== $p['valor']) {
                $cambian[$clave] = $valor;
            }
        }

        // El sugerido tiene que quedar entre el mínimo y el máximo (con los valores que quedarían después de guardar)
        $minimo = $cambian['precio_minimo'] ?? $actuales['precio_minimo']['valor'] ?? null;
        $sugerido = $cambian['precio_sugerido'] ?? $actuales['precio_sugerido']['valor'] ?? null;
        $maximo = $cambian['precio_maximo'] ?? $actuales['precio_maximo']['valor'] ?? null;
        if (!$errores && $minimo !== null && $sugerido !== null && $maximo !== null) {
            if ($minimo > $maximo) {
                $errores['precio_minimo'] = 'El precio mínimo no puede ser mayor que el máximo.';
            } elseif ($sugerido < $minimo || $sugerido > $maximo) {
                $errores['precio_sugerido'] = 'El precio sugerido tiene que estar entre el mínimo y el máximo.';
            }
        }
        return [$cambian, $errores];
    }

    /** Guarda los valores que cambian y anota cada cambio (quién, cuándo, antes y después), en una transacción. */
    public static function guardar(array $actuales, array $cambian, int $usuarioId): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $actualizar = $pdo->prepare('UPDATE parametro SET valor = :valor, updated_at = now(), updated_by = :u WHERE clave = :clave');
        $anotar = $pdo->prepare('INSERT INTO parametro_cambio (clave, valor_anterior, valor_nuevo, usuario_id)
            VALUES (:clave, :antes, :despues, :u)');
        foreach ($cambian as $clave => $valor) {
            $actualizar->execute(['valor' => $valor, 'u' => $usuarioId, 'clave' => $clave]);
            $anotar->execute(['clave' => $clave, 'antes' => $actuales[$clave]['valor'], 'despues' => $valor, 'u' => $usuarioId]);
        }
        $pdo->commit();
        self::$valores = null; // el resto del pedido ve los valores nuevos
    }

    private static function numero(float $n): string
    {
        return number_format($n, floor($n) === $n ? 0 : 2, ',', '.');
    }
}
