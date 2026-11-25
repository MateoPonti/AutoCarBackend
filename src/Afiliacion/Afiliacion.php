<?php
declare(strict_types=1);

namespace AutoCar\Afiliacion;

use AutoCar\Core\Database;
use AutoCar\Empresas\Empresa;
use AutoCar\Parametros\Parametros;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Afiliación con las plataformas del metabuscador (convenio simulado, como el pago de las inspecciones): cada clic en
 * "Ver en Kavak / MercadoLibre / Autocosmos" se anota y a fin de mes se le cobra a cada plataforma
 * clics válidos × su tarifa por clic. El admin ve el mes en curso y la evolución mes a mes, y marca cobrado cada mes
 * cerrado (migración 032). Los meses van en hora de Argentina.
 */
final class Afiliacion
{
    public const ZONA = 'America/Argentina/Buenos_Aires';
    /** Un mismo usuario y un mismo auto cuentan una sola vez en este lapso (para no inflar lo que se cobra) */
    public const HORAS_REPETIDO = 24;
    /** Desde dónde se puede tocar "Ver en …" en el metabuscador (como Estadisticas\Eventos::ORIGENES) */
    public const ORIGENES = ['para_vos', 'destacado', 'metabuscador'];
    /** Cuántos meses muestra la evolución, contando el actual */
    public const MESES = 12;

