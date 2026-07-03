<?php

declare(strict_types=1);

use App\Controller\UserController;
use Slim\App;

/**
 * Rutas de gestión de usuarios (CRUD).
 * - Admin puede gestionar todos los usuarios
 * - Todos los usuarios autenticados pueden cambiar su contraseña
 */
return function (App $app): void {
    // Admin: Listar usuarios
    $app->get('/admin/users', [UserController::class, 'adminIndex']);

    // Admin: Crear usuario
    $app->post('/admin/users', [UserController::class, 'adminStore']);

    // Admin: Actualizar usuario
    $app->put('/admin/users/{id}', [UserController::class, 'adminUpdate']);

    // Admin: Eliminar usuario
    $app->delete('/admin/users/{id}', [UserController::class, 'adminDelete']);

    // Admin: Resetear contraseña de usuario
    $app->post('/admin/users/{id}/reset-password', [UserController::class, 'adminResetPassword']);

    // Todo usuario autenticado: Cambiar propia contraseña
    $app->post('/users/change-password', [UserController::class, 'changePassword']);

    // Todo usuario autenticado: Obtener datos propios
    $app->get('/users/me', [UserController::class, 'me']);
};
