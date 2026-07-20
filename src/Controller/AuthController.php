<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Controller;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Endpoints de sesión para el módulo de Convocatorias.
 *
 * La autenticación se delega al servicio central `hal-auth-api`, que emite
 * el JWT con los módulos permitidos. Este módulo solo valida y expone los
 * claims del token recibido.
 */
final class AuthController extends Controller
{
    /** GET /me: devuelve los datos del usuario autenticado (requiere JWT válido). */
    public function me(Request $request, Response $response): Response
    {
        $claims = $request->getAttribute('token'); // claims decodificados del JWT

        if ($claims === null) {
            return $this->json($response, ['error' => 'No autorizado'], 401);
        }

        return $this->json($response, ['usuario' => $claims]);
    }
}
