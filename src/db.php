<?php

declare(strict_types=1);

/**
 * Conexión PDO a MySQL. Devuelve una instancia lista para usar.
 * Las consultas SIEMPRE deben ser preparadas (anti inyección SQL — OWASP A03).
 */

$host = $_ENV['DB_HOST'] ?? 'localhost';
$name = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';

return new PDO(
    "mysql:host=$host;dbname=$name;charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // consultas preparadas reales
    ]
);
