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
class ConvocatoriaModel
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    // ── Lectura ───────────────────────────────────────────────────────

    /** Metadatos de las convocatorias publicadas (de la más reciente a la más antigua). */
    public function publicados(): array
    {
        $rows = $this->pdo->query(
            'SELECT c.slug, c.titulo, c.area, c.fecha_publicacion, c.estado, c.descripcion, c.publicado,
                    (SELECT COUNT(*) FROM convocatoria_archivos a WHERE a.convocatoria_id = c.id) AS archivos
               FROM convocatorias c WHERE c.publicado = 1
              ORDER BY c.fecha_publicacion DESC, c.id DESC'
        )->fetchAll();

        return array_map([$this, 'mapMeta'], $rows);
    }

    /** Metadatos de todas las convocatorias (incluye no publicadas). */
    public function todosMeta(): array
    {
        $rows = $this->pdo->query(
            'SELECT c.slug, c.titulo, c.area, c.fecha_publicacion, c.estado, c.descripcion, c.publicado,
                    (SELECT COUNT(*) FROM convocatoria_archivos a WHERE a.convocatoria_id = c.id) AS archivos
               FROM convocatorias c ORDER BY c.fecha_publicacion DESC, c.id DESC'
        )->fetchAll();

        return array_map([$this, 'mapMeta'], $rows);
    }

    /**
     * Metadatos de convocatorias publicadas con búsqueda y paginación.
     *
     * Filtros admitidos: q (texto en título/área/descripción), area, year, month.
     * Devuelve ['items', 'total', 'page', 'per_page', 'total_pages'].
     */
    public function publicadosBuscar(array $f): array
    {
        $where  = ['c.publicado = 1'];
        $params = [];

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[]      = "(CONCAT(c.titulo, ' ', c.area, ' ', c.descripcion) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        $area = trim((string) ($f['area'] ?? ''));
        if ($area !== '') {
            $where[]         = 'c.area = :area';
            $params[':area'] = $area;
        }
        $year = (int) ($f['year'] ?? 0);
        if ($year > 0) {
            $where[]         = 'YEAR(c.fecha_publicacion) = :year';
            $params[':year'] = $year;
        }
        $month = (int) ($f['month'] ?? 0);
        if ($month >= 1 && $month <= 12) {
            $where[]          = 'MONTH(c.fecha_publicacion) = :month';
            $params[':month'] = $month;
        }
        $whereSql = implode(' AND ', $where);

        $stmtTotal = $this->pdo->prepare("SELECT COUNT(*) FROM convocatorias c WHERE {$whereSql}");
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        $perPage    = max(1, min(100, (int) ($f['per_page'] ?? 12)));
        $totalPages = (int) max(1, (int) ceil($total / $perPage));
        $page       = max(1, min($totalPages, (int) ($f['page'] ?? 1)));
        $offset     = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT c.slug, c.titulo, c.area, c.fecha_publicacion, c.estado, c.descripcion, c.publicado,
                    (SELECT COUNT(*) FROM convocatoria_archivos a WHERE a.convocatoria_id = c.id) AS archivos
               FROM convocatorias c WHERE {$whereSql}
              ORDER BY c.fecha_publicacion DESC, c.id DESC
              LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'items'       => array_map([$this, 'mapMeta'], $stmt->fetchAll()),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /** Años (distintos) de las convocatorias publicadas, de mayor a menor. */
    public function aniosPublicados(): array
    {
        $rows = $this->pdo->query(
            'SELECT DISTINCT YEAR(fecha_publicacion) AS y
               FROM convocatorias WHERE publicado = 1
              ORDER BY y DESC'
        )->fetchAll();

        return array_map(static fn (array $r): int => (int) $r['y'], $rows);
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
            'archivos'    => (int) ($row['archivos'] ?? 0),
        ];
    }
}
