<?php

declare(strict_types=1);

namespace Tests\Model;

use App\Model\LoginCodigoModel;
use Tests\TestCase;

final class LoginCodigoModelTest extends TestCase
{
    public function testCrearInvalidaPreviosYInserta(): void
    {
        // Dos prepares: UPDATE (invalida previos) + INSERT (nuevo código).
        $pdo   = $this->pdo(prepare: [$this->stmt(), $this->stmt()]);
        $model = new LoginCodigoModel($pdo);

        $model->crear(1, 'hash', '2026-01-01 00:00:00');

        $this->addToAssertionCount(1);
    }

    public function testBuscarVigentePorUsuarioDevuelveRegistro(): void
    {
        $fila  = ['id' => 5, 'codigo_hash' => 'h', 'expira_en' => '2026-01-01 00:00:00', 'intentos' => 0];
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetch' => $fila])]);
        $model = new LoginCodigoModel($pdo);

        $this->assertSame($fila, $model->buscarVigentePorUsuario(1));
    }

    public function testBuscarVigentePorUsuarioDevuelveNull(): void
    {
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetch' => false])]);
        $model = new LoginCodigoModel($pdo);

        $this->assertNull($model->buscarVigentePorUsuario(1));
    }

    public function testIncrementarIntentos(): void
    {
        $stmt = $this->stmt();
        $stmt->expects($this->once())->method('execute')->with([5]);
        (new LoginCodigoModel($this->pdo(prepare: [$stmt])))->incrementarIntentos(5);
    }

    public function testMarcarUsado(): void
    {
        $stmt = $this->stmt();
        $stmt->expects($this->once())->method('execute')->with([5]);
        (new LoginCodigoModel($this->pdo(prepare: [$stmt])))->marcarUsado(5);
    }
}
