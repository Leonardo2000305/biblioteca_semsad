-- Caducidad de reservas (hora de corte: 10:00 del día siguiente al aviso).
-- Ejecutar UNA SOLA VEZ en phpMyAdmin, sobre la base de datos de la biblioteca.
-- Si ya existen las columnas, phpMyAdmin mostrará un error de "Duplicate column": no pasa nada.

ALTER TABLE reserva
  ADD COLUMN fecha_aviso DATETIME NULL,
  ADD COLUMN fecha_limite_retiro DATETIME NULL;
