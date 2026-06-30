<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\ArchivoModel;
use App\Model\ConvocatoriaModel;
use App\Support\Controller;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * CRUD de convocatorias.
 *
 * Lectura pública (GET /convocatorias) y administración protegida por JWT bajo
 * /admin/convocatorias. La subida de archivos la hace el panel DIRECTAMENTE
 * contra hal-archivos-api; aquí solo se registra/borra el metadato y, al borrar,
 * se reenvía (servidor-a-servidor) la orden de borrado físico al servicio.
 */
final class ConvocatoriaController extends Controller
{
    /** Extensiones permitidas para los metadatos de archivo. */
    private const EXT_PERMITIDAS = ['pdf', 'jpg', 'jpeg', 'png'];

    private ConvocatoriaModel $convocatorias;
    private ArchivoModel $archivos;

    /** @var callable(string, string, string): bool */
    private $relayDelete;

    public function __construct(
        ?ConvocatoriaModel $convocatorias = null,
        ?ArchivoModel $archivos = null,
        ?callable $relayDelete = null
    ) {
        $this->convocatorias = $convocatorias ?? new ConvocatoriaModel();
        $this->archivos      = $archivos ?? new ArchivoModel();
        $this->relayDelete   = $relayDelete ?? [$this, 'relayBorradoHttp'];
    }

    // ── Lectura pública ───────────────────────────────────────────────

    /** GET /convocatorias — metadatos de convocatorias publicadas (búsqueda + paginación). */
    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();

        $resultado = $this->convocatorias->publicadosBuscar([
            'q'        => $q['q']        ?? null,
            'area'     => $q['area']     ?? null,
            'year'     => $q['year']     ?? null,
            'month'    => $q['month']    ?? null,
            'page'     => $q['page']     ?? null,
            'per_page' => $q['per_page'] ?? null,
        ]);

