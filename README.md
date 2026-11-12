# AutoCar Backend (API JSON)

API en **PHP + PostgreSQL** (con Composer) de AutoCar (TP Integrador, Programación en Ambiente Web, UNLu).
Compra y venta de vehículos. Entidad principal: **vehículos**. El front (Vercel) consume esta API.

- **URL desplegada:** https://autocarbackend-1.onrender.com *(HTTPS)*
- **Roles y permisos** (en tablas: `rol`, `permiso`, `rol_permiso`): `usuario` (publica y chatea), `moderador` (aprueba o rechaza publicaciones), `admin` (puede todo: usuarios, ABM del catálogo, moderación y métricas) y `mecanico` (verificaciones; endpoints pendientes). Las rutas piden **permisos**, no roles: un rol nuevo solo necesita filas en `rol_permiso`.
- **Autenticación:** sesión de PHP (`$_SESSION`) guardada en la tabla `sesion`; el navegador solo tiene la cookie `autocar_sesion` con el id (`HttpOnly`, `Secure`, `SameSite=Strict`) + token CSRF en el header `X-CSRF-Token`.
- **Middleware** (`src/Core/Middleware.php`): antes de ejecutar el controlador, controla el guard de la ruta (`auth` → sesión, 401; `permiso:...` → permiso del rol, 403) y el token CSRF en todo lo que cambia datos (403). Los guards se ven todos juntos en `src/routes.php`.
- **Roles:** *usuario* compra y vende; *mecánico* (cuenta del taller) inspecciona; *moderador* revisa publicaciones, reportes y reclamos; *admin* lo del moderador más usuarios, talleres, contacto y métricas (todos los permisos menos los del mecánico). Las cuentas del equipo y las de los talleres **no compran ni venden**: las rutas de publicar, pedir mecánico, favoritos, chat y búsquedas guardadas llevan el guard `usuario` (403 `cuenta_del_equipo`). Nadie modera su propia publicación (409 `publicacion_propia`).
- **Versión:** ver `VERSION` (versionado semántico). **Autores:** `autores.txt`.

## Endpoints

Todas las respuestas son JSON. Los errores tienen la forma `{"error": "codigo", "mensaje": "...", "errores": {"campo": "mensaje"}}`.

