<?php
/**
 * Historia de DEMO para las afiliaciones (Estadísticas → Plata): abonos de las concesionarias y clics a plataformas de
 * los meses anteriores, liquidados, para que el informe y las liquidaciones muestren datos de varios meses.
 *
 *   1. Abonos: por cada concesionaria aprobada y activa, un abono cobrado (parámetro abono_empresa_mensual) en cada uno
 *      de los últimos --meses meses cerrados.
 *   2. Clics: completa los clics válidos de cada mes hasta una curva que crece mes a mes (repartidos entre las
 *      plataformas con convenio, de cuentas personales reales), más algunos no válidos. El mes en curso, en proporción
 *      a los días que pasaron.
 *   3. Liquidación: cada mes cerrado queda cobrado con sus clics válidos × la tarifa de hoy, el día 5 del mes siguiente
 *      (como se liquidaría de verdad). Así el informe de "Cuánto ganamos" cuenta los cobros por la fecha de cobro y la
 *      grilla de liquidaciones muestra los mismos montos.
 *
 * Es idempotente: los clics se completan hasta la curva (no se suman de nuevo) y los cobros se recalculan.
 * Los clics de demo llevan auto_id "demo-afiliacion-…".
 *
 * Uso: php bin/demo-afiliaciones.php [--meses 6] [--simular]
 *   --meses N   meses cerrados con abono de las concesionarias (por defecto 6; los clics cubren los 11 de la evolución)
 *   --simular   hace todo y muestra el resultado, pero no guarda nada (ROLLBACK)
 * Toma la base de .env o de las variables de entorno, como el resto de bin/.
 */
declare(strict_types=1);

use AutoCar\Afiliacion\Afiliacion;
use AutoCar\Core\Config;
use AutoCar\Core\Database;
use AutoCar\Empresas\Empresa;
use AutoCar\Parametros\Parametros;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';
Config::load($raiz . '/.env');

$opciones = getopt('', ['meses:', 'simular']);
$mesesAbono = max(1, min(Afiliacion::MESES - 1, (int)($opciones['meses'] ?? 6)));
$simular = isset($opciones['simular']);

// Clics válidos por mes: arranca en ~120 hace 11 meses y crece ~11 % por mes (el último cerrado, ~340)
const CLICS_INICIO = 120;
const CRECIMIENTO = 1.11;
// Cómo se reparten entre las plataformas (las que no están acá o sin convenio activo, no reciben)
const REPARTO = ['mercadolibre' => 0.5, 'kavak' => 0.3, 'autocosmos' => 0.2];
// Además de los válidos, clics que no se cobran (repetidos o sin cuenta personal), para que el tráfico total sea creíble
const NO_VALIDOS = 0.18;

$zona = new DateTimeZone(Afiliacion::ZONA);
$ahora = new DateTimeImmutable('now', $zona);
$mesActual = new DateTimeImmutable(Afiliacion::mes($ahora), $zona);
$pdo = Database::pdo();
mt_srand(20261125); // el mismo reparto en cada corrida

// El día 5 del mes siguiente a las 10 (o ahora, si ese día todavía no llegó)
$liquidacion = static function (DateTimeImmutable $mes) use ($ahora): string {
    $dia = $mes->modify('first day of next month')->setTime(10, 0)->modify('+4 days');
    return ($dia < $ahora ? $dia : $ahora->modify('-1 minute'))->format(DATE_ATOM);
};

