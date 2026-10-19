# AutoCar Backend

Aplicación web en **PHP vanilla + MySQL** de AutoCar (TP Integrador, Programación en Ambiente Web, UNLu).
Compra y venta de vehículos. Entidad principal: **vehículos**.

- **URL desplegada:** https://autocarbackend-1.onrender.com *(HTTPS)*
- **Roles:** `usuario` (registrado) y `admin` (gestiona vehículos).

## Qué incluye (entrega 3)

| Requisito | Dónde está |
|---|---|
| Registro, ingreso y salida con sesión y 2 roles | `src/Auth/` |
| Alta, baja, modificación y listado de vehículos (admin) | `src/Admin/` → `/admin/vehiculos` |
| Vista pública: listado con búsqueda/filtros y detalle | `src/Catalogo/` → `/vehiculos`, `/vehiculos/{id}` |
| Despliegue con HTTPS | Render (Docker) + Aiven (MySQL) |
| Seguridad | ver más abajo |

## Estructura

```
public/            única carpeta expuesta: index.php (front controller), .htaccess, assets/, uploads/
src/bootstrap.php  autoload, errores, sesión y cabeceras de seguridad
src/routes.php     todas las rutas, con su guard (auth / admin)
src/Core/          Config, Database (PDO), Router, View, Logger
src/Auth/          Auth, Csrf, Usuario, AuthController, views/
src/Catalogo/      Vehiculo (modelo), CatalogoController, views/ (vista pública)
src/Admin/         AdminVehiculoController, views/ (ABM)
src/Views/         layout y páginas de error (403, 404, 500)
database/          schema.sql, seed.sql (y ca.pem de Aiven, ver abajo)
storage/logs/      app.log (fuera de public/)
```

## Requisitos

- PHP **8.1 o superior** con las extensiones `pdo_mysql`, `mbstring` y `fileinfo` (vienen activas en XAMPP/Laragon).
- MySQL 8 (o MariaDB 10.4+).

## Levantarla en local

1. Crear la base e importar los scripts:
   ```
   mysql -u root -p -e "CREATE DATABASE autocar CHARACTER SET utf8mb4"
   mysql -u root -p autocar < database/schema.sql
   mysql -u root -p autocar < database/seed.sql
   ```
2. Copiar `.env.example` como `.env` y completar `DB_*` (el `.env` no se sube al repo).
3. Iniciar el servidor:
   ```
   php -S localhost:8000 -t public public/index.php
   ```
4. Abrir http://localhost:8000

### Crear el primer administrador

El registro público siempre crea usuarios con rol `usuario`. Para tener un admin: registrate desde `/registro` y después ejecutá en la base:

```sql
UPDATE users SET rol = 'admin' WHERE email = 'tu@email.com';
```

Cerrá sesión y volvé a ingresar: aparece el enlace **Administrar**.

## Despliegue (Render + Aiven)

1. **Base en Aiven** (MySQL, plan Free). Descargar el *CA certificate* y guardarlo como `database/ca.pem` (es público, se sube al repo).
2. Importar el esquema contra Aiven:
   ```
   mysql -h HOST -P PUERTO -u USUARIO -p --ssl-ca=database/ca.pem defaultdb < database/schema.sql
   mysql -h HOST -P PUERTO -u USUARIO -p --ssl-ca=database/ca.pem defaultdb < database/seed.sql
   ```
3. **Render → New → Web Service**, elegir el repo (runtime Docker, plan Free).
4. En *Environment* cargar: `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME` (datos de Aiven).
5. Cada `git push` a `main` redespliega solo. Para ver errores: pestaña **Logs** de Render. `GET /health` responde `ok` sin tocar la base.

Notas del plan Free: el servicio se duerme tras ~15 min sin visitas (la primera carga tarda cerca de un minuto) y **el disco no es persistente**: las fotos subidas a `public/uploads/` se pierden en cada redeploy; el sitio muestra "Sin foto" en ese caso.

## Seguridad aplicada

- **Validación en el backend** de todos los formularios y filtros (`Vehiculo::validar`, `AuthController`, `CatalogoController::filtros`); la validación HTML del navegador es solo ayuda.
- **Consultas parametrizadas** con PDO (`ATTR_EMULATE_PREPARES` desactivado); los comodines `%` y `_` de la búsqueda se escapan.
- **Contraseñas** con `password_hash` / `password_verify` (bcrypt); nunca se guardan ni se muestran en claro.
- **Control de rol en el servidor**: cada ruta de `/admin` lleva el guard `admin` en `src/routes.php`, que consulta el rol en la base en cada pedido (no se confía en la sesión ni en el navegador). Sin sesión → redirige a login; sin rol → 403.
- **CSRF**: todos los POST (incluido salir) exigen token, verificado en `public/index.php`.
- **XSS**: toda salida pasa por `e()` (`htmlspecialchars`); CSP restrictiva sin scripts en línea.
- **Sesión**: cookie `HttpOnly`, `SameSite=Lax`, `Secure` bajo HTTPS; `session_regenerate_id` al ingresar.
- **Errores**: `display_errors=0`; se registran en `storage/logs/app.log` y se muestra una página 500 genérica.
- **Subida de fotos**: se valida el tipo por contenido (no por extensión), máximo 2 MB, nombre aleatorio y `public/uploads/.htaccess` bloquea la ejecución de PHP.
- Login sin pistas: el mensaje es el mismo si el email no existe o la contraseña es incorrecta.

## Entrega

```
git tag entrega-3
git push origin entrega-3
```

Subir a Classroom el link del repositorio y la URL desplegada (todos los integrantes).
