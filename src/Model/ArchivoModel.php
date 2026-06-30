<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Database;
use PDO;

/**
 * Acceso a datos de los archivos de convocatorias (tabla convocatoria_archivos).
 *
 * Solo metadatos: el binario físico lo gestiona hal-archivos-api.
 */
class ArchivoModel
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** Inserta un metadato de archivo. Devuelve el id nuevo. */
    public function agregar(int $convocatoriaId, array $c): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO convocatoria_archivos
                (convocatoria_id, etiqueta, nombre_archivo, ext, tamano, orden)
             VALUES (:cid, :etiqueta, :nombre, :ext, :tamano, :orden)'
        );
        $stmt->execute([
            'cid'      => $convocatoriaId,
            'etiqueta' => $c['etiqueta'],
            'nombre'   => $c['nombre'],
            'ext'      => $c['ext'],
            'tamano'   => $c['tamano'],
            'orden'    => $c['orden'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Siguiente número de orden disponible para una convocatoria. */
    public function siguienteOrden(int $convocatoriaId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(orden), -1) + 1 FROM convocatoria_archivos WHERE convocatoria_id = ?'
        );
        $stmt->execute([$convocatoriaId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca un archivo por id dentro de una convocatoria.
     * Devuelve [id, nombre_archivo, slug] o null.
     */
    public function buscar(int $id, int $convocatoriaId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.nombre_archivo, c.slug
               FROM convocatoria_archivos a
               JOIN convocatorias c ON c.id = a.convocatoria_id
              WHERE a.id = ? AND a.convocatoria_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $convocatoriaId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Elimina un metadato de archivo. true si borró alguna fila. */
    public function eliminar(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM convocatoria_archivos WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
}
