<?php
/**
 * Todas las rutas de la API. El tercer argumento es el guard que se aplica en el servidor:
 *   'auth' = cookie de sesión válida; 'permiso:x|y' = sesión válida + alguno de esos permisos.
 *   Los permisos de cada rol están en la base (tablas rol, permiso, rol_permiso), no acá.
 * Con guard, POST/PUT/PATCH/DELETE exigen también el header X-CSRF-Token (ver Router).
 */
declare(strict_types=1);

use AutoCar\Admin\AdminMetricasController;
use AutoCar\Admin\AdminUsuarioController;
use AutoCar\Admin\AdminVehiculoController;
use AutoCar\Auth\AuthController;
use AutoCar\Auth\PerfilController;
use AutoCar\Catalogo\CatalogoController;
use AutoCar\Chat\ChatController;
use AutoCar\Comparador\ComparadorController;
use AutoCar\Estadisticas\Eventos;
use AutoCar\Favoritos\FavoritoController;
use AutoCar\Inspecciones\AdminReclamoController;
use AutoCar\Inspecciones\FallaTipicaController;
use AutoCar\Inspecciones\InspeccionController;
use AutoCar\Inspecciones\MecanicoController;
use AutoCar\Inspecciones\PresupuestoController;
use AutoCar\Inspecciones\SolicitudTallerController;
use AutoCar\MetaBusqueda\MetaBusquedaController;
use AutoCar\Parametros\ParametroController;
use AutoCar\Publicaciones\PublicacionController;
use AutoCar\Soporte\SoporteController;
use AutoCar\Core\Router;

/** @var Router $router */

// Público
$router->get('/api/vehiculos', [CatalogoController::class, 'index']);
$router->get('/api/vehiculos/{id}', [CatalogoController::class, 'detalle']);
// Comparador de precios: la publicación frente a los autos iguales de MercadoLibre, Kavak y Autocosmos
$router->get('/api/vehiculos/{id}/comparacion', [ComparadorController::class, 'vehiculo']);
$router->get('/api/marcas', [CatalogoController::class, 'marcas']);
$router->get('/api/marcas-modelos', [CatalogoController::class, 'marcasModelos']);
$router->get('/api/fotos/{id}', [PublicacionController::class, 'foto']); // controla visibilidad adentro

// Publicaciones del usuario logueado
// Comprar y vender (guard 'usuario'): solo cuentas personales. Las del equipo (admin, moderador) y las de los
// talleres no publican, no piden mecánico, no guardan favoritos ni abren chats: para eso, una cuenta personal.
$router->get('/api/mis-vehiculos', [PublicacionController::class, 'index'], 'auth');
// Turnos de inspección de mis publicaciones: el vendedor los confirma (si el auto se vende antes, se caen solos)
$router->get('/api/mis-vehiculos/turnos', [InspeccionController::class, 'turnos'], 'auth');
$router->post('/api/mis-vehiculos', [PublicacionController::class, 'crear'], 'usuario');
$router->get('/api/mis-vehiculos/{id}', [PublicacionController::class, 'detalle'], 'auth');
$router->put('/api/mis-vehiculos/{id}', [PublicacionController::class, 'actualizar'], 'usuario');
$router->patch('/api/mis-vehiculos/{id}/estado', [PublicacionController::class, 'cambiarEstado'], 'usuario');
$router->delete('/api/mis-vehiculos/{id}', [PublicacionController::class, 'eliminar'], 'auth');
// Publicar por partes: las fotos se pueden agregar o sacar después de crear la publicación
$router->post('/api/mis-vehiculos/{id}/fotos', [PublicacionController::class, 'agregarFotos'], 'usuario');
$router->delete('/api/mis-vehiculos/{id}/fotos/{foto}', [PublicacionController::class, 'eliminarFoto'], 'auth');

// Chat entre interesado y vendedor (sólo los participantes ven cada conversación)
$router->post('/api/vehiculos/{id}/conversacion', [ChatController::class, 'abrir'], 'usuario');

// Favoritos del usuario logueado
$router->get('/api/favoritos', [FavoritoController::class, 'index'], 'auth');
$router->put('/api/favoritos/{id}', [FavoritoController::class, 'agregar'], 'usuario');
$router->delete('/api/favoritos/{id}', [FavoritoController::class, 'sacar'], 'auth');
$router->get('/api/conversaciones', [ChatController::class, 'index'], 'auth');
$router->get('/api/conversaciones/{id}/mensajes', [ChatController::class, 'mensajes'], 'auth');
$router->post('/api/conversaciones/{id}/mensajes', [ChatController::class, 'enviar'], 'auth');

