-- ============================================================
-- MIGRACIÓN: comprobantes, cierre de caja y avisos de vencimiento
-- ANTES de ejecutar: copia de seguridad de la base (phpMyAdmin > Exportar).
-- Ejecutar UNA sola vez.
-- ============================================================

-- 1. Datos del cobro en la multa (para comprobantes y caja).
ALTER TABLE multa ADD COLUMN fecha_pago DATETIME NULL;
ALTER TABLE multa ADD COLUMN id_cobrador INT NULL;
ALTER TABLE multa ADD COLUMN monto_recibido DECIMAL(10,2) NULL;
ALTER TABLE multa ADD COLUMN cambio DECIMAL(10,2) NULL;

-- 2. Fecha en que se envió el aviso de vencimiento (evita correos duplicados).
ALTER TABLE prestamo ADD COLUMN aviso_enviado_at DATETIME NULL;

-- 3. Cierres de caja: un cierre por día (UNIQUE evita duplicados).
CREATE TABLE IF NOT EXISTS cierre_caja (
  id_cierre INT(11) NOT NULL AUTO_INCREMENT,
  fecha DATE NOT NULL,
  id_responsable INT(11) NULL,
  total_qr DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_efectivo DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_otros DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_general DECIMAL(10,2) NOT NULL DEFAULT 0,
  efectivo_fisico DECIMAL(10,2) NOT NULL DEFAULT 0,
  diferencia DECIMAL(10,2) NOT NULL DEFAULT 0,
  fecha_registro DATETIME NOT NULL,
  motivo_correccion VARCHAR(255) NULL,
  fecha_correccion DATETIME NULL,
  id_corrector INT(11) NULL,
  PRIMARY KEY (id_cierre),
  UNIQUE KEY uq_cierre_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
