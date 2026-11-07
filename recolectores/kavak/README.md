# Recolector de Kavak

Recorre el listado de autos usados de [Kavak Argentina](https://www.kavak.com/ar/usados) con un navegador Chromium sin ventana (Playwright) y guarda los autos en `autos.json`, en el formato común de los recolectores. Después, `bin/importar-autos.php` los carga en la base para la Meta-búsqueda (ver el [README principal](../../README.md#meta-búsqueda)).

- **No se despliega en Render.** La carpeta está excluida de la imagen en `.dockerignore`.
- **Tiene sus propias dependencias.** Usa su `composer.json` y su `vendor/`, así la API sigue sin librerías de terceros.

## Archivos

| Archivo | Para qué sirve |
|---|---|
| `scraper.php` | Recorre el listado y genera `autos.json` |
| `Normalizador.php` | Interpreta cada tarjeta. No usa navegador ni red, y tiene pruebas en `tests/Unit` |
| `composer.json` y `composer.lock` | Dependencia `playwright-php/playwright`, fijada para PHP 8.3 (la versión del `Dockerfile`) |
| `.gitignore` | Deja afuera del repo `vendor/` y `autos.json` |
| `../../.github/workflows/scraper.yml` | La ejecución programada |

## Ejecución programada

El workflow **Scraper** de GitHub Actions corre **una vez por semana, los lunes a las 06:00 de Argentina** (09:00 UTC), y recorre **todas las páginas** del listado. En noviembre de 2026 eran 42 páginas y 1.247 autos, unos 16 minutos por la pausa entre páginas.

Después sube `autos.json` como artefacto (se guarda 30 días) y lo importa en la base, si el repo tiene estos secretos en **Settings → Secrets and variables → Actions**: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS`. Sin `DB_HOST`, la importación se saltea.

Para correrlo a mano: **Actions** → **Scraper** → **Run workflow**. Se puede indicar la cantidad de páginas; si queda vacío, recorre todas.

Para que corra **todos los días**, cambiar el `cron` en `.github/workflows/scraper.yml`:

```yaml
- cron: '0 9 * * *'
```

Limitaciones de GitHub Actions:

- **Solo corre desde `main`.** GitHub ejecuta los `schedule` únicamente desde la rama por defecto: hasta que este workflow llegue a `main` no corre solo. "Run workflow" también necesita que el archivo esté en `main`.
- **El horario es aproximado.** La corrida puede demorarse si GitHub tiene mucha carga.
- **Se puede desactivar solo.** En repos públicos, GitHub desactiva los `schedule` tras 60 días sin actividad en el repo. Se vuelve a activar desde la pestaña **Actions**.

Si falla la corrida, por ejemplo porque Kavak cambió su HTML o bloquea el pedido, el workflow queda en rojo. Si ya había juntado autos, el archivo se guarda con lo obtenido y marcado como incompleto. Si falla en la primera página, no genera archivo.

## Ejecutarlo en local

Requiere PHP 8.3 o superior, Composer y Node.js 20 o superior.

```
cd recolectores/kavak
composer install
php vendor/bin/playwright-install chromium    # solo la primera vez: descarga el navegador
php scraper.php                               # todas las páginas (unos 16 minutos)
php scraper.php 2                             # solo las primeras 2 páginas (corrida incompleta)
```

Cada corrida **reemplaza** `autos.json`. Los autos no se repiten: si el listado cambia durante la corrida, un mismo `id_externo` se guarda una sola vez.

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

- El `robots.txt` de Kavak no bloquea `/ar/usados` y pide 20 segundos entre pedidos (`Crawl-delay: 20`). El scraper espera ese tiempo entre páginas: conviene no quitar esa pausa.
- Los selectores dependen del HTML de Kavak (`data-testid="card-product-..."`). Si lo cambian, hay que actualizarlos en `scraper.php`.
