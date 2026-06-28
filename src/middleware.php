<?php

declare(strict_types=1);

use JimTools\JwtAuth\Decoder\FirebaseDecoder;
use JimTools\JwtAuth\Exceptions\AuthorizationException;
use JimTools\JwtAuth\Middleware\JwtAuthentication;
use JimTools\JwtAuth\Options;
use JimTools\JwtAuth\Rules\RequestMethodRule;
use JimTools\JwtAuth\Rules\RequestPathRule;
use JimTools\JwtAuth\Secret;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tuupola\Middleware\CorsMiddleware;

return function (App $app): void {
    // Parseo de body JSON/form
    $app->addBodyParsingMiddleware();

    // Routing
    $app->addRoutingMiddleware();

    // CORS: la app de login vive en otro subdominio (cross-origin).
    // Orígenes permitidos configurables por .env (CORS_ORIGINS, separados por coma).
    $origins = array_values(array_filter(array_map(
        'trim',
        explode(',', $_ENV['CORS_ORIGINS'] ?? '*')
    )));

    // Anti-CSRF (defensa en profundidad): en métodos de escritura, si la petición
    // trae cabecera Origin, debe estar en la allowlist. Un navegador siempre envía
    // Origin en una escritura cross-site, así que esto frena CSRF desde otra web.
    // Si no hay Origin (curl/Postman/servidor) se permite: el JWT es la barrera
    // principal de /admin/*. Con CORS_ORIGINS='*' (local) se omite por completo.
    $allowAll = $origins === [] || in_array('*', $origins, true);
    $app->add(function (Request $request, $handler) use ($app, $origins, $allowAll): Response {
        $esEscritura = in_array(
            strtoupper($request->getMethod()),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true
        );
        $origin = $request->getHeaderLine('Origin');
        if (!$allowAll && $esEscritura && $origin !== '' && !in_array($origin, $origins, true)) {
            $response = $app->getResponseFactory()->createResponse(403);
            $response->getBody()->write(json_encode(['error' => 'Origen no permitido']));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    });

    $app->add(new CorsMiddleware([
        'origin'        => $origins === [] ? ['*'] : $origins,
        'methods'       => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'headers.allow' => ['Authorization', 'Content-Type'],
        'credentials'   => false,
        'cache'         => 86400,
    ]));

    // Cabeceras de seguridad
    $app->add(function (Request $request, $handler): Response {
        $response = $handler->handle($request);

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer');
    });

    // Autenticación JWT (solo si está habilitada y hay secreto configurado).
    // En local (AUTH_ENABLED=false) se omite para poder probar las rutas.
    $authEnabled = filter_var($_ENV['AUTH_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOL);
    $jwtSecret   = $_ENV['JWT_SECRET'] ?? '';

    if ($authEnabled && $jwtSecret !== '') {
        $secure = filter_var($_ENV['APP_SECURE'] ?? 'true', FILTER_VALIDATE_BOOL);

        // Prefijo de la API ('/api' en producción, '' en local) para las reglas
        $base   = rtrim($_ENV['APP_BASE_PATH'] ?? '', '/');
        $paths  = [$base === '' ? '/' : $base];          // proteger toda la API
        $ignore = [                                       // rutas públicas
            $base . '/login',
            $base . '/login/verify',
            $base . '/health',
            $base . '/convocatorias',                     // lectura pública (GET)
        ];

        $app->add(new JwtAuthentication(
            new Options(isSecure: $secure),               // true = solo HTTPS
            new FirebaseDecoder(new Secret($jwtSecret, 'HS256')),
            [
                new RequestMethodRule(),                  // ignora OPTIONS (CORS)
                new RequestPathRule($paths, $ignore),
            ]
        ));
    }

    // Manejo de errores. En producción APP_DEBUG=false => no filtrar stack traces.
    $displayErrors   = filter_var($_ENV['APP_DEBUG'] ?? 'true', FILTER_VALIDATE_BOOL);
    $errorMiddleware = $app->addErrorMiddleware($displayErrors, true, true);

    // Respuesta JSON 401 cuando la autenticación JWT falla
    $errorMiddleware->setErrorHandler(
        AuthorizationException::class,
        function (Request $request) use ($app): Response {
            $response = $app->getResponseFactory()->createResponse(401);
            $response->getBody()->write(json_encode(['error' => 'No autorizado']));

            return $response->withHeader('Content-Type', 'application/json');
        }
    );
};
