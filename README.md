# AutoCar Backend (API JSON)

API en **PHP vanilla + MySQL** de AutoCar (TP Integrador, Programación en Ambiente Web, UNLu).
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
database/          schema.sql, seed.sql (y ca.pem de Aiven)
storage/logs/      app.log (fuera de public/)
```

## Requisitos

- PHP **8.1 o superior** con `pdo_mysql` y `mbstring` (vienen activas en XAMPP/Laragon).
- MySQL 8 (o MariaDB 10.4+).

## Levantarla en local

1. Crear la base e importar los scripts (con phpMyAdmin → Importar, o por consola):
   ```
   mysql -u root -p -e "CREATE DATABASE autocar CHARACTER SET utf8mb4"
   mysql -u root -p autocar < database/schema.sql
   mysql -u root -p autocar < database/seed.sql
   ```
2. Copiar `.env.example` como `.env` y completar `DB_*` y **`JWT_SECRET`** (generar con `php -r "echo bin2hex(random_bytes(32));"`).
3. Iniciar el servidor:
   ```
   php -S localhost:8000 -t public public/index.php
   ```
4. Probar: http://localhost:8000/api/vehiculos

### Crear el primer administrador

El registro público siempre crea usuarios con rol `usuario`. Para tener un admin, registrate con `POST /api/registro` y después ejecutá en la base:

```sql
UPDATE users SET rol = 'admin' WHERE email = 'tu@email.com';
```

Volvé a hacer login para obtener un token. (El rol se consulta en la base en cada pedido a `/api/admin`.)

## Despliegue (Render + Aiven)

1. **Base en Aiven** (MySQL, plan Free). Descargar el *CA certificate* y guardarlo como `database/ca.pem` (es público, se sube al repo).
2. Importar el esquema contra Aiven:
   ```
   mysql -h HOST -P PUERTO -u USUARIO -p --ssl-ca=database/ca.pem defaultdb < database/schema.sql
   mysql -h HOST -P PUERTO -u USUARIO -p --ssl-ca=database/ca.pem defaultdb < database/seed.sql
   ```
3. **Render → New → Web Service**, elegir el repo (runtime Docker, plan Free).
4. En *Environment* cargar: `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME` (Aiven), `JWT_SECRET` (distinto al local) y `FRONT_URL` (URL del front en Vercel, sin barra final; varias separadas por coma).
5. En **Vercel**, variable `VITE_API_URL` (o el prefijo de tu framework) con la URL de Render, y redeploy.
6. Cada `git push` a `main` redespliega solo. Errores: pestaña **Logs** de Render.

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
