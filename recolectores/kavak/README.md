# Recolector de Kavak

Recorre el listado de autos usados de [Kavak Argentina](https://www.kavak.com/ar/usados) con curl (el HTML ya trae las tarjetas, no hace falta navegador) y guarda la corrida del día en `storage/mercado/kavak/AAAA-MM-DD.json`, en el formato común de los recolectores. Después `bin/armar-modelos.php` la usa para el comparador y `bin/importar-autos.php` la carga en el metabuscador. Cómo se programa la corrida diaria (PC y GitHub Actions): [recolectores/README.md](../README.md).

- **No se despliega en Render.** La carpeta está excluida de la imagen en `.dockerignore`.
- Antes había un `scraper.php` de Taro con Playwright (navegador Chromium). Se borró el 11/11: hacía lo mismo, necesitaba sus propias dependencias y en GitHub Actions fallaba por timeout. Su `Normalizador.php` se sigue usando.

## Archivos

| Archivo | Para qué sirve |
|---|---|
| `recolectar.php` | Recorre las páginas (20 s entre una y otra, lo pide Kavak) y guarda la corrida |
| `ExtractorHtml.php` | Saca las tarjetas del HTML con DOM. No usa red: se prueba con HTML guardado en `tests/Unit/RecolectoresTest.php` |
| `Normalizador.php` | Interpreta cada tarjeta (marca, modelo, año, km, precios, reservado). Pruebas en `tests/Unit` |

## Ejecutarlo en local

```
php recolectores/kavak/recolectar.php      # todas las páginas (unos 15 minutos)
php recolectores/kavak/recolectar.php 2    # solo las primeras 2 páginas (corrida incompleta)
```

## Formato común

Cualquier recolector nuevo tiene que generar este JSON:

```json
{
    "fuente": "kavak",
    "fecha": "2026-11-09T06:15:00-03:00",
    "completo": true,
    "autos": [
        {
            "id_externo": "562715",
            "marca": "Chevrolet",
            "modelo": "Tracker",
            "version": "1.8 LTZ MT",
            "anio": 2014,
            "km": 88000,
            "transmision": "manual",
            "precio": 15020000,
            "precio_contado": null,
            "moneda": "ARS",
            "reservado": false,
            "ubicacion": "Buenos Aires",
            "url": "https://www.kavak.com/ar/venta/chevrolet-tracker-18_ltz_mt-suv-2014"
        }
    ]
}
```

- **`completo`:** `true` solo si recorrió todo el listado. Una corrida con límite de páginas o con errores va en `false`, porque un auto ausente no significa que se haya retirado.
- **`transmision`:** `manual`, `automatica` o `null`.
- **`moneda`:** `ARS` o `USD`.
- **`precio`:** número, o `null` si la plataforma no lo muestra.

## Qué significa cada precio

Kavak muestra hasta dos precios en la tarjeta, y su significado depende del texto que los acompaña. Se comprobó comparando tarjetas con páginas de detalle en noviembre de 2026:

| Tarjeta | Precio grande | Precio chico |
|---|---|---|
| "Precio desde", solo uno | Financiando 40% o más. El de contado no aparece en la tarjeta | — |
| "Precio desde", dos precios | Financiando 40% o más | Contado |
| "Precio de contado" (Outlet) | Contado, con oferta | Antes de la oferta |

`precio` es siempre el grande, porque es el único que tienen todas las tarjetas con precio. `precio_contado` se completa cuando la tarjeta lo informa. Los autos reservados no muestran precio.

## Limitaciones

- La `url` de Kavak describe el modelo y la versión, no el auto puntual: dos autos parecidos pueden compartirla, y la página de detalle puede mostrar otro auto de esa versión.
- Las tarjetas no traen combustible, color, estado del auto ni carrocería.

## Uso responsable

- El `robots.txt` de Kavak no bloquea `/ar/usados` y pide 20 segundos entre pedidos (`Crawl-delay: 20`). El recolector espera ese tiempo entre páginas: conviene no quitar esa pausa.
- Los selectores dependen del HTML de Kavak (`data-testid="card-product-..."`). Si lo cambian, hay que actualizarlos en `ExtractorHtml.php`.
