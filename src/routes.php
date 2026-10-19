<?php
/**
 * Todas las rutas de la aplicación. El tercer argumento es el guard que se aplica en el servidor:
 *   'auth' = sesión iniciada, 'admin' = rol administrador.
 * Todos los POST pasan además por la verificación CSRF de public/index.php.
 */
declare(strict_types=1);

/** @var Router $router */

// Público
$router->get('/', [CatalogoController::class, 'index']);
$router->get('/vehiculos', [CatalogoController::class, 'index']);
$router->get('/vehiculos/{id}', [CatalogoController::class, 'detalle']);

// Autenticación
$router->get('/login', [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/registro', [AuthController::class, 'registroForm']);
$router->post('/registro', [AuthController::class, 'registro']);
$router->post('/logout', [AuthController::class, 'logout'], 'auth');

// Administración (solo rol admin)
$router->get('/admin/vehiculos', [AdminVehiculoController::class, 'index'], 'admin');
$router->get('/admin/vehiculos/nuevo', [AdminVehiculoController::class, 'nuevo'], 'admin');
$router->post('/admin/vehiculos', [AdminVehiculoController::class, 'guardar'], 'admin');
$router->get('/admin/vehiculos/{id}/editar', [AdminVehiculoController::class, 'editar'], 'admin');
$router->post('/admin/vehiculos/{id}', [AdminVehiculoController::class, 'actualizar'], 'admin');
$router->post('/admin/vehiculos/{id}/eliminar', [AdminVehiculoController::class, 'eliminar'], 'admin');
