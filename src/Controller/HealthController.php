<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Controller;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Healthcheck público: verifica que la API responde.
 */
final class HealthController extends Controller
{
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['status' => 'ok', 'time' => date('c')]);
    }
}
