# AutoCar Backend (API JSON)

API en **PHP + PostgreSQL** (con Composer) de AutoCar (TP Integrador, Programación en Ambiente Web, UNLu).
Compra y venta de vehículos. Entidad principal: **vehículos**. El front (Vercel) consume esta API.

- **URL desplegada:** https://autocarbackend-1.onrender.com *(HTTPS)*
- **Roles y permisos** (en tablas: `rol`, `permiso`, `rol_permiso`): `usuario` (publica y chatea), `moderador` (aprueba o rechaza publicaciones), `admin` (puede todo: usuarios, ABM del catálogo, moderación y métricas) y `mecanico` (verificaciones; endpoints pendientes). Las rutas piden **permisos**, no roles: un rol nuevo solo necesita filas en `rol_permiso`.
- **Autenticación:** sesión de PHP (`$_SESSION`) guardada en la tabla `sesion`; el navegador solo tiene la cookie `autocar_sesion` con el id (`HttpOnly`, `Secure`, `SameSite=Strict`) + token CSRF en el header `X-CSRF-Token`.
- **Versión:** ver `VERSION` (versionado semántico). **Autores:** `autores.txt`.

## Endpoints

Todas las respuestas son JSON. Los errores tienen la forma `{"error": "codigo", "mensaje": "...", "errores": {"campo": "mensaje"}}`.

| Método y ruta | Acceso | Descripción |
|---|---|---|
| `POST /api/registro` | público | Crea una cuenta (`nombre`, `email`, `password`, `password_confirm` opcional). Rol siempre `usuario`. → 201 |
| `POST /api/login` | público | `email`, `password` → `{csrf, expira_en, usuario}` y la cookie de sesión (`Set-Cookie`) |
| `POST /api/logout` | público | Borra la cookie de sesión → 204 |
| `GET /api/me` | sesión | `{usuario, csrf}` del usuario autenticado |
| `GET /api/vehiculos` | público | Listado. Filtros: `q`, `marca`, `combustible`, `precio_max`, `page` |
| `GET /api/vehiculos/{id}` | público | Detalle |
| `GET /api/marcas` | público | Marcas existentes (para armar el filtro) |
| `GET /api/admin/vehiculos?estado=pendiente` | `vehiculos.moderar` o `vehiculos.gestionar` | Listado / cola de moderación |
| `GET /api/admin/vehiculos/{id}` | `vehiculos.moderar` o `vehiculos.gestionar` | Detalle con fotos y quién lo publicó |
| `PATCH /api/admin/vehiculos/{id}/estado` | `vehiculos.moderar` (moderador) | Aprobar (`{"estado":"disponible"}`) o rechazar con `motivo` |
| `POST /api/admin/vehiculos` | `vehiculos.gestionar` (admin) | Alta → 201 |
| `PUT /api/admin/vehiculos/{id}` | `vehiculos.gestionar` | Modificación |
| `DELETE /api/admin/vehiculos/{id}` | `vehiculos.gestionar` | Baja lógica → 204 |
| `GET /api/admin/usuarios?q=&rol=&activo=1\|0&page=` | `usuarios.ver` (admin) | Usuarios con rol, estado y cantidad de publicaciones |
| `GET /api/admin/usuarios/{id}` | `usuarios.ver` | Detalle |
| `PATCH /api/admin/usuarios/{id}` | `usuarios.editar` (admin) | `{nombre?, rol?, activo?}`. No se puede cambiar el propio rol ni darse de baja (409) |
| `DELETE /api/admin/usuarios/{id}` | `usuarios.editar` | Baja lógica (`activo = false`): no puede ingresar y su sesión deja de valer → 204 |
| `GET /api/admin/roles` | `usuarios.ver` | Roles con sus permisos |
| `GET /api/admin/metricas` | `metricas.ver` (admin) | Usuarios (activos, con actividad en 30 días, por rol), publicaciones (por estado, nuevas, precio promedio, valor en venta y vendido, marcas top), moderación y chat. Ganancias: no hay cobros todavía |
| `GET /health` | público | Chequeo de vida (no toca la base) |

