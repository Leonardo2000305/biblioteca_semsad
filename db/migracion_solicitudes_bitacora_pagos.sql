-- ============================================================================
-- MIGRACIÓN: solicitudes de préstamo con confirmación, bitácora y pagos en línea.
-- Es SEGURA ejecutarla varias veces (IF NOT EXISTS / cambios de tipo sin pérdida).
-- Dónde ejecutarla: phpMyAdmin de AlwaysData > base bibliosemsad_db > pestaña "SQL".
-- Ejecutar ANTES de usar la versión nueva de la biblioteca.
-- ============================================================================

-- 1. Préstamos: ahora pueden quedar en estado 'Solicitado' (el usuario pidió el libro y
--    el personal todavía no lo entregó). Se cambia la columna a texto para aceptar el nuevo valor
--    sin perder los estados que ya existen (Activo, Vencido, Con Mora, Devuelto...).
ALTER TABLE prestamo MODIFY estado VARCHAR(30) NOT NULL DEFAULT 'Activo';

-- 2. Bitácora: registro de todo lo importante (inicios y cierres de sesión, cambios, préstamos,
--    multas). La columna clave marca las «auditorías claves» (seguridad y cambios sensibles).
CREATE TABLE IF NOT EXISTS bitacora (
  id_bitacora  INT(11)      NOT NULL AUTO_INCREMENT,
  fecha_hora   DATETIME     NOT NULL,
  id_usuario   INT(11)      NULL,
  correo       VARCHAR(150) NULL,
  accion       VARCHAR(60)  NOT NULL,
  modulo       VARCHAR(60)  NOT NULL,
  descripcion  VARCHAR(500) NOT NULL,
  ip_origen    VARCHAR(64)  NULL,
  clave        TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id_bitacora),
  KEY idx_bitacora_fecha (fecha_hora),
  KEY idx_bitacora_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Pagos en línea de multas: se guarda el método usado (demostración o real).
--    Si la columna ya existe, este paso dará un aviso y se puede ignorar.
ALTER TABLE multa ADD COLUMN metodo_pago VARCHAR(40) NULL;
