-- Esquema de autenticación para el servicio de convocatorias (hal-convocatorias-api)
-- Ejecutar en MySQL/MariaDB. En HestiaCP usar la BD ya creada (p. ej. haladminweb_convocatorias)
-- y omitir las dos primeras líneas (CREATE DATABASE / USE).

CREATE DATABASE IF NOT EXISTS hal_convocatorias
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hal_convocatorias;

CREATE TABLE IF NOT EXISTS usuarios (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  usuario         VARCHAR(50)     NOT NULL,
  email           VARCHAR(190)    NOT NULL,
  password_hash   VARCHAR(255)    NOT NULL,
  nombre          VARCHAR(120)    NOT NULL,
  rol             VARCHAR(30)     NOT NULL DEFAULT 'usuario',
  activo          TINYINT(1)      NOT NULL DEFAULT 1,
  ultimo_acceso   DATETIME        NULL,
  creado_en       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_usuario (usuario),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Códigos de verificación de dos pasos (2FA por email). Cada login con
-- credenciales válidas genera un código de un solo uso, con caducidad y
-- límite de intentos. Se valida en POST /login/verify.
CREATE TABLE IF NOT EXISTS login_codigos (
  id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  usuario_id    INT UNSIGNED     NOT NULL,
  codigo_hash   VARCHAR(255)     NOT NULL,
  expira_en     DATETIME         NOT NULL,
  intentos      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  usado         TINYINT(1)       NOT NULL DEFAULT 0,
  creado_en     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_codigos_usuario (usuario_id),
  CONSTRAINT fk_login_codigos_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTA: no se insertan usuarios aquí. Las contraseñas deben cifrarse con
-- password_hash() de PHP. Usar el script: php scripts/crear-usuario.php
