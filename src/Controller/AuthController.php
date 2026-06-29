<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\LoginCodigoModel;
use App\Model\UsuarioModel;
use App\Support\Controller;
use App\Support\Mailer;
use Firebase\JWT\JWT;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Autenticación en dos pasos (2FA por email):
 *   1. POST /login         email + contraseña  -> envía un código al email.
 *   2. POST /login/verify  email + código      -> emite el JWT.
 */
final class AuthController extends Controller
{
    /** Dígitos del código de verificación. */
    private const CODIGO_LONGITUD = 6;

    /** Validez del código en segundos (10 minutos). */
    private const CODIGO_TTL = 600;

    /** Máximo de intentos de verificación por código. */
    private const CODIGO_MAX_INTENTOS = 5;

    private UsuarioModel $usuarios;
    private LoginCodigoModel $codigos;

    public function __construct()
    {
        $this->usuarios = new UsuarioModel();
        $this->codigos  = new LoginCodigoModel();
    }

    /**
     * POST /login: primer paso. Valida email + contraseña y, si son correctos,
     * genera un código de un solo uso y lo envía por email. NO emite el JWT.
     */
    public function login(Request $request, Response $response): Response
    {
        $data     = (array) $request->getParsedBody();
        $email    = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->json($response, ['error' => 'Email y contraseña son obligatorios'], 422);
        }

        $user = $this->usuarios->buscarActivoPorEmail($email);

