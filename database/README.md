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
| 13 | `013-marcas-y-modelos.sql` | Marcas y modelos que se pueden publicar (62 marcas y ~1.080 modelos de las corridas del scraper del 10/11) y clave foránea `vehiculo (marca, modelo)`: con curl ya no se publica "Pepita 21021". Correrla **antes** de desplegar el back que la usa |
| 14 | `014-foto-de-perfil.sql` | Foto de perfil opcional: tabla `usuario_foto` (en la base, como las fotos de las publicaciones). Correrla **antes** de desplegar el back que la usa |
| 15 | `015-contacto-y-reportes.sql` | Contacto (público, con email) y reportes de publicaciones o usuarios: tablas `contacto` y `reporte`. Correrla **antes** de desplegar el back que la usa |
| 16 | `016-roles-del-equipo.sql` | El admin deja de tener los permisos del mecánico (no tiene taller). El equipo no compra ni vende y nadie modera su propia publicación (eso va en el código). Correrla **antes** de desplegar el back que la usa |
| 17 | `017-modelos-nuevos.sql` | Suma a la lista de Publicar 7 marcas y 46 modelos de la corrida completa de MercadoLibre del 10/11. Solo agrega |
| 18 | `018-inspeccion-no-realizada.sql` | Estado `no_realizada` de una inspección: el mecánico fue y no la pudo hacer (por ejemplo, el vendedor no dejó revisar el auto); se le devuelve todo al comprador. Correrla **antes** de desplegar el back que la usa |
| 19 | `019-marca-unica.sql` | Una marca no se repite con otra escritura ("DS" y "Ds"): índice único sobre el nombre en minúsculas. No hace falta desplegar nada antes ni después |
| 20 | `020-seguridad.sql` | Arreglos de seguridad de la Entrega 4: un solo pedido de inspección en curso por comprador y aviso (columna `url_clave` e índice único, aunque lleguen varios a la vez) y tabla `login_intento` para el límite de 5 intentos de ingreso cada 15 minutos. Correrla **antes** de desplegar el back que la usa |
| 21 | `021-preferencias-y-parametros.sql` | Lo que pidió el profe el 13/11: tabla `parametro` (comisión, fondo, tope, días de reclamo y rango del precio de la inspección, editables por el admin, con historial en `parametro_cambio` y permiso `parametros.editar`), tope y días de reclamo guardados en cada inspección, `usuario_preferencia` ("¿Para qué buscás el auto?") y `usuario.privacidad_aceptada_at`. Correrla **antes** de desplegar el back que la usa |
| 22 | `022-patente.sql` | Patente del auto en cada inspección (la carga el mecánico con una foto de la chapa, tabla `inspeccion_patente_foto`; sin patente no se firma) e índice para el historial por patente. A las firmadas que ya existían les inventa una (`AD000DM`…, la misma por aviso). Correrla **antes** de desplegar el back que la usa |
| 23 | `023-cotizacion-dolar.sql` | Parámetro `cotizacion_dolar` (lo cambia el admin): el metabuscador muestra y ordena en pesos los autos publicados en dólares. Correrla **antes** de desplegar el back que la usa |
| 24 | `024-auto-vendido-y-fondo.sql` | Auto vendido y fondo de garantía: turno que confirma el vendedor (`turno_confirmado_at`), estado `caida` (la publicación se vendió o se bajó antes del turno), tabla `credito` (crédito del comprador si el auto se vendió) y `inspeccion.credito_usado`, `reclamo.devolucion` y `reclamo.franquicia`, y los parámetros `franquicia`, `credito_vendido` y `horas_cupon`. Correrla **antes** de desplegar el back que la usa |
| 25 | `025-presupuestos.sql` | Idea A, costo total: tabla `presupuesto` (cada arreglo del informe firmado se le pide a otros talleres de la red, nunca al que inspeccionó) y parámetros `presupuestos_por_arreglo`, `dias_presupuesto` y `comision_arreglos`. Correrla **antes** de desplegar el back que la usa |
| 26 | `026-fallas-tipicas.sql` | Idea B, fallas típicas por modelo: `inspeccion.marca` y `modelo` (del catálogo; a las que ya existen se les completan con la publicación o el título), tablas `falla_tipica` (origen internet o mecánicos) y `falla_voto`, y parámetros `fallas_minimo_inspecciones`, `fallas_votos_confirmar` y `fallas_porcentaje_medido`. Correrla **antes** de desplegar el back que la usa |
| 27 | `027-foto-de-falla.sql` | Foto de cada ítem del checklist: tabla `inspeccion_item_foto` (una por ítem). Si un ítem tiene "falla", sin foto no se firma; en "observado" es opcional. Correrla **antes** de desplegar el back que la usa |
| 28 | `028-estadisticas.sql` | Estadísticas del admin: `inspeccion.origen` (desde dónde se pidió) y tabla `evento` (recomendaciones mostradas y tocadas). "Compró" = pagó la inspección. Correrla **antes** de desplegar el back que la usa |
| 29 | `029-estadisticas-por-perfil.sql` | Estadísticas por tipo de usuario: eventos `busco` (abrió los resultados de una búsqueda del metabuscador) y `orden` (eligió "Menor precio", "Menos km", "Más nuevos" o "Recomendado"). El panel cruza el perfil del comprador con la plata, las recomendaciones, la búsqueda y el orden. Correrla **antes** de desplegar el back que la usa |
| 30 | `030-ranking-en-parametros.sql` | Ranking de mecánicos en Parámetros: `ranking_veces_reclamo` (2), `ranking_minimo_firmadas` (3) y `ranking_especialidad_buena` (2,5), los mismos valores que tenía el código. Sin correrla el back usa esos mismos valores |
| 31 | `031-cobro-mercado-pago.sql` | Cobro de la inspección con Mercado Pago (Checkout Pro, API de Orders): tabla `cobro` (order, link de pago y estado: pendiente, pagado, cancelado o devuelto). Solo se usa con `MP_ACTIVO=true`: con `false` el pago sigue simulado y el back no toca la tabla. Correrla **antes** de prender `MP_ACTIVO` |
| 32 | `032-afiliacion-clics.sql` | Afiliación con las plataformas (convenio simulado): `convenio_plataforma` (tarifa por clic de MercadoLibre, Kavak y Autocosmos), `clic_salida` (cada "Ver en …" del metabuscador; válido = de una cuenta personal y no repetido en 24 h) y `cobro_afiliacion` (los meses que el admin marcó cobrados, con el monto de ese momento). Correrla **antes** de desplegar el back que la usa |
| 33 | `033-empresas-y-ranking.sql` | Empresas (concesionarias): rol `empresa` y tabla `empresa` (razón social, CUIT, estado pendiente/aprobado/rechazado, como los talleres), `vehiculo_visita` (una visita por visitante, auto y día, para el panel de la empresa), `vehiculo_mercado` (el precio de cada publicación contra autos parecidos de otros sitios, guardado para el ranking de Explorar: se calcula al aprobarla y una vez por día, así Explorar no lee Firestore) y los parámetros `limite_autos_particular` (3) y `abono_empresa_mensual`. Suma la unidad `autos` a los parámetros. Correrla **antes** de desplegar el back que la usa |
| 34 | `034-sin-reclamos-ni-fondo.sql` | 23/11: sin reclamos ni fondo de garantía (el reclamo no se puede verificar y llega cuando el comprador ya compró). Borra la tabla `reclamo`, las columnas `monto_fondo`, `tope_veces` y `dias_reclamo` de `inspeccion`, los parámetros `parte_fondo`, `tope_veces`, `dias_reclamo`, `franquicia`, `ranking_veces_reclamo` y `ranking_especialidad_buena`, y el permiso `reclamos.resolver`. El ranking de mecánicos pasa a ser por experiencia (inspecciones firmadas). |
| 35 | `035-sin-historial-por-patente.sql` | 23/11: el informe ya no trae las inspecciones anteriores de la misma patente (un auto puede dejar de estar publicado y ese historial queda sin sentido). La patente y la foto de la chapa se quedan; se borra solo el índice `idx_inspeccion_patente`, que servía para buscarlas. |

