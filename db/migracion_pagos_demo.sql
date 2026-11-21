-- Biblioteca SEMSAD · Registro exclusivo de operaciones DEMO (sin cobro bancario)
-- Ejecutar en phpMyAdmin de AlwaysData una sola vez, después de realizar respaldo.
-- Nunca modifica multa, préstamo, usuario, cierre_caja ni comprobantes reales.
CREATE TABLE IF NOT EXISTS pago_demo (
  id_pago_demo INT NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(64) NOT NULL,
  id_usuario INT NOT NULL,
  metodo VARCHAR(36) NOT NULL,
  monto DECIMAL(10,2) NOT NULL,
  estado VARCHAR(16) NOT NULL DEFAULT 'SIMULADO',
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_pago_demo),
  UNIQUE KEY uk_pago_demo_codigo (codigo),
  KEY ix_pago_demo_usuario_fecha (id_usuario, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pago_demo_detalle (
  id_pago_demo_detalle INT NOT NULL AUTO_INCREMENT,
  id_pago_demo INT NOT NULL,
  id_usuario INT NOT NULL,
  id_multa INT NOT NULL,
  titulo VARCHAR(255) NOT NULL,
  monto DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (id_pago_demo_detalle),
  UNIQUE KEY uk_pago_demo_usuario_multa (id_usuario, id_multa),
  KEY ix_pago_demo_detalle_pago (id_pago_demo),
  CONSTRAINT fk_pago_demo_detalle_pago FOREIGN KEY (id_pago_demo)
    REFERENCES pago_demo(id_pago_demo) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