| Método y ruta | Acceso | Descripción |
|---|---|---|
| `POST /api/registro` | público | Crea una cuenta (`nombre`, `email`, `password`, `password_confirm` opcional). Rol siempre `usuario`. → 201 |
| `POST /api/login` | público | `email`, `password` → `{csrf, expira_en, usuario}` y la cookie de sesión (`Set-Cookie`). La cuenta de un taller con la solicitud pendiente o rechazada → 403 `taller_pendiente` o `taller_rechazado` (con el motivo). Más de 5 intentos en 15 minutos con el mismo email → 429 `demasiados_intentos` con `Retry-After`, aunque la contraseña sea la correcta |
| `POST /api/logout` | público | Borra la cookie de sesión → 204 |
| `GET /api/me` | sesión | `{usuario, csrf}` del usuario autenticado |
| `PUT /api/me` | sesión | Cambia el nombre (`{nombre}`, 2 a 80 caracteres) → `{usuario}`. El email no se cambia |
| `POST /api/me/foto` | sesión | Sube o reemplaza la foto de perfil (multipart, campo `foto`: JPG, PNG o WebP de hasta 2 MB) → `{usuario}` con `foto` |
| `DELETE /api/me/foto` | sesión | Borra la foto de perfil → 204 |
| `GET /api/usuarios/{id}/foto` | público | La foto de perfil (se ve en el detalle de sus publicaciones y en el chat). 404 si no tiene. `usuario.foto`, `vendedor.foto` y `con.foto` traen esta ruta con `?v=` (o `null`) |
| `GET /api/vehiculos` | público | Listado. Filtros: `q`, `marca`, `combustible`, `precio_max`, `page` |
| `GET /api/marcas-modelos` | público | Marcas y modelos que se pueden publicar: `{marcas: [{marca, modelos: [...]}]}`. Publicar o corregir con una marca o un modelo que no está en la lista → 422 (y la base tampoco lo acepta) |
| `GET /api/vehiculos/{id}` | público | Detalle con fotos y `vendedor: {nombre, desde}` (sin el email: es una página pública) |
| `GET /api/vehiculos/{id}/comparacion` | público | Precio frente a autos iguales de MercadoLibre, Kavak y Autocosmos: `buen_precio`, `en_precio`, `caro` o `sin_datos`, con mediana, ahorro y autos parecidos. Detalle en [docs/arquitectura-scraper.md](docs/arquitectura-scraper.md#5-cómo-decide-el-comparador). 503 si el almacén no responde |
| `GET /api/marcas` | público | Marcas existentes (para armar el filtro) |
| `GET /api/mis-vehiculos` | sesión | Las publicaciones propias (menos las eliminadas), con `estado`, `motivo_rechazo`, `completa` y `faltan` |
| `POST /api/mis-vehiculos` | sesión | **Publicar por partes** (multipart/form-data). Alcanza con `marca`, `modelo` y `anio`; `precio`, `kilometraje`, `combustible`, `transmision`, `condicion`, `localidad`, el resto de los campos y `fotos[]` (hasta 5, JPG/PNG/WebP, 2 MB c/u) se pueden mandar ahora o después. Lo que viene se valida. Nace `pendiente` → 201 `{vehiculo}` con `completa` y `faltan` (lo que le falta para que el moderador la pueda aprobar) |
| `GET /api/mis-vehiculos/{id}` | sesión (dueño) | Una publicación propia con todas sus `fotos` (para seguir completándola). De otro → 404 |
| `PUT /api/mis-vehiculos/{id}` | sesión (dueño) | Completa o edita (JSON, sin fotos): lo que no se manda queda igual. Vuelve a `pendiente`. Vendida → 409. Un campo que llega como arreglo u objeto → 422 y no se toca lo guardado |
| `POST /api/mis-vehiculos/{id}/fotos` | sesión (dueño) | Agrega `fotos[]` (multipart) a las que ya tiene, hasta 5 entre todas → 201. Vuelve a `pendiente` |
| `DELETE /api/mis-vehiculos/{id}/fotos/{foto}` | sesión (dueño) | Saca una foto. Vuelve a `pendiente` |
| `PATCH /api/mis-vehiculos/{id}/estado` | sesión (dueño) | `{"estado":"vendido"}`, solo desde `disponible` |
| `DELETE /api/mis-vehiculos/{id}` | sesión (dueño) | Baja lógica → 204 |
| `POST /api/vehiculos/{id}/conversacion` | sesión | Abre (o recupera) el chat con el vendedor de ese vehículo → 201 si es nueva, 200 si ya existía: `{conversacion: {id}}`. Sobre la publicación propia → 409 `vehiculo_propio`; sin vendedor (cargado por el admin) → 409 `sin_vendedor` |
| `POST /api/contacto` | público | `{email, mensaje}` (10 a 1000 caracteres) → 201. Hasta 3 por hora por email (429) |
| `POST /api/reportes` | sesión | Reporta una publicación (`vehiculo_id`) o un usuario (`usuario_id`): `motivo` (`fraude`, `datos`, `vendido`, `trato`, `spam`, `otro`) y `detalle` opcional → 201 |
| `GET /api/admin/contactos` · `PATCH /api/admin/contactos/{id}` | `usuarios.ver` (admin) | Mensajes de contacto (no leídos primero) · marcar leído → 204 |
| `GET /api/admin/reportes` · `PATCH /api/admin/reportes/{id}` | `vehiculos.moderar` | Reportes (pendientes primero) · marcar resuelto → 204 |
| `GET /api/favoritos` | sesión | Favoritos del usuario: `{items}` con las mismas fichas que el catálogo, el último guardado primero. Solo los publicados (disponibles o vendidos) |
| `PUT /api/favoritos/{id}` | sesión | Guarda el vehículo en favoritos → 204 (guardarlo dos veces no da error). 404 si no está disponible; 409 `vehiculo_propio` si es una publicación propia |
| `DELETE /api/favoritos/{id}` | sesión | Lo saca de favoritos → 204 |
| `GET /api/conversaciones` | sesión | Bandeja de chats del usuario, la de actividad más reciente primero |
| `GET /api/conversaciones/{id}/mensajes?desde=N` | sesión (participante) | Mensajes con id mayor a `N` (sin `desde`, todos) → `{items, ultimo_id, abierta}`. Los que devuelve quedan leídos. El front guarda `ultimo_id` y pregunta cada tanto por los nuevos. De otro → 404 |
| `POST /api/conversaciones/{id}/mensajes` | sesión (participante) | `{"texto": "..."}` (hasta 1000 caracteres) → 201 `{mensaje}`. Si la publicación se eliminó → 409 `conversacion_cerrada` |
| `GET /api/fotos/{id}` | público / dueño | La foto. Las de publicaciones visibles son públicas; las de una pendiente o rechazada solo las ven el dueño y el moderador (si no, 404) |
| `GET /api/admin/vehiculos?estado=pendiente&completa=1` | `vehiculos.moderar` o `vehiculos.gestionar` | Listado / cola de moderación. `completa=1`: solo las que se pueden aprobar; `completa=0`: las que el vendedor todavía está completando |
| `GET /api/admin/vehiculos/{id}` | `vehiculos.moderar` o `vehiculos.gestionar` | Detalle con fotos y quién lo publicó |
| `PATCH /api/admin/vehiculos/{id}/estado` | `vehiculos.moderar` (moderador) | Aprobar (`{"estado":"disponible"}`) o rechazar con `motivo`. Una incompleta no se aprueba: 409 `incompleta` con lo que falta (rechazarla sí se puede). La base también lo controla: una visible tiene que tener precio, kilometraje, combustible y transmisión. Si otro moderador la cambió mientras tanto → 409 `estado_cambiado` (gana la primera decisión) |
| `DELETE /api/admin/vehiculos/{id}` | `vehiculos.gestionar` (admin) | Baja lógica → 204. El panel no crea ni edita vehículos: toda publicación tiene un vendedor (la base lo exige), entra por `POST /api/mis-vehiculos` y la corrige solo su dueño. `POST /api/admin/vehiculos` y `PUT /api/admin/vehiculos/{id}` → 405 |
| `GET /api/admin/usuarios?q=&rol=&activo=1\|0&page=` | `usuarios.ver` (admin) | Usuarios con rol, estado y cantidad de publicaciones |
| `GET /api/admin/usuarios/{id}` | `usuarios.ver` | Detalle |
| `PATCH /api/admin/usuarios/{id}` | `usuarios.editar` (admin) | `{nombre?, rol?, activo?}`. No se puede cambiar el propio rol ni darse de baja (409) |
| `DELETE /api/admin/usuarios/{id}` | `usuarios.editar` | Baja lógica (`activo = false`): no puede ingresar y su sesión deja de valer → 204 |
| `GET /api/admin/roles` | `usuarios.ver` | Roles con sus permisos |
| `GET /api/admin/metricas` | `metricas.ver` (admin) | Usuarios (activos, con actividad en 30 días, por rol), publicaciones (por estado, nuevas, precio promedio, valor en venta y vendido, marcas top), moderación y chat. Ganancias: no hay cobros todavía Incluye `ganancias` de las inspecciones (pago simulado): facturado, ganancia de AutoCar, pagado a mecánicos, fondo de garantía (acumulado, usado, disponible), retenido y reembolsado |
| `GET /api/meta-busquedas` | sesión | Búsquedas guardadas del usuario, con autos que coinciden y avisos sin ver |
| `POST /api/meta-busquedas` | sesión | Crea una búsqueda: `marca` y `modelo` obligatorios; `anio_desde`, `anio_hasta`, `precio_max`, `km_max` y `transmision` opcionales. Máximo 20 por usuario (409) → 201 |
| `GET /api/meta-busquedas/conteo` | sesión | Cuántos autos coinciden hoy con esos criterios (los mismos que al crear), sin guardar nada: `{autos, por_fuente}`. Lo usa "Nueva búsqueda" mientras se eligen los filtros. 422 si los criterios no son válidos |
| `GET /api/meta-busquedas/{id}` | sesión (dueño) | Reporte: referencia de precio, novedades, destacados, resultados y otros modelos. Listado: `fuente`, `etiqueta`, `precio_max`, `km_max`, `orden` (`precio`, `km`, `nuevos`), `page` |
| `PUT /api/meta-busquedas/{id}` | sesión (dueño) | Modifica los criterios |
| `DELETE /api/meta-busquedas/{id}` | sesión (dueño) | Borra la búsqueda y sus avisos → 204 |
| `GET /api/meta-busquedas/avisos?sin_ver=1` | sesión | Últimos 100 avisos del usuario: auto nuevo, baja de precio, reservado o retirado |
| `POST /api/meta-busquedas/{id}/avisos/vistos` | sesión (dueño) | Marca como vistos los avisos de esa búsqueda → 204 |
| `GET /api/autos-externos?id=` | sesión | Detalle de un auto de otra plataforma: ficha, etiqueta de precio, comparables, historial y link al original. El `id` es texto (`fuente~modelo~id`, ej. `kavak~renault_sandero~562339`), el mismo que viene en los resultados |
| `GET /api/mecanicos?localidad=` | público | Ranking de la red: taller, dirección, precio con su desglose, inspecciones firmadas, reclamos que procedieron, `puntaje` (o `nuevo: true` con menos de 3 firmadas) y `localidades` para el filtro. Sin teléfono |
| `POST /api/inspecciones` | sesión | Pedir una inspección: `url` del aviso (solo MercadoLibre, Kavak, Autocosmos o AutoCar: los de otros sitios dan 422) y `mecanico_id`; `titulo`, `anio`, `kilometraje`, `precio_publicado` y `localidad` opcionales (si el link es de AutoCar salen de la publicación). Pago simulado `retenido` → 201. 409: `mecanico_propio`, `vehiculo_propio`, `mecanico_vendedor`, `duplicada` (ya hay un pedido en curso de ese aviso, aunque sea con otro link; lo controla un índice único, así que vale también con pedidos simultáneos) |
| `GET /api/mis-inspecciones` | sesión | Las inspecciones que pidió el usuario, con el mecánico, el reclamo y `puede_reclamar` |
| `GET /api/inspecciones/{id}` | sesión | Detalle de una inspección con el checklist. La ven el comprador, el mecánico asignado y quien tiene `reclamos.resolver`; para el resto, 404 |
| `POST /api/inspecciones/{id}/cancelar` | sesión (comprador) | Solo antes de que el mecánico la acepte; pago `reembolsado`. Si no, 409 |
| `POST /api/inspecciones/{id}/reclamo` | sesión (comprador) | `{descripcion, item_codigo?}`, una vez, hasta 15 días después de la firma → 201. 409: `estado_invalido`, `reclamo_existente`, `fuera_de_plazo` |
| `GET /api/admin/reclamos?estado=abierto` | `reclamos.resolver` (moderador y admin) | Cola de reclamos con la inspección, el mecánico, el comprador y el `tope` de la compensación |
| `PATCH /api/admin/reclamos/{id}` | `reclamos.resolver` | `{procede: true, compensacion, respuesta}` (compensación hasta 3 veces el precio; el pago queda `reembolsado` y el mecánico baja en el ranking) o `{procede: false, respuesta}`. Ya resuelto → 409; sobre una inspección propia → 409 `interes_propio` |
| `GET /api/mecanico/perfil` | `verificaciones.realizar` (mecánico) | `{taller}` o `{taller: null}` si todavía no completó el perfil |
| `PUT /api/mecanico/perfil` | `verificaciones.realizar` | Alta o modificación del taller: `nombre`, `direccion`, `localidad`, `telefono`, `precio_inspeccion` (entre $ 1.000 y $ 10.000.000, sin centavos) |
| `GET /api/mecanico/inspecciones?estado=` | `verificaciones.realizar` | Sus inspecciones (de un estado o todas) y `conteos` por estado |
| `PATCH /api/mecanico/inspecciones/{id}` | `verificaciones.realizar` | Desde `pedida`: `{"accion":"aceptar","fecha_turno":"ISO 8601"}` (desde ahora y hasta 60 días; hace falta el perfil, si no 409 `sin_taller`) o `{"accion":"rechazar","motivo":"..."}` (pago `reembolsado`). Desde `aceptada`: `{"accion":"no_realizada","motivo":"..."}` cuando fue y no la pudo hacer, por ejemplo porque el vendedor no dejó revisar el auto (pago `reembolsado`, el motivo queda en `motivo_rechazo`). Otro estado → 409 |
| `PUT /api/mecanico/inspecciones/{id}/informe` | `verificaciones.realizar` | Borrador del checklist `{items: [{codigo, resultado, nota}], conclusion?, resumen?}`, se puede a medias. Solo con la inspección `aceptada`; firmada → 409 `firmada` |
| `POST /api/mecanico/inspecciones/{id}/firma` | `verificaciones.realizar` | Firma lo guardado: exige los 10 ítems y la conclusión (si no, 422 `informe_incompleto`). Guarda el nombre del mecánico y del taller, el momento y la huella SHA-256; pago `liberado` |
| `POST /api/solicitudes-taller` | público | "Adherí tu taller". Crea la **cuenta del taller**, aparte de la cuenta personal: `responsable`, `email`, `password` y los datos del taller (`nombre`, `cuit` en formato `30-12345678-9` con o sin guiones, `direccion`, `localidad`, `telefono`, `horarios`, `precio_inspeccion`, `condiciones: true`) → 201 `{solicitud}`. La cuenta nace con rol `mecanico`, pero no puede ingresar ni aparece en el ranking hasta que un admin la aprueba. Si el email ya tiene una cuenta → 422 en `email` (un email es una sola cuenta). Si ya hay una solicitud pendiente con ese email → 409. Si estaba rechazada, se vuelve a mandar con la misma contraseña (con otra → 422 en `password`) |
| `GET /api/admin/solicitudes-taller?estado=pendiente` | `usuarios.editar` (admin) | Cola de solicitudes con el responsable, su email y su rol |
| `PATCH /api/admin/solicitudes-taller/{id}` | `usuarios.editar` | `{id}` = usuario. `{aprobar: true}`: el taller entra al ranking y su cuenta ya puede ingresar. `{aprobar: false, motivo}`: queda rechazada con el motivo (5 a 300 caracteres). Ya resuelta → 409 |
| `GET /health` | público | Chequeo de vida (no toca la base) |

Las rutas con sesión o con permiso que cambian datos (`POST`, `PUT`, `PATCH`, `DELETE`) exigen además el header `X-CSRF-Token` con el valor `csrf` que devolvió el login.

Códigos: `400` JSON inválido, `401` sin sesión o sesión vencida, `403` sin el permiso o token CSRF incorrecto (o, en el login, cuenta dada de baja o taller todavía no aprobado), `404` no existe, `405` método no permitido, `422` validación, `500` error del servidor.

Campos de un vehículo: `marca`, `modelo`, `anio`, `precio`, `kilometraje`, `combustible` (`nafta|diesel|gnc|hibrido|electrico`), `transmision` (`manual|automatica`), `estado` (`disponible|vendido`), `descripcion` (opcional), `imagen_url` (opcional, URL http/https).

### Ejemplos

```
# -c guarda la cookie de sesión en cookies.txt; la respuesta trae el "csrf"
curl -X POST http://localhost:8000/api/login -c cookies.txt -H "Content-Type: application/json" \
     -d '{"email":"tu@email.com","password":"Clave1234"}'

curl http://localhost:8000/api/vehiculos?q=toyota&combustible=nafta

# -b manda la cookie; CSRF = el valor "csrf" del login
curl -X POST http://localhost:8000/api/mis-vehiculos -b cookies.txt -H "X-CSRF-Token: CSRF" \
     -F marca=Ford -F modelo=Focus -F anio=2019
```

### Cómo lo usa el front (Vercel)

El front es HTML, CSS y JavaScript nativo, sin build. **Publicado, no llama a Render directamente**: pide `/api/...` a su propio dominio y Vercel reenvía esos pedidos a Render (archivo `vercel.json` del repo del front). Así la cookie de sesión queda como cookie del propio sitio; si el front llamara a `onrender.com`, sería una cookie de terceros y Safari (y otros navegadores) la bloquean. En local llama a `http://localhost:8000` (ver `js/api/config.js`).

```js
// En el front: API_URL de js/api/config.js ('' publicado, 'http://localhost:8000' en local)
const r = await fetch(`${API}/api/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  credentials: 'include',                    // guarda la cookie que manda el back
  body: JSON.stringify({ email, password }),
});
const { csrf } = await r.json();             // el id de sesión NO viene en el cuerpo: está en la cookie HttpOnly

