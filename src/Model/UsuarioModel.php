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
}
