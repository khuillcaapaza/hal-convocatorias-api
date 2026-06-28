<?php

declare(strict_types=1);

/**
 * Crea o actualiza un usuario con la contraseña cifrada (password_hash).
 *
 * Uso:
 *   php scripts/crear-usuario.php <usuario> <email> <password> "<nombre>" [rol]
 *
 * Ejemplo:
 *   php scripts/crear-usuario.php admin admin@hospital.gob.pe Secreta123 "Administrador" admin
 */

require __DIR__ . '/../vendor/autoload.php';

// Cargar variables de entorno (.env) para la conexión a la BD
if (file_exists(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
}

$usuario  = $argv[1] ?? null;
$email    = $argv[2] ?? null;
$password = $argv[3] ?? null;
$nombre   = $argv[4] ?? null;
$rol      = $argv[5] ?? 'usuario';

if ($usuario === null || $email === null || $password === null || $nombre === null) {
    fwrite(STDERR, "Uso: php scripts/crear-usuario.php <usuario> <email> <password> \"<nombre>\" [rol]\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Email inválido: {$email}\n");
    exit(1);
}

/** @var PDO $pdo */
$pdo  = require __DIR__ . '/../src/db.php';
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    'INSERT INTO usuarios (usuario, email, password_hash, nombre, rol)
     VALUES (:usuario, :email, :hash, :nombre, :rol)
     ON DUPLICATE KEY UPDATE
        email         = VALUES(email),
        password_hash = VALUES(password_hash),
        nombre        = VALUES(nombre),
        rol           = VALUES(rol)'
);

$stmt->execute([
    ':usuario' => $usuario,
    ':email'   => strtolower($email),
    ':hash'    => $hash,
    ':nombre'  => $nombre,
    ':rol'     => $rol,
]);

echo "Usuario '{$usuario}' ({$email}) creado/actualizado correctamente.\n";
