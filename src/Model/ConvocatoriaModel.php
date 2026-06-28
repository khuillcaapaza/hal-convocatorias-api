<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Database;
use PDO;

/**
 * Acceso a datos de las convocatorias (tabla convocatorias) y sus archivos.
 *
 * Todas las consultas son preparadas (anti inyección SQL — OWASP A03). La forma
 * de salida imita el modelo del sitio (slug/title/area/date/status/files) para
 * que la migración del front sea trivial.
 */
final class ConvocatoriaModel
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    // ── Lectura ───────────────────────────────────────────────────────

    /** Metadatos de las convocatorias publicadas (de la más reciente a la más antigua). */
    public function publicados(): array
    {
        $rows = $this->pdo->query(
            'SELECT slug, titulo, area, fecha_publicacion, estado, descripcion, publicado
               FROM convocatorias WHERE publicado = 1
              ORDER BY fecha_publicacion DESC, id DESC'
        )->fetchAll();

        return array_map([$this, 'mapMeta'], $rows);
    }

    /** Metadatos de todas las convocatorias (incluye no publicadas). */
    public function todosMeta(): array
    {
        $rows = $this->pdo->query(
            'SELECT slug, titulo, area, fecha_publicacion, estado, descripcion, publicado
               FROM convocatorias ORDER BY fecha_publicacion DESC, id DESC'
        )->fetchAll();

        return array_map([$this, 'mapMeta'], $rows);
    }

    /** Una convocatoria publicada completa (con archivos) por slug, o null. */
    public function publicadaPorSlug(string $slug): ?array
    {
        return $this->porSlugCond($slug, true);
    }

    /** Una convocatoria completa (publicada o no, con archivos) por slug, o null. */
    public function porSlug(string $slug): ?array
    {
        return $this->porSlugCond($slug, false);
    }

    private function porSlugCond(string $slug, bool $soloPublicada): ?array
    {
        $sql = 'SELECT * FROM convocatorias WHERE slug = ?'
            . ($soloPublicada ? ' AND publicado = 1' : '') . ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $conv          = $this->map($row);
        $conv['files'] = $this->archivosDe((int) $row['id'], $slug);

        return $conv;
    }

    public function existeSlug(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM convocatorias WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);

        return $stmt->fetchColumn() !== false;
    }

    public function idPorSlug(string $slug): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM convocatorias WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    // ── Escritura ─────────────────────────────────────────────────────

    /** Inserta una convocatoria. Devuelve el id nuevo. */
    public function crear(array $c): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO convocatorias
                (slug, titulo, area, fecha_publicacion, estado, descripcion, cuerpo, publicado)
             VALUES
                (:slug, :titulo, :area, :fecha_publicacion, :estado, :descripcion, :cuerpo, :publicado)'
        );
        $stmt->execute($c);

        return (int) $this->pdo->lastInsertId();
    }

    /** Actualiza una convocatoria por slug. true si existe (haya o no cambios). */
    public function actualizar(string $slug, array $c): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE convocatorias SET
                titulo = :titulo, area = :area, fecha_publicacion = :fecha_publicacion,
                estado = :estado, descripcion = :descripcion, cuerpo = :cuerpo,
                publicado = :publicado
              WHERE slug = :slug_actual'
        );
        $stmt->execute([
            'titulo'            => $c['titulo'],
            'area'              => $c['area'],
            'fecha_publicacion' => $c['fecha_publicacion'],
            'estado'            => $c['estado'],
            'descripcion'       => $c['descripcion'],
            'cuerpo'            => $c['cuerpo'],
            'publicado'         => $c['publicado'],
            'slug_actual'       => $slug,
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        return $this->existeSlug($slug);
    }

    /** Elimina una convocatoria (CASCADE borra sus archivos). true si borró. */
    public function eliminar(string $slug): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM convocatorias WHERE slug = ?');
        $stmt->execute([$slug]);

        return $stmt->rowCount() > 0;
    }

    // ── Archivos (forma de salida para el front) ──────────────────────

    /** Lista los archivos de una convocatoria con su URL pública de descarga. */
    public function archivosDe(int $convocatoriaId, string $slug): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, etiqueta, nombre_archivo, ext, tamano, orden
               FROM convocatoria_archivos WHERE convocatoria_id = ?
              ORDER BY orden ASC, id ASC'
        );
        $stmt->execute([$convocatoriaId]);

        $base = rtrim((string) ($_ENV['ARCHIVOS_BASE_URL'] ?? ''), '/');

        return array_map(static function (array $r) use ($base, $slug): array {
            return [
                'id'    => (int) $r['id'],
                'name'  => $r['nombre_archivo'],
                'label' => $r['etiqueta'],
                'ext'   => $r['ext'],
                'size'  => (int) $r['tamano'],
                'href'  => $base . '/' . $slug . '/' . rawurlencode((string) $r['nombre_archivo']),
            ];
        }, $stmt->fetchAll());
    }

    // ── Mapeo de filas ────────────────────────────────────────────────

    private function map(array $row): array
    {
        return [
            'slug'        => $row['slug'],
            'title'       => $row['titulo'],
            'area'        => $row['area'],
            'date'        => $row['fecha_publicacion'],
            'status'      => $row['estado'],
            'description' => $row['descripcion'],
            'cuerpo'      => $row['cuerpo'] ?? '',
            'publicado'   => (int) $row['publicado'] === 1,
            'actualizado' => $row['actualizado_en'] ?? null,
        ];
    }

    private function mapMeta(array $row): array
    {
        return [
            'slug'        => $row['slug'],
            'title'       => $row['titulo'],
            'area'        => $row['area'],
            'date'        => $row['fecha_publicacion'],
            'status'      => $row['estado'],
            'description' => $row['descripcion'],
            'publicado'   => (int) $row['publicado'] === 1,
        ];
    }
}
