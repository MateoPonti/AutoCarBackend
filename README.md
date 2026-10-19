# AutoCar Backend (API JSON)

API en **PHP vanilla + PostgreSQL** de AutoCar (TP Integrador, Programación en Ambiente Web, UNLu).
Compra y venta de vehículos. Entidad principal: **vehículos**. El front (Vercel) consume esta API.

- **URL desplegada:** https://autocarbackend-1.onrender.com *(HTTPS)*
- **Roles:** `usuario` (registrado) y `admin` (gestiona vehículos).
- **Autenticación:** JWT (HS256) en el header `Authorization: Bearer <token>`.

## Endpoints

Todas las respuestas son JSON. Los errores tienen la forma `{"error": "codigo", "mensaje": "...", "errores": {"campo": "mensaje"}}`.

| Método y ruta | Acceso | Descripción |
|---|---|---|
| `POST /api/registro` | público | Crea una cuenta (`nombre`, `email`, `password`, `password_confirm` opcional). Rol siempre `usuario`. → 201 |
| `POST /api/login` | público | `email`, `password` → `{token, tipo, expira_en, usuario}` |
| `GET /api/me` | token | Datos del usuario autenticado |
| `GET /api/vehiculos` | público | Listado. Filtros: `q`, `marca`, `combustible`, `precio_max`, `page` |
| `GET /api/vehiculos/{id}` | público | Detalle |
| `GET /api/marcas` | público | Marcas existentes (para armar el filtro) |
| `GET /api/admin/vehiculos` | admin | Listado incluyendo vendidos |
| `POST /api/admin/vehiculos` | admin | Alta → 201 |
| `PUT /api/admin/vehiculos/{id}` | admin | Modificación (se envía el objeto completo) |
| `DELETE /api/admin/vehiculos/{id}` | admin | Baja → 204 |
| `GET /health` | público | Chequeo de vida (no toca la base) |

Códigos: `400` JSON inválido, `401` sin token o token inválido, `403` sin rol admin, `404` no existe, `405` método no permitido, `422` validación, `500` error del servidor.

Campos de un vehículo: `marca`, `modelo`, `anio`, `precio`, `kilometraje`, `combustible` (`nafta|diesel|gnc|hibrido|electrico`), `transmision` (`manual|automatica`), `estado` (`disponible|vendido`), `descripcion` (opcional), `imagen_url` (opcional, URL http/https).

### Ejemplos

```
curl -X POST http://localhost:8000/api/login -H "Content-Type: application/json" \
     -d '{"email":"tu@email.com","password":"Clave1234"}'

curl http://localhost:8000/api/vehiculos?q=toyota&combustible=nafta

curl -X POST http://localhost:8000/api/admin/vehiculos -H "Authorization: Bearer TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"marca":"Ford","modelo":"Focus","anio":2019,"precio":15000000,"kilometraje":60000,"combustible":"nafta","transmision":"manual"}'
```

### Cómo lo usa el front (Vercel)

```js
const API = import.meta.env.VITE_API_URL;   // https://autocarbackend-1.onrender.com (sin barra final)

const r = await fetch(`${API}/api/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email, password }),
});
const { token } = await r.json();            // guardarlo (memoria o sessionStorage)

