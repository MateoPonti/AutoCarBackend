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
use AutoCar\Catalogo\CatalogoController;
use AutoCar\Chat\ChatController;
use AutoCar\Comparador\ComparadorController;
use AutoCar\Favoritos\FavoritoController;
use AutoCar\Inspecciones\AdminReclamoController;
use AutoCar\Inspecciones\InspeccionController;
use AutoCar\Inspecciones\MecanicoController;
use AutoCar\Inspecciones\SolicitudTallerController;
use AutoCar\MetaBusqueda\MetaBusquedaController;
use AutoCar\Publicaciones\PublicacionController;
use AutoCar\Core\Router;

/** @var Router $router */

// Público
$router->get('/api/vehiculos', [CatalogoController::class, 'index']);
$router->get('/api/vehiculos/{id}', [CatalogoController::class, 'detalle']);
// Comparador de precios: la publicación frente a los autos iguales de MercadoLibre, Kavak y Autocosmos
$router->get('/api/vehiculos/{id}/comparacion', [ComparadorController::class, 'vehiculo']);
$router->get('/api/marcas', [CatalogoController::class, 'marcas']);
$router->get('/api/fotos/{id}', [PublicacionController::class, 'foto']); // controla visibilidad adentro

// Publicaciones del usuario logueado
$router->get('/api/mis-vehiculos', [PublicacionController::class, 'index'], 'auth');
$router->post('/api/mis-vehiculos', [PublicacionController::class, 'crear'], 'auth');
$router->get('/api/mis-vehiculos/{id}', [PublicacionController::class, 'detalle'], 'auth');
$router->put('/api/mis-vehiculos/{id}', [PublicacionController::class, 'actualizar'], 'auth');
$router->patch('/api/mis-vehiculos/{id}/estado', [PublicacionController::class, 'cambiarEstado'], 'auth');
$router->delete('/api/mis-vehiculos/{id}', [PublicacionController::class, 'eliminar'], 'auth');
// Publicar por partes: las fotos se pueden agregar o sacar después de crear la publicación
$router->post('/api/mis-vehiculos/{id}/fotos', [PublicacionController::class, 'agregarFotos'], 'auth');
$router->delete('/api/mis-vehiculos/{id}/fotos/{foto}', [PublicacionController::class, 'eliminarFoto'], 'auth');

// Chat entre interesado y vendedor (sólo los participantes ven cada conversación)
$router->post('/api/vehiculos/{id}/conversacion', [ChatController::class, 'abrir'], 'auth');

// Favoritos del usuario logueado
$router->get('/api/favoritos', [FavoritoController::class, 'index'], 'auth');
$router->put('/api/favoritos/{id}', [FavoritoController::class, 'agregar'], 'auth');
$router->delete('/api/favoritos/{id}', [FavoritoController::class, 'sacar'], 'auth');
$router->get('/api/conversaciones', [ChatController::class, 'index'], 'auth');
$router->get('/api/conversaciones/{id}/mensajes', [ChatController::class, 'mensajes'], 'auth');
$router->post('/api/conversaciones/{id}/mensajes', [ChatController::class, 'enviar'], 'auth');

// Meta-búsqueda: cada usuario ve solo sus búsquedas (las rutas fijas no chocan con {id}, que acepta solo dígitos)
$router->get('/api/meta-busquedas', [MetaBusquedaController::class, 'index'], 'auth');
$router->post('/api/meta-busquedas', [MetaBusquedaController::class, 'crear'], 'auth');
$router->get('/api/meta-busquedas/marcas', [MetaBusquedaController::class, 'marcasYModelos'], 'auth');
$router->get('/api/meta-busquedas/avisos', [MetaBusquedaController::class, 'avisos'], 'auth');
$router->get('/api/meta-busquedas/{id}', [MetaBusquedaController::class, 'reporte'], 'auth');
$router->put('/api/meta-busquedas/{id}', [MetaBusquedaController::class, 'actualizar'], 'auth');
$router->delete('/api/meta-busquedas/{id}', [MetaBusquedaController::class, 'eliminar'], 'auth');
$router->post('/api/meta-busquedas/{id}/avisos/vistos', [MetaBusquedaController::class, 'marcarVistos'], 'auth');
$router->get('/api/autos-externos', [MetaBusquedaController::class, 'autoExterno'], 'auth'); // ?id=fuente~modelo~id

// Inspecciones: el mecánico que firma. El ranking de mecánicos es público.
$router->get('/api/mecanicos', [InspeccionController::class, 'mecanicos']);
// Comprador: pide, ve las suyas, cancela antes de que la acepten y reclama hasta 15 días después de la firma.
// El detalle lo ven el comprador, el mecánico asignado y quien resuelve reclamos.
$router->post('/api/inspecciones', [InspeccionController::class, 'crear'], 'auth');
$router->get('/api/mis-inspecciones', [InspeccionController::class, 'mias'], 'auth');
$router->get('/api/inspecciones/{id}', [InspeccionController::class, 'detalle'], 'auth');
$router->post('/api/inspecciones/{id}/cancelar', [InspeccionController::class, 'cancelar'], 'auth');
$router->post('/api/inspecciones/{id}/reclamo', [InspeccionController::class, 'reclamar'], 'auth');
// Reclamos: los resuelven el moderador y el admin
$router->get('/api/admin/reclamos', [AdminReclamoController::class, 'index'], 'permiso:reclamos.resolver');
$router->patch('/api/admin/reclamos/{id}', [AdminReclamoController::class, 'resolver'], 'permiso:reclamos.resolver');
// Lado del mecánico: cada uno ve y toca solo sus inspecciones (las de otro dan 404)
$mecanico = 'permiso:verificaciones.realizar';
$router->get('/api/mecanico/perfil', [MecanicoController::class, 'perfil'], $mecanico);
$router->put('/api/mecanico/perfil', [MecanicoController::class, 'guardarPerfil'], $mecanico);
$router->get('/api/mecanico/inspecciones', [MecanicoController::class, 'bandeja'], $mecanico);
$router->patch('/api/mecanico/inspecciones/{id}', [MecanicoController::class, 'cambiarEstado'], $mecanico);
$router->put('/api/mecanico/inspecciones/{id}/informe', [MecanicoController::class, 'guardarInforme'], $mecanico);
$router->post('/api/mecanico/inspecciones/{id}/firma', [MecanicoController::class, 'firmar'], $mecanico);
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

$router->get('/api/admin/roles', [AdminUsuarioController::class, 'roles'], 'permiso:usuarios.ver');
$router->get('/api/admin/usuarios', [AdminUsuarioController::class, 'index'], 'permiso:usuarios.ver');
$router->get('/api/admin/usuarios/{id}', [AdminUsuarioController::class, 'detalle'], 'permiso:usuarios.ver');
$router->patch('/api/admin/usuarios/{id}', [AdminUsuarioController::class, 'actualizar'], 'permiso:usuarios.editar');
$router->delete('/api/admin/usuarios/{id}', [AdminUsuarioController::class, 'eliminar'], 'permiso:usuarios.editar');
