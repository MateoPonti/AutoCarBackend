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

Se copian y pegan enteras en el **SQL Editor de Neon**, primero en la branch `dev` y en `main` recién cuando se pasa `dev` → `main`.

## Reglas para una migración nueva

1. **Nombre:** el número que sigue, con 3 dígitos, guion y un nombre corto en minúscula: `008-planes.sql`. Nada de `migracion-*.sql` ni números repetidos. Hay una prueba (`tests/Unit/MigracionesTest.php`) que hace fallar el CI si no se cumple.
2. **Idempotente:** se tiene que poder correr más de una vez sin romper nada (`CREATE TABLE IF NOT EXISTS`, `ON CONFLICT DO NOTHING`, `ADD COLUMN IF NOT EXISTS`).
3. **Mismo cambio en `schema.sql`**, para que una base nueva quede igual que una migrada.
4. **Encabezado:** qué hace, en qué base se corre y si tiene que ir antes de desplegar el back que la usa.
5. Sumarla a la tabla de arriba.

**Lo que no va acá:** los autos de otros sitios (MercadoLibre, Kavak, Autocosmos), las búsquedas guardadas y los avisos del metabuscador están en **Firestore (NoSQL)**, no en Neon. Ver `docs/arquitectura-scraper.md`, sección 6.
