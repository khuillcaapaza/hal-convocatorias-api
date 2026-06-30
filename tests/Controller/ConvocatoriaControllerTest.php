<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\ConvocatoriaController;
use App\Model\ArchivoModel;
use App\Model\ConvocatoriaModel;
use Tests\TestCase;

final class ConvocatoriaControllerTest extends TestCase
{
    private function controller(
        ?ConvocatoriaModel $conv = null,
        ?ArchivoModel $arch = null,
        $relay = null
    ): ConvocatoriaController {
        return new ConvocatoriaController(
            $conv ?? $this->createMock(ConvocatoriaModel::class),
            $arch ?? $this->createMock(ArchivoModel::class),
            $relay
        );
    }

    /** @return array<string,mixed> Cuerpo válido para crear/actualizar. */
    private function bodyValido(array $over = []): array
    {
        return array_merge([
            'titulo'            => 'CAS 001 2026',
            'slug'              => 'cas-001-2026',
            'area'              => 'CAS',
            'fecha_publicacion' => '2026-01-15',
            'estado'            => 'Abierta',
            'descripcion'       => 'desc',
            'publicado'         => true,
        ], $over);
    }

    // ── Lectura pública ───────────────────────────────────────────────

    public function testIndexDevuelveConvocatoriasYMeta(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('publicadosBuscar')->willReturn([
            'items' => [['slug' => 'cas-001-2026']],
            'total' => 1, 'page' => 1, 'per_page' => 12, 'total_pages' => 1,
        ]);
        $conv->method('aniosPublicados')->willReturn([2026, 2025]);

        $resp = $this->controller($conv)->index(
            $this->request('GET', null, ['q' => 'cas', 'page' => '1']),
            $this->response()
        );

        $body = $this->jsonBody($resp);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertCount(1, $body['convocatorias']);
        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame([2026, 2025], $body['meta']['years']);
    }

    public function testShowDevuelveConvocatoria(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('publicadaPorSlug')->willReturn(['slug' => 'cas-001-2026', 'files' => []]);

        $resp = $this->controller($conv)->show($this->request(), $this->response(), ['slug' => 'cas-001-2026']);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('cas-001-2026', $this->jsonBody($resp)['convocatoria']['slug']);
    }

