<?php
declare(strict_types=1);

namespace AutoCar\Tests\Unit;

use AutoCar\Recolectores\Autocosmos\Extractor as Autocosmos;
use AutoCar\Recolectores\Comun\JsonEmbebido;
use AutoCar\Recolectores\Kavak\ExtractorHtml;
use AutoCar\Recolectores\Kavak\Normalizador;
use AutoCar\Recolectores\MercadoLibre\Extractor as MercadoLibre;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/recolectores/autoload.php';
require_once dirname(__DIR__, 2) . '/recolectores/kavak/Normalizador.php';

/** Los extractores con HTML recortado de páginas reales (noviembre de 2026), sin red. */
final class RecolectoresTest extends TestCase
{
    private const POLYCARD = '{"id":"POLYCARD","state":"VISIBLE","polycard":{"metadata":{"id":"MLA2268147919","url":"auto.mercadolibre.com.ar/MLA-2268147919-volkswagen-gol-trend-_JM"},'
        . '"pictures":{"pictures":[{"id":"972581-MLA120950404543_112026"}]},"components":[{"type":"title","id":"title","title":{"text":"Volkswagen Gol Trend 2018 1.6 Comfortline 101cv"}},'
        . '{"type":"price","id":"price","price":{"current_price":{"value":16900000,"currency":"ARS"}}},'
        . '{"type":"attributes_list","id":"attributes_list","attributes_list":{"separator":"|","texts":["2018","110.000 Km"]}},'
        . '{"type":"location","id":"location","location":{"text":"Zárate - Buenos Aires Interior"}}]}}';

    public function testJsonEmbebidoRespetaLasLlavesDentroDeStrings(): void
    {
        $html = '<script>x = {"a":"tiene } y {","b":[1,2]}; y = {"a":2}</script>';
        self::assertSame([['a' => 'tiene } y {', 'b' => [1, 2]], ['a' => 2]], JsonEmbebido::todos($html, '{"a"'));
        self::assertNull(JsonEmbebido::balanceado('{"sin cerrar":', 0));
    }

    public function testMercadoLibreLeeLosAvisosSinRepetir(): void
    {
        $html = '<html>' . self::POLYCARD . ',' . self::POLYCARD . '"offset":0,"total":1271,</html>';
        $avisos = MercadoLibre::avisos($html, 'Volkswagen', 'Gol Trend');
        self::assertCount(1, $avisos);
        $a = $avisos[0];
        self::assertSame(['MLA2268147919', 2018, 110000, 16900000.0, 'ARS'], [$a['id_externo'], $a['anio'], $a['km'], $a['precio'], $a['moneda']]);
        self::assertSame('1.6 Comfortline 101cv', $a['version']);
        self::assertSame('https://auto.mercadolibre.com.ar/MLA-2268147919-volkswagen-gol-trend-_JM', $a['url']);
        self::assertSame(1271, MercadoLibre::total($html));
    }

    public function testMercadoLibreLeeLosFiltrosYArmaLaPaginacion(): void
    {
        $html = '"id":"MODEL","name":"Modelo","type":"STRING","values":["1"]},'
            . '"id":"MODEL","name":"Modelo","type":"STRING","values":[{"id":"63686","name":"Amarok","url":"https://autos.mercadolibre.com.ar/volkswagen/amarok/#applied","results":"(3.060)"}]';
        self::assertSame([['nombre' => 'Amarok', 'url' => 'https://autos.mercadolibre.com.ar/volkswagen/amarok/', 'cantidad' => 3060]], MercadoLibre::filtro($html, 'MODEL'));
        self::assertSame('https://autos.mercadolibre.com.ar/vw/_Desde_97_ITEM*CONDITION_2230581_NoIndex_True',
            MercadoLibre::pagina('https://autos.mercadolibre.com.ar/vw/_ITEM*CONDITION_2230581', 2));
        self::assertSame('https://autos.mercadolibre.com.ar/vw/gol/_Desde_49_NoIndex_True', MercadoLibre::pagina('https://autos.mercadolibre.com.ar/vw/gol/', 1));
    }

    public function testKavakSinNavegadorDaLoMismoQueConPlaywright(): void
    {
        $html = '<div><a href="https://www.kavak.com/ar/venta/volkswagen-bora" data-testid="card-product-562984">'
            . '<div class="badge_content___ZHbD">Nuevo ingreso</div><h3>Volkswagen • Bora</h3><p>2014 • 146.000 km • 2.0 TRENDLINE • Manual</p>'
            . '<span class="card-product_cardProduct__priceSection__2GKb8">Precio desde</span>'
            . '<span class="amount_uki-amount__large__price__2NvVx">13.120.000</span>'
            . '<span class="card-product_cardProduct__footerInfo__HrxVa">Buenos Aires</span></a>'
            . '<a href="/ar/usados?page=42">42</a></div>';
        $tarjetas = ExtractorHtml::tarjetas($html);
        self::assertCount(1, $tarjetas);
        $auto = Normalizador::auto($tarjetas[0]);
        self::assertSame(['562984', 'Volkswagen', 'Bora', '2.0 TRENDLINE', 2014, 146000, 'manual', 13120000, 'Buenos Aires'],
            [$auto['id_externo'], $auto['marca'], $auto['modelo'], $auto['version'], $auto['anio'], $auto['km'], $auto['transmision'], $auto['precio'], $auto['ubicacion']]);
        self::assertSame(42, ExtractorHtml::ultimaPagina($html));
    }

    public function testAutocosmosLeeLosMicrodatos(): void
    {
        $html = '<article class="card listing-card" itemscope itemtype="http://schema.org/Car">'
            . '<a itemprop="url" href="/auto/usado/fiat/cronos/13l/b39a31949fbd4abeac95ce8e97b35f44">'
            . '<img itemprop="image" content="https://img/x.webp" />'
            . '<span class="listing-card__brand" itemprop="brand">FIAT</span>'
            . '<span class="listing-card__model">Cronos</span> <span class="listing-card__version">1.3L Drive</span>'
            . '<span itemprop="modelDate" class="listing-card__year">2022</span>'
            . '<span itemprop="mileageFromOdometer" class="listing-card__km" content="KMT 69000">69000 km</span>'
            . '<span class="listing-card__city">La Plata | </span><span class="listing-card__province">Buenos Aires</span>'
            . '<meta itemprop="priceCurrency" content="USD" /><span itemprop="price" content="14500">US$14.500</span></a></article>';
        $a = Autocosmos::avisos($html)[0];
        self::assertSame(['Fiat', 'Cronos', '1.3L Drive', 2022, 69000, 14500.0, 'USD', 'La Plata - Buenos Aires'],
            [$a['marca'], $a['modelo'], $a['version'], $a['anio'], $a['km'], $a['precio'], $a['moneda'], $a['ubicacion']]);
    }
}
