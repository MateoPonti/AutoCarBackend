# Seguridad de AutoCar

Todo lo que protege al sitio hoy, en el front (Vercel), el back (Render) y la infraestructura. Al día el **17/11/2026**.
Lo que dice "verificado" se probó contra producción (`https://autocar-zeta.vercel.app` y `https://autocarbackend-1.onrender.com`).

## 1. Resumen

| Riesgo | Cómo se cubre |
|---|---|
| Robo de la sesión | Cookie `HttpOnly` + `Secure` + `SameSite=Strict`, sesión en la base que vence a las 2 h |
| CSRF (otro sitio actúa con tu sesión) | `SameSite=Strict` + token `X-CSRF-Token` en todo lo que cambia datos |
| XSS (meter código en la página) | El front no usa `innerHTML` (todo con `textContent`) + Content-Security-Policy sin scripts en línea |
| Inyección SQL | Consultas parametrizadas con PDO, sin preparadas emuladas |
| Contraseñas | bcrypt, 8 a 72 caracteres, 5 intentos cada 15 minutos por email |
| Acceso indebido | Permisos por rol leídos de la base en cada pedido + reglas de dueño |
| Tráfico en claro | HTTPS obligatorio (redirección 301/308) y HSTS |
| Clickjacking (meter el sitio en otra página) | `X-Frame-Options: DENY` y `frame-ancestors 'none'` |
| Archivos subidos | Tipo real verificado, 2 MB máximo, se sirven sin poder ejecutar nada |
| Fuga de información | Errores en JSON genéricos, sin versiones del servidor en las cabeceras |
| Secretos | Fuera del repo, por entorno, y Render solo con permiso de lectura en Firestore |

## 2. Sesión y cookie

- La sesión de PHP se guarda en la tabla `sesion` de Neon (`src/Auth/SesionDb.php`), no en archivos: el disco de Render se borra en cada deploy.
- El navegador solo tiene la cookie `autocar_sesion` con el id. **Verificado** en producción, directo a Render y por el proxy de Vercel:

  ```
  Set-Cookie: autocar_sesion=…; Max-Age=7200; path=/; secure; HttpOnly; SameSite=Strict
  ```

  | Atributo | Para qué |
  |---|---|
  | `HttpOnly` | El JavaScript de la página no la puede leer: aunque hubiera un XSS, no se puede robar la sesión |
  | `Secure` | Solo viaja por HTTPS (en local, con http, no se pone) |
  | `SameSite=Strict` | El navegador no la manda en pedidos que vienen de otro sitio |
  | `Max-Age=7200` | Vence a las 2 horas (`SESION_TTL`) |

- **Fijación de sesión:** `session.use_strict_mode` rechaza ids inventados por el cliente, `session.use_only_cookies` impide que el id viaje en la URL, y el login hace `session_regenerate_id(true)` (id nuevo y la sesión vieja se borra). Por eso el login manda dos `Set-Cookie` seguidos: el navegador se queda con el último.
- **Logout real:** vacía `$_SESSION`, vence la cookie y borra la fila. Un id copiado deja de servir.
- **El proxy de Vercel** (`Frontend/middleware.js`) hace que el front y la API estén en el mismo dominio: la cookie es del propio sitio y no una cookie de terceros (que Safari bloquea).

## 3. CSRF

- Primera barrera: `SameSite=Strict`.
- Segunda: toda ruta con sesión que no es `GET` (`POST`, `PUT`, `PATCH`, `DELETE`) exige el header `X-CSRF-Token` (`Auth::requireCsrf`). El token sale de `random_bytes(32)` en el login, se guarda en la sesión y se compara con `hash_equals` (sin filtrar por tiempo). Otro sitio puede hacer que el navegador mande la cookie, pero no puede conocer el token. Sin token: 403 `csrf`.

## 4. Contraseñas e ingreso