Las rutas con sesión o con permiso que cambian datos (`POST`, `PUT`, `PATCH`, `DELETE`) exigen además el header `X-CSRF-Token` con el valor `csrf` que devolvió el login.

Códigos: `400` JSON inválido, `401` sin sesión o sesión vencida, `403` sin el permiso o token CSRF incorrecto (o cuenta dada de baja, en el login), `404` no existe, `405` método no permitido, `422` validación, `500` error del servidor.

Campos de un vehículo: `marca`, `modelo`, `anio`, `precio`, `kilometraje`, `combustible` (`nafta|diesel|gnc|hibrido|electrico`), `transmision` (`manual|automatica`), `estado` (`disponible|vendido`), `descripcion` (opcional), `imagen_url` (opcional, URL http/https).

### Ejemplos

```
# -c guarda la cookie de sesión en cookies.txt; la respuesta trae el "csrf"
curl -X POST http://localhost:8000/api/login -c cookies.txt -H "Content-Type: application/json" \
     -d '{"email":"tu@email.com","password":"Clave1234"}'

curl http://localhost:8000/api/vehiculos?q=toyota&combustible=nafta

# -b manda la cookie; CSRF = el valor "csrf" del login
curl -X POST http://localhost:8000/api/admin/vehiculos -b cookies.txt -H "X-CSRF-Token: CSRF" \
     -H "Content-Type: application/json" \
     -d '{"marca":"Ford","modelo":"Focus","anio":2019,"precio":15000000,"kilometraje":60000,"combustible":"nafta","transmision":"manual"}'
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
src/Core/          Config, Database (PDO), Router, Request, Response, Cors, Logger
src/Auth/          Auth (sesión, cookie y CSRF), SesionDb (guarda $_SESSION en la base), Usuario, AuthController
src/Catalogo/      Vehiculo (modelo + validación), CatalogoController (API pública)
src/Admin/         AdminVehiculoController (ABM y moderación), AdminUsuarioController (usuarios), AdminMetricasController (métricas)
database/          schema.sql, seed.sql y las migraciones para bases que ya existían (PostgreSQL)
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
   Si la base ya existía de una entrega anterior, en vez de `schema.sql` correr las migraciones en orden: `migracion-e4.sql`, `migracion-nombres.sql`, `migracion-chat.sql`, `migracion-sesion.sql` y `migracion-roles.sql` (todas se pueden correr más de una vez).
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

- **Unitarias** (`tests/Unit`): validaciones de vehículos y fotos, `Config`, `Request` y la duración de la sesión.
- **Integración** (`tests/Integration`): cada corrida borra y recrea la base `autocar_test` con `schema.sql` + `seed.sql`, levanta la API en un puerto libre y le hace pedidos HTTP reales. Cubren registro, login y logout con la cookie y el CSRF, el ABM del admin, los permisos (401 y 403), las validaciones (422) y CORS. Se niegan a correr si la base apunta a Neon o si su nombre no termina en `_test`.
- **CI** (`.github/workflows/ci.yml`): en cada PR a `dev` o `main` corre las dos suites contra un `postgres:17` propio y verifica que la imagen de Docker siga construyendo.

Plan Free de Render: el servicio se duerme tras ~15 min sin visitas y la primera respuesta tarda cerca de un minuto.

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
- **Errores**: `display_errors=0`; se registran en `storage/logs/app.log` y el cliente recibe un 500 genérico.
- Login sin pistas: mismo mensaje y mismo costo si el email no existe o la contraseña es incorrecta.

## Entrega

```
git tag entrega-3
git push origin entrega-3
```

Subir a Classroom el link del repositorio y la URL desplegada (todos los integrantes).