    /**
     * Anota un clic hacia el aviso de otra plataforma. Es válido (se cobra) si es de una cuenta personal y ese usuario
     * no tocó el mismo auto en las últimas 24 horas. Si la base falla no corta: la redirección importa más.
     * @return bool si el clic fue válido
     */
    public static function registrar(?int $usuarioId, ?string $rol, string $fuente, string $autoId, mixed $origen): bool
    {
        try {
            $pdo = Database::pdo();
            $valido = false;
            if ($usuarioId !== null && $rol === 'usuario') {
                $st = $pdo->prepare(
                    "SELECT 1 FROM clic_salida WHERE usuario_id = ? AND auto_id = ? AND valido
                        AND created_at > now() - make_interval(hours => " . self::HORAS_REPETIDO . ") LIMIT 1"
                );
                $st->execute([$usuarioId, $autoId]);
                $valido = $st->fetchColumn() === false;
            }
            $pdo->prepare('INSERT INTO clic_salida (usuario_id, fuente, auto_id, origen, valido) VALUES (?, ?, ?, ?, ?)')
                ->execute([$usuarioId, $fuente, $autoId, self::origen($origen), $valido ? 'true' : 'false']);
            return $valido;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function origen(mixed $origen): ?string
    {
        return is_string($origen) && in_array($origen, self::ORIGENES, true) ? $origen : null;
    }

    /** El primer día del mes de $fecha en Argentina, "Y-m-01". */
    public static function mes(DateTimeImmutable $fecha): string
    {
        return $fecha->setTimezone(new DateTimeZone(self::ZONA))->format('Y-m-01');
    }

    /** @return list<array{fuente: string, nombre: string, tarifa_clic: float, activo: bool}> */
    public static function convenios(): array
    {
        return array_map(static fn(array $c): array => [
            'fuente'      => $c['fuente'],
            'nombre'      => $c['nombre'],
            'tarifa_clic' => (float)$c['tarifa_clic'],
            'activo'      => (bool)$c['activo'],
        ], Database::pdo()->query('SELECT fuente, nombre, tarifa_clic, activo FROM convenio_plataforma ORDER BY nombre')
            ->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Cambia la tarifa y si está activo el convenio de una plataforma. false si no existe. */
    public static function guardarConvenio(string $fuente, float $tarifa, bool $activo): bool
    {
        $st = Database::pdo()->prepare('UPDATE convenio_plataforma SET tarifa_clic = ?, activo = ?, updated_at = now() WHERE fuente = ?');
        $st->execute([$tarifa, $activo ? 'true' : 'false', $fuente]);
        return $st->rowCount() > 0;
    }

    /**
     * Todo lo del panel del admin: el mes en curso (la grilla) y la evolución de los últimos 12 meses, con las
     * plataformas (clics) y las empresas (abono mensual, el mismo para todas: parámetro abono_empresa_mensual).
     * @return array{convenios: list<array<string, mixed>>, abono_empresa: float, este_mes: array<string, mixed>, evolucion: list<array<string, mixed>>}
     */
    public static function panel(DateTimeImmutable $ahora): array
    {
        $pdo = Database::pdo();
        $local = "(created_at AT TIME ZONE '" . self::ZONA . "')";
        $desde = (new DateTimeImmutable(self::mes($ahora)))->modify('-' . (self::MESES - 1) . ' months')->format('Y-m-d');
        $st = $pdo->prepare(
            "SELECT to_char(date_trunc('month', $local), 'YYYY-MM-01') AS mes, fuente,
                    COUNT(*) AS clics, COUNT(*) FILTER (WHERE valido) AS validos
               FROM clic_salida WHERE $local >= ?::date GROUP BY 1, 2"
        );
        $st->execute([$desde]);
        $clics = $st->fetchAll(PDO::FETCH_ASSOC);
        $cobros = $pdo->query(
            "SELECT tipo, to_char(mes, 'YYYY-MM-01') AS mes, socio, cantidad, monto, cobrado_at FROM cobro_afiliacion"
        )->fetchAll(PDO::FETCH_ASSOC);
        $convenios = self::convenios();
        $empresas = Empresa::conAbono();
        $abono = Parametros::valor('abono_empresa_mensual');
        return [
            'convenios'     => $convenios,
            'abono_empresa' => $abono,
            'este_mes'      => self::esteMes($convenios, $clics, $ahora, $empresas, $abono),
            'evolucion'     => self::evolucion($convenios, $clics, $cobros, $ahora, $empresas, $abono),
        ];
    }

    /**
     * La grilla del mes en curso: una fila por plataforma (clics, tarifa y lo que va a cobrar) y una por empresa (su
     * abono). Función pura.
     * @param list<array<string, mixed>> $convenios
     * @param list<array{mes: string, fuente: string, clics: mixed, validos: mixed}> $clics
     * @param list<array{id: int, nombre: string, desde: string}> $empresas las que pagan abono y desde qué mes
     */
    public static function esteMes(array $convenios, array $clics, DateTimeImmutable $ahora, array $empresas = [], float $abono = 0.0): array
    {
        $mes = self::mes($ahora);
        $filas = self::filasDelMes($convenios, $clics, $mes, $empresas, $abono);
        $local = $ahora->setTimezone(new DateTimeZone(self::ZONA));
        return ['mes' => $mes, 'dias_al_cierre' => (int)$local->format('t') - (int)$local->format('j')] + self::totales($filas);
    }

    /**
     * Mes a mes, del más viejo al actual: cuánto se cobra (o se cobró) a cada socio y si cada mes cerrado ya se marcó
     * cobrado. Un mes cobrado muestra lo que se guardó al cobrarlo; uno sin cobrar, sus clics × la tarifa de hoy (o el
     * abono de hoy). Función pura.
     * @param list<array{tipo?: string, mes: string, socio: string, cantidad: mixed, monto: mixed, cobrado_at: string}> $cobros
     */
    public static function evolucion(array $convenios, array $clics, array $cobros, DateTimeImmutable $ahora, array $empresas = [], float $abono = 0.0): array
    {
        $actual = self::mes($ahora);
        $cobrado = [];
        foreach ($cobros as $c) {
            $cobrado[$c['mes']][($c['tipo'] ?? 'plataforma') . '|' . $c['socio']] = $c;
        }
        $salida = [];
        for ($i = self::MESES - 1; $i >= 0; $i--) {
            $mes = (new DateTimeImmutable($actual))->modify("-$i months")->format('Y-m-01');
            $filas = array_map(static function (array $f) use ($cobrado, $mes): array {
                $c = $cobrado[$mes][$f['tipo'] . '|' . $f['socio']] ?? null;
                return $c === null ? $f + ['cobrado_at' => null] : array_merge($f, [
                    'validos'    => $f['tipo'] === 'plataforma' ? (int)$c['cantidad'] : 0,
                    'monto'      => (float)$c['monto'],
                    'cobrado_at' => $c['cobrado_at'],
                ]);
            }, self::filasDelMes($convenios, $clics, $mes, $empresas, $abono));
            $porCobrar = array_filter($filas, static fn(array $f): bool => $f['cobrado_at'] === null && $f['monto'] > 0);
            $salida[] = ['mes' => $mes, 'en_curso' => $mes === $actual] + self::totales($filas) + [
                // en_curso: todavía no se cobra; pendiente: falta cobrar algún socio; cobrado: todo cobrado (o nada que cobrar)
                'estado' => $mes === $actual ? 'en_curso' : ($porCobrar === [] ? 'cobrado' : 'pendiente'),
            ];
        }
        return $salida;
    }

    /**
     * Lo que todavía no se liquidó, por fuente (clics y abonos), para mostrarlo al lado de lo cobrado en el informe de
     * Estadísticas: en_curso = lo que va del mes actual (se cobra cuando cierra); cerrados = meses que ya terminaron y
     * falta marcar cobrados. Sale de la misma evolución que la grilla de liquidaciones, así los números coinciden.
     * Función pura.
     * @param list<array<string, mixed>> $evolucion la de evolucion()
     * @return array{mes: ?string, clics: array{en_curso: float, cerrados: float}, abonos: array{en_curso: float, cerrados: float}}
     */
    public static function sinLiquidar(array $evolucion): array
    {
        $salida = ['mes' => null, 'clics' => ['en_curso' => 0.0, 'cerrados' => 0.0], 'abonos' => ['en_curso' => 0.0, 'cerrados' => 0.0]];
        foreach ($evolucion as $m) {
            if ($m['en_curso']) {
                $salida['mes'] = $m['mes'];
            }
            foreach ($m['filas'] as $f) {
                if ($f['cobrado_at'] === null && $f['monto'] > 0) {
                    $salida[$f['tipo'] === 'empresa' ? 'abonos' : 'clics'][$m['en_curso'] ? 'en_curso' : 'cerrados'] += (float)$f['monto'];
                }
            }
        }
        return $salida;
    }

    /** Las filas de un mes y sus totales, separados en clics (plataformas) y abonos (empresas). */
    private static function totales(array $filas): array
    {
        $deTipo = static fn(string $tipo): float => (float)array_sum(array_column(
            array_filter($filas, static fn(array $f): bool => $f['tipo'] === $tipo), 'monto'));
        return [
            'filas'     => $filas,
            'clics'     => array_sum(array_column($filas, 'clics')),
            'validos'   => array_sum(array_column($filas, 'validos')),
            'de_clics'  => $deTipo('plataforma'),
            'de_abonos' => $deTipo('empresa'),
            'total'     => (float)array_sum(array_column($filas, 'monto')),
        ];
    }

    /**
     * Una fila por plataforma con convenio, aunque no tenga clics ese mes (sin convenio activo no se cobra), y una por
     * empresa desde el mes en que se aprobó.
     */
    private static function filasDelMes(array $convenios, array $clics, string $mes, array $empresas = [], float $abono = 0.0): array
    {
        $delMes = [];
        foreach ($clics as $c) {
            if ($c['mes'] === $mes) {
                $delMes[$c['fuente']] = $c;
            }
        }
        $plataformas = array_map(static function (array $conv) use ($delMes): array {
            $c = $delMes[$conv['fuente']] ?? [];
            $validos = (int)($c['validos'] ?? 0);
            return [
                'tipo'        => 'plataforma',
                'socio'       => $conv['fuente'],
                'fuente'      => $conv['fuente'],
                'nombre'      => $conv['nombre'],
                'tarifa_clic' => $conv['tarifa_clic'],
                'activo'      => $conv['activo'],
                'clics'       => (int)($c['clics'] ?? 0),
                'validos'     => $validos,
                'monto'       => $conv['activo'] ? $validos * $conv['tarifa_clic'] : 0.0,
            ];
        }, $convenios);
        $deEmpresas = [];
        foreach ($empresas as $e) {
            if ($e['desde'] <= $mes) {
                $deEmpresas[] = [
                    'tipo' => 'empresa', 'socio' => (string)$e['id'], 'fuente' => null, 'nombre' => $e['nombre'],
                    'tarifa_clic' => null, 'activo' => true, 'clics' => 0, 'validos' => 0, 'monto' => $abono,
                ];
            }
        }
        return array_merge($plataformas, $deEmpresas);
    }

    /**
     * Marca cobrado lo de un socio (una plataforma o una empresa) en un mes cerrado, con el monto de ese momento.
     * @return array{ok: bool, error?: string} error: mes_en_curso, no_existe, ya_cobrado
     */
    public static function cobrar(string $tipo, string $socio, string $mes, int $adminId, DateTimeImmutable $ahora): array
    {
        if ($mes >= self::mes($ahora)) {
            return ['ok' => false, 'error' => 'mes_en_curso'];
        }
        foreach (self::panel($ahora)['evolucion'] as $m) {
            if ($m['mes'] !== $mes) {
                continue;
            }
            foreach ($m['filas'] as $f) {
                if ($f['tipo'] !== $tipo || $f['socio'] !== $socio) {
                    continue;
                }
                if ($f['cobrado_at'] !== null) {
                    return ['ok' => false, 'error' => 'ya_cobrado'];
                }
                Database::pdo()->prepare(
                    'INSERT INTO cobro_afiliacion (tipo, socio, mes, cantidad, monto, cobrado_por) VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$tipo, $socio, $mes, $f['validos'], $f['monto'], $adminId]);
                return ['ok' => true];
            }
        }
        return ['ok' => false, 'error' => 'no_existe'];
    }
}
