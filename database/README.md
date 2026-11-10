# Base de datos (Neon / PostgreSQL)

| Archivo | Para qué |
|---|---|
| `schema.sql` | Crea una base **nueva** con todo. No hace falta correr las migraciones después |
| `seed.sql` | Datos de prueba para una base nueva |
| `NNN-nombre.sql` | Migraciones para una base que **ya existe** (Neon dev y main). Se corren en orden de número |

## Orden de las migraciones

| # | Archivo | Qué hace |
|---|---|---|
| 1 | `001-e4.sql` | Publicar vehículos (entrega 4) |
| 2 | `002-nombres.sql` | Tablas en singular (`usuario`, `vehiculo`, `vehiculo_foto`) |
| 3 | `003-chat.sql` | Chat |
| 4 | `004-sesion.sql` | Sesiones de PHP en la base |
| 5 | `005-roles.sql` | Roles y permisos en tablas |
| 6 | `006-inspecciones.sql` | Talleres, inspecciones, checklist y reclamos |
| 7 | `007-demo-inspecciones-solo-dev.sql` | 5 mecánicos de ejemplo. **Solo en Neon dev, nunca en main** |
| 8 | `008-separar-version.sql` | Pasa la versión a su campo en los autos de prueba ("Corolla XEi" → "Corolla" + "XEi"), para que el comparador los encuentre |
| 9 | `009-solicitud-taller.sql` | "Adherí tu taller": CUIT, horarios y estado de la solicitud (pendiente, aprobado o rechazado) en `taller`. Correrla **antes** de desplegar el back que la usa |
| 10 | `010-publicar-por-partes.sql` | Publicar por partes: precio, kilometraje, combustible y transmisión pueden quedar vacíos mientras la publicación está pendiente, nunca en una visible. Correrla **antes** de desplegar el back que la usa |
| 11 | `011-vehiculo-con-vendedor.sql` | Toda publicación tiene un vendedor: los autos sin dueño pasan a la cuenta demo `vendedor-demo@autocar.test` y `usuario_id` queda obligatorio. El panel ya no crea ni edita vehículos. Correrla **antes** de desplegar el back que la usa |
| 12 | `012-favoritos.sql` | Favoritos: tabla `favorito` (usuario + vehículo) para `GET/PUT/DELETE /api/favoritos`. Correrla **antes** de desplegar el back que la usa |

Se copian y pegan enteras en el **SQL Editor de Neon**, primero en el proyecto de Neon de dev y en el de main recién cuando se pasa `dev` → `main` (son dos proyectos separados).

## Reglas para una migración nueva

1. **Nombre:** el número que sigue, con 3 dígitos, guion y un nombre corto en minúscula: `008-planes.sql`. Nada de `migracion-*.sql` ni números repetidos. Hay una prueba (`tests/Unit/MigracionesTest.php`) que hace fallar el CI si no se cumple.
2. **Idempotente:** se tiene que poder correr más de una vez sin romper nada (`CREATE TABLE IF NOT EXISTS`, `ON CONFLICT DO NOTHING`, `ADD COLUMN IF NOT EXISTS`).
3. **Si cambia la estructura** (tablas, columnas, permisos), el mismo cambio en `schema.sql`, para que una base nueva quede igual que una migrada. Si solo arregla datos, corregir también `seed.sql`.
4. **Encabezado:** qué hace, en qué base se corre y si tiene que ir antes de desplegar el back que la usa.
5. Sumarla a la tabla de arriba.

## Historial de la demo (solo dev)

Después de la 007, `php bin/demo-historial.php` carga inspecciones firmadas de meses anteriores de los 5 mecánicos (31 en total, 2 con reclamo que procedió), firmadas con el mismo código que la API. Sin eso, todos figuran como "Nuevo en la red" y en la demo no se ve al mecánico bajar en el ranking. No es una migración: es un script de PHP porque la huella se calcula en PHP. Toma la base de `.env` o de las variables de entorno, como `bin/importar-autos.php`; para Neon dev, con los datos de conexión de esa branch. Se puede correr más de una vez: si un mecánico ya tiene historial, no le agrega nada.

**Lo que no va acá:** los autos de otros sitios (MercadoLibre, Kavak, Autocosmos), las búsquedas guardadas y los avisos del metabuscador están en **Firestore (NoSQL)**, no en Neon. Ver `docs/arquitectura-scraper.md`, sección 6.