        return $this->json($response, [
            'convocatorias' => $resultado['items'],
            'meta'          => [
                'total'       => $resultado['total'],
                'page'        => $resultado['page'],
                'per_page'    => $resultado['per_page'],
                'total_pages' => $resultado['total_pages'],
                'years'       => $this->convocatorias->aniosPublicados(),
            ],
        ]);
    }

    /** GET /convocatorias/{slug} — una convocatoria publicada con sus archivos. */
    public function show(Request $request, Response $response, array $args): Response
    {
        $conv = $this->convocatorias->publicadaPorSlug((string) $args['slug']);
        if ($conv === null) {
            return $this->json($response, ['error' => 'Convocatoria no encontrada'], 404);
        }

        return $this->json($response, ['convocatoria' => $conv]);
    }

    // ── Administración (requiere JWT) ─────────────────────────────────

    /** GET /admin/convocatorias — todas (incluye no publicadas). */
    public function adminIndex(Request $request, Response $response): Response
    {
        return $this->json($response, ['convocatorias' => $this->convocatorias->todosMeta()]);
    }

    /** GET /admin/convocatorias/{slug} — una convocatoria completa para edición. */
    public function adminShow(Request $request, Response $response, array $args): Response
    {
        $conv = $this->convocatorias->porSlug((string) $args['slug']);
        if ($conv === null) {
            return $this->json($response, ['error' => 'Convocatoria no encontrada'], 404);
        }

        return $this->json($response, ['convocatoria' => $conv]);
    }

    /** POST /admin/convocatorias — crear una convocatoria. */
    public function store(Request $request, Response $response): Response
    {
        [$campos, $error] = $this->validar((array) $request->getParsedBody());
        if ($error !== null) {
            return $this->json($response, ['error' => $error], 422);
        }

        if ($this->convocatorias->existeSlug($campos['slug'])) {
            return $this->json($response, ['error' => 'Ya existe una convocatoria con ese slug.'], 409);
        }

        $this->convocatorias->crear($campos);

        return $this->json($response, ['ok' => true, 'slug' => $campos['slug']], 201);
    }

    /** PUT /admin/convocatorias/{slug} — actualizar una convocatoria. */
    public function update(Request $request, Response $response, array $args): Response
    {
        $slug = (string) $args['slug'];

        [$campos, $error] = $this->validar((array) $request->getParsedBody(), $slug);
        if ($error !== null) {
            return $this->json($response, ['error' => $error], 422);
        }

        if (!$this->convocatorias->actualizar($slug, $campos)) {
            return $this->json($response, ['error' => 'Convocatoria no encontrada'], 404);
        }

        return $this->json($response, ['ok' => true, 'slug' => $slug]);
    }

    /** DELETE /admin/convocatorias/{slug} — eliminar convocatoria + sus archivos. */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $slug = (string) $args['slug'];

        $conv = $this->convocatorias->porSlug($slug);
        if ($conv === null) {
            return $this->json($response, ['error' => 'Convocatoria no encontrada'], 404);
        }

        // Borrar primero los binarios físicos (reenviando al servicio de archivos).
        $auth        = $request->getHeaderLine('Authorization');
        $noBorrados  = [];
        foreach ($conv['files'] as $file) {
            if (!$this->relayBorrado($slug, (string) $file['name'], $auth)) {
                $noBorrados[] = $file['name'];
            }
        }

        // Borrar la fila (CASCADE elimina los metadatos de archivo).
        $this->convocatorias->eliminar($slug);

        $payload = ['ok' => true];
        if ($noBorrados !== []) {
            $payload['advertencia'] = 'Algunos archivos físicos no pudieron eliminarse.';
            $payload['no_borrados'] = $noBorrados;
        }

        return $this->json($response, $payload);
    }

    // ── Archivos de una convocatoria ──────────────────────────────────

    /**
     * POST /admin/convocatorias/{slug}/archivos — registra el metadato de un
     * archivo ya subido directamente a hal-archivos-api.
     */
    public function addArchivo(Request $request, Response $response, array $args): Response
    {
        $slug = (string) $args['slug'];
        $id   = $this->convocatorias->idPorSlug($slug);
        if ($id === null) {
            return $this->json($response, ['error' => 'Convocatoria no encontrada'], 404);
        }

        [$campos, $error] = $this->validarArchivo((array) $request->getParsedBody());
        if ($error !== null) {
            return $this->json($response, ['error' => $error], 422);
        }

        $campos['orden'] = $this->archivos->siguienteOrden($id);
        $nuevoId         = $this->archivos->agregar($id, $campos);

        return $this->json($response, ['ok' => true, 'id' => $nuevoId], 201);
    }

    /**
     * DELETE /admin/convocatorias/{slug}/archivos/{id} — borra el metadato y
     * reenvía el borrado físico al servicio de archivos.
     */
    public function deleteArchivo(Request $request, Response $response, array $args): Response
    {
        $slug = (string) $args['slug'];
        $cid  = $this->convocatorias->idPorSlug($slug);
        if ($cid === null) {
            return $this->json($response, ['error' => 'Convocatoria no encontrada'], 404);
        }

        $archivoId = (int) $args['id'];
        $archivo   = $this->archivos->buscar($archivoId, $cid);
        if ($archivo === null) {
            return $this->json($response, ['error' => 'Archivo no encontrado'], 404);
        }

        $auth     = $request->getHeaderLine('Authorization');
        $borrado  = $this->relayBorrado($slug, (string) $archivo['nombre_archivo'], $auth);

        $this->archivos->eliminar($archivoId);

        $payload = ['ok' => true];
        if (!$borrado) {
            $payload['advertencia'] = 'El metadato se eliminó, pero el archivo físico no pudo borrarse.';
        }

        return $this->json($response, $payload);
    }

    // ── Validación / normalización ────────────────────────────────────

    /**
     * Valida el cuerpo de creación/edición. Devuelve [campos, error].
     * En edición el slug viene fijado por la ruta y no se modifica.
     */
    private function validar(array $data, ?string $slugFijo = null): array
    {
        $titulo = trim((string) ($data['titulo'] ?? ''));
        if ($titulo === '') {
            return [null, 'El título es obligatorio.'];
        }

        if ($slugFijo !== null) {
            $slug = $slugFijo;
        } else {
            $slug = trim((string) ($data['slug'] ?? ''));
            $slug = $slug !== '' ? $this->slugify($slug) : $this->slugify($titulo);
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return [null, 'El slug resultante no es válido.'];
        }

        $area = trim((string) ($data['area'] ?? ''));
        if ($area === '') {
            return [null, 'El área/tipo es obligatoria.'];
        }

        $fecha = trim((string) ($data['fecha_publicacion'] ?? $data['date'] ?? ''));
        if (!$this->fechaValida($fecha)) {
            return [null, 'La fecha de publicación debe tener el formato AAAA-MM-DD.'];
        }

        $estado = trim((string) ($data['estado'] ?? $data['status'] ?? 'Abierta'));
        if (!in_array($estado, ['Abierta', 'Cerrada'], true)) {
            return [null, 'El estado debe ser "Abierta" o "Cerrada".'];
        }

        return [[
            'slug'              => mb_substr($slug, 0, 160),
            'titulo'            => mb_substr($titulo, 0, 200),
            'area'              => mb_substr($area, 0, 60),
            'fecha_publicacion' => $fecha,
            'estado'            => $estado,
            'descripcion'       => mb_substr(trim((string) ($data['descripcion'] ?? $data['description'] ?? '')), 0, 1000),
            'cuerpo'            => trim((string) ($data['cuerpo'] ?? '')),
            'publicado'         => filter_var($data['publicado'] ?? true, FILTER_VALIDATE_BOOL) ? 1 : 0,
        ], null];
    }

    /** Valida el registro de un archivo. Devuelve [campos, error]. */
    private function validarArchivo(array $data): array
    {
        $etiqueta = trim((string) ($data['etiqueta'] ?? $data['label'] ?? ''));
        if ($etiqueta === '') {
            return [null, 'La etiqueta del archivo es obligatoria.'];
        }

        $nombre = basename(str_replace('\\', '/', (string) ($data['nombre'] ?? $data['name'] ?? '')));
        if ($nombre === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $nombre)) {
            return [null, 'El nombre de archivo no es válido.'];
        }

        $ext = strtolower((string) ($data['ext'] ?? pathinfo($nombre, PATHINFO_EXTENSION)));
        if (!in_array($ext, self::EXT_PERMITIDAS, true)) {
            return [null, 'Extensión no permitida. Solo: ' . implode(', ', self::EXT_PERMITIDAS)];
        }

        $tamano = (int) ($data['tamano'] ?? $data['size'] ?? 0);

        return [[
            'etiqueta' => mb_substr($etiqueta, 0, 200),
            'nombre'   => $nombre,
            'ext'      => $ext,
            'tamano'   => max(0, $tamano),
        ], null];
    }

    private function fechaValida(string $fecha): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Convierte un texto a slug (minúsculas, sin tildes, separado por guiones). */
    private function slugify(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';

        return trim($s, '-');
    }

    /**
     * Reenvía la orden de borrado físico a hal-archivos-api (servidor-a-servidor),
     * propagando la cabecera Authorization del administrador. Devuelve éxito.
     */
    private function relayBorrado(string $slug, string $nombre, string $auth): bool
    {
        return ($this->relayDelete)($slug, $nombre, $auth);
    }

    /** Implementación HTTP real del relay (curl). Aislada para poder inyectarse en tests. */
    private function relayBorradoHttp(string $slug, string $nombre, string $auth): bool
    {
        $base = rtrim((string) ($_ENV['FILES_API_BASE_URL'] ?? ''), '/');
        if ($base === '' || !function_exists('curl_init')) {
            return false;
        }

        // @codeCoverageIgnoreStart
        $headers = ['Content-Type: application/json'];
        if ($auth !== '') {
            $headers[] = 'Authorization: ' . $auth;
        }

        $ch = curl_init($base . '/delete');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_POSTFIELDS     => json_encode(['slug' => $slug, 'nombre' => $nombre]),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 300;
        // @codeCoverageIgnoreEnd
    }
}