$pdo->beginTransaction();
try {
    // --- 1. Abonos de las concesionarias ---
    $abono = Parametros::valor('abono_empresa_mensual');
    $empresas = Empresa::conAbono();
    $insAbono = $pdo->prepare(
        "INSERT INTO cobro_afiliacion (tipo, socio, mes, cantidad, monto, cobrado_at) VALUES ('empresa', ?, ?, 0, ?, ?)
         ON CONFLICT (tipo, socio, mes) DO NOTHING"
    );
    $abonos = 0;
    foreach ($empresas as $e) {
        for ($i = $mesesAbono; $i >= 1; $i--) {
            $mes = $mesActual->modify("-$i months");
            $insAbono->execute([(string)$e['id'], $mes->format('Y-m-d'), $abono, $liquidacion($mes)]);
            $abonos += $insAbono->rowCount();
        }
    }
    printf("Abonos: %d concesionarias × %d meses de \$%s → %d cobros nuevos\n", count($empresas), $mesesAbono, number_format($abono, 0, ',', '.'), $abonos);

    // --- 2. Clics ---
    $convenios = array_filter(Afiliacion::convenios(), static fn(array $c): bool => $c['activo'] && isset(REPARTO[$c['fuente']]));
    $usuarios = $pdo->query(
        "SELECT u.id FROM usuario u JOIN rol r ON r.id = u.rol_id WHERE r.nombre = 'usuario' AND u.activo ORDER BY u.id"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($convenios === [] || $usuarios === []) {
        throw new RuntimeException('Hace falta al menos un convenio activo y una cuenta personal activa.');
    }
    $local = "(created_at AT TIME ZONE '" . Afiliacion::ZONA . "')";
    $validosDe = $pdo->prepare("SELECT COUNT(*) FROM clic_salida WHERE valido AND fuente = ? AND $local >= ?::timestamp AND $local < ?::timestamp");
    // De a muchos por INSERT: uno por uno contra Neon tardaría minutos
    $insertar = static function (array $filas) use ($pdo): void {
        foreach (array_chunk($filas, 250) as $tanda) {
            $pdo->prepare('INSERT INTO clic_salida (usuario_id, fuente, auto_id, origen, valido, created_at) VALUES '
                . implode(', ', array_fill(0, count($tanda), '(?, ?, ?, ?, ?, ?)')))
                ->execute(array_merge(...$tanda));
        }
    };
    $nuevos = 0;
    for ($i = Afiliacion::MESES - 1; $i >= 0; $i--) {
        $mes = $mesActual->modify("-$i months");
        $fin = $mes->modify('first day of next month');
        $hasta = $i === 0 ? $ahora->modify('-1 hour') : $fin;
        // El mes en curso, en proporción a lo que pasó del mes
        $parte = $i === 0 ? ($hasta->getTimestamp() - $mes->getTimestamp()) / ($fin->getTimestamp() - $mes->getTimestamp()) : 1.0;
        $meta = (int)round(CLICS_INICIO * CRECIMIENTO ** (Afiliacion::MESES - 1 - $i) * $parte);
        foreach ($convenios as $c) {
            $validosDe->execute([$c['fuente'], $mes->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')]);
            $faltan = (int)round($meta * REPARTO[$c['fuente']]) - (int)$validosDe->fetchColumn();
            if ($faltan <= 0) {
                continue;
            }
            $noValidos = (int)round($faltan * NO_VALIDOS);
            $filas = [];
            for ($k = 0; $k < $faltan + $noValidos; $k++) {
                $valido = $k < $faltan;
                $cuando = (new DateTimeImmutable('@' . mt_rand($mes->getTimestamp(), $hasta->getTimestamp() - 1)))->setTimezone($zona);
                $filas[] = [
                    $valido || mt_rand(0, 1) ? $usuarios[mt_rand(0, count($usuarios) - 1)] : null,
                    $c['fuente'],
                    'demo-afiliacion-' . bin2hex(random_bytes(6)),
                    Afiliacion::ORIGENES[mt_rand(0, count(Afiliacion::ORIGENES) - 1)],
                    $valido ? 'true' : 'false',
                    $cuando->format(DATE_ATOM),
                ];
            }
            $insertar($filas);
            $nuevos += count($filas);
        }
    }
    printf("Clics: %d nuevos (válidos y no válidos)\n", $nuevos);

    // --- 3. Liquidación de los meses cerrados ---
    $liquidar = $pdo->prepare(
        "INSERT INTO cobro_afiliacion (tipo, socio, mes, cantidad, monto, cobrado_at) VALUES ('plataforma', ?, ?, ?, ?, ?)
         ON CONFLICT (tipo, socio, mes) DO UPDATE SET cantidad = EXCLUDED.cantidad, monto = EXCLUDED.monto, cobrado_at = EXCLUDED.cobrado_at"
    );
    foreach (Afiliacion::convenios() as $c) {
        if (!$c['activo']) {
            continue;
        }
        for ($i = Afiliacion::MESES - 1; $i >= 1; $i--) {
            $mes = $mesActual->modify("-$i months");
            $validosDe->execute([$c['fuente'], $mes->format('Y-m-d H:i:s'), $mes->modify('first day of next month')->format('Y-m-d H:i:s')]);
            $validos = (int)$validosDe->fetchColumn();
            if ($validos > 0) {
                $liquidar->execute([$c['fuente'], $mes->format('Y-m-d'), $validos, $validos * $c['tarifa_clic'], $liquidacion($mes)]);
            }
        }
    }

    // --- Cómo queda (lo mismo que ve el admin en Liquidaciones) ---
    echo "\nMes        Estado     Válidos   Clics        Abonos\n";
    foreach (Afiliacion::panel(new DateTimeImmutable())['evolucion'] as $m) {
        printf("%s %-10s %7d  %11s  %12s\n", substr($m['mes'], 0, 7), $m['estado'], $m['validos'],
            '$' . number_format($m['de_clics'], 0, ',', '.'), '$' . number_format($m['de_abonos'], 0, ',', '.'));
    }

    if ($simular) {
        $pdo->rollBack();
        echo "\n--simular: no se guardó nada.\n";
    } else {
        $pdo->commit();
        echo "\nListo: guardado.\n";
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