    public function testShowDevuelve404(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('publicadaPorSlug')->willReturn(null);

        $resp = $this->controller($conv)->show($this->request(), $this->response(), ['slug' => 'x']);

        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testAdminIndex(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('todosMeta')->willReturn([['slug' => 'a'], ['slug' => 'b']]);

        $resp = $this->controller($conv)->adminIndex($this->request(), $this->response());

        $this->assertCount(2, $this->jsonBody($resp)['convocatorias']);
    }

    public function testAdminShowFoundYNotFound(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('porSlug')->willReturnOnConsecutiveCalls(['slug' => 'a', 'files' => []], null);

        $ok = $this->controller($conv)->adminShow($this->request(), $this->response(), ['slug' => 'a']);
        $this->assertSame(200, $ok->getStatusCode());

        $nf = $this->controller($conv)->adminShow($this->request(), $this->response(), ['slug' => 'x']);
        $this->assertSame(404, $nf->getStatusCode());
    }

    // ── store / validación ────────────────────────────────────────────

    public function testStoreTituloObligatorio(): void
    {
        $resp = $this->controller()->store(
            $this->request('POST', ['titulo' => '   ']),
            $this->response()
        );

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('El título es obligatorio.', $this->jsonBody($resp)['error']);
    }

    public function testStoreSlugInvalido(): void
    {
        $resp = $this->controller()->store(
            $this->request('POST', $this->bodyValido(['slug' => '***'])),
            $this->response()
        );

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertStringContainsString('slug', $this->jsonBody($resp)['error']);
    }

    public function testStoreAreaObligatoria(): void
    {
        $resp = $this->controller()->store(
            $this->request('POST', $this->bodyValido(['area' => ''])),
            $this->response()
        );
        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('El área/tipo es obligatoria.', $this->jsonBody($resp)['error']);
    }

    public function testStoreFechaInvalida(): void
    {
        $resp = $this->controller()->store(
            $this->request('POST', $this->bodyValido(['fecha_publicacion' => '2026-13-40'])),
            $this->response()
        );
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testStoreFechaConFormatoInvalido(): void
    {
        // No cumple el patrón AAAA-MM-DD: cubre la rama de formato (regex) inválido.
        $resp = $this->controller()->store(
            $this->request('POST', $this->bodyValido(['fecha_publicacion' => '01/01/2026'])),
            $this->response()
        );
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testStoreEstadoInvalido(): void
    {
        $resp = $this->controller()->store(
            $this->request('POST', $this->bodyValido(['estado' => 'Pausada'])),
            $this->response()
        );
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testStoreSlugDuplicado(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('existeSlug')->willReturn(true);

        $resp = $this->controller($conv)->store($this->request('POST', $this->bodyValido()), $this->response());

        $this->assertSame(409, $resp->getStatusCode());
    }

    public function testStoreCreaConSlugDerivadoDelTitulo(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('existeSlug')->willReturn(false);
        $conv->expects($this->once())->method('crear')->willReturn(1);

        // Sin slug explícito y con acentos: se deriva del título (slugify).
        $resp = $this->controller($conv)->store(
            $this->request('POST', ['titulo' => 'Convocatoria Médica Ñandú', 'area' => 'CAS',
                'fecha_publicacion' => '2026-01-15', 'estado' => 'Abierta']),
            $this->response()
        );

        $this->assertSame(201, $resp->getStatusCode());
        $this->assertSame('convocatoria-medica-nandu', $this->jsonBody($resp)['slug']);
    }

    // ── update ────────────────────────────────────────────────────────

    public function testUpdateValidacionFalla(): void
    {
        $resp = $this->controller()->update(
            $this->request('PUT', ['titulo' => '']),
            $this->response(),
            ['slug' => 'cas-001-2026']
        );
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testUpdateNoEncontrada(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('actualizar')->willReturn(false);

        $resp = $this->controller($conv)->update(
            $this->request('PUT', $this->bodyValido()),
            $this->response(),
            ['slug' => 'cas-001-2026']
        );
        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testUpdateExitoso(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('actualizar')->willReturn(true);

        $resp = $this->controller($conv)->update(
            $this->request('PUT', $this->bodyValido()),
            $this->response(),
            ['slug' => 'cas-001-2026']
        );
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('cas-001-2026', $this->jsonBody($resp)['slug']);
    }

    // ── destroy ───────────────────────────────────────────────────────

    public function testDestroyNoEncontrada(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('porSlug')->willReturn(null);

        $resp = $this->controller($conv)->destroy($this->request('DELETE'), $this->response(), ['slug' => 'x']);
        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testDestroyBorraTodoSinAdvertencia(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('porSlug')->willReturn(['slug' => 'a', 'files' => [['name' => 'x.pdf'], ['name' => 'y.pdf']]]);
        $conv->expects($this->once())->method('eliminar')->willReturn(true);

        $resp = $this->controller($conv, null, fn (): bool => true)->destroy(
            $this->request('DELETE', null, [], ['Authorization' => 'Bearer t']),
            $this->response(),
            ['slug' => 'a']
        );

        $body = $this->jsonBody($resp);
        $this->assertTrue($body['ok']);
        $this->assertArrayNotHasKey('advertencia', $body);
    }

    public function testDestroyConArchivosNoBorrados(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('porSlug')->willReturn(['slug' => 'a', 'files' => [['name' => 'x.pdf']]]);
        $conv->method('eliminar')->willReturn(true);

        $resp = $this->controller($conv, null, fn (): bool => false)->destroy(
            $this->request('DELETE'),
            $this->response(),
            ['slug' => 'a']
        );

        $body = $this->jsonBody($resp);
        $this->assertArrayHasKey('advertencia', $body);
        $this->assertSame(['x.pdf'], $body['no_borrados']);
    }

    public function testDestroyUsaRelayHttpPorDefecto(): void
    {
        // Sin inyectar relay: usa relayBorradoHttp. Con FILES_API_BASE_URL vacío
        // devuelve false (cubre la guarda del relay real).
        $_ENV['FILES_API_BASE_URL'] = '';

        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('porSlug')->willReturn(['slug' => 'a', 'files' => [['name' => 'x.pdf']]]);
        $conv->method('eliminar')->willReturn(true);

        $resp = $this->controller($conv)->destroy($this->request('DELETE'), $this->response(), ['slug' => 'a']);

        $this->assertArrayHasKey('advertencia', $this->jsonBody($resp));
    }

    // ── addArchivo ────────────────────────────────────────────────────

    public function testAddArchivoConvocatoriaNoEncontrada(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('idPorSlug')->willReturn(null);

        $resp = $this->controller($conv)->addArchivo(
            $this->request('POST', ['label' => 'Bases', 'name' => 'b.pdf']),
            $this->response(),
            ['slug' => 'x']
        );
        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testAddArchivoEtiquetaObligatoria(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('idPorSlug')->willReturn(3);

        $resp = $this->controller($conv)->addArchivo(
            $this->request('POST', ['etiqueta' => '']),
            $this->response(),
            ['slug' => 'a']
        );
        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('La etiqueta del archivo es obligatoria.', $this->jsonBody($resp)['error']);
    }

    public function testAddArchivoNombreInvalido(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('idPorSlug')->willReturn(3);

        $resp = $this->controller($conv)->addArchivo(
            $this->request('POST', ['etiqueta' => 'Bases', 'nombre' => 'mal nombre!.pdf']),
            $this->response(),
            ['slug' => 'a']
        );
        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('El nombre de archivo no es válido.', $this->jsonBody($resp)['error']);
    }

    public function testAddArchivoExtensionNoPermitida(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('idPorSlug')->willReturn(3);

        $resp = $this->controller($conv)->addArchivo(
            $this->request('POST', ['etiqueta' => 'Bases', 'nombre' => 'virus.exe']),
            $this->response(),
            ['slug' => 'a']
        );
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testAddArchivoExitoso(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('idPorSlug')->willReturn(3);

        $arch = $this->createMock(ArchivoModel::class);
        $arch->method('siguienteOrden')->willReturn(0);
        $arch->expects($this->once())->method('agregar')->willReturn(9);

        // ext se deriva de pathinfo del nombre.
        $resp = $this->controller($conv, $arch)->addArchivo(
            $this->request('POST', ['label' => 'Bases', 'name' => 'bases.pdf', 'size' => 100]),
            $this->response(),
            ['slug' => 'a']
        );

        $this->assertSame(201, $resp->getStatusCode());
        $this->assertSame(9, $this->jsonBody($resp)['id']);
    }

    // ── deleteArchivo ─────────────────────────────────────────────────

    public function testDeleteArchivoConvocatoriaNoEncontrada(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('idPorSlug')->willReturn(null);

        $resp = $this->controller($conv)->deleteArchivo($this->request('DELETE'), $this->response(), ['slug' => 'x', 'id' => '4']);
        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testDeleteArchivoNoEncontrado(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('idPorSlug')->willReturn(3);
        $arch = $this->createMock(ArchivoModel::class);
        $arch->method('buscar')->willReturn(null);

        $resp = $this->controller($conv, $arch)->deleteArchivo($this->request('DELETE'), $this->response(), ['slug' => 'a', 'id' => '4']);
        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testDeleteArchivoExitoso(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('idPorSlug')->willReturn(3);
        $arch = $this->createMock(ArchivoModel::class);
        $arch->method('buscar')->willReturn(['id' => 4, 'nombre_archivo' => 'b.pdf', 'slug' => 'a']);
        $arch->expects($this->once())->method('eliminar')->willReturn(true);

        $resp = $this->controller($conv, $arch, fn (): bool => true)->deleteArchivo(
            $this->request('DELETE'),
            $this->response(),
            ['slug' => 'a', 'id' => '4']
        );

        $body = $this->jsonBody($resp);
        $this->assertTrue($body['ok']);
        $this->assertArrayNotHasKey('advertencia', $body);
    }

    public function testDeleteArchivoConAdvertenciaSiRelayFalla(): void
    {
        $conv = $this->createMock(ConvocatoriaModel::class);
        $conv->method('idPorSlug')->willReturn(3);
        $arch = $this->createMock(ArchivoModel::class);
        $arch->method('buscar')->willReturn(['id' => 4, 'nombre_archivo' => 'b.pdf', 'slug' => 'a']);
        $arch->method('eliminar')->willReturn(true);

        $resp = $this->controller($conv, $arch, fn (): bool => false)->deleteArchivo(
            $this->request('DELETE'),
            $this->response(),
            ['slug' => 'a', 'id' => '4']
        );

        $this->assertArrayHasKey('advertencia', $this->jsonBody($resp));
    }
}
