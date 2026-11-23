# AutoCar Backend (API JSON)

API en **PHP + PostgreSQL** (con Composer) de AutoCar (TP Integrador, Programación en Ambiente Web, UNLu).
Compra y venta de vehículos. Entidad principal: **vehículos**. El front (Vercel) consume esta API.

- **URL desplegada:** https://autocarbackend-1.onrender.com *(HTTPS)*
- **Roles y permisos** (en tablas: `rol`, `permiso`, `rol_permiso`): `usuario` (publica y chatea), `moderador` (aprueba o rechaza publicaciones), `admin` (puede todo: usuarios, ABM del catálogo, moderación y métricas) y `mecanico` (verificaciones; endpoints pendientes). Las rutas piden **permisos**, no roles: un rol nuevo solo necesita filas en `rol_permiso`.
- **Autenticación:** sesión de PHP (`$_SESSION`) guardada en la tabla `sesion`; el navegador solo tiene la cookie `autocar_sesion` con el id (`HttpOnly`, `Secure`, `SameSite=Strict`) + token CSRF en el header `X-CSRF-Token`.
- **Middleware** (`src/Core/Middleware.php`): antes de ejecutar el controlador, controla el guard de la ruta (`auth` → sesión, 401; `permiso:...` → permiso del rol, 403) y el token CSRF en todo lo que cambia datos (403). Los guards se ven todos juntos en `src/routes.php`.
- **Roles:** *usuario* compra y vende; *mecánico* (cuenta del taller) inspecciona; *moderador* revisa publicaciones y reportes; *admin* lo del moderador más usuarios, talleres, contacto y métricas (todos los permisos menos los del mecánico). Las cuentas del equipo y las de los talleres **no compran ni venden**: las rutas de publicar, pedir mecánico, favoritos, chat y búsquedas guardadas llevan el guard `usuario` (403 `cuenta_del_equipo`). Nadie modera su propia publicación (409 `publicacion_propia`).
- **Versión:** ver `VERSION` (versionado semántico). **Autores:** `autores.txt`.

## Endpoints

Todas las respuestas son JSON. Los errores tienen la forma `{"error": "codigo", "mensaje": "...", "errores": {"campo": "mensaje"}}`.

> **Cambio del 23/11/2026 (decisión de producto, migraciones 034 y 035): sin reclamos, sin fondo de garantía y sin historial por patente.** El informe firmado reduce el riesgo antes de comprar, no lo elimina: la decisión es del comprador y no hay reclamo después (no se puede verificar si la falla estaba el día de la inspección, y llega cuando el auto ya se compró). Se eliminaron `POST /api/inspecciones/{id}/reclamo`, `GET` y `PATCH /api/admin/reclamos`, `GET /api/admin/fondo` y el permiso `reclamos.resolver` (ahora dan 404). Las inspecciones ya no traen `monto_fondo`, `tope_veces`, `dias_reclamo`, `reclamo`, `reclamo_hasta`, `puede_reclamar` ni `anteriores`; `GET /api/parametros` ya no trae `parte_fondo`, `tope_veces`, `dias_reclamo`, `franquicia`, `ranking_veces_reclamo` ni `ranking_especialidad_buena`. El ranking de mecánicos es por experiencia: `GET /api/mecanicos` ya no trae `puntaje` ni `reclamos_procedentes`. En las métricas se fueron `reclamos`, `perdida`, `neta`, `reclamos_procedieron` y `ganancias.fondo`.

