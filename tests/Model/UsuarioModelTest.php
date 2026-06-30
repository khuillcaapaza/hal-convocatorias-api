<?php

declare(strict_types=1);

namespace Tests\Model;

use App\Model\UsuarioModel;
use Tests\TestCase;

final class UsuarioModelTest extends TestCase
{
    public function testBuscarActivoPorEmailDevuelveUsuario(): void
    {
        $fila  = ['id' => 1, 'usuario' => 'admin', 'email' => 'a@b.test', 'nombre' => 'Admin', 'rol' => 'admin', 'password_hash' => 'x'];
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetch' => $fila])]);
        $model = new UsuarioModel($pdo);

        $this->assertSame($fila, $model->buscarActivoPorEmail('a@b.test'));
    }

    public function testBuscarActivoPorEmailDevuelveNull(): void
    {
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetch' => false])]);
        $model = new UsuarioModel($pdo);

        $this->assertNull($model->buscarActivoPorEmail('no@b.test'));
    }

    public function testRegistrarAccesoEjecutaUpdate(): void
    {
        $stmt = $this->stmt();
        $stmt->expects($this->once())->method('execute')->with([1]);
        $pdo  = $this->pdo(prepare: [$stmt]);

        (new UsuarioModel($pdo))->registrarAcceso(1);
    }
}
