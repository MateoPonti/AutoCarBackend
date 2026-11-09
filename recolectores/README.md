# Recolectores

Programas que corren en la PC del grupo (no en Render) y bajan los autos usados de otros sitios. Cada uno guarda una corrida por día en `storage/mercado/{fuente}/{AAAA-MM-DD}.json`, que después usa `bin/armar-modelos.php` para el comparador. La arquitectura completa está en [docs/arquitectura-scraper.md](../docs/arquitectura-scraper.md).

| Fuente | Cómo | Pausa | Una corrida completa |
|---|---|---|---|
| MercadoLibre | `mercadolibre/recolectar.php`: lee el JSON que la página trae adentro (`POLYCARD`) | 3 s | ~1.900 páginas, ~2 h 15 min |
| Kavak | `kavak/recolectar.php`: lee las tarjetas del HTML con DOM | 20 s (lo pide su `robots.txt`) | 43 páginas, ~15 min |
| Autocosmos | `autocosmos/recolectar.php`: microdatos schema.org | 3 s | hasta que una página no trae autos nuevos |

- **Sin librerías ni navegador:** curl y DOM de PHP. Usan el `vendor/autoload.php` del back (`composer install`) y `recolectores/autoload.php`.
- `kavak/scraper.php` es la versión de Taro con Playwright. Hace lo mismo y queda como alternativa si Kavak vuelve a cargar los autos solo con JavaScript (tiene su propio [README](kavak/README.md) y `composer.json`).
- Cada extractor (`Extractor.php`, `ExtractorHtml.php`) se prueba sin red en `tests/Unit/RecolectoresTest.php`.
- `diario.bat` corre todo y arma los modelos. Para programarlo: ver el comentario del archivo.

## Formato común

El mismo que definió Taro para Kavak, con dos campos más (`combustible`, `foto`) y el `dolar` del día:

```json
{
    "fuente": "mercadolibre",
    "fecha": "2026-11-08T09:00:00-03:00",
    "completo": true,
    "dolar": { "tipo": "oficial", "venta": 1570, "fecha": "2026-11-05T18:55:00.000Z" },
    "pedidos": 1912,
    "autos": [
        {
            "id_externo": "MLA2268147919",
            "marca": "Volkswagen",
            "modelo": "Gol Trend",
            "version": "1.6 Comfortline 101cv",
            "anio": 2018,
            "km": 110000,
            "transmision": null,
            "combustible": null,
            "precio": 16900000,
            "precio_contado": null,
            "moneda": "ARS",
            "reservado": false,
            "ubicacion": "Zárate - Buenos Aires Interior",
            "url": "https://auto.mercadolibre.com.ar/MLA-2268147919-...",
            "foto": "https://http2.mlstatic.com/D_NQ_NP_972581-MLA120950404543_112026-O.webp"
        }
    ]
}
```

- **Obligatorios para comparar:** `marca`, `modelo`, `anio`, `km`, `precio` y `moneda`. Un sitio que no da un campo opcional lo deja en `null`; un sitio que da algo más lo puede agregar (en Firestore no hay columnas).
- **`completo`:** `true` solo si recorrió todo. Con límite de páginas, filtro de marcas o un error, va en `false`.
- **`dolar`:** oficial venta de [dolarapi.com](https://dolarapi.com). Si no responde queda `null` y se usa el de otra fuente del mismo día.

## Agregar un sitio

1. Carpeta `recolectores/{fuente}/` con un `Extractor.php` (HTML → lista de autos en el formato común, sin red) y un `recolectar.php` que pagina con `Comun\Http` y guarda con `Comun\Salida::guardar('{fuente}', ...)`.
2. Una prueba en `tests/Unit/RecolectoresTest.php` con un pedazo de HTML real.
3. Sumarlo a `diario.bat`. `armar-modelos` lo toma solo.

Probados y descartados (noviembre de 2026): DeMotores (error TLS), Karvi (403), Facebook Marketplace (pide sesión), OLX (cerró en Argentina).

## Uso responsable

Una página por vez con pausa, sin paralelizar, con reintentos espaciados y frenando ante un 403. Se respeta el `Crawl-delay` de Kavak. Se guardan solo los datos públicos del aviso (sin datos del vendedor).
