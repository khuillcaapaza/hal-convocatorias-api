<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Controlador base. Aporta el helper común de respuesta JSON que antes se
 * repetía como closure en cada archivo de rutas.
 */
abstract class Controller
{
    protected function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