await fetch(`${API}/api/admin/vehiculos/3`, {
  method: 'DELETE',
  credentials: 'include',                    // manda la cookie
  headers: { 'X-CSRF-Token': csrf },         // obligatorio en POST, PUT y DELETE con sesión
});
```

Para cerrar sesión: `POST /api/logout` (JavaScript no puede borrar una cookie `HttpOnly`).

## Estructura

```
public/            única carpeta expuesta: index.php (front controller) y .htaccess
composer.json      sin librerías de terceros: solo el autoload PSR-4: namespace AutoCar\ → src/ (AutoCar\Core\Router = src/Core/Router.php)
src/bootstrap.php  autoload de Composer, errores, CORS y cabeceras
src/routes.php     todas las rutas, con su guard (auth / permiso:...)
src/Core/          Config, Database (PDO), Router, Middleware (guards de las rutas), Request, Response, Cors, Logger
src/Auth/          Auth (sesión, cookie y CSRF), SesionDb (guarda $_SESSION en la base), Usuario, AuthController
src/Catalogo/      Vehiculo (modelo + validación), CatalogoController (API pública)
src/Admin/         AdminVehiculoController (ABM y moderación), AdminUsuarioController (usuarios), AdminMetricasController (métricas)
src/MetaBusqueda/  búsquedas guardadas, importador, comparables, novedades y reporte
src/Comparador/    comparador de precios: documento por modelo, comparación y almacén (archivos o Firestore)
bin/               importar-autos.php (metabuscador), armar-modelos.php, comparar.php y medir-cambios.php (comparador)
database/          schema.sql, seed.sql y las migraciones para bases que ya existían (PostgreSQL)
recolectores/      MercadoLibre, Kavak y Autocosmos con curl, cada una en su carpeta (no se despliegan): recolectores/README.md
storage/logs/      app.log (fuera de public/)
vendor/            librerías de Composer (se genera, no se sube al repo)
```

## Requisitos

- PHP **8.2 o superior** con las extensiones `pdo_pgsql` y `mbstring` (en XAMPP: descomentar `extension=pdo_pgsql` y `extension=pgsql` en `php.ini`).
- [**Composer**](https://getcomposer.org) 2.x.
- Una base **PostgreSQL**. Se usa [Neon](https://neon.com) (plan Free); en local puede ser la misma base de Neon (rama `dev`) o un Postgres propio.

## Entorno de prueba

Versiones con las que se desarrolló y probó (lo pide la guía de entrega). En local la base corre en Docker y en producción el back corre en la imagen Docker del `Dockerfile`, así que esas versiones son las mismas en cualquier máquina.

| | Local (desarrollo) | Producción |
|---|---|---|
| Sistema operativo | Windows 11 Pro (build 26200) | Debian (imagen `php:8.3-apache`) en Render |
| PHP | 8.4.10 CLI, con `pdo_pgsql`, `mbstring` y `fileinfo` | 8.3.36 + Apache |
| Composer | 2.10.3 | 2.11.1 (imagen `composer:2`) |
| PostgreSQL | 17.11 en Docker (imagen `postgres:17`) | Neon (PostgreSQL administrado) |
| Docker | Docker Desktop 4.92.0 (Engine 29.8.0, Compose v5.5.1) | — |
| Git | 2.55.0 | — |
| Navegadores | Chrome 156 y Edge 156 | — |

El código sólo usa funciones de PHP 8.2 o anteriores, así que la diferencia entre 8.4 (local) y 8.3 (producción) no afecta.

## Base de datos (Neon)

1. Crear el proyecto en Neon. En la consola, abrir el **SQL Editor** de la rama `production`, pegar el contenido de `database/schema.sql` y ejecutarlo. Después hacer lo mismo con `database/seed.sql` (datos de prueba).
   Si la base ya existía de una entrega anterior, en vez de `schema.sql` correr las migraciones en orden: `001-e4.sql`, `002-nombres.sql`, `003-chat.sql`, `004-sesion.sql`, `005-roles.sql`, `006-inspecciones.sql`, `008-separar-version.sql`, `009-solicitud-taller.sql` y `010-publicar-por-partes.sql` (todas se pueden correr más de una vez). Solo en dev, para tener mecánicos de ejemplo: `007-demo-inspecciones-solo-dev.sql`.
   **Migraciones nuevas:** formato obligatorio `NNN-nombre.sql` con el número que sigue (`008-algo.sql`), idempotentes y con el mismo cambio en `schema.sql`. Reglas completas en [`database/README.md`](database/README.md); una prueba hace fallar el CI si el nombre no cumple.
2. Botón **Connect** → desactivar **Connection pooling** → copiar host, base (`neondb`), usuario y contraseña. Esos valores van a `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASS` (`DB_PORT` es 5432).
3. Para desarrollar sin tocar producción: **Create child branch** (por ejemplo `dev`) y usar sus datos en el `.env` local.

Neon usa certificados públicos: no hace falta ningún `ca.pem`; la conexión usa `sslmode=require`.
Plan Free: el cómputo se suspende tras 5 minutos sin uso y la primera consulta después tarda un poco más.

## Levantarla en local

En local se usa una base PostgreSQL propia en Docker (`docker-compose.yml`), sin tocar Neon. Requiere Docker Desktop abierto.

1. Instalar las dependencias: `composer install` (crea la carpeta `vendor/`).
2. Levantar la base: `docker compose up -d`. La primera vez carga `database/schema.sql` y `database/seed.sql`. Queda en el puerto **5433** (para no chocar con un Postgres instalado en 5432).
3. Copiar `.env.example` como `.env` con estos datos:
   ```
   DB_HOST=127.0.0.1
   DB_PORT=5433
   DB_NAME=autocar
   DB_USER=autocar
   DB_PASS=autocar
   DB_SSLMODE=disable
   ```
4. Iniciar el servidor:
   ```
   php -S localhost:8000 -t public public/index.php
   ```
5. Probar: http://localhost:8000/api/vehiculos

Apagar la base: `docker compose down` (los datos quedan). Empezar de cero: `docker compose down -v` y de nuevo `docker compose up -d`.
Los errores quedan en `storage/logs/app.log` y en la terminal del servidor.

### Crear el primer administrador

El registro público siempre crea usuarios con rol `usuario`. Para tener un admin, registrarse con `POST /api/registro` y después ejecutar en la base (en local: `docker exec -it autocar-db psql -U autocar -d autocar`; en producción: SQL Editor de Neon):

```sql
UPDATE usuario SET rol_id = (SELECT id FROM rol WHERE nombre = 'admin') WHERE email = 'tu@email.com';
```

No hace falta volver a hacer login: el rol y sus permisos se consultan en la base en cada pedido. Los demás roles (moderador, mecánico) los asigna ese admin con `PATCH /api/admin/usuarios/{id}`.

## Despliegue (Render + Neon)

Hay dos servicios de Render, uno por entorno. Cada uno despliega desde su rama y usa su propia branch de Neon. El detalle completo está en `infraestructura.md` del repo del front.

| | Dev | Producción |
|---|---|---|
| Servicio de Render | `autocar-backend-dev` | `autocarbackend-1` |
| Rama del repo | `dev` | `main` |
| Branch de Neon | `dev` | `main` / `production` |

1. Base creada e importada en Neon (ver arriba). Para dev, crear la branch `dev` desde la de producción y correr ahí primero las migraciones nuevas.
2. **Render → New → Web Service**, elegir el repo (runtime Docker, plan Free) y la **rama** del entorno. El `Dockerfile` ejecuta `composer install --no-dev` solo; hay que subir `composer.json` y `composer.lock` al repo, pero no `vendor/`.
3. En *Environment* cargar `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` y `DB_SSLMODE=require` con los datos de la branch de Neon de ese entorno. `SESION_TTL` y `FRONT_URL` son opcionales.
4. El front no llama a Render directo: su `middleware.js` reenvía `/api/*` a la variable `BACKEND_URL` de Vercel. Si cambia la URL de un servicio de Render, hay que actualizar `BACKEND_URL` en Vercel (Preview para dev, Production para main) y volver a desplegar el front.
5. Cada merge a la rama del entorno redespliega solo. Errores: pestaña **Logs** de Render. `GET /health` responde sin tocar la base.

## Pruebas

```
composer install                                   # incluye PHPUnit (require-dev)
vendor/bin/phpunit --testsuite unit                # unitarias: sin base ni red
docker compose --profile test up -d db-test        # Postgres descartable en el puerto 5434
vendor/bin/phpunit --testsuite integration         # API real (php -S) contra esa base
```

- **Unitarias** (`tests/Unit`): validaciones de vehículos y fotos, `Config`, `Request`, la duración de la sesión y la Meta-búsqueda entera (importar corridas, novedades, avisos y reporte) sobre documentos en memoria. Una prueba falla si alguien vuelve a poner las tablas del metabuscador en `schema.sql`.
- **Integración** (`tests/Integration`): cada corrida borra y recrea la base `autocar_test` con `schema.sql` + `seed.sql`, levanta la API en un puerto libre y le hace pedidos HTTP reales. Cubren registro, login y logout con la cookie y el CSRF, el ABM del admin, los permisos (401 y 403), las validaciones (422), CORS y la Meta-búsqueda con corridas simuladas (los documentos van a una carpeta temporal que comparten la prueba y la API). Se niegan a correr si la base apunta a Neon o si su nombre no termina en `_test`.
- **CI** (`.github/workflows/ci.yml`): en cada PR a `dev` o `main` corre las dos suites contra un `postgres:17` propio y verifica que la imagen de Docker siga construyendo.

Plan Free de Render: el servicio se duerme tras ~15 min sin visitas y la primera respuesta tarda cerca de un minuto.

## Meta-búsqueda

El usuario guarda qué auto busca y AutoCar sigue las publicaciones de MercadoLibre, Kavak y Autocosmos, las compara y le avisa cuando hay novedades. Los endpoints piden sesión iniciada; la restricción por plan todavía no está aplicada, porque los planes no existen en la base.

**Todo lo del metabuscador está en una base NoSQL (Firestore), no en Neon:** los autos de otros sitios con su historial, las búsquedas guardadas y los avisos. Neon solo aporta los vehículos publicados en AutoCar. Detalle de las colecciones en [`docs/arquitectura-scraper.md`](docs/arquitectura-scraper.md#6-metabuscador-nosql).

```
Recolectores → storage/mercado/{fuente}/{fecha}.json → bin/importar-autos.php → Firestore (mercado/{modelo}) → API
```

**Preparación.** Variables `MERCADO_ALMACEN=firestore` y `FIRESTORE_CREDENCIALES_JSON` en Render (ver `.env.example`). Sin eso, el almacén es `archivos` (`storage/mercado/docs`), que sirve en local. Si Firestore no responde, los endpoints del metabuscador devuelven 503 y el resto de la API sigue andando.

**Importar a mano.** El `.env` define a qué almacén se importa:

```
php bin/importar-autos.php storage/mercado/kavak/2026-11-08.json [otro.json ...]
php bin/importar-autos.php --hoy        # la corrida de hoy de cada fuente
```

Una corrida se marca como incompleta si el recolector lo indica, si tiene algún auto inválido o si trae menos del 80% de los autos de la anterior. Esas corridas se guardan, pero no se usan para calcular ni retiran autos ni generan avisos. Una fecha igual o anterior a la última se rechaza.

**Marcas que no están en el catálogo.** El importador compara la marca de cada auto con el catálogo (`MARCAS_URL`, la API pública `GET /api/marcas-modelos` del back del mismo entorno; sin esa variable, la tabla `marca` de la base). No cuenta mayúsculas, acentos, signos ni apodos ("VW" = Volkswagen). Si la marca no está, el auto no se carga, se avisa en la salida y, como sigue publicado, no se da por retirado. Para ver solo esos autos sin importar nada:

```
php bin/importar-autos.php --revisar-marcas storage/mercado/mercadolibre/2026-11-10.json
```

Si es una marca nueva, se agrega a la tabla `marca` con una migración y sus autos entran en la carga siguiente. Si es una del catálogo mal escrita por el sitio, se suma como apodo en `Clave::MARCAS`.

**Cómo se calcula.** Los valores son un punto de partida para ajustar con datos reales, y están como constantes en `src/MetaBusqueda`.

| Dato | Regla |
|---|---|
| Precio típico y rango | Mediana y percentiles 25 y 75 de los autos que coinciden, por plataforma. Con menos de 5, no se informan |
| Comparables | Misma plataforma y moneda, mismo modelo, año ±2 y km ±30%. Si no llegan a 5: año ±3 con el mismo filtro de km. Si tampoco, "datos insuficientes" |
| Etiqueta | `barato` a 7% o más bajo la mediana de comparables, `caro` a 7% o más sobre ella; `normal` en el medio y `datos_insuficientes` sin comparables |
| Mejor relación | Menor puntaje: diferencia % de precio + 0,2 × diferencia % de km, frente a sus comparables |
| Más barato / menos usado | Menor precio / menos km entre los resultados con precio |
| Novedades | Comparan las dos últimas corridas completas de cada plataforma: nuevo, bajó, subió, reservado, retirado |
| Avisos | Se generan al importar, salvo las subas, que solo se informan en el reporte |
| Otros modelos | Modelo distinto, precio entre 90% del P25 y el máximo de la búsqueda (o 110% del P75), km ±20% del típico y año ±2. No afectan estadísticas, etiquetas, destacados ni avisos |

Marca y modelo coinciden por la clave del modelo, que no distingue mayúsculas, acentos ni guiones y entiende "VW" como "Volkswagen": "Sandero" no incluye "Sandero Stepway". El historial de un auto guarda un renglón por cada cambio de precio, km o reserva. El precio máximo está en pesos. Los autos de AutoCar aparecen en los resultados con `fuente: "autocar"` y su `id` es el del vehículo, pero no llevan etiqueta ni compiten en los destacados. Todos los precios son publicados, no de venta.

## Seguridad aplicada

- **Validación en el backend** de todo lo que entra: cuerpos JSON (`Vehiculo::validar`, `AuthController`), filtros de la URL y tipos de datos.
- **Consultas parametrizadas** con PDO (`ATTR_EMULATE_PREPARES` desactivado); los comodines `%` y `_` de la búsqueda se escapan.
- **Contraseñas** con `password_hash` / `password_verify` (bcrypt); nunca se guardan ni se devuelven en claro.
- **Sesión de PHP en la base** (`src/Auth/Auth.php` y `SesionDb.php`): `$_SESSION` se guarda en la tabla `sesion` y no en archivos, porque el disco de Render se borra en cada deploy. `session.use_strict_mode` rechaza ids inventados por el cliente, y el login hace `session_regenerate_id(true)`: id nuevo y la sesión vieja se borra (evita fijación de sesión). Vence a las 2 horas (`SESION_TTL`).
- **Logout real**: `$_SESSION = []`, cookie vencida y `session_destroy()`, que borra la fila. El id deja de servir en el servidor aunque alguien lo hubiera copiado.
- **Cookie de sesión segura**: `autocar_sesion` lleva solo el id, con `HttpOnly` (JavaScript no la puede leer, así un XSS no puede robar la sesión), `Secure` (solo viaja por HTTPS; en local con http no se pone) y `SameSite=Strict` (el navegador no la manda en pedidos que vienen de otro sitio).
- **Protección CSRF**: además de `SameSite`, toda ruta con sesión que cambia datos (`POST`, `PUT`, `DELETE`) exige el header `X-CSRF-Token` (`Router` → `Auth::requireCsrf`). El token se genera al azar en el login (`random_bytes(32)`), se guarda en `$_SESSION` y se compara con `hash_equals`. Otro sitio puede hacer que el navegador mande la cookie, pero no puede conocer el token.
- **Permisos en el servidor**: cada ruta de `/api/admin` pide un permiso en `src/routes.php` (`permiso:vehiculos.moderar`, `permiso:usuarios.editar`, ...). `Auth::requirePermiso()` lee de la base los permisos del rol del usuario en cada pedido (el rol no se guarda en la sesión), así un cambio de rol o una baja surten efecto al instante. Las reglas de "es tuyo" (tus publicaciones, tus conversaciones) se controlan aparte, comparando el id de la sesión con el dueño.
- **CORS** restringido a los orígenes de `FRONT_URL` (en producción el front usa el proxy de Vercel y no hace falta; sirve para desarrollo local).
- **Errores**: `display_errors=0`; se registran en `storage/logs/app.log` y el cliente recibe un 500 genérico. Lo que es culpa del dato no es un 500: un id que no entra en `INTEGER` da 404, la página tiene tope y un texto que la base rechaza (no UTF-8, byte nulo, número fuera de rango: errores de clase 22 de PostgreSQL) da 400 `dato_invalido`.
- **Límite de intentos de ingreso**: 5 cada 15 minutos por email (`Auth\IntentosLogin`, tabla `login_intento`). Cada intento se anota antes de verificar la contraseña, así que pedidos simultáneos tampoco lo saltean. Se cuenta por email y no por IP porque todo el front llega por el proxy de Vercel.
- **Sin versiones en las cabeceras**: `expose_php = Off`, `ServerTokens Prod` y `ServerSignature Off` en el `Dockerfile` (las respuestas dicen `Server: Apache`, sin versión ni sistema operativo, y no mandan `X-Powered-By`).
- **Cambios de estado concurrentes**: los `UPDATE` de estado exigen el estado que se leyó (`... AND estado = :desde`). Si dos personas deciden a la vez, gana la primera y la segunda recibe 409.
- Login sin pistas: mismo mensaje y mismo costo si el email no existe o la contraseña es incorrecta.

## Entrega

```
git tag entrega-3
git push origin entrega-3
```

Subir a Classroom el link del repositorio y la URL desplegada (todos los integrantes).
