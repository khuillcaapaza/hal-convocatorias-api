<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Database;
use PDO;

/**
 * Acceso a datos de usuarios del panel (tabla usuarios).
 */
class UsuarioModel
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** Busca un usuario activo por su email. Null si no existe. */
    public function buscarActivoPorEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, usuario, email, nombre, rol, password_hash
               FROM usuarios
              WHERE email = ? AND activo = 1
              LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Registra la fecha/hora del último acceso. */
    public function registrarAcceso(int $id): void
    {
        $this->pdo->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?')
            ->execute([$id]);
    }

    /** Obtener usuario por ID (incluye password_hash, para verificación). */
    public function porId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, usuario, email, password_hash, nombre, rol, activo, creado_en, actualizado_en
             FROM usuarios WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Obtener usuario por email incluyendo hash (para login). */
    public function porEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, usuario, email, password_hash, nombre, rol, activo
             FROM usuarios WHERE email = ? LIMIT 1'
        );
        $stmt->execute([strtolower($email)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Obtener usuario por nombre de usuario incluyendo hash. */
    public function porUsuario(string $usuario): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, usuario, email, password_hash, nombre, rol, activo
             FROM usuarios WHERE usuario = ? LIMIT 1'
        );
        $stmt->execute([$usuario]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Listar todos los usuarios con paginación. */
    public function paginar(int $page = 1, int $per_page = 20): array
    {
        $offset = ($page - 1) * $per_page;

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT id, usuario, email, nombre, rol, activo, creado_en, actualizado_en
             FROM usuarios ORDER BY creado_en DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$per_page, $offset]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => ceil($total / $per_page) ?: 1,
        ];
    }

    /** Crear usuario. */
    public function crear(array $datos): int
    {
        if ($this->porUsuario($datos['usuario'])) {
            throw new \Exception("El usuario ya existe");
        }
        if ($this->porEmail($datos['email'])) {
            throw new \Exception("El email ya está registrado");
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (usuario, email, password_hash, nombre, rol)
             VALUES (?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $datos['usuario'],
            strtolower($datos['email']),
            $datos['password_hash'],
            $datos['nombre'],
            $datos['rol'] ?? 'usuario',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Actualizar usuario. */
    public function actualizar(int $id, array $datos): void
    {
        if (!$this->porId($id)) {
            throw new \Exception("Usuario no encontrado");
        }

        if (!empty($datos['email'])) {
            $existing = $this->porEmail($datos['email']);
            if ($existing && $existing['id'] !== $id) {
                throw new \Exception("El email ya está registrado");
            }
            $datos['email'] = strtolower($datos['email']);
        }

        $fields = [];
        $values = [];

        foreach ($datos as $field => $value) {
            if ($field === 'id') {
                continue;
            }
            $fields[] = "{$field} = ?";
            $values[] = $value;
        }

        if (empty($fields)) {
            return;
        }

        $values[] = $id;
        $sql = 'UPDATE usuarios SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $this->pdo->prepare($sql)->execute($values);
    }

    /** Eliminar usuario. */
    public function eliminar(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);
    }
}