// Meta-búsqueda: cada usuario ve solo sus búsquedas (las rutas fijas no chocan con {id}, que acepta solo dígitos)
$router->get('/api/meta-busquedas', [MetaBusquedaController::class, 'index'], 'auth');
$router->post('/api/meta-busquedas', [MetaBusquedaController::class, 'crear'], 'usuario');
$router->get('/api/meta-busquedas/conteo', [MetaBusquedaController::class, 'conteo'], 'auth');
$router->get('/api/meta-busquedas/avisos', [MetaBusquedaController::class, 'avisos'], 'auth');
$router->get('/api/meta-busquedas/{id}', [MetaBusquedaController::class, 'reporte'], 'auth');
$router->put('/api/meta-busquedas/{id}', [MetaBusquedaController::class, 'actualizar'], 'auth');
$router->delete('/api/meta-busquedas/{id}', [MetaBusquedaController::class, 'eliminar'], 'auth');
$router->post('/api/meta-busquedas/{id}/avisos/vistos', [MetaBusquedaController::class, 'marcarVistos'], 'auth');
$router->get('/api/autos-externos', [MetaBusquedaController::class, 'autoExterno'], 'auth'); // ?id=fuente~modelo~id

// Inspecciones: el mecánico que firma. El ranking de mecánicos es público.
$router->get('/api/mecanicos', [InspeccionController::class, 'mecanicos']);
// Idea B, fallas típicas por modelo (público): de internet, de los mecánicos y medidas en las inspecciones
$router->get('/api/fallas-tipicas', [FallaTipicaController::class, 'index']);
$router->post('/api/admin/fallas-tipicas', [FallaTipicaController::class, 'cargar'], 'permiso:parametros.editar');
// Comprador: pide, ve las suyas, cancela antes de que la acepten y reclama dentro de los días de reclamo (parámetro) después de la firma.
// El detalle lo ven el comprador, el mecánico asignado y quien resuelve reclamos.
$router->post('/api/inspecciones', [InspeccionController::class, 'crear'], 'usuario');
// Estadísticas: recomendaciones que el comprador vio y tocó (para el embudo del admin)
$router->post('/api/eventos', [Eventos::class, 'registrar'], 'usuario');
$router->get('/api/mis-inspecciones', [InspeccionController::class, 'mias'], 'auth');
$router->get('/api/inspecciones/{id}', [InspeccionController::class, 'detalle'], 'auth');
$router->get('/api/inspecciones/{id}/patente-foto', [InspeccionController::class, 'fotoPatente'], 'auth');
$router->get('/api/inspecciones/{id}/item-foto', [InspeccionController::class, 'fotoItem'], 'auth');
$router->post('/api/inspecciones/{id}/cancelar', [InspeccionController::class, 'cancelar'], 'auth');
$router->post('/api/inspecciones/{id}/reclamo', [InspeccionController::class, 'reclamar'], 'auth');
$router->post('/api/inspecciones/{id}/confirmar-turno', [InspeccionController::class, 'confirmarTurno'], 'auth');
// Idea A, costo total: el comprador pide los presupuestos de un informe firmado antes (los nuevos se piden solos al firmar)
$router->post('/api/inspecciones/{id}/presupuestos', [PresupuestoController::class, 'pedir'], 'auth');
// Créditos del comprador (el auto de una inspección anterior se vendió): se descuentan solos al pedir la próxima
$router->get('/api/mis-creditos', [InspeccionController::class, 'creditos'], 'auth');
// Reclamos: los resuelven el moderador y el admin
// Mensajes al equipo: contacto (público) y reportes (con sesión); el admin y el moderador los ven en el panel
$router->post('/api/contacto', [SoporteController::class, 'contactar']);
$router->post('/api/reportes', [SoporteController::class, 'reportar'], 'auth');
$router->get('/api/admin/contactos', [SoporteController::class, 'contactos'], 'permiso:usuarios.ver');
$router->patch('/api/admin/contactos/{id}', [SoporteController::class, 'leerContacto'], 'permiso:usuarios.ver');
$router->get('/api/admin/reportes', [SoporteController::class, 'reportes'], 'permiso:vehiculos.moderar');
$router->patch('/api/admin/reportes/{id}', [SoporteController::class, 'resolverReporte'], 'permiso:vehiculos.moderar');

