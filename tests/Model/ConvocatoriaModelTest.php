<?php

declare(strict_types=1);

namespace Tests\Model;

use App\Model\ConvocatoriaModel;
use Tests\TestCase;

final class ConvocatoriaModelTest extends TestCase
{
    private function fila(array $over = []): array
    {
        return array_merge([
            'id'                => 7,
            'slug'              => 'cas-001-2026',
            'titulo'            => 'CAS 001 2026',
            'area'              => 'CAS',
            'fecha_publicacion' => '2026-01-15',
            'estado'            => 'Abierta',
            'descripcion'       => 'Descripción',
            'cuerpo'            => 'Cuerpo',
            'publicado'         => 1,
            'actualizado_en'    => '2026-01-16 10:00:00',
            'archivos'          => 3,
        ], $over);
    }

    public function testPublicadosMapeaMetadatos(): void
    {
        $pdo   = $this->pdo(query: [$this->stmt(['fetchAll' => [$this->fila()]])]);
        $model = new ConvocatoriaModel($pdo);

        $items = $model->publicados();

        $this->assertCount(1, $items);
        $this->assertSame('cas-001-2026', $items[0]['slug']);
        $this->assertSame('CAS 001 2026', $items[0]['title']);
        $this->assertTrue($items[0]['publicado']);
        $this->assertSame(3, $items[0]['archivos']);
    }

    public function testTodosMetaMapeaMetadatos(): void
    {
        $pdo   = $this->pdo(query: [$this->stmt(['fetchAll' => [$this->fila(['publicado' => 0, 'archivos' => 0])]])]);
        $model = new ConvocatoriaModel($pdo);

        $items = $model->todosMeta();

        $this->assertFalse($items[0]['publicado']);
        $this->assertSame(0, $items[0]['archivos']);
    }

    public function testPublicadosBuscarConTodosLosFiltros(): void
    {
        $total = $this->stmt(['fetchColumn' => 25]);
        $lista = $this->stmt(['fetchAll' => [$this->fila()]]);
        $pdo   = $this->pdo(prepare: [$total, $lista]);
        $model = new ConvocatoriaModel($pdo);

        $res = $model->publicadosBuscar([
            'q'        => 'cancer',
            'area'     => 'CAS',
            'year'     => '2026',
            'month'    => '1',
            'page'     => '2',
            'per_page' => '10',
        ]);

        $this->assertSame(25, $res['total']);
        $this->assertSame(10, $res['per_page']);
        $this->assertSame(3, $res['total_pages']);
        $this->assertSame(2, $res['page']);
        $this->assertCount(1, $res['items']);
    }

    public function testPublicadosBuscarSinFiltrosUsaDefaults(): void
    {
        $total = $this->stmt(['fetchColumn' => 0]);
        $lista = $this->stmt(['fetchAll' => []]);
        $pdo   = $this->pdo(prepare: [$total, $lista]);
        $model = new ConvocatoriaModel($pdo);

        $res = $model->publicadosBuscar([]);

        $this->assertSame(0, $res['total']);
        $this->assertSame(12, $res['per_page']);
        $this->assertSame(1, $res['total_pages']);
        $this->assertSame(1, $res['page']);
    }

    public function testPublicadosBuscarMonthFueraDeRangoSeIgnora(): void
    {
        $total = $this->stmt(['fetchColumn' => 1]);
        $lista = $this->stmt(['fetchAll' => [$this->fila()]]);
        $pdo   = $this->pdo(prepare: [$total, $lista]);
        $model = new ConvocatoriaModel($pdo);

        $res = $model->publicadosBuscar(['month' => 13, 'per_page' => 500, 'page' => 99]);

        // per_page se limita a 100 y page se ajusta a total_pages (1).
        $this->assertSame(100, $res['per_page']);
        $this->assertSame(1, $res['page']);
    }

    public function testAniosPublicados(): void
    {
        $pdo   = $this->pdo(query: [$this->stmt(['fetchAll' => [['y' => '2026'], ['y' => '2025']]])]);
        $model = new ConvocatoriaModel($pdo);

        $this->assertSame([2026, 2025], $model->aniosPublicados());
    }

    public function testPublicadaPorSlugDevuelveNullSiNoExiste(): void
    {
        $pdo   = $this->pdo(prepare: [$this->stmt(['fetch' => false])]);
        $model = new ConvocatoriaModel($pdo);

        $this->assertNull($model->publicadaPorSlug('inexistente'));
    }

