-- ============================================================
-- MIGRACIÓN: agrega la columna observacion a la tabla categoria
-- ANTES de ejecutar: haz una copia de seguridad (phpMyAdmin > Exportar).
-- Es segura de repetir: ADD COLUMN IF NOT EXISTS no falla si la columna ya existe.
-- ============================================================
ALTER TABLE categoria ADD COLUMN IF NOT EXISTS observacion VARCHAR(255) NULL DEFAULT NULL;