$router->get('/api/admin/reclamos', [AdminReclamoController::class, 'index'], 'permiso:reclamos.resolver');
$router->patch('/api/admin/reclamos/{id}', [AdminReclamoController::class, 'resolver'], 'permiso:reclamos.resolver');
// Fondo de garantía: saldo, movimientos y el supuesto de la cuenta (solo paga reclamos)
$router->get('/api/admin/fondo', [AdminReclamoController::class, 'fondo'], 'permiso:metricas.ver|reclamos.resolver');
// Lado del mecánico: cada uno ve y toca solo sus inspecciones (las de otro dan 404)
$mecanico = 'permiso:verificaciones.realizar';
$router->get('/api/mecanico/perfil', [MecanicoController::class, 'perfil'], $mecanico);
$router->put('/api/mecanico/perfil', [MecanicoController::class, 'guardarPerfil'], $mecanico);
$router->get('/api/mecanico/inspecciones', [MecanicoController::class, 'bandeja'], $mecanico);
$router->patch('/api/mecanico/inspecciones/{id}', [MecanicoController::class, 'cambiarEstado'], $mecanico);
$router->put('/api/mecanico/inspecciones/{id}/informe', [MecanicoController::class, 'guardarInforme'], $mecanico);
$router->post('/api/mecanico/inspecciones/{id}/patente-foto', [MecanicoController::class, 'subirFotoPatente'], $mecanico);
$router->post('/api/mecanico/inspecciones/{id}/item-foto', [MecanicoController::class, 'subirFotoItem'], $mecanico);
$router->post('/api/mecanico/inspecciones/{id}/firma', [MecanicoController::class, 'firmar'], $mecanico);
// Presupuestos que le pidieron al taller (idea A): nunca de un auto que revisó él
$router->get('/api/mecanico/presupuestos', [PresupuestoController::class, 'bandeja'], $mecanico);
// Fallas típicas: el mecánico propone las que vio y vota las de los demás (confirmar o descartar)
$router->get('/api/mecanico/fallas-tipicas', [FallaTipicaController::class, 'delMecanico'], $mecanico);
$router->post('/api/mecanico/fallas-tipicas', [FallaTipicaController::class, 'proponer'], $mecanico);
$router->put('/api/mecanico/fallas-tipicas/{id}/voto', [FallaTipicaController::class, 'votar'], $mecanico);
$router->patch('/api/mecanico/presupuestos/{id}', [PresupuestoController::class, 'responder'], $mecanico);
// "Adherí tu taller": el usuario pide sumarse y un admin lo aprueba (pasa a rol mecanico) o lo rechaza.
// Aprobar le cambia el rol al usuario, por eso pide el mismo permiso que editar usuarios.
$router->post('/api/solicitudes-taller', [SolicitudTallerController::class, 'enviar']);
$router->get('/api/admin/solicitudes-taller', [SolicitudTallerController::class, 'index'], 'permiso:usuarios.editar');
$router->patch('/api/admin/solicitudes-taller/{id}', [SolicitudTallerController::class, 'resolver'], 'permiso:usuarios.editar');

// Autenticación
$router->post('/api/registro', [AuthController::class, 'registro']);
$router->post('/api/login', [AuthController::class, 'login']);
$router->post('/api/logout', [AuthController::class, 'logout']);
$router->get('/api/me', [AuthController::class, 'me'], 'auth');
// Mi perfil: nombre y foto (opcional). La foto se ve en el detalle de sus publicaciones y en el chat.
$router->put('/api/me', [PerfilController::class, 'actualizar'], 'auth');
$router->post('/api/me/foto', [PerfilController::class, 'subirFoto'], 'auth');
$router->delete('/api/me/foto', [PerfilController::class, 'borrarFoto'], 'auth');
// "¿Para qué buscás el auto?": obligatoria para las cuentas personales la primera vez que entran (las clasifica)
$router->get('/api/me/preferencias', [PerfilController::class, 'preferencias'], 'usuario');
$router->put('/api/me/preferencias', [PerfilController::class, 'guardarPreferencias'], 'usuario');
$router->get('/api/usuarios/{id}/foto', [PerfilController::class, 'foto']);

// Vehículos: el moderador y el admin revisan las publicaciones; solo el admin las borra. Nadie del panel publica ni edita:
// toda publicación tiene un vendedor, entra por POST /api/mis-vehiculos y la corrige solo su dueño.
$verVehiculos = 'permiso:vehiculos.moderar|vehiculos.gestionar';
$router->get('/api/admin/vehiculos', [AdminVehiculoController::class, 'index'], $verVehiculos);
$router->get('/api/admin/vehiculos/{id}', [AdminVehiculoController::class, 'detalle'], $verVehiculos);
$router->patch('/api/admin/vehiculos/{id}/estado', [AdminVehiculoController::class, 'moderar'], 'permiso:vehiculos.moderar');
$router->delete('/api/admin/vehiculos/{id}', [AdminVehiculoController::class, 'eliminar'], 'permiso:vehiculos.gestionar');

// Usuarios (admin): ver datos, editar nombre y rol, baja lógica
// Métricas de la app (admin)
$router->get('/api/admin/metricas', [AdminMetricasController::class, 'index'], 'permiso:metricas.ver');

// Parámetros del negocio (comisión, fondo, tope, días de reclamo, precio de la inspección): los cambia el admin.
// Los valores son públicos: el front muestra el desglose del precio y el rango que acepta el taller.
$router->get('/api/admin/parametros', [ParametroController::class, 'index'], 'permiso:parametros.editar');
$router->put('/api/admin/parametros', [ParametroController::class, 'actualizar'], 'permiso:parametros.editar');
$router->get('/api/parametros', [ParametroController::class, 'publicos']);

$router->get('/api/admin/roles', [AdminUsuarioController::class, 'roles'], 'permiso:usuarios.ver');
$router->get('/api/admin/usuarios', [AdminUsuarioController::class, 'index'], 'permiso:usuarios.ver');
$router->get('/api/admin/usuarios/{id}', [AdminUsuarioController::class, 'detalle'], 'permiso:usuarios.ver');
$router->patch('/api/admin/usuarios/{id}', [AdminUsuarioController::class, 'actualizar'], 'permiso:usuarios.editar');
$router->delete('/api/admin/usuarios/{id}', [AdminUsuarioController::class, 'eliminar'], 'permiso:usuarios.editar');
