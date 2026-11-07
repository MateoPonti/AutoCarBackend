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
use AutoCar\MetaBusqueda\MetaBusquedaController;
use AutoCar\Publicaciones\PublicacionController;
use AutoCar\Core\Router;

/** @var Router $router */

// Público
$router->get('/api/vehiculos', [CatalogoController::class, 'index']);
$router->get('/api/vehiculos/{id}', [CatalogoController::class, 'detalle']);
$router->get('/api/marcas', [CatalogoController::class, 'marcas']);
$router->get('/api/fotos/{id}', [PublicacionController::class, 'foto']); // controla visibilidad adentro

// Publicaciones del usuario logueado
$router->get('/api/mis-vehiculos', [PublicacionController::class, 'index'], 'auth');
$router->post('/api/mis-vehiculos', [PublicacionController::class, 'crear'], 'auth');
$router->put('/api/mis-vehiculos/{id}', [PublicacionController::class, 'actualizar'], 'auth');
$router->patch('/api/mis-vehiculos/{id}/estado', [PublicacionController::class, 'cambiarEstado'], 'auth');
$router->delete('/api/mis-vehiculos/{id}', [PublicacionController::class, 'eliminar'], 'auth');

// Chat entre interesado y vendedor (sólo los participantes ven cada conversación)
$router->post('/api/vehiculos/{id}/conversacion', [ChatController::class, 'abrir'], 'auth');
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
$router->get('/api/autos-externos/{id}', [MetaBusquedaController::class, 'autoExterno'], 'auth');

// Autenticación
$router->post('/api/registro', [AuthController::class, 'registro']);
$router->post('/api/login', [AuthController::class, 'login']);
$router->post('/api/logout', [AuthController::class, 'logout']);
$router->get('/api/me', [AuthController::class, 'me'], 'auth');

// Vehículos: el moderador revisa las publicaciones; el admin además hace el ABM del catálogo (tiene todos los permisos).
$verVehiculos = 'permiso:vehiculos.moderar|vehiculos.gestionar';
$router->get('/api/admin/vehiculos', [AdminVehiculoController::class, 'index'], $verVehiculos);
$router->get('/api/admin/vehiculos/{id}', [AdminVehiculoController::class, 'detalle'], $verVehiculos);
$router->patch('/api/admin/vehiculos/{id}/estado', [AdminVehiculoController::class, 'moderar'], 'permiso:vehiculos.moderar');
$router->post('/api/admin/vehiculos', [AdminVehiculoController::class, 'crear'], 'permiso:vehiculos.gestionar');
$router->put('/api/admin/vehiculos/{id}', [AdminVehiculoController::class, 'actualizar'], 'permiso:vehiculos.gestionar');
$router->delete('/api/admin/vehiculos/{id}', [AdminVehiculoController::class, 'eliminar'], 'permiso:vehiculos.gestionar');

// Usuarios (admin): ver datos, editar nombre y rol, baja lógica
// Métricas de la app (admin)
$router->get('/api/admin/metricas', [AdminMetricasController::class, 'index'], 'permiso:metricas.ver');

$router->get('/api/admin/roles', [AdminUsuarioController::class, 'roles'], 'permiso:usuarios.ver');
$router->get('/api/admin/usuarios', [AdminUsuarioController::class, 'index'], 'permiso:usuarios.ver');
$router->get('/api/admin/usuarios/{id}', [AdminUsuarioController::class, 'detalle'], 'permiso:usuarios.ver');
$router->patch('/api/admin/usuarios/{id}', [AdminUsuarioController::class, 'actualizar'], 'permiso:usuarios.editar');
$router->delete('/api/admin/usuarios/{id}', [AdminUsuarioController::class, 'eliminar'], 'permiso:usuarios.editar');
