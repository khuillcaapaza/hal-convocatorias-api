<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\AuthController;
use App\Model\LoginCodigoModel;
use App\Model\UsuarioModel;
use App\Support\Mailer;
use RuntimeException;
use Tests\TestCase;

final class AuthControllerTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
        $_ENV['JWT_SECRET'] = 'secreto-de-prueba-con-longitud-mas-que-suficiente-1234567890';
        $_ENV['AUTH_2FA_DEV'] = 'false';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
    }

    /** @return array<string,mixed> Usuario activo de prueba. */
    private function usuario(): array
    {
        return [
            'id' => 1, 'usuario' => 'admin', 'email' => 'admin@test',
            'nombre' => 'Admin', 'rol' => 'admin',
            'password_hash' => password_hash('Secreta123', PASSWORD_DEFAULT),
        ];
    }

    private function auth(
        ?UsuarioModel $usuarios = null,
        ?LoginCodigoModel $codigos = null,
        ?Mailer $mailer = null
    ): AuthController {
        return new AuthController(
            $usuarios ?? $this->createMock(UsuarioModel::class),
            $codigos ?? $this->createMock(LoginCodigoModel::class),
            $mailer ?? $this->createMock(Mailer::class)
        );
    }

    // ── login ─────────────────────────────────────────────────────────

    public function testLoginCamposObligatorios(): void
    {
        $resp = $this->auth()->login($this->request('POST', ['email' => '']), $this->response());
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testLoginUsuarioInexistente(): void
    {
        $usuarios = $this->createMock(UsuarioModel::class);
        $usuarios->method('buscarActivoPorEmail')->willReturn(null);

        $resp = $this->auth($usuarios)->login(
            $this->request('POST', ['email' => 'no@test', 'password' => 'x']),
            $this->response()
        );
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testLoginPasswordIncorrecta(): void
    {
        $usuarios = $this->createMock(UsuarioModel::class);
        $usuarios->method('buscarActivoPorEmail')->willReturn($this->usuario());

        $resp = $this->auth($usuarios)->login(
            $this->request('POST', ['email' => 'admin@test', 'password' => 'mala']),
            $this->response()
        );
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testLoginExitosoModoDev(): void
    {
        $_ENV['AUTH_2FA_DEV'] = 'true';

        $usuarios = $this->createMock(UsuarioModel::class);
        $usuarios->method('buscarActivoPorEmail')->willReturn($this->usuario());
        $codigos = $this->createMock(LoginCodigoModel::class);
        $codigos->expects($this->once())->method('crear');
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects($this->never())->method('enviar');

        $resp = $this->auth($usuarios, $codigos, $mailer)->login(
            $this->request('POST', ['email' => 'admin@test', 'password' => 'Secreta123']),
            $this->response()
        );

        $body = $this->jsonBody($resp);
        $this->assertTrue($body['requiere2fa']);
        $this->assertArrayHasKey('dev_codigo', $body);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $body['dev_codigo']);
    }

    public function testLoginExitosoEnviaCorreo(): void
    {
        $usuarios = $this->createMock(UsuarioModel::class);
        $usuarios->method('buscarActivoPorEmail')->willReturn($this->usuario());
        $codigos = $this->createMock(LoginCodigoModel::class);
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects($this->once())->method('enviar');

        $resp = $this->auth($usuarios, $codigos, $mailer)->login(
            $this->request('POST', ['email' => 'admin@test', 'password' => 'Secreta123']),
            $this->response()
        );

        $body = $this->jsonBody($resp);
        $this->assertTrue($body['requiere2fa']);
        $this->assertArrayNotHasKey('dev_codigo', $body);
    }

    public function testLoginDevuelve502SiFallaElCorreo(): void
    {
        $usuarios = $this->createMock(UsuarioModel::class);
        $usuarios->method('buscarActivoPorEmail')->willReturn($this->usuario());
        $mailer = $this->createMock(Mailer::class);
        $mailer->method('enviar')->willThrowException(new RuntimeException('smtp'));

        $resp = $this->auth($usuarios, null, $mailer)->login(
            $this->request('POST', ['email' => 'admin@test', 'password' => 'Secreta123']),
            $this->response()
        );
        $this->assertSame(502, $resp->getStatusCode());
    }

    // ── verify ────────────────────────────────────────────────────────

    public function testVerifyCamposObligatorios(): void
    {
        $resp = $this->auth()->verify($this->request('POST', ['email' => 'admin@test', 'codigo' => '']), $this->response());
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testVerifyUsuarioInexistente(): void
    {
        $usuarios = $this->createMock(UsuarioModel::class);
        $usuarios->method('buscarActivoPorEmail')->willReturn(null);

        $resp = $this->auth($usuarios)->verify(
            $this->request('POST', ['email' => 'no@test', 'codigo' => '123456']),
            $this->response()
        );
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testVerifySinCodigoVigente(): void
    {
        $usuarios = $this->createMock(UsuarioModel::class);
        $usuarios->method('buscarActivoPorEmail')->willReturn($this->usuario());
        $codigos = $this->createMock(LoginCodigoModel::class);
        $codigos->method('buscarVigentePorUsuario')->willReturn(null);

        $resp = $this->auth($usuarios, $codigos)->verify(
            $this->request('POST', ['email' => 'admin@test', 'codigo' => '123456']),
            $this->response()
        );
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testVerifyDemasiadosIntentos(): void
    {
        $usuarios = $this->createMock(UsuarioModel::class);
        $usuarios->method('buscarActivoPorEmail')->willReturn($this->usuario());
        $codigos = $this->createMock(LoginCodigoModel::class);
        $codigos->method('buscarVigentePorUsuario')->willReturn([
            'id' => 5, 'codigo_hash' => password_hash('123456', PASSWORD_DEFAULT), 'intentos' => 5,
        ]);
        $codigos->expects($this->once())->method('marcarUsado');

        $resp = $this->auth($usuarios, $codigos)->verify(
            $this->request('POST', ['email' => 'admin@test', 'codigo' => '123456']),
            $this->response()
        );
        $this->assertSame(429, $resp->getStatusCode());
    }

    public function testVerifyCodigoIncorrecto(): void
    {
        $usuarios = $this->createMock(UsuarioModel::class);
        $usuarios->method('buscarActivoPorEmail')->willReturn($this->usuario());
        $codigos = $this->createMock(LoginCodigoModel::class);
        $codigos->method('buscarVigentePorUsuario')->willReturn([
            'id' => 5, 'codigo_hash' => password_hash('999999', PASSWORD_DEFAULT), 'intentos' => 0,
        ]);
        $codigos->expects($this->once())->method('incrementarIntentos');

        $resp = $this->auth($usuarios, $codigos)->verify(
            $this->request('POST', ['email' => 'admin@test', 'codigo' => '123456']),
            $this->response()
        );
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testVerifyExitosoEmiteToken(): void
    {
        $usuarios = $this->createMock(UsuarioModel::class);
        $usuarios->method('buscarActivoPorEmail')->willReturn($this->usuario());
        $usuarios->expects($this->once())->method('registrarAcceso');
        $codigos = $this->createMock(LoginCodigoModel::class);
        $codigos->method('buscarVigentePorUsuario')->willReturn([
            'id' => 5, 'codigo_hash' => password_hash('123456', PASSWORD_DEFAULT), 'intentos' => 0,
        ]);
        $codigos->expects($this->once())->method('marcarUsado');

        $resp = $this->auth($usuarios, $codigos)->verify(
            $this->request('POST', ['email' => 'admin@test', 'codigo' => '123-456']),
            $this->response()
        );

        $body = $this->jsonBody($resp);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertNotEmpty($body['token']);
        $this->assertSame('admin', $body['usuario']['usuario']);
    }

    // ── me ────────────────────────────────────────────────────────────

    public function testMeSinClaims(): void
    {
        $resp = $this->auth()->me($this->request(), $this->response());
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testMeConClaims(): void
    {
        $resp = $this->auth()->me(
            $this->request('GET', null, [], [], ['token' => ['sub' => 1, 'usuario' => 'admin']]),
            $this->response()
        );

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('admin', $this->jsonBody($resp)['usuario']['usuario']);
    }
}
