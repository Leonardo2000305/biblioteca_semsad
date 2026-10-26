-- ============================================================================
-- SEGURIDAD v5 — tablas que usa la aplicación y que no estaban en el repositorio,
-- y tabla de límites de intentos (login, códigos de registro, recuperación).
-- Es SEGURO ejecutarla varias veces: usa IF NOT EXISTS y no borra datos.
-- Dónde ejecutarla: phpMyAdmin de AlwaysData > base bibliosemsad_db > pestaña "SQL".
-- ============================================================================

CREATE TABLE IF NOT EXISTS codigo_verificacion (
  id         INT(11)      NOT NULL AUTO_INCREMENT,
  correo     VARCHAR(150) NOT NULL,
  codigo     VARCHAR(10)  NOT NULL,
  expiracion DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_codigo_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditoria_recuperacion (
  id               INT(11)      NOT NULL AUTO_INCREMENT,
  correo_ingresado VARCHAR(150) NOT NULL,
  id_usuario       INT(11)      NULL,
  estado           VARCHAR(100) NOT NULL,
  ip_origen        VARCHAR(64)  NULL,
  fecha_hora       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_correo (correo_ingresado),
  KEY idx_audit_fecha (fecha_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Límites de intentos: cada fila es una clave (p. ej. "login:cuenta:correo", "login:ip:1.2.3.4")
CREATE TABLE IF NOT EXISTS intentos_seguridad (
  clave           VARCHAR(190) NOT NULL,
  fallos          INT(11)      NOT NULL DEFAULT 0,
  primer_fallo    DATETIME     NOT NULL,
  bloqueado_hasta DATETIME     NULL,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limpieza opcional de límites ya vencidos (puedes ejecutarla cuando quieras):
-- DELETE FROM intentos_seguridad WHERE bloqueado_hasta IS NULL AND primer_fallo < NOW() - INTERVAL 1 DAY;

-- Teléfono del usuario (lo piden el registro y el panel; si ya existe, no pasa nada)
ALTER TABLE usuario ADD COLUMN IF NOT EXISTS telefono VARCHAR(30) NULL;
