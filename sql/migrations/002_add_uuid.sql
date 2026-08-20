-- Agrega UUID publico a convocatorias existentes.
-- Ejecutar una sola vez sobre la base de datos ya desplegada.

ALTER TABLE convocatorias
  ADD COLUMN uuid CHAR(36) NULL AFTER id;

UPDATE convocatorias
   SET uuid = UUID()
 WHERE uuid IS NULL;

ALTER TABLE convocatorias
  MODIFY COLUMN uuid CHAR(36) NOT NULL,
  ADD UNIQUE KEY uq_convocatorias_uuid (uuid);