    public function testPorSlugDevuelveConvocatoriaConArchivos(): void
    {
        $_ENV['ARCHIVOS_BASE_URL'] = 'https://files.test/docs';

        $detalle  = $this->stmt(['fetch' => $this->fila()]);
        $archivos = $this->stmt(['fetchAll' => [[
            'id'             => 4,
            'etiqueta'       => 'Bases',
            'nombre_archivo' => 'bases del proceso.pdf',
            'ext'            => 'pdf',
            'tamano'         => 1200,
            'orden'          => 0,
        ]]]);
        $pdo   = $this->pdo(prepare: [$detalle, $archivos]);
        $model = new ConvocatoriaModel($pdo);

        $conv = $model->porSlug('cas-001-2026');

        $this->assertNotNull($conv);
        $this->assertSame('CAS 001 2026', $conv['title']);
        $this->assertSame('Cuerpo', $conv['cuerpo']);
        $this->assertCount(1, $conv['files']);
        $this->assertSame(
            'https://files.test/docs/cas-001-2026/bases%20del%20proceso.pdf',
            $conv['files'][0]['href']
        );
    }

    public function testMapUsaDefaultsCuandoFaltanCamposOpcionales(): void
    {
        $fila = $this->fila();
        unset($fila['cuerpo'], $fila['actualizado_en']);
        $detalle = $this->stmt(['fetch' => $fila]);
        $pdo     = $this->pdo(prepare: [$detalle, $this->stmt(['fetchAll' => []])]);
        $model   = new ConvocatoriaModel($pdo);

        $conv = $model->porSlug('cas-001-2026');

        $this->assertSame('', $conv['cuerpo']);
        $this->assertNull($conv['actualizado']);
        $this->assertSame([], $conv['files']);
    }

    public function testExisteSlug(): void
    {
        $model = new ConvocatoriaModel($this->pdo(prepare: [$this->stmt(['fetchColumn' => 1])]));
        $this->assertTrue($model->existeSlug('cas-001-2026'));

        $model2 = new ConvocatoriaModel($this->pdo(prepare: [$this->stmt(['fetchColumn' => false])]));
        $this->assertFalse($model2->existeSlug('nope'));
    }

    public function testIdPorSlug(): void
    {
        $model = new ConvocatoriaModel($this->pdo(prepare: [$this->stmt(['fetchColumn' => '9'])]));
        $this->assertSame(9, $model->idPorSlug('cas-001-2026'));

        $model2 = new ConvocatoriaModel($this->pdo(prepare: [$this->stmt(['fetchColumn' => false])]));
        $this->assertNull($model2->idPorSlug('nope'));
    }

    public function testCrearDevuelveNuevoId(): void
    {
        $pdo   = $this->pdo(prepare: [$this->stmt()], lastInsertId: '42');
        $model = new ConvocatoriaModel($pdo);

        $id = $model->crear([
            'slug' => 's', 'titulo' => 't', 'area' => 'a', 'fecha_publicacion' => '2026-01-01',
            'estado' => 'Abierta', 'descripcion' => 'd', 'cuerpo' => 'c', 'publicado' => 1,
        ]);

        $this->assertSame(42, $id);
    }

    public function testActualizarDevuelveTrueSiAfectaFilas(): void
    {
        $pdo   = $this->pdo(prepare: [$this->stmt(['rowCount' => 1])]);
        $model = new ConvocatoriaModel($pdo);

        $this->assertTrue($model->actualizar('s', $this->camposActualizar()));
    }

    public function testActualizarSinCambiosVerificaExistencia(): void
    {
        $update = $this->stmt(['rowCount' => 0]);
        $existe = $this->stmt(['fetchColumn' => 1]);
        $pdo    = $this->pdo(prepare: [$update, $existe]);
        $model  = new ConvocatoriaModel($pdo);

        $this->assertTrue($model->actualizar('s', $this->camposActualizar()));
    }

    public function testActualizarDevuelveFalseSiNoExiste(): void
    {
        $update = $this->stmt(['rowCount' => 0]);
        $existe = $this->stmt(['fetchColumn' => false]);
        $pdo    = $this->pdo(prepare: [$update, $existe]);
        $model  = new ConvocatoriaModel($pdo);

        $this->assertFalse($model->actualizar('s', $this->camposActualizar()));
    }

    public function testEliminar(): void
    {
        $model = new ConvocatoriaModel($this->pdo(prepare: [$this->stmt(['rowCount' => 1])]));
        $this->assertTrue($model->eliminar('s'));

        $model2 = new ConvocatoriaModel($this->pdo(prepare: [$this->stmt(['rowCount' => 0])]));
        $this->assertFalse($model2->eliminar('s'));
    }

    private function camposActualizar(): array
    {
        return [
            'titulo' => 't', 'area' => 'a', 'fecha_publicacion' => '2026-01-01',
            'estado' => 'Abierta', 'descripcion' => 'd', 'cuerpo' => 'c', 'publicado' => 1,
        ];
    }
}
