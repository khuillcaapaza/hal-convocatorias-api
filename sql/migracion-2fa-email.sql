-- Migración 2FA por email (hal-convocatorias-api)
-- Aplica sobre una BD existente que ya tiene la tabla `usuarios` sin email.
-- Ejecutar en phpMyAdmin de HestiaCP (no hay shell en el servidor).
--
-- IMPORTANTE: si ya existen usuarios, primero se añade la columna permitiendo
-- NULL, se rellenan los emails y luego se hace UNIQUE/NOT NULL.

-- 1) Añadir columna email (temporalmente NULL para rellenar los existentes).
ALTER TABLE usuarios
  ADD COLUMN email VARCHAR(190) NULL AFTER usuario;

-- 2) Asignar el email a cada usuario existente. Ajusta los valores reales.
UPDATE usuarios SET email = 'katerine.ha2023@gmail.com' WHERE usuario = 'admin';

-- 3) Hacer la columna obligatoria y única (ejecutar tras rellenar todos).
ALTER TABLE usuarios
  MODIFY COLUMN email VARCHAR(190) NOT NULL,
  ADD UNIQUE KEY uq_usuarios_email (email);

-- 4) Tabla de códigos de verificación de dos pasos.
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
