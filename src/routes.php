<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\HealthController;
use Slim\App;

/**
 * Rutas de autenticación y salud. El mapeo URL → controlador vive aquí;
 * la lógica está en App\Controller\* (arquitectura MVC).
 */
return function (App $app): void {
    $app->get('/health', [HealthController::class, 'index']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->post('/login/verify', [AuthController::class, 'verify']);
    $app->get('/me', [AuthController::class, 'me']);
};
