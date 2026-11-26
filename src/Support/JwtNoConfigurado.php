<?php

declare(strict_types=1);

namespace App\Support;

use JimTools\JwtAuth\Rules\RuleInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Autenticación activa pero sin JWT_PUBLIC_KEYS ni JWT_SECRET: las rutas protegidas responden
 * 503 en vez de quedar abiertas. Las públicas (mismas reglas que JwtAuthentication) siguen.
 */
final class JwtNoConfigurado implements MiddlewareInterface
{
    /** @param list<RuleInterface> $reglas */
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly array $reglas,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        foreach ($this->reglas as $regla) {
            if (!$regla($request)) {
                return $handler->handle($request);
            }
        }

        error_log('[api] jwt_no_configurado: define JWT_PUBLIC_KEYS (o JWT_SECRET durante la transición)');
        $response = $this->responses->createResponse(503);
        $response->getBody()->write((string) json_encode(
            ['error' => 'Autenticación no configurada. Revisa los logs del servidor.'],
            JSON_UNESCAPED_UNICODE
        ));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
