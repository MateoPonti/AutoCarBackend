<?php
/**
 * Todas las rutas de la API. El tercer argumento es el guard que se aplica en el servidor:
 *   'auth' = token válido, 'admin' = token válido + rol administrador (consultado en la base).
 */
declare(strict_types=1);

/** @var Router $router */

// Público
$router->get('/api/vehiculos', [CatalogoController::class, 'index']);
$router->get('/api/vehiculos/{id}', [CatalogoController::class, 'detalle']);
$router->get('/api/marcas', [CatalogoController::class, 'marcas']);

// Autenticación
$router->post('/api/registro', [AuthController::class, 'registro']);
$router->post('/api/login', [AuthController::class, 'login']);
$router->get('/api/me', [AuthController::class, 'me'], 'auth');

// Administración (solo rol admin)
$router->get('/api/admin/vehiculos', [AdminVehiculoController::class, 'index'], 'admin');
$router->post('/api/admin/vehiculos', [AdminVehiculoController::class, 'crear'], 'admin');
$router->put('/api/admin/vehiculos/{id}', [AdminVehiculoController::class, 'actualizar'], 'admin');
$router->delete('/api/admin/vehiculos/{id}', [AdminVehiculoController::class, 'eliminar'], 'admin');
