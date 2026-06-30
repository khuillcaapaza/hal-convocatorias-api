<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Database;
use PDO;

/**
 * Acceso a datos de los códigos de verificación de dos pasos (2FA por email).
 *
 * El código nunca se guarda en claro: se almacena su hash (password_hash) y se
 * verifica con password_verify, igual que una contraseña.
 */
class LoginCodigoModel
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /**
     * Invalida los códigos anteriores del usuario y crea uno nuevo.
     *
     * @param string $codigoHash Hash del código (password_hash).
     * @param string $expiraEn   Fecha de caducidad en formato 'Y-m-d H:i:s'.
     */
    public function crear(int $usuarioId, string $codigoHash, string $expiraEn): void
    {
        // Un solo código vigente por usuario: marca los previos como usados.
        $this->pdo->prepare(
            'UPDATE login_codigos SET usado = 1 WHERE usuario_id = ? AND usado = 0'
        )->execute([$usuarioId]);

        $this->pdo->prepare(
            'INSERT INTO login_codigos (usuario_id, codigo_hash, expira_en)
             VALUES (?, ?, ?)'
        )->execute([$usuarioId, $codigoHash, $expiraEn]);
    }

    /**
     * Devuelve el código vigente (no usado y sin caducar) más reciente del
     * usuario, o null si no hay ninguno.
     */
    public function buscarVigentePorUsuario(int $usuarioId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, codigo_hash, expira_en, intentos
               FROM login_codigos
              WHERE usuario_id = ? AND usado = 0 AND expira_en > NOW()
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Suma un intento fallido al código. */
    public function incrementarIntentos(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE login_codigos SET intentos = intentos + 1 WHERE id = ?'
        )->execute([$id]);
    }

    /** Marca el código como usado (un solo uso). */
    public function marcarUsado(int $id): void
    {
        $this->pdo->prepare('UPDATE login_codigos SET usado = 1 WHERE id = ?')
            ->execute([$id]);
    }
}
