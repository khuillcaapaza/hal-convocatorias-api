-- Esquema de convocatorias (hal-convocatorias-api)
-- Sustituye el modelo estático de hal-site (src/lib/convocatorias.ts) por datos
-- dinámicos en MySQL/MariaDB. En HestiaCP usar la BD ya creada e importar SOLO
-- las sentencias CREATE TABLE (sin USE).

-- USE hal_convocatorias;  -- (solo en local; en Hestia seleccionar la BD en phpMyAdmin)

CREATE TABLE IF NOT EXISTS convocatorias (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  slug              VARCHAR(160)  NOT NULL,
  titulo            VARCHAR(200)  NOT NULL,
  area              VARCHAR(60)   NOT NULL,
  fecha_publicacion DATE          NOT NULL,
  estado            ENUM('Abierta','Cerrada') NOT NULL DEFAULT 'Abierta',
  descripcion       VARCHAR(1000) NOT NULL DEFAULT '',
  cuerpo            MEDIUMTEXT    NULL,                    -- detalle en texto/markdown
  publicado         TINYINT(1)    NOT NULL DEFAULT 1,
  creado_en         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_convocatorias_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Archivos (documentos) asociados a cada convocatoria. El binario físico lo
-- guarda hal-archivos-api en documentos/convocatorias/<slug>/<nombre_archivo>;
-- aquí solo persisten los metadatos para listar/descargar.
CREATE TABLE IF NOT EXISTS convocatoria_archivos (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  convocatoria_id INT UNSIGNED  NOT NULL,
  etiqueta        VARCHAR(200)  NOT NULL,                 -- nombre visible (botón)
  nombre_archivo  VARCHAR(255)  NOT NULL,                 -- nombre físico en disco
  ext             VARCHAR(10)   NOT NULL,
  tamano          INT UNSIGNED  NOT NULL DEFAULT 0,        -- bytes
  orden           INT           NOT NULL DEFAULT 0,
  creado_en       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_archivos_convocatoria (convocatoria_id),
  CONSTRAINT fk_archivos_convocatoria FOREIGN KEY (convocatoria_id)
    REFERENCES convocatorias (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