await fetch(`${API}/api/admin/vehiculos`, {
  headers: { Authorization: `Bearer ${token}` },
});
```

No hace falta `credentials: 'include'` (no se usan cookies). Para cerrar sesión, el front descarta el token.

## Estructura

```
public/            única carpeta expuesta: index.php (front controller) y .htaccess
src/bootstrap.php  autoload, errores, CORS y cabeceras
src/routes.php     todas las rutas, con su guard (auth / admin)
src/Core/          Config, Database (PDO), Router, Request, Response, Cors, Logger
src/Auth/          Jwt, Auth, Usuario, AuthController
src/Catalogo/      Vehiculo (modelo + validación), CatalogoController (API pública)
src/Admin/         AdminVehiculoController (ABM)
database/          schema.sql, seed.sql (PostgreSQL)
storage/logs/      app.log (fuera de public/)
```

## Requisitos

- PHP **8.1 o superior** con las extensiones `pdo_pgsql` y `mbstring` (en XAMPP: descomentar `extension=pdo_pgsql` y `extension=pgsql` en `php.ini`).
- Una base **PostgreSQL**. Se usa [Neon](https://neon.com) (plan Free); en local puede ser la misma base de Neon (rama `dev`) o un Postgres propio.

## Base de datos (Neon)

1. Crear el proyecto en Neon. En la consola, abrir el **SQL Editor** de la rama `production`, pegar el contenido de `database/schema.sql` y ejecutarlo. Después hacer lo mismo con `database/seed.sql` (datos de prueba).
2. Botón **Connect** → desactivar **Connection pooling** → copiar host, base (`neondb`), usuario y contraseña. Esos valores van a `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASS` (`DB_PORT` es 5432).
3. Para desarrollar sin tocar producción: **Create child branch** (por ejemplo `dev`) y usar sus datos en el `.env` local.

Neon usa certificados públicos: no hace falta ningún `ca.pem`; la conexión usa `sslmode=require`.
Plan Free: el cómputo se suspende tras 5 minutos sin uso y la primera consulta después tarda un poco más.

## Levantarla en local

1. Copiar `.env.example` como `.env` y completar `DB_*` y **`JWT_SECRET`** (generar con `php -r "echo bin2hex(random_bytes(32));"`).
2. Iniciar el servidor:
   ```
   php -S localhost:8000 -t public public/index.php
   ```
3. Probar: http://localhost:8000/api/vehiculos

### Crear el primer administrador

El registro público siempre crea usuarios con rol `usuario`. Para tener un admin, registrarse con `POST /api/registro` y después ejecutar en el SQL Editor de Neon:

```sql
UPDATE users SET rol = 'admin' WHERE email = 'tu@email.com';
```

Volver a hacer login para obtener un token. (El rol se consulta en la base en cada pedido a `/api/admin`.)

## Despliegue (Render + Neon)

1. Base creada e importada en Neon (ver arriba).
2. **Render → New → Web Service**, elegir el repo (runtime Docker, plan Free).
3. En *Environment* cargar: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` (Neon), `JWT_SECRET` (distinto al local) y `FRONT_URL` (URL del front en Vercel, sin barra final; varias separadas por coma).
4. En **Vercel**, variable `VITE_API_URL` (o el prefijo de tu framework) con la URL de Render, y redeploy.
5. Cada `git push` a `main` redespliega solo. Errores: pestaña **Logs** de Render. `GET /health` responde sin tocar la base.

Plan Free de Render: el servicio se duerme tras ~15 min sin visitas y la primera respuesta tarda cerca de un minuto.

## Seguridad aplicada

- **Validación en el backend** de todo lo que entra: cuerpos JSON (`Vehiculo::validar`, `AuthController`), filtros de la URL y tipos de datos.
- **Consultas parametrizadas** con PDO (`ATTR_EMULATE_PREPARES` desactivado); los comodines `%` y `_` de la búsqueda se escapan.
- **Contraseñas** con `password_hash` / `password_verify` (bcrypt); nunca se guardan ni se devuelven en claro.
- **JWT** HS256 firmado con `JWT_SECRET`, con vencimiento; se verifica la firma con `hash_equals` y se rechaza cualquier algoritmo distinto de HS256.
- **Control de rol en el servidor**: cada ruta `/api/admin` lleva el guard `admin` en `src/routes.php`, que consulta el rol en la base (el rol no viaja en el token, así que un cambio de rol o un usuario borrado surten efecto al instante).
- **CORS** restringido a los orígenes de `FRONT_URL`.
- **Sin cookies**: el token va en un header, por lo que no hay CSRF.
- **Errores**: `display_errors=0`; se registran en `storage/logs/app.log` y el cliente recibe un 500 genérico.
- Login sin pistas: mismo mensaje y mismo costo si el email no existe o la contraseña es incorrecta.

## Entrega

```
git tag entrega-3
git push origin entrega-3
```

Subir a Classroom el link del repositorio y la URL desplegada (todos los integrantes).