- `password_hash` / `password_verify` (bcrypt). Nunca se guardan ni se devuelven en claro.
- Largo de 8 a 72 caracteres (72 es el límite de bcrypt: más largo se ignoraría en silencio).
- **Límite de intentos:** 5 cada 15 minutos por email (`Auth\IntentosLogin`, tabla `login_intento`). Al sexto: 429 `demasiados_intentos` con `Retry-After`, aunque la contraseña sea correcta. Cada intento se anota **antes** de verificar, así que pedidos simultáneos no lo saltean. Se cuenta por email y no por IP porque todo el front llega por las mismas IP del proxy de Vercel.
- **Sin pistas:** mismo mensaje y mismo tiempo si el email no existe o la contraseña está mal (si el email no existe se calcula igual un hash).

## 5. Permisos

- Cada ruta declara lo que pide en `src/routes.php`: `auth` (con sesión), `usuario` (compra y vende), o un permiso (`permiso:vehiculos.moderar`, `permiso:parametros.editar`…).
- El rol **no se guarda en la sesión**: en cada pedido se leen de la base el usuario (si sigue activo) y los permisos de su rol (`rol`, `permiso`, `rol_permiso`). Una baja o un cambio de rol surten efecto al instante.
- Roles: `usuario`, `mecanico`, `moderador` y `admin`. El equipo y los talleres no compran ni venden (403).
- **Reglas de dueño y conflicto de interés**, aparte de los permisos: solo el dueño ve y cambia lo suyo (publicaciones, chats, inspecciones, búsquedas); nadie modera su propia publicación (409); nadie resuelve un reclamo en el que tiene interés; no se puede guardar en favoritos ni abrir un chat sobre un auto propio (409).
- **Cambios concurrentes:** los `UPDATE` de estado exigen el estado que se leyó (`AND estado = :desde`). Si dos personas deciden a la vez, la segunda recibe 409. Un solo pedido de inspección en curso por comprador y aviso, con índice único (`020-seguridad.sql`).

## 6. Datos que entran

- **Validación en el back** de todo: cuerpos JSON (un arreglo donde se espera un objeto da 400, no borra datos), filtros de la URL, tipos y rangos.
- **SQL:** solo consultas parametrizadas con PDO y `ATTR_EMULATE_PREPARES = false`. Los comodines `%` y `_` de la búsqueda se escapan.
- **Entradas raras no dan 500:** un id que no entra en `INTEGER` da 404, la página tiene tope, y un texto que la base rechaza (no UTF-8, byte nulo, número fuera de rango) da 400 `dato_invalido`.
- **Límites de abuso:** contacto, 3 por hora por email (429).
- **Links de inspección:** solo de MercadoLibre, Kavak, Autocosmos o AutoCar (422 si no).

## 7. Archivos subidos (fotos de autos, de perfil y de la patente)

- Se mira el **tipo real** del archivo con `finfo` (no la extensión ni lo que dice el navegador) y que sea una imagen válida (`getimagesize`). Solo JPG, PNG y WebP, hasta 2 MB.
- Se guardan en la base (no en el disco de Render) y se sirven con `Content-Security-Policy: default-src 'none'; sandbox`: si alguien abre la foto directo, el navegador no ejecuta nada aunque el archivo tuviera código escondido.

## 8. Front: XSS y encabezados

- **Sin `innerHTML`**: todo el texto que viene de la API o de otro usuario se pone con `textContent` (`front/js/servicios/vistas.js`, función `el`). Un mensaje de chat con `<script>` se ve como texto (prueba en `Frontend/tests/mensajes-xss.html`).
- **Sin scripts en línea ni `onclick`** en el HTML: los botones y diálogos se crean desde el JS.
- **Encabezados de seguridad** en `Frontend/vercel.json` (desde el 17/11):

  | Encabezado | Valor y para qué |
  |---|---|
  | `Content-Security-Policy` | Solo scripts del propio sitio; estilos propios y de Google Fonts; imágenes propias, `data:` y `blob:` (vista previa al subir); la API solo por el mismo dominio; nada de `<object>`; no se puede meter el sitio en un iframe. Un único `<style>` permitido por su hash (la transición entre páginas de `js/tema.js`; si se cambia ese texto hay que recalcular el hash) |
  | `X-Frame-Options: DENY` | Lo mismo que `frame-ancestors` para navegadores viejos: evita el clickjacking |
  | `X-Content-Type-Options: nosniff` | El navegador no adivina tipos: un archivo de texto no se ejecuta como script |
  | `Referrer-Policy: strict-origin-when-cross-origin` | A otros sitios solo se les dice el dominio, no la página (las URLs pueden tener ids) |
  | `Permissions-Policy` | Cámara, micrófono, ubicación, pagos y USB apagados (el sitio no los usa) |
  | `Cross-Origin-Opener-Policy: same-origin` | Una ventana de otro sitio no puede manejar la nuestra |

  **Verificado** el 17/11 en el preview: 18 páginas (sin sesión y con sesión) sin ningún bloqueo de la CSP. En los previews de Vercel la CSP bloquea la barra de comentarios de Vercel (`vercel.live`); en producción esa barra no existe.

