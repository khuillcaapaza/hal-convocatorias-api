<?php

declare(strict_types=1);

namespace Tests\Model;

use App\Model\ArchivoModel;
use Tests\TestCase;

final class ArchivoModelTest extends TestCase
{
    public function testAgregarDevuelveNuevoId(): void
    {
        $pdo   = $this->pdo(prepare: [$this->stmt()], lastInsertId: '15');
        $model = new ArchivoModel($pdo);

        $id = $model->agregar(3, [
            'etiqueta' => 'Bases', 'nombre' => 'bases.pdf', 'ext' => 'pdf', 'tamano' => 100, 'orden' => 0,
        ]);

        $this->assertSame(15, $id);
    }

    public function testSiguienteOrden(): void
    {
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetchColumn' => '2'])]);
        $model = new ArchivoModel($pdo);

        $this->assertSame(2, $model->siguienteOrden(3));
    }

    public function testBuscarDevuelveFilaCuandoExiste(): void
    {
        $fila  = ['id' => 4, 'nombre_archivo' => 'bases.pdf', 'slug' => 'cas-001-2026'];
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetch' => $fila])]);
        $model = new ArchivoModel($pdo);

        $this->assertSame($fila, $model->buscar(4, 3));
    }

    public function testBuscarDevuelveNullCuandoNoExiste(): void
    {
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetch' => false])]);
        $model = new ArchivoModel($pdo);

        $this->assertNull($model->buscar(4, 3));
    }

    public function testEliminar(): void
    {
        $model = new ArchivoModel($this->pdo(prepare: [$this->stmt(['rowCount' => 1])]));
        $this->assertTrue($model->eliminar(4));

        $model2 = new ArchivoModel($this->pdo(prepare: [$this->stmt(['rowCount' => 0])]));
        $this->assertFalse($model2->eliminar(4));
    }
}
