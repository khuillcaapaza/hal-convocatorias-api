<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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

    /**
     * Parsea el body JSON de la petición (descarta valores nulos/vacíos).
     */
    protected function parseJson(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        return array_filter($body, function ($v) { return $v !== null && $v !== ''; });
    }

    /**
     * Obtiene los claims del JWT del request.
     */
    private function getToken(Request $request): ?array
    {
        $token = $request->getAttribute('token');
        return is_array($token) ? $token : null;
    }

    /**
     * Obtiene el ID del usuario autenticado del JWT.
     */
    protected function userId(Request $request): ?int
    {
        $token = $this->getToken($request);
        return $token ? ((int) ($token['sub'] ?? null) ?: null) : null;
    }

    /**
     * Obtiene el rol del usuario autenticado del JWT.
     */
    protected function userRole(Request $request): ?string
    {
        $token = $this->getToken($request);
        return $token ? ($token['rol'] ?? null) : null;
    }

    /**
     * Obtiene los datos completos del usuario autenticado del JWT.
     */
    protected function userData(Request $request): ?array
    {
        return $this->getToken($request);
    }
}
