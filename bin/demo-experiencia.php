<?php
/**
 * Experiencia de DEMO para el ranking: inspecciones firmadas de meses anteriores de los 5 mecánicos de
 * database/demo-inspecciones.sql. SOLO para dev o local, nunca en main.
 *
 * Sin esto, todos los mecánicos tienen menos de 3 firmadas y figuran como "Nuevo en la red". Con esto el ranking
 * (por experiencia, ver Inspecciones\Ranking) queda: Gómez 12, Ferreyra 8, Acosta 5, Medina 4 y Benítez 2 (nuevo).
 *
 * Las firmas usan el mismo código que el endpoint (Inspecciones\Huella), así la huella de cada informe es real.
 * Es idempotente: si un mecánico ya tiene estas inspecciones de demo, no le agrega nada.
 *
 * Uso: php bin/demo-experiencia.php   (toma la base de .env o de las variables de entorno, como el resto de bin/)
 */
declare(strict_types=1);

use AutoCar\Core\Config;
use AutoCar\Core\Database;
use AutoCar\Inspecciones\Checklist;
use AutoCar\Inspecciones\Huella;
use AutoCar\Inspecciones\Inspeccion;
use AutoCar\Inspecciones\Precio;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';
Config::load($raiz . '/.env');

// email del mecánico => inspecciones firmadas
const PLAN = [
    'mecanico1@demo.autocar.com.ar' => 8,
    'mecanico2@demo.autocar.com.ar' => 12,
    'mecanico3@demo.autocar.com.ar' => 5,
    'mecanico4@demo.autocar.com.ar' => 2,
    'mecanico5@demo.autocar.com.ar' => 4,
];
// .invalid es un dominio reservado: estos links nunca apuntan a un sitio real
const URL_HISTORIAL = 'https://historial.demo.invalid/';
const COMPRADOR = ['Comprador de ejemplo (demo)', 'comprador@demo.autocar.com.ar'];
// La misma contraseña que los mecánicos de la demo: mecanico-demo
const HASH_DEMO = '$2y$12$nhrbZHzYp9khIexSilRgoelAYw4KDpepfYVCYHsrkU2nJK2gu2Nmi';

const AUTOS = [
    ['Volkswagen Gol Trend 1.6', 2015, 98000, 8900000],
    ['Chevrolet Onix LT 1.4', 2018, 72000, 13500000],
    ['Fiat Cronos Drive 1.3', 2020, 45000, 16800000],
    ['Toyota Etios XLS 1.5', 2017, 88000, 12900000],
    ['Renault Sandero Stepway', 2016, 110000, 10200000],
    ['Ford Fiesta Kinetic SE', 2014, 125000, 8100000],
    ['Peugeot 208 Allure 1.6', 2019, 61000, 15400000],
    ['Volkswagen Suran Highline', 2013, 140000, 7600000],
];
const OBSERVACIONES = [
    'carroceria' => 'Rayones en el paragolpes trasero',
    'neumaticos' => 'Las delanteras están para cambiar',
    'frenos' => 'Pastillas al 30 %',
    'suspension' => 'Ruido en el tren delantero sobre empedrado',
];

$pdo = Database::pdo();
$pdo->beginTransaction();
try {
    $pdo->prepare(
        "INSERT INTO usuario (nombre, email, password_hash, rol_id)
         SELECT :n, :e, :h, id FROM rol WHERE nombre = 'usuario'
         ON CONFLICT (email) DO NOTHING"
    )->execute(['n' => COMPRADOR[0], 'e' => COMPRADOR[1], 'h' => HASH_DEMO]);
    $comprador = (int)$pdo->query("SELECT id FROM usuario WHERE email = '" . COMPRADOR[1] . "'")->fetchColumn();

    $mecanicos = $pdo->prepare(
        'SELECT u.id, u.nombre, t.nombre AS taller, t.localidad, t.precio_inspeccion
         FROM usuario u JOIN taller t ON t.usuario_id = u.id WHERE u.email = :e'
    );
    $yaTiene = $pdo->prepare('SELECT COUNT(*) FROM inspeccion WHERE mecanico_id = :m AND url LIKE :u');
    $n = 0;

    foreach (PLAN as $email => $firmadas) {
        $mecanicos->execute(['e' => $email]);
        $m = $mecanicos->fetch();
        if (!$m) {
            throw new RuntimeException("Falta $email: corré antes database/demo-inspecciones.sql.");
        }
        $yaTiene->execute(['m' => $m['id'], 'u' => URL_HISTORIAL . '%']);
        if ((int)$yaTiene->fetchColumn() > 0) {
            echo "{$m['nombre']}: ya tiene inspecciones de demo, no se toca.\n";
            continue;
        }

        for ($k = 0; $k < $firmadas; $k++) {
            [$titulo, $anio, $km, $precioAuto] = AUTOS[($k + $n) % count(AUTOS)];
            // Firmadas hace entre 20 y ~130 días
            $momento = (new DateTimeImmutable('@' . (time() - (20 + $k * 9) * 86400)))->setTime(14, 30 + $k);
            $precio = (float)$m['precio_inspeccion'];
            $id = Inspeccion::crear($comprador, [
                'mecanico_id' => (int)$m['id'], 'vehiculo_id' => null,
                'url' => URL_HISTORIAL . 'm' . $m['id'] . '-' . ($k + 1), 'fuente' => 'otro', 'titulo' => $titulo,
                'anio' => $anio, 'kilometraje' => $km + $k * 1500, 'precio_publicado' => $precioAuto,
                'localidad' => $m['localidad'],
            ], Precio::desgloseActual($precio));
            Inspeccion::cambiarEstado($id, 'pedida', 'aceptada', ['fecha_turno' => $momento->modify('-3 hours')->format('c')]);

            // Uno de cada tres con algo observado
            $items = array_map(static fn(): array => ['ok', null], Checklist::ITEMS);
            if ($k % 3 === 1) {
                $codigo = array_keys(OBSERVACIONES)[$k % count(OBSERVACIONES)];
                $items[$codigo] = ['observado', OBSERVACIONES[$codigo]];
            }
            $conclusion = $k % 3 === 1 ? 'con_reparos' : 'recomendable';
            $resumen = $conclusion === 'recomendable'
                ? 'Auto en buen estado para su año y kilometraje.'
                : 'En buen estado general; hay que presupuestar lo observado antes de cerrar el precio.';
            // El mismo auto de la lista tiene siempre la misma patente
            $patente = 'AC' . str_pad((string)(($k + $n) % count(AUTOS)), 3, '0', STR_PAD_LEFT) . 'HD';
            Inspeccion::guardarInforme($id, $items, $conclusion, $resumen, $patente);
            $huella = Huella::calcular($id, $items, $conclusion, $resumen, $m['nombre'], $m['taller'],
                Inspeccion::momentoHuella($momento), $patente);
            Inspeccion::firmar($id, $m['nombre'], $m['taller'], $momento, $huella);
            $pdo->prepare('UPDATE inspeccion SET created_at = :c, updated_at = :c WHERE id = :id')
                ->execute(['id' => $id, 'c' => $momento->modify('-2 days')->format('c')]);
        }
        echo "{$m['nombre']}: $firmadas firmadas.\n";
        $n++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'No se cargó nada: ' . $e->getMessage() . "\n");
    exit(1);
}
