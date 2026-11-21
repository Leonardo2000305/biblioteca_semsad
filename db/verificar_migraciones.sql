-- ARCHIVO: verificar_migraciones.sql
-- SIRVE PARA: comprobar que las migraciones de Biblioteca SEMSAD están aplicadas.
-- SOLO LEE: no cambia nada en la base de datos. Se puede ejecutar cuantas veces se quiera.
-- Resultado esperado: todas las filas deben decir 'OK'. Si alguna dice 'FALTA', ejecutar la migración indicada.

SELECT 'usuario.bloqueo_por_mora' AS verificacion,
       IF(COUNT(*) = 1, 'OK', 'FALTA (db/migracion_bloqueo_mora.sql)') AS resultado
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario' AND COLUMN_NAME = 'bloqueo_por_mora'
UNION ALL
SELECT 'multa.fecha_pago',
       IF(COUNT(*) = 1, 'OK', 'FALTA (db/migracion_comprobantes_caja_avisos.sql)')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'multa' AND COLUMN_NAME = 'fecha_pago'
UNION ALL
SELECT 'multa.id_cobrador',
       IF(COUNT(*) = 1, 'OK', 'FALTA (db/migracion_comprobantes_caja_avisos.sql)')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'multa' AND COLUMN_NAME = 'id_cobrador'
UNION ALL
SELECT 'multa.monto_recibido',
       IF(COUNT(*) = 1, 'OK', 'FALTA (db/migracion_comprobantes_caja_avisos.sql)')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'multa' AND COLUMN_NAME = 'monto_recibido'
UNION ALL
SELECT 'multa.cambio',
       IF(COUNT(*) = 1, 'OK', 'FALTA (db/migracion_comprobantes_caja_avisos.sql)')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'multa' AND COLUMN_NAME = 'cambio'
UNION ALL
SELECT 'prestamo.aviso_enviado_at',
       IF(COUNT(*) = 1, 'OK', 'FALTA (db/migracion_comprobantes_caja_avisos.sql)')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prestamo' AND COLUMN_NAME = 'aviso_enviado_at'
UNION ALL
SELECT 'tabla cierre_caja',
       IF(COUNT(*) = 1, 'OK', 'FALTA (db/migracion_comprobantes_caja_avisos.sql)')
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cierre_caja'
UNION ALL
SELECT 'cierre_caja.fecha es única',
       IF(COUNT(*) >= 1, 'OK', 'FALTA (db/migracion_comprobantes_caja_avisos.sql)')
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cierre_caja' AND COLUMN_NAME = 'fecha' AND NON_UNIQUE = 0
UNION ALL
SELECT 'cierre_caja.motivo_correccion',
       IF(COUNT(*) = 1, 'OK', 'FALTA (db/migracion_comprobantes_caja_avisos.sql)')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cierre_caja' AND COLUMN_NAME = 'motivo_correccion'
UNION ALL
SELECT 'multa.metodo_pago',
       IF(COUNT(*) = 1, 'OK', 'FALTA (db/migracion_solicitudes_bitacora_pagos.sql)')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'multa' AND COLUMN_NAME = 'metodo_pago';
