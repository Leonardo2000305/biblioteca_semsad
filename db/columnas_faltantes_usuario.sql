-- La aplicación (login, registro, recuperación de contraseña) usa la columna usuario.contrasena,
-- que no aparecía en db_biblioteca_semsad.sql. Esta migración es segura de ejecutar aunque ya exista.
-- Ejecutar una sola vez en phpMyAdmin.

ALTER TABLE usuario ADD COLUMN IF NOT EXISTS contrasena VARCHAR(255) NULL;
