# AutoCar Backend

Backend en PHP vanilla de AutoCar (TP Integrador, Programación en Ambiente Web, UNLu).

## Qué hay por ahora

Las pantallas de autenticación (registro, login y botón de salir), listas para conectar con la lógica de `app/Auth/`.

```
app/Auth/views/    login.php, registro.php, logout_form.php y los parciales _top / _bottom
public/assets/     css/auth.css, js/auth.js
public/index.php   STUB de vista previa (se reemplaza por el router real)
Dockerfile         imagen para desplegar en Render
```

## Ver las pantallas en local

Necesitás PHP 8.1 o superior.

```
php -S localhost:8000 -t public public/index.php
```

Abrí `/login`, `/login?demo=error`, `/login?demo=ok`, `/registro` y `/registro?demo=errores`.

## Cómo se conectan las vistas

Cada vista documenta arriba las variables que espera (`$errors`, `$old`, `$flash`). El controlador las define y hace `require` de la vista. Los formularios llaman a `Csrf::field()`, que tiene que escribir el input oculto con el token.

## Requisitos, instalación y despliegue

Pendiente: se completa en el README final de la entrega 3.