| Método y ruta | Acceso | Descripción |
|---|---|---|
| `POST /api/registro` | público | Crea una cuenta (`nombre`, `email`, `password`, `password_confirm` opcional, `acepta_privacidad: true` obligatorio). Rol siempre `usuario`. → 201 |
| `POST /api/login` | público | `email`, `password` → `{csrf, expira_en, usuario}` y la cookie de sesión (`Set-Cookie`). La cuenta de un taller con la solicitud pendiente o rechazada → 403 `taller_pendiente` o `taller_rechazado` (con el motivo). Más de 5 intentos en 15 minutos con el mismo email → 429 `demasiados_intentos` con `Retry-After`, aunque la contraseña sea la correcta |
| `POST /api/logout` | público | Borra la cookie de sesión → 204 |
| `GET /api/me` | sesión | `{usuario, csrf}` del usuario autenticado. `usuario` trae `preferencias`, `preferencias_pendientes` y `privacidad_pendiente` (el front no deja seguir a una cuenta personal hasta que contesta y acepta) |
| `PUT /api/me` | sesión | Cambia el nombre (`{nombre}`, 2 a 80 caracteres) → `{usuario}`. El email no se cambia |
| `POST /api/me/foto` | sesión | Sube o reemplaza la foto de perfil (multipart, campo `foto`: JPG, PNG o WebP de hasta 2 MB) → `{usuario}` con `foto` |
| `DELETE /api/me/foto` | sesión | Borra la foto de perfil → 204 |
| `GET /api/me/preferencias` | cuenta personal | `{preferencias, usos, tipos}`: lo que contestó (o `null`) y las opciones |
| `PUT /api/me/preferencias` | cuenta personal | "¿Para qué buscás el auto?": `{uso, tipo?, presupuesto?, localidad?, acepta_privacidad?}` → `{preferencias}`. `uso`: `personal`, `familia`, `trabajo` o `aplicacion`. Una cuenta que todavía no aceptó la política de privacidad manda `acepta_privacidad: true` |
| `GET /api/usuarios/{id}/foto` | público | La foto de perfil (se ve en el detalle de sus publicaciones y en el chat). 404 si no tiene. `usuario.foto`, `vendedor.foto` y `con.foto` traen esta ruta con `?v=` (o `null`) |
| `GET /api/vehiculos` | público | Listado, 12 por página (`{items, total, pagina, por_pagina, paginas}`). Filtros: `q`, `marca`, `combustible`, `precio_max`, `page` |
| (033) `GET /api/vehiculos?orden=&vendedor=` | pública | Explorar: por defecto `orden=relevantes`, el mismo cálculo que el metabuscador (con perfil, el puntaje de "para vos"; sin perfil o sin sesión, la diferencia con el precio típico de autos parecidos de otros sitios, guardada en `vehiculo_mercado`: Explorar solo lee Neon); sin parecidos, después y del más nuevo al más viejo. `orden=recientes`: los más nuevos primero. `vendedor=particular\|empresa`. Cada auto trae `empresa` ({id, nombre} si es de una concesionaria aprobada, para la insignia) o null. En `relevantes`, la respuesta trae `perfil` (el uso del que mira, o null) y en la página 1 hasta 4 autos traen `recomendado` {puesto, mensaje} (por qué se le recomienda) |
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
| `PATCH /api/mis-vehiculos/{id}/estado` | sesión (dueño) | `{"estado":"vendido"}`, solo desde `disponible`. Las inspecciones de ese auto cuyo turno todavía no pasó quedan `caida` (pago `reembolsado`); lo mismo si la publicación se elimina o se rechaza |
| `GET /api/mis-vehiculos/turnos` | sesión | Turnos de inspección de mis publicaciones para confirmar: `{items: [{id, vehiculo_id, titulo, fecha_turno, confirmado_at, taller_localidad}]}`. No dice qué mecánico es |
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
| `GET /api/admin/metricas?dias=7\|30\|90` | `metricas.ver` (admin) | Por período (30 días si no se indica): `resumen` (`actual` y `anterior`: nuevos usuarios, inspecciones pagadas, compradores, firmadas, facturado, ganancia, `mecanicos` (pagado a los mecánicos en el período), `ingresos` y `por_cobrar`), `serie` (por día o semana), `embudo` de las recomendaciones por origen (vieron → tocaron "Mandale un mecánico" → pagaron la inspección → firmadas; "compró" = pagó la inspección; cada origen y el total traen `ganancia` —comisión menos créditos de las inspecciones pedidas en el período desde ahí, ya firmadas y cobradas— y `por_vio`, `por_toco` y `por_pago` = ganancia ÷ vieron, tocaron o pagaron), `inspecciones` del período (por estado, mediana de horas de pagar a firmar, precio promedio, conclusiones y los 5 mecánicos que más firmaron) y `recurrentes`. Además, usuarios (activos, con actividad en 30 días, por rol), publicaciones (por estado, nuevas, precio promedio, valor en venta y vendido, marcas top), moderación y chat. Ganancias: no hay cobros todavía Incluye `ganancias` de las inspecciones (pago simulado): facturado, ganancia de AutoCar (la comisión menos los créditos), pagado a mecánicos, `creditos` (usados y pendientes), retenido y reembolsado. `compradores_por_uso`: cuántas cuentas eligieron cada respuesta de "¿Para qué buscás el auto?". `por_perfil` (029), todo por tipo de usuario (`usos`: personal, familia, trabajo, aplicación y sin perfil): `plata` (pagaron, compradores, ingresos, por cobrar), `embudo` (por origen: vieron, tocaron, pagaron, conversión, la misma `ganancia`, `por_vio`, `por_toco` y `por_pago`, y `mejor`, la recomendación que más convierte), `metabuscador` (cuántos buscaron y, de esos, cuántos pagaron una inspección, de cualquier lado y desde el metabuscador; con `total`) y `ordenes` (cuántos compradores eligieron Recomendado, Menor precio, Menos km o Más nuevos, cuántas veces, y el `preferido`) y `valor` (por tipo de usuario y `total`: ingresos, usuarios activos, interacciones —buscar, cambiar el orden y tocar "Mandale un mecánico"; lo mostrado no cuenta— y de los compradores la mediana de `interacciones_hasta_comprar` y `dias_hasta_comprar` en los 90 días antes de su primer pago; los que pagaron sin interacciones van en `compradores_sin_interacciones`). La `serie` trae `buscaron` |
| `GET /api/admin/afiliacion` | `metricas.ver` (admin) | Afiliación con las plataformas del metabuscador (convenio simulado, 032): `convenios` (tarifa por clic y si está activo), `este_mes` (una fila por plataforma: clics, válidos, tarifa y lo que se va a cobrar, y una por empresa aprobada con su abono mensual (033); total, `de_clics`, `de_abonos` y días al cierre) y `evolucion` (últimos 12 meses, en hora de Argentina: lo cobrado o a cobrar y `estado` en_curso, pendiente o cobrado, con el detalle por plataforma) |
| `PUT /api/admin/afiliacion/convenios` | `parametros.editar` (admin) | `{fuente, tarifa_clic, activo}`: cambia el convenio de una plataforma (vale para el mes en curso y los meses sin cobrar). 404 si no hay convenio con esa fuente |
| `POST /api/admin/afiliacion/cobros` | `parametros.editar` (admin) | `{fuente, mes: "AAAA-MM"}` (una plataforma) o `{tipo: "empresa", socio: id, mes}`: marca cobrado lo de ese socio en un mes cerrado, con el monto de ese momento. 409 `mes_en_curso` o `ya_cobrado` |
| `GET /api/admin/parametros` | `parametros.editar` (admin) | `{parametros, cambios}`: los números del negocio (comisión de AutoCar, precio mínimo/sugerido/máximo de la inspección, cotización del dólar, crédito por auto vendido, mínimo del ranking de mecánicos) con sus límites, y los últimos 30 cambios. La cotización del dólar (`cotizacion_dolar`) se actualiza sola con el dólar blue de dolarapi.com cuando tiene más de 12 horas; esos cambios figuran como "Automático" Incluye la fórmula de "para vos": `peso_<perfil>_<criterio>` (perfil personal, familia, trabajo o aplicacion; criterio precio, km, dias o anio; de 0 a 5) |
| `PUT /api/admin/parametros` | `parametros.editar` (admin) | `{valores: {clave: valor}}` → `{parametros, cambios}`. Valida límites (422 por clave) y que el sugerido quede entre el mínimo y el máximo. Cada cambio queda anotado (quién, cuándo, antes y después). |
| `GET /api/parametros` | público | `{comision_autocar, precio_minimo, precio_sugerido, precio_maximo, cotizacion_dolar, credito_vendido, horas_cupon, presupuestos_por_arreglo, dias_presupuesto, comision_arreglos, fallas_minimo_inspecciones, fallas_votos_confirmar, fallas_porcentaje_medido}`: el front arma el desglose del precio y el rango que acepta `PUT /api/mecanico/perfil` y "Adherí tu taller" |
| `GET /api/meta-busquedas` | sesión | Búsquedas guardadas del usuario, con autos que coinciden y avisos sin ver |
| `POST /api/meta-busquedas` | sesión | Crea una búsqueda: `marca` y `modelo` obligatorios; `anio_desde`, `anio_hasta`, `precio_max`, `km_max` y `transmision` opcionales. Máximo 20 por usuario (409) → 201 |
| `GET /api/meta-busquedas/conteo` | sesión | Cuántos autos coinciden hoy con esos criterios (los mismos que al crear), sin guardar nada: `{autos, por_fuente}`. Lo usa "Nueva búsqueda" mientras se eligen los filtros. 422 si los criterios no son válidos |
| `GET /api/meta-busquedas/{id}` | sesión (dueño) | Reporte: referencia de precio, novedades, destacados, resultados y otros modelos. Listado: `fuente`, `etiqueta`, `precio_max`, `km_max`, `orden` (`precio`, `km`, `nuevos`, `para_vos`), `page`. Si el usuario eligió para qué busca el auto, cada resultado trae `para_vos` {`puntaje`, `motivo`, `mensaje`} (`mensaje`: la frase de los primeros de "Más recomendados", con el perfil y lo que más sumó; null si nada suma a favor), la respuesta trae `perfil` y el orden por defecto es `para_vos` |
| `PUT /api/meta-busquedas/{id}` | sesión (dueño) | Modifica los criterios |
| `DELETE /api/meta-busquedas/{id}` | sesión (dueño) | Borra la búsqueda y sus avisos → 204 |
| `GET /api/meta-busquedas/{id}/novedades` | sesión (dueño) | Página de novedades: `corrida` (lo que cambió en la última corrida de cada sitio: nuevos, bajaron, retirados, ... e `items` con el auto), `por_dia` (últimos 30 días, hora de Argentina: cuántos `nuevo`, `bajo` y `retirado` hubo en los avisos de la búsqueda), `sin_ver` y `cotizacion_dolar`. No marca vistos ni cuenta como búsqueda |
| `GET /api/meta-busquedas/avisos?sin_ver=1` | sesión | Últimos 100 avisos del usuario: auto nuevo, baja de precio, reservado o retirado |
| `POST /api/meta-busquedas/{id}/avisos/vistos` | sesión (dueño) | Marca como vistos los avisos de esa búsqueda → 204 |
| `GET /api/autos-externos?id=` | sesión | Detalle de un auto de otra plataforma: ficha, etiqueta de precio, comparables, historial y link al original. El `id` es texto (`fuente~modelo~id`, ej. `kavak~renault_sandero~562339`), el mismo que viene en los resultados |
| `GET /api/salida?id=&origen=` | pública | El "Ver en …" del metabuscador: anota el clic (`clic_salida`; válido = de una cuenta personal y no repetido en 24 h) y redirige (302) al aviso de la plataforma. La dirección sale de los datos del scraper, nunca de la URL. `origen`: para_vos, destacado o metabuscador. 404 si el auto no existe |
| `GET /api/mecanicos?localidad=&marca=&modelo=&orden=` | público | Ranking de la red, por experiencia: taller, dirección, precio con su desglose (`monto_mecanico` y `monto_autocar`), `firmadas` y `nuevo` (true con menos de `ranking_minimo_firmadas`, de fábrica 3; van después) y `localidades` para el filtro. Orden: más firmadas primero. Sin teléfono. Con `marca` (la del auto a revisar), cada mecánico trae `revisados` (firmadas de ese modelo y de otros de la marca) y `especialidad: {en: modelo\|marca, revisados}` o `null`; el orden pasa a ser por especialidad (revisó el modelo, revisó la marca, sin experiencia) salvo `orden=ranking`. La respuesta trae `orden` |
| `POST /api/inspecciones` | sesión | Pedir una inspección: `url` del aviso (solo MercadoLibre, Kavak, Autocosmos o AutoCar: los de otros sitios dan 422) y `mecanico_id`; `titulo`, `anio`, `kilometraje`, `precio_publicado`, `localidad`, `marca` y `modelo` opcionales (si el link es de AutoCar salen de la publicación; marca y modelo tienen que estar en `GET /api/marcas-modelos`, si no 422). Pago simulado `retenido` → 201. Si el comprador tiene un crédito (`GET /api/mis-creditos`), se descuenta solo: `credito_usado` y `a_pagar`. 409: `mecanico_propio`, `vehiculo_propio`, `mecanico_vendedor`, `duplicada` (ya hay un pedido en curso de ese aviso, aunque sea con otro link; lo controla un índice único, así que vale también con pedidos simultáneos) Opcional `origen` (para_vos, destacado, metabuscador, o publicacion): desde dónde se pidió, para las estadísticas |
| `POST /api/eventos` | cuenta personal | `{eventos: [{tipo: mostrado\|tocado\|orden, origen: para_vos\|destacado\|metabuscador\|publicacion, ref?}]}` (hasta 50): recomendaciones que el comprador vio y tocó, para el embudo del admin, y el orden que eligió en el metabuscador (`orden`, origen `metabuscador`, ref `recomendado\|precio\|km\|nuevos`). Abrir los resultados de una búsqueda (`GET /api/meta-busquedas/{id}`, primera página) lo anota el back como `busco`. Lo inválido se ignora → 204 |
| `GET /api/mis-inspecciones` | sesión | Las inspecciones que pidió el usuario, con el mecánico |
| `GET /api/inspecciones/{id}` | sesión | Detalle de una inspección con el checklist. La ven el comprador y el mecánico asignado; para el resto, 404. Trae `patente`, `patente_foto` (ruta de la foto de la chapa, o null), `aviso_publicado` (si la publicación de AutoCar sigue a la vista; null para avisos de otros sitios) `turno_confirmado_at` (el vendedor confirmó el turno; solo autos de AutoCar), `credito_usado`, `a_pagar`, `credito` (el que dio esta inspección: `{tipo: auto_vendido|cupon, monto}` o null), `marca`, `modelo` y `modelo_clave` (la misma clave que el comparador), y si está firmada, `presupuestos` y `costo_total` (idea A: `{items: [{codigo, resultado, mejor}], arreglos, precio_publicado, total, completo}`: el precio del aviso más el presupuesto vigente más barato de cada arreglo; `completo` es false si algún arreglo todavía no tiene presupuesto). |
| `GET /api/inspecciones/{id}/patente-foto` | sesión | La foto de la chapa que subió el mecánico. La ven los mismos que el detalle |
| `GET /api/inspecciones/{id}/item-foto?codigo=motor` | sesión | La foto de un ítem del checklist (cada ítem del detalle trae `foto` con esta URL, o `null`). La ven los mismos que el detalle |
| `POST /api/inspecciones/{id}/confirmar-turno` | sesión (dueño de la publicación) | El vendedor confirma el turno que dio el mecánico → `{turno}`. Solo con la inspección `aceptada` y el turno por venir (si no, 409); de otra publicación → 404 |
| `POST /api/inspecciones/{id}/presupuestos` | sesión (comprador) | Idea A: pide los presupuestos de un informe firmado antes de que existiera el costo total (los nuevos se piden solos al firmar). No repite los ya pedidos → `{inspeccion}`. Sin firmar → 409 |
| `GET /api/fallas-tipicas?marca=&modelo=` | público | Idea B: `{fallas_tipicas: {marca, modelo, clave, inspecciones, minimo, medido, fallas, medidas}}`. Cada falla con `origen` (`internet` o `mecanicos`), `fuente_url`, `estado` según los votos (`confirmada` o `sin_confirmar`; las descartadas no salen), `votos` y `porcentaje` (qué % de las inspecciones firmadas del modelo tuvo ese ítem observado o con falla; null con menos de `fallas_minimo_inspecciones`). `medidas`: los ítems que superan `fallas_porcentaje_medido`. Marca o modelo fuera del catálogo → 422 |
| `POST /api/admin/fallas-tipicas` | `parametros.editar` (admin) | Carga una falla de internet: `{marca, modelo, item_codigo, titulo, descripcion?, fuente_url}` (la fuente es obligatoria) → 201 |
| `GET /api/mis-creditos` | sesión | `{items, total}`: créditos sin usar. Se dan si se firmó con el auto ya vendido (`auto_vendido`) o si el turno estaba confirmado y el auto se vendió dentro de `horas_cupon` después de la firma (`cupon`); valen `credito_vendido` % del precio. Vuelven si la inspección donde se usaron se cae, se cancela o se rechaza |
| `POST /api/inspecciones/{id}/cancelar` | sesión (comprador) | Solo antes de que el mecánico la acepte; pago `reembolsado` (con Mercado Pago, se devuelve por ahí). Si no, 409 |
| `POST /api/inspecciones/{id}/pago` | sesión (comprador) | Mercado Pago (solo con `MP_ACTIVO=true`): `{inspeccion, cobro: {estado, monto, checkout_url, pagado_at}}`. Si falta pagar trae el `checkout_url` de Checkout Pro (arma otra order si la anterior venció). Sin cobro con Mercado Pago → 409 `sin_cobro`; Mercado Pago no responde → 502 |
| `POST /api/inspecciones/{id}/pago/estado` | sesión (comprador) | Al volver de Mercado Pago: le pregunta cómo quedó la order y devuelve `{inspeccion, cobro}` (estado `pendiente`, `pagado`, `cancelado` o `devuelto`) |
| `POST /api/pagos/mercadopago` | público (lo llama Mercado Pago) | Aviso (webhook, evento *Order*) con `?data.id=ORD...&type=order`. No le cree al cuerpo: con ese id le pregunta la order a Mercado Pago. Siempre 200 |
| `GET /api/mecanico/perfil` | `verificaciones.realizar` (mecánico) | `{taller}` o `{taller: null}` si todavía no completó el perfil |
| `PUT /api/mecanico/perfil` | `verificaciones.realizar` | Alta o modificación del taller: `nombre`, `direccion`, `localidad`, `telefono`, `precio_inspeccion` (entre $ 1.000 y $ 10.000.000, sin centavos) |
| `GET /api/mecanico/inspecciones?estado=` | `verificaciones.realizar` | Sus inspecciones (de un estado o todas) y `conteos` por estado |
| `PATCH /api/mecanico/inspecciones/{id}` | `verificaciones.realizar` | Desde `pedida`: `{"accion":"aceptar","fecha_turno":"ISO 8601"}` (desde ahora y hasta 60 días; hace falta el perfil, si no 409 `sin_taller`) o `{"accion":"rechazar","motivo":"..."}` (pago `reembolsado`). Desde `aceptada`: `{"accion":"no_realizada","motivo":"..."}` cuando fue y no la pudo hacer, por ejemplo porque el vendedor no dejó revisar el auto (pago `reembolsado`, el motivo queda en `motivo_rechazo`). Otro estado → 409 |
| `PUT /api/mecanico/inspecciones/{id}/informe` | `verificaciones.realizar` | Borrador del checklist `{items: [{codigo, resultado, nota}], conclusion?, resumen?, patente?, marca?, modelo?}` (marca y modelo del catálogo; si no vienen, quedan los del pedido), se puede a medias. La patente se guarda en mayúsculas y sin espacios (ABC123 o AB123CD; otro formato → 422). Solo con la inspección `aceptada`; firmada → 409 `firmada` |
| `POST /api/mecanico/inspecciones/{id}/patente-foto` | `verificaciones.realizar` | Foto de la chapa (multipart, campo `foto`: JPG, PNG o WebP de hasta 2 MB), como control de la patente. Reemplaza la anterior. Solo con la inspección `aceptada` |
| `POST /api/mecanico/inspecciones/{id}/item-foto` | `verificaciones.realizar` | Foto de lo que vio en un ítem del checklist (multipart, campos `codigo` y `foto`: JPG, PNG o WebP de hasta 2 MB). Obligatoria para firmar si el ítem tiene `falla`; opcional en `observado`. Reemplaza la anterior de ese ítem. Solo con la inspección `aceptada` |
| `POST /api/mecanico/inspecciones/{id}/firma` | `verificaciones.realizar` | Firma lo guardado: exige los 10 ítems, la conclusión, la patente, la foto de la chapa, una foto de cada ítem con `falla` y la marca y el modelo (si no, 422 `informe_incompleto`). Al firmar se piden los presupuestos de cada arreglo a `presupuestos_por_arreglo` talleres de la red, nunca a este (idea A). Guarda el nombre del mecánico y del taller, el momento y la huella SHA-256 (incluye la patente); pago `liberado` |
| `GET /api/mecanico/presupuestos?estado=` | `verificaciones.realizar` | Idea A: lo que le pidieron presupuestar a este taller (`pedido`, `respondido` o `rechazado`), con el auto y la nota del ítem |
| `PATCH /api/mecanico/presupuestos/{id}` | `verificaciones.realizar` | `{monto, detalle}` (vale `dias_presupuesto` días) o `{rechazar: true}`. Una sola vez (409); de otro taller → 404 |
| `GET /api/mecanico/fallas-tipicas?marca=&modelo=` | `verificaciones.realizar` | Idea B: lo mismo que la pública, con las descartadas y `mi_voto` |
| `POST /api/mecanico/fallas-tipicas` | `verificaciones.realizar` | Propone una falla que vio `{marca, modelo, item_codigo, titulo, descripcion?, fuente_url?}` (origen `mecanicos`, queda con su voto a favor) → 201 |
| `PUT /api/mecanico/fallas-tipicas/{id}/voto` | `verificaciones.realizar` | `{confirma: true|false}` (la vio / no es típica); lo puede cambiar. Con `fallas_votos_confirmar` votos de un lado queda confirmada o descartada |
| `POST /api/solicitudes-taller` | público | "Adherí tu taller". Crea la **cuenta del taller**, aparte de la cuenta personal: `responsable`, `email`, `password` y los datos del taller (`nombre`, `cuit` en formato `30-12345678-9` con o sin guiones, `direccion`, `localidad`, `telefono`, `horarios`, `precio_inspeccion`, `condiciones: true`) → 201 `{solicitud}`. La cuenta nace con rol `mecanico`, pero no puede ingresar ni aparece en el ranking hasta que un admin la aprueba. Si el email ya tiene una cuenta → 422 en `email` (un email es una sola cuenta). Si ya hay una solicitud pendiente con ese email → 409. Si estaba rechazada, se vuelve a mandar con la misma contraseña (con otra → 422 en `password`) |
| `POST /api/solicitudes-empresa` | pública | "Sumá tu concesionaria" (033): `{responsable, email, password, razon_social, cuit, localidad, telefono, sitio_web?, condiciones: true}` → 201 `{solicitud}` pendiente. Crea la cuenta con rol `empresa`, que no ingresa hasta que la aprueban (403 `empresa_pendiente` / `empresa_rechazada`); si la rechazaron, se vuelve a mandar con la misma contraseña |
| `GET /api/empresas/{id}` | pública | Vitrina de una empresa aprobada: `{empresa: {id, nombre, localidad, telefono, sitio_web, desde}, vehiculos}` (sus autos publicados). 404 si no existe o no está aprobada |
| `GET /api/vehiculos/mercado` | pública | Los autos de Explorar (id, marca, modelo, año, km y precio de las publicaciones disponibles): los lee el scraper (`bin/precios-explorar.php`, con la dirección de `MARCAS_URL`) para calcular su precio contra el mercado y dejarlo en el documento `explorar/precios` de Firestore. El back lo copia a `vehiculo_mercado` la primera vez que alguien abre Explorar después de la corrida (como mucho una lectura por hora). También se calcula al aprobar cada publicación |
| `POST /api/admin/precios-mercado` | `parametros.editar` | Recalcula a mano el precio contra el mercado de todas las publicaciones (una lectura de Firestore por modelo) → `{autos, modelos}` |
| `GET /api/empresa/panel?dias=7\|30\|90` | cuenta de empresa | Su panel: `resumen` (visitas y las del período anterior, favoritos, consultas, inspecciones pedidas, autos activos), `mas_vista`, `serie` (visitas por día) y `autos` (por publicación: visitas, favoritos, consultas, inspecciones, consultó/visitó, días publicada y `mercado`: % contra el precio típico de autos parecidos de otros sitios) |
| `GET /api/admin/solicitudes-empresa?estado=` / `PATCH /api/admin/solicitudes-empresa/{id}` | `usuarios.editar` | La cola de "Sumá tu concesionaria" y aprobar `{aprobar: true}` o rechazar `{aprobar: false, motivo}` |
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
5. **Mercado Pago (opcional):** `MP_ACTIVO=true` y `MP_ACCESS_TOKEN` (el de la cuenta vendedora; en dev, la de prueba) prenden el cobro de las inspecciones con Checkout Pro. Hace falta la migración 031 y `FRONT_URL` (el primero es adonde vuelve el comprador después de pagar). Con `MP_ACTIVO=false` o sin las variables, el pago sigue simulado. El aviso se configura en Mercado Pago → la aplicación → Webhooks, evento *Order*, URL `https://<back>/api/pagos/mercadopago`.
6. Cada merge a la rama del entorno redespliega solo. Errores: pestaña **Logs** de Render. `GET /health` responde sin tocar la base.

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

El detalle completo (cookie, CSRF, permisos, archivos, encabezados del front, HTTPS, secretos y lo que falta) está en [`docs/seguridad.md`](docs/seguridad.md).

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
