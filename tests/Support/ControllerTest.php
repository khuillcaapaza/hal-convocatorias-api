<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Controller;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

final class ControllerTest extends TestCase
{
    private function controller(): Controller
    {
        return new class extends Controller {
            public function expose(ResponseInterface $r, array $d, int $s = 200): ResponseInterface
            {
                return $this->json($r, $d, $s);
            }
        };
    }

    public function testJsonEscribeCuerpoYCabeceras(): void
    {
        $resp = $this->controller()->expose($this->response(), ['hola' => 'múndo'], 201);

        $this->assertSame(201, $resp->getStatusCode());
        $this->assertSame('application/json', $resp->getHeaderLine('Content-Type'));
        // JSON_UNESCAPED_UNICODE: los acentos no se escapan.
        $this->assertStringContainsString('múndo', (string) $resp->getBody());
    }

    public function testJsonUsaEstado200PorDefecto(): void
    {
        $resp = $this->controller()->expose($this->response(), ['ok' => true]);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame(['ok' => true], $this->jsonBody($resp));
    }
}
