<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Conexión PDO a MySQL (singleton por petición).
 *
 * Centraliza la creación del PDO que antes hacía src/db.php. Las consultas
 * SIEMPRE deben ser preparadas (anti inyección SQL — OWASP A03).
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $name = $_ENV['DB_NAME'] ?? '';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? '';

        self::$pdo = new PDO(
            "mysql:host=$host;dbname=$name;charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // consultas preparadas reales
            ]
        );

        return self::$pdo;
    }
}
