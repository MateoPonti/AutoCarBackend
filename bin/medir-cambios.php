<?php
/**
 * Compara las dos últimas corridas de una fuente y cuenta qué cambió de un día a otro. Sirve para saber si el plan
 * gratis de Firestore alcanza si un día se guardan también los avisos uno por uno (20.000 escrituras por día).
 * Uso: php bin/medir-cambios.php mercadolibre
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$fuente = preg_replace('/[^a-z0-9_-]/', '', $argv[1] ?? '');
$archivos = glob(dirname(__DIR__) . "/storage/mercado/$fuente/????-??-??.json") ?: [];
sort($archivos);
if ($fuente === '' || count($archivos) < 2) {
    fwrite(STDERR, "Hacen falta dos corridas de \"$fuente\" en storage/mercado/$fuente/ (por ejemplo, de dos días seguidos).\n");
    exit(1);
}
[$antes, $ahora] = array_map(
    static fn(string $f): array => array_column(json_decode((string)file_get_contents($f), true)['autos'], null, 'id_externo'),
    array_slice($archivos, -2)
);

$nuevos = count(array_diff_key($ahora, $antes));
$retirados = count(array_diff_key($antes, $ahora));
$precio = 0;
foreach (array_intersect_key($ahora, $antes) as $id => $a) {
    if ($a['precio'] !== $antes[$id]['precio'] || $a['moneda'] !== $antes[$id]['moneda']) {
        $precio++;
    }
}
$total = $nuevos + $retirados + $precio;
printf("%s → %s\n", basename($archivos[count($archivos) - 2]), basename(end($archivos)));
printf("Antes: %d · Ahora: %d\nNuevos: %d · Retirados: %d · Cambiaron de precio: %d\n", count($antes), count($ahora), $nuevos, $retirados, $precio);
printf("Escrituras si se guardara cada aviso que cambió: %d de 20.000 por día (%.0f %%)\n", $total, $total / 200);
