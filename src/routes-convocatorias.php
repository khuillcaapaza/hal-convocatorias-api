<?php

declare(strict_types=1);

use App\Controller\ConvocatoriaController;
use Slim\App;

/**
 * Rutas de convocatorias.
 *
 * Lectura pública (GET /convocatorias) y CRUD protegido por JWT bajo
 * /admin/convocatorias. La lógica vive en App\Controller\ConvocatoriaController.
 */
return function (App $app): void {
    // Lectura pública.
    $app->get('/convocatorias', [ConvocatoriaController::class, 'index']);
    $app->get('/convocatorias/{slug}', [ConvocatoriaController::class, 'show']);

    // Administración (requiere JWT).
    $app->get('/admin/convocatorias', [ConvocatoriaController::class, 'adminIndex']);
    $app->get('/admin/convocatorias/{slug}', [ConvocatoriaController::class, 'adminShow']);
    $app->post('/admin/convocatorias', [ConvocatoriaController::class, 'store']);
    $app->put('/admin/convocatorias/{slug}', [ConvocatoriaController::class, 'update']);
    $app->delete('/admin/convocatorias/{slug}', [ConvocatoriaController::class, 'destroy']);

    // Archivos de una convocatoria.
    $app->post('/admin/convocatorias/{slug}/archivos', [ConvocatoriaController::class, 'addArchivo']);
    $app->delete('/admin/convocatorias/{slug}/archivos/{id:[0-9]+}', [ConvocatoriaController::class, 'deleteArchivo']);
};