Se copian y pegan enteras en el **SQL Editor de Neon**, primero en el proyecto de Neon de dev y en el de main recién cuando se pasa `dev` → `main` (son dos proyectos separados).

### Estado al 11/11/2026

Verificado con una consulta de solo lectura contra las dos bases: **Neon dev y Neon main tienen corridas todas las migraciones, de la `001` a la `020`**. La próxima migración nueva es la `021`. La `021` (14/11) se corre en dev con el back nuevo y en main en el próximo pase.

La `007` (mecánicos `mecanicoN@demo.autocar.com.ar`) está solo en dev, como corresponde. Main tiene **sus propios talleres de demo**, cargados el 11/11 desde el sitio: 5 talleres aprobados (`*@autocar.test`: Acosta, Benítez, Gómez, Medina y Ferreyra), 3 compradores y 15 inspecciones firmadas (3 por mecánico). No se borran: son los que permiten hacer la demo sobre la URL pública.

## Reglas para una migración nueva

1. **Nombre:** el número que sigue, con 3 dígitos, guion y un nombre corto en minúscula: `008-planes.sql`. Nada de `migracion-*.sql` ni números repetidos. Hay una prueba (`tests/Unit/MigracionesTest.php`) que hace fallar el CI si no se cumple.
2. **Idempotente:** se tiene que poder correr más de una vez sin romper nada (`CREATE TABLE IF NOT EXISTS`, `ON CONFLICT DO NOTHING`, `ADD COLUMN IF NOT EXISTS`).
3. **Si cambia la estructura** (tablas, columnas, permisos), el mismo cambio en `schema.sql`, para que una base nueva quede igual que una migrada. Si solo arregla datos, corregir también `seed.sql`.
4. **Encabezado:** qué hace, en qué base se corre y si tiene que ir antes de desplegar el back que la usa.
5. Sumarla a la tabla de arriba.

## Historial de la demo (solo dev)

Después de la 007, `php bin/demo-historial.php` carga inspecciones firmadas de meses anteriores de los 5 mecánicos (31 en total), firmadas con el mismo código que la API. Sin eso, todos figuran como "Nuevo en la red" (el ranking es por experiencia). No es una migración: es un script de PHP porque la huella se calcula en PHP. Toma la base de `.env` o de las variables de entorno, como `bin/importar-autos.php`; para Neon dev, con los datos de conexión de esa branch. Se puede correr más de una vez: si un mecánico ya tiene esas inspecciones, no le agrega nada.

**Ojo:** el script busca los mecánicos por los emails de la `007` (`mecanicoN@demo.autocar.com.ar`). En main esos usuarios no existen (los talleres de demo de main son otros, ver arriba), así que ahí falla sin cargar nada. Al 11/11 tampoco se corrió en dev: ninguna inspección tiene el link `historial.demo.invalid`.

**Lo que no va acá:** los autos de otros sitios (MercadoLibre, Kavak, Autocosmos), las búsquedas guardadas y los avisos del metabuscador están en **Firestore (NoSQL)**, no en Neon. Ver `docs/arquitectura-scraper.md`, sección 6.
