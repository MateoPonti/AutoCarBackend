<?php
// Recolector de autos usados de Kavak Argentina. Genera autos.json en el formato comun de los recolectores.
// Uso: php scraper.php [paginas]   (default: todas; con un limite, la corrida queda marcada como incompleta)

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/Normalizador.php';

use AutoCar\Recolectores\Kavak\Normalizador;
use Playwright\Playwright;

$limite  = isset($argv[1]) ? max(1, (int) $argv[1]) : PHP_INT_MAX;
$url     = 'https://www.kavak.com/ar/usados';

$context = Playwright::chromium(['headless' => true]);
$page    = $context->newPage();

$autos    = [];
$paginas  = 1; // se actualiza al leer la paginacion de la primera pagina
$fallo    = false;
$rechazos = 0;

for ($p = 0; $p < min($paginas, $limite); $p++) {
    // robots.txt de Kavak pide 20 segundos entre pedidos (Crawl-delay: 20)
    if ($p > 0) {
        sleep(20);
    }

    echo "Pagina ".($p + 1)."...\n";

    try {
        $page->goto("$url?page=$p", ['waitUntil' => 'domcontentloaded']);
        $page->waitForSelector('[data-testid^="card-product-"]');

        // Los links de la paginacion dicen cual es la ultima pagina (se cuenta desde 0)
        if ($p === 0) {
            $paginas = 1 + (int) $page->evaluate(<<<'JS'
                () => Math.max(0, ...[...document.querySelectorAll('a[href*="page="]')]
                    .map(a => Number(new URL(a.href).searchParams.get('page')) || 0))
            JS);
            echo "El listado tiene $paginas paginas\n";
        }

        // Extrae el texto de cada tarjeta directamente en el navegador; Normalizador lo interpreta
        $tarjetas = $page->evaluate(<<<'JS'
            () => [...document.querySelectorAll('[data-testid^="card-product-"]')].map(card => {
                const txt = sel => card.querySelector(sel)?.innerText.trim() ?? null;
                const seccion = card.querySelector('[class*="priceSection"]');
                return {
                    id:              card.dataset.testid.replace('card-product-', ''),
                    titulo:          txt('h3'),
                    subtitulo:       txt('p'),
                    precio_grande:   txt('[class*="amount__large__price"]'),
                    precio_chico:    seccion?.querySelector('[class*="cardProduct__price__"]')?.innerText.trim() ?? null,
                    etiqueta_precio: seccion ? [...seccion.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent).join('') : null,
                    etiquetas:       [...card.querySelectorAll('[class*="badge_content"]')].map(b => b.innerText.trim()),
                    ubicacion:       txt('[class*="footer"]'),
                    url:             card.href,
                };
            })
        JS);

    } catch (\Throwable $e) {
        // Sin nada juntado no hay archivo que guardar; si ya hay autos, se guarda lo que se pudo
        if ($autos === []) {
            $context->close();
            throw $e;
        }

        fwrite(STDERR, "Fallo la pagina ".($p + 1).": ".$e->getMessage()."\n");
        $fallo = true;
        break;
    }

    // Por id: si el listado cambia mientras se recorre, un auto puede repetirse entre paginas
    foreach ($tarjetas as $tarjeta) {
        $auto = Normalizador::auto($tarjeta);
        if ($auto === null) {
            $rechazos++;
            fwrite(STDERR, "Tarjeta no reconocida: ".json_encode($tarjeta, JSON_UNESCAPED_UNICODE)."\n");
            continue;
        }
        $autos[$auto['id_externo']] = $auto;
    }
}

$context->close();

// Una corrida incompleta no sirve para detectar autos que dejaron de publicarse
$completo = !$fallo && $rechazos === 0 && $limite >= $paginas;

file_put_contents(__DIR__.'/autos.json', json_encode([
    'fuente'   => 'kavak',
    'fecha'    => date(DATE_ATOM),
    'completo' => $completo,
    'autos'    => array_values($autos),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo count($autos)." autos guardados en autos.json".($completo ? '' : ' (corrida incompleta)')."\n";

exit($fallo || $rechazos > 0 ? 1 : 0);
