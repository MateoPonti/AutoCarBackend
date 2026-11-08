# Scraper y comparador de precios

Estado al 08/11/2026, rama `feat/comparador`. El recolector de cada sitio, el comparador y el endpoint de la ficha **ya están hechos y probados en local** con datos reales. Falta crear el proyecto de Firebase para que Render lea los datos (ver [Pendiente](#7-pendiente)).

## 1. La idea

Según el [modelo de negocio](https://github.com/Fedekasparian/AutoCar/tree/dev/modelo-de-negocio), el eje de AutoCar es **la publicación que se compara sola**: en cada publicación nuestra se muestra si el precio es bueno frente a autos iguales de otros sitios.

| | **Comparador** (el eje, hecho) | **Metabuscador** (plan comprador, después) |
|---|---|---|
| Arranca desde | Una publicación nuestra (tabla `vehiculo` de Neon) | Lo que busca el usuario (marca, modelo, año, precio) |
| Qué lee | El documento del modelo: todos los autos iguales de todos los sitios | El mismo documento del modelo |
| Qué muestra | En la ficha: etiqueta, mediana, cuánto se ahorra y los autos más parecidos con link | Lista de autos de todos los sitios, con los de AutoCar primero |
| Cuándo funciona | Con 5 o más autos parecidos afuera | Con cualquier auto scrapeado |

## 2. Arquitectura

```mermaid
flowchart LR
  subgraph PC["PC del grupo, todos los días 9:00 (diario.bat)"]
    ML["MercadoLibre<br/>~90.000 usados"]
    KV["Kavak<br/>~1.300"]
    AC["Autocosmos"]
    J[("storage/mercado<br/>un JSON por fuente y día")]
    ARM["armar-modelos<br/>un documento por modelo"]
    ML --> J
    KV --> J
    AC --> J
    J --> ARM
  end
  ARM -->|"solo los modelos que cambiaron"| FS[("Firestore<br/>modelos/{marca_modelo}")]
  NEON[("Neon<br/>usuarios, vehículos, chat")]
  subgraph RENDER["API en Render (no tiene el scraper)"]
    CTRL["GET /api/vehiculos/{id}/comparacion"]
    CMP["Comparador"]
    CTRL --> CMP
  end
  NEON -->|"la publicación"| CTRL
  FS -->|"1 lectura, solo lectura"| CTRL
  CMP --> FICHA["Ficha: Buen precio · En precio · Caro"]
```

**El scraper no se despliega.** Corre en la PC del grupo (IP de casa: MercadoLibre no bloqueó en 200 páginas seguidas). `recolectores/` y `storage/mercado/` están en `.dockerignore`, así que la imagen de Render no los tiene. Render solo lee Firestore con una credencial de solo lectura.

### Por qué un documento por modelo

En vez de guardar una comparación ya calculada por cada publicación, se guarda **un documento por modelo** (`volkswagen_gol_trend`) con todos los autos de ese modelo de todos los sitios, en forma compacta. La API compara en el momento.

- **1 lectura por ficha vista.** Con 50.000 lecturas gratis por día alcanza para 50.000 fichas.
- **Funciona para publicaciones nuevas** sin esperar a la corrida del día siguiente.
- **Pocas escrituras:** hay ~1.500 modelos y solo se reescriben los que cambiaron (cada documento tiene una huella). Lejos de las 20.000 escrituras diarias.
- **El metabuscador usa el mismo documento:** buscar "Gol Trend" es leer `volkswagen_gol_trend`.
- Si mañana se suma un sitio, solo se agrega su recolector: el documento suma autos con otra `fuente`.

## 3. Corrida diaria

```mermaid
sequenceDiagram
  participant T as Programador de tareas (9:00)
  participant R as Recolectores
  participant S as Sitios
  participant A as armar-modelos
  participant F as Firestore
  T->>R: diario.bat
  R->>S: Kavak (1 página cada 20 s)
  R->>S: Autocosmos (1 página cada 3 s)
  R->>S: MercadoLibre: marca → modelo → año (1 cada 3 s)
  R->>R: storage/mercado/{fuente}/{fecha}.json + dólar oficial del día
  T->>A: php bin/armar-modelos.php
  A->>F: lee indices/modelos (huellas)
  A->>F: escribe solo los modelos con huella distinta
```

- Si una fuente falla ese día, las otras siguen y `armar-modelos` usa la última corrida que haya de cada una.
- Una corrida cortada se guarda igual, marcada `"completo": false`.
- **MercadoLibre corta cada búsqueda en 42 páginas (~2.016 autos).** Por eso el recolector va por marca → modelo, y si un modelo tiene más, por año. La marca y el modelo salen del filtro de la búsqueda, no del título: son exactos.

## 4. Qué datos trae cada sitio

Lo que nos interesa para comparar es **marca, modelo, año, km y precio** (con su moneda). El resto se guarda si el sitio lo da.

| Campo | MercadoLibre | Kavak | Autocosmos | Lo usa el comparador |
|---|---|---|---|---|
| Marca y modelo | ✅ del filtro | ✅ | ✅ | Sí: tienen que ser iguales |
| Versión | ✅ del título | ✅ | ✅ | No (se muestra) |
| Año | ✅ | ✅ | ✅ | Sí: ±2 (±3 si faltan) |
| Km | ✅ | ✅ | ✅ | Sí: ±30 % (mínimo ±10.000) |
| Precio y moneda | ✅ ARS o USD (~20 % USD) | ✅ ARS | ✅ ARS o USD | Sí, en pesos |
| Precio de contado | — | A veces | — | Si está, se usa ese |
| Transmisión | — | ✅ | — | No (MercadoLibre no la da) |
| Combustible | — | — | — | No |
| Ubicación | ✅ | ✅ | ✅ | No (a futuro: por provincia) |
| Foto y link | ✅ | Link | ✅ | Se muestra en "autos parecidos" |

Sitios probados: **DeMotores** corta la conexión (error TLS), **Karvi** responde 403, **Facebook Marketplace** pide iniciar sesión, **OLX** ya no existe en Argentina. Quedan afuera.

## 5. Cómo decide el comparador

```mermaid
flowchart TD
  P["Publicación nuestra<br/>marca, modelo, año, km, precio"] --> K["Clave del modelo<br/>Citroën C4 Cactus → citroen_c4_cactus"]
  K --> D{"¿Hay documento<br/>del modelo?"}
  D -- No --> SD["sin_datos"]
  D -- Sí --> F["Autos con km parecidos<br/>y año ±2"]
  F --> U["Dólares a pesos<br/>con el dólar del día"]
  U --> E["Saca precios extremos<br/>(anticipos, cuotas, errores)"]
  E --> N{"¿5 o más?"}
  N -- "No, con ±2" --> F3["Prueba con año ±3"] --> N
  N -- "No, con ±3" --> SD
  N -- Sí --> M["Mediana de precios"]
  M --> L{"Diferencia con la mediana"}
  L -- "7 % o más abajo" --> B["buen_precio"]
  L -- "entre −7 % y +7 %" --> EP["en_precio"]
  L -- "7 % o más arriba" --> C["caro"]
```

- La **mediana** y no el promedio: un auto mal cargado no la mueve.
- **Precios extremos:** se descartan los que quedan fuera de 1,5 veces el rango intercuartil (en MercadoLibre hay avisos con el anticipo como precio).
- **Regla de honestidad:** con menos de 5 autos parecidos no se muestra el dato, y si está caro, también se dice.
- Los valores (7 %, ±2 años, ±30 % km, mínimo 5) son constantes en `src/Comparador/Comparador.php` para ajustar con datos reales.

### Respuesta de `GET /api/vehiculos/{id}/comparacion`

Público, sin sesión. 404 si la publicación no existe o no es visible. 503 si Firestore no responde (la ficha sigue andando).

```json
{
  "vehiculo_id": 12,
  "comparacion": {
    "clave": "volkswagen_gol_trend",
    "fecha_datos": "2026-11-08",
    "precio": 15500000, "precio_usd": 9873, "dolar": 1570,
    "criterio": { "anios": 2, "km_pct": 30, "minimo": 5 },
    "etiqueta": "buen_precio",
    "texto": "9.2 % menos que el precio típico de 38 autos parecidos en otros sitios.",
    "diferencia_pct": -9.2, "ahorro": 1570000,
    "comparables": 38, "descartados": 2,
    "mercado": { "mediana": 17070000, "p25": 16000000, "p75": 18200000, "minimo": 14500000, "maximo": 21000000 },
    "km":   { "este": 98000, "mediana_mercado": 105000 },
    "anio": { "este": 2018, "mediana_mercado": 2018 },
    "por_fuente": [ { "fuente": "kavak", "cantidad": 3, "mediana": 18100000 }, { "fuente": "mercadolibre", "cantidad": 35, "mediana": 17000000 } ],
    "parecidos": [ { "fuente": "mercadolibre", "version": "1.6 Comfortline", "anio": 2018, "km": 99000, "precio": 16900000, "moneda": "ARS", "precio_ars": 16900000, "url": "https://auto.mercadolibre.com.ar/MLA-2268147919-_JM" } ]
  }
}
```

Con `"etiqueta": "sin_datos"` solo vienen `clave`, `fecha_datos`, `precio`, `precio_usd`, `dolar`, `criterio`, `texto` y `comparables`.

## 6. Archivos

| Archivo | Qué hace | ¿Va a Render? |
|---|---|---|
| `recolectores/mercadolibre/` | `Extractor.php` (lee la página, sin red) y `recolectar.php` | No |
| `recolectores/kavak/` | `ExtractorHtml.php` + `recolectar.php` (curl, nuevo) y `scraper.php` (Playwright, de Taro, queda como alternativa). `Normalizador.php` lo usan los dos | No |
| `recolectores/autocosmos/` | `Extractor.php` (microdatos schema.org) y `recolectar.php` | No |
| `recolectores/comun/` | `Http` (curl con pausa y reintentos), `JsonEmbebido`, `Salida` (JSON del día + dólar) | No |
| `recolectores/diario.bat` | La corrida diaria para el Programador de tareas de Windows | No |
| `bin/armar-modelos.php` | JSON del día → documentos por modelo → almacén | No se usa |
| `bin/comparar.php` | Prueba el comparador desde la consola | No se usa |
| `bin/medir-cambios.php` | Cuenta qué cambió entre dos corridas | No se usa |
| `src/Comparador/` | `Clave`, `Modelos`, `Comparador`, `Almacen` (+ archivos y Firestore), `Firestore` (REST con curl y openssl), `ComparadorController` | Sí |
| `tests/Unit/ComparadorTest.php`, `RecolectoresTest.php` | 16 pruebas nuevas, sin red ni base | No |

Lo de Taro (`src/MetaBusqueda/`, rama `scraper`) queda como está: el comparador nuevo usa su `Estadistica` y su `Normalizador` de Kavak. Su metabuscador sigue en Neon y se puede pasar al documento por modelo después.

### Probarlo en local

```
php recolectores/kavak/recolectar.php 3                    # 3 páginas (1 minuto)
php recolectores/autocosmos/recolectar.php 10              # 10 páginas
php recolectores/mercadolibre/recolectar.php --marcas=volkswagen --max-paginas=3
php bin/armar-modelos.php                                  # con COMPARADOR_ALMACEN=archivos (por defecto)
php bin/comparar.php Volkswagen "Gol Trend" 2018 110000 15500000
php bin/comparar.php --publicaciones                       # las publicaciones de Neon dev
```

Con la API local levantada, `GET http://localhost:8000/api/vehiculos/1/comparacion` lee los mismos archivos.

En Windows el PHP suele venir sin certificados para curl: `Http` usa solo los de Git for Windows, o los de la variable `CURL_CAINFO`.

### Prueba real (08/11/2026, PC del grupo)

MercadoLibre solo Volkswagen con tope de 3 páginas por búsqueda (140 pedidos, 4 minutos, sin bloqueos), Kavak 3 páginas y Autocosmos 10: **6.082 autos → 204 modelos**. El documento más grande pesa 201 KiB (Firestore admite 1.024).

| Auto de prueba | Resultado |
|---|---|
| Gol Trend 2018, 110.000 km, $ 15,5 M | `en_precio`: 2,5 % abajo de la mediana de 51 autos ($ 15,9 M) |
| Amarok 2015, 150.000 km, $ 30 M | `en_precio`: 4,7 % arriba de 430 autos |
| Polo 2020, 60.000 km, $ 28 M | `caro`: 15,1 % arriba de 21 autos |
| Fiat Cronos 2022, 60.000 km, $ 21 M | `buen_precio`: 8,7 % abajo de 5 autos (Autocosmos y Kavak) |

## 7. Pendiente

1. **Crear el proyecto de Firebase** (Firestore en modo producción, plan Spark) y dos cuentas de servicio: una de escritura (rol *Cloud Datastore User*, el `.json` queda en la PC) y una de solo lectura (*Cloud Datastore Viewer*, su contenido va en `FIRESTORE_CREDENCIALES_JSON` de Render). Después: `COMPARADOR_ALMACEN=firestore` y `php bin/armar-modelos.php --almacen=firestore`. El cliente de Firestore está escrito y probado sin red (firma del token y conversión de datos), falta probarlo contra el proyecto real.
2. **Programar la corrida diaria** en la PC: `schtasks /create /tn "AutoCar scraper" /sc daily /st 09:00 /tr "C:\...\Backend\recolectores\diario.bat"`.
3. **Primera corrida completa de MercadoLibre** (~2 h 15 min) y al día siguiente `php bin/medir-cambios.php mercadolibre`, para tener el dato real de cuánto cambia por día.
4. **Front:** el bloque de la ficha que consume `/api/vehiculos/{id}/comparacion`.
5. **Respaldo con GitHub Actions** (si la PC no corrió): GitHub solo ejecuta los `schedule` desde `main`, así que para que corra solo el workflow tiene que estar en `main`. Decidir si se sube solo el workflow o se deja manual.
6. **Pasar el metabuscador de Taro** a leer los documentos por modelo en vez de Neon.