## 9. HTTPS

**Verificado** en producción:

| Si se entra por… | Respuesta |
|---|---|
| `http://autocar-zeta.vercel.app/...` | 308 a `https://` (Vercel) |
| `http://autocarbackend-1.onrender.com/...` | 301 a `https://` (Render) |

Los dos mandan **HSTS** (`Strict-Transport-Security`): después de la primera visita el navegador ni intenta `http://`. Vercel: 2 años con `includeSubDomains; preload`; Render: 1 año. La CSP además tiene `upgrade-insecure-requests`.

## 10. Errores y cabeceras del servidor

- Todas las respuestas son JSON, también los errores (404, 405, 500), con el código HTTP correcto. Nunca HTML ni warnings de PHP.
- `display_errors = 0`: el detalle va a `storage/logs/app.log` y el cliente recibe un 500 genérico.
- Sin versiones: `expose_php = Off`, `ServerTokens Prod`, `ServerSignature Off` (en el `Dockerfile`). Las respuestas dicen `Server: Apache` sin versión y no mandan `X-Powered-By`.
- Las respuestas de la API llevan `Cache-Control: no-store` (nada con datos de un usuario queda en cachés intermedias) y `X-Content-Type-Options: nosniff`.
- **CORS** solo para los orígenes de `FRONT_URL`. En producción no interviene (el front usa el proxy); no bloquea pedidos sin `Origin` (scripts, el profe), porque CORS solo limita navegadores.

## 11. Secretos e infraestructura

- **Nada secreto en los repos**: `.env` está en `.gitignore`; credenciales de Neon y Firebase en los apuntes del grupo.
- **Por entorno:** Neon dev y Neon main son proyectos separados; Render dev y prod tienen sus propias variables; Vercel elige el back con `BACKEND_URL` por entorno (si falta, responde 503 y no cae a otro entorno).
- **Firestore con mínimo privilegio:** Render usa una cuenta de **solo lectura** ("Cloud Datastore Viewer"). La de escritura está solo en la PC del scraper y en GitHub Actions.
- **GitHub Actions:** secretos por Environment (`prod` solo desde `main`, `dev` solo desde `dev`): correr el scraper desde `dev` nunca puede tocar prod.
- **Flujo de ramas:** los cambios entran por PR con CI en verde (`feat/*` → `dev` → `main`) y nunca con `push --force`. Es una regla del grupo: en GitHub las ramas `main` no tienen protección configurada (ver sección 13).

## 12. Pruebas que lo cubren

- `tests/Integration/SeguridadTest.php`: pedido duplicado, moderación concurrente, entradas raras, cabeceras sin versiones, límite de intentos, `PUT` con arreglo.
- `tests/Integration/AuthTest.php` y `RolesDelEquipoTest.php`: sesión, CSRF, permisos por rol.
- Front: `tests/mensajes-xss.html` (el chat no ejecuta HTML) y las E2E de Playwright.

## 13. Lo que no hay (todavía)

- Verificación del email al registrarse y recuperación de contraseña.
- Segundo factor (2FA) para el admin.
- Límite de pedidos general por IP (solo hay límites por email en login y contacto).
- Protección de ramas en GitHub (que `main` exija PR y CI en verde): hoy se respeta por acuerdo, no porque GitHub lo obligue.
- Auditoría automática de dependencias: el back no usa librerías de terceros y el front casi ninguna (`@vercel/functions` y Playwright para pruebas).
