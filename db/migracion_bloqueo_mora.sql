-- ============================================================
-- MIGRACIÓN: distinguir bloqueos por mora de suspensiones manuales
-- ANTES de ejecutar: haz una copia de seguridad de la base (phpMyAdmin > Exportar).
-- Ejecutar UNA sola vez. Si la columna ya existe, el primer paso dará error y no hace falta repetirlo.
-- ============================================================

-- 1. Marca que indica si el bloqueo de la cuenta vino de la mora (1) o fue manual (0).
ALTER TABLE usuario ADD COLUMN bloqueo_por_mora TINYINT(1) NOT NULL DEFAULT 0;

-- 2. Cuentas bloqueadas o suspendidas que todavía deben multa: se marcan como bloqueo por mora.
--    Las demás (suspendidas a mano) quedan en 0 y no se reactivan solas al pagar.
UPDATE usuario u
   SET u.bloqueo_por_mora = 1
 WHERE u.estado IN ('Bloqueado', 'Suspendido')
   AND EXISTS (
        SELECT 1 FROM prestamo p
          INNER JOIN multa m ON m.id_prestamo = p.id_prestamo
         WHERE p.id_usuario = u.id_usuario AND m.estado_pago = 'Pendiente'
   );
