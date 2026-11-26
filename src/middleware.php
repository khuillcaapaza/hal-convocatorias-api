<?php

declare(strict_types=1);

use JimTools\JwtAuth\Exceptions\AuthorizationException;
use JimTools\JwtAuth\Middleware\JwtAuthentication;
use JimTools\JwtAuth\Options;
use JimTools\JwtAuth\Rules\RequestMethodRule;
use JimTools\JwtAuth\Rules\RequestPathRule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\JwtNoConfigurado;
use App\Support\RequiereModulo;
use App\Support\TokenDecoder;
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
    $allowAll = $origins === [] || in_array('*', $origins, true);

    $aplicarCors = function (Request $request, Response $response) use ($origins, $allowAll): Response {
        $origin = $request->getHeaderLine('Origin');
        if ($allowAll) {
            return $response
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Vary', 'Origin');
        }
        if ($origin !== '' && in_array($origin, $origins, true)) {
            return $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
        }
        return $response;
    };

    // Anti-CSRF (defensa en profundidad): en métodos de escritura, si la petición
    // trae cabecera Origin, debe estar en la allowlist. Un navegador siempre envía
    // Origin en una escritura cross-site, así que esto frena CSRF desde otra web.
    // Si no hay Origin (curl/Postman/servidor) se permite: el JWT es la barrera
    // principal de /admin/*. Con CORS_ORIGINS='*' (local) se omite por completo.
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
        $response = $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            // Esta API solo devuelve JSON: no debe cargar ni embeber recurso alguno.
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
            // Desactiva APIs del navegador que una respuesta JSON nunca necesita.
            ->withHeader('Permissions-Policy', 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');

        // HSTS solo si la petición llega por HTTPS (los navegadores la ignoran en HTTP).
        $esHttps = $request->getUri()->getScheme() === 'https'
            || strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
        if ($esHttps) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    });

    // Autenticación JWT. Tokens de hal-auth-api: EdDSA con JWT_PUBLIC_KEYS (recomendado) y HS256
    // con JWT_SECRET solo durante la transición (decisión 0002 de lbyte-contexto).
    // Segura por defecto: para probar sin token en local pon AUTH_ENABLED=false en el .env.
    $authEnabled = filter_var($_ENV['AUTH_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOL);

    if ($authEnabled) {
        $secure  = filter_var($_ENV['APP_SECURE'] ?? 'true', FILTER_VALIDATE_BOOL);
        $decoder = TokenDecoder::fromEnv();

        // Prefijo de la API ('/api' en producción, '' en local) para las reglas
        $base   = rtrim($_ENV['APP_BASE_PATH'] ?? '', '/');
        $paths  = [$base === '' ? '/' : $base];          // proteger toda la API
        $ignore = [                                       // rutas públicas
            $base . '/health',
            $base . '/convocatorias',                     // lectura pública (GET)
        ];
        $reglas = [
            new RequestMethodRule(),                      // ignora OPTIONS (CORS)
            new RequestPathRule($paths, $ignore),
        ];

        if (!$decoder->configured()) {
            // Sin claves no se abre la API: las rutas protegidas responden 503.
            $app->add(new JwtNoConfigurado($app->getResponseFactory(), $reglas));
        } else {
            // Módulo obligatorio: un token sin `modulos` o sin 'convocatorias' recibe 403.
            // Se registra ANTES de JwtAuthentication para ejecutarse DESPUÉS (orden LIFO de Slim).
            $app->add(new RequiereModulo($app->getResponseFactory(), 'convocatorias', 'Sin acceso al módulo de convocatorias'));
            $app->add(new JwtAuthentication(
                new Options(isSecure: $secure),           // true = solo HTTPS
                $decoder,
                $reglas
            ));
        }
    }

    // Manejo de errores. En producción APP_DEBUG=false => no filtrar stack traces.
    $displayErrors   = filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOL);
    $errorMiddleware = $app->addErrorMiddleware($displayErrors, true, true);

    // Respuesta JSON 401 cuando la autenticación JWT falla
    $errorMiddleware->setErrorHandler(
        AuthorizationException::class,
        function (Request $request) use ($app, $aplicarCors): Response {
            $response = $app->getResponseFactory()->createResponse(401);
            $response->getBody()->write(json_encode(['error' => 'No autorizado']));
            $response = $response->withHeader('Content-Type', 'application/json');
            return $aplicarCors($request, $response);
        }
    );
};