        // Mensaje genérico: no revelar si el email existe o no.
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return $this->json($response, ['error' => 'Credenciales inválidas'], 401);
        }

        // Generar y almacenar (hasheado) el código de verificación.
        $codigo = $this->generarCodigo();
        $expira = date('Y-m-d H:i:s', time() + self::CODIGO_TTL);
        $this->codigos->crear(
            (int) $user['id'],
            password_hash($codigo, PASSWORD_DEFAULT),
            $expira
        );

        // Bypass de 2FA SOLO para desarrollo (AUTH_2FA_DEV=true): omite el envío
        // por SMTP y devuelve el código en la respuesta. Desactivado por defecto;
        // en producción NUNCA debe activarse.
        $devBypass = filter_var($_ENV['AUTH_2FA_DEV'] ?? 'false', FILTER_VALIDATE_BOOL);

        if ($devBypass) {
            error_log("[DEV 2FA] código para {$user['email']}: {$codigo}");
        } else {
            // Enviar el código por email.
            try {
                $this->enviarCodigo((string) $user['email'], (string) $user['nombre'], $codigo);
            } catch (Throwable $e) {
                return $this->json(
                    $response,
                    ['error' => 'No se pudo enviar el código de verificación. Inténtalo más tarde.'],
                    502
                );
            }
        }

        $payload = [
            'requiere2fa' => true,
            'email'       => $user['email'],
            'expira_en'   => self::CODIGO_TTL,
            'mensaje'     => $devBypass
                ? 'Modo desarrollo: usa el código mostrado abajo.'
                : 'Te enviamos un código de verificación a tu correo.',
        ];

        // Solo en desarrollo: exponer el código para autocompletarlo en la UI.
        if ($devBypass) {
            $payload['dev_codigo'] = $codigo;
        }

        return $this->json($response, $payload);
    }

    /**
     * POST /login/verify: segundo paso. Valida el código enviado al email y, si
     * es correcto y vigente, emite el JWT de sesión.
     */
    public function verify(Request $request, Response $response): Response
    {
        $data   = (array) $request->getParsedBody();
        $email  = strtolower(trim((string) ($data['email'] ?? '')));
        $codigo = preg_replace('/\D/', '', (string) ($data['codigo'] ?? ''));

        if ($email === '' || $codigo === '') {
            return $this->json($response, ['error' => 'Email y código son obligatorios'], 422);
        }

        $user = $this->usuarios->buscarActivoPorEmail($email);
        if ($user === null) {
            return $this->json($response, ['error' => 'Código inválido o expirado'], 401);
        }

        $registro = $this->codigos->buscarVigentePorUsuario((int) $user['id']);
        if ($registro === null) {
            return $this->json(
                $response,
                ['error' => 'Código inválido o expirado. Solicita uno nuevo.'],
                401
            );
        }

        // Límite de intentos: invalida el código y obliga a pedir otro.
        if ((int) $registro['intentos'] >= self::CODIGO_MAX_INTENTOS) {
            $this->codigos->marcarUsado((int) $registro['id']);

            return $this->json(
                $response,
                ['error' => 'Demasiados intentos. Solicita un código nuevo.'],
                429
            );
        }

        if (!password_verify($codigo, (string) $registro['codigo_hash'])) {
            $this->codigos->incrementarIntentos((int) $registro['id']);

            return $this->json($response, ['error' => 'Código incorrecto'], 401);
        }

        // Código correcto: consumirlo y emitir el JWT.
        $this->codigos->marcarUsado((int) $registro['id']);
        $this->usuarios->registrarAcceso((int) $user['id']);

        return $this->json($response, [
            'token'   => $this->emitirToken($user),
            'usuario' => [
                'id'      => (int) $user['id'],
                'usuario' => $user['usuario'],
                'email'   => $user['email'],
                'nombre'  => $user['nombre'],
                'rol'     => $user['rol'],
            ],
        ]);
    }

    /** GET /me: devuelve los datos del usuario autenticado (requiere JWT válido). */
    public function me(Request $request, Response $response): Response
    {
        $claims = $request->getAttribute('token'); // claims decodificados del JWT

        if ($claims === null) {
            return $this->json($response, ['error' => 'No autorizado'], 401);
        }

        return $this->json($response, ['usuario' => $claims]);
    }

    /** Genera un código numérico aleatorio criptográficamente seguro. */
    private function generarCodigo(): string
    {
        $max = (10 ** self::CODIGO_LONGITUD) - 1;

        return str_pad((string) random_int(0, $max), self::CODIGO_LONGITUD, '0', STR_PAD_LEFT);
    }

    /** Firma y devuelve el JWT de sesión para el usuario. */
    private function emitirToken(array $user): string
    {
        $now = time();
        $ttl = (int) ($_ENV['JWT_TTL'] ?? 28800); // 8 h por defecto

        $payload = [
            'iat'     => $now,
            'exp'     => $now + $ttl,
            'sub'     => (int) $user['id'],
            'usuario' => $user['usuario'],
            'email'   => $user['email'],
            'nombre'  => $user['nombre'],
            'rol'     => $user['rol'],
        ];

        return JWT::encode($payload, (string) ($_ENV['JWT_SECRET'] ?? ''), 'HS256');
    }

    /** Compone y envía el correo con el código de verificación. */
    private function enviarCodigo(string $email, string $nombre, string $codigo): void
    {
        $minutos = (int) (self::CODIGO_TTL / 60);
        $asunto  = 'Tu código de acceso · Sistema de Convocatorias';

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto">'
            . '<h2 style="color:#0d6efd;margin-bottom:4px">Sistema de Convocatorias</h2>'
            . '<p style="color:#555;margin-top:0">Hospital Antonio Lorena</p>'
            . '<p>Hola ' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . ', usa este código para completar tu inicio de sesión:</p>'
            . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;'
            . 'background:#f1f5f9;border-radius:8px;padding:16px;margin:24px 0">'
            . htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="color:#555">El código caduca en ' . $minutos . ' minutos. '
            . 'Si no intentaste iniciar sesión, ignora este mensaje.</p>'
            . '</div>';

        $texto = "Sistema de Convocatorias - Hospital Antonio Lorena\n\n"
            . "Tu código de acceso es: {$codigo}\n"
            . "Caduca en {$minutos} minutos. Si no intentaste iniciar sesión, ignora este mensaje.";

        (new Mailer())->enviar($email, $nombre, $asunto, $html, $texto);
    }
}
