<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\TokenDecoder;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * src/middleware.php completo (sin BD): módulo obligatorio, EdDSA/HS256 y cierre con 503
 * cuando la autenticación está activa pero no hay claves.
 */
final class MiddlewareAutenticacionTest extends TestCase
{
    private const MODULO  = 'convocatorias';
    private const PUBLICA = '/convocatorias';

    private array $envOriginal;
    private string $secreto;
    private string $privada;
    private string $publica;

    protected function setUp(): void
    {
        $this->envOriginal = $_ENV;
        $this->secreto = str_repeat('k', 64);
        $par = sodium_crypto_sign_keypair();
        $this->privada = JWT::urlsafeB64Encode(sodium_crypto_sign_secretkey($par));
        $this->publica = JWT::urlsafeB64Encode(sodium_crypto_sign_publickey($par));
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envOriginal;
    }

    private function app(array $env): App
    {
        $_ENV = $env + ['APP_BASE_PATH' => '', 'APP_SECURE' => 'true', 'APP_DEBUG' => 'false', 'CORS_ORIGINS' => 'https://panel.test'];
        $app = AppFactory::create();
        (require __DIR__ . '/../../src/middleware.php')($app);
        $ok = static function ($request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write('{"ok":true}');
            return $response;
        };
        $app->get('/admin/prueba', $ok);
        $app->get(self::PUBLICA, $ok);

        return $app;
    }

    private function pedir(App $app, string $ruta, ?string $token = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api.test' . $ruta);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $app->handle($request);
    }

    private function hs256(array $claims): string
    {
        return JWT::encode($claims + ['sub' => 1, 'exp' => time() + 60], $this->secreto, 'HS256');
    }

    private function eddsa(array $claims): string
    {
        $kid = TokenDecoder::kid((string) TokenDecoder::decodeKey($this->publica));

        return JWT::encode($claims + ['sub' => 1, 'exp' => time() + 60], $this->privada, 'EdDSA', $kid);
    }

    public function testTokenSinClaimModulosYaNoEntraAAdmin(): void
    {
        $app = $this->app(['AUTH_ENABLED' => 'true', 'JWT_SECRET' => $this->secreto]);

        self::assertSame(403, $this->pedir($app, '/admin/prueba', $this->hs256([]))->getStatusCode());
        self::assertSame(403, $this->pedir($app, '/admin/prueba', $this->hs256(['modulos' => 'convocatorias']))->getStatusCode());
        self::assertSame(403, $this->pedir($app, '/admin/prueba', $this->hs256(['modulos' => ['otro']]))->getStatusCode());
        self::assertSame(200, $this->pedir($app, '/admin/prueba', $this->hs256(['modulos' => [self::MODULO]]))->getStatusCode());
        self::assertSame(401, $this->pedir($app, '/admin/prueba')->getStatusCode());
        self::assertSame(200, $this->pedir($app, self::PUBLICA)->getStatusCode());
    }

    public function testAceptaEdDsaConClavePublicaYRechazaHs256SinSecreto(): void
    {
        $app = $this->app(['AUTH_ENABLED' => 'true', 'JWT_PUBLIC_KEYS' => $this->publica]);

        self::assertSame(200, $this->pedir($app, '/admin/prueba', $this->eddsa(['modulos' => [self::MODULO]]))->getStatusCode());
        // Con solo la clave pública, un HS256 (aunque alguien tenga el secreto viejo) no vale.
        self::assertSame(401, $this->pedir($app, '/admin/prueba', $this->hs256(['modulos' => [self::MODULO]]))->getStatusCode());
        // Tokens restringidos (MFA pendiente) no entran.
        self::assertSame(401, $this->pedir($app, '/admin/prueba', $this->eddsa(['modulos' => [self::MODULO], 'alcance' => 'mfa-pendiente']))->getStatusCode());
    }

    public function testSinClavesLasRutasProtegidasRespondenServicioNoDisponible(): void
    {
        // Antes: AUTH_ENABLED=true sin JWT_SECRET dejaba /admin abierto.
        $app = $this->app(['AUTH_ENABLED' => 'true']);

        self::assertSame(503, $this->pedir($app, '/admin/prueba')->getStatusCode());
        self::assertSame(200, $this->pedir($app, self::PUBLICA)->getStatusCode());
    }

    public function testAutenticacionActivaPorDefecto(): void
    {
        $app = $this->app([]);

        self::assertSame(503, $this->pedir($app, '/admin/prueba')->getStatusCode());
    }
}
