<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Exige que el JWT traiga el módulo de esta API en el claim `modulos`.
 *
 * Se registra ANTES que JwtAuthentication para que, por el orden LIFO de Slim, se ejecute
 * DESPUÉS (con los claims ya en el atributo `token`). Sin atributo la ruta es pública
 * (JwtAuthentication ya rechazó las protegidas sin token).
 *
 * Un token sin `modulos` se rechaza: antes pasaba, y con un JWT_SECRET compartido cualquier
 * token firmado (de otra app o fabricado) entraba a /admin/*.
 */
final class RequiereModulo implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly string $modulo,
        private readonly string $mensaje,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $token = $request->getAttribute('token');
        if ($token === null) {
            return $handler->handle($request);
        }

        $modulos = is_array($token) ? ($token['modulos'] ?? null) : null;
        if (!is_array($modulos) || !in_array($this->modulo, $modulos, true)) {
            $response = $this->responses->createResponse(403);
            $response->getBody()->write((string) json_encode(['error' => $this->mensaje], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
