<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\HealthController;
use Tests\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testIndexDevuelveEstadoOk(): void
    {
        $resp = (new HealthController())->index($this->request(), $this->response());

        $this->assertSame(200, $resp->getStatusCode());
        $body = $this->jsonBody($resp);
        $this->assertSame('ok', $body['status']);
        $this->assertArrayHasKey('time', $body);
    }
}
